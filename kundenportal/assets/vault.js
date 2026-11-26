/*
 * Passwort-Tresor (Dokumente & Anleitungen): Zero-Knowledge-Verschlüsselung im Browser mit Web Crypto.
 *
 * - Konto: ECDH-Schlüsselpaar P-256. Privater Schlüssel verschlüsselt mit dem Tresor-Passwort
 *   (PBKDF2-SHA256, 600.000 Runden → AES-256-GCM) und mit dem Notfallkit (32 Zufallsbytes, HKDF-SHA256 → AES-256-GCM).
 * - Ordner: zufälliger AES-256-Schlüssel, für jedes Mitglied verpackt: ECDH (Einmal-Schlüssel × öffentlicher
 *   Schlüssel des Mitglieds) → HKDF-SHA256 → AES-256-GCM.
 * - Einträge: JSON der Felder, AES-256-GCM mit dem Ordnerschlüssel.
 * Schlüssel liegen nur im Arbeitsspeicher dieser Seite; Sperren, Neuladen oder Zeitablauf löscht sie.
 */
(function () {
  'use strict';
  var root = document.getElementById('vault');
  if (!root) return;

  function T(s, vars) {
    var t = (window.KP_T && window.KP_T[s]) || s;
    if (vars) Object.keys(vars).forEach(function (k) { t = t.split('{' + k + '}').join(String(vars[k])); });
    return t;
  }

  var API = root.getAttribute('data-api');
  var CSRF = root.getAttribute('data-csrf');
  var ME = +root.getAttribute('data-user');
  var ADMIN = root.getAttribute('data-admin') === '1';
  var ITER = +root.getAttribute('data-iter') || 600000;
  var COMPANY = root.getAttribute('data-company') || '';
  var ME_NAME = root.getAttribute('data-user-name') || '';
  var C = window.crypto && window.crypto.subtle;
  var te = new TextEncoder(), td = new TextDecoder();

  var KINDS = {
    login: { label: T('Login'), fields: [['url', T('Adresse (URL)')], ['username', T('Benutzername')], ['password', T('Passwort'), 's'], ['totp', T('2FA-Geheimnis (TOTP)'), 's'], ['notes', T('Notiz'), 'm']] },
    wlan: { label: T('WLAN'), fields: [['ssid', T('Netzwerkname (SSID)')], ['password', T('Passwort'), 's'], ['security', T('Verschlüsselung')], ['guest', T('Gastnetz')], ['notes', T('Notiz'), 'm']] },
    netz: { label: T('Router / Firewall / Switch'), fields: [['address', T('Verwaltungsadresse oder IP')], ['username', T('Benutzername')], ['password', T('Passwort'), 's'], ['notes', T('Notiz'), 'm']] },
    portal: { label: T('Lizenzportal'), fields: [['url', T('Adresse (URL)')], ['customer_no', T('Kundennummer')], ['username', T('Benutzername')], ['password', T('Passwort'), 's'], ['notes', T('Notiz'), 'm']] },
    server: { label: T('Server / Dienst'), fields: [['host', T('Host')], ['port', T('Port')], ['username', T('Dienstkonto')], ['password', T('Passwort'), 's'], ['notes', T('Notiz'), 'm']] },
    notiz: { label: T('Freie Notiz'), fields: [['notes', T('Inhalt'), 'sm']] }
  };

  var S = { state: null, priv: null, pub: null, raw: {}, folder: null, timer: null, lockMin: 15, busy: false };

  // ---------------------------------------------------------------------------
  // Hilfsfunktionen
  // ---------------------------------------------------------------------------
  function b64(buf) {
    var b = new Uint8Array(buf), s = '';
    for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s);
  }
  function unb64(s) {
    var bin = atob(s), b = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i);
    return b;
  }
  function rand(n) { return crypto.getRandomValues(new Uint8Array(n)); }

  function el(tag, props) {
    var n = document.createElement(tag);
    if (props) Object.keys(props).forEach(function (k) {
      var v = props[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'text') n.textContent = v;
      else if (k === 'cls') n.className = v;
      else if (k.indexOf('on') === 0) n.addEventListener(k.slice(2), v);
      else n.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c === null || c === undefined || c === false) continue;
      if (Array.isArray(c)) c.forEach(function (x) { if (x) n.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); });
      else n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return n;
  }
  function clear(n) { while (n.firstChild) n.removeChild(n.firstChild); return n; }

  function msg(text, kind) {
    var box = document.getElementById('vault-msg');
    if (!box) return;
    clear(box);
    if (text) box.appendChild(el('div', { cls: 'alert ' + (kind || 'info') }, text));
  }

  function api(action, p) {
    var fd = new FormData();
    fd.append('_csrf', CSRF);
    fd.append('action', action);
    fd.append('p', JSON.stringify(p || {}));
    return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { throw new Error(T('Der Server hat nicht wie erwartet geantwortet.')); });
    }).then(function (j) {
      if (!j.ok) throw new Error(j.error || T('Unbekannter Fehler.'));
      return j;
    });
  }

  // ---------------------------------------------------------------------------
  // Kryptografie
  // ---------------------------------------------------------------------------
  function pwKey(pw, salt, iter) {
    return C.importKey('raw', te.encode(pw), 'PBKDF2', false, ['deriveKey']).then(function (base) {
      return C.deriveKey({ name: 'PBKDF2', salt: salt, iterations: iter, hash: 'SHA-256' }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    });
  }
  function hkdfKey(secret, info) {
    return C.importKey('raw', secret, 'HKDF', false, ['deriveKey']).then(function (base) {
      return C.deriveKey({ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(32), info: te.encode(info) }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    });
  }
  function aesEnc(key, bytes) {
    var iv = rand(12);
    return C.encrypt({ name: 'AES-GCM', iv: iv }, key, bytes).then(function (ct) { return { i: b64(iv), c: b64(ct) }; });
  }
  function aesDec(key, blob) {
    return C.decrypt({ name: 'AES-GCM', iv: unb64(blob.i) }, key, unb64(blob.c)).then(function (pt) { return new Uint8Array(pt); });
  }
  function rawAes(raw) { return C.importKey('raw', raw, 'AES-GCM', false, ['encrypt', 'decrypt']); }
  function newPair() { return C.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']); }
  function importPriv(pkcs8) { return C.importKey('pkcs8', pkcs8, { name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']); }
  function importPub(b) { return C.importKey('spki', unb64(b), { name: 'ECDH', namedCurve: 'P-256' }, false, []); }

  /** Ordnerschlüssel (32 Bytes) für einen öffentlichen Schlüssel verpacken */
  function wrapFor(pubB64, raw) {
    var eph;
    return newPair().then(function (p) { eph = p; return importPub(pubB64); })
      .then(function (pub) { return C.deriveBits({ name: 'ECDH', public: pub }, eph.privateKey, 256); })
      .then(function (bits) { return hkdfKey(bits, 'kp-vault-wrap'); })
      .then(function (k) { return Promise.all([aesEnc(k, raw), C.exportKey('spki', eph.publicKey)]); })
      .then(function (r) { return { e: b64(r[1]), i: r[0].i, c: r[0].c }; });
  }
  function unwrap(priv, w) {
    return C.importKey('spki', unb64(w.e), { name: 'ECDH', namedCurve: 'P-256' }, false, [])
      .then(function (eph) { return C.deriveBits({ name: 'ECDH', public: eph }, priv, 256); })
      .then(function (bits) { return hkdfKey(bits, 'kp-vault-wrap'); })
      .then(function (k) { return aesDec(k, w); });
  }

  /** Notfallkit: 32 Bytes als Base32 in Vierergruppen, z. B. KP1-ABCD-EFGH-… */
  var B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  function kitEncode(bytes) {
    var bits = 0, val = 0, out = '';
    for (var i = 0; i < bytes.length; i++) {
      val = (val << 8) | bytes[i]; bits += 8;
      while (bits >= 5) { out += B32[(val >>> (bits - 5)) & 31]; bits -= 5; }
    }
    if (bits > 0) out += B32[(val << (5 - bits)) & 31];
    return 'KP1-' + out.match(/.{1,4}/g).join('-');
  }
  function kitDecode(text) {
    var s = String(text || '').toUpperCase().replace(/^\s*KP1[-\s]*/, '').replace(/0/g, 'O').replace(/1/g, 'I').replace(/8/g, 'B').replace(/[^A-Z2-7]/g, '');
    if (s.length !== 52) return null;
    var bits = 0, val = 0, out = [];
    for (var i = 0; i < s.length; i++) {
      val = (val << 5) | B32.indexOf(s[i]); bits += 5;
      if (bits >= 8) { out.push((val >>> (bits - 8)) & 255); bits -= 8; }
    }
    return out.length >= 32 ? new Uint8Array(out.slice(0, 32)) : null;
  }

  /** Neues Konto: Schlüsselpaar, mit Passwort und Notfallkit verschlüsselt */
  function createAccount(pw) {
    var pair, pkcs8, kit = rand(32), salt = rand(16);
    return newPair().then(function (p) { pair = p; return Promise.all([C.exportKey('pkcs8', p.privateKey), C.exportKey('spki', p.publicKey)]); })
      .then(function (r) {
        pkcs8 = new Uint8Array(r[0]);
        return Promise.all([pwKey(pw, salt, ITER), hkdfKey(kit, 'kp-vault-kit'), r[1]]);
      })
      .then(function (r) { return Promise.all([aesEnc(r[0], pkcs8), aesEnc(r[1], pkcs8), r[2]]); })
      .then(function (r) {
        return { payload: { public_key: b64(r[2]), priv_pw: { s: b64(salt), it: ITER, i: r[0].i, c: r[0].c }, priv_kit: r[1] }, kit: kitEncode(kit), pkcs8: pkcs8 };
      });
  }
  function encPrivWithPw(pkcs8, pw) {
    var salt = rand(16);
    return pwKey(pw, salt, ITER).then(function (k) { return aesEnc(k, pkcs8); }).then(function (b) { return { s: b64(salt), it: ITER, i: b.i, c: b.c }; });
  }

  // ---------------------------------------------------------------------------
  // Passwort-Generator (Zufall aus crypto.getRandomValues, ohne Verzerrung)
  // ---------------------------------------------------------------------------
  function randInt(n) {
    var lim = Math.floor(4294967296 / n) * n, x = new Uint32Array(1);
    do { crypto.getRandomValues(x); } while (x[0] >= lim);
    return x[0] % n;
  }
  var SETS = { lower: 'abcdefghijkmnopqrstuvwxyz', upper: 'ABCDEFGHJKLMNPQRSTUVWXYZ', digits: '23456789', symbols: '!#$%&*+-=?@_~.:,;' };
  var SETS_AMB = { lower: 'l', upper: 'IO', digits: '01' };
  function genPassword(o) {
    var pools = [];
    ['lower', 'upper', 'digits', 'symbols'].forEach(function (k) { if (o[k]) pools.push(SETS[k] + (o.ambiguous ? '' : (SETS_AMB[k] || ''))); });
    if (!pools.length) pools.push(SETS.lower);
    var all = pools.join(''), out = [];
    pools.forEach(function (pl) { out.push(pl[randInt(pl.length)]); });        // aus jeder Gruppe mindestens ein Zeichen
    while (out.length < o.length) out.push(all[randInt(all.length)]);
    for (var i = out.length - 1; i > 0; i--) { var j = randInt(i + 1), t = out[i]; out[i] = out[j]; out[j] = t; }
    return out.slice(0, o.length).join('');
  }
  var WORDS = ('apfel abend acker adler ahorn akte alarm alpen amsel anker antwort apfel arena asche atlas auge august bach bahn balkon ball banane bank bart '
    + 'bauer baum becher beere berg besen bett biene bild birne blatt blitz blume boden bohne boot brief brille brot bruecke brunnen buch burg busch butter '
    + 'dach dame damm decke delfin dorf drache dreieck duene dusche eber ecke efeu eiche eimer eis elch engel ente erbse erde esel eule fabel fackel faden '
    + 'falke farbe fass feder fee feld fenster ferien fichte film finger fisch flagge flasche fluss flut fohlen forelle frosch fuchs funke gabel garten gast '
    + 'gebirge geige gipfel glas glocke gold gras grube gurke hafen hagel hahn hammer hand harfe hase haus hecht hecke hefe heide held hemd herbst himmel '
    + 'hirsch hobel honig horn hose huegel hund hut igel insel jacke jaguar joghurt kabel kaffee kahn kakao kamel kamm kanal kanne kappe karte kater kerze '
    + 'kette kiefer kiesel kirsche kissen kiste klee knopf koch koffer komet kompass korb kran kreis kreuz krone kuchen kugel kurve lachs lager lampe land '
    + 'laterne laub leder leiter lerche licht linde lineal loewe loeffel luchs luft lupe magnet mais mantel marder markt maus meer mehl melone messer mond '
    + 'moos motor muehle muschel nadel nebel nest netz nuss oase ofen ohr olive onkel orgel otter paddel palme papier pappel park pfanne pferd pflaume pilz '
    + 'pinsel planet platz pokal post puppe quelle rabe radio rakete rasen raupe regen reh reise rinde ring rose ruder saege salz sand sattel schaf schal '
    + 'schiff schirm schloss schnee schuh see segel seil sense sessel sieb silber sofa sonne spiegel stern stiefel strand stuhl sturm tafel tal tanne tasche '
    + 'taube tee teller tiger tinte tisch tomate topf traube turm uhr ufer veilchen vogel vulkan waage wagen wald wal wasser weide welle wiese wind winter '
    + 'wolke wurm zange zebra zelt ziege zimmer zirkus zitrone zucker zwerg').split(' ').filter(function (w, i, a) { return a.indexOf(w) === i; });
  function genPassphrase(n) {
    var w = [];
    for (var i = 0; i < n; i++) { var x = WORDS[randInt(WORDS.length)]; w.push(i === 0 ? x.charAt(0).toUpperCase() + x.slice(1) : x); }
    return w.join('-') + '-' + (10 + randInt(90));
  }

  // ---------------------------------------------------------------------------
  // TOTP (RFC 6238): Geheimnis als Base32 oder otpauth://-Adresse
  // ---------------------------------------------------------------------------
  function totpParams(v) {
    var o = { secret: String(v || '').trim(), digits: 6, period: 30, algo: 'SHA-1' };
    if (/^otpauth:\/\//i.test(o.secret)) {
      try {
        var u = new URL(o.secret), q = u.searchParams;
        o.secret = q.get('secret') || '';
        if (q.get('digits')) o.digits = Math.min(10, Math.max(6, +q.get('digits')));
        if (q.get('period')) o.period = Math.max(10, +q.get('period'));
        var a = (q.get('algorithm') || 'SHA1').toUpperCase();
        o.algo = a === 'SHA256' ? 'SHA-256' : a === 'SHA512' ? 'SHA-512' : 'SHA-1';
      } catch (e) { return null; }
    }
    var s = o.secret.toUpperCase().replace(/[\s=-]/g, ''), bits = 0, val = 0, bytes = [];
    for (var i = 0; i < s.length; i++) {
      var k = B32.indexOf(s[i]);
      if (k < 0) return null;
      val = (val << 5) | k; bits += 5;
      if (bits >= 8) { bytes.push((val >>> (bits - 8)) & 255); bits -= 8; }
    }
    if (bytes.length < 5) return null;
    o.key = new Uint8Array(bytes);
    return o;
  }
  function totpNow(o) {
    var now = Date.now() / 1000, ctr = Math.floor(now / o.period), msg = new Uint8Array(8), c = ctr;
    for (var i = 7; i >= 0; i--) { msg[i] = c & 255; c = Math.floor(c / 256); }
    return C.importKey('raw', o.key, { name: 'HMAC', hash: o.algo }, false, ['sign']).then(function (k) { return C.sign('HMAC', k, msg); }).then(function (sig) {
      var h = new Uint8Array(sig), off = h[h.length - 1] & 15;
      var bin = ((h[off] & 127) << 24) | (h[off + 1] << 16) | (h[off + 2] << 8) | h[off + 3];
      var code = String(bin % Math.pow(10, o.digits));
      while (code.length < o.digits) code = '0' + code;
      return { code: code, left: Math.ceil(o.period - (now % o.period)) };
    });
  }

  // ---------------------------------------------------------------------------
  // CSV lesen (Bitwarden, KeePass, KeePassXC, 1Password)
  // ---------------------------------------------------------------------------
  function parseCSV(text) {
    text = text.replace(/^﻿/, '');
    var first = text.split(/\r?\n/)[0] || '';
    var cnt = function (ch) { return first.split(ch).length - 1; };
    var d = [',', ';', '\t'].sort(function (a, b) { return cnt(b) - cnt(a); })[0];
    var rows = [], row = [], cur = '', q = false;
    for (var i = 0; i < text.length; i++) {
      var ch = text[i];
      if (q) {
        if (ch === '"') { if (text[i + 1] === '"') { cur += '"'; i++; } else q = false; }
        else cur += ch;
      } else if (ch === '"') q = true;
      else if (ch === d) { row.push(cur); cur = ''; }
      else if (ch === '\n' || ch === '\r') {
        if (ch === '\r' && text[i + 1] === '\n') i++;
        row.push(cur); cur = '';
        if (row.length > 1 || row[0] !== '') rows.push(row);
        row = [];
      } else cur += ch;
    }
    if (cur !== '' || row.length) { row.push(cur); if (row.length > 1 || row[0] !== '') rows.push(row); }
    return rows;
  }
  var ALIASES = {
    title: ['name', 'title', 'account', 'titel'], url: ['login_uri', 'url', 'website', 'web site', 'urls', 'adresse'],
    username: ['login_username', 'username', 'login name', 'user name', 'benutzername', 'user'], password: ['login_password', 'password', 'passwort', 'kennwort'],
    totp: ['login_totp', 'totp', 'otpauth', 'one-time password', 'otp'], notes: ['notes', 'comments', 'note', 'notizen', 'notiz', 'extra'],
    type: ['type'], group: ['folder', 'group', 'gruppe', 'ordner', 'tags'], fields: ['fields']
  };
  function csvItems(rows) {
    if (rows.length < 2) return { items: [], source: '' };
    var head = rows[0].map(function (h) { return h.trim().toLowerCase(); }), col = {};
    Object.keys(ALIASES).forEach(function (k) {
      for (var i = 0; i < ALIASES[k].length; i++) { var x = head.indexOf(ALIASES[k][i]); if (x !== -1) { col[k] = x; break; } }
    });
    var source = head.indexOf('login_uri') !== -1 ? 'Bitwarden' : head.indexOf('login name') !== -1 ? 'KeePass'
      : (head.indexOf('group') !== -1 && head.indexOf('title') !== -1) ? 'KeePassXC' : head.indexOf('otpauth') !== -1 || head.indexOf('website') !== -1 ? '1Password' : 'CSV';
    var get = function (r, k) { return col[k] === undefined ? '' : String(r[col[k]] || '').trim(); };
    var items = rows.slice(1).map(function (r) {
      var f = { url: get(r, 'url'), username: get(r, 'username'), password: get(r, 'password'), totp: get(r, 'totp'), notes: get(r, 'notes') };
      var extra = get(r, 'fields');
      if (extra) f.notes = (f.notes ? f.notes + '\n\n' : '') + extra;
      var type = get(r, 'type').toLowerCase();
      var kind = type === 'note' || type === 'securenote' || (!f.url && !f.username && !f.password) ? 'notiz' : 'login';
      if (kind === 'notiz') f = { notes: f.notes || [f.url, f.username, f.password].filter(Boolean).join('\n') };
      Object.keys(f).forEach(function (k) { if (!f[k]) delete f[k]; });
      return { title: get(r, 'title') || f.url || T('Ohne Titel'), kind: kind, fields: f, group: get(r, 'group') };
    }).filter(function (it) { return Object.keys(it.fields).length || it.title; });
    return { items: items, source: source };
  }
  function addMonths(months) {
    var d = new Date(), day = d.getDate();
    d.setDate(1); d.setMonth(d.getMonth() + months);
    d.setDate(Math.min(day, new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()));
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function today() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function download(name, content, type) {
    var a = el('a', { href: URL.createObjectURL(new Blob([content], { type: type })), download: name });
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
  }

  // ---------------------------------------------------------------------------
  // Zustand laden, Ordner entpacken, Freigaben und Schlüsselwechsel
  // ---------------------------------------------------------------------------
  function load() {
    return api('state').then(function (j) {
      S.state = j;
      S.lockMin = j.company && j.company.lock_minutes || 15;
      return S.priv ? processFolders() : null;
    }).then(render).catch(function (e) { render(); msg(e.message, 'error'); });
  }

  function processFolders() {
    var st = S.state, jobs = [], notes = [];
    st.folders.forEach(function (f) {
      if (!f.my_key) { delete S.raw[f.id]; return; }
      jobs.push(unwrap(S.priv, f.my_key).then(function (raw) {
        S.raw[f.id] = raw;
        if (f.needs_rotation && f.perm >= 2) return rotate(f).then(function () { notes.push(T('Ordner „{n}“ wurde neu verschlüsselt.', { n: f.name })); return 'reload'; });
        if (f.pending && f.pending.length) {
          return Promise.all(f.pending.map(function (m) {
            return wrapFor(m.public_key, raw).then(function (w) { return { user_id: m.user_id, wrapped: w }; });
          })).then(function (keys) { return api('share', { folder_id: f.id, key_version: f.key_version, keys: keys }); })
            .then(function (r) { if (r.shared) notes.push(T('Ordner „{n}“ wurde für {x} weitere Zugänge freigeschaltet.', { n: f.name, x: r.shared })); });
        }
      }).catch(function () { notes.push(T('Der Schlüssel für Ordner „{n}“ ließ sich nicht entschlüsseln.', { n: f.name })); }));
    });
    return Promise.all(jobs).then(function (res) {
      if (notes.length) S.note = notes.join(' ');
      if (res.indexOf('reload') !== -1) return api('state').then(function (j) { S.state = j; return processFolders(); });
    });
  }

  /** Schlüsselwechsel: neuer Ordnerschlüssel, alle Einträge neu verschlüsseln, für alle Mitglieder verpacken */
  function rotate(f) {
    var oldKey, newRaw = rand(32), newKey;
    return rawAes(S.raw[f.id]).then(function (k) { oldKey = k; return rawAes(newRaw); }).then(function (k) {
      newKey = k;
      return Promise.all(f.entries.map(function (e) {
        return api('entry_get', { id: e.id, purpose: 'rotate' }).then(function (r) { return aesDec(oldKey, r.data); })
          .then(function (pt) { return aesEnc(newKey, pt); }).then(function (blob) { return { id: e.id, data: blob }; });
      }));
    }).then(function (entries) {
      // alle Mitglieder mit Tresor-Zugang und der Firmen-Notfallschlüssel (Liste vom Server: „recipients“)
      var targets = (f.recipients || []).slice();
      if (!targets.some(function (m) { return m.user_id === ME; })) targets.push({ user_id: ME, public_key: S.state.account.public_key });
      if (!targets.some(function (m) { return m.user_id === 0; })) targets.push({ user_id: 0, public_key: S.state.company.public_key });
      return Promise.all(targets.map(function (m) { return wrapFor(m.public_key, newRaw).then(function (w) { return { user_id: m.user_id, wrapped: w }; }); }))
        .then(function (keys) { return api('rotate', { folder_id: f.id, old_version: f.key_version, entries: entries, keys: keys }); });
    }).then(function () { S.raw[f.id] = newRaw; });
  }

  // ---------------------------------------------------------------------------
  // Sperren
  // ---------------------------------------------------------------------------
  function lock(reason) {
    S.priv = null; S.raw = {}; S.folder = S.folder; S.pub = null;
    clearTimeout(S.timer);
    render();
    if (reason) msg(reason, 'info');
  }
  function touch() {
    if (!S.priv) return;
    clearTimeout(S.timer);
    S.timer = setTimeout(function () { lock(S.lockMin === 1 ? T('Der Tresor wurde nach 1 Minute ohne Aktivität gesperrt.') : T('Der Tresor wurde nach {n} Minuten ohne Aktivität gesperrt.', { n: S.lockMin })); }, S.lockMin * 60000);
  }
  ['mousedown', 'keydown', 'touchstart', 'scroll'].forEach(function (ev) { document.addEventListener(ev, touch, { passive: true }); });
  window.addEventListener('pagehide', function () { S.priv = null; S.raw = {}; });

  // ---------------------------------------------------------------------------
  // Oberfläche
  // ---------------------------------------------------------------------------
  function shell() {
    clear(root);
    root.appendChild(el('div', { id: 'vault-msg' }));
    var body = el('div', { cls: 'vault-body' });
    root.appendChild(body);
    if (S.note) { msg(S.note, 'success'); S.note = null; }
    return body;
  }

  function render() {
    if (!C) { clear(root).appendChild(el('div', { cls: 'alert error' }, T('Ihr Browser unterstützt die nötige Verschlüsselung nicht (Web Crypto). Bitte nutzen Sie einen aktuellen Browser über HTTPS.'))); return; }
    var st = S.state;
    if (!st) { shell().appendChild(el('div', { cls: 'card' }, el('p', { cls: 'muted', text: T('Tresor wird geladen …') }))); return; }
    if (!st.account) return viewSetup();
    if (!S.priv) return viewUnlock();
    if (!st.company.active) return ADMIN ? viewActivate() : viewInactive();
    viewMain();
  }

  function card(title, children) {
    return el('div', { cls: 'card vault-card' }, title ? el('h2', { text: title }) : null, children);
  }

  function pwField(name, label, auto) {
    return el('label', null, label, el('input', { type: 'password', name: name, autocomplete: auto || 'new-password', required: true, minlength: '12' }));
  }

  // --- Einrichten ---------------------------------------------------------------
  function viewSetup() {
    var body = shell();
    var form = el('form', { cls: 'form', onsubmit: function (ev) {
      ev.preventDefault();
      var pw = form.elements.pw.value, pw2 = form.elements.pw2.value;
      if (pw.length < 12) return msg(T('Das Tresor-Passwort muss mindestens 12 Zeichen lang sein.'), 'error');
      if (pw !== pw2) return msg(T('Die Passwörter stimmen nicht überein.'), 'error');
      busy(form, true);
      createAccount(pw).then(function (acc) {
        showKit(T('Ihr persönliches Notfallkit'), acc.kit, T('Mit diesem Notfallkit setzen Sie ein neues Tresor-Passwort, falls Sie es vergessen. Wer das Kit hat, kann zusammen mit Ihrer Portal-Anmeldung Ihren Tresor öffnen – bewahren Sie es sicher auf.'),
          ME_NAME, function () {
            return api('account_create', acc.payload).then(function () { return importPriv(acc.pkcs8); }).then(function (k) {
              S.priv = k; touch(); S.note = T('Ihr Tresor-Zugang ist eingerichtet.'); return load();
            });
          });
      }).catch(function (e) { busy(form, false); msg(e.message, 'error'); });
    } },
      el('p', null, T('Der Tresor verschlüsselt Zugangsdaten direkt in Ihrem Browser. Weder der Server noch Ihr IT-Dienstleister können sie lesen.')),
      el('p', null, T('Legen Sie dafür ein eigenes Tresor-Passwort fest. Es ist unabhängig von Ihrem Portal-Passwort und wird nirgends gespeichert.')),
      pwField('pw', T('Tresor-Passwort (mindestens 12 Zeichen)')),
      pwField('pw2', T('Tresor-Passwort wiederholen')),
      el('div', { cls: 'alert warn' }, T('Wichtig: Vergessen Sie das Tresor-Passwort und verlieren das Notfallkit, kann niemand Ihren Zugang wiederherstellen. Ihre Firma kann Ihnen dann nur einen neuen Zugang einrichten und die Ordner neu freigeben.')),
      el('button', { cls: 'btn primary', type: 'submit' }, T('Tresor-Zugang einrichten')));
    body.appendChild(card(T('Tresor einrichten'), form));
  }

  function busy(form, on) {
    Array.prototype.forEach.call(form.querySelectorAll('button, input, select, textarea'), function (x) { x.disabled = on; });
    var b = form.querySelector('button[type=submit]');
    if (b) { if (on) { b.setAttribute('data-label', b.textContent); b.textContent = T('Bitte warten …'); } else if (b.getAttribute('data-label')) b.textContent = b.getAttribute('data-label'); }
  }

  /** Notfallkit anzeigen, drucken oder speichern; erst nach Bestätigung geht es weiter */
  function showKit(title, kit, text, owner, onConfirm) {
    var body = shell();
    var date = new Date().toLocaleDateString();
    var sheet = el('div', { cls: 'vault-kit' },
      el('h2', { text: T('Notfallkit – Passwort-Tresor') }),
      el('p', null, el('strong', { text: title }), ' · ', owner, COMPANY ? ' · ' + COMPANY : '', ' · ', date),
      el('p', { cls: 'vault-kit-key', text: kit }),
      el('p', { text: text }),
      el('p', { cls: 'muted small', text: T('Aufbewahren: ausgedruckt an einem sicheren Ort (z. B. Tresor oder Safe), nicht als Datei auf demselben Rechner und nicht per E-Mail versenden.') }));
    var ok = el('input', { type: 'checkbox', id: 'kit-ok' });
    var next = el('button', { cls: 'btn primary', type: 'button', disabled: true, onclick: function () {
      next.disabled = true; next.textContent = T('Bitte warten …');
      Promise.resolve(onConfirm()).catch(function (e) { next.disabled = false; next.textContent = T('Weiter'); msg(e.message, 'error'); });
    } }, T('Weiter'));
    ok.addEventListener('change', function () { next.disabled = !ok.checked; });
    body.appendChild(el('div', { cls: 'card vault-card' }, sheet,
      el('div', { cls: 'btn-row' },
        el('button', { cls: 'btn', type: 'button', onclick: function () { document.body.classList.add('vault-print'); window.print(); setTimeout(function () { document.body.classList.remove('vault-print'); }, 500); } }, T('Drucken / als PDF speichern')),
        el('button', { cls: 'btn', type: 'button', onclick: function () {
          var blob = new Blob([T('Notfallkit – Passwort-Tresor') + '\r\n' + title + ' · ' + owner + (COMPANY ? ' · ' + COMPANY : '') + ' · ' + date + '\r\n\r\n' + kit + '\r\n\r\n' + text + '\r\n'], { type: 'text/plain' });
          var a = el('a', { href: URL.createObjectURL(blob), download: 'Notfallkit-Tresor.txt' }); document.body.appendChild(a); a.click(); a.remove();
        } }, T('Als Textdatei speichern'))),
      el('label', { cls: 'check' }, ok, ' ', T('Ich habe das Notfallkit gedruckt oder sicher abgelegt.')),
      next));
  }

  // --- Entsperren / Wiederherstellen --------------------------------------------
  function viewUnlock() {
    var body = shell();
    var form = el('form', { cls: 'form', onsubmit: function (ev) {
      ev.preventDefault();
      var pw = form.elements.pw.value, a = S.state.account;
      busy(form, true);
      pwKey(pw, unb64(a.priv_pw.s), a.priv_pw.it || ITER).then(function (k) { return aesDec(k, a.priv_pw); })
        .then(function (pk) { return importPriv(pk); })
        .then(function (k) { S.priv = k; touch(); return load(); })
        .catch(function () { busy(form, false); form.elements.pw.value = ''; form.elements.pw.focus(); msg(T('Das Tresor-Passwort ist falsch.'), 'error'); });
    } },
      el('label', null, T('Tresor-Passwort'), el('input', { type: 'password', name: 'pw', autocomplete: 'current-password', required: true, autofocus: true })),
      el('div', { cls: 'btn-row' }, el('button', { cls: 'btn primary', type: 'submit' }, T('Entsperren')),
        el('button', { cls: 'btn-link', type: 'button', onclick: viewRecover }, T('Tresor-Passwort vergessen?'))),
      el('p', { cls: 'muted small', text: T('Prüfwert Ihres Schlüssels: {f}', { f: S.state.account.fingerprint }) }));
    body.appendChild(card(T('Tresor entsperren'), form));
    setTimeout(function () { form.elements.pw.focus(); }, 0);
  }

  function viewRecover() {
    var body = shell();
    var form = el('form', { cls: 'form', onsubmit: function (ev) {
      ev.preventDefault();
      var kit = kitDecode(form.elements.kit.value), pw = form.elements.pw.value;
      if (!kit) return msg(T('Das Notfallkit ist unvollständig. Bitte alle Zeichen eingeben.'), 'error');
      if (pw.length < 12) return msg(T('Das Tresor-Passwort muss mindestens 12 Zeichen lang sein.'), 'error');
      if (pw !== form.elements.pw2.value) return msg(T('Die Passwörter stimmen nicht überein.'), 'error');
      busy(form, true);
      var pkcs8;
      hkdfKey(kit, 'kp-vault-kit').then(function (k) { return aesDec(k, S.state.account.priv_kit); })
        .catch(function () { throw new Error(T('Das Notfallkit passt nicht zu Ihrem Tresor-Zugang.')); })
        .then(function (pk) { pkcs8 = pk; return encPrivWithPw(pk, pw); })
        .then(function (blob) { return api('account_password', { priv_pw: blob, recovered: 1 }); })
        .then(function () { return importPriv(pkcs8); })
        .then(function (k) { S.priv = k; touch(); S.note = T('Ihr neues Tresor-Passwort gilt ab sofort. Das Notfallkit bleibt gültig.'); return load(); })
        .catch(function (e) { busy(form, false); msg(e.message, 'error'); });
    } },
      el('p', null, T('Geben Sie Ihr persönliches Notfallkit ein (beginnt mit KP1-) und legen Sie ein neues Tresor-Passwort fest.')),
      el('label', null, T('Notfallkit'), el('input', { name: 'kit', required: true, autocomplete: 'off', spellcheck: 'false', cls: 'vault-mono', placeholder: 'KP1-XXXX-XXXX-…' })),
      pwField('pw', T('Neues Tresor-Passwort (mindestens 12 Zeichen)')),
      pwField('pw2', T('Neues Tresor-Passwort wiederholen')),
      el('div', { cls: 'btn-row' }, el('button', { cls: 'btn primary', type: 'submit' }, T('Neues Passwort setzen')),
        el('button', { cls: 'btn', type: 'button', onclick: render }, T('Abbrechen'))),
      el('p', { cls: 'muted small', text: T('Kein Notfallkit mehr? Dann kann ein Administrator Ihrer Firma Ihren Tresor-Zugang zurücksetzen. Danach richten Sie ihn neu ein und bekommen die Ordner erneut freigegeben.') }));
    body.appendChild(card(T('Tresor-Passwort vergessen'), form));
  }

  // --- Firma aktivieren ------------------------------------------------------------
  function viewInactive() {
    shell().appendChild(card(T('Tresor noch nicht aktiviert'), el('p', null, T('Ihr Tresor-Zugang ist eingerichtet. Sobald ein Administrator Ihrer Firma den Tresor aktiviert und Ihnen Ordner freigibt, sehen Sie hier die Zugangsdaten.'))));
  }

  function viewActivate() {
    var body = shell();
    body.appendChild(card(T('Tresor für die Firma aktivieren'), [
      el('p', null, T('Beim Aktivieren entsteht der Firmen-Notfallschlüssel. Jeder Ordner wird zusätzlich für ihn verschlüsselt. Mit dem ausgedruckten Firmen-Notfallkit kann ein Administrator alle Ordner wieder öffnen, auch wenn alle Mitglieder ihren Zugang verloren haben.')),
      el('div', { cls: 'alert warn' }, T('Das Firmen-Notfallkit ist der Generalschlüssel des Tresors. Drucken Sie es aus und bewahren Sie es bei der Geschäftsführung im Safe auf. Es wird nirgends gespeichert und kann nicht erneut angezeigt werden.')),
      el('button', { cls: 'btn primary', type: 'button', onclick: function (ev) {
        ev.target.disabled = true;
        var kit = rand(32), pair;
        newPair().then(function (p) { pair = p; return Promise.all([C.exportKey('pkcs8', p.privateKey), C.exportKey('spki', p.publicKey), hkdfKey(kit, 'kp-vault-company')]); })
          .then(function (r) { return Promise.all([aesEnc(r[2], new Uint8Array(r[0])), r[1]]); })
          .then(function (r) {
            showKit(T('Firmen-Notfallkit'), kitEncode(kit), T('Mit diesem Kit öffnet ein Administrator der Firma unter „Notfallzugriff“ alle Ordner des Tresors.'), COMPANY, function () {
              return api('company_activate', { public_key: b64(r[1]), priv_kit: r[0] }).then(function () { S.note = T('Der Tresor ist aktiviert. Legen Sie jetzt Ordner an und vergeben Sie Rechte.'); return load(); });
            });
          }).catch(function (e) { ev.target.disabled = false; msg(e.message, 'error'); });
      } }, T('Firmen-Notfallschlüssel erzeugen und Tresor aktivieren'))
    ]));
  }

  // --- Hauptansicht --------------------------------------------------------------
  function viewMain() {
    var st = S.state, body = shell();
    if (!S.folder || !st.folders.some(function (f) { return f.id === S.folder; })) S.folder = st.folders.length ? st.folders[0].id : null;
    var bar = el('div', { cls: 'vault-bar' },
      el('span', { cls: 'vault-status' }, '🔓 ', S.lockMin === 1 ? T('Entsperrt – sperrt nach 1 Minute ohne Aktivität') : T('Entsperrt – sperrt nach {n} Minuten ohne Aktivität', { n: S.lockMin })),
      el('span', { cls: 'btn-row' },
        ADMIN ? el('button', { cls: 'btn small', type: 'button', onclick: function () { viewFolderEdit(null); } }, T('+ Ordner')) : null,
        ADMIN ? el('button', { cls: 'btn small', type: 'button', onclick: viewEmergency }, T('Notfallzugriff')) : null,
        ADMIN ? el('button', { cls: 'btn small', type: 'button', onclick: viewSettings }, T('Einstellungen')) : null,
        el('button', { cls: 'btn small', type: 'button', onclick: viewAccount }, T('Mein Zugang')),
        el('button', { cls: 'btn small primary', type: 'button', onclick: function () { lock(T('Der Tresor ist gesperrt.')); } }, '🔒 ' + T('Sperren'))));
    body.appendChild(bar);
    if (!st.folders.length) {
      body.appendChild(card(null, el('p', { cls: 'muted' }, ADMIN ? T('Noch keine Ordner. Legen Sie mit „+ Ordner“ den ersten an, zum Beispiel „IT“, „Geschäftsführung“ oder „WLAN“.') : T('Für Sie ist noch kein Ordner freigegeben.'))));
      return;
    }
    var soon = addMonths(1), due = [];
    st.folders.forEach(function (f) { (f.entries || []).forEach(function (e) { if (e.expires && e.expires <= soon) due.push([f, e]); }); });
    if (due.length) {
      due.sort(function (a, b) { return a[1].expires < b[1].expires ? -1 : 1; });
      body.appendChild(el('div', { cls: 'card vault-due' }, el('h2', { text: T('Bald zu ändern') }), el('ul', { cls: 'wk-list' }, due.map(function (x) {
        var over = x[1].expires < today();
        return el('li', null, el('a', { href: '#', onclick: function (ev) { ev.preventDefault(); S.folder = x[0].id; viewEntry(x[0], x[1]); } }, x[1].title),
          ' ', el('span', { cls: 'tag' + (over ? ' warn' : '') }, (over ? T('überfällig seit {d}', { d: x[1].expires.split('-').reverse().join('.') }) : T('bis {d}', { d: x[1].expires.split('-').reverse().join('.') }))),
          el('span', { cls: 'muted small' }, ' · ' + x[0].name));
      }))));
    }
    var side = el('nav', { cls: 'vault-folders card' });
    st.folders.forEach(function (f) {
      side.appendChild(el('a', { href: '#', cls: f.id === S.folder ? 'active' : '', onclick: function (ev) { ev.preventDefault(); S.folder = f.id; render(); } },
        (S.raw[f.id] ? '📂 ' : '🔒 ') + f.name, el('span', { cls: 'muted small' }, ' ' + (f.perm ? f.entries.length : '–'))));
    });
    var f = st.folders.filter(function (x) { return x.id === S.folder; })[0];
    body.appendChild(el('div', { cls: 'vault-layout' }, side, el('section', { cls: 'vault-main' }, folderView(f))));
  }

  function folderView(f) {
    var wrap = el('div', { cls: 'card' });
    var head = el('div', { cls: 'wk-title-row' }, el('h2', { text: f.name }),
      el('div', { cls: 'btn-row' },
        f.perm >= 2 && S.raw[f.id] ? el('button', { cls: 'btn primary small', type: 'button', onclick: function () { viewEntryEdit(f, null, null); } }, T('+ Eintrag')) : null,
        f.perm >= 2 && S.raw[f.id] ? el('button', { cls: 'btn small', type: 'button', onclick: function () { viewImport(f); } }, T('Importieren')) : null,
        ADMIN && S.raw[f.id] && f.entries.length ? el('button', { cls: 'btn small', type: 'button', onclick: function () { viewExport(f); } }, T('Exportieren')) : null,
        ADMIN ? el('button', { cls: 'btn small', type: 'button', onclick: function () { viewFolderEdit(f); } }, T('Ordner bearbeiten')) : null));
    wrap.appendChild(head);
    if (!S.raw[f.id]) {
      wrap.appendChild(el('div', { cls: 'alert info' }, ADMIN && f.company_key
        ? T('Dieser Ordner ist für Ihr Konto noch nicht freigeschaltet. Sobald ein Mitglied den Tresor öffnet, geschieht das automatisch – oder Sie nutzen den Notfallzugriff.')
        : T('Dieser Ordner ist für Ihr Konto noch nicht freigeschaltet. Sobald jemand mit Zugriff den Tresor öffnet, geschieht das automatisch.')));
      return wrap;
    }
    if (f.members_without_account && f.members_without_account.length) {
      wrap.appendChild(el('p', { cls: 'muted small' }, T('Noch ohne Tresor-Zugang (bekommen den Ordner automatisch, sobald sie ihn einrichten): {n}', { n: f.members_without_account.join(', ') })));
    }
    var q = el('input', { type: 'search', placeholder: T('Einträge filtern …'), 'aria-label': T('Einträge filtern') });
    var tbody = el('tbody');
    function fill() {
      clear(tbody);
      var needle = q.value.trim().toLowerCase();
      var list = f.entries.filter(function (e) { return !needle || e.title.toLowerCase().indexOf(needle) !== -1; });
      list.forEach(function (e) {
        var over = e.expires && e.expires < new Date().toISOString().slice(0, 10);
        tbody.appendChild(el('tr', { cls: 'vault-row', tabindex: '0', onclick: function () { viewEntry(f, e); }, onkeydown: function (ev) { if (ev.key === 'Enter') viewEntry(f, e); } },
          el('td', null, el('strong', { text: e.title })),
          el('td', { text: (KINDS[e.kind] || KINDS.notiz).label }),
          el('td', null, e.expires ? el('span', { cls: over ? 'tag warn' : '' }, e.expires.split('-').reverse().join('.')) : '–'),
          el('td', { cls: 'muted small', text: (e.updated_at || '').slice(0, 16) + ' · ' + (e.updated_by_name || '') })));
      });
      if (!list.length) tbody.appendChild(el('tr', null, el('td', { colspan: '4', cls: 'muted', text: f.entries.length ? T('Keine Treffer.') : T('Noch keine Einträge in diesem Ordner.') })));
    }
    q.addEventListener('input', fill);
    wrap.appendChild(el('div', { cls: 'toolbar' }, q));
    wrap.appendChild(el('table', { cls: 'table' }, el('thead', null, el('tr', null, el('th', { text: T('Titel') }), el('th', { text: T('Art') }), el('th', { text: T('Ändern bis') }), el('th', { text: T('Geändert') }))), tbody));
    fill();
    return wrap;
  }

  /** Eintrag abrufen (wird protokolliert) und entschlüsseln */
  function fetchEntry(f, e) {
    return api('entry_get', { id: e.id }).then(function (r) { return rawAes(S.raw[f.id]).then(function (k) { return aesDec(k, r.data); }); })
      .then(function (pt) { return JSON.parse(td.decode(pt)); });
  }

  function copy(f, e, field, value) {
    if (!navigator.clipboard) return msg(T('Kopieren wird von diesem Browser nicht unterstützt.'), 'error');
    navigator.clipboard.writeText(value).then(function () {
      msg(T('Kopiert. Die Zwischenablage wird in 30 Sekunden geleert.'), 'success');
      api('log', { id: e.id, field: field }).catch(function () {});
      setTimeout(function () { if (navigator.clipboard) navigator.clipboard.writeText('').catch(function () {}); }, 30000);
    }).catch(function () { msg(T('Kopieren nicht möglich.'), 'error'); });
  }

  function viewEntry(f, e) {
    var body = shell();
    var kind = KINDS[e.kind] || KINDS.notiz;
    var vals = null;
    var list = el('dl', { cls: 'vault-fields' });
    function draw(show) {
      clear(list);
      kind.fields.forEach(function (fd) {
        var key = fd[0], secret = (fd[2] || '').indexOf('s') !== -1, multi = (fd[2] || '').indexOf('m') !== -1;
        var v = vals ? (vals[key] || '') : '';
        if (vals && !v) return;
        var shown = vals && (show || !secret) ? v : '••••••••';
        list.appendChild(el('dt', { text: fd[1] }));
        list.appendChild(el('dd', null, el(multi ? 'pre' : 'span', { cls: 'vault-val' + (secret ? ' vault-mono' : ''), text: shown }),
          vals ? el('button', { cls: 'btn small', type: 'button', onclick: function () { copy(f, e, key, v); } }, T('Kopieren')) : null,
          vals && key === 'url' && /^https?:\/\//i.test(v) ? el('a', { cls: 'btn small', href: v, target: '_blank', rel: 'noopener noreferrer' }, T('Öffnen')) : null));
      });
    }
    var totpBox = el('div', { cls: 'vault-totp', hidden: true });
    function startTotp() {
      var o = vals && vals.totp ? totpParams(vals.totp) : null;
      if (!o) return;
      var codeEl = el('strong', { cls: 'vault-mono vault-code' }), left = el('span', { cls: 'muted small' }), cur = '';
      clear(totpBox).appendChild(el('span', null, T('Aktueller 2FA-Code:') + ' '));
      totpBox.appendChild(codeEl); totpBox.appendChild(left);
      totpBox.appendChild(el('button', { cls: 'btn small', type: 'button', onclick: function () { copy(f, e, 'totp_code', cur); } }, T('Kopieren')));
      totpBox.hidden = false;
      (function tick() {
        if (!totpBox.isConnected || !S.priv) return;
        totpNow(o).then(function (r) { cur = r.code; codeEl.textContent = r.code.slice(0, 3) + ' ' + r.code.slice(3); left.textContent = ' ' + T('noch {n} s', { n: r.left }); })
          .catch(function () { codeEl.textContent = '–'; });
        setTimeout(tick, 1000);
      })();
    }
    var reveal = el('button', { cls: 'btn primary', type: 'button', onclick: function () {
      if (vals) { secretShown = !secretShown; draw(secretShown); reveal.textContent = secretShown ? T('Verbergen') : T('Geheime Felder zeigen'); return; }
      reveal.disabled = true;
      fetchEntry(f, e).then(function (d) { vals = d; secretShown = true; draw(true); startTotp(); reveal.disabled = false; reveal.textContent = T('Verbergen'); })
        .catch(function (er) { reveal.disabled = false; msg(er.message || T('Der Eintrag ließ sich nicht entschlüsseln.'), 'error'); });
    } }, T('Anzeigen'));
    var secretShown = false;
    draw(false);
    var logBox = el('div');
    body.appendChild(el('div', { cls: 'card vault-card' },
      el('div', { cls: 'wk-title-row' }, el('h2', { text: e.title }), el('span', { cls: 'tag' }, kind.label)),
      el('p', { cls: 'muted small', text: T('Ordner „{n}“ · geändert {d} von {u}', { n: f.name, d: (e.updated_at || '').slice(0, 16), u: e.updated_by_name || '–' }) }),
      list,
      totpBox,
      el('p', { cls: 'muted small', text: T('Jedes Anzeigen und Kopieren wird protokolliert.') + (e.change_months ? ' ' + T('Passwortwechsel alle {n} Monate.', { n: e.change_months }) : '') }),
      el('div', { cls: 'btn-row' }, reveal,
        f.perm >= 2 ? el('button', { cls: 'btn', type: 'button', onclick: function () {
          (vals ? Promise.resolve(vals) : fetchEntry(f, e)).then(function (d) { viewEntryEdit(f, e, d); }).catch(function (er) { msg(er.message, 'error'); });
        } }, T('Bearbeiten')) : null,
        f.perm >= 2 ? el('button', { cls: 'btn', type: 'button', onclick: function () {
          api('entry_log', { id: e.id }).then(function (r) {
            clear(logBox).appendChild(el('table', { cls: 'table' }, el('tbody', null, r.log.map(function (l) {
              return el('tr', null, el('td', { cls: 'nowrap', text: l.created_at.slice(0, 16) }), el('td', { text: l.name || '–' }), el('td', { text: T(LOG_LABELS[l.action] || l.action) + (l.action === 'copy' && l.detail ? ' (' + l.detail + ')' : '') }));
            }))));
          }).catch(function (er) { msg(er.message, 'error'); });
        } }, T('Protokoll')) : null,
        f.perm >= 2 ? el('button', { cls: 'btn danger', type: 'button', onclick: function () {
          if (!window.confirm(T('Diesen Eintrag endgültig löschen?'))) return;
          api('entry_delete', { id: e.id }).then(function () { S.note = T('Der Eintrag wurde gelöscht.'); return load(); }).catch(function (er) { msg(er.message, 'error'); });
        } }, T('Löschen')) : null,
        el('button', { cls: 'btn', type: 'button', onclick: render }, T('Zurück'))),
      logBox));
  }
  var LOG_LABELS = { view: 'angezeigt', copy: 'kopiert', create: 'angelegt', update: 'geändert', 'delete': 'gelöscht', rotate_read: 'beim Schlüsselwechsel neu verschlüsselt', export_read: 'exportiert' };

  function viewEntryEdit(f, e, vals) {
    var body = shell();
    vals = vals || {};
    var kindSel = el('select', { name: 'kind' }, Object.keys(KINDS).map(function (k) { return el('option', { value: k, selected: (e ? e.kind : 'login') === k }, KINDS[k].label); }));
    var folders = S.state.folders.filter(function (x) { return x.perm >= 2 && S.raw[x.id]; });
    var folderSel = el('select', { name: 'folder' }, folders.map(function (x) { return el('option', { value: String(x.id), selected: x.id === f.id }, x.name); }));
    var fieldsBox = el('div');
    function drawFields() {
      clear(fieldsBox);
      KINDS[kindSel.value].fields.forEach(function (fd) {
        var key = fd[0], secret = (fd[2] || '').indexOf('s') !== -1, multi = (fd[2] || '').indexOf('m') !== -1;
        var input = multi ? el('textarea', { name: 'f_' + key, rows: '4' }) : el('input', { name: 'f_' + key, type: secret ? 'password' : 'text', autocomplete: 'off', spellcheck: 'false' });
        input.value = vals[key] || '';
        var extra = null, gen = null, panel = null;
        if (secret && !multi) extra = el('button', { cls: 'btn small', type: 'button', onclick: function () { input.type = input.type === 'password' ? 'text' : 'password'; } }, T('Zeigen'));
        if (key === 'password') {
          panel = genPanel(function (v) { input.value = v; input.type = 'text'; input.dispatchEvent(new Event('input')); });
          gen = el('button', { cls: 'btn small', type: 'button', onclick: function () { panel.hidden = !panel.hidden; } }, T('Generieren'));
        }
        fieldsBox.appendChild(el('label', null, fd[1], el('span', { cls: 'vault-input' }, input, extra, gen)));
        if (panel) fieldsBox.appendChild(panel);
      });
    }
    kindSel.addEventListener('change', function () {
      // Werte gleichnamiger Felder behalten
      Array.prototype.forEach.call(fieldsBox.querySelectorAll('[name^=f_]'), function (i) { vals[i.name.slice(2)] = i.value; });
      drawFields();
    });
    drawFields();
    var origPw = vals.password || '', expTouched = false;
    var cmSel = el('select', { name: 'change_months' }, [0, 3, 6, 12, 24].map(function (m) {
      return el('option', { value: String(m), selected: (e ? +e.change_months : 0) === m }, m ? T('alle {n} Monate', { n: m }) : T('nicht regelmäßig'));
    }));
    var form = el('form', { cls: 'form', onsubmit: function (ev) {
      ev.preventDefault();
      var data = {};
      KINDS[kindSel.value].fields.forEach(function (fd) { var v = form.elements['f_' + fd[0]].value; if (v !== '') data[fd[0]] = v; });
      // Turnus: neues Passwort (oder neuer Eintrag) → „Ändern bis“ neu berechnen, sofern nicht von Hand gesetzt
      var cm = +cmSel.value;
      if (cm && !expTouched && (!e || (data.password || '') !== origPw || !form.elements.expires.value)) form.elements.expires.value = addMonths(cm);
      if (!cm && !expTouched && e && +e.change_months && (data.password || '') !== origPw) form.elements.expires.value = '';
      var target = S.state.folders.filter(function (x) { return x.id === +folderSel.value; })[0];
      busy(form, true);
      rawAes(S.raw[target.id]).then(function (k) { return aesEnc(k, te.encode(JSON.stringify(data))); })
        .then(function (blob) {
          return api('entry_save', { id: e ? e.id : 0, folder_id: target.id, key_version: target.key_version, title: form.elements.title.value, kind: kindSel.value,
            expires: form.elements.expires.value, change_months: cm, data: blob });
        })
        .then(function () { S.folder = target.id; S.note = T('Der Eintrag wurde verschlüsselt gespeichert.'); return load(); })
        .catch(function (er) { busy(form, false); msg(er.message, 'error'); });
    } },
      el('div', { cls: 'grid2' },
        el('label', null, T('Titel'), el('input', { name: 'title', required: true, maxlength: '190', value: e ? e.title : '' })),
        el('label', null, T('Art'), kindSel)),
      fieldsBox,
      el('div', { cls: 'grid2' },
        el('label', null, T('Ordner'), folderSel),
        el('label', null, T('Passwort wechseln'), cmSel),
        el('label', null, T('Ändern bis (optional)'), el('input', { type: 'date', name: 'expires', value: e && e.expires ? e.expires : '', oninput: function () { expTouched = true; } }))),
      el('p', { cls: 'muted small', text: T('Mit Turnus wird „Ändern bis“ beim Speichern eines neuen Passworts automatisch neu gesetzt. 14 Tage vorher bekommen alle mit Bearbeiten-Recht eine E-Mail – nur mit Titel und Datum.') }),
      el('p', { cls: 'muted small', text: T('Titel, Art und „Ändern bis“ sind für die Liste unverschlüsselt gespeichert. Alle anderen Felder werden in Ihrem Browser verschlüsselt.') }),
      el('div', { cls: 'btn-row' }, el('button', { cls: 'btn primary', type: 'submit' }, T('Speichern')), el('button', { cls: 'btn', type: 'button', onclick: render }, T('Abbrechen'))));
    body.appendChild(card(e ? T('Eintrag bearbeiten') : T('Neuer Eintrag'), form));
  }

  /** Generator-Panel unter einem Passwortfeld */
  function genPanel(apply) {
    var out = el('input', { type: 'text', readonly: true, cls: 'vault-mono', 'aria-label': T('Vorschlag') });
    var len = el('input', { type: 'range', min: '8', max: '64', value: '20' }), lenLbl = el('span', { text: '20' });
    var mode = el('select', null, el('option', { value: 'pw' }, T('Zufällige Zeichen')), el('option', { value: 'pp' }, T('Passphrase aus Wörtern')));
    var words = el('input', { type: 'number', min: '4', max: '10', value: '5', cls: 'narrow-input' });
    var opts = {};
    var checks = [['lower', T('Kleinbuchstaben')], ['upper', T('Großbuchstaben')], ['digits', T('Ziffern')], ['symbols', T('Sonderzeichen')], ['ambiguous', T('ohne verwechselbare Zeichen (l, I, O, 0, 1)')]].map(function (c) {
      var cb = el('input', { type: 'checkbox', checked: true }); opts[c[0]] = cb;
      cb.addEventListener('change', make);
      return el('label', { cls: 'check' }, cb, ' ', c[1]);
    });
    var pwOpts = el('div', { cls: 'vault-gen-opts' }, el('label', null, T('Länge'), ' ', len, ' ', lenLbl), checks);
    var ppOpts = el('div', { cls: 'vault-gen-opts', hidden: true }, el('label', null, T('Anzahl Wörter'), ' ', words));
    function make() {
      lenLbl.textContent = len.value;
      pwOpts.hidden = mode.value !== 'pw'; ppOpts.hidden = mode.value !== 'pp';
      out.value = mode.value === 'pp' ? genPassphrase(Math.max(4, Math.min(10, +words.value || 5)))
        : genPassword({ length: +len.value, lower: opts.lower.checked, upper: opts.upper.checked, digits: opts.digits.checked, symbols: opts.symbols.checked, ambiguous: opts.ambiguous.checked });
    }
    [len, mode, words].forEach(function (x) { x.addEventListener('input', make); x.addEventListener('change', make); });
    var panel = el('div', { cls: 'vault-gen', hidden: true },
      el('div', { cls: 'vault-input' }, out, el('button', { cls: 'btn small', type: 'button', onclick: make }, T('Neu')),
        el('button', { cls: 'btn small primary', type: 'button', onclick: function () { apply(out.value); panel.hidden = true; } }, T('Übernehmen'))),
      el('label', null, T('Art'), ' ', mode), pwOpts, ppOpts);
    make();
    return panel;
  }

  // --- Import (Bitwarden, KeePass, KeePassXC, 1Password, Tresor-Exportdatei) -------------
  function viewImport(f) {
    var body = shell(), parsed = null;
    var file = el('input', { type: 'file', accept: '.csv,.txt,.kpvault,.json,text/csv' });
    var pwWrap = el('label', { hidden: true }, T('Passwort der Exportdatei'), el('input', { type: 'password', name: 'xpw', autocomplete: 'off' }));
    var preview = el('div');
    var go = el('button', { cls: 'btn primary', type: 'button', disabled: true }, T('Importieren'));
    var rawText = '';
    function show() {
      clear(preview);
      if (!parsed || !parsed.items.length) { preview.appendChild(el('p', { cls: 'muted', text: parsed ? T('In der Datei wurden keine Einträge gefunden.') : '' })); go.disabled = true; return; }
      preview.appendChild(el('p', null, T('Erkannt: {s} · {n} Einträge', { s: parsed.source, n: parsed.items.length })));
      preview.appendChild(el('table', { cls: 'table' }, el('thead', null, el('tr', null, el('th', { text: T('Titel') }), el('th', { text: T('Art') }), el('th', { text: T('Felder') }), el('th', { text: T('Gruppe') }))),
        el('tbody', null, parsed.items.slice(0, 200).map(function (it) {
          return el('tr', null, el('td', { text: it.title }), el('td', { text: (KINDS[it.kind] || KINDS.notiz).label }),
            el('td', { cls: 'muted small', text: Object.keys(it.fields).map(function (k) { var fd = (KINDS[it.kind] || KINDS.notiz).fields.filter(function (x) { return x[0] === k; })[0]; return fd ? fd[1] : k; }).join(', ') }),
            el('td', { cls: 'muted small', text: it.group || '' }));
        }))));
      if (parsed.items.length > 200) preview.appendChild(el('p', { cls: 'muted small', text: T('… und {n} weitere', { n: parsed.items.length - 200 }) }));
      go.disabled = false;
    }
    var lastPw = null;
    function parse(fromFile) {
      var j = null;
      try { j = JSON.parse(rawText); } catch (x) { j = null; }
      if (j && j.format === 'kp-vault-export') {
        pwWrap.hidden = false;
        if (fromFile) { parsed = null; lastPw = null; show(); }
        var pw = pwWrap.querySelector('input').value;
        // gleiches Passwort nicht erneut prüfen (das Feld verlässt man z. B. beim Klick auf „Importieren“)
        if (!pw || pw === lastPw) return;
        lastPw = pw;
        go.disabled = true;
        pwKey(pw, unb64(j.s), j.it || ITER).then(function (k) { return aesDec(k, j); })
          .then(function (pt) { var d = JSON.parse(td.decode(pt)); msg(''); parsed = { source: T('Tresor-Exportdatei'), items: d.items || [] }; show(); })
          .catch(function () { parsed = null; msg(T('Das Passwort der Exportdatei ist falsch.'), 'error'); show(); });
        return;
      }
      parsed = null;
      pwWrap.hidden = true;
      parsed = csvItems(parseCSV(rawText));
      show();
    }
    file.addEventListener('change', function () {
      msg('');
      var fl = file.files[0];
      if (!fl) return;
      if (fl.size > 20 * 1024 * 1024) return msg(T('Die Datei ist zu groß.'), 'error');
      var rd = new FileReader();
      rd.onload = function () { rawText = String(rd.result || ''); parse(true); };
      rd.readAsText(fl);
    });
    pwWrap.querySelector('input').addEventListener('change', function () { parse(false); });
    pwWrap.querySelector('input').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); parse(false); } });
    go.addEventListener('click', function () {
      if (!parsed || !parsed.items.length) return;
      go.disabled = true; go.textContent = T('Bitte warten …');
      var key, done = 0;
      rawAes(S.raw[f.id]).then(function (k) {
        key = k;
        return Promise.all(parsed.items.map(function (it) {
          var fields = {};
          Object.keys(it.fields || {}).forEach(function (x) { if (it.fields[x] !== '') fields[x] = String(it.fields[x]); });
          return aesEnc(key, te.encode(JSON.stringify(fields))).then(function (blob) {
            return { title: it.title, kind: it.kind, expires: it.expires || '', change_months: it.change_months || 0, data: blob };
          });
        }));
      }).then(function (items) {
        var chain = Promise.resolve();
        for (var i = 0; i < items.length; i += 500) (function (part) {
          chain = chain.then(function () { return api('entries_import', { folder_id: f.id, key_version: f.key_version, source: parsed.source, items: part }); })
            .then(function (r) { done += r.imported; });
        })(items.slice(i, i + 500));
        return chain;
      }).then(function () {
        rawText = ''; parsed = null; file.value = '';
        S.note = T('{n} Einträge wurden verschlüsselt importiert. Löschen Sie die Exportdatei jetzt von Ihrem Rechner.', { n: done });
        return load();
      }).catch(function (er) { go.disabled = false; go.textContent = T('Importieren'); msg(er.message + (done ? ' ' + T('({n} Einträge wurden bereits importiert.)', { n: done }) : ''), 'error'); });
    });
    body.appendChild(card(T('In Ordner „{n}“ importieren', { n: f.name }), [
      el('p', null, T('Unterstützt: CSV-Export aus Bitwarden, KeePass, KeePassXC und 1Password sowie verschlüsselte Exportdateien dieses Tresors. Die Datei wird nur in Ihrem Browser gelesen und verschlüsselt; unverschlüsselt verlässt nichts Ihren Rechner.')),
      el('label', null, T('Datei'), file), pwWrap, preview,
      el('div', { cls: 'btn-row' }, go, el('button', { cls: 'btn', type: 'button', onclick: function () { rawText = ''; parsed = null; render(); } }, T('Abbrechen')))
    ]));
  }

  // --- Export (nur Firmen-Admins) -------------------------------------------------------
  function viewExport(f) {
    var body = shell();
    var form = el('form', { cls: 'form', onsubmit: function (ev) {
      ev.preventDefault();
      var fmt = form.elements.fmt.value, pw = form.elements.xpw.value;
      if (fmt === 'file') {
        if (pw.length < 12) return msg(T('Das Passwort der Exportdatei muss mindestens 12 Zeichen lang sein.'), 'error');
        if (pw !== form.elements.xpw2.value) return msg(T('Die Passwörter stimmen nicht überein.'), 'error');
      } else if (!form.elements.ok.checked) return msg(T('Bitte bestätigen Sie, dass die CSV-Datei unverschlüsselt ist.'), 'error');
      busy(form, true);
      var key, items = [];
      rawAes(S.raw[f.id]).then(function (k) {
        key = k;
        return f.entries.reduce(function (chain, e) {
          return chain.then(function () { return api('entry_get', { id: e.id, purpose: 'export' }); })
            .then(function (r) { return aesDec(key, r.data); })
            .then(function (pt) { items.push({ title: e.title, kind: e.kind, expires: e.expires || '', change_months: +e.change_months || 0, fields: JSON.parse(td.decode(pt)) }); });
        }, Promise.resolve());
      }).then(function () {
        var name = 'Tresor-' + f.name.replace(/[^A-Za-z0-9._-]+/g, '_') + '-' + today();
        if (fmt === 'file') {
          var salt = rand(16);
          return pwKey(pw, salt, ITER).then(function (k) { return aesEnc(k, te.encode(JSON.stringify({ folder: f.name, items: items }))); }).then(function (b) {
            download(name + '.kpvault', JSON.stringify({ format: 'kp-vault-export', v: 1, s: b64(salt), it: ITER, i: b.i, c: b.c }), 'application/json');
          });
        }
        var q = function (v) { v = String(v || ''); return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        var lines = ['folder,favorite,type,name,notes,fields,reprompt,login_uri,login_username,login_password,login_totp'];
        items.forEach(function (it) {
          var x = it.fields, notes = x.notes || '', extra = [];
          ['ssid', 'security', 'guest', 'address', 'customer_no', 'host', 'port'].forEach(function (k) { if (x[k]) extra.push(k + ': ' + x[k]); });
          lines.push([f.name, '', it.kind === 'notiz' ? 'note' : 'login', it.title, notes, extra.join('\n'), '0', x.url || x.address || '', x.username || '', x.password || '', x.totp || ''].map(q).join(','));
        });
        download(name + '.csv', '﻿' + lines.join('\r\n') + '\r\n', 'text/csv');
      }).then(function () { return api('log_export', { folder_id: f.id, format: fmt, count: items.length }); })
        .then(function () { busy(form, false); items = []; msg(fmt === 'csv' ? T('Export erstellt. Die CSV-Datei ist unverschlüsselt – löschen Sie sie, sobald Sie sie nicht mehr brauchen.') : T('Export erstellt. Die Datei ist mit Ihrem Export-Passwort verschlüsselt.'), 'success'); })
        .catch(function (er) { busy(form, false); msg(er.message, 'error'); });
    } },
      el('label', { cls: 'check' }, el('input', { type: 'radio', name: 'fmt', value: 'file', checked: true }), ' ', T('Verschlüsselte Exportdatei (.kpvault) – lässt sich in diesen Tresor wieder importieren')),
      el('label', null, T('Export-Passwort (mindestens 12 Zeichen)'), el('input', { type: 'password', name: 'xpw', autocomplete: 'new-password' })),
      el('label', null, T('Export-Passwort wiederholen'), el('input', { type: 'password', name: 'xpw2', autocomplete: 'new-password' })),
      el('label', { cls: 'check' }, el('input', { type: 'radio', name: 'fmt', value: 'csv' }), ' ', T('CSV unverschlüsselt (Bitwarden-Format, z. B. für einen Umzug)')),
      el('label', { cls: 'check' }, el('input', { type: 'checkbox', name: 'ok' }), ' ', T('Mir ist klar, dass die CSV-Datei alle Passwörter im Klartext enthält.')),
      el('p', { cls: 'muted small', text: T('Der Export wird im Browser erzeugt. Jeder gelesene Eintrag und der Export selbst stehen im Protokoll.') }),
      el('div', { cls: 'btn-row' }, el('button', { cls: 'btn primary', type: 'submit' }, T('Exportieren')), el('button', { cls: 'btn', type: 'button', onclick: render }, T('Zurück'))));
    body.appendChild(card(T('Ordner „{n}“ exportieren', { n: f.name }), form));
  }

  // --- Ordner und Rechte (Firmen-Admins) -------------------------------------------
  function viewFolderEdit(f) {
    var st = S.state, body = shell();
    var acl = f && f.acl ? f.acl : [];
    function permOf(kind, id) {
      var a = acl.filter(function (x) { return kind === 'd' ? +x.department_id === id : +x.user_id === id; })[0];
      return a ? +a.perm : 0;
    }
    function permSel(name, cur) {
      return el('select', { name: name }, [[0, T('kein Zugriff')], [1, T('ansehen')], [2, T('bearbeiten')]].map(function (o) { return el('option', { value: String(o[0]), selected: cur === o[0] }, o[1]); }));
    }
    var depRows = (st.departments || []).map(function (d) { return el('tr', null, el('td', { text: d.name }), el('td', null, permSel('d_' + d.id, permOf('d', +d.id)))); });
    var userRows = (st.users || []).filter(function (u) { return u.id !== ME; }).map(function (u) {
      return el('tr', null, el('td', null, u.name + (u.staff ? ' · ' + T('IT-Dienstleister') : ''), el('br'), el('span', { cls: 'muted small', text: u.email + (u.has_account ? '' : ' · ' + T('noch ohne Tresor-Zugang')) })),
        el('td', null, permSel('u_' + u.id, permOf('u', u.id))));
    });
    var form = el('form', { cls: 'form', onsubmit: function (ev) {
      ev.preventDefault();
      var list = [];
      (st.departments || []).forEach(function (d) { var v = +form.elements['d_' + d.id].value; if (v) list.push({ department_id: +d.id, perm: v }); });
      (st.users || []).forEach(function (u) { if (u.id === ME) return; var v = +form.elements['u_' + u.id].value; if (v) list.push({ user_id: u.id, perm: v }); });
      var p = { id: f ? f.id : 0, name: form.elements.name.value, acl: list };
      busy(form, true);
      var prep = Promise.resolve();
      if (!f) {
        var raw = rand(32);
        prep = Promise.all([wrapFor(st.account.public_key, raw), wrapFor(st.company.public_key, raw)]).then(function (w) { p.keys = [{ user_id: ME, wrapped: w[0] }, { user_id: 0, wrapped: w[1] }]; });
      }
      prep.then(function () { return api('folder_save', p); })
        .then(function (r) { S.folder = r.id; S.note = T('Der Ordner wurde gespeichert. Neue Mitglieder werden automatisch freigeschaltet.'); return load(); })
        .catch(function (er) { busy(form, false); msg(er.message, 'error'); });
    } },
      el('label', null, T('Name'), el('input', { name: 'name', required: true, maxlength: '120', value: f ? f.name : '' })),
      el('h3', { text: T('Rechte je Gruppe') }),
      depRows.length ? el('table', { cls: 'table' }, el('tbody', null, depRows)) : el('p', { cls: 'muted small', text: T('Keine Abteilungen angelegt.') }),
      el('h3', { text: T('Rechte für einzelne Personen') }),
      el('p', { cls: 'muted small', text: T('Ihr IT-Dienstleister bekommt nur Zugriff, wenn Sie ihn hier ausdrücklich eintragen. Administratoren der Firma haben immer Zugriff.') }),
      el('table', { cls: 'table' }, el('tbody', null, userRows)),
      el('p', { cls: 'muted small', text: T('Wer ein Recht verliert, verliert sofort den Zugriff. Beim nächsten Öffnen durch eine Person mit Bearbeiten-Recht wird der Ordner automatisch neu verschlüsselt.') }),
      el('div', { cls: 'btn-row' }, el('button', { cls: 'btn primary', type: 'submit' }, T('Speichern')), el('button', { cls: 'btn', type: 'button', onclick: render }, T('Abbrechen')),
        f ? el('button', { cls: 'btn danger', type: 'button', onclick: function () {
          var c = window.prompt(T('Der Ordner wird mit allen Einträgen endgültig gelöscht. Zum Bestätigen den Namen des Ordners eingeben:'));
          if (c === null) return;
          if (c.trim() !== f.name) return msg(T('Der Name stimmt nicht. Nichts wurde gelöscht.'), 'error');
          api('folder_delete', { id: f.id }).then(function () { S.folder = null; S.note = T('Der Ordner wurde gelöscht.'); return load(); }).catch(function (er) { msg(er.message, 'error'); });
        } }, T('Ordner löschen')) : null));
    body.appendChild(card(f ? T('Ordner bearbeiten') : T('Neuer Ordner'), form));
  }

  // --- Notfallzugriff mit dem Firmen-Notfallkit (Firmen-Admins) ---------------------
  function viewEmergency() {
    var body = shell();
    var form = el('form', { cls: 'form', onsubmit: function (ev) {
      ev.preventDefault();
      var kit = kitDecode(form.elements.kit.value);
      if (!kit) return msg(T('Das Notfallkit ist unvollständig. Bitte alle Zeichen eingeben.'), 'error');
      busy(form, true);
      var companyPriv, n = 0;
      api('company_key').then(function (r) { return hkdfKey(kit, 'kp-vault-company').then(function (k) { return aesDec(k, r.priv_kit); }); })
        .catch(function (er) { throw new Error(er.message && er.message.indexOf('Recht') !== -1 ? er.message : T('Das Firmen-Notfallkit passt nicht.')); })
        .then(function (pk) { return importPriv(pk); })
        .then(function (k) {
          companyPriv = k;
          return Promise.all(S.state.folders.filter(function (f) { return !f.my_key && f.company_key; }).map(function (f) {
            return unwrap(companyPriv, f.company_key).then(function (raw) { return wrapFor(S.state.account.public_key, raw); })
              .then(function (w) { n++; return api('emergency_take', { folder_id: f.id, key_version: f.key_version, wrapped: w }); });
          }));
        })
        .then(function () { companyPriv = null; S.note = T('Notfallzugriff: {n} Ordner wurden für Ihr Konto freigeschaltet.', { n: n }); return load(); })
        .catch(function (er) { busy(form, false); msg(er.message, 'error'); });
    } },
      el('p', null, T('Mit dem Firmen-Notfallkit schalten Sie alle Ordner für Ihr eigenes Konto frei, auf die Sie noch keinen Zugriff haben – etwa wenn alle Mitglieder eines Ordners ihren Zugang verloren haben. Der Vorgang wird protokolliert.')),
      el('label', null, T('Firmen-Notfallkit'), el('input', { name: 'kit', required: true, autocomplete: 'off', spellcheck: 'false', cls: 'vault-mono', placeholder: 'KP1-XXXX-XXXX-…' })),
      el('div', { cls: 'btn-row' }, el('button', { cls: 'btn primary', type: 'submit' }, T('Ordner freischalten')), el('button', { cls: 'btn', type: 'button', onclick: render }, T('Abbrechen'))));
    body.appendChild(card(T('Notfallzugriff'), form));
  }

  // --- Einstellungen (Firmen-Admins) ---------------------------------------------------
  function viewSettings() {
    var st = S.state, body = shell();
    var form = el('form', { cls: 'form', onsubmit: function (ev) {
      ev.preventDefault();
      busy(form, true);
      api('settings', { lock_minutes: +form.elements.lock.value }).then(function () { S.note = T('Die Einstellungen wurden gespeichert.'); return load(); })
        .catch(function (er) { busy(form, false); msg(er.message, 'error'); });
    } },
      el('label', null, T('Automatisch sperren nach (Minuten ohne Aktivität)'), el('input', { type: 'number', name: 'lock', min: '1', max: '240', cls: 'narrow-input', value: String(S.lockMin) })),
      el('p', { cls: 'muted small', text: T('Prüfwert des Firmen-Notfallschlüssels: {f}', { f: st.company.fingerprint }) }),
      el('div', { cls: 'btn-row' }, el('button', { cls: 'btn primary', type: 'submit' }, T('Speichern')), el('button', { cls: 'btn', type: 'button', onclick: render }, T('Zurück'))));
    var resets = el('tbody');
    (st.users || []).filter(function (u) { return u.has_account && !u.staff && u.id !== ME; }).forEach(function (u) {
      resets.appendChild(el('tr', null, el('td', { text: u.name }), el('td', null, el('button', { cls: 'btn small danger', type: 'button', onclick: function () {
        if (!window.confirm(T('Tresor-Zugang von {n} zurücksetzen? Die Person muss ihn neu einrichten und bekommt die Ordner danach automatisch wieder freigeschaltet, sobald ein Mitglied den Tresor öffnet.', { n: u.name }))) return;
        api('account_reset', { user_id: u.id }).then(function () { S.note = T('Der Tresor-Zugang wurde zurückgesetzt.'); return load(); }).catch(function (er) { msg(er.message, 'error'); });
      } }, T('Zugang zurücksetzen')))));
    });
    body.appendChild(card(T('Tresor-Einstellungen'), form));
    body.appendChild(card(T('Tresor-Zugang zurücksetzen'), [el('p', { cls: 'muted small', text: T('Nur nötig, wenn jemand Tresor-Passwort und Notfallkit verloren hat.') }),
      resets.childNodes.length ? el('table', { cls: 'table' }, resets) : el('p', { cls: 'muted', text: T('Keine weiteren Tresor-Zugänge.') })]));
  }

  // --- Eigener Zugang: Passwort ändern, neues Notfallkit --------------------------------
  function viewAccount() {
    var body = shell();
    var form = el('form', { cls: 'form', onsubmit: function (ev) {
      ev.preventDefault();
      var a = S.state.account, old = form.elements.old.value, pw = form.elements.pw.value;
      if (pw.length < 12) return msg(T('Das Tresor-Passwort muss mindestens 12 Zeichen lang sein.'), 'error');
      if (pw !== form.elements.pw2.value) return msg(T('Die Passwörter stimmen nicht überein.'), 'error');
      busy(form, true);
      pwKey(old, unb64(a.priv_pw.s), a.priv_pw.it || ITER).then(function (k) { return aesDec(k, a.priv_pw); })
        .catch(function () { throw new Error(T('Das bisherige Tresor-Passwort ist falsch.')); })
        .then(function (pk) { return encPrivWithPw(pk, pw); })
        .then(function (blob) { return api('account_password', { priv_pw: blob }); })
        .then(function () { S.note = T('Ihr Tresor-Passwort wurde geändert.'); return load(); })
        .catch(function (er) { busy(form, false); msg(er.message, 'error'); });
    } },
      el('label', null, T('Bisheriges Tresor-Passwort'), el('input', { type: 'password', name: 'old', autocomplete: 'current-password', required: true })),
      pwField('pw', T('Neues Tresor-Passwort (mindestens 12 Zeichen)')),
      pwField('pw2', T('Neues Tresor-Passwort wiederholen')),
      el('p', { cls: 'muted small', text: T('Prüfwert Ihres Schlüssels: {f}', { f: S.state.account.fingerprint }) }),
      el('div', { cls: 'btn-row' }, el('button', { cls: 'btn primary', type: 'submit' }, T('Passwort ändern')), el('button', { cls: 'btn', type: 'button', onclick: render }, T('Zurück'))));
    body.appendChild(card(T('Mein Tresor-Zugang'), form));
  }

  load();
})();
