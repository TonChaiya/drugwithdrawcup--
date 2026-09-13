<?php
require 'includes/db.php';
require 'includes/auth.php';
$currentUser = require_login($pdo);
$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo 'Invalid id'; exit; }

$stmt = $pdo->prepare('SELECT w.*, u.username, u.name as user_name, u.facility_name FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ?');
$stmt->execute([$id]); $w = $stmt->fetch();
if (!$w) { echo 'ไม่พบใบเบิก'; exit; }
$withdrawalStatusLabels = [
  'draft' => 'ฉบับร่าง',
  'submitted' => 'รออนุมัติ',
  'approved' => 'อนุมัติแล้ว',
  'rejected' => 'ปฏิเสธ/ยกเลิก',
];

function withdrawal_delivered_or_zero($value): ?int {
  if (is_string($value) && trim($value) === '') return 0;
  return bounded_non_negative_int($value, 1000000);
}

// Users may view withdrawals from their own facility (same host_code).
// Editing a draft remains restricted to its creator; managers may manage only
// records inside the server-side scope assigned to their account.
$isAdmin = is_manager($currentUser);
$isOwner = (int)$currentUser['id'] === (int)$w['user_id'];
$sameFacility = (string)$currentUser['host_code'] !== ''
  && hash_equals((string)$currentUser['host_code'], (string)$w['host_code']);
if (!can_access_host_code($pdo, $currentUser, (string)$w['host_code'])) {
  http_response_code(403);
  echo 'ไม่มีสิทธิ์เข้าถึงข้อมูลของสถานบริการอื่น'; exit;
}
$canCancelWithdrawal = (($isOwner && $w['status'] !== 'approved') || $isAdmin)
  && $w['status'] !== 'rejected';

// handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) { $_SESSION['flash']='Invalid CSRF'; header('Location: view_withdrawal.php?id=' . $id); exit; }
  $action = $_POST['action'] ?? '';
  // add item to draft
  if ($action === 'add_item' && $w['status'] === 'draft' && ($isOwner || $isAdmin)) {
    $drug_id = (int)($_POST['drug_item_id'] ?? 0);
    $qty = bounded_non_negative_int($_POST['quantity'] ?? '', 1000000);
    if ($qty === null || $qty < 1) {
      $_SESSION['flash'] = 'จำนวนขอเบิกต้องเป็นจำนวนเต็ม 1–1,000,000';
      header('Location: view_withdrawal.php?id=' . $id); exit;
    }

    // Preserve any unsaved edits in existing rows before adding a new item.
    $existingQtys = $_POST['qty'] ?? [];
    if (is_array($existingQtys) && !empty($existingQtys)) {
      $existingNotes = $_POST['note'] ?? [];
      $existingCurrents = $_POST['current_stock'] ?? [];
      $lookup = $pdo->prepare('SELECT wi.pack_size_snapshot, wi.unit_snapshot, d.pack_size, d.unit FROM withdrawal_items wi JOIN drug_item d ON wi.drug_item_id = d.id WHERE wi.id = ? AND wi.withdrawal_id = ? LIMIT 1');
      $upExisting = $pdo->prepare('UPDATE withdrawal_items SET quantity = ?, note = ?, current_stock_snapshot = ?, pack_size_snapshot = COALESCE(pack_size_snapshot, ?), unit_snapshot = COALESCE(unit_snapshot, ?) WHERE id = ? AND withdrawal_id = ?');
      foreach ($existingQtys as $wi_id => $existingQty) {
        $wi_id = (int)$wi_id;
        if ($wi_id <= 0) continue;
        $lookup->execute([$wi_id, $id]);
        $ld = $lookup->fetch();
        if (!$ld) continue;
        $existingQtyValue = bounded_non_negative_int($existingQty, 1000000);
        $currentRaw = $existingCurrents[$wi_id] ?? null;
        $currentVal = ($currentRaw === '' || $currentRaw === null) ? null : bounded_non_negative_int($currentRaw, 100000000);
        $existingNote = bounded_plain_text($existingNotes[$wi_id] ?? '', 255);
        if ($existingQtyValue === null || $existingNote === null || $currentVal === null && $currentRaw !== '' && $currentRaw !== null) {
          $_SESSION['flash'] = 'พบจำนวนหรือหมายเหตุที่ไม่ถูกต้อง';
          header('Location: view_withdrawal.php?id=' . $id); exit;
        }
        $packParam = $ld['pack_size_snapshot'] ?? $ld['pack_size'] ?? null;
        $unitParam = $ld['unit_snapshot'] ?? $ld['unit'] ?? '';
        $upExisting->execute([$existingQtyValue, $existingNote, $currentVal, $packParam, $unitParam, $wi_id, $id]);
      }
    }

    if ($drug_id > 0) {
      $dup = $pdo->prepare('SELECT id FROM withdrawal_items WHERE withdrawal_id = ? AND drug_item_id = ? LIMIT 1');
      $dup->execute([$id, $drug_id]);
      if ($dup->fetch()) {
        $_SESSION['flash'] = 'รายการนี้มีในใบเบิกแล้ว';
        header('Location: view_withdrawal.php?id=' . $id); exit;
      }
      // capture current pack_size and unit as a snapshot to preserve historical multipliers
      $diStmt = $pdo->prepare('SELECT working_code, pack_size, unit FROM drug_item WHERE id = ? AND is_active = 1 LIMIT 1');
      $diStmt->execute([$drug_id]); $di = $diStmt->fetch();
      if (!$di) {
        $_SESSION['flash'] = 'รายการยานี้ถูกระงับหรือไม่มีอยู่ในระบบ';
        header('Location: view_withdrawal.php?id=' . $id); exit;
      }
      $pack_snapshot = $di ? $di['pack_size'] : null;
      $unit_snapshot = $di ? $di['unit'] : '';
      // current stock not provided when adding via search; set to NULL
      $ins = $pdo->prepare('INSERT INTO withdrawal_items (withdrawal_id, drug_item_id, working_code_snapshot, quantity, delivered_quantity, note, current_stock_snapshot, pack_size_snapshot, unit_snapshot) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)');
      $ins->execute([$id, $drug_id, $di['working_code'], $qty, '', null, $pack_snapshot, $unit_snapshot]);
      write_withdrawal_audit($pdo, $currentUser, $id, 'add_item', null, ['drug_item_id'=>$drug_id,'quantity'=>$qty]);
      $_SESSION['flash'] = 'เพิ่มรายการเรียบร้อย';
    }
    header('Location: view_withdrawal.php?id=' . $id); exit;
  }

  // delete single item from draft
  if ($action === 'delete_item' && $w['status'] === 'draft' && ($isOwner || $isAdmin)) {
    if (($_POST['confirm_delete_item'] ?? '') !== '1') {
      $_SESSION['flash'] = 'คำสั่งลบรายการไม่สมบูรณ์';
      header('Location: view_withdrawal.php?id=' . $id); exit;
    }
    $wi = (int)($_POST['wi_id'] ?? 0);
    if ($wi > 0) {
      $oldItemStmt = $pdo->prepare('SELECT drug_item_id,quantity FROM withdrawal_items WHERE id=? AND withdrawal_id=? LIMIT 1');
      $oldItemStmt->execute([$wi,$id]);
      $oldItem = $oldItemStmt->fetch();
      $del = $pdo->prepare('DELETE FROM withdrawal_items WHERE id = ? AND withdrawal_id = ?'); $del->execute([$wi, $id]);
      if ($del->rowCount() === 1) write_withdrawal_audit($pdo, $currentUser, $id, 'delete_item', $oldItem ?: ['item_id'=>$wi], null);
      $_SESSION['flash'] = 'ลบรายการเรียบร้อย';
    }
    header('Location: view_withdrawal.php?id=' . $id); exit;
  }
  if ($action === 'approve' && $isAdmin) {
    $delivered = $_POST['delivered'] ?? [];
    $notes = $_POST['note'] ?? [];
    if (!is_array($delivered) || !is_array($notes) || count($delivered) > 1000 || count($notes) > 1000) {
      $_SESSION['flash'] = 'ข้อมูลรายการอนุมัติไม่ถูกต้องหรือมีจำนวนมากเกินไป';
      header('Location: view_withdrawal.php?id=' . $id); exit;
    }
    foreach ($delivered as $wiId => $dq) {
      if ((int)$wiId <= 0 || withdrawal_delivered_or_zero($dq) === null) {
        $_SESSION['flash'] = 'จำนวนจ่ายจริงต้องเป็นจำนวนเต็ม 0–1,000,000';
        header('Location: view_withdrawal.php?id=' . $id); exit;
      }
    }
    foreach ($notes as $note) {
      if (bounded_plain_text($note, 255) === null) {
        $_SESSION['flash'] = 'หมายเหตุต้องไม่เกิน 255 ตัวอักษร';
        header('Location: view_withdrawal.php?id=' . $id); exit;
      }
    }
    $pdo->beginTransaction();
    try {
      $lockWithdrawal = $pdo->prepare('SELECT status FROM withdrawals WHERE id = ? FOR UPDATE');
      $lockWithdrawal->execute([$id]);
      $lockedStatus = (string)$lockWithdrawal->fetchColumn();
      if ($lockedStatus !== 'submitted') {
        throw new RuntimeException('อนุมัติได้เฉพาะใบเบิกที่อยู่ระหว่างรออนุมัติ');
      }
      $expectedStmt = $pdo->prepare('SELECT id FROM withdrawal_items WHERE withdrawal_id=? ORDER BY id FOR UPDATE');
      $expectedStmt->execute([$id]);
      $expectedIds = array_map('intval', $expectedStmt->fetchAll(PDO::FETCH_COLUMN));
      $postedIds = array_map('intval', array_keys($delivered));
      sort($postedIds);
      if (!$expectedIds || $postedIds !== $expectedIds) throw new RuntimeException('รายการอนุมัติไม่ครบหรือไม่ตรงกับใบเบิก กรุณาโหลดหน้าใหม่');
      // For each item, ensure we persist delivered, note, and set pack/unit snapshot if missing
      $lookup = $pdo->prepare('SELECT wi.quantity, wi.pack_size_snapshot, wi.unit_snapshot, d.pack_size, d.unit FROM withdrawal_items wi JOIN drug_item d ON wi.drug_item_id = d.id WHERE wi.id = ? AND wi.withdrawal_id = ? LIMIT 1');
      $upItem = $pdo->prepare('UPDATE withdrawal_items SET delivered_quantity = ?, note = ?, pack_size_snapshot = COALESCE(pack_size_snapshot, ?), unit_snapshot = COALESCE(unit_snapshot, ?) WHERE id = ? AND withdrawal_id = ?');
      foreach ($delivered as $wi_id => $dq) {
        $n = bounded_plain_text($notes[$wi_id] ?? '', 255);
        if ($n === null) throw new RuntimeException('หมายเหตุต้องไม่เกิน 255 ตัวอักษร');
        $lookup->execute([(int)$wi_id, $id]); $ld = $lookup->fetch();
        if (!$ld) throw new RuntimeException('พบรายการที่ไม่ได้อยู่ในใบเบิกนี้');
        $deliveredValue = withdrawal_delivered_or_zero($dq);
        if ($deliveredValue === null || $deliveredValue > (int)$ld['quantity']) throw new RuntimeException('จำนวนจ่ายจริงต้องไม่มากกว่าจำนวนที่ขอเบิก');
        $packParam = $ld['pack_size_snapshot'] ?? $ld['pack_size'] ?? null;
        $unitParam = $ld['unit_snapshot'] ?? $ld['unit'] ?? '';
        $upItem->execute([$deliveredValue, $n, $packParam, $unitParam, (int)$wi_id, $id]);
      }
      $upd = $pdo->prepare('UPDATE withdrawals SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?');
      $upd->execute(['approved', $_SESSION['user']['id'], $id]);
      write_withdrawal_audit($pdo, $currentUser, $id, 'approve', ['status' => 'submitted'], ['status' => 'approved']);
      $pdo->commit(); $_SESSION['flash']='อนุมัติเรียบร้อย'; header('Location: admin_all_withdrawals.php'); exit;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('approve withdrawal failed: '.$e->getMessage()); $_SESSION['flash']=$e instanceof RuntimeException ? $e->getMessage() : 'เกิดข้อผิดพลาด'; header('Location: view_withdrawal.php?id='.$id); exit; }
  }

  // save (owner may save draft; admin may save any non-approved)
  if ($action === 'save' && (($isOwner && $w['status'] === 'draft') || ($isAdmin && in_array($w['status'], ['draft','submitted'], true)))) {
    $qtys = $_POST['qty'] ?? [];
    $notes = $_POST['note'] ?? [];
    $currents = $_POST['current_stock'] ?? [];
    $newDrugIds = $_POST['new_drug_item_id'] ?? [];
    $newQtys = $_POST['new_qty'] ?? [];
    $newNotes = $_POST['new_note'] ?? [];
    $newCurrents = $_POST['new_current_stock'] ?? [];
    $collections = [$qtys, $notes, $currents, $newDrugIds, $newQtys, $newNotes, $newCurrents];
    foreach ($collections as $collection) {
      if (!is_array($collection) || count($collection) > 1000) {
        $_SESSION['flash'] = 'ข้อมูลรายการไม่ถูกต้องหรือมีจำนวนมากเกินไป';
        header('Location: view_withdrawal.php?id=' . $id); exit;
      }
    }

    $pdo->beginTransaction();
    try {
      $lockWithdrawal = $pdo->prepare('SELECT status FROM withdrawals WHERE id=? FOR UPDATE');
      $lockWithdrawal->execute([$id]);
      $lockedStatus = (string)$lockWithdrawal->fetchColumn();
      $maySave = ($isOwner && $lockedStatus === 'draft') || ($isAdmin && in_array($lockedStatus, ['draft','submitted'], true));
      if (!$maySave) throw new RuntimeException('สถานะใบเบิกเปลี่ยนไปแล้ว กรุณาโหลดหน้าใหม่');

      $itemStmt = $pdo->prepare('SELECT wi.id, wi.quantity, wi.pack_size_snapshot, wi.unit_snapshot, d.pack_size, d.unit FROM withdrawal_items wi JOIN drug_item d ON d.id=wi.drug_item_id WHERE wi.withdrawal_id=? FOR UPDATE');
      $itemStmt->execute([$id]);
      $itemMap = [];
      foreach ($itemStmt->fetchAll() as $row) $itemMap[(int)$row['id']] = $row;
      $up = $pdo->prepare('UPDATE withdrawal_items SET quantity=?, note=?, current_stock_snapshot=?, pack_size_snapshot=COALESCE(pack_size_snapshot,?), unit_snapshot=COALESCE(unit_snapshot,?) WHERE id=? AND withdrawal_id=?');

      foreach ($qtys as $wiIdRaw => $qtyRaw) {
        $wiId = (int)$wiIdRaw;
        if (!isset($itemMap[$wiId])) throw new RuntimeException('พบรายการที่ไม่ได้อยู่ในใบเบิกนี้');
        $qty = bounded_non_negative_int($qtyRaw, 1000000);
        $note = bounded_plain_text($notes[$wiIdRaw] ?? '', 255);
        $currentRaw = $currents[$wiIdRaw] ?? null;
        $current = ($currentRaw === '' || $currentRaw === null) ? null : bounded_non_negative_int($currentRaw, 100000000);
        if ($qty === null || $note === null || ($current === null && $currentRaw !== '' && $currentRaw !== null)) {
          throw new RuntimeException('จำนวนต้องเป็นเลขไม่ติดลบและหมายเหตุต้องไม่เกิน 255 ตัวอักษร');
        }
        $row = $itemMap[$wiId];
        $up->execute([$qty, $note, $current, $row['pack_size_snapshot'] ?? $row['pack_size'], $row['unit_snapshot'] ?? $row['unit'], $wiId, $id]);
        $itemMap[$wiId]['quantity'] = $qty;
      }

      $diStmt = $pdo->prepare('SELECT working_code, pack_size, unit FROM drug_item WHERE id=? AND is_active=1 LIMIT 1');
      $dup = $pdo->prepare('SELECT id FROM withdrawal_items WHERE withdrawal_id=? AND drug_item_id=? LIMIT 1');
      $ins = $pdo->prepare('INSERT INTO withdrawal_items (withdrawal_id,drug_item_id,working_code_snapshot,quantity,delivered_quantity,note,current_stock_snapshot,pack_size_snapshot,unit_snapshot) VALUES (?,?,?,?,0,?,?,?,?)');
      $insertedDrugIds = [];
      foreach ($newDrugIds as $key => $newDrugIdRaw) {
        $newDrugId = (int)$newDrugIdRaw;
        if ($newDrugId <= 0 || in_array($newDrugId, $insertedDrugIds, true)) continue;
        $newQty = bounded_non_negative_int($newQtys[$key] ?? '', 1000000);
        $newNote = bounded_plain_text($newNotes[$key] ?? '', 255);
        $newCurrentRaw = $newCurrents[$key] ?? null;
        $newCurrent = ($newCurrentRaw === '' || $newCurrentRaw === null) ? null : bounded_non_negative_int($newCurrentRaw, 100000000);
        if ($newQty === null || $newQty < 1 || $newNote === null || ($newCurrent === null && $newCurrentRaw !== '' && $newCurrentRaw !== null)) {
          throw new RuntimeException('ข้อมูลรายการยาใหม่ไม่ถูกต้อง');
        }
        $dup->execute([$id, $newDrugId]);
        if ($dup->fetch()) continue;
        $diStmt->execute([$newDrugId]); $di = $diStmt->fetch();
        if (!$di) throw new RuntimeException('รายการยาใหม่ถูกระงับหรือไม่มีอยู่แล้ว');
        $ins->execute([$id,$newDrugId,$di['working_code'],$newQty,$newNote,$newCurrent,$di['pack_size'],$di['unit']]);
        $insertedDrugIds[] = $newDrugId;
      }

      if ($isAdmin && $lockedStatus === 'submitted' && isset($_POST['delivered'])) {
        $delivered = $_POST['delivered'];
        if (!is_array($delivered) || count($delivered) > 1000) throw new RuntimeException('ข้อมูลจำนวนจ่ายจริงไม่ถูกต้อง');
        $upDelivered = $pdo->prepare('UPDATE withdrawal_items SET delivered_quantity=? WHERE id=? AND withdrawal_id=?');
        foreach ($delivered as $wiIdRaw => $deliveredRaw) {
          $wiId = (int)$wiIdRaw;
          $deliveredValue = withdrawal_delivered_or_zero($deliveredRaw);
          if (!isset($itemMap[$wiId]) || $deliveredValue === null || $deliveredValue > (int)$itemMap[$wiId]['quantity']) {
            throw new RuntimeException('จำนวนจ่ายจริงต้องไม่มากกว่าจำนวนที่ขอเบิก');
          }
          $upDelivered->execute([$deliveredValue, $wiId, $id]);
        }
      }

      $countPositive = $pdo->prepare('SELECT COUNT(*) FROM withdrawal_items WHERE withdrawal_id=? AND quantity>0');
      $countPositive->execute([$id]);
      if ((int)$countPositive->fetchColumn() < 1) throw new RuntimeException('ใบเบิกต้องมีรายการที่จำนวนมากกว่า 0 อย่างน้อยหนึ่งรายการ');
      write_withdrawal_audit($pdo, $currentUser, $id, 'save_items', ['status'=>$lockedStatus], ['status'=>$lockedStatus]);
      $pdo->commit();
      $_SESSION['flash'] = 'บันทึกเรียบร้อย';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('save withdrawal failed: '.$e->getMessage());
      $_SESSION['flash'] = $e instanceof RuntimeException ? $e->getMessage() : 'เกิดข้อผิดพลาดในการบันทึก';
    }
    header('Location: view_withdrawal.php?id=' . $id); exit;
  }

  // submit (owner draft or admin) - assign withdraw_no if not set
  if ($action === 'submit' && $w['status'] === 'draft' && ($isOwner || $isAdmin)) {
    $pdo->beginTransaction();
    try {
      $stmtCheck = $pdo->prepare('SELECT withdraw_no, status FROM withdrawals WHERE id = ? FOR UPDATE');
      $stmtCheck->execute([$id]); $r = $stmtCheck->fetch();
      if (!$r || (string)$r['status'] !== 'draft') throw new RuntimeException('ส่งได้เฉพาะใบเบิกฉบับร่าง');
      if (empty($r['withdraw_no'])) {
        // set withdraw_no to the primary id
        $upd = $pdo->prepare('UPDATE withdrawals SET withdraw_no = ?, status = ? WHERE id = ?');
        $upd->execute([(int)$id,'submitted',$id]);
      } else {
        $upd = $pdo->prepare('UPDATE withdrawals SET status = ? WHERE id = ?'); $upd->execute(['submitted',$id]);
      }
      write_withdrawal_audit($pdo, $currentUser, $id, 'submit', ['status'=>'draft'], ['status'=>'submitted']);
      $pdo->commit();
      $_SESSION['flash'] = 'ส่งใบเบิกเพื่อรออนุมัติแล้ว'; header('Location: dashboard.php'); exit;
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $_SESSION['flash']='เกิดข้อผิดพลาด'; header('Location: view_withdrawal.php?id='.$id); exit; }
  }

