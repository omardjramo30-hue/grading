<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

date_default_timezone_set((string) $config['app']['timezone']);

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name((string) $config['session']['name']);
session_set_cookie_params([
    'lifetime' => (int) $config['session']['lifetime'],
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

if (isset($_SESSION['last_activity'])
    && time() - (int) $_SESSION['last_activity'] > (int) $config['session']['lifetime']) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/migrations.php';
require_once __DIR__ . '/lib/auth.php';

if (str_starts_with((string) $config['database']['dsn'], 'sqlite:')) {
    $sqlitePath = substr((string) $config['database']['dsn'], 7);
    $directory = dirname($sqlitePath);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
}

try {
    $database = new Database($config['database']);
    migrate_database($database);
    if ($config['app']['environment'] === 'production'
        && $config['initial_admin']['password'] === 'ChangeMe123!'
        && $database->fetch('SELECT id FROM users LIMIT 1') === null) {
        throw new RuntimeException('ADMIN_PASSWORD must be configured before the first production request.');
    }
    seed_initial_admin($database, $config['initial_admin']);
} catch (Throwable $exception) {
    error_log($exception->__toString());
    http_response_code(500);
    $debug = $config['app']['environment'] === 'development';
    exit($debug
        ? 'Database startup failed: ' . e($exception->getMessage())
        : 'The application could not connect to its database.');
}
