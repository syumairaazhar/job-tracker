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
        "interview_date TEXT",
        "result TEXT",
        "technical_skills TEXT"
    ];

    foreach ($columns as $column) {
        try {
            $pdo->exec("ALTER TABLE applications ADD COLUMN $column");
        } catch (PDOException $e) {
            // Column already exists
        }
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
