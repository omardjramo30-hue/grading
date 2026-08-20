<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'status' => 'ok',
    'application' => config('app')['name'],
    'time' => now(),
], JSON_THROW_ON_ERROR);
