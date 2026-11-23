<?php
/**
 * App „Formulare“: Übersicht mit Ordnern, Editor, Briefkopf/Fußzeile,
 * Einstellungen (Logo, Briefpapier, API) und Erstellen/Drucken.
 */

// ---------------------------------------------------------------------------
// Hilfen
// ---------------------------------------------------------------------------

function folder_tree(int $cid): array
{
    $all = rows('SELECT * FROM form_folders WHERE company_id = ? ORDER BY name', [$cid]);
    $byParent = [];
    foreach ($all as $f) $byParent[(int)($f['parent_id'] ?? 0)][] = $f;
    return [$all, $byParent];
}

/** Ordner als eingerückte Auswahlliste: id => "— Name" */
function folder_options(int $cid, ?int $exclude = null): array
{
    [, $byParent] = folder_tree($cid);
    $out = [];
    $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent, $exclude) {
        foreach ($byParent[$parent] ?? [] as $f) {
            if ($exclude !== null && (int)$f['id'] === $exclude) continue;
            $out[(int)$f['id']] = str_repeat('— ', $depth) . $f['name'];
            $walk((int)$f['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $out;
}

function folder_path(int $cid, int $folderId): array
{
    $path = [];
    for ($i = 0; $folderId && $i < 30; $i++) {
        $f = row('SELECT * FROM form_folders WHERE id = ? AND company_id = ?', [$folderId, $cid]);
        if (!$f) break;
        array_unshift($path, $f);
        $folderId = (int)($f['parent_id'] ?? 0);
    }
    return $path;
}

function load_form(int $cid, int $id): array
{
    $f = row('SELECT * FROM forms WHERE id = ? AND company_id = ?', [$id, $cid]);
    if (!$f) abort(404, t('Das Formular wurde nicht gefunden.'));
    return $f;
}

function forms_subtitle(int $cid): string
{
    return is_admin() ? company_switcher($cid, (string)($_GET['r'] ?? 'forms')) : '';
}

// ---------------------------------------------------------------------------
// Übersicht
// ---------------------------------------------------------------------------

function page_forms(): void
{
    $cid = forms_company_id();
    $folderId = query_int('folder');
    $search = trim((string)($_GET['q'] ?? ''));
    $folder = $folderId ? row('SELECT * FROM form_folders WHERE id = ? AND company_id = ?', [$folderId, $cid]) : null;
    if ($folderId && !$folder) $folderId = 0;
    [$allFolders, $byParent] = folder_tree($cid);
    $counts = [];
    foreach (rows('SELECT folder_id, COUNT(*) AS n FROM forms WHERE company_id = ? GROUP BY folder_id', [$cid]) as $c) $counts[(int)$c['folder_id']] = (int)$c['n'];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $forms = rows('SELECT f.*, d.name AS folder_name FROM forms f LEFT JOIN form_folders d ON d.id = f.folder_id
            WHERE f.company_id = ? AND (f.name LIKE ? OR f.description LIKE ?) ORDER BY f.name', [$cid, $like, $like]);
    } elseif ($folderId) {
        $forms = rows('SELECT f.*, NULL AS folder_name FROM forms f WHERE f.company_id = ? AND f.folder_id = ? ORDER BY f.name', [$cid, $folderId]);
    } else {
        $forms = rows('SELECT f.*, NULL AS folder_name FROM forms f WHERE f.company_id = ? AND f.folder_id IS NULL ORDER BY f.name', [$cid]);
    }
    $sub = $search === '' ? ($byParent[$folderId] ?? []) : [];
    $path = $folderId ? folder_path($cid, $folderId) : [];
    $options = folder_options($cid);

    render_page(t('Formulare'), function () use ($cid, $folderId, $folder, $search, $forms, $sub, $path, $byParent, $counts, $options) { ?>
        <?= forms_subtitle($cid) ?>
        <div class="forms-layout">
            <aside class="card folder-nav">
                <h2><?= e(t('Ordner')) ?></h2>
                <ul class="tree">
                    <li class="<?= !$folderId && $search === '' ? 'active' : '' ?>"><a href="<?= e(furl('forms')) ?>"><?= e(t('📁 Alle Ordner')) ?></a>
                        <?php if ($counts[0] ?? 0): ?><span class="muted small">(<?= $counts[0] ?> <?= e(t('ohne Ordner)')) ?></span><?php endif; ?></li>
                    <?php
                    $walk = function (int $parent) use (&$walk, $byParent, $folderId, $counts) {
                        if (empty($byParent[$parent])) return;
                        echo '<ul>';
                        foreach ($byParent[$parent] as $f) {
                            $id = (int)$f['id'];
                            echo '<li class="' . ($id === $folderId ? 'active' : '') . '"><a href="' . e(furl('forms', ['folder' => $id])) . '">📁 ' . e($f['name']) . '</a>'
                                . (($counts[$id] ?? 0) ? ' <span class="muted small">' . $counts[$id] . '</span>' : '');
                            $walk($id);
                            echo '</li>';
                        }
                        echo '</ul>';
                    };
                    $walk(0);
                    ?>
                </ul>
                <form method="post" action="<?= e(furl('form_folder')) ?>" class="form compact-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="parent_id" value="<?= (int)$folderId ?>">
                    <label><?= e(t('Neuer Ordner')) ?><?= $folder ? t(' in „') . e($folder['name']) . '“' : '' ?>
                        <input name="name" required maxlength="190" placeholder="<?= e(t('z. B. Verträge')) ?>"></label>
                    <button class="btn small"><?= e(t('Ordner anlegen')) ?></button>
                </form>
            </aside>

            <section>
                <div class="toolbar">
                    <form method="get" class="toolbar">
                        <input type="hidden" name="r" value="forms">
                        <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
                        <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('Formulare durchsuchen')) ?>">
                        <button class="btn small"><?= e(t('Suchen')) ?></button>
                        <?php if ($search !== ''): ?><a href="<?= e(furl('forms')) ?>"><?= e(t('Suche aufheben')) ?></a><?php endif; ?>
                    </form>
                    <a class="btn primary push-right" href="<?= e(furl('form_edit', $folderId ? ['folder' => $folderId] : [])) ?>"><?= e(t('+ Neues Formular')) ?></a>
                </div>

                <?php if ($search === ''): ?>
                <p class="breadcrumb"><a href="<?= e(furl('forms')) ?>"><?= e(t('Alle Ordner')) ?></a>
                    <?php foreach ($path as $p): ?> › <a href="<?= e(furl('forms', ['folder' => $p['id']])) ?>"><?= e($p['name']) ?></a><?php endforeach; ?></p>
                <?php endif; ?>

                <?php if ($folder): ?>
                <details class="card folder-tools">
                    <summary><?= e(t('Ordner „')) ?><?= e($folder['name']) ?><?= e(t('“ bearbeiten')) ?></summary>
                    <div class="inline-form">
                        <form method="post" action="<?= e(furl('form_folder')) ?>" class="inline-form">
                            <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$folder['id'] ?>">
                            <input name="name" value="<?= e($folder['name']) ?>" required maxlength="190" aria-label="<?= e(t('Name')) ?>">
                            <select name="parent_id" aria-label="<?= e(t('Übergeordneter Ordner')) ?>">
                                <option value="0"><?= e(t('(oberste Ebene)')) ?></option>
                                <?php foreach (folder_options($cid, (int)$folder['id']) as $id => $label): ?>
                                    <option value="<?= $id ?>" <?= (int)$folder['parent_id'] === $id ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn small"><?= e(t('Speichern')) ?></button>
                        </form>
                        <form method="post" action="<?= e(furl('form_folder')) ?>" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$folder['id'] ?>">
                            <button class="btn small danger" data-confirm="<?= e(t('Ordner „{x}“ löschen? Enthaltene Formulare und Unterordner wandern eine Ebene nach oben.', ['x' => $folder['name']])) ?>"><?= e(t('Ordner löschen')) ?></button>
                        </form>
                    </div>
                </details>
                <?php endif; ?>

                <?php if ($sub): ?>
                <div class="folder-grid">
                    <?php foreach ($sub as $f): ?>
                        <a class="folder-tile" href="<?= e(furl('forms', ['folder' => $f['id']])) ?>"><span>📁</span><?= e($f['name']) ?>
                            <span class="muted small"><?= ($counts[(int)$f['id']] ?? 0) ?> <?= e(t('Formular(e)')) ?></span></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($forms || !$sub): ?>
                <div class="card">
                <?php if (!$forms): ?>
                    <p class="muted"><?= $search !== '' ? t('Keine Formulare gefunden.') : ($sub ? t('In dieser Ebene liegen nur Ordner.') : t('Hier liegen noch keine Formulare.')) ?></p>
                <?php else: ?>
                    <table class="table">
                        <thead><tr><th><?= e(t('Formular')) ?></th><?php if ($search !== ''): ?><th><?= e(t('Ordner')) ?></th><?php endif; ?><th><?= e(t('Geändert')) ?></th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($forms as $f): ?>
                            <tr>
                                <td><a href="<?= e(furl('form_use', ['id' => $f['id']])) ?>"><strong><?= e($f['name']) ?></strong></a>
                                    <?php if ($f['description'] !== ''): ?><br><span class="muted small"><?= e($f['description']) ?></span><?php endif; ?></td>
                                <?php if ($search !== ''): ?><td><?= e($f['folder_name'] ?? '–') ?></td><?php endif; ?>
                                <td class="small"><?= fmt_dt($f['updated_at']) ?></td>
                                <td class="actions">
                                    <a class="btn small primary" href="<?= e(furl('form_use', ['id' => $f['id']])) ?>"><?= e(t('Erstellen')) ?></a>
                                    <a class="btn small" href="<?= e(furl('form_edit', ['id' => $f['id']])) ?>"><?= e(t('Bearbeiten')) ?></a>
                                    <details class="more"><summary class="btn small">…</summary>
                                        <div class="more-menu">
                                            <form method="post" action="<?= e(furl('form_action')) ?>"><?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="action" value="duplicate">
                                                <button class="linkbtn"><?= e(t('Duplizieren')) ?></button></form>
                                            <form method="post" action="<?= e(furl('form_action')) ?>" class="inline-form"><?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="action" value="move">
                                                <select name="folder_id" aria-label="<?= e(t('Verschieben nach')) ?>"><option value="0"><?= e(t('(ohne Ordner)')) ?></option>
                                                    <?php foreach ($options as $id => $label): ?><option value="<?= $id ?>" <?= (int)$f['folder_id'] === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                                                </select><button class="btn small"><?= e(t('Verschieben')) ?></button></form>
                                            <form method="post" action="<?= e(furl('form_action')) ?>"><?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="action" value="delete">
                                                <button class="linkbtn err-text" data-confirm="<?= e(t('Formular „{x}“ endgültig löschen?', ['x' => $f['name']])) ?>"><?= e(t('Löschen')) ?></button></form>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>
    <?php });
}

function page_form_folder(): void
{
    $cid = forms_company_id();
    require_post_csrf();
    $action = post('action');
    $name = mb_substr(post('name'), 0, 190);
    $parent = (int)post('parent_id', 0);
    if ($parent && !row('SELECT id FROM form_folders WHERE id = ? AND company_id = ?', [$parent, $cid])) $parent = 0;

    if ($action === 'create') {
        if ($name === '') { flash('error', t('Bitte einen Namen angeben.')); fredirect('forms', ['folder' => $parent]); }
        $id = insert('form_folders', ['company_id' => $cid, 'parent_id' => $parent ?: null, 'name' => $name, 'created_at' => now()]);
        audit('form_folder_created', $name, $cid);
        flash('success', t('Der Ordner wurde angelegt.'));
        fredirect('forms', ['folder' => $id]);
    }
    $f = row('SELECT * FROM form_folders WHERE id = ? AND company_id = ?', [(int)post('id', 0), $cid]);
    if (!$f) abort(404);
    if ($action === 'rename') {
        // kein Verschieben in sich selbst oder einen eigenen Unterordner
        foreach (folder_path($cid, $parent) as $p) {
            if ((int)$p['id'] === (int)$f['id']) { flash('error', t('Ein Ordner kann nicht in sich selbst verschoben werden.')); fredirect('forms', ['folder' => $f['id']]); }
        }
        if ($name !== '') q('UPDATE form_folders SET name = ?, parent_id = ? WHERE id = ?', [$name, $parent ?: null, $f['id']]);
        flash('success', t('Der Ordner wurde gespeichert.'));
        fredirect('forms', ['folder' => $f['id']]);
    }
    if ($action === 'delete') {
        $up = $f['parent_id'] !== null ? (int)$f['parent_id'] : null;
        q('UPDATE forms SET folder_id = ? WHERE folder_id = ? AND company_id = ?', [$up, $f['id'], $cid]);
        q('UPDATE form_folders SET parent_id = ? WHERE parent_id = ? AND company_id = ?', [$up, $f['id'], $cid]);
        q('DELETE FROM form_folders WHERE id = ?', [$f['id']]);
        audit('form_folder_deleted', $f['name'], $cid);
        flash('success', t('Der Ordner wurde gelöscht.'));
        fredirect('forms', $up ? ['folder' => $up] : []);
    }
    abort(400);
}

function page_form_action(): void
{
    $cid = forms_company_id();
    require_post_csrf();
    $f = load_form($cid, (int)post('id', 0));
    $back = $f['folder_id'] ? ['folder' => (int)$f['folder_id']] : [];
    switch (post('action')) {
        case 'duplicate':
            $copy = $f;
            unset($copy['id']);
            $copy['name'] = mb_substr($f['name'] . ' (Kopie)', 0, 190);
            $copy['created_by'] = current_user()['id'];
            $copy['updated_by'] = current_user()['id'];
            $copy['created_at'] = $copy['updated_at'] = now();
            $id = insert('forms', $copy);
            audit('form_duplicated', $f['name'], $cid);
            flash('success', t('Das Formular wurde dupliziert.'));
            fredirect('form_edit', ['id' => $id]);
        case 'move':
            $to = (int)post('folder_id', 0);
            if ($to && !row('SELECT id FROM form_folders WHERE id = ? AND company_id = ?', [$to, $cid])) abort(404);
            q('UPDATE forms SET folder_id = ? WHERE id = ?', [$to ?: null, $f['id']]);
            flash('success', t('Das Formular wurde verschoben.'));
            fredirect('forms', $to ? ['folder' => $to] : []);
        case 'delete':
            q('DELETE FROM forms WHERE id = ?', [$f['id']]);
            audit('form_deleted', $f['name'], $cid);
            flash('success', t('Das Formular wurde gelöscht.'));
            fredirect('forms', $back);
    }
    abort(400);
}

// ---------------------------------------------------------------------------
// Editor
// ---------------------------------------------------------------------------

function layout_choice_options(int $cid, string $kind): array
{
    $opts = [0 => t('Standard der Firma'), -1 => $kind === 'header' ? t('Ohne Briefkopf') : t('Ohne Fußzeile')];
    foreach (rows('SELECT id, name, is_default FROM form_layouts WHERE company_id = ? AND kind = ? ORDER BY name', [$cid, $kind]) as $l) {
        $opts[(int)$l['id']] = $l['name'] . ((int)$l['is_default'] ? t(' (Standard)') : '');
    }
    return $opts;
}

// ---------------------------------------------------------------------------
// Briefkopf & Fußzeile
// ---------------------------------------------------------------------------

function page_form_layouts(): void
{
    $cid = forms_company_id();
    forms_require_manage();
    $presets = layout_presets();

    if (is_post()) {
        require_post_csrf();
        $action = post('action');
        $kind = post('kind') === 'footer' ? 'footer' : 'header';
        if ($action === 'save') {
            $id = (int)post('id', 0);
            $l = $id ? row('SELECT * FROM form_layouts WHERE id = ? AND company_id = ?', [$id, $cid]) : null;
            if ($id && !$l) abort(404);
            if ($l) $kind = $l['kind'];
            $name = mb_substr(post('name'), 0, 190) ?: ($kind === 'header' ? t('Briefkopf') : t('Fußzeile'));
            $body = sanitize_html((string)($_POST['body'] ?? ''));
            if ($l) {
                update('form_layouts', $id, ['name' => $name, 'body' => $body, 'updated_at' => now()]);
            } else {
                $preset = post('preset');
                if ($body === '' && isset($presets[$kind][$preset])) $body = $presets[$kind][$preset][1];
                $id = insert('form_layouts', ['company_id' => $cid, 'kind' => $kind, 'name' => $name, 'body' => $body,
                    'is_default' => row('SELECT id FROM form_layouts WHERE company_id = ? AND kind = ?', [$cid, $kind]) ? 0 : 1,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            if (post('make_default') === '1') {
                q('UPDATE form_layouts SET is_default = 0 WHERE company_id = ? AND kind = ?', [$cid, $kind]);
                q('UPDATE form_layouts SET is_default = 1 WHERE id = ?', [$id]);
            }
            audit('form_layout_saved', $name, $cid);
            flash('success', t('Die Vorlage wurde gespeichert.'));
            fredirect('form_layouts', ['id' => $id]);
        }
        if ($action === 'delete') {
            $l = row('SELECT * FROM form_layouts WHERE id = ? AND company_id = ?', [(int)post('id', 0), $cid]);
            if (!$l) abort(404);
            q('DELETE FROM form_layouts WHERE id = ?', [$l['id']]);
            q('UPDATE forms SET ' . $l['kind'] . '_id = 0 WHERE company_id = ? AND ' . $l['kind'] . '_id = ?', [$cid, $l['id']]);
            if ((int)$l['is_default']) {
                $next = row('SELECT id FROM form_layouts WHERE company_id = ? AND kind = ? ORDER BY id LIMIT 1', [$cid, $l['kind']]);
                if ($next) q('UPDATE form_layouts SET is_default = 1 WHERE id = ?', [$next['id']]);
            }
            audit('form_layout_deleted', $l['name'], $cid);
            flash('success', t('Die Vorlage wurde gelöscht. Formulare, die sie genutzt haben, verwenden jetzt den Standard.'));
            fredirect('form_layouts');
        }
        abort(400);
    }

    $layouts = rows('SELECT * FROM form_layouts WHERE company_id = ? ORDER BY kind DESC, is_default DESC, name', [$cid]);
    $id = query_int('id');
    $newKind = (string)($_GET['new'] ?? '');
    $current = $id ? row('SELECT * FROM form_layouts WHERE id = ? AND company_id = ?', [$id, $cid]) : null;
    if (!$current && !in_array($newKind, ['header', 'footer'], true) && $layouts) $current = $layouts[0];
    $preset = (string)($_GET['preset'] ?? '');
    if (!$current && in_array($newKind, ['header', 'footer'], true)) {
        $p = $presets[$newKind][$preset] ?? reset($presets[$newKind]);
        $current = ['id' => 0, 'kind' => $newKind, 'name' => $newKind === 'header' ? t('Neuer Briefkopf') : t('Neue Fußzeile'), 'body' => $p[1], 'is_default' => 0];
    }

    render_page(t('Briefkopf & Fußzeile'), function () use ($cid, $layouts, $current, $presets) { ?>
        <?= forms_subtitle($cid) ?>
        <div class="template-layout">
            <aside class="card">
                <?php foreach (['header' => t('Briefköpfe'), 'footer' => t('Fußzeilen')] as $kind => $label): ?>
                    <h2><?= e($label) ?></h2>
                    <ul class="pick">
                        <?php foreach ($layouts as $l): if ($l['kind'] !== $kind) continue; ?>
                            <li class="<?= $current && (int)$current['id'] === (int)$l['id'] ? 'active' : '' ?>">
                                <a href="<?= e(furl('form_layouts', ['id' => $l['id']])) ?>"><?= e(t($l['name'])) ?></a><?= (int)$l['is_default'] ? (' <span class="tag">' . e(t('Standard')) . '</span>') : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <details><summary class="btn small">+ <?= $kind === 'header' ? t('Briefkopf') : t('Fußzeile') ?> <?= e(t('aus Vorlage')) ?></summary>
                        <ul class="pick">
                            <?php foreach ($presets[$kind] as $key => [$plabel]): ?>
                                <li><a href="<?= e(furl('form_layouts', ['new' => $kind, 'preset' => $key])) ?>"><?= e($plabel) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
                <p class="muted small"><?= e(t('Logo und Angaben wie Bankverbindung oder USt-IdNr. pflegen Sie unter')) ?>
                    <a href="<?= e(furl('form_settings')) ?>"><?= e(t('Einstellungen')) ?></a><?= e(t('. Sie erscheinen über Platzhalter in der Vorlage.')) ?></p>
            </aside>
            <section>
            <?php if ($current): ?>
                <form method="post" class="form" data-editor-form id="word-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)$current['id'] ?>"><input type="hidden" name="kind" value="<?= e($current['kind']) ?>">
                    <div class="card layout-head">
                        <label><?= e(t('Name')) ?><input name="name" value="<?= e($current['name']) ?>" required maxlength="190"></label>
                        <label class="check"><input type="checkbox" name="make_default" value="1" <?= (int)$current['is_default'] ? 'checked disabled' : '' ?>>
                            <?= e($current['kind'] === 'header' ? t('Standard-Briefkopf der Firma') : t('Standard-Fußzeile der Firma')) ?></label>
                        <?php if ((int)$current['is_default']): ?><input type="hidden" name="make_default" value="1"><?php endif; ?>
                    </div>
                    <div class="paper-wrap">
                        <p class="muted small"><?= $current['kind'] === 'header'
                            ? t('Der Briefkopf steht oben auf der ersten Seite. Platzhalter wie das Logo oder die Anschrift des Kunden werden beim Erstellen ersetzt.')
                            : t('Die Fußzeile steht unten auf jeder Seite. Halten Sie sie kurz, damit sie auf eine Zeile oder einen kleinen Block passt.') ?></p>
                        <textarea name="body" hidden></textarea>
                        <?php render_word_editor($cid, [['region' => 'body', 'html' => (string)$current['body']]], page_settings(null), 'layout'); ?>
                    </div>
                    <div class="buttons">
                        <button class="btn primary"><?= e(t('Speichern')) ?></button>
                        <?php if ((int)$current['id']): ?>
                            <a class="btn" href="<?= e(furl('form_print', ['layout' => $current['id'], 'preview' => 1])) ?>" target="_blank" rel="noopener"><?= e(t('Vorschau')) ?></a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ((int)$current['id']): ?>
                <form method="post" class="danger-inline">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
                    <button class="btn small danger" data-confirm="<?= e(t('Vorlage „{x}“ löschen?', ['x' => $current['name']])) ?>"><?= e(t('Vorlage löschen')) ?></button>
                </form>
                <?php endif; ?>
            <?php else: ?>
                <div class="card"><p><?= e(t('Wählen Sie links eine Vorlage, um einen Briefkopf oder eine Fußzeile anzulegen.')) ?></p></div>
            <?php endif; ?>
            </section>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Einstellungen: Logo, Briefpapier, API-Zugänge
// ---------------------------------------------------------------------------

function page_form_settings(): void
{
    $cid = forms_company_id();
    forms_require_manage();
    $me = current_user();
    $b = forms_branding($cid);
    $newToken = null;

    if (is_post()) {
        require_post_csrf();
        $action = post('action');
        $save = function (array $data) use ($cid) {
            $data['updated_at'] = now();
            $have = row('SELECT id FROM form_branding WHERE company_id = ?', [$cid]);
            if ($have) update('form_branding', (int)$have['id'], $data);
            else insert('form_branding', $data + ['company_id' => $cid]);
        };
        if ($action === 'branding') {
            $data = [];
            foreach (BRANDING_FIELDS as $col => [$label]) $data[$col] = mb_substr(post($col), 0, $col === 'bic' ? 20 : 190);
            $save($data);
            audit('form_branding_saved', '', $cid);
            flash('success', t('Die Angaben wurden gespeichert.'));
        } elseif ($action === 'logo') {
            $f = $_FILES['logo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                flash('error', t('Bitte eine Bilddatei auswählen.'));
            } elseif ($f['size'] > LOGO_MAX_BYTES) {
                flash('error', t('Das Logo ist zu groß (höchstens 1 MB).'));
            } else {
                $info = @getimagesize($f['tmp_name']);
                $mime = $info['mime'] ?? '';
                if (!isset(LOGO_MIMES[$mime])) {
                    flash('error', t('Bitte ein Bild im Format PNG, JPG, GIF oder WebP hochladen.'));
                } else {
                    $save(['logo_mime' => $mime, 'logo_data' => base64_encode((string)file_get_contents($f['tmp_name']))]);
                    audit('form_logo_uploaded', $f['name'], $cid);
                    flash('success', t('Das Logo wurde gespeichert.'));
                }
            }
        } elseif ($action === 'logo_delete') {
            $save(['logo_mime' => '', 'logo_data' => null]);
            audit('form_logo_deleted', '', $cid);
            flash('success', t('Das Logo wurde entfernt.'));
        } elseif ($action === 'token_create') {
            $name = mb_substr(post('name'), 0, 120) ?: t('API-Zugang');
            [$plain, $prefix, $hash] = api_token_generate();
            insert('api_tokens', ['company_id' => $cid, 'name' => $name, 'prefix' => $prefix, 'token_hash' => $hash,
                'can_write' => post('can_write') === '1' ? 1 : 0, 'active' => 1, 'created_by' => $me['id'], 'created_at' => now()]);
            audit('api_token_created', $name, $cid);
            $_SESSION['new_api_token'] = $plain;
            flash('success', t('Der API-Schlüssel wurde erzeugt. Kopieren Sie ihn jetzt, er wird nur einmal angezeigt.'));
        } elseif ($action === 'token_revoke') {
            $t = row("SELECT * FROM api_tokens WHERE id = ? AND company_id = ? AND scope = 'kunden'", [(int)post('id', 0), $cid]);
            if (!$t) abort(404);
            q('DELETE FROM api_tokens WHERE id = ?', [$t['id']]);
            audit('api_token_revoked', $t['name'], $cid);
            flash('success', t('Der API-Schlüssel wurde gelöscht und funktioniert nicht mehr.'));
        }
        fredirect('form_settings');
    }
    $newToken = $_SESSION['new_api_token'] ?? null;
    unset($_SESSION['new_api_token']);
    $tokens = rows("SELECT t.*, u.name AS creator FROM api_tokens t LEFT JOIN users u ON u.id = t.created_by WHERE t.company_id = ? AND t.scope = 'kunden' ORDER BY t.created_at DESC", [$cid]);
    $apiBase = rtrim((string)cfg('base_url', ''), '/') . '/api.php';

    render_page(t('Einstellungen Formulare'), function () use ($cid, $b, $tokens, $newToken, $apiBase) { ?>
        <?= forms_subtitle($cid) ?>
        <div class="grid2">
            <div class="card">
                <h2><?= e(t('Logo')) ?></h2>
                <?php $logo = logo_tag($cid, 'logo-preview'); ?>
                <?= $logo ?: ('<p class="muted">' . e(t('Noch kein Logo hinterlegt.')) . '</p>') ?>
                <form method="post" enctype="multipart/form-data" class="form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="logo">
                    <label><?= e(t('Bilddatei (PNG, JPG, GIF oder WebP, max. 1 MB)')) ?><input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required></label>
                    <button class="btn primary"><?= e(t('Logo hochladen')) ?></button>
                </form>
                <?php if ($logo): ?>
                <form method="post" class="danger-inline"><?= csrf_field() ?><input type="hidden" name="action" value="logo_delete">
                    <button class="btn small danger" data-confirm="<?= e(t('Logo entfernen?')) ?>"><?= e(t('Logo entfernen')) ?></button></form>
                <?php endif; ?>
                <p class="muted small"><?= e(t('Das Logo erscheint überall, wo im Briefkopf oder Formular der Platzhalter')) ?> <span class="ph">{{logo}}</span> <?= e(t('steht. Am besten eignet sich ein PNG mit transparentem Hintergrund und etwa 600 Pixeln Breite.')) ?></p>
            </div>
            <div class="card">
                <h2><?= e(t('Angaben für Briefkopf und Fußzeile')) ?></h2>
                <p class="muted small"><?= e(t('Name, Anschrift, Telefon und E-Mail kommen aus dem Firmenprofil. Hier ergänzen Sie weitere Angaben, die als Platzhalter verfügbar sind.')) ?></p>
                <form method="post" class="form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="branding">
                    <div class="grid2">
                        <?php foreach (BRANDING_FIELDS as $col => [$label, $key]): ?>
                            <label><?= e(t($label)) ?> <span class="muted small">{{firma.<?= e($key) ?>}}</span>
                                <input name="<?= e($col) ?>" value="<?= e($b[$col] ?? '') ?>" maxlength="190"></label>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn primary"><?= e(t('Speichern')) ?></button>
                </form>
            </div>
        </div>

        <div class="card">
            <h2><?= e(t('API-Zugänge für die Kundendatenbank')) ?></h2>
            <?php if ($newToken): ?>
                <div class="alert success"><p><strong><?= e(t('Neuer API-Schlüssel')) ?></strong> <?= e(t('(wird nur jetzt angezeigt):')) ?></p>
                    <p><span class="secret"><?= e($newToken) ?></span></p></div>
            <?php endif; ?>
            <p class="muted small"><?= e(t('Mit einem API-Schlüssel können andere Programme (z. B. CRM, Warenwirtschaft, Zapier oder n8n) Kunden dieser Firma lesen und – wenn erlaubt – anlegen, ändern oder löschen.')) ?></p>
            <?php if ($tokens): ?>
            <table class="table compact">
                <thead><tr><th><?= e(t('Name')) ?></th><th><?= e(t('Schlüssel')) ?></th><th><?= e(t('Rechte')) ?></th><th><?= e(t('Erstellt')) ?></th><th><?= e(t('Zuletzt benutzt')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tokens as $t): ?>
                    <tr><td><?= e($t['name']) ?></td><td><code>kp_<?= e($t['prefix']) ?>_…</code></td>
                        <td><?= (int)$t['can_write'] ? t('Lesen und Schreiben') : t('Nur lesen') ?></td>
                        <td class="small"><?= fmt_dt($t['created_at']) ?><br><span class="muted"><?= e($t['creator'] ?? '') ?></span></td>
                        <td class="small"><?= fmt_dt($t['last_used_at']) ?></td>
                        <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="token_revoke"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button class="btn small danger" data-confirm="<?= e(t('API-Schlüssel „{x}“ löschen? Programme, die ihn nutzen, verlieren sofort den Zugriff.', ['x' => $t['name']])) ?>"><?= e(t('Löschen')) ?></button></form></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <form method="post" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="token_create">
                <input name="name" placeholder="<?= e(t('Bezeichnung, z. B. CRM-Abgleich')) ?>" maxlength="120" required>
                <label class="check"><input type="checkbox" name="can_write" value="1"> <?= e(t('darf Kunden anlegen, ändern und löschen')) ?></label>
                <button class="btn primary"><?= e(t('Schlüssel erzeugen')) ?></button>
            </form>
            <details class="api-doc">
                <summary><?= e(t('Kurzanleitung zur Schnittstelle')) ?></summary>
                <p><?= e(t('Adresse:')) ?> <code><?= e($apiBase) ?></code> <?= e(t('· Schlüssel im Header')) ?> <code>Authorization: Bearer kp_…</code> <?= e(t('(oder')) ?> <code>X-API-Key: kp_…</code><?= e(t('). Daten im JSON-Format, Feldnamen wie in der CSV (z. B.')) ?> <code>nummer</code>, <code>firma</code>, <code>vorname</code>).</p>
                <table class="table compact">
                    <tr><td><code>GET /api.php/kunden?suche=…&amp;seite=1&amp;pro_seite=100</code></td><td><?= e(t('Kunden auflisten und suchen')) ?></td></tr>
                    <tr><td><code>GET /api.php/kunden/{id}</code></td><td><?= e(t('einen Kunden abrufen')) ?></td></tr>
                    <tr><td><code>GET /api.php/kunden/nummer/{kundennummer}</code></td><td><?= e(t('Kunden über die Kundennummer abrufen')) ?></td></tr>
                    <tr><td><code>POST /api.php/kunden</code></td><td><?= e(t('Kunden anlegen; mit vorhandener')) ?> <code>nummer</code> <?= e(t('wird aktualisiert. Auch eine Liste von Kunden ist möglich.')) ?></td></tr>
                    <tr><td><code>PATCH /api.php/kunden/{id}</code></td><td><?= e(t('einzelne Felder ändern')) ?></td></tr>
                    <tr><td><code>DELETE /api.php/kunden/{id}</code></td><td><?= e(t('Kunden löschen')) ?></td></tr>
                    <tr><td><code>GET /api.php/felder</code></td><td><?= e(t('alle Felder mit Bezeichnung')) ?></td></tr>
                </table>
                <p><?= e(t('Beispiel:')) ?></p>
                <pre class="code-block">curl -H "Authorization: Bearer kp_…" -H "Content-Type: application/json" \
  -d '{"nummer":"10023","firma":"Muster GmbH","anrede":"Frau","vorname":"Erika","nachname":"Muster"}' \
  <?= e($apiBase) ?>/kunden</pre>
            </details>
        </div>
    <?php });
}

