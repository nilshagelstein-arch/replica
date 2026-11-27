<?php
/**
 * Administration → Updates: Archiv der Update-Pakete mit Zusammenfassung, Datum und Autor.
 * Ansehen und herunterladen: Superadmin und Verwaltungsadmins. Hochladen, einspielen, bearbeiten, löschen: nur Superadmin.
 */

/** So viele Updates stehen direkt in der Liste; ältere unter „Archiv“ */
const UPDATES_RECENT = 5;

function page_updates(): void
{
    $me = require_admin();
    $super = $me['role'] === 'superadmin';
    $errors = [];

    if (($_GET['download'] ?? '') !== '') {
        $u = update_get((string)$_GET['download']);
        if (!$u) abort(404);
        audit('update.download', $u['id']);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="update-' . preg_replace('/[^0-9-]/', '', (string)$u['date']) . '-' . substr($u['id'], -8) . '.php"');
        header('Content-Length: ' . $u['size']);
        header('X-Content-Type-Options: nosniff');
        readfile($u['file']);
        exit;
    }

    if (is_post()) {
        require_post_csrf();
        if (!$super) abort(403, t('Updates verwaltet nur der Superadmin.'));
        $action = post('action');
        if ($action === 'upload') {
            $f = $_FILES['package'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                $errors[] = t('Bitte eine Update-Datei (update.php) auswählen.');
            } elseif ($f['size'] > 50 * 1048576) {
                $errors[] = t('Die Datei ist zu groß.');
            } else {
                $check = update_check_package($f['tmp_name']);
                if (is_string($check)) {
                    $errors[] = $check;
                } else {
                    $info = update_embedded_info($f['tmp_name']);
                    $date = post('date');
                    $meta = [
                        'summary'       => mb_substr(post('summary') ?: (string)($info['summary'] ?? ''), 0, 5000),
                        'author'        => mb_substr(post('author') ?: (string)($info['author'] ?? $me['name']), 0, 190),
                        'date'          => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : (string)($info['date'] ?? date('Y-m-d')),
                        'files'         => $check,
                        'original_name' => mb_substr(basename((string)$f['name']), 0, 190),
                        'stored_by'     => $me['name'],
                    ];
                    $id = update_store($f['tmp_name'], $meta);
                    audit('update.upload', $id . ' ' . $meta['original_name']);
                    flash('success', t('Das Update wurde abgelegt.') . (post('apply_now') === '1' ? '' : t(' Einspielen können Sie es in der Liste.')));
                    if (post('apply_now') !== '1') redirect('updates');
                    $_POST['id'] = $id;
                    $action = 'apply';
                }
            }
        }
        if ($action === 'apply') {
            $u = update_get(post('id'));
            if (!$u) abort(404);
            if (!password_verify((string)($_POST['current_password'] ?? ''), $me['password_hash'])) {
                $errors[] = t('Ihr Passwort ist falsch. Das Update wurde nicht eingespielt.');
            } else {
                [$written, $errs] = update_apply($u['file']);
                if ($errs) {
                    audit('update.failed', $u['id'] . ': ' . implode(', ', array_slice($errs, 0, 10)));
                    $errors[] = t('Das Update ist unvollständig. Diese Dateien konnten nicht geschrieben werden: ') . implode(', ', $errs);
                } else {
                    $u['applied_at'] = now();
                    $u['applied_by'] = $me['name'];
                    update_save_meta($u['id'], $u);
                    audit('update.applied', $u['id'] . " ($written Dateien)");
                    flash('success', t('Das Update wurde eingespielt ({n} Dateien). Die Datenbank wird bei Bedarf automatisch angepasst.', ['n' => $written]));
                    redirect('updates');
                }
            }
        } elseif ($action === 'edit') {
            $u = update_get(post('id'));
            if (!$u) abort(404);
            $u['summary'] = mb_substr(post('summary'), 0, 5000);
            $u['author'] = mb_substr(post('author'), 0, 190);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', post('date'))) $u['date'] = post('date');
            update_save_meta($u['id'], $u);
            audit('update.edit', $u['id']);
            flash('success', t('Die Angaben wurden gespeichert.'));
            redirect('updates');
        } elseif ($action === 'delete') {
            $u = update_get(post('id'));
            if (!$u) abort(404);
            @unlink($u['file']);
            @unlink(update_meta_path($u['id']));
            audit('update.delete', $u['id']);
            flash('success', t('Das Update wurde aus dem Archiv gelöscht.'));
            redirect('updates');
        }
    }

    $list = updates_list();
    $current = null;
    foreach ($list as $u) {
        if ($u['applied_at'] && (!$current || strcmp($u['applied_at'], $current['applied_at']) > 0)) $current = $u;
    }
    render_page(t('Updates'), function () use ($list, $errors, $super, $current) {
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>'; ?>
        <p class="muted"><?= e(t('Alle Update-Pakete des Portals mit Zusammenfassung, Datum und Autor. Ein Update, das per FTP als')) ?> <code>update.php</code> <?= e(t('eingespielt wird, legt sich danach automatisch hier ab.')) ?></p>
        <?php if ($current): ?>
            <div class="alert info"><?= e(t('Zuletzt eingespielt:')) ?> <strong><?= e(fmt_date($current['date'])) ?></strong>
                <?= $current['summary'] !== '' ? '– ' . e(mb_strimwidth(strtok($current['summary'], "\n"), 0, 120, '…')) : '' ?>
                <?= e(t('(am')) ?> <?= fmt_dt($current['applied_at']) ?><?= $current['applied_by'] ? ' ' . e(t('von')) . ' ' . e($current['applied_by']) : '' ?>)</div>
        <?php endif; ?>

        <div class="card">
            <h2><?= e(t('Versionen')) ?></h2>
            <?php if (count($list) > UPDATES_RECENT): ?><p class="muted small"><?= e(t('Die letzten {n} Updates; ältere stehen unter „Archiv“.', ['n' => UPDATES_RECENT])) ?></p><?php endif; ?>
            <?php if (!$list): ?>
                <p class="muted"><?= e(t('Noch keine Updates abgelegt.')) ?></p>
            <?php else: ?>
            <?php $table = function (array $part) use ($current, $super) { ?>
            <table class="table updates">
                <thead><tr><th><?= e(t('Datum')) ?></th><th><?= e(t('Zusammenfassung')) ?></th><th><?= e(t('Autor')) ?></th><th><?= e(t('Status')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($part as $u): $older = $current && $u['id'] !== $current['id'] && strcmp((string)$u['date'] . $u['id'], (string)$current['date'] . $current['id']) < 0; ?>
                    <tr>
                        <td class="nowrap"><?= e(fmt_date($u['date'])) ?><br><span class="muted small"><?= e(round($u['size'] / 1024)) ?> KB<?= !empty($u['files']) ? ' · ' . (int)$u['files'] . t(' Dateien') : '' ?></span></td>
                        <td><div class="prewrap"><?= $u['summary'] !== '' ? e($u['summary']) : ('<span class="muted">' . e(t('keine Zusammenfassung')) . '</span>') ?></div>
                            <?php if (!empty($u['original_name'])): ?><span class="muted small"><?= e(t('Datei:')) ?> <?= e($u['original_name']) ?></span><?php endif; ?></td>
                        <td><?= e($u['author'] ?: '–') ?></td>
                        <td class="nowrap"><?php if ($current && $u['id'] === $current['id']): ?><span class="badge st-eingerichtet"><?= e(t('aktuell')) ?></span><br><?php endif; ?><?php if ($u['applied_at']): ?><span class="badge st-freigegeben"><?= e(t('eingespielt')) ?></span><br><span class="muted small"><?= fmt_dt($u['applied_at']) ?><?= $u['applied_by'] ? '<br>' . e($u['applied_by']) : '' ?></span>
                            <?php else: ?><span class="badge"><?= e(t('abgelegt')) ?></span><br><span class="muted small"><?= fmt_dt($u['stored_at'] ?? null) ?></span><?php endif; ?></td>
                        <td class="actions">
                            <a href="<?= e(url('updates', ['download' => $u['id']])) ?>"><?= e(t('Herunterladen')) ?></a>
                            <?php if ($super): ?>
                            <details class="update-tools"><summary><?= e(t('Mehr')) ?></summary>
                                <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="id" value="<?= e($u['id']) ?>">
                                    <label><?= e(t('Ihr Passwort zur Bestätigung')) ?><input type="password" name="current_password" required autocomplete="current-password"></label>
                                    <?php if ($older): ?><p class="alert error small"><?= e(t('Älter als das zuletzt eingespielte Update. Einspielen setzt das Portal auf diesen Stand zurück; neuere Funktionen fehlen dann.')) ?></p><?php endif; ?>
                                    <button class="btn <?= $older ? 'danger' : 'primary' ?> small" data-confirm="<?= $older ? t('ACHTUNG: Älteres Update – das Portal wird auf diesen Stand zurückgesetzt. Trotzdem einspielen?') : t('Dieses Update jetzt einspielen? Die Programmdateien werden ersetzt.') ?>"><?= $u['applied_at'] ? t('Erneut einspielen') : t('Einspielen') ?></button></form>
                                <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= e($u['id']) ?>">
                                    <label><?= e(t('Datum')) ?><input type="date" name="date" value="<?= e($u['date']) ?>"></label>
                                    <label><?= e(t('Autor')) ?><input name="author" maxlength="190" value="<?= e($u['author']) ?>"></label>
                                    <label><?= e(t('Zusammenfassung')) ?><textarea name="summary" rows="4"><?= e($u['summary']) ?></textarea></label>
                                    <button class="btn small"><?= e(t('Angaben speichern')) ?></button></form>
                                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($u['id']) ?>">
                                    <button class="btn danger small" data-confirm="<?= e(t('Update aus dem Archiv löschen? Die Programmdateien im Portal bleiben unverändert.')) ?>"><?= e(t('Aus Archiv löschen')) ?></button></form>
                            </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php };
            $recent = array_slice($list, 0, UPDATES_RECENT);
            $archive = array_slice($list, UPDATES_RECENT);
            $table($recent);
            if ($archive): ?>
                <details class="updates-archive">
                    <summary><strong><?= e(t('Archiv')) ?></strong> <span class="muted small">(<?= e(t('{n} ältere Updates', ['n' => count($archive)])) ?>)</span></summary>
                    <?php $table($archive); ?>
                </details>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($super): ?>
        <form method="post" enctype="multipart/form-data" class="card form">
            <?= csrf_field() ?><input type="hidden" name="action" value="upload">
            <h2><?= e(t('Update ablegen')) ?></h2>
            <p class="muted small"><?= e(t('Neue Pakete bringen Zusammenfassung, Datum und Autor selbst mit. Bei älteren Paketen tragen Sie die Angaben hier ein; leere Felder werden aus dem Paket übernommen.')) ?></p>
            <label><?= e(t('Update-Datei (update.php)')) ?><input type="file" name="package" accept=".php" required></label>
            <div class="grid2">
                <label><?= e(t('Datum')) ?><input type="date" name="date"></label>
                <label><?= e(t('Autor')) ?><input name="author" maxlength="190" placeholder="<?= e(t('aus dem Paket oder Ihr Name')) ?>"></label>
            </div>
            <label><?= e(t('Zusammenfassung')) ?><textarea name="summary" rows="4" placeholder="<?= e(t('Was ändert sich mit diesem Update?')) ?>"></textarea></label>
            <label class="check"><input type="checkbox" name="apply_now" value="1"> <?= e(t('Direkt einspielen')) ?></label>
            <label><?= e(t('Ihr Passwort (nur nötig zum direkten Einspielen)')) ?><input type="password" name="current_password" autocomplete="current-password"></label>
            <div class="buttons"><button class="btn primary"><?= e(t('Ablegen')) ?></button></div>
        </form>
        <?php else: ?>
            <p class="muted small"><?= e(t('Updates hochladen und einspielen kann nur der Superadmin.')) ?></p>
        <?php endif; ?>
    <?php });
}
