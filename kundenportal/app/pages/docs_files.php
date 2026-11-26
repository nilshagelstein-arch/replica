<?php
/**
 * App „Dokumente & Anleitungen“ – Stufe 3: Freigabe (Entwurf → veröffentlicht) mit Prüfdatum und Erinnerung,
 * Dateien mit Versionsstand und Seiten des Dienstleisters für alle Kunden (Firma 0, siehe wk_is_hub()).
 *
 * - Entwürfe sehen nur Personen mit Bearbeiten-Recht im Bereich; Leser sehen nur veröffentlichte Seiten
 *   (auch nicht, wenn eine übergeordnete Seite noch Entwurf ist).
 * - Prüfdatum: „Prüfen alle n Monate“ je Seite; fällige Seiten erscheinen unter „Zu prüfen“, die verantwortliche
 *   Person (sonst die Firmen-Admins bzw. der Superadmin) bekommt einmal je Fälligkeit eine E-Mail (cron.php oder Seitenaufrufe).
 * - Dateien: je Datei beliebig viele Versionen (wk_file_versions), gespeichert unter app/data/docs/<Firma>/.
 *   Lädt man eine Datei mit gleichem Namen auf dieselbe Seite, wird sie zur neuen Version.
 * - Für alle Kunden: Firmen sehen die veröffentlichten Seiten, können sie aber nicht kommentieren; mit
 *   „In unser Wiki kopieren“ entsteht ein Entwurf im eigenen Wiki.
 */

declare(strict_types=1);

const WK_REVIEW_MONTHS = [0, 3, 6, 12, 24];
const WK_REVIEW_INTERVAL = 3600;

