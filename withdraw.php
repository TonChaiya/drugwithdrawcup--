<?php
require 'includes/db.php';
require 'includes/auth.php';
$user = require_login($pdo);
$host_code = $user['host_code'] ?? null;
if (!preg_match('/^[0-9]{5}$/', (string)$host_code)) {
    http_response_code(403);
    exit('บัญชีนี้ยังไม่ได้กำหนดรหัสสถานบริการที่ถูกต้อง');
}

// โหลด type กลุ่มยา (ชื่อกลุ่ม ฯลฯ)
$drugTypes = require __DIR__ . "/includes/drug_types.php";

// ดึงรายการยา เรียงตาม type แล้วตามชื่อ
$stmt = $pdo->prepare("SELECT * FROM drug_item WHERE is_active = 1 ORDER BY type ASC, name ASC");
$stmt->execute();
$items = $stmt->fetchAll();

// Reuse the Rate 3เดือน semantics from view_withdrawal.php for this facility:
// the last three completed calendar months, delivered quantity × historical pack.
$monthlyTotals = [];
$drugIds = array_values(array_unique(array_map('intval', array_column($items, 'id'))));
if ($drugIds) {
    $placeholders = implode(',', array_fill(0, count($drugIds), '?'));
    $sql = "SELECT wi.drug_item_id, SUM(wi.delivered_quantity * COALESCE(wi.pack_size_snapshot, d.pack_size)) AS last_month_total
        FROM withdrawal_items wi
        JOIN withdrawals w ON wi.withdrawal_id = w.id
        JOIN drug_item d ON wi.drug_item_id = d.id
        WHERE w.status IN ('submitted','approved')
          AND w.created_at >= ? AND w.created_at < ?
          AND w.host_code = ?
          AND wi.drug_item_id IN ($placeholders)
        GROUP BY wi.drug_item_id";
    $stmtTot = $pdo->prepare($sql);
    for ($i = 1; $i <= 3; $i++) {
        $start = date('Y-m-01', strtotime(sprintf('first day of -%d month', $i)));
        $end = $i === 1 ? date('Y-m-01') : date('Y-m-01', strtotime(sprintf('first day of -%d month', $i - 1)));
        $stmtTot->execute(array_merge([$start, $end, $host_code], $drugIds));
        while ($row = $stmtTot->fetch()) {
            $monthlyTotals[$i][(int)$row['drug_item_id']] = (float)$row['last_month_total'];
        }
        $stmtTot->closeCursor();
    }
}
$monthLabels = [];
for ($i = 1; $i <= 3; $i++) {
    $ts = strtotime(sprintf('first day of -%d month', $i));
    $monthLabels[$i] = substr((string)((int)date('Y', $ts) + 543), -2) . '-' . date('m', $ts);
}

// จัดกลุ่มตาม type
$grouped = [];
foreach ($items as $it) {
    $t = $it['type'] ?: 'other';
    $grouped[$t][] = $it;
}

// A new-form submission must never silently replace the latest draft's items.
$stmt2 = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1');
$stmt2->execute([$user['id'],'draft']);
$draft = $stmt2->fetch();
if ($draft) {
    header('Location: view_withdrawal.php?id=' . (int)$draft['id']);
    exit;
}
$msg = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>สร้างใบเบิกยา | ระบบเบิกยา CUP สันกำแพง</title>
  <link rel="stylesheet" href="assets/css/app.css?v=<?= (int)filemtime(__DIR__ . '/assets/css/app.css') ?>">
  <?php define('DRUG_WITHDRAW_APP_STYLES', true); ?>
  <script src="assets/js/withdrawal.js?v=<?= (int)filemtime(__DIR__ . '/assets/js/withdrawal.js') ?>" defer></script>
