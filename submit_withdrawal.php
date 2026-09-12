<?php
require 'includes/db.php';
require 'includes/auth.php';
$user = require_login($pdo);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: withdraw.php'); exit; }
if (!verify_csrf($_POST['csrf_token'] ?? '')) { die('Invalid CSRF'); }

$user_id = $user['id'];
$host_code = $user['host_code'] ?? null;
$action = $_POST['action'] ?? 'submit';
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
  // find existing draft if any
  $stmtDraft = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1 FOR UPDATE');
  $stmtDraft->execute([$user_id,'draft']);
  $draft = $stmtDraft->fetch();

  if ($draft) {
    $withdrawal_id = $draft['id'];
  } else {
    // create new: only assign withdraw_no when action is 'submit'
    if ($action === 'submit') {
      // create row first (withdraw_no will be set to the new id afterwards)
      $stmtIns = $pdo->prepare('INSERT INTO withdrawals (host_code,user_id,withdraw_no,status) VALUES (?,?,NULL,?)');
      $stmtIns->execute([$host_code,$user_id,'submitted']);
    } else {
      $stmtIns = $pdo->prepare('INSERT INTO withdrawals (host_code,user_id,withdraw_no,status) VALUES (?,?,NULL,?)');
      $stmtIns->execute([$host_code,$user_id,'draft']);
    }
    $withdrawal_id = $pdo->lastInsertId();
    // set withdraw_no to the primary id to use id as reference
    try {
      $updNo = $pdo->prepare('UPDATE withdrawals SET withdraw_no = ? WHERE id = ?');
      $updNo->execute([(int)$withdrawal_id, $withdrawal_id]);
    } catch (Exception $e) {
      // not critical: continue, withdraw_no may be null but id exists
    }
  }

  // clear any existing items if re-saving
  $del = $pdo->prepare('DELETE FROM withdrawal_items WHERE withdrawal_id = ?'); $del->execute([$withdrawal_id]);
  // prepare insert: include current_stock_snapshot and pack/unit snapshots
  $insItem = $pdo->prepare('INSERT INTO withdrawal_items (withdrawal_id,drug_item_id,working_code_snapshot,quantity,note,current_stock_snapshot,pack_size_snapshot,unit_snapshot) VALUES (?,?,?,?,?,?,?,?)');
  $diLookup = $pdo->prepare('SELECT working_code, pack_size, unit FROM drug_item WHERE id = ? AND is_active = 1 LIMIT 1');
  $hasItem = false;
  $missingStocks = [];
  foreach ($qtys as $did => $q) {
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
    ['status' => $draft['status'] ?? null],
    ['status' => $action === 'submit' ? 'submitted' : 'draft', 'item_count' => count(array_filter($qtys, static fn($q) => (int)$q > 0))]
  );

  $pdo->commit();
  $_SESSION['flash'] = ($action === 'submit') ? 'ยืนยันใบเบิกเรียบร้อย' : 'บันทึกร่างเรียบร้อย';
  header('Location: dashboard.php'); exit;
} catch (Exception $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('submit_withdrawal.php error: ' . $e->getMessage());
  $_SESSION['flash'] = 'เกิดข้อผิดพลาดในการบันทึกใบเบิก กรุณาลองใหม่';
  header('Location: withdraw.php'); exit;
}
