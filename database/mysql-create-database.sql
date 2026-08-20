-- Run as a MySQL administrator, then replace the password before executing.
CREATE DATABASE IF NOT EXISTS grading CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'grading_user'@'localhost' IDENTIFIED BY 'replace-with-a-long-random-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
ON grading.* TO 'grading_user'@'localhost';
FLUSH PRIVILEGES;
