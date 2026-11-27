<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>มีใบเบิกฉบับร่างค้างอยู่ | ระบบเบิกยา CUP สันกำแพง</title>
  <link rel="stylesheet" href="assets/css/app.css?v=<?= (int)filemtime(__DIR__ . '/../assets/css/app.css') ?>">
  <?php define('DRUG_WITHDRAW_APP_STYLES', true); ?>
</head>
<body class="app-page">
  <?php require __DIR__ . '/nav.php'; ?>
  <main class="app-ui app-container">
    <dialog open id="outstanding-draft-warning" class="draft-warning" aria-labelledby="draft-warning-title" aria-describedby="draft-warning-message">
      <h1 id="draft-warning-title">มีใบเบิกฉบับร่างค้างอยู่</h1>
      <p id="draft-warning-message">กรุณากลับไปแก้ไขและส่งใบเบิกฉบับร่างให้เรียบร้อย หรือยกเลิกใบเบิกที่ค้างอยู่หากไม่ต้องการใช้งาน เพื่อให้สามารถสร้างใบเบิกใหม่ได้</p>
      <?php if ($visibleDrafts): ?>
        <ul class="draft-warning__list">
          <?php foreach ($visibleDrafts as $index => $draft): ?>
            <li>
              <span>สร้าง <?= e(format_thai_datetime($draft['created_at'])) ?></span>
              <small>ฉบับร่าง · <?= (int)$draft['item_count'] ?> รายการยา</small>
              <?php if ($index > 0): ?>
                <a href="view_withdrawal.php?id=<?= (int)$draft['id'] ?>">จัดการฉบับร่างนี้</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (count($visibleDrafts) !== count($drafts)): ?>
        <p>มีใบเบิกฉบับร่างนอกขอบเขตที่คุณเข้าถึงได้ กรุณาติดต่อผู้ดูแลระบบ</p>
      <?php endif; ?>
      <div class="draft-warning__actions">
        <?php if ($visibleDrafts): ?>
          <a class="app-btn app-btn--primary" href="view_withdrawal.php?id=<?= (int)$visibleDrafts[0]['id'] ?>">กลับไปจัดการใบเบิกฉบับร่าง</a>
        <?php endif; ?>
        <a class="app-btn app-btn--secondary" id="close-draft-warning" href="dashboard.php">ปิด</a>
      </div>
    </dialog>
    <script>
      const draftWarning = document.getElementById('outstanding-draft-warning');
      if (typeof draftWarning.showModal === 'function') {
        draftWarning.removeAttribute('open');
        draftWarning.showModal();
        draftWarning.addEventListener('cancel', function (event) {
          event.preventDefault();
          document.getElementById('close-draft-warning').click();
        });
      }
    </script>
  </main>
  <?php require __DIR__ . '/footer.php'; ?>
</body>
</html>