function wk_files_dir(int $cid): string
{
    $dir = APP_DIR . '/data/docs/' . $cid;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

function wk_add_months(string $date, int $months): string
{
    $d = new DateTimeImmutable($date);
    $t = $d->modify('first day of this month')->modify("+$months months");
    return $t->setDate((int)$t->format('Y'), (int)$t->format('m'), min((int)$d->format('d'), (int)$t->format('t')))->format('Y-m-d');
}

/** Benutzer, die im Bereich bearbeiten dürfen (für „Verantwortlich“) */
function wk_space_editors(int $cid, int $spaceId): array
{
    $sql = wk_is_hub($cid) ? "SELECT * FROM users WHERE role IN ('superadmin', 'admin') AND active = 1 ORDER BY name"
        : 'SELECT * FROM users WHERE company_id = ? AND active = 1 ORDER BY name';
    $out = [];
    foreach (rows($sql, wk_is_hub($cid) ? [] : [$cid]) as $u) {
        if ((wk_space_perms($cid, $u)[$spaceId] ?? 0) >= WK_PERM_EDIT) $out[(int)$u['id']] = $u;
    }
    return $out;
}

/** Felder „Prüfen alle“ und „Verantwortlich“ im Editor */
function wk_review_fields(int $cid, int $spaceId, ?array $page): void
{
    $months = (int)($page['review_months'] ?? 0);
    $owner = (int)($page['owner_id'] ?? current_user()['id']);
    $editors = wk_space_editors($cid, $spaceId); ?>
    <div class="wk-edit-head">
        <label><?= e(t('Inhalt prüfen')) ?> <select name="review_months">
            <?php foreach (WK_REVIEW_MONTHS as $m): ?><option value="<?= $m ?>" <?= $months === $m ? 'selected' : '' ?>><?= e($m ? t('alle {n} Monate', ['n' => $m]) : t('nicht regelmäßig')) ?></option><?php endforeach; ?>
        </select></label>
        <label><?= e(t('Verantwortlich')) ?> <select name="owner_id">
            <option value="0"><?= e(t('– niemand Bestimmtes –')) ?></option>
            <?php foreach ($editors as $id => $u): ?><option value="<?= $id ?>" <?= $owner === $id ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
        </select></label>
    </div>
<?php }

/** Werte aus dem Formular: [status, review_months, owner_id, review_due] */
function wk_review_post(int $cid, int $spaceId, ?array $page, bool $publish): array
{
    $months = (int)post('review_months', 0);
    if (!in_array($months, WK_REVIEW_MONTHS, true)) $months = 0;
    $owner = (int)post('owner_id', 0);
    if ($owner && !isset(wk_space_editors($cid, $spaceId)[$owner])) $owner = (int)($page['owner_id'] ?? 0);
    $status = $publish || ($page['status'] ?? '') === 'published' ? 'published' : 'draft';
    $due = (string)($page['review_due'] ?? '');
    $wasPublished = ($page['status'] ?? '') === 'published';
    if ($months === 0) $due = '';
    elseif ($status === 'published' && (!$wasPublished || $due === '' || $months !== (int)($page['review_months'] ?? 0))) $due = wk_add_months(date('Y-m-d'), $months);
    return ['status' => $status, 'review_months' => $months, 'owner_id' => $owner ?: null, 'review_due' => $due];
}

/** Hinweis zu Entwurf und Prüfung auf der Seite */
function wk_status_bar(int $cid, array $page, int $perm): void
{
    $draft = $page['status'] === 'draft';
    $due = (string)$page['review_due'];
    $over = !$draft && $due !== '' && $due < date('Y-m-d');
    if (!$draft && $due === '' && !$page['owner_id']) return; ?>
    <div class="wk-status <?= $draft ? 'draft' : ($over ? 'over' : '') ?>">
        <span>
            <?php if ($draft): ?><strong><?= e(t('Entwurf')) ?></strong> – <?= e(t('nur für Personen mit Bearbeiten-Recht sichtbar.')) ?>
            <?php elseif ($due !== ''): ?><?= e($over ? t('Prüfung überfällig seit {d}', ['d' => fmt_date($due)]) : t('Nächste Prüfung am {d}', ['d' => fmt_date($due)])) ?><?php endif; ?>
            <?php if ($page['owner_id']): ?><span class="muted small"> · <?= e(t('Verantwortlich: {n}', ['n' => wk_user_name((int)$page['owner_id'])])) ?></span><?php endif; ?>
        </span>
        <?php if ($perm >= WK_PERM_EDIT): ?>
        <form method="post" action="<?= e(wkurl('doc_action')) ?>" class="btn-row">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
            <?php if ($draft): ?><button class="btn small primary" name="action" value="publish"><?= e(t('Veröffentlichen')) ?></button>
            <?php else: ?>
                <?php if ((int)$page['review_months'] > 0): ?><button class="btn small <?= $over ? 'primary' : '' ?>" name="action" value="reviewed"><?= e(t('Als geprüft markieren')) ?></button><?php endif; ?>
                <button class="btn small" name="action" value="unpublish"><?= e(t('Zurück auf Entwurf')) ?></button>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
<?php }

// ---------------------------------------------------------------------------
// Dateien mit Versionen
// ---------------------------------------------------------------------------

function wk_fmt_size(int $b): string
{
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($b / 1024)) . ' KB';
}

