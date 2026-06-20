<?php

namespace Sadorect\OpsAgent\Exceptions;

use Sadorect\OpsAgent\Http\HubClient;
use Throwable;

/**
 * Fingerprints exceptions (class + normalized file:line) and reports grouped
 * error events to the hub, which dedupes and counts them by fingerprint.
 */
class ExceptionReporter
{
    public function __construct(protected HubClient $hub) {}

    public function report(Throwable $e): void
    {
        if (! $this->hub->isConfigured()) {
            return;
        }

        $file = $this->normalizePath($e->getFile());

        $payload = [
            'fingerprint' => $this->fingerprint($e, $file),
            'exception_class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $file,
            'line' => $e->getLine(),
            'occurred_at' => now()->format(DATE_ATOM),
            'context' => [
                'previous' => $e->getPrevious()?->getMessage(),
                'trace' => $this->topFrames($e),
            ],
        ];

        try {
            $this->hub->post('ingest/errors', ['errors' => [$payload]]);
        } catch (Throwable) {
            // Reporting must never throw out of the exception handler.
        }
    }

    protected function fingerprint(Throwable $e, string $file): string
    {
        return substr(hash('sha256', $e::class.'|'.$file.':'.$e->getLine()), 0, 32);
    }

    /** Make paths stable across deploys by stripping the app base path. */
    protected function normalizePath(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }

    /** @return list<string> */
    protected function topFrames(Throwable $e, int $limit = 5): array
    {
        return collect($e->getTrace())
            ->take($limit)
            ->map(fn (array $f) => isset($f['file'])
                ? $this->normalizePath($f['file']).':'.($f['line'] ?? '?')
                : ($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? ''))
            ->values()
            ->all();
    }
}
