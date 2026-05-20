<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Test environment bootstrap
|--------------------------------------------------------------------------
|
| Responsibilities:
|
| 1. Sync phpunit.xml `<env>` overrides into $_SERVER. PHPUnit only writes
|    to $_ENV and putenv(), but Laravel's Env reads $_SERVER first — so
|    container-level vars from compose's env_file silently win over the
|    test values. We mirror $_ENV → $_SERVER for every test-relevant key.
|
| 2. Per-worker parallel isolation. paratest sets TEST_TOKEN per worker
|    (1..N). For each worker we point Postgres at its own database
|    `notifications_test_{TOKEN}`, bind Redis to its own logical DB index
|    (TOKEN mod 16 — Redis ships with 16 numeric DBs), and route RabbitMQ
|    to its own vhost `testing_{TOKEN}`. In single-process mode we fall
|    back to the names set in phpunit.xml.
|
| 3. Idempotently create the PG database and RabbitMQ vhost the test
|    process will use — in both single and parallel modes.
*/

$testEnvKeys = [
    'APP_ENV',
    'BCRYPT_ROUNDS',
    'CACHE_DRIVER',
    'DB_CONNECTION',
    'DB_DATABASE',
    'MAIL_MAILER',
    'PULSE_ENABLED',
    'QUEUE_CONNECTION',
    'RABBITMQ_VHOST',
    'SESSION_DRIVER',
    'TELESCOPE_ENABLED',
];

foreach ($testEnvKeys as $key) {
    if (array_key_exists($key, $_ENV)) {
        $_SERVER[$key] = $_ENV[$key];
    }
}

$token = getenv('TEST_TOKEN');
$isParallel = $token !== false && $token !== '' && $token !== '0';

if ($isParallel) {
    $tokenInt = (int) $token;
    $dbName = "notifications_test_{$tokenInt}";
    $redisDb = $tokenInt % 16;
    $vhost = "testing_{$tokenInt}";

    foreach ([
        'DB_DATABASE' => $dbName,
        'REDIS_DB' => (string) $redisDb,
        'RABBITMQ_VHOST' => $vhost,
    ] as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
} else {
    $dbName = $_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? 'notifications';
    $vhost = $_SERVER['RABBITMQ_VHOST'] ?? $_ENV['RABBITMQ_VHOST'] ?? 'testing';
}

$pgHost = getenv('DB_HOST') ?: 'postgres';
$pgPort = (int) (getenv('DB_PORT') ?: 5432);
$pgUser = getenv('DB_USERNAME') ?: 'postgres';
$pgPass = (string) (getenv('DB_PASSWORD') ?: 'password');

$pdo = new PDO(
    "pgsql:host={$pgHost};port={$pgPort};dbname=postgres",
    $pgUser,
    $pgPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$exists = (bool) $pdo->query(
    'SELECT 1 FROM pg_database WHERE datname = '.$pdo->quote($dbName)
)->fetchColumn();

if (! $exists) {
    $pdo->exec('CREATE DATABASE "'.$dbName.'"');
}

$rmqHost = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
$rmqUser = getenv('RABBITMQ_USER') ?: 'guest';
$rmqPass = (string) (getenv('RABBITMQ_PASSWORD') ?: 'guest');
$encodedVhost = rawurlencode($vhost);
$mgmtBase = "http://{$rmqHost}:15672/api";

$put = static function (string $url, ?array $body = null) use ($rmqUser, $rmqPass): void {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_USERPWD => "{$rmqUser}:{$rmqPass}",
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    curl_exec($ch);
    curl_close($ch);
};

$put("{$mgmtBase}/vhosts/{$encodedVhost}");
$put("{$mgmtBase}/permissions/{$encodedVhost}/".rawurlencode($rmqUser), [
    'configure' => '.*',
    'write' => '.*',
    'read' => '.*',
]);
