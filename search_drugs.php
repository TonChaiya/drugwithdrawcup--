<?php
require 'includes/db.php';
require 'includes/auth.php';
require_login($pdo);
header('Content-Type: application/json; charset=utf-8');
$q = trim($_GET['q'] ?? '');
if ($q === '') { echo json_encode([]); exit; }
if ((function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q)) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'คำค้นหายาวเกินไป'], JSON_UNESCAPED_UNICODE);
    exit;
}
// search by working_code or name
$like = "%$q%";
$stmt = $pdo->prepare('SELECT id, working_code, name, pack_size, unit FROM drug_item WHERE is_active = 1 AND (working_code LIKE ? OR name LIKE ?) LIMIT 50');
$stmt->execute([$like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
