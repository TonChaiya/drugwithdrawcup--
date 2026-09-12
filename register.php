<?php
require 'includes/db.php';
require 'includes/auth.php';

$currentAdmin = require_admin($pdo);

$err = '';
$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$formModalMode = '';
$openCreateModal = ($_GET['new'] ?? '') === '1';
$formValues = [
  'id' => '',
  'name' => '',
  'position' => '',
  'username' => '',
  'facility_name' => '',
  'host_code' => '',
  'role' => 'user',
];

$hasActiveColumn = false;
try {
  $hasActiveColumn = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'")->fetch();
} catch (Exception $e) {
  error_log('register.php column check error: ' . $e->getMessage());
}

function password_error($password) {
  if (strlen((string)$password) < 8) return 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
  if (strlen((string)$password) > 72) return 'รหัสผ่านต้องยาวไม่เกิน 72 ไบต์';
  return '';
}

function superadmin_count($pdo) {
  return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
}

function active_superadmin_count($pdo, $hasActiveColumn) {
  if (!$hasActiveColumn) return superadmin_count($pdo);
  return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND COALESCE(is_active,1)=1")->fetchColumn();
}

function approved_superadmin_count($pdo) {
  return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND approval_status = 'approved'")->fetchColumn();
}

function approval_label($status) {
  return [
    'pending' => 'รออนุมัติ',
    'approved' => 'อนุมัติแล้ว',
    'rejected' => 'ไม่อนุมัติ',
  ][$status] ?? 'ไม่ทราบสถานะ';
}

function selected($a, $b) {
  return $a === $b ? 'selected' : '';
}