// change to rejected instead of delete
if ($action === 'delete' && (($isOwner && $w['status'] !== 'approved') || $isAdmin)) {
    if ($w['status'] === 'rejected') {
      $_SESSION['flash'] = 'ใบเบิกนี้ถูกยกเลิกไปแล้ว';
      header('Location: view_withdrawal.php?id=' . $id); exit;
    }
    if (($_POST['confirm_cancel_withdrawal'] ?? '') !== '1') {
      $_SESSION['flash'] = 'ยังไม่ได้ยืนยันการยกเลิกใบเบิก';
      header('Location: view_withdrawal.php?id=' . $id); exit;
    }

    $confirmationHostCode = trim((string)($_POST['cancel_host_code'] ?? ''));
    $expectedHostCode = trim((string)$w['host_code']);
    if (!preg_match('/^[0-9]{5}$/', $confirmationHostCode)
        || !preg_match('/^[0-9]{5}$/', $expectedHostCode)
        || !hash_equals($expectedHostCode, $confirmationHostCode)) {
      $_SESSION['flash'] = 'รหัสสถานบริการไม่ถูกต้อง ระบบจึงไม่ยกเลิกใบเบิก';
      header('Location: view_withdrawal.php?id=' . $id); exit;
    }

    $pdo->beginTransaction();
    try {
      $lock = $pdo->prepare('SELECT status FROM withdrawals WHERE id=? FOR UPDATE');
      $lock->execute([$id]);
      $oldStatus = (string)$lock->fetchColumn();
      if (!withdrawal_transition_allowed($oldStatus, 'rejected')) throw new RuntimeException('สถานะปัจจุบันไม่สามารถยกเลิกได้');
      $upd = $pdo->prepare("UPDATE withdrawals SET status='rejected', approved_by=NULL, approved_at=NULL WHERE id=? AND status=?");
      $upd->execute([$id,$oldStatus]);
      if ($upd->rowCount() !== 1) throw new RuntimeException('สถานะถูกเปลี่ยนโดยผู้ใช้อื่น กรุณาลองใหม่');
      write_withdrawal_audit($pdo, $currentUser, $id, 'cancel', ['status'=>$oldStatus], ['status'=>'rejected']);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $_SESSION['flash'] = $e instanceof RuntimeException ? $e->getMessage() : 'เกิดข้อผิดพลาดในการยกเลิกใบเบิก';
      header('Location: view_withdrawal.php?id='.$id); exit;
    }

    $_SESSION['flash'] = 'ใบเบิกถูกยกเลิกแล้ว';
    header('Location: dashboard.php');
    exit;
}


  // admin change status
  if ($action === 'change_status' && $isAdmin) {
    if (($_POST['confirm_change_status'] ?? '') !== '1') {
      $_SESSION['flash'] = 'ยังไม่ได้ยืนยันการเปลี่ยนสถานะ';
      header('Location: view_withdrawal.php?id=' . $id); exit;
    }
    $new = $_POST['new_status'] ?? '';
    $allowed = ['draft','submitted','approved','rejected'];
    if ($new === 'approved') {
      $_SESSION['flash'] = 'การอนุมัติต้องใช้ปุ่มอนุมัติและระบุจำนวนจ่ายจริง';
      header('Location: view_withdrawal.php?id='.$id); exit;
    }
    if (in_array($new, $allowed, true)) {
      $pdo->beginTransaction();
      try {
        $stmtCheck = $pdo->prepare('SELECT withdraw_no,status FROM withdrawals WHERE id=? FOR UPDATE');
        $stmtCheck->execute([$id]); $locked = $stmtCheck->fetch();
        $oldStatus = (string)($locked['status'] ?? '');
        if (!withdrawal_transition_allowed($oldStatus, (string)$new)) throw new RuntimeException('ไม่อนุญาตให้เปลี่ยนจากสถานะปัจจุบันไปยังสถานะที่เลือก');
        $withdrawNo = $locked['withdraw_no'] ?? null;
        if ($new === 'submitted' && empty($withdrawNo)) $withdrawNo = $id;
        $upd = $pdo->prepare('UPDATE withdrawals SET withdraw_no=?,status=?,approved_by=NULL,approved_at=NULL WHERE id=? AND status=?');
        $upd->execute([$withdrawNo,$new,$id,$oldStatus]);
        if ($upd->rowCount() !== 1) throw new RuntimeException('สถานะถูกเปลี่ยนโดยผู้ใช้อื่น กรุณาลองใหม่');
        write_withdrawal_audit($pdo, $currentUser, $id, 'change_status', ['status'=>$oldStatus], ['status'=>$new]);
        $pdo->commit();
        $_SESSION['flash']='เปลี่ยนสถานะเรียบร้อย';
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash']=$e instanceof RuntimeException ? $e->getMessage() : 'เกิดข้อผิดพลาด';
      }
      header('Location: view_withdrawal.php?id='.$id); exit;
    }
    $_SESSION['flash'] = 'สถานะที่เลือกไม่ถูกต้อง ระบบจึงไม่เปลี่ยนแปลงข้อมูล';
    header('Location: view_withdrawal.php?id=' . $id); exit;
  }
}

$stmtItems = $pdo->prepare('SELECT wi.id as wi_id, wi.drug_item_id, wi.quantity,wi.current_stock_snapshot, wi.delivered_quantity, wi.note, wi.pack_size_snapshot, wi.unit_snapshot, wi.working_code_snapshot AS working_code, d.name, d.pack_size as current_pack, d.unit as current_unit FROM withdrawal_items wi JOIN drug_item d ON wi.drug_item_id = d.id WHERE wi.withdrawal_id = ? ORDER BY wi.id DESC');
$stmtItems->execute([$id]); $items = $stmtItems->fetchAll();

// --- แยกกลุ่มรายการสำหรับใบเบิกที่อนุมัติแล้ว ---
$approvedItems = [];
$rejectedItems = [];

foreach ($items as $it) {
    $dq = (int)$it['delivered_quantity'];
    $note = trim($it['note']);

    if ($dq > 0) {
    // จ่ายจริง > 0 = อนุมัติ
    $approvedItems[] = $it;
} else {
    // จ่าย 0 = ไม่อนุมัติ (จะมี note หรือไม่มี ก็ถือว่าไม่อนุมัติ)
    $rejectedItems[] = $it;
}

}


