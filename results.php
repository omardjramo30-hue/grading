<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_auth();
$courseId = query_int('course_id');
$studentId = query_int('student_id');
$view = 'selector';
$records = [];
$subject = null;

if ($user['role'] === 'student') {
    $subject = db()->fetch(
        'SELECT s.*, u.first_name, u.last_name, u.email FROM student_profiles s JOIN users u ON u.id = s.user_id WHERE s.user_id = :user_id',
        ['user_id' => $user['id']]
    );
    if ($subject === null) {
        http_response_code(404);
        exit('Your student profile is incomplete. Contact an administrator.');
    }
    $studentId = (int) ($subject['id'] ?? 0);
    $view = 'student';
} elseif ($courseId > 0) {
    if (!teacher_can_access_course($user, $courseId)) {
        http_response_code(403);
        exit('You do not have access to this course report.');
    }
    $subject = db()->fetch(
        'SELECT c.*, u.first_name AS teacher_first_name, u.last_name AS teacher_last_name FROM courses c LEFT JOIN users u ON u.id = c.teacher_id WHERE c.id = :id',
        ['id' => $courseId]
    );
    if ($subject === null) {
        http_response_code(404);
        exit('Course not found.');
    }
    $view = 'course';
} elseif ($studentId > 0) {
    $subject = db()->fetch(
        'SELECT s.*, u.first_name, u.last_name, u.email FROM student_profiles s JOIN users u ON u.id = s.user_id WHERE s.id = :id',
        ['id' => $studentId]
    );
    if ($subject === null) {
        http_response_code(404);
        exit('Student not found.');
    }
    if ($user['role'] === 'teacher') {
        $authorized = db()->fetch(
            'SELECT e.id FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.student_id = :student_id AND c.teacher_id = :teacher_id LIMIT 1',
            ['student_id' => $studentId, 'teacher_id' => $user['id']]
        );
        if ($authorized === null) {
            http_response_code(403);
            exit('You do not have access to this student record.');
        }
    }
    $view = 'student';
}

if ($view === 'student' && $studentId > 0) {
    $whereTeacher = $user['role'] === 'teacher' ? 'AND c.teacher_id = :teacher_id' : '';
    $params = ['student_id' => $studentId];
    if ($user['role'] === 'teacher') {
        $params['teacher_id'] = $user['id'];
    }
    $records = db()->fetchAll(
        "SELECT e.id AS enrollment_id, c.*, u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
         FROM enrollments e JOIN courses c ON c.id = e.course_id LEFT JOIN users u ON u.id = c.teacher_id
         WHERE e.student_id = :student_id {$whereTeacher}
         ORDER BY c.academic_year DESC, c.semester, c.code",
        $params
    );
    foreach ($records as &$record) {
        $record['result'] = enrollment_result((int) $record['enrollment_id']);
    }
    unset($record);
} elseif ($view === 'course') {
    $records = db()->fetchAll(
        'SELECT e.id AS enrollment_id, s.student_number, s.program, s.study_level, u.first_name, u.last_name
         FROM enrollments e JOIN student_profiles s ON s.id = e.student_id JOIN users u ON u.id = s.user_id
         WHERE e.course_id = :course_id ORDER BY u.first_name, u.last_name',
        ['course_id' => $courseId]
    );
    foreach ($records as &$record) {
        $record['result'] = enrollment_result((int) $record['enrollment_id']);
    }
    unset($record);
}

