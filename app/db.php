<?php
$dbPath = "/var/www/database/job_tracker.sqlite";

try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
CREATE TABLE IF NOT EXISTS applications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company TEXT NOT NULL,
    job_title TEXT NOT NULL,
    date_found TEXT,
    date_applied TEXT,
    status TEXT DEFAULT 'Applied',
    platform TEXT,
    job_type TEXT,
    location TEXT,
    salary_range TEXT,
    job_link TEXT,
    remark TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
");

    $columns = [
        "date_found TEXT",
        "job_type TEXT",
        "location TEXT",
        "salary_range TEXT",
        "job_link TEXT",
        "remark TEXT",
        "follow_up_date TEXT",
        "interview_type TEXT",
        "interview_date TEXT",
        "interview_history TEXT",
        "status_history TEXT",
        "result TEXT",
        "technical_skills TEXT",
        "assessment_status TEXT DEFAULT 'None'",
        "assessment_type TEXT",
        "assessment_deadline TEXT",
        "assessment_link TEXT",
        "assessment_notes TEXT",
        "interview_location TEXT",
        "assessment_platform TEXT",
        "location_link TEXT",
        "interview_location_link TEXT",
        "assessment_history TEXT"
    ];

    foreach ($columns as $column) {
        try {
            $pdo->exec("ALTER TABLE applications ADD COLUMN $column");
        } catch (PDOException $e) {
            // Column already exists
        }
    }

    $pdo->exec("
CREATE TABLE IF NOT EXISTS settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT
)
");

    $pdo->exec("
CREATE TABLE IF NOT EXISTS dismissed_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    application_id INTEGER NOT NULL,
    notification_type TEXT NOT NULL,
    dismissed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(application_id, notification_type)
)
");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
