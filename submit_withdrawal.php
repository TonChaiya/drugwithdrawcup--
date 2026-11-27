<?php
require 'includes/db.php';
require 'includes/auth.php';
require 'includes/withdrawal_recovery.php';
require 'includes/withdrawal_drafts.php';
$user = require_login($pdo, 'index.php', 'new');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: withdraw.php'); exit; }
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
  $_SESSION['flash'] = 'เซสชันเปลี่ยนแปลง กรุณาตรวจข้อมูลใบเบิกและส่งใหม่';
  header('Location: index.php?resume=new', true, 303); exit;
}

$user_id = $user['id'];
$host_code = $user['host_code'] ?? null;
if (!preg_match('/^[0-9]{5}$/', (string)$host_code)) {
  $_SESSION['flash'] = 'บัญชีนี้ยังไม่ได้กำหนดรหัสสถานบริการที่ถูกต้อง';
  header('Location: dashboard.php'); exit;
}
$action = $_POST['action'] ?? 'submit';
// Preserve the long-standing draft button value while using one internal action.
if ($action === 'save') $action = 'draft';
$qtys = $_POST['qty'] ?? [];
$notes = $_POST['note'] ?? [];
$stocks = $_POST['stock'] ?? [];

if (!in_array($action, ['submit', 'draft'], true)
    || !is_array($qtys) || !is_array($notes) || !is_array($stocks)
    || count($qtys) > 1000 || count($notes) > 1000 || count($stocks) > 1000) {
  $_SESSION['flash'] = 'ข้อมูลใบเบิกไม่ถูกต้องหรือมีจำนวนรายการมากเกินไป';
  header('Location: withdraw.php'); exit;
}

try {
  $pdo->beginTransaction();

  // Lock a row that exists even when there is no draft. The first consistent
  // read in this transaction then sees drafts committed by earlier creators.
  lock_withdrawal_owner($pdo, (int)$user_id);

  if (outstanding_withdrawal_drafts($pdo, (int)$user_id)) {
    $pdo->rollBack();
    header('Location: withdraw.php', true, 303); exit;
  }
  withdrawal_claim_receipt($pdo, $user, 'new');
  $stmtIns = $pdo->prepare('INSERT INTO withdrawals (host_code,user_id,withdraw_no,status) VALUES (?,?,NULL,?)');
  $stmtIns->execute([$host_code, $user_id, $action === 'submit' ? 'submitted' : 'draft']);
  $withdrawal_id = $pdo->lastInsertId();
  // Set withdraw_no to the primary id to use id as reference.
  try {
    $updNo = $pdo->prepare('UPDATE withdrawals SET withdraw_no = ? WHERE id = ?');
    $updNo->execute([(int)$withdrawal_id, $withdrawal_id]);
  } catch (Exception $e) {
    // Not critical: continue, withdraw_no may be null but id exists.
  }

  // clear any existing items if re-saving
  $del = $pdo->prepare('DELETE FROM withdrawal_items WHERE withdrawal_id = ?'); $del->execute([$withdrawal_id]);
  // prepare insert: include current_stock_snapshot and pack/unit snapshots
  $insItem = $pdo->prepare('INSERT INTO withdrawal_items (withdrawal_id,drug_item_id,working_code_snapshot,quantity,note,current_stock_snapshot,pack_size_snapshot,unit_snapshot) VALUES (?,?,?,?,?,?,?,?)');
  $diLookup = $pdo->prepare('SELECT working_code, pack_size, unit FROM drug_item WHERE id = ? AND is_active = 1 LIMIT 1');
  $hasItem = false;
  $missingStocks = [];
  foreach ($qtys as $did => $q) {
    // The full medicine form posts empty quantities for every unselected row.
    if (is_string($q) && trim($q) === '') continue;
    $q = bounded_non_negative_int($q, 1000000); $did = (int)$did; $n = bounded_plain_text($notes[$did] ?? '', 255);
    if ($q === null || $did <= 0 || $n === null) {
      $missingStocks[] = $did > 0 ? $did : 0;
      continue;
    }
    if ($q > 0) {
      // require stock to be provided for any requested qty
      $stockVal = $stocks[$did] ?? null;
      if ($stockVal === null || trim((string)$stockVal) === '') {
        $missingStocks[] = $did; continue;
      }
      $stockNumber = bounded_non_negative_int($stockVal, 100000000);
      if ($stockNumber === null) { $missingStocks[] = $did; continue; }
      // fetch current pack/unit to store snapshot as well
      $diLookup->execute([$did]); $di = $diLookup->fetch();
      if (!$di) { $missingStocks[] = $did; continue; }
      $pack_snap = $di ? $di['pack_size'] : null;
      $unit_snap = $di ? $di['unit'] : '';
      $insItem->execute([$withdrawal_id,$did,$di['working_code'],$q,$n,$stockNumber,$pack_snap,$unit_snap]); $hasItem = true;
    }
  }

  if (!empty($missingStocks)) {
    $pdo->rollBack(); $_SESSION['flash'] = 'กรุณากรอกยอดคงเหลือสำหรับรายการ: ' . implode(', ', $missingStocks); header('Location: withdraw.php'); exit;
  }

  if (!$hasItem) {
    // if no items, rollback and inform user
    $pdo->rollBack(); $_SESSION['flash'] = 'กรุณาระบุรายการและจำนวนก่อนบันทึก'; header('Location: withdraw.php'); exit;
  }

  // update status depending on action
  // update status depending on action
  if ($action === 'submit') {
    // ensure withdraw_no is set to id for any draft (id already exists)
    $stmtCheck = $pdo->prepare('SELECT withdraw_no FROM withdrawals WHERE id = ? FOR UPDATE');
    $stmtCheck->execute([$withdrawal_id]); $r = $stmtCheck->fetch();
    if (empty($r['withdraw_no'])) {
      $upd = $pdo->prepare('UPDATE withdrawals SET withdraw_no = ?, status = ? WHERE id = ?');
      $upd->execute([(int)$withdrawal_id,'submitted',$withdrawal_id]);
    } else {
      $upd = $pdo->prepare('UPDATE withdrawals SET status = ? WHERE id = ?'); $upd->execute(['submitted',$withdrawal_id]);
    }
  } else {
    $upd = $pdo->prepare('UPDATE withdrawals SET status = ? WHERE id = ?'); $upd->execute(['draft',$withdrawal_id]);
  }

  write_withdrawal_audit(
    $pdo,
    $user,
    (int)$withdrawal_id,
    $action === 'submit' ? 'submit' : 'save_draft',
    ['status' => null],
    ['status' => $action === 'submit' ? 'submitted' : 'draft', 'item_count' => count(array_filter($qtys, static fn($q) => (int)$q > 0))]
  );

  $pdo->commit();
  $_SESSION['flash'] = ($action === 'submit') ? 'ยืนยันใบเบิกเรียบร้อย' : 'บันทึกร่างเรียบร้อย';
  header('Location: dashboard.php'); exit;
} catch (Exception $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('submit_withdrawal.php error: ' . $e->getMessage());
  $_SESSION['flash'] = $e instanceof RuntimeException && !$e instanceof PDOException
    ? $e->getMessage() : 'เกิดข้อผิดพลาดในการบันทึกใบเบิก กรุณาลองใหม่';
  header('Location: withdraw.php'); exit;
}
