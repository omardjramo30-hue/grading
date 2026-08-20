<?php

declare(strict_types=1);

$page_title = $page_title ?? 'Dashboard';
$viewer = current_user();
$flashes = consume_flashes();
$currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($page_title) ?> · <?= e(config('app')['name']) ?></title>
    <link rel="stylesheet" href="<?= e(app_path('css/app.css')) ?>">
</head>
<body>
<?php if ($viewer !== null): ?>
    <header class="topbar">
        <a class="brand" href="<?= e(app_path('dashboard.php')) ?>" aria-label="Go to dashboard">
            <span class="brand-mark" aria-hidden="true">AG</span>
            <span>
                <strong><?= e(config('app')['name']) ?></strong>
                <small>Academic records</small>
            </span>
        </a>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="main-nav">Menu</button>
        <nav id="main-nav" class="main-nav" aria-label="Primary navigation">
            <a<?= $currentPage === 'dashboard.php' ? ' class="active"' : '' ?> href="<?= e(app_path('dashboard.php')) ?>">Dashboard</a>
            <?php if ($viewer['role'] === 'admin'): ?>
                <a<?= $currentPage === 'users.php' ? ' class="active"' : '' ?> href="<?= e(app_path('users.php')) ?>">People</a>
                <a<?= $currentPage === 'courses.php' ? ' class="active"' : '' ?> href="<?= e(app_path('courses.php')) ?>">Courses</a>
                <a<?= $currentPage === 'enrollments.php' ? ' class="active"' : '' ?> href="<?= e(app_path('enrollments.php')) ?>">Enrollments</a>
                <a<?= $currentPage === 'audit.php' ? ' class="active"' : '' ?> href="<?= e(app_path('audit.php')) ?>">Audit</a>
            <?php elseif ($viewer['role'] === 'teacher'): ?>
                <a<?= in_array($currentPage, ['courses.php', 'grades.php'], true) ? ' class="active"' : '' ?> href="<?= e(app_path('courses.php')) ?>">My courses</a>
            <?php else: ?>
                <a<?= $currentPage === 'results.php' ? ' class="active"' : '' ?> href="<?= e(app_path('results.php')) ?>">My results</a>
            <?php endif; ?>
            <?php if (in_array($viewer['role'], ['admin', 'teacher'], true)): ?>
                <a<?= $currentPage === 'results.php' ? ' class="active"' : '' ?> href="<?= e(app_path('results.php')) ?>">Results</a>
            <?php endif; ?>
            <a<?= $currentPage === 'profile.php' ? ' class="active"' : '' ?> href="<?= e(app_path('profile.php')) ?>">Profile</a>
        </nav>
        <div class="account-menu">
            <span><strong><?= e($viewer['first_name'] . ' ' . $viewer['last_name']) ?></strong><small><?= e(ucfirst((string) $viewer['role'])) ?></small></span>
            <form action="<?= e(app_path('logout.php')) ?>" method="post">
                <?= csrf_field() ?>
                <button class="button button-ghost button-small" type="submit">Sign out</button>
            </form>
        </div>
    </header>
<?php endif; ?>

<main class="<?= $viewer === null ? 'auth-shell' : 'page-shell' ?>">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
