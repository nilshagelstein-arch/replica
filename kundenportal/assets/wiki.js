/* Dokumente & Anleitungen: Seitenbaum (Ein-/Ausklappen) und Seiten-Editor */
(function () {
  'use strict';
  // Übersetzung (Wörterbuch aus index.php?r=i18n_js)
  function T(s) { return (window.KP_T && window.KP_T[s]) || s; }

  // --- Seitenbaum: Unterseiten ein- und ausklappen ---------------------------
  document.addEventListener('click', function (ev) {
    var tw = ev.target.closest('.wk-twisty');
    if (!tw) return;
    var li = tw.parentNode;
    var open = !li.classList.contains('open');
    li.classList.toggle('open', open);
    li.classList.toggle('closed', !open);
    tw.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  // --- Editor ----------------------------------------------------------------
  var form = document.getElementById('wk-form');
  var ed = document.getElementById('wk-editor');
  var ta = document.getElementById('wk-body');
  var bar = document.getElementById('wk-toolbar');
  if (!form || !ed || !ta || !bar) return;

  var IMG_MAX_W = 1600;      // Bilder werden auf diese Breite verkleinert
  var IMG_MAX_BYTES = 8 * 1024 * 1024; // größere Dateien gar nicht erst einlesen

  // Editor statt Textfeld zeigen (ohne JavaScript bleibt das HTML-Textfeld)
  ed.innerHTML = ta.value;
  ed.contentEditable = 'true';
  ed.hidden = false;
  bar.hidden = false;
  ta.hidden = true;
  if (!ed.innerHTML.trim()) ed.innerHTML = '<p><br></p>';
  Array.prototype.forEach.call(ed.querySelectorAll('div.wk-toc'), function (d) { d.contentEditable = 'false'; d.textContent = T('Inhaltsverzeichnis'); });
  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* ältere Browser */ }
  try { document.execCommand('styleWithCSS', false, false); } catch (e) { /* ältere Browser */ }

  var dirty = false;
  ed.addEventListener('input', function () { dirty = true; });
  form.querySelector('[name=title]').addEventListener('input', function () { dirty = true; });
  form.addEventListener('submit', function () {
    ta.value = ed.innerHTML;
    dirty = false;
  });
  window.addEventListener('beforeunload', function (ev) {
    if (dirty) { ev.preventDefault(); ev.returnValue = ''; }
  });
  document.addEventListener('keydown', function (ev) {
    if ((ev.ctrlKey || ev.metaKey) && (ev.key === 's' || ev.key === 'S')) {
      ev.preventDefault();
      if (form.reportValidity()) { ta.value = ed.innerHTML; dirty = false; form.submit(); }
    }
  });

  // Auswahl merken, damit Knöpfe und Auswahllisten sie nicht verlieren
  var saved = null;
  function inEditor(node) { return node && (node === ed || ed.contains(node)); }
  function saveSel() {
    var s = window.getSelection();
    if (s.rangeCount && inEditor(s.getRangeAt(0).commonAncestorContainer)) saved = s.getRangeAt(0).cloneRange();
  }
  function restoreSel() {
    ed.focus();
    if (!saved) return;
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(saved);
  }
  // Auswahl nur merken, solange der Editor den Fokus hat (und beim Verlassen), sonst wäre sie veraltet
  document.addEventListener('selectionchange', function () {
    if (document.activeElement === ed) { saveSel(); updateState(); }
  });
  ['keyup', 'mouseup', 'input', 'blur'].forEach(function (evName) { ed.addEventListener(evName, saveSel); });

  function exec(cmd, val) {
    restoreSel();
    document.execCommand(cmd, false, val === undefined ? null : val);
    dirty = true;
    saveSel();
    updateState();
  }

  function insertHtml(html) {
    restoreSel();
    document.execCommand('insertHTML', false, html);
    dirty = true;
    saveSel();
  }

  /** Element an der Einfügemarke einsetzen (ersetzt eine Markierung), dahinter ein Leerzeichen */
  function insertNode(node) {
    restoreSel();
    var s = window.getSelection();
    if (!s.rangeCount || !inEditor(s.getRangeAt(0).commonAncestorContainer)) return;
    var r = s.getRangeAt(0);
    r.deleteContents();
    var space = document.createTextNode('\u00a0');
    r.insertNode(space);
    r.insertNode(node);
    r.setStartAfter(space);
    r.collapse(true);
    s.removeAllRanges();
    s.addRange(r);
    saved = r.cloneRange();
    dirty = true;
  }

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }

  /** Block-Element (Absatz, Überschrift, Liste …) um die Einfügemarke */
  function currentBlock() {
    var s = window.getSelection();
    if (!s.rangeCount) return null;
    var n = s.getRangeAt(0).startContainer;
    while (n && n !== ed) {
      if (n.nodeType === 1 && /^(P|H[1-4]|UL|OL|TABLE|BLOCKQUOTE|DIV|HR)$/.test(n.nodeName) && n.parentNode === ed) return n;
      if (n.nodeType === 1 && n.parentNode && n.parentNode.nodeType === 1 && /\bwk-(info|note|warn|ok)\b/.test(n.parentNode.className)) return n;
      n = n.parentNode;
    }
    return null;
  }

  /** Element auf oberster Ebene des Editors, in dem die Einfügemarke steht */
  function topBlock() {
    var s = window.getSelection();
    if (!s.rangeCount) return null;
    var n = s.getRangeAt(0).startContainer;
    if (!inEditor(n) || n === ed) return null;
    while (n.parentNode && n.parentNode !== ed) n = n.parentNode;
    return n.parentNode === ed ? n : null;
  }

  function closest(tag) {
    var s = window.getSelection();
    if (!s.rangeCount) return null;
    var n = s.getRangeAt(0).startContainer;
    if (n.nodeType !== 1) n = n.parentNode;
    var el = n && n.closest ? n.closest(tag) : null;
    return el && inEditor(el) ? el : null;
  }

  var blockSel = bar.querySelector('[data-block]');
  var tableBtns = bar.querySelector('.wk-tablebtns');
  function updateState() {
    var s = window.getSelection();
    if (!s.rangeCount || !inEditor(s.getRangeAt(0).commonAncestorContainer)) return;
    var h = closest('h1,h2,h3,h4');
    blockSel.value = h ? h.nodeName.toLowerCase().replace('h1', 'h2') : 'p';
    tableBtns.hidden = !closest('td,th');
    Array.prototype.forEach.call(bar.querySelectorAll('[data-cmd]'), function (b) {
      var c = b.getAttribute('data-cmd');
      if (['bold', 'italic', 'underline', 'strikeThrough', 'insertUnorderedList', 'insertOrderedList'].indexOf(c) === -1) return;
      var on = false;
      try { on = document.queryCommandState(c); } catch (e) { /* nicht unterstützt */ }
      b.classList.toggle('on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  // Knöpfe: Fokus im Editor lassen
  bar.addEventListener('mousedown', function (ev) {
    if (ev.target.closest('button')) ev.preventDefault();
  });

  bar.addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b) return;
    var cmd = b.getAttribute('data-cmd');
    if (cmd) { exec(cmd); return; }
    var act = b.getAttribute('data-act');
    if (actions[act]) actions[act]();
  });

  blockSel.addEventListener('change', function () {
    exec('formatBlock', '<' + blockSel.value + '>');
  });

  // Infobox: aktuellen Absatz in einen farbigen Kasten setzen (oder herausnehmen)
  var boxSel = bar.querySelector('[data-box]');
  boxSel.addEventListener('change', function () {
    var v = boxSel.value;
    boxSel.value = '';
    restoreSel();
    var box = closest('div.wk-info,div.wk-note,div.wk-warn,div.wk-ok');
    if (v === 'none') {
      if (box) {
        while (box.firstChild) box.parentNode.insertBefore(box.firstChild, box);
        box.parentNode.removeChild(box);
      }
    } else if (v) {
      if (box) {
        box.className = v;
      } else {
        var blk = currentBlock();
        var d = document.createElement('div');
        d.className = v;
        if (blk && blk.parentNode === ed) {
          ed.insertBefore(d, blk);
          d.appendChild(blk);
        } else {
          d.innerHTML = '<p>' + esc(T('Text der Infobox')) + '</p>';
          ed.appendChild(d);
        }
      }
    }
    dirty = true;
    ed.focus();
  });

  // Statusmarker (farbiges Etikett im Text)
  var stSel = bar.querySelector('[data-status]');
  stSel.addEventListener('change', function () {
    var c = stSel.value;
    stSel.value = '';
    if (!c) return;
    var text = window.prompt(T('Text des Statusmarkers (z. B. ERLEDIGT, IN ARBEIT)'), T('IN ARBEIT'));
    if (!text || !text.trim()) { ed.focus(); return; }
    var sp = document.createElement('span');
    sp.className = 'wk-st wk-st-' + c;
    sp.textContent = text.trim().slice(0, 40);
    insertNode(sp);
  });

  /** Einfügemarke an den Anfang (oder: Inhalt markieren) eines Elements setzen */
  function placeCaret(el, select) {
    if (!el) return;
    var r = document.createRange();
    r.selectNodeContents(el);
    if (!select) r.collapse(true);
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
    saved = r.cloneRange();
    updateState();
  }

  var fileIn = document.getElementById('wk-image-file');
  var actions = {
    link: function () {
      var a = closest('a');
      var url = window.prompt(T('Adresse des Links (https://…, mailto:… oder tel:…)'), a ? a.getAttribute('href') : 'https://');
      if (!url) return;
      url = url.trim();
      if (/^www\./i.test(url)) url = 'https://' + url;
      if (!/^(https?:\/\/|mailto:|tel:)/i.test(url)) { window.alert(T('Bitte eine Adresse mit https://, mailto: oder tel: angeben.')); return; }
      var s = window.getSelection();
      if (saved && !saved.collapsed) exec('createLink', url);
      else insertHtml('<a href="' + esc(url) + '">' + esc(url.replace(/^mailto:|^tel:/i, '')) + '</a>&nbsp;');
      if (s) saveSel();
    },
    image: function () { fileIn.value = ''; fileIn.click(); },
    table: function () {
      var spec = window.prompt(T('Tabelle: Spalten × Zeilen (z. B. 3x4)'), '3x4');
      if (!spec) return;
      var m = /^\s*(\d{1,2})\s*[x×*]\s*(\d{1,3})\s*$/i.exec(spec);
      if (!m) return;
      var cols = Math.max(1, Math.min(12, +m[1])), rows = Math.max(1, Math.min(100, +m[2]));
      var h = '<table class="tbl-grid" id="wk-new-table"><thead><tr>';
      for (var c = 0; c < cols; c++) h += '<th>' + esc(T('Spalte')) + ' ' + (c + 1) + '</th>';
      h += '</tr></thead><tbody>';
      for (var r = 1; r < rows; r++) {
        h += '<tr>';
        for (c = 0; c < cols; c++) h += '<td><br></td>';
        h += '</tr>';
      }
      insertHtml(h + '</tbody></table><p><br></p>');
      // Einfügemarke in die erste Zelle setzen
      var t = document.getElementById('wk-new-table');
      if (t) {
        t.removeAttribute('id');
        placeCaret(t.querySelector('th,td'), true);
      }
    },
    toc: function () {
      if (ed.querySelector('.wk-toc')) { window.alert(T('Die Seite hat schon ein Inhaltsverzeichnis.')); return; }
      // immer als eigener Block auf oberster Ebene, nie in Tabellen oder Infoboxen
      restoreSel();
      var top = topBlock();
      var d = document.createElement('div');
      d.className = 'wk-toc';
      d.contentEditable = 'false';
      d.textContent = T('Inhaltsverzeichnis');
      ed.insertBefore(d, top ? top.nextSibling : ed.firstChild);
      if (!d.nextSibling || d.nextSibling.nodeName !== 'P') {
        var np = document.createElement('p');
        np.innerHTML = '<br>';
        ed.insertBefore(np, d.nextSibling);
      }
      placeCaret(d.nextSibling, false);
      dirty = true;
    },
    row: function () {
      var tr = closest('tr');
      if (!tr) return;
      var n = document.createElement('tr');
      Array.prototype.forEach.call(tr.cells, function (cell) {
        var td = document.createElement('td');
        td.colSpan = cell.colSpan;
        td.innerHTML = '<br>';
        n.appendChild(td);
      });
      if (tr.parentNode.nodeName === 'THEAD') {
        var tb = tr.closest('table').tBodies[0] || tr.closest('table').createTBody();
        tb.insertBefore(n, tb.firstChild);
      } else {
        tr.parentNode.insertBefore(n, tr.nextSibling);
      }
      dirty = true;
    },
    col: function () {
      var cell = closest('td,th');
      if (!cell) return;
      var idx = cell.cellIndex;
      Array.prototype.forEach.call(cell.closest('table').rows, function (tr) {
        var ref = tr.cells[idx];
        var n = document.createElement(ref && ref.nodeName === 'TH' ? 'th' : 'td');
        n.innerHTML = '<br>';
        tr.insertBefore(n, ref ? ref.nextSibling : null);
      });
      dirty = true;
    },
    delrow: function () {
      var tr = closest('tr');
      if (!tr) return;
      var table = tr.closest('table');
      tr.parentNode.removeChild(tr);
      if (!table.rows.length) table.parentNode.removeChild(table);
      dirty = true;
    },
    delcol: function () {
      var cell = closest('td,th');
      if (!cell) return;
      var idx = cell.cellIndex;
      var table = cell.closest('table');
      Array.prototype.forEach.call(table.rows, function (tr) { if (tr.cells[idx]) tr.deleteCell(idx); });
      if (!table.rows.length || !table.rows[0].cells.length) table.parentNode.removeChild(table);
      dirty = true;
    }
  };

  // Bilder: verkleinern und als eingebettetes Bild einfügen
  function addImage(file) {
    if (!file || !/^image\/(png|jpeg|gif|webp)$/.test(file.type)) return;
    if (file.size > IMG_MAX_BYTES) { window.alert(T('Das Bild ist zu groß (höchstens 8 MB).')); return; }
    var rd = new FileReader();
    rd.onload = function () {
      var img = new Image();
      img.onload = function () {
        var src = rd.result;
        if (img.width > IMG_MAX_W || file.size > 400 * 1024) {
          var scale = Math.min(1, IMG_MAX_W / img.width);
          var cv = document.createElement('canvas');
          cv.width = Math.round(img.width * scale);
          cv.height = Math.round(img.height * scale);
          var cx = cv.getContext('2d');
          if (file.type !== 'image/png') { cx.fillStyle = '#fff'; cx.fillRect(0, 0, cv.width, cv.height); }
          cx.drawImage(img, 0, 0, cv.width, cv.height);
          src = file.type === 'image/png' && cv.width * cv.height < 800 * 800 ? cv.toDataURL('image/png') : cv.toDataURL('image/jpeg', 0.85);
        }
        insertHtml('<img src="' + src + '" alt="' + esc(file.name.replace(/\.[a-z0-9]+$/i, '')) + '">');
      };
      img.src = rd.result;
    };
    rd.readAsDataURL(file);
  }
  fileIn.addEventListener('change', function () { if (fileIn.files[0]) addImage(fileIn.files[0]); });
  ed.addEventListener('paste', function (ev) {
    var items = (ev.clipboardData && ev.clipboardData.files) || [];
    if (items.length && /^image\//.test(items[0].type)) { ev.preventDefault(); saveSel(); addImage(items[0]); }
  });
  ed.addEventListener('drop', function (ev) {
    var f = ev.dataTransfer && ev.dataTransfer.files;
    if (f && f.length && /^image\//.test(f[0].type)) {
      ev.preventDefault();
      if (document.caretRangeFromPoint) {
        var r = document.caretRangeFromPoint(ev.clientX, ev.clientY);
        if (r) saved = r;
      }
      addImage(f[0]);
    }
  });
  ed.addEventListener('keydown', function (ev) {
    // Enter im leeren letzten Absatz einer Infobox: Infobox verlassen
    if (ev.key === 'Enter' && !ev.shiftKey) {
      var box = closest('div.wk-info,div.wk-note,div.wk-warn,div.wk-ok');
      var para = closest('p');
      if (box && para && para.parentNode === box && para === box.lastElementChild && para.textContent.trim() === '' && box.children.length > 1) {
        ev.preventDefault();
        box.parentNode.insertBefore(para, box.nextSibling);
        para.innerHTML = '<br>';
        placeCaret(para, false);
        dirty = true;
        return;
      }
    }
    // Tab in Tabellen: nächste Zelle
    if (ev.key !== 'Tab') return;
    var cell = closest('td,th');
    if (!cell) return;
    ev.preventDefault();
    var cells = Array.prototype.slice.call(cell.closest('table').querySelectorAll('td,th'));
    var next = cells[cells.indexOf(cell) + (ev.shiftKey ? -1 : 1)];
    if (!next && !ev.shiftKey) { actions.row(); cells = Array.prototype.slice.call(cell.closest('table').querySelectorAll('td,th')); next = cells[cells.indexOf(cell) + 1]; }
    if (next) placeCaret(next, false);
  });
})();
