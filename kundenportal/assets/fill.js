/* Kundenportal – Formulare direkt im Dokument ausfüllen */
(function () {
  'use strict';
  // Übersetzung (Wörterbuch aus index.php?r=i18n_js)
  function T(s) { return (window.KP_T && window.KP_T[s]) || s; }
  var LANG = window.KP_LANG || 'de', DSEP = LANG === 'de' ? '.' : '/';

  var root = document.querySelector('[data-fill]');
  if (!root) return;
  var C = window.KPCalc;
  var key = 'kp-fill-' + root.getAttribute('data-form-id');
  var store = {};
  try { store = JSON.parse(sessionStorage.getItem(key) || '{}') || {}; } catch (e) { store = {}; }
  function persist() { try { sessionStorage.setItem(key, JSON.stringify(store)); } catch (e) { /* privat */ } }
  function $$(sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); }
  function same(el) { return $$('[data-key]').filter(function (x) { return x !== el && x.getAttribute('data-key') === el.getAttribute('data-key'); }); }
  function fmtDate(v) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || ''); return m ? m[3] + DSEP + m[2] + DSEP + m[1] : ''; }

  // ---------------------------------------------------------------- Felder
  function setField(el, v) {
    if (el.classList.contains('fill')) { if (el.innerText !== v) el.innerText = v; el.classList.toggle('filled', v !== ''); }
    else if (el.classList.contains('fill-check')) { var on = v === '1'; el.setAttribute('aria-pressed', on ? 'true' : 'false'); el.textContent = on ? '☒' : '☐'; }
    else if (el.classList.contains('fill-select')) { el.value = v; if (el.nextElementSibling) el.nextElementSibling.textContent = v; }
    else if (el.classList.contains('fill-date')) { el.value = v; if (el.nextElementSibling) el.nextElementSibling.textContent = fmtDate(v); }
  }
  function valueOf(el) {
    if (el.classList.contains('fill')) return el.innerText.replace(/\n$/, '');
    if (el.classList.contains('fill-check')) return el.getAttribute('aria-pressed') === 'true' ? '1' : '';
    return el.value;
  }
  function changed(el) {
    var v = valueOf(el);
    store[el.getAttribute('data-key')] = v;
    same(el).forEach(function (x) { setField(x, v); });
    if (el.classList.contains('fill')) el.classList.toggle('filled', v !== '');
    if (el.classList.contains('fill-select') || el.classList.contains('fill-date')) setField(el, v);
    persist();
  }
  $$('[data-key]').forEach(function (el) {
    var k = el.getAttribute('data-key');
    if (store[k] !== undefined) setField(el, store[k]);
    if (el.classList.contains('fill')) {
      el.addEventListener('input', function () { changed(el); });
      el.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !el.classList.contains('fill-multi')) { ev.preventDefault(); focusNext(el); }
      });
      el.addEventListener('paste', function (ev) {
        ev.preventDefault();
        var t = (ev.clipboardData && ev.clipboardData.getData('text/plain')) || '';
        if (!el.classList.contains('fill-multi')) t = t.replace(/\s*\n\s*/g, ' ');
        document.execCommand('insertText', false, t);
      });
    } else if (el.classList.contains('fill-check')) {
      el.addEventListener('click', function () { setField(el, valueOf(el) === '1' ? '' : '1'); changed(el); });
    } else {
      el.addEventListener('change', function () { changed(el); });
    }
  });
  function focusNext(el) {
    var all = $$('.fill, .fill-select, .fill-date, .fill-check, td.fill-cell');
    var n = all[all.indexOf(el) + 1];
    if (n) n.focus();
  }

  // Datum des Dokuments
  var dateInput = document.querySelector('[data-doc-date]');
  var MONTHS = [T('Januar'), T('Februar'), T('März'), T('April'), T('Mai'), T('Juni'), T('Juli'), T('August'), T('September'), T('Oktober'), T('November'), T('Dezember')];
  function applyDate() {
    if (!dateInput) return;
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateInput.value);
    if (!m) return;
    $$('[data-auto="datum"]').forEach(function (x) { x.textContent = m[3] + DSEP + m[2] + DSEP + m[1]; });
    $$('[data-auto="datum.lang"]').forEach(function (x) { x.textContent = (+m[3]) + (LANG === 'de' ? '. ' : ' ') + MONTHS[+m[2] - 1] + ' ' + m[1]; });
  }
  if (dateInput) {
    if (store.__datum) dateInput.value = store.__datum;
    dateInput.addEventListener('change', function () { store.__datum = dateInput.value; persist(); applyDate(); });
    applyDate();
  }

  // ---------------------------------------------------------------- Rechentabellen
  function prepTable(t) {
    $$('td[data-input], th[data-input]', t).forEach(function (td) {
      td.setAttribute('contenteditable', 'true');
      td.classList.add('fill-cell');
      td.setAttribute('inputmode', td.hasAttribute('data-format') ? 'decimal' : 'text');
      formatCell(td);
    });
  }
  function formatCell(td) {
    var f = td.getAttribute('data-format'), t = td.textContent.trim();
    if (!f || t === '') return;
    var n = C.parseNumber(t);
    if (n !== null && !isNaN(n)) td.textContent = C.format(n, f);
  }
  $$('.sheet table').forEach(function (t) {
    prepTable(t);
    if (C.hasFormulas(t)) C.recalc(t);
    if (t.hasAttribute('data-grow')) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'grow-btn';
      b.textContent = T('+ Zeile hinzufügen');
      b.addEventListener('click', function () {
        var rows = Array.prototype.filter.call(t.rows, function (tr) { return tr.querySelector('[data-input]'); });
        var last = rows[rows.length - 1];
        if (!last) return;
        var nr = C.insertRowCopy(t, last, false);
        prepTable(t);
        C.recalc(t);
        var first = nr.querySelector('[data-input]');
        if (first) first.focus();
      });
      t.parentNode.insertBefore(b, t.nextSibling);
    }
  });
  root.addEventListener('input', function (ev) {
    var td = ev.target.closest && ev.target.closest('td.fill-cell, th.fill-cell');
    if (td) { var t = td.closest('table'); if (C.hasFormulas(t)) C.recalc(t); }
  });
  root.addEventListener('focusout', function (ev) {
    var td = ev.target.closest && ev.target.closest('td.fill-cell, th.fill-cell');
    if (td) { formatCell(td); var t = td.closest('table'); if (C.hasFormulas(t)) C.recalc(t); }
  });
  root.addEventListener('keydown', function (ev) {
    var td = ev.target.closest && ev.target.closest('td.fill-cell');
    if (!td) return;
    if (ev.key === 'Enter' || ev.key === 'Tab') {
      ev.preventDefault();
      var cells = $$('td.fill-cell', td.closest('table'));
      var n = cells[cells.indexOf(td) + (ev.shiftKey ? -1 : 1)];
      if (n) { n.focus(); var r = document.createRange(); r.selectNodeContents(n); var s = getSelection(); s.removeAllRanges(); s.addRange(r); }
    }
  });

  // ---------------------------------------------------------------- Kunden wählen, Leeren
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-apply-customers]');
    if (b) {
      var ids = Array.prototype.map.call(document.querySelectorAll('[data-customer]:checked'), function (x) { return x.value; });
      window.location.href = b.getAttribute('data-base') + (ids.length ? '&kunden=' + ids.join(',') : '');
    }
    if (ev.target.closest('[data-fill-reset]') && window.confirm(T('Alle Eingaben in diesem Dokument leeren?'))) {
      try { sessionStorage.removeItem(key); } catch (e) { /* privat */ }
      window.location.reload();
    }
  });
})();
