<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$rsf_id = (int)($_GET['rsf_id'] ?? 0);
if ($rsf_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid RSF ID']);
    exit;
}

// Fetch job order info
$stmt = $database->prepare("SELECT rsf_number, employer_name, position_title, worksite_country, deadline FROM rsf_job_orders WHERE id = ?");
$stmt->bind_param('i', $rsf_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();

if (!$job) {
    echo json_encode(['status' => 'error', 'message' => 'Job order not found']);
    exit;
}

// Fetch applicants with their status and assigned evaluator
$stmt = $database->prepare("
    SELECT
        a.id             AS application_id,
        a.status,
        a.submitted_at,
        a.notes,
        c.first_name,
        c.middle_name,
        c.last_name,
        c.email,
        c.phone,
        e.full_name      AS evaluator_name,
        e.email          AS evaluator_email
    FROM applications a
    JOIN clients c ON a.client_id = c.cid
    LEFT JOIN assignments asn ON asn.application_id = a.id AND asn.status = 'active'
    LEFT JOIN evaluators e ON asn.evaluator_id = e.eid
    WHERE a.rsf_id = ?
    ORDER BY a.submitted_at DESC
");
$stmt->bind_param('i', $rsf_id);
$stmt->execute();
$applicants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Count by status
$counts = ['total' => count($applicants), 'pending' => 0, 'approved' => 0, 'disapproved' => 0, 'under_evaluation' => 0, 'for_compliance' => 0];
foreach ($applicants as $app) {
    $s = $app['status'];
    if (isset($counts[$s])) $counts[$s]++;
}

echo json_encode([
    'status'     => 'success',
    'job'        => $job,
    'applicants' => $applicants,
    'counts'     => $counts
]);