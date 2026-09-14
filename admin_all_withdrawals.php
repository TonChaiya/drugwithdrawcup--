<?php
require 'includes/db.php';
require 'includes/auth.php';
$manager = require_admin($pdo);

// This page is read-only. Mutations go through the confirmed and audited
// workflow in view_withdrawal.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  http_response_code(405);
  header('Allow: GET');
  exit('Method not allowed');
}

$user_filter = trim($_GET['user'] ?? '');
$withdraw_no = trim($_GET['withdraw_no'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$q = trim($_GET['q'] ?? '');

$scope = host_scope_condition($manager, 'w.host_code');
$where = 'w.status = "submitted" AND ' . $scope['sql'];
$params = $scope['params'];
if ($user_filter !== '') { $where .= ' AND u.username LIKE ?'; $params[] = "%$user_filter%"; }
if ($withdraw_no !== '') { $where .= ' AND w.withdraw_no = ?'; $params[] = (int)$withdraw_no; }
if ($from !== '') { $where .= ' AND w.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '') { $where .= ' AND w.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
if ($q !== '') {
  if (ctype_digit($q)) {
    $where .= ' AND w.withdraw_no = ?'; $params[] = (int)$q;
  } else {
    $where .= ' AND (u.facility_name LIKE ? OR u.name LIKE ? OR u.username LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
  }
}

$sql = "SELECT w.id,w.withdraw_no,w.created_at,w.user_id,u.username,u.name,u.facility_name, COUNT(wi.id) as item_count
        FROM withdrawals w
        LEFT JOIN users u ON u.id = w.user_id
        LEFT JOIN withdrawal_items wi ON wi.withdrawal_id = w.id
        WHERE $where
        GROUP BY w.id
        ORDER BY w.created_at DESC
        LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$scopeSummary = user_scope_summary($pdo, $manager);
$hasActiveFilters = $q !== '' || $from !== '' || $to !== '' || $user_filter !== '' || $withdraw_no !== '';
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script src="https://cdn.tailwindcss.com"></script><title>ใบเบิกรออนุมัติ</title></head>
<body class="app-page admin-work-queue">
  <?php include __DIR__ . '/includes/nav.php'; ?>
  <main class="admin-work-queue__main app-ui">
    <header class="admin-work-queue__heading">
      <h1>ใบเบิกรออนุมัติ</h1>
      <p>ขอบเขต: <?= e($scopeSummary['label']) ?> · <?= (int)$scopeSummary['facility_count'] ?> สถานบริการ · <?= count($rows) === 200 ? 'แสดง 200 รายการแรก' : 'แสดง ' . count($rows) . ' ใบรออนุมัติ' ?></p>
    </header>

    <form method="get" action="admin_all_withdrawals.php" class="admin-work-queue__filters" aria-label="ค้นหาใบเบิกรออนุมัติ">
      <div class="admin-work-queue__filter admin-work-queue__filter--query">
        <label for="queue-query">เลขใบเบิก / สถานบริการ / ผู้เบิก</label>
        <input id="queue-query" type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาเลขใบเบิก รพ.สต. หรือผู้เบิก" autocomplete="off">
      </div>
      <div class="admin-work-queue__filter">
        <label for="queue-from">จากวันที่</label>
        <input id="queue-from" type="date" name="from" value="<?= e($from) ?>">
      </div>
      <div class="admin-work-queue__filter">
        <label for="queue-to">ถึงวันที่</label>
        <input id="queue-to" type="date" name="to" value="<?= e($to) ?>">
      </div>
      <div class="admin-work-queue__filter-actions">
        <button type="submit" class="admin-work-queue__search-button">ค้นหา</button>
        <?php if ($hasActiveFilters): ?><a href="admin_all_withdrawals.php" class="admin-work-queue__clear">ล้างตัวกรอง</a><?php endif; ?>
      </div>
    </form>

    <section class="admin-work-queue__list" aria-label="คิวใบเบิกรออนุมัติ">
      <?php if (!$rows): ?>
        <p class="admin-work-queue__empty">ไม่พบใบเบิกรออนุมัติในเงื่อนไขที่เลือก<?php if ($hasActiveFilters): ?> · <a href="admin_all_withdrawals.php">ล้างตัวกรอง</a><?php endif; ?></p>
      <?php else: ?>
        <table class="admin-work-queue__table">
          <thead><tr>
            <th scope="col">เลขใบเบิก</th>
            <th scope="col">สถานบริการ</th>
            <th scope="col">ผู้เบิก</th>
            <th scope="col">วันที่สร้าง</th>
            <th scope="col">จำนวนรายการ</th>
            <th scope="col">ดำเนินการ</th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="admin-work-queue__code"><?= e(format_withdraw_code($r['withdraw_no'])) ?></td>
                <td class="admin-work-queue__facility"><?= e($r['facility_name'] ?? ($r['username'] . ' / ' . $r['name'])) ?></td>
                <td class="admin-work-queue__user"><?= e($r['name']) ?><small><?= e($r['username']) ?></small></td>
                <td class="admin-work-queue__date"><?= e(format_thai_datetime($r['created_at'])) ?></td>
                <td class="admin-work-queue__count"><?= (int)$r['item_count'] ?> รายการ</td>
                <td class="admin-work-queue__action"><a href="view_withdrawal.php?id=<?= (int)$r['id'] ?>">ตรวจใบเบิก <span aria-hidden="true">→</span></a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </main>
  <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
