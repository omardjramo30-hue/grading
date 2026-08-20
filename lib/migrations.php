<?php

declare(strict_types=1);

function migrate_database(Database $database): void
{
    $driver = $database->driver();
    $id = $driver === 'mysql'
        ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
        : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $integer = $driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
    $real = $driver === 'mysql' ? 'DECIMAL(8,2)' : 'REAL';
    $text = $driver === 'mysql' ? 'VARCHAR(255)' : 'TEXT';
    $timestamp = $driver === 'mysql' ? 'DATETIME' : 'TEXT';
    $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $statements = [
        "CREATE TABLE IF NOT EXISTS users (
            id {$id},
            username VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(190) NULL UNIQUE,
            role VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            must_change_password {$integer} NOT NULL DEFAULT 0,
            last_login_at {$timestamp} NULL,
            created_at {$timestamp} NOT NULL,
            updated_at {$timestamp} NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS student_profiles (
            id {$id},
            user_id {$integer} NOT NULL UNIQUE,
            student_number VARCHAR(80) NOT NULL UNIQUE,
            program VARCHAR(190) NOT NULL,
            study_level VARCHAR(80) NOT NULL,
            created_at {$timestamp} NOT NULL,
            CONSTRAINT fk_student_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS courses (
            id {$id},
            code VARCHAR(40) NOT NULL,
            name VARCHAR(190) NOT NULL,
            credit_hours {$integer} NOT NULL DEFAULT 3,
            teacher_id {$integer} NULL,
            semester VARCHAR(40) NOT NULL,
            academic_year VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at {$timestamp} NOT NULL,
            updated_at {$timestamp} NOT NULL,
            UNIQUE (code, semester, academic_year),
            CONSTRAINT fk_course_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS enrollments (
            id {$id},
            student_id {$integer} NOT NULL,
            course_id {$integer} NOT NULL,
            enrolled_at {$timestamp} NOT NULL,
            UNIQUE (student_id, course_id),
            CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id) REFERENCES student_profiles(id) ON DELETE CASCADE,
            CONSTRAINT fk_enrollment_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS assessments (
            id {$id},
            course_id {$integer} NOT NULL,
            name VARCHAR(120) NOT NULL,
            max_score {$real} NOT NULL,
            weight {$real} NOT NULL,
            due_date VARCHAR(10) NULL,
            created_at {$timestamp} NOT NULL,
            UNIQUE (course_id, name),
            CONSTRAINT fk_assessment_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS grades (
            id {$id},
            assessment_id {$integer} NOT NULL,
            enrollment_id {$integer} NOT NULL,
            score {$real} NULL,
            remarks {$text} NULL,
            graded_by {$integer} NOT NULL,
            updated_at {$timestamp} NOT NULL,
            UNIQUE (assessment_id, enrollment_id),
            CONSTRAINT fk_grade_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
            CONSTRAINT fk_grade_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments(id) ON DELETE CASCADE,
            CONSTRAINT fk_grade_user FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE RESTRICT
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id {$id},
            user_id {$integer} NULL,
            action VARCHAR(100) NOT NULL,
            details {$text} NULL,
            ip_address VARCHAR(64) NULL,
            created_at {$timestamp} NOT NULL,
            CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id {$id},
            attempt_key VARCHAR(64) NOT NULL,
            attempted_at {$timestamp} NOT NULL
        ){$engine}",
    ];

    foreach ($statements as $statement) {
        $database->pdo()->exec($statement);
    }

    try {
        $database->pdo()->exec('CREATE INDEX idx_login_attempt_key ON login_attempts (attempt_key, attempted_at)');
    } catch (PDOException $exception) {
        if (!str_contains(strtolower($exception->getMessage()), 'already exists')
            && !str_contains(strtolower($exception->getMessage()), 'duplicate')) {
            throw $exception;
        }
    }
}

/** @param array{username:string,password:string,first_name:string,last_name:string,email:string} $admin */
function seed_initial_admin(Database $database, array $admin): void
{
    $existing = $database->fetch('SELECT id FROM users LIMIT 1');
    if ($existing !== null) {
        return;
    }

    $now = gmdate('Y-m-d H:i:s');
    $database->execute(
        'INSERT INTO users (username, password_hash, first_name, last_name, email, role, status, must_change_password, created_at, updated_at)
         VALUES (:username, :password_hash, :first_name, :last_name, :email, :role, :status, :must_change_password, :created_at, :updated_at)',
        [
            'username' => $admin['username'],
            'password_hash' => password_hash($admin['password'], PASSWORD_DEFAULT),
            'first_name' => $admin['first_name'],
            'last_name' => $admin['last_name'],
            'email' => $admin['email'],
            'role' => 'admin',
            'status' => 'active',
            'must_change_password' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]
    );
}
