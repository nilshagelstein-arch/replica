<?php
/**
 * Stammdaten je Firma: Abteilungen, Positionen, Katalog (Geräte, Lizenzen, Programme,
 * Netzlaufwerke, Freigaben) und Vorlagen. Außerdem das Protokoll für Administratoren.
 */

function catalog_for_company(int $companyId, bool $onlyActive = true): array
{
    $sql = 'SELECT * FROM catalog_items WHERE (company_id IS NULL OR company_id = ?)'
        . ($onlyActive ? ' AND active = 1' : '') . ' ORDER BY sort, name';
    $grouped = array_fill_keys(array_keys(ITEM_TYPES), []);
    foreach (rows($sql, [$companyId]) as $it) $grouped[$it['type']][] = $it;
    return $grouped;
}

function departments_of(int $companyId, bool $onlyActive = true): array
{
    return rows('SELECT * FROM departments WHERE company_id = ?' . ($onlyActive ? ' AND active = 1' : '') . ' ORDER BY name', [$companyId]);
}

function positions_of(int $companyId, bool $onlyActive = true): array
{
    return rows('SELECT p.*, d.name AS department_name FROM positions p LEFT JOIN departments d ON d.id = p.department_id
        WHERE p.company_id = ?' . ($onlyActive ? ' AND p.active = 1' : '') . ' ORDER BY p.name', [$companyId]);
}

/** Firmenkontext für Stammdatenseiten ermitteln und prüfen */
function masterdata_company(): int
{
    require_manager();
    require_app('onboarding');
    $cid = context_company_id();
    if (!$cid) {
        flash('info', 'Bitte legen Sie zuerst eine Firma an.');
        redirect('companies');
    }
    require_company_access($cid);
    if (!row('SELECT id FROM companies WHERE id = ?', [$cid])) abort(404);
    return $cid;
}

function company_name(int $id): string
{
    return (string)(row('SELECT name FROM companies WHERE id = ?', [$id])['name'] ?? '');
}

// ---------------------------------------------------------------------------
// Abteilungen & Positionen
// ---------------------------------------------------------------------------
function page_structure(): void
{
    $cid = masterdata_company();
    $back = is_admin() ? ['company' => $cid] : [];
    if (is_post()) {
        require_post_csrf();
        $a = post('action');
        $name = mb_substr(post('name'), 0, 190);
        $depId = (int)post('department_id', 0);
        if ($depId && !row('SELECT id FROM departments WHERE id = ? AND company_id = ?', [$depId, $cid])) $depId = 0;
        if ($a === 'add_dep' && $name !== '') {
            insert('departments', ['company_id' => $cid, 'name' => $name, 'active' => 1]);
            audit('department_created', $name, $cid);
        } elseif ($a === 'save_dep') {
            $id = (int)post('id', 0);
            if (row('SELECT id FROM departments WHERE id = ? AND company_id = ?', [$id, $cid]) && $name !== '') {
                update('departments', $id, ['name' => $name, 'active' => post('active') === '1' ? 1 : 0]);
            }
        } elseif ($a === 'add_pos' && $name !== '') {
            insert('positions', ['company_id' => $cid, 'department_id' => $depId ?: null, 'name' => $name, 'active' => 1]);
            audit('position_created', $name, $cid);
        } elseif ($a === 'save_pos') {
            $id = (int)post('id', 0);
            if (row('SELECT id FROM positions WHERE id = ? AND company_id = ?', [$id, $cid]) && $name !== '') {
                update('positions', $id, ['name' => $name, 'department_id' => $depId ?: null, 'active' => post('active') === '1' ? 1 : 0]);
            }
        }
        flash('success', 'Gespeichert.');
        redirect('structure', $back);
    }
    $deps = departments_of($cid, false);
    $poss = positions_of($cid, false);
    render_page('Abteilungen & Positionen: ' . company_name($cid), function () use ($cid, $deps, $poss) {
        echo '<div class="toolbar">' . company_switcher($cid, 'structure') . '</div>'; ?>
        <p class="muted">Abteilungen und Positionen erscheinen im Auftragsformular. In den <a href="<?= e(url('templates', is_admin() ? ['company' => $cid] : [])) ?>">Vorlagen</a>
            legen Sie fest, welche Geräte, Programme, Laufwerke und Freigaben jeweils vorausgewählt werden.</p>
        <div class="grid2">
            <div class="card">
                <h2>Abteilungen</h2>
                <table class="table compact">
                    <thead><tr><th>Name</th><th>Aktiv</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($deps as $d): $f = 'dep' . $d['id']; ?>
                        <tr class="<?= (int)$d['active'] ? '' : 'inactive' ?>">
                            <td><input form="<?= $f ?>" name="name" value="<?= e($d['name']) ?>" required maxlength="190"></td>
                            <td><input form="<?= $f ?>" type="checkbox" name="active" value="1" <?= (int)$d['active'] ? 'checked' : '' ?>></td>
                            <td><form id="<?= $f ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_dep">
                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="company_id" value="<?= $cid ?>">
                                <button class="btn small">Speichern</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$deps): ?><tr><td colspan="3" class="muted">Noch keine Abteilungen.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                <form method="post" class="form inline-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="add_dep"><input type="hidden" name="company_id" value="<?= $cid ?>">
                    <input name="name" placeholder="Neue Abteilung, z. B. Buchhaltung" required maxlength="190">
                    <button class="btn primary">Hinzufügen</button>
                </form>
            </div>
            <div class="card">
                <h2>Positionen</h2>
                <table class="table compact">
                    <thead><tr><th>Name</th><th>Abteilung</th><th>Aktiv</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($poss as $p): $f = 'pos' . $p['id']; ?>
                        <tr class="<?= (int)$p['active'] ? '' : 'inactive' ?>">
                            <td><input form="<?= $f ?>" name="name" value="<?= e($p['name']) ?>" required maxlength="190"></td>
                            <td><select form="<?= $f ?>" name="department_id"><option value="0">– alle –</option>
                                <?php foreach ($deps as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$p['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                            </select></td>
                            <td><input form="<?= $f ?>" type="checkbox" name="active" value="1" <?= (int)$p['active'] ? 'checked' : '' ?>></td>
                            <td><form id="<?= $f ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_pos">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="company_id" value="<?= $cid ?>">
                                <button class="btn small">Speichern</button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$poss): ?><tr><td colspan="4" class="muted">Noch keine Positionen.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                <form method="post" class="form inline-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="add_pos"><input type="hidden" name="company_id" value="<?= $cid ?>">
                    <input name="name" placeholder="Neue Position, z. B. Sachbearbeiter/in" required maxlength="190">
                    <select name="department_id"><option value="0">– alle Abteilungen –</option>
                        <?php foreach ($deps as $d): if ((int)$d['active']): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endif; endforeach; ?>
                    </select>
                    <button class="btn primary">Hinzufügen</button>
                </form>
            </div>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Katalog
// ---------------------------------------------------------------------------
function can_edit_catalog_item(array $item): bool
{
    if ($item['company_id'] === null) return admin_all_companies();
    return can_access_company((int)$item['company_id']);
}

function page_catalog(): void
{
    $cid = masterdata_company();
    $back = is_admin() ? ['company' => $cid] : [];
    if (is_post()) {
        require_post_csrf();
        $a = post('action');
        $name = mb_substr(post('name'), 0, 190);
        $desc = mb_substr(post('description'), 0, 500);
        if ($a === 'add') {
            $type = post('type');
            $global = admin_all_companies() && post('scope') === 'global';
            if (isset(ITEM_TYPES[$type]) && $name !== '') {
                insert('catalog_items', ['company_id' => $global ? null : $cid, 'type' => $type, 'name' => $name,
                    'description' => $desc, 'active' => 1, 'sort' => (int)post('sort', 0)]);
                audit('catalog_created', ITEM_TYPES[$type] . ': ' . $name . ($global ? ' (global)' : ''), $cid);
                flash('success', 'Eintrag hinzugefügt.');
            }
        } elseif ($a === 'save') {
            $item = row('SELECT * FROM catalog_items WHERE id = ?', [(int)post('id', 0)]);
            if (!$item || !can_edit_catalog_item($item)) abort(403);
            if ($name !== '') {
                update('catalog_items', (int)$item['id'], ['name' => $name, 'description' => $desc,
                    'active' => post('active') === '1' ? 1 : 0, 'sort' => (int)post('sort', 0)]);
                flash('success', 'Gespeichert.');
            }
        }
        redirect('catalog', $back);
    }
    $items = catalog_for_company($cid, false);
    render_page('Katalog: ' . company_name($cid), function () use ($cid, $items) {
        echo '<div class="toolbar">' . company_switcher($cid, 'catalog') . '</div>'; ?>
        <p class="muted">Einträge mit dem Kennzeichen <span class="tag">alle Firmen</span> pflegen die Administratoren zentral.
            Firmeneigene Einträge – vor allem Netzlaufwerke und Freigaben – pflegt jede Firma selbst.</p>
        <?php foreach (ITEM_TYPES as $type => $label): ?>
        <div class="card">
            <h2><?= e($label) ?></h2>
            <table class="table compact">
                <thead><tr><th>Bezeichnung</th><th>Beschreibung / Pfad</th><th class="num">Sortierung</th><th>Aktiv</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($items[$type] as $it): $f = 'cat' . $it['id']; $editable = can_edit_catalog_item($it); ?>
                    <tr class="<?= (int)$it['active'] ? '' : 'inactive' ?>">
                        <?php if ($editable): ?>
                            <td><input form="<?= $f ?>" name="name" value="<?= e($it['name']) ?>" required maxlength="190">
                                <?= $it['company_id'] === null ? '<span class="tag">alle Firmen</span>' : '' ?></td>
                            <td><input form="<?= $f ?>" name="description" value="<?= e($it['description']) ?>" maxlength="500"></td>
                            <td class="num"><input form="<?= $f ?>" type="number" name="sort" value="<?= (int)$it['sort'] ?>" class="tiny"></td>
                            <td><input form="<?= $f ?>" type="checkbox" name="active" value="1" <?= (int)$it['active'] ? 'checked' : '' ?>></td>
                            <td><form id="<?= $f ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save">
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="company_id" value="<?= $cid ?>">
                                <button class="btn small">Speichern</button></form></td>
                        <?php else: ?>
                            <td><?= e($it['name']) ?> <span class="tag">alle Firmen</span></td>
                            <td><?= e($it['description']) ?></td>
                            <td class="num"><?= (int)$it['sort'] ?></td>
                            <td><?= (int)$it['active'] ? '✓' : '–' ?></td>
                            <td></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items[$type]): ?><tr><td colspan="5" class="muted">Keine Einträge.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <form method="post" class="form inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="type" value="<?= e($type) ?>">
                <input type="hidden" name="company_id" value="<?= $cid ?>">
                <input name="name" placeholder="<?= $type === 'drive' ? 'z. B. S: Gemeinsam' : 'Bezeichnung' ?>" required maxlength="190">
                <input name="description" placeholder="<?= $type === 'drive' ? '\\\\server\\freigabe' : 'Beschreibung (optional)' ?>" maxlength="500">
                <?php if (admin_all_companies()): ?>
                    <select name="scope"><option value="company">nur diese Firma</option><option value="global">alle Firmen</option></select>
                <?php endif; ?>
                <button class="btn primary">Hinzufügen</button>
            </form>
        </div>
        <?php endforeach; ?>
    <?php });
}

// ---------------------------------------------------------------------------
// Vorlagen
// ---------------------------------------------------------------------------
function template_map(int $companyId): array
{
    $map = ['dep' => [], 'pos' => []];
    foreach (rows('SELECT * FROM template_items WHERE company_id = ?', [$companyId]) as $t) {
        if ($t['department_id']) $map['dep'][(int)$t['department_id']][] = (int)$t['catalog_item_id'];
        if ($t['position_id']) $map['pos'][(int)$t['position_id']][] = (int)$t['catalog_item_id'];
    }
    return $map;
}

function page_templates(): void
{
    $cid = masterdata_company();
    $deps = departments_of($cid);
    $poss = positions_of($cid);
    $target = (string)($_GET['t'] ?? post('t', ''));
    if ($target === '' && $deps) $target = 'dep:' . $deps[0]['id'];
    if ($target === '' && $poss) $target = 'pos:' . $poss[0]['id'];
    [$kind, $tid] = array_pad(explode(':', $target), 2, '0');
    $tid = (int)$tid;
    $valid = ($kind === 'dep' && row('SELECT id FROM departments WHERE id = ? AND company_id = ?', [$tid, $cid]))
          || ($kind === 'pos' && row('SELECT id FROM positions WHERE id = ? AND company_id = ?', [$tid, $cid]));
    $params = (is_admin() ? ['company' => $cid] : []);

    if (is_post() && $valid) {
        require_post_csrf();
        $col = $kind === 'dep' ? 'department_id' : 'position_id';
        q("DELETE FROM template_items WHERE company_id = ? AND $col = ?", [$cid, $tid]);
        $allowed = [];
        foreach (catalog_for_company($cid) as $list) foreach ($list as $it) $allowed[(int)$it['id']] = true;
        foreach ((array)($_POST['items'] ?? []) as $itemId) {
            $itemId = (int)$itemId;
            if (isset($allowed[$itemId])) {
                insert('template_items', ['company_id' => $cid, 'department_id' => $kind === 'dep' ? $tid : null,
                    'position_id' => $kind === 'pos' ? $tid : null, 'catalog_item_id' => $itemId]);
            }
        }
        audit('template_saved', $target, $cid);
        flash('success', 'Die Vorlage wurde gespeichert.');
        redirect('templates', $params + ['t' => $target]);
    }

    $map = template_map($cid);
    $selected = $valid ? ($map[$kind][$tid] ?? []) : [];
    $catalog = catalog_for_company($cid);
    render_page('Vorlagen: ' . company_name($cid), function () use ($cid, $deps, $poss, $target, $valid, $selected, $catalog, $map) {
        echo '<div class="toolbar">' . company_switcher($cid, 'templates') . '</div>'; ?>
        <p class="muted">Wählen Sie eine Abteilung oder Position und markieren Sie, was neue Mitarbeitende dort standardmäßig erhalten.
            Im Auftragsformular werden die Einträge von Abteilung <em>und</em> Position zusammen vorausgewählt und können angepasst werden.</p>
        <?php if (!$deps && !$poss): ?>
            <div class="card"><p>Bitte legen Sie zuerst <a href="<?= e(url('structure', is_admin() ? ['company' => $cid] : [])) ?>">Abteilungen und Positionen</a> an.</p></div>
        <?php return; endif; ?>
        <div class="template-layout">
            <div class="card">
                <h2>Abteilungen</h2>
                <ul class="pick">
                <?php foreach ($deps as $d): $k = 'dep:' . $d['id']; ?>
                    <li class="<?= $k === $target ? 'active' : '' ?>"><a href="<?= e(url('templates', (is_admin() ? ['company' => $cid] : []) + ['t' => $k])) ?>"><?= e($d['name']) ?></a>
                        <span class="muted">(<?= count($map['dep'][(int)$d['id']] ?? []) ?>)</span></li>
                <?php endforeach; ?>
                </ul>
                <h2>Positionen</h2>
                <ul class="pick">
                <?php foreach ($poss as $p): $k = 'pos:' . $p['id']; ?>
                    <li class="<?= $k === $target ? 'active' : '' ?>"><a href="<?= e(url('templates', (is_admin() ? ['company' => $cid] : []) + ['t' => $k])) ?>"><?= e($p['name']) ?></a>
                        <span class="muted">(<?= count($map['pos'][(int)$p['id']] ?? []) ?>)</span></li>
                <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($valid): ?>
            <form method="post" class="card form">
                <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($target) ?>"><input type="hidden" name="company_id" value="<?= $cid ?>">
                <?php foreach (ITEM_TYPES as $type => $label): if (!$catalog[$type]) continue; ?>
                    <fieldset><legend><?= e($label) ?></legend>
                    <div class="checks">
                    <?php foreach ($catalog[$type] as $it): ?>
                        <label class="check"><input type="checkbox" name="items[]" value="<?= (int)$it['id'] ?>" <?= in_array((int)$it['id'], $selected, true) ? 'checked' : '' ?>>
                            <?= e($it['name']) ?><?= $it['description'] ? ' <span class="muted small">' . e($it['description']) . '</span>' : '' ?></label>
                    <?php endforeach; ?>
                    </div></fieldset>
                <?php endforeach; ?>
                <button class="btn primary">Vorlage speichern</button>
            </form>
            <?php endif; ?>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Protokoll
// ---------------------------------------------------------------------------
function page_audit(): void
{
    require_admin();
    $list = rows('SELECT a.*, u.name AS user_name, u.email AS user_email, c.name AS company_name
        FROM audit_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN companies c ON c.id = a.company_id
        WHERE 1 = 1' . company_scope_sql('a.company_id') . '
        ORDER BY a.id DESC LIMIT 500');
    render_page('Protokoll', function () use ($list) { ?>
        <div class="card">
            <p class="muted">Die letzten 500 sicherheitsrelevanten Ereignisse (Anmeldungen, Änderungen an Benutzern und Stammdaten).</p>
            <table class="table compact">
                <thead><tr><th>Zeit</th><th>Benutzer</th><th>Firma</th><th>Aktion</th><th>Details</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($list as $a): ?>
                    <tr><td><?= fmt_dt($a['created_at']) ?></td><td><?= e($a['user_name'] ?? '–') ?></td><td><?= e($a['company_name'] ?? '–') ?></td>
                        <td><code><?= e($a['action']) ?></code></td><td><?= e($a['details']) ?></td><td><?= e($a['ip']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php });
}
