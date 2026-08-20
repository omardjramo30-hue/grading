<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_role('admin');
$errors = [];
$roles = ['admin', 'teacher', 'student'];

if (request_is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'create' || $action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $username = strtolower(input('username'));
        $firstName = input('first_name');
        $lastName = input('last_name');
        $email = strtolower(input('email'));
        $role = input('role');
        $studentNumber = strtoupper(input('student_number'));
        $program = input('program');
        $studyLevel = input('study_level');
        $password = (string) ($_POST['password'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9._-]{3,80}$/', $username)) {
            $errors[] = 'Username must be 3–80 characters and may use letters, numbers, dots, dashes and underscores.';
        }
        if ($firstName === '' || $lastName === '') {
            $errors[] = 'First and last names are required.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (!in_array($role, $roles, true)) {
            $errors[] = 'Select a valid account role.';
        }
        if ($action === 'create' && (strlen($password) < 10 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password))) {
            $errors[] = 'Temporary password must have at least 10 characters, including uppercase, lowercase and a number.';
        }
        if ($role === 'student' && ($studentNumber === '' || $program === '' || $studyLevel === '')) {
            $errors[] = 'Student number, program and study level are required for students.';
        }

        if ($action === 'update') {
            $existingAccount = db()->fetch('SELECT id, role, status FROM users WHERE id = :id', ['id' => $id]);
            if ($existingAccount === null) {
                $errors[] = 'Account not found.';
            } else {
                if ($id === (int) $user['id'] && $role !== $existingAccount['role']) {
                    $errors[] = 'You cannot change your own administrator role.';
                }
                if ($existingAccount['role'] === 'admin' && $role !== 'admin') {
                    $admins = db()->fetch("SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND status = 'active'");
                    if ((int) ($admins['total'] ?? 0) <= 1) {
                        $errors[] = 'The final active administrator cannot be changed to another role.';
                    }
                }
                if ($existingAccount['role'] === 'teacher' && $role !== 'teacher') {
                    $assigned = db()->fetch('SELECT COUNT(*) AS total FROM courses WHERE teacher_id = :teacher_id', ['teacher_id' => $id]);
                    if ((int) ($assigned['total'] ?? 0) > 0) {
                        $errors[] = 'Reassign this teacher’s courses before changing their role.';
                    }
                }
                if ($existingAccount['role'] === 'student' && $role !== 'student') {
                    $enrolled = db()->fetch('SELECT COUNT(*) AS total FROM enrollments e JOIN student_profiles s ON s.id = e.student_id WHERE s.user_id = :user_id', ['user_id' => $id]);
                    if ((int) ($enrolled['total'] ?? 0) > 0) {
                        $errors[] = 'Remove this student’s enrollments before changing their role.';
                    }
                }
            }
        }

        if ($errors === []) {
            try {
                db()->transaction(function (Database $database) use ($action, $id, $username, $firstName, $lastName, $email, $role, $password, $studentNumber, $program, $studyLevel): void {
                    if ($action === 'create') {
                        $database->execute(
                            'INSERT INTO users (username, password_hash, first_name, last_name, email, role, status, must_change_password, created_at, updated_at)
                             VALUES (:username, :password_hash, :first_name, :last_name, :email, :role, :status, 1, :created_at, :updated_at)',
                            [
                                'username' => $username,
                                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'email' => $email === '' ? null : $email,
                                'role' => $role,
                                'status' => 'active',
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                        $targetId = $database->lastInsertId();
                    } else {
                        $targetId = $id;
                        $existing = $database->fetch('SELECT * FROM users WHERE id = :id', ['id' => $targetId]);
                        if ($existing === null) {
                            throw new RuntimeException('Account not found.');
                        }
                        $database->execute(
                            'UPDATE users SET username = :username, first_name = :first_name, last_name = :last_name, email = :email, role = :role, updated_at = :updated_at WHERE id = :id',
                            [
                                'username' => $username,
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'email' => $email === '' ? null : $email,
                                'role' => $role,
                                'updated_at' => now(),
                                'id' => $targetId,
                            ]
                        );
                    }

                    if ($role === 'student') {
                        $profile = $database->fetch('SELECT id FROM student_profiles WHERE user_id = :user_id', ['user_id' => $targetId]);
                        if ($profile === null) {
                            $database->execute(
                                'INSERT INTO student_profiles (user_id, student_number, program, study_level, created_at)
                                 VALUES (:user_id, :student_number, :program, :study_level, :created_at)',
                                ['user_id' => $targetId, 'student_number' => $studentNumber, 'program' => $program, 'study_level' => $studyLevel, 'created_at' => now()]
                            );
                        } else {
                            $database->execute(
                                'UPDATE student_profiles SET student_number = :student_number, program = :program, study_level = :study_level WHERE user_id = :user_id',
                                ['student_number' => $studentNumber, 'program' => $program, 'study_level' => $studyLevel, 'user_id' => $targetId]
                            );
                        }
                    } elseif ($action === 'update') {
                        $enrolled = $database->fetch(
                            'SELECT COUNT(*) AS total FROM enrollments e JOIN student_profiles s ON s.id = e.student_id WHERE s.user_id = :user_id',
                            ['user_id' => $targetId]
                        );
                        if ((int) ($enrolled['total'] ?? 0) === 0) {
                            $database->execute('DELETE FROM student_profiles WHERE user_id = :user_id', ['user_id' => $targetId]);
                        }
                    }
                });

                audit_log('user.' . ($action === 'create' ? 'created' : 'updated'), 'Username: ' . $username);
                flash('success', $action === 'create' ? 'Account created successfully.' : 'Account updated successfully.');
                redirect('users.php');
            } catch (Throwable $exception) {
                error_log($exception->__toString());
                $errors[] = str_contains(strtolower($exception->getMessage()), 'unique')
                    || str_contains(strtolower($exception->getMessage()), 'duplicate')
                    ? 'That username, email or student number is already in use.'
                    : 'The account could not be saved. Check the supplied information.';
            }
        }
    } elseif ($action === 'toggle_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $target = db()->fetch('SELECT id, username, status FROM users WHERE id = :id', ['id' => $id]);
        if ($target === null) {
            flash('danger', 'Account not found.');
        } elseif ((int) $target['id'] === (int) $user['id']) {
            flash('warning', 'You cannot deactivate your own account.');
        } else {
            $status = $target['status'] === 'active' ? 'inactive' : 'active';
            db()->execute('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id', ['status' => $status, 'updated_at' => now(), 'id' => $id]);
            audit_log('user.status_changed', 'Username: ' . $target['username'] . '; status: ' . $status);
            flash('success', 'Account status updated.');
        }
        redirect('users.php');
    } elseif ($action === 'reset_password') {
        $id = (int) ($_POST['id'] ?? 0);
        $temporary = (string) ($_POST['temporary_password'] ?? '');
        if (strlen($temporary) < 10 || !preg_match('/[A-Z]/', $temporary) || !preg_match('/[a-z]/', $temporary) || !preg_match('/\d/', $temporary)) {
            flash('danger', 'Temporary password must have at least 10 characters with uppercase, lowercase and a number.');
        } else {
            db()->execute(
                'UPDATE users SET password_hash = :hash, must_change_password = 1, updated_at = :updated_at WHERE id = :id',
                ['hash' => password_hash($temporary, PASSWORD_DEFAULT), 'updated_at' => now(), 'id' => $id]
            );
            audit_log('user.password_reset', 'User ID: ' . $id);
            flash('success', 'Temporary password saved. The user must change it at next sign-in.');
        }
        redirect('users.php?edit=' . $id);
    }
}

