<?php
require 'includes/db.php';
require 'includes/rate_limit.php';
if (isset($_SESSION['user'])) { header('Location: dashboard.php'); exit; }

$error = '';
$flash = $_SESSION['flash'] ?? null; 
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $now = time();
  $lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);
  if ($lockedUntil > $now) {
    $error = 'เข้าสู่ระบบไม่สำเร็จหลายครั้ง กรุณารอประมาณ 1 นาทีแล้วลองใหม่';
  } elseif (!verify_csrf($_POST['csrf_token'] ?? '')) {
    $error = 'Invalid CSRF token';
  } else {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rateIdentity = strtolower($username) . '|' . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $accountIdentity = strtolower($username);
    $persistentRetry = max(
      security_rate_retry_after($pdo, 'login', $rateIdentity),
      security_rate_retry_after($pdo, 'login_account', $accountIdentity)
    );

    if ($persistentRetry > 0) {
      $error = 'เข้าสู่ระบบไม่สำเร็จหลายครั้ง กรุณารอประมาณ ' . max(1, (int)ceil($persistentRetry / 60)) . ' นาทีแล้วลองใหม่';
    } else {
      $u = false;
      if (preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username) && is_string($password) && strlen($password) <= 1024) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $u = $stmt->fetch();
      }

      if ($u && password_verify($password, $u['password'])) {
      $approvalStatus = $u['approval_status'] ?? 'approved';
      if ($approvalStatus === 'pending') {
        $error = 'บัญชีของคุณกำลังรอแอดมินตรวจสอบและอนุมัติ';
      } elseif ($approvalStatus === 'rejected') {
        $error = 'คำขอสมัครสมาชิกนี้ไม่ได้รับการอนุมัติ กรุณาติดต่อผู้ดูแลระบบ';
      } elseif (isset($u['is_active']) && !$u['is_active']) {
        $error = 'บัญชีผู้ใช้นี้ถูกปิดการใช้งาน';
      } else {
      session_regenerate_id(true);
      unset($_SESSION['login_failures'], $_SESSION['login_first_failure'], $_SESSION['login_locked_until']);
      unset($_SESSION['csrf_token']);
      security_rate_clear($pdo, 'login', $rateIdentity);
      security_rate_clear($pdo, 'login_account', $accountIdentity);
      $_SESSION['user'] = [
        'id' => $u['id'],
        'username' => $u['username'],
        'name' => $u['name'],
        'role' => $u['role'],
        'host_code' => $u['host_code'],
        'facility_name' => $u['facility_name']
      ];
      $_SESSION['login_at'] = time();
      $_SESSION['last_activity_at'] = time();
      header('Location: dashboard.php');
      exit;
      }
    } else {
      security_rate_record($pdo, 'login', $rateIdentity, 5, 600, 300);
      security_rate_record($pdo, 'login_account', $accountIdentity, 10, 600, 300);
      $firstFailure = (int)($_SESSION['login_first_failure'] ?? 0);
      if ($firstFailure === 0 || $now - $firstFailure > 600) {
        $_SESSION['login_first_failure'] = $now;
        $_SESSION['login_failures'] = 1;
      } else {
        $_SESSION['login_failures'] = (int)($_SESSION['login_failures'] ?? 0) + 1;
      }
      if ((int)$_SESSION['login_failures'] >= 5) {
        $_SESSION['login_locked_until'] = $now + 60;
        $error = 'เข้าสู่ระบบไม่สำเร็จหลายครั้ง กรุณารอประมาณ 1 นาทีแล้วลองใหม่';
      } else {
        $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
      }
    }
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>ระบบเบิกยา CUP สันกำแพง</title>

  <style>
    :root {
      --ink: #102033;
      --muted: #5b6b7f;
      --line: rgba(16, 32, 51, .12);
      --white: rgba(255, 255, 255, .88);
      --green: #0f9f7a;
      --teal: #0ea5a4;
      --blue: #2563eb;
      --orange: #f59e0b;
      --red: #dc2626;
      --shadow: 0 24px 70px rgba(15, 23, 42, .18);
    }

    * {
      box-sizing: border-box;
    }

    body {
      min-height: 100vh;
      margin: 0;
      color: var(--ink);
      font-family: "Segoe UI", Tahoma, sans-serif;
      background:
        radial-gradient(circle at 14% 14%, rgba(14, 165, 164, .22), transparent 28%),
        radial-gradient(circle at 86% 18%, rgba(37, 99, 235, .18), transparent 30%),
        linear-gradient(135deg, #f4fbfb 0%, #eef6ff 48%, #f8fafc 100%);
      overflow-x: hidden;
    }

    body::before,
    body::after {
      content: "";
      position: fixed;
      width: 42vmin;
      height: 42vmin;
      border-radius: 35% 65% 55% 45%;
      background: rgba(15, 159, 122, .12);
      filter: blur(2px);
      z-index: 0;
      animation: drift 12s ease-in-out infinite alternate;
    }

    body::before {
      left: -13vmin;
      bottom: 5vmin;
    }

    body::after {
      right: -14vmin;
      top: 6vmin;
      background: rgba(245, 158, 11, .14);
      animation-delay: -5s;
    }

    @keyframes drift {
      from { transform: translate3d(0, 0, 0) rotate(0deg); }
      to { transform: translate3d(18px, -16px, 0) rotate(10deg); }
    }

    .page {
      position: relative;
      z-index: 1;
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 32px 18px;
    }

    .login-shell {
      width: min(1040px, 100%);
      display: grid;
      grid-template-columns: 1.08fr .92fr;
      align-items: stretch;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, .7);
      border-radius: 28px;
      background: var(--white);
      box-shadow: var(--shadow);
      backdrop-filter: blur(22px);
    }

    .brand-panel {
      position: relative;
      min-height: 620px;
      padding: 42px;
      color: #fff;
      background:
        linear-gradient(150deg, rgba(11, 101, 105, .96), rgba(12, 116, 159, .92) 53%, rgba(20, 73, 160, .95)),
        repeating-linear-gradient(45deg, rgba(255,255,255,.12) 0 1px, transparent 1px 18px);
      overflow: hidden;
    }

    .brand-panel::before {
      content: "";
      position: absolute;
      inset: auto -80px -120px auto;
      width: 340px;
      height: 340px;
      border: 36px solid rgba(255,255,255,.12);
      border-radius: 999px;
    }

    .brand-panel::after {
      content: "";
      position: absolute;
      left: 42px;
      right: 42px;
      bottom: 154px;
      height: 1px;
      background: linear-gradient(90deg, rgba(255,255,255,.55), transparent);
    }

    .brand-content {
      position: relative;
      z-index: 1;
      height: 100%;
      display: flex;
      flex-direction: column;
    }

    .badge {
      width: fit-content;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 9px 13px;
      border: 1px solid rgba(255, 255, 255, .28);
      border-radius: 999px;
      background: rgba(255, 255, 255, .12);
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0;
    }

    .badge-dot {
      width: 9px;
      height: 9px;
      border-radius: 999px;
      background: #7dd3fc;
      box-shadow: 0 0 0 6px rgba(125, 211, 252, .18);
    }

    .brand-mark {
      width: 86px;
      height: 86px;
      margin-top: 56px;
      display: grid;
      place-items: center;
      border-radius: 26px;
      background: rgba(255, 255, 255, .16);
      border: 1px solid rgba(255, 255, 255, .24);
      box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 18px 44px rgba(0, 0, 0, .16);
    }

    .brand-mark svg {
      width: 48px;
      height: 48px;
    }

    h1 {
      max-width: 560px;
      margin: 28px 0 12px;
      font-size: clamp(32px, 5vw, 56px);
      line-height: 1.08;
      letter-spacing: 0;
    }

    .lead {
      max-width: 520px;
      margin: 0;
      color: rgba(255, 255, 255, .82);
      font-size: 17px;
      line-height: 1.75;
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
      margin-top: auto;
    }

    .stat {
      min-height: 92px;
      padding: 16px;
      border: 1px solid rgba(255, 255, 255, .2);
      border-radius: 18px;
      background: rgba(255, 255, 255, .11);
    }

    .stat strong {
      display: block;
      font-size: 22px;
      line-height: 1.2;
    }

    .stat span {
      display: block;
      margin-top: 5px;
      color: rgba(255, 255, 255, .74);
      font-size: 12px;
      line-height: 1.45;
    }

    .form-panel {
      padding: 44px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      background:
        linear-gradient(180deg, rgba(255,255,255,.94), rgba(255,255,255,.82)),
        linear-gradient(135deg, rgba(14,165,164,.07), rgba(37,99,235,.05));
    }

    .form-top {
      margin-bottom: 26px;
    }

    .kicker {
      margin: 0 0 8px;
      color: var(--teal);
      font-size: 13px;
      font-weight: 800;
    }

    h2 {
      margin: 0;
      color: var(--ink);
      font-size: 30px;
      line-height: 1.25;
      letter-spacing: 0;
    }

    .sub {
      margin: 8px 0 0;
      color: var(--muted);
      font-size: 14px;
      line-height: 1.65;
    }

    .alert {
      display: flex;
      gap: 12px;
      align-items: flex-start;
      margin-bottom: 14px;
      padding: 13px 14px;
      border-radius: 16px;
      font-size: 14px;
      line-height: 1.55;
      border: 1px solid;
    }

    .alert svg {
      flex: 0 0 auto;
      width: 20px;
      height: 20px;
      margin-top: 1px;
    }

    .alert-success {
      color: #057a55;
      background: #ecfdf5;
      border-color: #bbf7d0;
    }

    .alert-error {
      color: #b91c1c;
      background: #fef2f2;
      border-color: #fecaca;
    }

    .login-form {
      display: grid;
      gap: 16px;
    }

    .field {
      display: grid;
      gap: 8px;
    }

    label {
      color: #34465d;
      font-size: 14px;
      font-weight: 800;
    }

    .input-wrap {
      position: relative;
    }

    .input-icon {
      position: absolute;
      left: 15px;
      top: 50%;
      width: 21px;
      height: 21px;
      color: #64748b;
      transform: translateY(-50%);
      pointer-events: none;
    }

    input {
      width: 100%;
      min-height: 54px;
      padding: 14px 46px 14px 48px;
      color: var(--ink);
      font: inherit;
      border: 1px solid var(--line);
      border-radius: 16px;
      outline: none;
      background: rgba(255, 255, 255, .86);
      transition: border-color .18s ease, box-shadow .18s ease, background .18s ease, transform .18s ease;
    }

    input:focus {
      border-color: rgba(14, 165, 164, .7);
      background: #fff;
      box-shadow: 0 0 0 5px rgba(14, 165, 164, .13);
      transform: translateY(-1px);
    }

    .toggle-password {
      position: absolute;
      right: 8px;
      top: 50%;
      width: 40px;
      height: 40px;
      display: grid;
      place-items: center;
      border: 0;
      border-radius: 12px;
      color: #64748b;
      background: transparent;
      cursor: pointer;
      transform: translateY(-50%);
      transition: background .18s ease, color .18s ease;
    }

    .toggle-password:hover {
      color: var(--blue);
      background: rgba(37, 99, 235, .08);
    }

    .toggle-password svg {
      width: 21px;
      height: 21px;
    }

    .submit-btn {
      position: relative;
      min-height: 56px;
      margin-top: 6px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      color: #fff;
      font: inherit;
      font-weight: 800;
      border: 0;
      border-radius: 17px;
      cursor: pointer;
      background: linear-gradient(135deg, var(--green), var(--blue));
      box-shadow: 0 16px 32px rgba(37, 99, 235, .24);
      transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
    }

    .submit-btn:hover {
      transform: translateY(-2px);
      filter: saturate(1.08);
      box-shadow: 0 20px 38px rgba(37, 99, 235, .30);
    }

    .submit-btn:active {
      transform: translateY(0);
    }

    .submit-btn svg {
      width: 21px;
      height: 21px;
    }

    .credit {
      margin-top: 26px;
      padding-top: 20px;
      border-top: 1px solid var(--line);
      color: var(--muted);
      font-size: 13px;
      line-height: 1.75;
    }

    .credit-row {
      display: grid;
      grid-template-columns: 28px 1fr;
      gap: 10px;
      align-items: start;
      margin-top: 10px;
    }

    .credit-row:first-child {
      margin-top: 0;
    }

    .credit-icon {
      width: 28px;
      height: 28px;
      display: grid;
      place-items: center;
      border-radius: 10px;
      color: var(--teal);
      background: rgba(14, 165, 164, .1);
    }

    .credit-icon svg {
      width: 17px;
      height: 17px;
    }

    .credit strong {
      color: var(--ink);
      font-weight: 800;
    }

    .credit small {
      display: block;
      color: var(--muted);
      font-size: 12px;
    }

    @media (max-width: 880px) {
      .page {
        padding: 18px;
      }

      .login-shell {
        grid-template-columns: 1fr;
        border-radius: 24px;
      }

      .brand-panel {
        min-height: auto;
        padding: 30px;
      }

      .brand-mark {
        width: 72px;
        height: 72px;
        margin-top: 30px;
        border-radius: 22px;
      }

      .brand-mark svg {
        width: 40px;
        height: 40px;
      }

      .brand-panel::after {
        display: none;
      }

      .stats {
        margin-top: 28px;
      }

      .form-panel {
        padding: 30px;
      }
    }

    @media (max-width: 560px) {
      .page {
        padding: 12px;
      }

      .brand-panel,
      .form-panel {
        padding: 24px 20px;
      }

      .stats {
        grid-template-columns: 1fr;
      }

      h1 {
        font-size: 32px;
      }

      h2 {
        font-size: 26px;
      }

      input {
        min-height: 52px;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      *,
      *::before,
      *::after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
      }
    }
  </style>