if (($_GET['format'] ?? '') === 'csv' && in_array($view, ['student', 'course'], true)) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . ($view === 'course' ? 'course-results-' . $courseId : 'student-transcript-' . $studentId) . '.csv"');
    $output = fopen('php://output', 'wb');
    fputs($output, "\xEF\xBB\xBF");
    if ($view === 'course') {
        fputcsv($output, ['Student Number', 'Student Name', 'Program', 'Percentage', 'Letter', 'Grade Points', 'Status']);
        foreach ($records as $record) {
            fputcsv($output, array_map('csv_safe', [$record['student_number'], $record['first_name'] . ' ' . $record['last_name'], $record['program'], $record['result']['percentage'], $record['result']['letter'], $record['result']['points'], $record['result']['complete'] ? 'Complete' : 'Incomplete']));
        }
    } else {
        fputcsv($output, ['Course Code', 'Course Name', 'Semester', 'Academic Year', 'Credits', 'Percentage', 'Letter', 'Grade Points', 'Status']);
        foreach ($records as $record) {
            fputcsv($output, array_map('csv_safe', [$record['code'], $record['name'], $record['semester'], $record['academic_year'], $record['credit_hours'], $record['result']['percentage'], $record['result']['letter'], $record['result']['points'], $record['result']['complete'] ? 'Complete' : 'Incomplete']));
        }
    }
    fclose($output);
    audit_log('report.exported', $view . ' report');
    exit;
}

$courses = [];
$students = [];
if (in_array($user['role'], ['admin', 'teacher'], true)) {
    $courseWhere = $user['role'] === 'teacher' ? 'WHERE c.teacher_id = :teacher_id' : '';
    $courseParams = $user['role'] === 'teacher' ? ['teacher_id' => $user['id']] : [];
    $courses = db()->fetchAll("SELECT c.id, c.code, c.name, c.semester, c.academic_year FROM courses c {$courseWhere} ORDER BY c.academic_year DESC, c.code", $courseParams);
    if ($user['role'] === 'admin') {
        $students = db()->fetchAll('SELECT s.id, s.student_number, u.first_name, u.last_name FROM student_profiles s JOIN users u ON u.id = s.user_id ORDER BY u.first_name, u.last_name');
    } else {
        $students = db()->fetchAll(
            'SELECT DISTINCT s.id, s.student_number, u.first_name, u.last_name FROM student_profiles s JOIN users u ON u.id = s.user_id JOIN enrollments e ON e.student_id = s.id JOIN courses c ON c.id = e.course_id WHERE c.teacher_id = :teacher_id ORDER BY u.first_name, u.last_name',
            ['teacher_id' => $user['id']]
        );
    }
}

$totalCredits = 0.0;
$qualityPoints = 0.0;
if ($view === 'student') {
    foreach ($records as $record) {
        if ($record['result']['complete']) {
            $credits = (float) $record['credit_hours'];
            $totalCredits += $credits;
            $qualityPoints += $record['result']['points'] * $credits;
        }
    }
}
$gpa = $totalCredits > 0 ? round($qualityPoints / $totalCredits, 2) : 0.0;

$page_title = $view === 'course' ? 'Course report' : ($view === 'student' ? 'Student transcript' : 'Results');
require __DIR__ . '/partials/header.php';
?>
<div class="page-heading">
    <div><h1><?= $view === 'course' ? 'Course report' : ($view === 'student' ? 'Academic transcript' : 'Results and reports') ?></h1><p><?= $view === 'selector' ? 'Open a course summary or an individual student transcript.' : 'Calculated from the assessment weights currently configured in the gradebook.' ?></p></div>
    <?php if ($view !== 'selector'): ?><div class="page-actions no-print"><button class="button button-secondary" type="button" onclick="window.print()">Print</button><a class="button" href="<?= e(app_path('results.php?' . ($view === 'course' ? 'course_id=' . $courseId : 'student_id=' . $studentId) . '&format=csv')) ?>">Export CSV</a><?php if ($user['role'] !== 'student'): ?><a class="button button-secondary" href="<?= e(app_path('results.php')) ?>">Choose another report</a><?php endif; ?></div><?php endif; ?>
</div>

