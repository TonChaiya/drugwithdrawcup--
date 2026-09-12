<?php

require __DIR__ . '/../../vendor/autoload.php';

$spreadsheet = PhpOffice\PhpSpreadsheet\IOFactory::load(__DIR__ . '/INVC_test.xlsx');
$sheet = $spreadsheet->getActiveSheet();
$pdo = new PDO('mysql:host=localhost;dbname=if0_42397740_withdraw_drug;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$stmt = $pdo->prepare(
    'SELECT working_code_snapshot AS code,
            CAST(quantity * pack_size_snapshot AS DECIMAL(20,4)) AS qty
     FROM withdrawal_items
     WHERE withdrawal_id = ?
     ORDER BY id ASC'
);
$stmt->execute([90]);
$databaseRows = $stmt->fetchAll();

$errors = [];
$nonBlankHospCodes = 0;
$textCodeRows = 0;
$example = null;
foreach ($databaseRows as $index => $databaseRow) {
    $row = $index + 2;
    $hospCode = $sheet->getCell('A' . $row)->getValue();
    $qtyDisp = $sheet->getCell('B' . $row)->getValue();
    $workingCode = $sheet->getCell('C' . $row)->getValue();

    if ($hospCode !== null && $hospCode !== '') {
        $nonBlankHospCodes++;
    }
    if ($sheet->getCell('C' . $row)->getDataType() === PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING) {
        $textCodeRows++;
    }
    if ((float)$qtyDisp !== (float)$databaseRow['qty']) {
        $errors[] = 'QTY_DISP mismatch at row ' . $row;
    }
    if ((string)$workingCode !== (string)$databaseRow['code']) {
        $errors[] = 'WORKING_CODE mismatch at row ' . $row;
    }
    if ((string)$workingCode === 'D000215') {
        $example = ['WORKING_CODE' => $workingCode, 'QTY_DISP' => $qtyDisp];
    }
}

echo json_encode([
    'headers' => $sheet->rangeToArray('A1:C1')[0],
    'highest_column' => $sheet->getHighestDataColumn(),
    'database_rows' => count($databaseRows),
    'xlsx_rows' => $sheet->getHighestDataRow() - 1,
    'non_blank_hosp_codes' => $nonBlankHospCodes,
    'working_codes_stored_as_text' => $textCodeRows,
    'example_2_x_500' => $example,
    'mismatch_count' => count($errors),
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

