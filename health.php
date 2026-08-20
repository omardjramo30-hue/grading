<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
http_response_code(200);

echo json_encode([
    'status' => 'ok',
    'time' => gmdate(DATE_ATOM),
], JSON_THROW_ON_ERROR);
