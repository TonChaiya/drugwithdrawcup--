<style>
html {
  min-height: 100%;
}

body {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.app-footer {
  flex: 0 0 auto;
  margin-top: auto;
  color: #64748b;
  border-top: 1px solid rgba(15, 23, 42, .08);
  background:
    linear-gradient(90deg, rgba(255, 255, 255, .68), rgba(244, 250, 252, .72)),
    linear-gradient(90deg, rgba(20, 184, 166, .05), rgba(37, 99, 235, .05));
}

.app-footer-inner {
  width: min(1180px, calc(100% - 32px));
  min-height: 54px;
  margin: 0 auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 11px 0;
  font-size: 13px;
}

.app-footer strong {
  display: block;
  color: #172033;
  font-size: 13px;
  line-height: 1.35;
}

.app-footer span {
  display: inline-block;
  line-height: 1.5;
}

.footer-meta {
  display: flex;
  align-items: center;
  gap: 10px;
  white-space: nowrap;
}

.footer-meta span + span {
  position: relative;
  padding-left: 10px;
}

.footer-meta span + span::before {
  content: "";
  position: absolute;
  left: 0;
  top: 50%;
  width: 4px;
  height: 4px;
  border-radius: 999px;
  background: #14b8a6;
  transform: translateY(-50%);
}

@media (max-width: 560px) {
  .app-footer-inner {
    width: min(100% - 20px, 1180px);
    align-items: flex-start;
    flex-direction: column;
    gap: 8px;
    padding: 12px 0;
  }
}
</style>

<footer class="app-footer">
  <div class="app-footer-inner">
    <div>
      <strong>ระบบเบิกยา CUP สันกำแพง</strong>
      <span>Drug Withdrawal System</span>
    </div>
    <div class="footer-meta">
      <span>2026</span>
      <span>สงวนลิขสิทธิ์</span>
    </div>
  </div>
</footer>
