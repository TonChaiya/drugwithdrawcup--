<?php
require 'includes/db.php';
require 'includes/auth.php';
$user = require_login($pdo);
$host_code = $user['host_code'] ?? null;
if (!$host_code) { echo 'Missing host_code'; exit; }

$msg = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// โหลด type กลุ่มยา (ชื่อกลุ่ม ฯลฯ)
$drugTypes = require __DIR__ . "/includes/drug_types.php";

// ดึงรายการยา เรียงตาม type แล้วตามชื่อ
$stmt = $pdo->prepare("SELECT * FROM drug_item WHERE is_active = 1 ORDER BY type ASC, name ASC");
$stmt->execute();
$items = $stmt->fetchAll();

// จัดกลุ่มตาม type
$grouped = [];
foreach ($items as $it) {
    $t = $it['type'] ?: 'other';
    $grouped[$t][] = $it;
}

// โหลด draft ล่าสุด ของ user (ยังไม่ได้ใช้ แต่อย่าลบทิ้ง)
$stmt2 = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1');
$stmt2->execute([$user['id'],'draft']);
$draft = $stmt2->fetch();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <title>เบิกยา</title>

  <style>
    /* กล่องปุ่มลอย (สกอลแยกจากหน้า) */
    #floatingMenu {
      position: fixed;
      top: 120px;
      left: 10px;
      width: 170px;
      max-height: 70vh;
      overflow-y: auto;
      padding: 8px 6px 8px 8px;
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 0.75rem;
      box-shadow: 0 4px 10px rgba(15,23,42,0.25);
      z-index: 999;
      transition: transform 0.2s ease, opacity 0.2s ease;
    }

    /* scrollbar ของปุ่มลอย */
    #floatingMenu::-webkit-scrollbar { width: 6px; }
    #floatingMenu::-webkit-scrollbar-track { background: #e5e7eb; border-radius: 999px; }
    #floatingMenu::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 999px; }

    /* ตอนซ่อนเมนู */
    #floatingMenu.hidden {
      transform: translateX(-190px);
      opacity: 0.2;
    }

    /* ปุ่ม toggle ซ่อน/แสดง เมนู */
    .toggleMenuBtn {
      position: fixed;
      top: 120px;
      left: 12px;
      background: #0f172a;
      color: #f9fafb;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 13px;
      z-index: 1000;
      box-shadow: 0 3px 8px rgba(15,23,42,0.4);
    }

    .toggleMenuBtn span {
      font-size: 11px;
      opacity: 0.8;
    }

    #noteEditor.hidden {
      display: none;
    }
  </style>
