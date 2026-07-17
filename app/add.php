<?php
session_start();
include 'db.php';
define('PDO_SUPPORT', true);
include 'telegram_notify.php';

// Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
        $stmt = $pdo->prepare("
            INSERT INTO applications 
            (company, job_title, date_found, date_applied, status, platform, job_type, location, salary_range, job_link, remark, follow_up_date, interview_date, result, technical_skills, assessment_status, assessment_type, assessment_deadline, assessment_link, assessment_notes, interview_location, assessment_platform, location_link, interview_location_link)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
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
            $interview_location ?: null,
            $assessment_platform ?: null,
            $location_link ?: null,
            $interview_location_link ?: null
        ]);

        $appId = $pdo->lastInsertId();
        $newAppData = [
            'id' => $appId,
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
        notifyApplicationChange($pdo, $newAppData, 'create');

        $_SESSION['notification'] = [
            'type' => 'success',
            'message' => 'Job application for <strong>' . htmlspecialchars($company) . '</strong> successfully tracked!'
        ];

        header("Location: index.php?view=applications");
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
                <li class="sidebar-menu-item active">
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
                <li class="mobile-nav-item active">
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
                <h1>Add Job Application</h1>
                <p class="muted">Save the job details you found and track your application progress.</p>

                <?php if (!empty($error)): ?>
                    <div style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); padding: 12px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 500;">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
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
                            <input type="text" name="company" required placeholder="e.g. Google, Stripe, Canva">
                        </div>

                        <div class="form-group">
                            <label>Job Title <span class="required-star">*</span></label>
                            <input type="text" name="job_title" required placeholder="e.g. Senior Software Engineer">
                        </div>

                        <div class="form-group">
                            <label>Platform Used</label>
                            <select name="platform_select" id="platform_select" onchange="togglePlatformOther(this)">
                                <option value="" disabled selected>Select platform</option>
                                <option value="JobStreet">JobStreet</option>
                                <option value="LinkedIn">LinkedIn</option>
                                <option value="Website">Website</option>
                                <option value="MyFutureJobs">MyFutureJobs</option>
                                <option value="Other">Other</option>
                            </select>
                            <input type="text" name="platform_other" id="platform_other"
                                   placeholder="Enter platform name"
                                   style="display:none; margin-top:0.5rem;">
                        </div>

                        <div class="form-group">
                            <label>Job Type</label>
                            <select name="job_type">
                                <option>Full-time</option>
                                <option>Part-time</option>
                                <option>Internship</option>
                                <option>Contract</option>
                                <option>Remote</option>
                                <option>Hybrid</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Location</label>
                            <input type="text" name="location" placeholder="e.g. Kuala Lumpur, Remote">
                        </div>

                        <div class="form-group">
                            <label>Location Map Link <span style="font-weight:400;opacity:.6;font-size:.85em;">(optional)</span></label>
                            <input type="url" name="location_link" placeholder="https://maps.google.com/...">
                        </div>

                        <div class="form-group">
                            <label>Salary Range</label>
                            <input type="text" name="salary_range" placeholder="e.g. RM 5,000 – RM 7,000">
                        </div>

                        <div class="form-group full-width">
                            <label>Job Link</label>
                            <input type="url" name="job_link" placeholder="https://...">
                        </div>

                        <div class="form-group full-width">
                            <label>Technical / Skills Required</label>
                            <input type="text" name="technical_skills" placeholder="e.g. React, PHP, SQL, Figma">
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
                            <select name="status" id="app_status" onchange="toggleStatusFields()">
                                <option selected>Applied</option>
                                <option>Pending</option>
                                <option>Viewed Application</option>
                                <option>Interview</option>
                                <option>Assessment</option>
                                <option>Rejected</option>
                                <option>Offered</option>
                                <option>Expired</option>
                                <option>Unlikely to Progress Further</option>
                            </select>
                        </div>

                        <!-- ── Interview Fields (shown when status = Interview) ── -->
                        <div id="interview_fields" style="display:none; contents">
                            <div class="form-group">
                                <label>Interview Date</label>
                                <input type="date" name="interview_date" id="interview_date">
                            </div>
                            <div class="form-group">
                                <label>Interview Location</label>
                                <input type="text" name="interview_location" id="interview_location" placeholder="e.g. Zoom, Google Meet, Kuala Lumpur Office">
                            </div>
                            <div class="form-group">
                                <label>Interview Location Link <span style="font-weight:400;opacity:.6;font-size:.85em;">(optional)</span></label>
                                <input type="url" name="interview_location_link" id="interview_location_link" placeholder="https://maps.google.com/...">
                            </div>
                        </div><!-- /#interview_fields -->

                        <!-- ── Assessment Fields (shown when status = Assessment) ── -->
                        <div id="assessment_status_fields" style="display:none; contents">
                            <div class="form-group">
                                <label>Assessment Type</label>
                                <select name="assessment_type" id="assessment_type" onchange="toggleAssessmentSuggestions()">
                                    <option value="" disabled selected>Select assessment type</option>
                                    <option value="Behavioral Assessment">Behavioral Assessment</option>
                                    <option value="Technical / Coding Test">Technical / Coding Test</option>
                                    <option value="Cognitive / Aptitude Test">Cognitive / Aptitude Test</option>
                                    <option value="Take-home Assignment">Take-home Assignment</option>
                                    <option value="English / Communication Test">English / Communication Test</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Assessment Date</label>
                                <input type="date" name="assessment_deadline" id="assessment_deadline">
                            </div>
                            <div class="form-group">
                                <label>Assessment Status</label>
                                <select name="assessment_status" id="assessment_status">
                                    <option value="None" selected>None</option>
                                    <option value="Pending">Pending</option>
                                    <option value="Completed">Completed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Assessment Platform</label>
                                <input type="text" name="assessment_platform" id="assessment_platform" placeholder="e.g. HackerRank, Codility, Custom Portal">
                            </div>
                            <div class="form-group full-width">
                                <label>Assessment Link</label>
                                <input type="url" name="assessment_link" id="assessment_link" placeholder="https://...">
                            </div>
                            <div class="form-group full-width">
                                <label>Assessment Notes / Reminders</label>
                                <textarea name="assessment_notes" id="assessment_notes" placeholder="e.g. Needs to be completed in one sitting, study company values..."></textarea>
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
                        </div>

                        <div class="form-group">
                            <label>Date Found Job</label>
                            <input type="date" name="date_found" value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="form-group">
                            <label>Date Applied</label>
                            <input type="date" name="date_applied">
                        </div>

                        <div class="form-group">
                            <label>Follow-up Date</label>
                            <input type="date" name="follow_up_date">
                        </div>

                        <div class="form-group full-width">
                            <label>Remark</label>
                            <textarea name="remark" placeholder="Example: HR contacted regarding portfolio, follow up on Friday..."></textarea>
                        </div>


                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Save Application
                        </button>
                        <a href="index.php" class="back">Cancel</a>
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

// Show/hide Interview or Assessment fields based on main status dropdown
function toggleStatusFields() {
    const status = document.getElementById('app_status').value;
    const interviewFields  = document.getElementById('interview_fields');
    const assessmentFields = document.getElementById('assessment_status_fields');

    if (status === 'Interview') {
        // Interview: show interview fields + assessment (assessment can happen during interview)
        interviewFields.style.display  = 'contents';
        assessmentFields.style.display = 'contents';
        toggleAssessmentSuggestions();
    } else if (status === 'Assessment') {
        interviewFields.style.display  = 'none';
        assessmentFields.style.display = 'contents';
        toggleAssessmentSuggestions();
    } else {
        interviewFields.style.display  = 'none';
        assessmentFields.style.display = 'none';
    }
}

// Show dynamic tips based on assessment type
function toggleAssessmentSuggestions() {
    const type = document.getElementById('assessment_type').value;
    const container = document.getElementById('assessment_suggestions_box');
    if (!container) return;
    const tipsBody = document.getElementById('assessment_suggestions_body');
    const tipsTitle = document.getElementById('assessment_suggestions_title');

    if (!type) {
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

document.addEventListener('DOMContentLoaded', () => {
    toggleStatusFields();
});
</script>
</html>