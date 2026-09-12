(function () {
  'use strict';

  function initWithdrawalWorkspace() {
    var form = document.getElementById('withdrawalForm');
    if (!form) return;

    var rows = Array.prototype.slice.call(form.querySelectorAll('[data-withdrawal-row]'));
    var groupHeaders = Array.prototype.slice.call(form.querySelectorAll('[data-withdrawal-group-header]'));
    var searchInput = form.querySelector('[data-withdrawal-search]');
    var clearSearchButton = form.querySelector('[data-withdrawal-search-clear]');
    var groupButtons = Array.prototype.slice.call(document.querySelectorAll('[data-withdrawal-group]'));
    var filledFilterInput = form.querySelector('[data-withdrawal-filled-filter]');
    var categoryDrawer = document.getElementById('withdrawalCategoryDrawer');
    var categoryOpenButtons = Array.prototype.slice.call(document.querySelectorAll('[data-withdrawal-category-open]'));
    var activeGroupLabel = form.querySelector('[data-withdrawal-active-group-label]');
    var summary = document.getElementById('withdrawalSummary');
    var selectedCounters = document.querySelectorAll('[data-withdrawal-selected-count]');
    var visibleCounter = form.querySelector('[data-withdrawal-visible-count]');
    var emptyState = form.querySelector('[data-withdrawal-empty]');
    var finalSubmitTrigger = form.querySelector('[data-final-submit-trigger]');
    var noteDialog = document.getElementById('withdrawalNoteDialog');
    var noteTextarea = document.getElementById('noteEditorTextarea');
    var noteTitle = document.getElementById('noteEditorTitle');
    var noteSubtitle = document.getElementById('noteEditorSub');
    var submitDialog = document.getElementById('withdrawalSubmitDialog');
    var activeGroup = 'all';
    var filledOnly = false;
    var activeNoteInput = null;
    var lastFocusedElement = null;
    var categoryReturnFocus = null;
    var suppressNextNoteFocus = false;

    function normalize(value) {
      return String(value || '').trim().toLowerCase();
    }

    function parseNonNegativeInput(input) {
      var raw = String(input.value || '').trim();
      if (raw === '') return { value: 0, invalid: false };
      var value = Number(raw);
      return {
        value: Number.isFinite(value) && value >= 0 ? value : 0,
        invalid: !Number.isFinite(value) || value < 0
      };
    }

    function formatNumber(number) {
      if (Number.isInteger(number)) return String(number);
      return number.toFixed(2).replace(/\.00$/, '');
    }

    function setDocumentLocked(locked) {
      document.documentElement.classList.toggle('app-scroll-locked', locked);
    }

    function isDialogOpen(dialog) {
      return Boolean(dialog && !dialog.hidden);
    }

    function refreshDocumentLock() {
      setDocumentLocked(isDialogOpen(noteDialog) || isDialogOpen(submitDialog) || isDialogOpen(categoryDrawer));
    }

    function updateRow(row) {
      var quantityInput = row.querySelector('[data-qty-input]');
      var stockInput = row.querySelector('[data-stock-input]');
      var quantityFeedback = row.querySelector('[data-qty-feedback]');
      var stockFeedback = row.querySelector('[data-stock-feedback]');
      var totalOutput = row.querySelector('[data-total-output]');
      if (!quantityInput || !stockInput || !totalOutput) return false;

      var quantity = parseNonNegativeInput(quantityInput);
      var packSize = Number(quantityInput.getAttribute('data-packnum') || 0);
      if (!Number.isFinite(packSize)) packSize = 0;
      var unit = quantityInput.getAttribute('data-unit') || '';
      var isSelected = !quantity.invalid && quantity.value > 0;
      var total = quantity.value * packSize;

      totalOutput.textContent = formatNumber(total) + (unit ? ' ' + unit : '');
      row.classList.toggle('is-selected', isSelected);
      row.classList.toggle('has-invalid-quantity', quantity.invalid);
      quantityInput.setAttribute('aria-invalid', String(quantity.invalid));
      if (quantityFeedback) quantityFeedback.hidden = !quantity.invalid;

      var stock = parseNonNegativeInput(stockInput);
      var stockMissing = isSelected && String(stockInput.value || '').trim() === '';
      var stockInvalid = stock.invalid || stockMissing;
      row.classList.toggle('has-missing-stock', stockMissing);
      row.classList.toggle('has-invalid-stock', stock.invalid);
      stockInput.setAttribute('aria-invalid', String(stockInvalid));
      if (stockFeedback) {
        stockFeedback.textContent = stock.invalid
          ? 'กรุณากรอกยอดคงเหลือตั้งแต่ 0 ขึ้นไป'
          : 'กรุณากรอกยอดคงเหลือเพื่อรายงาน';
        stockFeedback.hidden = !stockInvalid;
      }

      return isSelected;
    }

    function matchesCurrentFilters(row) {
      var query = searchInput ? normalize(searchInput.value) : '';
      var code = normalize(row.getAttribute('data-drug-code'));
      var name = normalize(row.getAttribute('data-drug-name'));
      var group = row.getAttribute('data-drug-group') || '';
      var quantityInput = row.querySelector('[data-qty-input]');
      var quantity = quantityInput ? parseNonNegativeInput(quantityInput) : { value: 0, invalid: false };
      var matchesSearch = query === '' || code.indexOf(query) !== -1 || name.indexOf(query) !== -1;
      var matchesGroup = activeGroup === 'all' || group === activeGroup;
      var matchesFilled = !filledOnly || (!quantity.invalid && quantity.value > 0);
      return matchesSearch && matchesGroup && matchesFilled;
    }

    function refreshWorkspace() {
      var selectedCount = 0;
      var visibleCount = 0;
      var visibleByGroup = Object.create(null);

      rows.forEach(function (row) {
        if (updateRow(row)) selectedCount += 1;
        var visible = matchesCurrentFilters(row);
        row.hidden = !visible;
        if (visible) {
          visibleCount += 1;
          var group = row.getAttribute('data-drug-group') || '';
          visibleByGroup[group] = (visibleByGroup[group] || 0) + 1;
        }
      });

      groupHeaders.forEach(function (header) {
        var group = header.getAttribute('data-withdrawal-group-header') || '';
        header.hidden = !visibleByGroup[group];
      });

      selectedCounters.forEach(function (counter) {
        counter.textContent = String(selectedCount);
      });
      if (visibleCounter) visibleCounter.textContent = visibleCount + ' รายการที่แสดง';
      if (emptyState) emptyState.hidden = visibleCount !== 0;
    }

    function setActiveGroup(group) {
      activeGroup = group;
      groupButtons.forEach(function (button) {
        var active = button.getAttribute('data-withdrawal-group') === group;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', String(active));
        if (active && activeGroupLabel) {
          var name = button.querySelector('span');
          activeGroupLabel.textContent = name ? name.textContent : button.textContent;
        }
      });
      refreshWorkspace();
    }

    function openCategoryDrawer(trigger) {
      if (!categoryDrawer) return;
      categoryReturnFocus = trigger || document.activeElement;
      categoryDrawer.hidden = false;
      categoryDrawer.setAttribute('aria-hidden', 'false');
      categoryOpenButtons.forEach(function (button) { button.setAttribute('aria-expanded', 'true'); });
      refreshDocumentLock();
      var selected = categoryDrawer.querySelector('[data-withdrawal-group].is-active');
      if (selected) selected.focus();
    }

    function closeCategoryDrawer() {
      if (!isDialogOpen(categoryDrawer)) return;
      categoryDrawer.hidden = true;
      categoryDrawer.setAttribute('aria-hidden', 'true');
      categoryOpenButtons.forEach(function (button) { button.setAttribute('aria-expanded', 'false'); });
      refreshDocumentLock();
      if (categoryReturnFocus && typeof categoryReturnFocus.focus === 'function') categoryReturnFocus.focus();
      categoryReturnFocus = null;
    }

    function revealInvalidField(input) {
      if (!input) return;
      if (searchInput) searchInput.value = '';
      filledOnly = false;
      if (filledFilterInput) filledFilterInput.checked = false;
      setActiveGroup('all');
      input.focus();
      if (typeof input.scrollIntoView === 'function') input.scrollIntoView({ block: 'center' });
    }

    function validNativeInputs() {
      if (form.checkValidity()) return true;
      revealInvalidField(form.querySelector(':invalid'));
      form.reportValidity();
      return false;
    }

    function syncActiveNote() {
      if (activeNoteInput && noteTextarea) activeNoteInput.value = noteTextarea.value;
    }

    function openNoteEditor(input) {
      if (!noteDialog || !noteTextarea || !noteTitle || !noteSubtitle || !input) return;
      if (activeNoteInput === input && isDialogOpen(noteDialog)) return;
      activeNoteInput = input;
      lastFocusedElement = input;
      noteTitle.textContent = input.getAttribute('data-drug-name') || 'หมายเหตุ';
      noteSubtitle.textContent = [
        input.getAttribute('data-drug-code') || '',
        input.getAttribute('data-drug-id') ? 'ID: ' + input.getAttribute('data-drug-id') : ''
      ].filter(Boolean).join(' · ');
      noteTextarea.value = input.value || '';
      noteDialog.hidden = false;
      noteDialog.setAttribute('aria-hidden', 'false');
      refreshDocumentLock();
      window.setTimeout(function () {
        noteTextarea.focus();
        noteTextarea.setSelectionRange(noteTextarea.value.length, noteTextarea.value.length);
      }, 0);
    }

    function closeNoteEditor() {
      if (!isDialogOpen(noteDialog)) return;
      syncActiveNote();
      var returnFocus = lastFocusedElement;
      noteDialog.hidden = true;
      noteDialog.setAttribute('aria-hidden', 'true');
      refreshDocumentLock();
      activeNoteInput = null;
      lastFocusedElement = null;
      if (returnFocus && typeof returnFocus.focus === 'function') {
        suppressNextNoteFocus = true;
        returnFocus.focus();
        window.setTimeout(function () { suppressNextNoteFocus = false; }, 0);
      }
    }

    function openSubmitDialog() {
      if (!submitDialog) return;
      lastFocusedElement = finalSubmitTrigger;
      submitDialog.hidden = false;
      submitDialog.setAttribute('aria-hidden', 'false');
      refreshDocumentLock();
      var confirmButton = submitDialog.querySelector('[data-confirm-final-submit]');
      if (confirmButton) confirmButton.focus();
    }

    function closeSubmitDialog() {
      if (!isDialogOpen(submitDialog)) return;
      submitDialog.hidden = true;
      submitDialog.setAttribute('aria-hidden', 'true');
      refreshDocumentLock();
      if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') lastFocusedElement.focus();
      lastFocusedElement = null;
    }

    function hasAnyEntry() {
      return rows.some(function (row) {
        var input = row.querySelector('[data-qty-input]');
        return input && parseFloat(input.value || '0') > 0;
      });
    }

    function validateWithdrawal() {
      refreshWorkspace();
      var firstInvalid = null;
      var missingIds = [];

      rows.forEach(function (row) {
        var quantityInput = row.querySelector('[data-qty-input]');
        var stockInput = row.querySelector('[data-stock-input]');
        if (!quantityInput || !stockInput) return;
        var quantity = parseNonNegativeInput(quantityInput);
        var stock = parseNonNegativeInput(stockInput);
        if (quantity.invalid && !firstInvalid) firstInvalid = quantityInput;
        if (stock.invalid && !firstInvalid) firstInvalid = stockInput;
        if (!quantity.invalid && quantity.value > 0 && String(stockInput.value || '').trim() === '') {
          missingIds.push(quantityInput.getAttribute('data-id') || '');
          if (!firstInvalid) firstInvalid = stockInput;
        }
      });

      if (firstInvalid) {
        revealInvalidField(firstInvalid);
        if (missingIds.length) {
          window.alert('กรุณากรอกยอดคงเหลือของรายการที่ทำการขอเบิก: ' + missingIds.join(', '));
        } else {
          window.alert('กรุณาตรวจสอบค่าตัวเลขที่กรอก');
        }
        return false;
      }

      if (!hasAnyEntry()) {
        window.alert('กรุณาระบุรายการและจำนวนก่อนบันทึก/ส่ง');
        return false;
      }
      return true;
    }

    function preventImplicitSubmit(event) {
      if (event.key !== 'Enter' && event.keyCode !== 13) return;
      var target = event.target;
      if (!target || !target.tagName) return;
      var tagName = target.tagName.toLowerCase();
      if (tagName === 'textarea' || target.type === 'submit' || tagName === 'button') return;
      event.preventDefault();
      event.stopPropagation();
    }

    if (searchInput) searchInput.addEventListener('input', refreshWorkspace);
    if (clearSearchButton && searchInput) {
      clearSearchButton.addEventListener('click', function () {
        searchInput.value = '';
        refreshWorkspace();
        searchInput.focus();
      });
    }

    groupButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        setActiveGroup(button.getAttribute('data-withdrawal-group') || 'all');
        closeCategoryDrawer();
      });
    });

    if (filledFilterInput) {
      filledFilterInput.addEventListener('change', function () {
        filledOnly = filledFilterInput.checked;
        refreshWorkspace();
      });
    }

    categoryOpenButtons.forEach(function (button) {
      button.addEventListener('click', function () { openCategoryDrawer(button); });
    });
    if (categoryDrawer) {
      categoryDrawer.querySelectorAll('[data-withdrawal-category-close]').forEach(function (button) {
        button.addEventListener('click', closeCategoryDrawer);
      });
    }

    function scrollBehavior() {
      return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    }
    var scrollTopButton = document.querySelector('[data-withdrawal-scroll-top]');
    var scrollBottomButton = document.querySelector('[data-withdrawal-scroll-bottom]');
    if (scrollTopButton) scrollTopButton.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: scrollBehavior() });
    });
    if (scrollBottomButton && summary) scrollBottomButton.addEventListener('click', function () {
      summary.scrollIntoView({ block: 'start', behavior: scrollBehavior() });
    });

    form.addEventListener('focusin', function (event) {
      if (event.target.closest && event.target.closest('[data-withdrawal-row]')) {
        document.body.classList.add('is-editing');
      }
    });
    form.addEventListener('focusout', function () {
      window.setTimeout(function () {
        var focused = document.activeElement;
        if (!focused || !focused.closest || !focused.closest('[data-withdrawal-row]')) {
          document.body.classList.remove('is-editing');
        }
      }, 0);
    });

    rows.forEach(function (row) {
      var quantityInput = row.querySelector('[data-qty-input]');
      var stockInput = row.querySelector('[data-stock-input]');
      var noteInput = row.querySelector('.note-popup-input');
      if (quantityInput) quantityInput.addEventListener('input', refreshWorkspace);
      if (stockInput) stockInput.addEventListener('input', function () { updateRow(row); });
      if (noteInput) {
        noteInput.addEventListener('click', function (event) {
          event.preventDefault();
          openNoteEditor(noteInput);
        });
        noteInput.addEventListener('focus', function () {
          if (suppressNextNoteFocus) return;
          openNoteEditor(noteInput);
        });
      }
    });

    if (noteTextarea) {
      noteTextarea.addEventListener('input', syncActiveNote);
    }
    if (noteDialog) {
      noteDialog.querySelectorAll('[data-close-note-editor]').forEach(function (button) {
        button.addEventListener('click', closeNoteEditor);
      });
    }

    if (finalSubmitTrigger) {
      finalSubmitTrigger.addEventListener('click', function (event) {
        event.preventDefault();
        syncActiveNote();
        if (!validateWithdrawal() || !validNativeInputs()) return;
        openSubmitDialog();
      });
    }
    var saveButton = document.getElementById('save-btn');
    if (saveButton) saveButton.addEventListener('click', function (event) {
      syncActiveNote();
      if (!validateWithdrawal() || !validNativeInputs()) event.preventDefault();
    });
    if (submitDialog) {
      submitDialog.querySelectorAll('[data-close-submit-dialog]').forEach(function (button) {
        button.addEventListener('click', closeSubmitDialog);
      });
    }

    form.addEventListener('keydown', preventImplicitSubmit, true);
    form.addEventListener('keypress', preventImplicitSubmit, true);
    form.addEventListener('submit', function (event) {
      syncActiveNote();
      if (!validateWithdrawal()) event.preventDefault();
    });

    document.addEventListener('keydown', function (event) {
      var openDialog = isDialogOpen(noteDialog) ? noteDialog
        : (isDialogOpen(submitDialog) ? submitDialog : (isDialogOpen(categoryDrawer) ? categoryDrawer : null));
      if (event.key === 'Escape') {
        if (openDialog === noteDialog) closeNoteEditor();
        if (openDialog === submitDialog) closeSubmitDialog();
        if (openDialog === categoryDrawer) closeCategoryDrawer();
        return;
      }
      if (event.key === 'Tab' && openDialog) {
        var focusable = Array.prototype.slice.call(openDialog.querySelectorAll('button, textarea, [href], input:not([type="hidden"])'))
          .filter(function (element) { return !element.disabled && element.offsetParent !== null; });
        if (!focusable.length) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });

    refreshWorkspace();
  }

  document.addEventListener('DOMContentLoaded', initWithdrawalWorkspace);
}());
