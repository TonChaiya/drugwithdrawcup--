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
?>

<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard | ระบบเบิกยา CUP สันกำแพง</title>
<style>
  :root {
    --ink: #172033;
    --muted: #64748b;
    --line: rgba(15, 23, 42, .10);
    --line-soft: rgba(15, 23, 42, .07);
    --surface: rgba(255, 255, 255, .76);
    --surface-strong: rgba(255, 255, 255, .90);
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

  body {
    min-height: 100vh;
    color: var(--ink);
    font-family: var(--font-main);
    font-feature-settings: "kern";
    background:
      radial-gradient(circle at 10% 10%, rgba(14, 165, 164, .13), transparent 26%),
      radial-gradient(circle at 90% 16%, rgba(37, 99, 235, .12), transparent 28%),
      linear-gradient(135deg, #f8fafc 0%, #eef7fb 48%, #f6f8fb 100%);
    background-attachment: fixed;
  }

  a {
    color: inherit;
    text-decoration: none;
  }

  .dash-wrap {
    width: min(1180px, calc(100% - 32px));
    margin: 0 auto;
    padding: 14px 0 20px;
  }

  .dashboard-panel,
  .modal-card {
    border: 1px solid var(--line-soft);
    background: var(--surface);
    box-shadow: var(--shadow);
    backdrop-filter: blur(18px);
  }

  .dashboard-panel {
    border-radius: var(--radius);
    overflow: hidden;
  }

  .hero-card {
    display: grid;
    grid-template-columns: 1fr;
    gap: 14px;
    align-items: center;
    padding: 18px 18px 16px;
    border-bottom: 1px solid var(--line-soft);
  }

  .hero-title {
    margin: 0;
    font-size: 23px;
    line-height: 1.25;
    letter-spacing: 0;
    font-weight: 900;
  }

  .hero-sub {
    margin-top: 4px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.65;
  }

  .soft-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 38px;
    padding: 0 12px;
    border-radius: var(--radius-sm);
    color: #0f766e;
    background: rgba(20, 184, 166, .10);
    border: 1px solid rgba(20, 184, 166, .18);
    font-size: 13px;
    font-weight: 800;
    transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
  }

  .hero-links {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
  }

  .hero-meta {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #64748b;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
  }

  .hero-meta::before {
    content: "";
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: #14b8a6;
    box-shadow: 0 0 0 4px rgba(20, 184, 166, .12);
  }

  .soft-btn:hover {
    transform: translateY(-1px);
    background: rgba(20, 184, 166, .16);
    box-shadow: 0 8px 18px rgba(15, 118, 110, .10);
  }

  .search-card {
    padding: 14px 18px;
    border-bottom: 1px solid var(--line-soft);
  }

  .search-form {
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 10px;
    align-items: center;
  }

  .search-input {
    min-height: 46px;
    width: 100%;
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    padding: 0 16px;
    color: var(--ink);
    background: var(--surface-strong);
    outline: none;
    font: inherit;
    transition: box-shadow .18s ease, border-color .18s ease;
  }

  .search-input:focus {
    border-color: rgba(14, 165, 164, .65);
    box-shadow: 0 0 0 5px rgba(14, 165, 164, .12);
  }

  .primary-btn,
  .ghost-btn {
    min-height: 46px;
    border-radius: var(--radius-sm);
    padding: 0 18px;
    font-size: 14px;
    font-weight: 800;
    font-family: var(--font-main);
  }

  .primary-btn {
    color: #fff;
    background: linear-gradient(135deg, #0f9f7a, #2563eb);
    box-shadow: 0 10px 22px rgba(37, 99, 235, .14);
  }

  .ghost-btn {
    display: inline-flex;
    align-items: center;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid var(--line);
  }

  .flash {
    margin: 14px 18px 0;
    padding: 12px 14px;
    border-radius: var(--radius);
    color: #047857;
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
    font-size: 14px;
    line-height: 1.65;
  }

  .status-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 0;
    padding: 16px 18px;
    border-top: 0;
    border-bottom: 1px solid var(--line-soft);
  }

  .status-card {
    position: relative;
    min-height: 132px;
    overflow: hidden;
    padding: 18px;
    border: 1px solid var(--line-soft);
    border-radius: var(--radius);
    background: rgba(255, 255, 255, .56);
    box-shadow: none;
    text-align: left;
    cursor: pointer;
    transition: background .18s ease, box-shadow .18s ease, border-color .18s ease, transform .18s ease;
  }

  .status-card:hover {
    background: rgba(255, 255, 255, .70);
    border-color: var(--tone);
    box-shadow: inset 0 3px 0 var(--tone);
    transform: translateY(-2px);
  }

  .status-card:focus-visible {
    outline: 3px solid rgba(14, 165, 164, .22);
    outline-offset: 2px;
  }

  .status-card::after {
    content: "";
    position: absolute;
    right: -34px;
    top: -34px;
    width: 112px;
    height: 112px;
    border-radius: 999px;
    background: var(--tone);
    opacity: .14;
  }

  .status-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 30px;
    padding: 0 11px;
    border-radius: 999px;
    color: var(--tone-dark);
    background: var(--tone-soft);
    font-size: 12px;
    font-weight: 800;
    line-height: 1.35;
  }

  .status-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: var(--tone);
  }

  .status-count {
    margin-top: 18px;
    font-size: 42px;
    line-height: 1;
    font-weight: 900;
    color: var(--ink);
  }

  .status-desc {
    margin-top: 8px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.5;
  }

  .status-action {
    position: absolute;
    right: 16px;
    bottom: 15px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: var(--tone-dark);
    font-size: 12px;
    font-weight: 800;
    line-height: 1.4;
    opacity: .76;
  }

  .status-action svg {
    width: 15px;
    height: 15px;
    transition: transform .18s ease;
  }

  .status-card:hover .status-action svg {
    transform: translateX(2px);
  }

  .queue-card {
    margin-top: 0;
    padding: 18px;
    border-radius: 0;
  }

  .section-head {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--line-soft);
  }

  .section-title {
    margin: 0;
    font-size: 17px;
    font-weight: 900;
    letter-spacing: 0;
  }

  .section-note {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.5;
  }

  .scroll-area {
    max-height: 420px;
    overflow: auto;
    padding-right: 6px;
    scrollbar-width: thin;
    scrollbar-color: rgba(14, 165, 164, .45) rgba(226, 232, 240, .7);
  }

  .scroll-area::-webkit-scrollbar {
    width: 9px;
  }

  .scroll-area::-webkit-scrollbar-track {
    background: rgba(226, 232, 240, .7);
    border-radius: 999px;
  }

  .scroll-area::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #14b8a6, #2563eb);
    border-radius: 999px;
  }

  .work-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 13px 14px;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    color: inherit;
    transition: background .18s ease, border-color .18s ease, transform .18s ease;
  }

  .work-row + .work-row {
    margin-top: 8px;
  }

  .work-row:hover {
    background: rgba(14, 165, 164, .07);
    border-color: rgba(14, 165, 164, .18);
    transform: translateX(2px);
  }

  .work-code {
    font-weight: 900;
    color: #1e293b;
    line-height: 1.35;
  }

  .work-place {
    margin-top: 3px;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.5;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .work-meta {
    margin-top: 3px;
    color: #7c8ca0;
    font-size: 11px;
    line-height: 1.55;
  }

  .work-date {
    color: #94a3b8;
    font-size: 12px;
    line-height: 1.5;
    white-space: nowrap;
  }

  .empty-state {
    display: grid;
    place-items: center;
    min-height: 150px;
    border: 1px dashed rgba(100, 116, 139, .28);
    border-radius: var(--radius);
    color: #94a3b8;
    background: rgba(248, 250, 252, .72);
    font-size: 14px;
    line-height: 1.6;
  }

  .modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, .54);
    backdrop-filter: blur(8px);
  }

  .modal-backdrop.is-open {
    display: flex;
  }

  .modal-card {
    width: min(760px, calc(100% - 28px));
    max-height: min(82vh, 760px);
    border-radius: var(--radius);
    overflow: hidden;
    background: rgba(255, 255, 255, .96);
    box-shadow: 0 28px 72px rgba(15, 23, 42, .24);
  }

  .modal-head {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    align-items: center;
    padding: 16px 18px;
    border-bottom: 1px solid var(--line-soft);
  }

  .modal-title {
    margin: 0;
    font-size: 17px;
    font-weight: 900;
    letter-spacing: 0;
  }

  .modal-body {
    padding: 12px;
    max-height: calc(min(82vh, 760px) - 72px);
    overflow: auto;
    background: linear-gradient(180deg, rgba(248, 250, 252, .65), rgba(255, 255, 255, .92));
    scrollbar-width: thin;
    scrollbar-color: rgba(14, 165, 164, .45) rgba(226, 232, 240, .75);
  }

  .modal-body::-webkit-scrollbar {
    width: 9px;
  }

  .modal-body::-webkit-scrollbar-track {
    background: rgba(226, 232, 240, .75);
    border-radius: 999px;
  }

  .modal-body::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #14b8a6, #2563eb);
    border-radius: 999px;
  }

  .modal-body .scroll-area {
    max-height: 56vh;
    padding: 8px;
    border: 1px solid var(--line-soft);
    border-radius: var(--radius);
    background: rgba(255, 255, 255, .72);
  }

  .modal-body .work-row {
    border-color: var(--line-soft);
    background: rgba(255, 255, 255, .86);
  }

  .modal-body .work-row:hover {
    background: #f0fdfa;
    border-color: rgba(14, 165, 164, .26);
  }

  .close-btn {
    width: 38px;
    height: 38px;
    border-radius: 13px;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid var(--line);
    font-size: 24px;
    line-height: 1;
    transition: color .18s ease, background .18s ease;
  }

  .close-btn:hover {
    color: #dc2626;
    background: #fef2f2;
  }

  @media (max-width: 820px) {
    .hero-card,
    .search-form {
      grid-template-columns: 1fr;
    }

    .hero-links {
      justify-content: flex-start;
    }

    .status-grid {
      grid-template-columns: 1fr;
    }

    .status-card {
      min-height: 126px;
    }
  }

  @media (max-width: 560px) {
    .dash-wrap {
      width: min(100% - 20px, 1180px);
      padding-top: 14px;
    }

    .hero-card {
      padding: 14px;
      border-radius: 0;
    }

    .search-card,
    .queue-card {
      padding: 14px;
    }

    .hero-title {
      font-size: 21px;
    }

    .work-row {
      grid-template-columns: 1fr;
      gap: 4px;
    }

    .work-date {
      white-space: normal;
    }
  }
