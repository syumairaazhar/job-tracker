<?php
session_start();
include 'db.php';

// Build the query with optional filters (mirrors index.php filter logic)
$filterStatus   = $_GET['status']   ?? '';
$filterJobType  = $_GET['job_type'] ?? '';
$filterPlatform = $_GET['platform'] ?? '';

$where  = [];
$params = [];

if ($filterStatus !== '') {
    if ($filterStatus === 'Expired_Rejected') {
        $where[]  = "(status = 'Expired' OR status = 'Rejected')";
    } else {
        $where[]  = "status = ?";
        $params[] = $filterStatus;
    }
}
if ($filterJobType !== '') {
    $where[]  = "job_type = ?";
    $params[] = $filterJobType;
}
if ($filterPlatform !== '') {
    $where[]  = "platform = ?";
    $params[] = $filterPlatform;
}

$sql = "SELECT * FROM applications";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY date_applied DESC, created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build a safe filename
$suffix   = $filterStatus ? '_' . preg_replace('/[^a-zA-Z0-9_]/', '', $filterStatus) : '';
$filename = 'job_applications' . $suffix . '_' . date('Y-m-d') . '.csv';

// Stream headers
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Open output stream
$output = fopen('php://output', 'w');

// UTF-8 BOM so Excel opens with correct encoding
fwrite($output, "\xEF\xBB\xBF");

// Column headers — human-friendly labels
$headers = [
    'ID',
    'Company',
    'Job Title',
    'Status',
    'Platform',
    'Job Type',
    'Location',
    'Salary Range',
    'Date Found',
    'Date Applied',
    'Interview Date',
    'Follow-up Date',
    'Result',
    'Technical Skills',
    'Job Link',
    'Remark',
    'Created At',
];
fputcsv($output, $headers);

// Data rows
foreach ($applications as $row) {
    fputcsv($output, [
        $row['id']               ?? '',
        $row['company']          ?? '',
        $row['job_title']        ?? '',
        $row['status']           ?? '',
        $row['platform']         ?? '',
        $row['job_type']         ?? '',
        $row['location']         ?? '',
        $row['salary_range']     ?? '',
        $row['date_found']       ?? '',
        $row['date_applied']     ?? '',
        $row['interview_date']   ?? '',
        $row['follow_up_date']   ?? '',
        $row['result']           ?? '',
        $row['technical_skills'] ?? '',
        $row['job_link']         ?? '',
        $row['remark']           ?? '',
        $row['created_at']       ?? '',
    ]);
}

fclose($output);
exit;