function wk_files_card(int $cid, array $page, int $perm): void
{
    $files = rows('SELECT * FROM wk_files WHERE company_id = ? AND page_id = ? ORDER BY name', [$cid, $page['id']]);
    if (!$files && $perm < WK_PERM_EDIT) return;
    $versions = [];
    if ($files) foreach (rows('SELECT v.*, u.name AS user_name FROM wk_file_versions v LEFT JOIN users u ON u.id = v.user_id WHERE v.company_id = ? AND v.file_id IN ('
        . implode(',', array_map(fn($f) => (int)$f['id'], $files)) . ') ORDER BY v.version_no DESC', [$cid]) as $v) $versions[(int)$v['file_id']][] = $v; ?>
    <div class="card wk-files" id="dateien">
        <h2><?= e(t('Dateien')) ?> <span class="muted small">(<?= count($files) ?>)</span></h2>
        <?php if ($files): ?>
        <table class="table">
            <thead><tr><th><?= e(t('Datei')) ?></th><th><?= e(t('Version')) ?></th><th><?= e(t('Größe')) ?></th><th><?= e(t('Hochgeladen')) ?></th><?php if ($perm >= WK_PERM_EDIT): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($files as $f): $vs = $versions[(int)$f['id']] ?? []; $cur = $vs[0] ?? null; if (!$cur) continue; ?>
                <tr>
                    <td><a href="<?= e(wkurl('doc_file', ['id' => $cur['id']])) ?>"><strong><?= e($f['name']) ?></strong></a>
                        <?php if ($cur['note'] !== ''): ?><br><span class="muted small"><?= e($cur['note']) ?></span><?php endif; ?>
                        <?php if (count($vs) > 1): ?>
                        <details class="wk-file-versions"><summary class="small"><?= e(t('Ältere Versionen ({n})', ['n' => count($vs) - 1])) ?></summary>
                            <ul><?php foreach (array_slice($vs, 1) as $v): ?>
                                <li><a href="<?= e(wkurl('doc_file', ['id' => $v['id']])) ?>"><?= e(t('Version {n}', ['n' => $v['version_no']])) ?></a>
                                    <span class="muted small"><?= fmt_dt($v['created_at']) ?> · <?= e($v['user_name'] ?? '–') ?> · <?= e(wk_fmt_size((int)$v['size'])) ?><?= $v['note'] !== '' ? ' · ' . e($v['note']) : '' ?></span></li>
                            <?php endforeach; ?></ul></details>
                        <?php endif; ?></td>
                    <td><?= (int)$cur['version_no'] ?></td>
                    <td class="nowrap"><?= e(wk_fmt_size((int)$cur['size'])) ?></td>
                    <td class="nowrap"><?= fmt_dt($cur['created_at']) ?><br><span class="muted small"><?= e($cur['user_name'] ?? '–') ?></span></td>
                    <?php if ($perm >= WK_PERM_EDIT): ?>
                    <td class="nowrap">
                        <details class="wk-file-new"><summary class="btn small"><?= e(t('Neue Version')) ?></summary>
                            <form method="post" action="<?= e(wkurl('doc_action')) ?>" enctype="multipart/form-data" class="form">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                                <input type="file" name="files[]" required>
                                <input name="note" maxlength="255" placeholder="<?= e(t('Was hat sich geändert? (optional)')) ?>">
                                <button class="btn small primary" name="action" value="file_version"><?= e(t('Hochladen')) ?></button>
                            </form></details>
                        <form method="post" action="<?= e(wkurl('doc_action')) ?>" class="inline" data-confirm="<?= e(t('Diese Datei mit allen Versionen löschen?')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                            <button class="btn-link small" name="action" value="file_delete"><?= e(t('löschen')) ?></button></form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php elseif ($perm >= WK_PERM_EDIT): ?><p class="muted small"><?= e(t('Noch keine Dateien. Hier können Sie zum Beispiel Handbücher als PDF, Netzwerkpläne oder das Notfallhandbuch ablegen.')) ?></p><?php endif; ?>
        <?php if ($perm >= WK_PERM_EDIT): ?>
        <form method="post" action="<?= e(wkurl('doc_action')) ?>" enctype="multipart/form-data" class="form wk-file-add">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
            <label><?= e(t('Dateien hinzufügen')) ?> <input type="file" name="files[]" multiple required></label>
            <button class="btn" name="action" value="file_add"><?= e(t('Hochladen')) ?></button>
            <p class="muted small"><?= e(t('Höchstens 10 MB je Datei. Eine Datei mit gleichem Namen wird zur neuen Version.')) ?></p>
        </form>
        <?php endif; ?>
    </div>
<?php }

