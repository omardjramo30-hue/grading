<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_role(['admin', 'teacher']);
$courseId = request_is_post() ? (int) ($_POST['course_id'] ?? 0) : query_int('course_id');

if ($courseId < 1 || !teacher_can_access_course($user, $courseId)) {
    if ($courseId > 0) {
        http_response_code(403);
        exit('You do not have access to this course.');
    }
    $where = $user['role'] === 'teacher' ? 'WHERE c.teacher_id = :teacher_id' : '';
    $params = $user['role'] === 'teacher' ? ['teacher_id' => $user['id']] : [];
    $availableCourses = db()->fetchAll(
        "SELECT c.*, COUNT(DISTINCT e.id) AS enrollment_count
         FROM courses c LEFT JOIN enrollments e ON e.course_id = c.id {$where}
         GROUP BY c.id ORDER BY c.academic_year DESC, c.semester, c.code",
        $params
    );
    $page_title = 'Gradebook';
    require __DIR__ . '/partials/header.php';
    ?>
    <div class="page-heading"><div><h1>Gradebook</h1><p>Select a course to configure assessments and enter student scores.</p></div></div>
    <section class="panel">
        <?php if ($availableCourses === []): ?>
            <div class="empty-state"><h2>No courses available</h2><p>No accessible course has been created or assigned.</p></div>
        <?php else: ?>
            <div class="grid-3"><?php foreach ($availableCourses as $available): ?><article class="panel"><span class="badge"><?= e($available['semester']) ?></span><h2><?= e($available['code']) ?></h2><p><?= e($available['name']) ?></p><p class="muted"><?= e($available['enrollment_count']) ?> students · <?= e($available['academic_year']) ?></p><a class="button" href="<?= e(app_path('grades.php?course_id=' . $available['id'])) ?>">Open gradebook</a></article><?php endforeach; ?></div>
        <?php endif; ?>
    </section>
    <?php require __DIR__ . '/partials/footer.php'; exit;
}

$course = db()->fetch(
    'SELECT c.*, u.first_name AS teacher_first_name, u.last_name AS teacher_last_name FROM courses c LEFT JOIN users u ON u.id = c.teacher_id WHERE c.id = :id',
    ['id' => $courseId]
);
if ($course === null) {
    http_response_code(404);
    exit('Course not found.');
}

