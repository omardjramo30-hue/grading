<?php

declare(strict_types=1);

/** @return array<string, mixed>|null */
function current_user(): ?array
{
    static $cachedUser = false;
    if ($cachedUser !== false) {
        return $cachedUser;
    }
    if (empty($_SESSION['user_id'])) {
        $cachedUser = null;
        return null;
    }
    $cachedUser = db()->fetch(
        'SELECT * FROM users WHERE id = :id AND status = :status',
        ['id' => (int) $_SESSION['user_id'], 'status' => 'active']
    );
    if ($cachedUser === null) {
        unset($_SESSION['user_id']);
    }
    return $cachedUser;
}

function login_attempt_key(string $username): string
{
    return hash('sha256', strtolower(trim($username)) . '|' . client_ip());
}

function login_is_rate_limited(string $username): bool
{
    db()->execute('DELETE FROM login_attempts WHERE attempted_at < :expiry', [
        'expiry' => gmdate('Y-m-d H:i:s', time() - 86400),
    ]);
    $threshold = gmdate('Y-m-d H:i:s', time() - 900);
    $row = db()->fetch(
        'SELECT COUNT(*) AS attempts FROM login_attempts WHERE attempt_key = :attempt_key AND attempted_at >= :threshold',
        ['attempt_key' => login_attempt_key($username), 'threshold' => $threshold]
    );
    return (int) ($row['attempts'] ?? 0) >= 5;
}

function record_failed_login(string $username): void
{
    db()->execute(
        'INSERT INTO login_attempts (attempt_key, attempted_at) VALUES (:attempt_key, :attempted_at)',
        ['attempt_key' => login_attempt_key($username), 'attempted_at' => now()]
    );
}

function attempt_login(string $username, string $password): bool
{
    if (login_is_rate_limited($username)) {
        return false;
    }

    $user = db()->fetch(
        'SELECT * FROM users WHERE username = :username AND status = :status',
        ['username' => $username, 'status' => 'active']
    );

    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        record_failed_login($username);
        audit_log('login.failed', 'Username: ' . substr($username, 0, 80), null);
        return false;
    }

    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        db()->execute(
            'UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id',
            ['hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => now(), 'id' => $user['id']]
        );
    }

    db()->execute('DELETE FROM login_attempts WHERE attempt_key = :attempt_key', ['attempt_key' => login_attempt_key($username)]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    db()->execute('UPDATE users SET last_login_at = :last_login WHERE id = :id', ['last_login' => now(), 'id' => $user['id']]);
    audit_log('login.success', null, (int) $user['id']);
    return true;
}

function logout_user(): void
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    if ($userId !== null) {
        audit_log('logout', null, $userId);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_auth(): array
{
    $user = current_user();
    if ($user === null) {
        flash('warning', 'Please sign in to continue.');
        redirect('index.php');
    }
    $currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    if (!empty($user['must_change_password']) && !in_array($currentPage, ['profile.php', 'logout.php'], true)) {
        flash('warning', 'Replace your temporary password before continuing.');
        redirect('profile.php');
    }
    return $user;
}

/** @param string|list<string> $roles */
function require_role(string|array $roles): array
{
    $user = require_auth();
    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($user['role'], $allowed, true)) {
        http_response_code(403);
        $page_title = 'Access denied';
        require __DIR__ . '/../partials/header.php';
        echo '<section class="panel empty-state"><h1>Access denied</h1><p>You do not have permission to view this page.</p></section>';
        require __DIR__ . '/../partials/footer.php';
        exit;
    }
    return $user;
}

function teacher_can_access_course(array $user, int $courseId): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    if ($user['role'] !== 'teacher') {
        return false;
    }
    $course = db()->fetch('SELECT id FROM courses WHERE id = :id AND teacher_id = :teacher_id', [
        'id' => $courseId,
        'teacher_id' => $user['id'],
    ]);
    return $course !== null;
}
