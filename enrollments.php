<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_role('admin');
$courseId = query_int('course_id');

if (request_is_post()) {
    verify_csrf();
    $action = input('action');
    $courseId = (int) ($_POST['course_id'] ?? 0);
    $course = db()->fetch('SELECT id, code FROM courses WHERE id = :id', ['id' => $courseId]);
    if ($course === null) {
        flash('danger', 'Course not found.');
        redirect('enrollments.php');
    }

    if ($action === 'enroll') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $student = db()->fetch('SELECT id, student_number FROM student_profiles WHERE id = :id', ['id' => $studentId]);
        if ($student === null) {
            flash('danger', 'Select a valid student.');
        } else {
            try {
                db()->execute(
                    'INSERT INTO enrollments (student_id, course_id, enrolled_at) VALUES (:student_id, :course_id, :enrolled_at)',
                    ['student_id' => $studentId, 'course_id' => $courseId, 'enrolled_at' => now()]
                );
                audit_log('enrollment.created', 'Course: ' . $course['code'] . '; student: ' . $student['student_number']);
                flash('success', 'Student enrolled successfully.');
            } catch (Throwable $exception) {
                flash('warning', 'The student is already enrolled in this course.');
            }
        }
    } elseif ($action === 'remove') {
        $enrollmentId = (int) ($_POST['enrollment_id'] ?? 0);
        $enrollment = db()->fetch(
            'SELECT e.id, s.student_number FROM enrollments e JOIN student_profiles s ON s.id = e.student_id WHERE e.id = :id AND e.course_id = :course_id',
            ['id' => $enrollmentId, 'course_id' => $courseId]
        );
        if ($enrollment !== null) {
            db()->execute('DELETE FROM enrollments WHERE id = :id', ['id' => $enrollmentId]);
            audit_log('enrollment.removed', 'Course: ' . $course['code'] . '; student: ' . $enrollment['student_number']);
            flash('success', 'Enrollment and its associated grades were removed.');
        }
    }
    redirect('enrollments.php?course_id=' . $courseId);
}

$courses = db()->fetchAll(
    "SELECT c.id, c.code, c.name, c.semester, c.academic_year, COUNT(e.id) AS enrollment_count
     FROM courses c LEFT JOIN enrollments e ON e.course_id = c.id
     WHERE c.status = 'active'
     GROUP BY c.id ORDER BY c.academic_year DESC, c.semester, c.code"
);
$course = $courseId > 0 ? db()->fetch('SELECT * FROM courses WHERE id = :id', ['id' => $courseId]) : null;
$enrolled = [];
$available = [];
if ($course !== null) {
    $enrolled = db()->fetchAll(
        'SELECT e.id AS enrollment_id, s.id AS student_id, s.student_number, s.program, s.study_level, u.first_name, u.last_name, u.email
         FROM enrollments e
         JOIN student_profiles s ON s.id = e.student_id
         JOIN users u ON u.id = s.user_id
         WHERE e.course_id = :course_id
         ORDER BY u.first_name, u.last_name',
        ['course_id' => $courseId]
    );
    $available = db()->fetchAll(
        "SELECT s.id, s.student_number, s.program, u.first_name, u.last_name
         FROM student_profiles s JOIN users u ON u.id = s.user_id
         WHERE u.status = 'active' AND NOT EXISTS (
             SELECT 1 FROM enrollments e WHERE e.student_id = s.id AND e.course_id = :course_id
         ) ORDER BY u.first_name, u.last_name",
        ['course_id' => $courseId]
    );
}

$page_title = 'Enrollments';
require __DIR__ . '/partials/header.php';
?>
<div class="page-heading">
    <div><h1>Enrollments</h1><p>Add students to a course or remove registrations that were entered in error.</p></div>
    <a class="button button-secondary" href="<?= e(app_path('courses.php')) ?>">Back to courses</a>
</div>

<section class="panel">
    <form method="get" class="inline-form">
        <div class="form-group"><label for="course_id">Select course</label><select id="course_id" name="course_id" required><option value="">Choose a course</option><?php foreach ($courses as $item): ?><option value="<?= e($item['id']) ?>"<?= selected($item['id'], $courseId) ?>><?= e($item['code'] . ' — ' . $item['name'] . ' (' . $item['semester'] . ', ' . $item['academic_year'] . ')') ?></option><?php endforeach; ?></select></div>
        <button class="button" type="submit">Open course</button>
    </form>
</section>

<?php if ($course !== null): ?>
<div class="grid-2">
    <section class="panel">
        <div class="panel-header"><div><h2>Enroll a student</h2><p><?= count($available) ?> active students available.</p></div></div>
        <?php if ($available === []): ?>
            <div class="empty-state"><h2>No students available</h2><p>All active students are already enrolled, or student accounts have not been created.</p><a href="<?= e(app_path('users.php?create=1')) ?>">Create a student account</a></div>
        <?php else: ?>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="enroll"><input type="hidden" name="course_id" value="<?= e($courseId) ?>">
            <div class="form-group"><label for="student_id">Student</label><select id="student_id" name="student_id" required><option value="">Choose a student</option><?php foreach ($available as $student): ?><option value="<?= e($student['id']) ?>"><?= e($student['student_number'] . ' — ' . $student['first_name'] . ' ' . $student['last_name'] . ' · ' . $student['program']) ?></option><?php endforeach; ?></select></div>
            <button class="button" type="submit">Enroll student</button>
        </form>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2><?= e($course['code']) ?> — <?= e($course['name']) ?></h2>
        <p class="muted"><?= e($course['semester']) ?> · <?= e($course['academic_year']) ?> · <?= e($course['credit_hours']) ?> credit hours</p>
        <a class="button button-secondary" href="<?= e(app_path('grades.php?course_id=' . $courseId)) ?>">Open gradebook</a>
    </section>
</div>

<section class="panel">
    <div class="panel-header"><div><h2>Enrolled students</h2><p><?= count($enrolled) ?> registrations in this course.</p></div></div>
    <?php if ($enrolled === []): ?>
        <div class="empty-state"><h2>No enrolled students</h2><p>Use the enrollment form to add the first student.</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Student</th><th>Number</th><th>Program</th><th>Level</th><th>Action</th></tr></thead>
            <tbody><?php foreach ($enrolled as $student): ?><tr><td><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong><br><span class="muted"><?= e($student['email'] ?: 'No email') ?></span></td><td><?= e($student['student_number']) ?></td><td><?= e($student['program']) ?></td><td><?= e($student['study_level']) ?></td><td><form method="post" onsubmit="return confirm('Remove this enrollment and its grades?')"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="course_id" value="<?= e($courseId) ?>"><input type="hidden" name="enrollment_id" value="<?= e($student['enrollment_id']) ?>"><button class="button button-ghost button-small" type="submit">Remove</button></form></td></tr><?php endforeach; ?></tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
