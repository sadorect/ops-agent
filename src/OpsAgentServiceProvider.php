<?php

namespace Sadorect\OpsAgent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Sadorect\OpsAgent\Console\HeartbeatCommand;
use Sadorect\OpsAgent\Console\PollCommand;
use Sadorect\OpsAgent\Exceptions\ExceptionReporter;
use Sadorect\OpsAgent\Http\HubClient;
use Sadorect\OpsAgent\Logging\HubLogHandler;

class OpsAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ops-agent.php', 'ops-agent');

        $this->app->singleton(HubClient::class, function ($app) {
            $c = $app['config']['ops-agent'];

            return new HubClient(
                hubUrl: $c['hub_url'] ?? null,
                slug: $c['app_slug'] ?? null,
                token: $c['token'] ?? null,
                secret: $c['hmac_secret'] ?? null,
                timeout: (int) ($c['timeout'] ?? 5),
            );
        });

        $this->app->singleton(ExceptionReporter::class);
        $this->app->singleton(HubLogHandler::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ops-agent.php' => config_path('ops-agent.php'),
        ], 'ops-agent-config');

        if (! config('ops-agent.enabled')) {
            return;
        }

        $this->registerCommands();
        $this->registerLogChannel();
        $this->registerExceptionReporting();
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([HeartbeatCommand::class, PollCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('ops:heartbeat')->everyMinute()->withoutOverlapping();

            if (config('ops-agent.commands.enabled')) {
                $schedule->command('ops:poll')->everyMinute()->withoutOverlapping();
            }
        });
    }

    /**
     * Expose a Monolog "ops" log channel that forwards records to the hub.
     * Add it to config/logging.php channels (or set LOG_STACK=...,ops).
     */
    protected function registerLogChannel(): void
    {
        $this->app->make('config')->set('logging.channels.ops', [
            'driver' => 'monolog',
            'handler' => HubLogHandler::class,
            'level' => config('ops-agent.logs.level', 'warning'),
        ]);
    }

    protected function registerExceptionReporting(): void
    {
        if (! config('ops-agent.errors.enabled')) {
            return;
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (\Throwable $e) {
                    $this->app->make(ExceptionReporter::class)->report($e);
                });
            }
        });
    }
}
