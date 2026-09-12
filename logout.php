<?php
require 'includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}
// Clear session and redirect to login
if (session_status() === PHP_SESSION_NONE) session_start();
// unset all session variables
$_SESSION = [];
// destroy session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}
session_destroy();
header('Location: index.php'); exit;
