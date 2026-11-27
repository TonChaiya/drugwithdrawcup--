<?php

function withdrawal_receipt_token($value): ?string
{
    return is_string($value) && preg_match('/\A[0-9a-f]{32}\z/', $value) ? $value : null;
}

function withdrawal_saved_attempt(PDO $pdo, array $user, string $context, string $token): string|false
{
    $stmt = $pdo->prepare('SELECT LOWER(HEX(attempt)) FROM withdrawal_save_receipts
        WHERE token_hash = UNHEX(?) AND user_id = ? AND account_host = ? AND form_context = ? AND expires_at > ?');
    $stmt->execute([hash('sha256', $token), $user['id'], (string)$user['host_code'], $context, time()]);
    return $stmt->fetchColumn();
}

// Claim inside the existing business transaction. A rollback also removes the
// claim, and two requests using the same token cannot both persist changes.
function withdrawal_claim_receipt(PDO $pdo, array $user, string $context): void
{
    if (!isset($_POST['recovery_token'])) return;
    $token = withdrawal_receipt_token($_POST['recovery_token']);
    $attempt = withdrawal_receipt_token($_POST['recovery_attempt'] ?? null);
    if ($token === null || $attempt === null) throw new RuntimeException('ข้อมูลการบันทึกไม่ถูกต้อง กรุณาโหลดหน้าใหม่');
    $count = 0;
    foreach (['stock', 'current_stock', 'qty', 'note', 'delivered', 'new_drug_item_id', 'new_current_stock', 'new_qty', 'new_note'] as $field) {
        $posted = $_POST[$field] ?? [];
        if (is_array($posted)) $count += count($posted);
    }
    if ((string)$count !== ($_POST['recovery_field_count'] ?? null)) {
        throw new RuntimeException('ข้อมูลรายการส่งมาไม่ครบ กรุณาตรวจใบเบิกก่อนบันทึกอีกครั้ง');
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO withdrawal_save_receipts
            (token_hash, attempt, user_id, account_host, form_context, expires_at) VALUES (UNHEX(?), UNHEX(?), ?, ?, ?, ?)');
        $stmt->execute([hash('sha256', $token), $attempt, $user['id'], (string)$user['host_code'], $context, time() + APP_SESSION_ABSOLUTE]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) !== 1062) throw $e;
        throw new RuntimeException('ระบบบันทึกคำขอนี้แล้ว กรุณาตรวจสอบใบเบิกก่อนลองอีกครั้ง');
    }
}

function withdrawal_form_version(string $status, array $items): string
{
    $values = [];
    foreach ($items as $item) {
        $values[(int)($item['wi_id'] ?? $item['id'])] = [
            (string)$item['drug_item_id'], (string)$item['quantity'], (string)$item['delivered_quantity'],
            $item['current_stock_snapshot'], (string)$item['note'],
        ];
    }
    ksort($values, SORT_NUMERIC);
    return hash('sha256', serialize([$status, $values]));
}

function withdrawal_check_form_version(PDO $pdo, int $id, string $status): void
{
    // Called while the normal withdrawal row lock is held. Legacy clients
    // still use the existing workflow; the rendered edit forms include it.
    if (!isset($_POST['form_version'])) return;
    $posted = $_POST['form_version'];
    $stmt = $pdo->prepare('SELECT id, drug_item_id, quantity, delivered_quantity, current_stock_snapshot, note
        FROM withdrawal_items WHERE withdrawal_id = ? ORDER BY id FOR UPDATE');
    $stmt->execute([$id]);
    if (!is_string($posted) || !hash_equals(withdrawal_form_version($status, $stmt->fetchAll()), $posted)) {
        throw new RuntimeException('ใบเบิกมีการเปลี่ยนแปลงจากหน้าที่เปิดไว้ กรุณาตรวจข้อมูลล่าสุดก่อนบันทึกอีกครั้ง');
    }
}
