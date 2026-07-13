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

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    notif_json(['ok' => false, 'error' => 'Unauthorized']);
}

$userEmail = (string) $_SESSION['user_email'];
$userType  = 'admin';
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action    = (string) ($_REQUEST['action'] ?? 'list');

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

// Default action: list
$limit = 20;
$stmt = $database->prepare("
    SELECT id, application_id, title, message, type, is_read, created_at
    FROM notifications
    WHERE user_email = ? AND usertype = ?
    ORDER BY created_at DESC
    LIMIT ?
");
$stmt->bind_param('ssi', $userEmail, $userType, $limit);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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
    'ok'             => true,
    'notifications'  => $rows,
    'unread_count'   => $unreadCount,
]);
