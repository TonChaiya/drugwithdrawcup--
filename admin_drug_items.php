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
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script src="https://cdn.tailwindcss.com"></script><title>จัดการรายการยา</title><style>.modal-backdrop{background:rgba(15,23,42,.6);backdrop-filter:blur(3px)}.modal-card{max-height:calc(100vh - 2rem);overflow-y:auto}body.modal-open{overflow:hidden}</style></head>
<body class="bg-slate-50">
  <?php include __DIR__ . '/includes/nav.php'; ?>
  <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-5">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">จัดการรายการยา</h1>
        <p class="text-sm text-slate-500 mt-1">ค้นหา ตรวจสอบ และจัดการข้อมูลยา พร้อมประวัติผู้ดำเนินการ</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a href="download_drug_template.php" class="px-4 py-2.5 rounded-xl border border-emerald-300 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 font-medium">ดาวน์โหลดต้นแบบ Excel</a>
        <button type="button" onclick="openModal('importModal')" class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 font-medium">นำเข้า Excel/CSV</button>
        <button type="button" onclick="openAddModal()" class="px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold shadow-sm">+ เพิ่มรายการยา</button>
      </div>
    </div>
    <?php if($msg): ?><div class="mb-5 p-3 rounded-xl border <?php echo $msgError ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700'; ?>"><?php echo e($msg); ?></div><?php endif; ?>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
      <div class="bg-white rounded-2xl border border-slate-200 p-4"><div class="text-sm text-slate-500">รายการทั้งหมด</div><div class="text-2xl font-bold mt-1"><?php echo count($items); ?></div></div>
      <div class="bg-white rounded-2xl border border-green-200 p-4"><div class="text-sm text-green-700">เปิดใช้งาน</div><div class="text-2xl font-bold text-green-800 mt-1"><?php echo $activeCount; ?></div></div>
      <div class="bg-white rounded-2xl border border-amber-200 p-4"><div class="text-sm text-amber-700">ระงับใช้งาน</div><div class="text-2xl font-bold text-amber-800 mt-1"><?php echo $suspendedCount; ?></div></div>
    </div>
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
      <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-4">
        <div><h2 class="font-semibold text-lg">รายการยา</h2><p id="result-count" class="text-sm text-slate-500 mt-1"></p></div>
        <div class="flex flex-col sm:flex-row gap-2 w-full lg:w-auto">
          <select id="year-filter" onchange="applyDrugFilters(true)" class="h-11 border border-slate-300 rounded-xl px-3 bg-white"><option value="all">ทุกปีงบประมาณ</option><?php foreach ($fiscalYears as $year): ?><option value="<?php echo (int)$year; ?>">ปี <?php echo (int)$year; ?></option><?php endforeach; ?></select>
          <select id="status-filter" onchange="applyDrugFilters(true)" class="h-11 border border-slate-300 rounded-xl px-3 bg-white"><option value="all">ทุกสถานะ</option><option value="active">เปิดใช้งาน</option><option value="suspended">ยังไม่เปิดใช้/ระงับ</option></select>
          <div id="drug-search-wrap" class="relative w-full sm:w-[420px]">
            <div class="relative"><span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400">⌕</span><input id="drug-search" type="search" autocomplete="off" placeholder="พิมพ์รหัสยา หรือชื่อยา..." class="h-11 border border-slate-300 rounded-xl pl-9 pr-10 w-full focus:outline-none focus:ring-2 focus:ring-blue-500" oninput="handleDrugSearch()" onkeydown="handleSearchKey(event)" onfocus="showDrugSuggestions()"><button id="clear-search" type="button" onclick="clearDrugSearch()" class="hidden absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700" aria-label="ล้างการค้นหา">&times;</button></div>
            <div id="drug-suggestions" class="hidden absolute z-40 left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-xl max-h-80 overflow-y-auto" role="listbox"></div>
          </div>
        </div>
      </div>
      <div id="bulk-toolbar" class="hidden mb-4 rounded-xl border border-blue-200 bg-blue-50 p-3 flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div class="text-sm text-blue-900"><strong id="selected-count">เลือก 0 รายการ</strong><span class="mx-2">·</span><button type="button" onclick="selectAllFiltered()" class="underline font-medium">เลือกทั้งหมดตามผลค้นหา</button><span class="mx-2">·</span><button type="button" onclick="clearDrugSelection()" class="underline">ล้างการเลือก</button></div>
        <div class="flex gap-2"><button type="button" onclick="openBulkStatusModal(1)" class="px-3 py-2 rounded-lg bg-emerald-600 text-white font-semibold">เปิดใช้ที่เลือก</button><button type="button" onclick="openBulkStatusModal(0)" class="px-3 py-2 rounded-lg bg-amber-600 text-white font-semibold">ระงับที่เลือก</button></div>
      </div>
      <div class="overflow-x-auto">
      <table class="w-full table-auto min-w-[1050px]">
        <thead class="bg-slate-100"><tr><th class="p-3 text-center"><input id="select-page" type="checkbox" onchange="toggleCurrentPage(this.checked)" aria-label="เลือกทุกแถวในหน้านี้"></th><th class="p-3 text-left">ID</th><th class="p-3 text-left">รหัส</th><th class="p-3 text-left">ชื่อยา</th><th class="p-3 text-left">ปีงบ</th><th class="p-3 text-left">ขนาด</th><th class="p-3 text-left">หน่วย</th><th class="p-3 text-left">ประเภท</th><th class="p-3 text-left">สถานะ</th><th class="p-3 text-left">จัดการ</th></tr></thead>
        <tbody>
        <?php foreach($items as $it): ?>
          <tr class="border-t drug-row hover:bg-slate-50 <?php echo (int)$it['is_active'] ? '' : 'bg-amber-50'; ?>" data-id="<?php echo (int)$it['id']; ?>" data-year="<?php echo (int)$it['fiscal_year']; ?>" data-status="<?php echo (int)$it['is_active'] ? 'active' : 'suspended'; ?>" data-code="<?php echo e($it['working_code']); ?>" data-name="<?php echo e($it['name']); ?>" data-type="<?php echo e($it['type']); ?>" data-search="<?php echo e(strtolower($it['working_code'].' '.$it['name'].' '.$it['type'])); ?>">
            <td class="p-3 text-center"><input type="checkbox" class="drug-select" value="<?php echo (int)$it['id']; ?>" onchange="toggleDrugSelection(this)"></td>
            <td class="p-3 text-slate-500"><?php echo $it['id']; ?></td>
            <td class="p-3 font-mono font-medium"><?php echo e($it['working_code']); ?></td>
            <td class="p-3 font-medium"><?php echo e($it['name']); ?></td>
            <td class="p-3"><?php echo (int)$it['fiscal_year']; ?></td>
            <td class="p-3"><?php echo e($it['pack_size']); ?></td>
            <td class="p-3"><?php echo e($it['unit'] ?? ''); ?></td>
            <td class="p-3"><?php echo e($it['type']); ?></td>
            <td class="p-3"><span class="inline-flex px-2.5 py-1 rounded-full text-xs <?php echo (int)$it['is_active'] ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-800'; ?>"><?php echo (int)$it['is_active'] ? 'เปิดใช้งาน' : 'ยังไม่เปิดใช้/ระงับ'; ?></span></td>
            <td class="p-3 whitespace-nowrap">
              <div class="flex items-center gap-2">
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
          <tr id="no-drug-results" class="hidden"><td colspan="10" class="p-10 text-center text-slate-500">ไม่พบรายการยาที่ตรงกับคำค้นหา</td></tr>
        </tbody>
      </table>
      </div>
      <div id="pagination-controls" class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-slate-100">
        <span class="text-sm text-slate-600">แสดงหน้าละ 30 รายการ</span>
        <div class="flex items-center gap-2"><button id="prev-page" type="button" onclick="changeDrugPage(-1)" class="px-3 py-2 rounded-lg border bg-white disabled:opacity-40">ก่อนหน้า</button><span id="page-label" class="text-sm text-slate-600 min-w-24 text-center"></span><button id="next-page" type="button" onclick="changeDrugPage(1)" class="px-3 py-2 rounded-lg border bg-white disabled:opacity-40">ถัดไป</button></div>
      </div>
    </div>
    <details class="bg-white rounded-2xl border border-slate-200 shadow-sm mt-4 group">
      <summary class="list-none cursor-pointer p-4 flex items-center justify-between"><div><h2 class="font-medium">ประวัติการเปลี่ยนแปลงล่าสุด</h2><p class="text-xs text-gray-500 mt-1">กดเพื่อดูผู้ดำเนินการ ช่องทาง IP และวันเวลา</p></div><span class="text-slate-400 group-open:rotate-180 transition-transform">⌄</span></summary>
      <div class="overflow-x-auto border-t border-slate-100 p-4 pt-2">
      <table class="w-full min-w-[760px] text-sm">
        <thead class="bg-slate-100"><tr><th class="p-2 text-left">วันเวลา</th><th class="p-2 text-left">การทำรายการ</th><th class="p-2 text-left">รายการยา</th><th class="p-2 text-left">ผู้ดำเนินการ</th><th class="p-2 text-left">ช่องทาง</th><th class="p-2 text-left">IP</th><th class="p-2 text-left">รายละเอียด</th></tr></thead>
        <tbody>
        <?php foreach ($auditLogs as $log): ?>
          <tr class="border-t"><td class="p-2 whitespace-nowrap"><?php echo e(format_thai_datetime($log['created_at'])); ?></td><td class="p-2"><?php echo e($auditActionLabels[$log['action']] ?? $log['action']); ?></td><td class="p-2">#<?php echo (int)$log['drug_item_id']; ?> <?php echo e(trim(($log['working_code'] ?? '').' '.($log['drug_name'] ?? ''))); ?></td><td class="p-2"><?php echo e(($log['actor_name'] ?: $log['actor_username']) ?: 'SYSTEM'); ?></td><td class="p-2"><?php echo e($log['source']); ?></td><td class="p-2 font-mono text-xs"><?php echo e($log['ip_address'] ?: '-'); ?></td><td class="p-2"><details class="max-w-xs"><summary class="text-blue-600 cursor-pointer">ดูข้อมูลก่อน–หลัง</summary><div class="mt-2 text-xs break-all"><strong>ก่อน:</strong> <?php echo e($log['old_values'] ?: '-'); ?><br><strong>หลัง:</strong> <?php echo e($log['new_values'] ?: '-'); ?></div></details></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </details>
  </div>

  <div id="drugModal" class="fixed inset-0 z-[100] hidden modal-backdrop p-4 items-center justify-center" onclick="backdropClose(event,'drugModal')">
    <div class="modal-card w-full max-w-2xl bg-white rounded-2xl shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="drugModalTitle">
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

  <div id="importModal" class="fixed inset-0 z-[100] hidden modal-backdrop p-4 items-center justify-center" onclick="backdropClose(event,'importModal')">
    <div class="modal-card w-full max-w-xl bg-white rounded-2xl shadow-2xl" role="dialog" aria-modal="true">
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
  <div id="importPreviewModal" class="fixed inset-0 z-[105] hidden modal-backdrop p-4 items-center justify-center" onclick="backdropClose(event,'importPreviewModal')">
    <div class="modal-card w-full max-w-2xl bg-white rounded-2xl shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="importPreviewTitle">
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

  <div id="bulkStatusModal" class="fixed inset-0 z-[108] hidden modal-backdrop p-4 items-center justify-center" onclick="backdropClose(event,'bulkStatusModal')">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="bulkStatusTitle">
      <div class="p-5"><div class="w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-2xl mb-4">!</div><h2 id="bulkStatusTitle" class="text-xl font-bold">ยืนยันเปลี่ยนสถานะ</h2><p id="bulkStatusText" class="text-slate-600 mt-2"></p><div class="mt-4 rounded-xl bg-amber-50 border border-amber-200 p-3 text-sm text-amber-900">การระงับไม่ลบประวัติใบเบิกเดิม รายการที่ระงับจะไม่แสดงสำหรับสร้างใบเบิกใหม่</div></div>
      <form method="post" class="px-5 pb-5 flex justify-end gap-2" onsubmit="return prepareBulkSubmit()"><input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="bulk_status"><input type="hidden" name="selected_ids" id="bulkDrugIds"><input type="hidden" name="is_active" id="bulkIsActive"><button type="button" onclick="closeModal('bulkStatusModal')" class="px-4 py-2.5 rounded-xl border border-slate-300">ยกเลิก</button><button id="bulkConfirmButton" class="px-5 py-2.5 rounded-xl text-white font-semibold">ยืนยัน</button></form>
    </div>
  </div>

  <div id="singleStatusModal" class="fixed inset-0 z-[109] hidden modal-backdrop p-4 items-center justify-center" onclick="backdropClose(event,'singleStatusModal')">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="singleStatusTitle">
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

  <div id="deleteModal" class="fixed inset-0 z-[110] hidden modal-backdrop p-4 items-center justify-center" onclick="backdropClose(event,'deleteModal')">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
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
