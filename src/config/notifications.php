<?php

declare(strict_types=1);

return [
    'batch_max' => (int) env('NOTIFICATIONS_BATCH_MAX', 5000),
    'insert_chunk' => (int) env('NOTIFICATIONS_INSERT_CHUNK', 2000),
    'max_attempts' => (int) env('NOTIFICATIONS_MAX_ATTEMPTS', 5),
    'retry_backoff_ms' => explode(',', env('NOTIFICATIONS_RETRY_BACKOFF_MS', '1000,5000,25000,125000')),
    'body_max' => [
        'sms' => (int) env('NOTIFICATIONS_BODY_MAX_SMS', 1000),
        'email' => (int) env('NOTIFICATIONS_BODY_MAX_EMAIL', 10000),
    ],
];