// Compute last calendar month's totals for these drugs (quantity * historical pack_size snapshot when available)
$monthlyTotals = []; // keys: 1 => last month, 2 => 2 months ago, 3 => 3 months ago
$drugIds = array_values(array_unique(array_column($items, 'drug_item_id')));
if (!empty($drugIds)) {
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
    // start = first day of N months ago; end = first day of (N-1) months ago (for N=1 end=this month)
    $start = date('Y-m-01', strtotime(sprintf('first day of -%d month', $i)));
    if ($i === 1) { $end = date('Y-m-01'); } else { $end = date('Y-m-01', strtotime(sprintf('first day of -%d month', $i-1))); }
    $params = array_merge([$start, $end, $w['host_code']], $drugIds);
    $stmtTot->execute($params);
    while ($r = $stmtTot->fetch()) { $monthlyTotals[$i][(int)$r['drug_item_id']] = (float)$r['last_month_total']; }
    $stmtTot->closeCursor();
  }
}
// build month labels in Thai BE two-digit year format (e.g., 68-09)
$monthLabels = [];
for ($i = 1; $i <= 3; $i++) {
  $ts = strtotime(sprintf('first day of -%d month', $i));
  $gYear = (int)date('Y', $ts);
  $beYear = $gYear + 543;
  $yy = substr((string)$beYear, -2);
  $mm = date('m', $ts);
  $monthLabels[$i] = $yy . '-' . $mm;
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><script src="https://cdn.tailwindcss.com"></script><title>ใบเบิก</title>
<style>
  tr.highlight-duplicate {
    background-color: #fef08a !important;
    box-shadow: inset 0 0 10px rgba(220, 38, 38, 0.3);
    border: 2px solid #dc2626;
  }
  #noteEditModal.hidden {
    display: none;
  }
</style>
</head>
<body class="app-page approval-page">
  <?php include __DIR__ . '/includes/nav.php'; ?>
  <main class="approval-workspace app-ui">
    <header class="approval-heading">
      <div class="approval-heading__title">
        <h1>ใบเบิกยา <?php echo e(format_withdraw_code($w['withdraw_no'])); ?></h1>
        <span class="approval-status approval-status--<?php echo e($w['status']); ?>"><?php echo e($withdrawalStatusLabels[$w['status']] ?? $w['status']); ?></span>
      </div>
      <p class="approval-heading__meta">
        <span><?php echo e($w['facility_name'] ?? ($w['user_name'] . ' (' . $w['username'] . ')')); ?></span>
        <span>สร้างโดย <?php echo e($w['user_name'] . ' (' . $w['username'] . ')'); ?></span>
        <span><?php echo e(format_thai_datetime($w['created_at'])); ?></span>
      </p>
      <div class="approval-heading__tools" aria-label="ส่งออกเอกสาร">
        <a href="printpdf/print_withdrawal.php?id=<?php echo $id; ?>" target="_blank" rel="noopener" class="approval-link">พิมพ์ใบเบิก (PDF)</a>
        <?php if ($w['status'] === 'approved'): ?>
          <a href="export_withdrawal_invc.php?id=<?php echo $id; ?>" class="approval-link"
             title="ใช้จำนวนจ่ายจริงที่อนุมัติแล้วคูณขนาดบรรจุที่บันทึกไว้ในใบเบิก">Export INVC (.xlsx)</a>
          <span class="approval-heading__export-note">ใช้จำนวนจ่ายจริงที่อนุมัติแล้ว</span>
        <?php endif; ?>
        <?php if ($canCancelWithdrawal || $isAdmin): ?>
          <div class="approval-heading__management" aria-label="การจัดการสถานะใบเบิก">
            <?php if ($isAdmin): ?>
              <button type="button" onclick="openStatusChangeModal()" class="approval-link">เปลี่ยนสถานะ</button>
            <?php endif; ?>
            <?php if ($canCancelWithdrawal): ?>
              <button type="button" onclick="openCancelWithdrawalModal()" class="approval-link approval-link--danger">ยกเลิกใบเบิก</button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </header>

    <?php if (!empty($_SESSION['flash'])): ?>
      <div class="approval-feedback" role="status"><?php echo e($_SESSION['flash']); ?></div>
      <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <section class="approval-list" aria-labelledby="approvalListTitle">
          <form id="items-form" method="post">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" id="items-action" value="">
          <div>
                <?php if($w['status'] === 'draft' && ($isOwner || $isAdmin)): ?>
                  <div class="approval-search">
                    <label for="drug-search">เพิ่มรายการยา</label>
                    <div class="approval-search__controls">
                      <input id="drug-search" type="search" placeholder="ค้นหารหัสหรือชื่อยา" autocomplete="off">
                      <button id="clear-search" type="button" class="approval-link">ล้าง</button>
                    </div>
                    <div id="search-results" class="approval-search__results hidden"></div>
                    <input type="hidden" id="csrf-token" value="<?php echo e(csrf_token()); ?>">
                  </div>
                <?php endif; ?>
            <h2 id="approvalListTitle" class="approval-section-title"><?php echo $w['status'] === 'approved' ? 'รายการที่อนุมัติเบิก' : 'รายการยาในใบเบิก'; ?> <small><?php echo count($w['status'] === 'approved' ? $approvedItems : $items); ?> รายการ</small></h2>
            <table class="approval-table" aria-label="<?php echo $w['status'] === 'approved' ? 'รายการที่อนุมัติเบิก' : 'รายการยาในใบเบิก'; ?>">
    <thead>
        <tr>
            <th scope="col">รหัสยา</th><th scope="col">รายการยา</th><th scope="col">คงเหลือ</th>
            <th scope="col">ขอเบิก</th><th scope="col">ขนาดบรรจุ</th><th scope="col">รวมขอ</th>
            <th scope="col" class="approval-table__delivered">จ่ายจริง</th><th scope="col">รวมจ่าย</th><th scope="col">หมายเหตุ</th>
        </tr>
    </thead>

    <tbody id="items-tbody">
<?php
// ถ้าอนุมัติแล้ว → ให้แสดงเฉพาะรายการอนุมัติในกลุ่มแรก
$showItems = ($w['status'] === 'approved') ? $approvedItems : $items;

foreach($showItems as $it):
?>

<?php
    $rawPack = trim((string)($it['pack_size_snapshot'] ?? $it['current_pack']));
    $unitVal = trim((string)($it['unit_snapshot'] ?? $it['current_unit']));
    $packNum = is_numeric($rawPack) ? (float)$rawPack : 0;
    $initialQty = (int)$it['quantity'];
    $initial_current_stock = (int)$it['current_stock_snapshot'];
    $initialTotal = $packNum * $initialQty;
