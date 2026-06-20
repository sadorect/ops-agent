<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hub connection
    |--------------------------------------------------------------------------
    | The control-plane URL and this app's credentials, issued from the hub's
    | application registry. The token is sent per request and verified against
    | its stored hash; the HMAC secret signs every request body.
    */
    'hub_url' => env('OPS_HUB_URL'),
    'app_slug' => env('OPS_APP_SLUG'),
    'token' => env('OPS_APP_TOKEN'),
    'hmac_secret' => env('OPS_HMAC_SECRET'),

    // Master switch — when false the agent registers nothing and sends nothing.
    'enabled' => env('OPS_AGENT_ENABLED', true),

    // HTTP timeout (seconds) for hub calls. Kept short so reporting never hangs a request.
    'timeout' => env('OPS_AGENT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Heartbeat
    |--------------------------------------------------------------------------
    | The queue connection whose depth is reported, and the cache key the
    | scheduler heartbeat writes so we can tell if the schedule is running.
    */
    'heartbeat' => [
        'queue_connection' => env('OPS_QUEUE_CONNECTION', config('queue.default')),
        'queue_name' => env('OPS_QUEUE_NAME', 'default'),
        'disk' => env('OPS_DISK_PATH', base_path()),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log forwarding
    |--------------------------------------------------------------------------
    | Add the "ops" channel to your stack (or set LOG_CHANNEL=ops) to forward
    | records. Buffered and flushed on shutdown so requests never block.
    */
    'logs' => [
        'level' => env('OPS_LOG_LEVEL', 'warning'),
        'batch_size' => env('OPS_LOG_BATCH', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error reporting
    |--------------------------------------------------------------------------
    */
    'errors' => [
        'enabled' => env('OPS_ERRORS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote commands
    |--------------------------------------------------------------------------
    | Defense-in-depth: even though the hub only dispatches allow-listed
    | commands, the agent independently refuses anything not on this list.
    */
    'commands' => [
        'enabled' => env('OPS_COMMANDS_ENABLED', true),
        'allowlist' => [
            'cache:clear',
            'config:clear',
            'route:clear',
            'view:clear',
            'queue:restart',
            'horizon:terminate',
            'migrate --pretend',
            'migrate --force',
            'down',
            'up',
            'optimize',
            'optimize:clear',
        ],
        'timeout' => env('OPS_COMMAND_TIMEOUT', 120),
    ],
];
