<?php
/**
 * Cronjob-Einstieg für die tägliche Datensicherung und die Zeitsteuerung der Tickets
 * (Erinnerungen, Eskalationen, Wiedervorlagen) sowie die Erinnerungen aus „Geräte & Lizenzen“ und „Verträge & Laufzeiten“ (inkl. automatischem PDF-Versand der Fristenübersicht). Empfehlung: stündlich aufrufen – gesichert wird höchstens einmal pro Tag.
 *
 * IONOS-Konto (HTTP GET):  https://ihre-domain.de/portal/cron.php?token=GEHEIM
 * SSH-Crontab:             0 * * * * /usr/bin/php8.3 -f /pfad/zum/portal/cron.php
 *
 * Den Schlüssel und die fertige Zeile zeigt das Portal unter „Datensicherung“.
 * Optional: ?force=1 bzw. --force sichert auch, wenn heute schon gesichert wurde.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$cli = PHP_SAPI === 'cli';
if (!is_file(APP_DIR . '/data/installed.lock')) {
    if (!$cli) http_response_code(503);
    exit(t("Portal ist noch nicht installiert.\n"));
}
require APP_DIR . '/schema.php';
require APP_DIR . '/backup.php';
require APP_DIR . '/pages/masterdata.php';
require APP_DIR . '/pages/tickets.php';
require APP_DIR . '/ticket_mail.php';
require APP_DIR . '/pages/inventory.php';
require APP_DIR . '/pages/inv_licenses.php';
require APP_DIR . '/pages/inv_lifecycle.php';
require APP_DIR . '/pages/inv_links.php';
require APP_DIR . '/pages/inv_reminders.php';
require APP_DIR . '/pages/contracts.php';
require APP_DIR . '/pages/ct_inventory.php';
require APP_DIR . '/pdf.php';
require APP_DIR . '/pages/ct_deadlines.php';
require APP_DIR . '/forms_lib.php';
require APP_DIR . '/pages/ct_cancel.php';
require APP_DIR . '/pages/docs.php';
require APP_DIR . '/pages/docs_more.php';
require APP_DIR . '/pages/docs_files.php';
require APP_DIR . '/pages/docs_vault.php';

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $token = (string)($_GET['token'] ?? '');
    $expected = setting_get('backup_cron_token');
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit(t("Zugriff verweigert.\n"));
    }
}

ensure_schema();
// Tickets zuerst: schnell und zeitkritisch
try {
    $tk = tickets_timers_run();
    setting_set('tickets_timer_last', (string)time());
    if ($cli && $tk) echo implode("\n", $tk) . "\n";
} catch (Throwable $ex) {
    error_log('[kundenportal] Ticket-Zeitsteuerung: ' . $ex->getMessage());
}
// Erinnerungen aus „Geräte & Lizenzen“ (jeder Punkt wird nur einmal gemeldet)
try {
    $iv = inv_reminders_run();
    setting_set('inv_reminder_last', (string)time());
    if ($cli && $iv) echo implode("\n", $iv) . "\n";
} catch (Throwable $ex) {
    error_log('[kundenportal] Inventar-Erinnerung: ' . $ex->getMessage());
}
// Fristen aus „Verträge & Laufzeiten“ (Erinnerungen, Status, automatischer PDF-Versand)
try {
    $ct = ct_reminders_run();
    setting_set('ct_reminder_last', (string)time());
    if ($cli && $ct) echo implode("\n", $ct) . "\n";
} catch (Throwable $ex) {
    error_log('[kundenportal] Vertragsfristen: ' . $ex->getMessage());
}
// Dokumente & Anleitungen: fällige Prüfungen (je Fälligkeit eine E-Mail)
try {
    $wk = wk_reviews_run();
    setting_set('wk_review_last', (string)time());
    if ($cli && $wk) echo implode("\n", $wk) . "\n";
} catch (Throwable $ex) {
    error_log('[kundenportal] Wiki-Prüferinnerung: ' . $ex->getMessage());
}
$force = $cli ? in_array('--force', $argv ?? [], true) : !empty($_GET['force']);

if (!$cli) {
    // Der IONOS-Cronjob wartet nur kurz auf eine Antwort: sofort antworten, dann im Hintergrund sichern
    ignore_user_abort(true);
    @set_time_limit(900);
    if (function_exists('fastcgi_finish_request')) {
        echo t("OK – Sicherung wird ausgeführt.\n");
        fastcgi_finish_request();
        backup_daily($force);
        exit;
    }
}
echo backup_daily($force) . "\n";
