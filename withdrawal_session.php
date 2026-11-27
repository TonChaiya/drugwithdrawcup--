<?php
define('APP_SESSION_JSON', true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function withdrawal_session_reply(int $code, array $payload): never
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($code === 401) header_remove('Set-Cookie');
    http_response_code($code);
    exit(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    withdrawal_session_reply(405, ['status' => 'method_not_allowed']);
}
require 'includes/db.php';
require 'includes/auth.php';
require 'includes/withdrawal_recovery.php';

try {
    $user = authenticated_user($pdo, false);
    if (!$user) withdrawal_session_reply(401, ['status' => 'expired']);
    if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        withdrawal_session_reply(409, ['status' => 'session_changed']);
    }
    $request = json_decode(file_get_contents('php://input', false, null, 0, 32768), true);
    $kind = $request['kind'] ?? null;
    $response = ['status' => 'ok'];
    if (in_array($kind, ['active', 'preflight'], true)) {
        $_SESSION['last_activity_at'] = time();
    } elseif ($kind === 'receipts') {
        $receipts = $request['receipts'] ?? null;
        if (!is_array($receipts) || count($receipts) > 30) withdrawal_session_reply(400, ['status' => 'invalid']);
        $response['saved'] = [];
        foreach ($receipts as $context => $candidate) {
            $context = (string)$context;
            $token = withdrawal_receipt_token($candidate);
            if ($token && withdrawal_resume_url($context) !== null) {
                $attempt = withdrawal_saved_attempt($pdo, $user, $context, $token);
                if ($attempt !== false) $response['saved'][] = ['context' => $context, 'attempt' => $attempt];
            }
        }
        if (random_int(1, 100) === 1) {
            $cleanup = $pdo->prepare('DELETE FROM withdrawal_save_receipts WHERE expires_at <= ? ORDER BY expires_at LIMIT 200');
            $cleanup->execute([time()]);
        }
    } elseif ($kind === 'restore') {
        $resume = $request['resume'] ?? null;
        $facility = $request['facility'] ?? null;
        $status = $request['status'] ?? null;
        $ids = $request['ids'] ?? [];
        if (!is_string($facility) || withdrawal_resume_url($resume) === null || !is_array($ids) || count($ids) > 1000) {
            withdrawal_session_reply(400, ['status' => 'invalid']);
        }
        if ($resume === 'new') {
            $allowed = $facility === (string)$user['host_code'] && preg_match('/^[0-9]{5}$/D', $facility);
        } else {
            $stmt = $pdo->prepare('SELECT user_id, host_code, status FROM withdrawals WHERE id = ?');
            $stmt->execute([$resume]);
            $row = $stmt->fetch();
            $allowed = $row && $row['host_code'] === $facility && $row['status'] === $status
                && can_access_host_code($pdo, $user, $facility)
                && (($status === 'draft' && (int)$row['user_id'] === (int)$user['id'])
                    || (is_manager($user) && in_array($status, ['draft', 'submitted'], true)));
        }
        if (!$allowed) withdrawal_session_reply(403, ['status' => 'no_access']);
        $response['drugs'] = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) withdrawal_session_reply(400, ['status' => 'invalid']);
        }
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, working_code, name, pack_size, unit FROM drug_item WHERE is_active = 1 AND id IN ($marks)");
            $stmt->execute($ids);
            $response['drugs'] = $stmt->fetchAll();
        }
    } else {
        withdrawal_session_reply(400, ['status' => 'invalid']);
    }
    $response['remaining'] = max(0, min($_SESSION['last_activity_at'] + APP_SESSION_IDLE, $_SESSION['login_at'] + APP_SESSION_ABSOLUTE) - time());
    withdrawal_session_reply(200, $response);
} catch (Throwable $e) {
    error_log('Withdrawal session request failed: ' . get_class($e) . ' [' . $e->getCode() . ']');
    withdrawal_session_reply(503, ['status' => 'unavailable']);
}
