<?php

/**
 * Reload the signed-in user from the database on every protected request.
 * This makes account approval and suspension take effect immediately.
 */
function require_login(PDO $pdo, string $loginUrl = 'index.php'): array
{
    if (empty($_SESSION['user']['id'])) {
        header('Location: ' . $loginUrl);
        exit;
    }

    $now = time();
    $loginAt = (int)($_SESSION['login_at'] ?? $now);
    $lastActivity = (int)($_SESSION['last_activity_at'] ?? $now);
    if (($now - $lastActivity) > 1800 || ($now - $loginAt) > 43200) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['flash'] = 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่';
        header('Location: ' . $loginUrl);
        exit;
    }
    $_SESSION['login_at'] = $loginAt;
    $_SESSION['last_activity_at'] = $now;

    $stmt = $pdo->prepare(
        'SELECT id, host_code, facility_name, name, username, role, is_active, approval_status
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(int)$_SESSION['user']['id']]);
    $user = $stmt->fetch();

    $isApproved = $user && ($user['approval_status'] ?? 'approved') === 'approved';
    $isActive = $user && (!isset($user['is_active']) || (int)$user['is_active'] === 1);
    $hasValidFacility = $user && (in_array(($user['role'] ?? ''), ['admin', 'superadmin'], true)
        || preg_match('/^[0-9]{5}$/', (string)$user['host_code']));

    if (!$user || !$isApproved || !$isActive || !$hasValidFacility) {
        unset($_SESSION['user']);
        if (!$user) {
            $_SESSION['flash'] = 'ไม่พบบัญชีผู้ใช้งาน';
        } elseif (!$isApproved) {
            $_SESSION['flash'] = 'บัญชีนี้ยังไม่ได้รับอนุมัติ';
        } elseif (!$isActive) {
            $_SESSION['flash'] = 'บัญชีผู้ใช้นี้ถูกปิดการใช้งาน';
        } else {
            $_SESSION['flash'] = 'บัญชีนี้ยังไม่ได้กำหนดรหัสสถานบริการที่ถูกต้อง กรุณาติดต่อแอดมิน';
        }
        header('Location: ' . $loginUrl);
        exit;
    }

    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'name' => $user['name'],
        'role' => $user['role'],
        'host_code' => $user['host_code'],
        'facility_name' => $user['facility_name'],
    ];

    return $_SESSION['user'];
}

function require_admin(PDO $pdo, string $loginUrl = 'index.php'): array
{
    $user = require_login($pdo, $loginUrl);
    if (!is_manager($user)) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์เข้าหน้านี้');
    }
    return $user;
}

function require_superadmin(PDO $pdo, string $loginUrl = 'index.php'): array
{
    $user = require_login($pdo, $loginUrl);
    if (!is_superadmin($user)) {
        http_response_code(403);
        exit('หน้านี้สำหรับผู้ดูแลระบบส่วนกลางเท่านั้น');
    }
    return $user;
}

function is_superadmin(array $user): bool
{
    return ($user['role'] ?? '') === 'superadmin';
}

function is_zone_admin(array $user): bool
{
    return ($user['role'] ?? '') === 'admin';
}

function is_manager(array $user): bool
{
    return in_array(($user['role'] ?? ''), ['admin', 'superadmin'], true);
}

/**
 * Sensitive administrator operations must confirm the password that belongs to
 * the currently authenticated account. Never accept a target user's password.
 */
function verify_current_user_password(PDO $pdo, array $user, string $password): bool
{
    if ($password === '' || strlen($password) > 1024) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)($user['id'] ?? 0)]);
    $hash = $stmt->fetchColumn();
    return is_string($hash) && $hash !== '' && password_verify($password, $hash);
}

function withdrawal_transition_allowed(string $from, string $to): bool
{
    $transitions = [
        'draft' => ['submitted', 'rejected'],
        'submitted' => ['draft', 'approved', 'rejected'],
        'approved' => ['draft', 'rejected'],
        'rejected' => ['draft'],
    ];
    return $from !== $to && in_array($to, $transitions[$from] ?? [], true);
}

function bounded_non_negative_int($value, int $maximum = 100000000): ?int
{
    if (!is_scalar($value) && $value !== null) {
        return null;
    }
    $raw = trim((string)$value);
    if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
        return null;
    }
    $number = filter_var($raw, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 0, 'max_range' => $maximum],
    ]);
    return $number === false ? null : (int)$number;
}

function bounded_plain_text($value, int $maximum = 255): ?string
{
    if (!is_scalar($value) && $value !== null) return null;
    $text = trim((string)$value);
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) return null;
    return $text;
}

