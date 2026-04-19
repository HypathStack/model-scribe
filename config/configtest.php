<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Modelos de actividad por guard
    |--------------------------------------------------------------------------
    */
    'log_models' => [
        'backoffice' => null,
        'api' => null, // EINA
        'web' => null,
        // 'system' => null, // Crons, jobs, etc.
        'default' => null, // Crons, jobs, etc.
    ],

    /*
    |--------------------------------------------------------------------------
    | Retención de logs por guard
    |--------------------------------------------------------------------------
    */
    'retention_days' => [
        'backoffice' => null, // env("BACKOFFICE_LOGS_RETENTION_DAYS", 100),
        'api' => env('API_LOGS_RETENTION_DAYS', 30),
        'web' => env('WEB_LOGS_RETENTION_DAYS', 30),
        // 'system' => env("SYSTEM_LOGS_RETENTION_DAYS", 365),
        'default' => env('DEFAULT_LOGS_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Políticas de guardado de logs
    |--------------------------------------------------------------------------
    */
    'save_only_dirty' => true, // Si true, solo se guardarán los logs de los atributos declarados como loggables que hayan cambiado.

];
