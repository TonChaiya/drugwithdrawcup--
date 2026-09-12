<?php
require 'includes/db.php';
require 'includes/auth.php';
$user = require_login($pdo);
$host_code = $user['host_code'] ?? null;
if (!$host_code) { echo 'Missing host_code'; exit; }

$msg = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// โหลด type กลุ่มยา (ชื่อกลุ่ม ฯลฯ)
$drugTypes = require __DIR__ . "/includes/drug_types.php";

// ดึงรายการยา เรียงตาม type แล้วตามชื่อ
$stmt = $pdo->prepare("SELECT * FROM drug_item WHERE is_active = 1 ORDER BY type ASC, name ASC");
$stmt->execute();
$items = $stmt->fetchAll();

// จัดกลุ่มตาม type
$grouped = [];
foreach ($items as $it) {
    $t = $it['type'] ?: 'other';
    $grouped[$t][] = $it;
}

// โหลด draft ล่าสุด ของ user (ยังไม่ได้ใช้ แต่อย่าลบทิ้ง)
$stmt2 = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1');
$stmt2->execute([$user['id'],'draft']);
$draft = $stmt2->fetch();
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>สร้างใบเบิกยา | ระบบเบิกยา CUP สันกำแพง</title>
  <link rel="stylesheet" href="assets/css/app.css">
  <?php define('DRUG_WITHDRAW_APP_STYLES', true); ?>
  <script src="assets/js/withdrawal.js" defer></script>
</head>
<body class="app-page withdrawal-page">
  <?php include __DIR__ . '/includes/nav.php'; ?>

  <main class="app-ui app-container withdrawal-workspace">
    <header class="withdrawal-page-header">
      <div>
        <p class="withdrawal-page-header__kicker">สร้างรายการเบิกยา</p>
        <h1 class="withdrawal-page-header__title">สร้างใบเบิกยา</h1>
        <p class="withdrawal-page-header__subtitle">ค้นหารายการ กรอกยอดคงเหลือเพื่อรายงาน และระบุจำนวนที่ต้องการเบิก</p>
      </div>
      <div class="withdrawal-page-header__context" aria-label="ข้อมูลสถานบริการ">
        <span>สถานบริการ</span>
        <strong><?= e($user['facility_name'] ?? $host_code) ?></strong>
        <small>รหัส <?= e($host_code) ?></small>
      </div>
    </header>

    <?php if ($msg): ?>
      <div class="app-alert" role="status"><?= e($msg) ?></div>
    <?php endif; ?>

    <form id="withdrawalForm" method="post" action="submit_withdrawal.php" class="withdrawal-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <section class="app-card withdrawal-toolbar" aria-label="ค้นหาและกรองรายการยา">
        <div class="withdrawal-toolbar__top">
          <div class="withdrawal-search">
            <label for="drugSearch" class="withdrawal-search__label">ค้นหารหัสยา / ชื่อยา</label>
            <div class="withdrawal-search__control">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="m21 21-4.35-4.35m2.35-5.15a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              </svg>
              <input id="drugSearch" type="search" class="withdrawal-search__input" placeholder="พิมพ์รหัสหรือชื่อยา" autocomplete="off" data-withdrawal-search>
              <button type="button" class="withdrawal-search__clear" data-withdrawal-search-clear aria-label="ล้างคำค้นหา">ล้าง</button>
            </div>
          </div>
          <div class="withdrawal-toolbar__summary" aria-live="polite">
            <span class="withdrawal-toolbar__summary-label">รายการที่กำลังเบิก</span>
            <strong><span data-withdrawal-selected-count>0</span> รายการ</strong>
          </div>
        </div>

        <div class="withdrawal-toolbar__filters">
          <div class="withdrawal-group-filters" role="group" aria-label="กรองตามกลุ่มยา">
            <button type="button" class="withdrawal-filter-chip is-active" data-withdrawal-group="all" aria-pressed="true">ทั้งหมด</button>
            <?php foreach ($grouped as $type => $list): ?>
              <?php $label = $drugTypes[$type]['label'] ?? $type; ?>
              <button type="button" class="withdrawal-filter-chip" data-withdrawal-group="<?= e((string)$type) ?>" aria-pressed="false"><?= e($label) ?></button>
            <?php endforeach; ?>
          </div>
          <button type="button" class="withdrawal-filled-filter" data-withdrawal-filled-filter aria-pressed="false">
            <span class="withdrawal-filled-filter__indicator" aria-hidden="true"></span>
            เฉพาะรายการที่กรอก
          </button>
        </div>
      </section>

      <section class="app-card withdrawal-list" aria-labelledby="drug-list-title">
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
                <th scope="col">ยา</th>
                <th scope="col">
                  ยอดคงเหลือ
                  <small>ข้อมูลรายงาน</small>
                </th>
                <th scope="col">
                  จำนวนขอเบิก
                  <small>หน่วยบรรจุ</small>
                </th>
                <th scope="col">ยอดรวม</th>
                <th scope="col">หมายเหตุ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grouped as $type => $rows): ?>
                <?php $label = $drugTypes[$type]['label'] ?? $type; ?>
                <tr class="withdrawal-group-row" data-withdrawal-group-header="<?= e((string)$type) ?>">
                  <th colspan="5" scope="rowgroup"><span><?= e($label) ?></span><small><?= count($rows) ?> รายการ</small></th>
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
                    <td class="drug-entry__medicine">
                      <div class="drug-entry__name"><?= e($it['name']) ?></div>
                      <div class="drug-entry__meta">
                        <span class="drug-entry__code"><?= e($it['working_code']) ?></span>
                        <span>ขนาดบรรจุ <?= e($rawPack !== '' ? $rawPack : 'ไม่ระบุ') ?><?= $unit !== '' ? ' ' . e($unit) : '' ?></span>
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

      <div class="withdrawal-action-bar" aria-label="การดำเนินการใบเบิก">
        <div class="withdrawal-action-bar__summary">
          <span>กำลังขอเบิก</span>
          <strong><span data-withdrawal-selected-count>0</span> รายการ</strong>
        </div>
        <div class="withdrawal-action-bar__actions">
          <button id="save-btn" type="submit" name="action" value="save" class="app-btn app-btn--secondary">บันทึกร่าง</button>
          <button id="submit-btn" type="submit" name="action" value="submit" class="app-btn app-btn--primary" data-final-submit-trigger>ส่งใบเบิกให้ศูนย์</button>
        </div>
      </div>
    </form>
  </main>

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