</head>

<body>
  <main class="page">
    <section class="login-shell" aria-label="เข้าสู่ระบบเบิกยา CUP สันกำแพง">
      <div class="brand-panel">
        <div class="brand-content">
          <div class="badge">
            <span class="badge-dot"></span>
            CUP สันกำแพง
          </div>

          <div class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M32 8c9.39 0 17 7.61 17 17v14c0 9.39-7.61 17-17 17s-17-7.61-17-17V25C15 15.61 22.61 8 32 8Z" fill="rgba(255,255,255,.22)" stroke="white" stroke-width="3"/>
              <path d="M23 32h18M32 23v18" stroke="white" stroke-width="5" stroke-linecap="round"/>
              <path d="M20 48h24" stroke="rgba(255,255,255,.75)" stroke-width="3" stroke-linecap="round"/>
            </svg>
          </div>

          <h1>ระบบเบิกยา CUP สันกำแพง</h1>
          <p class="lead">
            ระบบจัดการการเบิกยาและติดตามรายการเบิก สำหรับหน่วยบริการในเครือข่ายอำเภอสันกำแพง จังหวัดเชียงใหม่
          </p>

          <div class="stats" aria-label="จุดเด่นของระบบ">
            <div class="stat">
              <strong>Login</strong>
              <span>เข้าใช้งานด้วยบัญชีผู้ใช้ของหน่วยบริการ</span>
            </div>
            <div class="stat">
              <strong>CUP</strong>
              <span>รองรับการทำงานของเครือข่ายหน่วยบริการ</span>
            </div>
            <div class="stat">
              <strong>Track</strong>
              <span>ติดตามสถานะรายการเบิกได้เป็นระบบ</span>
            </div>
          </div>
        </div>
      </div>

      <div class="form-panel">
        <div class="form-top">
          <p class="kicker">เข้าสู่ระบบ</p>
          <h2>ยินดีต้อนรับ</h2>
          <p class="sub">กรอกชื่อผู้ใช้และรหัสผ่านเพื่อเข้าใช้งานระบบเบิกยา</p>
        </div>

        <?php if($flash): ?>
          <div class="alert alert-success" role="status">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span><?php echo e($flash); ?></span>
          </div>
        <?php endif; ?>

        <?php if($error): ?>
          <div class="alert alert-error" role="alert">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M12 8v5m0 4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span><?php echo e($error); ?></span>
          </div>
        <?php endif; ?>

        <form method="post" class="login-form">
          <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

          <div class="field">
            <label for="username">ชื่อผู้ใช้</label>
            <div class="input-wrap">
              <svg class="input-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              <input id="username" name="username" autocomplete="username" required>
            </div>
          </div>

          <div class="field">
            <label for="password">รหัสผ่าน</label>
            <div class="input-wrap">
              <svg class="input-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M7 11V8a5 5 0 0 1 10 0v3M6 11h12a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              <input id="password" type="password" name="password" autocomplete="current-password" required>
              <button class="toggle-password" type="button" aria-label="แสดงรหัสผ่าน" aria-pressed="false" data-toggle-password>
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </div>
          </div>

          <button class="submit-btn" type="submit">
            เข้าสู่ระบบ
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
        </form>

        <p style="margin:18px 0 0;text-align:center;color:var(--muted);font-size:14px">
          ยังไม่มีบัญชี?
          <a href="signup.php" style="color:var(--blue);font-weight:800;text-decoration:none">สมัครสมาชิกใหม่</a>
        </p>

        <footer class="credit">
          <div class="credit-row">
            <span class="credit-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none">
                <path d="M16 21v-2a4 4 0 0 0-8 0v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-1 2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </span>
            <span>
              <strong>ภก.ชุตติภัทร เงินอินต๊ะ</strong>
              <small>ผู้รับผิดชอบงาน CUP สันกำแพง</small>
            </span>
          </div>
          <div class="credit-row">
            <span class="credit-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none">
                <path d="m16 18 6-6-6-6M8 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </span>
            <span>
              <strong>ผู้พัฒนา ไชยา บุญทานุช</strong>
              <small>จพง.เภสัชกรรมปฏิบัติงาน</small>
            </span>
          </div>
        </footer>
      </div>
    </section>
  </main>

  <script>
    const togglePassword = document.querySelector('[data-toggle-password]');
    const passwordInput = document.getElementById('password');

    if (togglePassword && passwordInput) {
      togglePassword.addEventListener('click', () => {
        const isHidden = passwordInput.type === 'password';
        passwordInput.type = isHidden ? 'text' : 'password';
        togglePassword.setAttribute('aria-pressed', String(isHidden));
        togglePassword.setAttribute('aria-label', isHidden ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
      });
    }
  </script>

</body>
</html>
