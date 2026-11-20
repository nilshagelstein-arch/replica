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
    if (!$f) abort(404, 'Das Formular wurde nicht gefunden.');
    return $f;
}

/** Editor mit Werkzeugleiste; der Inhalt landet beim Speichern im Feld $name */
function render_editor(string $name, string $html, int $cid, string $mode = 'body'): void
{
    $catalog = placeholder_catalog($cid);
    ?>
    <div class="editor" data-editor="<?= e($name) ?>">
        <div class="ed-toolbar" role="toolbar" aria-label="Formatierung">
            <select data-block aria-label="Absatzformat">
                <option value="p">Text</option><option value="h1">Überschrift 1</option>
                <option value="h2">Überschrift 2</option><option value="h3">Überschrift 3</option>
            </select>
            <select data-size aria-label="Schriftgröße">
                <option value="">Größe</option><option value="fs-small">klein</option><option value="normal">normal</option>
                <option value="fs-large">groß</option><option value="fs-xlarge">sehr groß</option>
            </select>
            <span class="ed-group">
                <button type="button" data-cmd="bold" title="Fett (Strg+B)"><b>F</b></button>
                <button type="button" data-cmd="italic" title="Kursiv (Strg+I)"><i>K</i></button>
                <button type="button" data-cmd="underline" title="Unterstrichen (Strg+U)"><u>U</u></button>
                <button type="button" data-cmd="strikeThrough" title="Durchgestrichen"><s>S</s></button>
                <select data-color aria-label="Textfarbe"><option value="">Farbe</option><option value="none">Standard</option><option value="c-muted">Grau</option><option value="c-accent">Akzent</option></select>
            </span>
            <span class="ed-group">
                <button type="button" data-align="al-left" title="Linksbündig">⇤ links</button>
                <button type="button" data-align="al-center" title="Zentriert">↔ mittig</button>
                <button type="button" data-align="al-right" title="Rechtsbündig">⇥ rechts</button>
                <button type="button" data-align="al-justify" title="Blocksatz">☰ Block</button>
            </span>
            <span class="ed-group">
                <button type="button" data-cmd="insertUnorderedList" title="Aufzählung">• Liste</button>
                <button type="button" data-cmd="insertOrderedList" title="Nummerierung">1. Liste</button>
            </span>
            <span class="ed-group">
                <button type="button" data-table="insert" title="Tabelle einfügen">▦ Tabelle</button>
                <button type="button" data-table="row" title="Zeile darunter einfügen">+ Zeile</button>
                <button type="button" data-table="col" title="Spalte rechts einfügen">+ Spalte</button>
                <button type="button" data-table="delrow" title="Zeile löschen">− Zeile</button>
                <button type="button" data-table="delcol" title="Spalte löschen">− Spalte</button>
                <button type="button" data-table="border" title="Rahmen ein/aus">Rahmen</button>
            </span>
            <span class="ed-group">
                <button type="button" data-insert="hr" title="Trennlinie">― Linie</button>
                <?php if ($mode === 'body'): ?><button type="button" data-insert="pagebreak" title="Neue Seite">⤓ Seitenumbruch</button><?php endif; ?>
                <button type="button" data-insert="image" title="Bild einfügen (PNG, JPG, max. 500 KB)">🖼 Bild</button>
                <button type="button" data-insert="link" title="Link">🔗</button>
            </span>
            <select data-placeholder aria-label="Platzhalter einfügen" class="ed-ph-select">
                <option value="">{{ }} Platzhalter einfügen …</option>
                <?php foreach ($catalog as $group => $items): ?>
                    <optgroup label="<?= e($group) ?>">
                        <?php foreach ($items as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <?php if ($mode === 'body'): ?>
            <span class="ed-group">
                <button type="button" data-field="eingabe" title="Einzeiliges Feld, wird beim Erstellen ausgefüllt">▭ Eingabefeld</button>
                <button type="button" data-field="text" title="Mehrzeiliges Feld, wird beim Erstellen ausgefüllt">▤ Textfeld</button>
                <button type="button" data-field="ankreuzen" title="Kästchen zum Ankreuzen">☐ Ankreuzfeld</button>
                <button type="button" data-insert="signature" title="Ort, Datum und Unterschrift">✍ Unterschrift</button>
            </span>
            <?php endif; ?>
            <span class="ed-group">
                <button type="button" data-cmd="removeFormat" title="Formatierung entfernen">⌫ Format</button>
                <button type="button" data-cmd="undo" title="Rückgängig (Strg+Z)">↶</button>
                <button type="button" data-cmd="redo" title="Wiederholen (Strg+Y)">↷</button>
            </span>
            <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" data-image-input hidden>
        </div>
        <div class="ed-area doc-content <?= $mode === 'body' ? '' : 'ed-layout' ?>" contenteditable="true" spellcheck="true" lang="de"><?= $html ?: '<p><br></p>' ?></div>
        <textarea name="<?= e($name) ?>" hidden><?= e($html) ?></textarea>
    </div>
    <?php
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

    render_page('Formulare', function () use ($cid, $folderId, $folder, $search, $forms, $sub, $path, $byParent, $counts, $options) { ?>
        <?= forms_subtitle($cid) ?>
        <div class="forms-layout">
            <aside class="card folder-nav">
                <h2>Ordner</h2>
                <ul class="tree">
                    <li class="<?= !$folderId && $search === '' ? 'active' : '' ?>"><a href="<?= e(furl('forms')) ?>">📁 Alle Ordner</a>
                        <?php if ($counts[0] ?? 0): ?><span class="muted small">(<?= $counts[0] ?> ohne Ordner)</span><?php endif; ?></li>
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
                    <label>Neuer Ordner<?= $folder ? ' in „' . e($folder['name']) . '“' : '' ?>
                        <input name="name" required maxlength="190" placeholder="z. B. Verträge"></label>
                    <button class="btn small">Ordner anlegen</button>
                </form>
            </aside>

            <section>
                <div class="toolbar">
                    <form method="get" class="toolbar">
                        <input type="hidden" name="r" value="forms">
                        <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
                        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Formulare durchsuchen">
                        <button class="btn small">Suchen</button>
                        <?php if ($search !== ''): ?><a href="<?= e(furl('forms')) ?>">Suche aufheben</a><?php endif; ?>
                    </form>
                    <a class="btn primary push-right" href="<?= e(furl('form_edit', $folderId ? ['folder' => $folderId] : [])) ?>">+ Neues Formular</a>
                </div>

                <?php if ($search === ''): ?>
                <p class="breadcrumb"><a href="<?= e(furl('forms')) ?>">Alle Ordner</a>
                    <?php foreach ($path as $p): ?> › <a href="<?= e(furl('forms', ['folder' => $p['id']])) ?>"><?= e($p['name']) ?></a><?php endforeach; ?></p>
                <?php endif; ?>

                <?php if ($folder): ?>
                <details class="card folder-tools">
                    <summary>Ordner „<?= e($folder['name']) ?>“ bearbeiten</summary>
                    <div class="inline-form">
                        <form method="post" action="<?= e(furl('form_folder')) ?>" class="inline-form">
                            <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$folder['id'] ?>">
                            <input name="name" value="<?= e($folder['name']) ?>" required maxlength="190" aria-label="Name">
                            <select name="parent_id" aria-label="Übergeordneter Ordner">
                                <option value="0">(oberste Ebene)</option>
                                <?php foreach (folder_options($cid, (int)$folder['id']) as $id => $label): ?>
                                    <option value="<?= $id ?>" <?= (int)$folder['parent_id'] === $id ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn small">Speichern</button>
                        </form>
                        <form method="post" action="<?= e(furl('form_folder')) ?>" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$folder['id'] ?>">
                            <button class="btn small danger" data-confirm="Ordner „<?= e($folder['name']) ?>“ löschen? Enthaltene Formulare und Unterordner wandern eine Ebene nach oben.">Ordner löschen</button>
                        </form>
                    </div>
                </details>
                <?php endif; ?>

                <?php if ($sub): ?>
                <div class="folder-grid">
                    <?php foreach ($sub as $f): ?>
                        <a class="folder-tile" href="<?= e(furl('forms', ['folder' => $f['id']])) ?>"><span>📁</span><?= e($f['name']) ?>
                            <span class="muted small"><?= ($counts[(int)$f['id']] ?? 0) ?> Formular(e)</span></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($forms || !$sub): ?>
                <div class="card">
                <?php if (!$forms): ?>
                    <p class="muted"><?= $search !== '' ? 'Keine Formulare gefunden.' : ($sub ? 'In dieser Ebene liegen nur Ordner.' : 'Hier liegen noch keine Formulare.') ?></p>
                <?php else: ?>
                    <table class="table">
                        <thead><tr><th>Formular</th><?php if ($search !== ''): ?><th>Ordner</th><?php endif; ?><th>Geändert</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($forms as $f): ?>
                            <tr>
                                <td><a href="<?= e(furl('form_use', ['id' => $f['id']])) ?>"><strong><?= e($f['name']) ?></strong></a>
                                    <?php if ($f['description'] !== ''): ?><br><span class="muted small"><?= e($f['description']) ?></span><?php endif; ?></td>
                                <?php if ($search !== ''): ?><td><?= e($f['folder_name'] ?? '–') ?></td><?php endif; ?>
                                <td class="small"><?= fmt_dt($f['updated_at']) ?></td>
                                <td class="actions">
                                    <a class="btn small primary" href="<?= e(furl('form_use', ['id' => $f['id']])) ?>">Erstellen</a>
                                    <a class="btn small" href="<?= e(furl('form_edit', ['id' => $f['id']])) ?>">Bearbeiten</a>
                                    <details class="more"><summary class="btn small">…</summary>
                                        <div class="more-menu">
                                            <form method="post" action="<?= e(furl('form_action')) ?>"><?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="action" value="duplicate">
                                                <button class="linkbtn">Duplizieren</button></form>
                                            <form method="post" action="<?= e(furl('form_action')) ?>" class="inline-form"><?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="action" value="move">
                                                <select name="folder_id" aria-label="Verschieben nach"><option value="0">(ohne Ordner)</option>
                                                    <?php foreach ($options as $id => $label): ?><option value="<?= $id ?>" <?= (int)$f['folder_id'] === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                                                </select><button class="btn small">Verschieben</button></form>
                                            <form method="post" action="<?= e(furl('form_action')) ?>"><?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="action" value="delete">
                                                <button class="linkbtn err-text" data-confirm="Formular „<?= e($f['name']) ?>“ endgültig löschen?">Löschen</button></form>
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
        if ($name === '') { flash('error', 'Bitte einen Namen angeben.'); fredirect('forms', ['folder' => $parent]); }
        $id = insert('form_folders', ['company_id' => $cid, 'parent_id' => $parent ?: null, 'name' => $name, 'created_at' => now()]);
        audit('form_folder_created', $name, $cid);
        flash('success', 'Der Ordner wurde angelegt.');
        fredirect('forms', ['folder' => $id]);
    }
    $f = row('SELECT * FROM form_folders WHERE id = ? AND company_id = ?', [(int)post('id', 0), $cid]);
    if (!$f) abort(404);
    if ($action === 'rename') {
        // kein Verschieben in sich selbst oder einen eigenen Unterordner
        foreach (folder_path($cid, $parent) as $p) {
            if ((int)$p['id'] === (int)$f['id']) { flash('error', 'Ein Ordner kann nicht in sich selbst verschoben werden.'); fredirect('forms', ['folder' => $f['id']]); }
        }
        if ($name !== '') q('UPDATE form_folders SET name = ?, parent_id = ? WHERE id = ?', [$name, $parent ?: null, $f['id']]);
        flash('success', 'Der Ordner wurde gespeichert.');
        fredirect('forms', ['folder' => $f['id']]);
    }
    if ($action === 'delete') {
        $up = $f['parent_id'] !== null ? (int)$f['parent_id'] : null;
        q('UPDATE forms SET folder_id = ? WHERE folder_id = ? AND company_id = ?', [$up, $f['id'], $cid]);
        q('UPDATE form_folders SET parent_id = ? WHERE parent_id = ? AND company_id = ?', [$up, $f['id'], $cid]);
        q('DELETE FROM form_folders WHERE id = ?', [$f['id']]);
        audit('form_folder_deleted', $f['name'], $cid);
        flash('success', 'Der Ordner wurde gelöscht.');
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
            flash('success', 'Das Formular wurde dupliziert.');
            fredirect('form_edit', ['id' => $id]);
        case 'move':
            $to = (int)post('folder_id', 0);
            if ($to && !row('SELECT id FROM form_folders WHERE id = ? AND company_id = ?', [$to, $cid])) abort(404);
            q('UPDATE forms SET folder_id = ? WHERE id = ?', [$to ?: null, $f['id']]);
            flash('success', 'Das Formular wurde verschoben.');
            fredirect('forms', $to ? ['folder' => $to] : []);
        case 'delete':
            q('DELETE FROM forms WHERE id = ?', [$f['id']]);
            audit('form_deleted', $f['name'], $cid);
            flash('success', 'Das Formular wurde gelöscht.');
            fredirect('forms', $back);
    }
    abort(400);
}

// ---------------------------------------------------------------------------
// Editor
// ---------------------------------------------------------------------------

function layout_choice_options(int $cid, string $kind): array
{
    $opts = [0 => 'Standard der Firma', -1 => $kind === 'header' ? 'Ohne Briefkopf' : 'Ohne Fußzeile'];
    foreach (rows('SELECT id, name, is_default FROM form_layouts WHERE company_id = ? AND kind = ? ORDER BY name', [$cid, $kind]) as $l) {
        $opts[(int)$l['id']] = $l['name'] . ((int)$l['is_default'] ? ' (Standard)' : '');
    }
    return $opts;
}

function page_form_edit(): void
{
    $cid = forms_company_id();
    $u = current_user();
    $id = query_int('id');
    $form = $id ? load_form($cid, $id) : null;
    $data = $form ?? ['name' => '', 'description' => '', 'body' => '', 'header_id' => 0, 'footer_id' => 0,
        'folder_id' => query_int('folder') ?: null];
    $errors = [];

    if (is_post()) {
        require_post_csrf();
        $data['name'] = mb_substr(post('name'), 0, 190);
        $data['description'] = mb_substr(post('description'), 0, 500);
        $data['body'] = sanitize_html((string)($_POST['body'] ?? ''));
        $folder = (int)post('folder_id', 0);
        $data['folder_id'] = $folder && row('SELECT id FROM form_folders WHERE id = ? AND company_id = ?', [$folder, $cid]) ? $folder : null;
        foreach (['header', 'footer'] as $kind) {
            $v = (int)post($kind . '_id', 0);
            if ($v > 0 && !row('SELECT id FROM form_layouts WHERE id = ? AND company_id = ? AND kind = ?', [$v, $cid, $kind])) $v = 0;
            $data[$kind . '_id'] = max(-1, $v);
        }
        if ($data['name'] === '') $errors[] = 'Bitte einen Namen für das Formular angeben.';
        if (!$errors) {
            $save = ['name' => $data['name'], 'description' => $data['description'], 'body' => $data['body'],
                'folder_id' => $data['folder_id'], 'header_id' => $data['header_id'], 'footer_id' => $data['footer_id'],
                'updated_by' => $u['id'], 'updated_at' => now()];
            if ($form) {
                update('forms', (int)$form['id'], $save);
                audit('form_updated', $data['name'], $cid);
            } else {
                $id = insert('forms', $save + ['company_id' => $cid, 'created_by' => $u['id'], 'created_at' => now()]);
                audit('form_created', $data['name'], $cid);
            }
            flash('success', 'Das Formular wurde gespeichert.');
            if (post('next') === 'use') fredirect('form_use', ['id' => $id]);
            fredirect('form_edit', ['id' => $id]);
        }
    }

    $title = $form ? 'Formular bearbeiten' : 'Neues Formular';
    render_page($title, function () use ($cid, $form, $data, $errors) { ?>
        <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" class="form" data-editor-form>
            <?= csrf_field() ?>
            <div class="card">
                <div class="grid3">
                    <label>Name<input name="name" value="<?= e($data['name']) ?>" required maxlength="190" placeholder="z. B. Auftragsbestätigung"></label>
                    <label>Ordner<select name="folder_id"><option value="0">(ohne Ordner)</option>
                        <?php foreach (folder_options($cid) as $fid => $label): ?><option value="<?= $fid ?>" <?= (int)$data['folder_id'] === $fid ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select></label>
                    <label>Kurzbeschreibung<input name="description" value="<?= e($data['description']) ?>" maxlength="500"></label>
                    <label>Briefkopf<select name="header_id">
                        <?php foreach (layout_choice_options($cid, 'header') as $lid => $label): ?><option value="<?= $lid ?>" <?= (int)$data['header_id'] === $lid ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select></label>
                    <label>Fußzeile<select name="footer_id">
                        <?php foreach (layout_choice_options($cid, 'footer') as $lid => $label): ?><option value="<?= $lid ?>" <?= (int)$data['footer_id'] === $lid ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select></label>
                </div>
            </div>
            <div class="paper-wrap">
                <p class="muted small">Briefkopf und Fußzeile werden beim Erstellen automatisch ergänzt. Platzhalter wie
                    <span class="ph">{{kunde.nachname}}</span> füllt das Portal mit Daten aus der Kundendatenbank,
                    Eingabe- und Ankreuzfelder beim Erstellen von Hand.</p>
                <?php render_editor('body', (string)$data['body'], $cid); ?>
            </div>
            <div class="buttons sticky-buttons">
                <button class="btn primary">Speichern</button>
                <button class="btn" name="next" value="use">Speichern und Dokument erstellen</button>
                <?php if ($form): ?><a class="btn" href="<?= e(furl('form_print', ['id' => $form['id'], 'preview' => 1])) ?>" target="_blank" rel="noopener">Vorschau (gespeicherter Stand)</a><?php endif; ?>
                <a class="btn" href="<?= e(furl('forms', $data['folder_id'] ? ['folder' => (int)$data['folder_id']] : [])) ?>">Zurück</a>
            </div>
        </form>
    <?php });
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
            $name = mb_substr(post('name'), 0, 190) ?: ($kind === 'header' ? 'Briefkopf' : 'Fußzeile');
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
            flash('success', 'Die Vorlage wurde gespeichert.');
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
            flash('success', 'Die Vorlage wurde gelöscht. Formulare, die sie genutzt haben, verwenden jetzt den Standard.');
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
        $current = ['id' => 0, 'kind' => $newKind, 'name' => $newKind === 'header' ? 'Neuer Briefkopf' : 'Neue Fußzeile', 'body' => $p[1], 'is_default' => 0];
    }

    render_page('Briefkopf & Fußzeile', function () use ($cid, $layouts, $current, $presets) { ?>
        <?= forms_subtitle($cid) ?>
        <div class="template-layout">
            <aside class="card">
                <?php foreach (['header' => 'Briefköpfe', 'footer' => 'Fußzeilen'] as $kind => $label): ?>
                    <h2><?= e($label) ?></h2>
                    <ul class="pick">
                        <?php foreach ($layouts as $l): if ($l['kind'] !== $kind) continue; ?>
                            <li class="<?= $current && (int)$current['id'] === (int)$l['id'] ? 'active' : '' ?>">
                                <a href="<?= e(furl('form_layouts', ['id' => $l['id']])) ?>"><?= e($l['name']) ?></a><?= (int)$l['is_default'] ? ' <span class="tag">Standard</span>' : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <details><summary class="btn small">+ <?= $kind === 'header' ? 'Briefkopf' : 'Fußzeile' ?> aus Vorlage</summary>
                        <ul class="pick">
                            <?php foreach ($presets[$kind] as $key => [$plabel]): ?>
                                <li><a href="<?= e(furl('form_layouts', ['new' => $kind, 'preset' => $key])) ?>"><?= e($plabel) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
                <p class="muted small">Logo und Angaben wie Bankverbindung oder USt-IdNr. pflegen Sie unter
                    <a href="<?= e(furl('form_settings')) ?>">Einstellungen</a>. Sie erscheinen über Platzhalter in der Vorlage.</p>
            </aside>
            <section>
            <?php if ($current): ?>
                <form method="post" class="form" data-editor-form>
                    <?= csrf_field() ?><input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)$current['id'] ?>"><input type="hidden" name="kind" value="<?= e($current['kind']) ?>">
                    <div class="card layout-head">
                        <label>Name<input name="name" value="<?= e($current['name']) ?>" required maxlength="190"></label>
                        <label class="check"><input type="checkbox" name="make_default" value="1" <?= (int)$current['is_default'] ? 'checked disabled' : '' ?>>
                            Standard-<?= $current['kind'] === 'header' ? 'Briefkopf' : 'Fußzeile' ?> der Firma</label>
                        <?php if ((int)$current['is_default']): ?><input type="hidden" name="make_default" value="1"><?php endif; ?>
                    </div>
                    <div class="paper-wrap">
                        <p class="muted small"><?= $current['kind'] === 'header'
                            ? 'Der Briefkopf steht oben auf der ersten Seite. Platzhalter wie das Logo oder die Anschrift des Kunden werden beim Erstellen ersetzt.'
                            : 'Die Fußzeile steht unten auf jeder Seite. Halten Sie sie kurz, damit sie auf eine Zeile oder einen kleinen Block passt.' ?></p>
                        <?php render_editor('body', (string)$current['body'], $cid, 'layout'); ?>
                    </div>
                    <div class="buttons">
                        <button class="btn primary">Speichern</button>
                        <?php if ((int)$current['id']): ?>
                            <a class="btn" href="<?= e(furl('form_print', ['layout' => $current['id'], 'preview' => 1])) ?>" target="_blank" rel="noopener">Vorschau</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if ((int)$current['id']): ?>
                <form method="post" class="danger-inline">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
                    <button class="btn small danger" data-confirm="Vorlage „<?= e($current['name']) ?>“ löschen?">Vorlage löschen</button>
                </form>
                <?php endif; ?>
            <?php else: ?>
                <div class="card"><p>Wählen Sie links eine Vorlage, um einen Briefkopf oder eine Fußzeile anzulegen.</p></div>
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
            flash('success', 'Die Angaben wurden gespeichert.');
        } elseif ($action === 'logo') {
            $f = $_FILES['logo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                flash('error', 'Bitte eine Bilddatei auswählen.');
            } elseif ($f['size'] > LOGO_MAX_BYTES) {
                flash('error', 'Das Logo ist zu groß (höchstens 1 MB).');
            } else {
                $info = @getimagesize($f['tmp_name']);
                $mime = $info['mime'] ?? '';
                if (!isset(LOGO_MIMES[$mime])) {
                    flash('error', 'Bitte ein Bild im Format PNG, JPG, GIF oder WebP hochladen.');
                } else {
                    $save(['logo_mime' => $mime, 'logo_data' => base64_encode((string)file_get_contents($f['tmp_name']))]);
                    audit('form_logo_uploaded', $f['name'], $cid);
                    flash('success', 'Das Logo wurde gespeichert.');
                }
            }
        } elseif ($action === 'logo_delete') {
            $save(['logo_mime' => '', 'logo_data' => null]);
            audit('form_logo_deleted', '', $cid);
            flash('success', 'Das Logo wurde entfernt.');
        } elseif ($action === 'token_create') {
            $name = mb_substr(post('name'), 0, 120) ?: 'API-Zugang';
            [$plain, $prefix, $hash] = api_token_generate();
            insert('api_tokens', ['company_id' => $cid, 'name' => $name, 'prefix' => $prefix, 'token_hash' => $hash,
                'can_write' => post('can_write') === '1' ? 1 : 0, 'active' => 1, 'created_by' => $me['id'], 'created_at' => now()]);
            audit('api_token_created', $name, $cid);
            $_SESSION['new_api_token'] = $plain;
            flash('success', 'Der API-Schlüssel wurde erzeugt. Kopieren Sie ihn jetzt, er wird nur einmal angezeigt.');
        } elseif ($action === 'token_revoke') {
            $t = row('SELECT * FROM api_tokens WHERE id = ? AND company_id = ?', [(int)post('id', 0), $cid]);
            if (!$t) abort(404);
            q('DELETE FROM api_tokens WHERE id = ?', [$t['id']]);
            audit('api_token_revoked', $t['name'], $cid);
            flash('success', 'Der API-Schlüssel wurde gelöscht und funktioniert nicht mehr.');
        }
        fredirect('form_settings');
    }
    $newToken = $_SESSION['new_api_token'] ?? null;
    unset($_SESSION['new_api_token']);
    $tokens = rows('SELECT t.*, u.name AS creator FROM api_tokens t LEFT JOIN users u ON u.id = t.created_by WHERE t.company_id = ? ORDER BY t.created_at DESC', [$cid]);
    $apiBase = rtrim((string)cfg('base_url', ''), '/') . '/api.php';

    render_page('Einstellungen Formulare', function () use ($cid, $b, $tokens, $newToken, $apiBase) { ?>
        <?= forms_subtitle($cid) ?>
        <div class="grid2">
            <div class="card">
                <h2>Logo</h2>
                <?php $logo = logo_tag($cid, 'logo-preview'); ?>
                <?= $logo ?: '<p class="muted">Noch kein Logo hinterlegt.</p>' ?>
                <form method="post" enctype="multipart/form-data" class="form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="logo">
                    <label>Bilddatei (PNG, JPG, GIF oder WebP, max. 1 MB)<input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required></label>
                    <button class="btn primary">Logo hochladen</button>
                </form>
                <?php if ($logo): ?>
                <form method="post" class="danger-inline"><?= csrf_field() ?><input type="hidden" name="action" value="logo_delete">
                    <button class="btn small danger" data-confirm="Logo entfernen?">Logo entfernen</button></form>
                <?php endif; ?>
                <p class="muted small">Das Logo erscheint überall, wo im Briefkopf oder Formular der Platzhalter <span class="ph">{{logo}}</span> steht.
                    Am besten eignet sich ein PNG mit transparentem Hintergrund und etwa 600 Pixeln Breite.</p>
            </div>
            <div class="card">
                <h2>Angaben für Briefkopf und Fußzeile</h2>
                <p class="muted small">Name, Anschrift, Telefon und E-Mail kommen aus dem Firmenprofil. Hier ergänzen Sie weitere Angaben, die als Platzhalter verfügbar sind.</p>
                <form method="post" class="form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="branding">
                    <div class="grid2">
                        <?php foreach (BRANDING_FIELDS as $col => [$label, $key]): ?>
                            <label><?= e($label) ?> <span class="muted small">{{firma.<?= e($key) ?>}}</span>
                                <input name="<?= e($col) ?>" value="<?= e($b[$col] ?? '') ?>" maxlength="190"></label>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn primary">Speichern</button>
                </form>
            </div>
        </div>

        <div class="card">
            <h2>API-Zugänge für die Kundendatenbank</h2>
            <?php if ($newToken): ?>
                <div class="alert success"><p><strong>Neuer API-Schlüssel</strong> (wird nur jetzt angezeigt):</p>
                    <p><span class="secret"><?= e($newToken) ?></span></p></div>
            <?php endif; ?>
            <p class="muted small">Mit einem API-Schlüssel können andere Programme (z. B. CRM, Warenwirtschaft, Zapier oder n8n) Kunden dieser Firma lesen und – wenn erlaubt – anlegen, ändern oder löschen.</p>
            <?php if ($tokens): ?>
            <table class="table compact">
                <thead><tr><th>Name</th><th>Schlüssel</th><th>Rechte</th><th>Erstellt</th><th>Zuletzt benutzt</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tokens as $t): ?>
                    <tr><td><?= e($t['name']) ?></td><td><code>kp_<?= e($t['prefix']) ?>_…</code></td>
                        <td><?= (int)$t['can_write'] ? 'Lesen und Schreiben' : 'Nur lesen' ?></td>
                        <td class="small"><?= fmt_dt($t['created_at']) ?><br><span class="muted"><?= e($t['creator'] ?? '') ?></span></td>
                        <td class="small"><?= fmt_dt($t['last_used_at']) ?></td>
                        <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="token_revoke"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button class="btn small danger" data-confirm="API-Schlüssel „<?= e($t['name']) ?>“ löschen? Programme, die ihn nutzen, verlieren sofort den Zugriff.">Löschen</button></form></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <form method="post" class="inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="token_create">
                <input name="name" placeholder="Bezeichnung, z. B. CRM-Abgleich" maxlength="120" required>
                <label class="check"><input type="checkbox" name="can_write" value="1"> darf Kunden anlegen, ändern und löschen</label>
                <button class="btn primary">Schlüssel erzeugen</button>
            </form>
            <details class="api-doc">
                <summary>Kurzanleitung zur Schnittstelle</summary>
                <p>Adresse: <code><?= e($apiBase) ?></code> · Schlüssel im Header <code>Authorization: Bearer kp_…</code> (oder <code>X-API-Key: kp_…</code>). Daten im JSON-Format, Feldnamen wie in der CSV (z. B. <code>nummer</code>, <code>firma</code>, <code>vorname</code>).</p>
                <table class="table compact">
                    <tr><td><code>GET /api.php/kunden?suche=…&amp;seite=1&amp;pro_seite=100</code></td><td>Kunden auflisten und suchen</td></tr>
                    <tr><td><code>GET /api.php/kunden/{id}</code></td><td>einen Kunden abrufen</td></tr>
                    <tr><td><code>GET /api.php/kunden/nummer/{kundennummer}</code></td><td>Kunden über die Kundennummer abrufen</td></tr>
                    <tr><td><code>POST /api.php/kunden</code></td><td>Kunden anlegen; mit vorhandener <code>nummer</code> wird aktualisiert. Auch eine Liste von Kunden ist möglich.</td></tr>
                    <tr><td><code>PATCH /api.php/kunden/{id}</code></td><td>einzelne Felder ändern</td></tr>
                    <tr><td><code>DELETE /api.php/kunden/{id}</code></td><td>Kunden löschen</td></tr>
                    <tr><td><code>GET /api.php/felder</code></td><td>alle Felder mit Bezeichnung</td></tr>
                </table>
                <p>Beispiel:</p>
                <pre class="code-block">curl -H "Authorization: Bearer kp_…" -H "Content-Type: application/json" \
  -d '{"nummer":"10023","firma":"Muster GmbH","anrede":"Frau","vorname":"Erika","nachname":"Muster"}' \
  <?= e($apiBase) ?>/kunden</pre>
            </details>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Dokument erstellen
// ---------------------------------------------------------------------------

function page_form_use(): void
{
    $cid = forms_company_id();
    $form = load_form($cid, query_int('id'));
    $full = (string)$form['body'] . layout_for($cid, 'header', (int)$form['header_id']) . layout_for($cid, 'footer', (int)$form['footer_id']);
    $needsCustomer = uses_customer($full);
    $inputs = form_inputs((string)$form['body']);
    $search = trim((string)($_GET['q'] ?? ''));
    $customers = [];
    $total = 0;
    if ($needsCustomer) {
        $where = 'company_id = ?';
        $params = [$cid];
        if ($search !== '') {
            $where .= ' AND (customer_no LIKE ? OR company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR city LIKE ? OR email LIKE ?)';
            $params = array_merge($params, array_fill(0, 6, '%' . $search . '%'));
        }
        $total = (int)row("SELECT COUNT(*) AS n FROM customers WHERE $where", $params)['n'];
        $customers = rows("SELECT * FROM customers WHERE $where ORDER BY company_name, last_name, first_name LIMIT 300", $params);
    }

    render_page('Dokument erstellen: ' . $form['name'], function () use ($cid, $form, $needsCustomer, $inputs, $search, $customers, $total) { ?>
        <?php if ($needsCustomer): ?>
        <form method="get" class="toolbar">
            <input type="hidden" name="r" value="form_use"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
            <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Kunden suchen (Name, Nummer, Ort …)">
            <button class="btn small">Suchen</button>
        </form>
        <?php endif; ?>
        <form method="post" action="<?= e(furl('form_print')) ?>" target="_blank" class="form">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
            <div class="grid2">
                <?php if ($needsCustomer): ?>
                <div class="card">
                    <h2>Empfänger aus der Kundendatenbank</h2>
                    <?php if (!$customers): ?>
                        <p class="muted"><?= $search !== '' ? 'Keine Kunden gefunden.' : 'Die Kundendatenbank ist noch leer.' ?>
                            <a href="<?= e(furl('customer_edit')) ?>">Kunden anlegen</a> oder <a href="<?= e(furl('customer_import')) ?>">per CSV importieren</a>.</p>
                    <?php else: ?>
                        <p class="muted small">Mehrere Kunden auswählen ergibt einen Serienbrief: ein Dokument je Kunde, jeweils auf neuer Seite.
                            <?= $total > count($customers) ? 'Es werden die ersten ' . count($customers) . ' von ' . $total . ' Treffern angezeigt. Grenzen Sie die Suche ein.' : '' ?></p>
                        <input type="search" class="filter-input" data-filter-list="#customer-pick" placeholder="Liste filtern">
                        <p><button type="button" class="linkbtn small" data-check-all="#customer-pick">alle sichtbaren auswählen / abwählen</button></p>
                        <div id="customer-pick" class="pick-list">
                            <?php foreach ($customers as $c): ?>
                                <label class="check"><input type="checkbox" name="customers[]" value="<?= (int)$c['id'] ?>"> <?= e(customer_label($c)) ?>
                                    <?php if ($c['city'] !== ''): ?><span class="muted small"><?= e($c['city']) ?></span><?php endif; ?></label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="card">
                    <h2>Angaben für dieses Dokument</h2>
                    <label>Datum<input type="date" name="date" value="<?= date('Y-m-d') ?>"></label>
                    <?php foreach ($inputs as $i => $in): ?>
                        <?php if ($in['type'] === 'check'): ?>
                            <label class="check"><input type="checkbox" name="inputs[<?= $i ?>]" value="1"> <?= e($in['label']) ?></label>
                        <?php elseif ($in['type'] === 'textarea'): ?>
                            <label><?= e($in['label']) ?><textarea name="inputs[<?= $i ?>]" rows="6"></textarea></label>
                        <?php else: ?>
                            <label><?= e($in['label']) ?><input name="inputs[<?= $i ?>]"></label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$inputs): ?><p class="muted small">Dieses Formular hat keine Eingabefelder.</p><?php endif; ?>
                </div>
            </div>
            <div class="buttons">
                <button class="btn primary">Dokument erstellen</button>
                <a class="btn" href="<?= e(furl('form_edit', ['id' => $form['id']])) ?>">Formular bearbeiten</a>
                <span class="muted small">Das Dokument öffnet sich in einem neuen Tab. Dort drucken oder als PDF speichern.</span>
            </div>
        </form>
    <?php });
}

/** Beispielkunde für Vorschauen */
function sample_customer(int $cid): array
{
    return row('SELECT * FROM customers WHERE company_id = ? ORDER BY id LIMIT 1', [$cid])
        ?? ['customer_no' => '10001', 'company_name' => 'Musterfirma GmbH', 'salutation' => 'Frau', 'title' => '',
            'first_name' => 'Erika', 'last_name' => 'Mustermann', 'street' => 'Musterstraße 1', 'zip' => '26122',
            'city' => 'Oldenburg', 'country' => 'Deutschland', 'email' => 'erika@example.de', 'phone' => '0441 123456',
            'notes' => '', 'extra' => '{}'];
}

function page_form_print(): void
{
    $cid = forms_company_id();
    $u = current_user();
    $preview = isset($_GET['preview']);
    $date = time();
    $manual = [];
    $customerRows = [null];

    if ($preview && query_int('layout')) {
        $l = row('SELECT * FROM form_layouts WHERE id = ? AND company_id = ?', [query_int('layout'), $cid]);
        if (!$l) abort(404);
        $form = ['name' => 'Vorschau: ' . $l['name'], 'body' => '<p>Hier steht der Inhalt des Formulars.</p>',
            'header_id' => $l['kind'] === 'header' ? (int)$l['id'] : 0, 'footer_id' => $l['kind'] === 'footer' ? (int)$l['id'] : 0];
        $customerRows = [sample_customer($cid)];
    } elseif ($preview) {
        $form = load_form($cid, query_int('id'));
        $customerRows = [sample_customer($cid)];
        foreach (form_inputs((string)$form['body']) as $in) $manual[$in['key']] = $in['type'] === 'check' ? '' : '[' . $in['label'] . ']';
    } else {
        require_post_csrf();
        $form = load_form($cid, (int)post('id', 0));
        $d = strtotime((string)post('date'));
        if ($d) $date = $d;
        $given = (array)($_POST['inputs'] ?? []);
        foreach (form_inputs((string)$form['body']) as $i => $in) {
            $manual[$in['key']] = mb_substr((string)($given[$i] ?? ''), 0, 20000);
        }
        $ids = array_slice(array_map('intval', (array)($_POST['customers'] ?? [])), 0, 500);
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $customerRows = rows("SELECT * FROM customers WHERE company_id = ? AND id IN ($in) ORDER BY company_name, last_name, first_name", array_merge([$cid], $ids));
            if (!$customerRows) $customerRows = [null];
        }
        audit('form_generated', $form['name'] . ' (' . ($ids ? count($customerRows) . ' Kunde(n)' : 'ohne Kunde') . ')', $cid);
    }

    $header = layout_for($cid, 'header', (int)$form['header_id']);
    $footer = layout_for($cid, 'footer', (int)$form['footer_id']);
    $logo = logo_tag($cid);
    $base = company_values($cid) + [
        'datum' => date('d.m.Y', $date), 'datum.lang' => german_date_long($date),
        'benutzer.name' => (string)$u['name'], 'benutzer.email' => (string)$u['email'],
    ] + $manual;
    $title = $form['name'];
    ?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="assets/style.css"></head>
<body class="doc-print">
<div class="doc-toolbar">
    <strong><?= e($title) ?></strong>
    <?php if ($preview): ?><span class="tag warn">Vorschau mit Beispieldaten</span><?php endif; ?>
    <span class="muted small"><?= count($customerRows) > 1 ? count($customerRows) . ' Dokumente' : '' ?></span>
    <button type="button" class="btn primary" data-print>Drucken / als PDF speichern</button>
    <button type="button" class="btn" data-close>Schließen</button>
</div>
<?php foreach ($customerRows as $c):
    $values = $base + customer_values($c, $cid); ?>
<section class="sheet">
    <?php if ($header !== ''): ?><header class="doc-header doc-content"><?= fill_placeholders($header, $values, $logo) ?></header><?php endif; ?>
    <div class="doc-body doc-content"><?= fill_placeholders((string)$form['body'], $values, $logo) ?></div>
    <?php if ($footer !== ''): ?><footer class="doc-footer doc-content"><?= fill_placeholders($footer, $values, $logo) ?></footer><?php endif; ?>
</section>
<?php endforeach; ?>
<script src="assets/app.js"></script>
</body></html>
<?php
    exit;
}
