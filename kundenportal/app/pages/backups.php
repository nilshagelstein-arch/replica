<?php
/**
 * Datensicherung: manuelle Sicherung, Liste mit Download, Einstellungen für die tägliche Sicherung.
 * Nur für Superadmin und Verwaltungsadmins.
 */

function page_backups(): void
{
    require_admin();
    // Portal-Sicherungen enthalten alle Firmen: nur mit Zugriff auf alle Firmen
    if (!admin_all_companies()) redirect('company_backups');
    if (is_post()) {
        require_post_csrf();
        $action = post('action');
        if ($action === 'create') {
            try {
                $name = backup_create('manuell');
                $drive = gdrive_after_backup(backup_dir() . '/' . $name, false);
                flash(str_starts_with($drive, 'Upload') ? 'error' : 'success', t('Sicherung „{x}“ wurde erstellt.', ['x' => $name]) . ($drive !== '' ? ' ' . $drive : ''));
            } catch (Throwable $ex) {
                error_log('[kundenportal] Datensicherung fehlgeschlagen: ' . $ex->getMessage());
                flash('error', t('Sicherung fehlgeschlagen: ') . $ex->getMessage());
            }
        } elseif ($action === 'delete') {
            $f = backup_path(post('name'));
            if ($f && @unlink($f)) {
                audit('backup.delete', basename($f));
                flash('success', t('Sicherung gelöscht.'));
            } else {
                flash('error', t('Sicherung nicht gefunden.'));
            }
        } elseif ($action === 'settings') {
            setting_set('backup_auto', post('auto') === '1' ? '1' : '0');
            setting_set('backup_notify', post('notify') === '1' ? '1' : '0');
            setting_set('backup_keep', (string)min(365, max(1, (int)post('keep', BACKUP_KEEP_DEFAULT))));
            $pw = (string)($_POST['password'] ?? '');
            if (post('password_clear') === '1') {
                setting_set('backup_password_enc', '');
            } elseif ($pw !== '') {
                if (!backup_can_encrypt()) {
                    flash('error', t('Dieser Server kann keine verschlüsselten ZIP-Archive erstellen. Das Passwort wurde nicht gespeichert.'));
                    redirect('backups');
                }
                if (mb_strlen($pw) < 10) {
                    flash('error', t('Das Sicherungspasswort muss mindestens 10 Zeichen haben.'));
                    redirect('backups');
                }
                setting_set('backup_password_enc', encrypt_secret($pw));
            }
            audit('backup.settings', 'automatisch: ' . (post('auto') === '1' ? 'ja' : 'nein') . ', behalten: ' . setting_get('backup_keep'));
            flash('success', t('Einstellungen gespeichert.'));
        } elseif (gdrive_handle_post($action, null, backup_dir())) {
            // erledigt
        } elseif ($action === 'token') {
            setting_set('backup_cron_token', '');
            backup_cron_token();
            audit('backup.token', 'Cron-Schlüssel neu erzeugt');
            flash('info', t('Neuer Cron-Schlüssel erzeugt. Bitte die URL im IONOS-Cronjob aktualisieren.'));
        }
        redirect('backups');
    }

    $list = backup_list();
    $total = array_sum(array_column($list, 'size'));
    $cronUrl = backup_cron_url();
    $lastAuto = setting_get('backup_last_auto');
    $lastErr = setting_get('backup_last_error');
    $auto = setting_get('backup_auto', '1') === '1';
    $hasPw = setting_get('backup_password_enc') !== '';
    $free = @disk_free_space(backup_dir());
    $drive = gdrive_connected();
    $inDrive = gdrive_uploaded_names();

    render_page(t('Datensicherung'), function () use ($list, $total, $cronUrl, $lastAuto, $lastErr, $auto, $hasPw, $free, $drive, $inDrive) { ?>
        <?php if ($lastErr !== ''): ?>
            <div class="alert error"><strong><?= e(t('Letzte automatische Sicherung fehlgeschlagen:')) ?></strong> <?= e($lastErr) ?></div>
        <?php elseif ($drive && setting_get('gdrive_last_error') !== ''): ?>
            <div class="alert error"><strong><?= e(t('Letzter Upload nach Google Drive fehlgeschlagen:')) ?></strong> <?= e(setting_get('gdrive_last_error')) ?></div>
        <?php elseif ($auto && ($lastAuto === '' || strtotime($lastAuto) < time() - 36 * 3600)): ?>
            <div class="alert info"><?= e($lastAuto === '' ? t('Die tägliche Sicherung ist eingeschaltet, hat aber noch nie gesichert. Ist der Cronjob eingerichtet (siehe unten)?')
                : t('Die tägliche Sicherung ist eingeschaltet, hat aber seit {x} nicht mehr gesichert. Ist der Cronjob eingerichtet (siehe unten)?', ['x' => fmt_dt($lastAuto)])) ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="create">
                <button class="btn primary"><?= e(t('Jetzt sichern')) ?></button></form>
            <span class="muted"><?= e(t('Sichert die komplette Datenbank (alle Firmen, Benutzer, Aufträge, Formulare, Kunden, Einstellungen) sowie Schlüsseldatei und Konfiguration als ZIP-Archiv.')) ?></span>
            <p class="muted small"><?= e(t('Letzte automatische Sicherung:')) ?> <?= $lastAuto !== '' ? fmt_dt($lastAuto) : '–' ?> · <?= count($list) ?> <?= e(t('Sicherung(en), zusammen')) ?> <?= backup_fmt_size((int)$total) ?><?= $free !== false ? t(' · freier Speicher: ') . backup_fmt_size((int)$free) : '' ?></p>

            <?php if (!$list): ?>
                <p><?= e(t('Noch keine Sicherungen vorhanden.')) ?></p>
            <?php else: ?>
            <table class="table compact">
                <thead><tr><th><?= e(t('Erstellt')) ?></th><th><?= e(t('Art')) ?></th><th><?= e(t('Größe')) ?></th><th><?= e(t('Datei')) ?></th><?php if ($drive): ?><th><?= e(t('Google Drive')) ?></th><?php endif; ?><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $b): ?>
                    <tr>
                        <td><?= e(date(lang_date_format(true), $b['time'])) ?></td>
                        <td><?= $b['kind'] === 'auto' ? 'automatisch' : 'manuell' ?></td>
                        <td><?= e(backup_fmt_size((int)$b['size'])) ?></td>
                        <td><code class="small"><?= e($b['name']) ?></code></td>
                        <?php if ($drive): ?><td><?php if (isset($inDrive[$b['name']])): ?><?= e(t('✓ gespeichert')) ?><?php else: ?>
                            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="gdrive_upload"><input type="hidden" name="name" value="<?= e($b['name']) ?>">
                                <button class="btn small"><?= e(t('Hochladen')) ?></button></form><?php endif; ?></td><?php endif; ?>
                        <td class="actions">
                            <a href="<?= e(url('backup_download', ['name' => $b['name']])) ?>"><?= e(t('Herunterladen')) ?></a>
                            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?= e($b['name']) ?>">
                                <button class="linklike" data-confirm="<?= e(t('Sicherung vom {x} endgültig löschen?', ['x' => date(lang_date_format(true), $b['time'])])) ?>"><?= e(t('Löschen')) ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <p class="muted small"><?= e(t('Die Sicherungen liegen auf demselben Webspace (gesperrter Ordner')) ?> <code>app/data/backups</code><?= e(t('). Laden Sie regelmäßig eine Sicherung herunter, damit sie auch bei einem Ausfall des Hostings verfügbar ist.')) ?></p>
        </div>

        <p><a class="btn" href="<?= e(url('company_backups')) ?>"><?= e(t('Sicherungen der Firmen (App „Datensicherung“) →')) ?></a></p>

        <?php gdrive_card(null, url('backups')); ?>

        <div class="grid2">
            <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
                <h2><?= e(t('Tägliche Sicherung')) ?></h2>
                <label class="check"><input type="checkbox" name="auto" value="1" <?= $auto ? 'checked' : '' ?>> <?= e(t('Täglich automatisch sichern (über den Cronjob)')) ?></label>
                <label><?= e(t('Automatische Sicherungen behalten')) ?>
                    <input type="number" name="keep" min="1" max="365" value="<?= (int)setting_get('backup_keep', (string)BACKUP_KEEP_DEFAULT) ?>"></label>
                <p class="muted small"><?= e(t('Ältere automatische Sicherungen werden gelöscht. Manuelle Sicherungen bleiben, bis Sie sie löschen.')) ?></p>
                <label class="check"><input type="checkbox" name="notify" value="1" <?= setting_get('backup_notify', '1') === '1' ? 'checked' : '' ?>> <?= e(t('Bei Fehlern E-Mail an die Administratoren senden')) ?></label>
                <?php if (backup_can_encrypt()): ?>
                    <label><?= e(t('Sicherungspasswort (optional, AES-256)')) ?>
                        <input type="password" name="password" autocomplete="new-password" placeholder="<?= $hasPw ? t('gespeichert – leer lassen für unverändert') : t('mindestens 10 Zeichen') ?>"></label>
                    <?php if ($hasPw): ?><label class="check"><input type="checkbox" name="password_clear" value="1"> <?= e(t('Passwort entfernen (Sicherungen unverschlüsselt)')) ?></label><?php endif; ?>
                    <p class="muted small"><?= e(t('Mit Passwort sind die ZIP-Archive verschlüsselt. Zum Öffnen z. B. 7-Zip verwenden – der Windows-Explorer kann AES-verschlüsselte ZIPs nicht öffnen. Passwort gut aufbewahren, ohne es ist die Sicherung unbrauchbar.')) ?></p>
                <?php endif; ?>
                <button class="btn primary"><?= e(t('Speichern')) ?></button>
            </form>

            <div class="card">
                <h2><?= e(t('Cronjob bei IONOS (1&1) einrichten')) ?></h2>
                <p><strong><?= e(t('Variante A – im IONOS-Konto')) ?></strong> <?= e(t('(Hosting › Cronjobs › Cronjob erstellen):')) ?></p>
                <ul>
                    <li><?= e(t('Ausführung: stündlich (für die Tickets), mindestens aber täglich')) ?></li>
                    <li><?= e(t('Feld')) ?> <em><?= e(t('HTTP GET')) ?></em>:<br><input readonly value="<?= e($cronUrl) ?>" aria-label="<?= e(t('Cron-URL')) ?>"></li>
                </ul>
                <p><strong><?= e(t('Variante B – per SSH')) ?></strong> (<code>crontab -e</code><?= e(t('), läuft ohne Zeitlimit des Webservers:')) ?></p>
                <p><input readonly value="<?= e('0 * * * * /usr/bin/php8.3 -f ' . ROOT_DIR . '/cron.php') ?>" aria-label="<?= e(t('Crontab-Zeile')) ?>"></p>
                <p class="muted small"><?= e(t('PHP-Version an die Ihres Webspace anpassen (z. B. php8.2, php8.4). Am besten stündlich: Der Cronjob prüft dann auch Erinnerungen und Eskalationen der Tickets; gesichert wird höchstens einmal pro Tag.')) ?></p>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="token">
                    <button class="btn small" data-confirm="<?= e(t('Neuen Schlüssel erzeugen? Die bisherige Cron-URL funktioniert danach nicht mehr.')) ?>"><?= e(t('Neuen Cron-Schlüssel erzeugen')) ?></button></form>
                <p class="muted small"><?= e(t('Die URL enthält einen geheimen Schlüssel – bitte nicht weitergeben.')) ?></p>
            </div>
        </div>
    <?php });
}

function page_backup_download(): void
{
    require_admin();
    require_all_companies();
    $f = backup_path((string)($_GET['name'] ?? ''));
    if (!$f) abort(404, t('Sicherung nicht gefunden.'));
    audit('backup.download', basename($f));
    @set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . (str_ends_with($f, '.zip') ? 'application/zip' : 'application/gzip'));
    header('Content-Disposition: attachment; filename="' . basename($f) . '"');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: no-store');
    readfile($f);
    exit;
}