</head>
<body class="bg-slate-50">
  <?php include __DIR__ . '/includes/nav.php'; ?>

  <!-- ปุ่มซ่อน/แสดง เมนู -->
  <button id="menuToggle" class="toggleMenuBtn">
    กลุ่มยา <span id="menuToggleHint">◀</span>
  </button>

  <!-- ปุ่มลอย (มีสกอลเฉพาะเมนู) -->
  <div id="floatingMenu">
    <div class="text-xs font-semibold text-slate-500 mb-2 px-1">
      เลือกกลุ่มยา
    </div>
    <?php
      $gIndex = 0;
      foreach ($grouped as $type => $list):
        $label = $drugTypes[$type]['label'] ?? $type;
    ?>
      <button
        type="button"
        onclick='scrollToGroup(<?php echo json_encode((string)$type, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
        class="w-full text-left px-3 py-1.5 mb-1 rounded-md text-[13px] font-medium
               bg-slate-800 text-slate-50 hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-400">
        <?php echo htmlspecialchars($label); ?>
      </button>
    <?php
      $gIndex++;
      endforeach;
    ?>
  </div>

  <script>
    // สกอหน้าไปยังหัวกลุ่มยา
    function scrollToGroup(type){
      const el = document.getElementById("group-" + type);
      if(el){
        window.scrollTo({ top: el.offsetTop - 80, behavior: 'smooth' });
      }
    }

    // ซ่อน/แสดง floating menu
    (function(){
      const toggleBtn = document.getElementById("menuToggle");
      const menu = document.getElementById("floatingMenu");
      const hint = document.getElementById("menuToggleHint");
      if (!toggleBtn || !menu || !hint) return;

      toggleBtn.addEventListener("click", function(){
        menu.classList.toggle("hidden");
        if (menu.classList.contains("hidden")) {
          hint.textContent = "▶";
        } else {
          hint.textContent = "◀";
        }
      });
    })();
  </script>

      <!-- JS: ป้องกันการกด Enter แล้วทำการ submit ฟอร์ม -->
      <script>
        (function(){
          function handler(e){
            const k = e.key || (e.which ? String.fromCharCode(e.which) : null);
            if (e.key === 'Enter' || e.keyCode === 13) {
              const t = e.target;
              if (t && t.tagName && t.tagName.toLowerCase() === 'textarea') return;
              // ให้ปุ่ม submit แบบชัดเจน (type=submit หรือ button) ทำงานตามปกติ
              if (t && (t.type === 'submit' || t.tagName.toLowerCase() === 'button')) return;
              e.preventDefault();
              e.stopPropagation();
              return false;
            }
          }

          function attach(){
            const form = document.querySelector('form[action="submit_withdrawal.php"]');
            if (!form) return;
            form.addEventListener('keydown', handler, true);
            form.addEventListener('keypress', handler, true);
            // also catch Enter on inputs that might be outside form bubble
            document.addEventListener('keydown', function(e){
              const active = document.activeElement;
              if (!active) return;
              if (active.form === form) return; // already handled by form
              if (e.key === 'Enter' || e.keyCode === 13) {
                if (active.tagName && active.tagName.toLowerCase() === 'textarea') return;
                if (active.type === 'submit' || active.tagName.toLowerCase() === 'button') return;
                e.preventDefault(); e.stopPropagation(); return false;
              }
            }, true);
          }

          if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach);
          else attach();
        })();
      </script>

