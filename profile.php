<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_auth();
$errors = [];

if (request_is_post()) {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (!password_verify($currentPassword, (string) $user['password_hash'])) {
        $errors[] = 'Your current password is incorrect.';
    }
    if (strlen($newPassword) < 10 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
        $errors[] = 'The new password must contain at least 10 characters, including uppercase, lowercase and a number.';
    }
    if ($newPassword !== $confirmation) {
        $errors[] = 'The new password confirmation does not match.';
    }

    if ($errors === []) {
        db()->execute(
            'UPDATE users SET password_hash = :password_hash, must_change_password = 0, updated_at = :updated_at WHERE id = :id',
            ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'updated_at' => now(), 'id' => $user['id']]
        );
        audit_log('password.changed');
        flash('success', 'Your password has been changed.');
        redirect('profile.php');
    }
}

$page_title = 'Profile';
require __DIR__ . '/partials/header.php';
?>
<div class="page-heading">
    <div><h1>Your profile</h1><p>Review your account information and maintain a secure password.</p></div>
</div>
<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><strong>Could not update your password.</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="grid-2">
    <section class="panel">
        <h2>Account details</h2>
        <dl>
            <dt>Full name</dt><dd><?= e($user['first_name'] . ' ' . $user['last_name']) ?></dd>
            <dt>Username</dt><dd><?= e($user['username']) ?></dd>
            <dt>Email</dt><dd><?= e($user['email'] ?: 'Not provided') ?></dd>
            <dt>Role</dt><dd><span class="badge"><?= e(ucfirst((string) $user['role'])) ?></span></dd>
        </dl>
    </section>
    <section class="panel">
        <h2>Change password</h2>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-group"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
            <div class="form-group"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" required><p class="field-help">At least 10 characters with uppercase, lowercase and a number.</p></div>
            <div class="form-group"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
            <button class="button" type="submit">Update password</button>
        </form>
    </section>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
