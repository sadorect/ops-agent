<?php

namespace Sadorect\OpsAgent\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Sadorect\OpsAgent\Http\HubClient;
use Throwable;

/**
 * Buffers log records and ships them to the hub in batches. Flushes when the
 * buffer fills and again on shutdown, so a request is never blocked waiting on
 * the hub and a hub outage only costs the in-memory buffer.
 */
class HubLogHandler extends AbstractProcessingHandler
{
    /** @var array<int, array<string, mixed>> */
    protected array $buffer = [];

    protected int $batchSize;

    public function __construct(protected HubClient $hub)
    {
        $level = Level::fromName(ucfirst(config('ops-agent.logs.level', 'warning')));
        parent::__construct($level, bubble: true);

        $this->batchSize = (int) config('ops-agent.logs.batch_size', 50);

        // Ensure the buffer is flushed even on a fatal/normal shutdown.
        register_shutdown_function([$this, 'flush']);
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer[] = [
            'level' => strtolower($record->level->getName()),
            'channel' => $record->channel,
            'message' => $record->message,
            'context' => $this->normalizeContext($record->context),
            'occurred_at' => $record->datetime->format(DATE_ATOM),
        ];

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === [] || ! $this->hub->isConfigured()) {
            $this->buffer = [];

            return;
        }

        $batch = $this->buffer;
        $this->buffer = [];

        try {
            $this->hub->post('ingest/logs', ['logs' => $batch]);
        } catch (Throwable) {
            // Never let logging take down the app; drop on failure.
        }
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    /** Context can hold exceptions / objects; reduce to JSON-safe scalars. */
    protected function normalizeContext(array $context): array
    {
        return array_map(function ($value) {
            if ($value instanceof Throwable) {
                return $value::class.': '.$value->getMessage();
            }

            return is_scalar($value) || is_array($value) || $value === null
                ? $value
                : (string) (is_object($value) ? $value::class : gettype($value));
        }, $context);
    }
}
