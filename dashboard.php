<?php
require 'includes/db.php';
require 'includes/auth.php';
$user = require_login($pdo);

/* All withdrawal mutations are handled by view_withdrawal.php, where the
 * current status is locked, validated and audited. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  http_response_code(405);
  header('Allow: GET');
  exit('Method not allowed');
}

/* ===== SEARCH ===== */
$q = trim($_GET['q'] ?? '');
$scope = host_scope_condition($user, 'w.host_code');
$where = $scope['sql'];
$params = $scope['params'];

if ($q !== '') {
  if (ctype_digit($q)) {
    $where .= ' AND w.withdraw_no=?';
    $params[] = (int)$q;
  } else {
    $where .= ' AND (u.facility_name LIKE ? OR u.name LIKE ? OR u.username LIKE ?)';
    $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%";
  }
}

/* ===== LOAD DATA ===== */
$statuses = ['draft','submitted','approved'];
$withdrawals = ['draft'=>[],'submitted'=>[],'approved'=>[]];
$counts = ['draft'=>0,'submitted'=>0,'approved'=>0];

foreach ($statuses as $status) {
  $stmtC = $pdo->prepare("
    SELECT COUNT(*) FROM withdrawals w
    JOIN users u ON u.id=w.user_id
    WHERE $where AND w.status=?
  ");
  $stmtC->execute(array_merge($params,[$status]));
  $counts[$status] = (int)$stmtC->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT w.id,w.withdraw_no,w.created_at,u.facility_name,u.name AS user_name,u.username
    FROM withdrawals w
    JOIN users u ON u.id=w.user_id
    WHERE $where AND w.status=?
    ORDER BY w.created_at DESC
  ");
  $stmt->execute(array_merge($params,[$status]));
  $withdrawals[$status] = $stmt->fetchAll();
}
$scopeSummary = user_scope_summary($pdo, $user);
$scopeWarnings = ['facilities' => 0, 'admins' => 0];
if (is_superadmin($user)) {
  $scopeWarnings['facilities'] = (int)$pdo->query('SELECT COUNT(*) FROM facilities WHERE zone_id IS NULL')->fetchColumn();
  $scopeWarnings['admins'] = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE u.role='admin' AND NOT EXISTS (SELECT 1 FROM user_zone_assignments uza WHERE uza.user_id=u.id)")->fetchColumn();
}

/* Presentation-only dashboard context; no query or permission semantics change. */
$isManager = is_manager($user);
$taskStatus = $isManager ? 'submitted' : 'draft';
$taskTitle = $isManager ? 'ใบเบิกที่รอตรวจสอบ' : 'ใบเบิกร่างที่ต้องดำเนินการต่อ';
$taskNote = $isManager
  ? 'รายการที่ส่งมาแล้วและอยู่ในขอบเขตที่คุณรับผิดชอบ'
  : 'ตรวจสอบรายการร่างของสถานบริการ ก่อนส่งเพื่อขออนุมัติ';
$statusMeta = [
  'draft' => ['title'=>'ใบเบิกร่าง', 'desc'=>'รายการที่ยังไม่ส่งอนุมัติ'],
  'submitted' => ['title'=>'รออนุมัติ', 'desc'=>'รายการที่กำลังรอตรวจสอบ'],
  'approved' => ['title'=>'อนุมัติแล้ว', 'desc'=>'รายการที่ผ่านการอนุมัติ'],
];
?>

<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard | ระบบเบิกยา CUP สันกำแพง</title>
<link rel="stylesheet" href="assets/css/app.css">
<?php define('DRUG_WITHDRAW_APP_STYLES', true); ?>
</head>

<body class="app-page">
<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="app-ui app-container">
  <section class="app-card dashboard-header" aria-labelledby="dashboard-title">
    <div>
      <p class="dashboard-kicker">ระบบเบิกยา CUP สันกำแพง</p>
      <h1 id="dashboard-title" class="dashboard-title">สวัสดี <?= e($user['name'] ?? $user['username']) ?></h1>
      <p class="dashboard-subtitle">ภาพรวมใบเบิกและงานที่ต้องดำเนินการในขอบเขตของคุณ</p>
      <div class="dashboard-context" aria-label="ข้อมูลผู้ใช้และขอบเขต">
        <span class="dashboard-context__item"><?= e(role_label((string)$user['role'])) ?></span>
        <span class="dashboard-context__item"><?= e($scopeSummary['label']) ?></span>
        <span class="dashboard-context__item"><?= (int)$scopeSummary['facility_count'] ?> สถานบริการ</span>
      </div>
    </div>
    <div class="dashboard-actions" aria-label="ทางลัดการทำงาน">
      <a href="withdraw.php" class="app-btn app-btn--primary">สร้างใบเบิกใหม่</a>
      <?php if ($isManager): ?>
        <a href="admin_all_withdrawals.php" class="app-btn app-btn--action">ตรวจใบเบิกรออนุมัติ (<?= (int)$counts['submitted'] ?>)</a>
      <?php endif; ?>
    </div>
  </section>

  <?php if (is_zone_admin($user) && !$scopeSummary['zones']): ?>
    <div class="app-alert app-alert--warning">บัญชีนี้ยังไม่ได้รับการกำหนดเขต จึงไม่แสดงข้อมูลใด ๆ กรุณาติดต่อผู้ดูแลระบบส่วนกลาง</div>
  <?php elseif (is_superadmin($user) && ($scopeWarnings['facilities'] || $scopeWarnings['admins'])): ?>
    <div class="app-alert app-alert--warning">พบสถานบริการยังไม่อยู่ในเขต <?= $scopeWarnings['facilities'] ?> แห่ง และผู้ดูแลยังไม่มีขอบเขต <?= $scopeWarnings['admins'] ?> บัญชี — <a href="admin_zones.php">ไปจัดเขตบริการ</a></div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="app-alert">
      <?= e($_SESSION['flash']); unset($_SESSION['flash']); ?>
    </div>
  <?php endif; ?>

  <section class="app-card dashboard-search" aria-label="ค้นหาใบเบิก">
    <form method="get" class="dashboard-search__form">
      <label for="dashboard-search" class="dashboard-search__label">เลขที่ใบเบิก สถานบริการ หรือผู้สร้าง</label>
      <input id="dashboard-search" type="search" name="q" value="<?= e($q) ?>" class="app-field" placeholder="ค้นหาเลขที่ใบเบิก สถานบริการ หรือผู้สร้าง">
      <button class="app-btn app-btn--action" type="submit">ค้นหา</button>
      <a href="dashboard.php" class="app-btn app-btn--secondary">ล้าง</a>
    </form>
  </section>

  <section class="dashboard-stats" aria-label="สรุปสถานะใบเบิก">
    <?php foreach ($statuses as $st): $meta = $statusMeta[$st]; ?>
      <button type="button" class="dashboard-stat dashboard-stat--<?= e($st) ?>" data-app-modal-open="<?= e($st) ?>" aria-label="เปิดรายการ<?= e($meta['title']) ?>">
        <span class="dashboard-stat__label"><span class="dashboard-stat__dot" aria-hidden="true"></span><?= e($meta['title']) ?></span>
        <span class="dashboard-stat__count"><?= (int)$counts[$st] ?></span>
        <span class="dashboard-stat__desc"><?= e($meta['desc']) ?></span>
        <span class="dashboard-stat__action">ดูรายการ →</span>
      </button>
    <?php endforeach; ?>
  </section>

  <section class="app-card dashboard-work" aria-labelledby="work-title">
    <div class="dashboard-section-head">
      <div>
        <h2 id="work-title" class="dashboard-section-title"><?= e($taskTitle) ?></h2>
        <div class="dashboard-section-note"><?= e($taskNote) ?> · <?= (int)$counts[$taskStatus] ?> รายการ</div>
      </div>
    </div>

    <?php if (empty($withdrawals[$taskStatus])): ?>
      <div class="app-empty">ไม่มีงานค้างในขณะนี้</div>
    <?php else: ?>
      <div class="dashboard-work-list">
        <?php foreach ($withdrawals[$taskStatus] as $w): ?>
          <a href="view_withdrawal.php?id=<?= (int)$w['id'] ?>" class="dashboard-work-row">
            <div class="dashboard-work-row__main">
              <div class="dashboard-work-row__code"><?= e(format_withdraw_code($w['withdraw_no'])) ?></div>
              <div class="dashboard-work-row__place">สถานบริการ: <?= e($w['facility_name']) ?></div>
              <div class="dashboard-work-row__meta">สร้างโดย: <?= e($w['user_name']) ?> · Username: <?= e($w['username']) ?></div>
            </div>
            <div class="dashboard-work-row__date"><?= e(format_thai_datetime($w['created_at'])) ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php foreach ($statuses as $st): $meta = $statusMeta[$st]; ?>
    <div id="modal-<?= e($st) ?>" class="app-modal" aria-hidden="true">
      <section class="app-modal__card" role="dialog" aria-modal="true" aria-labelledby="modal-title-<?= e($st) ?>">
        <div class="app-modal__head">
          <div>
            <h2 id="modal-title-<?= e($st) ?>" class="app-modal__title">รายการ<?= e($meta['title']) ?></h2>
            <div class="dashboard-section-note">ทั้งหมด <?= (int)$counts[$st] ?> รายการ</div>
          </div>
          <button type="button" class="app-modal__close" aria-label="ปิด" data-app-modal-close="<?= e($st) ?>">&times;</button>
        </div>
        <div class="app-modal__body">
          <?php if (empty($withdrawals[$st])): ?>
            <div class="app-empty">ไม่มีข้อมูล</div>
          <?php else: ?>
            <div class="dashboard-work-list">
              <?php foreach ($withdrawals[$st] as $w): ?>
                <a href="view_withdrawal.php?id=<?= (int)$w['id'] ?>" class="dashboard-work-row">
                  <div class="dashboard-work-row__main">
                    <div class="dashboard-work-row__code"><?= e(format_withdraw_code($w['withdraw_no'])) ?></div>
                    <div class="dashboard-work-row__place">สถานบริการ: <?= e($w['facility_name']) ?></div>
                    <div class="dashboard-work-row__meta">สร้างโดย: <?= e($w['user_name']) ?> · Username: <?= e($w['username']) ?></div>
                  </div>
                  <div class="dashboard-work-row__date"><?= e(format_thai_datetime($w['created_at'])) ?></div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>
  <?php endforeach; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
