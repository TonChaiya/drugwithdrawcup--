(function () {
  'use strict';
  const config = document.currentScript.dataset;
  const base = new URL('../../', document.currentScript.src);
  const form = document.querySelector('[data-withdrawal-recovery]');
  const context = form?.dataset.withdrawalRecovery;
  const prefix = 'withdrawal-recovery:v1:';
  const accountPrefix = prefix + config.account + ':' + config.accountFacility + ':';
  const key = accountPrefix + context;
  const retention = 12 * 60 * 60 * 1000;
  const interval = 120000;
  const fieldPattern = /^(stock|current_stock|qty|note|delivered|new_drug_item_id|new_current_stock|new_qty|new_note)\[([a-zA-Z0-9_]+)\]$/;
  let storage;
  let saved;
  let loaded = false;
  let paused = false;
  let inFlight = false;
  let navigating = false;
  let timer;
  let deadline = Date.now() + Number(config.remaining) * 1000;
  let lastPulse = Date.now();
  let warned = false;
  let storageWarned = false;
  let editGeneration = 0;

  try {
    storage = sessionStorage;
    for (let i = storage.length - 1; i >= 0; i--) {
      const entry = storage.key(i);
      if (!entry?.startsWith(prefix)) continue;
      try {
        const snapshot = JSON.parse(storage.getItem(entry));
        if (!(snapshot.expires > Date.now())) storage.removeItem(entry);
      } catch {
        storage.removeItem(entry);
      }
    }
  } catch {
    storage = null;
  }

  function read(entry) {
    try { return JSON.parse(storage?.getItem(entry) || 'null'); }
    catch { return null; }
  }

  function write(entry, value) {
    try {
      if (!storage) return false;
      if (value) storage.setItem(entry, JSON.stringify(value));
      else storage.removeItem(entry);
      return true;
    } catch { return false; }
  }

  function notify(message, actions = []) {
    let notice = document.getElementById('withdrawal-session-notice');
    if (!notice) {
      notice = document.createElement('aside');
      notice.id = 'withdrawal-session-notice';
      notice.className = 'withdrawal-session-notice';
      notice.setAttribute('role', 'status');
      (form || document.querySelector('main') || document.body).prepend(notice);
    }
    notice.replaceChildren(document.createTextNode(message));
    for (const [label, handler] of actions) {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      button.addEventListener('click', handler);
      notice.append(' ', button);
    }
    notice.hidden = false;
  }

  function hideNotice() {
    const notice = document.getElementById('withdrawal-session-notice');
    if (notice) notice.hidden = true;
  }

  async function request(kind, fields = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(new URL('withdrawal_session.php', base), {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrf },
        body: JSON.stringify({ kind, ...fields }), signal: controller.signal,
      });
      if (!response.headers.get('content-type')?.includes('application/json')) return { status: 'unavailable' };
      const result = await response.json();
      if (response.status === 401 && result.status === 'expired') return result;
      if (response.status === 409 && result.status === 'session_changed') return result;
      if (response.status === 403 && result.status === 'no_access') return result;
      if (!response.ok || result.status !== 'ok' || !Number.isFinite(result.remaining)) {
        return { status: 'unavailable' };
      }
      // Local time at completion can only estimate the deadline.
      deadline = Date.now() + result.remaining * 1000;
      warned = false;
      return result;
    } catch {
      return { status: 'unavailable' };
    } finally {
      clearTimeout(timeout);
    }
  }

  function showSessionIssue(result, resume) {
    if (paused) return;
    if (result.status === 'expired' || result.status === 'session_changed') {
      const returnTo = new URL('index.php', base);
      returnTo.searchParams.set('resume', resume);
      notify('กรุณาเข้าสู่ระบบอีกครั้ง แล้วตรวจข้อมูลใบเบิกก่อนบันทึกหรือส่งใหม่', [
        ['เข้าสู่ระบบ', () => {
          if (!preserve() && !confirm('เบราว์เซอร์เก็บข้อมูลชั่วคราวไม่ได้ การออกจากหน้านี้อาจทำให้ข้อมูลหาย ต้องการไปต่อหรือไม่?')) return;
          location.assign(returnTo);
        }],
      ]);
    } else {
      notify('ตรวจสอบเซสชันไม่ได้ กรุณาตรวจการเชื่อมต่อและลองกดบันทึกหรือส่งอีกครั้ง');
    }
  }

  function values() {
    const fields = {};
    for (const input of form?.elements || []) {
      if (!input.disabled && fieldPattern.test(input.name)) fields[input.name] = input.value;
    }
    return fields;
  }

  const initial = values();
  const version = form?.elements.namedItem('form_version')?.value || '';
  function changes() {
    const changed = {};
    for (const [name, value] of Object.entries(values())) {
      if (value !== initial[name]) changed[name] = value;
    }
    return changed;
  }

  function persist(snapshot) {
    if (!write(key, snapshot)) return false;
    saved = snapshot;
    return true;
  }

  function preserve() {
    clearTimeout(timer);
    if (!form || paused || !loaded) return true;
    const fields = changes();
    if (!Object.keys(fields).length && !saved?.pending) return !saved || persist(null);
    const unchanged = saved && JSON.stringify(fields) === JSON.stringify(saved.fields);
    return persist({
      expires: Date.now() + retention,
      facility: form.dataset.facility,
      status: form.dataset.status || '',
      version, fields, pending: saved?.pending || null,
      pendingMatches: !!(unchanged && saved.pendingMatches),
      conflict: !!saved?.conflict,
    });
  }

  async function checkReceipts() {
    if (!storage) return { status: 'ok' };
    const candidates = {};
    const snapshots = {};
    try {
      let count = 0;
      for (let i = 0; i < storage.length && count < 30; i++) {
        const entry = storage.key(i);
        if (!entry?.startsWith(accountPrefix)) continue;
        const snapshot = read(entry);
        const candidate = entry.slice(accountPrefix.length);
        if (snapshot?.pending && /^(new|[1-9][0-9]{0,9})$/.test(candidate)) {
          candidates[candidate] = snapshot.pending.token;
          snapshots[candidate] = snapshot;
          count++;
        }
      }
    } catch {
      return { status: 'unavailable' };
    }
    if (!Object.keys(candidates).length) return { status: 'ok' };
    const result = await request('receipts', { receipts: candidates });
    if (result.status !== 'ok') return result;
    for (const { context: receiptContext, attempt } of result.saved || []) {
      const entry = accountPrefix + receiptContext;
      const current = read(entry);
      if (!current || JSON.stringify(current) !== JSON.stringify(snapshots[receiptContext])) continue;
      const matches = current.pending.attempt === attempt && current.pendingMatches;
      if (matches) {
        // The new form intentionally persists only drugs with requested quantities.
        const remaining = receiptContext === 'new' ? current.pending.unselected : null;
        write(entry, remaining && Object.keys(remaining).length
          ? { ...current, fields: remaining, pending: null, pendingMatches: false }
          : null);
      }
      else write(entry, { ...current, pending: null, pendingMatches: false, conflict: true });
      if (receiptContext === context) {
        if (matches && loaded && !paused) navigating = true;
        saved = read(entry);
      }
    }
    return result;
  }

  function discard() {
    if (!confirm('ทิ้งข้อมูลใบเบิกที่เก็บไว้ชั่วคราวในแท็บนี้หรือไม่?')) return;
    if (!persist(null)) {
      notify('ล้างข้อมูลชั่วคราวไม่ได้ กรุณาลองใหม่');
      return;
    }
    paused = false;
    hideNotice();
  }

  function showRecovery() {
    paused = true;
    notify('พบข้อมูลใบเบิกที่ยังไม่ได้บันทึกในแท็บนี้', [['กู้ข้อมูล', restore], ['ทิ้งข้อมูล', discard]]);
  }

  async function restore() {
    if (inFlight || !saved) return;
    const snapshot = saved;
    const fields = Object.entries(snapshot.fields);
    const generation = editGeneration;
    const sameStatus = snapshot.status === (form.dataset.status || '');
    const sameFacility = snapshot.facility === form.dataset.facility;
    if (!sameStatus || !sameFacility) {
      notify('สถานะหรือสถานบริการของใบเบิกเปลี่ยนไป ระบบยังเก็บข้อมูลชั่วคราวไว้', [['ทิ้งข้อมูล', discard]]);
      return;
    }
    const existing = fields.filter(([name]) => !name.startsWith('new_'));
    const added = fields.filter(([name]) => name.startsWith('new_drug_item_id['));
    const ids = added.map(([, id]) => Number(id));
    if (existing.some(([name]) => !form.elements.namedItem(name))
        || ids.some(id => form.querySelector('tr[data-drug-id="' + id + '"]'))) {
      notify('รายการยาในใบเบิกเปลี่ยนไป กู้คืนทั้งหมดไม่ได้ ระบบยังเก็บข้อมูลชั่วคราวไว้', [['ทิ้งข้อมูล', discard]]);
      return;
    }
    const conflict = snapshot.conflict || snapshot.version !== version || Object.keys(changes()).length > 0;
    if (!confirm(conflict
      ? 'ใบเบิกมีข้อมูลใหม่ การกู้คืนจะแทนค่าบางช่องที่คุณเคยแก้ไข ต้องการกู้คืนหรือไม่?'
      : 'กู้ข้อมูลใบเบิกที่ยังไม่ได้บันทึกหรือไม่?')) return;
    inFlight = true;
    try {
      const result = await request('restore', {
        resume: context, facility: snapshot.facility, status: snapshot.status, ids,
      });
      if (result.status !== 'ok') {
        if (result.status === 'unavailable') {
          notify('ตรวจสอบเซสชันไม่ได้ กรุณาตรวจการเชื่อมต่อแล้วลองกู้ใหม่', [['กู้ข้อมูล', restore], ['ทิ้งข้อมูล', discard]]);
        } else if (result.status === 'no_access') {
          notify('สถานะหรือสิทธิ์เข้าถึงใบเบิกเปลี่ยนไป กรุณาโหลดหน้าใหม่ ระบบยังเก็บข้อมูลชั่วคราวไว้', [['ทิ้งข้อมูล', discard]]);
        } else {
          paused = false;
          showSessionIssue(result, context);
          paused = true;
        }
        return;
      }
      if (snapshot !== saved || generation !== editGeneration) return;
      const drugMap = new Map((result.drugs || []).map(drug => [String(drug.id), drug]));
      if (ids.some(id => !drugMap.has(String(id)))) {
        notify('มียาที่ถูกระงับหรือไม่มีแล้ว กู้คืนทั้งหมดไม่ได้ ระบบยังเก็บข้อมูลชั่วคราวไว้', [['ทิ้งข้อมูล', discard]]);
        return;
      }
      for (const [name, id] of added) {
        form.dispatchEvent(new CustomEvent('withdrawal:restore-row', {
          detail: { drug: drugMap.get(id), token: name.match(fieldPattern)[2] },
        }));
      }
      for (const [name, value] of fields) {
        const input = form.elements.namedItem(name);
        if (!input) continue;
        input.value = value;
        if (input.classList.contains('note-edit-value')) {
          const display = input.parentElement.querySelector('.note-edit-input');
          if (display) display.value = value;
        }
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
      paused = false;
      if (saved?.conflict) saved = { ...saved, conflict: false };
      preserve();
      notify('กู้ข้อมูลแล้ว กรุณาตรวจรายการและกดบันทึกหรือส่งด้วยตนเอง');
    } finally {
      inFlight = false;
    }
  }

  function id() {
    return Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
  }

  function setHidden(target, name, value) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    input.setAttribute('data-withdrawal-submit-field', '');
    target.prepend(input);
  }

  async function submit(event) {
    const target = event.target;
    const resume = target === form ? context : target.dataset?.withdrawalPreflight;
    if (event.defaultPrevented || !resume) return;
    event.preventDefault();
    if (!loaded || inFlight || navigating) return;
    if (paused) { showRecovery(); return; }
    if (target !== form && Object.keys(changes()).length) {
      preserve();
      notify('กรุณาบันทึกการแก้ไขรายการก่อนส่งใบเบิก');
      return;
    }
    const stored = preserve();
    if (!stored && !confirm('เบราว์เซอร์เก็บข้อมูลชั่วคราวไม่ได้ ต้องการส่งคำขอต่อหรือไม่?')) return;
    inFlight = true;
    try {
      const result = await request('preflight');
      if (result.status !== 'ok') { showSessionIssue(result, resume); return; }
      if (saved?.pending) {
        const checked = await checkReceipts();
        if (checked.status !== 'ok') { showSessionIssue(checked, resume); return; }
        if (navigating || saved?.conflict) {
          notify('พบการบันทึกก่อนหน้า กรุณาโหลดหน้าใหม่และตรวจใบเบิกก่อนลองอีกครั้ง',
            [['โหลดหน้าใหม่', () => location.reload()]]);
          return;
        }
      }
      if (event.isTrusted && !target.reportValidity()) return;
      if (target === form) {
        preserve();
        const pending = { token: saved?.pending?.token || id(), attempt: id() };
        const fields = changes();
        const fullValues = values();
        if (context === 'new') {
          pending.unselected = Object.fromEntries(Object.entries(fields).filter(([name]) => {
            const [, , drugId] = name.match(fieldPattern);
            return !(Number(fullValues['qty[' + drugId + ']']) > 0);
          }));
        }
        setHidden(target, 'recovery_token', pending.token);
        setHidden(target, 'recovery_attempt', pending.attempt);
        setHidden(target, 'recovery_field_count', String(Object.keys(fullValues).length));
        persist({
          expires: Date.now() + retention, facility: form.dataset.facility, status: form.dataset.status || '',
          version, fields, pending, pendingMatches: true,
        });
      }
      if (event.submitter?.name) setHidden(target, event.submitter.name, event.submitter.value);
      navigating = true;
      HTMLFormElement.prototype.submit.call(target);
    } finally {
      inFlight = false;
    }
  }

  async function activity(event) {
    if (!event.isTrusted || document.visibilityState !== 'visible' || inFlight || paused || navigating) return;
    const now = Date.now();
    if (now - lastPulse < interval) return;
    lastPulse = now;
    const shared = accountPrefix + 'pulse';
    try {
      if (now - Number(localStorage.getItem(shared) || '0') < interval) return;
      localStorage.setItem(shared, String(now));
    } catch { /* Storage can be disabled. Keep the per-tab limit. */ }
    const result = await request('active');
    if (result.status !== 'ok') showSessionIssue(result, context);
  }

  function showConfirmedSave() {
    navigating = true;
    notify('ระบบบันทึกใบเบิกจากหน้านี้แล้ว กรุณาโหลดหน้าใหม่ก่อนทำรายการต่อ', [['โหลดหน้าใหม่', () => location.reload()]]);
  }

  async function initialize(previousAttempt = null) {
    loaded = false;
    const checked = await checkReceipts();
    saved = read(key);
    let confirmed = previousAttempt && checked.saved?.some(r => r.context === context && r.attempt === previousAttempt.attempt);
    if (previousAttempt && !saved?.pending && !confirmed) {
      const result = await request('receipts', { receipts: { [context]: previousAttempt.token } });
      confirmed = result.saved?.some(r => r.context === context && r.attempt === previousAttempt.attempt);
    }
    loaded = true;
    if (confirmed) showConfirmedSave();
    else if (form && saved) showRecovery();
    else if (previousAttempt) {
      navigating = true;
      notify('ยังยืนยันผลการบันทึกไม่ได้ กรุณาโหลดหน้าใหม่และตรวจใบเบิกก่อนทำรายการต่อ', [['โหลดหน้าใหม่', () => location.reload()]]);
    }
    else if (context && context !== 'new' && read(accountPrefix + 'new')) {
      notify('แท็บนี้มีข้อมูลใบเบิกใหม่ที่ยังไม่ได้บันทึก กรุณาจัดการใบเบิกร่างฉบับนี้ก่อน แล้วกลับไปหน้าเบิกยาเพื่อกู้ข้อมูลใบใหม่');
    }
  }

  if (form) {
    for (const eventName of ['input', 'change', 'click']) {
      document.addEventListener(eventName, event => {
        if (!event.isTrusted) return;
        if (eventName !== 'click') editGeneration++;
        clearTimeout(timer);
        timer = setTimeout(autosave, 350);
        activity(event);
      });
    }
    const pendingRows = form.querySelector('#items-tbody');
    if (pendingRows) new MutationObserver(() => {
      editGeneration++;
      clearTimeout(timer);
      timer = setTimeout(autosave, 350);
    }).observe(pendingRows, { childList: true });
    window.addEventListener('pagehide', () => { if (!navigating) preserve(); });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden' && !navigating) preserve();
    });
    setInterval(() => {
      if (document.visibilityState !== 'visible' || paused || warned || deadline - Date.now() > 300000) return;
      warned = true;
      notify('เซสชันอาจใกล้หมดอายุ กรุณาบันทึกใบเบิกเมื่อพร้อม');
    }, 30000);
  }

  function autosave() {
    if (navigating || preserve() || storageWarned) return;
    storageWarned = true;
    notify('เบราว์เซอร์เก็บข้อมูลชั่วคราวไม่ได้ กรุณาบันทึกร่างก่อนปิดหรือโหลดหน้านี้ใหม่');
  }

  if (form || document.querySelector('[data-withdrawal-preflight]')) {
    window.addEventListener('submit', submit);
    window.addEventListener('pageshow', event => {
      if (event.persisted) {
        const previousAttempt = navigating ? saved?.pending : null;
        navigating = false;
        for (const input of document.querySelectorAll('[data-withdrawal-submit-field]')) input.remove();
        initialize(previousAttempt);
      }
    });
  }
  initialize();
})();
