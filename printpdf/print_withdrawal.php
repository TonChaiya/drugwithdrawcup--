<?php 
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
$currentUser = require_login($pdo, '../index.php');

function format_withdraw_code_pdf($id){
    return 'S' . str_pad($id, 5, '0', STR_PAD_LEFT);
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { die("Invalid ID"); }

// Load withdrawal
$stmt = $pdo->prepare("SELECT w.*, u.name as user_name, u.facility_name, u.username
                       FROM withdrawals w 
                       JOIN users u ON u.id = w.user_id
                       WHERE w.id = ?");
$stmt->execute([$id]);
$w = $stmt->fetch();

if (!$w) { die("ไม่พบข้อมูลใบเบิก"); }

if (!can_access_host_code($pdo, $currentUser, (string)$w['host_code'])) {
    http_response_code(403);
    die('ไม่มีสิทธิ์ดูใบเบิกนี้');
}

require __DIR__ . '/../vendor/autoload.php';

$stmt2 = $pdo->prepare("SELECT wi.*, d.name, d.working_code, d.pack_size as current_pack
                        FROM withdrawal_items wi
                        JOIN drug_item d ON d.id = wi.drug_item_id
                        WHERE wi.withdrawal_id = ?
                        ORDER BY d.name ASC");
$stmt2->execute([$id]);
$allItems = $stmt2->fetchAll();

// ------------------------
// แยกอนุมัติ / ไม่อนุมัติ
// ------------------------
$approvedItems = [];
$rejectedItems = [];

foreach ($allItems as $it) {
    if ((int)$it['delivered_quantity'] > 0) {
        $approvedItems[] = $it;
    } else {
        $rejectedItems[] = $it;
    }
}

$withdrawCode = format_withdraw_code_pdf($w['withdraw_no']);
$statusLabels = [
    'draft' => 'ร่าง',
    'submitted' => 'รออนุมัติ',
    'approved' => 'อนุมัติแล้ว',
    'rejected' => 'ยกเลิก',
];
$withdrawStatusLabel = $statusLabels[$w['status']] ?? $w['status'];

ob_start();
?>

<style>
body { font-family: "garuda"; font-size: 13px; }

.highlight-total {
  background: #ffe48a !important;  /* สีไฮไล */
}

.highlight-total-green {
  background: #ffe48a !important; /* สีไฮไล */
}

/* Fixed layout ensures column widths are consistent and respected by mPDF */
.items-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
  table-layout: fixed;
}

.items-table th, .items-table td {
  border: 1px solid #444;
  padding: 5px;
  font-size: 10px;
  vertical-align: top;
}

.items-header th {
  background: #f0f0f0;
  text-align: center;
  font-weight: bold;
}

.items-reject th {
  background: #ffe5e5;
  color: #900;
  text-align: center;
  font-weight: bold;
}

/* Make sure both tables use the same column sizing via classes */
.items-table th.code, .items-table td.code { width: 60px; text-align:center; }
.items-table th.name, .items-table td.name {
  width: 200px; /* reduce name column */
  word-wrap: break-word;
  overflow-wrap: break-word;
  word-break: break-word;
  white-space: normal;
}
.items-table th.note, .items-table td.note {
  width: 150px; /* widen note column */
  word-wrap: break-word;
  overflow-wrap: break-word;
  word-break: break-word;
  white-space: normal;
}

.signature-table td {
  border: none !important;
  padding-top: 40px;
  text-align: center;
  font-size: 14px;
}
</style>

<htmlpageheader name="header">
  <div style="text-align:center; font-weight:bold; font-size:16px;">
    ใบเบิกเวชภัณฑ์
  </div>

  <table width="100%" style="margin-bottom:10px;">
    <tr>
      <td><strong>เลขใบเบิก:</strong> <?= $withdrawCode ?></td>
      <td><strong>วันที่เบิก:</strong> <?= htmlspecialchars(format_thai_datetime($w['created_at']), ENT_QUOTES, 'UTF-8'); ?></td>
    </tr>
    <tr>
      <td><strong>ผู้ขอเบิก:</strong> <?= htmlspecialchars($w['user_name']); ?></td>
      <td><strong>สถานะ:</strong> <?= htmlspecialchars($withdrawStatusLabel, ENT_QUOTES, 'UTF-8'); ?></td>
    </tr>
    <tr>
      <td><strong>หน่วยเบิก:</strong> <?= htmlspecialchars($w['facility_name']); ?></td>
      <td></td>
    </tr>
  </table>
</htmlpageheader>

<sethtmlpageheader name="header" value="on" show-this-page="1" />

<!-- ====================== -->
<!-- ตารางรายการที่อนุมัติ -->
<!-- ====================== -->

<h3>รายการที่อนุมัติเบิก</h3>

<table class="items-table">
<thead class="items-header">
<tr>
  <th class="code">รหัส</th>
  <th class="name">รายการยา</th>
  <th>คงเหลือล่าสุด</th>
  <th>ขอเบิก</th>
  <th>ขนาดบรรจุ</th>
  <th>รวมขอเบิก</th>
  <th>จ่ายจริง</th>
  <th>รวมจ่ายจริง</th>
  <th class="note">หมายเหตุ</th>
</tr>
</thead>
<tbody>
<?php foreach($approvedItems as $it): 
    $pack = $it['pack_size_snapshot'] ?? $it['current_pack'];
    $packNum = is_numeric($pack) ? (float)$pack : 0;

    $qty = (int)$it['quantity'];
    $stock = (int)$it['current_stock_snapshot'];
    $del = (int)$it['delivered_quantity'];

    $totalReq = $qty * $packNum;
    $totalDel = $del * $packNum;
?>
<tr>

  <td class="code" style="text-align:center;"><?= htmlspecialchars($it['working_code']); ?></td>
  <td class="name"><?= htmlspecialchars($it['name']); ?></td>

  <td style="text-align:center;"><?= $stock; ?></td>
  <td style="text-align:center;"><?= $qty; ?></td>

  <td style="text-align:center;"><?= htmlspecialchars($pack); ?></td>

  <!-- จำนวนที่ขอเบิกรวม ไฮไลต์ -->
  <td class="highlight-total" style="text-align:center;"><?= $totalReq; ?></td>

  <td style="text-align:center;"><?= $del; ?></td>

  <!-- จำนวนจ่ายรวม ไฮไลต์ -->
  <td class="highlight-total" style="text-align:center;"><?= $totalDel; ?></td>

  <td class="note"><?= nl2br(htmlspecialchars($it['note'])); ?></td>
</tr>

<?php endforeach; ?>
</tbody>
</table>


<!-- =========================== -->
<!-- ตารางรายการที่ไม่อนุมัติ -->
<!-- =========================== -->
<?php if(count($rejectedItems) > 0): ?>

<h3 style="margin-top:25px; color:#900;">รายการที่ไม่ได้รับการอนุมัติ</h3>

<table class="items-table">
<thead class="items-reject">
<tr>
  <th class="code">รหัส</th>
  <th class="name">รายการยา</th>
  <th>คงเหลือล่าสุด</th>
  <th>ขอเบิก</th>
  <th>ขนาดบรรจุ</th>
  <th>รวมขอเบิก</th>
  <th>จ่ายจริง</th>
  <th>รวมจ่ายจริง</th>
  <th class="note">หมายเหตุ</th>
</tr>
</thead>
<tbody>
<?php foreach($rejectedItems as $it): 
    $pack = $it['pack_size_snapshot'] ?? $it['current_pack'];
    $packNum = is_numeric($pack) ? (float)$pack : 0;

    $qty = (int)$it['quantity'];
    $stock = (int)$it['current_stock_snapshot'];

    $totalReq = $qty * $packNum;
?>
<tr>
  <td class="code" style="text-align:center;"><?= htmlspecialchars($it['working_code']); ?></td>
  <td class="name"><?= htmlspecialchars($it['name']); ?></td>

  <td style="text-align:center;"><?= $stock; ?></td>
  <td style="text-align:center;"><?= $qty; ?></td>

  <td style="text-align:center;"><?= htmlspecialchars($pack); ?></td>

  <!-- ขอเบิกรวม ไฮไลต์ -->
  <td class="highlight-total" style="text-align:center;"><?= $totalReq; ?></td>

  <td style="text-align:center;">0</td>

  <!-- จ่ายรวม ไฮไลต์ -->
  <td class="highlight-total" style="text-align:center;">0</td>

  <td class="note"><?= nl2br(htmlspecialchars($it['note'])); ?></td>
</tr>

<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>


<!-- ลายเซ็นท้าย -->
<div style="page-break-inside: avoid; margin-top:40px;">
<table class="signature-table" width="100%">
  <tr>
    <td>
      ลงชื่อ.......................................ผู้ขอเบิก<br>
      (.......................................................)<br>
      ตำแหน่ง............................................
    </td>
    <td>
      ลงชื่อ.......................................ผู้รับ<br>
      (.......................................................)<br>
      ตำแหน่ง............................................
    </td>
  </tr>
  <tr>
    <td>
      ลงชื่อ.......................................ผู้จ่าย<br>
      (.......................................................)<br>
      ตำแหน่ง............................................
    </td>
    <td>
      ลงชื่อ.......................................ผู้อนุมัติ<br>
      (.......................................................)<br>
      ตำแหน่ง............................................
    </td>
  </tr>
</table>
</div>

<?php
$html = ob_get_clean();

$mpdf = new \Mpdf\Mpdf([
    'default_font' => 'garuda',
    'margin_top' => 50,
    'margin_bottom' => 20
]);

$footerCreator = htmlspecialchars($w['user_name'], ENT_QUOTES, 'UTF-8');
$footerUsername = htmlspecialchars($w['username'], ENT_QUOTES, 'UTF-8');
$footerFacility = htmlspecialchars($w['facility_name'], ENT_QUOTES, 'UTF-8');
$footerCreatedAt = htmlspecialchars(format_thai_datetime($w['created_at']), ENT_QUOTES, 'UTF-8');
$footerHtml = <<<HTML
<div style="border-top:0.4px solid #b8c1cc; padding-top:4px; color:#667085; font-size:8px; line-height:1.35;">
  ผู้สร้าง: {$footerCreator} ({$footerUsername}) | สถานบริการ: {$footerFacility} | สร้าง: {$footerCreatedAt} | หน้า {PAGENO} / {nbpg}
</div>
HTML;
$mpdf->SetHTMLFooter($footerHtml);

$mpdf->WriteHTML($html);
$mpdf->Output("withdraw_$withdrawCode.pdf", "I");