<div class="max-w-7xl mx-auto pl-4 sm:pl-6 lg:pl-40 pr-6 pb-10">
<div class="mt-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
      <div>
        <h1 class="text-2xl font-semibold text-slate-800">หน้าเบิกยา</h1>
        <p class="text-sm text-slate-500 mt-0.5">
          เลือกรายการยา กรอกจำนวน และหมายเหตุให้ชัดเจนก่อนส่งให้แอดมินอนุมัติ
        </p>
      </div>
      <?php if($msg): ?>
        <div class="px-3 py-2 rounded-md bg-emerald-50 text-emerald-700 text-sm border border-emerald-200">
          <?php echo e($msg); ?>
        </div>
      <?php endif; ?>
    </div>

    <form method="post" action="submit_withdrawal.php" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">

      <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between bg-slate-50">
          <div class="text-sm font-medium text-slate-700">
            รายการยาทั้งหมดจัดตามกลุ่มยา
          </div>
          <div class="text-[11px] text-slate-500">
            ช่องจำนวนขอเบิกและหมายเหตุจะแสดงด้วยพื้นหลังสีเหลืองอ่อน
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full table-auto">
            <thead class="bg-slate-100/80">
              <tr>
                <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-600 border-b border-slate-200 text-center w-16">
                  ลำดับ
                </th>
                <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-600 border-b border-slate-200 w-28">
                  รหัส
                </th>
                <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-600 border-b border-slate-200">
                  รายการยา
                </th>
                <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-600 border-b border-slate-200 text-center w-40">
                  ยอดคงเหลือ<br>ปัจจุบัน
                </th>
                <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-600 border-b border-slate-200 text-center w-48">
                  จำนวนที่ต้องการ<br>(หน่วยบรรจุ)
                </th>
                <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-600 border-b border-slate-200 text-center w-44">
                  จำนวนที่ขอเบิกรวม
                </th>
                <th class="px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-600 border-b border-slate-200 w-64">
                  หมายเหตุ
                </th>
              </tr>
            </thead>

            <tbody class="text-sm text-slate-800">
              <?php
                $index = 1;
                // สีกลุ่มต่าง ๆ ให้ดูต่างกัน
                $styleMap = [
                  ['header' => 'bg-emerald-50', 'row' => 'bg-emerald-50/40'],
                  ['header' => 'bg-sky-50',     'row' => 'bg-sky-50/40'],
                  ['header' => 'bg-amber-50',   'row' => 'bg-amber-50/40'],
                  ['header' => 'bg-rose-50',    'row' => 'bg-rose-50/40'],
                  ['header' => 'bg-violet-50',  'row' => 'bg-violet-50/40'],
                ];
                $gIdx = 0;

                foreach($grouped as $type => $rows):
                  $label = $drugTypes[$type]['label'] ?? $type;
                  $style = $styleMap[$gIdx % count($styleMap)];
                  $headerClass = $style['header'];
                  $rowClass = $style['row'];
                  $gIdx++;
              ?>
                <!-- หัวกลุ่มยา -->
                <tr id="group-<?php echo e($type); ?>" class="<?php echo $headerClass; ?>">
                  <td colspan="7" class="px-4 py-2.5 border-t border-b border-slate-200 text-sm font-semibold text-slate-800">
                    <div class="flex items-center gap-2">
                      <div class="w-1.5 h-5 rounded-full bg-slate-500/80"></div>
                      <span><?php echo htmlspecialchars($label); ?></span>
                    </div>
                  </td>
                </tr>

                <?php foreach($rows as $it): ?>
                  <?php
                    // ดึงเลข pack_size เพื่อนำมาคำนวณ
                    $rawPack = trim((string)($it['pack_size'] ?? ''));
                    $packNum = 0;
                    if (preg_match('/([0-9]+(?:\.[0-9]+)?)/u', $rawPack, $m)) {
                      $packNum = (float)$m[1];
                    }
                    $unit = trim((string)($it['unit'] ?? ''));

                    $parts = array_filter([$rawPack, $unit], function($v){ return $v !== ''; });
                    $packLabel = count($parts) ? ('× ' . implode(' ', $parts)) : '× 1';
                  ?>
                  <tr class="border-b border-slate-100 hover:bg-slate-50 <?php echo $rowClass; ?>">
                    <!-- ลำดับวิ่งรวมทุกกลุ่ม -->
                    <td class="px-3 py-2 text-center text-[13px] text-slate-600">
                      <?php echo $index++; ?>
                    </td>

                    <!-- รหัส -->
                    <td class="px-3 py-2 text-[13px] text-slate-700">
                      <span class="inline-flex px-2 py-0.5 rounded-full bg-slate-800/90 text-slate-50 text-[11px] font-mono">
                        <?php echo e($it['working_code']); ?>
                      </span>
                    </td>

                    <!-- ชื่อยา -->
                    <td class="px-3 py-2 align-top">
                      <div class="font-medium text-slate-800">
                        <?php echo e($it['name']); ?>
                      </div>
                      <?php if($rawPack || $unit): ?>
                        <div class="text-[11px] text-slate-500 mt-0.5">
                          ขนาดบรรจุ: <?php echo e($rawPack); ?> <?php echo e($unit); ?>
                        </div>
                      <?php endif; ?>
                    </td>

                    <!-- ยอดคงเหลือ -->
                    <td class="px-3 py-2 text-center align-top">
                      <input
                        type="number"
                        min="0"
                        name="stock[<?php echo $it['id']; ?>]"
                        class="stock-input w-24 px-2 py-1.5 border border-slate-300 rounded-md text-sm
                               focus:outline-none focus:ring-2 focus:ring-sky-300 focus:border-sky-400 bg-white"
                        placeholder="คงเหลือ">
                    </td>

                    <!-- จำนวนที่ต้องการ -->
                    <td class="px-3 py-2 align-top">
                      <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-2">
                          <input
                            type="number"
                            min="0"
                            name="qty[<?php echo $it['id']; ?>]"
                            class="qty-input w-24 px-2 py-1.5 border rounded-md text-sm
                                   bg-amber-50 border-amber-300
                                   focus:outline-none focus:ring-2 focus:ring-amber-300 focus:border-amber-400"
                            data-packnum="<?php echo $packNum; ?>"
                            data-unit="<?php echo htmlspecialchars($unit); ?>"
                            data-id="<?php echo $it['id']; ?>"
                            placeholder="0">
                          <span class="text-[11px] text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">
                            <?php echo e($packLabel); ?>
                          </span>
                        </div>
                        <div class="text-[11px] text-slate-500">
                          เบิกเต็มกล่อง ตามขนาดบรรจุ
                        </div>
                      </div>
                    </td>

                    <!-- จำนวนขอเบิกรวม -->
                    <td class="px-3 py-2 text-center align-top">
                      <span
                        class="total-span inline-flex items-center justify-center min-w-[4rem] px-2 py-1.5 rounded-md
                               bg-slate-900 text-slate-50 text-[13px] font-medium"
                        id="total-<?php echo $it['id']; ?>">
                        0<?php echo $unit ? ' ' . e($unit) : ''; ?>
                      </span>
                    </td>

                    <!-- หมายเหตุ -->
                    <td class="px-3 py-2 align-top">
                      <input
                        type="text"
                        name="note[<?php echo $it['id']; ?>]"
                        class="note-popup-input w-full px-3 py-1.5 border rounded-md text-sm cursor-pointer
                               bg-amber-50 border-amber-300
                               focus:outline-none focus:ring-2 focus:ring-amber-300 focus:border-amber-400"
                        readonly
                        data-drug-code="<?php echo e($it['working_code']); ?>"
                        data-drug-name="<?php echo e($it['name']); ?>"
                        data-drug-id="<?php echo $it['id']; ?>"
                        placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)">
                    </td>
                  </tr>
                <?php endforeach; ?>

              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- JS เดิม: คำนวณจำนวนรวมแบบเรียลไทม์ -->
      <script>
        (function(){
          function formatNumber(n){
            if (Number.isInteger(n)) return n.toString();
            return n.toFixed(2).replace(/\.00$/, '');
          }
          document.querySelectorAll('.qty-input').forEach(function(inp){
            function update(){
              var val = parseFloat(inp.value || '0');
              if (!isFinite(val)) val = 0;
              var pack = parseFloat(inp.getAttribute('data-packnum') || '0');
              if (!isFinite(pack)) pack = 0;
              var unit = inp.getAttribute('data-unit') || '';
              var total = val * pack;
              var id = inp.getAttribute('data-id');
              var span = document.getElementById('total-' + id);
              if (span){ span.textContent = formatNumber(total) + (unit ? ' ' + unit : ''); }
            }
            inp.addEventListener('input', update);
            // initialize
            update();
          });
        })();
      </script>

      <!-- JS เดิม: ตรวจว่ามียอดคงเหลือสำหรับรายการที่มี qty>0 -->
      <script>
        (function(){
          const form = document.querySelector('form[action="submit_withdrawal.php"]');
          if (!form) return;
          form.addEventListener('submit', function(e){
            // only validate when there is at least one qty>0
            const qtys = form.querySelectorAll('input[name^="qty"]');
            const stocks = form.querySelectorAll('input[name^="stock"]');
            let missing = [];
            qtys.forEach(function(q){
              const val = parseFloat(q.value || '0');
              if (isFinite(val) && val > 0) {
                const id = q.getAttribute('data-id');
                const stockInp = form.querySelector('input[name="stock['+id+']"]');
                if (!stockInp) { missing.push(id); return; }
                const s = (stockInp.value||'').trim();
                if (s === '') {
                  missing.push(id);
                  stockInp.classList.add('border-red-500','ring-1','ring-red-200');
                } else {
                  stockInp.classList.remove('border-red-500','ring-1','ring-red-200');
                }
              }
            });
            if (missing.length > 0) {
              e.preventDefault();
              alert('กรุณากรอกยอดคงเหลือของรายการที่ทำการขอเบิก: ' + missing.join(', '));
              return false;
            }
          });
        })();
      </script>

      <!-- JS เดิม: ต้องมีอย่างน้อย 1 รายการที่กรอก qty>0 หรือมี note -->
      <script>
        (function(){
          const form = document.querySelector('form[action="submit_withdrawal.php"]');
          if (!form) return;
          function hasAnyEntry(){
            const qtys = form.querySelectorAll('input[name^="qty"]');
            for (const q of qtys){ if (parseFloat(q.value||'0') > 0) return true; }
            const notes = form.querySelectorAll('input[name^="note"]');
            for (const n of notes){ if ((n.value||'').trim() !== '') return true; }
            return false;
          }
          form.addEventListener('submit', function(e){
            if (!hasAnyEntry()){
              e.preventDefault(); alert('กรุณาระบุรายการและจำนวนก่อนบันทึก/ส่ง'); return false;
            }
          });
        })();
      </script>

      <div class="mt-2 flex flex-wrap gap-3 justify-end">
        <button
          id="save-btn"
          type="submit"
          name="action"
          value="save"
          class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-medium
                 bg-slate-500 text-white hover:bg-slate-600">
          บันทึกร่าง
        </button>
        <button
          id="submit-btn"
          type="submit"
          name="action"
          value="submit"
          class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md text-sm font-semibold
                 bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm">
          ยืนยันส่งให้แอดมิน
        </button>
      </div>
    </form>
  </div>

  <div id="noteEditor" class="fixed inset-0 z-[1100] hidden">
    <div class="absolute inset-0 bg-slate-950/60 backdrop-blur-[2px]" data-close-note-editor></div>
    <div class="relative z-10 min-h-full flex items-center justify-center p-4">
      <div class="w-full max-w-3xl rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 bg-slate-50 flex items-start justify-between gap-4">
          <div>
            <div class="text-[11px] uppercase tracking-wide text-slate-500">หมายเหตุของยา</div>
            <div id="noteEditorTitle" class="text-base font-semibold text-slate-800 mt-0.5"></div>
            <div id="noteEditorSub" class="text-sm text-slate-500 mt-0.5"></div>
          </div>
          <button type="button" class="px-3 py-1.5 rounded-md text-sm bg-slate-200 text-slate-700 hover:bg-slate-300" data-close-note-editor>
            ปิด
          </button>
        </div>
        <div class="p-4">
          <textarea
            id="noteEditorTextarea"
            rows="8"
            class="w-full resize-y rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-base text-slate-800
                   focus:outline-none focus:ring-2 focus:ring-amber-300 focus:border-amber-400"
            placeholder="พิมพ์หมายเหตุของยาตัวนี้ได้ที่นี่..."></textarea>
          <div class="mt-3 flex items-center justify-between gap-3">
            <div class="text-xs text-slate-500">
              กด Esc หรือคลิกพื้นหลังเพื่อปิด
            </div>
            <button type="button" class="px-4 py-2 rounded-md text-sm font-medium bg-emerald-600 text-white hover:bg-emerald-700" data-close-note-editor>
              เสร็จแล้ว
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>

  <script>
    (function(){
      const popup = document.getElementById('noteEditor');
      const textarea = document.getElementById('noteEditorTextarea');
      const title = document.getElementById('noteEditorTitle');
      const sub = document.getElementById('noteEditorSub');
      let activeInput = null;

      if (!popup || !textarea || !title || !sub) return;

      function openEditor(input){
        if (!input) return;
        activeInput = input;
        title.textContent = input.getAttribute('data-drug-name') || 'หมายเหตุ';
        sub.textContent = [
          input.getAttribute('data-drug-code') || '',
          input.getAttribute('data-drug-id') ? ('ID: ' + input.getAttribute('data-drug-id')) : ''
        ].filter(Boolean).join(' | ');
        textarea.value = input.value || '';
        popup.classList.remove('hidden');
        window.setTimeout(function(){
          textarea.focus();
          textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        }, 0);
      }

      function closeEditor(){
        if (activeInput) {
          activeInput.value = textarea.value;
        }
        popup.classList.add('hidden');
        activeInput = null;
      }

      document.addEventListener('click', function(e){
        const input = e.target.closest('.note-popup-input');
        if (!input) return;
        e.preventDefault();
        openEditor(input);
      }, true);

      document.addEventListener('focusin', function(e){
        const input = e.target.closest('.note-popup-input');
        if (!input) return;
        openEditor(input);
      });

      textarea.addEventListener('input', function(){
        if (activeInput) activeInput.value = textarea.value;
      });

      popup.addEventListener('click', function(e){
        if (e.target && e.target.hasAttribute('data-close-note-editor')) {
          closeEditor();
        }
      });

      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && !popup.classList.contains('hidden')) {
          closeEditor();
        }
      });

      const form = document.querySelector('form[action="submit_withdrawal.php"]');
      if (form) {
        form.addEventListener('submit', function(){
          if (activeInput) {
            activeInput.value = textarea.value;
          }
        });
      }
    })();
  </script>
</body>
</html>
