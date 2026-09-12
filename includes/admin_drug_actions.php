<?php

function drug_admin_redirect(string $message, bool $isError = false): void
{
    $_SESSION['flash'] = $message;
    $_SESSION['flash_error'] = $isError;
    header('Location: admin_drug_items.php');
    exit;
}

function drug_import_rows(string $tmp, string $extension): array
{
    $rows = [];
    if ($extension === 'csv') {
        $handle = fopen($tmp, 'rb');
        if ($handle === false) throw new RuntimeException('ไม่สามารถอ่านไฟล์ CSV');
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) > 20) {
                fclose($handle);
                throw new RuntimeException('ไฟล์มีจำนวนคอลัมน์มากเกินไป');
            }
            foreach ($row as $cell) {
                $value = (string)$cell;
                $isUtf8 = function_exists('mb_check_encoding') ? mb_check_encoding($value, 'UTF-8') : preg_match('//u', $value) === 1;
                if (!$isUtf8) {
                    fclose($handle);
                    throw new RuntimeException('ไฟล์ CSV ต้องบันทึกเป็น UTF-8 กรุณาใช้ไฟล์ต้นแบบของระบบ');
                }
            }
            $rows[] = $row;
            if (count($rows) > 5001) {
                fclose($handle);
                throw new RuntimeException('ไฟล์มีข้อมูลเกิน 5,000 รายการ');
            }
        }
        fclose($handle);
        return $rows;
    }

    if (!class_exists('PhpOffice\\PhpSpreadsheet\\Reader\\Xlsx')) {
        throw new RuntimeException('เซิร์ฟเวอร์ยังไม่มีไลบรารีสำหรับอ่าน Excel กรุณาใช้ CSV');
    }
    if ($extension === 'xlsx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new RuntimeException('ไฟล์ XLSX ไม่สมบูรณ์');
        $expandedBytes = 0;
        if ($zip->numFiles > 1000) {
            $zip->close();
            throw new RuntimeException('ไฟล์ XLSX มีองค์ประกอบมากเกินไป');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $expandedBytes += (int)($stat['size'] ?? 0);
            if ($expandedBytes > 25 * 1024 * 1024) {
                $zip->close();
                throw new RuntimeException('ไฟล์ XLSX มีขนาดข้อมูลภายในมากเกินไป');
            }
        }
        $zip->close();
    }

    $reader = $extension === 'xlsx'
        ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx()
        : new \PhpOffice\PhpSpreadsheet\Reader\Xls();
    $reader->setReadDataOnly(true);
    $sheetInfo = $reader->listWorksheetInfo($tmp);
    $firstSheet = $sheetInfo[0] ?? [];
    if ((int)($firstSheet['totalRows'] ?? 0) > 5001 || (int)($firstSheet['totalColumns'] ?? 0) > 20) {
        throw new RuntimeException('ไฟล์ต้องมีข้อมูลไม่เกิน 5,000 รายการและไม่เกิน 20 คอลัมน์');
    }
    $reader->setReadFilter(new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
        public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
        {
            return $row <= 5001 && in_array($columnAddress, ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T'], true);
        }
    });
    $spreadsheet = $reader->load($tmp);
    foreach ($spreadsheet->getActiveSheet()->toArray(null, false, true, false) as $row) {
        $rows[] = $row;
        if (count($rows) > 5001) throw new RuntimeException('ไฟล์มีข้อมูลเกิน 5,000 รายการ');
    }
    $spreadsheet->disconnectWorksheets();
    return $rows;
}

