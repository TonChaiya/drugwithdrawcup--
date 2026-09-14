<?php
require 'includes/db.php';
require 'includes/auth.php';
require 'includes/drug_item_security.php';
require 'includes/admin_drug_actions.php';
$admin = require_superadmin($pdo);

// optional composer autoload (for PHPSpreadsheet)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require __DIR__ . '/vendor/autoload.php';
}

if (!drug_schema_is_ready($pdo)) {
  http_response_code(503);
  exit('ฐานข้อมูลยังไม่พร้อม กรุณานำเข้า databaseSQL/20260909_harden_drug_items.sql และ databaseSQL/20260911_add_drug_fiscal_year.sql ตามลำดับ');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  handle_admin_drug_post($pdo, $admin);
}

$msg = $_SESSION['flash'] ?? '';
$msgError = (bool)($_SESSION['flash_error'] ?? false);
$formRecovery = $_SESSION['drug_form_recovery'] ?? null;
$importPreview = $_SESSION['drug_import_preview'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_error']);
unset($_SESSION['drug_form_recovery']);

// load items
$items = $pdo->query('SELECT * FROM drug_item ORDER BY is_active DESC, fiscal_year DESC, type, name')->fetchAll();
$auditLogs = $pdo->query('SELECT a.*, d.working_code, d.name AS drug_name FROM drug_item_audit_logs a LEFT JOIN drug_item d ON d.id = a.drug_item_id ORDER BY a.id DESC LIMIT 20')->fetchAll();
$auditActionLabels = ['create'=>'เพิ่มรายการ','update'=>'แก้ไข','activate'=>'เปิดใช้งาน','suspend'=>'ระงับ','bulk_activate'=>'เปิดใช้หลายรายการ','bulk_suspend'=>'ระงับหลายรายการ','delete'=>'ลบถาวร','import_create'=>'นำเข้าใหม่','import_update'=>'อัปเดตจากไฟล์','auto_suspend_invalid'=>'ระงับข้อมูลไม่ครบอัตโนมัติ'];
$activeCount = 0;
foreach ($items as $item) if ((int)$item['is_active'] === 1) $activeCount++;
$suspendedCount = count($items) - $activeCount;
$defaultFiscalYear = (int)date('Y') + 543 + ((int)date('n') >= 10 ? 1 : 0);
$fiscalYears = [];
foreach ($items as $item) if (!empty($item['fiscal_year'])) $fiscalYears[(int)$item['fiscal_year']] = true;
$fiscalYears = array_keys($fiscalYears);
rsort($fiscalYears, SORT_NUMERIC);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>จัดการรายการยา</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/app.css">
  <?php define('DRUG_WITHDRAW_APP_STYLES', true); ?>
</head>
<body class="app-page drugs-admin-page">
  <?php include __DIR__ . '/includes/nav.php'; ?>
  <main class="app-ui drugs-admin-main">
    <header class="drugs-admin-header">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">จัดการรายการยา</h1>
        <p class="text-sm text-slate-500 mt-1">ค้นหา ตรวจสอบ และจัดการข้อมูลยา พร้อมประวัติผู้ดำเนินการ</p>
      </div>
      <div class="drugs-admin-header__actions">
        <a href="download_drug_template.php" class="drugs-admin-button">ดาวน์โหลดต้นแบบ Excel</a>
        <button type="button" onclick="openModal('importModal')" class="drugs-admin-button">นำเข้า Excel/CSV</button>
        <button type="button" onclick="openAddModal()" class="drugs-admin-button drugs-admin-button--primary">+ เพิ่มรายการยา</button>
      </div>
    </header>
    <?php if($msg): ?><div class="drugs-admin-notice <?php echo $msgError ? 'drugs-admin-notice--error' : 'drugs-admin-notice--success'; ?>"><?php echo e($msg); ?></div><?php endif; ?>
    <section class="drugs-admin-summary" aria-label="สรุปรายการยา">
      <div><span>รายการทั้งหมด</span><strong><?php echo count($items); ?></strong></div>
      <div><span>เปิดใช้งาน</span><strong><?php echo $activeCount; ?></strong></div>
      <div><span>ระงับใช้งาน</span><strong><?php echo $suspendedCount; ?></strong></div>
    </section>
    <section class="drugs-admin-section">
      <div class="drugs-admin-section__head">
        <div><h2 class="font-semibold text-lg">รายการยา</h2><p id="result-count" class="text-sm text-slate-500 mt-1"></p></div>
        <div class="drugs-admin-filters">
          <select id="year-filter" onchange="applyDrugFilters(true)" aria-label="กรองปีงบประมาณ"><option value="all">ทุกปีงบประมาณ</option><?php foreach ($fiscalYears as $year): ?><option value="<?php echo (int)$year; ?>">ปี <?php echo (int)$year; ?></option><?php endforeach; ?></select>
          <select id="status-filter" onchange="applyDrugFilters(true)" aria-label="กรองสถานะ"><option value="all">ทุกสถานะ</option><option value="active">เปิดใช้งาน</option><option value="suspended">ยังไม่เปิดใช้/ระงับ</option></select>
          <div id="drug-search-wrap" class="drugs-admin-search-wrap">
            <div class="drugs-admin-search-control"><span aria-hidden="true">⌕</span><input id="drug-search" type="search" autocomplete="off" placeholder="พิมพ์รหัสยา หรือชื่อยา..." oninput="handleDrugSearch()" onkeydown="handleSearchKey(event)" onfocus="showDrugSuggestions()"><button id="clear-search" type="button" onclick="clearDrugSearch()" class="hidden" aria-label="ล้างการค้นหา">&times;</button></div>
            <div id="drug-suggestions" class="hidden drugs-admin-suggestions" role="listbox"></div>
          </div>
        </div>
      </div>
      <label class="drugs-admin-select-page"><input id="select-page" type="checkbox" onchange="toggleCurrentPage(this.checked)"><span>เลือกทั้งหน้าปัจจุบัน</span></label>
      <div id="bulk-toolbar" class="hidden drugs-admin-bulk-toolbar">
        <div class="drugs-admin-bulk-toolbar__selection"><strong id="selected-count">เลือก 0 รายการ</strong><span>·</span><button type="button" onclick="selectAllFiltered()">เลือกทั้งหมดตามผลค้นหา</button><span>·</span><button type="button" onclick="clearDrugSelection()">ล้างการเลือก</button></div>
        <div class="drugs-admin-bulk-toolbar__actions"><button type="button" onclick="openBulkStatusModal(1)" class="drugs-admin-button drugs-admin-button--activate">เปิดใช้ที่เลือก</button><button type="button" onclick="openBulkStatusModal(0)" class="drugs-admin-button drugs-admin-button--suspend">ระงับที่เลือก</button></div>
      </div>
      <div class="drugs-admin-table-wrap">
      <table class="drugs-admin-table">
        <thead><tr><th>เลือก</th><th>ID</th><th>รหัส</th><th>ชื่อยา</th><th>ปีงบ</th><th>ขนาด</th><th>หน่วย</th><th>ประเภท</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
        <tbody>
        <?php foreach($items as $it): ?>
          <tr class="drug-row <?php echo (int)$it['is_active'] ? '' : 'drug-row--suspended'; ?>" data-id="<?php echo (int)$it['id']; ?>" data-year="<?php echo (int)$it['fiscal_year']; ?>" data-status="<?php echo (int)$it['is_active'] ? 'active' : 'suspended'; ?>" data-code="<?php echo e($it['working_code']); ?>" data-name="<?php echo e($it['name']); ?>" data-type="<?php echo e($it['type']); ?>" data-search="<?php echo e(strtolower($it['working_code'].' '.$it['name'].' '.$it['type'])); ?>">
            <td class="drug-cell--select"><input type="checkbox" class="drug-select" value="<?php echo (int)$it['id']; ?>" onchange="toggleDrugSelection(this)" aria-label="เลือกรายการยา <?php echo e($it['working_code']); ?>"></td>
            <td class="drug-cell--id" data-label="ID"><?php echo $it['id']; ?></td>
            <td class="drug-cell--code"><?php echo e($it['working_code']); ?></td>
            <td class="drug-cell--name"><?php echo e($it['name']); ?></td>
            <td class="drug-cell--year" data-label="ปีงบ"><?php echo (int)$it['fiscal_year']; ?></td>
            <td class="drug-cell--pack" data-label="ขนาด"><?php echo e($it['pack_size']); ?></td>
            <td class="drug-cell--unit" data-label="หน่วย"><?php echo e($it['unit'] ?? ''); ?></td>
            <td class="drug-cell--type" data-label="ประเภท"><?php echo e($it['type']); ?></td>
            <td class="drug-cell--status"><span class="drugs-admin-status <?php echo (int)$it['is_active'] ? 'drugs-admin-status--active' : 'drugs-admin-status--suspended'; ?>"><?php echo (int)$it['is_active'] ? 'เปิดใช้งาน' : 'ยังไม่เปิดใช้/ระงับ'; ?></span></td>
            <td class="drug-cell--actions">
              <div class="drugs-admin-row-actions">
              <button type="button" class="px-2.5 py-1.5 rounded-lg border border-blue-200 bg-blue-50 hover:bg-blue-100 text-blue-700 font-medium" onclick="openEditModal(this)" data-id="<?php echo (int)$it['id']; ?>" data-code="<?php echo e($it['working_code']); ?>" data-name="<?php echo e($it['name']); ?>" data-year="<?php echo (int)$it['fiscal_year']; ?>" data-pack="<?php echo e($it['pack_size']); ?>" data-unit="<?php echo e($it['unit'] ?? ''); ?>" data-type="<?php echo e($it['type']); ?>">แก้ไข</button>
              <button type="button"
                onclick="openSingleStatusModal(this)"
                data-id="<?php echo (int)$it['id']; ?>"
                data-code="<?php echo e($it['working_code']); ?>"
                data-name="<?php echo e($it['name']); ?>"
                data-active="<?php echo (int)$it['is_active']; ?>"
                class="px-2.5 py-1.5 rounded-lg border font-semibold <?php echo (int)$it['is_active'] ? 'border-amber-200 bg-amber-50 hover:bg-amber-100 text-amber-800' : 'border-emerald-300 bg-emerald-50 hover:bg-emerald-100 text-emerald-800'; ?>">
                <?php echo (int)$it['is_active'] ? 'ระงับ' : 'เปิดใช้งาน'; ?>
              </button>
              <?php if ((int)$it['is_active'] === 0): ?>
                <button type="button" class="px-2.5 py-1.5 rounded-lg border border-red-200 bg-red-50 hover:bg-red-100 text-red-700 font-medium" onclick="openDeleteModal(this)" data-id="<?php echo (int)$it['id']; ?>" data-code="<?php echo e($it['working_code']); ?>" data-name="<?php echo e($it['name']); ?>">ลบถาวร</button>
              <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
          <tr id="no-drug-results" class="hidden"><td colspan="10">ไม่พบรายการยาที่ตรงกับคำค้นหา</td></tr>
        </tbody>
      </table>
      </div>
      <div id="pagination-controls" class="drugs-admin-pagination">
        <span class="text-sm text-slate-600">แสดงหน้าละ 30 รายการ</span>
        <div><button id="prev-page" type="button" onclick="changeDrugPage(-1)" class="drugs-admin-button">ก่อนหน้า</button><span id="page-label"></span><button id="next-page" type="button" onclick="changeDrugPage(1)" class="drugs-admin-button">ถัดไป</button></div>
      </div>
    </section>
    <details class="drugs-admin-audit">
      <summary><div><h2>ประวัติการเปลี่ยนแปลงล่าสุด</h2><p>กดเพื่อดูผู้ดำเนินการ ช่องทาง IP และวันเวลา</p></div><span aria-hidden="true">⌄</span></summary>
      <div class="drugs-admin-audit__wrap">
      <table class="drugs-admin-audit__table">
        <thead class="bg-slate-100"><tr><th class="p-2 text-left">วันเวลา</th><th class="p-2 text-left">การทำรายการ</th><th class="p-2 text-left">รายการยา</th><th class="p-2 text-left">ผู้ดำเนินการ</th><th class="p-2 text-left">ช่องทาง</th><th class="p-2 text-left">IP</th><th class="p-2 text-left">รายละเอียด</th></tr></thead>
        <tbody>
        <?php foreach ($auditLogs as $log): ?>
          <tr class="border-t"><td class="p-2 whitespace-nowrap"><?php echo e(format_thai_datetime($log['created_at'])); ?></td><td class="p-2"><?php echo e($auditActionLabels[$log['action']] ?? $log['action']); ?></td><td class="p-2">#<?php echo (int)$log['drug_item_id']; ?> <?php echo e(trim(($log['working_code'] ?? '').' '.($log['drug_name'] ?? ''))); ?></td><td class="p-2"><?php echo e(($log['actor_name'] ?: $log['actor_username']) ?: 'SYSTEM'); ?></td><td class="p-2"><?php echo e($log['source']); ?></td><td class="p-2 font-mono text-xs"><?php echo e($log['ip_address'] ?: '-'); ?></td><td class="p-2"><details class="max-w-xs"><summary class="text-blue-600 cursor-pointer">ดูข้อมูลก่อน–หลัง</summary><div class="mt-2 text-xs break-all"><strong>ก่อน:</strong> <?php echo e($log['old_values'] ?: '-'); ?><br><strong>หลัง:</strong> <?php echo e($log['new_values'] ?: '-'); ?></div></details></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </details>
  </main>

  <div id="drugModal" class="drug-admin-modal hidden modal-backdrop" onclick="backdropClose(event,'drugModal')">
    <div class="drug-admin-dialog modal-card" role="dialog" aria-modal="true" aria-labelledby="drugModalTitle">
      <div class="flex items-center justify-between px-5 py-4 border-b"><div><h2 id="drugModalTitle" class="text-xl font-bold">เพิ่มรายการยา</h2><p class="text-sm text-slate-500">กรอกข้อมูลให้ครบทุกช่อง</p></div><button type="button" onclick="closeModal('drugModal')" class="text-3xl leading-none text-slate-400 hover:text-slate-700">&times;</button></div>
      <form method="post" id="drugForm" class="p-5 space-y-4" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" id="drugAction" value="add"><input type="hidden" name="id" id="drugId">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label class="block"><span class="text-sm font-medium">รหัสยา <span class="text-red-500">*</span></span><input required maxlength="100" name="working_code" id="drugCode" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5" placeholder="เช่น D000233"></label>
          <label class="block"><span class="text-sm font-medium">ขนาดบรรจุ <span class="text-red-500">*</span></span><input required type="number" min="0.0001" step="any" name="pack_size" id="drugPack" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5" placeholder="เช่น 100"></label>
          <label class="block sm:col-span-2"><span class="text-sm font-medium">ชื่อยา <span class="text-red-500">*</span></span><input required maxlength="255" name="name" id="drugName" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5" placeholder="ชื่อยา ความแรง และรูปแบบยา"></label>
          <label class="block"><span class="text-sm font-medium">หน่วย <span class="text-red-500">*</span></span><input required maxlength="50" name="unit" id="drugUnit" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5" placeholder="เช่น เม็ด ขวด หลอด"></label>
          <label class="block"><span class="text-sm font-medium">ประเภท <span class="text-red-500">*</span></span><input required maxlength="100" name="type" id="drugType" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5" placeholder="เช่น ยาเม็ด"></label>
          <label class="block"><span class="text-sm font-medium">ปีงบประมาณ (พ.ศ.) <span class="text-red-500">*</span></span><input required type="number" min="2500" max="3000" name="fiscal_year" id="drugFiscalYear" value="<?php echo $defaultFiscalYear; ?>" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5" placeholder="เช่น 2570"></label>
        </div>
        <div class="rounded-xl bg-blue-50 text-blue-800 text-sm p-3">ระบบจะตรวจข้อมูลซ้ำและบันทึกผู้ดำเนินการ พร้อมข้อมูลก่อนและหลังการแก้ไข</div>
        <div class="flex justify-end gap-2"><button type="button" onclick="closeModal('drugModal')" class="px-4 py-2.5 rounded-xl border border-slate-300">ยกเลิก</button><button class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold">ตรวจสอบและบันทึก</button></div>
      </form>
    </div>
  </div>

  <div id="importModal" class="drug-admin-modal hidden modal-backdrop" onclick="backdropClose(event,'importModal')">
    <div class="drug-admin-dialog modal-card" role="dialog" aria-modal="true">
      <div class="flex items-center justify-between px-5 py-4 border-b"><h2 class="text-xl font-bold">นำเข้ารายการยา</h2><button type="button" onclick="closeModal('importModal')" class="text-3xl leading-none text-slate-400">&times;</button></div>
      <form method="post" enctype="multipart/form-data" class="p-5 space-y-4">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="import_preview">
        <div class="rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-sm p-3">ระบบยึดรหัสยาเป็นตัวตนหลัก ชื่อยาเหมือนเดิมได้หากเป็นคนละรหัส และจะแสดงตัวอย่างก่อนบันทึกจริง</div>
        <label class="block"><span class="text-sm font-medium">เลือกไฟล์ CSV, XLSX หรือ XLS (ไม่เกิน 5 MB)</span><input required type="file" name="drug_file" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" class="mt-2 block w-full rounded-xl border border-slate-300 p-3"></label>
        <label class="block"><span class="text-sm font-medium">วิธีจัดการรหัสที่มีอยู่แล้ว</span><select name="import_mode" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5"><option value="add_only">เพิ่มรหัสใหม่เท่านั้น (แนะนำ)</option><option value="update_by_code">อัปเดตข้อมูลเมื่อรหัสตรงกัน</option></select></label>
        <label class="block"><span class="text-sm font-medium">สถานะของรหัสใหม่</span><select name="new_is_active" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5"><option value="0">เตรียมไว้ก่อน ยังไม่แสดงในหน้าเบิก (แนะนำ)</option><option value="1">เปิดใช้งานทันที</option></select></label>
        <div class="text-sm text-slate-600">หัวตารางต้องมี <code>working_code,name,pack_size,unit,type,fiscal_year</code><div class="mt-2"><a href="download_drug_template.php" class="text-blue-600 hover:underline">ดาวน์โหลด Excel ต้นแบบ</a> · <a href="download_drug_template.php?format=csv" class="text-blue-600 hover:underline">ดาวน์โหลด CSV UTF-8</a> · <a href="admin_import_logs.php" class="text-blue-600 hover:underline">ดูบันทึกนำเข้า</a></div></div>
        <div class="flex justify-end gap-2"><button type="button" onclick="closeModal('importModal')" class="px-4 py-2.5 rounded-xl border border-slate-300">ยกเลิก</button><button class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">ตรวจไฟล์และดูตัวอย่าง</button></div>
      </form>
    </div>
  </div>

  <?php if (is_array($importPreview)): ?>
  <div id="importPreviewModal" class="drug-admin-modal hidden modal-backdrop" onclick="backdropClose(event,'importPreviewModal')">
    <div class="drug-admin-dialog modal-card" role="dialog" aria-modal="true" aria-labelledby="importPreviewTitle">
      <div class="flex items-center justify-between px-5 py-4 border-b"><div><h2 id="importPreviewTitle" class="text-xl font-bold">ตรวจสอบก่อนนำเข้า</h2><p class="text-sm text-slate-500 mt-1"><?php echo e($importPreview['original_name'] ?? ''); ?></p></div><button type="button" onclick="closeModal('importPreviewModal')" class="text-3xl leading-none text-slate-400">&times;</button></div>
      <div class="p-5 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-4"><div class="text-sm text-emerald-700">รหัสใหม่</div><div class="text-2xl font-bold text-emerald-800"><?php echo (int)($importPreview['new_count'] ?? 0); ?></div></div>
          <div class="rounded-xl bg-blue-50 border border-blue-200 p-4"><div class="text-sm text-blue-700">รหัสที่มีแล้ว</div><div class="text-2xl font-bold text-blue-800"><?php echo (int)($importPreview['existing_count'] ?? 0); ?></div></div>
          <div class="rounded-xl bg-slate-50 border border-slate-200 p-4"><div class="text-sm text-slate-600">ทั้งหมด</div><div class="text-2xl font-bold text-slate-800"><?php echo count($importPreview['rows'] ?? []); ?></div></div>
        </div>
        <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-sm text-slate-700">โหมด: <?php echo ($importPreview['mode'] ?? '') === 'add_only' ? 'เพิ่มรหัสใหม่เท่านั้น รหัสเดิมจะถูกข้าม' : 'อัปเดตเฉพาะรายการที่รหัสตรงกัน'; ?> · รหัสใหม่: <?php echo (int)($importPreview['new_is_active'] ?? 0) === 1 ? 'เปิดใช้งานทันที' : 'เตรียมไว้และยังไม่แสดงในหน้าเบิก'; ?></div>
        <?php if (!empty($importPreview['same_name_warnings'])): ?><div class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-sm text-amber-900"><strong>พบชื่อเดิมในรหัสใหม่ (อนุญาตให้นำเข้า):</strong><ul class="list-disc pl-5 mt-2 space-y-1"><?php foreach ($importPreview['same_name_warnings'] as $warning): ?><li><?php echo e($warning); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2">
          <form method="post"><input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="import_cancel"><button class="w-full px-4 py-2.5 rounded-xl border border-slate-300">ยกเลิกการนำเข้า</button></form>
          <form method="post"><input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="import_confirm"><input type="hidden" name="preview_token" value="<?php echo e($importPreview['token'] ?? ''); ?>"><button class="w-full px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold">ยืนยันและบันทึก</button></form>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div id="bulkStatusModal" class="drug-admin-modal hidden modal-backdrop" onclick="backdropClose(event,'bulkStatusModal')">
    <div class="drug-admin-dialog drug-admin-dialog--compact" role="dialog" aria-modal="true" aria-labelledby="bulkStatusTitle">
      <div class="p-5"><div class="w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-2xl mb-4">!</div><h2 id="bulkStatusTitle" class="text-xl font-bold">ยืนยันเปลี่ยนสถานะ</h2><p id="bulkStatusText" class="text-slate-600 mt-2"></p><div class="mt-4 rounded-xl bg-amber-50 border border-amber-200 p-3 text-sm text-amber-900">การระงับไม่ลบประวัติใบเบิกเดิม รายการที่ระงับจะไม่แสดงสำหรับสร้างใบเบิกใหม่</div></div>
      <form method="post" class="px-5 pb-5 flex justify-end gap-2" onsubmit="return prepareBulkSubmit()"><input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="bulk_status"><input type="hidden" name="selected_ids" id="bulkDrugIds"><input type="hidden" name="is_active" id="bulkIsActive"><button type="button" onclick="closeModal('bulkStatusModal')" class="px-4 py-2.5 rounded-xl border border-slate-300">ยกเลิก</button><button id="bulkConfirmButton" class="px-5 py-2.5 rounded-xl text-white font-semibold">ยืนยัน</button></form>
    </div>
  </div>

  <div id="singleStatusModal" class="drug-admin-modal hidden modal-backdrop" onclick="backdropClose(event,'singleStatusModal')">
    <div class="drug-admin-dialog drug-admin-dialog--compact" role="dialog" aria-modal="true" aria-labelledby="singleStatusTitle">
      <div class="p-5">
        <div id="singleStatusIcon" class="w-12 h-12 rounded-full flex items-center justify-center text-2xl mb-4">!</div>
        <h2 id="singleStatusTitle" class="text-xl font-bold text-slate-900">ยืนยันเปลี่ยนสถานะ</h2>
        <p id="singleStatusDrug" class="text-slate-700 font-medium mt-2"></p>
        <div id="singleStatusNotice" class="mt-4 rounded-xl border p-3 text-sm"></div>
      </div>
      <form method="post" class="px-5 pb-5 flex justify-end gap-2">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="set_status">
        <input type="hidden" name="confirm_set_status" value="1">
        <input type="hidden" name="id" id="singleStatusDrugId">
        <input type="hidden" name="is_active" id="singleStatusValue">
        <button type="button" onclick="closeModal('singleStatusModal')" class="px-4 py-2.5 rounded-xl border border-slate-300">ยกเลิก</button>
        <button id="singleStatusConfirm" class="px-5 py-2.5 rounded-xl text-white font-semibold">ยืนยัน</button>
      </form>
    </div>
  </div>

  <div id="deleteModal" class="drug-admin-modal hidden modal-backdrop" onclick="backdropClose(event,'deleteModal')">
    <div class="drug-admin-dialog drug-admin-dialog--compact" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
      <div class="p-5">
        <div class="w-12 h-12 rounded-full bg-red-100 text-red-700 flex items-center justify-center text-2xl mb-4">!</div>
        <h2 id="deleteModalTitle" class="text-xl font-bold text-slate-900">ยืนยันการลบถาวร</h2>
        <p id="deleteDrugText" class="text-slate-600 mt-2"></p>
        <div class="mt-4 rounded-xl bg-amber-50 border border-amber-200 p-3 text-sm text-amber-900">ระบบจะยอมให้ลบเฉพาะรายการที่ระงับและไม่เคยมีประวัติใบเบิกหรือข้อมูลคงคลัง การลบจะย้อนกลับไม่ได้ แต่หลักฐานผู้ลบจะยังอยู่ในประวัติ</div>
      </div>
      <form method="post" class="px-5 pb-5 flex justify-end gap-2">
        <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="delete_suspended">
        <input type="hidden" name="id" id="deleteDrugId">
        <button type="button" onclick="closeModal('deleteModal')" class="px-4 py-2.5 rounded-xl border border-slate-300">ยกเลิก</button>
        <button class="px-5 py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold">ลบถาวร</button>
      </form>
    </div>
  </div>
  <?php include __DIR__ . '/includes/footer.php'; ?>
  <script>
    function openModal(id) {
      var modal = document.getElementById(id);
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      document.body.classList.add('modal-open');
    }
    function closeModal(id) {
      var modal = document.getElementById(id);
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      document.body.classList.remove('modal-open');
    }
    function backdropClose(event, id) { if (event.target.id === id) closeModal(id); }
    function openAddModal() {
      document.getElementById('drugForm').reset();
      document.getElementById('drugAction').value = 'add';
      document.getElementById('drugId').value = '';
      document.getElementById('drugFiscalYear').value = '<?php echo $defaultFiscalYear; ?>';
      document.getElementById('drugModalTitle').textContent = 'เพิ่มรายการยา';
      openModal('drugModal');
      setTimeout(function () { document.getElementById('drugCode').focus(); }, 50);
    }
    function openEditModal(button) {
      document.getElementById('drugForm').reset();
      document.getElementById('drugAction').value = 'update';
      document.getElementById('drugId').value = button.dataset.id;
      document.getElementById('drugCode').value = button.dataset.code;
      document.getElementById('drugName').value = button.dataset.name;
      document.getElementById('drugPack').value = button.dataset.pack;
      document.getElementById('drugUnit').value = button.dataset.unit;
      document.getElementById('drugType').value = button.dataset.type;
      document.getElementById('drugFiscalYear').value = button.dataset.year;
      document.getElementById('drugModalTitle').textContent = 'แก้ไขรายการยา ID ' + button.dataset.id;
      openModal('drugModal');
      setTimeout(function () { document.getElementById('drugCode').focus(); }, 50);
    }
    function openSingleStatusModal(button) {
      var currentlyActive = button.dataset.active === '1';
      var nextActive = currentlyActive ? '0' : '1';
      var actionText = currentlyActive ? 'ระงับรายการยา' : 'เปิดใช้งานรายการยา';
      var label = (button.dataset.code ? button.dataset.code + ' — ' : '') + button.dataset.name;
      var icon = document.getElementById('singleStatusIcon');
      var notice = document.getElementById('singleStatusNotice');
      var confirmButton = document.getElementById('singleStatusConfirm');

      document.getElementById('singleStatusDrugId').value = button.dataset.id;
      document.getElementById('singleStatusValue').value = nextActive;
      document.getElementById('singleStatusTitle').textContent = 'ยืนยัน' + actionText;
      document.getElementById('singleStatusDrug').textContent = label;
      confirmButton.textContent = 'ยืนยัน' + actionText;

      if (currentlyActive) {
        icon.className = 'w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-2xl mb-4';
        notice.className = 'mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900';
        notice.textContent = 'รายการนี้จะไม่แสดงสำหรับสร้างใบเบิกใหม่ แต่ประวัติใบเบิกเดิมจะยังคงอยู่';
        confirmButton.className = 'px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-semibold';
      } else {
        icon.className = 'w-12 h-12 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-2xl mb-4';
        notice.className = 'mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900';
        notice.textContent = 'เมื่อเปิดใช้งานแล้ว รายการนี้จะกลับมาให้เลือกในใบเบิกใหม่ ระบบจะตรวจความครบถ้วนของข้อมูลก่อนเปิดใช้';
        confirmButton.className = 'px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold';
      }
      openModal('singleStatusModal');
    }
    function openDeleteModal(button) {
      document.getElementById('deleteDrugId').value = button.dataset.id;
      var label = (button.dataset.code ? button.dataset.code + ' — ' : '') + button.dataset.name;
      document.getElementById('deleteDrugText').textContent = 'ต้องการลบ “' + label + '” ออกจากฐานข้อมูลถาวรหรือไม่?';
      openModal('deleteModal');
    }
    var allDrugRows = Array.prototype.slice.call(document.querySelectorAll('.drug-row'));
    var filteredDrugRows = [];
    var drugPage = 1;
    var drugPageSize = 30;
    var suggestionIndex = -1;
    var selectedDrugIds = new Set();

    function normalizeDrugSearch(value) {
      value = String(value || '').trim().toLowerCase();
      return value.normalize ? value.normalize('NFKC') : value;
    }

    function drugMatchesQuery(row, query) {
      if (!query) return true;
      var haystack = normalizeDrugSearch(row.dataset.code + ' ' + row.dataset.name + ' ' + row.dataset.type);
      var words = query.split(/\s+/).filter(Boolean);
      return words.every(function (word) { return haystack.indexOf(word) !== -1; });
    }

    function drugSuggestionScore(row, query) {
      var code = normalizeDrugSearch(row.dataset.code);
      var name = normalizeDrugSearch(row.dataset.name);
      if (code === query || name === query) return 0;
      if (code.indexOf(query) === 0 || name.indexOf(query) === 0) return 1;
      if (code.indexOf(query) !== -1 || name.indexOf(query) !== -1) return 2;
      return 3;
    }

    function applyDrugFilters(resetPage) {
      if (resetPage) drugPage = 1;
      var query = normalizeDrugSearch(document.getElementById('drug-search').value);
      var status = document.getElementById('status-filter').value;
      var year = document.getElementById('year-filter').value;
      filteredDrugRows = allDrugRows.filter(function (row) {
        return (status === 'all' || row.dataset.status === status) && (year === 'all' || row.dataset.year === year) && drugMatchesQuery(row, query);
      });
      var totalPages = Math.max(1, Math.ceil(filteredDrugRows.length / drugPageSize));
      drugPage = Math.min(Math.max(1, drugPage), totalPages);
      allDrugRows.forEach(function (row) { row.style.display = 'none'; });
      var start = (drugPage - 1) * drugPageSize;
      filteredDrugRows.slice(start, start + drugPageSize).forEach(function (row) { row.style.display = ''; });
      document.getElementById('no-drug-results').classList.toggle('hidden', filteredDrugRows.length !== 0);
      var end = Math.min(start + drugPageSize, filteredDrugRows.length);
      document.getElementById('result-count').textContent = filteredDrugRows.length ? 'แสดง ' + (start + 1) + '–' + end + ' จาก ' + filteredDrugRows.length + ' รายการ' : 'ไม่พบรายการยา';
      document.getElementById('page-label').textContent = 'หน้า ' + drugPage + ' / ' + totalPages;
      document.getElementById('prev-page').disabled = drugPage <= 1;
      document.getElementById('next-page').disabled = drugPage >= totalPages;
      document.getElementById('pagination-controls').style.display = filteredDrugRows.length > drugPageSize ? '' : 'none';
      syncSelectionUI();
    }

    function changeDrugPage(direction) {
      drugPage += direction;
      applyDrugFilters(false);
      document.querySelector('table').scrollIntoView({behavior:'smooth', block:'start'});
    }

    function renderDrugSuggestions() {
      var input = document.getElementById('drug-search');
      var box = document.getElementById('drug-suggestions');
      var query = normalizeDrugSearch(input.value);
      box.innerHTML = '';
      suggestionIndex = -1;
      if (!query) { box.classList.add('hidden'); return; }
      var status = document.getElementById('status-filter').value;
      var year = document.getElementById('year-filter').value;
      var matches = allDrugRows.filter(function (row) {
        return (status === 'all' || row.dataset.status === status) && (year === 'all' || row.dataset.year === year) && drugMatchesQuery(row, query);
      }).sort(function (a, b) {
        return drugSuggestionScore(a, query) - drugSuggestionScore(b, query) || a.dataset.name.localeCompare(b.dataset.name, 'th');
      });
      if (!matches.length) { box.classList.add('hidden'); return; }
      matches.forEach(function (row) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'drug-suggestion w-full text-left px-4 py-3 border-b last:border-0 hover:bg-blue-50 focus:bg-blue-50 focus:outline-none';
        button.setAttribute('role', 'option');
        var top = document.createElement('div');
        top.className = 'font-medium text-slate-800';
        top.textContent = row.dataset.name;
        var sub = document.createElement('div');
        sub.className = 'text-xs text-slate-500 mt-0.5';
        sub.textContent = row.dataset.code + ' · ปี ' + row.dataset.year + ' · ' + row.dataset.type + (row.dataset.status === 'suspended' ? ' · ยังไม่เปิดใช้/ระงับ' : '');
        button.appendChild(top); button.appendChild(sub);
        button.addEventListener('click', function () { selectDrugSuggestion(row); });
        box.appendChild(button);
      });
      box.classList.remove('hidden');
    }

    function selectDrugSuggestion(row) {
      document.getElementById('drug-search').value = row.dataset.code + ' ' + row.dataset.name;
      document.getElementById('clear-search').classList.remove('hidden');
      document.getElementById('drug-suggestions').classList.add('hidden');
      applyDrugFilters(true);
    }

    function handleDrugSearch() {
      document.getElementById('clear-search').classList.toggle('hidden', !document.getElementById('drug-search').value);
      applyDrugFilters(true);
      renderDrugSuggestions();
    }

    function showDrugSuggestions() { renderDrugSuggestions(); }

    function clearDrugSearch() {
      document.getElementById('drug-search').value = '';
      document.getElementById('clear-search').classList.add('hidden');
      document.getElementById('drug-suggestions').classList.add('hidden');
      applyDrugFilters(true);
      document.getElementById('drug-search').focus();
    }

    function handleSearchKey(event) {
      var box = document.getElementById('drug-suggestions');
      var buttons = Array.prototype.slice.call(box.querySelectorAll('.drug-suggestion'));
      if (!buttons.length || box.classList.contains('hidden')) return;
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        suggestionIndex = event.key === 'ArrowDown' ? Math.min(suggestionIndex + 1, buttons.length - 1) : Math.max(suggestionIndex - 1, 0);
        buttons[suggestionIndex].focus();
      } else if (event.key === 'Escape') {
        box.classList.add('hidden');
      }
    }
    var recoveredDrugForm = <?php echo json_encode($formRecovery, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    if (recoveredDrugForm) {
      document.getElementById('drugAction').value = recoveredDrugForm.action;
      document.getElementById('drugId').value = recoveredDrugForm.id || '';
      document.getElementById('drugCode').value = recoveredDrugForm.working_code || '';
      document.getElementById('drugName').value = recoveredDrugForm.name || '';
      document.getElementById('drugPack').value = recoveredDrugForm.pack_size || '';
      document.getElementById('drugUnit').value = recoveredDrugForm.unit || '';
      document.getElementById('drugType').value = recoveredDrugForm.type || '';
      document.getElementById('drugFiscalYear').value = recoveredDrugForm.fiscal_year || '<?php echo $defaultFiscalYear; ?>';
      document.getElementById('drugModalTitle').textContent = recoveredDrugForm.action === 'update' ? 'แก้ไขรายการยา ID ' + recoveredDrugForm.id : 'เพิ่มรายการยา';
      openModal('drugModal');
    }
    applyDrugFilters(true);
    document.addEventListener('click', function (event) {
      if (!document.getElementById('drug-search-wrap').contains(event.target)) {
        document.getElementById('drug-suggestions').classList.add('hidden');
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        ['drugModal','importModal','importPreviewModal','bulkStatusModal','singleStatusModal','deleteModal'].forEach(function (id) {
          if (!document.getElementById(id)) return;
          if (!document.getElementById(id).classList.contains('hidden')) closeModal(id);
        });
      }
    });
    function visiblePageRows() {
      var start = (drugPage - 1) * drugPageSize;
      return filteredDrugRows.slice(start, start + drugPageSize);
    }
    function toggleDrugSelection(input) {
      if (input.checked) selectedDrugIds.add(input.value); else selectedDrugIds.delete(input.value);
      syncSelectionUI();
    }
    function toggleCurrentPage(checked) {
      visiblePageRows().forEach(function (row) {
        if (checked) selectedDrugIds.add(row.dataset.id); else selectedDrugIds.delete(row.dataset.id);
      });
      syncSelectionUI();
    }
    function selectAllFiltered() {
      filteredDrugRows.forEach(function (row) { selectedDrugIds.add(row.dataset.id); });
      syncSelectionUI();
    }
    function clearDrugSelection() {
      selectedDrugIds.clear();
      syncSelectionUI();
    }
    function syncSelectionUI() {
      document.querySelectorAll('.drug-select').forEach(function (input) { input.checked = selectedDrugIds.has(input.value); });
      var pageRows = visiblePageRows();
      var pageSelected = pageRows.filter(function (row) { return selectedDrugIds.has(row.dataset.id); }).length;
      var selectPage = document.getElementById('select-page');
      selectPage.checked = pageRows.length > 0 && pageSelected === pageRows.length;
      selectPage.indeterminate = pageSelected > 0 && pageSelected < pageRows.length;
      document.getElementById('selected-count').textContent = 'เลือก ' + selectedDrugIds.size + ' รายการ';
      var toolbar = document.getElementById('bulk-toolbar');
      toolbar.classList.toggle('hidden', selectedDrugIds.size === 0);
      toolbar.classList.toggle('flex', selectedDrugIds.size > 0);
    }
    function openBulkStatusModal(isActive) {
      if (!selectedDrugIds.size) return;
      document.getElementById('bulkIsActive').value = String(isActive);
      var actionText = isActive ? 'เปิดใช้งาน' : 'ระงับ';
      document.getElementById('bulkStatusTitle').textContent = 'ยืนยัน' + actionText + 'หลายรายการ';
      document.getElementById('bulkStatusText').textContent = 'ต้องการ' + actionText + 'รายการยาที่เลือก ' + selectedDrugIds.size + ' รายการหรือไม่?';
      var button = document.getElementById('bulkConfirmButton');
      button.textContent = 'ยืนยัน' + actionText;
      button.className = 'px-5 py-2.5 rounded-xl text-white font-semibold ' + (isActive ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-amber-600 hover:bg-amber-700');
      openModal('bulkStatusModal');
    }
    function prepareBulkSubmit() {
      if (!selectedDrugIds.size) return false;
      document.getElementById('bulkDrugIds').value = Array.from(selectedDrugIds).join(',');
      return true;
    }
    <?php if (is_array($importPreview)): ?>openModal('importPreviewModal');<?php endif; ?>
  </script>
</body>
</html>
