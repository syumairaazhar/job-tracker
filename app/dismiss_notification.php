<?php
session_start();
include 'db.php';

// Only accept POST requests for state-changing operations
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify CSRF token
$token = isset($_POST['token']) ? $_POST['token'] : '';
if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

header('Content-Type: application/json');

$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'dismiss') {
    // Dismiss a single notification
    $appId = isset($_POST['app_id']) ? (int)$_POST['app_id'] : 0;
    $type = isset($_POST['type']) ? $_POST['type'] : '';

    // Validate notification type against allow-list to prevent injection
    $allowedTypes = [
        'overdue_interview', 'overdue_followup', 'overdue_assessment',
        'today_interview', 'today_followup', 'today_assessment',
        'tomorrow_interview', 'tomorrow_followup', 'tomorrow_assessment'
    ];

    if ($appId <= 0 || !in_array($type, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid parameters']);
        exit;
    }

    // Insert or ignore if already dismissed (using parameterized query)
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO dismissed_notifications (application_id, notification_type) VALUES (?, ?)");
    $stmt->execute([$appId, $type]);

    echo json_encode(['success' => true]);
    exit;

} elseif ($action === 'dismiss_all') {
    // Dismiss all currently visible notifications
    $notifications = isset($_POST['notifications']) ? $_POST['notifications'] : '';

    if (empty($notifications)) {
        http_response_code(400);
        echo json_encode(['error' => 'No notifications provided']);
        exit;
    }

    $items = json_decode($notifications, true);
    if (!is_array($items)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid notification data']);
        exit;
    }

    $allowedTypes = [
        'overdue_interview', 'overdue_followup', 'overdue_assessment',
        'today_interview', 'today_followup', 'today_assessment',
        'tomorrow_interview', 'tomorrow_followup', 'tomorrow_assessment'
    ];

    $stmt = $pdo->prepare("INSERT OR IGNORE INTO dismissed_notifications (application_id, notification_type) VALUES (?, ?)");
    foreach ($items as $item) {
        $appId = isset($item['app_id']) ? (int)$item['app_id'] : 0;
        $type = isset($item['type']) ? $item['type'] : '';
        if ($appId > 0 && in_array($type, $allowedTypes, true)) {
            $stmt->execute([$appId, $type]);
        }
    }

    echo json_encode(['success' => true]);
    exit;

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}