/** Eine hochgeladene Datei als (neue Version einer) Datei der Seite speichern; gibt die Versionsnummer zurück */
function wk_file_store(int $cid, array $page, array $fl, ?int $fileId, string $note): int
{
    $dir = wk_files_dir($cid);
    $stored = $page['id'] . '-' . bin2hex(random_bytes(12)) . '.bin';
    if (!move_uploaded_file($fl['tmp'], $dir . '/' . $stored)) return 0;
    @chmod($dir . '/' . $stored, 0640);
    $mime = function_exists('mime_content_type') ? (string)@mime_content_type($dir . '/' . $stored) : 'application/octet-stream';
    if ($fileId === null) {
        $ex = row('SELECT id FROM wk_files WHERE company_id = ? AND page_id = ? AND name = ?', [$cid, $page['id'], $fl['name']]);
        $fileId = $ex ? (int)$ex['id'] : insert('wk_files', ['company_id' => $cid, 'page_id' => $page['id'], 'name' => $fl['name'],
            'created_by' => current_user()['id'], 'created_at' => now(), 'updated_at' => now()]);
    }
    $no = (int)(row('SELECT MAX(version_no) AS m FROM wk_file_versions WHERE company_id = ? AND file_id = ?', [$cid, $fileId])['m'] ?? 0) + 1;
    insert('wk_file_versions', ['company_id' => $cid, 'file_id' => $fileId, 'version_no' => $no, 'filename' => $fl['name'], 'mime' => mb_substr($mime, 0, 100),
        'size' => $fl['size'], 'stored_name' => $stored, 'note' => mb_substr($note, 0, 255), 'user_id' => current_user()['id'], 'created_at' => now()]);
    update('wk_files', $fileId, ['updated_at' => now()]);
    return $no;
}

function wk_file_delete(int $cid, int $fileId): void
{
    foreach (rows('SELECT stored_name FROM wk_file_versions WHERE company_id = ? AND file_id = ?', [$cid, $fileId]) as $v) @unlink(wk_files_dir($cid) . '/' . basename((string)$v['stored_name']));
    q('DELETE FROM wk_file_versions WHERE company_id = ? AND file_id = ?', [$cid, $fileId]);
    q('DELETE FROM wk_files WHERE company_id = ? AND id = ?', [$cid, $fileId]);
}

