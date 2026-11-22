/* Linksammlung: Bild-, Titel- und Beschreibungsvorschläge nach Eingabe der Adresse */
(function () {
  'use strict';
  // Übersetzung (Wörterbuch aus index.php?r=i18n_js)
  function T(s) { return (window.KP_T && window.KP_T[s]) || s; }
  var url = document.getElementById('link-url');
  if (!url) return;
  var btn = document.getElementById('link-suggest-btn');
  var box = document.getElementById('link-suggest');
  var grid = document.getElementById('link-suggest-grid');
  var status = document.getElementById('link-suggest-status');
  var pick = document.getElementById('image-pick');
  var file = document.querySelector('input[name="image"]');
  var remove = document.querySelector('input[name="remove_image"]');
  var form = url.form;
  var lastUrl = '';
  var running = null;

  function isWeb(v) {
    v = v.trim();
    if (!v || v.indexOf('@') !== -1 && v.indexOf('/') === -1) return false;
    return /^https?:\/\//i.test(v) || /^[\w.-]+\.[a-z]{2,}([:\/?#].*)?$/i.test(v);
  }

  function setPick(src, tile) {
    pick.value = src;
    Array.prototype.forEach.call(grid.querySelectorAll('.img-sug'), function (t) {
      t.classList.toggle('selected', t === tile && src !== '');
      t.setAttribute('aria-pressed', t === tile && src !== '' ? 'true' : 'false');
    });
    if (src && file) file.value = '';
    if (src && remove) remove.checked = false;
  }

  function load(force) {
    var v = url.value.trim();
    if (!isWeb(v) || (!force && v === lastUrl)) return;
    lastUrl = v;
    if (running) running.abort();
    running = new AbortController();
    box.hidden = false;
    grid.innerHTML = '';
    status.textContent = T('Suche Bilder auf der Webseite …');
    btn.disabled = true;
    fetch(url.getAttribute('data-suggest') + '&url=' + encodeURIComponent(v), { credentials: 'same-origin', signal: running.signal })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (!d.ok) { status.textContent = d.error || T('Keine Vorschläge gefunden.'); return; }
        if (d.title && form.title && !form.title.value.trim()) form.title.value = d.title;
        if (d.description && form.description && !form.description.value.trim()) form.description.value = d.description;
        if (!d.images.length) { status.textContent = T('Auf der Webseite wurden keine passenden Bilder gefunden. Sie können selbst ein Bild hochladen.'); return; }
        status.textContent = T('Bildvorschläge – zum Übernehmen anklicken:');
        d.images.forEach(function (im) {
          var t = document.createElement('button');
          t.type = 'button';
          t.className = 'img-sug';
          t.setAttribute('aria-pressed', 'false');
          t.title = im.kind + ' (' + im.size + ')';
          var f = document.createElement('span');
          f.className = 'img-sug-frame';
          var img = document.createElement('img');
          img.src = im.thumb;
          img.alt = im.kind;
          f.appendChild(img);
          var c = document.createElement('span');
          c.className = 'img-sug-cap';
          c.textContent = im.kind;
          t.appendChild(f);
          t.appendChild(c);
          t.addEventListener('click', function () { setPick(t.classList.contains('selected') ? '' : im.src, t); });
          grid.appendChild(t);
        });
      })
      .catch(function (e) {
        btn.disabled = false;
        if (e.name !== 'AbortError') status.textContent = T('Die Vorschläge konnten nicht geladen werden.');
      });
  }

  url.addEventListener('change', function () { load(false); });
  url.addEventListener('paste', function () { setTimeout(function () { load(false); }, 50); });
  btn.addEventListener('click', function () { load(true); });
  if (file) file.addEventListener('change', function () { if (file.value) setPick('', null); });
  // Neuer Link mit bereits eingetragener Adresse (z. B. nach Fehlermeldung): gleich laden
  if (url.value && !document.querySelector('.link-preview')) load(false);
})();
