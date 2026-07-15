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
$success = '';

// Fetch current settings
$config = getTelegramConfig($pdo);
$has_token = !empty($config['token']);
$display_token = $has_token ? '••••••••••••' . substr($config['token'], -6) : '';
$display_chat_id = $config['chat_id'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // CSRF Token Validation
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die("Invalid CSRF token match. Request denied for security reasons.");
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $bot_token_input = trim($_POST['telegram_bot_token'] ?? '');
        $chat_id = trim($_POST['telegram_chat_id'] ?? '');

        // If the token input starts with bullets, it means the user didn't modify the existing token.
        $bot_token = $bot_token_input;
        if (strpos($bot_token_input, '••••') === 0) {
            $bot_token = $config['token'];
        }

        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('telegram_bot_token', ?)");
            $stmt->execute([$bot_token]);

            $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('telegram_chat_id', ?)");
            $stmt->execute([$chat_id]);

            // Refresh local config values
            $config = getTelegramConfig($pdo);
            $has_token = !empty($config['token']);
            $display_token = $has_token ? '••••••••••••' . substr($config['token'], -6) : '';
            $display_chat_id = $config['chat_id'];

            $_SESSION['notification'] = [
                'type' => 'success',
                'message' => 'Telegram configurations saved successfully!'
            ];
            
            header("Location: settings.php");
            exit;
        } catch (PDOException $e) {
            $error = 'Failed to save settings: ' . $e->getMessage();
        }
    } elseif ($action === 'test') {
        // Send a test message
        if (empty($config['token']) || empty($config['chat_id'])) {
            $error = 'Please save a valid Telegram Bot Token and Chat ID first.';
        } else {
            $testMsg = "🧪 <b>[JobTracker] Connection Test Successful!</b>\n\nYour job tracker application is now integrated with Telegram. You will receive updates about upcoming interviews, follow-ups, and assessment deadlines here.";
            $sent = sendTelegramMessage($config['token'], $config['chat_id'], $testMsg);
            if ($sent) {
                $success = 'Test message successfully sent to Telegram!';
            } else {
                $error = 'Failed to send Telegram message. Please check your Bot Token, Chat ID, and bot permissions.';
            }
        }
    } elseif ($action === 'trigger_reminders') {
        // Run daily reminder summary immediately and push it
        if (empty($config['token']) || empty($config['chat_id'])) {
            $error = 'Please save a valid Telegram Bot Token and Chat ID first.';
        } else {
            // Find reminders
            $todayStr = date('Y-m-d');
            $tomorrowStr = date('Y-m-d', strtotime('+1 day'));
            
            // 1. Interviews (Today & Tomorrow)
            $interviewsStmt = $pdo->prepare("SELECT company, job_title, interview_date FROM applications WHERE (interview_date = ? OR interview_date = ?) AND status = 'Interview'");
            $interviewsStmt->execute([$todayStr, $tomorrowStr]);
            $interviews = $interviewsStmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Follow-ups (Today & Tomorrow)
            $followupsStmt = $pdo->prepare("SELECT company, job_title, follow_up_date FROM applications WHERE (follow_up_date = ? OR follow_up_date = ?) AND (status = 'Applied' OR status = 'Responded')");
            $followupsStmt->execute([$todayStr, $tomorrowStr]);
            $followups = $followupsStmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Assessments (Today, Tomorrow & Overdue)
            $assessmentsStmt = $pdo->prepare("SELECT company, job_title, assessment_deadline, assessment_type FROM applications WHERE (assessment_deadline <= ? OR assessment_deadline = ?) AND assessment_status = 'Pending'");
            $assessmentsStmt->execute([$todayStr, $tomorrowStr]);
            $assessments = $assessmentsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($interviews) && empty($followups) && empty($assessments)) {
                $remMsg = "📅 <b>[JobTracker] Daily Reminder Summary</b>\n\nNo pending interviews, follow-ups, or assessment deadlines today or tomorrow. All caught up!";
            } else {
                $remMsg = "📅 <b>[JobTracker] Daily Reminder Summary</b>\n\n";
                
                if (!empty($interviews)) {
                    $remMsg .= "🔔 <b>Interviews:</b>\n";
                    foreach ($interviews as $row) {
                        $when = ($row['interview_date'] === $todayStr) ? 'Today' : 'Tomorrow';
                        $remMsg .= "• " . htmlspecialchars($row['company']) . " - " . htmlspecialchars($row['job_title']) . " (<i>" . $when . "</i>)\n";
                    }
                    $remMsg .= "\n";
                }

                if (!empty($followups)) {
                    $remMsg .= "📅 <b>Follow-ups:</b>\n";
                    foreach ($followups as $row) {
                        $when = ($row['follow_up_date'] === $todayStr) ? 'Today' : 'Tomorrow';
                        $remMsg .= "• " . htmlspecialchars($row['company']) . " - " . htmlspecialchars($row['job_title']) . " (<i>" . $when . "</i>)\n";
                    }
                    $remMsg .= "\n";
                }

                if (!empty($assessments)) {
                    $remMsg .= "📝 <b>Assessments Due:</b>\n";
                    foreach ($assessments as $row) {
                        $when = ($row['assessment_deadline'] < $todayStr) ? 'OVERDUE (' . $row['assessment_deadline'] . ')' : (($row['assessment_deadline'] === $todayStr) ? 'Today' : 'Tomorrow');
                        $remMsg .= "• " . htmlspecialchars($row['company']) . " - " . htmlspecialchars($row['assessment_type']) . " (<i>" . $when . "</i>)\n";
                    }
                    $remMsg .= "\n";
                }
            }

            $sent = sendTelegramMessage($config['token'], $config['chat_id'], $remMsg);
            if ($sent) {
                $success = 'Reminder summary successfully pushed to Telegram!';
            } else {
                $error = 'Failed to push reminders. Please verify your Telegram Bot connection settings.';
            }
        }
    }
}

