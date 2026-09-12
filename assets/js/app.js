(function () {
  'use strict';

  var navPanel;
  var navToggle;
  var lastFocusedElement = null;

  function setDocumentLocked(locked) {
    document.documentElement.classList.toggle('app-scroll-locked', locked);
  }

  function setNavOpen(open) {
    if (!navPanel || !navToggle) return;
    navPanel.classList.toggle('is-open', open);
    navPanel.setAttribute('aria-hidden', String(!open));
    navToggle.setAttribute('aria-expanded', String(open));
    setDocumentLocked(open || Boolean(document.querySelector('.app-modal.is-open')));

    if (open) {
      lastFocusedElement = document.activeElement;
      var closeButton = navPanel.querySelector('[data-app-nav-close]');
      if (closeButton) closeButton.focus();
    } else if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus();
      lastFocusedElement = null;
    }
  }

  function modalByName(name) {
    return document.getElementById('modal-' + name);
  }

  function openModal(name) {
    var modal = modalByName(name);
    if (!modal) return;
    lastFocusedElement = document.activeElement;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    setDocumentLocked(true);
    var closeButton = modal.querySelector('[data-app-modal-close]');
    if (closeButton) closeButton.focus();
  }

  function closeModal(name) {
    var modal = modalByName(name);
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    setDocumentLocked(Boolean(navPanel && navPanel.classList.contains('is-open')));
    if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
      lastFocusedElement.focus();
      lastFocusedElement = null;
    }
  }

  function closeOpenModal() {
    var modal = document.querySelector('.app-modal.is-open');
    if (!modal) return false;
    closeModal(modal.id.replace(/^modal-/, ''));
    return true;
  }

  document.addEventListener('DOMContentLoaded', function () {
    navPanel = document.querySelector('[data-app-nav-panel]');
    navToggle = document.querySelector('[data-app-nav-toggle]');

    if (navToggle && navPanel) {
      navToggle.addEventListener('click', function () {
        setNavOpen(!navPanel.classList.contains('is-open'));
      });
    }

    var navClose = document.querySelector('[data-app-nav-close]');
    if (navClose) navClose.addEventListener('click', function () { setNavOpen(false); });

    if (navPanel) {
      navPanel.addEventListener('click', function (event) {
        if (event.target === navPanel) setNavOpen(false);
      });
      navPanel.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () { setNavOpen(false); });
      });
    }

    document.querySelectorAll('[data-app-modal-open]').forEach(function (button) {
      button.addEventListener('click', function () { openModal(button.dataset.appModalOpen); });
    });

    document.querySelectorAll('[data-app-modal-close]').forEach(function (button) {
      button.addEventListener('click', function () { closeModal(button.dataset.appModalClose); });
    });

    document.querySelectorAll('.app-modal').forEach(function (modal) {
      modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal(modal.id.replace(/^modal-/, ''));
      });
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    if (closeOpenModal()) return;
    if (navPanel && navPanel.classList.contains('is-open')) setNavOpen(false);
  });

}());
