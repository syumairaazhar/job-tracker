<?php
// Prevent direct access if needed, though this is a library
if (!defined('PDO_SUPPORT')) {
    // Just helper definitions
}

/**
 * Fetch Telegram configuration settings from the database.
 * 
 * @param PDO $pdo
 * @return array|null
 */
function getTelegramConfig($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('telegram_bot_token', 'telegram_chat_id')");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $config = ['token' => '', 'chat_id' => ''];
        foreach ($rows as $row) {
            if ($row['setting_key'] === 'telegram_bot_token') {
                $config['token'] = $row['setting_value'];
            } elseif ($row['setting_key'] === 'telegram_chat_id') {
                $config['chat_id'] = $row['setting_value'];
            }
        }
        return $config;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Send an HTML-formatted message to Telegram using native file_get_contents.
 * 
 * @param string $token
 * @param string $chatId
 * @param string $message
 * @return bool
 */
function sendTelegramMessage($token, $chatId, $message) {
    if (empty($token) || empty($chatId)) {
        return false;
    }
    
    $url = "https://api.telegram.org/bot" . urlencode($token) . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 8
        ]
    ];

    $context  = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    
    if ($result === false) {
        return false;
    }
    
    $response = json_decode($result, true);
    return isset($response['ok']) && $response['ok'] === true;
}

/**
 * Send real-time notification alert when a job application is added or updated.
 * 
 * @param PDO $pdo
 * @param array $appData
 * @param string $changeType 'create' or 'update'
 */
function notifyApplicationChange($pdo, $appData, $changeType) {
    $config = getTelegramConfig($pdo);
    if (!$config || empty($config['token']) || empty($config['chat_id'])) {
        return;
    }

    $company = $appData['company'] ?? '';
    $jobTitle = $appData['job_title'] ?? '';
    $status = $appData['status'] ?? 'Applied';
    $interviewDate = $appData['interview_date'] ?? '';
    $followUpDate = $appData['follow_up_date'] ?? '';
    $assessmentStatus = $appData['assessment_status'] ?? 'None';
    $assessmentType = $appData['assessment_type'] ?? '';
    $assessmentDeadline = $appData['assessment_deadline'] ?? '';

    $msg = "";
    if ($changeType === 'create') {
        $msg .= "🔔 <b>[JobTracker] New Application Saved</b>\n\n";
    } else {
        $msg .= "🔄 <b>[JobTracker] Application Updated</b>\n\n";
    }

    $msg .= "🏢 <b>Company:</b> " . htmlspecialchars($company) . "\n";
    $msg .= "💼 <b>Job Title:</b> " . htmlspecialchars($jobTitle) . "\n";
    $msg .= "📊 <b>Status:</b> " . htmlspecialchars($status) . "\n";

    $hasImportantInfo = false;
    
    if ($status === 'Interview' && !empty($interviewDate)) {
        $msg .= "📅 <b>Interview Date:</b> " . htmlspecialchars($interviewDate) . "\n";
        $hasImportantInfo = true;
    }
    
    if (!empty($followUpDate)) {
        $msg .= "📅 <b>Follow-up Date:</b> " . htmlspecialchars($followUpDate) . "\n";
        $hasImportantInfo = true;
    }

    if ($assessmentStatus === 'Pending' && !empty($assessmentDeadline)) {
        $msg .= "📝 <b>Assessment:</b> Pending (" . htmlspecialchars($assessmentType) . ")\n";
        $msg .= "⏰ <b>Deadline:</b> " . htmlspecialchars($assessmentDeadline) . "\n";
        if (!empty($appData['assessment_link'])) {
            $msg .= "🔗 <b>Assessment Link:</b> " . htmlspecialchars($appData['assessment_link']) . "\n";
        }
        $hasImportantInfo = true;
    }

    // Notify if it is a new entry or if it has critical upcoming schedule parameters
    if ($changeType === 'create' || $hasImportantInfo) {
        sendTelegramMessage($config['token'], $config['chat_id'], $msg);
    }
}
