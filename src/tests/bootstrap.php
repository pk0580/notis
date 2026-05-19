<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Per-worker parallel test isolation
|--------------------------------------------------------------------------
|
| paratest sets the TEST_TOKEN env var per worker (1..N). For each worker we
| (1) point Postgres at its own database `notifications_test_{TOKEN}`,
| (2) bind Redis to its own logical DB index (TOKEN mod 16, since Redis
|     ships with 16 numeric DBs out of the box),
| (3) route RabbitMQ to its own vhost `testing_{TOKEN}` so queues and
|     exchanges declared by one worker are invisible to the others.
|
| Resources are created lazily on first worker boot — the PG database via
| a direct PDO connection to the default `postgres` DB, and the vhost via
| the RabbitMQ management HTTP API. Both calls are idempotent.
|
| Serial runs (no TEST_TOKEN) keep the shared `notifications` / `testing`
| / Redis DB 0 setup — unchanged behavior.
*/

$token = getenv('TEST_TOKEN');

if ($token === false || $token === '' || $token === '0') {
    return;
}

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
