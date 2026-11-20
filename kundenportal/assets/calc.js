/* Kundenportal – Rechentabellen wie in Excel (Zellbezüge A1, Formeln, Zahlenformate) */
(function () {
  'use strict';

  // ---------------------------------------------------------------- Raster
  /** Raster einer Tabelle unter Berücksichtigung verbundener Zellen */
  function grid(table) {
    var rows = Array.prototype.filter.call(table.rows, function (tr) { return tr.closest('table') === table; });
    var m = [], pos = new Map();
    rows.forEach(function (tr, r) {
      m[r] = m[r] || [];
      var c = 0;
      Array.prototype.forEach.call(tr.cells, function (td) {
        while (m[r][c]) c++;
        var rs = Math.max(1, td.rowSpan || 1), cs = Math.max(1, td.colSpan || 1);
        for (var i = 0; i < rs; i++) {
          m[r + i] = m[r + i] || [];
          for (var j = 0; j < cs; j++) m[r + i][c + j] = td;
        }
        pos.set(td, { r: r, c: c, rs: rs, cs: cs });
        c += cs;
      });
    });
    var cols = m.reduce(function (n, row) { return Math.max(n, row ? row.length : 0); }, 0);
    return { m: m, pos: pos, rows: m.length, cols: cols, trs: rows };
  }

  function colName(c) {
    var s = '';
    c++;
    while (c > 0) { var d = (c - 1) % 26; s = String.fromCharCode(65 + d) + s; c = Math.floor((c - 1) / 26); }
    return s;
  }
  function colIndex(s) {
    var n = 0;
    for (var i = 0; i < s.length; i++) n = n * 26 + (s.charCodeAt(i) - 64);
    return n - 1;
  }
  function addr(r, c) { return colName(c) + (r + 1); }

  // ---------------------------------------------------------------- Zahlen
  function parseNumber(text) {
    var s = String(text == null ? '' : text).replace(/[\s €]/g, '');
    if (s === '') return null;
    var pct = /%$/.test(s);
    if (pct) s = s.slice(0, -1);
    if (/^-?[\d.]*,\d+$/.test(s) || /^-?\d{1,3}(\.\d{3})+(,\d*)?$/.test(s)) s = s.replace(/\./g, '').replace(',', '.');
    else if (/^-?\d+,$/.test(s)) s = s.slice(0, -1);
    if (!/^-?(\d+\.?\d*|\.\d+)$/.test(s)) return NaN;
    var v = parseFloat(s);
    return pct ? v / 100 : v;
  }

  function fmtDe(v, min, max) {
    return v.toLocaleString('de-DE', { minimumFractionDigits: min, maximumFractionDigits: max });
  }
  function format(v, f) {
    if (typeof v === 'string') return v;
    if (typeof v !== 'number' || !isFinite(v)) return '#WERT!';
    switch (f) {
      case 'zahl': return fmtDe(v, 2, 2);
      case 'ganz': return fmtDe(Math.round(v), 0, 0);
      case 'euro': return fmtDe(v, 2, 2) + ' €';
      case 'prozent': return fmtDe(v * 100, 0, 2) + ' %';
      default: return fmtDe(Math.round(v * 1e10) / 1e10, 0, 10);
    }
  }

  // ---------------------------------------------------------------- Formeln
  function Err(code) { this.code = code; }
  var FN = {
    SUMME: 'sum', SUM: 'sum', MITTELWERT: 'avg', AVERAGE: 'avg', MIN: 'min', MAX: 'max', ANZAHL: 'count', COUNT: 'count',
    RUNDEN: 'round', ROUND: 'round', ABS: 'abs', WENN: 'if', IF: 'if', PRODUKT: 'prod', PRODUCT: 'prod',
    AUFRUNDEN: 'ceil', ROUNDUP: 'ceil', ABRUNDEN: 'floor', ROUNDDOWN: 'floor', UND: 'and', AND: 'and', ODER: 'or', OR: 'or'
  };

  function tokenize(src) {
    var t = [], i = 0, re;
    while (i < src.length) {
      var ch = src[i], rest = src.slice(i);
      if (/\s/.test(ch)) { i++; continue; }
      if ((re = /^\$?([A-Z]{1,2})\$?(\d{1,4})(?::\$?([A-Z]{1,2})\$?(\d{1,4}))?/i.exec(rest)) && !/^[A-ZÄÖÜ]+\(/i.test(rest.slice(0, re[0].length + 1))) {
        t.push({ k: 'ref', a: [parseInt(re[2], 10) - 1, colIndex(re[1].toUpperCase())], b: re[3] ? [parseInt(re[4], 10) - 1, colIndex(re[3].toUpperCase())] : null });
        i += re[0].length; continue;
      }
      if ((re = /^(\d+(?:[.,]\d+)?)/.exec(rest))) { t.push({ k: 'num', v: parseFloat(re[1].replace(',', '.')) }); i += re[0].length; continue; }
      if ((re = /^([A-ZÄÖÜ][A-ZÄÖÜ0-9_.]*)\s*\(/i.exec(rest))) { t.push({ k: 'fn', v: re[1].toUpperCase() }); i += re[0].length; continue; }
      if ((re = /^(WAHR|FALSCH|TRUE|FALSE)\b/i.exec(rest))) { t.push({ k: 'num', v: /^(WAHR|TRUE)$/i.test(re[1]) ? 1 : 0 }); i += re[0].length; continue; }
      if ((re = /^"([^"]*)"/.exec(rest))) { t.push({ k: 'str', v: re[1] }); i += re[0].length; continue; }
      if ((re = /^(<=|>=|<>|[-+*\/^%&(),;:<>=])/.exec(rest))) { t.push({ k: 'op', v: re[1] }); i += re[0].length; continue; }
      throw new Err('#NAME?');
    }
    return t;
  }

  /** Wertet eine Formel aus; getCell(r, c) liefert den Wert einer Zelle */
  function evaluate(formula, getCell) {
    var t = tokenize(String(formula).replace(/^=/, '')), p = 0;
    function peek(v) { return t[p] && t[p].k === 'op' && t[p].v === v; }
    function eat(v) { if (!peek(v)) throw new Err('#FEHLER'); p++; }
    function num(x) {
      if (x instanceof Err) throw x;
      if (Array.isArray(x)) x = x[0];
      if (x === null || x === '') return 0;
      if (typeof x === 'number') return x;
      var n = parseNumber(x);
      if (n === null) return 0;
      if (isNaN(n)) throw new Err('#WERT!');
      return n;
    }
    function rangeVals(ref) {
      if (!ref.b) return [getCell(ref.a[0], ref.a[1])];
      var out = [], r1 = Math.min(ref.a[0], ref.b[0]), r2 = Math.max(ref.a[0], ref.b[0]), c1 = Math.min(ref.a[1], ref.b[1]), c2 = Math.max(ref.a[1], ref.b[1]);
      for (var r = r1; r <= r2; r++) for (var c = c1; c <= c2; c++) out.push(getCell(r, c));
      return out;
    }
    function nums(list) {
      var o = [];
      list.forEach(function (x) {
        if (x instanceof Err) throw x;
        if (typeof x === 'number') o.push(x);
        else if (x !== null && x !== '') { var n = parseNumber(x); if (n !== null && !isNaN(n)) o.push(n); }
      });
      return o;
    }
    function compare() {
      var a = concat();
      while (t[p] && t[p].k === 'op' && /^(=|<>|<|>|<=|>=)$/.test(t[p].v)) {
        var op = t[p++].v, b = concat();
        var x = typeof a === 'string' || typeof b === 'string' ? String(a) : num(a), y = typeof a === 'string' || typeof b === 'string' ? String(b) : num(b);
        a = (op === '=' ? x === y : op === '<>' ? x !== y : op === '<' ? x < y : op === '>' ? x > y : op === '<=' ? x <= y : x >= y) ? 1 : 0;
      }
      return a;
    }
    function concat() {
      var a = add();
      while (peek('&')) { p++; var b = add(); a = String(a instanceof Err ? '' : a) + String(b); }
      return a;
    }
    function add() {
      var a = mul();
      while (peek('+') || peek('-')) { var op = t[p++].v, b = mul(); a = op === '+' ? num(a) + num(b) : num(a) - num(b); }
      return a;
    }
    function mul() {
      var a = pow();
      while (peek('*') || peek('/')) {
        var op = t[p++].v, b = pow();
        if (op === '/' && num(b) === 0) throw new Err('#DIV/0!');
        a = op === '*' ? num(a) * num(b) : num(a) / num(b);
      }
      return a;
    }
    function pow() {
      var a = unary();
      while (peek('^')) { p++; a = Math.pow(num(a), num(unary())); }
      return a;
    }
    function unary() {
      if (peek('-')) { p++; return -num(unary()); }
      if (peek('+')) { p++; return num(unary()); }
      var v = primary();
      while (peek('%')) { p++; v = num(v) / 100; }
      return v;
    }
    function args() {
      var list = [];
      if (peek(')')) { p++; return list; }
      for (;;) {
        var tk = t[p];
        if (tk && tk.k === 'ref' && tk.b && t[p + 1] && t[p + 1].k === 'op' && (t[p + 1].v === ';' || t[p + 1].v === ')')) { p++; list.push(rangeVals(tk)); }
        else list.push(compare());
        if (peek(';') || peek(',')) { p++; continue; }
        eat(')');
        return list;
      }
    }
    function flat(list) { return list.reduce(function (a, x) { return a.concat(Array.isArray(x) ? x : [x]); }, []); }
    function primary() {
      var tk = t[p++];
      if (!tk) throw new Err('#FEHLER');
      if (tk.k === 'num' || tk.k === 'str') return tk.v;
      if (tk.k === 'ref') {
        if (tk.b) throw new Err('#WERT!');
        var v = getCell(tk.a[0], tk.a[1]);
        if (v instanceof Err) throw v;
        return v;
      }
      if (tk.k === 'op' && tk.v === '(') { var v2 = compare(); eat(')'); return v2; }
      if (tk.k === 'fn') {
        var f = FN[tk.v];
        if (!f) throw new Err('#NAME?');
        var a = args(), n;
        switch (f) {
          case 'sum': return nums(flat(a)).reduce(function (s, x) { return s + x; }, 0);
          case 'prod': return nums(flat(a)).reduce(function (s, x) { return s * x; }, 1);
          case 'avg': n = nums(flat(a)); if (!n.length) throw new Err('#DIV/0!'); return n.reduce(function (s, x) { return s + x; }, 0) / n.length;
          case 'min': n = nums(flat(a)); return n.length ? Math.min.apply(null, n) : 0;
          case 'max': n = nums(flat(a)); return n.length ? Math.max.apply(null, n) : 0;
          case 'count': return nums(flat(a)).length;
          case 'abs': return Math.abs(num(a[0]));
          case 'round': case 'ceil': case 'floor':
            var d = a.length > 1 ? num(a[1]) : 0, k = Math.pow(10, d), x = num(a[0]) * k;
            return (f === 'round' ? Math.round(x + (x >= 0 ? 1e-9 : -1e-9)) : f === 'ceil' ? Math.ceil(x - 1e-9) : Math.floor(x + 1e-9)) / k;
          case 'if': return num(a[0]) ? (a.length > 1 ? a[1] : 1) : (a.length > 2 ? a[2] : 0);
          case 'and': return flat(a).every(function (x) { return num(x); }) ? 1 : 0;
          case 'or': return flat(a).some(function (x) { return num(x); }) ? 1 : 0;
        }
      }
      throw new Err('#FEHLER');
    }
    var res = compare();
    if (p < t.length) throw new Err('#FEHLER');
    return res;
  }

  /** Berechnet alle Formelzellen einer Tabelle und schreibt die Ergebnisse hinein */
  function recalc(table) {
    var g = grid(table), memo = new Map(), busy = new Set();
    function cellValue(r, c) {
      var td = g.m[r] && g.m[r][c];
      if (!td) return null;
      if (td.hasAttribute('data-formula')) {
        if (memo.has(td)) return memo.get(td);
        if (busy.has(td)) return new Err('#ZIRKEL');
        busy.add(td);
        var v;
        try { v = evaluate(td.getAttribute('data-formula'), cellValue); } catch (e) { v = e instanceof Err ? e : new Err('#FEHLER'); }
        busy.delete(td);
        memo.set(td, v);
        return v;
      }
      var txt = td.textContent.replace(/​/g, '').trim();
      if (txt === '') return null;
      var n = parseNumber(txt);
      return n === null || isNaN(n) ? txt : n;
    }
    g.pos.forEach(function (p, td) {
      if (!td.hasAttribute('data-formula')) return;
      var v = cellValue(p.r, p.c);
      var out = v instanceof Err ? v.code : format(v, td.getAttribute('data-format') || '');
      if (td.textContent !== out) td.textContent = out;
      td.classList.toggle('calc-error', v instanceof Err);
    });
  }

  function hasFormulas(table) { return !!table.querySelector('[data-formula]'); }
  function recalcAll(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('table'), function (t) { if (hasFormulas(t)) recalc(t); });
  }

  // ------------------------------------------------- Bezüge beim Einfügen verschieben
  /**
   * Passt Bezüge an, wenn Zeilen/Spalten eingefügt (delta > 0) oder gelöscht (delta < 0) werden.
   * axis 0 = Zeile, 1 = Spalte; at = Index der ersten betroffenen Zeile/Spalte.
   * Ein Bereich, der direkt vor der Einfügestelle endet, wird erweitert (z. B. SUMME über Positionen).
   */
  function shiftFormula(f, axis, at, delta) {
    return f.replace(/(\$?)([A-Z]{1,2})(\$?)(\d{1,4})(?::(\$?)([A-Z]{1,2})(\$?)(\d{1,4}))?(?![A-Za-zÄÖÜ(])/g, function (all, d1, c1, d2, r1, d3, c2, d4, r2) {
      var a = [parseInt(r1, 10) - 1, colIndex(c1)];
      var b = c2 ? [parseInt(r2, 10) - 1, colIndex(c2)] : null;
      function mv(x, isEnd, isRange) {
        var v = x[axis];
        if (delta > 0) {
          if (v >= at) v += delta;
          else if (isEnd && isRange && v === at - 1) v += delta;
        } else if (v > at) v += delta;
        var y = x.slice(); y[axis] = Math.max(0, v); return y;
      }
      var na = mv(a, false, !!b), nb = b ? mv(b, true, true) : null;
      if (b && delta > 0 && a[axis] >= at && b[axis] >= at) { na = a.slice(); na[axis] += delta; }
      var s = d1 + colName(na[1]) + d2 + (na[0] + 1);
      if (nb) s += ':' + d3 + colName(nb[1]) + d4 + (nb[0] + 1);
      return s;
    });
  }

  /** Verschiebt die Bezüge in allen Formeln einer Tabelle */
  function shiftTable(table, axis, at, delta, except) {
    Array.prototype.forEach.call(table.querySelectorAll('[data-formula]'), function (td) {
      if (except && except.indexOf(td) !== -1) return;
      td.setAttribute('data-formula', shiftFormula(td.getAttribute('data-formula'), axis, at, delta));
    });
  }

  /** Bezüge auf die eigene Zeile beim Kopieren einer Zeile mitziehen (=C4*D4 → =C5*D5) */
  function copyRowFormula(f, fromRow, toRow) {
    return f.replace(/(\$?)([A-Z]{1,2})(\$?)(\d{1,4})(?![A-Za-zÄÖÜ(])/g, function (all, d1, c, d2, r) {
      return parseInt(r, 10) - 1 === fromRow && !d2 ? d1 + c + (toRow + 1) : all;
    });
  }

  /**
   * Fügt unter einer Zeile eine Kopie ein (leere Eingabezellen, angepasste Formeln).
   * Gibt die neue Zeile zurück.
   */
  function insertRowCopy(table, tr, keepText) {
    var g = grid(table), r = g.trs.indexOf(tr);
    var originals = {};
    Array.prototype.forEach.call(tr.cells, function (td, i) { if (td.hasAttribute('data-formula')) originals[i] = td.getAttribute('data-formula'); });
    var nr = tr.cloneNode(true);
    Array.prototype.forEach.call(nr.cells, function (td) { td.rowSpan = 1; });
    shiftTable(table, 0, r + 1, 1);
    Array.prototype.forEach.call(nr.cells, function (td, i) {
      if (originals[i] !== undefined) td.setAttribute('data-formula', copyRowFormula(originals[i], r, r + 1));
      else if (!keepText) td.innerHTML = '<br>';
      // Positionsnummer weiterzählen
      if (!td.hasAttribute('data-formula') && !td.hasAttribute('data-input') && i === 0) {
        var n = parseNumber(tr.cells[0].textContent);
        if (n !== null && !isNaN(n) && Math.round(n) === n) td.textContent = String(n + 1);
      }
    });
    tr.parentNode.insertBefore(nr, tr.nextSibling);
    return nr;
  }

  window.KPCalc = {
    grid: grid, colName: colName, colIndex: colIndex, addr: addr, parseNumber: parseNumber, format: format,
    evaluate: evaluate, recalc: recalc, recalcAll: recalcAll, hasFormulas: hasFormulas,
    shiftFormula: shiftFormula, shiftTable: shiftTable, copyRowFormula: copyRowFormula, insertRowCopy: insertRowCopy
  };
})();
