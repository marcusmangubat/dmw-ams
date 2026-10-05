<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../db.php';

function notif_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

// Authorization: Must be logged in as evaluator
if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'evaluator') {
    notif_json(['ok' => false, 'error' => 'Unauthorized']);
}

$userEmail = (string) $_SESSION['user_email'];
$userType = 'evaluator';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_REQUEST['action'] ?? 'list');


// ====================== MARK ALL AS READ ======================
if ($method === 'POST' && $action === 'mark_all_read') {
    $stmt = $database->prepare("
        UPDATE notifications 
        SET is_read = 1, read_at = NOW() 
        WHERE user_email = ? AND usertype = ? AND is_read = 0
    ");
    $stmt->bind_param('ss', $userEmail, $userType);
    $ok = $stmt->execute();
    $stmt->close();

    notif_json(['ok' => $ok]);
}


// ====================== MARK SINGLE NOTIFICATION AS READ ======================
if ($method === 'POST' && $action === 'mark_read') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        notif_json(['ok' => false, 'error' => 'Invalid notification id']);
    }

    $stmt = $database->prepare("
        UPDATE notifications 
        SET is_read = 1, read_at = NOW() 
        WHERE id = ? AND user_email = ? AND usertype = ?
    ");
    $stmt->bind_param('iss', $id, $userEmail, $userType);
    $ok = $stmt->execute();
    $stmt->close();

    notif_json(['ok' => $ok]);
}


// ====================== DEFAULT: LIST NOTIFICATIONS ======================
$limit = 20;
$stmt = $database->prepare("
    SELECT
        n.id,
        n.application_id,
        n.title,
        n.message,
        n.type,
        n.is_read,
        n.created_at,
        c.first_name,
        c.last_name
    FROM notifications n
    LEFT JOIN applications a ON n.application_id = a.id
    LEFT JOIN clients c ON a.client_id = c.cid
    WHERE n.user_email = ? AND n.usertype = ?
    ORDER BY n.created_at DESC
    LIMIT ?
");
$stmt->bind_param('ssi', $userEmail, $userType, $limit);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get unread count
$stmt = $database->prepare("
    SELECT COUNT(*) AS c 
    FROM notifications 
    WHERE user_email = ? AND usertype = ? AND is_read = 0
");
$stmt->bind_param('ss', $userEmail, $userType);
$stmt->execute();
$unreadCount = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

notif_json([
    'ok' => true,
    'notifications' => $rows,
    'unread_count' => $unreadCount,
]);
