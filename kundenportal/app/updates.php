<?php
/**
 * Update-Archiv: Update-Pakete (update.php) mit Zusammenfassung, Datum und Autor.
 * Ablage in app/data/updates/ (per .htaccess gesperrt), je Paket eine .php und eine .json mit den Angaben.
 * Pakete aus tools/build-update.php tragen ihre Angaben in der Zeile "// UPDATE-INFO: <base64-json>".
 */

// Beim Einspielen über update.php läuft noch die alte bootstrap.php; t() dann hier nachladen
if (!function_exists('t')) require_once __DIR__ . '/i18n.php';

function updates_dir(): string
{
    $dir = APP_DIR . '/data/updates';
    if (!is_dir($dir)) mkdir($dir, 0770, true);
    return $dir;
}

/** Angaben, die im Paket selbst stehen (oder leeres Array) */
function update_embedded_info(string $file): array
{
    $head = (string)@file_get_contents($file, false, null, 0, 8192);
    if (!preg_match('~^// UPDATE-INFO: ([A-Za-z0-9+/=]+)\s*$~m', $head, $m)) return [];
    $info = json_decode((string)base64_decode($m[1], true), true);
    return is_array($info) ? $info : [];
}

/** Prüft, ob die Datei ein Update-Paket des Portals ist; liefert die Zahl der Dateien oder eine Fehlermeldung */
function update_check_package(string $file): int|string
{
    if (!class_exists('ZipArchive')) return t('Auf dem Server fehlt die PHP-Erweiterung „zip“.');
    $zipPath = update_extract_zip($file);
    if ($zipPath === null) return t('Die Datei ist kein Update-Paket des Kundenportals.');
    $zip = new ZipArchive();
    $ok = $zip->open($zipPath) === true;
    $n = $ok ? $zip->numFiles : 0;
    $valid = $ok && $zip->locateName('index.php') !== false && $zip->locateName('app/bootstrap.php') !== false;
    if ($ok) $zip->close();
    @unlink($zipPath);
    return $valid ? $n : t('Das Update-Paket ist beschädigt oder enthält kein Kundenportal.');
}

/** Schreibt das eingebettete ZIP in eine temporäre Datei */
function update_extract_zip(string $file): ?string
{
    $data = (string)@file_get_contents($file);
    $pos = strpos($data, "__HALT_COMPILER();");
    if ($pos === false) return null;
    $raw = base64_decode((string)preg_replace('/\s+/', '', substr($data, $pos + strlen('__HALT_COMPILER();'))), true);
    if ($raw === false || $raw === '' || !str_starts_with($raw, "PK")) return null;
    $tmp = APP_DIR . '/data/update-' . bin2hex(random_bytes(6)) . '.zip';
    file_put_contents($tmp, $raw);
    return $tmp;
}

function update_meta_path(string $id): string
{
    return updates_dir() . '/' . $id . '.json';
}

function update_valid_id(string $id): bool
{
    return (bool)preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{8}$/', $id) && is_file(updates_dir() . '/' . $id . '.php');
}

function update_get(string $id): ?array
{
    if (!update_valid_id($id)) return null;
    $meta = json_decode((string)@file_get_contents(update_meta_path($id)), true);
    $meta = is_array($meta) ? $meta : [];
    $meta['id'] = $id;
    $meta['file'] = updates_dir() . '/' . $id . '.php';
    $meta['size'] = (int)@filesize($meta['file']);
    return $meta;
}

function update_save_meta(string $id, array $meta): void
{
    unset($meta['id'], $meta['file'], $meta['size']);
    file_put_contents(update_meta_path($id), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Legt ein Paket im Archiv ab (verschiebt oder kopiert die Datei) und liefert die ID */
function update_store(string $source, array $meta, bool $move = false): string
{
    $info = update_embedded_info($source);
    $id = date('Ymd-His') . '-' . substr(hash_file('sha256', $source), 0, 8);
    $target = updates_dir() . '/' . $id . '.php';
    if ($move) { if (!@rename($source, $target)) { copy($source, $target); @unlink($source); } }
    else copy($source, $target);
    @chmod($target, 0640);
    $meta += [
        'summary'     => (string)($info['summary'] ?? ''),
        'author'      => (string)($info['author'] ?? ''),
        'date'        => (string)($info['date'] ?? date('Y-m-d')),
        'build'       => (string)($info['build'] ?? ''),
        'files'       => (int)($info['files'] ?? 0),
        'sha256'      => hash_file('sha256', $target),
        'stored_at'   => now(),
        'applied_at'  => null,
        'applied_by'  => null,
    ];
    update_save_meta($id, $meta);
    return $id;
}

/** Alle Pakete, neueste zuerst */
function updates_list(): array
{
    $list = [];
    foreach (glob(updates_dir() . '/*.php') ?: [] as $f) {
        $u = update_get(basename($f, '.php'));
        if ($u) $list[] = $u;
    }
    usort($list, fn($a, $b) => strcmp(($b['date'] ?? '') . $b['id'], ($a['date'] ?? '') . $a['id']));
    return $list;
}

/** Spielt ein Paket ein (wie update.php); liefert [geschriebene Dateien, Fehlerliste] */
function update_apply(string $file): array
{
    $zipPath = update_extract_zip($file);
    if ($zipPath === null) return [0, ['Kein gültiges Update-Paket']];
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) { @unlink($zipPath); return [0, ['Paket beschädigt']]; }
    $root = ROOT_DIR;
    $installed = is_file(APP_DIR . '/data/installed.lock');
    $written = 0;
    $errors = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || str_contains($name, '..') || str_starts_with($name, '/')) continue;
        if ($name === 'config.php' || (str_starts_with($name, 'app/data/') && $name !== 'app/data/.htaccess')) continue;
        if ($installed && $name === 'install.php') continue;
        $target = $root . '/' . $name;
        if (str_ends_with($name, '/')) { if (!is_dir($target)) @mkdir($target, 0755, true); continue; }
        if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
        if (file_put_contents($target, $zip->getFromIndex($i)) === false) $errors[] = $name; else $written++;
    }
    $zip->close();
    @unlink($zipPath);
    if (function_exists('opcache_reset')) @opcache_reset();
    return [$written, $errors];
}