function validate_drug_import(array $rows): array
{
    if (count($rows) < 2) throw new RuntimeException('ไฟล์ไม่มีข้อมูลรายการยา');
    $headers = array_map(function ($value) {
        return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$value)));
    }, $rows[0]);
    $required = ['working_code', 'name', 'pack_size', 'unit', 'type', 'fiscal_year'];
    foreach ($required as $field) {
        if (!in_array($field, $headers, true)) {
            throw new RuntimeException('หัวตารางไม่ครบ ต้องมี working_code,name,pack_size,unit,type,fiscal_year');
        }
    }
    $map = array_flip($headers);
    $valid = [];
    $seenCodes = [];
    $rowErrors = [];
    for ($index = 1; $index < count($rows); $index++) {
        $raw = [];
        foreach ($required as $field) $raw[$field] = $rows[$index][$map[$field]] ?? '';
        $item = normalize_drug_item($raw);
        if (implode('', $item) === '') continue;
        $errors = validate_drug_item($item);
        $codeKey = function_exists('mb_strtolower') ? mb_strtolower($item['working_code'], 'UTF-8') : strtolower($item['working_code']);
        if (isset($seenCodes[$codeKey])) $errors[] = 'รหัสยาซ้ำกับแถว ' . $seenCodes[$codeKey];
        if ($errors) {
            $rowErrors[] = 'แถว ' . ($index + 1) . ': ' . implode(', ', $errors);
            if (count($rowErrors) >= 10) break;
            continue;
        }
        $seenCodes[$codeKey] = $index + 1;
        $valid[] = ['row_number' => $index + 1, 'item' => $item];
    }
    if ($rowErrors) throw new RuntimeException('ยกเลิกทั้งไฟล์ — ' . implode(' • ', $rowErrors));
    if (!$valid) throw new RuntimeException('ไม่พบรายการยาที่สมบูรณ์ในไฟล์');
    return $valid;
}

