<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => getenv('APP_NAME') ?: 'Academic Grading System',
        'environment' => getenv('APP_ENV') ?: 'development',
        'timezone' => getenv('APP_TIMEZONE') ?: 'Africa/Mogadishu',
        'base_path' => rtrim(getenv('APP_BASE_PATH') ?: '', '/'),
    ],
    'database' => [
        'dsn' => getenv('DB_DSN') ?: 'sqlite:' . __DIR__ . '/data/grading.sqlite',
        'username' => getenv('DB_USERNAME') ?: null,
        'password' => getenv('DB_PASSWORD') ?: null,
    ],
    'session' => [
        'name' => getenv('SESSION_NAME') ?: 'grading_session',
        'lifetime' => (int) (getenv('SESSION_LIFETIME') ?: 7200),
    ],
    'initial_admin' => [
        'username' => getenv('ADMIN_USERNAME') ?: 'admin',
        'password' => getenv('ADMIN_PASSWORD') ?: 'ChangeMe123!',
        'first_name' => getenv('ADMIN_FIRST_NAME') ?: 'System',
        'last_name' => getenv('ADMIN_LAST_NAME') ?: 'Administrator',
        'email' => getenv('ADMIN_EMAIL') ?: 'admin@example.com',
    ],
];
