<?php

// config for HypathBel/ModelScribe

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    | Which driver to use by default. Can be overridden per-model via the
    | $auditDriver property on the model using the HasAuditLog trait.
    |
    | Supported: "database", "file", "stack"
    */
    'default' => env('MODEL_SCRIBE_DRIVER', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    | Each driver has its own configuration block. The "stack" driver fans out
    | a single log entry to multiple other drivers simultaneously.
    */
    'drivers' => [

        'database' => [
            'driver' => 'database',

            // Which DB connection to use. null = app default.
            'connection' => env('MODEL_SCRIBE_DB_CONNECTION', null),

            // Default table for models that don't define a specific store.
            'table' => env('MODEL_SCRIBE_TABLE', 'model_scribe_logs'),

            /*
            |------------------------------------------------------------------
            | Named Stores (multi-table routing)
            |------------------------------------------------------------------
            | Map a $auditLogName value to a specific table (and optionally a
            | different connection). Models set $auditLogName to select a store.
            |
            | 'stores' => [
            |     'invoices' => [
            |         'tables'     => ['invoice_error_logs', 'invoice_scribe_logs'],
            |         'connection' => 'mysql_audit',   // optional
            |         'auth_guard' => null,
            |     ],
            |     'orders' => [
            |         'tables' => ['order_scribe_logs'],
            |         'auth_guard' => null,
            |     ],
            | ],
            */
            'stores' => [],

            'retention' => [
                // 'permanent' — never delete automatically
                // 'days'      — delete records older than `days`
                // 'rotating'  — keep only the latest `keep` records per table
                'type' => env('MODEL_SCRIBE_RETENTION', 'permanent'),
                'days' => (int) env('MODEL_SCRIBE_RETENTION_DAYS', 90),
                'keep' => (int) env('MODEL_SCRIBE_RETENTION_KEEP', 500),
            ],

            'auth_guard' => null,
        ],

        'file' => [
            'driver' => 'file',

            // Any channel defined in config/logging.php.
            'channel' => env('MODEL_SCRIBE_LOG_CHANNEL', 'daily'),

            'auth_guard' => null,
        ],

        'stack' => [
            'driver' => 'stack',

            // Fan-out to these named drivers.
            'drivers' => ['database', 'file'],

            'auth_guard' => null,
        ],

        /*
        |----------------------------------------------------------------------
        | Future Drivers (examples)
        |----------------------------------------------------------------------
        | 'elk' => [
        |     'driver'   => 'elk',
        |     'host'     => env('ELASTICSEARCH_HOST', 'localhost'),
        |     'port'     => env('ELASTICSEARCH_PORT', 9200),
        |     'index'    => env('MODEL_SCRIBE_ELK_INDEX', 'model-scribe'),
        | ],
        */
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Settings
    |--------------------------------------------------------------------------
    */

    // Capture request context (URL, IP, User-Agent) for every log entry.
    'capture_request_context' => true,

    // The model that represents the "causer" (who performed the action).
    // null = auto-detect from Auth::user().
    'auth_guard' => null,

    /*
    |--------------------------------------------------------------------------
    | Guard-based Routing
    |--------------------------------------------------------------------------
    | Map an authentication guard name to a specific store. This is used if
    | the model being audited hasn't defined a specific $auditLogName.
    */
    'guard_stores' => [
        // 'api' => 'invoices',
        // 'web' => 'orders',
    ],

];
