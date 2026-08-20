<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!request_is_post()) {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

verify_csrf();
logout_user();
redirect('index.php');