function page_doc_file(): void
{
    $cid = wk_company_id();
    $v = row('SELECT * FROM wk_file_versions WHERE id = ? AND company_id = ?', [query_int('id'), $cid]);
    if (!$v) abort(404);
    $f = row('SELECT * FROM wk_files WHERE id = ? AND company_id = ?', [$v['file_id'], $cid]);
    $page = $f ? wk_page($cid, (int)$f['page_id']) : null;
    if (!$page || !wk_page_visible($cid, $page)) abort(404);
    $path = wk_files_dir($cid) . '/' . basename((string)$v['stored_name']);
    if (!is_file($path)) abort(404);
    $inline = in_array($v['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'], true);
    header('Content-Type: ' . ($inline ? $v['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode((string)$v['filename']));
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

// ---------------------------------------------------------------------------
// Für alle Kunden: Seite ins eigene Wiki kopieren
// ---------------------------------------------------------------------------

/** Formular „In unser Wiki kopieren“ (nur Firmenbenutzer mit Bearbeiten-Recht in einem eigenen Bereich) */
function wk_copy_card(array $page): void
{
    $u = current_user();
    $own = (int)($u['company_id'] ?? 0);
    if (is_staff_role($u['role']) || !$own) return;
    $perms = wk_space_perms($own);
    $ids = array_keys(array_filter($perms, fn($p) => $p >= WK_PERM_EDIT));
    if (!$ids) return;
    $spaces = rows('SELECT id, name FROM wk_spaces WHERE company_id = ? AND id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY sort, name', [$own]); ?>
    <form method="post" action="<?= e(wkurl('doc_action')) ?>" class="card wk-copy">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
        <label><?= e(t('In unser Wiki kopieren, Bereich:')) ?> <select name="target_space"><?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
        <button class="btn" name="action" value="copy_to_company"><?= e(t('Kopieren')) ?></button>
        <p class="muted small"><?= e(t('Die Kopie entsteht als Entwurf und lässt sich dann anpassen. Spätere Änderungen Ihres IT-Dienstleisters übernimmt sie nicht automatisch.')) ?></p>
    </form>
<?php }

// ---------------------------------------------------------------------------
// Aktionen der Stufe 3 (aus page_doc_action)
// ---------------------------------------------------------------------------

function wk_action_stage3(int $cid, array $page, string $action): void
{
    $sid = (int)$page['space_id'];
    $me = current_user();
    $back = function (string $anchor = '') use ($page): never {
        header('Location: ' . wkurl('doc', ['id' => $page['id']]) . $anchor);
        exit;
    };
    switch ($action) {
        case 'copy_to_company':
            if (!wk_is_hub($cid) || is_staff_role($me['role'])) abort(403);
            $own = (int)$me['company_id'];
            $target = wk_space($own, (int)post('target_space', 0));
            if (!$target || (wk_space_perms($own)[(int)$target['id']] ?? 0) < WK_PERM_EDIT) abort(403);
            $sort = (int)(row('SELECT MAX(sort) AS m FROM wk_pages WHERE company_id = ? AND space_id = ? AND parent_id IS NULL', [$own, $target['id']])['m'] ?? 0) + 10;
            $pid = insert('wk_pages', ['company_id' => $own, 'space_id' => $target['id'], 'parent_id' => null, 'title' => $page['title'], 'body' => $page['body'],
                'status' => 'draft', 'sort' => $sort, 'created_by' => $me['id'], 'updated_by' => $me['id'], 'created_at' => now(), 'updated_at' => now()]);
            wk_version_add($own, $pid, (string)$page['title'], (string)$page['body'], (int)$me['id']);
            wk_watch_set($own, $pid, (int)$me['id'], true);
            audit('docs.copy_from_provider', '#' . $page['id'] . ' → #' . $pid . ' ' . $page['title'], $own);
            flash('success', t('Die Seite wurde als Entwurf in Ihr Wiki kopiert.'));
            header('Location: ' . url('doc', ['id' => $pid]));
            exit;
    }
    // ab hier: Bearbeiten-Recht im Bereich
    if (!in_array($action, ['publish', 'unpublish', 'reviewed', 'file_add', 'file_version', 'file_delete'], true)) return;
    wk_require_space($cid, $sid, WK_PERM_EDIT);
    switch ($action) {
        case 'publish':
            $due = (int)$page['review_months'] > 0 ? wk_add_months(date('Y-m-d'), (int)$page['review_months']) : '';
            update('wk_pages', (int)$page['id'], ['status' => 'published', 'review_due' => $due, 'review_notified' => '']);
            wk_log($cid, 'page_publish', '#' . $page['id'] . ' ' . $page['title']);
            flash('success', t('Die Seite ist veröffentlicht und für alle Leser des Bereichs sichtbar.'));
            $back();
        case 'unpublish':
            update('wk_pages', (int)$page['id'], ['status' => 'draft']);
            wk_log($cid, 'page_unpublish', '#' . $page['id'] . ' ' . $page['title']);
            flash('success', t('Die Seite ist wieder ein Entwurf.'));
            $back();
        case 'reviewed':
            if ((int)$page['review_months'] <= 0) abort(400);
            $due = wk_add_months(date('Y-m-d'), (int)$page['review_months']);
            update('wk_pages', (int)$page['id'], ['review_due' => $due, 'review_notified' => '']);
            wk_log($cid, 'page_reviewed', '#' . $page['id'] . ' ' . $page['title'] . ' → ' . $due);
            flash('success', t('Als geprüft markiert. Nächste Prüfung am {d}.', ['d' => fmt_date($due)]));
            $back();
        case 'file_add':
        case 'file_version':
            [$files, $err] = ticket_uploads();
            if ($err) { flash('error', $err); $back('#dateien'); }
            if (!$files) { flash('error', t('Bitte eine Datei auswählen.')); $back('#dateien'); }
            $fileId = null;
            if ($action === 'file_version') {
                $f = row('SELECT id FROM wk_files WHERE id = ? AND page_id = ? AND company_id = ?', [(int)post('file_id', 0), $page['id'], $cid]);
                if (!$f) abort(404);
                $fileId = (int)$f['id'];
                $files = array_slice($files, 0, 1);
            }
            $names = [];
            foreach ($files as $fl) if (wk_file_store($cid, $page, $fl, $fileId, $action === 'file_version' ? trim((string)post('note')) : '')) $names[] = $fl['name'];
            if ($names) {
                wk_log($cid, 'file_upload', '#' . $page['id'] . ' ' . implode(', ', $names));
                wk_notify($cid, $page, 'change', t('Neue Dateien bzw. Versionen: {n}', ['n' => implode(', ', $names)]));
                flash('success', t('{n} Datei(en) gespeichert.', ['n' => count($names)]));
            }
            $back('#dateien');
        case 'file_delete':
            $f = row('SELECT * FROM wk_files WHERE id = ? AND page_id = ? AND company_id = ?', [(int)post('file_id', 0), $page['id'], $cid]);
            if (!$f) abort(404);
            wk_file_delete($cid, (int)$f['id']);
            wk_log($cid, 'file_delete', '#' . $page['id'] . ' ' . $f['name']);
            flash('success', t('Die Datei wurde gelöscht.'));
            $back('#dateien');
    }
}

// ---------------------------------------------------------------------------
// Erinnerung an fällige Prüfungen
// ---------------------------------------------------------------------------

/** Mails für fällige Prüfungen (je Fälligkeit nur einmal). Gibt Protokollzeilen zurück. */
function wk_reviews_run(): array
{
    $log = [];
    $today = date('Y-m-d');
    $due = rows("SELECT * FROM wk_pages WHERE status = 'published' AND review_months > 0 AND review_due <> '' AND review_due <= ? AND review_notified <> review_due LIMIT 500", [$today]);
    foreach ($due as $p) {
        $cid = (int)$p['company_id'];
        q('UPDATE wk_pages SET review_notified = ? WHERE id = ?', [$p['review_due'], $p['id']]);
        if ($cid > 0 && !company_has_app($cid, WK_APP)) continue;
        $to = [];
        $owner = $p['owner_id'] ? row('SELECT * FROM users WHERE id = ? AND active = 1', [$p['owner_id']]) : null;
        if ($owner && (wk_space_perms($cid, $owner)[(int)$p['space_id']] ?? 0) >= WK_PERM_EDIT) $to[] = $owner;
        if (!$to) $to = $cid > 0 ? rows("SELECT * FROM users WHERE company_id = ? AND role = 'firma_admin' AND active = 1", [$cid])
            : rows("SELECT * FROM users WHERE role = 'superadmin' AND active = 1");
        $link = wk_page_link((int)$p['id'], $cid);
        foreach ($to as $u) {
            try {
                with_lang(user_lang($u), function () use ($u, $p, $link, $cid) {
                    send_mail([(string)$u['email']], t('Seite prüfen: {t}', ['t' => $p['title']]),
                        t('Guten Tag {n},', ['n' => $u['name']]) . "\n\n"
                        . t('die Seite „{t}“ sollte bis {d} geprüft werden. Bitte lesen Sie sie durch, passen Sie sie bei Bedarf an und wählen Sie „Als geprüft markieren“.', ['t' => $p['title'], 'd' => fmt_date($p['review_due'])])
                        . "\n\n" . t('Zur Seite:') . ' ' . $link, $cid ?: null);
                });
            } catch (Throwable $ex) {
                error_log('[kundenportal] Wiki-Prüferinnerung: ' . $ex->getMessage());
            }
        }
        $log[] = 'Wiki: Prüfung fällig – #' . $p['id'] . ' ' . $p['title'] . ' (' . count($to) . ' Empfänger)';
    }
    // Tresor: „Ändern bis“ (Stufe 5)
    if (function_exists('wkv_reminders_run')) $log = array_merge($log, wkv_reminders_run());
    return $log;
}

/** Ohne Cronjob: höchstens stündlich bei Seitenaufrufen */
function wk_reviews_tick(): void
{
    try {
        $last = setting_get('wk_review_last');
        if ($last !== '' && (int)$last > time() - WK_REVIEW_INTERVAL) return;
        if ($last === '') setting_set('wk_review_last', '0');
        if (q('UPDATE settings SET value = ? WHERE name = ? AND value = ?', [(string)time(), 'wk_review_last', $last === '' ? '0' : $last])->rowCount() !== 1) return;
        wk_reviews_run();
    } catch (Throwable $ex) {
        error_log('[kundenportal] Wiki-Prüferinnerung: ' . $ex->getMessage());
    }
}
