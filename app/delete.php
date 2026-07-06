<?php
session_start();
include 'db.php';

// Verify CSRF token
$token = isset($_GET['token']) ? $_GET['token'] : '';
if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    die("Invalid CSRF token match. Delete request denied for security reasons.");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    // Fetch company name first to display inside the notification
    $fetchStmt = $pdo->prepare("SELECT company FROM applications WHERE id = ?");
    $fetchStmt->execute([$id]);
    $company = $fetchStmt->fetchColumn();

    // Delete the application securely using prepared statement
    $stmt = $pdo->prepare("DELETE FROM applications WHERE id = ?");
    $stmt->execute([$id]);

    if ($company) {
        $_SESSION['notification'] = [
            'type' => 'info',
            'message' => 'Application for <strong>' . htmlspecialchars($company) . '</strong> deleted successfully.'
        ];
    }
}

$back = 'index.php';
if (isset($_GET['back'])) {
    $parsed = parse_url($_GET['back']);
    if (empty($parsed['host']) && (strpos($parsed['path'], 'index.php') !== false || empty($parsed['path']))) {
        $back = $_GET['back'];
    }
}

// Redirect back to dashboard
header("Location: " . $back);
exit;
