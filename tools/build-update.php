<?php
/**
 * Baut build/update.php: eine einzelne Textdatei, die alle Programmdateien des
 * Kundenportals enthält. Auf den Server in den Portal-Ordner hochladen und im
 * Browser aufrufen (als Administrator angemeldet). config.php und app/data/
 * bleiben unverändert, die Datei löscht sich danach selbst.
 *
 * Aufruf (im Repository-Ordner):
 *   php tools/build-update.php --author="Name" --summary="Was ist neu" [--summary-file=notes.txt] [--date=2026-11-21]
 * Zusammenfassung, Datum und Autor stehen danach im Paket und erscheinen im Portal unter Administration → Updates.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$src = $root . '/kundenportal';
$outDir = $root . '/build';
if (!is_dir($outDir)) mkdir($outDir, 0775, true);

$opt = getopt('', ['summary:', 'summary-file:', 'author:', 'date:']);
$summary = isset($opt['summary-file']) ? trim((string)file_get_contents($opt['summary-file'])) : trim((string)($opt['summary'] ?? ''));
$commit = trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null'));
$updateInfo = [
    'date'    => (string)($opt['date'] ?? date('Y-m-d')),
    'author'  => (string)($opt['author'] ?? trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' config user.name 2>/dev/null'))),
    'summary' => $summary,
    'build'   => date('Y-m-d H:i') . ($commit !== '' ? ' · ' . $commit : ''),
];

$zipFile = tempnam(sys_get_temp_dir(), 'kp');
$zip = new ZipArchive();
$zip->open($zipFile, ZipArchive::OVERWRITE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
$n = 0;
foreach ($it as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
    if ($rel === 'config.php' || $rel === '.gitignore') continue;
    if (str_starts_with($rel, 'app/data/') && $rel !== 'app/data/.htaccess') continue;
    $zip->addFile($file->getPathname(), $rel);
    $n++;
}
$zip->close();
$updateInfo['files'] = $n;

$head = <<<'PHP'
<?php
/**
 * Update des Kundenportals. In den Portal-Ordner hochladen und im Browser aufrufen.
 * Ersetzt die Programmdateien, behält config.php und app/data/ und legt sich danach unter
 * Administration → Updates ab (app/data/updates/).
 */
// UPDATE-INFO: __UPDATE_INFO__
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
$dir = __DIR__;
function upd_page(string $title, string $html): never
{
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;color:#1d2733}'
        . '.box{border:1px solid #dde3ea;border-radius:8px;padding:1.2rem 1.4rem}a.btn,button{display:inline-block;background:#1f5fa8;color:#fff;border:0;border-radius:6px;padding:.6rem 1.1rem;text-decoration:none;font:inherit;cursor:pointer}'
        . '.err{color:#b42318}</style></head><body><div class="box"><h1>' . $title . '</h1>' . $html . '</div></body></html>';
    exit;
}
if (!class_exists('ZipArchive')) upd_page('Update nicht möglich', '<p class="err">Auf dem Server fehlt die PHP-Erweiterung „zip“. Bitte die Dateien von Hand hochladen.</p>');
if (!is_file($dir . '/config.php') || !is_file($dir . '/app/bootstrap.php')) {
    upd_page('Falscher Ordner', '<p class="err">Diese Datei gehört in den Ordner des Kundenportals (dort, wo config.php liegt).</p>');
}

// Nur angemeldete Administratoren (Dienstleister) dürfen das Update ausführen
require $dir . '/app/bootstrap.php';
start_session();
$u = current_user();
if (!$u || !in_array($u['role'], ['superadmin', 'admin'], true)) {
    upd_page('Bitte anmelden', '<p>Melden Sie sich zuerst im Portal als Superadmin oder Verwaltungsadmin an und laden Sie diese Seite danach neu.</p>'
        . '<p><a class="btn" href="index.php" target="_blank" rel="noopener">Zur Anmeldung</a></p>');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    upd_page('Kundenportal aktualisieren', '<p>Angemeldet als ' . htmlspecialchars($u['name']) . '. Die Programmdateien werden ersetzt. '
        . '<strong>config.php</strong> und der Ordner <strong>app/data/</strong> (Schlüssel, Sperrdateien) bleiben erhalten, die Datenbank wird beim nächsten Aufruf automatisch angepasst.</p>'
        . '<form method="post"><input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '"><button>Update jetzt einspielen</button></form>');
}
if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) upd_page('Abgelaufen', '<p class="err">Bitte die Seite neu laden.</p>');

$tmp = $dir . '/app/data/update-' . bin2hex(random_bytes(6)) . '.zip';
$fh = fopen(__FILE__, 'rb');
fseek($fh, __COMPILER_HALT_OFFSET__);
file_put_contents($tmp, base64_decode(preg_replace('/\s+/', '', (string)stream_get_contents($fh)), true) ?: '');
fclose($fh);
$zip = new ZipArchive();
if ($zip->open($tmp) !== true) { @unlink($tmp); upd_page('Update fehlgeschlagen', '<p class="err">Das Update-Paket ist beschädigt. Bitte erneut hochladen.</p>'); }
$installed = is_file($dir . '/app/data/installed.lock');
$written = 0;
$errors = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === false || str_contains($name, '..') || str_starts_with($name, '/')) continue;
    if ($name === 'config.php' || (str_starts_with($name, 'app/data/') && $name !== 'app/data/.htaccess')) continue;
    if ($installed && $name === 'install.php') continue;
    $target = $dir . '/' . $name;
    if (str_ends_with($name, '/')) { if (!is_dir($target)) @mkdir($target, 0755, true); continue; }
    if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
    if (file_put_contents($target, $zip->getFromIndex($i)) === false) $errors[] = $name; else $written++;
}
$zip->close();
@unlink($tmp);
if (function_exists('opcache_reset')) @opcache_reset();
if ($errors) upd_page('Update unvollständig', '<p class="err">Diese Dateien konnten nicht geschrieben werden:</p><ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $errors)) . '</li></ul><p>Bitte Schreibrechte prüfen und erneut versuchen.</p>');
// Paket im Update-Archiv ablegen (Administration → Updates) statt es zu löschen
$archived = false;
if (is_file($dir . '/app/updates.php')) {
    try {
        require_once $dir . '/app/updates.php';
        update_store(__FILE__, ['applied_at' => date('Y-m-d H:i:s'), 'applied_by' => $u['name'], 'stored_by' => $u['name'], 'original_name' => basename(__FILE__)], true);
        $archived = true;
    } catch (Throwable $ex) {
        error_log('[kundenportal] Update-Archiv: ' . $ex->getMessage());
    }
}
if (!$archived) @unlink(__FILE__);
upd_page('Update eingespielt', '<p>' . $written . ' Dateien wurden aktualisiert. Die update.php ' . ($archived ? 'liegt jetzt unter <strong>Administration → Updates</strong>.' : 'hat sich selbst gelöscht.') . '</p><p><a class="btn" href="index.php?r=updates">Zum Portal</a></p>');
__HALT_COMPILER();
PHP;

$head = str_replace('__UPDATE_INFO__', base64_encode(json_encode($updateInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $head);
$out = $outDir . '/update.php';
// Paket als Base64-Text anhängen: so übersteht die Datei auch Downloads, die sie als Text behandeln
file_put_contents($out, $head . "\n" . chunk_split(base64_encode((string)file_get_contents($zipFile)), 76, "\n"));
unlink($zipFile);
echo "$out ($n Dateien, " . round(filesize($out) / 1024) . " KB)\n";
