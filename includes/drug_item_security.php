<?php

function drug_text_length($value)
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function normalize_drug_item(array $input): array
{
    $workingCode = trim((string)($input['working_code'] ?? ''));
    $workingCode = preg_replace('/^\xEF\xBB\xBF/', '', $workingCode);

    return [
        'working_code' => $workingCode,
        'name' => trim((string)($input['name'] ?? '')),
        'pack_size' => trim((string)($input['pack_size'] ?? '')),
        'unit' => trim((string)($input['unit'] ?? '')),
        'type' => trim((string)($input['type'] ?? '')),
        'fiscal_year' => trim((string)($input['fiscal_year'] ?? '')),
    ];
}

function validate_drug_item(array $item): array
{
    $labels = [
        'working_code' => 'รหัสยา',
        'name' => 'ชื่อยา',
        'pack_size' => 'ขนาดบรรจุ',
        'unit' => 'หน่วย',
        'type' => 'ประเภท',
    ];
    $limits = ['working_code' => 100, 'name' => 255, 'pack_size' => 100, 'unit' => 50, 'type' => 100];
    $errors = [];

    foreach ($labels as $field => $label) {
        if ($item[$field] === '') {
            $errors[] = 'กรุณาระบุ' . $label;
        } elseif (drug_text_length($item[$field]) > $limits[$field]) {
            $errors[] = $label . 'ยาวเกิน ' . $limits[$field] . ' ตัวอักษร';
        } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $item[$field])) {
            $errors[] = $label . 'มีอักขระควบคุมที่ไม่อนุญาต';
        }
    }

    if ($item['fiscal_year'] === '') {
        $errors[] = 'กรุณาระบุปีงบประมาณ';
    } elseif (!preg_match('/^\d{4}$/', $item['fiscal_year']) || (int)$item['fiscal_year'] < 2500 || (int)$item['fiscal_year'] > 3000) {
        $errors[] = 'ปีงบประมาณต้องเป็น พ.ศ. 4 หลัก เช่น 2570';
    }

    if ($item['pack_size'] !== '' && (!preg_match('/^\d+(?:\.\d+)?$/', $item['pack_size']) || (float)$item['pack_size'] <= 0)) {
        $errors[] = 'ขนาดบรรจุต้องเป็นตัวเลขมากกว่า 0 เช่น 1, 10 หรือ 10.5';
    }

    return array_values(array_unique($errors));
}

function drug_item_snapshot(array $row): array
{
    return [
        'working_code' => (string)($row['working_code'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'pack_size' => (string)($row['pack_size'] ?? ''),
        'unit' => (string)($row['unit'] ?? ''),
        'type' => (string)($row['type'] ?? ''),
        'is_active' => (int)($row['is_active'] ?? 1),
        'fiscal_year' => isset($row['fiscal_year']) ? (int)$row['fiscal_year'] : null,
    ];
}

function write_drug_audit(PDO $pdo, array $actor, $itemId, string $action, ?array $oldValues, ?array $newValues, string $source = 'web'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO drug_item_audit_logs
         (drug_item_id, action, source, actor_user_id, actor_username, actor_name, old_values, new_values, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $stmt->execute([
        $itemId ?: null,
        $action,
        $source,
        isset($actor['id']) ? (int)$actor['id'] : null,
        $actor['username'] ?? null,
        $actor['name'] ?? null,
        $oldValues === null ? null : json_encode($oldValues, $jsonOptions),
        $newValues === null ? null : json_encode($newValues, $jsonOptions),
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
}

function find_drug_item_matches(PDO $pdo, string $workingCode, string $name = '', int $excludeId = 0): array
{
    $sql = 'SELECT id, working_code, name FROM drug_item WHERE working_code = ?';
    $params = [$workingCode];
    if ($excludeId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $sql .= ' ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function drug_schema_is_ready(PDO $pdo): bool
{
    try {
        $hasActive = (bool)$pdo->query("SHOW COLUMNS FROM drug_item LIKE 'is_active'")->fetch();
        $hasFiscalYear = (bool)$pdo->query("SHOW COLUMNS FROM drug_item LIKE 'fiscal_year'")->fetch();
        $hasAudit = (bool)$pdo->query("SHOW TABLES LIKE 'drug_item_audit_logs'")->fetch();
        return $hasActive && $hasFiscalYear && $hasAudit;
    } catch (Exception $e) {
        return false;
    }
}
