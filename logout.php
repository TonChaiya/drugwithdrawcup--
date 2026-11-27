<?php
require 'includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    unset($params['lifetime']);
    setcookie(session_name(), '', ['expires' => time() - 42000] + $params);
}
if (!session_destroy()) {
    http_response_code(503);
    exit('ออกจากระบบไม่สำเร็จ กรุณาลองใหม่');
}
header('Location: index.php'); exit;
