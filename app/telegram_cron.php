<?php
// telegram_cron.php - Run daily from a cron job
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram_notify.php';

$config = getTelegramConfig($pdo);
if (empty($config) || empty($config['token']) || empty($config['chat_id'])) {
    die("Telegram is not configured. Please configure it in the settings first.\n");
}

$todayStr = date('Y-m-d');
$tomorrowStr = date('Y-m-d', strtotime('+1 day'));

// 1. Interviews (Today & Tomorrow)
$interviewsStmt = $pdo->prepare("SELECT company, job_title, interview_date, job_link FROM applications WHERE (interview_date = ? OR interview_date = ?) AND status = 'Interview'");
$interviewsStmt->execute([$todayStr, $tomorrowStr]);
$interviews = $interviewsStmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Follow-ups (Today & Tomorrow)
$followupsStmt = $pdo->prepare("SELECT company, job_title, follow_up_date, job_link FROM applications WHERE (follow_up_date = ? OR follow_up_date = ?) AND status IN ('Applied', 'Pending', 'Viewed Application', 'Interview', 'Assessment')");
$followupsStmt->execute([$todayStr, $tomorrowStr]);
$followups = $followupsStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Assessments (Today, Tomorrow & Overdue)
$assessmentsStmt = $pdo->prepare("SELECT company, job_title, assessment_deadline, assessment_type, job_link FROM applications WHERE (assessment_deadline <= ? OR assessment_deadline = ?) AND assessment_status = 'Pending'");
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
            $link = !empty($row['job_link']) ? " (<a href=\"" . htmlspecialchars($row['job_link']) . "\">Job Link</a>)" : "";
            $remMsg .= "• " . htmlspecialchars($row['company']) . " - " . htmlspecialchars($row['job_title']) . $link . " (<i>" . $when . "</i>)\n";
        }
        $remMsg .= "\n";
    }

    if (!empty($followups)) {
        $remMsg .= "📅 <b>Follow-ups:</b>\n";
        foreach ($followups as $row) {
            $when = ($row['follow_up_date'] === $todayStr) ? 'Today' : 'Tomorrow';
            $link = !empty($row['job_link']) ? " (<a href=\"" . htmlspecialchars($row['job_link']) . "\">Job Link</a>)" : "";
            $remMsg .= "• " . htmlspecialchars($row['company']) . " - " . htmlspecialchars($row['job_title']) . $link . " (<i>" . $when . "</i>)\n";
        }
        $remMsg .= "\n";
    }

    if (!empty($assessments)) {
        $remMsg .= "📝 <b>Assessments Due:</b>\n";
        foreach ($assessments as $row) {
            $when = ($row['assessment_deadline'] < $todayStr) ? 'OVERDUE (' . $row['assessment_deadline'] . ')' : (($row['assessment_deadline'] === $todayStr) ? 'Today' : 'Tomorrow');
            $link = !empty($row['job_link']) ? " (<a href=\"" . htmlspecialchars($row['job_link']) . "\">Job Link</a>)" : "";
            $remMsg .= "• " . htmlspecialchars($row['company']) . " - " . htmlspecialchars($row['assessment_type']) . $link . " (<i>" . $when . "</i>)\n";
        }
        $remMsg .= "\n";
    }
}

$sent = sendTelegramMessage($config['token'], $config['chat_id'], $remMsg);
if ($sent) {
    echo "Reminder summary successfully pushed to Telegram!\n";
} else {
    echo "Failed to push reminders. Please verify your Telegram Bot connection settings.\n";
}
?>
