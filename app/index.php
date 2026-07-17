<?php
session_start();
include 'db.php';

function getStatusClass($status) {
    return preg_replace('/\s+/', '-', strtolower(trim($status)));
}

function getAvatarColor($name) {
    $hash = md5($name);
    $h = hexdec(substr($hash, 0, 4)) % 360;
    return "hsl({$h}, 70%, 93%)";
}

function getAvatarTextColor($name) {
    $hash = md5($name);
    $h = hexdec(substr($hash, 0, 4)) % 360;
    return "hsl({$h}, 75%, 35%)";
}

// Generate CSRF token if it doesn't exist to secure edit/delete
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Determine active view (dashboard, applications, analytics)
$view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';
if (!in_array($view, ['dashboard', 'applications', 'analytics'])) {
    $view = 'dashboard';
}

// Fetch general stats
$total = $pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn();
$applied = $pdo->query("SELECT COUNT(*) FROM applications WHERE status='Applied'")->fetchColumn();
$interview = $pdo->query("SELECT COUNT(*) FROM applications WHERE status='Interview'")->fetchColumn();
$offered = $pdo->query("SELECT COUNT(*) FROM applications WHERE status='Offered'")->fetchColumn();
$expired_rejected = $pdo->query("SELECT COUNT(*) FROM applications WHERE status='Expired' OR status='Rejected' OR status='Unlikely to Progress Further'")->fetchColumn();
$pending = $pdo->query("SELECT COUNT(*) FROM applications WHERE status='Pending'")->fetchColumn();
$pending_assessments = $pdo->query("SELECT COUNT(*) FROM applications WHERE assessment_status='Pending'")->fetchColumn();

// Fetch applications from the last 7 days for the motivational progress tracker
$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
$stmtWeekly = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE date_applied >= ?");
$stmtWeekly->execute([$sevenDaysAgo]);
$weeklyApplied = (int)$stmtWeekly->fetchColumn();

// Fetch all applications
$stmt = $pdo->query("SELECT * FROM applications ORDER BY date_applied DESC, created_at DESC");
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter applications by status, job_type, or platform if provided via query params
$filterStatus = $_GET['status'] ?? '';
$filterJobType = $_GET['job_type'] ?? '';
$filterPlatform = $_GET['platform'] ?? '';
$filterAssessmentStatus = $_GET['assessment_status'] ?? '';

if ($filterStatus !== '') {
    if ($filterStatus === 'Expired_Rejected') {
        $applications = array_filter($applications, fn($row) => $row['status'] === 'Expired' || $row['status'] === 'Rejected' || $row['status'] === 'Unlikely to Progress Further');
    } else {
        $applications = array_filter($applications, fn($row) => $row['status'] === $filterStatus);
    }
}
if ($filterJobType !== '') {
    $applications = array_filter($applications, fn($row) => $row['job_type'] === $filterJobType);
}
if ($filterPlatform !== '') {
    $applications = array_filter($applications, fn($row) => $row['platform'] === $filterPlatform);
}
if ($filterAssessmentStatus !== '') {
    $applications = array_filter($applications, fn($row) => ($row['assessment_status'] ?? 'None') === $filterAssessmentStatus);
}

// Real-time Session Notification Listener
$sessionNotification = null;
if (isset($_SESSION['notification'])) {
    $sessionNotification = $_SESSION['notification'];
    unset($_SESSION['notification']);
}

// Notification & Deadline Reminders Query
$todayStr = date('Y-m-d');
$tomorrowStr = date('Y-m-d', strtotime('+1 day'));
$reminders = [];

// Fetch dismissed notifications to filter them out
$dismissedSet = [];
$dismissedStmt = $pdo->query("SELECT application_id, notification_type FROM dismissed_notifications");
foreach ($dismissedStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
    $dismissedSet[$d['application_id'] . '|' . $d['notification_type']] = true;
}

/**
 * Serialize application detail for JSON modal data.
 */
