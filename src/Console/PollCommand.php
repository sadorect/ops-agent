<?php

namespace Sadorect\OpsAgent\Console;

use Illuminate\Console\Command;
use Sadorect\OpsAgent\Http\HubClient;
use Symfony\Component\Process\Process;
use Throwable;

class PollCommand extends Command
{
    protected $signature = 'ops:poll';

    protected $description = 'Pull pending allow-listed commands from the hub, execute, and report results.';

    public function handle(HubClient $hub): int
    {
        if (! $hub->isConfigured()) {
            $this->warn('ops-agent is not configured. Skipping.');

            return self::SUCCESS;
        }

        if (! config('ops-agent.commands.enabled')) {
            return self::SUCCESS;
        }

        $response = $hub->get('agent/commands');

        if (! $response->successful()) {
            $this->error("Failed to fetch commands: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $commands = $response->json('commands', []);

        foreach ($commands as $command) {
            $this->process($hub, $command);
        }

        $this->info(count($commands).' command(s) processed.');

        return self::SUCCESS;
    }

    protected function process(HubClient $hub, array $command): void
    {
        $id = $command['id'] ?? null;
        $name = trim((string) ($command['command'] ?? ''));
        $expiresAt = $command['expires_at'] ?? null;
        $signature = (string) ($command['signature'] ?? '');

        if (! $id) {
            return;
        }

        // 1) Integrity: the hub signed this command with our shared secret.
        if (! $hub->verifyCommandSignature($id, $name, $expiresAt, $signature)) {
            $this->report($hub, $id, 1, 'Rejected: signature mismatch.');

            return;
        }

        // 2) Freshness: never run a stale, replayable command.
        if ($expiresAt && time() > (int) $expiresAt) {
            $this->report($hub, $id, 1, 'Rejected: command expired.');

            return;
        }

        // 3) Allowlist: defense-in-depth beyond the hub's own allowlist.
        if (! in_array($name, config('ops-agent.commands.allowlist', []), true)) {
            $this->report($hub, $id, 1, "Rejected: '{$name}' is not allow-listed on this agent.");

            return;
        }

        [$exitCode, $output] = $this->run($name);
        $this->report($hub, $id, $exitCode, $output);
    }

    /** @return array{0:int,1:string} */
    protected function run(string $name): array
    {
        try {
            $process = Process::fromShellCommandline(
                'php artisan '.$name,
                base_path(),
                timeout: (int) config('ops-agent.commands.timeout', 120),
            );
            $process->run();

            return [
                $process->getExitCode() ?? 1,
                trim($process->getOutput()."\n".$process->getErrorOutput()),
            ];
        } catch (Throwable $e) {
            return [1, 'Execution error: '.$e->getMessage()];
        }
    }

    protected function report(HubClient $hub, int $id, int $exitCode, string $output): void
    {
        try {
            $hub->post("agent/commands/{$id}/result", [
                'exit_code' => $exitCode,
                'output' => $output,
            ]);
        } catch (Throwable) {
            // If reporting fails the command stays claimable/expirable on the hub.
        }
    }
}
