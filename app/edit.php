<?php
session_start();
include 'db.php';
define('PDO_SUPPORT', true);
include 'telegram_notify.php';

// Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: index.php");
    exit;
}

$back = 'index.php';
if (isset($_GET['back'])) {
    $parsed = parse_url($_GET['back']);
    if (empty($parsed['host']) && (strpos($parsed['path'], 'index.php') !== false || empty($parsed['path']))) {
        $back = $_GET['back'];
    }
}

// Fetch the job application securely
$stmt = $pdo->prepare("SELECT * FROM applications WHERE id = ?");
$stmt->execute([$id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    header("Location: " . $back);
    exit;
}

$platform_options = ['JobStreet', 'LinkedIn', 'Website', 'MyFutureJobs'];
$platform_select_val = '';
$platform_other_val = '';
$show_other = false;

if (!empty($app['platform'])) {
    if (in_array($app['platform'], $platform_options)) {
        $platform_select_val = $app['platform'];
    } else {
        $platform_select_val = 'Other';
        $platform_other_val = $app['platform'];
        $show_other = true;
    }
}

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF Token Validation
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die("Invalid CSRF token match. Request denied for security reasons.");
    }

    $company = trim($_POST['company'] ?? '');
    $job_title = trim($_POST['job_title'] ?? '');
    $date_found = trim($_POST['date_found'] ?? '');
    $date_applied = trim($_POST['date_applied'] ?? '');
    $status = trim($_POST['status'] ?? 'Applied');
    $platform_select = trim($_POST['platform_select'] ?? '');
    $platform_other  = trim($_POST['platform_other']  ?? '');
    $platform = ($platform_select === 'Other') ? $platform_other : $platform_select;
    $job_type = trim($_POST['job_type'] ?? 'Full-time');
    $location = trim($_POST['location'] ?? '');
    $salary_range = trim($_POST['salary_range'] ?? '');
    $job_link = trim($_POST['job_link'] ?? '');
    $remark = trim($_POST['remark'] ?? '');
    $follow_up_date = trim($_POST['follow_up_date'] ?? '');
    $interview_type = trim($_POST['interview_type'] ?? '');
    $interview_date = trim($_POST['interview_date'] ?? '');
    $result = trim($_POST['result'] ?? '');
    $technical_skills = trim($_POST['technical_skills'] ?? '');

    // Assessment fields
    $assessment_status = trim($_POST['assessment_status'] ?? 'None');
    $assessment_type = trim($_POST['assessment_type'] ?? '');
    $assessment_deadline = trim($_POST['assessment_deadline'] ?? '');
    $assessment_link = trim($_POST['assessment_link'] ?? '');
    $assessment_notes = trim($_POST['assessment_notes'] ?? '');
    $interview_location = trim($_POST['interview_location'] ?? '');
    $assessment_platform = trim($_POST['assessment_platform'] ?? '');
    $location_link = trim($_POST['location_link'] ?? '');
    $interview_location_link = trim($_POST['interview_location_link'] ?? '');

    // Auto-promote assessment_status to 'Pending' if a deadline is set but status was left as 'None'
    if (!empty($assessment_deadline) && $assessment_status === 'None') {
        $assessment_status = 'Pending';
    }

    // Server-side validation
    if (empty($company) || empty($job_title)) {
        $error = 'Company name and job title are required.';
    } else {
        $archive_interview = !empty($_POST['archive_interview']);
        $delete_index = $_POST['delete_interview_index'] ?? '';
        
        $history = json_decode($app['interview_history'] ?? '[]', true) ?: [];

        if ($delete_index !== '' && isset($history[$delete_index])) {
            array_splice($history, $delete_index, 1);
            $interview_history = json_encode($history);
        } elseif ($archive_interview) {
            if ($interview_date || $interview_type || $interview_location) {
                $history[] = [
                    'type' => $interview_type,
                    'date' => $interview_date,
                    'location' => $interview_location,
                    'link' => $interview_location_link,
                    'archived_at' => date('Y-m-d H:i:s')
                ];
                $interview_history = json_encode($history);
            } else {
                $interview_history = $app['interview_history'] ?? null;
            }
            
            // Clear active fields
            $interview_type = '';
            $interview_date = '';
            $interview_location = '';
            $interview_location_link = '';
        } else {
            $interview_history = $app['interview_history'] ?? null;
        }

        // Assessment History processing
        $archive_assessment = !empty($_POST['archive_assessment']);
        $delete_assessment_idx = $_POST['delete_assessment_index'] ?? '';
        
        $a_history = json_decode($app['assessment_history'] ?? '[]', true) ?: [];

        if ($delete_assessment_idx !== '' && isset($a_history[$delete_assessment_idx])) {
            array_splice($a_history, $delete_assessment_idx, 1);
            $assessment_history_json = json_encode($a_history);
        } elseif ($archive_assessment) {
            if ($assessment_type || $assessment_deadline || $assessment_platform || $assessment_notes || $assessment_link) {
                $a_history[] = [
                    'type' => $assessment_type,
                    'deadline' => $assessment_deadline,
                    'status' => $assessment_status ?: 'Completed',
                    'platform' => $assessment_platform,
                    'link' => $assessment_link,
                    'notes' => $assessment_notes,
                    'archived_at' => date('Y-m-d H:i:s')
                ];
                $assessment_history_json = json_encode($a_history);
            } else {
                $assessment_history_json = $app['assessment_history'] ?? null;
            }
            
            // Clear active fields
            $assessment_type = '';
            $assessment_deadline = '';
            $assessment_status = 'None';
            $assessment_platform = '';
            $assessment_link = '';
            $assessment_notes = '';
        } else {
            $assessment_history_json = $app['assessment_history'] ?? null;
        }
        
        $status_history_json = $app['status_history'] ?? '[]';
        if (($app['status'] ?? '') !== $status) {
            $status_history = json_decode($status_history_json, true) ?: [];
            $status_history[] = [
                'status' => $status,
                'date' => date('Y-m-d')
            ];
            $status_history_json = json_encode($status_history);
        }

        $updateStmt = $pdo->prepare("
            UPDATE applications 
            SET company = ?, job_title = ?, date_found = ?, date_applied = ?, status = ?, status_history = ?, platform = ?, job_type = ?, location = ?, salary_range = ?, job_link = ?, remark = ?, follow_up_date = ?, interview_type = ?, interview_date = ?, interview_history = ?, result = ?, technical_skills = ?, assessment_status = ?, assessment_type = ?, assessment_deadline = ?, assessment_link = ?, assessment_notes = ?, interview_location = ?, assessment_platform = ?, location_link = ?, interview_location_link = ?, assessment_history = ?
            WHERE id = ?
        ");

        $updateStmt->execute([
            $company,
            $job_title,
            $date_found ?: null,
            $date_applied ?: null,
            $status,
            $status_history_json,
            $platform ?: null,
            $job_type,
            $location ?: null,
            $salary_range ?: null,
            $job_link ?: null,
            $remark ?: null,
            $follow_up_date ?: null,
            $interview_type ?: null,
            $interview_date ?: null,
            $interview_history ?: null,
            $result ?: null,
            $technical_skills ?: null,
            $assessment_status,
            $assessment_type ?: null,
            $assessment_deadline ?: null,
            $assessment_link ?: null,
            $assessment_notes ?: null,
            $interview_location ?: null,
            $assessment_platform ?: null,
            $location_link ?: null,
            $interview_location_link ?: null,
            $assessment_history_json,
            $id
        ]);

        // Clear dismissed notifications for this application when dates change.
        // This allows notifications to reappear if the user updates a date.
        $typesToClear = [];

        if ($interview_date !== ($app['interview_date'] ?? '')) {
            $typesToClear[] = 'overdue_interview';
            $typesToClear[] = 'today_interview';
            $typesToClear[] = 'tomorrow_interview';
        }
        if ($follow_up_date !== ($app['follow_up_date'] ?? '')) {
            $typesToClear[] = 'overdue_followup';
            $typesToClear[] = 'today_followup';
            $typesToClear[] = 'tomorrow_followup';
        }
        if ($assessment_deadline !== ($app['assessment_deadline'] ?? '')) {
            $typesToClear[] = 'overdue_assessment';
            $typesToClear[] = 'today_assessment';
            $typesToClear[] = 'tomorrow_assessment';
        }

        if (!empty($typesToClear)) {
            $placeholders = implode(',', array_fill(0, count($typesToClear), '?'));
            $clearStmt = $pdo->prepare(
                "DELETE FROM dismissed_notifications WHERE application_id = ? AND notification_type IN ($placeholders)"
            );
            $clearStmt->execute(array_merge([$id], $typesToClear));
        }

        $updatedAppData = [
            'id' => $id,
            'company' => $company,
            'job_title' => $job_title,
            'status' => $status,
            'status_history' => $status_history_json,
            'interview_type' => $interview_type,
            'interview_date' => $interview_date,
            'interview_history' => $interview_history,
            'follow_up_date' => $follow_up_date,
            'assessment_status' => $assessment_status,
            'assessment_type' => $assessment_type,
            'assessment_deadline' => $assessment_deadline,
            'assessment_link' => $assessment_link,
            'assessment_notes' => $assessment_notes,
            'job_link' => $job_link,
            'assessment_history' => $assessment_history_json
        ];
        notifyApplicationChange($pdo, $updatedAppData, 'update', $app);

        $msg = 'Application for <strong>' . htmlspecialchars($company) . '</strong> updated successfully!';
        if ($delete_index !== '') {
            $msg = 'Past interview deleted successfully.';
        } elseif ($delete_assessment_idx !== '') {
            $msg = 'Past assessment deleted successfully.';
        } elseif ($archive_interview) {
            $msg = 'Interview stage saved! You can now enter the next stage details.';
        } elseif ($archive_assessment) {
            $msg = 'Assessment stage saved! You can now enter the next assessment details.';
        } elseif ($status === 'Interview' && !empty($interview_date)) {
            $msg = 'Interview scheduled with <strong>' . htmlspecialchars($company) . '</strong> on ' . htmlspecialchars($interview_date) . '!';
        } elseif (!empty($follow_up_date)) {
            $msg = 'Follow-up set for <strong>' . htmlspecialchars($company) . '</strong> on ' . htmlspecialchars($follow_up_date) . '.';
        }

        $_SESSION['notification'] = [
            'type' => 'success',
            'message' => $msg
        ];

        if ($archive_interview || $archive_assessment || $delete_index !== '' || $delete_assessment_idx !== '') {
            header("Location: edit.php?id=" . urlencode($id) . "&back=" . urlencode($back));
        } else {
            header("Location: " . $back);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Tracker</title>
    <link rel="stylesheet" href="style.css?v=1.2.0">
</head>
<body>

    <div class="layout">
        <!-- Sticky Left Sidebar (Desktop) -->
        <aside class="sidebar">
            <div class="sidebar-logo">
                <div class="sidebar-logo-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    </svg>
                </div>
                <h2>JobTrack</h2>
            </div>
            
            <ul class="sidebar-menu">
                <li class="sidebar-menu-item">
                    <a href="index.php?view=dashboard">
                        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect></svg>
                        Dashboard
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="index.php?view=applications">
                        <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        Applications
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="index.php?view=analytics">
                        <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        Analytics
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="add.php">
                        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        Add Job
                    </a>
                </li>
                <li class="sidebar-menu-item">
                    <a href="settings.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        Settings
                    </a>
                </li>
            </ul>
        </aside>

        <!-- Bottom Navigation Bar (Mobile only) -->
        <nav class="mobile-nav">
            <ul class="mobile-nav-menu">
                <li class="mobile-nav-item">
                    <a href="index.php?view=dashboard">
                        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect></svg>
                        Home
                    </a>
                </li>
                <li class="mobile-nav-item">
                    <a href="index.php?view=applications">
                        <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        Jobs
                    </a>
                </li>
                <li class="mobile-nav-item">
                    <a href="index.php?view=analytics">
                        <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        Charts
                    </a>
                </li>
                <li class="mobile-nav-item">
                    <a href="add.php">
                        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        Add
                    </a>
                </li>
                <li class="mobile-nav-item">
                    <a href="settings.php">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        Settings
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Main Workspace -->
        <main class="main">
            <div class="form-container">
                <h1>Edit Job Application</h1>
                <p class="muted">Update details of your job application and keep its tracking fresh.</p>

                <?php if (!empty($error)): ?>
                    <div style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); padding: 12px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 500;">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="edit.php?id=<?= $id ?>&back=<?= urlencode($back) ?>">
                    <!-- CSRF Token -->
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                    <div class="form-grid">

                        <!-- ── Section 1: Job Details ── -->
                        <div class="form-section-header">
                            <span class="form-section-number">1</span>
                            <div>
                                <div class="form-section-title">Job Details</div>
                                <div class="form-section-subtitle">Basic information about the position</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Company Name <span class="required-star">*</span></label>
                            <input type="text" name="company" required value="<?= htmlspecialchars($app['company']) ?>" placeholder="e.g. Google, Stripe, Canva">
                        </div>

                        <div class="form-group">
                            <label>Job Title <span class="required-star">*</span></label>
                            <input type="text" name="job_title" required value="<?= htmlspecialchars($app['job_title']) ?>" placeholder="e.g. Senior Software Engineer">
                        </div>

                        <div class="form-group">
                            <label>Platform Used</label>
                            <select name="platform_select" id="platform_select" onchange="togglePlatformOther(this)">
                                <option value="" <?= empty($platform_select_val) ? 'selected' : '' ?> disabled>Select platform</option>
                                <?php foreach ($platform_options as $option): ?>
                                    <option value="<?= $option ?>" <?= $platform_select_val === $option ? 'selected' : '' ?>><?= $option ?></option>
                                <?php endforeach; ?>
                                <option value="Other" <?= $platform_select_val === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                            <input type="text" name="platform_other" id="platform_other"
                                   value="<?= htmlspecialchars($platform_other_val) ?>"
                                   placeholder="Enter platform name"
                                   style="display: <?= $show_other ? 'block' : 'none' ?>; margin-top: 0.5rem;"
                                   <?= $show_other ? 'required' : '' ?>>
                        </div>

                        <div class="form-group">
                            <label>Job Type</label>
                            <select name="job_type">
                                <?php 
                                $types = ['Full-time', 'Part-time', 'Internship', 'Contract', 'Remote', 'Hybrid'];
                                foreach ($types as $type): ?>
                                    <option <?= $app['job_type'] === $type ? 'selected' : '' ?>><?= $type ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Location</label>
                            <input type="text" name="location" value="<?= htmlspecialchars($app['location'] ?: '') ?>" placeholder="e.g. Kuala Lumpur, Remote">
                        </div>

                        <div class="form-group full-width" style="margin-top: 10px; margin-bottom: 5px;">
                            <button type="button" class="btn secondary" style="width:100%; justify-content:center; font-size: 0.9em; background:var(--bg-secondary); color:var(--text-secondary); border: 1px dashed var(--border-color);" onclick="toggleAdvanced('advanced_job_details', this)">
                                + Show Advanced Job Details (Salary, Skills, Links)
                            </button>
                        </div>

                        <div id="advanced_job_details" style="display: none;">
                            <div class="form-group">
                                <label>Location Map Link <span style="font-weight:400;opacity:.6;font-size:.85em;">(optional)</span></label>
                                <input type="url" name="location_link" value="<?= htmlspecialchars($app['location_link'] ?? '') ?>" placeholder="https://maps.google.com/...">
                            </div>

                        <div class="form-group">
                            <label>Salary Range</label>
                            <input type="text" name="salary_range" value="<?= htmlspecialchars($app['salary_range'] ?: '') ?>" placeholder="e.g. RM 5,000 – RM 7,000">
                        </div>

                        <div class="form-group full-width">
                            <label>Job Link</label>
                            <input type="url" name="job_link" value="<?= htmlspecialchars($app['job_link'] ?: '') ?>" placeholder="https://...">
                        </div>

                        <div class="form-group full-width">
                            <label>Technical / Skills Required</label>
                            <input type="text" name="technical_skills" value="<?= htmlspecialchars($app['technical_skills'] ?: '') ?>" placeholder="e.g. React, PHP, SQL, Figma">
                        </div>
                        </div>

                        <!-- ── Section 2: Timeline & Status ── -->
                        <div class="form-section-header">
                            <span class="form-section-number">2</span>
                            <div>
                                <div class="form-section-title">Timeline &amp; Status</div>
                                <div class="form-section-subtitle">Track your application progress and dates</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Date Found Job</label>
                            <input type="date" name="date_found" value="<?= htmlspecialchars($app['date_found'] ?: '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Date Applied</label>
                            <input type="date" name="date_applied" value="<?= htmlspecialchars($app['date_applied'] ?: '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Application Status</label>
                            <select name="status" id="app_status" onchange="toggleStatusFields()">
                                <?php 
                                $statuses = ['Applied', 'Pending', 'Viewed Application', 'Interview', 'Assessment', 'Rejected', 'Offered', 'Expired', 'Unlikely to Progress Further'];
                                foreach ($statuses as $stat): ?>
                                    <option <?= $app['status'] === $stat ? 'selected' : '' ?>><?= $stat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- ── Interview Fields (shown when status = Interview) ── -->
                        <div id="interview_fields" style="display:none;">
                            <?php 
                            $history = json_decode($app['interview_history'] ?? '[]', true) ?: [];
                            if (!empty($history)): 
                            ?>
                            <div class="form-group full-width" style="background: var(--bg-secondary); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 15px;">
                                <strong style="display:block; margin-bottom:10px; font-size:0.95em; color:var(--text-secondary);">Past Interview Stages:</strong>
                                <input type="hidden" name="delete_interview_index" id="delete_interview_index" value="">
                                <ul style="margin: 0; padding-left: 20px; font-size: 0.9em; line-height: 1.5;">
                                    <?php foreach ($history as $idx => $h): ?>
                                        <li style="margin-bottom: 5px;">
                                            <strong><?= htmlspecialchars($h['type'] ?? 'Interview') ?></strong> 
                                            (<?= htmlspecialchars($h['date'] ?? 'N/A') ?>)
                                            <?php if (!empty($h['location'])): ?>
                                                - <?= htmlspecialchars($h['location']) ?>
                                            <?php endif; ?>
                                            <button type="button" style="background:none; border:none; color:var(--danger-color); cursor:pointer; font-size: 0.85em; margin-left: 10px; opacity: 0.8; padding: 0;" onclick="if(confirm('Delete this past interview?')) { document.getElementById('delete_interview_index').value = '<?= $idx ?>'; this.closest('form').submit(); }">
                                                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                Remove
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="form-group full-width" style="margin-bottom: 5px;">
                                <strong style="font-size: 0.95em;">Active / Next Interview Stage:</strong>
                            </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label>Interview Type</label>
                                <select name="interview_type" id="interview_type">
                                    <option value="" disabled <?= empty($app['interview_type']) ? 'selected' : '' ?>>Select interview type</option>
                                    <?php 
                                    $i_types = ['Phone Call', 'HR Screening', 'Technical Interview', 'Manager Interview', 'Final Interview', 'Other'];
                                    foreach ($i_types as $itype): ?>
                                        <option value="<?= $itype ?>" <?= ($app['interview_type'] ?? '') === $itype ? 'selected' : '' ?>><?= $itype ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Interview Date</label>
                                <input type="date" name="interview_date" id="interview_date" value="<?= htmlspecialchars($app['interview_date'] ?: '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Interview Location</label>
                                <input type="text" name="interview_location" id="interview_location" value="<?= htmlspecialchars($app['interview_location'] ?? '') ?>" placeholder="e.g. Zoom, Google Meet, Kuala Lumpur Office">
                            </div>
                            <div class="form-group">
                                <label>Interview Location Link <span style="font-weight:400;opacity:.6;font-size:.85em;">(optional)</span></label>
                                <input type="url" name="interview_location_link" id="interview_location_link" value="<?= htmlspecialchars($app['interview_location_link'] ?? '') ?>" placeholder="https://maps.google.com/...">
                            </div>

                            <div class="form-group full-width" style="margin-top: 10px;">
                                <input type="hidden" name="archive_interview" id="archive_interview" value="0">
                                <button type="button" class="btn secondary" style="width:100%; justify-content:center; font-size: 0.9em;" onclick="document.getElementById('archive_interview').value='1'; this.closest('form').submit();">
                                    ✓ Mark as Completed &amp; Go to Next Stage
                                </button>
                            </div>
                        </div>

                        <!-- ── Assessment Inline Fields (shown when status = Assessment) ── -->
                        <div id="assessment_toggle_container" class="form-group full-width" style="display:none; margin-top: 10px;">
                            <button type="button" class="btn secondary" style="width:100%; justify-content:center; font-size: 0.9em; background:var(--bg-secondary); color:var(--text-secondary); border: 1px dashed var(--border-color);" onclick="toggleAdvanced('assessment_status_fields', this)">
                                + Add Assessment Details
                            </button>
                        </div>
                        <div id="assessment_status_fields" style="display:none;">
                            <?php 
                            $a_history = json_decode($app['assessment_history'] ?? '[]', true) ?: [];
                            if (!empty($a_history)): 
                            ?>
                            <div class="form-group full-width" style="background: var(--bg-secondary); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 15px;">
                                <strong style="display:block; margin-bottom:10px; font-size:0.95em; color:var(--text-secondary);">Past Assessment Stages:</strong>
                                <input type="hidden" name="delete_assessment_index" id="delete_assessment_index" value="">
                                <ul style="margin: 0; padding-left: 20px; font-size: 0.9em; line-height: 1.5;">
                                    <?php foreach ($a_history as $idx => $h): ?>
                                        <li style="margin-bottom: 5px;">
                                            <strong><?= htmlspecialchars($h['type'] ?? 'Assessment') ?></strong> 
                                            (<?= htmlspecialchars($h['deadline'] ?? 'N/A') ?>)
                                            <?php if (!empty($h['platform'])): ?>
                                                - <?= htmlspecialchars($h['platform']) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($h['status'])): ?>
                                                [<?= htmlspecialchars($h['status']) ?>]
                                            <?php endif; ?>
                                            <button type="button" style="background:none; border:none; color:var(--danger-color); cursor:pointer; font-size: 0.85em; margin-left: 10px; opacity: 0.8; padding: 0;" onclick="if(confirm('Delete this past assessment?')) { document.getElementById('delete_assessment_index').value = '<?= $idx ?>'; this.closest('form').submit(); }">
                                                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                Remove
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="form-group full-width" style="margin-bottom: 5px;">
                                <strong style="font-size: 0.95em;">Active / Next Assessment Stage:</strong>
                            </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label>Assessment Type</label>
                                <select name="assessment_type" id="assessment_type" onchange="toggleAssessmentSuggestions()">
                                    <option value="" disabled <?= empty($app['assessment_type']) ? 'selected' : '' ?>>Select assessment type</option>
                                    <?php 
                                    $a_types = ['Behavioral Assessment', 'Technical / Coding Test', 'Cognitive / Aptitude Test', 'Take-home Assignment', 'English / Communication Test'];
                                    foreach ($a_types as $type): ?>
                                        <option value="<?= $type ?>" <?= ($app['assessment_type'] ?? '') === $type ? 'selected' : '' ?>><?= $type ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Assessment Date</label>
                                <input type="date" name="assessment_deadline" id="assessment_deadline" value="<?= htmlspecialchars($app['assessment_deadline'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Assessment Status</label>
                                <select name="assessment_status" id="assessment_status">
                                    <option value="None" <?= ($app['assessment_status'] ?? 'None') === 'None' ? 'selected' : '' ?>>None</option>
                                    <option value="Pending" <?= ($app['assessment_status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Completed" <?= ($app['assessment_status'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Assessment Platform</label>
                                <input type="text" name="assessment_platform" id="assessment_platform" value="<?= htmlspecialchars($app['assessment_platform'] ?? '') ?>" placeholder="e.g. HackerRank, Codility, Custom Portal">
                            </div>
                            <div class="form-group full-width">
                                <label>Assessment Link</label>
                                <input type="url" name="assessment_link" id="assessment_link" value="<?= htmlspecialchars($app['assessment_link'] ?? '') ?>" placeholder="https://...">
                            </div>
                            <div class="form-group full-width">
                                <label>Assessment Notes / Reminders</label>
                                <textarea name="assessment_notes" id="assessment_notes" placeholder="e.g. Needs to be completed in one sitting, study company values..."><?= htmlspecialchars($app['assessment_notes'] ?? '') ?></textarea>
                            </div>

                            <!-- Preparation Suggestions Box -->
                            <div class="assessment-suggestions-container full-width" id="assessment_suggestions_box" style="grid-column: 1 / -1;">
                                <div class="suggestions-card">
                                    <div class="suggestions-header">
                                        <h3 id="assessment_suggestions_title">💡 Preparation Suggestions &amp; Tips</h3>
                                    </div>
                                    <div class="suggestions-body" id="assessment_suggestions_body">
                                        <!-- Tips injected dynamically via JavaScript -->
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group full-width" style="margin-top: 10px;">
                                <input type="hidden" name="archive_assessment" id="archive_assessment" value="0">
                                <button type="button" class="btn secondary" style="width:100%; justify-content:center; font-size: 0.9em;" onclick="document.getElementById('archive_assessment').value='1'; this.closest('form').submit();">
                                    ✓ Mark as Completed &amp; Go to Next Assessment
                                </button>
                            </div>
                        </div>

                        <!-- ── Section 3: Notes & Follow-up ── -->
                        <div class="form-section-header">
                            <span class="form-section-number">3</span>
                            <div>
                                <div class="form-section-title">Notes &amp; Follow-up</div>
                                <div class="form-section-subtitle">Set reminders and add remarks</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Follow-up Date</label>
                            <input type="date" name="follow_up_date" value="<?= htmlspecialchars($app['follow_up_date'] ?: '') ?>">
                        </div>

                        <div class="form-group">
                            <label>Result / Outcome</label>
                            <input type="text" name="result" value="<?= htmlspecialchars($app['result'] ?: '') ?>" placeholder="e.g. Passed, Pending, Shortlisted">
                        </div>

                        <div class="form-group full-width">
                            <label>Remark</label>
                            <textarea name="remark" placeholder="Example: HR contacted regarding portfolio, follow up on Friday..."><?= htmlspecialchars($app['remark'] ?: '') ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Update Details
                        </button>
                        <a href="<?= htmlspecialchars($back) ?>" class="back">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>

</body>
<script>
function togglePlatformOther(select) {
    var otherInput = document.getElementById('platform_other');
    if (select.value === 'Other') {
        otherInput.style.display = 'block';
        otherInput.required = true;
    } else {
        otherInput.style.display = 'none';
        otherInput.required = false;
        otherInput.value = '';
    }
}


// Show dynamic tips based on assessment type
function toggleAssessmentSuggestions() {
    const type = document.getElementById('assessment_type') ? document.getElementById('assessment_type').value : '';
    const container = document.getElementById('assessment_suggestions_box');
    if (!container || !type) { if (container) container.classList.remove('active'); return; }
    const tipsBody = document.getElementById('assessment_suggestions_body');
    const tipsTitle = document.getElementById('assessment_suggestions_title');

    let titleText = '';
    let tipsHTML = '';

    switch (type) {
        case 'Behavioral Assessment':
            titleText = '💡 Behavioral Assessment Preparation Tips';
            tipsHTML = `<ul>
                    <li><strong>The STAR Method:</strong> Always frame your answers with: <b>S</b>ituation, <b>T</b>ask, <b>A</b>ction, <b>R</b>esult.</li>
                    <li><strong>Company Core Values:</strong> Research the company's culture. Align your answers with their values.</li>
                    <li><strong>Consistency check:</strong> Answer honestly and consistently—don't try to game the system.</li>
                    <li><strong>Time Management:</strong> Trust your first instinct; they are usually timed.</li>
                </ul>`;
            break;
        case 'Technical / Coding Test':
            titleText = '💻 Technical & Coding Test Strategy';
            tipsHTML = `<ul>
                    <li><strong>Think Out Loud:</strong> Talk through your reasoning and design choices.</li>
                    <li><strong>Brute Force First:</strong> Write a working naive solution first, then optimize.</li>
                    <li><strong>Edge Cases:</strong> Test for empty arrays, null values, negative inputs, and boundary limits.</li>
                    <li><strong>Core DSA:</strong> Review Arrays, HashMaps, Trees, Graphs, Sorting, and Complexity.</li>
                </ul>`;
            break;
        case 'Cognitive / Aptitude Test':
            titleText = '🧠 Cognitive & Aptitude Test Hacks';
            tipsHTML = `<ul>
                    <li><strong>Manage Time Strictly:</strong> If a logic puzzle takes more than 45 seconds, guess and move on.</li>
                    <li><strong>Do Not Get Stuck:</strong> Skip harder questions and return if the interface allows.</li>
                    <li><strong>Warm-up:</strong> Practice similar numerical reasoning tests for 15 minutes before the exam.</li>
                    <li><strong>Elimination Method:</strong> Eliminate clearly wrong options to improve guessing odds.</li>
                </ul>`;
            break;
        case 'Take-home Assignment':
            titleText = '🏠 Take-home Project Best Practices';
            tipsHTML = `<ul>
                    <li><strong>README is Key:</strong> Write a detailed README with: how to run, how to build, tests, and design choices.</li>
                    <li><strong>Clean Code:</strong> Use clear variable names, separate logic, and handle errors.</li>
                    <li><strong>Write Tests:</strong> Add unit/integration tests to show you value quality assurance.</li>
                    <li><strong>Git History:</strong> Commit in clean logical units.</li>
                </ul>`;
            break;
        case 'English / Communication Test':
            titleText = '🗣️ Communication & Language Tips';
            tipsHTML = `<ul>
                    <li><strong>Speak Clearly:</strong> Speak slowly, articulate your words, and check your microphone level.</li>
                    <li><strong>Structure Answers:</strong> Introduce the topic, explain main points, then summarize.</li>
                    <li><strong>Quiet Environment:</strong> Use headphones with a noise-canceling mic.</li>
                    <li><strong>Grammar & Spelling:</strong> Avoid slang or excessive abbreviations.</li>
                </ul>`;
            break;
        default:
            container.classList.remove('active');
            return;
    }

    tipsTitle.textContent = titleText;
    tipsBody.innerHTML = tipsHTML;
    container.classList.add('active');
}

// Flags set from PHP — true if this record already has saved data for that section
const hasInterviewData   = <?= (!empty($app['interview_date']) || !empty($app['interview_location']) || !empty($app['interview_type']) || (!empty($app['interview_history']) && $app['interview_history'] !== '[]')) ? 'true' : 'false' ?>;
const hasAssessmentData  = <?= (!empty($app['assessment_deadline']) || !empty($app['assessment_type']) || !empty($app['assessment_notes']) || !empty($app['assessment_link']) || (!empty($app['assessment_history']) && $app['assessment_history'] !== '[]')) ? 'true' : 'false' ?>;

// Show/hide Interview or Assessment fields based on main status dropdown.
// Rule: ALWAYS show a section if it has existing saved data, regardless of current status.
// Also: Interview shows assessment too, since assessments can happen during interviews.
function toggleStatusFields() {
    const status = document.getElementById('app_status').value;
    const interviewFields  = document.getElementById('interview_fields');
    const assessmentFields = document.getElementById('assessment_status_fields');
    const assessmentToggle = document.getElementById('assessment_toggle_container');

    // Interview section: show if status === Interview OR data already exists
    if (status === 'Interview' || hasInterviewData) {
        interviewFields.style.display = 'contents';
    } else {
        interviewFields.style.display = 'none';
    }

    // Assessment section: show if status === Assessment OR data already exists
    if (status === 'Assessment' || hasAssessmentData) {
        assessmentFields.style.display = 'contents';
        if (assessmentToggle) assessmentToggle.style.display = 'none';
        toggleAssessmentSuggestions();
    } else if (status === 'Interview' || hasInterviewData) {
        assessmentFields.style.display = 'none';
        if (assessmentToggle) assessmentToggle.style.display = 'contents';
    } else {
        assessmentFields.style.display = 'none';
        if (assessmentToggle) assessmentToggle.style.display = 'none';
    }
}

function toggleAdvanced(id, btn) {
    const el = document.getElementById(id);
    if (el.style.display === 'none' || el.style.display === '') {
        el.style.display = 'contents';
        btn.innerHTML = btn.innerHTML.replace('+ Show', '- Hide');
    } else {
        el.style.display = 'none';
        btn.innerHTML = btn.innerHTML.replace('- Hide', '+ Show');
    }
}

// Run on page load
document.addEventListener('DOMContentLoaded', () => {
    toggleStatusFields();
});
</script>
</html>
