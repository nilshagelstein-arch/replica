/* Kundenportal – Editor der App „Formulare“ (ohne externe Bibliotheken) */
(function () {
  'use strict';

  var ALLOWED = { P: 1, DIV: 1, H1: 1, H2: 1, H3: 1, H4: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, S: 1, STRIKE: 1, SUB: 1, SUP: 1,
    BR: 1, HR: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1, TABLE: 1, THEAD: 1, TBODY: 1, TFOOT: 1, TR: 1, TH: 1, TD: 1, SPAN: 1, A: 1, IMG: 1 };
  var DROP = { SCRIPT: 1, STYLE: 1, META: 1, LINK: 1, TITLE: 1, HEAD: 1, IFRAME: 1, OBJECT: 1, SVG: 1, XML: 1 };
  var BLOCK_SEL = 'p,h1,h2,h3,h4,li,blockquote,td,th,div';

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }

  /** Eingefügtes HTML (z. B. aus Word) auf erlaubte Elemente reduzieren */
  function cleanHtml(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    (function walk(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (n) {
        if (n.nodeType === 8) { n.remove(); return; }
        if (n.nodeType !== 1) return;
        if (DROP[n.tagName] || /^O:/.test(n.tagName)) { n.remove(); return; }
        walk(n);
        if (!ALLOWED[n.tagName]) {
          while (n.firstChild) n.parentNode.insertBefore(n.firstChild, n);
          n.remove();
          return;
        }
        Array.prototype.slice.call(n.attributes).forEach(function (a) {
          var keep = (n.tagName === 'A' && a.name === 'href') || (n.tagName === 'IMG' && a.name === 'src' && /^data:image\//.test(a.value)) ||
            ((n.tagName === 'TD' || n.tagName === 'TH') && (a.name === 'colspan' || a.name === 'rowspan'));
          if (!keep) n.removeAttribute(a.name);
        });
        if (n.tagName === 'IMG' && !n.getAttribute('src')) n.remove();
      });
    })(doc.body);
    return doc.body.innerHTML;
  }

  function initEditor(root) {
    var area = root.querySelector('.ed-area');
    var field = root.querySelector('textarea');
    var bar = root.querySelector('.ed-toolbar');
    var imgInput = root.querySelector('[data-image-input]');
    var saved = null;
    var dirty = false;

    try { document.execCommand('styleWithCSS', false, false); } catch (e) { /* ältere Browser */ }
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* ältere Browser */ }

    /** Hinter jedem Platzhalter muss der Cursor stehen können (Chrome braucht dafür einen Textknoten) */
    function caretFix() {
      Array.prototype.forEach.call(area.querySelectorAll('span.ph'), function (ph) {
        var n = ph.nextSibling;
        if (!n || (n.nodeType === 1 && (n.tagName === 'BR' || n.classList.contains('ph')))) {
          ph.parentNode.insertBefore(document.createTextNode('\u200B'), n);
        }
        var prev = ph.previousSibling;
        if (!prev || (prev.nodeType === 1 && prev.tagName === 'BR')) ph.parentNode.insertBefore(document.createTextNode('\u200B'), ph);
      });
    }
    function sync() { field.value = area.innerHTML.replace(/\u200B/g, ''); }
    function touch() { dirty = true; caretFix(); sync(); }

    function saveSel() {
      var sel = window.getSelection();
      if (sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer)) saved = sel.getRangeAt(0).cloneRange();
    }
    function restoreSel() {
      area.focus();
      if (saved) {
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(saved);
      }
    }
    function range() {
      var sel = window.getSelection();
      return sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer) ? sel.getRangeAt(0) : null;
    }
    function closest(sel) {
      var r = range();
      if (!r) return null;
      var n = r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode;
      var el = n.closest(sel);
      return el && area.contains(el) ? el : null;
    }
    function exec(cmd, val) { restoreSel(); document.execCommand(cmd, false, val); saveSel(); touch(); }
    function insertHtml(html) { exec('insertHTML', html); }

    /** Absätze, die die Auswahl berührt (die innersten) */
    function selectedBlocks() {
      var r = range();
      if (!r) return [];
      var all = Array.prototype.filter.call(area.querySelectorAll(BLOCK_SEL), function (el) {
        return r.intersectsNode(el) && !el.classList.contains('page-break');
      });
      var leaves = all.filter(function (el) { return !all.some(function (o) { return o !== el && el.contains(o); }); });
      if (!leaves.length) {
        document.execCommand('formatBlock', false, 'p');
        var b = closest('p');
        return b ? [b] : [];
      }
      return leaves;
    }

    /** Auswahl in <span class="…"> einschließen bzw. Klassen der Gruppe entfernen */
    function styleSpan(prefix, cls) {
      restoreSel();
      var r = range();
      if (!r || r.collapsed) { alert('Bitte zuerst Text markieren.'); return; }
      var frag = r.extractContents();
      Array.prototype.forEach.call(frag.querySelectorAll('span'), function (s) {
        Array.prototype.slice.call(s.classList).forEach(function (c) { if (c.indexOf(prefix) === 0) s.classList.remove(c); });
        if (!s.className && !s.classList.contains('ph')) { while (s.firstChild) s.parentNode.insertBefore(s.firstChild, s); s.remove(); }
      });
      if (cls) {
        var span = document.createElement('span');
        span.className = cls;
        span.appendChild(frag);
        r.insertNode(span);
        r.selectNodeContents(span);
      } else {
        var last = frag.lastChild;
        r.insertNode(frag);
        if (last) r.setEndAfter(last);
      }
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(r);
      saveSel();
      touch();
    }

    function placeholder(key) {
      insertHtml('<span class="ph" contenteditable="false">{{' + esc(key) + '}}</span>&nbsp;');
    }

    // --- Tabellen ---
    function tableCmd(what) {
      restoreSel();
      if (what === 'insert') {
        var spec = window.prompt('Tabelle einfügen: Zeilen x Spalten', '3 x 3');
        if (!spec) return;
        var m = spec.match(/(\d+)\D+(\d+)/);
        var rows = Math.min(50, m ? +m[1] : 3), cols = Math.min(10, m ? +m[2] : 3);
        var html = '<table class="tbl-grid"><tbody>';
        for (var i = 0; i < rows; i++) {
          html += '<tr>';
          for (var j = 0; j < cols; j++) html += '<td><br></td>';
          html += '</tr>';
        }
        insertHtml(html + '</tbody></table><p><br></p>');
        var tables = area.querySelectorAll('table');
        var r = range(), newest = null;
        Array.prototype.forEach.call(tables, function (t) { if (r && t.compareDocumentPosition(r.startContainer) & Node.DOCUMENT_POSITION_FOLLOWING) newest = t; });
        var first = newest && newest.querySelector('td');
        if (first) {
          var nr2 = document.createRange();
          nr2.selectNodeContents(first);
          nr2.collapse(true);
          window.getSelection().removeAllRanges();
          window.getSelection().addRange(nr2);
          saveSel();
        }
        return;
      }
      var cell = closest('td,th');
      if (!cell) { alert('Bitte den Cursor in eine Tabellenzelle setzen.'); return; }
      var tr = cell.parentNode, table = cell.closest('table'), idx = Array.prototype.indexOf.call(tr.children, cell);
      if (what === 'row') {
        var nr = tr.cloneNode(true);
        Array.prototype.forEach.call(nr.children, function (c) { c.innerHTML = '<br>'; });
        tr.parentNode.insertBefore(nr, tr.nextSibling);
      } else if (what === 'col') {
        Array.prototype.forEach.call(table.querySelectorAll('tr'), function (row) {
          var ref = row.children[idx];
          var nc = document.createElement(ref ? ref.tagName : 'td');
          nc.innerHTML = '<br>';
          row.insertBefore(nc, ref ? ref.nextSibling : null);
        });
      } else if (what === 'delrow') {
        if (table.querySelectorAll('tr').length <= 1) table.remove(); else tr.remove();
      } else if (what === 'delcol') {
        if (tr.children.length <= 1) { table.remove(); } else {
          Array.prototype.forEach.call(table.querySelectorAll('tr'), function (row) { if (row.children[idx]) row.children[idx].remove(); });
        }
      } else if (what === 'border') {
        var grid = table.classList.contains('tbl-grid');
        table.classList.toggle('tbl-grid', !grid);
        table.classList.toggle('tbl-plain', grid);
      }
      touch();
    }

    // --- Werkzeugleiste ---
    bar.addEventListener('mousedown', function (ev) {
      if (ev.target.closest('button')) ev.preventDefault(); // Auswahl im Editor behalten
    });
    bar.addEventListener('click', function (ev) {
      var b = ev.target.closest('button');
      if (!b) return;
      if (b.dataset.cmd) exec(b.dataset.cmd);
      else if (b.dataset.align) {
        restoreSel();
        selectedBlocks().forEach(function (bl) {
          bl.classList.remove('al-left', 'al-center', 'al-right', 'al-justify');
          if (b.dataset.align !== 'al-left') bl.classList.add(b.dataset.align);
        });
        touch();
      } else if (b.dataset.table) tableCmd(b.dataset.table);
      else if (b.dataset.insert === 'hr') exec('insertHorizontalRule');
      else if (b.dataset.insert === 'pagebreak') insertHtml('<div class="page-break"></div><p><br></p>');
      else if (b.dataset.insert === 'image') { saveSel(); imgInput.click(); }
      else if (b.dataset.insert === 'link') {
        var url = window.prompt('Adresse des Links (https://…, mailto:… oder tel:…)', 'https://');
        if (url && /^(https?:\/\/|mailto:|tel:)/i.test(url)) exec('createLink', url);
      } else if (b.dataset.insert === 'signature') {
        insertHtml('<table class="tbl-plain sig"><tbody><tr><td><p><br></p><p class="sig">Ort, Datum</p></td><td><p><br></p><p class="sig">Unterschrift</p></td></tr></tbody></table><p><br></p>');
      } else if (b.dataset.field) {
        var names = { eingabe: 'Bezeichnung des Eingabefelds (z. B. Betreff)', text: 'Bezeichnung des Textfelds (z. B. Brieftext)', ankreuzen: 'Text neben dem Kästchen (z. B. Rückruf erwünscht)' };
        var label = window.prompt(names[b.dataset.field], '');
        if (label && label.trim()) {
          label = label.replace(/[{}<>]/g, '').trim().slice(0, 60);
          if (b.dataset.field === 'ankreuzen') insertHtml('<span class="ph" contenteditable="false">{{ankreuzen:' + esc(label) + '}}</span>&nbsp;' + esc(label) + '&nbsp;');
          else placeholder(b.dataset.field + ':' + label);
        }
      }
    });
    bar.addEventListener('change', function (ev) {
      var s = ev.target;
      if (s.matches('[data-block]')) exec('formatBlock', '<' + s.value + '>');
      else if (s.matches('[data-size]') && s.value) { styleSpan('fs-', s.value === 'normal' ? '' : s.value); s.value = ''; }
      else if (s.matches('[data-color]') && s.value) { styleSpan('c-', s.value === 'none' ? '' : s.value); s.value = ''; }
      else if (s.matches('[data-placeholder]') && s.value) { placeholder(s.value); s.value = ''; }
    });
    bar.addEventListener('focusin', saveSel);

    imgInput.addEventListener('change', function () {
      var f = imgInput.files[0];
      imgInput.value = '';
      if (!f) return;
      if (f.size > 512000) { alert('Das Bild ist zu groß (höchstens 500 KB). Bitte verkleinern oder als Logo unter Einstellungen hinterlegen.'); return; }
      var rd = new FileReader();
      rd.onload = function () { insertHtml('<img class="img-m" alt="" src="' + rd.result + '">'); };
      rd.readAsDataURL(f);
    });

    // Bildgröße per Doppelklick wechseln
    area.addEventListener('dblclick', function (ev) {
      var img = ev.target.closest('img');
      if (!img) return;
      var order = ['img-s', 'img-m', 'img-l'];
      var cur = order.findIndex(function (c) { return img.classList.contains(c); });
      img.classList.remove('img-s', 'img-m', 'img-l');
      img.classList.add(order[(cur + 1) % order.length]);
      touch();
    });

    // Einfügen: Formatierung aus Word/Web bereinigen
    area.addEventListener('paste', function (ev) {
      var cd = ev.clipboardData;
      if (!cd) return;
      ev.preventDefault();
      var html = cd.getData('text/html');
      if (html) {
        document.execCommand('insertHTML', false, cleanHtml(html));
      } else {
        var text = cd.getData('text/plain') || '';
        var parts = text.replace(/\r/g, '').split(/\n{2,}/);
        document.execCommand('insertHTML', false, parts.map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join(''));
      }
      touch();
    });

    // Tab springt in der Tabelle zur nächsten Zelle
    area.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Tab') return;
      var cell = closest('td,th');
      if (!cell) return;
      ev.preventDefault();
      var cells = Array.prototype.slice.call(cell.closest('table').querySelectorAll('td,th'));
      var next = cells[cells.indexOf(cell) + (ev.shiftKey ? -1 : 1)];
      if (!next && !ev.shiftKey) { tableCmd('row'); next = cells.length ? cell.closest('table').querySelectorAll('td,th')[cells.length] : null; }
      if (next) {
        var r = document.createRange();
        r.selectNodeContents(next);
        r.collapse(true);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(r);
      }
    });

    area.addEventListener('input', touch);
    area.addEventListener('keyup', saveSel);
    area.addEventListener('mouseup', saveSel);
    document.addEventListener('selectionchange', function () {
      var bl = closest('h1,h2,h3,p');
      var sel = bar.querySelector('[data-block]');
      if (bl && sel) sel.value = bl.tagName.toLowerCase();
    });

    var form = root.closest('form');
    if (form) {
      form.addEventListener('submit', function () { sync(); dirty = false; });
    }
    window.addEventListener('beforeunload', function (ev) {
      if (dirty) { ev.preventDefault(); ev.returnValue = ''; }
    });
    caretFix();
    sync();
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-editor]'), initEditor);
})();
