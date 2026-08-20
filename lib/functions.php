<?php

declare(strict_types=1);

function config(?string $section = null): mixed
{
    global $config;
    return $section === null ? $config : ($config[$section] ?? null);
}

function db(): Database
{
    global $database;
    return $database;
}

function app_path(string $path = ''): string
{
    $base = (string) config('app')['base_path'];
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . app_path($path), true, 302);
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function request_is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function query_int(string $key): int
{
    return max(0, (int) ($_GET[$key] ?? 0));
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page, and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type:string,message:string}> */
function consume_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function audit_log(string $action, ?string $details = null, ?int $userId = null): void
{
    $actor = $userId ?? (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null);
    db()->execute(
        'INSERT INTO audit_logs (user_id, action, details, ip_address, created_at)
         VALUES (:user_id, :action, :details, :ip_address, :created_at)',
        [
            'user_id' => $actor,
            'action' => $action,
            'details' => $details,
            'ip_address' => client_ip(),
            'created_at' => now(),
        ]
    );
}

function grade_letter(float $percentage): string
{
    return match (true) {
        $percentage >= 90 => 'A',
        $percentage >= 85 => 'A-',
        $percentage >= 80 => 'B+',
        $percentage >= 75 => 'B',
        $percentage >= 70 => 'B-',
        $percentage >= 65 => 'C+',
        $percentage >= 60 => 'C',
        $percentage >= 55 => 'C-',
        $percentage >= 50 => 'D',
        default => 'F',
    };
}

function grade_points(string $letter): float
{
    return match ($letter) {
        'A' => 4.0,
        'A-' => 3.7,
        'B+' => 3.3,
        'B' => 3.0,
        'B-' => 2.7,
        'C+' => 2.3,
        'C' => 2.0,
        'C-' => 1.7,
        'D' => 1.0,
        default => 0.0,
    };
}

/** @return array{percentage:float,letter:string,points:float,complete:bool,graded_weight:float,total_weight:float} */
function enrollment_result(int $enrollmentId): array
{
    $rows = db()->fetchAll(
        'SELECT a.max_score, a.weight, g.score
         FROM assessments a
         JOIN enrollments e ON e.course_id = a.course_id
         LEFT JOIN grades g ON g.assessment_id = a.id AND g.enrollment_id = e.id
         WHERE e.id = :enrollment_id',
        ['enrollment_id' => $enrollmentId]
    );

    $weightedScore = 0.0;
    $totalWeight = 0.0;
    $gradedWeight = 0.0;
    $complete = count($rows) > 0;

    foreach ($rows as $row) {
        $maxScore = (float) $row['max_score'];
        $weight = (float) $row['weight'];
        $totalWeight += $weight;
        if ($row['score'] === null) {
            $complete = false;
            continue;
        }
        $gradedWeight += $weight;
        if ($maxScore > 0) {
            $weightedScore += ((float) $row['score'] / $maxScore) * $weight;
        }
    }

    $complete = $complete && abs($totalWeight - 100.0) < 0.01;

    $percentage = $totalWeight > 0 ? ($weightedScore / $totalWeight) * 100 : 0.0;
    $percentage = round($percentage, 2);
    $letter = grade_letter($percentage);

    return [
        'percentage' => $percentage,
        'letter' => $letter,
        'points' => grade_points($letter),
        'complete' => $complete,
        'graded_weight' => round($gradedWeight, 2),
        'total_weight' => round($totalWeight, 2),
    ];
}

function selected(string|int $value, string|int|null $current): string
{
    return (string) $value === (string) $current ? ' selected' : '';
}

function csv_safe(mixed $value): string
{
    $text = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'" . $text : $text;
}