function canonical_facility_name(PDO $pdo, string $hostCode): ?string {
  $stmt = $pdo->prepare('SELECT facility_name FROM facilities WHERE host_code = ? AND is_active = 1 LIMIT 1');
  $stmt->execute([$hostCode]);
  $name = $stmt->fetchColumn();
  return $name === false ? null : (string)$name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    $err = 'Invalid CSRF';
  } else {
    $action = $_POST['action'] ?? 'create';

    // Every mutation of an existing account is scoped here, before action-specific code.
    if (in_array($action, ['update','review','reset_password','toggle','delete'], true)) {
      $guardId = (int)($_POST['id'] ?? 0);
      $guardStmt = $pdo->prepare('SELECT id, host_code, role, approval_status, is_active FROM users WHERE id = ? LIMIT 1');
      $guardStmt->execute([$guardId]);
      $guardTarget = $guardStmt->fetch();
      if (!$guardTarget || !can_manage_user($pdo, $currentAdmin, $guardTarget)) {
        $err = 'ไม่มีสิทธิ์จัดการบัญชีนี้หรือบัญชีอยู่นอกเขตบริการ';
        $action = '';
      }
    }

    if (in_array($action, ['create', 'update'], true)) {
      $formModalMode = $action;
      foreach (['id', 'name', 'position', 'username', 'facility_name', 'host_code', 'role'] as $field) {
        if (isset($_POST[$field])) $formValues[$field] = trim((string)$_POST[$field]);
      }
    }

    if ($action === 'create') {
      $name = trim($_POST['name'] ?? '');
      $position = trim($_POST['position'] ?? '');
      $username = trim($_POST['username'] ?? '');
      $password = $_POST['password'] ?? '';
      $host_code = trim($_POST['host_code'] ?? '');
      $facility = trim($_POST['facility_name'] ?? '');
      $allowedRoles = is_superadmin($currentAdmin) ? ['user','admin','superadmin'] : ['user'];
      $role = in_array($_POST['role'] ?? 'user', $allowedRoles, true) ? $_POST['role'] : 'user';
      $passErr = password_error($password);

      if (!$name || !$position || !$username || !$password || !$host_code || !$facility) {
        $err = 'กรุณากรอกข้อมูลให้ครบ';
      } elseif (mb_strlen($name) > 255 || mb_strlen($position) > 255 || mb_strlen($facility) > 255) {
        $err = 'ชื่อ ตำแหน่ง หรือชื่อสถานบริการยาวเกิน 255 ตัวอักษร';
      } elseif (!preg_match('/^[0-9]{5}$/', $host_code)) {
        $err = 'host_code ต้องเป็นตัวเลข 5 หลัก';
      } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username)) {
        $err = 'Username ต้องเป็นอังกฤษ/ตัวเลข/._- และยาวอย่างน้อย 3 ตัวอักษร';
      } elseif ($passErr) {
        $err = $passErr;
      } elseif ($role !== 'user' && !verify_current_user_password($pdo, $currentAdmin, (string)($_POST['current_admin_password'] ?? ''))) {
        $err = 'กรุณายืนยันรหัสผ่านของผู้ดูแลก่อนสร้างบัญชีสิทธิ์สูง';
      } elseif (!can_access_host_code($pdo, $currentAdmin, $host_code)) {
        $err = 'เลือกได้เฉพาะสถานบริการในขอบเขตของคุณ';
      } elseif (($canonicalFacility = canonical_facility_name($pdo, $host_code)) === null) {
        $err = 'ไม่พบรหัสสถานบริการในทะเบียน กรุณาให้ผู้ดูแลระบบส่วนกลางเพิ่มสถานบริการและกำหนดเขตก่อน';
      } else {
        $facility = $canonicalFacility;
        try {
          $chk = $pdo->prepare('SELECT id FROM users WHERE username = ?');
          $chk->execute([$username]);
          if ($chk->fetch()) {
            $err = 'ชื่อผู้ใช้ซ้ำ';
          } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare("INSERT INTO users (host_code,facility_name,name,position,username,password,role,approval_status,is_active,reviewed_at,reviewed_by) VALUES (?,?,?,?,?,?,?,'approved',1,NOW(),?)");
            $ins->execute([$host_code, $facility, $name, $position, $username, $hash, $role, $currentAdmin['id']]);
            write_zone_audit($pdo, $currentAdmin, 'create_user', 'user', (string)$pdo->lastInsertId(), null, ['username'=>$username,'role'=>$role,'host_code'=>$host_code]);
            $_SESSION['flash'] = 'เพิ่มผู้ใช้เรียบร้อย';
            header('Location: register.php'); exit;
          }
        } catch (Exception $e) {
          error_log('register.php create error: ' . $e->getMessage());
          $err = 'เกิดข้อผิดพลาด';
        }
      }
    }

    if ($action === 'review') {
      $id = (int)($_POST['id'] ?? 0);
      $decision = $_POST['decision'] ?? '';
      if (!$id || !in_array($decision, ['approved', 'rejected'], true)) {
        $err = 'ข้อมูลการพิจารณาไม่ถูกต้อง';
      } elseif ((int)$currentAdmin['id'] === $id && $decision === 'rejected') {
        $err = 'ไม่สามารถปฏิเสธบัญชีที่กำลังใช้งานอยู่ได้';
      } else {
        try {
          $s = $pdo->prepare('SELECT id, host_code, role, approval_status FROM users WHERE id = ?');
          $s->execute([$id]);
          $target = $s->fetch();
          if (!$target) {
            $err = 'ไม่พบผู้ใช้';
          } elseif ($target['role'] === 'superadmin' && $decision === 'rejected' && approved_superadmin_count($pdo) <= 1) {
            $err = 'ไม่สามารถปฏิเสธผู้ดูแลระบบส่วนกลางคนสุดท้ายได้';
          } elseif ($decision === 'approved' && canonical_facility_name($pdo, (string)$target['host_code']) === null) {
            $err = 'ยังอนุมัติไม่ได้: รหัสสถานบริการยังไม่อยู่ในทะเบียนและยังไม่ได้กำหนดเขต';
          } else {
            if ($target['role'] === 'superadmin' && !verify_current_user_password($pdo, $currentAdmin, (string)($_POST['current_admin_password'] ?? ''))) {
              throw new RuntimeException('กรุณายืนยันรหัสผ่านของผู้ดูแล');
            }
            $canonical = canonical_facility_name($pdo, (string)$target['host_code']);
            $upd = $pdo->prepare('UPDATE users SET approval_status = ?, facility_name = COALESCE(?, facility_name), reviewed_at = NOW(), reviewed_by = ? WHERE id = ?');
            $upd->execute([$decision, $canonical, $currentAdmin['id'], $id]);
            write_zone_audit($pdo, $currentAdmin, 'review_user', 'user', (string)$id, ['approval_status'=>$target['approval_status']], ['approval_status'=>$decision]);
            $_SESSION['flash'] = $decision === 'approved'
              ? 'อนุมัติบัญชีผู้ใช้เรียบร้อย'
              : 'ปฏิเสธคำขอสมัครสมาชิกเรียบร้อย';
            header('Location: register.php'); exit;
          }
        } catch (Exception $e) {
          error_log('register.php review error: ' . $e->getMessage());
          $err = 'เกิดข้อผิดพลาด';
        }
      }
    }

    if ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      $position = trim($_POST['position'] ?? '');
      $username = trim($_POST['username'] ?? '');
      $password = $_POST['password'] ?? '';
      $host_code = trim($_POST['host_code'] ?? '');
      $facility = trim($_POST['facility_name'] ?? '');
      $allowedRoles = is_superadmin($currentAdmin) ? ['user','admin','superadmin'] : ['user'];
      $role = in_array($_POST['role'] ?? 'user', $allowedRoles, true) ? $_POST['role'] : 'user';
      $passErr = $password !== '' ? password_error($password) : '';

      if (!$id || !$name || !$position || !$username || !$host_code || !$facility) {
        $err = 'กรุณากรอกข้อมูลให้ครบ';
      } elseif (mb_strlen($name) > 255 || mb_strlen($position) > 255 || mb_strlen($facility) > 255) {
        $err = 'ชื่อ ตำแหน่ง หรือชื่อสถานบริการยาวเกิน 255 ตัวอักษร';
      } elseif (!preg_match('/^[0-9]{5}$/', $host_code)) {
        $err = 'host_code ต้องเป็นตัวเลข 5 หลัก';
      } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username)) {
        $err = 'Username ต้องเป็นอังกฤษ/ตัวเลข/._- และยาวอย่างน้อย 3 ตัวอักษร';
      } elseif ($passErr) {
        $err = $passErr;
      } elseif (!can_access_host_code($pdo, $currentAdmin, $host_code)) {
        $err = 'เลือกได้เฉพาะสถานบริการในขอบเขตของคุณ';
      } elseif (($canonicalFacility = canonical_facility_name($pdo, $host_code)) === null) {
        $err = 'ไม่พบรหัสสถานบริการในทะเบียน กรุณาให้ผู้ดูแลระบบส่วนกลางเพิ่มสถานบริการและกำหนดเขตก่อน';
      } else {
        $facility = $canonicalFacility;
        try {
          $current = $pdo->prepare('SELECT id, role FROM users WHERE id = ?');
          $current->execute([$id]);
          $currentUser = $current->fetch();
          if (!$currentUser) {
            $err = 'ไม่พบผู้ใช้';
          } elseif ($currentUser['role'] === 'superadmin' && $role !== 'superadmin' && superadmin_count($pdo) <= 1) {
            $err = 'ไม่สามารถลดสิทธิ์ผู้ดูแลระบบส่วนกลางคนสุดท้ายได้';
          } else {
            if (($password !== '' || $role !== $currentUser['role'])
                && !verify_current_user_password($pdo, $currentAdmin, (string)($_POST['current_admin_password'] ?? ''))) {
              throw new RuntimeException('กรุณายืนยันรหัสผ่านของผู้ดูแลก่อนเปลี่ยนรหัสผ่านหรือระดับสิทธิ์');
            }
            $chk = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $chk->execute([$username, $id]);
            if ($chk->fetch()) {
              $err = 'ชื่อผู้ใช้ซ้ำ';
            } else {
              if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $pdo->prepare('UPDATE users SET host_code=?,facility_name=?,name=?,position=?,username=?,password=?,role=? WHERE id=?');
                $upd->execute([$host_code, $facility, $name, $position, $username, $hash, $role, $id]);
              } else {
                $upd = $pdo->prepare('UPDATE users SET host_code=?,facility_name=?,name=?,position=?,username=?,role=? WHERE id=?');
                $upd->execute([$host_code, $facility, $name, $position, $username, $role, $id]);
              }
              if ($role !== 'admin') {
                $pdo->prepare('DELETE FROM user_zone_assignments WHERE user_id = ?')->execute([$id]);
              }
              write_zone_audit($pdo, $currentAdmin, 'update_user', 'user', (string)$id, ['role'=>$currentUser['role']], ['username'=>$username,'role'=>$role,'host_code'=>$host_code,'password_changed'=>$password !== '']);

              if (($_SESSION['user']['id'] ?? 0) == $id) {
                $s = $pdo->prepare('SELECT id,host_code,facility_name,name,username,role FROM users WHERE id = ?');
                $s->execute([$id]);
                $_SESSION['user'] = $s->fetch();
              }

              $_SESSION['flash'] = 'อัปเดตข้อมูลผู้ใช้เรียบร้อย';
              header('Location: register.php'); exit;
            }
          }
        } catch (Exception $e) {
          error_log('register.php update error: ' . $e->getMessage());
          $err = 'เกิดข้อผิดพลาด';
        }
      }
    }

    if ($action === 'reset_password') {
      $id = (int)($_POST['id'] ?? 0);
      $password = $_POST['new_password'] ?? '';
      $passErr = password_error($password);

      if (!$id) {
        $err = 'ไม่พบผู้ใช้';
      } elseif ($passErr) {
        $err = $passErr;
      } elseif (!verify_current_user_password($pdo, $currentAdmin, (string)($_POST['current_admin_password'] ?? ''))) {
        $err = 'รหัสผ่านของผู้ดูแลไม่ถูกต้อง';
      } else {
        try {
          $hash = password_hash($password, PASSWORD_DEFAULT);
          $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
          $upd->execute([$hash, $id]);
          write_zone_audit($pdo, $currentAdmin, 'reset_password', 'user', (string)$id, null, ['password_changed'=>true]);
          $_SESSION['flash'] = 'รีเซ็ตรหัสผ่านเรียบร้อย';
          header('Location: register.php'); exit;
        } catch (Exception $e) {
          error_log('register.php reset password error: ' . $e->getMessage());
          $err = 'เกิดข้อผิดพลาด';
        }
      }
    }

    if ($action === 'toggle') {
      if (!$hasActiveColumn) {
        $err = 'ฟีเจอร์นี้ยังไม่ได้ตั้งค่าในฐานข้อมูล (รันมิเกรชัน)';
      } else {
        $id = (int)($_POST['id'] ?? 0);
        $desired = (($_POST['set'] ?? '') === '1') ? 1 : 0;
        if (!$id) {
          $err = 'ไม่พบผู้ใช้';
        } elseif (($_SESSION['user']['id'] ?? 0) == $id && $desired === 0) {
          $err = 'ไม่สามารถปิดการใช้งานบัญชีที่กำลังใช้งานอยู่ได้';
        } else {
          try {
            $s = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
            $s->execute([$id]);
            $target = $s->fetch();
            if (!$target) {
              $err = 'ไม่พบผู้ใช้';
            } elseif ($target['role'] === 'superadmin' && $desired === 0 && active_superadmin_count($pdo, $hasActiveColumn) <= 1) {
              $err = 'ไม่สามารถปิดผู้ดูแลระบบส่วนกลางคนสุดท้ายได้';
            } else {
              if ($target['role'] === 'superadmin' && !verify_current_user_password($pdo, $currentAdmin, (string)($_POST['current_admin_password'] ?? ''))) {
                throw new RuntimeException('กรุณายืนยันรหัสผ่านของผู้ดูแล');
              }
              $upd = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
              $upd->execute([$desired, $id]);
              write_zone_audit($pdo, $currentAdmin, 'toggle_user', 'user', (string)$id, ['is_active'=>(int)$target['is_active']], ['is_active'=>$desired]);
              $_SESSION['flash'] = $desired ? 'เปิดการใช้งานผู้ใช้เรียบร้อย' : 'ปิดการใช้งานผู้ใช้เรียบร้อย';
              header('Location: register.php'); exit;
            }
          } catch (Exception $e) {
            error_log('register.php toggle error: ' . $e->getMessage());
            $err = 'เกิดข้อผิดพลาด';
          }
        }
      }
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if (!$id) {
        $err = 'ไม่พบผู้ใช้';
      } elseif (($_SESSION['user']['id'] ?? 0) == $id) {
        $err = 'ไม่สามารถลบผู้ใช้ปัจจุบันได้';
      } else {
        try {
          $s = $pdo->prepare('SELECT role FROM users WHERE id = ?');
          $s->execute([$id]);
          $target = $s->fetch();
          if (!$target) {
            $err = 'ไม่พบผู้ใช้';
          } elseif ($target['role'] === 'superadmin' && superadmin_count($pdo) <= 1) {
            $err = 'ไม่สามารถลบผู้ดูแลระบบส่วนกลางคนสุดท้ายได้';
          } else {
            $s2 = $pdo->prepare('SELECT
              (SELECT COUNT(*) FROM withdrawals WHERE user_id = ? OR approved_by = ?) +
              (SELECT COUNT(*) FROM users WHERE reviewed_by = ?)');
            $s2->execute([$id, $id, $id]);
            if ((int)$s2->fetchColumn() > 0) {
              $err = 'ไม่สามารถลบผู้ใช้ที่มีประวัติใบเบิก การอนุมัติ หรือการพิจารณาผู้ใช้ได้ กรุณาระงับบัญชีแทน';
            } else {
              write_zone_audit($pdo, $currentAdmin, 'delete_user', 'user', (string)$id, ['role'=>$target['role']], null);
              $d = $pdo->prepare('DELETE FROM users WHERE id = ?');
              $d->execute([$id]);
              $_SESSION['flash'] = 'ลบผู้ใช้เรียบร้อย';
              header('Location: register.php'); exit;
            }
          }
        } catch (Exception $e) {
          error_log('register.php delete error: ' . $e->getMessage());
          $err = 'เกิดข้อผิดพลาด';
        }
      }
    }
  }
}

