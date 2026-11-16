<?php
/**
 * Onboarding-Aufträge: Übersicht, Formular, Detailansicht, Status-Workflow.
 */

/** Lädt einen Auftrag und prüft die Mandantenberechtigung */
function load_request(int $id): array
{
    require_login();
    $r = row('SELECT r.*, c.name AS company_name, c.email_domain, u.name AS creator_name, u.email AS creator_email
        FROM requests r JOIN companies c ON c.id = r.company_id LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ?', [$id]);
    if (!$r) abort(404);
    require_company_access((int)$r['company_id']);
    return $r;
}

function request_editable(array $r): bool
{
    if (is_admin()) return !in_array($r['status'], ['freigegeben', 'storniert'], true);
    return in_array($r['status'], ['eingereicht', 'rueckfrage'], true);
}

function request_log(int $requestId, string $kind, string $message = '', ?string $from = null, ?string $to = null, bool $internal = false): void
{
    insert('request_log', [
        'request_id' => $requestId, 'user_id' => current_user()['id'] ?? null, 'kind' => $kind,
        'status_from' => $from, 'status_to' => $to, 'message' => $message, 'internal' => $internal ? 1 : 0,
        'created_at' => now(),
    ]);
}

function request_title(array $r): string
{
    return '#' . $r['id'] . ' ' . $r['first_name'] . ' ' . $r['last_name'];
}

// ---------------------------------------------------------------------------
// Übersicht
// ---------------------------------------------------------------------------
function page_dashboard(): void
{
    $u = require_login();
    $where = [];
    $params = [];
    $companyFilter = 0;
    if ($u['role'] === 'admin') {
        $companyFilter = query_int('company');
        if ($companyFilter) { $where[] = 'r.company_id = ?'; $params[] = $companyFilter; }
    } else {
        $where[] = 'r.company_id = ?';
        $params[] = $u['company_id'];
    }
    $baseWhere = $where;
    $baseParams = $params;

    $status = (string)($_GET['status'] ?? 'offen');
    if ($status === 'offen') {
        $where[] = "r.status IN ('" . implode("','", OPEN_STATUSES) . "')";
    } elseif (isset(STATUSES[$status])) {
        $where[] = 'r.status = ?';
        $params[] = $status;
    } else {
        $status = 'alle';
    }
    $search = trim((string)($_GET['q'] ?? ''));
    if ($search !== '') {
        $where[] = "(r.first_name LIKE ? ESCAPE '!' OR r.last_name LIKE ? ESCAPE '!' OR r.employee_no LIKE ? ESCAPE '!')";
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        array_push($params, $like, $like, $like);
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $list = rows("SELECT r.*, c.name AS company_name,
            (SELECT COUNT(*) FROM request_items i WHERE i.request_id = r.id) AS item_count,
            (SELECT COUNT(*) FROM request_items i WHERE i.request_id = r.id AND i.done = 1) AS done_count
        FROM requests r JOIN companies c ON c.id = r.company_id $sqlWhere
        ORDER BY CASE WHEN r.status IN ('freigegeben','storniert') THEN 1 ELSE 0 END, r.entry_date, r.id DESC LIMIT 500", $params);

    $counts = array_fill_keys(array_keys(STATUSES), 0);
    $cw = $baseWhere ? 'WHERE ' . implode(' AND ', $baseWhere) : '';
    foreach (rows("SELECT r.status, COUNT(*) AS n FROM requests r $cw GROUP BY r.status", $baseParams) as $c) {
        $counts[$c['status']] = (int)$c['n'];
    }

    render_page('Onboarding-Aufträge', function () use ($u, $list, $counts, $status, $search, $companyFilter) {
        $open = array_sum(array_intersect_key($counts, array_flip(OPEN_STATUSES)));
        $keep = $companyFilter ? ['company' => $companyFilter] : []; ?>
        <div class="tiles">
            <a class="tile <?= $status === 'offen' ? 'active' : '' ?>" href="<?= e(url('dashboard', $keep + ['status' => 'offen'])) ?>"><strong><?= $open ?></strong><span>Offen</span></a>
            <?php foreach (STATUSES as $k => $label): ?>
                <a class="tile st-<?= e($k) ?> <?= $status === $k ? 'active' : '' ?>" href="<?= e(url('dashboard', $keep + ['status' => $k])) ?>"><strong><?= $counts[$k] ?></strong><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
            <a class="tile <?= $status === 'alle' ? 'active' : '' ?>" href="<?= e(url('dashboard', $keep + ['status' => 'alle'])) ?>"><strong><?= array_sum($counts) ?></strong><span>Alle</span></a>
        </div>
        <form method="get" class="toolbar">
            <input type="hidden" name="r" value="dashboard"><input type="hidden" name="status" value="<?= e($status) ?>">
            <?php if ($u['role'] === 'admin'): ?>
                <label>Firma <select name="company"><option value="0">Alle Firmen</option>
                    <?php foreach (companies_for_select() as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $companyFilter === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                </select></label>
            <?php endif; ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name oder Personalnummer">
            <button class="btn">Filtern</button>
            <a class="btn primary push-right" href="<?= e(url('request_new', $companyFilter ? ['company' => $companyFilter] : [])) ?>">+ Neuer Auftrag</a>
        </form>
        <div class="card">
        <?php if (!$list): ?>
            <p class="muted">Keine Aufträge gefunden.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Nr.</th><?php if ($u['role'] === 'admin'): ?><th>Firma</th><?php endif; ?><th>Mitarbeiter/in</th><th>Pers.-Nr.</th>
                    <th>Abteilung / Position</th><th>Eintritt</th><th>Fortschritt</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($list as $r):
                    $soon = in_array($r['status'], OPEN_STATUSES, true) && strtotime($r['entry_date']) <= strtotime('+7 days');
                    $pct = $r['item_count'] ? round(100 * $r['done_count'] / $r['item_count']) : 0; ?>
                    <tr>
                        <td><a href="<?= e(url('request', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td>
                        <?php if ($u['role'] === 'admin'): ?><td><?= e($r['company_name']) ?></td><?php endif; ?>
                        <td><a href="<?= e(url('request', ['id' => $r['id']])) ?>"><strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong></a></td>
                        <td><?= e($r['employee_no']) ?></td>
                        <td><?= e($r['department_name']) ?><br><span class="muted small"><?= e($r['position_name']) ?></span></td>
                        <td class="<?= $soon ? 'soon' : '' ?>"><?= fmt_date($r['entry_date']) ?><?= $soon ? ' ⚠' : '' ?></td>
                        <td><progress max="100" value="<?= $pct ?>" title="<?= (int)$r['done_count'] ?> von <?= (int)$r['item_count'] ?> erledigt"></progress>
                            <span class="muted small"><?= (int)$r['done_count'] ?>/<?= (int)$r['item_count'] ?></span></td>
                        <td><?= status_badge($r['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Formular (neu / bearbeiten)
// ---------------------------------------------------------------------------
function page_request_form(): void
{
    $u = require_login();
    $id = query_int('id');
    $existing = $id ? load_request($id) : null;
    if ($existing && !request_editable($existing)) {
        flash('error', 'Dieser Auftrag kann im aktuellen Status nicht mehr geändert werden.');
        redirect('request', ['id' => $id]);
    }

    if ($existing) {
        $cid = (int)$existing['company_id'];
    } elseif ($u['role'] === 'admin') {
        $cid = query_int('company') ?: (int)post('company_id', 0);
    } else {
        $cid = (int)$u['company_id'];
    }
    $company = $cid ? row('SELECT * FROM companies WHERE id = ? AND active = 1', [$cid]) : null;

    // Admin muss zuerst die Firma wählen
    if (!$company) {
        if ($u['role'] !== 'admin') abort(403);
        $companies = rows('SELECT id, name FROM companies WHERE active = 1 ORDER BY name');
        render_page('Neuer Auftrag', function () use ($companies) { ?>
            <form method="get" class="card form narrow">
                <input type="hidden" name="r" value="request_new">
                <label>Für welche Firma wird der Auftrag angelegt?
                    <select name="company" required><option value="">– bitte wählen –</option>
                    <?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                    </select></label>
                <button class="btn primary">Weiter</button>
            </form>
        <?php });
        return;
    }
    require_company_access($cid);

    $deps = departments_of($cid);
    $poss = positions_of($cid);
    $catalog = catalog_for_company($cid);
    $catalogIds = [];
    foreach ($catalog as $list) foreach ($list as $it) $catalogIds[(int)$it['id']] = $it;

    $fields = ['salutation' => '', 'first_name' => '', 'last_name' => '', 'employee_no' => '', 'department_id' => 0,
        'position_id' => 0, 'entry_date' => '', 'location' => '', 'manager_name' => '', 'email_wish' => '', 'phone' => '', 'notes' => ''];
    $data = $existing ? array_intersect_key($existing, $fields) : $fields;
    $selected = [];
    $itemNotes = [];
    $extras = array_fill_keys(array_keys(ITEM_TYPES), '');
    if ($existing) {
        foreach (rows('SELECT * FROM request_items WHERE request_id = ?', [$id]) as $it) {
            if ($it['catalog_item_id']) {
                $selected[] = (int)$it['catalog_item_id'];
                $itemNotes[(int)$it['catalog_item_id']] = $it['note'];
            } else {
                $extras[$it['type']] .= ($extras[$it['type']] ? "\n" : '') . $it['name'];
            }
        }
    }
    $errors = [];

    if (is_post()) {
        require_post_csrf();
        foreach ($fields as $f => $_) $data[$f] = is_int($fields[$f]) ? (int)post($f, 0) : mb_substr((string)post($f), 0, $f === 'notes' ? 5000 : 190);
        $selected = array_values(array_filter(array_map('intval', (array)($_POST['items'] ?? [])), fn($i) => isset($catalogIds[$i])));
        foreach ((array)($_POST['item_note'] ?? []) as $iid => $note) $itemNotes[(int)$iid] = mb_substr(trim((string)$note), 0, 500);
        foreach (ITEM_TYPES as $t => $_) $extras[$t] = mb_substr(trim((string)($_POST['extra'][$t] ?? '')), 0, 2000);

        if ($data['first_name'] === '') $errors[] = 'Bitte den Vornamen angeben.';
        if ($data['last_name'] === '') $errors[] = 'Bitte den Nachnamen angeben.';
        if ($data['employee_no'] === '') $errors[] = 'Bitte die Mitarbeiternummer angeben.';
        $dep = $data['department_id'] ? row('SELECT * FROM departments WHERE id = ? AND company_id = ?', [$data['department_id'], $cid]) : null;
        $pos = $data['position_id'] ? row('SELECT * FROM positions WHERE id = ? AND company_id = ?', [$data['position_id'], $cid]) : null;
        if (!$dep) $errors[] = 'Bitte eine Abteilung wählen.';
        if (!$pos) $errors[] = 'Bitte eine Position wählen.';
        $d = DateTime::createFromFormat('!Y-m-d', $data['entry_date']);
        if (!$d || $d->format('Y-m-d') !== $data['entry_date']) $errors[] = 'Bitte ein gültiges Eintrittsdatum angeben.';
        if ($data['email_wish'] !== '' && !filter_var($data['email_wish'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Die gewünschte E-Mail-Adresse ist ungültig.';
        if ($data['employee_no'] !== '') {
            $dup = row("SELECT id FROM requests WHERE company_id = ? AND employee_no = ? AND status <> 'storniert' AND id <> ?",
                [$cid, $data['employee_no'], $id ?: 0]);
            if ($dup) $errors[] = 'Für diese Mitarbeiternummer gibt es bereits einen Auftrag (#' . $dup['id'] . ').';
        }

        if (!$errors) {
            $save = $data;
            $save['department_id'] = (int)$dep['id'];
            $save['position_id'] = (int)$pos['id'];
            $save['department_name'] = $dep['name'];
            $save['position_name'] = $pos['name'];
            $save['updated_at'] = now();
            db()->beginTransaction();
            if ($existing) {
                update('requests', $id, $save);
            } else {
                $save += ['company_id' => $cid, 'created_by' => $u['id'], 'status' => 'eingereicht', 'created_at' => now()];
                $id = insert('requests', $save);
            }
            // Positionen abgleichen: Erledigt-Status bestehender Einträge bleibt erhalten
            $current = [];
            foreach (rows('SELECT * FROM request_items WHERE request_id = ? AND catalog_item_id IS NOT NULL', [$id]) as $it) {
                $current[(int)$it['catalog_item_id']] = $it;
            }
            foreach ($current as $cidItem => $it) {
                if (!in_array($cidItem, $selected, true)) q('DELETE FROM request_items WHERE id = ?', [$it['id']]);
            }
            foreach ($selected as $iid) {
                $note = $itemNotes[$iid] ?? '';
                if (isset($current[$iid])) {
                    update('request_items', (int)$current[$iid]['id'], ['note' => $note]);
                } else {
                    $ci = $catalogIds[$iid];
                    insert('request_items', ['request_id' => $id, 'catalog_item_id' => $iid, 'type' => $ci['type'],
                        'name' => $ci['name'] . ($ci['type'] === 'drive' && $ci['description'] ? ' (' . $ci['description'] . ')' : ''),
                        'note' => $note, 'done' => 0]);
                }
            }
            q('DELETE FROM request_items WHERE request_id = ? AND catalog_item_id IS NULL', [$id]);
            foreach ($extras as $type => $text) {
                foreach (preg_split('/\R/', $text) as $line) {
                    $line = trim($line);
                    if ($line !== '') insert('request_items', ['request_id' => $id, 'catalog_item_id' => null, 'type' => $type,
                        'name' => mb_substr($line, 0, 190), 'note' => 'zusätzlich angefordert', 'done' => 0]);
                }
            }
            request_log($id, 'system', $existing ? 'Auftrag geändert' : 'Auftrag eingereicht');
            db()->commit();

            $r = load_request($id);
            $subject = ($existing ? 'Onboarding-Auftrag geändert: ' : 'Neuer Onboarding-Auftrag: ') . request_title($r) . ' (' . $r['company_name'] . ')';
            $body = "Firma: {$r['company_name']}\nMitarbeiter/in: {$r['first_name']} {$r['last_name']} (Pers.-Nr. {$r['employee_no']})\n"
                . "Abteilung/Position: {$r['department_name']} / {$r['position_name']}\nEintritt: " . fmt_date($r['entry_date'])
                . "\nErstellt/geändert von: {$u['name']}\n\nZum Auftrag: " . request_link($id);
            if ($u['role'] === 'admin') {
                if (!$existing) send_mail(company_recipients($cid, $u['id']), $subject, "Für Ihre Firma wurde ein Onboarding-Auftrag angelegt.\n\n" . $body);
            } else {
                send_mail((array)cfg('mail.admin_recipients', []), $subject, $body);
            }
            flash('success', $existing ? 'Der Auftrag wurde aktualisiert.' : 'Der Auftrag wurde eingereicht. Sie werden über Statusänderungen informiert.');
            redirect('request', ['id' => $id]);
        }
    } elseif (!$existing) {
        // Vorschlag aus Abteilung/Position wird per JavaScript gesetzt
        $selected = [];
    }

    $tpl = template_map($cid);
    $posDept = [];
    foreach ($poss as $p) $posDept[(int)$p['id']] = (int)$p['department_id'];
    $jsData = ['dep' => $tpl['dep'], 'pos' => $tpl['pos'], 'posDept' => $posDept, 'isNew' => !$existing, 'domain' => $company['email_domain']];

    $title = $existing ? 'Auftrag bearbeiten: ' . request_title($existing) : 'Neuer Onboarding-Auftrag';
    render_page($title, function () use ($company, $data, $errors, $deps, $poss, $catalog, $selected, $itemNotes, $extras, $jsData, $existing, $cid) {
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>'; ?>
        <p class="muted">Firma: <strong><?= e($company['name']) ?></strong></p>
        <form method="post" class="form request-form" id="request-form"
              action="<?= e($existing ? url('request_edit', ['id' => $existing['id']]) : url('request_new', is_admin() ? ['company' => $cid] : [])) ?>">
            <?= csrf_field() ?><input type="hidden" name="company_id" value="<?= (int)$cid ?>">
            <script type="application/json" id="template-data"><?= json_encode($jsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <div class="card">
                <h2>1. Mitarbeiter/in</h2>
                <div class="grid3">
                    <label>Anrede
                        <select name="salutation"><?php foreach (['', 'Frau', 'Herr', 'Divers'] as $s): ?><option <?= $data['salutation'] === $s ? 'selected' : '' ?> value="<?= e($s) ?>"><?= $s === '' ? '–' : e($s) ?></option><?php endforeach; ?></select>
                    </label>
                    <label>Vorname *<input name="first_name" required maxlength="120" value="<?= e($data['first_name']) ?>"></label>
                    <label>Nachname *<input name="last_name" required maxlength="120" value="<?= e($data['last_name']) ?>"></label>
                    <label>Mitarbeiternummer *<input name="employee_no" required maxlength="60" value="<?= e($data['employee_no']) ?>"></label>
                    <label>Eintrittsdatum *<input type="date" name="entry_date" required value="<?= e($data['entry_date']) ?>"></label>
                    <label>Standort / Büro<input name="location" maxlength="190" value="<?= e($data['location']) ?>"></label>
                    <label>Abteilung *
                        <select name="department_id" id="department_id" required><option value="">– bitte wählen –</option>
                        <?php foreach ($deps as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$data['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                        </select></label>
                    <label>Position *
                        <select name="position_id" id="position_id" required><option value="">– bitte wählen –</option>
                        <?php foreach ($poss as $p): ?><option value="<?= (int)$p['id'] ?>" data-dep="<?= (int)$p['department_id'] ?>" <?= (int)$data['position_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                        </select></label>
                    <label>Vorgesetzte/r<input name="manager_name" maxlength="190" value="<?= e($data['manager_name']) ?>"></label>
                    <label>Gewünschte E-Mail-Adresse<input type="email" name="email_wish" id="email_wish" maxlength="190" value="<?= e($data['email_wish']) ?>" placeholder="<?= $company['email_domain'] ? 'vorname.nachname@' . e($company['email_domain']) : '' ?>"></label>
                    <label>Telefon / Durchwahl<input name="phone" maxlength="60" value="<?= e($data['phone']) ?>"></label>
                </div>
                <?php if (!$deps || !$poss): ?>
                    <p class="alert info">Für diese Firma sind noch keine Abteilungen oder Positionen angelegt.
                        <?php if (is_admin() || is_company_admin()): ?><a href="<?= e(url('structure', is_admin() ? ['company' => $cid] : [])) ?>">Jetzt anlegen</a><?php else: ?>Bitte wenden Sie sich an Ihren Firmen-Administrator.<?php endif; ?></p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2>2. Ausstattung, Zugänge und Freigaben</h2>
                <p class="muted" id="template-hint">Nach Auswahl von Abteilung und Position werden die üblichen Einträge automatisch vorausgewählt. Sie können die Auswahl anpassen.</p>
                <?php foreach (ITEM_TYPES as $type => $label): ?>
                    <fieldset>
                        <legend><?= e($label) ?></legend>
                        <div class="checks">
                        <?php foreach ($catalog[$type] as $it): $iid = (int)$it['id']; $on = in_array($iid, $selected, true); ?>
                            <div class="check-row">
                                <label class="check"><input type="checkbox" name="items[]" value="<?= $iid ?>" <?= $on ? 'checked' : '' ?>>
                                    <?= e($it['name']) ?><?= $it['description'] ? ' <span class="muted small">' . e($it['description']) . '</span>' : '' ?></label>
                                <input class="item-note" name="item_note[<?= $iid ?>]" value="<?= e($itemNotes[$iid] ?? '') ?>" placeholder="Bemerkung (z. B. Lesen/Schreiben, Anzahl)" maxlength="500">
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$catalog[$type]): ?><p class="muted small">Keine Einträge im Katalog.</p><?php endif; ?>
                        </div>
                        <label class="small">Weitere <?= e($label) ?> (eine pro Zeile)
                            <textarea name="extra[<?= e($type) ?>]" rows="2"><?= e($extras[$type]) ?></textarea></label>
                    </fieldset>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <h2>3. Hinweise</h2>
                <label>Weitere Informationen für die IT<textarea name="notes" rows="4"><?= e($data['notes']) ?></textarea></label>
                <p class="muted small">Bitte keine Passwörter oder Gesundheitsdaten eintragen.</p>
            </div>
            <div class="buttons">
                <button class="btn primary"><?= $existing ? 'Änderungen speichern' : 'Auftrag absenden' ?></button>
                <a class="btn" href="<?= e($existing ? url('request', ['id' => $existing['id']]) : url('dashboard')) ?>">Abbrechen</a>
            </div>
        </form>
    <?php });
}

// ---------------------------------------------------------------------------
// Detailansicht
// ---------------------------------------------------------------------------
function request_items_grouped(int $id): array
{
    $g = array_fill_keys(array_keys(ITEM_TYPES), []);
    foreach (rows('SELECT i.*, u.name AS done_by_name FROM request_items i LEFT JOIN users u ON u.id = i.done_by
        WHERE i.request_id = ? ORDER BY i.id', [$id]) as $it) {
        $g[$it['type']][] = $it;
    }
    return $g;
}

function page_request_detail(): void
{
    $u = require_login();
    $r = load_request(query_int('id'));
    $items = request_items_grouped((int)$r['id']);
    $logSql = 'SELECT l.*, u.name AS user_name, u.role AS user_role FROM request_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.request_id = ?'
        . (is_admin() ? '' : ' AND l.internal = 0') . ' ORDER BY l.id';
    $log = rows($logSql, [$r['id']]);
    $total = 0; $done = 0;
    foreach ($items as $list) foreach ($list as $it) { $total++; $done += (int)$it['done']; }

    render_page('Auftrag ' . request_title($r), function () use ($u, $r, $items, $log, $total, $done) { ?>
        <div class="toolbar">
            <?= status_badge($r['status']) ?>
            <span class="muted">Fortschritt: <?= $done ?> von <?= $total ?> erledigt</span>
            <span class="push-right"></span>
            <?php if (request_editable($r)): ?><a class="btn" href="<?= e(url('request_edit', ['id' => $r['id']])) ?>">Bearbeiten</a><?php endif; ?>
            <a class="btn" href="<?= e(url('request_print', ['id' => $r['id']])) ?>" target="_blank" rel="noopener">Druckansicht</a>
        </div>
        <div class="grid2">
            <div class="card">
                <h2>Mitarbeiter/in</h2>
                <dl class="facts">
                    <dt>Firma</dt><dd><?= e($r['company_name']) ?></dd>
                    <dt>Name</dt><dd><?= e(trim($r['salutation'] . ' ' . $r['first_name'] . ' ' . $r['last_name'])) ?></dd>
                    <dt>Mitarbeiternummer</dt><dd><?= e($r['employee_no']) ?></dd>
                    <dt>Abteilung</dt><dd><?= e($r['department_name']) ?></dd>
                    <dt>Position</dt><dd><?= e($r['position_name']) ?></dd>
                    <dt>Eintritt</dt><dd><?= fmt_date($r['entry_date']) ?></dd>
                    <dt>Standort</dt><dd><?= e($r['location'] ?: '–') ?></dd>
                    <dt>Vorgesetzte/r</dt><dd><?= e($r['manager_name'] ?: '–') ?></dd>
                    <dt>E-Mail (Wunsch)</dt><dd><?= e($r['email_wish'] ?: '–') ?></dd>
                    <dt>Telefon</dt><dd><?= e($r['phone'] ?: '–') ?></dd>
                    <dt>Erstellt</dt><dd><?= fmt_dt($r['created_at']) ?> von <?= e($r['creator_name'] ?? '–') ?></dd>
                </dl>
                <?php if ($r['notes']): ?><h3>Hinweise</h3><p class="prewrap"><?= e($r['notes']) ?></p><?php endif; ?>
            </div>
            <div class="card">
                <h2>Checkliste</h2>
                <?php $canCheck = is_admin() && !in_array($r['status'], ['storniert'], true); ?>
                <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="checklist">
                    <?php foreach (ITEM_TYPES as $type => $label): if (!$items[$type]) continue; ?>
                        <h3><?= e($label) ?></h3>
                        <ul class="checklist">
                        <?php foreach ($items[$type] as $it): ?>
                            <li class="<?= (int)$it['done'] ? 'done' : '' ?>">
                                <label><input type="checkbox" name="done[]" value="<?= (int)$it['id'] ?>" <?= (int)$it['done'] ? 'checked' : '' ?> <?= $canCheck ? '' : 'disabled' ?>>
                                    <?= e($it['name']) ?></label>
                                <?php if ($it['note']): ?><span class="muted small"> – <?= e($it['note']) ?></span><?php endif; ?>
                                <?php if ((int)$it['done'] && $it['done_at']): ?><span class="muted small"> (<?= fmt_dt($it['done_at']) ?><?= $it['done_by_name'] ? ', ' . e($it['done_by_name']) : '' ?>)</span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                    <?php if (!$total): ?><p class="muted">Keine Positionen angefordert.</p><?php endif; ?>
                    <?php if ($canCheck && $total): ?><button class="btn">Checkliste speichern</button><?php endif; ?>
                </form>
            </div>
        </div>

        <?php if (is_admin()): ?>
        <div class="card">
            <h2>Status ändern</h2>
            <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>" class="form">
                <?= csrf_field() ?><input type="hidden" name="action" value="status">
                <div class="grid3">
                    <label>Neuer Status
                        <select name="status"><?php foreach (STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                    </label>
                </div>
                <label>Nachricht an die Firma (optional; bei „Rückfrage“ bitte die Frage formulieren)<textarea name="message" rows="3"></textarea></label>
                <p class="muted small">„Eingerichtet“ = technisch vorbereitet · „Freigegeben“ = Zugang ist aktiv und an die Firma übergeben. Zugangsdaten bitte nicht über das Portal versenden.</p>
                <button class="btn primary">Status speichern</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2>Verlauf &amp; Nachrichten</h2>
            <ul class="timeline">
            <?php foreach ($log as $l): ?>
                <li class="<?= (int)$l['internal'] ? 'internal' : '' ?> kind-<?= e($l['kind']) ?>">
                    <div class="meta"><?= fmt_dt($l['created_at']) ?> · <?= e($l['user_name'] ?? 'System') ?>
                        <?= ($l['user_role'] ?? '') === 'admin' ? '<span class="tag">IT-Service</span>' : '' ?>
                        <?= (int)$l['internal'] ? '<span class="tag warn">intern</span>' : '' ?></div>
                    <?php if ($l['kind'] === 'status'): ?>
                        <div>Status: <?= status_badge((string)$l['status_from']) ?> → <?= status_badge((string)$l['status_to']) ?></div>
                    <?php endif; ?>
                    <?php if ($l['message']): ?><div class="prewrap"><?= e($l['message']) ?></div><?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
            <?php if ($r['status'] !== 'storniert'): ?>
            <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>" class="form">
                <?= csrf_field() ?><input type="hidden" name="action" value="comment">
                <label><?= $r['status'] === 'rueckfrage' && !is_admin() ? 'Antwort auf die Rückfrage' : 'Nachricht' ?><textarea name="message" rows="3" required></textarea></label>
                <?php if (is_admin()): ?><label class="check"><input type="checkbox" name="internal" value="1"> Interne Notiz (für die Firma nicht sichtbar)</label><?php endif; ?>
                <button class="btn">Senden</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!is_admin() && in_array($r['status'], ['eingereicht', 'rueckfrage'], true)): ?>
        <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>" class="card form">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
            <h2>Auftrag stornieren</h2>
            <label>Grund (optional)<input name="message" maxlength="500"></label>
            <button class="btn danger" data-confirm="Auftrag wirklich stornieren?">Auftrag stornieren</button>
        </form>
        <?php endif; ?>
    <?php });
}

function page_request_action(): void
{
    $u = require_login();
    require_post_csrf();
    $r = load_request(query_int('id'));
    $id = (int)$r['id'];
    $action = post('action');
    $msg = mb_substr(post('message'), 0, 5000);
    $firmRecipients = fn() => company_recipients((int)$r['company_id'], (int)$r['created_by']);
    $adminRecipients = (array)cfg('mail.admin_recipients', []);

    if ($action === 'checklist') {
        if (!is_admin() || $r['status'] === 'storniert') abort(403);
        $doneIds = array_map('intval', (array)($_POST['done'] ?? []));
        $changed = 0;
        foreach (rows('SELECT id, done FROM request_items WHERE request_id = ?', [$id]) as $it) {
            $want = in_array((int)$it['id'], $doneIds, true) ? 1 : 0;
            if ($want !== (int)$it['done']) {
                update('request_items', (int)$it['id'], ['done' => $want, 'done_by' => $want ? $u['id'] : null, 'done_at' => $want ? now() : null]);
                $changed++;
            }
        }
        if ($changed && $r['status'] === 'eingereicht') {
            update('requests', $id, ['status' => 'in_bearbeitung', 'updated_at' => now()]);
            request_log($id, 'status', '', 'eingereicht', 'in_bearbeitung');
        }
        if ($changed) {
            request_log($id, 'system', "Checkliste aktualisiert ($changed Änderungen)", null, null, true);
            update('requests', $id, ['updated_at' => now()]);
        }
        flash('success', 'Die Checkliste wurde gespeichert.');
    } elseif ($action === 'status') {
        if (!is_admin()) abort(403);
        $new = post('status');
        if (!isset(STATUSES[$new])) abort(400);
        if ($new === 'rueckfrage' && $msg === '') {
            flash('error', 'Bitte formulieren Sie die Rückfrage an die Firma.');
            redirect('request', ['id' => $id]);
        }
        if ($new !== $r['status']) {
            update('requests', $id, ['status' => $new, 'updated_at' => now(),
                'completed_at' => in_array($new, ['freigegeben', 'storniert'], true) ? now() : null]);
            request_log($id, 'status', $msg, $r['status'], $new);
            $text = "Der Status Ihres Onboarding-Auftrags " . request_title($r) . " wurde geändert:\n"
                . STATUSES[$r['status']] . ' → ' . STATUSES[$new] . ($msg ? "\n\nNachricht:\n$msg" : '')
                . "\n\nZum Auftrag: " . request_link($id);
            send_mail($firmRecipients(), 'Onboarding ' . request_title($r) . ': ' . STATUSES[$new], $text);
            flash('success', 'Der Status wurde geändert und die Firma benachrichtigt.');
        } elseif ($msg !== '') {
            request_log($id, 'comment', $msg);
            send_mail($firmRecipients(), 'Nachricht zu Onboarding ' . request_title($r), $msg . "\n\nZum Auftrag: " . request_link($id));
            flash('success', 'Die Nachricht wurde gesendet.');
        }
    } elseif ($action === 'comment') {
        if ($msg === '' || $r['status'] === 'storniert') redirect('request', ['id' => $id]);
        $internal = is_admin() && post('internal') === '1';
        request_log($id, 'comment', $msg, null, null, $internal);
        update('requests', $id, ['updated_at' => now()]);
        if (is_admin()) {
            if (!$internal) send_mail($firmRecipients(), 'Nachricht zu Onboarding ' . request_title($r), $msg . "\n\nZum Auftrag: " . request_link($id));
        } else {
            if ($r['status'] === 'rueckfrage') {
                update('requests', $id, ['status' => 'in_bearbeitung']);
                request_log($id, 'status', 'Rückfrage beantwortet', 'rueckfrage', 'in_bearbeitung');
            }
            send_mail($adminRecipients, 'Antwort der Firma zu Onboarding ' . request_title($r) . ' (' . $r['company_name'] . ')',
                $u['name'] . " schreibt:\n\n" . $msg . "\n\nZum Auftrag: " . request_link($id));
        }
        flash('success', $internal ? 'Die interne Notiz wurde gespeichert.' : 'Die Nachricht wurde gesendet.');
    } elseif ($action === 'cancel') {
        if (is_admin() || !in_array($r['status'], ['eingereicht', 'rueckfrage'], true)) abort(403);
        update('requests', $id, ['status' => 'storniert', 'updated_at' => now(), 'completed_at' => now()]);
        request_log($id, 'status', $msg ?: 'Von der Firma storniert', $r['status'], 'storniert');
        send_mail($adminRecipients, 'Onboarding storniert: ' . request_title($r) . ' (' . $r['company_name'] . ')',
            'Der Auftrag wurde von ' . $u['name'] . ' storniert.' . ($msg ? "\nGrund: $msg" : '') . "\n\n" . request_link($id));
        flash('success', 'Der Auftrag wurde storniert.');
    } else {
        abort(400);
    }
    redirect('request', ['id' => $id]);
}

function page_request_print(): void
{
    require_login();
    $r = load_request(query_int('id'));
    $items = request_items_grouped((int)$r['id']);
    ?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><title>Onboarding <?= e(request_title($r)) ?></title>
<link rel="stylesheet" href="assets/style.css"></head>
<body class="print">
<h1>Onboarding-Auftrag <?= e(request_title($r)) ?></h1>
<p><?= e($r['company_name']) ?> · Status: <?= e(STATUSES[$r['status']] ?? $r['status']) ?> · gedruckt <?= date('d.m.Y H:i') ?></p>
<table class="table compact">
    <tr><th>Name</th><td><?= e(trim($r['salutation'] . ' ' . $r['first_name'] . ' ' . $r['last_name'])) ?></td><th>Pers.-Nr.</th><td><?= e($r['employee_no']) ?></td></tr>
    <tr><th>Abteilung</th><td><?= e($r['department_name']) ?></td><th>Position</th><td><?= e($r['position_name']) ?></td></tr>
    <tr><th>Eintritt</th><td><?= fmt_date($r['entry_date']) ?></td><th>Standort</th><td><?= e($r['location']) ?></td></tr>
    <tr><th>Vorgesetzte/r</th><td><?= e($r['manager_name']) ?></td><th>E-Mail (Wunsch)</th><td><?= e($r['email_wish']) ?></td></tr>
</table>
<?php foreach (ITEM_TYPES as $type => $label): if (!$items[$type]) continue; ?>
    <h2><?= e($label) ?></h2>
    <ul class="checklist print-list">
    <?php foreach ($items[$type] as $it): ?>
        <li><span class="box"><?= (int)$it['done'] ? '☑' : '☐' ?></span> <?= e($it['name']) ?><?= $it['note'] ? ' – <em>' . e($it['note']) . '</em>' : '' ?></li>
    <?php endforeach; ?>
    </ul>
<?php endforeach; ?>
<?php if ($r['notes']): ?><h2>Hinweise</h2><p class="prewrap"><?= e($r['notes']) ?></p><?php endif; ?>
<p class="sign">Eingerichtet von: ______________________ &nbsp; Datum: ____________</p>
<script src="assets/app.js"></script>
</body></html>
<?php
}
