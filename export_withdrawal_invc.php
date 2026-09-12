<?php

ob_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

$currentUser = require_login($pdo);
$withdrawalId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

function invc_export_fail(int $withdrawalId, string $userMessage, ?Throwable $error = null): void
{
    if ($error !== null) {
        error_log('INVC export failed for withdrawal ' . $withdrawalId . ': ' . $error->getMessage());
    }
    $_SESSION['flash'] = $userMessage;
    header('Location: view_withdrawal.php?id=' . $withdrawalId);
    exit;
}

function invc_normalize_decimal(string $value): string
{
    $value = trim($value);
    if (strpos($value, '.') === false) {
        return $value;
    }
    return rtrim(rtrim($value, '0'), '.');
}

if (!$withdrawalId) {
    http_response_code(400);
    exit('รหัสใบเบิกไม่ถูกต้อง');
}

try {
    $withdrawalStmt = $pdo->prepare(
        'SELECT id, host_code, withdraw_no, status
         FROM withdrawals
         WHERE id = ?
         LIMIT 1'
    );
    $withdrawalStmt->execute([$withdrawalId]);
    $withdrawal = $withdrawalStmt->fetch();

    if (!$withdrawal) {
        http_response_code(404);
        exit('ไม่พบใบเบิก');
    }

    if (!can_access_host_code($pdo, $currentUser, (string)$withdrawal['host_code'])) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์ส่งออกข้อมูลของสถานบริการอื่น');
    }

    if ($withdrawal['status'] !== 'approved') {
        http_response_code(403);
        exit('ส่งออก INVC ได้เฉพาะใบเบิกที่อนุมัติแล้ว');
    }

    // Deliberately use both snapshots only. Falling back to current master data
    // could export a changed code or pack size for an older withdrawal.
    $itemStmt = $pdo->prepare(
        'SELECT wi.id,
                wi.working_code_snapshot,
                wi.delivered_quantity,
                wi.pack_size_snapshot,
                (CAST(wi.delivered_quantity AS DECIMAL(20,4)) * CAST(wi.pack_size_snapshot AS DECIMAL(20,4))) AS qty_disp
         FROM withdrawal_items wi
         WHERE wi.withdrawal_id = ?
         ORDER BY wi.id ASC'
    );
    $itemStmt->execute([$withdrawalId]);
    $items = $itemStmt->fetchAll();

    if (!$items) {
        invc_export_fail($withdrawalId, 'ใบเบิกนี้ยังไม่มีรายการยา จึงไม่สามารถ Export ไป INVC ได้');
    }

    $exportItems = [];
    foreach ($items as $index => $item) {
        $line = $index + 1;
        $delivered = (int)$item['delivered_quantity'];
        if ($delivered === 0) {
            continue;
        }
        $workingCode = trim((string)$item['working_code_snapshot']);
        $packSize = trim((string)$item['pack_size_snapshot']);
        $qtyDisp = invc_normalize_decimal((string)$item['qty_disp']);

        if ($workingCode === '' || strlen($workingCode) > 100 || preg_match('/[\x00-\x1F\x7F]/', $workingCode)) {
            invc_export_fail($withdrawalId, 'Export ไม่สำเร็จ: รหัสยาของรายการลำดับที่ ' . $line . ' ไม่ครบหรือไม่ถูกต้อง');
        }
        if ($delivered < 0 || !preg_match('/^\d+(?:\.\d+)?$/', $packSize) || (float)$packSize <= 0) {
            invc_export_fail($withdrawalId, 'Export ไม่สำเร็จ: จำนวนหรือขนาดบรรจุของรายการลำดับที่ ' . $line . ' ไม่ถูกต้อง');
        }
        if (!ctype_digit($qtyDisp) || (int)$qtyDisp <= 0) {
            invc_export_fail($withdrawalId, 'Export ไม่สำเร็จ: คำนวณ QTY_DISP ของรายการลำดับที่ ' . $line . ' ไม่ได้');
        }
        $significantDigits = ltrim(str_replace('.', '', $qtyDisp), '0');
        if (strlen($significantDigits) > 15) {
            invc_export_fail($withdrawalId, 'Export ไม่สำเร็จ: QTY_DISP ของรายการลำดับที่ ' . $line . ' ยาวเกินขีดจำกัดความแม่นยำของ Excel');
        }
        $item['_qty_disp_normalized'] = $qtyDisp;
        $exportItems[] = $item;
    }

    if (!$exportItems) {
        invc_export_fail($withdrawalId, 'ใบเบิกที่อนุมัตินี้ไม่มีรายการที่จ่ายยาจริง จึงไม่สร้างไฟล์ INVC');
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Composer autoload was not found');
    }
    require $autoload;

    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('INVC');
    $sheet->setCellValueExplicit('A1', 'HOSP_CODE', PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B1', 'QTY_DISP', PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('C1', 'WORKING_CODE', PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

    $row = 2;
    foreach ($exportItems as $item) {
        $qtyDisp = $item['_qty_disp_normalized'];

        $sheet->setCellValueExplicit('A' . $row, '', PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B' . $row, (int)$qtyDisp, PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit(
            'C' . $row,
            trim((string)$item['working_code_snapshot']),
            PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $row++;
    }

    $lastRow = $row - 1;
    $sheet->getStyle('A1:C1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1:C1')->getFill()
        ->setFillType(PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FF0F766E');
    $sheet->getStyle('A1:C' . $lastRow)->getAlignment()->setVertical(PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $sheet->getStyle('A2:A' . $lastRow)->getNumberFormat()->setFormatCode('@');
    $sheet->getStyle('B2:B' . $lastRow)->getNumberFormat()->setFormatCode('0');
    $sheet->getStyle('C2:C' . $lastRow)->getNumberFormat()->setFormatCode('@');
    $sheet->getColumnDimension('A')->setWidth(16);
    $sheet->getColumnDimension('B')->setWidth(14);
    $sheet->getColumnDimension('C')->setWidth(22);
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:C' . $lastRow);

    $safeNumber = (int)($withdrawal['withdraw_no'] ?: $withdrawalId);
    $filename = 'INVC_S' . str_pad((string)$safeNumber, 5, '0', STR_PAD_LEFT) . '_' . date('Ymd_His') . '.xlsx';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
} catch (PDOException $error) {
    if ($error->getCode() === '42S22') {
        invc_export_fail($withdrawalId, 'ฐานข้อมูลยังไม่พร้อมสำหรับ Export INVC กรุณารันไฟล์ databaseSQL/20260911_add_withdrawal_working_code_snapshot.sql ก่อน', $error);
    }
    invc_export_fail($withdrawalId, 'เกิดข้อผิดพลาดในการอ่านข้อมูลใบเบิก กรุณาลองใหม่', $error);
} catch (Throwable $error) {
    invc_export_fail($withdrawalId, 'สร้างไฟล์ Excel ไม่สำเร็จ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ', $error);
}
