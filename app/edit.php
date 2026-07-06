<?php
session_start();
include 'db.php';

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

    // Server-side validation
    if (empty($company) || empty($job_title)) {
        $error = 'Company name and job title are required.';
    } else {
        $updateStmt = $pdo->prepare("
            UPDATE applications 
            SET company = ?, job_title = ?, date_found = ?, date_applied = ?, status = ?, platform = ?, job_type = ?, location = ?, salary_range = ?, job_link = ?, remark = ?, follow_up_date = ?, interview_date = ?, result = ?, technical_skills = ?
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
            $id
        ]);

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
    <link rel="stylesheet" href="style.css?v=1.0.4">
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
                        <div class="form-group">
                            <label>Company Name *</label>
                            <input type="text" name="company" required value="<?= htmlspecialchars($app['company']) ?>" placeholder="e.g. Google, Stripe, Canva">
                        </div>

                        <div class="form-group">
                            <label>Job Title *</label>
                            <input type="text" name="job_title" required value="<?= htmlspecialchars($app['job_title']) ?>" placeholder="e.g. Senior Software Engineer">
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
                            <select name="status">
                                <?php 
                                $statuses = ['Saved', 'Pending', 'Applied', 'Responded', 'Interview', 'Assessment', 'Rejected', 'Offered', 'Expired', 'Unlikely to Progress'];
                                foreach ($statuses as $stat): 
                                ?>
                                    <option <?= $app['status'] === $stat ? 'selected' : '' ?>><?= $stat ?></option>
                                <?php endforeach; ?>
                            </select>
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
                                foreach ($types as $type): 
                                ?>
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
                            <input type="text" name="salary_range" value="<?= htmlspecialchars($app['salary_range'] ?: '') ?>" placeholder="e.g. RM 5,000 - RM 7,000">
                        </div>

                        <div class="form-group">
                            <label>Job Link</label>
                            <input type="url" name="job_link" value="<?= htmlspecialchars($app['job_link'] ?: '') ?>" placeholder="https://...">
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

                        <div class="form-group">
                            <label>Technical / Skills Required</label>
                            <input type="text" name="technical_skills" value="<?= htmlspecialchars($app['technical_skills'] ?: '') ?>" placeholder="e.g. React, PHP, SQL, Figma">
                        </div>

                        <div class="form-group full-width">
                            <label>Remark</label>
                            <textarea name="remark" placeholder="Example: HR contacted regarding portfolio, follow up on Friday..."><?= htmlspecialchars($app['remark'] ?: '') ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn">Update Details</button>
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
</script>
</html>
