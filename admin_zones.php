<?php
require 'includes/db.php';
require 'includes/auth.php';

$superadmin = require_superadmin($pdo);
$error = '';
$message = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

function zone_redirect(): void {
    header('Location: admin_zones.php');
    exit;
}

function zone_approval_label(string $status): string {
    return ['pending'=>'รออนุมัติ','approved'=>'อนุมัติแล้ว','rejected'=>'ไม่อนุมัติ'][$status] ?? $status;
}

function approval_label(string $status): string {
    return zone_approval_label($status);
}

function posted_zone_ids(PDO $pdo, array $raw, bool $requireOne = false): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static fn($id) => $id > 0)));
    if ($requireOne && !$ids) throw new RuntimeException('กรุณาเลือกเขตบริการอย่างน้อยหนึ่งเขต');
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM service_zones WHERE is_active = 1 AND id IN ($marks)");
        $stmt->execute($ids);
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) throw new RuntimeException('มีเขตบริการที่ไม่ถูกต้องหรือถูกระงับ');
    }
    return $ids;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอหมดอายุ กรุณาลองใหม่อีกครั้ง';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create_zone') {
                $code = strtoupper(trim((string)($_POST['zone_code'] ?? '')));
                $name = trim((string)($_POST['zone_name'] ?? ''));
                if (!preg_match('/^[A-Z0-9_-]{2,50}$/', $code) || $name === '' || mb_strlen($name) > 255) {
                    throw new RuntimeException('กรุณากรอกรหัสเขตเป็น A-Z, 0-9, _ หรือ - และระบุชื่อเขตให้ครบ');
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO service_zones (zone_code, zone_name) VALUES (?, ?)');
                $stmt->execute([$code, $name]);
                $id = (int)$pdo->lastInsertId();
                write_zone_audit($pdo, $superadmin, 'create_zone', 'zone', (string)$id, null, ['zone_code'=>$code,'zone_name'=>$name]);
                $pdo->commit();
                $_SESSION['flash'] = 'เพิ่มเขตบริการเรียบร้อย';
                zone_redirect();
            }

            if ($action === 'update_zone') {
                $id = (int)($_POST['zone_id'] ?? 0);
                $code = strtoupper(trim((string)($_POST['zone_code'] ?? '')));
                $name = trim((string)($_POST['zone_name'] ?? ''));
                $active = isset($_POST['is_active']) ? 1 : 0;
                $stmt = $pdo->prepare('SELECT * FROM service_zones WHERE id = ? FOR UPDATE');
                $pdo->beginTransaction();
                $stmt->execute([$id]);
                $old = $stmt->fetch();
                if (!$old || !preg_match('/^[A-Z0-9_-]{2,50}$/', $code) || $name === '' || mb_strlen($name) > 255) throw new RuntimeException('ไม่พบเขตบริการหรือข้อมูลไม่ถูกต้อง');
                if ((string)$old['zone_code'] === 'LEGACY') $code = 'LEGACY';
                if (!$active) {
                    if ((int)$pdo->query('SELECT COUNT(*) FROM service_zones WHERE is_active=1')->fetchColumn() <= 1) throw new RuntimeException('ไม่สามารถระงับโซนที่เปิดใช้งานโซนสุดท้ายได้');
                    $refs = $pdo->prepare('SELECT (SELECT COUNT(*) FROM facilities WHERE zone_id=?) + (SELECT COUNT(*) FROM user_zone_assignments WHERE zone_id=?)');
                    $refs->execute([$id,$id]);
                    if ((int)$refs->fetchColumn() > 0) throw new RuntimeException('ระงับโซนที่ยังมีสถานบริการหรือผู้ดูแลไม่ได้ กรุณาย้ายออกก่อน');
                }
                $pdo->prepare('UPDATE service_zones SET zone_code = ?, zone_name = ?, is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$code,$name,$active,$id]);
                write_zone_audit($pdo, $superadmin, 'update_zone', 'zone', (string)$id, $old, ['zone_code'=>$code,'zone_name'=>$name,'is_active'=>$active]);
                $pdo->commit();
                $_SESSION['flash'] = 'บันทึกเขตบริการเรียบร้อย';
                zone_redirect();
            }

            if ($action === 'assign_facility') {
                $host = trim((string)($_POST['host_code'] ?? ''));
                $name = trim((string)($_POST['facility_name'] ?? ''));
                $zoneId = (int)($_POST['zone_id'] ?? 0);
                if (!preg_match('/^[0-9]{5}$/', $host) || $name === '' || mb_strlen($name) > 255) throw new RuntimeException('รหัสสถานบริการต้องเป็นตัวเลข 5 หลักและต้องระบุชื่อ');
                $pdo->beginTransaction();
                $z = $pdo->prepare('SELECT id FROM service_zones WHERE id = ? AND is_active = 1 FOR UPDATE');
                $z->execute([$zoneId]);
                if (!$z->fetch()) throw new RuntimeException('ไม่พบเขตบริการที่เปิดใช้งาน');
                $oldStmt = $pdo->prepare('SELECT * FROM facilities WHERE host_code = ? FOR UPDATE');
                $oldStmt->execute([$host]);
                $old = $oldStmt->fetch() ?: null;
                $stmt = $pdo->prepare('INSERT INTO facilities (host_code, facility_name, zone_id, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE facility_name=VALUES(facility_name), zone_id=VALUES(zone_id), is_active=1, updated_at=NOW()');
                $stmt->execute([$host,$name,$zoneId]);
                $pdo->prepare('UPDATE users SET facility_name = ? WHERE host_code = ?')->execute([$name,$host]);
                write_zone_audit($pdo, $superadmin, 'assign_facility', 'facility', $host, $old, ['facility_name'=>$name,'zone_id'=>$zoneId,'is_active'=>1]);
                $pdo->commit();
                $_SESSION['flash'] = 'กำหนดสถานบริการเข้าเขตเรียบร้อย';
                zone_redirect();
            }

            if ($action === 'assign_admin') {
                $userId = (int)($_POST['user_id'] ?? 0);
                $zoneIds = posted_zone_ids($pdo, (array)($_POST['zone_ids'] ?? []), true);
                $pdo->beginTransaction();
                $u = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? AND role = 'admin' FOR UPDATE");
                $u->execute([$userId]);
                $admin = $u->fetch();
                if (!$admin) throw new RuntimeException('บัญชีที่เลือกไม่ใช่ผู้ดูแลเขตบริการ');
                $oldStmt = $pdo->prepare('SELECT zone_id FROM user_zone_assignments WHERE user_id = ? ORDER BY zone_id');
                $oldStmt->execute([$userId]);
                $old = array_map('intval', $oldStmt->fetchAll(PDO::FETCH_COLUMN));
                $pdo->prepare('DELETE FROM user_zone_assignments WHERE user_id = ?')->execute([$userId]);
                $ins = $pdo->prepare('INSERT INTO user_zone_assignments (user_id, zone_id, assigned_by) VALUES (?, ?, ?)');
                foreach ($zoneIds as $zoneId) $ins->execute([$userId,$zoneId,(int)$superadmin['id']]);
                write_zone_audit($pdo, $superadmin, 'assign_admin', 'user', (string)$userId, ['zone_ids'=>$old], ['zone_ids'=>$zoneIds]);
                $pdo->commit();
                $_SESSION['flash'] = 'บันทึกขอบเขตผู้ดูแลเรียบร้อย';
                zone_redirect();
            }

            if ($action === 'bulk_move_facilities') {
                $hosts = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['host_codes'] ?? [])))));
                $zoneId = (int)($_POST['zone_id'] ?? 0);
                if (!$hosts || count($hosts) > 500) throw new RuntimeException('กรุณาเลือกสถานบริการ 1–500 แห่ง');
                foreach ($hosts as $host) if (!preg_match('/^[0-9]{5}$/', $host)) throw new RuntimeException('พบรหัสสถานบริการไม่ถูกต้อง');
                posted_zone_ids($pdo, [$zoneId], true);
                $pdo->beginTransaction();
                $marks = implode(',', array_fill(0, count($hosts), '?'));
                $lock = $pdo->prepare("SELECT host_code, zone_id FROM facilities WHERE host_code IN ($marks) FOR UPDATE");
                $lock->execute($hosts);
                $oldRows = $lock->fetchAll();
                if (count($oldRows) !== count($hosts)) throw new RuntimeException('มีสถานบริการบางรายการไม่อยู่ในทะเบียน');
                $params = array_merge([$zoneId], $hosts);
                $pdo->prepare("UPDATE facilities SET zone_id=?, is_active=1, updated_at=NOW() WHERE host_code IN ($marks)")->execute($params);
                write_zone_audit($pdo, $superadmin, 'bulk_move_facilities', 'zone', (string)$zoneId, $oldRows, ['host_codes'=>$hosts,'zone_id'=>$zoneId]);
                $pdo->commit();
                $_SESSION['flash'] = 'ย้ายสถานบริการ ' . count($hosts) . ' แห่งเรียบร้อย';
                zone_redirect();
            }

            if ($action === 'delete_zone') {
                $id = (int)($_POST['zone_id'] ?? 0);
                $confirmCode = strtoupper(trim((string)($_POST['confirm_zone_code'] ?? '')));
                $targetId = (int)($_POST['target_zone_id'] ?? 0);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT * FROM service_zones WHERE id=? FOR UPDATE');
                $stmt->execute([$id]);
                $old = $stmt->fetch();
                if (!$old || !hash_equals((string)$old['zone_code'], $confirmCode)) throw new RuntimeException('รหัสโซนที่ใช้ยืนยันไม่ถูกต้อง');
                if ((int)$pdo->query('SELECT COUNT(*) FROM service_zones')->fetchColumn() <= 1) throw new RuntimeException('ไม่สามารถลบโซนสุดท้ายของระบบได้');
                if ((int)$old['is_active'] === 1 && (int)$pdo->query('SELECT COUNT(*) FROM service_zones WHERE is_active=1')->fetchColumn() <= 1) throw new RuntimeException('ไม่สามารถลบโซนที่เปิดใช้งานโซนสุดท้ายได้');
                $countStmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM facilities WHERE zone_id=?) facilities, (SELECT COUNT(*) FROM user_zone_assignments WHERE zone_id=?) admins');
                $countStmt->execute([$id,$id]);
                $counts = $countStmt->fetch();
                $hasMembers = (int)$counts['facilities'] + (int)$counts['admins'] > 0;
                if ($hasMembers) {
                    if ($targetId <= 0 || $targetId === $id) throw new RuntimeException('โซนนี้ยังมีสมาชิก กรุณาเลือกโซนปลายทาง');
                    posted_zone_ids($pdo, [$targetId], true);
                    $pdo->prepare('UPDATE facilities SET zone_id=?, updated_at=NOW() WHERE zone_id=?')->execute([$targetId,$id]);
                    $pdo->prepare('INSERT IGNORE INTO user_zone_assignments(user_id,zone_id,assigned_by) SELECT user_id, ?, ? FROM user_zone_assignments WHERE zone_id=?')->execute([$targetId,(int)$superadmin['id'],$id]);
                    $pdo->prepare('DELETE FROM user_zone_assignments WHERE zone_id=?')->execute([$id]);
                }
                write_zone_audit($pdo, $superadmin, 'delete_zone', 'zone', (string)$id, ['zone'=>$old,'counts'=>$counts], ['moved_to'=>$hasMembers ? $targetId : null]);
                $pdo->prepare('DELETE FROM service_zones WHERE id=?')->execute([$id]);
                $pdo->commit();
                $_SESSION['flash'] = $hasMembers ? 'รวมสมาชิกและลบโซนเดิมเรียบร้อย' : 'ลบโซนว่างเรียบร้อย';
                zone_redirect();
            }

            if ($action === 'update_user_scope') {
                $userId = (int)($_POST['user_id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                $position = trim((string)($_POST['position'] ?? ''));
                $username = trim((string)($_POST['username'] ?? ''));
                $role = (string)($_POST['role'] ?? 'user');
                $active = isset($_POST['is_active']) ? 1 : 0;
                if (!in_array($role, ['user','admin','superadmin'], true) || $name === '' || $position === '' || mb_strlen($name) > 255 || mb_strlen($position) > 255 || !preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username)) throw new RuntimeException('ข้อมูลผู้ใช้ไม่ถูกต้อง');
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT * FROM users WHERE id=? AND id<>? FOR UPDATE');
                $stmt->execute([$userId,(int)$superadmin['id']]);
                $old = $stmt->fetch();
                if (!$old) throw new RuntimeException('ไม่พบผู้ใช้ หรือกำลังพยายามแก้สิทธิ์บัญชีของตัวเอง');
                if (($old['role'] === 'superadmin' || $role === 'superadmin')
                    && !verify_current_user_password($pdo, $superadmin, (string)($_POST['current_admin_password'] ?? ''))) {
                    throw new RuntimeException('กรุณายืนยันรหัสผ่านของผู้ดูแลก่อนเปลี่ยนสิทธิ์ superadmin');
                }
                if ($old['role'] === 'superadmin' && $role !== 'superadmin') {
                    $superCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='superadmin'")->fetchColumn();
                    if ($superCount <= 1) throw new RuntimeException('ไม่สามารถลดสิทธิ์ superadmin คนสุดท้ายได้');
                }
                $oldAudit = [
                    'name'=>$old['name'], 'position'=>$old['position'], 'username'=>$old['username'],
                    'role'=>$old['role'], 'host_code'=>$old['host_code'],
                    'facility_name'=>$old['facility_name'], 'is_active'=>$old['is_active'],
                ];
                $dup = $pdo->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');
                $dup->execute([$username,$userId]);
                if ($dup->fetch()) throw new RuntimeException('Username นี้ถูกใช้แล้ว');
                $zoneIds = [];
                $host = (string)$old['host_code'];
                $facilityName = (string)$old['facility_name'];
                if ($role === 'user') {
                    $host = trim((string)($_POST['host_code'] ?? ''));
                    if (!preg_match('/^[0-9]{5}$/', $host)) throw new RuntimeException('กรุณาเลือกสถานบริการที่ถูกต้อง');
                    $facilityStmt = $pdo->prepare('SELECT facility_name FROM facilities WHERE host_code=? AND is_active=1 AND zone_id IS NOT NULL LIMIT 1');
                    $facilityStmt->execute([$host]);
                    $facilityName = $facilityStmt->fetchColumn();
                    if ($facilityName === false) throw new RuntimeException('สถานบริการนี้ยังไม่พร้อมใช้งานหรือยังไม่มีโซน');
                } elseif ($role === 'admin') {
                    $zoneIds = posted_zone_ids($pdo, (array)($_POST['zone_ids'] ?? []), true);
                }
                $pdo->prepare('UPDATE users SET name=?, position=?, username=?, role=?, host_code=?, facility_name=?, is_active=? WHERE id=?')->execute([$name,$position,$username,$role,$host,$facilityName,$active,$userId]);
                $pdo->prepare('DELETE FROM user_zone_assignments WHERE user_id=?')->execute([$userId]);
                if ($role === 'admin') {
                    $ins = $pdo->prepare('INSERT INTO user_zone_assignments(user_id,zone_id,assigned_by) VALUES(?,?,?)');
                    foreach ($zoneIds as $zoneId) $ins->execute([$userId,$zoneId,(int)$superadmin['id']]);
                }
                write_zone_audit($pdo, $superadmin, 'update_user_scope', 'user', (string)$userId, $oldAudit, ['name'=>$name,'position'=>$position,'username'=>$username,'role'=>$role,'host_code'=>$host,'is_active'=>$active,'zone_ids'=>$zoneIds]);
                $pdo->commit();
                $_SESSION['flash'] = 'อัปเดตผู้ใช้และขอบเขตเรียบร้อย ใบเบิกย้อนหลังไม่ได้ถูกย้าย';
                zone_redirect();
            }

            if ($action === 'delete_user') {
                $userId = (int)($_POST['user_id'] ?? 0);
                $confirmUsername = trim((string)($_POST['confirm_username'] ?? ''));
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT id,username,name,role,host_code FROM users WHERE id=? AND role<>'superadmin' FOR UPDATE");
                $stmt->execute([$userId]);
                $old = $stmt->fetch();
                if (!$old || !hash_equals((string)$old['username'], $confirmUsername)) throw new RuntimeException('Username ที่ใช้ยืนยันไม่ถูกต้อง');
                $history = $pdo->prepare('SELECT
                    (SELECT COUNT(*) FROM withdrawals WHERE user_id=? OR approved_by=?) +
                    (SELECT COUNT(*) FROM users WHERE reviewed_by=?)');
                $history->execute([$userId,$userId,$userId]);
                if ((int)$history->fetchColumn() > 0) throw new RuntimeException('ลบบัญชีนี้ไม่ได้เพราะมีประวัติใบเบิก การอนุมัติ หรือการพิจารณาผู้ใช้ กรุณาใช้การระงับแทน');
                write_zone_audit($pdo, $superadmin, 'delete_user', 'user', (string)$userId, $old, null);
                $pdo->prepare('DELETE FROM user_zone_assignments WHERE user_id=?')->execute([$userId]);
                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
                $pdo->commit();
                $_SESSION['flash'] = 'ลบบัญชีที่ไม่มีประวัติเรียบร้อย';
                zone_redirect();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('admin_zones.php: ' . $e->getMessage());
            $error = ($e instanceof RuntimeException && !($e instanceof PDOException))
                ? $e->getMessage()
                : 'บันทึกไม่สำเร็จ กรุณาตรวจสอบว่ารหัสไม่ซ้ำและข้อมูลถูกต้อง';
        }
    }
}