?>
<tr class="approval-row" data-drug-id="<?php echo (int)$it['drug_item_id']; ?>">
    <td class="approval-row__code"><span><?php echo e($it['working_code']); ?></span></td>
    <td class="approval-row__medicine">
        <strong class="approval-row__name"><?php echo e($it['name']); ?></strong>

        <?php
            $colorClasses = [1=>'text-red-600',2=>'text-amber-600',3=>'text-emerald-600'];
            $partsHtml = [];
            for ($mi=1;$mi<=3;$mi++){
                $val = isset($monthlyTotals[$mi][$it['drug_item_id']]) ? (int)$monthlyTotals[$mi][$it['drug_item_id']] : 0;
                $cls = $colorClasses[$mi];
                $partsHtml[] = "<span class='{$cls}'>{$monthLabels[$mi]}={$val}</span>";
            }
            $rateHtml = "Rate 3เดือน " . implode(', ', $partsHtml);
        ?>
        <div class="approval-row__context">
          <span class="approval-row__mobile-pack">บรรจุ <?php echo e($rawPack . ' ' . $unitVal); ?></span>
          <span class="approval-row__history"><?php echo $rateHtml; ?> <?php echo e($unitVal); ?></span>
        </div>
    </td>

            <td class="approval-row__stock" data-label="คงเหลือ">
                <?php if($w['status']==='draft' && ($isOwner || $isAdmin)): ?>
                    <input type="number"
                           name="current_stock[<?php echo $it['wi_id']; ?>]"
                           value="<?php echo $initial_current_stock; ?>"
                           min="0" inputmode="numeric" aria-label="คงเหลือ <?php echo e($it['working_code']); ?>"
                           class="approval-number approval-number--stock"
                           data-packnum="<?php echo $packNum; ?>"
                           data-id="<?php echo $it['wi_id']; ?>">
                <?php else: ?>
                    <?php echo $initial_current_stock; ?>
                <?php endif; ?>
            </td>

            <td class="approval-row__requested" data-label="ขอเบิก">
                <?php if($w['status']==='draft' && ($isOwner || $isAdmin)): ?>
                    <input type="number"
                           name="qty[<?php echo $it['wi_id']; ?>]"
                           value="<?php echo $initialQty; ?>"
                           min="0" inputmode="numeric" aria-label="ขอเบิก <?php echo e($it['working_code']); ?>"
                           class="qty-input approval-number"
                           data-packnum="<?php echo $packNum; ?>"
                           data-unit="<?php echo e($unitVal); ?>"
                           data-id="<?php echo $it['wi_id']; ?>">
                <?php else: ?>
                    <?php echo $initialQty; ?>
                <?php endif; ?>
            </td>

            <td class="approval-row__pack" data-label="ขนาดบรรจุ">
                บรรจุ <?php echo e($rawPack . ' ' . $unitVal); ?>
            </td>

            <td class="approval-row__requested-total" data-label="รวมขอ" id="total-<?php echo $it['wi_id']; ?>">
                <?php echo e($initialTotal . ' ' . $unitVal); ?>
            </td>

            <td class="approval-row__delivered" data-label="จ่ายจริง">
                <?php if($isAdmin && $w['status']==='submitted'): ?>
                    <input type="number"
                      name="delivered[<?php echo $it['wi_id']; ?>]"
                      value="<?php echo (int)$it['delivered_quantity'] > 0 ? (int)$it['delivered_quantity'] : ''; ?>"
                      min="0" max="<?php echo $initialQty; ?>" inputmode="numeric"
                      aria-label="จ่ายจริง <?php echo e($it['working_code']); ?>"
                      title="เว้นว่างเพื่อไม่อนุมัติรายการนี้"
                      class="approval-number approval-number--delivered delivered-input"
                      data-packnum="<?php echo $packNum; ?>"
                      data-unit="<?php echo e($unitVal); ?>"
                      data-id="<?php echo $it['wi_id']; ?>">

                <?php else: ?>
                    <?php echo (int)$it['delivered_quantity']; ?>
                <?php endif; ?>
            </td>

            <td class="approval-row__delivered-total" data-label="รวมจ่าย" id="delivered-total-<?php echo $it['wi_id']; ?>">
                <?php echo e(($packNum * (int)$it['delivered_quantity']).' '.$unitVal); ?>
            </td>

            <td class="approval-row__note" data-label="หมายเหตุ">
                <div class="approval-row__note-content">

                    <div class="approval-row__note-field">
                    <?php if(($w['status']==='draft' && ($isOwner || $isAdmin)) || ($isAdmin && $w['status']==='submitted')): ?>
                        <input type="hidden"
                               name="note[<?php echo $it['wi_id']; ?>]"
                               value="<?php echo e($it['note']); ?>"
                               class="note-edit-value">
                        <input type="text"
                               value="<?php echo e($it['note']); ?>"
                               class="note-edit-input approval-note-input"
                               readonly
                               aria-label="แก้หมายเหตุ <?php echo e($it['working_code']); ?>"
                               data-note-id="<?php echo (int)$it['wi_id']; ?>"
                               data-note-title="<?php echo e($it['working_code'] . ' - ' . $it['name']); ?>"
                               data-note-subtitle="รายการ #<?php echo (int)$it['wi_id']; ?>">
                    <?php else: ?>
                        <div class="approval-note-readonly">
                            <?php echo e($it['note']); ?>
                        </div>
                    <?php endif; ?>
                    </div>

                    <!-- ดู note -->
                    <button type="button"
                      class="hidden inline-flex items-center justify-center text-sm text-blue-600 underline px-2 py-1 rounded hover:bg-blue-50"
                      onclick='showNoteModal(<?php echo json_encode((string)$it['note'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                      ดู
                    </button>

                    <!-- ลบรายการ -->
                    <?php if(($w['status']==='draft') && ($isOwner || $isAdmin)): ?>
                    <button type="button"
                      class="delete-item-btn approval-delete"
                      data-wi-id="<?php echo (int)$it['wi_id']; ?>">ลบ</button>
                    <?php endif; ?>

                </div>
            </td>

        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if($w['status'] === 'approved' && count($rejectedItems) > 0): ?>

<h2 class="approval-section-title approval-section-title--rejected">รายการที่ไม่ได้รับการอนุมัติ <small><?php echo count($rejectedItems); ?> รายการ</small></h2>

