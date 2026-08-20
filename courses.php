<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_role(['admin', 'teacher']);
$errors = [];

if (request_is_post()) {
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Access denied.');
    }
    verify_csrf();
    $action = input('action');
    if ($action === 'create' || $action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $code = strtoupper(input('code'));
        $name = input('name');
        $creditHours = (int) ($_POST['credit_hours'] ?? 0);
        $teacherId = (int) ($_POST['teacher_id'] ?? 0);
        $semester = input('semester');
        $academicYear = input('academic_year');

        if (!preg_match('/^[A-Z0-9._-]{2,40}$/', $code)) {
            $errors[] = 'Course code must be 2–40 letters, numbers, dots, dashes or underscores.';
        }
        if ($name === '') {
            $errors[] = 'Course name is required.';
        }
        if ($creditHours < 1 || $creditHours > 12) {
            $errors[] = 'Credit hours must be between 1 and 12.';
        }
        if ($semester === '' || $academicYear === '') {
            $errors[] = 'Semester and academic year are required.';
        }
        if ($teacherId > 0 && db()->fetch("SELECT id FROM users WHERE id = :id AND role = 'teacher' AND status = 'active'", ['id' => $teacherId]) === null) {
            $errors[] = 'Select a valid active teacher.';
        }

        if ($errors === []) {
            try {
                $params = [
                    'code' => $code,
                    'name' => $name,
                    'credit_hours' => $creditHours,
                    'teacher_id' => $teacherId > 0 ? $teacherId : null,
                    'semester' => $semester,
                    'academic_year' => $academicYear,
                    'updated_at' => now(),
                ];
                if ($action === 'create') {
                    $params['status'] = 'active';
                    $params['created_at'] = now();
                    db()->execute(
                        'INSERT INTO courses (code, name, credit_hours, teacher_id, semester, academic_year, status, created_at, updated_at)
                         VALUES (:code, :name, :credit_hours, :teacher_id, :semester, :academic_year, :status, :created_at, :updated_at)',
                        $params
                    );
                    $id = db()->lastInsertId();
                } else {
                    $params['id'] = $id;
                    db()->execute(
                        'UPDATE courses SET code = :code, name = :name, credit_hours = :credit_hours, teacher_id = :teacher_id, semester = :semester, academic_year = :academic_year, updated_at = :updated_at WHERE id = :id',
                        $params
                    );
                }
                audit_log('course.' . ($action === 'create' ? 'created' : 'updated'), 'Course: ' . $code);
                flash('success', $action === 'create' ? 'Course created.' : 'Course updated.');
                redirect('courses.php');
            } catch (Throwable $exception) {
                error_log($exception->__toString());
                $errors[] = str_contains(strtolower($exception->getMessage()), 'unique') || str_contains(strtolower($exception->getMessage()), 'duplicate')
                    ? 'That course code already exists for this semester and academic year.'
                    : 'The course could not be saved.';
            }
        }
    } elseif ($action === 'toggle_status') {
        $id = (int) ($_POST['id'] ?? 0);
        $course = db()->fetch('SELECT id, code, status FROM courses WHERE id = :id', ['id' => $id]);
        if ($course !== null) {
            $status = $course['status'] === 'active' ? 'archived' : 'active';
            db()->execute('UPDATE courses SET status = :status, updated_at = :updated_at WHERE id = :id', ['status' => $status, 'updated_at' => now(), 'id' => $id]);
            audit_log('course.status_changed', 'Course: ' . $course['code'] . '; status: ' . $status);
            flash('success', 'Course status updated.');
        }
        redirect('courses.php');
    }
}

$editId = query_int('edit');
$editing = $user['role'] === 'admin' && $editId > 0 ? db()->fetch('SELECT * FROM courses WHERE id = :id', ['id' => $editId]) : null;
$showForm = $user['role'] === 'admin' && (isset($_GET['create']) || $editing !== null || $errors !== []);
$teachers = $user['role'] === 'admin' ? db()->fetchAll("SELECT id, first_name, last_name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY first_name, last_name") : [];
$where = $user['role'] === 'teacher' ? 'WHERE c.teacher_id = :teacher_id' : '';
$params = $user['role'] === 'teacher' ? ['teacher_id' => $user['id']] : [];
$courses = db()->fetchAll(
    "SELECT c.*, u.first_name AS teacher_first_name, u.last_name AS teacher_last_name,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS enrollment_count,
            (SELECT COUNT(*) FROM assessments a WHERE a.course_id = c.id) AS assessment_count,
            (SELECT COALESCE(SUM(a.weight), 0) FROM assessments a WHERE a.course_id = c.id) AS total_weight
     FROM courses c
     LEFT JOIN users u ON u.id = c.teacher_id
     {$where}
     ORDER BY c.academic_year DESC, c.semester, c.code",
    $params
);

