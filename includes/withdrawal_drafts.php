<?php

function lock_withdrawal_owner(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('ไม่พบบัญชีผู้ใช้งาน');
    $stmt->closeCursor();
}

function outstanding_withdrawal_drafts(PDO $pdo, int $userId, bool $forDisplay = false): array
{
    $sql = $forDisplay
        ? "SELECT w.id, w.host_code, w.created_at,
             (SELECT COUNT(*) FROM withdrawal_items wi WHERE wi.withdrawal_id = w.id) AS item_count
           FROM withdrawals w WHERE w.user_id = ? AND w.status = 'draft'
           ORDER BY w.created_at DESC, w.id DESC"
        : "SELECT w.id FROM withdrawals w WHERE w.user_id = ? AND w.status = 'draft' LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
