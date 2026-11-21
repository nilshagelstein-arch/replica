<?php
/**
 * Cronjob-Einstieg für die tägliche Datensicherung.
 *
 * IONOS-Konto (HTTP GET):  https://ihre-domain.de/portal/cron.php?token=GEHEIM
 * SSH-Crontab:             0 3 * * * /usr/bin/php8.3 -f /pfad/zum/portal/cron.php
 *
 * Den Schlüssel und die fertige Zeile zeigt das Portal unter „Datensicherung“.
 * Optional: ?force=1 bzw. --force sichert auch, wenn heute schon gesichert wurde.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$cli = PHP_SAPI === 'cli';
if (!is_file(APP_DIR . '/data/installed.lock')) {
    if (!$cli) http_response_code(503);
    exit("Portal ist noch nicht installiert.\n");
}
require APP_DIR . '/schema.php';
require APP_DIR . '/backup.php';

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $token = (string)($_GET['token'] ?? '');
    $expected = setting_get('backup_cron_token');
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit("Zugriff verweigert.\n");
    }
}

ensure_schema();
$force = $cli ? in_array('--force', $argv ?? [], true) : !empty($_GET['force']);

if (!$cli) {
    // Der IONOS-Cronjob wartet nur kurz auf eine Antwort: sofort antworten, dann im Hintergrund sichern
    ignore_user_abort(true);
    @set_time_limit(900);
    if (function_exists('fastcgi_finish_request')) {
        echo "OK – Sicherung wird ausgeführt.\n";
        fastcgi_finish_request();
        backup_daily($force);
        exit;
    }
}
echo backup_daily($force) . "\n";
