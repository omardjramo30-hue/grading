<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_auth();

$page_title = 'Dashboard';
$stats = [];
$recent = [];

if ($user['role'] === 'admin') {
    $stats = [
        ['label' => 'Active students', 'value' => db()->fetch("SELECT COUNT(*) AS total FROM users WHERE role = 'student' AND status = 'active'")['total'], 'note' => 'Student accounts'],
        ['label' => 'Teachers', 'value' => db()->fetch("SELECT COUNT(*) AS total FROM users WHERE role = 'teacher' AND status = 'active'")['total'], 'note' => 'Active instructors'],
        ['label' => 'Active courses', 'value' => db()->fetch("SELECT COUNT(*) AS total FROM courses WHERE status = 'active'")['total'], 'note' => 'Across all semesters'],
        ['label' => 'Enrollments', 'value' => db()->fetch('SELECT COUNT(*) AS total FROM enrollments')['total'], 'note' => 'Course registrations'],
    ];
    $recent = db()->fetchAll(
        'SELECT c.*, u.first_name, u.last_name, COUNT(e.id) AS enrollment_count
         FROM courses c
         LEFT JOIN users u ON u.id = c.teacher_id
         LEFT JOIN enrollments e ON e.course_id = c.id
         GROUP BY c.id, u.first_name, u.last_name
         ORDER BY c.created_at DESC LIMIT 6'
    );
} elseif ($user['role'] === 'teacher') {
    $stats = [
        ['label' => 'My courses', 'value' => db()->fetch('SELECT COUNT(*) AS total FROM courses WHERE teacher_id = :id AND status = :status', ['id' => $user['id'], 'status' => 'active'])['total'], 'note' => 'Assigned courses'],
        ['label' => 'My students', 'value' => db()->fetch('SELECT COUNT(DISTINCT e.student_id) AS total FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE c.teacher_id = :id', ['id' => $user['id']])['total'], 'note' => 'Unique learners'],
        ['label' => 'Assessments', 'value' => db()->fetch('SELECT COUNT(*) AS total FROM assessments a JOIN courses c ON c.id = a.course_id WHERE c.teacher_id = :id', ['id' => $user['id']])['total'], 'note' => 'Created components'],
        ['label' => 'Grades entered', 'value' => db()->fetch('SELECT COUNT(*) AS total FROM grades g JOIN assessments a ON a.id = g.assessment_id JOIN courses c ON c.id = a.course_id WHERE c.teacher_id = :id', ['id' => $user['id']])['total'], 'note' => 'Recorded scores'],
    ];
    $recent = db()->fetchAll(
        'SELECT c.*, COUNT(DISTINCT e.id) AS enrollment_count, COUNT(DISTINCT a.id) AS assessment_count
         FROM courses c
         LEFT JOIN enrollments e ON e.course_id = c.id
         LEFT JOIN assessments a ON a.course_id = c.id
         WHERE c.teacher_id = :teacher_id
         GROUP BY c.id ORDER BY c.created_at DESC LIMIT 6',
        ['teacher_id' => $user['id']]
    );
} else {
    $profile = db()->fetch('SELECT * FROM student_profiles WHERE user_id = :user_id', ['user_id' => $user['id']]);
    $enrollments = $profile === null ? [] : db()->fetchAll(
        'SELECT e.id, c.* FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.student_id = :student_id ORDER BY c.academic_year DESC, c.semester',
        ['student_id' => $profile['id']]
    );
    $totalCredits = 0.0;
    $qualityPoints = 0.0;
    $completeCourses = 0;
    foreach ($enrollments as &$enrollment) {
        $enrollment['result'] = enrollment_result((int) $enrollment['id']);
        if ($enrollment['result']['complete']) {
            $credits = (float) $enrollment['credit_hours'];
            $totalCredits += $credits;
            $qualityPoints += $enrollment['result']['points'] * $credits;
            $completeCourses++;
        }
    }
    unset($enrollment);
    $gpa = $totalCredits > 0 ? round($qualityPoints / $totalCredits, 2) : 0.0;
    $stats = [
        ['label' => 'Enrolled courses', 'value' => count($enrollments), 'note' => 'Current academic record'],
        ['label' => 'Completed results', 'value' => $completeCourses, 'note' => 'Fully graded courses'],
        ['label' => 'Cumulative GPA', 'value' => number_format($gpa, 2), 'note' => 'Four-point scale'],
        ['label' => 'Program level', 'value' => $profile['study_level'] ?? '—', 'note' => $profile['program'] ?? 'Profile pending'],
    ];
    $recent = array_slice($enrollments, 0, 6);
}

require __DIR__ . '/partials/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Good <?= date('G') < 12 ? 'morning' : (date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e($user['first_name']) ?>.</h1>
        <p>Here is the latest overview of your academic workspace.</p>
    </div>
    <div class="page-actions">
        <?php if ($user['role'] === 'admin'): ?>
            <a class="button" href="<?= e(app_path('users.php?create=1')) ?>">Add person</a>
            <a class="button button-secondary" href="<?= e(app_path('courses.php?create=1')) ?>">Create course</a>
        <?php elseif ($user['role'] === 'teacher'): ?>
            <a class="button" href="<?= e(app_path('courses.php')) ?>">Open my courses</a>
        <?php else: ?>
            <a class="button" href="<?= e(app_path('results.php')) ?>">View full results</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($user['must_change_password'])): ?>
    <div class="alert alert-warning">Your account is using a temporary password. <a href="<?= e(app_path('profile.php')) ?>">Change it now</a>.</div>
<?php endif; ?>

<section class="stat-grid" aria-label="Overview statistics">
    <?php foreach ($stats as $stat): ?>
        <article class="stat-card">
            <span><?= e($stat['label']) ?></span>
            <strong><?= e($stat['value']) ?></strong>
            <em><?= e($stat['note']) ?></em>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel">
    <div class="panel-header">
        <div>
            <h2><?= $user['role'] === 'student' ? 'Recent results' : 'Recent courses' ?></h2>
            <p><?= $user['role'] === 'student' ? 'Your latest course performance.' : 'Quick access to current teaching activity.' ?></p>
        </div>
    </div>
    <?php if ($recent === []): ?>
        <div class="empty-state"><h2>No records yet</h2><p>Records will appear here after courses and enrollments are created.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Course</th><th>Period</th><th><?= $user['role'] === 'student' ? 'Result' : 'Students' ?></th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $course): ?>
                    <tr>
                        <td><strong><?= e($course['code']) ?></strong><br><span class="muted"><?= e($course['name']) ?></span></td>
                        <td><?= e($course['semester']) ?><br><span class="muted"><?= e($course['academic_year']) ?></span></td>
                        <td>
                            <?php if ($user['role'] === 'student'): ?>
                                <?php $result = $course['result']; ?>
                                <span class="badge <?= $result['complete'] ? 'badge-success' : 'badge-warning' ?>"><?= $result['complete'] ? e($result['percentage'] . '% · ' . $result['letter']) : 'In progress' ?></span>
                            <?php else: ?>
                                <?= e($course['enrollment_count']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['role'] === 'student'): ?>
                                <a href="<?= e(app_path('results.php')) ?>">View results</a>
                            <?php else: ?>
                                <a href="<?= e(app_path('grades.php?course_id=' . $course['id'])) ?>">Manage grades</a>
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
