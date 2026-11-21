<?php
/**
 * Datensicherung: manuelle Sicherung, Liste mit Download, Einstellungen für die tägliche Sicherung.
 * Nur für Superadmin und Verwaltungsadmins.
 */

function page_backups(): void
{
    require_admin();
    if (is_post()) {
        require_post_csrf();
        $action = post('action');
        if ($action === 'create') {
            try {
                $name = backup_create('manuell');
                $drive = gdrive_after_backup(backup_dir() . '/' . $name, false);
                flash(str_starts_with($drive, 'Upload') ? 'error' : 'success', "Sicherung „{$name}“ wurde erstellt." . ($drive !== '' ? ' ' . $drive : ''));
            } catch (Throwable $ex) {
                error_log('[kundenportal] Datensicherung fehlgeschlagen: ' . $ex->getMessage());
                flash('error', 'Sicherung fehlgeschlagen: ' . $ex->getMessage());
            }
        } elseif ($action === 'delete') {
            $f = backup_path(post('name'));
            if ($f && @unlink($f)) {
                audit('backup.delete', basename($f));
                flash('success', 'Sicherung gelöscht.');
            } else {
                flash('error', 'Sicherung nicht gefunden.');
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
                    flash('error', 'Dieser Server kann keine verschlüsselten ZIP-Archive erstellen. Das Passwort wurde nicht gespeichert.');
                    redirect('backups');
                }
                if (mb_strlen($pw) < 10) {
                    flash('error', 'Das Sicherungspasswort muss mindestens 10 Zeichen haben.');
                    redirect('backups');
                }
                setting_set('backup_password_enc', encrypt_secret($pw));
            }
            audit('backup.settings', 'automatisch: ' . (post('auto') === '1' ? 'ja' : 'nein') . ', behalten: ' . setting_get('backup_keep'));
            flash('success', 'Einstellungen gespeichert.');
        } elseif (gdrive_handle_post($action, null, backup_dir())) {
            // erledigt
        } elseif ($action === 'token') {
            setting_set('backup_cron_token', '');
            backup_cron_token();
            audit('backup.token', 'Cron-Schlüssel neu erzeugt');
            flash('info', 'Neuer Cron-Schlüssel erzeugt. Bitte die URL im IONOS-Cronjob aktualisieren.');
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

    render_page('Datensicherung', function () use ($list, $total, $cronUrl, $lastAuto, $lastErr, $auto, $hasPw, $free, $drive, $inDrive) { ?>
        <?php if ($lastErr !== ''): ?>
            <div class="alert error"><strong>Letzte automatische Sicherung fehlgeschlagen:</strong> <?= e($lastErr) ?></div>
        <?php elseif ($drive && setting_get('gdrive_last_error') !== ''): ?>
            <div class="alert error"><strong>Letzter Upload nach Google Drive fehlgeschlagen:</strong> <?= e(setting_get('gdrive_last_error')) ?></div>
        <?php elseif ($auto && ($lastAuto === '' || strtotime($lastAuto) < time() - 36 * 3600)): ?>
            <div class="alert info">Die tägliche Sicherung ist eingeschaltet, hat aber <?= $lastAuto === '' ? 'noch nie' : 'seit ' . fmt_dt($lastAuto) . ' nicht mehr' ?> gesichert. Ist der Cronjob eingerichtet (siehe unten)?</div>
        <?php endif; ?>

        <div class="card">
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="create">
                <button class="btn primary">Jetzt sichern</button></form>
            <span class="muted">Sichert die komplette Datenbank (alle Firmen, Benutzer, Aufträge, Formulare, Kunden, Einstellungen) sowie Schlüsseldatei und Konfiguration als ZIP-Archiv.</span>
            <p class="muted small">Letzte automatische Sicherung: <?= $lastAuto !== '' ? fmt_dt($lastAuto) : '–' ?> · <?= count($list) ?> Sicherung(en), zusammen <?= backup_fmt_size((int)$total) ?><?= $free !== false ? ' · freier Speicher: ' . backup_fmt_size((int)$free) : '' ?></p>

            <?php if (!$list): ?>
                <p>Noch keine Sicherungen vorhanden.</p>
            <?php else: ?>
            <table class="table compact">
                <thead><tr><th>Erstellt</th><th>Art</th><th>Größe</th><th>Datei</th><?php if ($drive): ?><th>Google Drive</th><?php endif; ?><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $b): ?>
                    <tr>
                        <td><?= e(date('d.m.Y H:i', $b['time'])) ?></td>
                        <td><?= $b['kind'] === 'auto' ? 'automatisch' : 'manuell' ?></td>
                        <td><?= e(backup_fmt_size((int)$b['size'])) ?></td>
                        <td><code class="small"><?= e($b['name']) ?></code></td>
                        <?php if ($drive): ?><td><?php if (isset($inDrive[$b['name']])): ?>✓ gespeichert<?php else: ?>
                            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="gdrive_upload"><input type="hidden" name="name" value="<?= e($b['name']) ?>">
                                <button class="btn small">Hochladen</button></form><?php endif; ?></td><?php endif; ?>
                        <td class="actions">
                            <a href="<?= e(url('backup_download', ['name' => $b['name']])) ?>">Herunterladen</a>
                            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?= e($b['name']) ?>">
                                <button class="linklike" data-confirm="Sicherung vom <?= e(date('d.m.Y H:i', $b['time'])) ?> endgültig löschen?">Löschen</button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <p class="muted small">Die Sicherungen liegen auf demselben Webspace (gesperrter Ordner <code>app/data/backups</code>). Laden Sie regelmäßig eine Sicherung herunter, damit sie auch bei einem Ausfall des Hostings verfügbar ist.</p>
        </div>

        <p><a class="btn" href="<?= e(url('company_backups')) ?>">Sicherungen der Firmen (App „Datensicherung“) →</a></p>

        <?php gdrive_card(null, url('backups')); ?>

        <div class="grid2">
            <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
                <h2>Tägliche Sicherung</h2>
                <label class="check"><input type="checkbox" name="auto" value="1" <?= $auto ? 'checked' : '' ?>> Täglich automatisch sichern (über den Cronjob)</label>
                <label>Automatische Sicherungen behalten
                    <input type="number" name="keep" min="1" max="365" value="<?= (int)setting_get('backup_keep', (string)BACKUP_KEEP_DEFAULT) ?>"></label>
                <p class="muted small">Ältere automatische Sicherungen werden gelöscht. Manuelle Sicherungen bleiben, bis Sie sie löschen.</p>
                <label class="check"><input type="checkbox" name="notify" value="1" <?= setting_get('backup_notify', '1') === '1' ? 'checked' : '' ?>> Bei Fehlern E-Mail an die Administratoren senden</label>
                <?php if (backup_can_encrypt()): ?>
                    <label>Sicherungspasswort (optional, AES-256)
                        <input type="password" name="password" autocomplete="new-password" placeholder="<?= $hasPw ? 'gespeichert – leer lassen für unverändert' : 'mindestens 10 Zeichen' ?>"></label>
                    <?php if ($hasPw): ?><label class="check"><input type="checkbox" name="password_clear" value="1"> Passwort entfernen (Sicherungen unverschlüsselt)</label><?php endif; ?>
                    <p class="muted small">Mit Passwort sind die ZIP-Archive verschlüsselt. Zum Öffnen z. B. 7-Zip verwenden – der Windows-Explorer kann AES-verschlüsselte ZIPs nicht öffnen. Passwort gut aufbewahren, ohne es ist die Sicherung unbrauchbar.</p>
                <?php endif; ?>
                <button class="btn primary">Speichern</button>
            </form>

            <div class="card">
                <h2>Cronjob bei IONOS (1&amp;1) einrichten</h2>
                <p><strong>Variante A – im IONOS-Konto</strong> (Hosting › Cronjobs › Cronjob erstellen):</p>
                <ul>
                    <li>Ausführung: täglich, z. B. 3:00 Uhr</li>
                    <li>Feld <em>HTTP GET</em>:<br><input readonly value="<?= e($cronUrl) ?>" aria-label="Cron-URL"></li>
                </ul>
                <p><strong>Variante B – per SSH</strong> (<code>crontab -e</code>), läuft ohne Zeitlimit des Webservers:</p>
                <p><input readonly value="<?= e('0 3 * * * /usr/bin/php8.3 -f ' . ROOT_DIR . '/cron.php') ?>" aria-label="Crontab-Zeile"></p>
                <p class="muted small">PHP-Version an die Ihres Webspace anpassen (z. B. php8.2, php8.4). Der Cronjob darf auch öfter laufen – gesichert wird höchstens einmal pro Tag.</p>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="token">
                    <button class="btn small" data-confirm="Neuen Schlüssel erzeugen? Die bisherige Cron-URL funktioniert danach nicht mehr.">Neuen Cron-Schlüssel erzeugen</button></form>
                <p class="muted small">Die URL enthält einen geheimen Schlüssel – bitte nicht weitergeben.</p>
            </div>
        </div>
    <?php });
}

function page_backup_download(): void
{
    require_admin();
    $f = backup_path((string)($_GET['name'] ?? ''));
    if (!$f) abort(404, 'Sicherung nicht gefunden.');
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