<table class="approval-table approval-table--rejected" aria-label="รายการที่ไม่ได้รับการอนุมัติ">
    <thead>
        <tr>
            <th scope="col">รหัสยา</th><th scope="col">รายการยา</th><th scope="col">คงเหลือ</th>
            <th scope="col">ขอเบิก</th><th scope="col">ขนาดบรรจุ</th><th scope="col">รวมขอ</th>
            <th scope="col">จ่ายจริง</th><th scope="col">รวมจ่าย</th><th scope="col">หมายเหตุ</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach($rejectedItems as $it): ?>
        <?php
            $rawPack = trim((string)($it['pack_size_snapshot'] ?? $it['current_pack']));
            $unitVal = trim((string)($it['unit_snapshot'] ?? $it['current_unit']));
            $packNum = is_numeric($rawPack) ? (float)$rawPack : 0;
            $initialQty = (int)$it['quantity'];
            $initial_current_stock = (int)$it['current_stock_snapshot'];
            $initialTotal = $packNum * $initialQty;
        ?>
        <tr class="approval-row">
            <td class="approval-row__code"><span><?php echo e($it['working_code']); ?></span></td>
            <td class="approval-row__medicine">
              <strong class="approval-row__name"><?php echo e($it['name']); ?></strong>
              <div class="approval-row__context">
                <span class="approval-row__mobile-pack">บรรจุ <?php echo e($rawPack . ' ' . $unitVal); ?></span>
                <span class="approval-row__history">Rate 3เดือน
                <?php for ($mi = 1; $mi <= 3; $mi++): ?>
                  <?php echo e($monthLabels[$mi]); ?>=<?php echo (int)($monthlyTotals[$mi][$it['drug_item_id']] ?? 0); ?><?php echo $mi < 3 ? ' · ' : ''; ?>
                <?php endfor; ?> <?php echo e($unitVal); ?></span>
              </div>
            </td>
            <td class="approval-row__stock" data-label="คงเหลือ"><?php echo $initial_current_stock; ?></td>
            <td class="approval-row__requested" data-label="ขอเบิก"><?php echo $initialQty; ?></td>
            <td class="approval-row__pack" data-label="ขนาดบรรจุ">บรรจุ <?php echo e($rawPack . ' ' . $unitVal); ?></td>
            <td class="approval-row__requested-total" data-label="รวมขอ"><?php echo e($initialTotal . ' ' . $unitVal); ?></td>
            <td class="approval-row__delivered" data-label="จ่ายจริง">0</td>
            <td class="approval-row__delivered-total" data-label="รวมจ่าย">0 <?php echo e($unitVal); ?></td>
            <td class="approval-row__note" data-label="หมายเหตุ"><span class="approval-note-readonly"><?php echo e($it['note']); ?></span></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php endif; ?>



          </div>
            </form>
            <form id="delete-item-form" method="post" class="hidden">
              <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="delete_item">
              <input type="hidden" name="confirm_delete_item" value="1">
              <input type="hidden" name="wi_id" id="delete-item-id" value="">
            </form>
            <script>
              (function(){
                const itemsForm = document.getElementById('items-form');
                const itemsAction = document.getElementById('items-action');
                const deleteForm = document.getElementById('delete-item-form');
                const deleteItemId = document.getElementById('delete-item-id');

                if (itemsForm && itemsAction) {
                  itemsForm.addEventListener('keydown', function(e){
                    if (e.key !== 'Enter') return;
                    const tag = (e.target && e.target.tagName ? e.target.tagName : '').toLowerCase();
                    if (tag === 'textarea') return;
                    e.preventDefault();
                    e.stopPropagation();
                  });

                  itemsForm.addEventListener('submit', function(e){
                    if (!itemsAction.value) {
                      e.preventDefault();
                      e.stopPropagation();
                    }
                  });
                }

                document.querySelectorAll('.delete-item-btn').forEach(function(btn){
                  btn.addEventListener('click', function(){
                    if (!deleteForm || !deleteItemId) return;
                    if (!confirm('ลบรายการนี้?')) return;
                    deleteItemId.value = btn.getAttribute('data-wi-id') || '';
                    if (deleteItemId.value) deleteForm.submit();
                  });
                });
              })();
            </script>
            <script>
              (function(){
                const search = document.getElementById('drug-search');
                const results = document.getElementById('search-results');
                const csrfToken = document.getElementById('csrf-token') ? document.getElementById('csrf-token').value : '';
                const clearBtn = document.getElementById('clear-search');
                if (!search) return;
                let timeout = null;
                let latestResults = [];
                
                // ฟังก์ชันเพื่อรวบรวม drug_item_id ทั้งหมดที่อยู่ในรายการ
                function getExistingDrugIds() {
                  const existingIds = [];
                  document.querySelectorAll('input[name^="qty["]').forEach(inp => {
                    const wiId = inp.getAttribute('data-id');
                    // หาแถวที่มี data-drug-id เพื่อเก็บ drug_item_id
                    const row = inp.closest('tr');
                    if (row) {
                      const drugIdAttr = row.getAttribute('data-drug-id');
                      if (drugIdAttr) existingIds.push(parseInt(drugIdAttr));
                    }
                  });
                  return existingIds;
                }

                function escapeHtml(value) {
                  return String(value ?? '').replace(/[&<>"']/g, function(ch) {
                    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[ch];
                  });
                }

                function formatPendingNumber(n) {
                  if (Number.isInteger(n)) return String(n);
                  return n.toFixed(2).replace(/\.00$/, '');
                }

                function addPendingRow(drug) {
                  const tbody = document.getElementById('items-tbody');
                  if (!tbody) return;
                  const token = 'new_' + Date.now() + '_' + Math.random().toString(36).slice(2);
                  const packRaw = drug.pack_size || '';
                  const packNum = parseFloat(packRaw);
                  const safePackNum = Number.isFinite(packNum) ? packNum : 0;
                  const unit = drug.unit || '';
                  const row = document.createElement('tr');
                  row.className = 'approval-row approval-row--pending';
                  row.setAttribute('data-drug-id', drug.id);
                  row.setAttribute('data-pending-row', '1');
                  row.innerHTML = `
                    <td class="approval-row__code">
                      <span>${escapeHtml(drug.working_code || '')}</span>
                      <input type="hidden" name="new_drug_item_id[${token}]" value="${escapeHtml(drug.id)}">
                    </td>
                    <td class="approval-row__medicine">
                      <strong class="approval-row__name">${escapeHtml(drug.name || '')}</strong>
                      <div class="approval-row__context"><span class="approval-row__mobile-pack">บรรจุ ${escapeHtml(packRaw)} ${escapeHtml(unit)}</span><span>ใหม่ · รอบันทึก</span></div>
                    </td>
                    <td class="approval-row__stock" data-label="คงเหลือ">
                      <input type="number" name="new_current_stock[${token}]" value="0" min="0" inputmode="numeric" aria-label="คงเหลือ ${escapeHtml(drug.working_code || '')}" class="approval-number approval-number--stock">
                    </td>
                    <td class="approval-row__requested" data-label="ขอเบิก">
                      <input type="number" name="new_qty[${token}]" value="1" min="0" inputmode="numeric" aria-label="ขอเบิก ${escapeHtml(drug.working_code || '')}"
                        class="qty-input approval-number"
                        data-packnum="${escapeHtml(safePackNum)}" data-unit="${escapeHtml(unit)}" data-id="${token}">
                    </td>
                    <td class="approval-row__pack" data-label="ขนาดบรรจุ">บรรจุ ${escapeHtml(packRaw)} ${escapeHtml(unit)}</td>
                    <td class="approval-row__requested-total" data-label="รวมขอ" id="total-${token}">${formatPendingNumber(safePackNum)}${unit ? ' ' + escapeHtml(unit) : ''}</td>
                    <td class="approval-row__delivered" data-label="จ่ายจริง">0</td>
                    <td class="approval-row__delivered-total" data-label="รวมจ่าย">0${unit ? ' ' + escapeHtml(unit) : ''}</td>
                    <td class="approval-row__note" data-label="หมายเหตุ">
                      <div class="approval-row__note-content">
                        <input type="text" name="new_note[${token}]" value="" aria-label="หมายเหตุ ${escapeHtml(drug.working_code || '')}" class="approval-note-input">
                        <button type="button" class="remove-pending-item approval-delete">ลบ</button>
                      </div>
                    </td>
                  `;
                  tbody.prepend(row);
                  const qtyInput = row.querySelector('.qty-input');
                  const total = row.querySelector('[id="total-' + token + '"]');
                  if (qtyInput && total) {
                    qtyInput.addEventListener('input', function() {
                      const qty = parseFloat(qtyInput.value || '0');
                      const totalValue = (Number.isFinite(qty) ? qty : 0) * safePackNum;
                      total.textContent = formatPendingNumber(totalValue) + (unit ? ' ' + unit : '');
                    });
                  }
                  row.querySelector('.remove-pending-item')?.addEventListener('click', function() {
                    row.remove();
                  });
                  row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                
                // Prevent Enter from submitting the surrounding form when focused in the search box
                search.addEventListener('keydown', function(e){
                  if (e.key === 'Enter') { e.preventDefault(); return false; }
                });

                results.addEventListener('click', function(e){
                  const el = e.target.closest('div[data-id]');
                  if (!el || !results.contains(el)) return;
                  e.preventDefault();
                  e.stopPropagation();
                  e.stopImmediatePropagation();
                  const id = el.getAttribute('data-id');
                  const existingIds = [];
                  document.querySelectorAll('tr[data-drug-id]').forEach(function(row) {
                    const rowDrugId = row.getAttribute('data-drug-id');
                    if (rowDrugId) existingIds.push(parseInt(rowDrugId));
                  });
                  if (existingIds.includes(parseInt(id))) {
                    alert('รายการนี้มีในรายการแล้ว!');
                    document.querySelectorAll('tr[data-drug-id="' + id + '"]').forEach(function(row) {
                      row.classList.add('highlight-duplicate');
                      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                      setTimeout(function(){ row.classList.remove('highlight-duplicate'); }, 3000);
                    });
                    return;
                  }
                  const index = Array.prototype.indexOf.call(results.querySelectorAll('div[data-id]'), el);
                  const drug = latestResults[index];
                  if (!drug) return;
                  addPendingRow(drug);
                  search.value = '';
                  results.classList.add('hidden');
                  results.innerHTML = '';
                  latestResults = [];
                }, true);

                search.addEventListener('input', function(){
                  clearTimeout(timeout);
                  const q = this.value.trim();
                  if (q.length < 1) { latestResults = []; results.classList.add('hidden'); results.innerHTML = ''; return; }
                  timeout = setTimeout(()=>{
                    fetch('search_drugs.php?q=' + encodeURIComponent(q)).then(r=>r.json()).then(data=>{
                      if (!Array.isArray(data) || data.length === 0) { results.innerHTML = '<div class="p-2 text-sm text-gray-500">ไม่พบรายการ</div>'; results.classList.remove('hidden'); return; }
                      latestResults = data;
                      results.replaceChildren();
                      data.forEach(d => {
                        const option = document.createElement('div');
                        option.className = 'p-2 hover:bg-slate-100 cursor-pointer text-sm';
                        option.dataset.id = String(Number.parseInt(d.id, 10) || 0);
                        option.textContent = (d.working_code ? '['+d.working_code+'] ' : '') + d.name + (d.pack_size ? ' - ' + d.pack_size : '');
                        results.appendChild(option);
                      });
                      results.classList.remove('hidden');
                      // attach click
                    results.querySelectorAll('div[data-id]').forEach(el=>{
                      el.addEventListener('click', ()=>{
                        const id = el.getAttribute('data-id');
                        const existingIds = getExistingDrugIds();
                        
                        // ตรวจสอบซ้ำ
                        if (existingIds.includes(parseInt(id))) {
                          alert('⚠️ รายการนี้มีในรายการแล้ว!');
                          // หารายการที่ซ้ำ และ scroll + highlight
                          const rows = document.querySelectorAll('tbody tr');
                          rows.forEach(row => {
                            if (row.getAttribute('data-drug-id') === id) {
                              row.classList.add('highlight-duplicate');
                              row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                              // เอาออก highlight หลังจาก 3 วินาที
                              setTimeout(() => {
                                row.classList.remove('highlight-duplicate');
                              }, 3000);
                            }
                          });
                          return;
                        }
                        
                        const drug = data.find(d => String(d.id) === String(id));
                        if (!drug) return;
                        addPendingRow(drug);
                        search.value = '';
                        results.classList.add('hidden');
                        results.innerHTML = '';
                      });
                    });
                    }).catch(()=>{ results.innerHTML = '<div class="p-2 text-sm text-red-500">เกิดข้อผิดพลาด</div>'; results.classList.remove('hidden'); });
                  }, 250);
                });
                clearBtn && clearBtn.addEventListener('click', ()=>{ search.value=''; results.classList.add('hidden'); results.innerHTML=''; });
              })();
            </script>
            <script>
              // live update delivered totals for delivered * packNum
              (function(){
                function formatNumber(n){
                  if (Number.isInteger(n)) return n.toString();
                  return n.toFixed(2).replace(/\.00$/, '');
                }
                function updateApprovalSummary(){
                  const inputs = document.querySelectorAll('.delivered-input');
                  const delivered = Array.from(inputs).filter(function(input){ return Number(input.value) > 0; }).length;
                  const deliveredCount = document.getElementById('approvalDeliveredCount');
                  const zeroCount = document.getElementById('approvalZeroCount');
                  if (deliveredCount) deliveredCount.textContent = String(delivered);
                  if (zeroCount) zeroCount.textContent = String(inputs.length - delivered);
                }
                document.querySelectorAll('.delivered-input').forEach(function(inp){
                  function update(){
                    var val = parseFloat(inp.value || '0');
                    if (!isFinite(val)) val = 0;
                    var pack = parseFloat(inp.getAttribute('data-packnum') || '0');
                    if (!isFinite(pack)) pack = 0;
                    var unit = inp.getAttribute('data-unit') || '';
                    var total = val * pack;
                    var id = inp.getAttribute('data-id');
                    var span = document.getElementById('delivered-total-' + id);
                    if (span){ span.textContent = formatNumber(total) + (unit ? ' ' + unit : ''); }
                  }
                  inp.addEventListener('input', update);
                  inp.addEventListener('input', updateApprovalSummary);
                  update();
                });
                updateApprovalSummary();
              })();
            </script>
            <script>
              // live update totals for qty * packNum
              (function(){
                function formatNumber(n){
                  if (Number.isInteger(n)) return n.toString();
                  return n.toFixed(2).replace(/\.00$/, '');
                }
                document.querySelectorAll('.qty-input').forEach(function(inp){
                  function update(){
                    var val = parseFloat(inp.value || '0');
                    if (!isFinite(val)) val = 0;
                    var pack = parseFloat(inp.getAttribute('data-packnum') || '0');
                    if (!isFinite(pack)) pack = 0;
                    var unit = inp.getAttribute('data-unit') || '';
                    var total = val * pack;
                    var id = inp.getAttribute('data-id');
                    var span = document.getElementById('total-' + id);
                    if (span){ span.textContent = formatNumber(total) + (unit ? ' ' + unit : ''); }
                  }
                  inp.addEventListener('input', update);
                  update();
                });
              })();
            </script>
            <script>
              // wire save button to submit the main items form with action=save
              document.addEventListener('DOMContentLoaded', function(){
                const saveBtn = document.getElementById('save-btn');
                const itemsForm = document.getElementById('items-form');
                const itemsAction = document.getElementById('items-action');
                if (saveBtn && itemsForm && itemsAction) {
                  saveBtn.addEventListener('click', function(){
                    if (!confirm('บันทึกการแก้ไขรายการใช่หรือไม่?')) return;
                    if (window.syncAllNoteFields) window.syncAllNoteFields();
                    itemsAction.value = 'save';
                    itemsForm.submit();
                  });
                }
              });
            </script>
            <script>
              // wire approve button to submit main form with action=approve
              document.addEventListener('DOMContentLoaded', function(){
                const approveBtn = document.getElementById('approve-btn');
                const itemsForm = document.getElementById('items-form');
                const itemsAction = document.getElementById('items-action');
                if (approveBtn && itemsForm && itemsAction) {
                  approveBtn.addEventListener('click', function(){
                    if (!confirm('อนุมัติใบเบิกและบันทึกจำนวนจ่ายจริงใช่หรือไม่?')) return;
                    if (window.syncAllNoteFields) window.syncAllNoteFields();
                    itemsAction.value = 'approve';
                    itemsForm.submit();
                  });
                }
              });
            </script>
    </section>

    <?php if ($isAdmin && $w['status'] === 'submitted'): ?>
      <section class="approval-summary" aria-labelledby="approvalSummaryTitle">
        <div>
          <h2 id="approvalSummaryTitle">สรุปการอนุมัติ</h2>
          <p aria-live="polite">รายการทั้งหมด <?php echo count($items); ?> • ระบุจำนวนจ่าย <span id="approvalDeliveredCount">0</span> • ไม่จ่าย <span id="approvalZeroCount"><?php echo count($items); ?></span></p>
          <p>ช่องจ่ายจริงที่เว้นว่าง = ไม่อนุมัติรายการนั้น</p>
        </div>
        <div class="approval-summary__actions">
          <button id="save-btn" type="button" class="approval-button approval-button--secondary">บันทึกการแก้ไข</button>
          <button id="approve-btn" type="button" class="approval-button approval-button--primary">อนุมัติใบเบิก</button>
        </div>
      </section>
    <?php elseif ($w['status'] === 'draft' && ($isOwner || $isAdmin)): ?>
      <section class="approval-summary" aria-label="การดำเนินการใบเบิก">
        <div>
          <h2>ตรวจสอบรายการก่อนส่ง</h2>
          <p>แก้จำนวนขอเบิกและบันทึกได้ในฉบับร่าง ส่วนจำนวนจ่ายจริงจะกรอกได้หลังส่งเพื่อขออนุมัติ</p>
        </div>
        <div class="approval-summary__actions">
          <?php if (($w['status'] === 'draft' && $isOwner) || $isAdmin): ?>
            <button id="save-btn" type="button" class="approval-button approval-button--secondary">บันทึกการแก้ไข</button>
          <?php endif; ?>
          <?php if ($w['status'] === 'draft' && ($isOwner || $isAdmin)): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
              <button name="action" value="submit" class="approval-button approval-button--primary">ส่งเพื่อขออนุมัติใบเบิก</button>
            </form>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

  </main>

<?php if ($canCancelWithdrawal): ?>
<!-- Cancel Withdrawal Modal -->
<div id="cancelWithdrawalModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-[70] p-4" role="dialog" aria-modal="true" aria-labelledby="cancelWithdrawalTitle">
  <button type="button" class="absolute inset-0 w-full h-full cursor-default" data-close-cancel-modal aria-label="ปิดหน้าต่าง"></button>
  <div class="relative bg-white rounded-2xl shadow-2xl border border-red-200 max-w-md w-full overflow-hidden">
    <div class="px-6 py-5 border-b border-slate-200">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs font-medium text-slate-500">ใบเบิก <?php echo e(format_withdraw_code($w['withdraw_no'])); ?></p>
          <h2 id="cancelWithdrawalTitle" class="text-xl font-semibold text-red-700 mt-1">ยืนยันยกเลิกใบเบิก</h2>
        </div>
        <button type="button" data-close-cancel-modal class="w-9 h-9 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-600 text-xl" aria-label="ปิด">&times;</button>
      </div>
    </div>

    <form id="cancelWithdrawalForm" method="post" class="p-6" autocomplete="off">
      <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="confirm_cancel_withdrawal" value="1">

      <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 mb-5">
        การยกเลิกจะเปลี่ยนสถานะใบเบิกเป็น “ปฏิเสธ/ยกเลิก” กรุณาตรวจสอบว่าเป็นใบเบิกที่ต้องการก่อนดำเนินการ
      </div>

      <label for="cancelHostCode" class="block text-sm font-semibold text-slate-800">กรุณาใส่รหัสสถานบริการของตัวเอง</label>
      <p class="text-xs text-slate-500 mt-1 mb-2">
        <?php echo $isAdmin ? 'สำหรับแอดมิน ให้ใส่รหัสสถานบริการของเจ้าของใบเบิกนี้' : 'กรอกรหัสสถานบริการ 5 หลักของบัญชีที่กำลังใช้งาน'; ?>
      </p>
      <input id="cancelHostCode" name="cancel_host_code" type="text" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" required autocomplete="off"
        placeholder="รหัสสถานบริการ 5 หลัก"
        class="w-full h-12 px-3 border border-slate-300 rounded-xl tracking-widest font-semibold focus:outline-none focus:ring-2 focus:ring-red-400 focus:border-red-400">
      <p id="cancelHostCodeError" class="hidden text-sm text-red-600 mt-2">กรุณากรอกรหัสสถานบริการให้ครบ 5 หลัก</p>

      <div class="flex justify-end gap-3 mt-6">
        <button type="button" data-close-cancel-modal class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700">กลับ</button>
        <button id="confirmCancelWithdrawalBtn" type="submit" disabled
          class="px-4 py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-semibold disabled:opacity-40 disabled:cursor-not-allowed">
          ยืนยันยกเลิกใบเบิก
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- Status Change Modal -->
<div id="statusChangeModal" class="fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-[60] p-4" role="dialog" aria-modal="true" aria-labelledby="statusChangeTitle">
  <button type="button" class="absolute inset-0 w-full h-full cursor-default" data-close-status-modal aria-label="ปิดหน้าต่าง"></button>
  <div class="relative bg-white rounded-2xl shadow-2xl border border-slate-200 max-w-md w-full overflow-hidden">
    <div class="px-6 py-5 border-b border-slate-200">
      <div class="flex items-start justify-between gap-4">
        <div>
          <p class="text-xs font-medium text-slate-500">ใบเบิก <?php echo e(format_withdraw_code($w['withdraw_no'])); ?></p>
          <h2 id="statusChangeTitle" class="text-xl font-semibold text-slate-900 mt-1">เปลี่ยนสถานะใบเบิก</h2>
        </div>
        <button type="button" data-close-status-modal class="w-9 h-9 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-600 text-xl" aria-label="ปิด">&times;</button>
      </div>
    </div>

    <form id="statusChangeForm" method="post" class="p-6">
      <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
      <input type="hidden" name="action" value="change_status">
      <input type="hidden" name="confirm_change_status" value="1">

      <div class="rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 mb-5">
        <div class="text-xs text-slate-500">สถานะปัจจุบัน</div>
        <div class="font-semibold text-slate-800 mt-1"><?php echo e($withdrawalStatusLabels[$w['status']] ?? $w['status']); ?></div>
      </div>

      <label for="newWithdrawalStatus" class="block text-sm font-medium text-slate-700 mb-2">เลือกสถานะใหม่</label>
      <select id="newWithdrawalStatus" name="new_status" required class="w-full h-12 px-3 border border-slate-300 rounded-xl bg-white text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-400">
        <option value="">— เลือกสถานะ —</option>
        <?php foreach ($withdrawalStatusLabels as $statusValue => $statusLabel): ?>
          <?php if ($statusValue !== 'approved' && withdrawal_transition_allowed((string)$w['status'], (string)$statusValue)): ?>
            <option value="<?php echo e($statusValue); ?>"><?php echo e($statusLabel); ?></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </select>

      <div id="statusChangeWarning" class="hidden mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"></div>

      <div class="flex justify-end gap-3 mt-6">
        <button type="button" data-close-status-modal class="px-4 py-2.5 rounded-xl border border-slate-300 bg-white hover:bg-slate-50 text-slate-700">ยกเลิก</button>
        <button id="confirmStatusChangeBtn" type="submit" disabled class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-900 font-semibold disabled:opacity-40 disabled:cursor-not-allowed">
          ยืนยันเปลี่ยนสถานะ
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  const modal = document.getElementById('cancelWithdrawalModal');
  const form = document.getElementById('cancelWithdrawalForm');
  const hostCodeInput = document.getElementById('cancelHostCode');
  const errorText = document.getElementById('cancelHostCodeError');
  const confirmButton = document.getElementById('confirmCancelWithdrawalBtn');
  if (!modal || !form || !hostCodeInput || !errorText || !confirmButton) return;

  function validateHostCode(showError){
    hostCodeInput.value = hostCodeInput.value.replace(/\D/g, '').slice(0, 5);
    const valid = /^\d{5}$/.test(hostCodeInput.value);
    confirmButton.disabled = !valid;
    errorText.classList.toggle('hidden', valid || !showError);
    return valid;
  }

  let cancelReturnFocus = null;
  window.openCancelWithdrawalModal = function(){
    cancelReturnFocus = document.activeElement;
    hostCodeInput.value = '';
    errorText.classList.add('hidden');
    confirmButton.disabled = true;
    confirmButton.textContent = 'ยืนยันยกเลิกใบเบิก';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
    setTimeout(function(){ hostCodeInput.focus(); }, 0);
  };

  function closeCancelWithdrawalModal(){
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
    hostCodeInput.value = '';
    if (cancelReturnFocus && cancelReturnFocus.isConnected) cancelReturnFocus.focus();
  }

  hostCodeInput.addEventListener('input', function(){ validateHostCode(false); });
  hostCodeInput.addEventListener('blur', function(){ validateHostCode(hostCodeInput.value.length > 0); });
  form.addEventListener('submit', function(event){
    if (!validateHostCode(true)) {
      event.preventDefault();
      hostCodeInput.focus();
      return;
    }
    confirmButton.disabled = true;
    confirmButton.textContent = 'กำลังยกเลิก...';
  });
  document.querySelectorAll('[data-close-cancel-modal]').forEach(function(button){
    button.addEventListener('click', closeCancelWithdrawalModal);
  });
  document.addEventListener('keydown', function(event){
    if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
      closeCancelWithdrawalModal();
    }
  });
})();
</script>

