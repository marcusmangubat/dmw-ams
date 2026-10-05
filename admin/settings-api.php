<?php

/**
 * settings-api.php – Profile & Security actions for the admin settings page
 * Actions:
 *   GET  ?action=get_profile
 *   GET  ?action=login_activity
 *   POST action=update_profile      { full_name, phone, email }
 *   POST action=change_password     { current_password, new_password }
 *   POST action=logout_all_devices
 */

session_start();
require_once '../db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_email']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$admin_email = $_SESSION['user_email'];
$method = $_SERVER['REQUEST_METHOD'];

function respond($arr)
{
    echo json_encode($arr);
    exit;
}

function verify_csrf($input)
{
    if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $input['csrf_token'])) {
        respond(['success' => false, 'message' => 'Invalid session token. Please refresh the page.']);
    }
}

/* ───────────────────────── GET actions ───────────────────────── */
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_profile') {
        $stmt = $database->prepare("
            SELECT a.full_name, a.phone, a.created_at, w.email, w.usertype, w.updated_at
            FROM admins a
            JOIN webuser w ON w.email = a.email
            WHERE a.email = ? LIMIT 1
        ");
        $stmt->bind_param('s', $admin_email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) respond(['success' => false, 'message' => 'Profile not found']);

        respond(['success' => true, 'data' => [
            'full_name'  => $row['full_name'],
            'phone'      => $row['phone'],
            'email'      => $row['email'],
            'usertype'   => $row['usertype'],
            'created_at' => date('M d, Y', strtotime($row['created_at'])),
            'updated_at' => date('M d, Y g:i A', strtotime($row['updated_at'])),
        ]]);
    }

    if ($action === 'login_activity') {
        $stmt = $database->prepare("
            SELECT ip_address, user_agent, success, attempted_at
            FROM login_attempts
            WHERE email = ?
            ORDER BY attempted_at DESC
            LIMIT 15
        ");
        $stmt->bind_param('s', $admin_email);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $r['attempted_at'] = date('M d, Y g:i A', strtotime($r['attempted_at']));
            $rows[] = $r;
        }
        $stmt->close();
        respond(['success' => true, 'data' => $rows]);
    }

    respond(['success' => false, 'message' => 'Unknown action']);
}

/* ───────────────────────── POST actions ──────────────────────── */
$input = json_decode(file_get_contents('php://input'), true) ?? [];
verify_csrf($input);
$action = $input['action'] ?? '';

if ($action === 'update_profile') {
    $full_name = trim($input['full_name'] ?? '');
    $phone     = trim($input['phone'] ?? '');
    $new_email = trim($input['email'] ?? '');

    if ($full_name === '' || $new_email === '') {
        respond(['success' => false, 'message' => 'Full name and email are required']);
    }
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'message' => 'Invalid email format']);
    }

    $email_changed = ($new_email !== $admin_email);

    if ($email_changed) {
        // Ensure new email isn't already taken
        $chk = $database->prepare("SELECT email FROM webuser WHERE email = ? LIMIT 1");
        $chk->bind_param('s', $new_email);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $chk->close();
            respond(['success' => false, 'message' => 'That email is already in use']);
        }
        $chk->close();

        // webuser.email is a PK referenced by several FKs (admins, notifications,
        // document_comments) without ON UPDATE CASCADE defined in the schema.
        // We update every dependent row inside a single transaction, disabling
        // FK checks for the duration of the transaction only.
        $database->begin_transaction();
        try {
            $database->query("SET FOREIGN_KEY_CHECKS=0");

            $stmt = $database->prepare("UPDATE webuser SET email = ? WHERE email = ?");
            $stmt->bind_param('ss', $new_email, $admin_email);
            $stmt->execute();
            $stmt->close();

            $stmt = $database->prepare("UPDATE admins SET email = ?, full_name = ?, phone = ? WHERE email = ?");
            $stmt->bind_param('ssss', $new_email, $full_name, $phone, $admin_email);
            $stmt->execute();
            $stmt->close();

            $stmt = $database->prepare("UPDATE notifications SET user_email = ? WHERE user_email = ?");
            $stmt->bind_param('ss', $new_email, $admin_email);
            $stmt->execute();
            $stmt->close();

            $stmt = $database->prepare("UPDATE document_comments SET commented_by = ? WHERE commented_by = ?");
            $stmt->bind_param('ss', $new_email, $admin_email);
            $stmt->execute();
            $stmt->close();

            $database->query("SET FOREIGN_KEY_CHECKS=1");
            $database->commit();
        } catch (Exception $e) {
            $database->rollback();
            $database->query("SET FOREIGN_KEY_CHECKS=1");
            respond(['success' => false, 'message' => 'Failed to update email: ' . $e->getMessage()]);
        }

        // Email changed -> force re-login
        session_destroy();
        respond(['success' => true, 'email_changed' => true]);
    } else {
        $stmt = $database->prepare("UPDATE admins SET full_name = ?, phone = ? WHERE email = ?");
        $stmt->bind_param('sss', $full_name, $phone, $admin_email);
        $stmt->execute();
        $stmt->close();

        $stmt = $database->prepare("UPDATE webuser SET updated_at = CURRENT_TIMESTAMP WHERE email = ?");
        $stmt->bind_param('s', $admin_email);
        $stmt->execute();
        $stmt->close();

        $_SESSION['admin_name'] = $full_name;

        respond(['success' => true, 'email_changed' => false, 'updated_at' => date('M d, Y g:i A')]);
    }
}

if ($action === 'change_password') {
    $current = $input['current_password'] ?? '';
    $new     = $input['new_password'] ?? '';

    if (strlen($new) < 8) {
        respond(['success' => false, 'message' => 'New password must be at least 8 characters']);
    }

    $stmt = $database->prepare("SELECT password FROM webuser WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $admin_email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, $row['password'])) {
        respond(['success' => false, 'message' => 'Current password is incorrect']);
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $database->prepare("UPDATE webuser SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE email = ?");
    $stmt->bind_param('ss', $hash, $admin_email);
    $stmt->execute();
    $stmt->close();

    respond(['success' => true]);
}

if ($action === 'logout_all_devices') {
    $stmt = $database->prepare("UPDATE webuser SET remember_token = NULL, remember_expires = NULL WHERE email = ?");
    $stmt->bind_param('s', $admin_email);
    $stmt->execute();
    $stmt->close();
    respond(['success' => true]);
}

respond(['success' => false, 'message' => 'Unknown action']);
