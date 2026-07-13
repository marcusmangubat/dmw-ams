<?php
session_start();
require_once 'db.php';

// 1. Capture email before wiping session
$user_email = $_SESSION['email'] ?? null;

// 2. Clear all session data
$_SESSION = [];

// 3. Delete the session cookie from the browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 4. Destroy session data on the server
session_destroy();

// 5. Invalidate "Remember Me" token in DB
if ($user_email !== null) {
    $stmt = $database->prepare("UPDATE webuser SET remember_token = NULL, remember_expires = NULL WHERE email = ?");
    if ($stmt) {
        $stmt->bind_param("s", $user_email);
        $stmt->execute();
        $stmt->close();
    }
}

// 6. Kill the remember_me cookie too
setcookie('remember_me', '', time() - 3600, '/', '', true, true);

// 7. Prevent cached pages from being viewed via back button
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// 8. Redirect to login
header("Location: login.php");
exit;
