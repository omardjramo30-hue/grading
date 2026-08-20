# Academic Grading System

A secure, framework-free PHP 8 grading-system MVP for administrators, teachers and students.

## Included features

- Role-based authentication for administrators, teachers and students
- Password hashing, CSRF protection, session hardening and login rate limiting
- User and student-profile management
- Course creation, teacher assignment, semesters and academic years
- Course enrollment management
- Weighted assessments and batch grade entry
- Automatic percentages, letter grades, grade points and cumulative GPA
- Student transcripts, course reports, printing and CSV export
- Audit logging for authentication, accounts, courses, enrollments and grades
- Responsive interface without external JavaScript or CSS dependencies
- SQLite quick start and MySQL support
- Automated PHP lint, calculation tests, static security checks and boot smoke test

## Requirements

- PHP 8.1 or newer
- PDO SQLite extension for the quick start, or PDO MySQL for MySQL hosting
- A writable database directory when using SQLite
- HTTPS in production

## Quick start with SQLite

```bash
cp .env.example .env
export APP_ENV=development
export DB_DSN="sqlite:$(pwd)/data/grading.sqlite"
export ADMIN_PASSWORD='Use-A-Strong-Password-123'
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080`. The application creates its tables automatically. Sign in with the configured `ADMIN_USERNAME` and `ADMIN_PASSWORD`, then immediately replace the temporary password.

PHP does not automatically load `.env`; export the variables through the shell, hosting control panel, Apache configuration, container configuration or service manager. The file is a documented template.

## MySQL setup

1. Create the database and a restricted database user. An editable example is in `database/mysql-create-database.sql`.
2. Configure these environment variables:

```bash
export DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=grading;charset=utf8mb4'
export DB_USERNAME='grading_user'
export DB_PASSWORD='your-random-database-password'
```

3. Start the application. Tables are created automatically using the configured database account.

## Production checklist

- Set `APP_ENV=production`.
- Set a strong, unique `ADMIN_PASSWORD` before the first request.
- For SQLite, put the database outside the publicly served directory and provide its absolute path in `DB_DSN`.
- For MySQL, do not use the `root` account and grant access only to the grading database.
- Point the domain to HTTPS and enable secure cookies through the HTTPS connection.
- Ensure Apache allows the included `.htaccess`, or reproduce its protections in Nginx.
- Deny web access to `.env`, `config.php`, `bootstrap.php`, `data/`, `database/`, `lib/`, `partials/`, `tests/` and `.github/`.
- Back up the database daily and test restoration regularly.
- Change the initial administrator password immediately.

## Role permissions

| Capability | Administrator | Teacher | Student |
|---|---:|---:|---:|
| Manage accounts | Yes | No | No |
| Create and assign courses | Yes | No | No |
| Manage enrollment | Yes | No | No |
| Configure assigned course assessments | Yes | Yes | No |
| Enter assigned course grades | Yes | Yes | No |
| View course reports | Yes | Assigned only | No |
| View student transcripts | Yes | Their students only | Own only |
| View audit log | Yes | No | No |

## Tests

```bash
find . -name '*.php' -not -path './data/*' -print0 | xargs -0 -n1 php -l
php tests/grade_calculation_test.php
php tests/security_static_test.php
```

GitHub Actions runs these checks plus an SQLite application-boot test on each push and pull request.

## Docker and Railway

The included `Dockerfile` runs PHP 8.3 with Apache and PDO MySQL. `railway.json` configures Docker deployment and the `/health.php` health check. For Railway production, attach a MySQL service and configure `DB_DSN`, `DB_USERNAME`, `DB_PASSWORD`, `APP_ENV=production` and the initial administrator variables. Do not rely on the container filesystem for permanent SQLite storage unless a persistent volume is mounted.

## Grade scale

The built-in scale is A (90–100), A− (85–89.99), B+ (80–84.99), B (75–79.99), B− (70–74.99), C+ (65–69.99), C (60–64.99), C− (55–59.99), D (50–54.99) and F (below 50). Adjust `grade_letter()` and `grade_points()` in `lib/functions.php` if your institution uses a different scale.
