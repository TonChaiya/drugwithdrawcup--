<?php

// Download the fixed import template through PHP because some shared hosts
// reject direct requests to .xlsx files. Keep this endpoint superadmin-only.
ob_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require_superadmin($pdo);

$format = (string)($_GET['format'] ?? 'xlsx');
$templates = [
    'xlsx' => [
        'path' => __DIR__ . '/tool/drug_items_import_template.xlsx',
        'name' => 'drug_items_import_template.xlsx',
        'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],
    'csv' => [
        'path' => __DIR__ . '/tool/drug_items_import_sample.csv',
        'name' => 'drug_items_import_sample_utf8.csv',
        'type' => 'text/csv; charset=UTF-8',
    ],
];

if (!isset($templates[$format])) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'รูปแบบไฟล์ต้นแบบไม่ถูกต้อง';
    exit;
}

$template = $templates[$format];
$templatePath = $template['path'];

if (!is_file($templatePath) || !is_readable($templatePath)) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ไม่พบไฟล์ต้นแบบนำเข้ารายการยา กรุณาแจ้งผู้ดูแลระบบให้อัปโหลดไฟล์ต้นแบบในโฟลเดอร์ tool';
    exit;
}

$fileSize = filesize($templatePath);
if ($fileSize === false) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ไม่สามารถอ่านขนาดไฟล์ต้นแบบได้ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ';
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $template['type']);
header('Content-Disposition: attachment; filename="' . $template['name'] . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($templatePath);
exit;