</style>
</head>

<body>

<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="dash-wrap">
  <section class="dashboard-panel">
    <div class="hero-card">
      <div>
        <div class="hero-meta">ระบบเบิกยา CUP สันกำแพง</div>
        <h1 class="hero-title">ภาพรวมใบเบิกยา</h1>
        <p class="hero-sub">ผู้ใช้งาน: <?php echo e($user['name'] ?? $user['username']); ?> · <?= e(role_label((string)$user['role'])) ?></p>
        <p class="hero-sub"><strong>ขอบเขตข้อมูล:</strong> <?= e($scopeSummary['label']) ?> · <?= (int)$scopeSummary['facility_count'] ?> สถานบริการ</p>
      </div>

    </div>

    <?php if (is_zone_admin($user) && !$scopeSummary['zones']): ?>
      <div class="flash" style="background:#fff7ed;color:#9a3412;border-color:#fed7aa">บัญชีนี้ยังไม่ได้รับการกำหนดเขต จึงไม่แสดงข้อมูลใด ๆ กรุณาติดต่อผู้ดูแลระบบส่วนกลาง</div>
    <?php elseif (is_superadmin($user) && ($scopeWarnings['facilities'] || $scopeWarnings['admins'])): ?>
      <div class="flash" style="background:#fff7ed;color:#9a3412;border-color:#fed7aa">พบสถานบริการยังไม่อยู่ในเขต <?= $scopeWarnings['facilities'] ?> แห่ง และผู้ดูแลยังไม่มีขอบเขต <?= $scopeWarnings['admins'] ?> บัญชี — <a href="admin_zones.php" style="font-weight:900;text-decoration:underline">ไปจัดเขตบริการ</a></div>
    <?php endif; ?>

    <?php if(!empty($_SESSION['flash'])): ?>
    <div class="flash">
      <?php echo e($_SESSION['flash']); unset($_SESSION['flash']); ?>
    </div>
    <?php endif; ?>

    <div class="search-card">
      <form method="get" class="search-form">
        <input type="search" name="q" value="<?php echo e($q); ?>"
          class="search-input"
          placeholder="ค้นหาเลขที่ใบเบิก หรือ หน่วยบริการ">
        <button class="primary-btn" type="submit">ค้นหา</button>
        <a href="dashboard.php" class="ghost-btn">ล้าง</a>
      </form>
    </div>

    <div class="status-grid" aria-label="สรุปสถานะใบเบิก">
      <?php
        $statusMeta = [
          'draft' => ['title'=>'ใบเบิกร่าง', 'desc'=>'รายการที่ยังไม่ส่งอนุมัติ', 'tone'=>'#f59e0b', 'dark'=>'#b45309', 'soft'=>'rgba(245,158,11,.13)'],
          'submitted' => ['title'=>'รออนุมัติ', 'desc'=>'รายการที่กำลังรอตรวจสอบ', 'tone'=>'#0ea5e9', 'dark'=>'#0369a1', 'soft'=>'rgba(14,165,233,.13)'],
          'approved' => ['title'=>'อนุมัติแล้ว', 'desc'=>'รายการที่ผ่านการอนุมัติ', 'tone'=>'#10b981', 'dark'=>'#047857', 'soft'=>'rgba(16,185,129,.13)'],
        ];
      ?>

      <?php foreach($statuses as $st): $meta = $statusMeta[$st]; ?>
        <button type="button" onclick="openModal('<?php echo $st; ?>')" class="status-card"
          style="--tone: <?php echo $meta['tone']; ?>; --tone-dark: <?php echo $meta['dark']; ?>; --tone-soft: <?php echo $meta['soft']; ?>;"
          aria-label="เปิดรายการ<?php echo e($meta['title']); ?>">
        <span class="status-pill"><span class="status-dot"></span><?php echo e($meta['title']); ?></span>
        <div class="status-count"><?php echo (int)$counts[$st]; ?></div>
        <div class="status-desc"><?php echo e($meta['desc']); ?></div>
        <span class="status-action">
          ดูรายการ
          <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M5 12h14m-5-5 5 5-5 5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
      </button>
      <?php endforeach; ?>
    </div>

  <?php foreach($statuses as $st): $meta = $statusMeta[$st]; ?>
  <div id="modal-<?php echo $st; ?>" class="modal-backdrop">
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title-<?php echo $st; ?>">
      <div class="modal-head">
        <div>
          <h2 id="modal-title-<?php echo $st; ?>" class="modal-title">รายการ<?php echo e($meta['title']); ?></h2>
          <div class="section-note">ทั้งหมด <?php echo (int)$counts[$st]; ?> รายการ</div>
        </div>
        <button type="button" onclick="closeModal('<?php echo $st; ?>')" class="close-btn" aria-label="ปิด">&times;</button>
      </div>

      <div class="modal-body">
        <?php if(empty($withdrawals[$st])): ?>
          <div class="empty-state">ไม่มีข้อมูล</div>
        <?php else: ?>
          <div class="scroll-area">
            <?php foreach($withdrawals[$st] as $w): ?>
              <a href="view_withdrawal.php?id=<?php echo $w['id']; ?>" class="work-row">
                <div>
                  <div class="work-code"><?php echo e(format_withdraw_code($w['withdraw_no'])); ?></div>
                  <div class="work-place">สถานบริการ: <?php echo e($w['facility_name']); ?></div>
                  <div class="work-meta">สร้างโดย: <?php echo e($w['user_name']); ?> · Username: <?php echo e($w['username']); ?></div>
                </div>
                <div class="work-date"><?php echo e(format_thai_datetime($w['created_at'])); ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>
  <?php endforeach; ?>

  <section class="queue-card">
    <div class="section-head">
      <div>
        <h2 class="section-title">ใบเบิกรออนุมัติ</h2>
        <div class="section-note">รายการค้างอยู่ <?php echo (int)$counts['submitted']; ?> รายการ</div>
      </div>
    </div>

    <?php if(empty($withdrawals['submitted'])): ?>
      <div class="empty-state">ไม่มีรายการค้าง</div>
    <?php else: ?>
      <div class="scroll-area">
        <?php foreach($withdrawals['submitted'] as $w): ?>
          <a href="view_withdrawal.php?id=<?php echo $w['id']; ?>" class="work-row">
            <div>
              <div class="work-code"><?php echo e(format_withdraw_code($w['withdraw_no'])); ?></div>
              <div class="work-place">สถานบริการ: <?php echo e($w['facility_name']); ?></div>
              <div class="work-meta">สร้างโดย: <?php echo e($w['user_name']); ?> · Username: <?php echo e($w['username']); ?></div>
            </div>
            <div class="work-date"><?php echo e(format_thai_datetime($w['created_at'])); ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  </section>

</main>

<script>
function openModal(id){
  const modal = document.getElementById('modal-'+id);
  if (!modal) return;
  modal.classList.add('is-open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id){
  const modal = document.getElementById('modal-'+id);
  if (!modal) return;
  modal.classList.remove('is-open');
  document.body.style.overflow = '';
}

document.addEventListener('keydown', function(event) {
  if (event.key !== 'Escape') return;
  document.querySelectorAll('[id^="modal-"]').forEach(function(modal) {
    modal.classList.remove('is-open');
  });
  document.body.style.overflow = '';
});

document.querySelectorAll('[id^="modal-"]').forEach(function(modal) {
  modal.addEventListener('click', function(event) {
    if (event.target === modal) {
      closeModal(modal.id.replace('modal-', ''));
    }
  });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