<script>
(function(){
  const modal = document.getElementById('statusChangeModal');
  const form = document.getElementById('statusChangeForm');
  const select = document.getElementById('newWithdrawalStatus');
  const warning = document.getElementById('statusChangeWarning');
  const confirmButton = document.getElementById('confirmStatusChangeBtn');
  if (!modal || !form || !select || !warning || !confirmButton) return;

  const currentStatus = <?php echo json_encode($withdrawalStatusLabels[$w['status']] ?? $w['status'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  let statusReturnFocus = null;
  window.openStatusChangeModal = function(){
    statusReturnFocus = document.activeElement;
    select.value = '';
    warning.textContent = '';
    warning.classList.add('hidden');
    confirmButton.disabled = true;
    confirmButton.textContent = 'ยืนยันเปลี่ยนสถานะ';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
    setTimeout(function(){ select.focus(); }, 0);
  };

  function closeStatusChangeModal(){
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
    if (statusReturnFocus && statusReturnFocus.isConnected) statusReturnFocus.focus();
  }

  select.addEventListener('change', function(){
    const selectedOption = select.options[select.selectedIndex];
    if (!select.value || !selectedOption) {
      warning.textContent = '';
      warning.classList.add('hidden');
      confirmButton.disabled = true;
      return;
    }
    warning.textContent = 'กำลังจะเปลี่ยนสถานะจาก “' + currentStatus + '” เป็น “' + selectedOption.textContent.trim() + '” กรุณาตรวจสอบก่อนยืนยัน';
    warning.classList.remove('hidden');
    confirmButton.disabled = false;
  });

  form.addEventListener('submit', function(event){
    if (!select.value || confirmButton.disabled) {
      event.preventDefault();
      select.focus();
      return;
    }
    confirmButton.disabled = true;
    confirmButton.textContent = 'กำลังเปลี่ยนสถานะ...';
  });

  document.querySelectorAll('[data-close-status-modal]').forEach(function(button){
    button.addEventListener('click', closeStatusChangeModal);
  });
  document.addEventListener('keydown', function(event){
    if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
      closeStatusChangeModal();
    }
  });
})();
</script>

  <!-- Modal -->
<div id="noteModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50">
  <div class="bg-white p-6 rounded-lg shadow-xl max-w-lg w-full mx-4">
    <h2 class="text-lg font-semibold mb-3">หมายเหตุ</h2>
    <div id="noteModalText" class="text-sm text-gray-700 whitespace-pre-wrap max-h-96 overflow-y-auto border p-3 rounded bg-gray-50"></div>

    <div class="text-right mt-4">
      <button onclick="closeNoteModal()" 
          class="inline-flex items-center justify-center text-sm bg-gray-700 hover:bg-gray-800 text-white px-3 py-2 rounded">
        ปิด
      </button>
    </div>
  </div>
</div>

<!-- Note Edit Modal -->
<div id="noteEditModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center z-50" role="dialog" aria-modal="true" aria-labelledby="noteEditTitle">
  <div class="absolute inset-0" data-close-note-edit></div>
  <div class="relative bg-white p-6 rounded-lg shadow-xl max-w-3xl w-full mx-4">
    <div class="flex items-start justify-between gap-4 mb-4">
      <div>
        <div class="text-xs uppercase tracking-wide text-gray-500">แก้หมายเหตุ</div>
        <h2 id="noteEditTitle" class="text-lg font-semibold text-gray-800">หมายเหตุ</h2>
        <div id="noteEditSub" class="text-sm text-gray-500 mt-0.5"></div>
      </div>
      <button type="button" data-close-note-edit
        class="inline-flex items-center justify-center text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded">
        ปิด
      </button>
    </div>

    <textarea id="noteEditText" rows="10"
      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-300 focus:border-yellow-400 bg-yellow-50 resize-y"
      placeholder="พิมพ์หมายเหตุได้ที่นี่"></textarea>

    <div class="mt-4 flex items-center justify-between gap-3">
      <div class="text-xs text-gray-500">กด Esc หรือคลิกพื้นหลังเพื่อปิด</div>
      <button type="button" data-close-note-edit
        class="inline-flex items-center justify-center text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
        เสร็จแล้ว
      </button>
    </div>
  </div>
</div>

<script>
function showNoteModal(text){
  document.getElementById("noteModalText").innerText = text || "(ไม่มีข้อมูล)";
  document.getElementById("noteModal").classList.remove("hidden");
  document.getElementById("noteModal").classList.add("flex");
}

function closeNoteModal(){
  document.getElementById("noteModal").classList.add("hidden");
  document.getElementById("noteModal").classList.remove("flex");
}
</script>

<script>
(function(){
  const modal = document.getElementById('noteEditModal');
  const textarea = document.getElementById('noteEditText');
  const titleEl = document.getElementById('noteEditTitle');
  const subEl = document.getElementById('noteEditSub');
  if (!modal || !textarea || !titleEl || !subEl) return;

  let activeInput = null;
  let activeHidden = null;

  function syncAllNoteFields(){
    document.querySelectorAll('.note-edit-input').forEach(function(input){
      const noteId = input.getAttribute('data-note-id');
      if (!noteId) return;
      const hidden = document.querySelector('input.note-edit-value[name="note[' + noteId + ']"]');
      if (hidden) hidden.value = input.value || '';
    });
  }
  window.syncAllNoteFields = syncAllNoteFields;

  function openEditor(input){
    activeInput = input;
    activeHidden = null;
    if (input && input.dataset && input.dataset.noteId) {
      activeHidden = document.querySelector('input.note-edit-value[name="note[' + input.dataset.noteId + ']"]');
    }
    titleEl.textContent = input.getAttribute('data-note-title') || 'หมายเหตุ';
    subEl.textContent = input.getAttribute('data-note-subtitle') || '';
    textarea.value = input.value || '';
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
    setTimeout(function(){
      textarea.focus();
      try {
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
      } catch (err) {}
    }, 0);
  }

  function closeEditor(){
    const returnFocus = activeInput;
    if (activeInput) {
      activeInput.value = textarea.value;
    }
    if (activeHidden) {
      activeHidden.value = textarea.value;
    }
    syncAllNoteFields();
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
    activeInput = null;
    activeHidden = null;
    if (returnFocus && returnFocus.isConnected) returnFocus.focus();
  }

  document.addEventListener('click', function(e){
    const input = e.target.closest('.note-edit-input');
    if (input) {
      e.preventDefault();
      openEditor(input);
      return;
    }

    if (e.target && e.target.hasAttribute('data-close-note-edit')) {
      closeEditor();
    }
  }, true);

  textarea.addEventListener('input', function(){
    if (activeInput) {
      activeInput.value = textarea.value;
    }
    if (activeHidden) {
      activeHidden.value = textarea.value;
    }
    syncAllNoteFields();
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
      closeEditor();
    }
  });

  document.addEventListener('submit', function(){
    syncAllNoteFields();
    if (activeInput) {
      activeInput.value = textarea.value;
    }
    if (activeHidden) {
      activeHidden.value = textarea.value;
    }
  }, true);

  modal.addEventListener('click', function(e){
    if (e.target === modal) {
      closeEditor();
    }
  });
})();
</script>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
