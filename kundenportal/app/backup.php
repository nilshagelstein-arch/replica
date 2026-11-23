<?php
/**
 * Datensicherung: komplette Datenbank als SQL-Datei plus Schlüsseldatei und
 * Konfiguration in einem ZIP-Archiv unter app/data/backups/ (per .htaccess gesperrt).
 *
 * Manuell über „Datensicherung“ im Portal, automatisch täglich über cron.php.
 */

const BACKUP_KEEP_DEFAULT = 14;

function backup_dir(): string
{
    $dir = APP_DIR . '/data/backups';
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    // zusätzlicher Schutz, falls app/.htaccess einmal fehlt
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', '<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
');
    }
    if (!is_file($dir . '/index.html')) file_put_contents($dir . '/index.html', '');
    return $dir;
}

/** Liste der Sicherungen, neueste zuerst */
function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/backup-*.{zip,gz}', GLOB_BRACE) ?: [] as $f) {
        $name = basename($f);
        $out[] = [
            'name' => $name,
            'size' => filesize($f) ?: 0,
            'time' => filemtime($f) ?: 0,
            'kind' => str_contains($name, '-auto-') ? 'auto' : 'manuell',
        ];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
    return $out;
}

/** Pfad einer Sicherung aus dem Dateinamen – nur gültige Namen im Sicherungsordner */
function backup_path(string $name): ?string
{
    if (!preg_match('/^backup-[0-9_-]+-(auto|manuell)-[a-f0-9]{8}\.(zip|sql\.gz)$/', $name)) return null;
    $f = backup_dir() . '/' . $name;
    return is_file($f) ? $f : null;
}

function backup_fmt_size(int $b): string
{
    if ($b >= 1073741824) return number_format($b / 1073741824, 1, ',', '.') . ' GB';
    if ($b >= 1048576) return number_format($b / 1048576, 1, ',', '.') . ' MB';
    if ($b >= 1024) return number_format($b / 1024, 0, ',', '.') . ' KB';
    return $b . ' B';
}

/** Schreibt einen vollständigen SQL-Dump der Datenbank in $file */
function backup_dump_sql(string $file): array
{
    $pdo = db();
    $mysql = (cfg('db.driver', 'mysql') !== 'sqlite');
    $fh = fopen($file, 'wb');
    if (!$fh) throw new RuntimeException(t('Sicherungsdatei konnte nicht angelegt werden.'));
    $w = function (string $s) use ($fh) { if (fwrite($fh, $s) === false) throw new RuntimeException(t('Schreiben der Sicherung fehlgeschlagen (Speicherplatz?).')); };

    $w(t('-- Kundenportal – Datensicherung vom ') . date('d.m.Y H:i:s') . "\n");
    $w(t('-- Datenbank: ') . ($mysql ? t('MySQL/MariaDB') : 'SQLite') . t(', Schema-Version ') . (defined('SCHEMA_VERSION') ? SCHEMA_VERSION : '?') . "\n\n");
    if ($mysql) {
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
        $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $tables = array_map(fn($t) => $t[0], array_filter($tables, fn($t) => strtoupper($t[1]) === 'BASE TABLE'));
    } else {
        $w("PRAGMA foreign_keys = OFF;\nBEGIN TRANSACTION;\n\n");
        $tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(), 'name');
    }

    $stats = ['tables' => 0, 'rows' => 0];
    foreach ($tables as $t) {
        $qt = $mysql ? '`' . str_replace('`', '``', $t) . '`' : '"' . str_replace('"', '""', $t) . '"';
        if ($mysql) {
            $create = $pdo->query("SHOW CREATE TABLE $qt")->fetch(PDO::FETCH_NUM)[1];
        } else {
            $create = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
        }
        $w("-- Tabelle $t\nDROP TABLE IF EXISTS $qt;\n$create;\n\n");

        // Zeilen in Blöcken schreiben; ungepuffert, damit große Logos/Formulare nicht den Speicher füllen
        if ($mysql) $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $st = $pdo->query("SELECT * FROM $qt");
            $cols = null; $buf = ''; $n = 0;
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) {
                    $cols = '(' . implode(', ', array_map(fn($c) => $mysql ? '`' . str_replace('`', '``', $c) . '`' : '"' . str_replace('"', '""', $c) . '"', array_keys($r))) . ')';
                }
                $vals = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $pdo->quote((string)$v)), $r)) . ')';
                $buf .= ($buf === '' ? "INSERT INTO $qt $cols VALUES\n" : ",\n") . $vals;
                $n++;
                if (strlen($buf) > 500000) { $w($buf . ";\n"); $buf = ''; }
            }
            if ($buf !== '') $w($buf . ";\n");
            $st->closeCursor();
        } finally {
            if ($mysql) $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        $w("\n");
        $stats['tables']++;
        $stats['rows'] += $n;
    }
    $w($mysql ? "SET FOREIGN_KEY_CHECKS = 1;\n" : "COMMIT;\nPRAGMA foreign_keys = ON;\n");
    fclose($fh);
    return $stats;
}

function backup_readme(): string
{
    return t('Kundenportal – Datensicherung vom ') . date('d.m.Y H:i') . "\r\n\r\n"
        . t("Inhalt\n")
        . t("  database.sql  komplette Datenbank (alle Firmen, Benutzer, Aufträge, Formulare, Kunden, Einstellungen)\n")
        . t("  secret.key    Schlüssel für die verschlüsselt gespeicherten Postfach-Zugangsdaten (gehört nach app/data/)\n")
        . t("  config.php    Konfiguration des Portals inkl. Datenbank-Zugangsdaten\n\n")
        . t("Wiederherstellen\n")
        . t("  1. Programmdateien des Portals hochladen (oder vorhandene Installation verwenden).\n")
        . t("  2. config.php und app/data/secret.key aus dieser Sicherung zurückspielen\n")
        . t("     (bei neuer Datenbank die Zugangsdaten in config.php anpassen).\n")
        . t("  3. database.sql in die leere oder vorhandene Datenbank importieren, z. B. mit phpMyAdmin\n")
        . ('     (IONOS: Hosting > ' . e(t('Datenbanken > phpMyAdmin > Importieren). Vorhandene Tabellen werden dabei ersetzt.')) . "\n")
        . t("  4. Sicherstellen, dass app/data/installed.lock existiert, dann das Portal aufrufen.\n\n")
        . t("Diese Datei enthält vertrauliche Daten. Bitte sicher aufbewahren.\n");
}