if (request_is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'create_assessment') {
        $name = input('name');
        $maxScore = (float) ($_POST['max_score'] ?? 0);
        $weight = (float) ($_POST['weight'] ?? 0);
        $dueDate = input('due_date');
        $weightRow = db()->fetch('SELECT COALESCE(SUM(weight), 0) AS total FROM assessments WHERE course_id = :course_id', ['course_id' => $courseId]);
        $existingWeight = (float) ($weightRow['total'] ?? 0);

        if ($name === '' || strlen($name) > 120) {
            flash('danger', 'Assessment name is required and must not exceed 120 characters.');
        } elseif ($maxScore <= 0 || $maxScore > 10000) {
            flash('danger', 'Maximum score must be greater than zero.');
        } elseif ($weight <= 0 || $weight > 100 || $existingWeight + $weight > 100.001) {
            flash('danger', 'Assessment weight must be positive and total course weight cannot exceed 100%.');
        } elseif ($dueDate !== '' && DateTime::createFromFormat('Y-m-d', $dueDate) === false) {
            flash('danger', 'Enter a valid due date.');
        } else {
            try {
                db()->execute(
                    'INSERT INTO assessments (course_id, name, max_score, weight, due_date, created_at)
                     VALUES (:course_id, :name, :max_score, :weight, :due_date, :created_at)',
                    ['course_id' => $courseId, 'name' => $name, 'max_score' => $maxScore, 'weight' => $weight, 'due_date' => $dueDate === '' ? null : $dueDate, 'created_at' => now()]
                );
                audit_log('assessment.created', 'Course: ' . $course['code'] . '; assessment: ' . $name);
                flash('success', 'Assessment added.');
            } catch (Throwable $exception) {
                flash('danger', 'An assessment with that name already exists in this course.');
            }
        }
    } elseif ($action === 'delete_assessment') {
        $assessmentId = (int) ($_POST['assessment_id'] ?? 0);
        $assessment = db()->fetch('SELECT id, name FROM assessments WHERE id = :id AND course_id = :course_id', ['id' => $assessmentId, 'course_id' => $courseId]);
        if ($assessment !== null) {
            db()->execute('DELETE FROM assessments WHERE id = :id', ['id' => $assessmentId]);
            audit_log('assessment.deleted', 'Course: ' . $course['code'] . '; assessment: ' . $assessment['name']);
            flash('success', 'Assessment and its recorded scores were deleted.');
        }
    } elseif ($action === 'save_grades') {
        $assessments = db()->fetchAll('SELECT id, name, max_score FROM assessments WHERE course_id = :course_id', ['course_id' => $courseId]);
        $assessmentMap = [];
        foreach ($assessments as $assessment) {
            $assessmentMap[(int) $assessment['id']] = $assessment;
        }
        $enrollments = db()->fetchAll('SELECT id FROM enrollments WHERE course_id = :course_id', ['course_id' => $courseId]);
        $enrollmentIds = array_map(static fn (array $row): int => (int) $row['id'], $enrollments);
        $submitted = $_POST['scores'] ?? [];
        $validationErrors = [];

        foreach ($submitted as $enrollmentId => $scores) {
            if (!in_array((int) $enrollmentId, $enrollmentIds, true) || !is_array($scores)) {
                continue;
            }
            foreach ($scores as $assessmentId => $scoreValue) {
                if (!isset($assessmentMap[(int) $assessmentId])) {
                    continue;
                }
                $scoreText = trim((string) $scoreValue);
                if ($scoreText === '') {
                    continue;
                }
                if (!is_numeric($scoreText) || (float) $scoreText < 0 || (float) $scoreText > (float) $assessmentMap[(int) $assessmentId]['max_score']) {
                    $validationErrors[] = $assessmentMap[(int) $assessmentId]['name'] . ' contains a score outside its allowed range.';
                }
            }
        }

        if ($validationErrors !== []) {
            flash('danger', implode(' ', array_unique($validationErrors)));
        } else {
            db()->transaction(function (Database $database) use ($submitted, $enrollmentIds, $assessmentMap, $user): void {
                foreach ($submitted as $enrollmentId => $scores) {
                    if (!in_array((int) $enrollmentId, $enrollmentIds, true) || !is_array($scores)) {
                        continue;
                    }
                    foreach ($scores as $assessmentId => $scoreValue) {
                        $assessmentId = (int) $assessmentId;
                        if (!isset($assessmentMap[$assessmentId])) {
                            continue;
                        }
                        $scoreText = trim((string) $scoreValue);
                        $existing = $database->fetch(
                            'SELECT id FROM grades WHERE assessment_id = :assessment_id AND enrollment_id = :enrollment_id',
                            ['assessment_id' => $assessmentId, 'enrollment_id' => (int) $enrollmentId]
                        );
                        if ($scoreText === '') {
                            if ($existing !== null) {
                                $database->execute('DELETE FROM grades WHERE id = :id', ['id' => $existing['id']]);
                            }
                            continue;
                        }
                        $params = [
                            'score' => (float) $scoreText,
                            'graded_by' => $user['id'],
                            'updated_at' => now(),
                        ];
                        if ($existing === null) {
                            $params['assessment_id'] = $assessmentId;
                            $params['enrollment_id'] = (int) $enrollmentId;
                            $database->execute(
                                'INSERT INTO grades (assessment_id, enrollment_id, score, graded_by, updated_at)
                                 VALUES (:assessment_id, :enrollment_id, :score, :graded_by, :updated_at)',
                                $params
                            );
                        } else {
                            $params['id'] = $existing['id'];
                            $database->execute('UPDATE grades SET score = :score, graded_by = :graded_by, updated_at = :updated_at WHERE id = :id', $params);
                        }
                    }
                }
            });
            audit_log('grades.saved', 'Course: ' . $course['code']);
            flash('success', 'Grades saved successfully.');
        }
    }
    redirect('grades.php?course_id=' . $courseId);
}

$assessments = db()->fetchAll('SELECT * FROM assessments WHERE course_id = :course_id ORDER BY id', ['course_id' => $courseId]);
$totalWeight = array_sum(array_map(static fn (array $assessment): float => (float) $assessment['weight'], $assessments));
$students = db()->fetchAll(
    'SELECT e.id AS enrollment_id, s.student_number, u.first_name, u.last_name
     FROM enrollments e JOIN student_profiles s ON s.id = e.student_id JOIN users u ON u.id = s.user_id
     WHERE e.course_id = :course_id ORDER BY u.first_name, u.last_name',
    ['course_id' => $courseId]
);
$gradeRows = db()->fetchAll(
    'SELECT g.assessment_id, g.enrollment_id, g.score FROM grades g JOIN assessments a ON a.id = g.assessment_id WHERE a.course_id = :course_id',
    ['course_id' => $courseId]
);
$gradeMap = [];
foreach ($gradeRows as $grade) {
    $gradeMap[(int) $grade['enrollment_id']][(int) $grade['assessment_id']] = $grade['score'];
}

$page_title = 'Gradebook · ' . $course['code'];
require __DIR__ . '/partials/header.php';
?>
<div class="page-heading">
    <div><span class="badge"><?= e($course['semester'] . ' · ' . $course['academic_year']) ?></span><h1><?= e($course['code']) ?> — <?= e($course['name']) ?></h1><p><?= e($course['credit_hours']) ?> credit hours · <?= $course['teacher_id'] ? e($course['teacher_first_name'] . ' ' . $course['teacher_last_name']) : 'Teacher unassigned' ?></p></div>
    <div class="page-actions no-print"><a class="button button-secondary" href="<?= e(app_path('courses.php')) ?>">Back to courses</a><?php if ($user['role'] === 'admin'): ?><a class="button button-secondary" href="<?= e(app_path('enrollments.php?course_id=' . $courseId)) ?>">Manage enrollment</a><?php endif; ?><a class="button" href="<?= e(app_path('results.php?course_id=' . $courseId)) ?>">Course report</a></div>