</head>
<body class="app-page withdrawal-page">
  <?php include __DIR__ . '/includes/nav.php'; ?>

  <main class="app-ui app-container withdrawal-workspace" id="withdrawalTop">
    <header class="withdrawal-page-header">
      <h1 class="withdrawal-page-header__title">สร้างใบเบิกยา</h1>
      <p class="withdrawal-page-header__meta"><?= e($user['facility_name'] ?? $host_code) ?> <span aria-hidden="true">·</span> รหัส <?= e($host_code) ?></p>
    </header>

    <?php if ($msg): ?>
      <div class="app-alert" role="status"><?= e($msg) ?></div>
    <?php endif; ?>

    <form id="withdrawalForm" method="post" action="submit_withdrawal.php" class="withdrawal-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <section class="withdrawal-toolbar" aria-label="ค้นหาและกรองรายการยา">
        <div class="withdrawal-toolbar__main">
          <button type="button" class="withdrawal-category-trigger" data-withdrawal-category-open aria-haspopup="dialog" aria-controls="withdrawalCategoryDrawer" aria-expanded="false">
            <span aria-hidden="true">☰</span> หมวดยา <small data-withdrawal-active-group-label>ทั้งหมด</small>
          </button>
          <div class="withdrawal-search">
            <label for="drugSearch" class="withdrawal-search__label">ค้นหารหัสยา หรือชื่อยา</label>
            <div class="withdrawal-search__control">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="m21 21-4.35-4.35m2.35-5.15a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              </svg>
              <input id="drugSearch" type="search" class="withdrawal-search__input" placeholder="พิมพ์รหัสหรือชื่อยา" autocomplete="off" data-withdrawal-search>
              <button type="button" class="withdrawal-search__clear" data-withdrawal-search-clear aria-label="ล้างคำค้นหา">ล้าง</button>
            </div>
          </div>
        </div>
        <div class="withdrawal-toolbar__minor">
          <label class="withdrawal-filled-filter"><input type="checkbox" data-withdrawal-filled-filter> แสดงเฉพาะรายการที่เบิก</label>
          <span class="withdrawal-toolbar__count" aria-live="polite">กำลังเบิก <strong data-withdrawal-selected-count>0</strong> รายการ</span>
        </div>
      </section>

      <section class="withdrawal-list" aria-labelledby="drug-list-title">
        <div class="withdrawal-list__head">
          <div>
            <h2 id="drug-list-title">รายการยา</h2>
            <p>ยอดคงเหลือเป็นข้อมูลรายงานเท่านั้น ไม่ถูกนำไปคำนวณยอดเบิก</p>
          </div>
          <span data-withdrawal-visible-count><?= count($items) ?> รายการที่แสดง</span>
        </div>

        <div class="withdrawal-table-scroll">
          <table class="withdrawal-table">
            <thead>
              <tr>
                <th scope="col">รหัสยา</th>
                <th scope="col">รายการยา</th>
                <th scope="col">
                  ยอดคงเหลือ
                  <small>เพื่อรายงาน</small>
                </th>
                <th scope="col">
                  จำนวนขอเบิก
                </th>
                <th scope="col">หน่วยบรรจุ</th>
                <th scope="col">ยอดรวม</th>
                <th scope="col">หมายเหตุ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grouped as $type => $rows): ?>
                <?php $label = $drugTypes[$type]['label'] ?? $type; ?>
                <tr class="withdrawal-group-row" data-withdrawal-group-header="<?= e((string)$type) ?>">
                  <th colspan="7" scope="rowgroup"><span><?= e($label) ?></span><small><?= count($rows) ?> รายการ</small></th>
                </tr>

                <?php foreach ($rows as $it): ?>
                  <?php
                    // คงสูตรเดิม: ดึงเลขแรกจาก pack_size และคำนวณ qty × packNum เท่านั้น
                    $rawPack = trim((string)($it['pack_size'] ?? ''));
                    $packNum = 0;
                    if (preg_match('/([0-9]+(?:\.[0-9]+)?)/u', $rawPack, $m)) {
                      $packNum = (float)$m[1];
                    }
                    $unit = trim((string)($it['unit'] ?? ''));
                    $parts = array_filter([$rawPack, $unit], function($v){ return $v !== ''; });
                    $packLabel = count($parts) ? ('× ' . implode(' ', $parts)) : '× 1';
                    $drugId = (int)$it['id'];
                  ?>
                  <tr class="drug-entry" data-withdrawal-row data-drug-code="<?= e($it['working_code']) ?>" data-drug-name="<?= e($it['name']) ?>" data-drug-group="<?= e((string)$type) ?>">
                    <td class="drug-entry__code-cell"><span class="drug-entry__code"><?= e($it['working_code']) ?></span></td>
                    <td class="drug-entry__medicine">
                      <div class="drug-entry__name"><?= e($it['name']) ?></div>
                      <div class="drug-entry__meta">
                        <span class="drug-entry__pack-context">บรรจุ <?= e($rawPack !== '' ? $rawPack : 'ไม่ระบุ') ?><?= $unit !== '' ? ' ' . e($unit) : '' ?></span>
                        <span class="drug-entry__history">ย้อนหลัง 3 เดือน:
                          <?php for ($mi = 1; $mi <= 3; $mi++): ?>
                            <?= $mi > 1 ? ' · ' : '' ?><?= e($monthLabels[$mi]) ?> <?= (int)($monthlyTotals[$mi][$drugId] ?? 0) ?>
                          <?php endfor; ?>
                          <?= e($unit) ?>
                        </span>
                        <span class="drug-entry__selected-label">กำลังเบิก</span>
                      </div>
                    </td>

                    <td class="drug-entry__field drug-entry__stock">
                      <label for="stock-<?= $drugId ?>">ยอดคงเหลือ <small>ข้อมูลรายงาน</small></label>
                      <input id="stock-<?= $drugId ?>" type="number" min="0" step="1" inputmode="numeric" name="stock[<?= $drugId ?>]" class="drug-entry__input stock-input" placeholder="0" autocomplete="off" data-stock-input aria-describedby="stock-help-<?= $drugId ?>">
                      <span id="stock-help-<?= $drugId ?>" class="drug-entry__feedback" data-stock-feedback hidden>กรุณากรอกยอดคงเหลือเพื่อรายงาน</span>
                    </td>

                    <td class="drug-entry__field drug-entry__quantity">
                      <label for="qty-<?= $drugId ?>">จำนวนขอเบิก <small>หน่วยบรรจุ</small></label>
                      <div class="drug-entry__quantity-control">
                        <input id="qty-<?= $drugId ?>" type="number" min="0" step="1" inputmode="numeric" name="qty[<?= $drugId ?>]" class="drug-entry__input qty-input" data-packnum="<?= e((string)$packNum) ?>" data-unit="<?= e($unit) ?>" data-id="<?= $drugId ?>" placeholder="0" autocomplete="off" data-qty-input>
                        <span class="drug-entry__pack"><?= e($packLabel) ?></span>
                      </div>
                      <span class="drug-entry__feedback drug-entry__feedback--quantity" data-qty-feedback hidden>กรุณากรอกจำนวนตั้งแต่ 0 ขึ้นไป</span>
                    </td>

                    <td class="drug-entry__unit" data-label="หน่วยบรรจุ"><?= e($rawPack !== '' ? $rawPack : 'ไม่ระบุ') ?><?= $unit !== '' ? ' ' . e($unit) : '' ?></td>

                    <td class="drug-entry__total" data-label="ยอดรวม">
                      <strong id="total-<?= $drugId ?>" class="total-span" data-total-output>0<?= $unit !== '' ? ' ' . e($unit) : '' ?></strong>
                    </td>

                    <td class="drug-entry__note">
                      <label for="note-<?= $drugId ?>">หมายเหตุ</label>
                      <input id="note-<?= $drugId ?>" type="text" name="note[<?= $drugId ?>]" class="drug-entry__note-input note-popup-input" readonly data-drug-code="<?= e($it['working_code']) ?>" data-drug-name="<?= e($it['name']) ?>" data-drug-id="<?= $drugId ?>" placeholder="เพิ่มหมายเหตุ (ถ้ามี)">
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="withdrawal-empty" hidden data-withdrawal-empty>
          <strong>ไม่พบรายการยา</strong>
          <span>ลองเปลี่ยนคำค้นหา กลุ่มยา หรือตัวกรองรายการที่กรอก</span>
        </div>
      </section>

      <section id="withdrawalSummary" class="withdrawal-summary" aria-labelledby="withdrawalSummaryTitle">
        <div class="withdrawal-summary__copy">
          <h2 id="withdrawalSummaryTitle">สรุปใบเบิก</h2>
          <p>รายการที่ขอเบิก <strong data-withdrawal-selected-count>0</strong> รายการ</p>
        </div>
        <div class="withdrawal-summary__actions">
          <button id="save-btn" type="submit" name="action" value="save" class="app-btn app-btn--secondary">บันทึกร่าง</button>
          <button id="submit-btn" type="submit" name="action" value="submit" class="app-btn app-btn--primary" data-final-submit-trigger>ตรวจสอบและส่งใบเบิก</button>
        </div>
      </section>
    </form>
  </main>

  <div id="withdrawalCategoryDrawer" class="withdrawal-category-drawer" hidden aria-hidden="true">
    <div class="withdrawal-category-drawer__backdrop" data-withdrawal-category-close></div>
    <section class="withdrawal-category-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="withdrawalCategoryTitle">
      <header class="withdrawal-category-drawer__head">
        <h2 id="withdrawalCategoryTitle">หมวดยา</h2>
        <button type="button" class="withdrawal-category-drawer__close" data-withdrawal-category-close aria-label="ปิดหมวดยา">&times;</button>
      </header>
      <nav class="withdrawal-category-drawer__list" aria-label="เลือกหมวดยา">
        <button type="button" class="withdrawal-category-drawer__item is-active" data-withdrawal-group="all" aria-pressed="true"><span>ทั้งหมด</span><small><?= count($items) ?></small></button>
        <?php foreach ($grouped as $type => $list): ?>
          <?php $label = $drugTypes[$type]['label'] ?? $type; ?>
          <button type="button" class="withdrawal-category-drawer__item" data-withdrawal-group="<?= e((string)$type) ?>" aria-pressed="false"><span><?= e($label) ?></span><small><?= count($list) ?></small></button>
        <?php endforeach; ?>
      </nav>
    </section>
  </div>

  <div class="withdrawal-utility" role="group" aria-label="ทางลัดในหน้าเบิกยา">
    <button type="button" data-withdrawal-scroll-top aria-label="เลื่อนไปบนสุด" title="บนสุด">↑</button>
    <button type="button" data-withdrawal-category-open aria-label="เปิดหมวดยา" title="หมวดยา">☰</button>
    <button type="button" data-withdrawal-scroll-bottom aria-label="เลื่อนไปสรุปใบเบิก" title="ล่างสุด">↓</button>
  </div>

  <div id="withdrawalNoteDialog" class="withdrawal-dialog" hidden aria-hidden="true">
    <div class="withdrawal-dialog__backdrop" data-close-note-editor></div>
    <section class="withdrawal-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="noteEditorTitle">
      <header class="withdrawal-dialog__head">
        <div>
          <span>หมายเหตุของยา</span>
          <h2 id="noteEditorTitle">หมายเหตุ</h2>
          <p id="noteEditorSub"></p>
        </div>
        <button type="button" class="withdrawal-dialog__close" data-close-note-editor aria-label="ปิดหน้าต่างหมายเหตุ">&times;</button>
      </header>
      <div class="withdrawal-dialog__body">
        <label for="noteEditorTextarea">รายละเอียดหมายเหตุ</label>
        <textarea id="noteEditorTextarea" rows="7" placeholder="พิมพ์หมายเหตุของยารายการนี้"></textarea>
        <div class="withdrawal-dialog__footer">
          <span>กด Esc หรือคลิกพื้นหลังเพื่อปิด</span>
          <button type="button" class="app-btn app-btn--primary" data-close-note-editor>บันทึกหมายเหตุ</button>
        </div>
      </div>
    </section>
  </div>

  <div id="withdrawalSubmitDialog" class="withdrawal-dialog" hidden aria-hidden="true">
    <div class="withdrawal-dialog__backdrop" data-close-submit-dialog></div>
    <section class="withdrawal-dialog__panel withdrawal-dialog__panel--confirm" role="dialog" aria-modal="true" aria-labelledby="submitDialogTitle">
      <header class="withdrawal-dialog__head">
        <div>
          <span>ยืนยันการส่งใบเบิก</span>
          <h2 id="submitDialogTitle">ส่งใบเบิกให้ศูนย์?</h2>
          <p>กรุณาตรวจยอดคงเหลือ จำนวนขอเบิก และหมายเหตุให้ครบถ้วนก่อนส่ง</p>
        </div>
      </header>
      <div class="withdrawal-dialog__footer withdrawal-dialog__footer--confirm">
        <button type="button" class="app-btn app-btn--secondary" data-close-submit-dialog>กลับไปตรวจสอบ</button>
        <button type="submit" form="withdrawalForm" name="action" value="submit" class="app-btn app-btn--primary" data-confirm-final-submit>ยืนยันส่งใบเบิก</button>
      </div>
    </section>
  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
