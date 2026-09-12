<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Prevent duplicate navigation if a page or nested include loads this component twice.
if (defined('DRUG_WITHDRAW_NAV_RENDERED')) {
  return;
}
define('DRUG_WITHDRAW_NAV_RENDERED', true);

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
function nav_is_active(string $needle): bool {
  global $currentPath;
  return str_contains($currentPath, $needle);
}
function nav_active(string $needle): string {
  return nav_is_active($needle) ? ' is-active' : '';
}
function nav_current(string $needle): string {
  return nav_is_active($needle) ? ' aria-current="page"' : '';
}

$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$navAssetPrefix = str_contains($scriptPath, '/report/') ? '../' : '';
$navUser = $_SESSION['user'] ?? null;
$navRole = (string)($navUser['role'] ?? '');
$navCanWithdraw = $navUser && preg_match('/^[0-9]{5}$/', (string)($navUser['host_code'] ?? ''));
$navName = (string)($navUser['name'] ?? $navUser['username'] ?? '');
$navFacility = trim((string)($navUser['facility_name'] ?? ''));
$navRoleLabel = $navUser && function_exists('role_label') ? role_label($navRole) : '';
$navInitial = 'U';
if ($navName !== '' && preg_match('/^./u', $navName, $navInitialMatch)) {
  $navInitial = $navInitialMatch[0];
}
?>

<?php if (!defined('DRUG_WITHDRAW_APP_STYLES')): define('DRUG_WITHDRAW_APP_STYLES', true); ?>
<link rel="stylesheet" href="<?= e($navAssetPrefix) ?>assets/css/app.css">
<?php endif; ?>

<header id="navbar" class="app-shell" data-app-nav>
  <div class="app-shell__inner">
    <a href="<?= BASE_URL ?>dashboard.php" class="app-shell__brand" aria-label="ระบบเบิกยา CUP สันกำแพง — หน้าหลัก">
      <span class="app-shell__brand-mark" aria-hidden="true">
        <svg width="21" height="21" viewBox="0 0 24 24" fill="none">
          <path d="M7 4h8l3 3v13H7V4Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
          <path d="M15 4v4h4M10 12h6M10 16h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </span>
      <span class="app-shell__brand-copy">
        <strong>ระบบเบิกยา</strong>
        <small>CUP สันกำแพง</small>
      </span>
    </a>

    <nav class="app-shell__menu" aria-label="เมนูหลัก">
      <a href="<?= BASE_URL ?>dashboard.php" class="app-shell__link<?= nav_active('/dashboard.php') ?>"<?= nav_current('/dashboard.php') ?>>หน้าหลัก</a>
      <a href="<?= BASE_URL ?>report/report.php" class="app-shell__link<?= nav_active('/report/report.php') ?>"<?= nav_current('/report/report.php') ?>>รายงาน</a>

      <?php if ($navUser): ?>
        <?php if ($navCanWithdraw): ?>
          <a href="<?= BASE_URL ?>withdraw.php" class="app-shell__link<?= nav_active('/withdraw.php') ?>"<?= nav_current('/withdraw.php') ?>>เบิกยา</a>
        <?php endif; ?>

        <?php if (in_array($navRole, ['admin', 'superadmin'], true)): ?>
          <a href="<?= BASE_URL ?>admin_all_withdrawals.php" class="app-shell__link<?= nav_active('/admin_all_withdrawals.php') ?>"<?= nav_current('/admin_all_withdrawals.php') ?>>ตรวจใบเบิก</a>
          <a href="<?= BASE_URL ?>register.php" class="app-shell__link<?= nav_active('/register.php') ?>"<?= nav_current('/register.php') ?>>ผู้ใช้</a>
        <?php endif; ?>
        <?php if ($navRole === 'superadmin'): ?>
          <a href="<?= BASE_URL ?>admin_zones.php" class="app-shell__link<?= nav_active('/admin_zones.php') ?>"<?= nav_current('/admin_zones.php') ?>>เขตบริการ</a>
          <a href="<?= BASE_URL ?>admin_drug_items.php" class="app-shell__link<?= nav_active('/admin_drug_items.php') ?>"<?= nav_current('/admin_drug_items.php') ?>>รายการยา</a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="app-shell__account">
      <?php if ($navUser): ?>
        <span class="app-shell__avatar" aria-hidden="true"><?= e($navInitial) ?></span>
        <span class="app-shell__identity" title="<?= e($navName) ?>">
          <strong><?= e($navName) ?></strong>
          <small><?= e($navRoleLabel) ?><?= $navFacility !== '' ? ' · ' . e($navFacility) : '' ?></small>
        </span>
        <form method="post" action="<?= BASE_URL ?>logout.php" class="app-shell__logout-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <button type="submit" class="app-shell__logout">ออกจากระบบ</button>
        </form>
      <?php else: ?>
        <a href="<?= BASE_URL ?>index.php" class="app-btn app-btn--primary app-btn--compact">เข้าสู่ระบบ</a>
      <?php endif; ?>
    </div>

    <button type="button" class="app-shell__toggle" aria-label="เปิดเมนู" aria-controls="appMobileMenu" aria-expanded="false" data-app-nav-toggle>
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
      </svg>
    </button>
  </div>