</div>

<div class="grid-2 no-print">
    <section class="panel">
        <div class="panel-header"><div><h2>Add assessment</h2><p>The combined assessment weight cannot exceed 100%.</p></div><span class="badge <?= abs($totalWeight - 100) < .01 ? 'badge-success' : 'badge-warning' ?>"><?= e(number_format($totalWeight, 1)) ?>% configured</span></div>
        <form method="post" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="action" value="create_assessment"><input type="hidden" name="course_id" value="<?= e($courseId) ?>">
            <div class="form-group"><label for="name">Assessment name</label><input id="name" name="name" maxlength="120" placeholder="e.g. Midterm exam" required></div>
            <div class="form-group"><label for="max_score">Maximum score</label><input id="max_score" name="max_score" type="number" min="0.01" max="10000" step="0.01" required></div>
            <div class="form-group"><label for="weight">Weight (%)</label><input id="weight" name="weight" type="number" min="0.01" max="100" step="0.01" required></div>
            <div class="form-group"><label for="due_date">Due date</label><input id="due_date" name="due_date" type="date"></div>
            <div class="form-group full"><button class="button" type="submit">Add assessment</button></div>
        </form>
    </section>
    <section class="panel">
        <div class="panel-header"><div><h2>Assessment structure</h2><p><?= count($assessments) ?> grading components.</p></div></div>
        <?php if ($assessments === []): ?><div class="empty-state"><h2>No assessments</h2><p>Add assignments, tests and exams before entering scores.</p></div><?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Component</th><th>Max</th><th>Weight</th><th></th></tr></thead><tbody><?php foreach ($assessments as $assessment): ?><tr><td><strong><?= e($assessment['name']) ?></strong><?= $assessment['due_date'] ? '<br><span class="muted">Due ' . e($assessment['due_date']) . '</span>' : '' ?></td><td><?= e(number_format((float) $assessment['max_score'], 2)) ?></td><td><?= e(number_format((float) $assessment['weight'], 2)) ?>%</td><td><form method="post" onsubmit="return confirm('Delete this assessment and all of its scores?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_assessment"><input type="hidden" name="course_id" value="<?= e($courseId) ?>"><input type="hidden" name="assessment_id" value="<?= e($assessment['id']) ?>"><button class="button button-ghost button-small" type="submit">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
</div>

<section class="panel">
    <div class="panel-header"><div><h2>Student gradebook</h2><p>Leave a field blank when a score has not yet been graded.</p></div><span class="badge"><?= count($students) ?> students</span></div>
    <?php if ($students === []): ?>
        <div class="empty-state"><h2>No students enrolled</h2><p>Enroll students before entering grades.</p><?php if ($user['role'] === 'admin'): ?><a class="button" href="<?= e(app_path('enrollments.php?course_id=' . $courseId)) ?>">Manage enrollment</a><?php endif; ?></div>
    <?php elseif ($assessments === []): ?>
        <div class="empty-state"><h2>Assessment setup required</h2><p>Add at least one assessment before entering grades.</p></div>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_grades"><input type="hidden" name="course_id" value="<?= e($courseId) ?>">
        <div class="table-wrap"><table><thead><tr><th>Student</th><?php foreach ($assessments as $assessment): ?><th class="number"><?= e($assessment['name']) ?><br><span class="muted">/ <?= e(number_format((float) $assessment['max_score'], 2)) ?></span></th><?php endforeach; ?><th class="number">Result</th></tr></thead><tbody>
        <?php foreach ($students as $student): $result = enrollment_result((int) $student['enrollment_id']); ?><tr><td><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong><br><span class="muted"><?= e($student['student_number']) ?></span></td><?php foreach ($assessments as $assessment): ?><td class="number"><label class="sr-only" for="score-<?= e($student['enrollment_id']) ?>-<?= e($assessment['id']) ?>"><?= e($assessment['name'] . ' score for ' . $student['first_name'] . ' ' . $student['last_name']) ?></label><input class="grade-input" id="score-<?= e($student['enrollment_id']) ?>-<?= e($assessment['id']) ?>" name="scores[<?= e($student['enrollment_id']) ?>][<?= e($assessment['id']) ?>]" type="number" min="0" max="<?= e($assessment['max_score']) ?>" step="0.01" value="<?= e($gradeMap[(int) $student['enrollment_id']][(int) $assessment['id']] ?? '') ?>"></td><?php endforeach; ?><td class="number"><strong><?= e(number_format($result['percentage'], 2)) ?>%</strong><br><span class="badge <?= $result['complete'] ? 'badge-success' : 'badge-warning' ?>"><?= $result['complete'] ? e($result['letter']) : 'Incomplete' ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="page-actions no-print" style="margin-top:16px"><button class="button" type="submit">Save all grades</button><button class="button button-secondary" type="button" onclick="window.print()">Print gradebook</button></div>
    </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