// Session notification
$sessionNotification = null;
if (isset($_SESSION['notification'])) {
    $sessionNotification = $_SESSION['notification'];
    unset($_SESSION['notification']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Tracker - Settings</title>
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
                <li class="sidebar-menu-item active">
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
                <li class="mobile-nav-item active">
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
                <h1>Settings &amp; Integrations</h1>
                <p class="muted">Configure external modules, push notifications, and daily automated checks.</p>

                <?php if (!empty($error)): ?>
                    <div style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); padding: 12px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 500;">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success)): ?>
                    <div style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); padding: 12px; border-radius: var(--radius-md); margin-bottom: 20px; font-weight: 500;">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <!-- Telegram Integration Card -->
                <div class="settings-card card-box">
                    <h2>Telegram Push Notifications</h2>
                    <p class="muted" style="margin-bottom: 20px;">Receive push notifications for interviews, follow-ups, and assessment deadlines directly on your Telegram chat.</p>
                    
                    <form method="POST" action="settings.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="save">

                        <div class="form-group">
                            <label>Telegram Bot Token</label>
                            <input type="text" name="telegram_bot_token" value="<?= htmlspecialchars($display_token) ?>" placeholder="e.g. 123456789:ABCdefGhIJKlmNoPQRsT...">
                            <span class="field-hint">Obtain this token from Telegram @BotFather by creating a new bot.</span>
                        </div>

                        <div class="form-group">
                            <label>Telegram Chat ID</label>
                            <input type="text" name="telegram_chat_id" value="<?= htmlspecialchars($display_chat_id) ?>" placeholder="e.g. -1001234567890 or 987654321">
                            <span class="field-hint">Your individual Chat ID or Group Chat ID. Send any message to your bot and visit <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> to find it.</span>
                        </div>

                        <div class="form-actions" style="margin-top: 24px;">
                            <button type="submit" class="btn">Save Configuration</button>
                        </div>
                    </form>

                    <!-- Extra Actions for Integration Testing -->
                    <?php if ($has_token && !empty($display_chat_id)): ?>
                        <div class="integration-actions" style="margin-top: 30px; padding-top: 24px; border-top: 1px solid var(--border-color);">
                            <h3>Integration Controls</h3>
                            <p class="muted" style="margin-bottom: 16px;">Test connection endpoints or trigger push reminders manually.</p>
                            
                            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                <form method="POST" action="settings.php" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="test">
                                    <button type="submit" class="btn secondary">🧪 Test Connection</button>
                                </form>

                                <form method="POST" action="settings.php" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="trigger_reminders">
                                    <button type="submit" class="btn secondary" style="border-color: var(--accent-color); color: var(--accent-color);">📅 Push Daily Reminders Now</button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Daily Automation Guide Card -->
                <div class="settings-card card-box" style="margin-top: 30px;">
                    <h2>Automated Reminders Setup</h2>
                    <p class="muted" style="margin-bottom: 12px;">To automatically receive daily reminders on Telegram every morning, configure a cron job on your hosting server.</p>
                    <div class="cron-instructions" style="background: rgba(123, 111, 140, 0.08); padding: 16px; border-radius: var(--radius-md); font-family: monospace; font-size: 13px; line-height: 1.6; border: 1px solid var(--border-color);">
                        # Example crontab entry to run reminders daily at 9:00 AM<br>
                        0 9 * * * php /var/www/html/telegram_cron.php
                    </div>
                </div>
            </div>
        </main>
    </div>

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
<script>
    // Minimal dynamic helper script if required
    document.addEventListener('DOMContentLoaded', () => {
        // Safe toast display if custom message set
        window.showToast = function(message, type = 'success') {
            const toastContainer = document.getElementById('toastContainer') || document.body;
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.style.position = 'fixed';
            toast.style.bottom = '20px';
            toast.style.right = '20px';
            toast.style.background = 'var(--bg-secondary)';
            toast.style.boxShadow = 'var(--shadow-lg)';
            toast.style.padding = '12px 20px';
            toast.style.borderRadius = 'var(--radius-md)';
            toast.style.borderLeft = '4px solid ' + (type === 'success' ? '#10b981' : '#f43f5e');
            toast.style.zIndex = '9999';
            toast.textContent = message;
            toastContainer.appendChild(toast);
            setTimeout(() => { toast.remove(); }, 4000);
        };
    });
</script>
</html>