</header>

<div id="appMobileMenu" class="app-shell-mobile" aria-hidden="true" data-app-nav-panel>
  <div class="app-shell-mobile__panel" role="dialog" aria-modal="true" aria-label="เมนูหลัก">
    <div class="app-shell-mobile__head">
      <div>
        <strong>เมนูระบบเบิกยา</strong>
        <small>CUP สันกำแพง</small>
      </div>
      <button type="button" class="app-shell-mobile__close" aria-label="ปิดเมนู" data-app-nav-close>&times;</button>
    </div>

    <nav class="app-shell-mobile__links" aria-label="เมนูหลักสำหรับมือถือ">
      <a href="<?= BASE_URL ?>dashboard.php" class="app-shell-mobile__link<?= nav_active('/dashboard.php') ?>"<?= nav_current('/dashboard.php') ?>>หน้าหลัก</a>
      <a href="<?= BASE_URL ?>report/report.php" class="app-shell-mobile__link<?= nav_active('/report/report.php') ?>"<?= nav_current('/report/report.php') ?>>รายงาน</a>

      <?php if ($navUser): ?>
        <?php if ($navCanWithdraw): ?>
          <a href="<?= BASE_URL ?>withdraw.php" class="app-shell-mobile__link<?= nav_active('/withdraw.php') ?>"<?= nav_current('/withdraw.php') ?>>เบิกยา</a>
        <?php endif; ?>

        <?php if (in_array($navRole, ['admin', 'superadmin'], true)): ?>
          <a href="<?= BASE_URL ?>admin_all_withdrawals.php" class="app-shell-mobile__link<?= nav_active('/admin_all_withdrawals.php') ?>"<?= nav_current('/admin_all_withdrawals.php') ?>>ตรวจใบเบิก</a>
          <a href="<?= BASE_URL ?>register.php" class="app-shell-mobile__link<?= nav_active('/register.php') ?>"<?= nav_current('/register.php') ?>>ผู้ใช้</a>
        <?php endif; ?>
        <?php if ($navRole === 'superadmin'): ?>
          <a href="<?= BASE_URL ?>admin_zones.php" class="app-shell-mobile__link<?= nav_active('/admin_zones.php') ?>"<?= nav_current('/admin_zones.php') ?>>เขตบริการ</a>
          <a href="<?= BASE_URL ?>admin_drug_items.php" class="app-shell-mobile__link<?= nav_active('/admin_drug_items.php') ?>"<?= nav_current('/admin_drug_items.php') ?>>รายการยา</a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>

    <div class="app-shell-mobile__account">
      <?php if ($navUser): ?>
        <div class="app-shell-mobile__identity">
          <strong><?= e($navName) ?></strong>
          <small><?= e($navRoleLabel) ?><?= $navFacility !== '' ? ' · ' . e($navFacility) : '' ?></small>
        </div>
        <form method="post" action="<?= BASE_URL ?>logout.php" class="app-shell__logout-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <button type="submit" class="app-shell-mobile__logout">ออกจากระบบ</button>
        </form>
      <?php else: ?>
        <a href="<?= BASE_URL ?>index.php" class="app-btn app-btn--primary">เข้าสู่ระบบ</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="app-shell-spacer" aria-hidden="true"></div>

<?php if (!defined('DRUG_WITHDRAW_APP_SCRIPT')): define('DRUG_WITHDRAW_APP_SCRIPT', true); ?>
<script src="<?= e($navAssetPrefix) ?>assets/js/app.js" defer></script>
<?php endif; ?>