$page_title = $user['role'] === 'teacher' ? 'My courses' : 'Courses';
require __DIR__ . '/partials/header.php';
?>
<div class="page-heading">
    <div><h1><?= $user['role'] === 'teacher' ? 'My courses' : 'Courses' ?></h1><p><?= $user['role'] === 'teacher' ? 'Open a course to configure assessments and enter grades.' : 'Manage teaching assignments, semesters and course activity.' ?></p></div>
    <?php if ($user['role'] === 'admin'): ?><a class="button" href="<?= e(app_path('courses.php?create=1')) ?>">Create course</a><?php endif; ?>
</div>

<?php if ($errors !== []): ?><div class="alert alert-danger"><strong>Course not saved.</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($showForm): ?>
<section class="panel">
    <div class="panel-header"><div><h2><?= $editing ? 'Edit course' : 'Create course' ?></h2><p>Course codes are unique within a semester and academic year.</p></div><a href="<?= e(app_path('courses.php')) ?>">Close</a></div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>"><?php if ($editing): ?><input type="hidden" name="id" value="<?= e($editing['id']) ?>"><?php endif; ?>
        <div class="form-group"><label for="code">Course code</label><input id="code" name="code" maxlength="40" required value="<?= e($_POST['code'] ?? $editing['code'] ?? '') ?>"></div>
        <div class="form-group"><label for="name">Course name</label><input id="name" name="name" maxlength="190" required value="<?= e($_POST['name'] ?? $editing['name'] ?? '') ?>"></div>
        <div class="form-group"><label for="credit_hours">Credit hours</label><input id="credit_hours" name="credit_hours" type="number" min="1" max="12" required value="<?= e($_POST['credit_hours'] ?? $editing['credit_hours'] ?? 3) ?>"></div>
        <div class="form-group"><label for="teacher_id">Assigned teacher</label><select id="teacher_id" name="teacher_id"><option value="0">Unassigned</option><?php foreach ($teachers as $teacher): ?><option value="<?= e($teacher['id']) ?>"<?= selected($teacher['id'], $_POST['teacher_id'] ?? $editing['teacher_id'] ?? 0) ?>><?= e($teacher['first_name'] . ' ' . $teacher['last_name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="semester">Semester</label><input id="semester" name="semester" maxlength="40" placeholder="e.g. Semester 1" required value="<?= e($_POST['semester'] ?? $editing['semester'] ?? '') ?>"></div>
        <div class="form-group"><label for="academic_year">Academic year</label><input id="academic_year" name="academic_year" maxlength="20" placeholder="e.g. 2026/2027" required value="<?= e($_POST['academic_year'] ?? $editing['academic_year'] ?? '') ?>"></div>
        <div class="form-group full"><button class="button" type="submit"><?= $editing ? 'Save changes' : 'Create course' ?></button></div>
    </form>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header"><div><h2><?= count($courses) ?> course<?= count($courses) === 1 ? '' : 's' ?></h2><p>Assessment weights should total 100% before results are finalized.</p></div></div>
    <?php if ($courses === []): ?>
        <div class="empty-state"><h2>No courses available</h2><p><?= $user['role'] === 'teacher' ? 'An administrator has not assigned a course to you yet.' : 'Create the first course to begin enrolling students.' ?></p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Course</th><th>Teacher</th><th>Period</th><th>Students</th><th>Assessment setup</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($courses as $course): ?>
                <tr>
                    <td><strong><?= e($course['code']) ?></strong><br><span class="muted"><?= e($course['name']) ?> · <?= e($course['credit_hours']) ?> credits</span></td>
                    <td><?= $course['teacher_id'] ? e($course['teacher_first_name'] . ' ' . $course['teacher_last_name']) : '<span class="muted">Unassigned</span>' ?></td>
                    <td><?= e($course['semester']) ?><br><span class="muted"><?= e($course['academic_year']) ?></span></td>
                    <td><?= e($course['enrollment_count']) ?></td>
                    <td><span class="badge <?= abs((float) $course['total_weight'] - 100) < 0.01 ? 'badge-success' : 'badge-warning' ?>"><?= e($course['assessment_count']) ?> components · <?= e(number_format((float) $course['total_weight'], 1)) ?>%</span></td>
                    <td><span class="badge <?= $course['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= e(ucfirst((string) $course['status'])) ?></span></td>
                    <td class="table-actions">
                        <a class="button button-secondary button-small" href="<?= e(app_path('grades.php?course_id=' . $course['id'])) ?>">Grades</a>
                        <?php if ($user['role'] === 'admin'): ?>
                            <a class="button button-secondary button-small" href="<?= e(app_path('enrollments.php?course_id=' . $course['id'])) ?>">Enroll</a>
                            <a class="button button-secondary button-small" href="<?= e(app_path('courses.php?edit=' . $course['id'])) ?>">Edit</a>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= e($course['id']) ?>"><button class="button button-ghost button-small" type="submit"><?= $course['status'] === 'active' ? 'Archive' : 'Restore' ?></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
