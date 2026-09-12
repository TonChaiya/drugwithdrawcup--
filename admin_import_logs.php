<?php
require 'includes/db.php';
require 'includes/auth.php';
require_superadmin($pdo);

$logsDir = __DIR__ . '/tool/import_logs';
$files = [];
if (is_dir($logsDir)) {
  $dh = opendir($logsDir);
  while (($f = readdir($dh)) !== false) {
    if ($f === '.' || $f === '..') continue;
    $path = $logsDir . '/' . $f;
    if (is_file($path)) $files[] = $f;
  }
  closedir($dh);
  rsort($files);
}

$view = $_GET['view'] ?? '';
$download = $_GET['dl'] ?? '';
if ($download) {
  $file = basename($download);
  $path = $logsDir . '/' . $file;
  if (is_file($path)) {
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    readfile($path); exit;
  } else { $_SESSION['flash'] = 'ไม่พบไฟล์'; header('Location: admin_import_logs.php'); exit; }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script src="https://cdn.tailwindcss.com"></script><title>Import Logs</title></head>
<body class="bg-slate-50 p-6">
  <?php include __DIR__ . '/includes/nav.php'; ?>
  <div class="max-w-4xl mx-auto">
    <div class="bg-white p-4 rounded shadow mb-4">
      <h1 class="text-lg font-semibold">รายงานการนำเข้า (Import Logs)</h1>
      <p class="text-sm text-gray-600">ไฟล์บันทึกรายละเอียดการนำเข้าจะเก็บไว้ใน `tool/import_logs`</p>
    </div>

    <div class="bg-white p-4 rounded shadow mb-4">
      <h2 class="font-medium mb-2">รายการไฟล์บันทึก</h2>
      <?php if(empty($files)): ?>
        <div class="text-gray-500">ยังไม่มีบันทึกการนำเข้า</div>
      <?php else: ?>
        <ul class="space-y-2">
          <?php foreach($files as $f): ?>
            <li class="flex items-center justify-between border p-2 rounded">
              <div class="text-sm font-mono"><?php echo e($f); ?></div>
              <div class="text-sm">
                <a href="admin_import_logs.php?view=<?php echo urlencode($f); ?>" class="text-blue-600 mr-3">ดู</a>
                <a href="admin_import_logs.php?dl=<?php echo urlencode($f); ?>" class="text-green-600">ดาวน์โหลด</a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <?php if($view):
      $vf = basename($view);
      $path = $logsDir . '/' . $vf;
      if (is_file($path)):
        $maxViewBytes = 1024 * 1024;
        $handle = fopen($path, 'rb');
        $content = $handle ? fread($handle, $maxViewBytes + 1) : false;
        if ($handle) fclose($handle);
        if ($content === false) $content = 'ไม่สามารถอ่านไฟล์ได้';
        if (strlen($content) > $maxViewBytes) $content = substr($content, 0, $maxViewBytes) . "\n\n[ตัดการแสดงผลที่ 1 MB กรุณาดาวน์โหลดไฟล์เพื่อดูทั้งหมด]";
    ?>
      <div class="bg-white p-4 rounded shadow">
        <h2 class="font-medium mb-2">ดูไฟล์: <?php echo e($vf); ?></h2>
        <pre class="whitespace-pre-wrap bg-slate-50 p-3 rounded text-sm overflow-auto" style="max-height:400px"><?php echo e($content); ?></pre>
      </div>
    <?php else: ?>
      <div class="text-red-600">ไม่พบไฟล์</div>
    <?php endif; endif; ?>

  </div>
  <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
