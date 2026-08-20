<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (current_user() !== null) {
    redirect(current_user()['must_change_password'] ? 'profile.php' : 'dashboard.php');
}

$error = null;
if (request_is_post()) {
    verify_csrf();
    $username = input('username');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Enter both your username and password.';
    } elseif (attempt_login($username, $password)) {
        redirect('dashboard.php');
    } else {
        $error = login_is_rate_limited($username)
            ? 'Too many failed attempts. Try again in 15 minutes.'
            : 'The username or password is incorrect.';
    }
}

$page_title = 'Sign in';
require __DIR__ . '/partials/header.php';
?>
<section class="auth-card">
    <div class="auth-intro">
        <div>
            <span class="brand-mark">AG</span>
            <h1>One reliable record for every result.</h1>
            <p>Manage courses, assessments and student performance with clear role-based access.</p>
        </div>
        <ul class="auth-benefits">
            <li>Secure access for administrators, teachers and students</li>
            <li>Weighted grade calculation and GPA reporting</li>
            <li>Complete audit trail for academic changes</li>
        </ul>
    </div>
    <div class="auth-form">
        <h2>Welcome back</h2>
        <p>Sign in with your assigned academic account.</p>
        <?php if ($error !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(app_path('index.php')) ?>">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" autocomplete="username" maxlength="80" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button class="button" type="submit">Sign in</button>
        </form>
    </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
