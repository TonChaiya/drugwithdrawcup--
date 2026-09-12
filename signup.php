<?php
require 'includes/db.php';
require 'includes/rate_limit.php';

if (!empty($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$form = [
    'name' => '',
    'position' => '',
    'username' => '',
    'facility_name' => '',
    'host_code' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $key => $_) {
        $form[$key] = trim((string)($_POST[$key] ?? ''));
    }

    $password = (string)($_POST['password'] ?? '');
    $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
    $honeypot = trim((string)($_POST['website'] ?? ''));
    $lastAttempt = (int)($_SESSION['signup_last_attempt'] ?? 0);
    $signupIdentity = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif ($honeypot !== '') {
        $error = 'ไม่สามารถส่งคำขอได้';
    } elseif ($lastAttempt > 0 && time() - $lastAttempt < 5) {
        $error = 'กรุณารอสักครู่ก่อนส่งคำขออีกครั้ง';
    } elseif (security_rate_retry_after($pdo, 'signup', $signupIdentity) > 0) {
        $error = 'มีการส่งคำขอจากเครือข่ายนี้มากเกินไป กรุณาลองใหม่ภายหลัง';
    } elseif (in_array('', $form, true) || $password === '' || $passwordConfirmation === '') {
        $error = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    } elseif (mb_strlen($form['name']) > 255 || mb_strlen($form['position']) > 255 || mb_strlen($form['facility_name']) > 255) {
        $error = 'ชื่อผู้ใช้ ตำแหน่ง หรือชื่อสถานบริการยาวเกิน 255 ตัวอักษร';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $form['username'])) {
        $error = 'ชื่อผู้ใช้ต้องเป็นอังกฤษ ตัวเลข หรือ . _ - และยาวอย่างน้อย 3 ตัวอักษร';
    } elseif (!preg_match('/^[0-9]{5}$/', $form['host_code'])) {
        $error = 'รหัสหน่วยบริการต้องเป็นตัวเลข 5 หลัก';
    } elseif (strlen($password) < 8) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif (strlen($password) > 72) {
        $error = 'รหัสผ่านต้องยาวไม่เกิน 72 ไบต์';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน';
    } else {
        $_SESSION['signup_last_attempt'] = time();
        security_rate_record($pdo, 'signup', $signupIdentity, 10, 3600, 900);
        try {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$form['username']]);
            if ($stmt->fetch()) {
                $error = 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว';
            } else {
                $facilityStmt = $pdo->prepare('SELECT facility_name FROM facilities WHERE host_code = ? AND is_active = 1 LIMIT 1');
                $facilityStmt->execute([$form['host_code']]);
                $registeredFacility = $facilityStmt->fetchColumn();
                if ($registeredFacility !== false) {
                    $form['facility_name'] = (string)$registeredFacility;
                }
                $stmt = $pdo->prepare(
                    "INSERT INTO users
                     (host_code, facility_name, name, position, username, password, role, approval_status, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, 'user', 'pending', 1)"
                );
                $stmt->execute([
                    $form['host_code'],
                    $form['facility_name'],
                    $form['name'],
                    $form['position'],
                    $form['username'],
                    password_hash($password, PASSWORD_DEFAULT),
                ]);

                $_SESSION['flash'] = 'ส่งคำขอสมัครสมาชิกแล้ว กรุณารอแอดมินตรวจสอบและอนุมัติบัญชี';
                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('signup.php create error: ' . $e->getMessage());
            $error = $e->getCode() === '23000'
                ? 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว'
                : 'เกิดข้อผิดพลาดในการสมัครสมาชิก กรุณาลองใหม่';
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>สมัครสมาชิก | ระบบเบิกยา CUP สันกำแพง</title>
  <style>
    :root { --ink:#102033; --muted:#64748b; --line:rgba(15,23,42,.12); --green:#0f9f7a; --blue:#2563eb; --red:#b91c1c; }
    * { box-sizing:border-box; }
    body { min-height:100vh; margin:0; color:var(--ink); font-family:"Noto UI Thai","Leelawadee UI","Segoe UI",Tahoma,sans-serif; background:radial-gradient(circle at 12% 10%,rgba(14,165,164,.18),transparent 28%),radial-gradient(circle at 88% 18%,rgba(37,99,235,.14),transparent 30%),linear-gradient(135deg,#f4fbfb,#eef6ff 52%,#f8fafc); }
    .page { width:min(920px,calc(100% - 28px)); margin:0 auto; padding:38px 0; }
    .card { overflow:hidden; border:1px solid rgba(15,23,42,.08); border-radius:24px; background:rgba(255,255,255,.92); box-shadow:0 24px 70px rgba(15,23,42,.14); }
    .head { padding:28px 32px; color:#fff; background:linear-gradient(135deg,#0f9f7a,#0e7490 56%,#2563eb); }
    .head a { color:#fff; text-decoration:none; font-weight:800; }
    .head h1 { margin:14px 0 5px; font-size:30px; }
    .head p { margin:0; line-height:1.7; color:rgba(255,255,255,.86); }
    form { padding:28px 32px 32px; }
    .notice { margin-bottom:20px; padding:13px 15px; border:1px solid #fecaca; border-radius:14px; color:var(--red); background:#fef2f2; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .field.full { grid-column:1/-1; }
    label { display:block; margin-bottom:7px; font-size:14px; font-weight:800; }
    input { width:100%; min-height:48px; padding:11px 13px; color:var(--ink); font:inherit; border:1px solid var(--line); border-radius:13px; outline:none; background:#fff; }
    input:focus { border-color:rgba(14,165,164,.75); box-shadow:0 0 0 4px rgba(14,165,164,.12); }
    .hint { margin-top:6px; color:var(--muted); font-size:12px; line-height:1.55; }
    .actions { display:flex; gap:12px; align-items:center; margin-top:24px; }
    .submit { min-height:48px; padding:0 24px; border:0; border-radius:13px; color:#fff; background:linear-gradient(135deg,var(--green),#0e7490); font:inherit; font-weight:900; cursor:pointer; }
    .back { color:#475569; font-weight:800; text-decoration:none; }
    .approval-note { margin-top:22px; padding:14px 16px; border-radius:14px; color:#075985; background:#f0f9ff; font-size:13px; line-height:1.7; }
    .website-field { position:absolute!important; left:-9999px!important; width:1px!important; height:1px!important; overflow:hidden!important; }
    @media (max-width:680px) { .page{padding:14px 0}.head,form{padding:22px 18px}.grid{grid-template-columns:1fr}.field.full{grid-column:auto}.actions{align-items:stretch;flex-direction:column}.submit{width:100%} }
  </style>
</head>
<body>
  <main class="page">
    <section class="card">
      <header class="head">
        <a href="index.php">← กลับหน้าเข้าสู่ระบบ</a>
        <h1>สมัครสมาชิกผู้ใช้ใหม่</h1>
        <p>ลงทะเบียนบัญชีสำหรับหน่วยบริการในเครือข่าย CUP สันกำแพง</p>
      </header>

      <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="website-field" aria-hidden="true">
          <label for="website">เว็บไซต์</label>
          <input id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <?php if ($error): ?>
          <div class="notice" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="grid">
          <div class="field full">
            <label for="name">ชื่อ-นามสกุลผู้ใช้</label>
            <input id="name" name="name" maxlength="255" required autocomplete="name" value="<?= e($form['name']) ?>">
          </div>
          <div class="field full">
            <label for="position">ตำแหน่ง</label>
            <input id="position" name="position" maxlength="255" required placeholder="เช่น เจ้าพนักงานเภสัชกรรม" value="<?= e($form['position']) ?>">
          </div>
          <div class="field">
            <label for="facility_name">ชื่อ รพ.สต. / หน่วยบริการ</label>
            <input id="facility_name" name="facility_name" maxlength="255" required value="<?= e($form['facility_name']) ?>">
          </div>
          <div class="field">
            <label for="host_code">รหัสหน่วยบริการ (host_code)</label>
            <input id="host_code" name="host_code" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" required value="<?= e($form['host_code']) ?>">
            <div class="hint">ตัวเลข 5 หลัก เช่น 05957</div>
          </div>
          <div class="field full">
            <label for="username">ชื่อผู้ใช้ (Username)</label>
            <input id="username" name="username" minlength="3" maxlength="100" pattern="[A-Za-z0-9_.-]+" required autocomplete="username" value="<?= e($form['username']) ?>">
            <div class="hint">ใช้อักษรอังกฤษ ตัวเลข และเครื่องหมาย . _ - เท่านั้น</div>
          </div>
          <div class="field">
            <label for="password">รหัสผ่าน</label>
            <input id="password" type="password" name="password" minlength="8" maxlength="72" required autocomplete="new-password">
            <div class="hint">อย่างน้อย 8 ตัวอักษร</div>
          </div>
          <div class="field">
            <label for="password_confirmation">ยืนยันรหัสผ่าน</label>
            <input id="password_confirmation" type="password" name="password_confirmation" minlength="8" maxlength="72" required autocomplete="new-password">
          </div>
        </div>

        <div class="approval-note">
          หลังส่งคำขอ บัญชีจะยังไม่สามารถเข้าสู่ระบบได้จนกว่าแอดมินจะตรวจสอบและอนุมัติ
        </div>

        <div class="actions">
          <button class="submit" type="submit">ส่งคำขอสมัครสมาชิก</button>
          <a class="back" href="index.php">มีบัญชีแล้ว เข้าสู่ระบบ</a>
        </div>
      </form>
    </section>
  </main>
</body>
</html>