function write_withdrawal_audit(PDO $pdo, array $actor, int $withdrawalId, string $action, $oldValues, $newValues): void
{
    try {
        $encode = static function ($value): ?string {
            return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };
        $stmt = $pdo->prepare(
            'INSERT INTO withdrawal_audit_logs
             (withdrawal_id, actor_user_id, action, old_values, new_values, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $withdrawalId,
            (int)($actor['id'] ?? 0),
            substr($action, 0, 50),
            $encode($oldValues),
            $encode($newValues),
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        // Keep the application available while a deployment is between the
        // file upload and its database migration, but leave an explicit trace.
        error_log('Withdrawal audit log unavailable: ' . $e->getMessage());
    }
}

function role_label(string $role): string
{
    return [
        'user' => 'ผู้ใช้งานสถานบริการ',
        'admin' => 'ผู้ดูแลเขตบริการ',
        'superadmin' => 'ผู้ดูแลระบบส่วนกลาง',
    ][$role] ?? 'ไม่ทราบระดับสิทธิ์';
}

/**
 * SQL condition for a withdrawal/facility host_code column.
 * The column argument must be a hard-coded server-side identifier, never user input.
 */
function host_scope_condition(array $user, string $column = 'w.host_code'): array
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
        throw new InvalidArgumentException('Invalid scope column');
    }
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
        throw new InvalidArgumentException('Invalid scope column');
    }
    if (is_superadmin($user)) {
        return ['sql' => '1=1', 'params' => []];
    }
    if (is_zone_admin($user)) {
        return [
            'sql' => "EXISTS (
                SELECT 1
                FROM facilities scope_f
                JOIN user_zone_assignments scope_uza ON scope_uza.zone_id = scope_f.zone_id
                JOIN service_zones scope_z ON scope_z.id = scope_f.zone_id AND scope_z.is_active = 1
                WHERE scope_f.host_code = {$column}
                  AND scope_f.is_active = 1
                  AND scope_uza.user_id = ?
            )",
            'params' => [(int)$user['id']],
        ];
    }
    return ['sql' => "{$column} = ?", 'params' => [(string)($user['host_code'] ?? '')]];
}

function can_access_host_code(PDO $pdo, array $user, string $hostCode): bool
{
    if (!preg_match('/^[0-9]{5}$/', $hostCode)) {
        return false;
    }
    if (is_superadmin($user)) {
        return true;
    }
    if (!is_zone_admin($user)) {
        return hash_equals((string)($user['host_code'] ?? ''), $hostCode);
    }
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM facilities f
         JOIN service_zones z ON z.id = f.zone_id AND z.is_active = 1
         JOIN user_zone_assignments uza ON uza.zone_id = f.zone_id
         WHERE f.host_code = ? AND f.is_active = 1 AND uza.user_id = ? LIMIT 1'
    );
    $stmt->execute([$hostCode, (int)$user['id']]);
    return (bool)$stmt->fetchColumn();
}

function can_manage_user(PDO $pdo, array $actor, array $target): bool
{
    if (is_superadmin($actor)) {
        return true;
    }
    return is_zone_admin($actor)
        && ($target['role'] ?? '') === 'user'
        && can_access_host_code($pdo, $actor, (string)($target['host_code'] ?? ''));
}

function visible_facilities(PDO $pdo, array $user): array
{
    $scope = host_scope_condition($user, 'f.host_code');
    $stmt = $pdo->prepare(
        'SELECT f.host_code, f.facility_name, f.zone_id, z.zone_name
         FROM facilities f
         LEFT JOIN service_zones z ON z.id = f.zone_id
         WHERE f.is_active = 1 AND ' . $scope['sql'] . '
         ORDER BY f.facility_name, f.host_code'
    );
    $stmt->execute($scope['params']);
    return $stmt->fetchAll();
}

function user_scope_summary(PDO $pdo, array $user): array
{
    if (is_superadmin($user)) {
        $facilityCount = (int)$pdo->query('SELECT COUNT(*) FROM facilities WHERE is_active = 1')->fetchColumn();
        return ['label' => 'ทุกเขตบริการ', 'facility_count' => $facilityCount, 'zones' => []];
    }
    if (is_zone_admin($user)) {
        $stmt = $pdo->prepare(
            'SELECT z.zone_name, COUNT(DISTINCT f.host_code) AS facility_count
             FROM user_zone_assignments uza
             JOIN service_zones z ON z.id = uza.zone_id AND z.is_active = 1
             LEFT JOIN facilities f ON f.zone_id = z.id AND f.is_active = 1
             WHERE uza.user_id = ? GROUP BY z.id, z.zone_name ORDER BY z.zone_name'
        );
        $stmt->execute([(int)$user['id']]);
        $zones = $stmt->fetchAll();
        return [
            'label' => $zones ? implode(', ', array_column($zones, 'zone_name')) : 'ยังไม่ได้กำหนดเขตบริการ',
            'facility_count' => array_sum(array_map('intval', array_column($zones, 'facility_count'))),
            'zones' => $zones,
        ];
    }
    return [
        'label' => (string)($user['facility_name'] ?? '') . ' (' . (string)($user['host_code'] ?? '') . ')',
        'facility_count' => 1,
        'zones' => [],
    ];
}

function write_zone_audit(PDO $pdo, array $actor, string $action, string $targetType, string $targetId, $oldValues, $newValues): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO zone_audit_logs
         (actor_user_id, action, target_type, target_id, old_values, new_values, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $encode = static function ($value): ?string {
        if ($value === null) return null;
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $stmt->execute([
        (int)$actor['id'], $action, $targetType, $targetId,
        $encode($oldValues), $encode($newValues),
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}