/**
 * Erstellt eine Sicherung. $kind: 'auto' oder 'manuell'. Gibt den Dateinamen zurück.
 */
function backup_create(string $kind): string
{
    @set_time_limit(900);
    @ini_set('memory_limit', '512M');
    $dir = backup_dir();
    $lock = fopen($dir . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException(t('Es läuft bereits eine Sicherung. Bitte kurz warten.'));

    $base = 'backup-' . date('Y-m-d_His') . '-' . ($kind === 'auto' ? 'auto' : 'manuell') . '-' . bin2hex(random_bytes(4));
    $sql = $dir . '/' . $base . '.sql.tmp';
    try {
        $stats = backup_dump_sql($sql);
        if (class_exists('ZipArchive')) {
            $target = $dir . '/' . $base . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($target . '.tmp', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException(t('ZIP-Archiv konnte nicht angelegt werden.'));
            $zip->addFile($sql, 'database.sql');
            if (is_file(APP_DIR . '/data/secret.key')) $zip->addFile(APP_DIR . '/data/secret.key', 'secret.key');
            if (is_file(ROOT_DIR . '/config.php')) $zip->addFile(ROOT_DIR . '/config.php', 'config.php');
            $zip->addFromString('LIESMICH.txt', backup_readme());
            // Anhänge der Tickets (Dateien liegen nicht in der Datenbank): app/data/tickets/<Firma>/…
            $attach = [];
            foreach (array_merge(glob(APP_DIR . '/data/tickets/*/*.bin') ?: [], glob(APP_DIR . '/data/inventory/*/*.bin') ?: []) as $f) {
                $n = basename(dirname(dirname($f))) . '/' . basename(dirname($f)) . '/' . basename($f);
                $zip->addFile($f, $n);
                $attach[] = $n;
            }
            $pw = backup_password();
            if ($pw !== '') {
                if (!backup_can_encrypt()) throw new RuntimeException(t('Der Server unterstützt keine verschlüsselten ZIP-Archive (AES). Bitte das Sicherungspasswort entfernen.'));
                $zip->setPassword($pw);
                foreach (array_merge(['database.sql', 'secret.key', 'config.php'], $attach) as $n) {
                    if ($zip->locateName($n) !== false) $zip->setEncryptionName($n, ZipArchive::EM_AES_256, $pw);
                }
            }
            if (!$zip->close()) throw new RuntimeException(t('ZIP-Archiv konnte nicht geschrieben werden (Speicherplatz?).'));
            rename($target . '.tmp', $target);
        } else {
            // Fallback ohne ZIP-Erweiterung: nur die Datenbank, gzip-komprimiert
            $target = $dir . '/' . $base . '.sql.gz';
            $in = fopen($sql, 'rb'); $out = gzopen($target, 'wb6');
            while (!feof($in)) gzwrite($out, (string)fread($in, 1048576));
            fclose($in); gzclose($out);
        }
    } finally {
        if (is_file($sql)) @unlink($sql);
        if (isset($target) && is_file($target . '.tmp')) @unlink($target . '.tmp');
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    @chmod($target, 0640);
    setting_set('backup_last_' . ($kind === 'auto' ? 'auto' : 'manual'), now());
    setting_set('backup_last_error', '');
    audit('backup.create', basename($target) . " ({$stats['tables']} Tabellen, {$stats['rows']} Zeilen, " . backup_fmt_size((int)filesize($target)) . ')');
    return basename($target);
}

/** Löscht ältere automatische Sicherungen, behält die neuesten $keep */
function backup_prune(): int
{
    $keep = max(1, (int)setting_get('backup_keep', (string)BACKUP_KEEP_DEFAULT));
    $n = 0;
    $auto = array_values(array_filter(backup_list(), fn($b) => $b['kind'] === 'auto'));
    foreach (array_slice($auto, $keep) as $b) {
        if (@unlink(backup_dir() . '/' . $b['name'])) $n++;
    }
    return $n;
}

function backup_password(): string
{
    $enc = setting_get('backup_password_enc');
    return $enc === '' ? '' : decrypt_secret($enc);
}

function backup_can_encrypt(): bool
{
    return class_exists('ZipArchive') && defined('ZipArchive::EM_AES_256') && method_exists('ZipArchive', 'setEncryptionName');
}

function backup_cron_token(): string
{
    $t = setting_get('backup_cron_token');
    if ($t === '') {
        $t = bin2hex(random_bytes(20));
        setting_set('backup_cron_token', $t);
    }
    return $t;
}

function backup_cron_url(): string
{
    return rtrim((string)cfg('base_url', ''), '/') . '/cron.php?token=' . backup_cron_token();
}

/**
 * Tägliche Sicherung für cron.php: läuft höchstens einmal pro Tag (der Cronjob darf also
 * auch stündlich laufen). $force erzwingt eine Sicherung.
 */
function backup_daily(bool $force = false): string
{
    // zuerst die Sicherungen der Firmen (App „Datensicherung“), jede für sich
    $firms = cbackup_daily($force);
    $suffix = $firms ? "\n" . implode("\n", $firms) : '';
    if (!$force && setting_get('backup_auto', '1') !== '1') return t('Automatische Sicherung des Portals ist ausgeschaltet.') . $suffix;
    $last = setting_get('backup_last_auto');
    if (!$force && $last !== '' && substr($last, 0, 10) === date('Y-m-d')) return t('Portal: heute bereits gesichert (') . fmt_dt($last) . ').' . $suffix;
    setting_set('backup_last_cron', now());
    try {
        $name = backup_create('auto');
        $pruned = backup_prune();
        $drive = gdrive_after_backup(backup_dir() . '/' . $name, true);
        return t('Sicherung erstellt: ') . $name . ($pruned ? t(' ({n} ältere entfernt)', ['n' => $pruned]) : '') . ($drive !== '' ? ' – ' . $drive : '') . $suffix;
    } catch (Throwable $ex) {
        $msg = $ex->getMessage();
        setting_set('backup_last_error', now() . ' – ' . $msg);
        error_log('[kundenportal] Datensicherung fehlgeschlagen: ' . $msg);
        audit('backup.error', mb_substr($msg, 0, 400));
        if (setting_get('backup_notify', '1') === '1') {
            send_mail_t((array)cfg('mail.admin_recipients', []), fn() => [t('Datensicherung fehlgeschlagen'), t("Die automatische Datensicherung des Kundenportals ist fehlgeschlagen.\n\nFehler: {msg}\n\n", ['msg' => $msg])
                . t('Bitte im Portal unter „Datensicherung“ prüfen: ') . rtrim((string)cfg('base_url', ''), '/') . '/index.php?r=backups']);
        }
        return 'FEHLER: ' . $msg . $suffix;
    }
}

// ---------------------------------------------------------------------------
// Google Drive über eine Apps-Script-Brücke: Ein kleines Google Apps Script im
// eigenen Google-Konto nimmt die Sicherungen entgegen und legt sie in Drive ab.
// Keine Cloud Console, keine Client-ID – nur Skript-URL und gemeinsamer Schlüssel.
// $cid = null: Sicherung des ganzen Portals, sonst die Drive-Verbindung einer Firma.
// ---------------------------------------------------------------------------
const GDRIVE_KEEP_DEFAULT = 30;
const GDRIVE_MAX_BYTES = 35 * 1048576; // Apps Script nimmt ca. 50 MB je Anfrage, Base64 braucht ein Drittel mehr

/** Name einer Einstellung (portalweit oder je Firma) */
function gk(string $name, ?int $cid): string
{
    return $cid ? $name . '_c' . $cid : $name;
}

function gdrive_get(string $name, ?int $cid, string $default = ''): string
{
    return setting_get(gk($name, $cid), $default);
}

function gdrive_set(string $name, ?int $cid, string $value): void
{
    setting_set(gk($name, $cid), $value);
}

function gdrive_connected(?int $cid = null): bool
{
    return gdrive_get('gdrive_url', $cid) !== '';
}

function gdrive_secret(?int $cid = null): string
{
    $s = gdrive_get('gdrive_secret', $cid);
    if ($s === '') {
        $s = bin2hex(random_bytes(24));
        gdrive_set('gdrive_secret', $cid, $s);
    }
    return $s;
}

function gdrive_folder_name(?int $cid = null): string
{
    return gdrive_get('gdrive_folder_name', $cid, $cid ? t('Datensicherung Kundenportal') : t('Kundenportal-Backups'))
        ?: ($cid ? t('Datensicherung Kundenportal') : t('Kundenportal-Backups'));
}

/** Quelltext des Apps Scripts (mit dem Schlüssel des Portals bzw. der Firma) */
function gdrive_script_code(?int $cid = null): string
{
    $secret = gdrive_secret($cid);
    $folder = str_replace("'", '', gdrive_folder_name($cid));
    return <<<JS
// Kundenportal – Datensicherung nach Google Drive
// In script.google.com als Web-App bereitstellen: Ausführen als „Ich“, Zugriff „Jeder“.
// Danach einmal die Funktion „autorisieren“ ausführen und den Zugriff auf Google Drive erlauben.
const SECRET = '$secret';

function doPost(e) {
  try {
    const p = JSON.parse(e.postData.contents);
    if (p.secret !== SECRET) return out({ ok: false, error: 'Falscher Schlüssel' });
    const folder = getFolder(p.folder || '$folder');
    if (p.action === 'ping') {
      return out({ ok: true, email: Session.getEffectiveUser().getEmail(), folder: folder.getUrl() });
    }
    if (p.action === 'upload') {
      const blob = Utilities.newBlob(Utilities.base64Decode(p.data), p.mime || 'application/zip', p.name);
      const f = folder.createFile(blob);
      return out({ ok: true, id: f.getId(), folder: folder.getUrl() });
    }
    if (p.action === 'prune') {
      const files = [];
      const it = folder.getFiles();
      while (it.hasNext()) {
        const f = it.next();
        if (f.getName().indexOf('-auto-') !== -1) files.push(f);
      }
      files.sort(function (a, b) { return b.getDateCreated() - a.getDateCreated(); });
      const old = files.slice(Math.max(1, p.keep || 30));
      old.forEach(function (f) { f.setTrashed(true); });
      return out({ ok: true, removed: old.length });
    }
    return out({ ok: false, error: 'Unbekannte Aktion' });
  } catch (err) {
    return out({ ok: false, error: String(err) });
  }
}

function getFolder(name) {
  const it = DriveApp.getFoldersByName(name);
  return it.hasNext() ? it.next() : DriveApp.createFolder(name);
}

function out(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}

// Einmal ausführen, um den Zugriff auf Google Drive zu erlauben
function autorisieren() {
  getFolder('$folder');
}
JS;
}

/** Anfrage an das Apps Script (folgt der Weiterleitung von Google) */
function gdrive_call(array $payload, ?int $cid = null, string $url = ''): array
{
    $url = $url !== '' ? $url : gdrive_get('gdrive_url', $cid);
    if ($url === '') throw new RuntimeException(t('Google Drive ist nicht verbunden.'));
    $payload += ['secret' => gdrive_secret($cid), 'folder' => gdrive_folder_name($cid)];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, // Google leitet die Antwort auf script.googleusercontent.com um
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException(t('Google Drive nicht erreichbar: ') . $err);
    $d = json_decode((string)$resp, true);
    if (!is_array($d)) {
        throw new RuntimeException($status === 200
            ? t('Google Drive: unerwartete Antwort. Ist das Skript als Web-App mit Zugriff „Jeder“ bereitgestellt?')
            : t('Google Drive: HTTP {n}. Stimmt die Web-App-URL?', ['n' => $status]));
    }
    if (empty($d['ok'])) {
        $msg = (string)($d['error'] ?? t('Fehler'));
        if (str_contains($msg, 'Berechtigung') || stripos($msg, 'permission') !== false) {
            $msg .= t(' – Bitte im Skript einmal die Funktion „autorisieren“ ausführen und den Zugriff auf Google Drive erlauben.');
        }
        throw new RuntimeException(t('Google Drive: ') . $msg);
    }
    return $d;
}

/** Prüft eine Web-App-URL und speichert sie */
function gdrive_connect(string $url, string $secret = '', ?int $cid = null): array
{
    if (!preg_match('#^https://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec$#', $url)) {
        throw new RuntimeException('Bitte die Web-App-URL aus Apps Script einfügen (https://script.google.com/macros/s/…/exec).');
    }
    if ($secret !== '') {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $secret)) throw new RuntimeException(t('Der Schlüssel muss aus 32–64 Zeichen 0–9/a–f bestehen.'));
        gdrive_set('gdrive_secret', $cid, $secret);
    }
    $d = gdrive_call(['action' => 'ping'], $cid, $url);
    gdrive_set('gdrive_url', $cid, $url);
    gdrive_set('gdrive_email', $cid, (string)($d['email'] ?? ''));
    gdrive_set('gdrive_folder_url', $cid, (string)($d['folder'] ?? ''));
    gdrive_set('gdrive_last_error', $cid, '');
    audit('backup.gdrive_connect', (string)($d['email'] ?? ''), $cid);
    return $d;
}

function gdrive_disconnect(?int $cid = null): void
{
    foreach (['gdrive_url', 'gdrive_email', 'gdrive_folder_url', 'gdrive_last_error'] as $k) gdrive_set($k, $cid, '');
    gdrive_set('gdrive_secret', $cid, ''); // neuer Schlüssel: das alte Skript nimmt nichts mehr an
    audit('backup.gdrive_disconnect', '', $cid);
}

function gdrive_upload(string $file, ?int $cid = null): void
{
    @set_time_limit(1800);
    $size = (int)filesize($file);
    if ($size > GDRIVE_MAX_BYTES) {
        throw new RuntimeException(t('Google Drive: Die Sicherung ist mit ') . backup_fmt_size($size) . t(' zu groß für die Skript-Brücke (max. ') . backup_fmt_size(GDRIVE_MAX_BYTES) . ').');
    }
    $d = gdrive_call(['action' => 'upload', 'name' => basename($file),
        'mime' => str_ends_with($file, '.zip') ? 'application/zip' : 'application/gzip',
        'data' => base64_encode((string)file_get_contents($file))], $cid);
    if (!empty($d['folder'])) gdrive_set('gdrive_folder_url', $cid, (string)$d['folder']);
    $done = json_decode(gdrive_get('gdrive_uploaded', $cid, '[]'), true) ?: [];
    $done[] = basename($file);
    gdrive_set('gdrive_uploaded', $cid, json_encode(array_slice(array_values(array_unique($done)), -300)));
    gdrive_set('gdrive_last_upload', $cid, now());
    gdrive_set('gdrive_last_error', $cid, '');
    audit('backup.gdrive', basename($file) . ' nach Google Drive hochgeladen', $cid);
}

function gdrive_prune(?int $cid = null): int
{
    $keep = max(1, (int)gdrive_get('gdrive_keep', $cid, (string)GDRIVE_KEEP_DEFAULT));
    return (int)(gdrive_call(['action' => 'prune', 'keep' => $keep], $cid)['removed'] ?? 0);
}

/** Nach einer Sicherung: hochladen, falls eingeschaltet. Gibt eine Meldung zurück, wirft nie. */
function gdrive_after_backup(string $file, bool $notify, ?int $cid = null): string
{
    if (!gdrive_connected($cid) || gdrive_get('gdrive_enabled', $cid, '1') !== '1') return '';
    $name = basename($file);
    try {
        gdrive_upload($file, $cid);
        $pruned = gdrive_prune($cid);
        return t('In Google Drive gespeichert') . ($pruned ? t(' ({n} ältere entfernt)', ['n' => $pruned]) : '') . '.';
    } catch (Throwable $ex) {
        $msg = $ex->getMessage();
        gdrive_set('gdrive_last_error', $cid, now() . ' – ' . $msg);
        error_log('[kundenportal] ' . ($cid ? "Firma $cid: " : '') . $msg);
        audit('backup.gdrive_error', mb_substr($msg, 0, 400), $cid);
        if ($notify && ($cid ? setting_get(gk('cbackup_notify', $cid), '1') : setting_get('backup_notify', '1')) === '1') {
            $to = $cid ? company_recipients($cid) : (array)cfg('mail.admin_recipients', []);
            send_mail_t($to, fn() => [t('Datensicherung: Upload nach Google Drive fehlgeschlagen'), t("Die Sicherung {name} wurde erstellt, konnte aber nicht nach Google Drive hochgeladen werden.\n\nFehler: {msg}\n\n", ['name' => $name, 'msg' => $msg])
                . t('Bitte im Portal unter „Datensicherung“ prüfen: ') . rtrim((string)cfg('base_url', ''), '/') . '/index.php?r=' . ($cid ? 'company_backups' : 'backups')], $cid);
        }
        return t('Upload nach Google Drive fehlgeschlagen: ') . $msg;
    }
}

function gdrive_uploaded_names(?int $cid = null): array
{
    return array_flip(json_decode(gdrive_get('gdrive_uploaded', $cid, '[]'), true) ?: []);
}

/** Abschnitt „Google Drive“ für die Seiten Datensicherung (Portal) und Datensicherung (Firma) */
function gdrive_card(?int $cid, string $actionUrl): void
{
    $drive = gdrive_connected($cid);
    $form = function (string $action, string $extra = '', string $class = 'inline') use ($actionUrl) {
        return '<form method="post" action="' . e($actionUrl) . '" class="' . $class . '">' . csrf_field() . '<input type="hidden" name="action" value="' . $action . '">' . $extra;
    };
    ?>
    <div class="card">
        <h2><?= e(t('Google Drive')) ?></h2>
        <?php if (!$drive): ?>
            <p><?= e(t('Sicherungen zusätzlich automatisch in')) ?> <?= $cid ? t('Ihr eigenes') : t('Ihr') ?> <?= e(t('Google Drive hochladen – damit liegen sie auch außerhalb des Webspace. Ohne Google Cloud Console und ohne API-Schlüssel: Ein kleines Skript im Google-Konto nimmt die Sicherungen an.')) ?></p>
            <ol>
                <li><?= e(t('Mit dem gewünschten Google-Konto auf')) ?> <a href="https://script.google.com/create" target="_blank" rel="noopener noreferrer"><?= e(t('script.google.com ↗')) ?></a> <?= e(t('ein neues Projekt anlegen, den vorhandenen Code löschen und diesen Code einfügen:')) ?>
                    <textarea readonly rows="8" class="code" aria-label="<?= e(t('Skript-Code')) ?>"><?= e(gdrive_script_code($cid)) ?></textarea></li>
                <li><?= e(t('Speichern. Oben die Funktion')) ?> <em>autorisieren</em> <?= e(t('auswählen,')) ?> <em><?= e(t('Ausführen')) ?></em> <?= e(t('klicken und den Zugriff erlauben (bei „Google hat diese App nicht überprüft“:')) ?> <em><?= e(t('Erweitert › Zu … wechseln')) ?></em><?= e(t(', dann alle Häkchen setzen).')) ?></li>
                <li><em><?= e(t('Bereitstellen › Neue Bereitstellung › Typ: Web-App')) ?></em><?= e(t(', „Ausführen als: Ich“, „Zugriff: Jeder“,')) ?> <em><?= e(t('Bereitstellen')) ?></em>.</li>
                <li><?= e(t('Die angezeigte Web-App-URL hier einfügen. Der Schlüssel daneben muss mit')) ?> <code>SECRET</code> <?= e(t('im Skript übereinstimmen (beim Code oben ist er bereits richtig):')) ?></li>
            </ol>
            <?= $form('gdrive_connect', '', 'inline-form') ?>
                <input name="url" placeholder="https://script.google.com/macros/s/…/exec" aria-label="<?= e(t('Web-App-URL')) ?>" required>
                <input name="secret" value="<?= e(gdrive_secret($cid)) ?>" pattern="[a-fA-F0-9]{32,64}" aria-label="<?= e(t('Schlüssel (SECRET im Skript)')) ?>" title="<?= e(t('Schlüssel – muss mit SECRET im Skript übereinstimmen')) ?>">
                <button class="btn primary"><?= e(t('Verbinden & testen')) ?></button></form>
            <p class="muted small"><?= e(t('Das Skript läuft im Google-Konto und nimmt nur Anfragen mit diesem Schlüssel an. Größe je Sicherung bis')) ?> <?= e(backup_fmt_size(GDRIVE_MAX_BYTES)) ?>.</p>
        <?php else: ?>
            <p><?= e(t('Verbunden')) ?><?= gdrive_get('gdrive_email', $cid) !== '' ? (' ' . e(t('mit')) . ' <strong>') . e(gdrive_get('gdrive_email', $cid)) . '</strong>' : '' ?> <?= e(t('· Ordner „')) ?><?= e(gdrive_folder_name($cid)) ?>“
                <?php if (gdrive_get('gdrive_folder_url', $cid) !== ''): ?>(<a href="<?= e(gdrive_get('gdrive_folder_url', $cid)) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('in Drive öffnen ↗')) ?></a>)<?php endif; ?>
                <?= e(t('· letzter Upload:')) ?> <?= gdrive_get('gdrive_last_upload', $cid) !== '' ? fmt_dt(gdrive_get('gdrive_last_upload', $cid)) : '–' ?></p>
            <?= $form('gdrive_settings', '', 'form') ?>
                <label class="check"><input type="checkbox" name="enabled" value="1" <?= gdrive_get('gdrive_enabled', $cid, '1') === '1' ? 'checked' : '' ?>> <?= e(t('Jede neue Sicherung (automatisch und manuell) nach Google Drive hochladen')) ?></label>
                <div class="grid2">
                    <label><?= e(t('Ordner in Google Drive')) ?><input name="folder" maxlength="100" value="<?= e(gdrive_folder_name($cid)) ?>"></label>
                    <label><?= e(t('Automatische Sicherungen in Drive behalten')) ?><input type="number" name="keep" min="1" max="365" value="<?= (int)gdrive_get('gdrive_keep', $cid, (string)GDRIVE_KEEP_DEFAULT) ?>"></label>
                </div>
                <button class="btn primary"><?= e(t('Speichern')) ?></button>
            </form>
            <?= $form('gdrive_disconnect') ?>
                <button class="btn small danger" data-confirm="<?= e(t('Verbindung zu Google Drive trennen? Die Sicherungen in Drive bleiben erhalten.')) ?>"><?= e(t('Verbindung trennen')) ?></button></form>
        <?php endif; ?>
    </div>
    <?php
}

/** Verarbeitet die Google-Drive-Aktionen beider Seiten. Gibt true zurück, wenn die Aktion erkannt wurde. */
function gdrive_handle_post(string $action, ?int $cid, string $dir): bool
{
    if ($action === 'gdrive_connect') {
        try {
            $d = gdrive_connect(trim((string)post('url')), strtolower(trim((string)post('secret'))), $cid);
            flash('success', t('Google Drive') . (!empty($d['email']) ? ' (' . $d['email'] . ')' : '') . t(' ist verbunden. Neue Sicherungen werden in den Ordner „') . gdrive_folder_name($cid) . t('“ hochgeladen.'));
        } catch (Throwable $ex) {
            flash('error', $ex->getMessage());
        }
    } elseif ($action === 'gdrive_disconnect') {
        gdrive_disconnect($cid);
        flash('success', t('Google Drive wurde getrennt. Die Sicherungen in Drive bleiben erhalten. Das bisherige Skript ist ungültig; zum erneuten Verbinden den neuen Skript-Code verwenden.'));
    } elseif ($action === 'gdrive_settings') {
        gdrive_set('gdrive_enabled', $cid, post('enabled') === '1' ? '1' : '0');
        gdrive_set('gdrive_keep', $cid, (string)min(365, max(1, (int)post('keep', GDRIVE_KEEP_DEFAULT))));
        $folder = trim((string)preg_replace('/[^\p{L}\p{N} ._-]/u', '', (string)post('folder')));
        if ($folder !== '' && $folder !== gdrive_folder_name($cid)) {
            gdrive_set('gdrive_folder_name', $cid, mb_substr($folder, 0, 100)); // das Skript legt den Ordner beim nächsten Upload an
            gdrive_set('gdrive_folder_url', $cid, '');
        }
        flash('success', t('Einstellungen für Google Drive gespeichert.'));
    } elseif ($action === 'gdrive_upload') {
        $name = basename((string)post('name'));
        $f = $dir . '/' . $name;
        if (!preg_match('/^[A-Za-z0-9_.-]+\.(zip|sql\.gz)$/', $name) || !is_file($f)) {
            flash('error', t('Sicherung nicht gefunden.'));
            return true;
        }
        try {
            gdrive_upload($f, $cid);
            flash('success', $name . t(' wurde nach Google Drive hochgeladen.'));
        } catch (Throwable $ex) {
            gdrive_set('gdrive_last_error', $cid, now() . ' – ' . $ex->getMessage());
            flash('error', $ex->getMessage());
        }
    } else {
        return false;
    }
    return true;
}

// ---------------------------------------------------------------------------
// Datensicherung je Firma (App „Datensicherung“): nur die Daten einer Firma
// als JSON plus Kundenliste als CSV. Ohne Passwörter, Schlüssel und Postfach-Zugangsdaten.
// ---------------------------------------------------------------------------
const CBACKUP_APP = 'datensicherung';
const CBACKUP_KEEP_DEFAULT = 14;

function cbackup_dir(int $cid): string
{
    $dir = backup_dir() . '/firma-' . $cid;
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    return $dir;
}

function cbackup_list(int $cid): array
{
    $out = [];
    foreach (glob(cbackup_dir($cid) . '/firma-*.zip') ?: [] as $f) {
        $name = basename($f);
        $out[] = ['name' => $name, 'size' => filesize($f) ?: 0, 'time' => filemtime($f) ?: 0,
            'kind' => str_contains($name, '-auto-') ? 'auto' : 'manuell'];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
    return $out;
}

function cbackup_path(int $cid, string $name): ?string
{
    if (!preg_match('/^firma-' . $cid . '-[0-9_-]+-(auto|manuell)-[a-f0-9]{8}\.zip$/', $name)) return null;
    $f = cbackup_dir($cid) . '/' . $name;
    return is_file($f) ? $f : null;
}

/** Alle Daten einer Firma (Tabelle => Zeilen) */
function cbackup_data(int $cid): array
{
    $rq = array_column(rows('SELECT id FROM requests WHERE company_id = ?', [$cid]), 'id');
    $in = $rq ? implode(',', array_map('intval', $rq)) : '0';
    $userCols = 'id, company_id, email, name, role, totp_enabled, active, last_login, created_at';
    return [
        'companies'      => rows('SELECT * FROM companies WHERE id = ?', [$cid]),
        'users'          => rows("SELECT $userCols FROM users WHERE company_id = ?", [$cid]),
        'departments'    => rows('SELECT * FROM departments WHERE company_id = ?', [$cid]),
        'positions'      => rows('SELECT * FROM positions WHERE company_id = ?', [$cid]),
        'catalog_items'  => rows('SELECT * FROM catalog_items WHERE company_id = ?', [$cid]),
        'template_items' => rows('SELECT * FROM template_items WHERE company_id = ?', [$cid]),
        'requests'       => rows('SELECT * FROM requests WHERE company_id = ?', [$cid]),
        'employees'      => rows('SELECT * FROM employees WHERE company_id = ?', [$cid]),
        'request_items'  => rows("SELECT * FROM request_items WHERE request_id IN ($in)"),
        'request_log'    => rows("SELECT * FROM request_log WHERE request_id IN ($in)"),
        'form_folders'   => rows('SELECT * FROM form_folders WHERE company_id = ?', [$cid]),
        'forms'          => rows('SELECT * FROM forms WHERE company_id = ?', [$cid]),
        'form_layouts'   => rows('SELECT * FROM form_layouts WHERE company_id = ?', [$cid]),
        'form_branding'  => rows('SELECT * FROM form_branding WHERE company_id = ?', [$cid]),
        'customer_fields'=> rows('SELECT * FROM customer_fields WHERE company_id = ?', [$cid]),
        'customers'      => rows('SELECT * FROM customers WHERE company_id = ?', [$cid]),
        'link_tabs'      => rows('SELECT * FROM link_tabs WHERE company_id = ?', [$cid]),
        'link_sections'  => rows('SELECT * FROM link_sections WHERE company_id = ?', [$cid]),
        'links'          => rows('SELECT * FROM links WHERE company_id = ?', [$cid]),
        'ticket_queues'  => rows('SELECT * FROM ticket_queues WHERE company_id = ?', [$cid]),
        'ticket_queue_members' => rows('SELECT m.* FROM ticket_queue_members m JOIN ticket_queues q ON q.id = m.queue_id WHERE q.company_id = ?', [$cid]),
        'ticket_queue_groups' => rows('SELECT g.* FROM ticket_queue_groups g JOIN ticket_queues q ON q.id = g.queue_id WHERE q.company_id = ?', [$cid]),
        'user_departments' => rows('SELECT ud.* FROM user_departments ud JOIN departments d ON d.id = ud.department_id WHERE d.company_id = ?', [$cid]),
        // Postfächer ohne Zugangsdaten (die bleiben nur verschlüsselt in der Datenbank)
        'ticket_mailboxes' => rows('SELECT id, company_id, queue_id, address, provider, imap_host, imap_port, imap_security, imap_user, imap_folder, active, created_at FROM ticket_mailboxes WHERE company_id = ?', [$cid]),
        'ticket_mail_rules' => rows('SELECT * FROM ticket_mail_rules WHERE company_id = ?', [$cid]),
        'ticket_providers' => rows('SELECT * FROM ticket_providers WHERE company_id = ?', [$cid]),
        'ticket_inbox'   => rows('SELECT id, company_id, mailbox_id, message_id, from_email, from_name, to_email, subject, body, status, reason, ticket_id, handled_by, handled_at, created_at FROM ticket_inbox WHERE company_id = ?', [$cid]),
        'tickets'        => rows('SELECT * FROM tickets WHERE company_id = ?', [$cid]),
        'ticket_messages'=> rows('SELECT * FROM ticket_messages WHERE company_id = ?', [$cid]),
        'ticket_attachments' => rows('SELECT * FROM ticket_attachments WHERE company_id = ?', [$cid]),
        'inv_types'      => rows('SELECT * FROM inv_types WHERE company_id = ?', [$cid]),
        'inv_locations'  => rows('SELECT * FROM inv_locations WHERE company_id = ?', [$cid]),
        'inv_group_perms'=> rows('SELECT * FROM inv_group_perms WHERE company_id = ?', [$cid]),
        'inv_min_stock'  => rows('SELECT * FROM inv_min_stock WHERE company_id = ?', [$cid]),
        // BitLocker-Schlüssel bleiben nur verschlüsselt in der Datenbank
        'inv_devices'    => rows('SELECT id, company_id, inv_no, type_id, manufacturer, model, serial, status, location_id, room, employee_id, purchase_date, price_cents, supplier, warranty_until, os, ip, mac, phone_no, sim_no, mobile_contract, notes, repair_reason, repair_vendor, repair_until, retire_reason, retired_at, disposal_date, disposal_vendor, wipe_method, wipe_by, wipe_confirmed, created_by, created_at, updated_at FROM inv_devices WHERE company_id = ?', [$cid]),
        'inv_log'        => rows('SELECT * FROM inv_log WHERE company_id = ?', [$cid]),
        // Lizenzschlüssel bleiben nur verschlüsselt in der Datenbank
        'inv_licenses'   => rows('SELECT id, company_id, product, manufacturer, kind, seats, contract_no, supplier, start_date, end_date, notice_value, notice_unit, auto_renew, renew_months, cost_cents, cost_interval, device_id, status, cancelled_at, notes, created_by, created_at, updated_at FROM inv_licenses WHERE company_id = ?', [$cid]),
        'inv_license_assign' => rows('SELECT * FROM inv_license_assign WHERE company_id = ?', [$cid]),
        'inv_handovers'  => rows('SELECT * FROM inv_handovers WHERE company_id = ?', [$cid]),
        'inv_handover_items' => rows('SELECT i.* FROM inv_handover_items i JOIN inv_handovers h ON h.id = i.handover_id WHERE h.company_id = ?', [$cid]),
        'inv_files'      => rows('SELECT * FROM inv_files WHERE company_id = ?', [$cid]),
        'ct_types'       => rows('SELECT * FROM ct_types WHERE company_id = ?', [$cid]),
        'ct_group_perms' => rows('SELECT * FROM ct_group_perms WHERE company_id = ?', [$cid]),
        'contracts'      => rows('SELECT * FROM contracts WHERE company_id = ?', [$cid]),
        'ct_log'         => rows('SELECT * FROM ct_log WHERE company_id = ?', [$cid]),
        'ct_files'       => rows('SELECT * FROM ct_files WHERE company_id = ?', [$cid]),
        'ct_prices'      => rows('SELECT * FROM ct_prices WHERE company_id = ?', [$cid]),
        'ct_links'       => rows('SELECT * FROM ct_links WHERE company_id = ?', [$cid]),
        'ct_notices'     => rows('SELECT * FROM ct_notices WHERE company_id = ?', [$cid]),
        'ct_cancellations' => rows('SELECT * FROM ct_cancellations WHERE company_id = ?', [$cid]),
        'ct_offers'      => rows('SELECT * FROM ct_offers WHERE company_id = ?', [$cid]),
    ];
}

function cbackup_csv(array $rows): string
{
    if (!$rows) return "\xEF\xBB\xBF";
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, array_keys($rows[0]), ';', '"', '');
    foreach ($rows as $r) fputcsv($fh, array_map(fn($v) => $v === null ? '' : (string)$v, $r), ';', '"', '');
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return "\xEF\xBB\xBF" . $csv; // BOM, damit Excel Umlaute richtig anzeigt
}

/** Erstellt die Sicherung einer Firma. Gibt den Pfad zurück. */
function cbackup_create(int $cid, string $kind): string
{
    @set_time_limit(600);
    $co = row('SELECT id, name FROM companies WHERE id = ?', [$cid]);
    if (!$co) throw new RuntimeException(t('Firma nicht gefunden.'));
    if (!class_exists('ZipArchive')) throw new RuntimeException(t('Der Server unterstützt keine ZIP-Archive.'));
    $data = cbackup_data($cid);
    $dir = cbackup_dir($cid);
    $file = $dir . '/firma-' . $cid . '-' . date('Y-m-d_His') . '-' . ($kind === 'auto' ? 'auto' : 'manuell') . '-' . bin2hex(random_bytes(4)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($file . '.tmp', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException(t('ZIP-Archiv konnte nicht angelegt werden.'));
    $zip->addFromString('daten.json', json_encode(['format' => 'kundenportal-firma', 'version' => 1, 'schema' => defined('SCHEMA_VERSION') ? SCHEMA_VERSION : null,
        'exported_at' => date('c'), 'company' => $co['name'], 'tables' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    // Anhänge der Tickets (Dateien liegen nicht in der Datenbank)
    foreach (glob(APP_DIR . '/data/tickets/' . $cid . '/*.bin') ?: [] as $f) $zip->addFile($f, 'ticket-anhaenge/' . basename($f));
    foreach (glob(APP_DIR . '/data/inventory/' . $cid . '/*.bin') ?: [] as $f) $zip->addFile($f, 'inventar-anhaenge/' . basename($f));
    foreach (glob(APP_DIR . '/data/contracts/' . $cid . '/*.bin') ?: [] as $f) $zip->addFile($f, 'vertrags-dokumente/' . basename($f));
    $zip->addFromString('kunden.csv', cbackup_csv(array_map(function ($r) { unset($r['company_id']); return $r; }, $data['customers'])));
    $zip->addFromString('LIESMICH.txt', "Datensicherung „{$co['name']}“ vom " . date('d.m.Y H:i') . "\r\n\r\n"
        . t("daten.json   alle Daten der Firma im Kundenportal: Firmenprofil, Benutzer (ohne Passwörter), Abteilungen,\n")
        . t("             Positionen, Aufträge, Formulare mit Ordnern, Briefköpfe/Fußzeilen, Logo, Kundenfelder und Kunden\n")
        . t("kunden.csv   Kundendatenbank zum Öffnen in Excel (Trennzeichen Semikolon)\n\n")
        . t("Zur Wiederherstellung wenden Sie sich bitte an Ihren IT-Dienstleister.\n"));
    if (!$zip->close()) throw new RuntimeException(t('ZIP-Archiv konnte nicht geschrieben werden (Speicherplatz?).'));
    rename($file . '.tmp', $file);
    @chmod($file, 0640);
    setting_set(gk('cbackup_last_' . ($kind === 'auto' ? 'auto' : 'manual'), $cid), now());
    setting_set(gk('cbackup_last_error', $cid), '');
    audit('cbackup.create', basename($file) . ' (' . backup_fmt_size((int)filesize($file)) . ')', $cid);
    return $file;
}

function cbackup_prune(int $cid): int
{
    $keep = max(1, (int)setting_get(gk('cbackup_keep', $cid), (string)CBACKUP_KEEP_DEFAULT));
    $n = 0;
    $auto = array_values(array_filter(cbackup_list($cid), fn($b) => $b['kind'] === 'auto'));
    foreach (array_slice($auto, $keep) as $b) if (@unlink(cbackup_dir($cid) . '/' . $b['name'])) $n++;
    return $n;
}

/** Tägliche Sicherung aller Firmen mit freigeschalteter App (aus cron.php). Gibt Zeilen fürs Protokoll zurück. */
function cbackup_daily(bool $force = false): array
{
    $out = [];
    foreach (rows("SELECT c.id, c.name FROM companies c JOIN company_apps ca ON ca.company_id = c.id JOIN apps a ON a.id = ca.app_id
        WHERE a.slug = ? AND a.active = 1 AND c.active = 1 ORDER BY c.id", [CBACKUP_APP]) as $c) {
        $cid = (int)$c['id'];
        if (!$force && setting_get(gk('cbackup_auto', $cid), '1') !== '1') continue;
        $last = setting_get(gk('cbackup_last_auto', $cid));
        if (!$force && $last !== '' && substr($last, 0, 10) === date('Y-m-d')) continue;
        try {
            $file = cbackup_create($cid, 'auto');
            cbackup_prune($cid);
            $drive = gdrive_after_backup($file, true, $cid);
            $out[] = "Firma {$c['name']}: " . basename($file) . ($drive !== '' ? ' – ' . $drive : '');
        } catch (Throwable $ex) {
            $msg = $ex->getMessage();
            setting_set(gk('cbackup_last_error', $cid), now() . ' – ' . $msg);
            audit('cbackup.error', mb_substr($msg, 0, 400), $cid);
            error_log("[kundenportal] Datensicherung Firma $cid fehlgeschlagen: $msg");
            $out[] = "Firma {$c['name']}: FEHLER – $msg";
        }
    }
    return $out;
}
