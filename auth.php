<?php
// auth.php - Automatic "Remember Me" login
require_once 'db.php';

if (!isset($_SESSION['user_email'])) {

    if (isset($_COOKIE['remember_token']) && !empty($_COOKIE['remember_token'])) {

        $token = $_COOKIE['remember_token'];

        // Check if token is valid and not expired
        $stmt = $database->prepare("
            SELECT email, usertype 
            FROM webuser 
            WHERE remember_token = ? 
              AND remember_expires > NOW()
            LIMIT 1
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // Auto login
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_type']  = $user['usertype'];

            // Get full name for clients
            if ($user['usertype'] === 'client') {
                $stmt_name = $database->prepare("
                    SELECT CONCAT(first_name, ' ', last_name) AS full_name 
                    FROM clients 
                    WHERE email = ? 
                    LIMIT 1
                ");
                $stmt_name->bind_param("s", $user['email']);
                $stmt_name->execute();
                $name_result = $stmt_name->get_result();

                if ($name_result->num_rows === 1) {
                    $_SESSION['full_name'] = $name_result->fetch_assoc()['full_name'];
                }
                $stmt_name->close();
            }

            // Extend cookie life (another 30 days)
            setcookie(
                'remember_token',
                $token,
                [
                    'expires'  => time() + (30 * 24 * 60 * 60),
                    'path'     => '/',
                    'secure'   => false,      // Change to true when using HTTPS
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]
            );
        } else {
            // Invalid or expired token → delete the cookie
            setcookie('remember_token', '', time() - 3600, '/', '', false, true);
        }
    }
}
?>