$editId = query_int('edit');
$editing = $editId > 0 ? db()->fetch(
    'SELECT u.*, s.student_number, s.program, s.study_level FROM users u LEFT JOIN student_profiles s ON s.user_id = u.id WHERE u.id = :id',
    ['id' => $editId]
) : null;
$showForm = isset($_GET['create']) || $editing !== null || $errors !== [];
$search = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = '';
if ($search !== '') {
    $where = 'WHERE u.username LIKE :q OR u.first_name LIKE :q OR u.last_name LIKE :q OR s.student_number LIKE :q';
    $params['q'] = '%' . $search . '%';
}
$people = db()->fetchAll(
    "SELECT u.*, s.student_number, s.program, s.study_level
     FROM users u LEFT JOIN student_profiles s ON s.user_id = u.id {$where}
     ORDER BY CASE u.role WHEN 'admin' THEN 1 WHEN 'teacher' THEN 2 ELSE 3 END, u.first_name, u.last_name",
    $params
);

$page_title = 'People';
require __DIR__ . '/partials/header.php';
?>
<div class="page-heading">
    <div><h1>People</h1><p>Create and manage administrator, teacher and student accounts.</p></div>
    <a class="button" href="<?= e(app_path('users.php?create=1')) ?>">Add person</a>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><strong>Account not saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($showForm): ?>