$userScope = is_superadmin($currentAdmin)
  ? ['sql'=>'1=1','params'=>[]]
  : host_scope_condition($currentAdmin, 'u.host_code');
$roleScopeSql = is_superadmin($currentAdmin) ? '1=1' : "u.role = 'user'";
$usersStmt = $pdo->prepare("SELECT u.*, reviewer.name AS reviewer_name
                      FROM users u
                      LEFT JOIN users reviewer ON reviewer.id = u.reviewed_by
                      WHERE {$roleScopeSql} AND " . $userScope['sql'] . "
                      ORDER BY CASE u.approval_status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
                               u.role ASC, u.id DESC");
$usersStmt->execute($userScope['params']);
$users = $usersStmt->fetchAll();
$pendingCount = count(array_filter($users, fn($u) => $u['approval_status'] === 'pending'));
$userGroups = [
  'pending' => [
    'title' => 'คำขอรออนุมัติ',
    'description' => 'บัญชีที่สมัครเข้ามาและยังไม่สามารถเข้าสู่ระบบได้',
    'tone' => 'amber',
    'users' => array_values(array_filter($users, fn($u) => $u['approval_status'] === 'pending')),
  ],
  'active' => [
    'title' => 'ผู้ใช้ที่ใช้งานอยู่',
    'description' => 'บัญชีที่ได้รับอนุมัติและเปิดใช้งานตามปกติ',
    'tone' => 'green',
    'users' => array_values(array_filter($users, fn($u) => $u['approval_status'] === 'approved' && !empty($u['is_active']))),
  ],
  'suspended' => [
    'title' => 'บัญชีที่ระงับ',
    'description' => 'บัญชีที่ได้รับอนุมัติแล้ว แต่ถูกปิดการใช้งานชั่วคราว',
    'tone' => 'red',
    'users' => array_values(array_filter($users, fn($u) => $u['approval_status'] === 'approved' && empty($u['is_active']))),
  ],
  'rejected' => [
    'title' => 'คำขอที่ไม่อนุมัติ',
    'description' => 'คำขอสมัครที่ถูกปฏิเสธ สามารถอนุมัติภายหลังได้',
    'tone' => 'slate',
    'users' => array_values(array_filter($users, fn($u) => $u['approval_status'] === 'rejected')),
  ],
];
$managedFacilities = visible_facilities($pdo, $currentAdmin);
$managementScopeSummary = user_scope_summary($pdo, $currentAdmin);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>จัดการผู้ใช้ | ระบบเบิกยา CUP สันกำแพง</title>
  <style>
    :root {
      --ink: #172033;
      --muted: #64748b;
      --line: rgba(15, 23, 42, .10);
      --line-soft: rgba(15, 23, 42, .07);
      --surface: rgba(255, 255, 255, .78);
      --surface-strong: rgba(255, 255, 255, .94);
      --radius: 16px;
      --radius-sm: 12px;
      --shadow: 0 10px 28px rgba(15, 23, 42, .07);
      --font-main: "Noto Sans Thai", "Sarabun", "Leelawadee UI", "Segoe UI", Tahoma, sans-serif;
    }

    html {
      font-size: 16px;
      text-rendering: optimizeLegibility;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    *, *::before, *::after {
      box-sizing: border-box;
    }

    body {
      min-height: 100vh;
      margin: 0;
      display: flex;
      flex-direction: column;
      color: var(--ink);
      font-family: var(--font-main);
      background:
        radial-gradient(circle at 10% 10%, rgba(14, 165, 164, .13), transparent 26%),
        radial-gradient(circle at 90% 16%, rgba(37, 99, 235, .12), transparent 28%),
        linear-gradient(135deg, #f8fafc 0%, #eef7fb 48%, #f6f8fb 100%);
      background-attachment: fixed;
    }

    a { color: inherit; text-decoration: none; }
    button, input, select { font: inherit; }

    .page-wrap {
      width: min(1440px, calc(100% - 28px));
      flex: 1 0 auto;
      margin: 0 auto;
      padding: 14px 0 22px;
    }

    .panel {
      overflow: hidden;
      border: 1px solid var(--line-soft);
      border-radius: var(--radius);
      background: var(--surface);
      box-shadow: var(--shadow);
      backdrop-filter: blur(18px);
    }

    .page-head {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      align-items: center;
      padding: 18px;
      border-bottom: 1px solid var(--line-soft);
    }

    .head-actions { display: flex; align-items: center; gap: 10px; }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 800;
    }

    .eyebrow::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: #14b8a6;
      box-shadow: 0 0 0 4px rgba(20, 184, 166, .12);
    }

    h1 {
      margin: 4px 0 0;
      font-size: 23px;
      line-height: 1.25;
      font-weight: 900;
      letter-spacing: 0;
    }

    .head-sub {
      margin-top: 4px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.65;
    }

    .notice {
      margin: 14px 18px 0;
      padding: 12px 14px;
      border-radius: var(--radius);
      font-size: 14px;
      line-height: 1.65;
    }

    .notice.error { color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; }
    .notice.ok { color: #047857; background: #ecfdf5; border: 1px solid #bbf7d0; }

    .users-content { padding: 18px; }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 16px;
    }

    .summary-card {
      padding: 14px 16px;
      border: 1px solid var(--line-soft);
      border-radius: var(--radius);
      background: rgba(255,255,255,.82);
    }

    .summary-card strong { display: block; font-size: 24px; line-height: 1; }
    .summary-card span { display: block; margin-top: 7px; color: var(--muted); font-size: 12px; font-weight: 800; }
    .summary-card.amber strong { color: #b45309; }
    .summary-card.green strong { color: #047857; }
    .summary-card.red strong { color: #b91c1c; }
    .summary-card.slate strong { color: #475569; }

    .groups { display: grid; gap: 16px; }

    .user-group {
      overflow: hidden;
      border: 1px solid var(--line-soft);
      border-radius: var(--radius);
      background: rgba(255,255,255,.72);
    }

    .group-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      padding: 14px 16px;
      border-bottom: 1px solid var(--line-soft);
      background: rgba(248,250,252,.86);
    }

    .group-head h2 { margin: 0; font-size: 17px; }
    .group-head p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
    .group-count { min-width: 34px; height: 34px; display: grid; place-items: center; border-radius: 999px; font-weight: 900; }
    .user-group.amber .group-count { color:#92400e; background:#fef3c7; }
    .user-group.green .group-count { color:#047857; background:#d1fae5; }
    .user-group.red .group-count { color:#b91c1c; background:#fee2e2; }
    .user-group.slate .group-count { color:#475569; background:#e2e8f0; }

    .empty-state { padding: 24px 16px; color: var(--muted); text-align: center; font-size: 13px; }

    .section-title {
      margin: 0 0 12px;
      font-size: 17px;
      font-weight: 900;
    }

    .security-note {
      margin-bottom: 14px;
      padding: 11px 12px;
      border: 1px solid rgba(14, 165, 164, .18);
      border-radius: var(--radius);
      color: #0f766e;
      background: rgba(20, 184, 166, .08);
      font-size: 13px;
      line-height: 1.6;
    }

    .table-wrap {
      max-width: 100%;
      overflow: auto;
      border: 0;
      border-radius: 0;
      background: transparent;
      scrollbar-width: thin;
      scrollbar-color: rgba(14, 165, 164, .45) rgba(226, 232, 240, .75);
    }

    table {
      width: 100%;
      min-width: 900px;
      border-collapse: separate;
      border-spacing: 0;
      font-size: 13px;
      line-height: 1.55;
    }

    th, td {
      padding: 10px 11px;
      border-bottom: 1px solid var(--line-soft);
      text-align: left;
      vertical-align: top;
    }

    th {
      position: sticky;
      top: 0;
      z-index: 1;
      color: #334155;
      background: #f8fafc;
      font-weight: 900;
    }

    tr:last-child td { border-bottom: 0; }
    tr:hover td { background: rgba(20, 184, 166, .05); }

    .pill {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 0 9px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 900;
      white-space: nowrap;
    }

    .pill.user { color: #0369a1; background: #e0f2fe; }
    .pill.admin { color: #7c3aed; background: #ede9fe; }
    .pill.on { color: #047857; background: #d1fae5; }
    .pill.off { color: #b91c1c; background: #fee2e2; }
    .pill.pending { color: #a16207; background: #fef3c7; }
    .pill.approved { color: #047857; background: #d1fae5; }
    .pill.rejected { color: #b91c1c; background: #fee2e2; }

    .pending-summary {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-top: 10px;
      padding: 8px 12px;
      border-radius: 999px;
      color: #92400e;
      background: #fef3c7;
      font-size: 13px;
      font-weight: 900;
    }

    .review-form { display: inline-flex; gap: 7px; }

    .identity strong { display: block; color: #1e293b; }
    .identity span { display: block; margin-top: 2px; color: var(--muted); font-size: 12px; }

    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 7px;
      min-width: 170px;
    }

    .btn {
      min-height: 34px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      padding: 0 10px;
      color: #475569;
      background: #f8fafc;
      cursor: pointer;
      font-size: 13px;
      font-weight: 900;
      transition: background .18s ease, border-color .18s ease, transform .18s ease;
    }

    .btn:hover {
      transform: translateY(-1px);
      background: #fff;
      border-color: rgba(14, 165, 164, .28);
    }

    .btn.primary {
      color: #fff;
      border-color: transparent;
      background: linear-gradient(135deg, #0f9f7a, #2563eb);
      box-shadow: 0 10px 22px rgba(37, 99, 235, .14);
    }

    .btn.danger { color: #b91c1c; background: #fff1f2; border-color: #ffe4e6; }
    .btn.warn { color: #0f766e; background: rgba(20, 184, 166, .08); border-color: rgba(20, 184, 166, .18); }

    .user-form {
      display: grid;
      gap: 12px;
    }

    .field {
      display: grid;
      gap: 6px;
      min-width: 0;
    }

    .field label {
      color: #334155;
      font-size: 13px;
      font-weight: 900;
    }

    .hint {
      color: var(--muted);
      font-size: 12px;
      font-weight: 700;
    }

    .field input,
    .field select {
      width: 100%;
      max-width: 100%;
      min-width: 0;
      min-height: 42px;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      padding: 0 12px;
      color: var(--ink);
      background: var(--surface-strong);
      outline: none;
    }

    .field input:focus,
    .field select:focus {
      border-color: rgba(14, 165, 164, .65);
      box-shadow: 0 0 0 4px rgba(14, 165, 164, .12);
    }

    .password-wrap {
      position: relative;
    }

    .password-wrap input {
      padding-right: 48px;
    }

    .eye-btn {
      position: absolute;
      right: 6px;
      top: 50%;
      width: 36px;
      height: 32px;
      display: grid;
      place-items: center;
      border: 0;
      border-radius: 10px;
      color: #64748b;
      background: transparent;
      cursor: pointer;
      transform: translateY(-50%);
    }

    .eye-btn:hover {
      color: #0f766e;
      background: rgba(20, 184, 166, .10);
    }

    .form-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-top: 4px;
    }

    .modal-backdrop {
      position: fixed;
      inset: 0;
      z-index: 80;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
      background: rgba(15, 23, 42, .54);
      backdrop-filter: blur(8px);
    }

    .modal-backdrop.is-open { display: flex; }

    .modal-card {
      width: min(520px, 100%);
      overflow: hidden;
      border: 1px solid var(--line-soft);
      border-radius: var(--radius);
      background: rgba(255,255,255,.96);
      box-shadow: 0 28px 72px rgba(15, 23, 42, .24);
    }

    .modal-card.user-editor { width: min(720px, 100%); max-height: min(90vh, 820px); overflow: auto; }
    .editor-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .editor-grid .full { grid-column: 1 / -1; }

    .modal-head,
    .modal-body {
      padding: 16px 18px;
    }

    .modal-head {
      border-bottom: 1px solid var(--line-soft);
    }

    .modal-head h3 {
      margin: 0;
      font-size: 18px;
      font-weight: 900;
    }

    .modal-text {
      margin-top: 5px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.6;
    }

    @media (max-width: 900px) { .summary-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }

    @media (max-width: 620px) {
      .page-wrap {
        width: min(100% - 20px, 1500px);
      }

      .page-head {
        align-items: flex-start;
        flex-direction: column;
      }

      .head-actions, .head-actions .btn { width: 100%; }
      .summary-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
      .summary-card { padding: 12px; }
      .summary-card strong { font-size: 20px; }
      .group-head { align-items: flex-start; }
      .editor-grid { grid-template-columns: 1fr; }
      .editor-grid .full { grid-column: auto; }

      .form-actions {
        grid-template-columns: 1fr;
      }

      table { min-width: 820px; }

      .users-content,
      .page-head {
        padding: 14px;
      }
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="page-wrap">
  <section class="panel">
    <div class="page-head">
      <div>
        <div class="eyebrow">ระบบเบิกยา CUP สันกำแพง</div>
        <h1>จัดการผู้ใช้</h1>
        <div class="head-sub">อนุมัติคำขอสมัคร เพิ่ม แก้ไข ปิดใช้งาน และรีเซ็ตรหัสผ่าน · ขอบเขต: <?= e($managementScopeSummary['label']) ?></div>
      </div>
      <div class="head-actions">
        <button type="button" class="btn primary" onclick="openUserModal('create')">+ เพิ่มผู้ใช้ใหม่</button>
      </div>
    </div>

    <?php if($err): ?><div class="notice error"><?= e($err) ?></div><?php endif; ?>
    <?php if($msg): ?><div class="notice ok"><?= e($msg) ?></div><?php endif; ?>

    <div class="users-content">
      <div class="summary-grid" aria-label="สรุปสถานะผู้ใช้">
        <?php foreach ($userGroups as $group): ?>
          <div class="summary-card <?= e($group['tone']) ?>">
            <strong><?= count($group['users']) ?></strong>
            <span><?= e($group['title']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="security-note">
        รหัสผ่านถูกจัดเก็บแบบเข้ารหัส จึงดูรหัสเดิมไม่ได้ แต่สามารถตั้งรหัสใหม่ได้
        <?php if (is_zone_admin($currentAdmin)): ?> หน้านี้แสดงและจัดการเฉพาะบัญชีผู้ใช้ทั่วไปในเขตของคุณเท่านั้น<?php else: ?> เมื่อสร้างบัญชี admin ใหม่ ต้องไปกำหนดเขตในหน้า “เขตบริการ” ก่อนจึงจะมองเห็นข้อมูล<?php endif; ?>
      </div>

      <div class="groups">
        <?php foreach ($userGroups as $groupKey => $group): ?>
          <section class="user-group <?= e($group['tone']) ?>" id="group-<?= e($groupKey) ?>">
            <header class="group-head">
              <div>
                <h2><?= e($group['title']) ?></h2>
                <p><?= e($group['description']) ?></p>
              </div>
              <span class="group-count"><?= count($group['users']) ?></span>
            </header>

            <?php if (!$group['users']): ?>
              <div class="empty-state">ไม่มีบัญชีในกลุ่มนี้</div>
            <?php else: ?>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>ผู้ใช้</th>
                      <th>บัญชี / สิทธิ์</th>
                      <th>หน่วยบริการ</th>
                      <th>สถานะ</th>
                      <th>ผู้พิจารณา</th>
                      <th>จัดการ</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($group['users'] as $u): ?>
                      <tr>
                        <td class="identity">
                          <strong><?= e($u['name']) ?></strong>
                          <span><?= e($u['position'] ?: 'ยังไม่ระบุตำแหน่ง') ?> · ID <?= e($u['id']) ?></span>
                        </td>
                        <td class="identity">
                          <strong><?= e($u['username']) ?></strong>
                          <span class="pill <?= in_array($u['role'], ['admin','superadmin'], true) ? 'admin' : 'user' ?>"><?= e(role_label($u['role'])) ?></span>
                        </td>
                        <td class="identity">
                          <strong><?= e($u['facility_name']) ?></strong>
                          <span>host_code: <?= e($u['host_code']) ?></span>
                        </td>
                        <td>
                          <span class="pill <?= e($u['approval_status']) ?>"><?= e(approval_label($u['approval_status'])) ?></span>
                          <span class="pill <?= !empty($u['is_active']) ? 'on' : 'off' ?>"><?= !empty($u['is_active']) ? 'เปิดใช้งาน' : 'ระงับ' ?></span>
                        </td>
                        <td class="identity">
                          <strong><?= e($u['reviewer_name'] ?: '-') ?></strong>
                          <?php if (!empty($u['reviewed_at'])): ?><span><?= e(date('d/m/Y H:i', strtotime($u['reviewed_at']))) ?></span><?php endif; ?>
                        </td>
                        <td>
                          <div class="actions">
                            <?php if ($u['approval_status'] === 'pending'): ?>
                              <form method="post" class="review-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="review">
                                <input type="hidden" name="id" value="<?= e($u['id']) ?>">
                                <button type="submit" name="decision" value="approved" class="btn primary">อนุมัติ</button>
                                <button type="submit" name="decision" value="rejected" class="btn danger" onclick="return confirm('ปฏิเสธคำขอสมัครของผู้ใช้นี้?')">ปฏิเสธ</button>
                              </form>
                            <?php elseif ($u['approval_status'] === 'rejected'): ?>
                              <form method="post" class="review-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="review">
                                <input type="hidden" name="id" value="<?= e($u['id']) ?>">
                                <button type="submit" name="decision" value="approved" class="btn primary">อนุมัติภายหลัง</button>
                              </form>
                            <?php endif; ?>

                            <button type="button" class="btn"
                              data-id="<?= e($u['id']) ?>"
                              data-name="<?= e($u['name']) ?>"
                              data-position="<?= e($u['position']) ?>"
                              data-username="<?= e($u['username']) ?>"
                              data-facility="<?= e($u['facility_name']) ?>"
                              data-host-code="<?= e($u['host_code']) ?>"
                              data-role="<?= e($u['role']) ?>"
                              onclick="openUserModal('edit', this)">แก้ไข</button>
                            <button type="button" class="btn warn" data-id="<?= e($u['id']) ?>" data-name="<?= e($u['name']) ?>" onclick="openPasswordModal(this)">รหัสผ่าน</button>
                            <?php if ($hasActiveColumn): ?>
                              <button type="button" class="btn" data-id="<?= e($u['id']) ?>" data-name="<?= e($u['name']) ?>" data-active="<?= !empty($u['is_active']) ? '1' : '0' ?>" onclick="openStatusModal(this)"><?= !empty($u['is_active']) ? 'ระงับ' : 'เปิดใช้งาน' ?></button>
                            <?php endif; ?>
                            <form method="post" onsubmit="return confirm('ลบผู้ใช้นี้?');">
                              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="action" value="delete">
                              <input type="hidden" name="id" value="<?= e($u['id']) ?>">
                              <button type="submit" class="btn danger">ลบ</button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</main>

<div id="userModal" class="modal-backdrop" aria-hidden="true">
  <section class="modal-card user-editor" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
    <div class="modal-head">
      <h3 id="userModalTitle">เพิ่มผู้ใช้ใหม่</h3>
      <div id="userModalText" class="modal-text">กรอกข้อมูลผู้ใช้ บัญชีที่แอดมินเพิ่มจะใช้งานได้ทันที</div>
    </div>
    <div class="modal-body">
      <form id="userForm" method="post" class="user-form editor-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" id="userAction" value="create">
        <input type="hidden" name="id" id="userId">

        <div class="field full">
          <label for="userName">ชื่อ-นามสกุลผู้ใช้</label>
          <input id="userName" name="name" required maxlength="255">
        </div>
        <div class="field full">
          <label for="userPosition">ตำแหน่ง</label>
          <input id="userPosition" name="position" required maxlength="255" placeholder="เช่น เจ้าพนักงานเภสัชกรรม">
        </div>
        <div class="field">
          <label for="userUsername">Username</label>
          <input id="userUsername" name="username" autocomplete="username" required maxlength="100">
        </div>
        <div class="field">
          <label id="userPasswordLabel" for="userPassword">Password</label>
          <div class="password-wrap">
            <input id="userPassword" type="password" name="password" autocomplete="new-password" maxlength="72">
            <button type="button" class="eye-btn" data-toggle-password aria-label="แสดงรหัสผ่าน">ดู</button>
          </div>
          <div id="userPasswordHint" class="hint">อย่างน้อย 8 ตัวอักษร</div>
        </div>
        <div class="field full">
          <label for="userFacility">ชื่อ รพ.สต. / หน่วยบริการ</label>
          <input id="userFacility" name="facility_name" required maxlength="255" readonly>
        </div>
        <div class="field">
          <label for="userHostCode">host_code</label>
          <input id="userHostCode" name="host_code" list="managedFacilityList" required inputmode="numeric" pattern="[0-9]{5}" minlength="5" maxlength="5" onchange="syncFacilityName(this.value)">
          <datalist id="managedFacilityList"><?php foreach ($managedFacilities as $facility): ?><option value="<?= e($facility['host_code']) ?>"><?= e($facility['facility_name']) ?></option><?php endforeach; ?></datalist>
        </div>
        <div class="field">
          <label for="userRole">สิทธิ์</label>
          <select id="userRole" name="role">
            <option value="user">user</option>
            <?php if (is_superadmin($currentAdmin)): ?><option value="admin">admin</option><option value="superadmin">superadmin</option><?php endif; ?>
          </select>
        </div>
        <div class="field full">
          <label for="currentAdminPassword">ยืนยันรหัสผ่านของผู้ดูแล</label>
          <input id="currentAdminPassword" type="password" name="current_admin_password" autocomplete="current-password" maxlength="128">
          <div class="hint">จำเป็นเมื่อสร้างบัญชี admin/superadmin เปลี่ยนระดับสิทธิ์ หรือเปลี่ยนรหัสผ่านผู้อื่น</div>
        </div>
        <div class="form-actions full">
          <button id="userSubmit" class="btn primary" type="submit">เพิ่มผู้ใช้</button>
          <button type="button" class="btn" onclick="closeModal('userModal')">ยกเลิก</button>
        </div>
      </form>
    </div>
  </section>
</div>

<div id="statusModal" class="modal-backdrop" aria-hidden="true">
  <section class="modal-card" role="dialog" aria-modal="true">
    <div class="modal-head">
      <h3>เปลี่ยนสถานะผู้ใช้</h3>
      <div id="statusModalText" class="modal-text"></div>
    </div>
    <div class="modal-body">
      <form id="statusForm" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="id" id="statusUserId">
        <input type="hidden" name="set" id="statusSet">
        <?php if (is_superadmin($currentAdmin)): ?>
        <div class="field"><label for="status_admin_password">รหัสผ่านผู้ดูแล (เมื่อจัดการ superadmin)</label><input id="status_admin_password" type="password" name="current_admin_password" autocomplete="current-password" maxlength="128"></div>
        <?php endif; ?>
        <div class="form-actions">
          <button type="button" class="btn primary" onclick="submitStatus(1)">เปิดใช้งาน</button>
          <button type="button" class="btn danger" onclick="submitStatus(0)">ปิดใช้งาน</button>
          <button type="button" class="btn" onclick="closeModal('statusModal')">ยกเลิก</button>
        </div>
      </form>
    </div>
  </section>
</div>

<div id="passwordModal" class="modal-backdrop" aria-hidden="true">
  <section class="modal-card" role="dialog" aria-modal="true">
    <div class="modal-head">
      <h3>รีเซ็ตรหัสผ่าน</h3>
      <div id="passwordModalText" class="modal-text"></div>
    </div>
    <div class="modal-body">
      <form method="post" class="user-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="id" id="passwordUserId">
        <div class="security-note">
          ไม่สามารถดูรหัสเดิมได้ เพราะระบบเก็บรหัสแบบ hash ให้ตั้งรหัสใหม่แทน
        </div>
        <div class="field">
          <label for="new_password">รหัสผ่านใหม่</label>
          <div class="password-wrap">
            <input id="new_password" type="password" name="new_password" autocomplete="new-password" maxlength="72" required>
            <button type="button" class="eye-btn" data-toggle-password aria-label="แสดงรหัสผ่าน">ดู</button>
          </div>
          <div class="hint">อย่างน้อย 8 ตัวอักษร</div>
        </div>
        <div class="field">
          <label for="reset_admin_password">รหัสผ่านของผู้ดูแลที่กำลังใช้งาน</label>
          <input id="reset_admin_password" type="password" name="current_admin_password" autocomplete="current-password" maxlength="128" required>
        </div>
        <div class="form-actions">
          <button class="btn primary" type="submit">บันทึกรหัสใหม่</button>
          <button type="button" class="btn" onclick="closeModal('passwordModal')">ยกเลิก</button>
        </div>
      </form>
    </div>
  </section>
</div>

<script>
const managedFacilities = <?= json_encode(array_column($managedFacilities, 'facility_name', 'host_code'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function syncFacilityName(hostCode) {
  document.getElementById('userFacility').value = managedFacilities[String(hostCode).trim()] || '';
}

function openModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.add('is-open');
  modal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.remove('is-open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}

function openUserModal(mode, button = null, values = null) {
  const isEdit = mode === 'edit' || mode === 'update';
  const source = values || (button ? {
    id: button.dataset.id || '',
    name: button.dataset.name || '',
    position: button.dataset.position || '',
    username: button.dataset.username || '',
    facility_name: button.dataset.facility || '',
    host_code: button.dataset.hostCode || '',
    role: button.dataset.role || 'user'
  } : {});

  document.getElementById('userModalTitle').textContent = isEdit ? 'แก้ไขข้อมูลผู้ใช้' : 'เพิ่มผู้ใช้ใหม่';
  document.getElementById('userModalText').textContent = isEdit
    ? 'ตรวจสอบข้อมูลให้ถูกต้องก่อนกดบันทึกการเปลี่ยนแปลง'
    : 'บัญชีที่แอดมินเพิ่มจะได้รับอนุมัติและใช้งานได้ทันที';
  document.getElementById('userAction').value = isEdit ? 'update' : 'create';
  document.getElementById('userId').value = isEdit ? (source.id || '') : '';
  document.getElementById('userName').value = source.name || '';
  document.getElementById('userPosition').value = source.position || '';
  document.getElementById('userUsername').value = source.username || '';
  document.getElementById('userFacility').value = source.facility_name || '';
  document.getElementById('userHostCode').value = source.host_code || '';
  const roleSelect = document.getElementById('userRole');
  roleSelect.value = Array.from(roleSelect.options).some(option => option.value === source.role) ? source.role : 'user';

  const password = document.getElementById('userPassword');
  password.value = '';
  const currentAdminPassword = document.getElementById('currentAdminPassword');
  if (currentAdminPassword) currentAdminPassword.value = '';
  password.required = !isEdit;
  document.getElementById('userPasswordLabel').textContent = isEdit ? 'Password (ไม่เปลี่ยนให้เว้นว่าง)' : 'Password';
  document.getElementById('userPasswordHint').textContent = isEdit
    ? 'กรอกเฉพาะเมื่อต้องการเปลี่ยนรหัสผ่าน อย่างน้อย 8 ตัวอักษร'
    : 'อย่างน้อย 8 ตัวอักษร';
  document.getElementById('userSubmit').textContent = isEdit ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้';

  openModal('userModal');
  window.setTimeout(() => document.getElementById('userName').focus(), 50);
}

function openStatusModal(btn) {
  const id = btn.getAttribute('data-id');
  const name = btn.getAttribute('data-name');
  const active = btn.getAttribute('data-active') === '1';
  document.getElementById('statusUserId').value = id;
  document.getElementById('statusModalText').textContent = active ? `เลือกปิดหรือคงสถานะผู้ใช้: ${name}` : `เลือกเปิดหรือคงสถานะผู้ใช้: ${name}`;
  openModal('statusModal');
}

function submitStatus(value) {
  document.getElementById('statusSet').value = value ? '1' : '0';
  document.getElementById('statusForm').submit();
}

function openPasswordModal(btn) {
  const id = btn.getAttribute('data-id');
  const name = btn.getAttribute('data-name');
  document.getElementById('passwordUserId').value = id;
  document.getElementById('passwordModalText').textContent = `ตั้งรหัสผ่านใหม่ให้ ${name}`;
  document.getElementById('new_password').value = '';
  document.getElementById('reset_admin_password').value = '';
  openModal('passwordModal');
}

document.querySelectorAll('[data-toggle-password]').forEach(button => {
  button.addEventListener('click', () => {
    const input = button.parentElement.querySelector('input');
    if (!input) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.textContent = show ? 'ซ่อน' : 'ดู';
    button.setAttribute('aria-label', show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
  });
});

document.querySelectorAll('.modal-backdrop').forEach(modal => {
  modal.addEventListener('click', event => {
    if (event.target === modal) closeModal(modal.id);
  });
});

document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  document.querySelectorAll('.modal-backdrop.is-open').forEach(modal => closeModal(modal.id));
});

<?php if ($formModalMode): ?>
openUserModal(<?= json_encode($formModalMode, JSON_UNESCAPED_UNICODE) ?>, null, <?= json_encode($formValues, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
<?php elseif ($openCreateModal): ?>
openUserModal('create');
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
