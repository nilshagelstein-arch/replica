/* Kundenportal – Formular-Editor im Stil von Word, Rechentabellen wie in Excel (ohne externe Bibliotheken) */
(function () {
  'use strict';

  var root = document.querySelector('[data-word]');
  if (!root) return;
  var C = window.KPCalc;
  var cfg = JSON.parse(root.querySelector('[data-word-config]').textContent);
  var form = document.getElementById('word-form') || root.closest('form');
  var page = root.querySelector('[data-page]');
  var ruler = root.querySelector('[data-ruler]');
  var heads = root.querySelector('[data-cell-heads]');
  var fbName = root.querySelector('[data-fb-name]');
  var fbInput = root.querySelector('[data-fb-input]');
  var imgInput = root.querySelector('[data-image-input]');
  var regions = {};
  Array.prototype.forEach.call(root.querySelectorAll('[data-region]'), function (r) { regions[r.getAttribute('data-region')] = r; });
  var active = regions.body;
  var saved = null, dirty = false, activeCell = null, pendingSize = null, zoom = 100;
  var pageSet = cfg.page || { m: [20, 20, 15, 25], font: 'Arial', size: 11, ls: 1.4 };
  var colors = { fore: '#c00000', back: '#ffff00' };
  var MM = 96 / 25.4;

  function $$(sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function regionOf(node) {
    var el = node && (node.nodeType === 1 ? node : node.parentNode);
    var r = el && el.closest ? el.closest('[data-region]') : null;
    return r && root.contains(r) && r.isContentEditable ? r : null;
  }

  // ------------------------------------------------------------ Auswahl
  function selRange() {
    var s = window.getSelection();
    if (!s.rangeCount) return null;
    var r = s.getRangeAt(0);
    return regionOf(r.commonAncestorContainer) ? r : null;
  }
  function saveSel() {
    var r = selRange();
    if (r) { saved = r.cloneRange(); active = regionOf(r.commonAncestorContainer); }
  }
  function restore() {
    if (!active) return;
    active.focus({ preventScroll: true });
    if (saved && active.contains(saved.commonAncestorContainer)) {
      var s = window.getSelection();
      s.removeAllRanges();
      s.addRange(saved);
    }
  }
  function placeCaret(el, atEnd) {
    var r = document.createRange();
    r.selectNodeContents(el);
    r.collapse(!atEnd);
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
    saveSel();
  }
  function exec(cmd, val, css) {
    restore();
    try { document.execCommand('styleWithCSS', false, !!css); } catch (e) { /* alt */ }
    document.execCommand(cmd, false, val);
    saveSel();
    touch();
  }
  function insertHtml(html) { exec('insertHTML', html); }

  // ------------------------------------------------------------ Änderungen
  var stateEl = document.querySelector('[data-save-state]');
  function touch() {
    dirty = true;
    if (stateEl) stateEl.textContent = 'Nicht gespeichert';
    caretFix();
    if (active && active !== regions.body && form && form.elements[active.getAttribute('data-region') + '_dirty']) {
      form.elements[active.getAttribute('data-region') + '_dirty'].value = '1';
    }
    $$('table', page).forEach(function (t) { if (C.hasFormulas(t)) C.recalc(t); });
    status();
  }

  /** Hinter jedem Platzhalter muss der Cursor stehen können (Chrome braucht einen Textknoten) */
  function caretFix() {
    $$('span.ph', page).forEach(function (ph) {
      var n = ph.nextSibling;
      if (!n || (n.nodeType === 1 && (n.tagName === 'BR' || n.classList.contains('ph')))) ph.parentNode.insertBefore(document.createTextNode('​'), n);
      var p = ph.previousSibling;
      if (!p || (p.nodeType === 1 && p.tagName === 'BR')) ph.parentNode.insertBefore(document.createTextNode('​'), ph);
    });
    $$('[data-formula]', page).forEach(function (td) { td.setAttribute('contenteditable', 'false'); });
  }

  // ------------------------------------------------------------ Absätze
  var BLOCKS = 'p,h1,h2,h3,h4,li,blockquote,td,th,div:not(.page-break)';
  function blocks() {
    var r = selRange() || saved;
    if (!r || !active) return [];
    var all = $$(BLOCKS, active).filter(function (el) { return r.intersectsNode(el); });
    var leaves = all.filter(function (el) { return !all.some(function (o) { return o !== el && el.contains(o); }); });
    if (!leaves.length) {
      document.execCommand('formatBlock', false, 'p');
      var n = (selRange() || r).startContainer;
      var b = (n.nodeType === 1 ? n : n.parentNode).closest('p');
      return b ? [b] : [];
    }
    return leaves;
  }
  function mmOf(v) {
    if (!v) return 0;
    var n = parseFloat(v);
    if (/mm$/.test(v)) return n;
    if (/cm$/.test(v)) return n * 10;
    if (/pt$/.test(v)) return n * 0.3528;
    if (/px$/.test(v)) return n / MM;
    return 0;
  }
  function align(v) {
    restore();
    blocks().forEach(function (b) {
      b.classList.remove('al-left', 'al-center', 'al-right', 'al-justify');
      b.style.textAlign = v === 'left' ? '' : v;
    });
    touch();
  }
  function indent(d) {
    restore();
    var r = selRange();
    if (r && (r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode).closest('li')) { exec(d > 0 ? 'indent' : 'outdent'); return; }
    blocks().forEach(function (b) {
      var m = Math.max(0, mmOf(b.style.marginLeft) + d * 10);
      b.style.marginLeft = m ? m + 'mm' : '';
    });
    touch();
  }
  function blockStyle(prop, val) { restore(); blocks().forEach(function (b) { b.style[prop] = val; }); touch(); }
  function paragraphStyle(name) {
    restore();
    var tag = { p: 'p', title: 'h1', h1: 'h1', h2: 'h2', h3: 'h3', quote: 'blockquote', small: 'p' }[name] || 'p';
    document.execCommand('formatBlock', false, tag);
    blocks().forEach(function (b) {
      b.style.fontSize = name === 'title' ? '22pt' : name === 'small' ? '8.5pt' : '';
      b.style.color = name === 'small' ? '#666666' : '';
      b.classList.remove('fs-small', 'fs-large', 'fs-xlarge');
    });
    saveSel();
    touch();
  }

  // ------------------------------------------------------------ Schrift
  function applySize(pt) {
    pt = Math.max(5, Math.min(96, Math.round(pt * 2) / 2));
    restore();
    var r = selRange();
    if (!r) return;
    try { document.execCommand('styleWithCSS', false, false); } catch (e) { /* alt */ }
    document.execCommand('fontSize', false, '7');
    pendingSize = r.collapsed ? pt : null;
    convertFonts(pt);
    saveSel();
    touch();
  }
  function convertFonts(pt) {
    $$('font[size="7"]', page).forEach(function (f) {
      var sp = document.createElement('span');
      sp.style.fontSize = pt + 'pt';
      while (f.firstChild) sp.appendChild(f.firstChild);
      f.parentNode.replaceChild(sp, f);
      $$('[style]', sp).forEach(function (x) { x.style.fontSize = ''; });
    });
    $$('span[style*="xxx-large"]', page).forEach(function (x) { x.style.fontSize = pt + 'pt'; });
  }
  function currentPt() {
    var r = selRange() || saved;
    if (!r) return pageSet.size;
    var el = r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode;
    return Math.round(parseFloat(getComputedStyle(el).fontSize) * 0.75 * 2) / 2;
  }

  // ------------------------------------------------------------ Einfügen
  function placeholder(key, field) {
    insertHtml('<span class="ph' + (field ? ' ph-f' : '') + '" contenteditable="false">{{' + esc(key) + '}}</span>&nbsp;');
  }
  function insertField(type) {
    var q = { eingabe: 'Bezeichnung des Eingabefelds (z. B. Betreff)', text: 'Bezeichnung des Textfelds (z. B. Brieftext)', zahl: 'Bezeichnung des Zahlenfelds (z. B. Anzahl)',
      datum: 'Bezeichnung des Datumsfelds (z. B. Liefertermin)', ankreuzen: 'Text neben dem Kästchen (z. B. Rückruf erwünscht)', auswahl: 'Bezeichnung der Auswahlliste (z. B. Zahlungsart)' }[type];
    var label = window.prompt(q, '');
    if (!label || !label.trim()) return;
    label = label.replace(/[{}<>|]/g, '').trim().slice(0, 60);
    var key = type + ':' + label;
    if (type === 'auswahl') {
      var opts = window.prompt('Antwortmöglichkeiten, getrennt durch Komma', 'Rechnung, Lastschrift, Barzahlung');
      if (!opts) return;
      key += '|' + opts.split(',').map(function (o) { return o.replace(/[{}<>|]/g, '').trim(); }).filter(Boolean).join('|');
    }
    if (type === 'ankreuzen') insertHtml('<span class="ph ph-f" contenteditable="false">{{' + esc(key) + '}}</span>&nbsp;' + esc(label) + '&nbsp;');
    else placeholder(key, true);
  }

  function tableHtml(rows, cols) {
    var w = (100 / cols).toFixed(2), h = '<table class="tbl-grid"><tbody>';
    for (var i = 0; i < rows; i++) {
      h += '<tr>';
      for (var j = 0; j < cols; j++) h += '<td' + (i === 0 ? ' style="width: ' + w + '%"' : '') + '><br></td>';
      h += '</tr>';
    }
    return h + '</tbody></table><p><br></p>';
  }
  function calcPreset(kind) {
    var th = function (t, w, al) { return '<th style="width: ' + w + '%; background-color: #f2f2f2' + (al ? '; text-align: ' + al : '') + '">' + t + '</th>'; };
    var h;
    if (kind === 'positions') {
      h = '<table class="tbl-grid" data-grow="1"><tbody><tr>' + th('Pos.', 8) + th('Leistung', 44) + th('Menge', 12, 'right') + th('Einzelpreis', 18, 'right') + th('Gesamt', 18, 'right') + '</tr>';
      for (var r = 2; r <= 4; r++) {
        h += '<tr><td>' + (r - 1) + '</td><td data-input="1"><br></td><td data-input="1" data-format="ganz" style="text-align: right"><br></td>'
          + '<td data-input="1" data-format="euro" style="text-align: right"><br></td><td data-formula="=C' + r + '*D' + r + '" data-format="euro" style="text-align: right"></td></tr>';
      }
      var tot = function (label, f, bold) {
        return '<tr><td colspan="3" style="border: none"><br></td><td style="text-align: right' + (bold ? '; font-weight: bold' : '') + '">' + label + '</td>'
          + '<td data-formula="' + f + '" data-format="euro" style="text-align: right' + (bold ? '; font-weight: bold' : '') + '"></td></tr>';
      };
      h += tot('Netto', '=SUMME(E2:E4)') + tot('MwSt. 19 %', '=RUNDEN(E5*0,19;2)') + tot('Brutto', '=E5+E6', true) + '</tbody></table><p><br></p>';
    } else if (kind === 'hours') {
      h = '<table class="tbl-grid" data-grow="1"><tbody><tr>' + th('Datum', 20) + th('Tätigkeit', 60) + th('Stunden', 20, 'right') + '</tr>';
      for (var k = 2; k <= 6; k++) h += '<tr><td data-input="1"><br></td><td data-input="1"><br></td><td data-input="1" data-format="zahl" style="text-align: right"><br></td></tr>';
      h += '<tr><td colspan="2" style="text-align: right; font-weight: bold">Summe Stunden</td><td data-formula="=SUMME(C2:C6)" data-format="zahl" style="text-align: right; font-weight: bold"></td></tr></tbody></table><p><br></p>';
    } else {
      h = tableHtml(4, 5);
    }
    insertHtml(h);
    var t = lastInsertedTable();
    if (t) { C.recalc(t); var first = t.querySelector('td[data-input], td'); if (first) { placeCaret(first); setActiveCell(first); } }
    touch();
  }
  function lastInsertedTable() {
    var r = selRange();
    var list = $$('table', active);
    if (!r) return list[list.length - 1];
    var found = null;
    list.forEach(function (t) { if (t.compareDocumentPosition(r.startContainer) & Node.DOCUMENT_POSITION_FOLLOWING) found = t; });
    return found;
  }

  // ------------------------------------------------------------ Tabellen
  function cellOf(node) {
    var el = node && (node.nodeType === 1 ? node : node.parentNode);
    var td = el && el.closest ? el.closest('td,th') : null;
    return td && page.contains(td) ? td : null;
  }
  function caretCell() { var r = selRange() || saved; return r ? cellOf(r.startContainer) : null; }
  function curCell() { return activeCell && page.contains(activeCell) ? activeCell : caretCell(); }
  function selectedCells() {
    var s = $$('.cell-sel', page);
    if (s.length) return s;
    var c = curCell();
    return c ? [c] : [];
  }
  function clearCellSel() { $$('.cell-sel', page).forEach(function (c) { c.classList.remove('cell-sel'); }); }
  function rectOf(cells, g) {
    var r1 = 1e9, c1 = 1e9, r2 = -1, c2 = -1;
    cells.forEach(function (td) {
      var p = g.pos.get(td);
      if (!p) return;
      r1 = Math.min(r1, p.r); c1 = Math.min(c1, p.c); r2 = Math.max(r2, p.r + p.rs - 1); c2 = Math.max(c2, p.c + p.cs - 1);
    });
    return { r1: r1, c1: c1, r2: r2, c2: c2 };
  }
  function selectRect(table, a, b) {
    var g = C.grid(table), pa = g.pos.get(a), pb = g.pos.get(b);
    if (!pa || !pb) return;
    var rc = { r1: Math.min(pa.r, pb.r), c1: Math.min(pa.c, pb.c), r2: Math.max(pa.r + pa.rs - 1, pb.r + pb.rs - 1), c2: Math.max(pa.c + pa.cs - 1, pb.c + pb.cs - 1) };
    // auf verbundene Zellen erweitern
    for (var grew = true; grew;) {
      grew = false;
      for (var r = rc.r1; r <= rc.r2; r++) for (var c = rc.c1; c <= rc.c2; c++) {
        var td = g.m[r] && g.m[r][c], p = td && g.pos.get(td);
        if (!p) continue;
        if (p.r < rc.r1) { rc.r1 = p.r; grew = true; }
        if (p.c < rc.c1) { rc.c1 = p.c; grew = true; }
        if (p.r + p.rs - 1 > rc.r2) { rc.r2 = p.r + p.rs - 1; grew = true; }
        if (p.c + p.cs - 1 > rc.c2) { rc.c2 = p.c + p.cs - 1; grew = true; }
      }
    }
    clearCellSel();
    for (var rr = rc.r1; rr <= rc.r2; rr++) for (var cc = rc.c1; cc <= rc.c2; cc++) { var t = g.m[rr] && g.m[rr][cc]; if (t) t.classList.add('cell-sel'); }
  }
  function newCell(likeTd) {
    var td = document.createElement(likeTd && likeTd.tagName === 'TH' ? 'th' : 'td');
    td.innerHTML = '<br>';
    return td;
  }
  /** Zelle in Zeile tr an Rasterspalte c einfügen (vor der ersten Zelle, die rechts davon beginnt) */
  function insertCellAt(tr, g, c, td) {
    var ref = null;
    Array.prototype.some.call(tr.cells, function (x) { var p = g.pos.get(x); if (p && p.c >= c) { ref = x; return true; } return false; });
    tr.insertBefore(td, ref);
  }

  function tableCmd(cmd) {
    restore();
    var cells = selectedCells();
    var td = cells[0];
    if (!td) { alert('Bitte den Cursor in eine Tabellenzelle setzen.'); return; }
    var table = td.closest('table'), g = C.grid(table), rc = rectOf(cells, g);
    var tr, r, c;
    switch (cmd) {
      case 'row-above':
        tr = g.trs[rc.r1];
        var nr = tr.cloneNode(true);
        Array.prototype.forEach.call(nr.cells, function (x) { x.rowSpan = 1; x.removeAttribute('data-formula'); x.removeAttribute('contenteditable'); x.innerHTML = '<br>'; });
        C.shiftTable(table, 0, rc.r1, 1);
        tr.parentNode.insertBefore(nr, tr);
        break;
      case 'row-below':
        tr = g.trs[rc.r2];
        C.insertRowCopy(table, tr, false);
        break;
      case 'col-left': case 'col-right':
        c = cmd === 'col-left' ? rc.c1 : rc.c2 + 1;
        var widened = new Set();
        g.trs.forEach(function (row, ri) {
          var at = g.m[ri] && g.m[ri][c], p = at && g.pos.get(at);
          if (p && p.c < c && p.c + p.cs > c) { if (!widened.has(at)) { at.colSpan = p.cs + 1; widened.add(at); } return; }
          if (p && p.r < ri) return; // von oben überdeckt
          var like = g.m[ri] && (g.m[ri][c] || g.m[ri][c - 1]);
          insertCellAt(row, g, c, newCell(like));
        });
        C.shiftTable(table, 1, c, 1);
        break;
      case 'del-row':
        for (r = rc.r2; r >= rc.r1; r--) {
          g = C.grid(table);
          g.m[r].forEach(function (x) { var p = g.pos.get(x); if (p.r < r && x.rowSpan > 1) x.rowSpan -= 1; });
          g.trs[r].remove();
          C.shiftTable(table, 0, r, -1);
        }
        if (!table.rows.length) table.remove();
        clearCellSel();
        break;
      case 'del-col':
        for (c = rc.c2; c >= rc.c1; c--) {
          g = C.grid(table);
          var seen = new Set();
          g.m.forEach(function (row) {
            var x = row[c];
            if (!x || seen.has(x)) return;
            seen.add(x);
            if (x.colSpan > 1) x.colSpan -= 1; else x.remove();
          });
          C.shiftTable(table, 1, c, -1);
        }
        if (!table.querySelector('td,th')) table.remove();
        clearCellSel();
        break;
      case 'del-table':
        table.remove();
        activeCell = null;
        break;
      case 'merge':
        if (cells.length < 2) { alert('Bitte zuerst mehrere Zellen markieren (mit gedrückter Maustaste über die Zellen ziehen).'); return; }
        var tl = g.m[rc.r1][rc.c1], parts = [];
        cells.forEach(function (x) { if (x !== tl && x.textContent.replace(/​/g, '').trim() !== '') parts.push(x.innerHTML); });
        for (r = rc.r1; r <= rc.r2; r++) for (c = rc.c1; c <= rc.c2; c++) { var x = g.m[r][c]; if (x !== tl && x.parentNode) x.remove(); }
        tl.colSpan = rc.c2 - rc.c1 + 1;
        tl.rowSpan = rc.r2 - rc.r1 + 1;
        if (parts.length) tl.innerHTML += '<br>' + parts.join('<br>');
        clearCellSel();
        placeCaret(tl, true);
        break;
      case 'split':
        cells.forEach(function (x) {
          var p = g.pos.get(x);
          if (!p || (p.rs === 1 && p.cs === 1)) return;
          x.colSpan = 1; x.rowSpan = 1;
          for (r = p.r; r < p.r + p.rs; r++) {
            var row = g.trs[r], ref = null;
            Array.prototype.some.call(row.cells, function (y) { var q = g.pos.get(y); if (y !== x && q && q.c > p.c) { ref = y; return true; } return false; });
            for (c = p.c; c < p.c + p.cs; c++) {
              if (r === p.r && c === p.c) continue;
              row.insertBefore(newCell(x), ref);
            }
          }
        });
        break;
      case 'b-all': cells.forEach(function (x) { x.style.border = '1px solid #555555'; }); break;
      case 'b-none':
        cells.forEach(function (x) { x.style.border = 'none'; });
        if (cells.length === $$('td,th', table).length) { table.classList.remove('tbl-grid'); table.classList.add('tbl-plain'); cells.forEach(function (x) { x.style.border = ''; }); }
        break;
      case 'b-outer': case 'b-top': case 'b-bottom':
        cells.forEach(function (x) {
          var p = g.pos.get(x), line = '1px solid #555555';
          if (cmd !== 'b-bottom' && p.r === rc.r1) x.style.borderTop = line;
          if (cmd !== 'b-top' && p.r + p.rs - 1 === rc.r2) x.style.borderBottom = line;
          if (cmd === 'b-outer' && p.c === rc.c1) x.style.borderLeft = line;
          if (cmd === 'b-outer' && p.c + p.cs - 1 === rc.c2) x.style.borderRight = line;
        });
        break;
      case 'v-top': case 'v-middle': case 'v-bottom': cells.forEach(function (x) { x.style.verticalAlign = cmd.slice(2); }); break;
      case 'h-left': case 'h-center': case 'h-right': cells.forEach(function (x) { x.style.textAlign = cmd === 'h-left' ? '' : cmd.slice(2); }); break;
      case 'autosum': autoSum(td, table); break;
      case 'formula':
        setActiveCell(td);
        fbInput.focus();
        if (!fbInput.value) fbInput.value = '=';
        return;
      case 'input':
        var on = !td.hasAttribute('data-input');
        cells.forEach(function (x) { if (x.hasAttribute('data-formula')) return; if (on) x.setAttribute('data-input', '1'); else x.removeAttribute('data-input'); });
        break;
      case 'grow':
        if (table.hasAttribute('data-grow')) table.removeAttribute('data-grow'); else table.setAttribute('data-grow', '1');
        break;
      case 'equal':
        var n = g.cols;
        g.trs.forEach(function (row) { Array.prototype.forEach.call(row.cells, function (x) { x.style.width = x.colSpan === 1 ? (100 / n).toFixed(2) + '%' : ''; }); });
        break;
    }
    touch();
    updateRibbon();
  }

  function autoSum(td, table) {
    var g = C.grid(table), p = g.pos.get(td), r1 = null;
    var isNum = function (x) {
      if (!x) return false;
      if (x.hasAttribute('data-formula')) return true;
      var t = x.textContent.replace(/​/g, '').trim(), n = C.parseNumber(t);
      return t === '' ? x.hasAttribute('data-input') : (n !== null && !isNaN(n));
    };
    for (var r = p.r - 1; r >= 0; r--) { var x = g.m[r][p.c]; if (isNum(x)) r1 = r; else if (r1 !== null || x.tagName === 'TH') break; }
    var f;
    if (r1 !== null) f = '=SUMME(' + C.addr(r1, p.c) + ':' + C.addr(p.r - 1, p.c) + ')';
    else {
      var c1 = null;
      for (var c = p.c - 1; c >= 0; c--) { var y = g.m[p.r][c]; if (isNum(y)) c1 = c; else if (c1 !== null) break; }
      if (c1 === null) { setActiveCell(td); fbInput.value = '=SUMME()'; fbInput.focus(); return; }
      f = '=SUMME(' + C.addr(p.r, c1) + ':' + C.addr(p.r, p.c - 1) + ')';
    }
    setFormula(td, f);
  }

  function setFormula(td, f) {
    td.setAttribute('data-formula', f.trim());
    td.setAttribute('contenteditable', 'false');
    td.removeAttribute('data-input');
    C.recalc(td.closest('table'));
    if (td === activeCell) showCell(td);
    touch();
  }
  function setValue(td, text) {
    td.removeAttribute('data-formula');
    td.removeAttribute('contenteditable');
    td.classList.remove('calc-error');
    var f = td.getAttribute('data-format'), n = C.parseNumber(text);
    td.textContent = f && n !== null && !isNaN(n) ? C.format(n, f) : text;
    if (!td.textContent) td.innerHTML = '<br>';
    touch();
  }

  // ------------------------------------------------------------ Formelleiste
  function showCell(td) {
    var table = td.closest('table');
    if (!table) return;
    var g = C.grid(table), p = g.pos.get(td);
    fbName.textContent = p ? C.addr(p.r, p.c) : '–';
    fbInput.disabled = false;
    if (document.activeElement !== fbInput) fbInput.value = td.getAttribute('data-formula') || td.textContent.replace(/​/g, '');
    showHeads(table, g, p);
  }
  function setActiveCell(td) {
    if (activeCell && activeCell !== td && page.contains(activeCell)) {
      // „=…“ direkt in die Zelle getippt: in Formel umwandeln
      var t = activeCell.textContent.replace(/​/g, '').trim();
      if (!activeCell.hasAttribute('data-formula') && /^=./.test(t)) setFormula(activeCell, t);
      else if (activeCell.hasAttribute('data-format') && !activeCell.hasAttribute('data-formula')) {
        var n = C.parseNumber(t);
        if (t !== '' && n !== null && !isNaN(n)) { var ft = C.format(n, activeCell.getAttribute('data-format')); if (ft !== t) { activeCell.textContent = ft; touch(); } }
      }
    }
    activeCell = td;
    if (td) showCell(td);
    else { fbName.textContent = '–'; fbInput.value = ''; fbInput.disabled = true; heads.hidden = true; }
    updateRibbon();
  }
  fbInput.addEventListener('keydown', function (ev) {
    if (!activeCell) return;
    if (ev.key === 'Enter' || ev.key === 'Tab') {
      ev.preventDefault();
      var v = fbInput.value.trim();
      if (/^=./.test(v)) setFormula(activeCell, v); else setValue(activeCell, fbInput.value);
      var table = activeCell.closest('table'), g = C.grid(table), p = g.pos.get(activeCell);
      var next = ev.key === 'Tab' ? (g.m[p.r] && g.m[p.r][p.c + p.cs]) : (g.m[p.r + p.rs] && g.m[p.r + p.rs][p.c]);
      fbInput.blur();
      if (next) { setActiveCell(next); if (!next.hasAttribute('data-formula')) placeCaret(next, true); }
      else showCell(activeCell);
    } else if (ev.key === 'Escape') {
      fbInput.value = activeCell.getAttribute('data-formula') || activeCell.textContent;
      fbInput.blur();
    }
  });

  /** Spalten- und Zeilenköpfe (A, B, C … / 1, 2, 3 …) wie in Excel */
  function showHeads(table, g, p) {
    var pr = page.getBoundingClientRect(), ratio = pr.width / page.offsetWidth || 1;
    var tb = table.getBoundingClientRect(), html = '';
    for (var c = 0; c < g.cols; c++) {
      for (var r = 0; r < g.rows; r++) {
        var x = g.m[r] && g.m[r][c], q = x && g.pos.get(x);
        if (q && q.cs === 1) {
          var b = x.getBoundingClientRect();
          html += '<span class="ch-col' + (p && c >= p.c && c < p.c + p.cs ? ' on' : '') + '" style="left:' + ((b.left - pr.left) / ratio) + 'px;top:' + ((tb.top - pr.top) / ratio - 17) + 'px;width:' + (b.width / ratio) + 'px">' + C.colName(c) + '</span>';
          break;
        }
      }
    }
    g.trs.forEach(function (tr, r) {
      var b = tr.getBoundingClientRect();
      html += '<span class="ch-row' + (p && r >= p.r && r < p.r + p.rs ? ' on' : '') + '" style="top:' + ((b.top - pr.top) / ratio) + 'px;height:' + (b.height / ratio) + 'px;left:' + ((tb.left - pr.left) / ratio - 21) + 'px">' + (r + 1) + '</span>';
    });
    heads.innerHTML = html;
    heads.hidden = false;
  }

  // ------------------------------------------------------------ Maus in Tabellen: markieren, Spaltenbreite
  var drag = null, cellAnchor = null;
  page.addEventListener('mousemove', function (ev) {
    if (drag) return;
    var td = cellOf(ev.target);
    var edge = false;
    if (td && regionOf(td)) {
      var b = td.getBoundingClientRect();
      edge = ev.clientX > b.right - 5 && ev.clientX < b.right + 3;
    }
    page.classList.toggle('col-resize', edge && !ev.buttons);
    if (cellAnchor && ev.buttons === 1 && td && td !== cellAnchor && td.closest('table') === cellAnchor.closest('table')) {
      selectRect(td.closest('table'), cellAnchor, td);
      page.classList.add('selecting-cells');
    }
  });
  page.addEventListener('mousedown', function (ev) {
    var td = cellOf(ev.target);
    if (!td || !regionOf(td)) { cellAnchor = null; clearCellSel(); return; }
    if (page.classList.contains('col-resize')) { startResize(ev, td); return; }
    if (ev.shiftKey && activeCell && activeCell.closest('table') === td.closest('table')) {
      ev.preventDefault();
      selectRect(td.closest('table'), activeCell, td);
      return;
    }
    clearCellSel();
    cellAnchor = td;
    setActiveCell(td);
  });
  document.addEventListener('mouseup', function () {
    if (page.classList.contains('selecting-cells')) {
      page.classList.remove('selecting-cells');
      if (cellAnchor && !cellAnchor.hasAttribute('data-formula')) placeCaret(cellAnchor, true);
      updateRibbon();
    }
    cellAnchor = null;
  });
  page.addEventListener('dblclick', function (ev) {
    var td = cellOf(ev.target);
    if (td && td.hasAttribute('data-formula')) { setActiveCell(td); fbInput.focus(); fbInput.select(); return; }
    var img = ev.target.closest('img');
    if (img && regionOf(img)) {
      var order = ['img-s', 'img-m', 'img-l'], cur = order.findIndex(function (c) { return img.classList.contains(c); });
      img.classList.remove('img-s', 'img-m', 'img-l');
      img.classList.add(order[(cur + 1) % order.length]);
      touch();
    }
  });

  function startResize(ev, td) {
    ev.preventDefault();
    var table = td.closest('table'), g = C.grid(table), p = g.pos.get(td), c = p.c + p.cs - 1;
    if (c + 1 >= g.cols) return;
    var pr = page.getBoundingClientRect(), ratio = pr.width / page.offsetWidth || 1, total = table.getBoundingClientRect().width / ratio;
    var widths = [];
    for (var k = 0; k < g.cols; k++) {
      for (var r = 0; r < g.rows; r++) { var x = g.m[r][k]; if (x && g.pos.get(x).cs === 1) { widths[k] = x.getBoundingClientRect().width / ratio; break; } }
      if (!widths[k]) widths[k] = total / g.cols;
    }
    drag = { table: table, g: g, c: c, x: ev.clientX, w: widths.slice(), total: total, ratio: ratio };
    document.body.classList.add('dragging-col');
  }
  document.addEventListener('mousemove', function (ev) {
    if (!drag) return;
    var dx = (ev.clientX - drag.x) / drag.ratio, w = drag.w.slice();
    var a = w[drag.c] + dx, b = w[drag.c + 1] - dx;
    if (a < 12 || b < 12) return;
    w[drag.c] = a; w[drag.c + 1] = b;
    drag.g.trs.forEach(function (tr) {
      Array.prototype.forEach.call(tr.cells, function (x) {
        var q = drag.g.pos.get(x);
        x.style.width = q.cs === 1 ? (w[q.c] / drag.total * 100).toFixed(2) + '%' : '';
      });
    });
  });
  document.addEventListener('mouseup', function () {
    if (!drag) return;
    drag = null;
    document.body.classList.remove('dragging-col');
    page.classList.remove('col-resize');
    touch();
  });

  // ------------------------------------------------------------ Tastatur
  function allCells(table) {
    var g = C.grid(table), cells = [];
    g.m.forEach(function (row) { row.forEach(function (x) { if (cells.indexOf(x) === -1) cells.push(x); }); });
    return cells;
  }
  root.addEventListener('keydown', function (ev) {
    var mod = ev.ctrlKey || ev.metaKey;
    if (mod && ev.key.toLowerCase() === 's') { ev.preventDefault(); submit(); return; }
    if (mod && !ev.shiftKey && !ev.altKey && regionOf(ev.target)) {
      var k = ev.key.toLowerCase();
      if (k === 'l' || k === 'e' || k === 'r' || k === 'j') { ev.preventDefault(); saveSel(); align({ l: 'left', e: 'center', r: 'right', j: 'justify' }[k]); return; }
    }
    if (ev.key === 'Enter' && ev.target.matches('[data-size],[data-page-size]')) { ev.preventDefault(); ev.target.dispatchEvent(new Event('change', { bubbles: true })); return; }
    if (ev.key === 'Tab' && regionOf(ev.target)) {
      var td = activeCell && page.contains(activeCell) ? activeCell : caretCell();
      ev.preventDefault();
      if (!td) { insertHtml('&emsp;'); return; }
      var table = td.closest('table'), cells = allCells(table);
      var i = cells.indexOf(td) + (ev.shiftKey ? -1 : 1);
      if (i >= cells.length && !ev.shiftKey) { var g = C.grid(table); C.insertRowCopy(table, g.trs[g.rows - 1], false); touch(); cells = allCells(table); }
      var next = cells[i];
      if (next) { setActiveCell(next); if (!next.hasAttribute('data-formula')) placeCaret(next, true); }
    }
  });
  document.addEventListener('keydown', function (ev) {
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's' && !root.contains(ev.target)) { ev.preventDefault(); submit(); }
  });

  // ------------------------------------------------------------ Einfügen aus Word/Excel/Web
  var ALLOWED = { P: 1, DIV: 1, H1: 1, H2: 1, H3: 1, H4: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, S: 1, SUB: 1, SUP: 1, BR: 1, HR: 1, UL: 1, OL: 1, LI: 1,
    BLOCKQUOTE: 1, TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, TH: 1, TD: 1, SPAN: 1, A: 1, IMG: 1 };
  function cleanHtml(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    (function walk(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (n) {
        if (n.nodeType === 8) { n.remove(); return; }
        if (n.nodeType !== 1) return;
        if (/^(SCRIPT|STYLE|META|LINK|TITLE|IFRAME|OBJECT|SVG)$/.test(n.tagName) || /:/.test(n.tagName)) { n.remove(); return; }
        walk(n);
        if (!ALLOWED[n.tagName]) { while (n.firstChild) n.parentNode.insertBefore(n.firstChild, n); n.remove(); return; }
        var keepStyle = '';
        if (n.style) ['font-weight', 'font-style', 'text-decoration', 'text-align'].forEach(function (pr) { var v = n.style.getPropertyValue(pr); if (v) keepStyle += pr + ':' + v + ';'; });
        Array.prototype.slice.call(n.attributes).forEach(function (a) {
          var keep = (n.tagName === 'A' && a.name === 'href') || (n.tagName === 'IMG' && a.name === 'src' && /^data:image\//.test(a.value)) || /^(colspan|rowspan)$/.test(a.name);
          if (!keep) n.removeAttribute(a.name);
        });
        if (keepStyle) n.setAttribute('style', keepStyle);
        if (n.tagName === 'TABLE') n.className = 'tbl-grid';
      });
    })(doc.body);
    return doc.body.innerHTML;
  }
  page.addEventListener('paste', function (ev) {
    var cd = ev.clipboardData;
    if (!cd || !regionOf(ev.target)) return;
    ev.preventDefault();
    var text = cd.getData('text/plain') || '';
    var td = caretCell();
    // aus Excel kopierte Zellen (Tabulator/Zeilen) direkt in die Tabelle übernehmen
    if (td && /\t|\n./.test(text.replace(/\r/g, '').replace(/\n$/, ''))) { pasteGrid(td, text.replace(/\r/g, '')); return; }
    var html = cd.getData('text/html');
    if (html) document.execCommand('insertHTML', false, cleanHtml(html));
    else document.execCommand('insertHTML', false, text.replace(/\r/g, '').split(/\n{2,}/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join(''));
    touch();
  });
  function pasteGrid(td, text) {
    var table = td.closest('table'), rowsIn = text.replace(/\n$/, '').split('\n').map(function (l) { return l.split('\t'); });
    var p = C.grid(table).pos.get(td);
    rowsIn.forEach(function (vals, i) {
      var g = C.grid(table);
      while (p.r + i >= g.rows) { C.insertRowCopy(table, g.trs[g.rows - 1], false); g = C.grid(table); }
      vals.forEach(function (v, j) {
        var x = g.m[p.r + i] && g.m[p.r + i][p.c + j];
        if (!x) return;
        if (/^=./.test(v.trim())) setFormula(x, v.trim()); else if (!x.hasAttribute('data-formula')) x.textContent = v;
      });
    });
    touch();
  }

  // ------------------------------------------------------------ Menüband
  $$('.rb-tab').forEach(function (t) {
    t.addEventListener('click', function () { showTab(t.getAttribute('data-tab')); t.classList.remove('pulse'); });
  });
  function showTab(name) {
    $$('.rb-tab').forEach(function (x) { x.classList.toggle('active', x.getAttribute('data-tab') === name); });
    $$('.rb-panel').forEach(function (x) { x.classList.toggle('active', x.getAttribute('data-panel') === name); });
  }
  // Fokus im Text behalten, wenn Knöpfe gedrückt werden
  root.querySelector('.ribbon').addEventListener('mousedown', function (ev) {
    if (ev.target.closest('button')) ev.preventDefault();
  });

  root.addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b || !root.contains(b) || b.hasAttribute('data-pop') || b.closest('[data-table-grid]')) return;
    var d = b.dataset, popup = b.closest('.pop');
    if (popup) popup.classList.remove('open');
    if (d.cmd) exec(d.cmd);
    else if (d.align) align(d.align);
    else if (d.indent) indent(+d.indent);
    else if (d.lineHeight) blockStyle('lineHeight', d.lineHeight);
    else if (d.paraSpace !== undefined) blockStyle('marginBottom', d.paraSpace === '0' ? '0' : d.paraSpace);
    else if (d.pstyle) paragraphStyle(d.pstyle);
    else if (d.sizeStep) {
      var cur = currentPt(), steps = [8, 9, 10, 10.5, 11, 12, 14, 16, 18, 20, 24, 28, 36, 48, 72];
      var nx = +d.sizeStep > 0 ? steps.find(function (s) { return s > cur; }) : steps.slice().reverse().find(function (s) { return s < cur; });
      if (nx) applySize(nx);
    }
    else if (d.colorApply) applyColor(d.colorApply, colors[d.colorApply]);
    else if (d.color !== undefined) pickColor(b.closest('[data-palette]').getAttribute('data-palette'), d.color);
    else if (d.tbl) tableCmd(d.tbl);
    else if (d.ph) placeholder(d.ph, false);
    else if (d.field) insertField(d.field);
    else if (d.symbol) insertHtml(esc(d.symbol));
    else if (d.calcPreset) calcPreset(d.calcPreset);
    else if (d.margins) setMargins(d.margins.split(',').map(Number));
    else if (d.hfEdit) startHf(d.hfEdit);
    else if (d.zoom) setZoom(zoom + +d.zoom);
    else if (d.insert === 'hr') exec('insertHorizontalRule');
    else if (d.insert === 'pagebreak') insertHtml('<div class="page-break"></div><p><br></p>');
    else if (d.insert === 'image') { saveSel(); imgInput.click(); }
    else if (d.insert === 'signature') insertHtml('<table class="tbl-plain sig"><tbody><tr><td style="width: 50%"><p><br></p><p class="sig">Ort, Datum</p></td><td style="width: 50%"><p><br></p><p class="sig">Unterschrift</p></td></tr></tbody></table><p><br></p>');
    else if (d.insert === 'link') {
      var url = window.prompt('Adresse des Links (https://…, mailto:… oder tel:…)', 'https://');
      if (url && /^(https?:\/\/|mailto:|tel:)/i.test(url)) exec('createLink', url);
    }
  });

  root.addEventListener('change', function (ev) {
    var s = ev.target;
    if (s.matches('[data-font]')) exec('fontName', s.value, true);
    else if (s.matches('[data-size]')) { var v = parseFloat(String(s.value).replace(',', '.')); if (v) applySize(v); }
    else if (s.matches('[data-number-format]')) {
      var f = s.value;
      selectedCells().forEach(function (x) {
        if (f) x.setAttribute('data-format', f); else x.removeAttribute('data-format');
        if (!x.hasAttribute('data-formula')) { var n = C.parseNumber(x.textContent); if (n !== null && !isNaN(n)) x.textContent = C.format(n, f); }
      });
      touch();
    } else if (s.matches('[data-margin]')) {
      var m = pageSet.m.slice(); m[+s.getAttribute('data-margin')] = parseFloat(s.value) || 0; setMargins(m);
    } else if (s.matches('[data-page-font]')) { pageSet.font = s.value; applyPage(); markDirty(); }
    else if (s.matches('[data-page-size]')) { var ps = parseFloat(String(s.value).replace(',', '.')); if (ps >= 6 && ps <= 30) { pageSet.size = ps; applyPage(); markDirty(); } }
    else if (s.matches('[data-page-ls]')) { pageSet.ls = parseFloat(s.value); applyPage(); markDirty(); }
    else if (s.matches('[data-layout-select]')) setLayout(s.getAttribute('data-layout-select'), +s.value);
    else if (s.matches('[data-zoom-range]')) setZoom(+s.value);
    else if (s.matches('[data-custom-color]')) pickColor(s.getAttribute('data-custom-color'), s.value);
  });
  function markDirty() { dirty = true; if (stateEl) stateEl.textContent = 'Nicht gespeichert'; }

  // Farbpaletten
  var PAL = {
    fore: ['#000000', '#404040', '#7f7f7f', '#ffffff', '#1f3864', '#2f5496', '#4472c4', '#5b9bd5', '#0f7b6c', '#70ad47',
      '#c00000', '#ff0000', '#ed7d31', '#ffc000', '#ffff00', '#92d050', '#00b050', '#00b0f0', '#0070c0', '#7030a0'],
    back: ['#ffff00', '#00ff00', '#00ffff', '#ff00ff', '#ff0000', '#0000ff', '#fde9d9', '#e2efda', '#ddebf7', '#fff2cc', 'none'],
    cell: ['#f2f2f2', '#d9d9d9', '#bfbfbf', '#ddebf7', '#bdd7ee', '#e2efda', '#c6e0b4', '#fff2cc', '#ffe699', '#fce4d6', '#f8cbad', '#e6f4f1', '#1f3864', '#16263a', 'none']
  };
  $$('[data-palette]').forEach(function (box) {
    var kind = box.getAttribute('data-palette'), html = '<div class="sw-grid">';
    PAL[kind].forEach(function (c) { html += '<button type="button" class="sw' + (c === 'none' ? ' sw-none' : '') + '" data-color="' + c + '" title="' + (c === 'none' ? 'Keine Farbe' : c) + '"></button>'; });
    html += '</div><label class="sw-more">Weitere Farben … <input type="color" data-custom-color="' + kind + '"></label>';
    box.innerHTML = html;
    $$('.sw', box).forEach(function (sw) { if (sw.dataset.color !== 'none') sw.style.background = sw.dataset.color; });
  });
  function paintBars() { $$('[data-bar]').forEach(function (b) { b.style.background = colors[b.getAttribute('data-bar')]; }); }
  paintBars();
  function pickColor(kind, c) {
    $$('.pop.open').forEach(function (p) { p.classList.remove('open'); });
    if (kind === 'cell') {
      restore();
      selectedCells().forEach(function (x) { x.style.backgroundColor = c === 'none' ? '' : c; });
      touch();
      return;
    }
    if (c !== 'none') { colors[kind] = c; paintBars(); }
    applyColor(kind, c);
  }
  function applyColor(kind, c) {
    if (kind === 'fore') exec('foreColor', c, true);
    else exec('hiliteColor', c === 'none' ? 'transparent' : c, true);
  }

  // Tabellenauswahl (Raster wie in Word)
  var tg = root.querySelector('[data-table-grid]');
  if (tg) {
    var gh = '';
    for (var tr0 = 1; tr0 <= 8; tr0++) for (var tc0 = 1; tc0 <= 10; tc0++) gh += '<button type="button" data-tr="' + tr0 + '" data-tc="' + tc0 + '" aria-label="' + tc0 + ' × ' + tr0 + '"></button>';
    tg.innerHTML = gh;
    var tl = root.querySelector('[data-table-label]');
    tg.addEventListener('mouseover', function (ev) {
      var b = ev.target.closest('button');
      if (!b) return;
      var rr = +b.dataset.tr, cc = +b.dataset.tc;
      $$('button', tg).forEach(function (x) { x.classList.toggle('on', +x.dataset.tr <= rr && +x.dataset.tc <= cc); });
      tl.textContent = 'Tabelle ' + cc + ' × ' + rr;
    });
    tg.addEventListener('click', function (ev) {
      var b = ev.target.closest('button');
      if (!b) return;
      tg.closest('.pop').classList.remove('open');
      insertHtml(tableHtml(+b.dataset.tr, +b.dataset.tc));
      var t = lastInsertedTable();
      if (t) { var first = t.querySelector('td'); placeCaret(first); setActiveCell(first); }
    });
  }

  imgInput.addEventListener('change', function () {
    var f = imgInput.files[0];
    imgInput.value = '';
    if (!f) return;
    if (f.size > 1048576) { alert('Das Bild ist zu groß (höchstens 1 MB).'); return; }
    var rd = new FileReader();
    rd.onload = function () { insertHtml('<img class="img-m" alt="" src="' + rd.result + '">'); };
    rd.readAsDataURL(f);
  });

  // ------------------------------------------------------------ Zustand im Menüband anzeigen
  var raf = 0;
  function updateRibbon() {
    cancelAnimationFrame(raf);
    raf = requestAnimationFrame(function () {
      var r = selRange();
      ['bold', 'italic', 'underline', 'strikeThrough', 'superscript', 'subscript', 'insertUnorderedList', 'insertOrderedList'].forEach(function (cmd) {
        var on = false;
        try { on = !!r && document.queryCommandState(cmd); } catch (e) { /* alt */ }
        $$('[data-cmd="' + cmd + '"]').forEach(function (b) { b.classList.toggle('on', on); });
      });
      r = r || saved;
      if (r) {
        var el = r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode;
        var cs = getComputedStyle(el);
        var fam = cs.fontFamily.split(',')[0].replace(/["']/g, '').trim();
        var fs = root.querySelector('[data-font]');
        if (fs && Array.prototype.some.call(fs.options, function (o) { return o.value === fam; })) fs.value = fam;
        var sz = root.querySelector('[data-size]');
        if (sz && document.activeElement !== sz) sz.value = String(currentPt()).replace('.', ',');
        var blk = el.closest(BLOCKS);
        var ta = blk ? getComputedStyle(blk).textAlign : 'left';
        $$('[data-align]').forEach(function (b) { b.classList.toggle('on', b.dataset.align === (ta === 'start' ? 'left' : ta)); });
      }
      var td = curCell();
      var tab = root.querySelector('.rb-tab-ctx');
      if (tab) {
        var was = tab.hidden;
        tab.hidden = !td;
        if (!td && tab.classList.contains('active')) showTab('start');
        if (td && was) tab.classList.add('pulse');
      }
      if (td) {
        var nf = root.querySelector('[data-number-format]');
        if (nf) nf.value = td.getAttribute('data-format') || '';
        $$('[data-tbl="input"]').forEach(function (b) { b.classList.toggle('on', td.hasAttribute('data-input')); });
        $$('[data-tbl="grow"]').forEach(function (b) { b.classList.toggle('on', td.closest('table').hasAttribute('data-grow')); });
      }
    });
  }
  document.addEventListener('selectionchange', function () {
    var r = selRange();
    if (r) {
      saveSel();
      var td = cellOf(r.startContainer);
      if (td && td !== activeCell && !$$('.cell-sel', page).length) setActiveCell(td);
      if (!td && activeCell && document.activeElement !== fbInput) setActiveCell(null);
    }
    updateRibbon();
  });
  page.addEventListener('input', function () {
    if (pendingSize) { convertFonts(pendingSize); pendingSize = null; }
    touch();
    if (activeCell && page.contains(activeCell) && document.activeElement !== fbInput) showCell(activeCell);
  });
  page.addEventListener('focusin', function (ev) { var r = regionOf(ev.target); if (r) active = r; });

  // ------------------------------------------------------------ Seite, Lineal, Zoom, Status
  function applyPage() {
    var m = pageSet.m;
    page.style.padding = m[0] + 'mm ' + m[1] + 'mm ' + m[2] + 'mm ' + m[3] + 'mm';
    page.style.fontFamily = "'" + pageSet.font + "', Arial, sans-serif";
    page.style.fontSize = pageSet.size + 'pt';
    page.style.lineHeight = pageSet.ls;
    var pf = root.querySelector('[data-page-font]'); if (pf) pf.value = pageSet.font;
    var psz = root.querySelector('[data-page-size]'); if (psz) psz.value = String(pageSet.size).replace('.', ',');
    var pls = root.querySelector('[data-page-ls]'); if (pls) pls.value = String(pageSet.ls);
    $$('[data-margin]').forEach(function (i) { i.value = m[+i.getAttribute('data-margin')]; });
    // Lineal: Zentimeter ab linkem Seitenrand, graue Ränder
    var h = '<div class="rl-margin" style="left:0;width:' + m[3] + 'mm"></div><div class="rl-margin" style="right:0;width:' + m[1] + 'mm"></div>';
    for (var k = -Math.floor(m[3] / 5); m[3] + k * 5 <= 210; k++) {
      var mm = m[3] + k * 5, major = k % 2 === 0;
      h += '<span class="rl-tick' + (major ? ' major' : '') + '" style="left:' + mm + 'mm">' + (major && k > 0 && mm < 210 - m[1] - 2 ? k / 2 : '') + '</span>';
    }
    h += '<span class="rl-indent" style="left:' + m[3] + 'mm" title="linker Seitenrand"></span><span class="rl-indent" style="left:' + (210 - m[1]) + 'mm" title="rechter Seitenrand"></span>';
    ruler.innerHTML = h;
    if (form && form.elements.page) form.elements.page.value = JSON.stringify(pageSet);
    status();
  }
  function setMargins(m) { pageSet.m = m.map(function (x) { return Math.max(0, Math.min(60, x || 0)); }); applyPage(); markDirty(); }
  function setZoom(z) {
    zoom = Math.max(50, Math.min(200, z));
    page.style.zoom = zoom / 100;
    ruler.style.zoom = zoom / 100;
    var rg = root.querySelector('[data-zoom-range]'); if (rg) rg.value = zoom;
    var lb = root.querySelector('[data-zoom-label]'); if (lb) lb.textContent = zoom + ' %';
    if (activeCell && page.contains(activeCell)) showCell(activeCell);
  }
  function status() {
    var pages = Math.max(1, Math.ceil(page.offsetHeight / (297 * MM) - 0.02));
    var words = (regions.body.innerText || '').replace(/\{\{[^}]*\}\}/g, ' x ').trim().split(/\s+/).filter(Boolean).length;
    var sp = root.querySelector('[data-st-pages]'); if (sp) sp.textContent = pages === 1 ? '1 Seite' : pages + ' Seiten';
    var sw = root.querySelector('[data-st-words]'); if (sw) sw.textContent = words + ' Wörter';
  }

  // ------------------------------------------------------------ Briefkopf & Fußzeile auf der Seite
  var hfBar = null;
  function layoutBody(kind, id) {
    var list = (cfg.layouts && cfg.layouts[kind]) || {};
    if (id < 0) return null;
    var lid = id > 0 && list[id] ? id : (cfg.defaults && cfg.defaults[kind]);
    return list[lid] ? list[lid] : null;
  }
  function setLayout(kind, id) {
    var reg = regions[kind];
    if (!reg || !form) return;
    stopHf();
    form.elements[kind + '_id'].value = id;
    form.elements[kind + '_dirty'].value = '0';
    form.elements[kind + '_new_name'].value = '';
    var l = layoutBody(kind, id);
    reg.innerHTML = l ? l.body || '<p><br></p>' : '';
    reg.classList.toggle('hf-empty', !l);
    var tag = root.querySelector('[data-hf-name="' + kind + '"]');
    if (tag) tag.textContent = l ? ' „' + l.name + '“' : ' (keiner)';
    caretFix();
    markDirty();
    status();
  }
  function startHf(kind) {
    var reg = regions[kind];
    if (!reg || !cfg.canManage) return;
    if (+form.elements[kind + '_id'].value < 0) { alert('Wählen Sie zuerst unter „Layout“ ' + (kind === 'header' ? 'einen Briefkopf' : 'eine Fußzeile') + ' aus.'); return; }
    stopHf();
    reg.setAttribute('contenteditable', 'true');
    reg.classList.remove('locked');
    page.classList.add('hf-editing');
    reg.classList.add('hf-active');
    active = reg;
    var l = layoutBody(kind, +form.elements[kind + '_id'].value);
    hfBar = document.createElement('div');
    hfBar.className = 'hf-bar';
    hfBar.innerHTML = '<strong>' + (kind === 'header' ? 'Briefkopf' : 'Fußzeile') + (l ? ' „' + esc(l.name) + '“' : '') + ' bearbeiten.</strong> Änderungen gelten für alle Formulare mit dieser Vorlage. '
      + '<button type="button" class="btn small" data-hf-new>Als neue Vorlage speichern …</button> <button type="button" class="btn small primary" data-hf-done>Fertig</button>';
    root.querySelector('[data-canvas]').insertBefore(hfBar, ruler);
    hfBar.querySelector('[data-hf-done]').addEventListener('click', stopHf);
    hfBar.querySelector('[data-hf-new]').addEventListener('click', function () {
      var n = window.prompt('Name der neuen Vorlage', (l ? l.name : '') + ' (Kopie)');
      if (!n) return;
      form.elements[kind + '_new_name'].value = n.slice(0, 190);
      form.elements[kind + '_dirty'].value = '1';
      var tag = root.querySelector('[data-hf-name="' + kind + '"]'); if (tag) tag.textContent = ' „' + n + '“ (neu)';
      markDirty();
    });
    reg.focus();
    placeCaret(reg.querySelector('p,td,h1,h2,h3') || reg, true);
  }
  function stopHf() {
    ['header', 'footer'].forEach(function (k) {
      var reg = regions[k];
      if (!reg) return;
      reg.setAttribute('contenteditable', 'false');
      reg.classList.add('locked');
      reg.classList.remove('hf-active');
    });
    page.classList.remove('hf-editing');
    if (hfBar) { hfBar.remove(); hfBar = null; }
    active = regions.body;
  }
  ['header', 'footer'].forEach(function (k) {
    var reg = regions[k];
    if (!reg) return;
    reg.addEventListener('dblclick', function () { if (reg.classList.contains('locked')) startHf(k); });
    var l = layoutBody(k, cfg[k]);
    var tag = root.querySelector('[data-hf-name="' + k + '"]');
    if (tag) tag.textContent = l ? ' „' + l.name + '“' : ' (keiner)';
    reg.classList.toggle('hf-empty', !l);
  });
  regions.body.addEventListener('dblclick', function () { if (page.classList.contains('hf-editing')) stopHf(); });
  $$('[data-layout-select]').forEach(function (s) { s.value = String(cfg[s.getAttribute('data-layout-select')] || 0); });

  // ------------------------------------------------------------ Speichern
  function cleanRegion(reg) {
    var c = reg.cloneNode(true);
    $$('[contenteditable]', c).forEach(function (x) { if (!x.classList.contains('ph')) x.removeAttribute('contenteditable'); });
    $$('.cell-sel', c).forEach(function (x) { x.classList.remove('cell-sel'); });
    $$('.calc-error', c).forEach(function (x) { x.classList.remove('calc-error'); });
    $$('[data-formula]', c).forEach(function (x) { x.textContent = ''; });
    return c.innerHTML.replace(/​/g, '');
  }
  function sync() {
    if (!form) return;
    if (form.elements.body) form.elements.body.value = cleanRegion(regions.body);
    if (regions.header && form.elements.header_html) form.elements.header_html.value = cleanRegion(regions.header);
    if (regions.footer && form.elements.footer_html) form.elements.footer_html.value = cleanRegion(regions.footer);
    if (form.elements.page) form.elements.page.value = JSON.stringify(pageSet);
  }
  function submit() { sync(); dirty = false; if (form.requestSubmit) form.requestSubmit(); else form.submit(); }
  if (form) form.addEventListener('submit', function () { sync(); dirty = false; });
  window.addEventListener('beforeunload', function (ev) { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });

  // ------------------------------------------------------------ Start
  applyPage();
  caretFix();
  $$('table', page).forEach(function (t) { if (C.hasFormulas(t)) C.recalc(t); });
  setZoom(window.innerWidth < 1100 ? 80 : 100);
  dirty = false;
  if (stateEl) stateEl.textContent = '';
  status();
})();
