<?php
// api/evaluator/claim.php – Atomic application claiming with race condition prevention
session_start();
require_once '../../db.php';
require_once '../../includes/app-helpers.php';

header('Content-Type: application/json');

// Verify evaluator is logged in
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$app_id = (int)($data['application_id'] ?? 0);

if (!$app_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Application ID required']);
    exit;
}

// Get evaluator ID
$evaluator_email = $_SESSION['user_email'];
$stmt = $database->prepare("SELECT eid FROM evaluators WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $evaluator_email);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $stmt->close();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Evaluator not found']);
    exit;
}
$evaluator_id = (int)$result->fetch_assoc()['eid'];
$stmt->close();

// Use transaction for atomic claiming - SELECT...FOR UPDATE pattern
$database->begin_transaction();

try {
    // Lock the application row to prevent race conditions
    $stmt = $database->prepare("SELECT id, status FROM applications WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $database->rollback();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Application not found']);
        exit;
    }

    $app = $result->fetch_assoc();
    $stmt->close();

    // Check if application is still pending
    if ($app['status'] !== 'pending') {
        $database->rollback();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Application is no longer available']);
        exit;
    }

    // Check if already claimed by someone else
    $stmt = $database->prepare("SELECT id FROM assignments WHERE application_id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("i", $app_id);
    $stmt->execute();

    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        $database->rollback();
        http_response_code(409);
        echo json_encode(['success' => false, 'already_claimed' => true, 'error' => 'Application already claimed']);
        exit;
    }
    $stmt->close();

    // Create assignment (atomic claim)
    $now = date('Y-m-d H:i:s');
    $stmt = $database->prepare("INSERT INTO assignments (application_id, evaluator_id, assigned_at, status) VALUES (?, ?, ?, 'active')");
    $stmt->bind_param("iis", $app_id, $evaluator_id, $now);

    if (!$stmt->execute()) {
        $stmt->close();
        $database->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create assignment']);
        exit;
    }
    $stmt->close();

    // Update application status to 'under_evaluation'
    $stmt = $database->prepare("UPDATE applications SET status = 'under_evaluation', updated_at = ? WHERE id = ?");
    $stmt->bind_param("si", $now, $app_id);

    if (!$stmt->execute()) {
        $stmt->close();
        $database->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update application status']);
        exit;
    }
    $stmt->close();

    // Record status history
    $reason = "Evaluator {$evaluator_email} claimed this application";
    $stmt = $database->prepare("INSERT INTO status_history (application_id, from_status, to_status, changed_by, reason, changed_at) VALUES (?, 'pending', 'under_evaluation', ?, ?, ?)");
    $stmt->bind_param("isss", $app_id, $evaluator_email, $reason, $now);
    $stmt->execute();
    $stmt->close();

    // Notify client that evaluation has started
    notify_client_for_application(
        $database,
        $app_id,
        'Application Claimed for Evaluation',
        'An evaluator has claimed your application and will begin the review process.'
    );

    $database->commit();

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Application claimed successfully']);
} catch (Exception $e) {
    $database->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
