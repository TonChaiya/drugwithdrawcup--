<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
$user = require_login($pdo, '../index.php');
$isAdmin = is_manager($user);
$myHost = $user['host_code'] ?? '';
$reportScope = host_scope_condition($user, 'w.host_code');

$reportType = $_GET['report_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$facilityId = (int)($_GET['facility_id'] ?? 0);
$withdrawNoRaw = trim((string)($_GET['withdraw_no'] ?? ''));
$drugId = (int)($_GET['drug_id'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$message = '';
$results = [];
$detailItems = [];
$reportTitle = '';

$statusLabels = [
  'draft' => 'ร่าง',
  'submitted' => 'รออนุมัติ',
  'approved' => 'อนุมัติ',
  'rejected' => 'ยกเลิก',
];

$reportList = [
  'by_drug' => ['title' => 'รายงานตามชื่อยา', 'desc' => 'ดูแต่ละ รพ.สต. ที่เบิกยานั้น พร้อมสถานะ'],
  'withdraw_no' => ['title' => 'รายงานตามเลขที่ใบเบิก', 'desc' => 'ค้นหาใบเบิกและแสดงรายการยาในใบนั้น'],
  'status' => ['title' => 'รายงานตามสถานะ', 'desc' => 'แยกใบเบิกตามร่าง รออนุมัติ อนุมัติ หรือยกเลิก'],
  'facility' => ['title' => 'รายงานแยกตาม รพ.สต.', 'desc' => 'ดูใบเบิกของหน่วยบริการที่เลือก'],
  'date_range' => ['title' => 'รายงานระหว่างวันที่', 'desc' => 'ดึงใบเบิกตามช่วงวันที่ที่กำหนด'],
];

$facilityScope = host_scope_condition($user, 'u.host_code');
$stmt = $pdo->prepare("SELECT MIN(u.id) AS id, MAX(u.name) AS name, MAX(u.username) AS username,
                              MAX(u.facility_name) AS facility_name, u.host_code
                       FROM users u
                       WHERE u.role = 'user' AND u.host_code IS NOT NULL AND u.host_code <> ''
                         AND " . $facilityScope['sql'] . "
                       GROUP BY u.host_code
                       ORDER BY facility_name, u.host_code");
$stmt->execute($facilityScope['params']);
$facilities = $stmt->fetchAll();
$facilityHostById = [];
foreach ($facilities as $facility) {
  $facilityHostById[(int)$facility['id']] = (string)$facility['host_code'];
}

$stmt = $pdo->prepare(
  "SELECT d.id, d.working_code, d.name, d.is_active
   FROM drug_item d
   WHERE d.is_active = 1
      OR EXISTS (
        SELECT 1
        FROM withdrawal_items wi
        WHERE wi.drug_item_id = d.id
      )
   ORDER BY d.is_active DESC, d.name"
);
$stmt->execute();
$drugs = $stmt->fetchAll();
$selectedDrug = null;
foreach ($drugs as $drug) {
  if ((int)$drug['id'] === $drugId) {
    $selectedDrug = $drug;
    break;
  }
}

function norm_withdraw_no($s) {
  $digits = preg_replace('/\D+/', '', trim((string)$s));
  return $digits === '' ? null : (int)$digits;
}

function status_label($status, $labels) {
  return $labels[$status] ?? $status;
}

function add_common_filters(&$sql, &$params, $statusFilter, $dateFrom, $dateTo, $scope) {
  if ($statusFilter !== '') { $sql .= " AND w.status = ?"; $params[] = $statusFilter; }
  if ($dateFrom !== '') { $sql .= " AND w.created_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
  if ($dateTo !== '') { $sql .= " AND w.created_at <= ?"; $params[] = $dateTo . ' 23:59:59'; }
  $sql .= " AND " . $scope['sql'];
  foreach ($scope['params'] as $scopeParam) $params[] = $scopeParam;
}

function load_withdrawal_items($pdo, $withdrawalId) {
  $stmt = $pdo->prepare("
    SELECT wi.quantity, wi.delivered_quantity, wi.note, wi.current_stock_snapshot,
           COALESCE(wi.pack_size_snapshot, d.pack_size) AS pack_size,
           COALESCE(wi.unit_snapshot, d.unit) AS unit,
           d.working_code, d.name AS drug_name
    FROM withdrawal_items wi
    JOIN drug_item d ON d.id = wi.drug_item_id
    WHERE wi.withdrawal_id = ?
    ORDER BY d.name
  ");
  $stmt->execute([$withdrawalId]);
  return $stmt->fetchAll();
}

if ($reportType !== '' && !isset($reportList[$reportType])) {
  $message = 'ประเภทรายงานไม่ถูกต้อง';
  $reportType = '';
}

if ($reportType !== '') {
  $reportTitle = $reportList[$reportType]['title'];
}

if ($reportType === 'by_drug') {
  if ($drugId <= 0) {
    $message = 'กรุณาเลือกชื่อยา';
  } elseif ($selectedDrug === null) {
    $message = 'ไม่พบรายการยานี้ หรือเป็นยาที่ระงับและไม่เคยมีประวัติเบิก';
  } else {
    $sql = "
      SELECT w.id, w.withdraw_no, w.status, w.created_at, w.approved_at,
             u.facility_name, u.name AS user_name, u.username,
             d.working_code, d.name AS drug_name,
             wi.quantity, wi.delivered_quantity, wi.note
      FROM withdrawal_items wi
      JOIN withdrawals w ON w.id = wi.withdrawal_id
      JOIN users u ON u.id = w.user_id
      JOIN drug_item d ON d.id = wi.drug_item_id
      WHERE wi.drug_item_id = ?
    ";
    $params = [$drugId];
    add_common_filters($sql, $params, $statusFilter, $dateFrom, $dateTo, $reportScope);
    $sql .= " ORDER BY u.facility_name, w.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
  }
}

if ($reportType === 'withdraw_no') {
  $n = norm_withdraw_no($withdrawNoRaw);
  if ($n === null) {
    $message = 'กรุณากรอกเลขที่ใบเบิก';
  } else {
    $sql = "
      SELECT w.*, u.name AS user_name, u.facility_name, u.username
      FROM withdrawals w
      JOIN users u ON u.id = w.user_id
      WHERE w.withdraw_no = ?
    ";
    $params = [$n];
    $sql .= " AND " . $reportScope['sql'];
    $params = array_merge($params, $reportScope['params']);
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if ($row) {
      $results = [$row];
      $detailItems = load_withdrawal_items($pdo, (int)$row['id']);
    } else {
      $message = 'ไม่พบเลขที่ใบเบิก ' . e($withdrawNoRaw);
    }
  }
}

if ($reportType === 'status') {
  $sql = "
    SELECT w.*, u.name AS user_name, u.facility_name, u.username
    FROM withdrawals w
    JOIN users u ON u.id = w.user_id
    WHERE 1=1
  ";
  $params = [];
  add_common_filters($sql, $params, $statusFilter, $dateFrom, $dateTo, $reportScope);
  $sql .= " ORDER BY w.created_at DESC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $results = $stmt->fetchAll();
}

if ($reportType === 'facility') {
  if ($facilityId <= 0 && $isAdmin) {
    $message = 'กรุณาเลือก รพ.สต.';
  } else {
    $sql = "
      SELECT w.*, u.name AS user_name, u.facility_name, u.username
      FROM withdrawals w
      JOIN users u ON u.id = w.user_id
      WHERE 1=1
    ";
    $params = [];
    if ($isAdmin) {
      $selectedHost = $facilityHostById[$facilityId] ?? '';
      if ($selectedHost === '') {
        $message = 'ไม่พบสถานบริการที่เลือก';
      }
      $sql .= " AND w.host_code = ?";
      $params[] = $selectedHost;
    } else {
      $sql .= " AND w.host_code = ?";
      $params[] = $myHost;
    }
    add_common_filters($sql, $params, $statusFilter, $dateFrom, $dateTo, ['sql'=>'1=1','params'=>[]]);
    $sql .= " ORDER BY w.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
  }
}

if ($reportType === 'date_range') {
  if ($dateFrom === '' || $dateTo === '') {
    $message = 'กรุณาเลือกวันที่เริ่มต้นและวันที่สิ้นสุด';
  } else {
    $sql = "
      SELECT w.*, u.name AS user_name, u.facility_name, u.username
      FROM withdrawals w
      JOIN users u ON u.id = w.user_id
      WHERE w.created_at >= ? AND w.created_at <= ?
    ";
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
    if ($statusFilter !== '') { $sql .= " AND w.status = ?"; $params[] = $statusFilter; }
    $sql .= " AND " . $reportScope['sql'];
    $params = array_merge($params, $reportScope['params']);
    $sql .= " ORDER BY w.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
  }
}

$hasSearched = ($reportType !== '');
$showReportModal = $hasSearched;
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>รายงานใบเบิก | ระบบเบิกยา CUP สันกำแพง</title>
  <style>
    :root {
      --ink: #172033;
      --muted: #64748b;
      --line: rgba(15, 23, 42, .10);
      --line-soft: rgba(15, 23, 42, .07);
      --surface: rgba(255, 255, 255, .78);
      --surface-strong: rgba(255, 255, 255, .94);
      --radius: 16px;
      --radius-sm: 12px;
      --shadow: 0 10px 28px rgba(15, 23, 42, .07);
      --font-main: "Noto Sans Thai", "Sarabun", "Leelawadee UI", "Segoe UI", Tahoma, sans-serif;
    }

    html {
      font-size: 16px;
      text-rendering: optimizeLegibility;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    *,
    *::before,
    *::after {
      box-sizing: border-box;
    }

    body {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      color: var(--ink);
      font-family: var(--font-main);
      font-feature-settings: "kern";
      background:
        radial-gradient(circle at 10% 10%, rgba(14, 165, 164, .13), transparent 26%),
        radial-gradient(circle at 90% 16%, rgba(37, 99, 235, .12), transparent 28%),
        linear-gradient(135deg, #f8fafc 0%, #eef7fb 48%, #f6f8fb 100%);
      background-attachment: fixed;
    }

    a {
      color: inherit;
      text-decoration: none;
    }

    .report-wrap {
      width: min(1180px, calc(100% - 32px));
      flex: 1 0 auto;
      margin: 0 auto;
      padding: 14px 0 22px;
    }

    .report-panel {
      overflow: visible;
      border: 1px solid var(--line-soft);
      border-radius: var(--radius);
      background: var(--surface);
      box-shadow: var(--shadow);
      backdrop-filter: blur(18px);
    }

    .report-head {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 14px;
      align-items: center;
      padding: 18px;
      border-bottom: 1px solid var(--line-soft);
    }

    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 800;
    }

    .eyebrow::before {
      content: "";
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: #14b8a6;
      box-shadow: 0 0 0 4px rgba(20, 184, 166, .12);
    }

    .report-title {
      margin: 4px 0 0;
      font-size: 23px;
      line-height: 1.25;
      font-weight: 900;
      letter-spacing: 0;
    }

    .report-sub {
      margin-top: 4px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.65;
    }

    .type-grid {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 10px;
      padding: 16px 18px;
      border-bottom: 1px solid var(--line-soft);
    }

    .type-card {
      position: relative;
      min-height: 104px;
      display: block;
      padding: 13px;
      border: 1px solid var(--line-soft);
      border-radius: var(--radius);
      background: rgba(255, 255, 255, .55);
      cursor: pointer;
      transition: background .18s ease, border-color .18s ease, transform .18s ease, box-shadow .18s ease;
    }

    .type-card:hover,
    .type-card.is-selected {
      border-color: #14b8a6;
      background: rgba(255, 255, 255, .88);
      box-shadow: inset 0 3px 0 #14b8a6;
      transform: translateY(-1px);
    }

    .type-card input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }

    .type-card strong {
      display: block;
      font-size: 14px;
      line-height: 1.35;
      font-weight: 900;
    }

    .type-card span {
      display: block;
      margin-top: 6px;
      color: var(--muted);
      font-size: 12px;
      line-height: 1.55;
    }

    .filter-area {
      display: none;
      padding: 16px 18px;
      border-bottom: 1px solid var(--line-soft);
    }

    .filter-grid {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 12px;
    }

    .filter-drug { grid-column: span 3; }
    .filter-withdraw { grid-column: span 3; }
    .filter-status { grid-column: span 1; }
    .filter-facility { grid-column: span 2; }
    .filter-date { grid-column: span 1; }

    .field {
      display: grid;
      gap: 6px;
      min-width: 0;
      align-content: start;
    }

    .field label {
      color: #334155;
      font-size: 13px;
      font-weight: 900;
    }

    .field input,
    .field select {
      height: 42px;
      min-height: 42px;
      width: 100%;
      max-width: 100%;
      min-width: 0;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      padding: 0 12px;
      color: var(--ink);
      background: var(--surface-strong);
      outline: none;
      font-size: 14px;
      font-family: var(--font-main);
    }

    .field input:focus,
    .field select:focus {
      border-color: rgba(14, 165, 164, .65);
      box-shadow: 0 0 0 4px rgba(14, 165, 164, .12);
    }

    .drug-picker { position: relative; }
    .drug-search-control { position: relative; }

    .drug-field-heading {
      display: flex;
      min-height: 20px;
      align-items: center;
      justify-content: flex-start;
      gap: 10px;
    }

    .drug-field-heading .drug-search-hint {
      margin: 0;
      text-align: left;
    }

    .field .drug-search-control input {
      padding-left: 38px;
      padding-right: 40px;
    }

    .drug-search-icon {
      position: absolute;
      top: 50%;
      left: 13px;
      z-index: 1;
      color: #64748b;
      font-size: 18px;
      pointer-events: none;
      transform: translateY(-50%);
    }

    .drug-clear-btn {
      position: absolute;
      top: 50%;
      right: 8px;
      display: none;
      width: 28px;
      height: 28px;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 8px;
      color: #64748b;
      background: #f1f5f9;
      font: 700 19px/1 var(--font-main);
      transform: translateY(-50%);
    }

    .drug-clear-btn.is-visible { display: inline-flex; }
    .drug-clear-btn:hover { color: #b91c1c; background: #fee2e2; }

    .drug-suggestions {
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      right: 0;
      z-index: 60;
      display: none;
      max-height: 320px;
      overflow-y: auto;
      border: 1px solid var(--line);
      border-radius: 12px;
      background: #fff;
      box-shadow: 0 18px 42px rgba(15, 23, 42, .16);
    }

    .drug-suggestions.is-open { display: block; }

    .drug-suggestion {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr) auto;
      width: 100%;
      align-items: flex-start;
      gap: 10px;
      padding: 10px 12px;
      border: 0;
      border-bottom: 1px solid #eef2f7;
      color: var(--ink);
      background: #fff;
      text-align: left;
      font-family: var(--font-main);
      cursor: pointer;
    }

    .drug-suggestion:last-child { border-bottom: 0; }
    .drug-suggestion:hover,
    .drug-suggestion.is-active { background: #ecfeff; }

    .drug-code {
      flex: 0 0 auto;
      padding: 2px 7px;
      border-radius: 7px;
      color: #0f766e;
      background: #ccfbf1;
      font-size: 12px;
      font-weight: 900;
      white-space: nowrap;
    }

    .drug-name {
      min-width: 0;
      font-size: 13px;
      font-weight: 700;
      line-height: 1.55;
      overflow-wrap: anywhere;
    }

    .drug-status {
      align-self: center;
      padding: 2px 7px;
      border-radius: 999px;
      color: #047857;
      background: #d1fae5;
      font-size: 10px;
      font-weight: 900;
      line-height: 1.5;
      white-space: nowrap;
    }

    .drug-status.is-suspended {
      color: #b45309;
      background: #fef3c7;
    }

    .drug-empty {
      padding: 13px;
      color: var(--muted);
      font-size: 13px;
      text-align: center;
    }

    .drug-search-hint,
    .drug-search-error {
      margin-top: 5px;
      font-size: 12px;
      line-height: 1.45;
    }

    .drug-search-hint { color: var(--muted); }
    .drug-search-error { display: none; color: #b91c1c; font-weight: 800; }
    .drug-search-error.is-visible { display: block; }

    .actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      padding: 14px 18px;
    }

    .primary-btn,
    .ghost-btn {
      min-height: 42px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: var(--radius-sm);
      padding: 0 16px;
      font-size: 14px;
      font-weight: 900;
      font-family: var(--font-main);
    }

    .primary-btn {
      color: #fff;
      background: linear-gradient(135deg, #0f9f7a, #2563eb);
      box-shadow: 0 10px 22px rgba(37, 99, 235, .14);
    }

    .ghost-btn {
      color: #64748b;
      background: #f8fafc;
      border: 1px solid var(--line);
    }

    .report-modal {
      position: fixed;
      inset: 0;
      z-index: 80;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(15, 23, 42, .50);
      backdrop-filter: blur(8px);
    }

    .report-modal.is-open {
      display: flex;
    }

    .modal-panel {
      width: min(1120px, 100%);
      max-height: min(86vh, 820px);
      overflow: hidden;
      border: 1px solid var(--line-soft);
      border-radius: var(--radius);
      background: rgba(255, 255, 255, .96);
      box-shadow: 0 28px 72px rgba(15, 23, 42, .22);
    }

    .modal-head {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      align-items: center;
      padding: 16px 18px;
      border-bottom: 1px solid var(--line-soft);
    }

    .modal-head h2 {
      margin: 0;
      font-size: 18px;
      font-weight: 900;
      letter-spacing: 0;
    }

    .modal-note {
      margin-top: 3px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
    }

    .close-btn {
      width: 38px;
      height: 38px;
      border: 1px solid var(--line);
      border-radius: var(--radius-sm);
      color: #64748b;
      background: #f8fafc;
      font-size: 24px;
      line-height: 1;
      font-family: var(--font-main);
    }

    .modal-body {
      max-height: calc(min(86vh, 820px) - 72px);
      overflow: auto;
      padding: 14px;
      scrollbar-width: thin;
      scrollbar-color: rgba(14, 165, 164, .45) rgba(226, 232, 240, .75);
    }

    .modal-body::-webkit-scrollbar { width: 9px; height: 9px; }
    .modal-body::-webkit-scrollbar-track { background: rgba(226, 232, 240, .75); border-radius: 999px; }
    .modal-body::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #14b8a6, #2563eb); border-radius: 999px; }

    .message {
      padding: 12px 14px;
      border: 1px solid #fde68a;
      border-radius: var(--radius);
      color: #92400e;
      background: #fffbeb;
      font-size: 14px;
      line-height: 1.65;
    }

    .empty-state {
      min-height: 160px;
      display: grid;
      place-items: center;
      border: 1px dashed rgba(100, 116, 139, .28);
      border-radius: var(--radius);
      color: #94a3b8;
      background: rgba(248, 250, 252, .72);
      font-size: 14px;
      line-height: 1.6;
    }

    .summary-line {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 12px;
    }

    .chip {
      display: inline-flex;
      align-items: center;
      min-height: 28px;
      padding: 0 10px;
      border-radius: 999px;
      color: #475569;
      background: #f8fafc;
      border: 1px solid var(--line-soft);
      font-size: 12px;
      font-weight: 800;
      line-height: 1.4;
    }

    .table-wrap {
      overflow: auto;
      border: 1px solid var(--line-soft);
      border-radius: var(--radius);
    }

    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      font-size: 13px;
      line-height: 1.55;
      min-width: 860px;
    }

    th,
    td {
      padding: 10px 11px;
      border-bottom: 1px solid var(--line-soft);
      text-align: left;
      vertical-align: top;
    }

    th {
      position: sticky;
      top: 0;
      z-index: 1;
      color: #334155;
      background: #f8fafc;
      font-weight: 900;
      letter-spacing: 0;
    }

    tr:last-child td {
      border-bottom: 0;
    }

    tr:hover td {
      background: rgba(20, 184, 166, .05);
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 0 9px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 900;
      line-height: 1.35;
    }

    .st-draft { color: #92400e; background: #fef3c7; }
    .st-submitted { color: #0369a1; background: #e0f2fe; }
    .st-approved { color: #047857; background: #d1fae5; }
    .st-rejected { color: #b91c1c; background: #fee2e2; }

    .view-link {
      color: #0f766e;
      font-weight: 900;
      white-space: nowrap;
    }

    @media (max-width: 1100px) {
      .filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .filter-withdraw,
      .filter-status,
      .filter-facility,
      .filter-date {
        grid-column: auto;
      }

      .filter-drug {
        grid-column: 1 / -1;
      }
    }

    @media (max-width: 960px) {
      .type-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 620px) {
      .report-wrap {
        width: min(100% - 20px, 1180px);
      }

      .report-head,
      .filter-grid {
        grid-template-columns: 1fr;
      }

      .filter-drug {
        grid-column: auto;
      }

      .type-grid {
        grid-template-columns: 1fr;
      }

      .actions {
        justify-content: stretch;
      }

      .primary-btn,
      .ghost-btn {
        flex: 1;
      }
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/../includes/nav.php'; ?>

<main class="report-wrap">
  <section class="report-panel">
    <div class="report-head">
      <div>
        <div class="eyebrow">ระบบเบิกยา CUP สันกำแพง</div>
        <h1 class="report-title">รายงานใบเบิก</h1>
        <div class="report-sub">เลือกรูปแบบรายงาน กำหนดเงื่อนไข แล้วกดค้นหาเพื่อดูผลในหน้าต่างรายงาน</div>
      </div>
    </div>

    <form method="get" id="reportForm">
      <div class="type-grid">
        <?php foreach ($reportList as $key => $item): ?>
          <label class="type-card <?= $reportType === $key ? 'is-selected' : '' ?>">
            <input type="radio" name="report_type" value="<?= e($key) ?>" <?= $reportType === $key ? 'checked' : '' ?>>
            <strong><?= e($item['title']) ?></strong>
            <span><?= e($item['desc']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div id="filterArea" class="filter-area">
        <div class="filter-grid">
          <div class="field filter-drug">
            <div class="drug-field-heading">
              <label for="drug_search">ชื่อยาหรือรหัสยา</label>
              <span class="drug-search-hint">พิมพ์บางส่วนของรหัสหรือชื่อยา แล้วเลือกรายการที่ต้องการ</span>
            </div>
            <div class="drug-picker" id="drugPicker">
              <div class="drug-search-control">
                <span class="drug-search-icon" aria-hidden="true">⌕</span>
                <input
                  id="drug_search"
                  type="search"
                  value="<?= e($selectedDrug ? (($selectedDrug['working_code'] ? $selectedDrug['working_code'] . ' — ' : '') . $selectedDrug['name']) : '') ?>"
                  placeholder="พิมพ์รหัสยา หรือชื่อยา..."
                  autocomplete="off"
                  role="combobox"
                  aria-autocomplete="list"
                  aria-expanded="false"
                  aria-controls="drugSuggestions"
                >
                <button type="button" class="drug-clear-btn <?= $selectedDrug ? 'is-visible' : '' ?>" id="clearDrugSearch" aria-label="ล้างยาที่เลือก">×</button>
              </div>
              <input type="hidden" id="drug_id" name="drug_id" value="<?= $selectedDrug ? (int)$selectedDrug['id'] : '' ?>">
              <div class="drug-suggestions" id="drugSuggestions" role="listbox" aria-label="ผลการค้นหารายการยา"></div>
              <div class="drug-search-error" id="drugSearchError" role="alert">กรุณาเลือกยาจากรายการค้นหา</div>
            </div>
          </div>

          <div class="field filter-withdraw">
            <label for="withdraw_no">เลขที่ใบเบิก</label>
            <input id="withdraw_no" name="withdraw_no" value="<?= e($withdrawNoRaw) ?>" placeholder="เช่น S00001 หรือ 1">
          </div>

          <div class="field filter-status">
            <label for="status">สถานะ</label>
            <select id="status" name="status">
              <option value="">ทุกสถานะ</option>
              <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field filter-facility">
            <label for="facility_id">รพ.สต.</label>
            <select id="facility_id" name="facility_id" <?= $isAdmin ? '' : 'disabled' ?>>
              <option value="">เลือก รพ.สต.</option>
              <?php foreach ($facilities as $f): ?>
                <option value="<?= (int)$f['id'] ?>" <?= $facilityId === (int)$f['id'] ? 'selected' : '' ?>>
                  <?= e($f['facility_name'] ?: ($f['name'] . ' / ' . $f['username'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field filter-date">
            <label for="date_from">จากวันที่</label>
            <input id="date_from" type="date" name="date_from" value="<?= e($dateFrom) ?>">
          </div>

          <div class="field filter-date">
            <label for="date_to">ถึงวันที่</label>
            <input id="date_to" type="date" name="date_to" value="<?= e($dateTo) ?>">
          </div>
        </div>
      </div>

      <div class="actions">
        <a href="report.php" class="ghost-btn">ล้าง</a>
        <button type="submit" class="primary-btn">ค้นหา</button>
      </div>
    </form>
  </section>
</main>

<div id="reportModal" class="report-modal <?= $showReportModal ? 'is-open' : '' ?>" aria-hidden="<?= $showReportModal ? 'false' : 'true' ?>">
  <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="reportModalTitle">
    <div class="modal-head">
      <div>
        <h2 id="reportModalTitle"><?= e($reportTitle ?: 'ผลรายงาน') ?></h2>
        <div class="modal-note">พบข้อมูล <?= count($results) ?> รายการ</div>
      </div>
      <button type="button" class="close-btn" data-close-report aria-label="ปิด">&times;</button>
    </div>

    <div class="modal-body">
      <?php if ($message): ?>
        <div class="message"><?= e($message) ?></div>
      <?php elseif (!$results): ?>
        <div class="empty-state">ไม่มีข้อมูลตามเงื่อนไขที่ค้นหา</div>
      <?php else: ?>
        <div class="summary-line">
          <span class="chip"><?= e($reportTitle) ?></span>
          <?php if ($statusFilter !== ''): ?><span class="chip">สถานะ: <?= e(status_label($statusFilter, $statusLabels)) ?></span><?php endif; ?>
          <?php if ($dateFrom !== '' || $dateTo !== ''): ?><span class="chip">ช่วงวันที่: <?= e($dateFrom ?: '-') ?> ถึง <?= e($dateTo ?: '-') ?></span><?php endif; ?>
        </div>

        <?php if ($reportType === 'withdraw_no'): $w = $results[0]; ?>
          <div class="summary-line">
            <span class="chip"><?= e(format_withdraw_code($w['withdraw_no'])) ?></span>
            <span class="chip"><?= e($w['facility_name'] ?: $w['user_name']) ?></span>
            <span class="chip"><?= e(status_label($w['status'], $statusLabels)) ?></span>
            <a class="chip view-link" href="../view_withdrawal.php?id=<?= (int)$w['id'] ?>">เปิดใบเบิกเต็ม</a>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>รหัสยา</th>
                  <th>ชื่อยา</th>
                  <th>จำนวนขอ</th>
                  <th>จำนวนจ่าย</th>
                  <th>หน่วย</th>
                  <th>คงคลังขณะเบิก</th>
                  <th>หมายเหตุ</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($detailItems as $it): ?>
                <tr>
                  <td><?= e($it['working_code']) ?></td>
                  <td><?= e($it['drug_name']) ?></td>
                  <td><?= (int)$it['quantity'] ?></td>
                  <td><?= (int)$it['delivered_quantity'] ?></td>
                  <td><?= e($it['unit']) ?></td>
                  <td><?= e($it['current_stock_snapshot']) ?></td>
                  <td><?= e($it['note'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php elseif ($reportType === 'by_drug'): ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>ยา</th>
                  <th>รพ.สต.</th>
                  <th>เลขที่ใบเบิก</th>
                  <th>จำนวนขอ</th>
                  <th>จำนวนจ่าย</th>
                  <th>สถานะ</th>
                  <th>วันที่สร้าง</th>
                  <th>ดู</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($results as $r): ?>
                <tr>
                  <td><?= e(($r['working_code'] ? '[' . $r['working_code'] . '] ' : '') . $r['drug_name']) ?></td>
                  <td><?= e($r['facility_name'] ?: $r['user_name']) ?></td>
                  <td><?= e(format_withdraw_code($r['withdraw_no'])) ?></td>
                  <td><?= (int)$r['quantity'] ?></td>
                  <td><?= (int)$r['delivered_quantity'] ?></td>
                  <td><span class="status-badge st-<?= e($r['status']) ?>"><?= e(status_label($r['status'], $statusLabels)) ?></span></td>
                  <td><?= e($r['created_at']) ?></td>
                  <td><a class="view-link" href="../view_withdrawal.php?id=<?= (int)$r['id'] ?>">เปิดดู</a></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>เลขที่ใบเบิก</th>
                  <th>สถานะ</th>
                  <th>รพ.สต. / ผู้ขอ</th>
                  <th>วันที่สร้าง</th>
                  <th>วันที่อนุมัติ</th>
                  <th>ดู</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($results as $r): ?>
                <tr>
                  <td><?= e(format_withdraw_code($r['withdraw_no'])) ?></td>
                  <td><span class="status-badge st-<?= e($r['status']) ?>"><?= e(status_label($r['status'], $statusLabels)) ?></span></td>
                  <td><?= e($r['facility_name'] ?: ($r['user_name'] ?? $r['username'])) ?></td>
                  <td><?= e($r['created_at']) ?></td>
                  <td><?= e($r['approved_at'] ?: '-') ?></td>
                  <td><a class="view-link" href="../view_withdrawal.php?id=<?= (int)$r['id'] ?>">เปิดดู</a></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
const drugData = <?= json_encode(array_map(static function ($drug) {
  return [
    'id' => (int)$drug['id'],
    'code' => (string)($drug['working_code'] ?? ''),
    'name' => (string)($drug['name'] ?? ''),
    'isActive' => (int)($drug['is_active'] ?? 1) === 1,
  ];
}, $drugs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const reportInputs = document.querySelectorAll('input[name="report_type"]');
const reportForm = document.getElementById('reportForm');
const filterArea = document.getElementById('filterArea');
const drugPicker = document.getElementById('drugPicker');
const drugSearch = document.getElementById('drug_search');
const drugIdInput = document.getElementById('drug_id');
const drugSuggestions = document.getElementById('drugSuggestions');
const clearDrugSearch = document.getElementById('clearDrugSearch');
const drugSearchError = document.getElementById('drugSearchError');
let activeDrugIndex = -1;
let visibleDrugMatches = [];
let selectedDrugLabel = drugSearch.value;

function normalizeDrugText(value) {
  return String(value || '').normalize('NFKC').trim().toLocaleLowerCase('th-TH');
}

function drugLabel(drug) {
  return `${drug.code ? `${drug.code} — ` : ''}${drug.name}`;
}

function closeDrugSuggestions() {
  drugSuggestions.classList.remove('is-open');
  drugSearch.setAttribute('aria-expanded', 'false');
  activeDrugIndex = -1;
}

function updateDrugActiveOption() {
  drugSuggestions.querySelectorAll('.drug-suggestion').forEach((option, index) => {
    option.classList.toggle('is-active', index === activeDrugIndex);
    option.setAttribute('aria-selected', index === activeDrugIndex ? 'true' : 'false');
    if (index === activeDrugIndex) option.scrollIntoView({ block: 'nearest' });
  });
}

function selectDrug(drug) {
  const label = drugLabel(drug);
  drugIdInput.value = String(drug.id);
  drugSearch.value = label;
  selectedDrugLabel = label;
  clearDrugSearch.classList.add('is-visible');
  drugSearchError.classList.remove('is-visible');
  closeDrugSuggestions();
}

function renderDrugSuggestions() {
  const terms = normalizeDrugText(drugSearch.value).split(/\s+/).filter(Boolean);
  visibleDrugMatches = drugData.filter(drug => {
    const searchable = normalizeDrugText(`${drug.code} ${drug.name}`);
    return terms.every(term => searchable.includes(term));
  });

  drugSuggestions.replaceChildren();
  activeDrugIndex = -1;

  if (!visibleDrugMatches.length) {
    const empty = document.createElement('div');
    empty.className = 'drug-empty';
    empty.textContent = 'ไม่พบรหัสยาหรือชื่อยาที่ค้นหา';
    drugSuggestions.appendChild(empty);
  } else {
    visibleDrugMatches.forEach((drug, index) => {
      const option = document.createElement('button');
      option.type = 'button';
      option.className = 'drug-suggestion';
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');

      const code = document.createElement('span');
      code.className = 'drug-code';
      code.textContent = drug.code || 'ไม่มีรหัส';

      const name = document.createElement('span');
      name.className = 'drug-name';
      name.textContent = drug.name;

      const status = document.createElement('span');
      status.className = `drug-status${drug.isActive ? '' : ' is-suspended'}`;
      status.textContent = drug.isActive ? 'ใช้งานอยู่' : 'ระงับ · ดูย้อนหลัง';

      option.append(code, name, status);
      option.addEventListener('mouseenter', () => {
        activeDrugIndex = index;
        updateDrugActiveOption();
      });
      option.addEventListener('click', () => selectDrug(drug));
      drugSuggestions.appendChild(option);
    });
  }

  drugSuggestions.classList.add('is-open');
  drugSearch.setAttribute('aria-expanded', 'true');
}

drugSearch.addEventListener('focus', renderDrugSuggestions);
drugSearch.addEventListener('input', () => {
  if (drugSearch.value !== selectedDrugLabel) drugIdInput.value = '';
  clearDrugSearch.classList.toggle('is-visible', drugSearch.value.length > 0);
  drugSearchError.classList.remove('is-visible');
  renderDrugSuggestions();
});

drugSearch.addEventListener('keydown', event => {
  if (!drugSuggestions.classList.contains('is-open') && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
    renderDrugSuggestions();
  }
  if (event.key === 'ArrowDown' && visibleDrugMatches.length) {
    event.preventDefault();
    activeDrugIndex = (activeDrugIndex + 1) % visibleDrugMatches.length;
    updateDrugActiveOption();
  } else if (event.key === 'ArrowUp' && visibleDrugMatches.length) {
    event.preventDefault();
    activeDrugIndex = activeDrugIndex <= 0 ? visibleDrugMatches.length - 1 : activeDrugIndex - 1;
    updateDrugActiveOption();
  } else if (event.key === 'Enter' && activeDrugIndex >= 0) {
    event.preventDefault();
    selectDrug(visibleDrugMatches[activeDrugIndex]);
  } else if (event.key === 'Escape') {
    closeDrugSuggestions();
  }
});

clearDrugSearch.addEventListener('click', () => {
  drugSearch.value = '';
  drugIdInput.value = '';
  selectedDrugLabel = '';
  clearDrugSearch.classList.remove('is-visible');
  drugSearchError.classList.remove('is-visible');
  drugSearch.focus();
  renderDrugSuggestions();
});

document.addEventListener('click', event => {
  if (!drugPicker.contains(event.target)) closeDrugSuggestions();
});

reportForm.addEventListener('submit', event => {
  const reportType = document.querySelector('input[name="report_type"]:checked')?.value || '';
  if (reportType === 'by_drug' && !drugIdInput.value) {
    event.preventDefault();
    drugSearchError.classList.add('is-visible');
    drugSearch.focus();
    renderDrugSuggestions();
  }
});

const filterGroups = {
  drug: document.querySelectorAll('.filter-drug'),
  withdraw: document.querySelectorAll('.filter-withdraw'),
  status: document.querySelectorAll('.filter-status'),
  facility: document.querySelectorAll('.filter-facility'),
  date: document.querySelectorAll('.filter-date')
};

function showGroup(name, show) {
  filterGroups[name].forEach(el => { el.style.display = show ? 'grid' : 'none'; });
}

function updateFilters() {
  const type = document.querySelector('input[name="report_type"]:checked')?.value || '';
  filterArea.style.display = type ? 'block' : 'none';
  document.querySelectorAll('.type-card').forEach(card => {
    card.classList.toggle('is-selected', !!card.querySelector('input[name="report_type"]:checked'));
  });

  showGroup('drug', type === 'by_drug');
  showGroup('withdraw', type === 'withdraw_no');
  showGroup('status', ['by_drug', 'status', 'facility', 'date_range'].includes(type));
  showGroup('facility', type === 'facility');
  showGroup('date', ['by_drug', 'status', 'facility', 'date_range'].includes(type));
  if (type !== 'by_drug') closeDrugSuggestions();
}

reportInputs.forEach(input => input.addEventListener('change', updateFilters));
updateFilters();

const modal = document.getElementById('reportModal');
document.querySelectorAll('[data-close-report]').forEach(btn => {
  btn.addEventListener('click', () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  });
});

modal.addEventListener('click', event => {
  if (event.target === modal) {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }
});

document.addEventListener('keydown', event => {
  if (event.key === 'Escape') {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>