<?php if ($user['role'] !== 'student'): ?>
<section class="panel no-print">
    <div class="grid-2">
        <form method="get" class="inline-form"><div class="form-group"><label for="course_id">Course report</label><select id="course_id" name="course_id" required><option value="">Choose course</option><?php foreach ($courses as $course): ?><option value="<?= e($course['id']) ?>"<?= selected($course['id'], $courseId) ?>><?= e($course['code'] . ' — ' . $course['name'] . ' · ' . $course['semester'] . ' ' . $course['academic_year']) ?></option><?php endforeach; ?></select></div><button class="button" type="submit">Open</button></form>
        <form method="get" class="inline-form"><div class="form-group"><label for="student_id">Student transcript</label><select id="student_id" name="student_id" required><option value="">Choose student</option><?php foreach ($students as $student): ?><option value="<?= e($student['id']) ?>"<?= selected($student['id'], $studentId) ?>><?= e($student['student_number'] . ' — ' . $student['first_name'] . ' ' . $student['last_name']) ?></option><?php endforeach; ?></select></div><button class="button" type="submit">Open</button></form>
    </div>
</section>
<?php endif; ?>

<?php if ($view === 'selector'): ?>
    <section class="panel empty-state"><h2>Select a report</h2><p>Use the selectors above to review overall course performance or a student’s academic record.</p></section>
<?php elseif ($view === 'course'): ?>
    <section class="panel">
        <div class="panel-header"><div><h2><?= e($subject['code'] . ' — ' . $subject['name']) ?></h2><p><?= e($subject['semester'] . ' · ' . $subject['academic_year']) ?><?= $subject['teacher_id'] ? ' · ' . e($subject['teacher_first_name'] . ' ' . $subject['teacher_last_name']) : '' ?></p></div><span class="badge"><?= count($records) ?> students</span></div>
        <?php if ($records === []): ?><div class="empty-state"><h2>No results</h2><p>No students are enrolled in this course.</p></div><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Student</th><th>Program</th><th class="number">Percentage</th><th class="number">Letter</th><th class="number">Points</th><th>Status</th></tr></thead><tbody><?php foreach ($records as $record): ?><tr><td><strong><?= e($record['first_name'] . ' ' . $record['last_name']) ?></strong><br><span class="muted"><?= e($record['student_number']) ?></span></td><td><?= e($record['program']) ?><br><span class="muted"><?= e($record['study_level']) ?></span></td><td class="number"><?= e(number_format($record['result']['percentage'], 2)) ?>%</td><td class="number"><strong><?= e($record['result']['letter']) ?></strong></td><td class="number"><?= e(number_format($record['result']['points'], 1)) ?></td><td><span class="badge <?= $record['result']['complete'] ? 'badge-success' : 'badge-warning' ?>"><?= $record['result']['complete'] ? 'Complete' : 'Incomplete' ?></span></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="panel">
        <div class="panel-header"><div><h2><?= e($subject['first_name'] . ' ' . $subject['last_name']) ?></h2><p><?= e($subject['student_number'] . ' · ' . $subject['program'] . ' · ' . $subject['study_level']) ?></p></div><div><span class="badge badge-success">GPA <?= e(number_format($gpa, 2)) ?></span> <span class="badge"><?= e(number_format($totalCredits, 0)) ?> completed credits</span></div></div>
        <?php if ($records === []): ?><div class="empty-state"><h2>No academic record</h2><p>This student has not been enrolled in a course.</p></div><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Course</th><th>Period</th><th>Credits</th><th class="number">Percentage</th><th class="number">Letter</th><th class="number">Points</th><th>Status</th></tr></thead><tbody><?php foreach ($records as $record): ?><tr><td><strong><?= e($record['code']) ?></strong><br><span class="muted"><?= e($record['name']) ?></span></td><td><?= e($record['semester']) ?><br><span class="muted"><?= e($record['academic_year']) ?></span></td><td><?= e($record['credit_hours']) ?></td><td class="number"><?= e(number_format($record['result']['percentage'], 2)) ?>%</td><td class="number"><strong><?= e($record['result']['letter']) ?></strong></td><td class="number"><?= e(number_format($record['result']['points'], 1)) ?></td><td><span class="badge <?= $record['result']['complete'] ? 'badge-success' : 'badge-warning' ?>"><?= $record['result']['complete'] ? 'Complete' : 'Incomplete' ?></span></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
