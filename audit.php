<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_role('admin');

$actionFilter = trim((string) ($_GET['action'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$params = [];
$where = '';
if ($actionFilter !== '') {
    $where = 'WHERE a.action LIKE :action';
    $params['action'] = '%' . $actionFilter . '%';
}

$count = db()->fetch("SELECT COUNT(*) AS total FROM audit_logs a {$where}", $params);
$total = (int) ($count['total'] ?? 0);
$limitSql = ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
$logs = db()->fetchAll(
    "SELECT a.*, u.username, u.first_name, u.last_name
     FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
     {$where} ORDER BY a.id DESC{$limitSql}",
    $params
);

$page_title = 'Audit log';
require __DIR__ . '/partials/header.php';
?>
<div class="page-heading"><div><h1>Audit log</h1><p>Security and academic activity recorded by the system.</p></div></div>
<section class="panel">
    <div class="panel-header"><div><h2><?= e($total) ?> recorded events</h2><p>Newest activity appears first.</p></div><form method="get" class="inline-form"><div class="form-group"><label class="sr-only" for="action">Filter activity</label><input id="action" name="action" placeholder="Filter e.g. grades.saved" value="<?= e($actionFilter) ?>"></div><button class="button button-secondary" type="submit">Filter</button></form></div>
    <?php if ($logs === []): ?><div class="empty-state"><h2>No activity found</h2><p>Try removing the filter or perform an action in the system.</p></div><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Date (UTC)</th><th>Actor</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead><tbody><?php foreach ($logs as $log): ?><tr><td><?= e($log['created_at']) ?></td><td><?= $log['user_id'] ? e(($log['first_name'] ?: '') . ' ' . ($log['last_name'] ?: '') . ' (@' . $log['username'] . ')') : '<span class="muted">Unauthenticated</span>' ?></td><td><span class="badge"><?= e($log['action']) ?></span></td><td><?= e($log['details'] ?: '—') ?></td><td><?= e($log['ip_address'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="page-actions" style="margin-top:16px"><?php if ($page > 1): ?><a class="button button-secondary" href="<?= e(app_path('audit.php?action=' . urlencode($actionFilter) . '&page=' . ($page - 1))) ?>">Previous</a><?php endif; ?><?php if ($offset + count($logs) < $total): ?><a class="button button-secondary" href="<?= e(app_path('audit.php?action=' . urlencode($actionFilter) . '&page=' . ($page + 1))) ?>">Next</a><?php endif; ?></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