function serializeAppDetail($row) {
    return json_encode([
        'id'                  => (int)$row['id'],
        'company'             => $row['company'],
        'job_title'           => $row['job_title'],
        'location'            => $row['location'] ?: '',
        'job_type'            => $row['job_type'] ?: '',
        'salary_range'        => $row['salary_range'] ?: '',
        'date_found'          => $row['date_found'] ?: '',
        'date_applied'        => $row['date_applied'] ?: '',
        'follow_up_date'      => $row['follow_up_date'] ?: '',
        'platform'            => $row['platform'] ?: '',
        'status'              => $row['status'],
        'result'              => $row['result'] ?: '',
        'technical_skills'    => $row['technical_skills'] ?: '',
        'job_link'            => $row['job_link'] ?: '',
        'remark'              => $row['remark'] ?: '',
        // Interview
        'interview_date'             => $row['interview_date'] ?: '',
        'interview_location'         => $row['interview_location'] ?? '',
        'interview_location_link'    => $row['interview_location_link'] ?? '',
        // Assessment
        'assessment_status'          => $row['assessment_status'] ?? 'None',
        'assessment_type'            => $row['assessment_type'] ?? '',
        'assessment_deadline'        => $row['assessment_deadline'] ?? '',
        'assessment_platform'        => $row['assessment_platform'] ?? '',
        'assessment_link'            => $row['assessment_link'] ?? '',
        'assessment_notes'           => $row['assessment_notes'] ?? '',
        // Links
        'location_link'              => $row['location_link'] ?? '',
    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

// 1. Overdue Interviews (Date is in the past, but status is still Interview)
$overdueInterviewsStmt = $pdo->prepare("
    SELECT * FROM applications 
    WHERE status = 'Interview' AND interview_date < ? 
    ORDER BY interview_date DESC
");
$overdueInterviewsStmt->execute([$todayStr]);
foreach ($overdueInterviewsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($dismissedSet[$row['id'] . '|overdue_interview'])) continue;
    $reminders[] = [
        'type' => 'overdue_interview',
        'app_id' => (int)$row['id'],
        'detail' => serializeAppDetail($row),
        'message' => "Interview outcome update needed for <strong>" . htmlspecialchars($row['company']) . "</strong>.",
        'time' => "Scheduled: " . htmlspecialchars($row['interview_date'])
    ];
}

// 2. Overdue Follow-ups (Date in the past, status is active)
$overdueFollowupsStmt = $pdo->prepare("
    SELECT * FROM applications 
    WHERE status IN ('Applied', 'Pending', 'Viewed Application', 'Interview', 'Assessment') AND follow_up_date < ? 
    ORDER BY follow_up_date DESC
");
$overdueFollowupsStmt->execute([$todayStr]);
foreach ($overdueFollowupsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($dismissedSet[$row['id'] . '|overdue_followup'])) continue;
    $reminders[] = [
        'type' => 'overdue_followup',
        'app_id' => (int)$row['id'],
        'detail' => serializeAppDetail($row),
        'message' => "Overdue follow-up for <strong>" . htmlspecialchars($row['company']) . "</strong>.",
        'time' => "Scheduled: " . htmlspecialchars($row['follow_up_date'])
    ];
}

// 3. Today's Interviews
$todayInterviewsStmt = $pdo->prepare("
    SELECT * FROM applications 
    WHERE interview_date = ?
");
$todayInterviewsStmt->execute([$todayStr]);
foreach ($todayInterviewsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($dismissedSet[$row['id'] . '|today_interview'])) continue;
    $reminders[] = [
        'type' => 'today_interview',
        'app_id' => (int)$row['id'],
        'detail' => serializeAppDetail($row),
        'message' => "🔔 Interview scheduled today for <strong>" . htmlspecialchars($row['company']) . "</strong>!",
        'time' => "Today"
    ];
}

// 4. Today's Follow-ups
$todayFollowupsStmt = $pdo->prepare("
    SELECT * FROM applications 
    WHERE follow_up_date = ?
");
$todayFollowupsStmt->execute([$todayStr]);
foreach ($todayFollowupsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($dismissedSet[$row['id'] . '|today_followup'])) continue;
    $reminders[] = [
        'type' => 'today_followup',
        'app_id' => (int)$row['id'],
        'detail' => serializeAppDetail($row),
        'message' => "📅 Follow-up scheduled today for <strong>" . htmlspecialchars($row['company']) . "</strong>.",
        'time' => "Today"
    ];
}

// 5. Tomorrow's Interviews
$tomorrowInterviewsStmt = $pdo->prepare("
    SELECT * FROM applications 
    WHERE interview_date = ?
");
$tomorrowInterviewsStmt->execute([$tomorrowStr]);
foreach ($tomorrowInterviewsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($dismissedSet[$row['id'] . '|tomorrow_interview'])) continue;
    $reminders[] = [
        'type' => 'tomorrow_interview',
        'app_id' => (int)$row['id'],
        'detail' => serializeAppDetail($row),
        'message' => "Upcoming interview tomorrow for <strong>" . htmlspecialchars($row['company']) . "</strong>.",
        'time' => "Tomorrow"
    ];
}

// 6. Tomorrow's Follow-ups
$tomorrowFollowupsStmt = $pdo->prepare("
    SELECT * FROM applications 
    WHERE follow_up_date = ?
");
$tomorrowFollowupsStmt->execute([$tomorrowStr]);
foreach ($tomorrowFollowupsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($dismissedSet[$row['id'] . '|tomorrow_followup'])) continue;
    $reminders[] = [
        'type' => 'tomorrow_followup',
        'app_id' => (int)$row['id'],
        'detail' => serializeAppDetail($row),
        'message' => "Upcoming follow-up tomorrow for <strong>" . htmlspecialchars($row['company']) . "</strong>.",
        'time' => "Tomorrow"
    ];
}

// 7. Overdue Assessments (Deadline past, not yet completed)
$overdueAssessmentsStmt = $pdo->prepare("
    SELECT * FROM applications 
    WHERE assessment_status != 'Completed'
      AND assessment_deadline IS NOT NULL AND assessment_deadline != ''
      AND assessment_deadline < ? 
    ORDER BY assessment_deadline DESC
");
$overdueAssessmentsStmt->execute([$todayStr]);
foreach ($overdueAssessmentsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($dismissedSet[$row['id'] . '|overdue_assessment'])) continue;
    $reminders[] = [
        'type' => 'overdue_assessment',
        'app_id' => (int)$row['id'],
        'detail' => serializeAppDetail($row),
        'message' => "Overdue assessment (<strong>" . htmlspecialchars($row['assessment_type']) . "</strong>) for <strong>" . htmlspecialchars($row['company']) . "</strong>.",
        'time' => "Deadline: " . htmlspecialchars($row['assessment_deadline'])
    ];
}

// 8. Today's Assessments (due today, not completed)
$todayAssessmentsStmt = $pdo->prepare("
    SELECT * FROM applications 
    WHERE assessment_status != 'Completed'
      AND assessment_deadline IS NOT NULL AND assessment_deadline != ''
      AND assessment_deadline = ?
");
$todayAssessmentsStmt->execute([$todayStr]);
foreach ($todayAssessmentsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($dismissedSet[$row['id'] . '|today_assessment'])) continue;
    $reminders[] = [
        'type' => 'today_assessment',
        'app_id' => (int)$row['id'],
        'detail' => serializeAppDetail($row),
        'message' => "📝 Assessment (<strong>" . htmlspecialchars($row['assessment_type']) . "</strong>) due today for <strong>" . htmlspecialchars($row['company']) . "</strong>!",
        'time' => "Today"
    ];
}

// 9. Tomorrow's Assessments (due tomorrow, not completed)
$tomorrowAssessmentsStmt = $pdo->prepare("
    SELECT * FROM applications 
    WHERE assessment_status != 'Completed'
      AND assessment_deadline IS NOT NULL AND assessment_deadline != ''
      AND assessment_deadline = ?
");
$tomorrowAssessmentsStmt->execute([$tomorrowStr]);
foreach ($tomorrowAssessmentsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($dismissedSet[$row['id'] . '|tomorrow_assessment'])) continue;
    $reminders[] = [
        'type' => 'tomorrow_assessment',
        'app_id' => (int)$row['id'],
        'detail' => serializeAppDetail($row),
        'message' => "⏰ Assessment (<strong>" . htmlspecialchars($row['assessment_type']) . "</strong>) due tomorrow for <strong>" . htmlspecialchars($row['company']) . "</strong>.",
        'time' => "Tomorrow — " . htmlspecialchars($row['assessment_deadline'])
    ];
}

// Fetch status distribution for doughnut chart
$statusStmt = $pdo->query("SELECT status, COUNT(*) as total FROM applications GROUP BY status");
$statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
$statusLabels = [];
$statusCounts = [];
foreach ($statusData as $item) {
    $statusLabels[] = $item['status'];
    $statusCounts[] = $item['total'];
}

// Fetch platform distribution for platform bar chart
$platformStmt = $pdo->query("
    SELECT platform, COUNT(*) as total 
    FROM applications 
    WHERE platform IS NOT NULL AND platform != '' 
    GROUP BY platform 
    ORDER BY total DESC 
    LIMIT 6
");
$platformData = $platformStmt->fetchAll(PDO::FETCH_ASSOC);
$platformLabels = [];
$platformCounts = [];
foreach ($platformData as $item) {
    $platformLabels[] = $item['platform'];
    $platformCounts[] = $item['total'];
}

// Fetch job type distribution for job type doughnut/pie chart
$jobTypeStmt = $pdo->query("
    SELECT job_type, COUNT(*) as total 
    FROM applications 
    WHERE job_type IS NOT NULL AND job_type != '' 
    GROUP BY job_type
");
$jobTypeData = $jobTypeStmt->fetchAll(PDO::FETCH_ASSOC);
$jobTypeLabels = [];
$jobTypeCounts = [];
foreach ($jobTypeData as $item) {
    $jobTypeLabels[] = $item['job_type'];
    $jobTypeCounts[] = $item['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Tracker</title>
    <link rel="stylesheet" href="style.css?v=1.2.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                <h2>JobTracker</h2>
            </div>
            
            <ul class="sidebar-menu">
                <li class="sidebar-menu-item <?= $view === 'dashboard' ? 'active' : '' ?>">
                    <a href="index.php?view=dashboard">
                        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect></svg>
                        Dashboard
                    </a>
                </li>
                <li class="sidebar-menu-item <?= $view === 'applications' ? 'active' : '' ?>">
                    <a href="index.php?view=applications">
                        <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        Applications
                    </a>
                </li>
                <li class="sidebar-menu-item <?= $view === 'analytics' ? 'active' : '' ?>">
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
                <li class="mobile-nav-item <?= $view === 'dashboard' ? 'active' : '' ?>">
                    <a href="index.php?view=dashboard">
                        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect></svg>
                        Home
                    </a>
                </li>
                <li class="mobile-nav-item <?= $view === 'applications' ? 'active' : '' ?>">
                    <a href="index.php?view=applications">
                        <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        Jobs
                    </a>
                </li>
                <li class="mobile-nav-item <?= $view === 'analytics' ? 'active' : '' ?>">
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
            
            <!-- Topbar Header -->
            <div class="topbar">
                <div>
                    <?php if ($view === 'dashboard'): ?>
                        <h1>Dashboard</h1>
                        <p>Welcome back! Monitor your job search progress in one place.</p>
                    <?php elseif ($view === 'applications'): ?>
                        <h1>Applications</h1>
                        <p>Search, filter, and manage your job applications list.</p>
                    <?php else: ?>
                        <h1>Analytics</h1>
                        <p>Visualize key metrics, platform distributions, and job statistics.</p>
                    <?php endif; ?>
                </div>
                <div class="topbar-actions">
                    <!-- Notification Bell & Dropdown -->
                    <div class="notification-bell-wrapper">
                        <button class="notification-bell-btn" id="notificationBell" aria-label="Toggle notifications">
                            <svg viewBox="0 0 24 24">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            <?php if (count($reminders) > 0): ?>
                                <span class="notification-badge"><?= count($reminders) ?></span>
                            <?php endif; ?>
                        </button>
                        
                        <div class="notification-dropdown" id="notificationDropdown">
                            <div class="notification-dropdown-header">
                                <span class="notification-dropdown-title">Reminders (<?= count($reminders) ?>)</span>
                                <div class="notification-header-actions">
                                    <?php if (count($reminders) > 0): ?>
                                        <button class="notification-dropdown-clear" id="clearAllNotifBtn" title="Dismiss all notifications">Clear All</button>
                                    <?php endif; ?>
                                    <button class="notification-dropdown-enable" id="enablePushBtn">Enable Push</button>
                                </div>
                            </div>
                            <div class="notification-list">
                                <?php if (count($reminders) === 0): ?>
                                    <div class="notification-dropdown-empty">
                                        <svg viewBox="0 0 24 24">
                                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                                        </svg>
                                        <p>All caught up! No notifications or overdue follow-ups.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($reminders as $rem): 
                                        $dotClass = 'follow_up';
                                        if (strpos($rem['type'], 'overdue') !== false) {
                                            $dotClass = 'overdue';
                                        } elseif (strpos($rem['type'], 'interview') !== false) {
                                            $dotClass = 'interview';
                                        }
                                    ?>
                                        <div class="notification-item" data-app-detail='<?= $rem['detail'] ?>' data-app-id="<?= (int)$rem['app_id'] ?>" data-notif-type="<?= htmlspecialchars($rem['type']) ?>">
                                            <span class="notification-item-dot <?= $dotClass ?>"></span>
                                            <div class="notification-item-body">
                                                <span class="notification-item-text"><?= $rem['message'] ?></span>
                                                <span class="notification-item-time"><?= htmlspecialchars($rem['time']) ?></span>
                                            </div>
                                            <button class="notification-dismiss-btn" title="Dismiss this notification" aria-label="Dismiss notification">
                                                <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- CSRF token for notification dismiss AJAX -->
                    <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    
                    <a href="add.php" class="btn">+ Add Application</a>
                </div>
            </div>

            <!-- Stats metric grid (Rendered on Dashboard & Applications) -->
            <?php if ($view !== 'analytics'): ?>
            <div class="cards">
                <a href="index.php?view=applications" class="card-link">
                    <div class="card purple">
                        <h3>Total</h3>
                        <p><?= (int)$total ?></p>
                    </div>
                </a>
                <a href="index.php?view=applications&status=Applied" class="card-link">
                    <div class="card blue">
                        <h3>Applied</h3>
                        <p><?= (int)$applied ?></p>
                    </div>
                </a>
                <a href="index.php?view=applications&status=Pending" class="card-link">
                    <div class="card cyan">
                        <h3>Pending</h3>
                        <p><?= (int)$pending ?></p>
                    </div>
                </a>
                <a href="index.php?view=applications&status=Interview" class="card-link">
                    <div class="card orange">
                        <h3>Interview</h3>
                        <p><?= (int)$interview ?></p>
                    </div>
                </a>
                <a href="index.php?view=applications&status=Offered" class="card-link">
                    <div class="card green">
                        <h3>Offered</h3>
                        <p><?= (int)$offered ?></p>
                    </div>
                </a>
                <a href="index.php?view=applications&status=Expired_Rejected" class="card-link">
                    <div class="card red">
                        <h3>Expired, Rejected &amp; No Progress</h3>
                        <p><?= (int)$expired_rejected ?></p>
                    </div>
                </a>
            </div>
            <?php endif; ?>

            <!-- ================= VIEW 1: DASHBOARD ================= -->
            <?php if ($view === 'dashboard'): ?>
                <!-- Motivational Spotlight Hero Banner -->
                <?php
                    $weeklyGoal = 5;
                    $progressPct = min(100, round(($weeklyApplied / $weeklyGoal) * 100));
                    $quotes = [
                        "Each application is a seed sown for your future. Keep pushing!",
                        "The only way to fail is to stop trying. Your dream job is waiting!",
                        "Action breeds confidence and courage. Submit one more today!",
                        "Believe you can and you're halfway there. Let's make it happen!",
                        "Consistency is the key. Small steps every day lead to big results!"
                    ];
                    // Select quote based on day of week or count
                    $motivationalQuote = $quotes[($weeklyApplied + date('N')) % count($quotes)];
                    
                    if ($progressPct >= 100) {
                        $greeting = "Goal Achieved! You're Unstoppable! 🏆";
                        $advice = "Fantastic job! You met your weekly application target. Keep the momentum going!";
                    } elseif ($progressPct >= 60) {
                        $greeting = "You're Doing Amazing! ⚡";
                        $advice = "Just a little more to hit your weekly target of $weeklyGoal. You got this!";
                    } else {
                        $greeting = "Ready to Land Your Dream Job? 🚀";
                        $advice = "Apply to a few more positions to reach your weekly goal of $weeklyGoal!";
                    }
                ?>
                <div class="motivation-banner">
                    <div class="motivation-content">
                        <div class="motivation-text-side">
                            <h2><?= $greeting ?></h2>
                            <p class="motivation-advice"><?= $advice ?></p>
                            <p class="motivation-quote">"<?= $motivationalQuote ?>"</p>
                        </div>
                        <div class="motivation-progress-side">
                            <div class="motivation-progress-meta">
                                <span>Weekly Target Progress</span>
                                <strong><?= $weeklyApplied ?> / <?= $weeklyGoal ?></strong>
                            </div>
                            <div class="motivation-progress-container">
                                <div class="motivation-progress-bar" style="width: <?= $progressPct ?>%"></div>
                            </div>
                            <span class="motivation-pct"><?= $progressPct ?>% Complete</span>
                        </div>
                    </div>
                </div>

                <div class="dashboard-grid">
                    
                    <!-- Full-width: Recent Applications Table -->
                    <div class="table-box">
                        <h2>
                            Recent Applications
                            <a href="index.php?view=applications" class="btn secondary" style="padding: 6px 14px; font-size: 13px;">View All</a>
                        </h2>
                        
                        <div class="table-wrapper">
                            <?php if (empty($applications)): ?>
                                <div class="empty-state">
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                                    <h3>No applications tracked yet</h3>
                                    <p>Track your first job application to get started.</p>
                                </div>
                            <?php else: ?>
                                <table class="responsive-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 35%;">Job / Company</th>
                                            <th style="width: 25%;">Details</th>
                                            <th style="width: 15%;">Date Applied</th>
                                            <th style="width: 15%;">Status</th>
                                            <th style="width: 10%;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $recentApps = array_slice($applications, 0, 5);
                                        foreach ($recentApps as $row):
                                            $detailData = serializeAppDetail($row);
                                        ?>
                                            <tr class="app-row status-<?= getStatusClass($row['status']) ?>" data-status="<?= htmlspecialchars($row['status']) ?>" data-date="<?= htmlspecialchars($row['date_applied'] ?: $row['date_found'] ?: '') ?>" data-detail='<?= $detailData ?>'>
                                                <td data-label="Job" class="searchable">
                                                    <div class="company-info-cell">
                                                        <div class="company-logo-avatar" style="background: <?= getAvatarColor($row['company']) ?>; color: <?= getAvatarTextColor($row['company']) ?>;">
                                                            <?= htmlspecialchars(strtoupper(substr($row['company'], 0, 1))) ?>
                                                        </div>
                                                        <div class="company-text-info">
                                                            <a href="javascript:void(0)" class="detail-link job-title-link"><strong><?= htmlspecialchars($row['job_title']) ?></strong></a>
                                                            <span class="company-name"><?= htmlspecialchars($row['company']) ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td data-label="Details" class="searchable">
                                                    <div class="job-meta-details">
                                                        <?php if (!empty($row['location'])): ?>
                                                            <span class="meta-tag location-tag" title="Location">
                                                                <svg viewBox="0 0 24 24" width="12" height="12"><path d="M12 2a8 8 0 0 0-8 8c0 5.25 8 12 8 12s8-6.75 8-12a8 8 0 0 0-8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                                                                <?= htmlspecialchars($row['location']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($row['platform'])): ?>
                                                            <span class="meta-tag platform-tag" title="Platform">
                                                                <svg viewBox="0 0 24 24" width="12" height="12"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                                                <?= htmlspecialchars($row['platform']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <span class="meta-tag type-tag">
                                                            <?= htmlspecialchars($row['job_type'] ?: 'Full-time') ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td data-label="Date Applied">
                                                    <div class="date-applied-cell">
                                                        <svg viewBox="0 0 24 24" class="icon-calendar" width="14" height="14"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                                        <span><?= htmlspecialchars($row['date_applied'] ?: $row['date_found'] ?: 'N/A') ?></span>
                                                    </div>
                                                </td>
                                                <td data-label="Status">
                                                    <div class="status-assessment-cell">
                                                        <span class="badge <?= getStatusClass($row['status']) ?>">
                                                            <?= htmlspecialchars($row['status']) ?>
                                                        </span>
                                                        <?php if (!empty($row['assessment_status']) && $row['assessment_status'] !== 'None'): ?>
                                                            <span class="assessment-indicator <?= getStatusClass($row['assessment_status']) ?>" title="Assessment: <?= htmlspecialchars($row['assessment_status']) ?> (Deadline: <?= htmlspecialchars($row['assessment_deadline'] ?: 'No deadline') ?>)">
                                                                <svg viewBox="0 0 24 24" width="12" height="12"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                                                <span><?= htmlspecialchars($row['assessment_status']) ?></span>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td data-label="Actions">
                                                    <div class="action-buttons-cell">
                                                        <a class="action-btn edit-btn-icon" href="edit.php?id=<?= (int)$row['id'] ?>&back=<?= urlencode('index.php?' . http_build_query($_GET)) ?>" title="Edit Application">
                                                            <svg viewBox="0 0 24 24" width="16" height="16"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4z"/></svg>
                                                        </a>
                                                        <a class="action-btn delete-btn-icon confirm-delete" href="delete.php?id=<?= (int)$row['id'] ?>&token=<?= $_SESSION['csrf_token'] ?>" title="Delete Application" data-company="<?= htmlspecialchars($row['company']) ?>">
                                                            <svg viewBox="0 0 24 24" width="16" height="16"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Bottom row: Status Overview Chart -->
                    <div class="dashboard-chart-row">
                        <div class="chart-box">
                            <h2>Status Overview</h2>
                            <div style="position: relative; width: 100%; max-width: 320px; aspect-ratio: 1;">
                                <canvas id="quickStatusChart"></canvas>
                            </div>
                        </div>
                    </div>

                </div>

            <!-- ================= VIEW 2: APPLICATIONS LIST ================= -->
            <?php elseif ($view === 'applications'): ?>
                <div class="table-box">
                    
                    <div class="table-header-controls">
                        <!-- Left: Live Search -->
                        <div class="search-box-wrapper">
                            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="text" id="tableSearch" placeholder="Search company, title, platform, remark...">
                        </div>
                        
                        <!-- Right: Filter By Status + Export -->
                        <div class="filter-wrapper">
                            <select id="limitFilter" title="Show applications per page">
                                <?php $filterLimit = $_GET['limit'] ?? '50'; ?>
                                <option value="10" <?= $filterLimit === '10' ? 'selected' : '' ?>>Show 10</option>
                                <option value="20" <?= $filterLimit === '20' ? 'selected' : '' ?>>Show 20</option>
                                <option value="50" <?= $filterLimit === '50' ? 'selected' : '' ?>>Show 50</option>
                                <option value="all" <?= $filterLimit === 'all' ? 'selected' : '' ?>>Show All</option>
                            </select>

                            <select id="sortOrder" title="Sort by date applied">
                                <?php $filterSort = $_GET['sort'] ?? 'latest'; ?>
                                <option value="latest" <?= $filterSort === 'latest' ? 'selected' : '' ?>>Latest First</option>
                                <option value="earliest" <?= $filterSort === 'earliest' ? 'selected' : '' ?>>Earliest First</option>
                            </select>

                            <select id="statusFilter" >
                                <?php $filterStatus = $_GET['status'] ?? ''; ?>
                                <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>All Statuses</option>
                                <option value="Applied" <?= $filterStatus === 'Applied' ? 'selected' : '' ?>>Applied</option>
                                <option value="Pending" <?= $filterStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="Viewed Application" <?= $filterStatus === 'Viewed Application' ? 'selected' : '' ?>>Viewed Application</option>
                                <option value="Interview" <?= $filterStatus === 'Interview' ? 'selected' : '' ?>>Interview</option>
                                <option value="Assessment" <?= $filterStatus === 'Assessment' ? 'selected' : '' ?>>Assessment</option>
                                <option value="Rejected" <?= $filterStatus === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                                <option value="Offered" <?= $filterStatus === 'Offered' ? 'selected' : '' ?>>Offered</option>
                                <option value="Expired" <?= $filterStatus === 'Expired' ? 'selected' : '' ?>>Expired</option>
                                <option value="Unlikely to Progress Further" <?= $filterStatus === 'Unlikely to Progress Further' ? 'selected' : '' ?>>Unlikely to Progress Further</option>
                            </select>

                            <!-- Export to Excel Button -->
                            <?php
                                $exportParams = [];
                                if (!empty($_GET['status']))   $exportParams['status']   = $_GET['status'];
                                if (!empty($_GET['job_type'])) $exportParams['job_type'] = $_GET['job_type'];
                                if (!empty($_GET['platform'])) $exportParams['platform'] = $_GET['platform'];
                                $exportUrl = 'export.php' . (!empty($exportParams) ? '?' . http_build_query($exportParams) : '');
                            ?>
                            <a href="<?= htmlspecialchars($exportUrl) ?>" id="exportExcelBtn" class="btn export-btn" title="Export current view to Excel">
                                <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <line x1="12" y1="11" x2="12" y2="17"/>
                                    <line x1="9" y1="14" x2="15" y2="14"/>
                                </svg>
                                <span class="export-label">Export Excel</span>
                            </a>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <?php if (empty($applications)): ?>
                            <div class="empty-state">
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                                <h3>No applications found</h3>
                                <p>Click the "+ Add Application" button to insert a record.</p>
                            </div>
                        <?php else: ?>
                            <table id="applicationsTable" class="responsive-table">
                                <thead>
                                    <tr>
                                        <th style="width: 35%;">Job / Company</th>
                                        <th style="width: 25%;">Details</th>
                                        <th style="width: 15%;">Date Applied</th>
                                        <th style="width: 15%;">Status</th>
                                        <th style="width: 10%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($applications as $row):
                                        $detailData = serializeAppDetail($row);
                                    ?>
                                        <tr class="app-row status-<?= getStatusClass($row['status']) ?>" data-status="<?= htmlspecialchars($row['status']) ?>" data-date="<?= htmlspecialchars($row['date_applied'] ?: $row['date_found'] ?: '') ?>" data-detail='<?= $detailData ?>'>
                                            <td data-label="Job" class="searchable">
                                                <div class="company-info-cell">
                                                    <div class="company-logo-avatar" style="background: <?= getAvatarColor($row['company']) ?>; color: <?= getAvatarTextColor($row['company']) ?>;">
                                                        <?= htmlspecialchars(strtoupper(substr($row['company'], 0, 1))) ?>
                                                    </div>
                                                    <div class="company-text-info">
                                                        <a href="javascript:void(0)" class="detail-link job-title-link"><strong><?= htmlspecialchars($row['job_title']) ?></strong></a>
                                                        <span class="company-name"><?= htmlspecialchars($row['company']) ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td data-label="Details" class="searchable">
                                                <div class="job-meta-details">
                                                    <?php if (!empty($row['location'])): ?>
                                                        <span class="meta-tag location-tag" title="Location">
                                                            <svg viewBox="0 0 24 24" width="12" height="12"><path d="M12 2a8 8 0 0 0-8 8c0 5.25 8 12 8 12s8-6.75 8-12a8 8 0 0 0-8-8z"/><circle cx="12" cy="10" r="3"/></svg>
                                                            <?= htmlspecialchars($row['location']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($row['platform'])): ?>
                                                        <span class="meta-tag platform-tag" title="Platform">
                                                            <svg viewBox="0 0 24 24" width="12" height="12"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                                                            <?= htmlspecialchars($row['platform']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="meta-tag type-tag">
                                                        <?= htmlspecialchars($row['job_type'] ?: 'Full-time') ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td data-label="Date Applied">
                                                <div class="date-applied-cell">
                                                    <svg viewBox="0 0 24 24" class="icon-calendar" width="14" height="14"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                                    <span><?= htmlspecialchars($row['date_applied'] ?: $row['date_found'] ?: 'N/A') ?></span>
                                                </div>
                                            </td>
                                            <td data-label="Status">
                                                <div class="status-assessment-cell">
                                                    <span class="badge <?= getStatusClass($row['status']) ?>">
                                                        <?= htmlspecialchars($row['status']) ?>
                                                    </span>
                                                    <?php if (!empty($row['assessment_status']) && $row['assessment_status'] !== 'None'): ?>
                                                        <span class="assessment-indicator <?= getStatusClass($row['assessment_status']) ?>" title="Assessment: <?= htmlspecialchars($row['assessment_status']) ?> (Deadline: <?= htmlspecialchars($row['assessment_deadline'] ?: 'No deadline') ?>)">
                                                            <svg viewBox="0 0 24 24" width="12" height="12"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                                            <span><?= htmlspecialchars($row['assessment_status']) ?></span>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td data-label="Actions">
                                                <div class="action-buttons-cell">
                                                    <a class="action-btn edit-btn-icon" href="edit.php?id=<?= (int)$row['id'] ?>&back=<?= urlencode('index.php?' . http_build_query($_GET)) ?>" title="Edit Application">
                                                        <svg viewBox="0 0 24 24" width="16" height="16"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4z"/></svg>
                                                    </a>
                                                    <a class="action-btn delete-btn-icon confirm-delete" href="delete.php?id=<?= (int)$row['id'] ?>&token=<?= $_SESSION['csrf_token'] ?>" title="Delete Application" data-company="<?= htmlspecialchars($row['company']) ?>">
                                                        <svg viewBox="0 0 24 24" width="16" height="16"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div id="noResults" class="empty-state" style="display: none;">
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                                <h3>No matching applications found</h3>
                                <p>Try adjusting your search terms or filters.</p>
                            </div>

                            <div id="paginationControls" class="pagination-controls" style="display: none;">
                                <div class="pagination-info">
                                    Showing <span id="paginationStart">0</span>-<span id="paginationEnd">0</span> of <span id="paginationTotal">0</span> entries
                                </div>
                                <div class="pagination-buttons">
                                    <button id="prevPageBtn" class="btn secondary pagination-btn" title="Previous Page">
                                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="15 18 9 12 15 6"></polyline>
                                        </svg>
                                        Prev
                                    </button>
                                    <span id="pageIndicator" class="page-indicator">Page 1 of 1</span>
                                    <button id="nextPageBtn" class="btn secondary pagination-btn" title="Next Page">
                                        Next
                                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="9 18 15 12 9 6"></polyline>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- ================= VIEW 3: ANALYTICS ================= -->
            <?php else: ?>
                <div class="analytics-grid">
                    
                    <!-- Chart 1: Status Distribution -->
                    <div class="chart-box">
                        <h2>Application Statuses</h2>
                        <div style="position: relative; margin: auto; width: 100%; max-width: 320px; aspect-ratio: 1;">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>

                    <!-- Chart 2: Job Types -->
                    <div class="chart-box">
                        <h2>Job Type Distribution</h2>
                        <div style="position: relative; margin: auto; width: 100%; max-width: 320px; aspect-ratio: 1;">
                            <canvas id="jobTypeChart"></canvas>
                        </div>
                    </div>

                    <!-- Chart 3: Platform distribution -->
                    <div class="chart-box analytics-full">
                        <h2>Top Channels & Platforms</h2>
                        <div style="position: relative; width: 100%; max-width: 600px; height: 280px;">
                            <canvas id="platformChart"></canvas>
                        </div>
                    </div>

                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- Premium Job Details Modal -->
    <div id="detailsModal" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modalJobTitle">
        <div class="modal-container">
            <div class="modal-header">
                <div class="modal-title-area">
                    <h3 id="modalJobTitle" class="modal-title"></h3>
                    <div id="modalCompany" class="modal-subtitle"></div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span id="modalStatusBadge" class="badge"></span>
                    <button class="modal-close-btn" id="modalCloseBtn" aria-label="Close modal">
                        <svg viewBox="0 0 24 24">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="modal-content">

                <!-- 📋 Job Details — always visible, empty fields hidden -->
                <div class="modal-section-group">
                    <span class="modal-section-title">📋 Job Details</span>
                    <div class="modal-grid">
                        <div id="modalLocationItem" class="modal-info-item">
                            <span class="modal-info-label">Location</span>
                            <span id="modalLocation" class="modal-info-value"></span>
                        </div>
                        <div id="modalJobTypeItem" class="modal-info-item">
                            <span class="modal-info-label">Job Type</span>
                            <span id="modalJobType" class="modal-info-value"></span>
                        </div>
                        <div id="modalSalaryItem" class="modal-info-item">
                            <span class="modal-info-label">Salary Range</span>
                            <span id="modalSalary" class="modal-info-value"></span>
                        </div>
                        <div id="modalPlatformItem" class="modal-info-item">
                            <span class="modal-info-label">Platform / Channel</span>
                            <span id="modalPlatform" class="modal-info-value"></span>
                        </div>
                    </div>
                    <!-- Job link & Map link inline with details -->
                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
                        <div id="modalLinkWrapper" style="display: none;">
                            <a id="modalJobLink" href="" target="_blank" rel="noopener noreferrer" class="btn secondary" style="width: fit-content; padding: 7px 14px; font-size: 13px;">
                                <svg viewBox="0 0 24 24" style="width: 14px; height: 14px; stroke: currentColor; stroke-width: 2.5; fill: none; display: inline-block; vertical-align: middle; margin-right: 6px;">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                    <polyline points="15 3 21 3 21 9"></polyline>
                                    <line x1="10" y1="14" x2="21" y2="3"></line>
                                </svg>
                                View Job Posting
                            </a>
                        </div>
                        <div id="modalLocationLinkWrapper" style="display: none;">
                            <a id="modalLocationLink" href="" target="_blank" rel="noopener noreferrer" class="btn secondary" style="width: fit-content; padding: 7px 14px; font-size: 13px;">
                                📍 Open Location Map
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 🛠 Technical Skills (only when present) -->
                <div id="modalSkillsWrapper" class="modal-section-group" style="display: none;">
                    <span class="modal-section-title">🛠 Required Skills</span>
                    <div id="modalSkills" class="modal-skills-list"></div>
                </div>

                <!-- 📅 Timeline — only show fields that have data -->
                <div class="modal-section-group">
                    <span class="modal-section-title">📅 Timeline</span>
                    <div class="modal-grid">
                        <div id="modalDateAppliedItem" class="modal-info-item">
                            <span class="modal-info-label">Date Applied</span>
                            <span id="modalDateApplied" class="modal-info-value"></span>
                        </div>
                        <div id="modalDateFoundItem" class="modal-info-item" style="display:none;">
                            <span class="modal-info-label">Date Found</span>
                            <span id="modalDateFound" class="modal-info-value"></span>
                        </div>
                        <div id="modalFollowUpDateItem" class="modal-info-item" style="display:none;">
                            <span class="modal-info-label">📌 Follow-up Date</span>
                            <span id="modalFollowUpDate" class="modal-info-value"></span>
                        </div>
                    </div>
                </div>

                <!-- 🎙 Interview Section (only shown when interview data exists) -->
                <div id="modalInterviewSection" class="modal-section-group" style="display: none;">
                    <div class="modal-assessment-card">
                        <span class="modal-section-title">🎙 Interview</span>
                        <div class="modal-grid">
                            <div id="modalInterviewDateItem" class="modal-info-item" style="display:none;">
                                <span class="modal-info-label">Interview Date</span>
                                <span id="modalInterviewDate" class="modal-info-value"></span>
                            </div>
                            <div id="modalInterviewLocationItem" class="modal-info-item" style="display:none;">
                                <span class="modal-info-label">Location / Platform</span>
                                <span id="modalInterviewLocation" class="modal-info-value"></span>
                            </div>
                        </div>
                        <!-- Interview map link -->
                        <div id="modalInterviewLocationLinkWrapper" style="display:none; margin-top:10px;">
                            <a id="modalInterviewLocationLink" href="" target="_blank" rel="noopener noreferrer" class="btn secondary" style="width: fit-content; padding: 7px 14px; font-size: 13px;">
                                📍 Open in Maps
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 📝 Assessment Section (only shown when assessment data exists) -->
                <div id="modalAssessmentSection" class="modal-section-group" style="display: none;">
                    <div class="modal-assessment-card">
                        <span class="modal-section-title">📝 Assessment</span>
                        <div class="modal-grid">
                            <div id="modalAssessmentStatusItem" class="modal-info-item" style="display:none;">
                                <span class="modal-info-label">Status</span>
                                <span id="modalAssessmentStatus" class="modal-assessment-badge"></span>
                            </div>
                            <div id="modalAssessmentTypeItem" class="modal-info-item" style="display:none;">
                                <span class="modal-info-label">Type</span>
                                <span id="modalAssessmentType" class="modal-info-value"></span>
                            </div>
                            <div id="modalAssessmentDeadlineItem" class="modal-info-item" style="display:none;">
                                <span class="modal-info-label">Date</span>
                                <span id="modalAssessmentDeadline" class="modal-info-value"></span>
                            </div>
                            <div id="modalAssessmentPlatformItem" class="modal-info-item" style="display:none;">
                                <span class="modal-info-label">Platform</span>
                                <span id="modalAssessmentPlatform" class="modal-info-value"></span>
                            </div>
                        </div>
                        <div id="modalAssessmentLinkWrapper" style="display: none; margin-top: 10px;">
                            <a id="modalAssessmentLink" href="" target="_blank" rel="noopener noreferrer" class="btn secondary" style="width: fit-content; padding: 6px 14px; font-size: 12px;">
                                <svg viewBox="0 0 24 24" style="width: 14px; height: 14px; stroke: currentColor; stroke-width: 2.5; fill: none; display: inline-block; vertical-align: middle; margin-right: 5px;">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                    <polyline points="15 3 21 3 21 9"></polyline>
                                    <line x1="10" y1="14" x2="21" y2="3"></line>
                                </svg>
                                Open Assessment
                            </a>
                        </div>
                        <div id="modalAssessmentNotesWrapper" style="display: none; margin-top: 14px;">
                            <span class="modal-info-label">Notes / Reminders</span>
                            <div id="modalAssessmentNotes" class="modal-assessment-notes"></div>
                        </div>
                    </div>
                </div>

                <!-- 🏆 Result (only shown when available) -->
                <div id="modalResultWrapper" class="modal-section-group" style="display: none;">
                    <span class="modal-section-title">🏆 Result / Outcome</span>
                    <span id="modalResult" class="modal-info-value" style="font-weight: 700;"></span>
                </div>

                <!-- 📝 Notes & Remarks -->
                <div id="modalRemarkWrapper" class="modal-section-group" style="display:none;">
                    <span class="modal-section-title">📝 Notes & Remarks</span>
                    <div id="modalRemark" class="modal-remark-card"></div>
                </div>

            </div>

            <div class="modal-footer">
                <button id="modalDismissNotifBtn" class="btn warning" style="display: none;">
                    <svg viewBox="0 0 24 24" style="width: 16px; height: 16px; stroke: currentColor; stroke-width: 2.5; fill: none; display: inline-block; vertical-align: middle; margin-right: 6px;">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                    Dismiss Notification
                </button>
                <a id="modalEditBtn" href="" class="btn">Edit Application</a>
                <button id="modalCloseFooterBtn" class="btn secondary">Close</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" style="
        display:none; position:fixed; inset:0; z-index:9999;
        background:rgba(0,0,0,0.55); backdrop-filter:blur(4px);
        align-items:center; justify-content:center;
    ">
        <div style="
            background:var(--card-bg, #fff); border-radius:16px;
            padding:32px 28px; max-width:400px; width:90%;
            box-shadow:0 20px 60px rgba(0,0,0,0.3);
            text-align:center;
        ">
            <div style="font-size:2.5rem; margin-bottom:12px;">🗑️</div>
            <h3 style="margin:0 0 8px; font-size:1.15rem; color:var(--text-primary,#1e293b);">Delete Application?</h3>
            <p id="deleteConfirmMsg" style="margin:0 0 16px; color:var(--text-secondary,#64748b); font-size:.95rem; line-height:1.5;"></p>
            <p style="margin:0 0 24px; font-size:.85rem; color:#ef4444; font-weight:600;">⚠️ This action cannot be undone.</p>
            <div style="display:flex; gap:12px; justify-content:center;">
                <button id="deleteCancelBtn" class="btn secondary" style="flex:1; max-width:160px; justify-content:center;">Cancel</button>
                <button id="deleteConfirmBtn" class="btn" style="flex:1; max-width:160px; background:#ef4444; border-color:#ef4444; justify-content:center;">Yes, Delete</button>
            </div>
        </div>
    </div>

    <!-- ChartJS and Interactive Filters Script -->
    <script>
        // Shared colors matching CSS variables (Clean Light Mode)
        const chartColors = {
            saved: '#64748b',
            pending: '#ea580c',
            applied: '#2563eb',
            responded: '#7c3aed',
            interview: '#db2777',
            assessment: '#0891b2',
            rejected: '#dc2626',
            offered: '#16a34a',
            expired: '#94a3b8',
            unlikely: '#be185d',
            text: '#475569',
            grid: '#e2e8f0'
        };

        const mapColors = (labels) => {
            return labels.map(label => {
                const lower = label.toLowerCase();
                if (lower.includes('saved')) return chartColors.saved;
                if (lower.includes('pending')) return chartColors.pending;
                if (lower.includes('applied')) return chartColors.applied;
                if (lower.includes('responded')) return chartColors.responded;
                if (lower.includes('interview')) return chartColors.interview;
                if (lower.includes('assessment')) return chartColors.assessment;
                if (lower.includes('rejected')) return chartColors.rejected;
                if (lower.includes('offered')) return chartColors.offered;
                if (lower.includes('expired')) return chartColors.expired;
                if (lower.includes('unlikely')) return chartColors.unlikely;
                
                // Fallbacks for random/different job types or platforms (pastels)
                const hash = label.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0);
                const palettes = ['#f472b6', '#c084fc', '#60a5fa', '#34d399', '#fbbf24', '#f87171'];
                return palettes[hash % palettes.length];
            });
        };

        // Render charts depending on the active view
        <?php if ($view === 'dashboard'): ?>
            // Quick Status Doughnut
            const statusLabels = <?= json_encode($statusLabels) ?>;
            const statusCounts = <?= json_encode($statusCounts) ?>;
            
            new Chart(document.getElementById('quickStatusChart'), {
                type: 'doughnut',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusCounts,
                        backgroundColor: mapColors(statusLabels),
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (evt, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const label = statusLabels[index];
                            window.location.href = 'index.php?view=applications&status=' + encodeURIComponent(label);
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: chartColors.text,
                                font: { family: 'Outfit', size: 12 }
                            }
                        }
                    }
                }
            });
        <?php endif; ?>

        <?php if ($view === 'analytics'): ?>
            // Analytics View status doughnut
            const statusLabels = <?= json_encode($statusLabels) ?>;
            const statusCounts = <?= json_encode($statusCounts) ?>;
            
            new Chart(document.getElementById('statusChart'), {
                type: 'doughnut',
                data: {
                    labels: statusLabels,
                    datasets: [{
                        data: statusCounts,
                        backgroundColor: mapColors(statusLabels),
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (evt, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const label = statusLabels[index];
                            window.location.href = 'index.php?view=applications&status=' + encodeURIComponent(label);
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: chartColors.text,
                                font: { family: 'Outfit', size: 12 }
                            }
                        }
                    }
                }
            });

            // Render Job Type Doughnut Chart
            const jobTypeLabels = <?php echo json_encode($jobTypeLabels); ?>;
            const jobTypeCounts = <?php echo json_encode($jobTypeCounts); ?>;
            new Chart(document.getElementById('jobTypeChart'), {
                type: 'doughnut',
                data: {
                    labels: jobTypeLabels,
                    datasets: [{
                        data: jobTypeCounts,
                        backgroundColor: mapColors(jobTypeLabels),
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (evt, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const label = jobTypeLabels[index];
                            window.location.href = 'index.php?view=applications&job_type=' + encodeURIComponent(label);
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: chartColors.text,
                                font: { family: 'Outfit', size: 12 }
                            }
                        }
                    }
                }
            });

            // Render Platform Bar Chart
            const platformLabels = <?php echo json_encode($platformLabels); ?>;
            const platformCounts = <?php echo json_encode($platformCounts); ?>;
            new Chart(document.getElementById('platformChart'), {
                type: 'bar',
                data: {
                    labels: platformLabels,
                    datasets: [{
                        label: 'Applications',
                        data: platformCounts,
                        backgroundColor: mapColors(platformLabels),
                        borderWidth: 1,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (evt, elements) => {
                        if (elements.length > 0) {
                            const index = elements[0].index;
                            const label = platformLabels[index];
                            window.location.href = 'index.php?view=applications&platform=' + encodeURIComponent(label);
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: chartColors.text, font: { family: 'Outfit' } }
                        },
                        y: {
                            grid: { color: chartColors.grid },
                            ticks: { color: chartColors.text, font: { family: 'Outfit' }, precision: 0 }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        <?php endif; ?>

        // Applications list filtering logic (Client-Side Search & Filter with URL syncing & Pagination)
        <?php if ($view === 'applications' && !empty($applications)): ?>
            const tableSearch = document.getElementById('tableSearch');
            const statusFilter = document.getElementById('statusFilter');
            const limitFilter = document.getElementById('limitFilter');
            const sortOrder = document.getElementById('sortOrder');
            const tableRows = document.querySelectorAll('.app-row');
            const noResults = document.getElementById('noResults');
            const tableElement = document.getElementById('applicationsTable');
            const paginationControls = document.getElementById('paginationControls');
            const paginationStart = document.getElementById('paginationStart');
            const paginationEnd = document.getElementById('paginationEnd');
            const paginationTotal = document.getElementById('paginationTotal');
            const prevPageBtn = document.getElementById('prevPageBtn');
            const nextPageBtn = document.getElementById('nextPageBtn');
            const pageIndicator = document.getElementById('pageIndicator');

            let currentPage = 1;
            let pageSize = 50;
            let currentSort = 'latest';

            function filterTable() {
                const query = tableSearch.value.toLowerCase().trim();
                const selectedStatus = statusFilter.value;
                const matchingRows = [];

                tableRows.forEach(row => {
                    const rowStatus = row.getAttribute('data-status');
                    let detailText = '';
                    const detailDataStr = row.getAttribute('data-detail');
                    if (detailDataStr) {
                        try {
                            const details = JSON.parse(detailDataStr);
                            detailText = [
                                details.company,
                                details.job_title,
                                details.platform,
                                details.location,
                                details.remark,
                                details.technical_skills
                            ].filter(Boolean).join(' ').toLowerCase();
                        } catch (e) {}
                    }

                    const textContent = Array.from(row.querySelectorAll('.searchable'))
                        .map(cell => cell.textContent.toLowerCase())
                        .join(' ') + ' ' + detailText;
                    
                    const matchesSearch = query === '' || textContent.includes(query);
                    const matchesStatus = selectedStatus === '' || 
                        (selectedStatus === 'Expired_Rejected' 
                            ? (rowStatus === 'Expired' || rowStatus === 'Rejected' || rowStatus === 'Unlikely to Progress Further') 
                            : rowStatus === selectedStatus);

                    if (matchesSearch && matchesStatus) {
                        matchingRows.push(row);
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Sort matching rows by date
                const tbody = tableElement.querySelector('tbody');
                matchingRows.sort((a, b) => {
                    const dateA = a.getAttribute('data-date') || '';
                    const dateB = b.getAttribute('data-date') || '';
                    if (currentSort === 'latest') {
                        return dateB.localeCompare(dateA);
                    } else {
                        return dateA.localeCompare(dateB);
                    }
                });
                // Re-append sorted rows to DOM (non-matching rows stay hidden at end)
                matchingRows.forEach(row => tbody.appendChild(row));

                const visibleCount = matchingRows.length;
                let totalPages = 1;
                const effectivePageSize = pageSize === 'all' ? visibleCount : pageSize;

                if (effectivePageSize > 0 && visibleCount > 0) {
                    totalPages = Math.ceil(visibleCount / effectivePageSize);
                }

                // Clamp currentPage
                if (currentPage > totalPages) {
                    currentPage = totalPages;
                }
                if (currentPage < 1) {
                    currentPage = 1;
                }

                // Display rows for current page
                matchingRows.forEach((row, index) => {
                    if (pageSize === 'all') {
                        row.style.display = '';
                    } else {
                        const startIdx = (currentPage - 1) * pageSize;
                        const endIdx = startIdx + pageSize;
                        if (index >= startIdx && index < endIdx) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });

                if (visibleCount === 0) {
                    tableElement.style.display = 'none';
                    noResults.style.display = '';
                    paginationControls.style.display = 'none';
                } else {
                    tableElement.style.display = '';
                    noResults.style.display = 'none';
                    paginationControls.style.display = '';

                    const startEntry = pageSize === 'all' ? 1 : (currentPage - 1) * pageSize + 1;
                    const endEntry = pageSize === 'all' ? visibleCount : Math.min(currentPage * pageSize, visibleCount);

                    paginationStart.textContent = startEntry.toString();
                    paginationEnd.textContent = endEntry.toString();
                    paginationTotal.textContent = visibleCount.toString();

                    pageIndicator.textContent = `Page ${currentPage} of ${totalPages}`;

                    prevPageBtn.disabled = (currentPage === 1);
                    nextPageBtn.disabled = (currentPage === totalPages);
                }

                // Sync filters & pagination to URL query parameters
                const currentParams = new URLSearchParams(window.location.search);
                if (query !== '') {
                    currentParams.set('search', tableSearch.value);
                } else {
                    currentParams.delete('search');
                }
                if (selectedStatus !== '') {
                    currentParams.set('status', selectedStatus);
                } else {
                    currentParams.delete('status');
                }

                currentParams.set('limit', pageSize.toString());
                currentParams.set('page', currentPage.toString());
                currentParams.set('sort', currentSort);

                const newSearch = currentParams.toString();
                const newUrl = window.location.pathname + (newSearch ? '?' + newSearch : '');
                window.history.replaceState(null, '', newUrl);

                // Dynamically sync Export Excel button's href to current active filters
                const exportBtn = document.getElementById('exportExcelBtn');
                if (exportBtn) {
                    const exportParams = new URLSearchParams();
                    if (selectedStatus !== '') {
                        exportParams.set('status', selectedStatus);
                    }
                    if (query !== '') {
                        exportParams.set('search', tableSearch.value);
                    }
                    if (currentParams.has('job_type')) {
                        exportParams.set('job_type', currentParams.get('job_type'));
                    }
                    if (currentParams.has('platform')) {
                        exportParams.set('platform', currentParams.get('platform'));
                    }
                    if (currentParams.has('assessment_status')) {
                        exportParams.set('assessment_status', currentParams.get('assessment_status'));
                    }
                    exportBtn.href = 'export.php' + (exportParams.toString() ? '?' + exportParams.toString() : '');
                }
            }

            // Load filters and pagination from URL parameters on page load
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('search')) {
                tableSearch.value = urlParams.get('search');
            }
            if (urlParams.has('status')) {
                statusFilter.value = urlParams.get('status');
            }
            if (urlParams.has('limit')) {
                const urlLimit = urlParams.get('limit');
                if (['10', '20', '50', 'all'].includes(urlLimit)) {
                    pageSize = urlLimit === 'all' ? 'all' : parseInt(urlLimit, 10);
                    limitFilter.value = urlLimit;
                }
            } else {
                limitFilter.value = pageSize.toString();
            }
            if (urlParams.has('page')) {
                const urlPage = parseInt(urlParams.get('page'), 10);
                if (urlPage > 0) {
                    currentPage = urlPage;
                }
            }
            if (urlParams.has('sort')) {
                const urlSort = urlParams.get('sort');
                if (['latest', 'earliest'].includes(urlSort)) {
                    currentSort = urlSort;
                    sortOrder.value = urlSort;
                }
            }

            filterTable();

            tableSearch.addEventListener('input', function () {
                currentPage = 1;
                filterTable();
            });

            limitFilter.addEventListener('change', function () {
                const val = limitFilter.value;
                pageSize = val === 'all' ? 'all' : parseInt(val, 10);
                currentPage = 1;
                filterTable();
            });

            sortOrder.addEventListener('change', function () {
                currentSort = sortOrder.value;
                currentPage = 1;
                filterTable();
            });

            prevPageBtn.addEventListener('click', function () {
                if (currentPage > 1) {
                    currentPage--;
                    filterTable();
                    tableElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });

            nextPageBtn.addEventListener('click', function () {
                // Determine max pages again dynamically
                const query = tableSearch.value.toLowerCase().trim();
                const selectedStatus = statusFilter.value;
                let visibleCount = 0;
                tableRows.forEach(row => {
                    const rowStatus = row.getAttribute('data-status');
                    let detailText = '';
                    const detailDataStr = row.getAttribute('data-detail');
                    if (detailDataStr) {
                        try {
                            const details = JSON.parse(detailDataStr);
                            detailText = [
                                details.company,
                                details.job_title,
                                details.platform,
                                details.location,
                                details.remark,
                                details.technical_skills
                            ].filter(Boolean).join(' ').toLowerCase();
                        } catch (e) {}
                    }
                    const textContent = Array.from(row.querySelectorAll('.searchable'))
                        .map(cell => cell.textContent.toLowerCase())
                        .join(' ') + ' ' + detailText;
                    
                    const matchesSearch = query === '' || textContent.includes(query);
                    const matchesStatus = selectedStatus === '' || 
                        (selectedStatus === 'Expired_Rejected' 
                            ? (rowStatus === 'Expired' || rowStatus === 'Rejected' || rowStatus === 'Unlikely to Progress Further') 
                            : rowStatus === selectedStatus);
                    if (matchesSearch && matchesStatus) {
                        visibleCount++;
                    }
                });
                const effectivePageSize = pageSize === 'all' ? visibleCount : pageSize;
                const totalPages = Math.ceil(visibleCount / effectivePageSize);

                if (currentPage < totalPages) {
                    currentPage++;
                    filterTable();
                    tableElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });

            // Status dropdown triggers a full page reload so PHP always
            // renders the correct unfiltered dataset before JS applies search.
            statusFilter.addEventListener('change', function () {
                const params = new URLSearchParams(window.location.search);
                const selected = statusFilter.value;
                if (selected !== '') {
                    params.set('status', selected);
                } else {
                    params.delete('status');
                }
                // Preserve view and other params, strip stale search
                params.delete('search');
                params.set('view', 'applications');
                params.set('limit', pageSize.toString());
                params.set('page', '1');
                params.set('sort', currentSort);
                window.location.href = window.location.pathname + '?' + params.toString();
            });
        <?php endif; ?>

        // ================= DETAILED MODAL CONTROLLER =================
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('detailsModal');
            const closeBtns = [
                document.getElementById('modalCloseBtn'),
                document.getElementById('modalCloseFooterBtn')
            ];
            const editBtn = document.getElementById('modalEditBtn');
            const dismissNotifBtn = document.getElementById('modalDismissNotifBtn');

            function showDetail(details, appId = null, notifType = null) {
                // Title & company
                document.getElementById('modalJobTitle').textContent = details.job_title || 'N/A';
                document.getElementById('modalCompany').textContent  = details.company    || 'N/A';

                // Status Badge
                const statusBadge = document.getElementById('modalStatusBadge');
                statusBadge.textContent = details.status || 'Unknown';
                statusBadge.className   = 'badge ' + (details.status ? details.status.toLowerCase().replace(/\s+/g, '-') : 'unknown');

                // Helper: show a wrapper item only when val has content
                const setOptional = (wrapperId, valueId, val, isText = true) => {
                    const wrapper = document.getElementById(wrapperId);
                    const el      = document.getElementById(valueId);
                    if (val && val.toString().trim() !== '') {
                        wrapper.style.display = '';
                        if (isText) el.textContent = val;
                        return true;
                    } else {
                        wrapper.style.display = 'none';
                        return false;
                    }
                };

                // ── Job Details (hide individual items if empty) ──
                setOptional('modalLocationItem',  'modalLocation',  details.location);
                setOptional('modalJobTypeItem',   'modalJobType',   details.job_type);
                setOptional('modalSalaryItem',    'modalSalary',    details.salary_range);
                setOptional('modalPlatformItem',  'modalPlatform',  details.platform);

                // Job link
                const linkWrapper = document.getElementById('modalLinkWrapper');
                const jobLink     = document.getElementById('modalJobLink');
                if (details.job_link && details.job_link.trim() !== '') {
                    linkWrapper.style.display = '';
                    jobLink.setAttribute('href', details.job_link);
                } else {
                    linkWrapper.style.display = 'none';
                }

                // Location map link
                const locationLinkWrapper = document.getElementById('modalLocationLinkWrapper');
                const locationLink        = document.getElementById('modalLocationLink');
                if (details.location_link && details.location_link.trim() !== '') {
                    locationLinkWrapper.style.display = '';
                    locationLink.setAttribute('href', details.location_link);
                } else {
                    locationLinkWrapper.style.display = 'none';
                }

                // ── Technical Skills ──
                const skillsContainer = document.getElementById('modalSkills');
                skillsContainer.replaceChildren();
                const skillsWrapper = document.getElementById('modalSkillsWrapper');
                if (details.technical_skills && details.technical_skills.trim() !== '') {
                    skillsWrapper.style.display = '';
                    details.technical_skills.split(',').map(s => s.trim()).filter(Boolean).forEach(skill => {
                        const tag = document.createElement('span');
                        tag.className   = 'modal-skill-tag';
                        tag.textContent = skill;
                        skillsContainer.appendChild(tag);
                    });
                } else {
                    skillsWrapper.style.display = 'none';
                }

                // ── Timeline (only show fields with data) ──
                // Date Applied always shown
                const daEl = document.getElementById('modalDateApplied');
                daEl.textContent = details.date_applied || 'Not specified';
                daEl.className   = details.date_applied ? 'modal-info-value' : 'modal-info-value empty';

                setOptional('modalDateFoundItem',    'modalDateFound',    details.date_found);
                setOptional('modalFollowUpDateItem', 'modalFollowUpDate', details.follow_up_date);

                // ── Interview Section (show only when interview data exists) ──
                const interviewSection  = document.getElementById('modalInterviewSection');
                const hasInterview      = (details.interview_date && details.interview_date.trim()) ||
                                          (details.interview_location && details.interview_location.trim());
                if (hasInterview) {
                    interviewSection.style.display = '';
                    setOptional('modalInterviewDateItem',     'modalInterviewDate',     details.interview_date);
                    setOptional('modalInterviewLocationItem', 'modalInterviewLocation', details.interview_location);

                    // Interview location map link
                    const iLocLinkWrapper = document.getElementById('modalInterviewLocationLinkWrapper');
                    const iLocLink        = document.getElementById('modalInterviewLocationLink');
                    if (details.interview_location_link && details.interview_location_link.trim() !== '') {
                        iLocLinkWrapper.style.display = '';
                        iLocLink.setAttribute('href', details.interview_location_link);
                    } else {
                        iLocLinkWrapper.style.display = 'none';
                    }
                } else {
                    interviewSection.style.display = 'none';
                }

                // ── Assessment Section (show only when assessment data exists) ──
                const assessmentSection = document.getElementById('modalAssessmentSection');
                const aStatus           = (details.assessment_status || 'None').trim();
                const hasAssessment     = aStatus !== 'None' && aStatus !== '' ||
                                          (details.assessment_type && details.assessment_type.trim()) ||
                                          (details.assessment_deadline && details.assessment_deadline.trim());
                if (hasAssessment) {
                    assessmentSection.style.display = '';

                    // Assessment status badge
                    if (aStatus && aStatus !== 'None') {
                        const aBadge = document.getElementById('modalAssessmentStatus');
                        aBadge.textContent = aStatus;
                        aBadge.className   = 'modal-assessment-badge ' + aStatus.toLowerCase();
                        document.getElementById('modalAssessmentStatusItem').style.display = '';
                    } else {
                        document.getElementById('modalAssessmentStatusItem').style.display = 'none';
                    }

                    setOptional('modalAssessmentTypeItem',     'modalAssessmentType',     details.assessment_type);
                    setOptional('modalAssessmentDeadlineItem', 'modalAssessmentDeadline', details.assessment_deadline);
                    setOptional('modalAssessmentPlatformItem', 'modalAssessmentPlatform', details.assessment_platform);

                    // Assessment link button
                    const aLinkWrapper = document.getElementById('modalAssessmentLinkWrapper');
                    const aLink        = document.getElementById('modalAssessmentLink');
                    if (details.assessment_link && details.assessment_link.trim() !== '') {
                        aLinkWrapper.style.display = '';
                        aLink.setAttribute('href', details.assessment_link);
                    } else {
                        aLinkWrapper.style.display = 'none';
                    }

                    // Assessment notes
                    const aNotesWrapper = document.getElementById('modalAssessmentNotesWrapper');
                    const aNotesEl      = document.getElementById('modalAssessmentNotes');
                    if (details.assessment_notes && details.assessment_notes.trim() !== '') {
                        aNotesWrapper.style.display = '';
                        aNotesEl.textContent = details.assessment_notes;
                    } else {
                        aNotesWrapper.style.display = 'none';
                    }
                } else {
                    assessmentSection.style.display = 'none';
                }

                // ── Result ──
                const resultWrapper = document.getElementById('modalResultWrapper');
                if (details.result && details.result.trim() !== '') {
                    document.getElementById('modalResult').textContent = details.result;
                    resultWrapper.style.display = '';
                } else {
                    resultWrapper.style.display = 'none';
                }

                // ── Notes / Remarks ──
                const remarkWrapper   = document.getElementById('modalRemarkWrapper');
                const remarkContainer = document.getElementById('modalRemark');
                if (details.remark && details.remark.trim() !== '') {
                    remarkWrapper.style.display   = '';
                    remarkContainer.textContent   = details.remark;
                    remarkContainer.classList.remove('empty');
                    remarkContainer.style.display = '';
                } else {
                    remarkWrapper.style.display = 'none';
                    remarkContainer.textContent = '';
                    remarkContainer.classList.add('empty');
                }

                // Edit button href
                editBtn.setAttribute('href', 'edit.php?id=' + encodeURIComponent(details.id) + '&back=' + encodeURIComponent(window.location.pathname + window.location.search));

                // Handle Notification Dismiss button in modal
                if (dismissNotifBtn) {
                    let activeNotifEl = null;
                    let targetNotifType = notifType;
                    let targetAppId = appId || details.id;

                    const dropdownEl = document.getElementById('notificationDropdown');
                    if (dropdownEl) {
                        if (targetNotifType) {
                            activeNotifEl = dropdownEl.querySelector(`.notification-item[data-app-id="${targetAppId}"][data-notif-type="${targetNotifType}"]`);
                        } else {
                            activeNotifEl = dropdownEl.querySelector(`.notification-item[data-app-id="${targetAppId}"]`);
                        }
                    }

                    if (activeNotifEl) {
                        dismissNotifBtn.style.display = 'inline-flex';
                        dismissNotifBtn.setAttribute('data-app-id', activeNotifEl.getAttribute('data-app-id'));
                        dismissNotifBtn.setAttribute('data-notif-type', activeNotifEl.getAttribute('data-notif-type'));
                    } else if (appId && notifType) {
                        dismissNotifBtn.style.display = 'inline-flex';
                        dismissNotifBtn.setAttribute('data-app-id', appId);
                        dismissNotifBtn.setAttribute('data-notif-type', notifType);
                    } else {
                        dismissNotifBtn.style.display = 'none';
                        dismissNotifBtn.removeAttribute('data-app-id');
                        dismissNotifBtn.removeAttribute('data-notif-type');
                    }
                }

                // Activate modal and lock body scroll
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }

            window.showDetail = showDetail;

            if (dismissNotifBtn) {
                dismissNotifBtn.addEventListener('click', () => {
                    const appId = dismissNotifBtn.getAttribute('data-app-id');
                    const notifType = dismissNotifBtn.getAttribute('data-notif-type');
                    if (appId && notifType) {
                        const dropdownEl = document.getElementById('notificationDropdown');
                        const itemEl = dropdownEl ? dropdownEl.querySelector(`.notification-item[data-app-id="${appId}"][data-notif-type="${notifType}"]`) : null;
                        
                        if (typeof dismissNotification === 'function') {
                            dismissNotification(appId, notifType, itemEl);
                        }
                        
                        dismissNotifBtn.style.display = 'none';
                        hideModal();
                    }
                });
            }

            function hideModal() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }

            // Event delegation to capture clicks on detail-link anchors
            document.addEventListener('click', (e) => {
                const detailLink = e.target.closest('.detail-link');
                if (detailLink) {
                    e.preventDefault();
                    const tr = detailLink.closest('tr');
                    if (tr) {
                        const detailDataStr = tr.getAttribute('data-detail');
                        if (detailDataStr) {
                            try {
                                const details = JSON.parse(detailDataStr);
                                showDetail(details);
                            } catch (err) {
                                console.error('Error parsing job detail:', err);
                            }
                        }
                    }
                }
            });

            // ── Delete confirmation modal ──
            const deleteModal    = document.getElementById('deleteConfirmModal');
            const deleteMsg      = document.getElementById('deleteConfirmMsg');
            const deleteCancelBtn= document.getElementById('deleteCancelBtn');
            const deleteConfirmBtn= document.getElementById('deleteConfirmBtn');
            let pendingDeleteUrl = null;

            function showDeleteConfirm(href, company) {
                pendingDeleteUrl = href;
                deleteMsg.textContent = 'You are about to permanently delete the application for "' + company + '". Are you sure?';
                deleteModal.style.display = 'flex';
            }
            function hideDeleteConfirm() {
                deleteModal.style.display = 'none';
                pendingDeleteUrl = null;
            }

            deleteCancelBtn.addEventListener('click', hideDeleteConfirm);
            deleteModal.addEventListener('click', (e) => { if (e.target === deleteModal) hideDeleteConfirm(); });
            deleteConfirmBtn.addEventListener('click', () => {
                if (pendingDeleteUrl) window.location.href = pendingDeleteUrl;
            });

            // Intercept Edit and Delete link clicks to append the current page/filter state
            document.addEventListener('click', (e) => {
                const editLink = e.target.closest('.action-links a.edit');
                if (editLink) {
                    e.preventDefault();
                    const currentUrl = window.location.pathname + window.location.search;
                    const href = editLink.getAttribute('href');
                    window.location.href = href + '&back=' + encodeURIComponent(currentUrl);
                }

                // All delete buttons (table rows + any .action-links.delete)
                const deleteLink = e.target.closest('.confirm-delete, .action-links a.delete');
                if (deleteLink) {
                    e.preventDefault();
                    const company = deleteLink.dataset.company || 'this application';
                    const currentUrl = window.location.pathname + window.location.search;
                    const href = deleteLink.getAttribute('href') + '&back=' + encodeURIComponent(currentUrl);
                    showDeleteConfirm(href, company);
                }
            });

            // Close buttons click events
            closeBtns.forEach(btn => {
                if (btn) btn.addEventListener('click', hideModal);
            });

            // Close modal by clicking on backdrop
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    hideModal();
                }
            });

            // Close modal with ESC key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    hideModal();
                }
            });

            // ================= TOAST SYSTEM CONTROLLER =================
            const toastContainer = document.getElementById('toastContainer');

            window.showToast = function(message, type = 'success') {
                if (!toastContainer) return;
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                
                let iconSVG = '';
                if (type === 'success') {
                    iconSVG = `<svg viewBox="0 0 24 24" style="width: 18px; height: 18px; stroke: currentColor; stroke-width: 2.5; fill: none;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
                } else if (type === 'info') {
                    iconSVG = `<svg viewBox="0 0 24 24" style="width: 18px; height: 18px; stroke: currentColor; stroke-width: 2.5; fill: none;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>`;
                } else if (type === 'warning') {
                    iconSVG = `<svg viewBox="0 0 24 24" style="width: 18px; height: 18px; stroke: currentColor; stroke-width: 2.5; fill: none;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`;
                } else {
                    iconSVG = `<svg viewBox="0 0 24 24" style="width: 18px; height: 18px; stroke: currentColor; stroke-width: 2.5; fill: none;"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`;
                }

                toast.innerHTML = `
                    <div class="toast-content">
                        <div class="toast-icon">${iconSVG}</div>
                        <span class="toast-message">${message}</span>
                    </div>
                    <button class="toast-close" aria-label="Close message">
                        <svg viewBox="0 0 24 24">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                `;

                toastContainer.appendChild(toast);

                // Auto-slide-in transition
                setTimeout(() => {
                    toast.classList.add('show');
                }, 50);

                // Setup dismiss handlers
                const closeBtn = toast.querySelector('.toast-close');
                const dismissToast = () => {
                    toast.classList.remove('show');
                    setTimeout(() => {
                        toast.remove();
                    }, 400);
                };

                if (closeBtn) {
                    closeBtn.addEventListener('click', dismissToast);
                }

                // Auto hide after 5 seconds
                setTimeout(dismissToast, 5000);
            };

            // ================= NOTIFICATION BELL & DROPDOWN =================
            const bellBtn = document.getElementById('notificationBell');
            const dropdown = document.getElementById('notificationDropdown');
            const csrfTokenEl = document.getElementById('csrfToken');
            const csrfToken = csrfTokenEl ? csrfTokenEl.value : '';

            /**
             * Dismiss a single notification via AJAX POST.
             */
            function dismissNotification(appId, notifType, itemEl) {
                const formData = new FormData();
                formData.append('action', 'dismiss');
                formData.append('app_id', appId);
                formData.append('type', notifType);
                formData.append('token', csrfToken);

                fetch('dismiss_notification.php', {
                    method: 'POST',
                    body: formData
                }).then(res => res.json()).then(data => {
                    if (data.success) {
                        if (itemEl) {
                            // Animate removal
                            itemEl.style.transition = 'opacity 0.3s ease, max-height 0.3s ease, padding 0.3s ease';
                            itemEl.style.opacity = '0';
                            itemEl.style.maxHeight = '0';
                            itemEl.style.paddingTop = '0';
                            itemEl.style.paddingBottom = '0';
                            itemEl.style.overflow = 'hidden';
                            setTimeout(() => {
                                itemEl.remove();
                                updateNotificationCount();
                            }, 300);
                        } else {
                            updateNotificationCount();
                        }
                    }
                }).catch(() => {
                    // Silently fail - notification stays visible
                });
            }

            /**
             * Update the notification badge count and title after dismissals.
             */
            function updateNotificationCount() {
                const remainingItems = dropdown ? dropdown.querySelectorAll('.notification-item') : [];
                const count = remainingItems.length;

                // Update badge
                const badge = bellBtn ? bellBtn.querySelector('.notification-badge') : null;
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count;
                    } else {
                        badge.remove();
                    }
                }

                // Update dropdown title
                const titleEl = dropdown ? dropdown.querySelector('.notification-dropdown-title') : null;
                if (titleEl) {
                    titleEl.textContent = 'Reminders (' + count + ')';
                }

                // If no notifications left, show empty state
                if (count === 0) {
                    const listEl = dropdown ? dropdown.querySelector('.notification-list') : null;
                    if (listEl) {
                        listEl.replaceChildren(); // Safe DOM clear

                        const emptyDiv = document.createElement('div');
                        emptyDiv.className = 'notification-dropdown-empty';

                        const svgMarkup = '<svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>';
                        const svgDoc = new DOMParser().parseFromString(svgMarkup, 'image/svg+xml');
                        emptyDiv.appendChild(svgDoc.documentElement);

                        const p = document.createElement('p');
                        p.textContent = 'All caught up! No notifications or overdue follow-ups.';
                        emptyDiv.appendChild(p);

                        listEl.appendChild(emptyDiv);
                    }

                    // Hide Clear All button
                    const clearBtn = document.getElementById('clearAllNotifBtn');
                    if (clearBtn) clearBtn.style.display = 'none';
                }
            }

            if (bellBtn && dropdown) {
                // Bell click toggling
                bellBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    dropdown.classList.toggle('active');
                });

                // Dismiss bell dropdown on click outside
                document.addEventListener('click', (e) => {
                    if (!dropdown.contains(e.target) && e.target !== bellBtn && !bellBtn.contains(e.target)) {
                        dropdown.classList.remove('active');
                    }
                });

                // Dropdown notification item clicks -> open detailed modal instantly
                // (but not if the dismiss button was clicked)
                const notifItems = dropdown.querySelectorAll('.notification-item');
                notifItems.forEach(item => {
                    item.addEventListener('click', (e) => {
                        // Ignore if dismiss button was clicked
                        if (e.target.closest('.notification-dismiss-btn')) return;

                        dropdown.classList.remove('active');
                        const detailStr = item.getAttribute('data-app-detail');
                        const appId = item.getAttribute('data-app-id');
                        const notifType = item.getAttribute('data-notif-type');
                        if (detailStr) {
                            try {
                                const details = JSON.parse(detailStr);
                                showDetail(details, appId, notifType);
                            } catch (err) {
                                console.error('Error parsing notification job detail:', err);
                            }
                        }
                    });

                    // Dismiss button click handler
                    const dismissBtn = item.querySelector('.notification-dismiss-btn');
                    if (dismissBtn) {
                        dismissBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const appId = item.getAttribute('data-app-id');
                            const notifType = item.getAttribute('data-notif-type');
                            if (appId && notifType) {
                                dismissNotification(appId, notifType, item);
                            }
                        });
                    }
                });

                // Clear All button handler
                const clearAllBtn = document.getElementById('clearAllNotifBtn');
                if (clearAllBtn) {
                    clearAllBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const items = dropdown.querySelectorAll('.notification-item');
                        if (items.length === 0) return;

                        // Collect all notification data for bulk dismiss
                        const notifications = [];
                        items.forEach(item => {
                            notifications.push({
                                app_id: item.getAttribute('data-app-id'),
                                type: item.getAttribute('data-notif-type')
                            });
                        });

                        const formData = new FormData();
                        formData.append('action', 'dismiss_all');
                        formData.append('notifications', JSON.stringify(notifications));
                        formData.append('token', csrfToken);

                        fetch('dismiss_notification.php', {
                            method: 'POST',
                            body: formData
                        }).then(res => res.json()).then(data => {
                            if (data.success) {
                                items.forEach(item => {
                                    item.style.transition = 'opacity 0.25s ease, max-height 0.25s ease';
                                    item.style.opacity = '0';
                                    item.style.maxHeight = '0';
                                    item.style.overflow = 'hidden';
                                });
                                setTimeout(() => {
                                    items.forEach(item => item.remove());
                                    updateNotificationCount();
                                    if (typeof showToast === 'function') {
                                        showToast('All notifications dismissed.', 'info');
                                    }
                                }, 300);
                            }
                        }).catch(() => {});
                    });
                }
            }

            // ================= HTML5 NATIVE DESKTOP NOTIFICATIONS =================
            const enablePushBtn = document.getElementById('enablePushBtn');
            
            // Check native notification status on load to set button text
            function updatePushBtnText() {
                if (!enablePushBtn) return;
                if (!("Notification" in window)) {
                    enablePushBtn.style.display = 'none';
                    return;
                }
                if (Notification.permission === 'granted') {
                    enablePushBtn.textContent = 'Push Enabled ✓';
                    enablePushBtn.style.opacity = '0.6';
                    enablePushBtn.style.pointerEvents = 'none';
                } else if (Notification.permission === 'denied') {
                    enablePushBtn.textContent = 'Push Blocked';
                    enablePushBtn.style.opacity = '0.6';
                    enablePushBtn.style.pointerEvents = 'none';
                }
            }
            updatePushBtnText();

            if (enablePushBtn) {
                enablePushBtn.addEventListener('click', () => {
                    if (!("Notification" in window)) {
                        showToast('Desktop notifications are not supported in this browser.', 'warning');
                        return;
                    }

                    Notification.requestPermission().then(permission => {
                        updatePushBtnText();
                        if (permission === 'granted') {
                            showToast('Desktop notifications successfully enabled!', 'success');
                            // Trigger welcome push
                            new Notification('Job Tracker notifications active!', {
                                body: 'You will now receive desktop alerts for upcoming interviews and follow-up deadlines!',
                                icon: 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png'
                            });
                        } else {
                            showToast('Notifications permission was denied.', 'warning');
                        }
                    });
                });
            }

            // Automatic native push for critical events on load
            if ("Notification" in window && Notification.permission === 'granted') {
                const nativeReminders = <?php
                    $cleanReminders = array_map(function($r) {
                        $msg = html_entity_decode(strip_tags($r['message']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        return [
                            'app_id' => (int)$r['app_id'],
                            'type' => $r['type'],
                            'message' => $msg,
                            'time' => $r['time'],
                            'detail' => $r['detail']
                        ];
                    }, $reminders);
                    echo json_encode($cleanReminders);
                ?>;

                nativeReminders.forEach(rem => {
                    const title = 'Job Tracker: ' + (rem.type.includes('interview') ? 'Interview Reminder' : (rem.type.includes('assessment') ? 'Assessment Deadline' : 'Follow-up Reminder'));
                    
                    const notif = new Notification(title, {
                        body: rem.message + ' (' + rem.time + ')',
                        icon: 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png',
                        tag: 'notif-' + rem.app_id + '-' + rem.type
                    });

                    notif.onclick = function(e) {
                        e.preventDefault();
                        window.focus();
                        try {
                            const details = JSON.parse(rem.detail);
                            showDetail(details, rem.app_id, rem.type);
                        } catch (err) {
                            console.error('Error opening detail modal from push notification:', err);
                        }
                    };
                });
            }
        });
    </script>

    <!-- Toast Notification Container -->
    <div id="toastContainer"></div>

    <!-- Active Redirection Session Toast Trigger -->
    <?php if ($sessionNotification): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                if (typeof showToast === 'function') {
                    showToast(
                        <?= json_encode($sessionNotification['message']) ?>, 
                        <?= json_encode($sessionNotification['type']) ?>
                    );
                }
            });
        </script>
    <?php endif; ?>
</body>
</html>