$zones = $pdo->query('SELECT z.*,
    (SELECT COUNT(*) FROM facilities f WHERE f.zone_id=z.id) facility_count,
    (SELECT COUNT(*) FROM user_zone_assignments uza WHERE uza.zone_id=z.id) admin_count
    FROM service_zones z ORDER BY z.is_active DESC, z.zone_name')->fetchAll();
$facilities = $pdo->query('SELECT f.*, z.zone_name FROM facilities f LEFT JOIN service_zones z ON z.id=f.zone_id ORDER BY z.zone_name, f.facility_name')->fetchAll();
$admins = $pdo->query("SELECT id, name, username, host_code FROM users WHERE role='admin' ORDER BY name, username")->fetchAll();
$managedUsersStmt = $pdo->prepare("SELECT u.*,
    (SELECT GROUP_CONCAT(z.zone_name ORDER BY z.zone_name SEPARATOR ', ')
     FROM user_zone_assignments uza JOIN service_zones z ON z.id=uza.zone_id
     WHERE uza.user_id=u.id) zone_names
    FROM users u WHERE u.id<>?
    ORDER BY FIELD(u.role,'superadmin','admin','user'),u.name,u.username");
$managedUsersStmt->execute([(int)$superadmin['id']]);
$managedUsers = $managedUsersStmt->fetchAll();
foreach ($managedUsers as &$managedUser) {
    if ($managedUser['role'] === 'superadmin') {
        $managedUser['facility_name'] = 'ทุกเขตบริการ';
        $managedUser['host_code'] = '';
    }
}
unset($managedUser);
$assignmentRows = $pdo->query('SELECT user_id, zone_id FROM user_zone_assignments ORDER BY user_id, zone_id')->fetchAll();
$assignments = [];
foreach ($assignmentRows as $row) $assignments[(int)$row['user_id']][] = (int)$row['zone_id'];
$unassignedFacilities = count(array_filter($facilities, static fn($f) => empty($f['zone_id'])));
$unassignedAdmins = count(array_filter($admins, static fn($a) => empty($assignments[(int)$a['id']])));
$recentAudits = $pdo->query('SELECT a.*, u.name actor_name FROM zone_audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id ORDER BY a.id DESC LIMIT 30')->fetchAll();
?>
<!doctype html>
<html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script src="https://cdn.tailwindcss.com"></script><title>เขตบริการและลูกข่าย</title><style>dialog{max-height:calc(100vh - 2rem);overflow:auto}dialog::backdrop{background:rgba(15,23,42,.62);backdrop-filter:blur(3px)}tr[hidden]{display:none}</style></head>
<body class="bg-slate-50 text-slate-900">
<?php include __DIR__ . '/includes/nav.php'; ?>
<main class="max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 flex-1">
  <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-5">
    <div><h1 class="text-2xl font-bold">เขตบริการและลูกข่าย</h1><p class="text-sm text-slate-500 mt-1">กำหนดขอบเขตที่ผู้ดูแลแต่ละเขตมองเห็น การเปลี่ยนทุกครั้งถูกบันทึกประวัติ</p></div>
    <button type="button" onclick="document.getElementById('newZone').showModal()" class="rounded-xl bg-blue-600 text-white px-4 py-2.5 font-semibold">+ เพิ่มเขตบริการ</button>
  </div>
  <?php if ($message): ?><div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 p-3"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="mb-4 rounded-xl border border-red-200 bg-red-50 text-red-800 p-3"><?= e($error) ?></div><?php endif; ?>
  <?php if ($unassignedFacilities || $unassignedAdmins): ?><div class="mb-5 rounded-xl border border-amber-300 bg-amber-50 text-amber-900 p-4"><strong>ต้องตรวจสอบการกำหนดขอบเขต</strong><div class="text-sm mt-1">สถานบริการยังไม่อยู่ในเขต <?= $unassignedFacilities ?> แห่ง · ผู้ดูแลยังไม่มีเขต <?= $unassignedAdmins ?> บัญชี</div></div><?php endif; ?>

  <section class="grid sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-2xl border p-4"><div class="text-sm text-slate-500">โซนทั้งหมด</div><strong class="text-2xl"><?= count($zones) ?></strong></div>
    <div class="bg-white rounded-2xl border p-4"><div class="text-sm text-slate-500">สถานบริการ</div><strong class="text-2xl"><?= count($facilities) ?></strong></div>
    <div class="bg-white rounded-2xl border p-4"><div class="text-sm text-slate-500">ผู้ดูแลเขต</div><strong class="text-2xl"><?= count($admins) ?></strong></div>
    <div class="bg-white rounded-2xl border p-4"><div class="text-sm text-slate-500">ผู้ใช้ที่จัดการได้</div><strong class="text-2xl"><?= count($managedUsers) ?></strong></div>
  </section>

  <section class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3"><div><h2 class="text-lg font-bold">โซนบริการ</h2><p class="text-sm text-slate-500">แก้ไข ระงับ รวม หรือลบโซน โดยไม่แตะใบเบิกย้อนหลัง</p></div></div>
    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3 mt-4"><?php foreach ($zones as $z): ?><article class="rounded-xl border <?= (int)$z['is_active'] ? 'border-slate-200' : 'border-amber-200 bg-amber-50' ?> p-4"><div class="flex justify-between gap-3"><div><div class="font-mono text-xs text-slate-500"><?= e($z['zone_code']) ?></div><div class="font-bold mt-1"><?= e($z['zone_name']) ?></div></div><span class="text-xs rounded-full px-2 py-1 h-fit <?= (int)$z['is_active'] ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>"><?= (int)$z['is_active'] ? 'เปิดใช้' : 'ระงับ' ?></span></div><div class="text-sm text-slate-500 mt-3"><?= (int)$z['facility_count'] ?> สถานบริการ · <?= (int)$z['admin_count'] ?> ผู้ดูแล</div><div class="flex gap-2 mt-3"><button type="button" onclick='openZoneEdit(<?= json_encode($z, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' class="rounded-lg border px-3 py-2 text-sm font-semibold">แก้ไข</button><button type="button" onclick='openZoneDelete(<?= json_encode($z, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' class="rounded-lg border border-red-200 text-red-700 px-3 py-2 text-sm font-semibold">รวม/ลบ</button></div></article><?php endforeach; ?></div>
  </section>

  <section class="mt-5 bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3"><div><h2 class="text-lg font-bold">สถานบริการในโซน</h2><p class="text-sm text-slate-500">เลือกหลายแห่งเพื่อย้ายพร้อมกัน หรือแก้ไขทีละแห่ง</p></div><div class="flex flex-wrap gap-2"><input id="facilitySearch" oninput="filterRows('facilitySearch','facilityRow')" type="search" placeholder="ค้นหา host_code ชื่อ หรือโซน" class="h-10 rounded-xl border px-3 min-w-[260px]"><button type="button" onclick="openFacility()" class="rounded-xl bg-slate-900 text-white px-4 py-2 font-semibold">+ เพิ่มสถานบริการ</button><button id="bulkMoveButton" type="button" onclick="openBulkMove()" class="rounded-xl bg-blue-600 text-white px-4 py-2 font-semibold disabled:opacity-40" disabled>ย้ายที่เลือก</button></div></div>
    <div class="mt-4 max-h-[460px] overflow-auto border rounded-xl"><table class="w-full text-sm min-w-[720px]"><thead class="sticky top-0 bg-slate-100"><tr><th class="p-3"><input id="facilityAll" type="checkbox" onchange="toggleVisibleFacilities(this.checked)"></th><th class="text-left p-3">รหัส</th><th class="text-left p-3">สถานบริการ</th><th class="text-left p-3">โซน</th><th class="p-3">จัดการ</th></tr></thead><tbody><?php foreach ($facilities as $f): ?><tr class="facilityRow border-t" data-search="<?= e(mb_strtolower($f['host_code'].' '.$f['facility_name'].' '.($f['zone_name'] ?? ''))) ?>"><td class="p-3 text-center"><input class="facilityCheck" type="checkbox" value="<?= e($f['host_code']) ?>" onchange="updateBulkButton()"></td><td class="p-3 font-mono"><?= e($f['host_code']) ?></td><td class="p-3"><?= e($f['facility_name']) ?></td><td class="p-3"><?= e($f['zone_name'] ?: 'ยังไม่กำหนด') ?></td><td class="p-3 text-center"><button type="button" onclick='openFacility(<?= json_encode($f, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' class="text-blue-700 font-semibold">แก้ไข/ย้าย</button></td></tr><?php endforeach; ?></tbody></table></div>
  </section>

  <section class="mt-5 bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3"><div><h2 class="text-lg font-bold">ผู้ใช้และผู้ดูแลเขต</h2><p class="text-sm text-slate-500">การย้ายบัญชีไม่มีผลกับใบเบิกที่สร้างไว้แล้ว</p></div><div class="flex flex-wrap gap-2"><input id="userSearch" oninput="filterRows('userSearch','userRow')" type="search" placeholder="ค้นหาชื่อ Username สถานบริการ หรือโซน" class="h-10 rounded-xl border px-3 min-w-[300px]"><a href="register.php?new=1" class="rounded-xl bg-slate-900 text-white px-4 py-2 font-semibold">+ เพิ่มผู้ใช้</a></div></div>
    <div class="mt-4 max-h-[520px] overflow-auto border rounded-xl"><table class="w-full text-sm min-w-[900px]"><thead class="sticky top-0 bg-slate-100"><tr><th class="text-left p-3">ผู้ใช้</th><th class="text-left p-3">ตำแหน่ง</th><th class="text-left p-3">ระดับ</th><th class="text-left p-3">สถานบริการ/ขอบเขต</th><th class="text-left p-3">สถานะ</th><th class="p-3">จัดการ</th></tr></thead><tbody><?php foreach ($managedUsers as $u): ?><tr class="userRow border-t" data-search="<?= e(mb_strtolower($u['name'].' '.$u['username'].' '.$u['position'].' '.$u['host_code'].' '.$u['facility_name'].' '.$u['zone_names'])) ?>"><td class="p-3"><strong><?= e($u['name']) ?></strong><div class="text-xs text-slate-500">@<?= e($u['username']) ?></div></td><td class="p-3"><?= e($u['position']) ?></td><td class="p-3"><span class="rounded-full px-2 py-1 text-xs <?= $u['role']==='admin' ? 'bg-blue-100 text-blue-700' : 'bg-slate-100' ?>"><?= e(role_label($u['role'])) ?></span></td><td class="p-3"><?php if($u['role']==='admin'): ?><?= e($u['zone_names'] ?: 'ยังไม่มีเขต') ?><?php else: ?><?= e($u['facility_name']) ?><div class="text-xs font-mono text-slate-500"><?= e($u['host_code']) ?></div><?php endif; ?></td><td class="p-3"><?= (int)$u['is_active'] ? 'เปิดใช้งาน' : 'ระงับ' ?> · <?= e(approval_label($u['approval_status'])) ?></td><td class="p-3 text-center"><button type="button" onclick='openUserEdit(<?= json_encode($u, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>,<?= json_encode($assignments[(int)$u['id']] ?? []) ?>)' class="text-blue-700 font-semibold">แก้ไข/ย้าย</button></td></tr><?php endforeach; ?></tbody></table></div>
  </section>

  <section class="mt-5 bg-white rounded-2xl border border-slate-200 p-5 shadow-sm"><details><summary class="font-bold cursor-pointer">ประวัติการจัดการล่าสุด</summary><div class="mt-3 overflow-auto"><table class="w-full text-sm min-w-[700px]"><thead><tr class="bg-slate-100"><th class="text-left p-3">วันเวลา</th><th class="text-left p-3">ผู้ดำเนินการ</th><th class="text-left p-3">คำสั่ง</th><th class="text-left p-3">เป้าหมาย</th></tr></thead><tbody><?php foreach($recentAudits as $a): ?><tr class="border-t"><td class="p-3"><?= e(format_thai_datetime($a['created_at'])) ?></td><td class="p-3"><?= e($a['actor_name'] ?: '-') ?></td><td class="p-3 font-mono text-xs"><?= e($a['action']) ?></td><td class="p-3"><?= e($a['target_type'].' #'.$a['target_id']) ?></td></tr><?php endforeach; ?></tbody></table></div></details></section>
</main>
<dialog id="newZone" class="rounded-2xl p-0 w-[min(92vw,480px)] backdrop:bg-slate-900/60"><form method="post" class="p-6"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_zone"><h2 class="text-xl font-bold">เพิ่มเขตบริการ</h2><label class="block mt-4 text-sm font-semibold">รหัสเขต<input name="zone_code" maxlength="50" pattern="[A-Za-z0-9_-]{2,50}" required class="mt-1 w-full h-11 rounded-xl border border-slate-300 px-3" placeholder="เช่น ZONE_A"></label><label class="block mt-3 text-sm font-semibold">ชื่อเขต<input name="zone_name" maxlength="255" required class="mt-1 w-full h-11 rounded-xl border border-slate-300 px-3"></label><div class="flex justify-end gap-2 mt-5"><button type="button" onclick="this.closest('dialog').close()" class="rounded-xl border px-4 py-2">ยกเลิก</button><button class="rounded-xl bg-blue-600 text-white px-4 py-2 font-semibold">เพิ่มเขต</button></div></form></dialog>

<dialog id="zoneEditDialog" class="rounded-2xl p-0 w-[min(92vw,520px)] backdrop:bg-slate-900/60"><form method="post" class="p-6"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_zone"><input id="zeId" type="hidden" name="zone_id"><h2 class="text-xl font-bold">แก้ไขโซน</h2><label class="block mt-4 text-sm font-semibold">รหัสโซน<input id="zeCode" name="zone_code" maxlength="50" pattern="[A-Za-z0-9_-]{2,50}" required class="mt-1 w-full h-11 rounded-xl border px-3 uppercase"></label><label class="block mt-3 text-sm font-semibold">ชื่อโซน<input id="zeName" name="zone_name" maxlength="255" required class="mt-1 w-full h-11 rounded-xl border px-3"></label><label class="flex gap-2 items-center mt-4"><input id="zeActive" type="checkbox" name="is_active"> เปิดใช้งาน</label><p class="text-xs text-amber-700 mt-3">ระงับได้เมื่อย้ายสถานบริการและผู้ดูแลออกจากโซนหมดแล้ว</p><div class="flex justify-end gap-2 mt-5"><button type="button" onclick="this.closest('dialog').close()" class="rounded-xl border px-4 py-2">ยกเลิก</button><button class="rounded-xl bg-blue-600 text-white px-4 py-2 font-semibold">บันทึก</button></div></form></dialog>

<dialog id="zoneDeleteDialog" class="rounded-2xl p-0 w-[min(92vw,560px)] backdrop:bg-slate-900/60"><form method="post" class="p-6"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_zone"><input id="zdId" type="hidden" name="zone_id"><h2 class="text-xl font-bold text-red-700">รวม/ลบโซน</h2><p id="zdText" class="mt-2 text-sm text-slate-600"></p><div class="mt-4 rounded-xl bg-amber-50 text-amber-900 p-3 text-sm">หากโซนยังมีสมาชิก ระบบจะย้ายสถานบริการและขอบเขต admin ไปโซนปลายทางก่อน ใบเบิกย้อนหลังไม่ถูกแก้ไข</div><label class="block mt-4 text-sm font-semibold">โซนปลายทาง<select id="zdTarget" name="target_zone_id" class="mt-1 w-full h-11 rounded-xl border px-3 bg-white"><option value="">ไม่เลือก (ใช้ได้เฉพาะโซนว่าง)</option><?php foreach($zones as $z): if(!(int)$z['is_active']) continue; ?><option value="<?= (int)$z['id'] ?>"><?= e($z['zone_name']) ?> (<?= e($z['zone_code']) ?>)</option><?php endforeach; ?></select></label><label class="block mt-4 text-sm font-semibold">พิมพ์รหัสโซนเพื่อยืนยัน<input id="zdConfirm" name="confirm_zone_code" autocomplete="off" required class="mt-1 w-full h-11 rounded-xl border border-red-300 px-3 font-mono uppercase"></label><div class="flex justify-end gap-2 mt-5"><button type="button" onclick="this.closest('dialog').close()" class="rounded-xl border px-4 py-2">ยกเลิก</button><button class="rounded-xl bg-red-600 text-white px-4 py-2 font-semibold">ยืนยันรวม/ลบ</button></div></form></dialog>

<dialog id="facilityDialog" class="rounded-2xl p-0 w-[min(92vw,560px)] backdrop:bg-slate-900/60"><form method="post" class="p-6"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="assign_facility"><h2 id="facilityTitle" class="text-xl font-bold">เพิ่มสถานบริการ</h2><label class="block mt-4 text-sm font-semibold">รหัสสถานบริการ<input id="facilityHost" name="host_code" maxlength="5" inputmode="numeric" pattern="[0-9]{5}" required class="mt-1 w-full h-11 rounded-xl border px-3 font-mono"></label><label class="block mt-3 text-sm font-semibold">ชื่อสถานบริการ<input id="facilityName" name="facility_name" maxlength="255" required class="mt-1 w-full h-11 rounded-xl border px-3"></label><label class="block mt-3 text-sm font-semibold">โซน<select id="facilityZone" name="zone_id" required class="mt-1 w-full h-11 rounded-xl border px-3 bg-white"><option value="">เลือกโซน</option><?php foreach($zones as $z): if(!(int)$z['is_active']) continue; ?><option value="<?= (int)$z['id'] ?>"><?= e($z['zone_name']) ?></option><?php endforeach; ?></select></label><p class="text-xs text-slate-500 mt-3">เมื่อแก้ชื่อ ระบบจะปรับชื่อสถานบริการของบัญชีที่ใช้ host_code นี้ด้วย</p><div class="flex justify-end gap-2 mt-5"><button type="button" onclick="this.closest('dialog').close()" class="rounded-xl border px-4 py-2">ยกเลิก</button><button class="rounded-xl bg-blue-600 text-white px-4 py-2 font-semibold">บันทึก</button></div></form></dialog>

<dialog id="bulkMoveDialog" class="rounded-2xl p-0 w-[min(92vw,520px)] backdrop:bg-slate-900/60"><form method="post" class="p-6"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="bulk_move_facilities"><div id="bulkHosts"></div><h2 class="text-xl font-bold">ย้ายสถานบริการหลายแห่ง</h2><p id="bulkMoveCount" class="mt-2 text-sm text-slate-600"></p><label class="block mt-4 text-sm font-semibold">โซนปลายทาง<select name="zone_id" required class="mt-1 w-full h-11 rounded-xl border px-3 bg-white"><option value="">เลือกโซน</option><?php foreach($zones as $z): if(!(int)$z['is_active']) continue; ?><option value="<?= (int)$z['id'] ?>"><?= e($z['zone_name']) ?></option><?php endforeach; ?></select></label><div class="flex justify-end gap-2 mt-5"><button type="button" onclick="this.closest('dialog').close()" class="rounded-xl border px-4 py-2">ยกเลิก</button><button class="rounded-xl bg-blue-600 text-white px-4 py-2 font-semibold">ยืนยันการย้าย</button></div></form></dialog>

<dialog id="userDialog" class="rounded-2xl p-0 w-[min(94vw,680px)] backdrop:bg-slate-900/60"><div class="p-6"><form id="userEditForm" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_user_scope"><input id="ueId" type="hidden" name="user_id"><h2 class="text-xl font-bold">แก้ไขผู้ใช้และขอบเขต</h2><div class="grid sm:grid-cols-2 gap-3 mt-4"><label class="text-sm font-semibold sm:col-span-2">ชื่อผู้ใช้<input id="ueName" name="name" maxlength="255" required class="mt-1 w-full h-11 rounded-xl border px-3"></label><label class="text-sm font-semibold">ตำแหน่ง<input id="uePosition" name="position" maxlength="255" required class="mt-1 w-full h-11 rounded-xl border px-3"></label><label class="text-sm font-semibold">Username<input id="ueUsername" name="username" maxlength="100" pattern="[A-Za-z0-9_.-]{3,100}" required class="mt-1 w-full h-11 rounded-xl border px-3"></label><label class="text-sm font-semibold">ระดับสิทธิ์<select id="ueRole" name="role" onchange="toggleUserScopeFields()" class="mt-1 w-full h-11 rounded-xl border px-3 bg-white"><option value="user">user</option><option value="admin">admin</option></select></label><label class="flex items-end gap-2 pb-3"><input id="ueActive" type="checkbox" name="is_active"> เปิดใช้งานบัญชี</label></div><div id="userFacilityField" class="mt-3"><label class="text-sm font-semibold">สถานบริการ<select id="ueHost" name="host_code" class="mt-1 w-full h-11 rounded-xl border px-3 bg-white"><?php foreach($facilities as $f): if(!(int)$f['is_active'] || empty($f['zone_id'])) continue; ?><option value="<?= e($f['host_code']) ?>"><?= e($f['facility_name']) ?> (<?= e($f['host_code']) ?>)</option><?php endforeach; ?></select></label></div><div id="userZoneField" class="hidden mt-3"><div class="text-sm font-semibold mb-2">เขตที่ admin ดูแล</div><div class="grid sm:grid-cols-2 gap-2"><?php foreach($zones as $z): if(!(int)$z['is_active']) continue; ?><label class="rounded-lg bg-slate-50 px-3 py-2 flex gap-2"><input class="ueZone" type="checkbox" name="zone_ids[]" value="<?= (int)$z['id'] ?>"> <?= e($z['zone_name']) ?></label><?php endforeach; ?></div></div><label class="block mt-3 text-sm font-semibold">ยืนยันรหัสผ่านผู้ดูแลเมื่อเปลี่ยนเป็น/จาก superadmin<input id="ueCurrentPassword" type="password" name="current_admin_password" autocomplete="current-password" maxlength="128" class="mt-1 w-full h-11 rounded-xl border px-3"></label><div class="mt-4 rounded-xl bg-blue-50 text-blue-900 p-3 text-sm">การเปลี่ยนสถานบริการมีผลกับงานใหม่หลังบันทึก ใบเบิกย้อนหลังยังคง host_code เดิม</div><div class="flex justify-end gap-2 mt-5"><button type="button" onclick="document.getElementById('userDialog').close()" class="rounded-xl border px-4 py-2">ยกเลิก</button><button class="rounded-xl bg-blue-600 text-white px-4 py-2 font-semibold">บันทึก</button></div></form><hr class="my-5"><form method="post" onsubmit="return confirmDeleteUser()"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_user"><input id="udId" type="hidden" name="user_id"><label class="text-sm font-semibold text-red-700">ลบบัญชีที่ไม่เคยมีใบเบิกเท่านั้น<input id="udConfirm" name="confirm_username" autocomplete="off" placeholder="พิมพ์ Username เพื่อยืนยัน" class="mt-1 w-full h-11 rounded-xl border border-red-300 px-3"></label><button class="mt-2 rounded-xl border border-red-300 text-red-700 px-4 py-2 font-semibold">ลบบัญชี</button></form></div></dialog>

<script>
const zoneEl=id=>document.getElementById(id);
const superRoleOption=document.createElement('option');superRoleOption.value='superadmin';superRoleOption.textContent='superadmin';zoneEl('ueRole').appendChild(superRoleOption);
function filterRows(inputId,rowClass){const q=document.getElementById(inputId).value.trim().toLocaleLowerCase('th');document.querySelectorAll('.'+rowClass).forEach(row=>row.hidden=q!==''&&!row.dataset.search.includes(q));if(rowClass==='facilityRow')updateBulkButton()}
function openZoneEdit(z){zoneEl('zeId').value=z.id;zoneEl('zeCode').value=z.zone_code;zoneEl('zeName').value=z.zone_name;zoneEl('zeActive').checked=Number(z.is_active)===1;zoneEl('zeCode').readOnly=z.zone_code==='LEGACY';zoneEl('zoneEditDialog').showModal()}
function openZoneDelete(z){zoneEl('zdId').value=z.id;zoneEl('zdConfirm').value='';zoneEl('zdText').textContent=`โซน ${z.zone_name} มี ${z.facility_count} สถานบริการ และ ${z.admin_count} ผู้ดูแล`;Array.from(zoneEl('zdTarget').options).forEach(o=>o.disabled=Number(o.value)===Number(z.id));zoneEl('zdTarget').value='';zoneEl('zoneDeleteDialog').showModal()}
function openFacility(f=null){zoneEl('facilityTitle').textContent=f?'แก้ไข/ย้ายสถานบริการ':'เพิ่มสถานบริการ';zoneEl('facilityHost').value=f?.host_code||'';zoneEl('facilityHost').readOnly=!!f;zoneEl('facilityName').value=f?.facility_name||'';zoneEl('facilityZone').value=f?.zone_id||'';zoneEl('facilityDialog').showModal()}
function visibleFacilityChecks(){return Array.from(document.querySelectorAll('.facilityRow:not([hidden]) .facilityCheck'))}
function toggleVisibleFacilities(on){visibleFacilityChecks().forEach(c=>c.checked=on);updateBulkButton()}
function updateBulkButton(){const n=document.querySelectorAll('.facilityCheck:checked').length;zoneEl('bulkMoveButton').disabled=n===0;zoneEl('bulkMoveButton').textContent=n?`ย้ายที่เลือก (${n})`:'ย้ายที่เลือก'}
function openBulkMove(){const selected=Array.from(document.querySelectorAll('.facilityCheck:checked')).map(c=>c.value);zoneEl('bulkHosts').innerHTML='';selected.forEach(host=>{const i=document.createElement('input');i.type='hidden';i.name='host_codes[]';i.value=host;zoneEl('bulkHosts').appendChild(i)});zoneEl('bulkMoveCount').textContent=`เลือก ${selected.length} สถานบริการ ใบเบิกย้อนหลังจะไม่ถูกเปลี่ยน`;zoneEl('bulkMoveDialog').showModal()}
function openUserEdit(u,zoneIds){zoneEl('ueId').value=u.id;zoneEl('udId').value=u.id;zoneEl('ueName').value=u.name||'';zoneEl('uePosition').value=u.position||'';zoneEl('ueUsername').value=u.username||'';zoneEl('ueRole').value=u.role;zoneEl('ueActive').checked=Number(u.is_active)===1;zoneEl('ueHost').value=u.host_code||'';zoneEl('ueCurrentPassword').value='';zoneEl('udConfirm').value='';document.querySelectorAll('.ueZone').forEach(c=>c.checked=zoneIds.map(Number).includes(Number(c.value)));toggleUserScopeFields();const deleteForm=zoneEl('userDialog').querySelector('input[value="delete_user"]').closest('form');deleteForm.hidden=u.role==='superadmin';zoneEl('userDialog').dataset.username=u.username;zoneEl('userDialog').showModal()}
function toggleUserScopeFields(){const role=zoneEl('ueRole').value;zoneEl('userFacilityField').classList.toggle('hidden',role!=='user');zoneEl('userZoneField').classList.toggle('hidden',role!=='admin')}
function confirmDeleteUser(){if(zoneEl('udConfirm').value!==zoneEl('userDialog').dataset.username){alert('กรุณาพิมพ์ Username ให้ตรงกับบัญชี');return false}return confirm('ยืนยันลบบัญชีนี้? การลบทำได้เฉพาะบัญชีที่ไม่มีประวัติใบเบิก')}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body></html>