function handle_admin_drug_post(PDO $pdo, array $admin): void
{
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        drug_admin_redirect('คำขอหมดอายุหรือไม่ถูกต้อง กรุณาลองใหม่', true);
    }
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add' || $action === 'update') {
            $item = normalize_drug_item($_POST);
            $errors = validate_drug_item($item);
            if ($errors) throw new RuntimeException(implode(' • ', $errors));
            $id = $action === 'update' ? (int)($_POST['id'] ?? 0) : 0;
            if ($action === 'update' && $id <= 0) throw new RuntimeException('ไม่พบรหัสรายการยา');

            $pdo->beginTransaction();
            $oldRow = null;
            if ($id > 0) {
                $stmt = $pdo->prepare('SELECT * FROM drug_item WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $oldRow = $stmt->fetch();
                if (!$oldRow) throw new RuntimeException('ไม่พบรายการยา');
            }
            $matches = find_drug_item_matches($pdo, $item['working_code'], $item['name'], $id);
            if ($matches) throw new RuntimeException('รหัสยาซ้ำกับรายการ ID ' . (int)$matches[0]['id']);

            if ($action === 'add') {
                $stmt = $pdo->prepare('INSERT INTO drug_item (working_code,name,pack_size,unit,type,fiscal_year,is_active) VALUES (?,?,?,?,?,?,1)');
                $stmt->execute([$item['working_code'], $item['name'], $item['pack_size'], $item['unit'], $item['type'], (int)$item['fiscal_year']]);
                $id = (int)$pdo->lastInsertId();
                write_drug_audit($pdo, $admin, $id, 'create', null, $item + ['is_active' => 1]);
                $message = 'เพิ่มรายการยาเรียบร้อย (ID ' . $id . ')';
            } else {
                $pdo->prepare('UPDATE drug_item SET working_code=?,name=?,pack_size=?,unit=?,type=?,fiscal_year=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
                    ->execute([$item['working_code'], $item['name'], $item['pack_size'], $item['unit'], $item['type'], (int)$item['fiscal_year'], $id]);
                write_drug_audit($pdo, $admin, $id, 'update', drug_item_snapshot($oldRow), $item + ['is_active' => (int)$oldRow['is_active']]);
                $message = 'บันทึกการแก้ไขรายการยาเรียบร้อย';
            }
            $pdo->commit();
            drug_admin_redirect($message);
        }

        if ($action === 'set_status') {
            if (($_POST['confirm_set_status'] ?? '') !== '1') {
                throw new RuntimeException('ยังไม่ได้ยืนยันการเปลี่ยนสถานะรายการยา');
            }
            $id = (int)($_POST['id'] ?? 0);
            $isActive = (int)($_POST['is_active'] ?? -1);
            if ($id <= 0 || !in_array($isActive, [0, 1], true)) throw new RuntimeException('ข้อมูลสถานะไม่ถูกต้อง');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM drug_item WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $oldRow = $stmt->fetch();
            if (!$oldRow) throw new RuntimeException('ไม่พบรายการยา');
            if ($isActive === 1) {
                $errors = validate_drug_item(normalize_drug_item($oldRow));
                if ($errors) throw new RuntimeException('ยังเปิดใช้งานไม่ได้: ' . implode(' • ', $errors));
            }
            $pdo->prepare('UPDATE drug_item SET is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$isActive, $id]);
            $newValues = drug_item_snapshot($oldRow);
            $newValues['is_active'] = $isActive;
            write_drug_audit($pdo, $admin, $id, $isActive ? 'activate' : 'suspend', drug_item_snapshot($oldRow), $newValues);
            $pdo->commit();
            drug_admin_redirect($isActive ? 'เปิดใช้งานรายการยาเรียบร้อย' : 'ระงับแล้ว และจะไม่แสดงในใบเบิกใหม่');
        }

        if ($action === 'bulk_status') {
            $rawIds = explode(',', (string)($_POST['selected_ids'] ?? ''));
            $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), function ($id) { return $id > 0; })));
            $isActive = (int)($_POST['is_active'] ?? -1);
            if (!$ids || count($ids) > 5000 || !in_array($isActive, [0, 1], true)) {
                throw new RuntimeException('รายการที่เลือกหรือสถานะไม่ถูกต้อง');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM drug_item WHERE id IN ($placeholders) ORDER BY id FOR UPDATE");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll();
            if (count($rows) !== count($ids)) throw new RuntimeException('มีบางรายการไม่อยู่ในระบบ กรุณาโหลดหน้าใหม่');

            $update = $pdo->prepare('UPDATE drug_item SET is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $changed = 0;
            foreach ($rows as $row) {
                if ((int)$row['is_active'] === $isActive) continue;
                if ($isActive === 1) {
                    $errors = validate_drug_item(normalize_drug_item($row));
                    if ($errors) throw new RuntimeException('เปิดใช้รหัส ' . $row['working_code'] . ' ไม่ได้: ' . implode(' • ', $errors));
                }
                $update->execute([$isActive, (int)$row['id']]);
                $newValues = drug_item_snapshot($row);
                $newValues['is_active'] = $isActive;
                write_drug_audit($pdo, $admin, (int)$row['id'], $isActive ? 'bulk_activate' : 'bulk_suspend', drug_item_snapshot($row), $newValues);
                $changed++;
            }
            $pdo->commit();
            drug_admin_redirect(($isActive ? 'เปิดใช้งาน' : 'ระงับ') . " {$changed} รายการเรียบร้อย");
        }

        if ($action === 'delete_suspended') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ข้อมูลรายการยาไม่ถูกต้อง');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM drug_item WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $oldRow = $stmt->fetch();
            if (!$oldRow) throw new RuntimeException('ไม่พบรายการยาที่ต้องการลบ');
            if ((int)$oldRow['is_active'] !== 0) {
                throw new RuntimeException('ต้องระงับรายการยาก่อนจึงจะลบถาวรได้');
            }

            $withdrawalStmt = $pdo->prepare('SELECT COUNT(*) FROM withdrawal_items WHERE drug_item_id = ?');
            $withdrawalStmt->execute([$id]);
            if ((int)$withdrawalStmt->fetchColumn() > 0) {
                throw new RuntimeException('ลบไม่ได้ เนื่องจากรายการยานี้เคยถูกใช้ในใบเบิกแล้ว');
            }

            $inventoryStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_snapshots WHERE drug_item_id = ?');
            $inventoryStmt->execute([$id]);
            if ((int)$inventoryStmt->fetchColumn() > 0) {
                throw new RuntimeException('ลบไม่ได้ เนื่องจากรายการยานี้มีประวัติข้อมูลคงคลัง');
            }

            write_drug_audit($pdo, $admin, $id, 'delete', drug_item_snapshot($oldRow), null);
            $pdo->prepare('DELETE FROM drug_item WHERE id = ?')->execute([$id]);
            $pdo->commit();
            drug_admin_redirect('ลบรายการยาที่ระงับเรียบร้อย และเก็บประวัติการลบไว้แล้ว');
        }

        if ($action === 'import_cancel') {
            unset($_SESSION['drug_import_preview']);
            drug_admin_redirect('ยกเลิกการนำเข้าแล้ว');
        }

        if ($action === 'import_preview') {
            $upload = $_FILES['drug_file'] ?? null;
            if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('ไม่พบไฟล์หรืออัปโหลดไม่สำเร็จ');
            if ((int)($upload['size'] ?? 0) <= 0 || (int)$upload['size'] > 5 * 1024 * 1024) throw new RuntimeException('ไฟล์ต้องมีขนาดไม่เกิน 5 MB');
            $originalName = basename(str_replace('\\', '/', (string)($upload['name'] ?? '')));
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['csv', 'xlsx', 'xls'], true)) throw new RuntimeException('อนุญาตเฉพาะ CSV, XLSX หรือ XLS');
            $tmp = (string)$upload['tmp_name'];
            if (!is_uploaded_file($tmp)) throw new RuntimeException('ไฟล์อัปโหลดไม่ถูกต้อง');
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
                if ($finfo) finfo_close($finfo);
                $allowedMimes = [
                    'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'],
                    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
                    'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/octet-stream'],
                ];
                if ($mime !== '' && !in_array($mime, $allowedMimes[$extension], true)) {
                    throw new RuntimeException('ชนิดไฟล์ไม่ตรงกับนามสกุลที่เลือก');
                }
            }
            $validRows = validate_drug_import(drug_import_rows($tmp, $extension));

            $mode = (string)($_POST['import_mode'] ?? 'add_only');
            if (!in_array($mode, ['add_only', 'update_by_code'], true)) throw new RuntimeException('โหมดนำเข้าไม่ถูกต้อง');
            $newActive = (int)($_POST['new_is_active'] ?? 0);
            if (!in_array($newActive, [0, 1], true)) throw new RuntimeException('สถานะรายการใหม่ไม่ถูกต้อง');

            $existingRows = $pdo->query('SELECT id, working_code, name FROM drug_item')->fetchAll();
            $codeMap = [];
            $nameMap = [];
            foreach ($existingRows as $row) {
                $codeKey = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$row['working_code']), 'UTF-8') : strtolower(trim((string)$row['working_code']));
                $nameKey = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$row['name']), 'UTF-8') : strtolower(trim((string)$row['name']));
                $codeMap[$codeKey] = $row;
                if (!isset($nameMap[$nameKey])) $nameMap[$nameKey] = $row;
            }

            $newCount = 0;
            $existingCount = 0;
            $sameNameWarnings = [];
            foreach ($validRows as $entry) {
                $item = $entry['item'];
                $codeKey = function_exists('mb_strtolower') ? mb_strtolower($item['working_code'], 'UTF-8') : strtolower($item['working_code']);
                $nameKey = function_exists('mb_strtolower') ? mb_strtolower($item['name'], 'UTF-8') : strtolower($item['name']);
                if (isset($codeMap[$codeKey])) {
                    $existingCount++;
                } else {
                    $newCount++;
                    if (isset($nameMap[$nameKey]) && count($sameNameWarnings) < 10) {
                        $sameNameWarnings[] = 'แถว ' . $entry['row_number'] . ': ชื่อเหมือนรหัสเดิม ' . $nameMap[$nameKey]['working_code'] . ' แต่จะสร้างเป็นรหัสใหม่ ' . $item['working_code'];
                    }
                }
            }

            $_SESSION['drug_import_preview'] = [
                'token' => bin2hex(random_bytes(24)),
                'created_at' => time(),
                'original_name' => $originalName,
                'mode' => $mode,
                'new_is_active' => $newActive,
                'rows' => $validRows,
                'new_count' => $newCount,
                'existing_count' => $existingCount,
                'same_name_warnings' => $sameNameWarnings,
            ];
            header('Location: admin_drug_items.php#import-preview');
            exit;
        }

        if ($action === 'import_confirm') {
            $preview = $_SESSION['drug_import_preview'] ?? null;
            $token = (string)($_POST['preview_token'] ?? '');
            if (!is_array($preview) || !isset($preview['token']) || !hash_equals((string)$preview['token'], $token)) {
                throw new RuntimeException('ข้อมูลตัวอย่างนำเข้าหมดอายุ กรุณาเลือกไฟล์ใหม่');
            }
            if ((int)($preview['created_at'] ?? 0) < time() - 3600) {
                unset($_SESSION['drug_import_preview']);
                throw new RuntimeException('ข้อมูลตัวอย่างนำเข้าเกิน 1 ชั่วโมง กรุณาเลือกไฟล์ใหม่');
            }

            $validRows = $preview['rows'] ?? [];
            $mode = (string)($preview['mode'] ?? 'add_only');
            $newActive = (int)($preview['new_is_active'] ?? 0);
            $originalName = (string)($preview['original_name'] ?? 'import');
            if (!$validRows || !in_array($mode, ['add_only', 'update_by_code'], true) || !in_array($newActive, [0, 1], true)) {
                throw new RuntimeException('ข้อมูลตัวอย่างนำเข้าไม่ถูกต้อง กรุณาเลือกไฟล์ใหม่');
            }

            $pdo->beginTransaction();
            $added = 0; $updated = 0; $skipped = 0; $logLines = [];
            foreach ($validRows as $entry) {
                $item = $entry['item'];
                $matches = find_drug_item_matches($pdo, $item['working_code'], $item['name']);
                if ($matches) {
                    $id = (int)$matches[0]['id'];
                    if ($mode === 'add_only') {
                        $skipped++;
                        $logLines[] = 'Row ' . $entry['row_number'] . ': skipped existing code ' . $item['working_code'];
                        continue;
                    }
                    $stmt = $pdo->prepare('SELECT * FROM drug_item WHERE id=? FOR UPDATE');
                    $stmt->execute([$id]);
                    $oldRow = $stmt->fetch();
                    $pdo->prepare('UPDATE drug_item SET name=?,pack_size=?,unit=?,type=?,fiscal_year=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
                        ->execute([$item['name'], $item['pack_size'], $item['unit'], $item['type'], (int)$item['fiscal_year'], $id]);
                    write_drug_audit($pdo, $admin, $id, 'import_update', drug_item_snapshot($oldRow), $item + ['is_active' => (int)$oldRow['is_active']], 'import');
                    $updated++;
                    $logLines[] = 'Row ' . $entry['row_number'] . ': updated ID ' . $id;
                } else {
                    $stmt = $pdo->prepare('INSERT INTO drug_item (working_code,name,pack_size,unit,type,fiscal_year,is_active) VALUES (?,?,?,?,?,?,?)');
                    $stmt->execute([$item['working_code'], $item['name'], $item['pack_size'], $item['unit'], $item['type'], (int)$item['fiscal_year'], $newActive]);
                    $id = (int)$pdo->lastInsertId();
                    write_drug_audit($pdo, $admin, $id, 'import_create', null, $item + ['is_active' => $newActive], 'import');
                    $added++;
                    $logLines[] = 'Row ' . $entry['row_number'] . ': inserted ID ' . $id;
                }
            }
            $pdo->commit();
            unset($_SESSION['drug_import_preview']);

            $logDir = dirname(__DIR__) . '/tool/import_logs';
            if (is_dir($logDir) || mkdir($logDir, 0755, true)) {
                $logFile = $logDir . '/import_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.log';
                $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'import';
                $summary = 'File=' . $safeName . "\nActor=" . ($admin['username'] ?? '-') . ' (ID ' . (int)$admin['id'] . ")\nIP=" . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\nAdded={$added}, Updated={$updated}, Skipped={$skipped}\n";
                file_put_contents($logFile, $summary . implode("\n", $logLines), LOCK_EX);
            }
            drug_admin_redirect("นำเข้าสำเร็จ: เพิ่ม {$added} อัปเดต {$updated} และข้ามรหัสเดิม {$skipped} รายการ");
        }

        throw new RuntimeException('ไม่รู้จักคำสั่งที่ส่งมา');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (in_array($action, ['add', 'update'], true) && isset($item) && is_array($item)) {
            $_SESSION['drug_form_recovery'] = $item + ['action' => $action, 'id' => (int)($_POST['id'] ?? 0)];
        }
        drug_admin_redirect($e->getMessage(), true);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin drug database error: ' . $e->getMessage());
        drug_admin_redirect($e->getCode() === '23000' ? 'รหัสยาซ้ำกับข้อมูลเดิม' : 'เกิดข้อผิดพลาดในการบันทึกฐานข้อมูล', true);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin drug error: ' . $e->getMessage());
        drug_admin_redirect('เกิดข้อผิดพลาดในการประมวลผล กรุณาตรวจข้อมูลแล้วลองใหม่', true);
    }
}
