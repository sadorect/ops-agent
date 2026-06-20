<?php

namespace Sadorect\OpsAgent\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Sadorect\OpsAgent\Http\HubClient;
use Throwable;

class HeartbeatCommand extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Collect a health snapshot and send it to the ops hub.';

    /** Cache key the scheduler writes so we can detect a stalled schedule. */
    public const SCHEDULER_KEY = 'ops-agent:scheduler-tick';

    public function handle(HubClient $hub): int
    {
        // Record that the schedule fired, for the next heartbeat to read.
        Cache::put(self::SCHEDULER_KEY, now()->timestamp, now()->addMinutes(15));

        if (! $hub->isConfigured()) {
            $this->warn('ops-agent is not configured (missing OPS_* env). Skipping.');

            return self::SUCCESS;
        }

        $dbOk = $this->dbOk();
        $cacheOk = $this->cacheOk();

        $payload = [
            'status' => $dbOk ? 'up' : 'down',
            'queue_depth' => $this->queueDepth(),
            'scheduler_ok' => $this->schedulerOk(),
            'db_ok' => $dbOk,
            'cache_ok' => $cacheOk,
            'disk_free_pct' => $this->diskFreePct(),
            'framework_version' => app()->version(),
            'php_version' => PHP_VERSION,
        ];

        $started = microtime(true);
        $response = $hub->post('ingest/heartbeat', $payload);
        $payload['response_ms'] = (int) round((microtime(true) - $started) * 1000);

        if (! $response->successful()) {
            $this->error("Heartbeat rejected: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $this->info('Heartbeat sent.');

        return self::SUCCESS;
    }

    protected function dbOk(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function cacheOk(): bool
    {
        try {
            Cache::put('ops-agent:ping', '1', now()->addMinute());

            return Cache::get('ops-agent:ping') === '1';
        } catch (Throwable) {
            return false;
        }
    }

    protected function queueDepth(): ?int
    {
        try {
            return Queue::connection(config('ops-agent.heartbeat.queue_connection'))
                ->size(config('ops-agent.heartbeat.queue_name', 'default'));
        } catch (Throwable) {
            return null;
        }
    }

    /** The schedule is healthy if it ticked within the last ~2 minutes. */
    protected function schedulerOk(): ?bool
    {
        $last = Cache::get(self::SCHEDULER_KEY);

        return $last === null ? null : (now()->timestamp - (int) $last) < 120;
    }

    protected function diskFreePct(): ?float
    {
        try {
            $path = config('ops-agent.heartbeat.disk', base_path());
            $free = disk_free_space($path);
            $total = disk_total_space($path);

            return ($free && $total) ? round($free / $total * 100, 2) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
