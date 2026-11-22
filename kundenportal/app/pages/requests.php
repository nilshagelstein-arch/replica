<?php
/**
 * Onboarding-Aufträge: Übersicht, Formular, Detailansicht, Status-Workflow.
 */

/** Lädt einen Auftrag und prüft die Mandantenberechtigung */
function load_request(int $id): array
{
    require_app('onboarding');
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

/**
 * Abgeschlossene Aufträge (freigegeben/storniert) darf der IT-Service nicht mehr direkt ändern.
 * Er kann nur einen Änderungsantrag stellen; gibt die Firma ihn frei, wird der Auftrag wieder geöffnet.
 */
function request_locked(array $r): bool
{
    return in_array($r['status'], ['freigegeben', 'storniert'], true);
}

function request_change_open(array $r): bool
{
    return !empty($r['change_requested_at']);
}

/** Über einen Änderungsantrag entscheiden: Firmen-Admins der Firma und die Person, die den Auftrag eingereicht hat */
function can_decide_change(array $u, array $r): bool
{
    if (is_staff_role((string)$u['role']) || (int)$u['company_id'] !== (int)$r['company_id']) return false;
    return $u['role'] === 'firma_admin' || (int)$u['id'] === (int)$r['created_by'];
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

/** z. B. "Offboarding #12 Erika Müller" für Betreffzeilen */
function request_subject(array $r): string
{
    return (t(REQUEST_TYPES[$r['type'] ?? 'onboarding'] ?? t('Auftrag'))) . ' ' . request_title($r);
}

function date_label(array $r): string
{
    return t(TYPE_DATE_LABELS[$r['type'] ?? 'onboarding'] ?? t('Stichtag'));
}

// ---------------------------------------------------------------------------
// Übersicht
// ---------------------------------------------------------------------------
function page_dashboard(): void
{
    $u = require_app('onboarding');
    $where = [];
    $params = [];
    $companyFilter = 0;
    if (is_staff_role($u['role'])) {
        $companyFilter = query_int('company');
        if ($companyFilter) { $where[] = 'r.company_id = ?'; $params[] = $companyFilter; }
        if (($scope = company_scope_sql('r.company_id')) !== '') $where[] = substr($scope, 5);
    } else {
        $where[] = 'r.company_id = ?';
        $params[] = $u['company_id'];
    }
    $type = (string)($_GET['type'] ?? '');
    if (isset(REQUEST_TYPES[$type])) {
        $where[] = 'r.type = ?';
        $params[] = $type;
    } else {
        $type = '';
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

    render_page(t('Aufträge'), function () use ($u, $list, $counts, $status, $search, $companyFilter, $type) {
        $open = array_sum(array_intersect_key($counts, array_flip(OPEN_STATUSES)));
        $keep = ($companyFilter ? ['company' => $companyFilter] : []) + ($type ? ['type' => $type] : []);
        $keepNoType = $companyFilter ? ['company' => $companyFilter] : []; ?>
        <div class="new-buttons">
            <?php if (!is_admin() || admin_can('onboarding', true)) foreach (REQUEST_TYPES as $t => $label): ?>
                <a class="btn <?= $t === 'onboarding' ? 'primary' : '' ?>" title="<?= e(t(REQUEST_TYPE_HINTS[$t])) ?>"
                   href="<?= e(url($t === 'onboarding' ? 'request_new' : 'change_new', ['type' => $t] + ($companyFilter ? ['company' => $companyFilter] : []))) ?>">+ <?= e(t($label)) ?></a>
            <?php endforeach; ?>
        </div>
        <nav class="type-tabs">
            <a class="<?= $type === '' ? 'active' : '' ?>" href="<?= e(url('dashboard', $keepNoType + ['status' => $status])) ?>"><?= e(t('Alle Arten')) ?></a>
            <?php foreach (REQUEST_TYPES as $t => $label): ?>
                <a class="<?= $type === $t ? 'active' : '' ?>" href="<?= e(url('dashboard', $keepNoType + ['status' => $status, 'type' => $t])) ?>"><?= e(t($label)) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="tiles">
            <a class="tile <?= $status === 'offen' ? 'active' : '' ?>" href="<?= e(url('dashboard', $keep + ['status' => 'offen'])) ?>"><strong><?= $open ?></strong><span><?= e(t('Offen')) ?></span></a>
            <?php foreach (STATUSES as $k => $label): ?>
                <a class="tile st-<?= e($k) ?> <?= $status === $k ? 'active' : '' ?>" href="<?= e(url('dashboard', $keep + ['status' => $k])) ?>"><strong><?= $counts[$k] ?></strong><span><?= e($type && $type !== 'onboarding' ? status_label($k, $type) : t($label)) ?></span></a>
            <?php endforeach; ?>
            <a class="tile <?= $status === 'alle' ? 'active' : '' ?>" href="<?= e(url('dashboard', $keep + ['status' => 'alle'])) ?>"><strong><?= array_sum($counts) ?></strong><span><?= e(t('Alle')) ?></span></a>
        </div>
        <form method="get" class="toolbar">
            <input type="hidden" name="r" value="dashboard"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="hidden" name="type" value="<?= e($type) ?>">
            <?php if (is_staff_role($u['role'])): ?>
                <label><?= e(t('Firma')) ?> <select name="company"><option value="0"><?= e(t('Alle Firmen')) ?></option>
                    <?php foreach (companies_for_select() as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $companyFilter === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                </select></label>
            <?php endif; ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('Name oder Personalnummer')) ?>">
            <button class="btn"><?= e(t('Filtern')) ?></button>
        </form>
        <div class="card">
        <?php if (!$list): ?>
            <p class="muted"><?= e(t('Keine Aufträge gefunden.')) ?></p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th><?= e(t('Nr.')) ?></th><?php if (is_staff_role($u['role'])): ?><th><?= e(t('Firma')) ?></th><?php endif; ?><th><?= e(t('Art')) ?></th><th><?= e(t('Mitarbeiter/in')) ?></th><th><?= e(t('Pers.-Nr.')) ?></th>
                    <th><?= e(t('Abteilung / Position')) ?></th><th><?= e(t('Stichtag')) ?></th><th><?= e(t('Fortschritt')) ?></th><th><?= e(t('Status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($list as $r):
                    $soon = in_array($r['status'], OPEN_STATUSES, true) && strtotime($r['entry_date']) <= strtotime('+7 days');
                    $pct = $r['item_count'] ? round(100 * $r['done_count'] / $r['item_count']) : 0; ?>
                    <tr>
                        <td><a href="<?= e(url('request', ['id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td>
                        <?php if (is_staff_role($u['role'])): ?><td><?= e($r['company_name']) ?></td><?php endif; ?>
                        <td><?= type_badge($r['type']) ?></td>
                        <td><a href="<?= e(url('request', ['id' => $r['id']])) ?>"><strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong></a></td>
                        <td><?= e($r['employee_no']) ?></td>
                        <td><?= e($r['department_name']) ?><br><span class="muted small"><?= e($r['position_name']) ?></span></td>
                        <td class="<?= $soon ? 'soon' : '' ?>"><?= fmt_date($r['entry_date']) ?><?= $soon ? ' ⚠' : '' ?></td>
                        <td><progress max="100" value="<?= $pct ?>" title="<?= e(t('{n} von {m} erledigt', ['n' => (int)$r['done_count'], 'm' => (int)$r['item_count']])) ?>"></progress>
                            <span class="muted small"><?= (int)$r['done_count'] ?>/<?= (int)$r['item_count'] ?></span></td>
                        <td><?= status_badge($r['status'], $r['type']) ?><?= request_change_open($r) ? (' <span class="tag warn">' . e(t('Änderungsantrag')) . '</span>') : '' ?></td>
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
    $u = require_app('onboarding');
    $id = query_int('id');
    $existing = $id ? load_request($id) : null;
    if ($existing && $existing['type'] !== 'onboarding') {
        page_change_form();
        return;
    }
    if ($existing && !request_editable($existing)) {
        flash('error', t('Dieser Auftrag kann im aktuellen Status nicht mehr geändert werden.'));
        redirect('request', ['id' => $id]);
    }

    if ($existing) {
        $cid = (int)$existing['company_id'];
    } elseif (is_staff_role($u['role'])) {
        $cid = query_int('company') ?: (int)post('company_id', 0);
    } else {
        $cid = (int)$u['company_id'];
    }
    $company = $cid && can_access_company($cid) ? row('SELECT * FROM companies WHERE id = ? AND active = 1', [$cid]) : null;
    // Firmen-Benutzer brauchen die App-Freigabe; der IT-Service darf für jede aktive Firma Aufträge anlegen
    if ($company && !is_admin() && !company_has_app($cid, 'onboarding')) $company = null;

    // Admin muss zuerst die Firma wählen
    if (!$company) {
        if (!is_staff_role($u['role'])) abort(403);
        $companies = rows('SELECT id, name FROM companies WHERE active = 1' . company_scope_sql('id') . ' ORDER BY name');
        render_page(t('Neuer Auftrag'), function () use ($companies) { ?>
            <form method="get" class="card form narrow">
                <input type="hidden" name="r" value="request_new">
                <label><?= e(t('Für welche Firma wird der Auftrag angelegt?')) ?>
                    <select name="company" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                    <?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= company_has_app((int)$c['id'], 'onboarding') ? '' : t(' (App nicht freigeschaltet)') ?></option><?php endforeach; ?>
                    </select></label>
                <p class="muted small"><?= e(t('Sie können für jede Firma Aufträge anlegen und selbst durchführen. Ohne App-Freigabe sieht die Firma den Auftrag nicht im Portal.')) ?></p>
                <button class="btn primary"><?= e(t('Weiter')) ?></button>
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

        if ($data['first_name'] === '') $errors[] = t('Bitte den Vornamen angeben.');
        if ($data['last_name'] === '') $errors[] = t('Bitte den Nachnamen angeben.');
        if ($data['employee_no'] === '') $errors[] = t('Bitte die Mitarbeiternummer angeben.');
        $dep = $data['department_id'] ? row('SELECT * FROM departments WHERE id = ? AND company_id = ?', [$data['department_id'], $cid]) : null;
        $pos = $data['position_id'] ? row('SELECT * FROM positions WHERE id = ? AND company_id = ?', [$data['position_id'], $cid]) : null;
        if (!$dep) $errors[] = t('Bitte eine Abteilung wählen.');
        if (!$pos) $errors[] = t('Bitte eine Position wählen.');
        $d = DateTime::createFromFormat('!Y-m-d', $data['entry_date']);
        if (!$d || $d->format('Y-m-d') !== $data['entry_date']) $errors[] = t('Bitte ein gültiges Eintrittsdatum angeben.');
        if ($data['email_wish'] !== '' && !filter_var($data['email_wish'], FILTER_VALIDATE_EMAIL)) $errors[] = t('Die gewünschte E-Mail-Adresse ist ungültig.');
        if ($data['employee_no'] !== '') {
            $dup = row("SELECT id FROM requests WHERE company_id = ? AND employee_no = ? AND type = 'onboarding' AND status <> 'storniert' AND id <> ?",
                [$cid, $data['employee_no'], $id ?: 0]);
            if ($dup) $errors[] = t('Für diese Mitarbeiternummer gibt es bereits ein Onboarding (#') . $dup['id'] . ').';
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
                $save += ['type' => 'onboarding', 'company_id' => $cid, 'created_by' => $u['id'],
                    'status' => is_admin() ? 'in_bearbeitung' : 'eingereicht', 'created_at' => now()];
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
            // feste Texte auf Deutsch speichern, die Anzeige übersetzt sie in die Sprache des Lesers
            request_log($id, 'system', $existing ? 'Auftrag geändert' : (is_admin() ? 'Vom IT-Service angelegt und in Bearbeitung genommen' : 'Auftrag eingereicht'));
            db()->commit();
            employee_sync_request($id);

            $r = load_request($id);
            // Betreff und Text erst beim Versand bilden, damit jeder Empfänger seine Sprache bekommt
            $subject = fn() => ($existing ? t('Onboarding-Auftrag geändert: ') : t('Neuer Onboarding-Auftrag: ')) . request_title($r) . ' (' . $r['company_name'] . ')';
            $body = fn() => t("Firma: {company}\nMitarbeiter/in: {name} (Pers.-Nr. {no})\n", ['company' => $r['company_name'], 'name' => $r['first_name'] . ' ' . $r['last_name'], 'no' => $r['employee_no']])
                . t("Abteilung/Position: {dept} / {pos}\nEintritt: ", ['dept' => $r['department_name'], 'pos' => $r['position_name']]) . fmt_date($r['entry_date'])
                . t("\nErstellt/geändert von: {name}\n\nZum Auftrag: ", ['name' => $u['name']]) . request_link($id);
            if (is_staff_role($u['role'])) {
                if (!$existing && post('notify') === '1') send_mail_t(company_recipients($cid, $u['id']), fn() => [$subject(), t("Unser IT-Service hat für Ihre Firma einen Onboarding-Auftrag angelegt und bearbeitet ihn.\n\n") . $body()], $cid);
            } else {
                send_mail_t((array)cfg('mail.admin_recipients', []), fn() => [$subject(), $body()]);
            }
            flash('success', $existing ? t('Der Auftrag wurde aktualisiert.') : (is_admin() ? t('Der Auftrag wurde angelegt und ist in Bearbeitung. Haken Sie die Checkliste ab und schließen Sie ihn danach ab.') : t('Der Auftrag wurde eingereicht. Sie werden über Statusänderungen informiert.')));
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

    $title = $existing ? t('Auftrag bearbeiten: ') . request_title($existing) : t('Neuer Onboarding-Auftrag');
    render_page($title, function () use ($company, $data, $errors, $deps, $poss, $catalog, $selected, $itemNotes, $extras, $jsData, $existing, $cid) {
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>'; ?>
        <p class="muted"><?= e(t('Firma:')) ?> <strong><?= e($company['name']) ?></strong></p>
        <form method="post" class="form request-form" id="request-form"
              action="<?= e($existing ? url('request_edit', ['id' => $existing['id']]) : url('request_new', is_admin() ? ['company' => $cid] : [])) ?>">
            <?= csrf_field() ?><input type="hidden" name="company_id" value="<?= (int)$cid ?>">
            <script type="application/json" id="template-data"><?= json_encode($jsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
            <div class="card">
                <h2><?= e(t('1. Mitarbeiter/in')) ?></h2>
                <div class="grid3">
                    <label><?= e(t('Anrede')) ?>
                        <select name="salutation"><?php foreach (['', 'Frau', 'Herr', 'Divers'] as $s): ?><option <?= $data['salutation'] === $s ? 'selected' : '' ?> value="<?= e($s) ?>"><?= $s === '' ? '–' : e(t($s)) ?></option><?php endforeach; ?></select>
                    </label>
                    <label><?= e(t('Vorname *')) ?><input name="first_name" required maxlength="120" value="<?= e($data['first_name']) ?>"></label>
                    <label><?= e(t('Nachname *')) ?><input name="last_name" required maxlength="120" value="<?= e($data['last_name']) ?>"></label>
                    <label><?= e(t('Mitarbeiternummer *')) ?><input name="employee_no" required maxlength="60" value="<?= e($data['employee_no']) ?>"></label>
                    <label><?= e(t('Eintrittsdatum *')) ?><input type="date" name="entry_date" required value="<?= e($data['entry_date']) ?>"></label>
                    <label><?= e(t('Standort / Büro')) ?><input name="location" maxlength="190" value="<?= e($data['location']) ?>"></label>
                    <label><?= e(t('Abteilung *')) ?>
                        <select name="department_id" id="department_id" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                        <?php foreach ($deps as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$data['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                        </select></label>
                    <label><?= e(t('Position *')) ?>
                        <select name="position_id" id="position_id" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                        <?php foreach ($poss as $p): ?><option value="<?= (int)$p['id'] ?>" data-dep="<?= (int)$p['department_id'] ?>" <?= (int)$data['position_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                        </select></label>
                    <label><?= e(t('Vorgesetzte/r')) ?><input name="manager_name" maxlength="190" value="<?= e($data['manager_name']) ?>"></label>
                    <label><?= e(t('Gewünschte E-Mail-Adresse')) ?><input type="email" name="email_wish" id="email_wish" maxlength="190" value="<?= e($data['email_wish']) ?>" placeholder="<?= $company['email_domain'] ? 'vorname.nachname@' . e($company['email_domain']) : '' ?>"></label>
                    <label><?= e(t('Telefon / Durchwahl')) ?><input name="phone" maxlength="60" value="<?= e($data['phone']) ?>"></label>
                </div>
                <?php if (!$deps || !$poss): ?>
                    <p class="alert info"><?= e(t('Für diese Firma sind noch keine Abteilungen oder Positionen angelegt.')) ?>
                        <?php if (is_admin() || is_company_admin()): ?><a href="<?= e(url('structure', is_admin() ? ['company' => $cid] : [])) ?>"><?= e(t('Jetzt anlegen')) ?></a><?php else: ?><?= e(t('Bitte wenden Sie sich an Ihren Firmen-Administrator.')) ?><?php endif; ?></p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><?= e(t('2. Ausstattung, Zugänge und Freigaben')) ?></h2>
                <p class="muted" id="template-hint"><?= e(t('Nach Auswahl von Abteilung und Position werden die üblichen Einträge automatisch vorausgewählt. Sie können die Auswahl anpassen.')) ?></p>
                <?php foreach (ITEM_TYPES as $type => $label): ?>
                    <fieldset>
                        <legend><?= e(t($label)) ?></legend>
                        <div class="checks">
                        <?php foreach ($catalog[$type] as $it): $iid = (int)$it['id']; $on = in_array($iid, $selected, true); ?>
                            <div class="check-row">
                                <label class="check"><input type="checkbox" name="items[]" value="<?= $iid ?>" <?= $on ? 'checked' : '' ?>>
                                    <?= e(t_parts($it['name'])) ?><?= $it['description'] ? ' <span class="muted small">' . e($it['description']) . '</span>' : '' ?></label>
                                <input class="item-note" name="item_note[<?= $iid ?>]" value="<?= e($itemNotes[$iid] ?? '') ?>" placeholder="<?= e(t('Bemerkung (z. B. Lesen/Schreiben, Anzahl)')) ?>" maxlength="500">
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$catalog[$type]): ?><p class="muted small"><?= e(t('Keine Einträge im Katalog.')) ?></p><?php endif; ?>
                        </div>
                        <label class="small"><?= e(t('Weitere {x} (eine pro Zeile)', ['x' => item_type_label($type)])) ?>
                            <textarea name="extra[<?= e($type) ?>]" rows="2"><?= e($extras[$type]) ?></textarea></label>
                    </fieldset>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <h2><?= e(t('3. Hinweise')) ?></h2>
                <label><?= e(t('Weitere Informationen für die IT')) ?><textarea name="notes" rows="4"><?= e($data['notes']) ?></textarea></label>
                <p class="muted small"><?= e(t('Bitte keine Passwörter oder Gesundheitsdaten eintragen.')) ?></p>
            </div>
            <?php if (!$existing && is_admin()): $hasApp = company_has_app((int)$cid, 'onboarding'); ?>
            <div class="card staff-box">
                <strong><?= e(t('Durchführung durch den IT-Service')) ?></strong>
                <p class="muted small"><?= e(t('Der Auftrag startet direkt mit „In Bearbeitung“. Sie können ihn anschließend abhaken und abschließen.')) ?></p>
                <label class="check"><input type="checkbox" name="notify" value="1" <?= $hasApp ? 'checked' : '' ?>> <?= e(t('Firma per E-Mail über den neuen Auftrag informieren')) ?></label>
                <?php if (!$hasApp): ?><p class="muted small"><?= e(t('Die Firma hat die App nicht freigeschaltet und sieht den Auftrag nicht im Portal.')) ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="buttons">
                <button class="btn primary"><?= $existing ? t('Änderungen speichern') : t('Auftrag absenden') ?></button>
                <a class="btn" href="<?= e($existing ? url('request', ['id' => $existing['id']]) : url('dashboard')) ?>"><?= e(t('Abbrechen')) ?></a>
            </div>
        </form>
    <?php });
}

// ---------------------------------------------------------------------------
// Detailansicht
// ---------------------------------------------------------------------------
function request_items_grouped(int $id): array
{
    $g = ['task' => []] + array_fill_keys(array_keys(ITEM_TYPES), []);
    foreach (rows('SELECT i.*, u.name AS done_by_name FROM request_items i LEFT JOIN users u ON u.id = i.done_by
        WHERE i.request_id = ? ORDER BY i.id', [$id]) as $it) {
        $g[$it['type']][] = $it;
    }
    return $g;
}

/** Fachliche Angaben eines Auftrags als [Bezeichnung => Wert] */
function request_facts(array $r): array
{
    $t = $r['type'];
    $f = [
        'Firma' => $r['company_name'],
        'Name' => trim(($r['salutation'] ? t($r['salutation']) : '') . ' ' . $r['first_name'] . ' ' . $r['last_name']),
        'Mitarbeiternummer' => $r['employee_no'],
        'Abteilung' => $r['department_name'],
        'Position' => $r['position_name'],
        date_label($r) => fmt_date($r['entry_date']),
    ];
    if ($t === 'stilllegung') {
        $f['Grund'] = $r['absence_reason'] ? t($r['absence_reason']) : '–';
        $f['Geplante Rückkehr'] = $r['return_date'] ? fmt_date($r['return_date']) : t('offen');
    }
    if (in_array($t, ['offboarding', 'stilllegung'], true)) {
        $lock = t(LOCK_OPTIONS[$r['lock_option']] ?? '–');
        if ($r['lock_option'] === 'termin' && $r['lock_at']) $lock .= ': ' . fmt_dt(str_replace('T', ' ', $r['lock_at']));
        $f[$t === 'offboarding' ? t('Konto sperren') : t('Konto deaktivieren')] = $lock;
        $f['Postfach'] = t(MAILBOX_ACTIONS[$t][$r['mailbox_action']] ?? '–');
        $f[$t === 'offboarding' ? t('Nachfolger/in') : t('Vertretung')] = trim($r['successor_name'] . ($r['successor_email'] ? ' <' . $r['successor_email'] . '>' : '')) ?: '–';
        if ($r['forward_until']) $f['Weiterleitung bis'] = fmt_date($r['forward_until']);
        if ($t === 'offboarding') $f['Persönliche Dateien'] = t(DATA_ACTIONS[$r['data_action']] ?? '–');
        $f['Telefon / Rufnummer'] = $r['phone_action'] ?: '–';
    }
    if ($t === 'onboarding') {
        $f['Standort'] = $r['location'] ?: '–';
        $f['Vorgesetzte/r'] = $r['manager_name'] ?: '–';
        $f['E-Mail (Wunsch)'] = $r['email_wish'] ?: '–';
        $f['Telefon'] = $r['phone'] ?: '–';
    }
    if ($t === 'reaktivierung') {
        $f['Standort'] = $r['location'] ?: '–';
        $f['Vorgesetzte/r'] = $r['manager_name'] ?: '–';
    }
    return $f;
}

/** Folgeaufträge, die aus einem Auftrag gestartet werden können */
function followup_types(array $r): array
{
    if ($r['status'] !== 'freigegeben') return [];
    return match ($r['type']) {
        'onboarding', 'reaktivierung' => ['offboarding', 'stilllegung'],
        'stilllegung' => ['reaktivierung', 'offboarding'],
        default => [],
    };
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

    $source = $r['source_request_id'] ? row('SELECT id, type, company_id FROM requests WHERE id = ?', [$r['source_request_id']]) : null;
    if ($source && !can_access_company((int)$source['company_id'])) $source = null;
    $followups = rows('SELECT id, type, status FROM requests WHERE source_request_id = ? ORDER BY id', [$r['id']]);
    render_page(request_subject($r), function () use ($u, $r, $items, $log, $total, $done, $source, $followups) { ?>
        <div class="toolbar">
            <?= type_badge($r['type']) ?>
            <?= status_badge($r['status'], $r['type']) ?>
            <span class="muted"><?= e(t('Fortschritt:')) ?> <?= $done ?> <?= e(t('von')) ?> <?= $total ?> <?= e(t('erledigt')) ?></span>
            <span class="push-right"></span>
            <?php if (request_editable($r)): ?><a class="btn" href="<?= e(url('request_edit', ['id' => $r['id']])) ?>"><?= e(t('Bearbeiten')) ?></a><?php endif; ?>
            <a class="btn" href="<?= e(url('request_print', ['id' => $r['id']])) ?>" target="_blank" rel="noopener"><?= e(t('Druckansicht')) ?></a>
        </div>
        <?php if ($source || $followups || followup_types($r)): ?>
        <div class="card related">
            <?php if ($source): ?><span><?= e(t('Basiert auf:')) ?> <a href="<?= e(url('request', ['id' => $source['id']])) ?>"><?= e(t(REQUEST_TYPES[$source['type']])) ?> #<?= (int)$source['id'] ?></a></span><?php endif; ?>
            <?php foreach ($followups as $fu): ?><span><?= e(t('Folgeauftrag:')) ?> <a href="<?= e(url('request', ['id' => $fu['id']])) ?>"><?= e(t(REQUEST_TYPES[$fu['type']])) ?> #<?= (int)$fu['id'] ?></a> <?= status_badge($fu['status'], $fu['type']) ?></span><?php endforeach; ?>
            <?php foreach (followup_types($r) as $ft): ?>
                <a class="btn" href="<?= e(url('change_new', ['type' => $ft, 'from' => $r['id']])) ?>" title="<?= e(t(REQUEST_TYPE_HINTS[$ft])) ?>"><?= e(t(REQUEST_TYPES[$ft])) ?> <?= e(t('starten')) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="grid2">
            <div class="card">
                <h2><?= e(t('Mitarbeiter/in')) ?></h2>
                <dl class="facts">
                    <?php foreach (request_facts($r) as $label => $value): ?><dt><?= e(t($label)) ?></dt><dd><?= e($value) ?></dd><?php endforeach; ?>
                    <dt><?= e(t('Erstellt')) ?></dt><dd><?= fmt_dt($r['created_at']) ?> <?= e(t('von')) ?> <?= e($r['creator_name'] ?? '–') ?></dd>
                </dl>
                <?php if (!empty($r['auto_reply'])): ?><h3><?= e(t('Abwesenheitsnotiz')) ?></h3><p class="prewrap"><?= e($r['auto_reply']) ?></p><?php endif; ?>
                <?php if ($r['notes']): ?><h3><?= e(t('Hinweise')) ?></h3><p class="prewrap"><?= e($r['notes']) ?></p><?php endif; ?>
            </div>
            <div class="card">
                <h2><?= e(t('Checkliste')) ?></h2>
                <?php $canCheck = is_admin() && !request_locked($r); ?>
                <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="checklist">
                    <?php foreach ($items as $type => $list): if (!$list) continue; ?>
                        <h3><?= e(item_type_label($type, $r['type'])) ?></h3>
                        <ul class="checklist">
                        <?php foreach ($items[$type] as $it): ?>
                            <li class="<?= (int)$it['done'] ? 'done' : '' ?>">
                                <label><input type="checkbox" name="done[]" value="<?= (int)$it['id'] ?>" <?= (int)$it['done'] ? 'checked' : '' ?> <?= $canCheck ? '' : 'disabled' ?>>
                                    <?= e(t_parts($it['name'])) ?></label>
                                <?php if ($it['note']): ?><span class="muted small"> – <?= e(t($it['note'])) ?></span><?php endif; ?>
                                <?php if ((int)$it['done'] && $it['done_at']): ?><span class="muted small"> (<?= fmt_dt($it['done_at']) ?><?= $it['done_by_name'] ? ', ' . e($it['done_by_name']) : '' ?>)</span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                    <?php if (!$total): ?><p class="muted"><?= e(t('Keine Positionen angefordert.')) ?></p><?php endif; ?>
                    <?php if ($canCheck && $total): ?><button class="btn"><?= e(t('Checkliste speichern')) ?></button><?php endif; ?>
                </form>
            </div>
        </div>

        <?php if (request_change_open($r)): ?>
        <div class="card">
            <h2><?= e(t('Änderungsantrag')) ?></h2>
            <p><?= e(t('Der IT-Service möchte diesen abgeschlossenen Auftrag noch einmal ändern')) ?><?= $r['change_requested_at'] ? t(' (beantragt am ') . fmt_dt($r['change_requested_at']) . ')' : '' ?>.</p>
            <p class="prewrap"><strong><?= e(t('Begründung:')) ?></strong> <?= e((string)$r['change_reason']) ?></p>
            <?php if (can_decide_change($u, $r)): ?>
            <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>" class="form">
                <?= csrf_field() ?>
                <label><?= e(t('Nachricht an den IT-Service (optional)')) ?><textarea name="message" rows="2"></textarea></label>
                <p class="muted small"><?= e(t('Freigeben setzt den Auftrag wieder auf „In Bearbeitung“. Der IT-Service kann ihn dann ändern und schließt ihn anschließend erneut ab.')) ?></p>
                <button class="btn primary" name="action" value="change_approve" data-confirm="<?= e(t('Änderung freigeben und den Auftrag wieder öffnen?')) ?>"><?= e(t('Änderung freigeben')) ?></button>
                <button class="btn danger" name="action" value="change_reject" data-confirm="<?= e(t('Änderungsantrag ablehnen? Der Auftrag bleibt abgeschlossen.')) ?>"><?= e(t('Ablehnen')) ?></button>
            </form>
            <?php elseif (is_admin()): ?>
            <p class="muted"><?= e(t('Wartet auf die Freigabe durch einen Firmen-Administrator oder die Person, die den Auftrag eingereicht hat.')) ?></p>
            <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="change_withdraw">
                <button class="btn" data-confirm="<?= e(t('Änderungsantrag zurückziehen?')) ?>"><?= e(t('Antrag zurückziehen')) ?></button>
            </form>
            <?php else: ?>
            <p class="muted"><?= e(t('Freigeben oder ablehnen können die Firmen-Administratoren und die Person, die den Auftrag eingereicht hat.')) ?></p>
            <?php endif; ?>
        </div>
        <?php elseif (is_admin() && request_locked($r)): ?>
        <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>" class="card form">
            <?= csrf_field() ?><input type="hidden" name="action" value="change_request">
            <h2><?= e(t('Änderung beantragen')) ?></h2>
            <p class="muted small"><?= e(t('Der Auftrag ist')) ?> <?= e(status_label($r['status'], $r['type'])) ?> <?= e(t('und kann nicht mehr direkt geändert werden. Gibt die Firma den Antrag frei, wird er wieder auf „In Bearbeitung“ gesetzt.')) ?></p>
            <label><?= e(t('Was soll geändert werden und warum?')) ?><textarea name="message" rows="3" required></textarea></label>
            <button class="btn primary"><?= e(t('Änderungsantrag an die Firma senden')) ?></button>
        </form>
        <?php endif; ?>

        <?php if (is_admin() && !request_locked($r)): ?>
        <div class="card">
            <h2><?= e(t('Status ändern')) ?></h2>
            <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>" class="form">
                <?= csrf_field() ?><input type="hidden" name="action" value="status">
                <div class="grid3">
                    <label><?= e(t('Neuer Status')) ?>
                        <select name="status"><?php foreach (STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= e(status_label($k, $r['type'])) ?></option><?php endforeach; ?></select>
                    </label>
                </div>
                <label><?= e(t('Nachricht an die Firma (optional; bei „Rückfrage“ bitte die Frage formulieren)')) ?><textarea name="message" rows="3"></textarea></label>
                <p class="muted small"><?php if (in_array($r['type'], ['offboarding', 'stilllegung'], true)): ?><?= e(t('„Durchgeführt“ = Zugänge gesperrt bzw. stillgelegt · „Abgeschlossen“ = alle Punkte erledigt, Geräte zurück.')) ?><?php else: ?><?= e(t('„Eingerichtet“ = technisch vorbereitet · „Freigegeben“ = Zugang ist aktiv und an die Firma übergeben. Zugangsdaten bitte nicht über das Portal versenden.')) ?><?php endif; ?></p>
                <button class="btn primary"><?= e(t('Status speichern')) ?></button>
            </form>
            <p class="muted small"><?= e(t('Nach „Freigegeben“ oder „Storniert“ ist der Auftrag abgeschlossen. Spätere Änderungen gehen nur noch über einen Änderungsantrag, den die Firma freigibt.')) ?></p>
            <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>" class="form complete-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="complete_all">
                <label class="check"><input type="checkbox" name="notify" value="1" <?= company_has_app((int)$r['company_id'], 'onboarding') ? 'checked' : '' ?>> <?= e(t('Firma per E-Mail informieren')) ?></label>
                <button class="btn" data-confirm="<?= e(t('Alle Punkte als erledigt markieren und den Auftrag abschließen?')) ?>"><?= e(t('Alles erledigt und abschließen')) ?></button>
            </form>
        </div>
        <?php endif; ?>

        <div class="card">
            <h2><?= e(t('Verlauf & Nachrichten')) ?></h2>
            <ul class="timeline">
            <?php foreach ($log as $l): ?>
                <li class="<?= (int)$l['internal'] ? 'internal' : '' ?> kind-<?= e($l['kind']) ?>">
                    <div class="meta"><?= fmt_dt($l['created_at']) ?> · <?= e($l['user_name'] ?? ($l['user_id'] ? t('Gelöschter Benutzer') : t('System'))) ?>
                        <?= is_staff_role((string)($l['user_role'] ?? '')) ? ('<span class="tag">' . e(t('IT-Service')) . '</span>') : '' ?>
                        <?= (int)$l['internal'] ? ('<span class="tag warn">' . e(t('intern')) . '</span>') : '' ?></div>
                    <?php if ($l['kind'] === 'status'): ?>
                        <div><?= e(t('Status:')) ?> <?= status_badge((string)$l['status_from'], $r['type']) ?> → <?= status_badge((string)$l['status_to'], $r['type']) ?></div>
                    <?php elseif ($l['kind'] === 'change_request'): ?>
                        <div><strong><?= e(t('Änderungsantrag gestellt')) ?></strong></div>
                    <?php endif; ?>
                    <?php if ($l['message']): ?><div class="prewrap"><?= e(t($l['message'])) ?></div><?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
            <?php if ($r['status'] !== 'storniert'): ?>
            <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>" class="form">
                <?= csrf_field() ?><input type="hidden" name="action" value="comment">
                <label><?= $r['status'] === 'rueckfrage' && !is_admin() ? t('Antwort auf die Rückfrage') : t('Nachricht') ?><textarea name="message" rows="3" required></textarea></label>
                <?php if (is_admin()): ?><label class="check"><input type="checkbox" name="internal" value="1"> <?= e(t('Interne Notiz (für die Firma nicht sichtbar)')) ?></label><?php endif; ?>
                <button class="btn"><?= e(t('Senden')) ?></button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!is_admin() && in_array($r['status'], ['eingereicht', 'rueckfrage'], true)): ?>
        <form method="post" action="<?= e(url('request_action', ['id' => $r['id']])) ?>" class="card form">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
            <h2><?= e(t('Auftrag stornieren')) ?></h2>
            <label><?= e(t('Grund (optional)')) ?><input name="message" maxlength="500"></label>
            <button class="btn danger" data-confirm="<?= e(t('Auftrag wirklich stornieren?')) ?>"><?= e(t('Auftrag stornieren')) ?></button>
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
        if (!is_admin() || request_locked($r)) abort(403);
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
            request_log($id, 'system', t('Checkliste aktualisiert ({n} Änderungen)', ['n' => $changed]), null, null, true);
            update('requests', $id, ['updated_at' => now()]);
        }
        flash('success', t('Die Checkliste wurde gespeichert.'));
    } elseif ($action === 'status') {
        if (!is_admin() || request_locked($r)) abort(403);
        $new = post('status');
        if (!isset(STATUSES[$new])) abort(400);
        if ($new === 'rueckfrage' && $msg === '') {
            flash('error', t('Bitte formulieren Sie die Rückfrage an die Firma.'));
            redirect('request', ['id' => $id]);
        }
        if ($new !== $r['status']) {
            update('requests', $id, ['status' => $new, 'updated_at' => now(),
                'completed_at' => in_array($new, ['freigegeben', 'storniert'], true) ? now() : null]);
            request_log($id, 'status', $msg, $r['status'], $new);
            $text = fn() => t('Der Status Ihres Auftrags ') . request_subject($r) . t(" wurde geändert:\n")
                . status_label($r['status'], $r['type']) . ' → ' . status_label($new, $r['type']) . ($msg ? t("\n\nNachricht:\n") . $msg : '')
                . t("\n\nZum Auftrag: ") . request_link($id);
            send_mail_t($firmRecipients(), fn() => [request_subject($r) . ': ' . status_label($new, $r['type']), $text()], (int)$r['company_id']);
            flash('success', t('Der Status wurde geändert und die Firma benachrichtigt.'));
        } elseif ($msg !== '') {
            request_log($id, 'comment', $msg);
            send_mail_t($firmRecipients(), fn() => [t('Nachricht zu ') . request_subject($r), $msg . t("\n\nZum Auftrag: ") . request_link($id)], (int)$r['company_id']);
            flash('success', t('Die Nachricht wurde gesendet.'));
        }
    } elseif ($action === 'complete_all') {
        if (!is_admin() || in_array($r['status'], ['freigegeben', 'storniert'], true)) abort(403);
        $open = rows('SELECT id FROM request_items WHERE request_id = ? AND done = 0', [$id]);
        foreach ($open as $it) update('request_items', (int)$it['id'], ['done' => 1, 'done_by' => $u['id'], 'done_at' => now()]);
        update('requests', $id, ['status' => 'freigegeben', 'updated_at' => now(), 'completed_at' => now()]);
        request_log($id, 'status', t('Alle Punkte erledigt (') . count($open) . t(' abgehakt)'), $r['status'], 'freigegeben');
        if (post('notify') === '1') {
            send_mail_t($firmRecipients(), fn() => [request_subject($r) . ': ' . status_label('freigegeben', $r['type']), t('Ihr Auftrag ') . request_subject($r) . t(" wurde vollständig erledigt.\n\nZum Auftrag: ") . request_link($id)], (int)$r['company_id']);
        }
        flash('success', t('Alle Punkte sind erledigt, der Auftrag ist abgeschlossen.'));
    } elseif ($action === 'comment') {
        if ($msg === '' || $r['status'] === 'storniert') redirect('request', ['id' => $id]);
        $internal = is_admin() && post('internal') === '1';
        request_log($id, 'comment', $msg, null, null, $internal);
        update('requests', $id, ['updated_at' => now()]);
        if (is_admin()) {
            if (!$internal) send_mail_t($firmRecipients(), fn() => [t('Nachricht zu ') . request_subject($r), $msg . t("\n\nZum Auftrag: ") . request_link($id)], (int)$r['company_id']);
        } else {
            if ($r['status'] === 'rueckfrage') {
                update('requests', $id, ['status' => 'in_bearbeitung']);
                request_log($id, 'status', 'Rückfrage beantwortet', 'rueckfrage', 'in_bearbeitung');
            }
            send_mail_t($adminRecipients, fn() => [t('Antwort der Firma zu ') . request_subject($r) . ' (' . $r['company_name'] . ')', $u['name'] . t(" schreibt:\n\n") . $msg . t("\n\nZum Auftrag: ") . request_link($id)]);
        }
        flash('success', $internal ? t('Die interne Notiz wurde gespeichert.') : t('Die Nachricht wurde gesendet.'));
    } elseif ($action === 'cancel') {
        if (is_admin() || !in_array($r['status'], ['eingereicht', 'rueckfrage'], true)) abort(403);
        update('requests', $id, ['status' => 'storniert', 'updated_at' => now(), 'completed_at' => now()]);
        request_log($id, 'status', $msg ?: 'Von der Firma storniert', $r['status'], 'storniert');
        send_mail_t($adminRecipients, fn() => [t('Storniert: ') . request_subject($r) . ' (' . $r['company_name'] . ')', t('Der Auftrag wurde von ') . $u['name'] . t(' storniert.') . ($msg ? t("\nGrund: ") . $msg : '') . "\n\n" . request_link($id)]);
        flash('success', t('Der Auftrag wurde storniert.'));
    } elseif ($action === 'change_request') {
        // IT-Service beantragt, einen abgeschlossenen Auftrag wieder zu öffnen
        if (!is_admin() || !request_locked($r) || request_change_open($r)) abort(403);
        if ($msg === '') {
            flash('error', t('Bitte begründen Sie, was am Auftrag geändert werden soll.'));
            redirect('request', ['id' => $id]);
        }
        update('requests', $id, ['change_requested_at' => now(), 'change_requested_by' => $u['id'],
            'change_reason' => $msg, 'updated_at' => now()]);
        request_log($id, 'change_request', $msg);
        send_mail_t($firmRecipients(), fn() => [t('Änderungsantrag zu ') . request_subject($r), t('Der IT-Service möchte den abgeschlossenen Auftrag ') . request_subject($r) . t(" noch einmal ändern.\n\n")
            . t("Begründung:\n") . $msg . "\n\n"
            . t("Bitte geben Sie den Antrag im Portal frei oder lehnen Sie ihn ab (Firmen-Administratoren oder die Person, die den Auftrag eingereicht hat).\n\n")
            . t('Zum Auftrag: ') . request_link($id)], (int)$r['company_id']);
        flash('success', t('Der Änderungsantrag wurde an die Firma geschickt.'));
    } elseif ($action === 'change_withdraw') {
        if (!is_admin() || !request_change_open($r)) abort(403);
        update('requests', $id, ['change_requested_at' => null, 'change_requested_by' => null, 'change_reason' => null, 'updated_at' => now()]);
        request_log($id, 'change_withdrawn', 'Änderungsantrag zurückgezogen');
        flash('success', t('Der Änderungsantrag wurde zurückgezogen.'));
    } elseif ($action === 'change_approve' || $action === 'change_reject') {
        if (!request_change_open($r) || !can_decide_change($u, $r)) abort(403);
        $approve = $action === 'change_approve';
        $requester = $r['change_requested_by'] ? row('SELECT email FROM users WHERE id = ? AND active = 1', [$r['change_requested_by']]) : null;
        $notify = array_merge($adminRecipients, $requester ? [$requester['email']] : []);
        $clear = ['change_requested_at' => null, 'change_requested_by' => null, 'change_reason' => null, 'updated_at' => now()];
        if ($approve) {
            update('requests', $id, $clear + ['status' => 'in_bearbeitung', 'completed_at' => null]);
            request_log($id, 'status', t('Änderungsantrag freigegeben') . ($msg ? ": $msg" : ''), $r['status'], 'in_bearbeitung');
        } else {
            update('requests', $id, $clear);
            request_log($id, 'change_rejected', t('Änderungsantrag abgelehnt') . ($msg ? ": $msg" : ''));
        }
        send_mail_t($notify, fn() => [($approve ? t('Änderung freigegeben: ') : t('Änderung abgelehnt: ')) . request_subject($r) . ' (' . $r['company_name'] . ')', $u['name'] . ($approve ? t(' hat den Änderungsantrag freigegeben. Der Auftrag ist wieder in Bearbeitung.')
                                  : t(' hat den Änderungsantrag abgelehnt. Der Auftrag bleibt abgeschlossen.'))
            . ($msg ? t("\n\nNachricht:\n") . $msg : '') . t("\n\nZum Auftrag: ") . request_link($id)]);
        flash('success', $approve ? t('Der Auftrag ist wieder in Bearbeitung.') : t('Der Änderungsantrag wurde abgelehnt.'));
    } else {
        abort(400);
    }
    employee_sync_request($id);
    redirect('request', ['id' => $id]);
}

function page_request_print(): void
{
    require_login();
    $r = load_request(query_int('id'));
    $items = request_items_grouped((int)$r['id']);
    $facts = request_facts($r);
    ?><!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>"><head><meta charset="utf-8"><title><?= e(request_subject($r)) ?></title>
<link rel="stylesheet" href="assets/style.css"></head>
<body class="print">
<h1><?= e(request_subject($r)) ?></h1>
<p><?= e($r['company_name']) ?> <?= e(t('· Status:')) ?> <?= e(status_label($r['status'], $r['type'])) ?> <?= e(t('· gedruckt')) ?> <?= date(lang_date_format(true)) ?></p>
<table class="table compact">
<?php foreach (array_chunk($facts, 2, true) as $pair): ?>
    <tr><?php foreach ($pair as $label => $value): ?><th><?= e(t($label)) ?></th><td><?= e($value) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</table>
<?php if (!empty($r['auto_reply'])): ?><h2><?= e(t('Abwesenheitsnotiz')) ?></h2><p class="prewrap"><?= e($r['auto_reply']) ?></p><?php endif; ?>
<?php foreach ($items as $type => $list): if (!$list) continue; ?>
    <h2><?= e(item_type_label($type, $r['type'])) ?></h2>
    <ul class="checklist print-list">
    <?php foreach ($list as $it): ?>
        <li><span class="box"><?= (int)$it['done'] ? '☑' : '☐' ?></span> <?= e(t_parts($it['name'])) ?><?= $it['note'] ? ' – <em>' . e(t($it['note'])) . '</em>' : '' ?></li>
    <?php endforeach; ?>
    </ul>
<?php endforeach; ?>
<?php if ($r['notes']): ?><h2><?= e(t('Hinweise')) ?></h2><p class="prewrap"><?= e($r['notes']) ?></p><?php endif; ?>
<p class="sign"><?= e(t('Erledigt von: ______________________   Datum: ____________')) ?></p>
<script src="index.php?r=i18n_js&amp;l=<?= e(current_lang()) ?>"></script>
<script src="assets/app.js"></script>
</body></html>
<?php
}
