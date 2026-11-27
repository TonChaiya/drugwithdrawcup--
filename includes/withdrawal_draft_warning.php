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
    <dialog open id="outstanding-draft-warning" class="draft-warning" aria-labelledby="draft-warning-title" aria-describedby="draft-warning-subtitle draft-warning-message">
      <header class="draft-warning__header">
        <span class="draft-warning__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
            <path d="M10.3 4.8 2.9 17.6a1.9 1.9 0 0 0 1.7 2.9h14.8a1.9 1.9 0 0 0 1.7-2.9L13.7 4.8a2 2 0 0 0-3.4 0Z"/>
            <path d="M12 9v4m0 3.3h.01"/>
          </svg>
        </span>
        <div>
          <h1 id="draft-warning-title">มีใบเบิกฉบับร่างค้างอยู่</h1>
          <p id="draft-warning-subtitle" class="draft-warning__subtitle">ยังไม่สามารถสร้างใบเบิกใหม่ได้</p>
        </div>
      </header>
      <p id="draft-warning-message" class="draft-warning__message">กรุณากลับไปแก้ไขและส่งใบเบิกฉบับร่างให้เรียบร้อย หรือยกเลิกใบเบิกที่ค้างอยู่หากไม่ต้องการใช้งาน เพื่อให้สามารถสร้างใบเบิกใหม่ได้</p>
      <?php if ($visibleDrafts): ?>
        <ul class="draft-warning__list">
          <?php foreach ($visibleDrafts as $index => $draft): ?>
            <li class="draft-warning__row">
              <dl class="draft-warning__details">
                <div>
                  <dt>วันที่สร้าง</dt>
                  <dd><?= e(format_thai_datetime($draft['created_at'])) ?></dd>
                </div>
                <div>
                  <dt>รายการยา</dt>
                  <dd><?= (int)$draft['item_count'] ?> รายการ</dd>
                </div>
              </dl>
              <span class="draft-warning__badge">ฉบับร่าง</span>
              <?php if ($index > 0): ?>
                <a class="draft-warning__draft-link" href="view_withdrawal.php?id=<?= (int)$draft['id'] ?>">จัดการฉบับร่างนี้</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (count($visibleDrafts) !== count($drafts)): ?>
        <p class="draft-warning__message">มีใบเบิกฉบับร่างนอกขอบเขตที่คุณเข้าถึงได้ กรุณาติดต่อผู้ดูแลระบบ</p>
      <?php endif; ?>
      <div class="draft-warning__actions">
        <a class="app-btn app-btn--secondary" id="close-draft-warning" href="dashboard.php">ปิด</a>
        <?php if ($visibleDrafts): ?>
          <a class="app-btn app-btn--primary" href="view_withdrawal.php?id=<?= (int)$visibleDrafts[0]['id'] ?>">
            จัดการใบเบิกฉบับร่าง
            <svg viewBox="0 0 20 20" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 10h11m-4-4 4 4-4 4"/></svg>
          </a>
        <?php endif; ?>
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
