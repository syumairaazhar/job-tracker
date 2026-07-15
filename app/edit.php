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
    $interview_date = trim($_POST['interview_date'] ?? '');
    $result = trim($_POST['result'] ?? '');
    $technical_skills = trim($_POST['technical_skills'] ?? '');

    // Assessment fields
    $assessment_status = trim($_POST['assessment_status'] ?? 'None');
    $assessment_type = trim($_POST['assessment_type'] ?? '');
    $assessment_deadline = trim($_POST['assessment_deadline'] ?? '');
    $assessment_link = trim($_POST['assessment_link'] ?? '');
    $assessment_notes = trim($_POST['assessment_notes'] ?? '');

    // Auto-promote assessment_status to 'Pending' if a deadline is set but status was left as 'None'
    if (!empty($assessment_deadline) && $assessment_status === 'None') {
        $assessment_status = 'Pending';
    }

    // Server-side validation
    if (empty($company) || empty($job_title)) {
        $error = 'Company name and job title are required.';
    } else {
        $updateStmt = $pdo->prepare("
            UPDATE applications 
            SET company = ?, job_title = ?, date_found = ?, date_applied = ?, status = ?, platform = ?, job_type = ?, location = ?, salary_range = ?, job_link = ?, remark = ?, follow_up_date = ?, interview_date = ?, result = ?, technical_skills = ?, assessment_status = ?, assessment_type = ?, assessment_deadline = ?, assessment_link = ?, assessment_notes = ?
            WHERE id = ?
        ");

        $updateStmt->execute([
            $company,
            $job_title,
            $date_found ?: null,
            $date_applied ?: null,
            $status,
            $platform ?: null,
            $job_type,
            $location ?: null,
            $salary_range ?: null,
            $job_link ?: null,
            $remark ?: null,
            $follow_up_date ?: null,
            $interview_date ?: null,
            $result ?: null,
            $technical_skills ?: null,
            $assessment_status,
            $assessment_type ?: null,
            $assessment_deadline ?: null,
            $assessment_link ?: null,
            $assessment_notes ?: null,
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
            'interview_date' => $interview_date,
            'follow_up_date' => $follow_up_date,
            'assessment_status' => $assessment_status,
            'assessment_type' => $assessment_type,
            'assessment_deadline' => $assessment_deadline,
            'assessment_link' => $assessment_link,
            'assessment_notes' => $assessment_notes
        ];
        notifyApplicationChange($pdo, $updatedAppData, 'update');

        $msg = 'Application for <strong>' . htmlspecialchars($company) . '</strong> updated successfully!';
        if ($status === 'Interview' && !empty($interview_date)) {
            $msg = 'Interview scheduled with <strong>' . htmlspecialchars($company) . '</strong> on ' . htmlspecialchars($interview_date) . '!';
        } elseif (!empty($follow_up_date)) {
            $msg = 'Follow-up set for <strong>' . htmlspecialchars($company) . '</strong> on ' . htmlspecialchars($follow_up_date) . '.';
        }

        $_SESSION['notification'] = [
            'type' => 'success',
            'message' => $msg
        ];

        header("Location: " . $back);
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

                        <!-- ── Section 2: Status & Tracking ── -->
                        <div class="form-section-header">
                            <span class="form-section-number">2</span>
                            <div>
                                <div class="form-section-title">Status &amp; Tracking</div>
                                <div class="form-section-subtitle">Track your application progress and dates</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Application Status</label>
                            <select name="status">
                                <?php 
                                $statuses = ['Saved', 'Pending', 'Applied', 'Responded', 'Interview', 'Assessment', 'Rejected', 'Offered', 'Expired', 'Unlikely to Progress Further'];
                                foreach ($statuses as $stat): ?>
                                    <option <?= $app['status'] === $stat ? 'selected' : '' ?>><?= $stat ?></option>
                                <?php endforeach; ?>
                            </select>
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
                            <label>Interview Date</label>
                            <input type="date" name="interview_date" value="<?= htmlspecialchars($app['interview_date'] ?: '') ?>">
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

                        <!-- ── Section 3: Assessment Details ── -->
                        <div class="form-section-header">
                            <span class="form-section-number">3</span>
                            <div>
                                <div class="form-section-title">Assessment</div>
                                <div class="form-section-subtitle">Track any online tests or assessments</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Assessment Status</label>
                            <select name="assessment_status" id="assessment_status" onchange="toggleAssessmentFields()">
                                <option value="None" <?= ($app['assessment_status'] ?? 'None') === 'None' ? 'selected' : '' ?>>None</option>
                                <option value="Pending" <?= ($app['assessment_status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="Completed" <?= ($app['assessment_status'] ?? '') === 'Completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>

                        <div id="assessment_extra_fields" style="display: none;">
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
                                <label>Assessment Deadline</label>
                                <input type="date" name="assessment_deadline" id="assessment_deadline" value="<?= htmlspecialchars($app['assessment_deadline'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>Assessment Link</label>
                                <input type="url" name="assessment_link" id="assessment_link" value="<?= htmlspecialchars($app['assessment_link'] ?? '') ?>" placeholder="https://...">
                            </div>

                            <div class="form-group full-width">
                                <label>Assessment Notes / Reminders</label>
                                <textarea name="assessment_notes" id="assessment_notes" placeholder="e.g. Needs to be completed in one sitting, study company values..."><?= htmlspecialchars($app['assessment_notes'] ?? '') ?></textarea>
                            </div>

                            <!-- Preparation Suggestions Box -->
                            <div class="assessment-suggestions-container" id="assessment_suggestions_box">
                                <div class="suggestions-card">
                                    <div class="suggestions-header">
                                        <h3 id="assessment_suggestions_title">💡 Preparation Suggestions &amp; Tips</h3>
                                    </div>
                                    <div class="suggestions-body" id="assessment_suggestions_body">
                                        <!-- Tips injected dynamically via JavaScript -->
                                    </div>
                                </div>
                            </div>
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

// Toggle assessment fields visibility based on status
function toggleAssessmentFields() {
    const status = document.getElementById('assessment_status').value;
    const extraFields = document.getElementById('assessment_extra_fields');
    const typeSelect = document.getElementById('assessment_type');
    
    if (status !== 'None') {
        extraFields.style.display = 'contents'; // Fits inside CSS Grid
        toggleAssessmentSuggestions();
    } else {
        extraFields.style.display = 'none';
        document.getElementById('assessment_suggestions_box').classList.remove('active');
    }
}

// Show dynamic tips based on type
function toggleAssessmentSuggestions() {
    const type = document.getElementById('assessment_type').value;
    const container = document.getElementById('assessment_suggestions_box');
    const tipsBody = document.getElementById('assessment_suggestions_body');
    const tipsTitle = document.getElementById('assessment_suggestions_title');
    
    if (!type || document.getElementById('assessment_status').value === 'None') {
        container.classList.remove('active');
        return;
    }
    
    let titleText = '';
    let tipsHTML = '';
    
    switch (type) {
        case 'Behavioral Assessment':
            titleText = '💡 Behavioral Assessment Preparation Tips';
            tipsHTML = `
                <ul>
                    <li><strong>The STAR Method:</strong> Always frame your answers with: <b>S</b>ituation, <b>T</b>ask, <b>A</b>ction, <b>R</b>esult. Be specific about your actions and quantify results.</li>
                    <li><strong>Company Core Values:</strong> Research the company's culture. Align your answers with their specific core values (e.g. customer obsession, collaboration).</li>
                    <li><strong>Consistency check:</strong> These tests often ask the same question in multiple formats. Answer honestly and consistently—don't try to guess what the system wants.</li>
                    <li><strong>Time Management:</strong> Don't overthink personality test questions. Trust your first instinct; they are usually timed.</li>
                </ul>
            `;
            break;
        case 'Technical / Coding Test':
            titleText = '💻 Technical & Coding Test Strategy';
            tipsHTML = `
                <ul>
                    <li><strong>Think Out Loud:</strong> Talk through your reasoning, trade-offs, and design choices. Solvers who explain their thought process score higher even with bugs.</li>
                    <li><strong>Brute Force First:</strong> Write a working naive solution first, then optimize. It secures partial points and ensures you have something functional.</li>
                    <li><strong>Edge Cases:</strong> Test for empty arrays, null values, negative inputs, large numbers, and boundary limits.</li>
                    <li><strong>Core DSA:</strong> Review Arrays, HashMaps, Trees, Graphs, Sorting algorithms, and Space/Time Complexity.</li>
                </ul>
            `;
            break;
        case 'Cognitive / Aptitude Test':
            titleText = '🧠 Cognitive & Aptitude Test Hacks';
            tipsHTML = `
                <ul>
                    <li><strong>Manage Time Strictly:</strong> These tests are designed to be speed-runs. If a logic puzzle takes more than 45 seconds, guess and move on.</li>
                    <li><strong>Do Not Get Stuck:</strong> Harder questions are placed to consume your time. Skip them and return if the interface allows.</li>
                    <li><strong>Warm-up:</strong> Play simple brain puzzles or practice similar numerical reasoning tests for 15 minutes before opening the real exam.</li>
                    <li><strong>Elimination Method:</strong> Eliminate clearly wrong options to improve your guessing odds.</li>
                </ul>
            `;
            break;
        case 'Take-home Assignment':
            titleText = '🏠 Take-home Project Best Practices';
            tipsHTML = `
                <ul>
                    <li><strong>README is Key:</strong> Write a detailed README showing: how to run, how to build, tests, and architectural design choices.</li>
                    <li><strong>Clean Code:</strong> Treat it like production code. Use clear variable names, separate logic, follow folder structures, and handle errors.</li>
                    <li><strong>Write Tests:</strong> Add a couple of simple unit/integration tests to show that you value quality assurance.</li>
                    <li><strong>Git History:</strong> Commit in clean logical units. Companies evaluate your commit history to see how you work.</li>
                </ul>
            `;
            break;
        case 'English / Communication Test':
            titleText = '🗣️ Communication & Language Tips';
            tipsHTML = `
                <ul>
                    <li><strong>Speak Clearly:</strong> In verbal recording tests, speak slowly, articulate your words, and ensure your microphone level is perfect.</li>
                    <li><strong>Structure Answers:</strong> Introduce the topic, explain the main points, and give a quick summary. Avoid unstructured rambling.</li>
                    <li><strong>Quiet Environment:</strong> Use headphones with a noise-canceling mic. Background noise can fail automated scoring bots.</li>
                    <li><strong>Grammar & Spelling:</strong> In writing tasks, review your spelling. Avoid using slang or excessive abbreviations.</li>
                </ul>
            `;
            break;
        default:
            container.classList.remove('active');
            return;
    }
    
    tipsTitle.textContent = titleText;
    tipsBody.innerHTML = tipsHTML;
    container.classList.add('active');
}

// Run visibility check on page load to pre-show if assessment exists
document.addEventListener('DOMContentLoaded', () => {
    toggleAssessmentFields();
});
</script>
</html>
