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
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script src="https://cdn.tailwindcss.com"></script><title>All Submitted Withdrawals</title></head>
<body class="bg-slate-50">
  <?php include __DIR__ . '/includes/nav.php'; ?>
  <div class="max-w-6xl mx-auto">
    <div class="mb-4"><h1 class="text-xl font-semibold">ใบเบิกรออนุมัติในขอบเขตของคุณ</h1><p class="text-sm text-slate-500 mt-1"><?= e($scopeSummary['label']) ?> · <?= (int)$scopeSummary['facility_count'] ?> สถานบริการ</p></div>
    <div class="bg-slate-100 p-4 rounded shadow">
      <form method="get" class="mb-4 flex gap-2 items-center">
        <input type="search" name="q" value="<?php echo e($q ?? ''); ?>" placeholder="ค้นหาเลขที่ หรือ ชื่อ รพ.สต." class="border rounded px-3 py-1 text-sm w-64">
        <button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded text-sm">ค้นหา</button>
        <a href="admin_all_withdrawals.php" class="ml-2 text-sm text-gray-500">ล้าง</a>
      </form>
      <div class="space-y-3">
        <?php foreach($rows as $r): ?>
          <div onclick="window.location='view_withdrawal.php?id=<?php echo $r['id']; ?>'" class="p-3 bg-white rounded-md shadow-sm hover:shadow-md flex items-center justify-between border-l-4 border-sky-400 cursor-pointer">
            <div>
              <div class="font-medium"><?php echo e(format_withdraw_code($r['withdraw_no'])); ?> — <?php echo e($r['facility_name'] ?? ($r['username'] . ' / ' . $r['name'])); ?></div>
              <div class="mt-1 text-xs text-slate-500">สร้างโดย: <?php echo e($r['name']); ?> · Username: <?php echo e($r['username']); ?> · สถานบริการ: <?php echo e($r['facility_name']); ?> · <?php echo e(format_thai_datetime($r['created_at'])); ?></div>
              <div class="text-xs text-gray-500"><?php echo $r['item_count']; ?> รายการ • <?php echo e($r['created_at']); ?></div>
            </div>
            <div class="text-sm">
              <a href="view_withdrawal.php?id=<?php echo $r['id']; ?>" class="text-blue-600">ดู</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
