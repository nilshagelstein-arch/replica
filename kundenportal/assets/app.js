/* Kundenportal – kleine Komfortfunktionen (ohne externe Bibliotheken) */
(function () {
  'use strict';

  // Sicherheitsabfrage für Buttons mit data-confirm
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-confirm]');
    if (btn && !window.confirm(btn.getAttribute('data-confirm'))) ev.preventDefault();
  });

  // App-Freigaben: ganze Spalte an-/abwählen
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-toggle-col]');
    if (!b) return;
    var boxes = document.querySelectorAll('input[data-col="' + b.getAttribute('data-toggle-col') + '"]');
    var allOn = Array.prototype.every.call(boxes, function (x) { return x.checked; });
    Array.prototype.forEach.call(boxes, function (x) { x.checked = !allOn; });
  });

  // OAuth: automatisch zur Anmeldeseite weiterleiten
  var go = document.getElementById('oauth-go');
  if (go) window.location.href = go.href;

  // Druckansicht automatisch drucken
  if (document.body.classList.contains('print')) {
    window.addEventListener('load', function () { window.print(); });
  }

  // Felder nur bei bestimmter Auswahl anzeigen: data-show-if="feldname=wert"
  Array.prototype.forEach.call(document.querySelectorAll('[data-show-if]'), function (el) {
    var parts = el.getAttribute('data-show-if').split('=');
    var ctrl = document.querySelector('[name="' + parts[0] + '"]');
    if (!ctrl) return;
    function upd() { el.hidden = ctrl.value !== parts[1]; }
    ctrl.addEventListener('change', upd);
    upd();
  });

  // Formulare: Druckansicht
  document.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-print]')) window.print();
    if (ev.target.closest('[data-close]')) { window.close(); if (!window.closed) history.back(); }
  });

  // Listen filtern (z. B. Kundenauswahl) und alle sichtbaren an-/abwählen
  Array.prototype.forEach.call(document.querySelectorAll('[data-filter-list]'), function (inp) {
    var list = document.querySelector(inp.getAttribute('data-filter-list'));
    if (!list) return;
    inp.addEventListener('input', function () {
      var q = inp.value.trim().toLowerCase();
      Array.prototype.forEach.call(list.children, function (el) {
        el.hidden = q !== '' && el.textContent.toLowerCase().indexOf(q) === -1;
      });
    });
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-check-all]');
    if (!b) return;
    var list = document.querySelector(b.getAttribute('data-check-all'));
    var boxes = Array.prototype.filter.call(list.querySelectorAll('input[type=checkbox]'), function (x) { return !x.closest('[hidden]'); });
    var allOn = boxes.length && boxes.every(function (x) { return x.checked; });
    boxes.forEach(function (x) { x.checked = !allOn; });
  });

  var form = document.getElementById('request-form');
  if (!form) return;
  var dataEl = document.getElementById('template-data');
  var tpl = dataEl ? JSON.parse(dataEl.textContent) : { dep: {}, pos: {}, posDept: {} };
  var dep = document.getElementById('department_id');
  var pos = document.getElementById('position_id');
  var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="items[]"]'));
  var autoChecked = new Set();
  var manual = new Set();

  boxes.forEach(function (b) {
    b.addEventListener('change', function () { manual.add(b.value); });
  });

  function filterPositions() {
    var d = dep.value;
    Array.prototype.forEach.call(pos.options, function (o) {
      if (!o.value) return;
      var od = o.getAttribute('data-dep');
      var visible = !d || od === '0' || od === d;
      o.hidden = !visible;
      o.disabled = !visible;
      if (!visible && o.selected) pos.value = '';
    });
  }

  function applyTemplates() {
    var wanted = new Set();
    (tpl.dep[dep.value] || []).forEach(function (id) { wanted.add(String(id)); });
    (tpl.pos[pos.value] || []).forEach(function (id) { wanted.add(String(id)); });
    var added = 0;
    boxes.forEach(function (b) {
      if (manual.has(b.value)) return;
      if (wanted.has(b.value)) {
        if (!b.checked) added++;
        b.checked = true;
      } else if (autoChecked.has(b.value)) {
        b.checked = false;
      }
    });
    autoChecked = wanted;
    var hint = document.getElementById('template-hint');
    if (hint && (dep.value || pos.value)) {
      hint.textContent = wanted.size
        ? wanted.size + ' Einträge aus den Vorlagen für Abteilung/Position vorausgewählt. Sie können die Auswahl anpassen.'
        : 'Für diese Abteilung/Position ist keine Vorlage hinterlegt. Bitte wählen Sie die Einträge manuell aus.';
    }
  }

  // Position wählt passende Abteilung, falls noch keine gewählt
  pos.addEventListener('change', function () {
    var d = tpl.posDept[pos.value];
    if (d && !dep.value) { dep.value = String(d); filterPositions(); }
    applyTemplates();
  });
  dep.addEventListener('change', function () { filterPositions(); applyTemplates(); });
  filterPositions();

  // E-Mail-Vorschlag aus Vor- und Nachname
  var email = document.getElementById('email_wish');
  function norm(s) {
    return s.trim().toLowerCase()
      .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }
  function suggest() {
    if (!tpl.domain || !email) return;
    var f = norm(form.first_name.value), l = norm(form.last_name.value);
    if (f && l) email.placeholder = f + '.' + l + '@' + tpl.domain;
  }
  if (email) {
    form.first_name.addEventListener('input', suggest);
    form.last_name.addEventListener('input', suggest);
  }
  if (email) {
    email.addEventListener('focus', function () {
      if (!email.value && email.placeholder && email.placeholder.indexOf('vorname.') !== 0) email.value = email.placeholder;
    });
  }
  suggest();
})();
