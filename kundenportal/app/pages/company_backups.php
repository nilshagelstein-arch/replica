<?php
/**
 * App „Datensicherung“ für Firmen: Sicherung der eigenen Daten, Download, tägliche
 * Sicherung über den Cronjob des Portals und Upload ins eigene Google Drive.
 * Firmen-Administratoren (bei freigeschalteter App) und Dienstleister.
 */

function cbackup_company_id(): int
{
    $u = require_app(CBACKUP_APP);
    if (!in_array($u['role'], ['superadmin', 'admin', 'firma_admin'], true)) abort(403, t('Die Datensicherung verwalten nur Firmen-Administratoren.'));
    $cid = context_company_id();
    if (!$cid) abort(404, t('Es ist noch keine Firma angelegt.'));
    require_company_access($cid);
    if (!row('SELECT id FROM companies WHERE id = ?', [$cid])) abort(404);
    return $cid;
}

function page_company_backups(): void
{
    $cid = cbackup_company_id();
    $params = is_admin() ? ['company' => $cid] : [];
    $self = url('company_backups', $params);
    if (is_post()) {
        require_post_csrf();
        $action = post('action');
        if ($action === 'create') {
            try {
                $file = cbackup_create($cid, 'manuell');
                $drive = gdrive_after_backup($file, false, $cid);
                flash(str_starts_with($drive, 'Upload') ? 'error' : 'success', t('Sicherung „') . basename($file) . t('“ wurde erstellt.') . ($drive !== '' ? ' ' . $drive : ''));
            } catch (Throwable $ex) {
                flash('error', t('Sicherung fehlgeschlagen: ') . $ex->getMessage());
            }
        } elseif ($action === 'delete') {
            $f = cbackup_path($cid, (string)post('name'));
            if ($f && @unlink($f)) {
                audit('cbackup.delete', basename($f), $cid);
                flash('success', t('Sicherung gelöscht.'));
            } else {
                flash('error', t('Sicherung nicht gefunden.'));
            }
        } elseif ($action === 'settings') {
            setting_set(gk('cbackup_auto', $cid), post('auto') === '1' ? '1' : '0');
            setting_set(gk('cbackup_notify', $cid), post('notify') === '1' ? '1' : '0');
            setting_set(gk('cbackup_keep', $cid), (string)min(365, max(1, (int)post('keep', CBACKUP_KEEP_DEFAULT))));
            audit('cbackup.settings', 'automatisch: ' . (post('auto') === '1' ? 'ja' : 'nein'), $cid);
            flash('success', t('Einstellungen gespeichert.'));
        } elseif (!gdrive_handle_post($action, $cid, cbackup_dir($cid))) {
            abort(400);
        }
        header('Location: ' . $self);
        exit;
    }

    $list = cbackup_list($cid);
    $drive = gdrive_connected($cid);
    $inDrive = gdrive_uploaded_names($cid);
    $auto = setting_get(gk('cbackup_auto', $cid), '1') === '1';
    $lastAuto = setting_get(gk('cbackup_last_auto', $cid));
    $lastErr = setting_get(gk('cbackup_last_error', $cid));
    $driveErr = $drive ? gdrive_get('gdrive_last_error', $cid) : '';
    $coName = (string)(row('SELECT name FROM companies WHERE id = ?', [$cid])['name'] ?? '');
    $granted = company_has_app($cid, CBACKUP_APP);

    render_page(t('Datensicherung'), function () use ($cid, $params, $self, $list, $drive, $inDrive, $auto, $lastAuto, $lastErr, $driveErr, $coName, $granted) { ?>
        <?php if (is_admin()): ?><div class="toolbar"><?= company_switcher($cid, 'company_backups') ?></div><?php endif; ?>
        <?php if (!$granted): ?>
            <div class="alert info"><?= e(t('Die App „Datensicherung“ ist für diese Firma nicht freigeschaltet. Manuelle Sicherungen sind möglich, die tägliche Sicherung läuft erst nach der Freigabe unter')) ?> <a href="<?= e(url('apps')) ?>"><?= e(t('Apps & Freigaben')) ?></a>.</div>
        <?php endif; ?>
        <?php if ($lastErr !== ''): ?>
            <div class="alert error"><strong><?= e(t('Letzte automatische Sicherung fehlgeschlagen:')) ?></strong> <?= e($lastErr) ?></div>
        <?php elseif ($driveErr !== ''): ?>
            <div class="alert error"><strong><?= e(t('Letzter Upload nach Google Drive fehlgeschlagen:')) ?></strong> <?= e($driveErr) ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="post" action="<?= e($self) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="create">
                <button class="btn primary"><?= e(t('Jetzt sichern')) ?></button></form>
            <span class="muted"><?= e(t('Sichert alle Daten von')) ?> <?= e($coName) ?><?= e(t(': Firmenprofil, Benutzer (ohne Passwörter), Aufträge, Formulare, Briefköpfe, Logo und die Kundendatenbank (zusätzlich als Excel-taugliche CSV-Datei).')) ?></span>
            <p class="muted small"><?= e(t('Letzte automatische Sicherung:')) ?> <?= $lastAuto !== '' ? fmt_dt($lastAuto) : '–' ?> · <?= count($list) ?> <?= e(t('Sicherung(en)')) ?></p>
            <?php if (!$list): ?>
                <p><?= e(t('Noch keine Sicherungen vorhanden.')) ?></p>
            <?php else: ?>
            <table class="table compact">
                <thead><tr><th><?= e(t('Erstellt')) ?></th><th><?= e(t('Art')) ?></th><th><?= e(t('Größe')) ?></th><?php if ($drive): ?><th><?= e(t('Google Drive')) ?></th><?php endif; ?><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $b): ?>
                    <tr>
                        <td><?= e(date(lang_date_format(true), $b['time'])) ?></td>
                        <td><?= $b['kind'] === 'auto' ? 'automatisch' : 'manuell' ?></td>
                        <td><?= e(backup_fmt_size((int)$b['size'])) ?></td>
                        <?php if ($drive): ?><td><?php if (isset($inDrive[$b['name']])): ?><?= e(t('✓ gespeichert')) ?><?php else: ?>
                            <form method="post" action="<?= e($self) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="gdrive_upload"><input type="hidden" name="name" value="<?= e($b['name']) ?>">
                                <button class="btn small"><?= e(t('Hochladen')) ?></button></form><?php endif; ?></td><?php endif; ?>
                        <td class="actions">
                            <a href="<?= e(url('company_backup_download', $params + ['name' => $b['name']])) ?>"><?= e(t('Herunterladen')) ?></a>
                            <form method="post" action="<?= e($self) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?= e($b['name']) ?>">
                                <button class="linklike" data-confirm="<?= e(t('Sicherung vom {x} endgültig löschen?', ['x' => date(lang_date_format(true), $b['time'])])) ?>"><?= e(t('Löschen')) ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <p class="muted small"><?= e(t('Tägliche Sicherung, Aufbewahrung, Fehler-Mail und Google Drive:')) ?> <a href="<?= e(url('backup_settings', $params)) ?>"><?= e(t('Einstellungen')) ?></a></p>
    <?php });
}

function page_company_backup_download(): void
{
    $cid = cbackup_company_id();
    $f = cbackup_path($cid, (string)($_GET['name'] ?? ''));
    if (!$f) abort(404, t('Sicherung nicht gefunden.'));
    audit('cbackup.download', basename($f), $cid);
    @set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($f) . '"');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: no-store');
    readfile($f);
    exit;
}