<section class="panel">
    <div class="panel-header"><div><h2><?= $editing ? 'Edit account' : 'Create account' ?></h2><p><?= $editing ? 'Update identity, role and academic details.' : 'The user will be asked to replace their temporary password.' ?></p></div><a href="<?= e(app_path('users.php')) ?>">Close</a></div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= e($editing['id']) ?>"><?php endif; ?>
        <div class="form-group"><label for="first_name">First name</label><input id="first_name" name="first_name" required maxlength="100" value="<?= e($_POST['first_name'] ?? $editing['first_name'] ?? '') ?>"></div>
        <div class="form-group"><label for="last_name">Last name</label><input id="last_name" name="last_name" required maxlength="100" value="<?= e($_POST['last_name'] ?? $editing['last_name'] ?? '') ?>"></div>
        <div class="form-group"><label for="username">Username</label><input id="username" name="username" required maxlength="80" value="<?= e($_POST['username'] ?? $editing['username'] ?? '') ?>"></div>
        <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" value="<?= e($_POST['email'] ?? $editing['email'] ?? '') ?>"></div>
        <div class="form-group"><label for="role">Role</label><select id="role" name="role" required data-role-select><?php foreach ($roles as $role): ?><option value="<?= e($role) ?>"<?= selected($role, $_POST['role'] ?? $editing['role'] ?? 'student') ?>><?= e(ucfirst($role)) ?></option><?php endforeach; ?></select></div>
        <?php if (!$editing): ?><div class="form-group"><label for="password">Temporary password</label><input id="password" name="password" type="password" autocomplete="new-password" required><p class="field-help">Minimum 10 characters with uppercase, lowercase and a number.</p></div><?php endif; ?>
        <div class="form-group student-field"><label for="student_number">Student number</label><input id="student_number" name="student_number" maxlength="80" value="<?= e($_POST['student_number'] ?? $editing['student_number'] ?? '') ?>"></div>
        <div class="form-group student-field"><label for="program">Program</label><input id="program" name="program" maxlength="190" value="<?= e($_POST['program'] ?? $editing['program'] ?? '') ?>"></div>
        <div class="form-group student-field"><label for="study_level">Study level</label><input id="study_level" name="study_level" maxlength="80" placeholder="e.g. Year 2" value="<?= e($_POST['study_level'] ?? $editing['study_level'] ?? '') ?>"></div>
        <div class="form-group full"><button class="button" type="submit"><?= $editing ? 'Save changes' : 'Create account' ?></button></div>
    </form>
    <?php if ($editing): ?>
        <hr>
        <form method="post" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= e($editing['id']) ?>">
            <div class="form-group"><label for="temporary_password">Reset temporary password</label><input id="temporary_password" name="temporary_password" type="password" autocomplete="new-password" required></div>
            <button class="button button-secondary" type="submit">Reset password</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <div><h2>All accounts</h2><p><?= count($people) ?> people shown.</p></div>
        <form method="get" class="inline-form"><div class="form-group"><label class="sr-only" for="q">Search</label><input id="q" name="q" placeholder="Search name, username or student number" value="<?= e($search) ?>"></div><button class="button button-secondary" type="submit">Search</button></form>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Person</th><th>Role</th><th>Academic details</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($people as $person): ?>
                <tr>
                    <td><strong><?= e($person['first_name'] . ' ' . $person['last_name']) ?></strong><br><span class="muted">@<?= e($person['username']) ?><?= $person['email'] ? ' · ' . e($person['email']) : '' ?></span></td>
                    <td><span class="badge"><?= e(ucfirst((string) $person['role'])) ?></span></td>
                    <td><?= $person['role'] === 'student' ? e(($person['student_number'] ?: '—') . ' · ' . ($person['program'] ?: 'Profile incomplete')) : '—' ?></td>
                    <td><span class="badge <?= $person['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= e(ucfirst((string) $person['status'])) ?></span></td>
                    <td class="table-actions"><a class="button button-secondary button-small" href="<?= e(app_path('users.php?edit=' . $person['id'])) ?>">Edit</a><?php if ((int) $person['id'] !== (int) $user['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= e($person['id']) ?>"><button class="button button-ghost button-small" type="submit"><?= $person['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
    const roleSelect = document.querySelector('[data-role-select]');
    const updateStudentFields = () => document.querySelectorAll('.student-field').forEach(field => field.hidden = roleSelect?.value !== 'student');
    roleSelect?.addEventListener('change', updateStudentFields); updateStudentFields();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
