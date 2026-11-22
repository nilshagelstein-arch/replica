<?php
/**
 * Offboarding, Stilllegung (z. B. Elternzeit) und Reaktivierung.
 * Ein gemeinsames Formular; Mitarbeiterdaten und Ausstattung lassen sich aus einem
 * früheren Auftrag übernehmen.
 */

const CHANGE_TYPES = ['offboarding', 'stilllegung', 'reaktivierung'];

/** Aufgaben, die sich aus den Angaben ergeben und in der Checkliste erscheinen */
/**
 * Aufgaben der Checkliste. Wird immer auf Deutsch gebildet (with_lang('de')), weil sync_request_items()
 * Aufgaben über den Namen wiedererkennt; die Anzeige übersetzt sie mit t().
 */
function change_tasks(string $type, array $d): array
{
    $t = [];
    $lock = t(LOCK_OPTIONS[$d['lock_option']] ?? '');
    if ($d['lock_option'] === 'termin' && $d['lock_at']) $lock = date(lang_date_format(true), strtotime(str_replace('T', ' ', $d['lock_at'])));
    $succ = trim($d['successor_name'] . ($d['successor_email'] ? ' (' . $d['successor_email'] . ')' : ''));
    if ($type === 'offboarding') {
        $t[] = t('Benutzerkonto sperren – ') . $lock;
        $t[] = 'Passwort zurücksetzen und angemeldete Sitzungen beenden';
        $t[] = 'MFA-Methoden und mobile Geräte aus der Geräteverwaltung entfernen';
        $t[] = 'Aus Gruppen, Teams und Verteilerlisten entfernen';
        if ($d['mailbox_action']) $t[] = t('Postfach: ') . t(MAILBOX_ACTIONS['offboarding'][$d['mailbox_action']]) . ($succ && $d['mailbox_action'] !== 'sperren' ? ' – ' . $succ : '');
        if ($d['forward_until']) $t[] = t('Weiterleitung befristen bis ') . fmt_date($d['forward_until']);
        if ($d['auto_reply']) $t[] = 'Abwesenheitsnotiz einrichten (Text siehe Auftrag)';
        if ($d['data_action']) $t[] = t('Dateien: ') . t(DATA_ACTIONS[$d['data_action']]) . ($d['data_action'] === 'uebergeben' && $succ ? ' – ' . $succ : '');
        if ($d['phone_action']) $t[] = t('Telefon / Rufnummer: ') . $d['phone_action'];
    } elseif ($type === 'stilllegung') {
        $t[] = t('Benutzerkonto deaktivieren (nicht löschen) – ') . $lock;
        if ($d['mailbox_action']) $t[] = t('Postfach: ') . t(MAILBOX_ACTIONS['stilllegung'][$d['mailbox_action']]) . ($succ && $d['mailbox_action'] !== 'abwesenheit' ? ' – ' . $succ : '');
        $t[] = ($d['auto_reply'] ? 'Abwesenheitsnotiz einrichten (Text siehe Auftrag)' : 'Abwesenheitsnotiz einrichten');
        if ($d['phone_action']) $t[] = t('Telefon / Rufnummer: ') . $d['phone_action'];
        $t[] = 'Zugewiesene Lizenzen und Berechtigungen für die Rückkehr dokumentieren';
        if ($d['return_date']) $t[] = t('Wiedervorlage: Reaktivierung vor Rückkehr am {x} vorbereiten', ['x' => fmt_date($d['return_date'])]);
    } elseif ($type === 'reaktivierung') {
        $t[] = 'Benutzerkonto reaktivieren und Startpasswort setzen';
        $t[] = 'MFA neu einrichten bzw. prüfen';
        $t[] = 'Abwesenheitsnotiz und Weiterleitungen entfernen';
        $t[] = 'Gruppen, Teams und Verteilerlisten wiederherstellen';
    }
    return $t;
}

/** Positionen eines Auftrags mit Katalogauswahl, Freitext und Aufgaben abgleichen (Erledigt-Status bleibt erhalten) */
function sync_request_items(int $id, array $selected, array $itemNotes, array $extras, array $catalogIds, array $tasks): void
{
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
    // Freitext-Positionen und Aufgaben: gleichnamige, bereits erledigte Einträge bleiben erhalten
    $want = [];
    foreach ($extras as $type => $text) {
        foreach (preg_split('/\R/', (string)$text) as $line) {
            $line = mb_substr(trim($line), 0, 190);
            if ($line !== '') $want[] = [$type, $line, 'zusätzlich angegeben'];
        }
    }
    foreach ($tasks as $task) $want[] = ['task', mb_substr($task, 0, 190), ''];
    $existing = rows('SELECT * FROM request_items WHERE request_id = ? AND catalog_item_id IS NULL', [$id]);
    foreach ($existing as $it) {
        $keep = false;
        foreach ($want as $k => [$type, $name]) {
            if ($it['type'] === $type && $it['name'] === $name) { $keep = true; unset($want[$k]); break; }
        }
        if (!$keep) q('DELETE FROM request_items WHERE id = ?', [$it['id']]);
    }
    foreach ($want as [$type, $name, $note]) {
        insert('request_items', ['request_id' => $id, 'catalog_item_id' => null, 'type' => $type, 'name' => $name, 'note' => $note, 'done' => 0]);
    }
}

/** Mitarbeitende aus der Liste, die für die Auftragsart in Frage kommen */
function employees_for_change(int $companyId, string $type): array
{
    employees_ensure_backfill();
    $status = $type === 'reaktivierung' ? ['stillgelegt'] : ($type === 'stilllegung' ? ['aktiv'] : ['aktiv', 'stillgelegt']);
    $in = "'" . implode("','", $status) . "'";
    return rows("SELECT * FROM employees WHERE company_id = ? AND status IN ($in) ORDER BY last_name, first_name", [$companyId]);
}

function page_change_form(): void
{
    $u = require_app('onboarding');
    $id = query_int('id');
    $existing = $id ? load_request($id) : null;
    $type = $existing ? $existing['type'] : (string)($_GET['type'] ?? '');
    if ($type === 'onboarding') redirect('request_new', array_filter(['company' => query_int('company')]));
    if (!in_array($type, CHANGE_TYPES, true)) abort(404);
    if ($existing && !request_editable($existing)) {
        flash('error', t('Dieser Auftrag kann im aktuellen Status nicht mehr geändert werden.'));
        redirect('request', ['id' => $id]);
    }

    $source = null;
    $employee = null;
    if (!$existing && query_int('employee')) {
        employees_ensure_backfill();
        $employee = load_employee(query_int('employee'));
        $source = employee_latest_request($employee);
    } elseif (!$existing && query_int('from')) {
        $source = load_request(query_int('from'));
    } elseif ($existing && $existing['source_request_id']) {
        $source = row('SELECT * FROM requests WHERE id = ?', [$existing['source_request_id']]);
    }

    if ($existing) $cid = (int)$existing['company_id'];
    elseif ($employee) $cid = (int)$employee['company_id'];
    elseif ($source) $cid = (int)$source['company_id'];
    elseif (is_staff_role($u['role'])) $cid = query_int('company') ?: (int)post('company_id', 0);
    else $cid = (int)$u['company_id'];
    $company = $cid && can_access_company($cid) ? row('SELECT * FROM companies WHERE id = ? AND active = 1', [$cid]) : null;
    if ($company && !is_admin() && !company_has_app($cid, 'onboarding')) $company = null;
    $typeLabel = t(REQUEST_TYPES[$type]);

    if (!$company) {
        if (!is_staff_role($u['role'])) abort(403);
        $companies = rows('SELECT id, name FROM companies WHERE active = 1' . company_scope_sql('id') . ' ORDER BY name');
        render_page(t('Neu: {type}', ['type' => $typeLabel]), function () use ($companies, $type) { ?>
            <form method="get" class="card form narrow">
                <input type="hidden" name="r" value="change_new"><input type="hidden" name="type" value="<?= e($type) ?>">
                <label><?= e(t('Für welche Firma?')) ?>
                    <select name="company" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                    <?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= company_has_app((int)$c['id'], 'onboarding') ? '' : t(' (App nicht freigeschaltet)') ?></option><?php endforeach; ?>
                    </select></label>
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

    $fields = ['salutation' => '', 'first_name' => '', 'last_name' => '', 'employee_no' => '', 'department_id' => 0, 'position_id' => 0,
        'entry_date' => '', 'return_date' => '', 'absence_reason' => '', 'location' => '', 'manager_name' => '',
        'lock_option' => 'austritt', 'lock_at' => '', 'mailbox_action' => '', 'successor_name' => '', 'successor_email' => '',
        'forward_until' => '', 'auto_reply' => '', 'data_action' => '', 'phone_action' => '', 'notes' => ''];
    $data = $fields;
    $selected = [];
    $itemNotes = [];
    $extras = array_fill_keys(array_keys(ITEM_TYPES), '');

    // Vorbelegung aus bestehendem Auftrag oder Quellauftrag
    $prefill = $existing ?? $source;
    if ($prefill) {
        foreach ($fields as $f => $_) {
            if (!array_key_exists($f, $prefill)) continue;
            if (!$existing && !in_array($f, ['salutation', 'first_name', 'last_name', 'employee_no', 'department_id', 'position_id', 'location', 'manager_name'], true)) continue;
            $data[$f] = is_int($fields[$f]) ? (int)$prefill[$f] : (string)($prefill[$f] ?? '');
        }
        if (!$existing && $type === 'reaktivierung' && $source && $source['type'] === 'stilllegung') $data['entry_date'] = (string)$source['return_date'];
    }
    // Stammdaten aus der Mitarbeiterliste haben Vorrang (können außerhalb des Portals gepflegt sein)
    if ($employee) {
        foreach (['salutation', 'first_name', 'last_name', 'employee_no', 'location', 'manager_name'] as $f) {
            if ((string)$employee[$f] !== '') $data[$f] = (string)$employee[$f];
        }
        foreach (['department_id', 'position_id'] as $f) if ((int)$employee[$f]) $data[$f] = (int)$employee[$f];
        if ($type === 'offboarding' && $employee['exit_date'] !== '') $data['entry_date'] = $employee['exit_date'];
    }
    if ($prefill) {
        foreach (rows('SELECT * FROM request_items WHERE request_id = ?', [$prefill['id']]) as $it) {
            if ($it['catalog_item_id'] && isset($catalogIds[(int)$it['catalog_item_id']])) {
                $selected[] = (int)$it['catalog_item_id'];
                if ($existing) $itemNotes[(int)$it['catalog_item_id']] = $it['note'];
            } elseif (!$it['catalog_item_id'] && $it['type'] !== 'task' && isset($extras[$it['type']])) {
                $extras[$it['type']] .= ($extras[$it['type']] ? "\n" : '') . $it['name'];
            }
        }
    }
    $errors = [];

    if (is_post()) {
        require_post_csrf();
        foreach ($fields as $f => $_) $data[$f] = is_int($fields[$f]) ? (int)post($f, 0) : mb_substr((string)post($f), 0, in_array($f, ['notes', 'auto_reply'], true) ? 5000 : 190);
        $selected = array_values(array_filter(array_map('intval', (array)($_POST['items'] ?? [])), fn($i) => isset($catalogIds[$i])));
        $itemNotes = [];
        foreach ((array)($_POST['item_note'] ?? []) as $iid => $note) $itemNotes[(int)$iid] = mb_substr(trim((string)$note), 0, 500);
        foreach (ITEM_TYPES as $t => $_) $extras[$t] = mb_substr(trim((string)($_POST['extra'][$t] ?? '')), 0, 2000);

        foreach (['first_name' => t('den Vornamen'), 'last_name' => t('den Nachnamen'), 'employee_no' => t('die Mitarbeiternummer')] as $f => $l) {
            if ($data[$f] === '') $errors[] = t('Bitte {x} angeben.', ['x' => $l]);
        }
        $dep = $data['department_id'] ? row('SELECT * FROM departments WHERE id = ? AND company_id = ?', [$data['department_id'], $cid]) : null;
        $pos = $data['position_id'] ? row('SELECT * FROM positions WHERE id = ? AND company_id = ?', [$data['position_id'], $cid]) : null;
        if (!$dep) $errors[] = t('Bitte eine Abteilung wählen.');
        if (!$pos) $errors[] = t('Bitte eine Position wählen.');
        $validDate = function (string $v): bool { $d = DateTime::createFromFormat('!Y-m-d', $v); return $d && $d->format('Y-m-d') === $v; };
        if (!$validDate($data['entry_date'])) $errors[] = t('Bitte ein gültiges Datum für „') . t(TYPE_DATE_LABELS[$type]) . t('“ angeben.');
        foreach (['return_date' => t('geplante Rückkehr'), 'forward_until' => t('Weiterleitung bis')] as $f => $l) {
            if ($data[$f] !== '' && !$validDate($data[$f])) $errors[] = t('Das Datum „{x}“ ist ungültig.', ['x' => $l]);
        }
        if ($type === 'stilllegung') {
            if (!in_array($data['absence_reason'], ABSENCE_REASONS, true)) $errors[] = t('Bitte den Grund der Abwesenheit wählen.');
            if ($data['return_date'] !== '' && $data['return_date'] <= $data['entry_date']) $errors[] = t('Die geplante Rückkehr muss nach dem Beginn liegen.');
        }
        if (in_array($type, ['offboarding', 'stilllegung'], true)) {
            if (!isset(LOCK_OPTIONS[$data['lock_option']])) $errors[] = t('Bitte angeben, wann das Konto gesperrt werden soll.');
            if ($data['lock_option'] === 'termin' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $data['lock_at'])) $errors[] = t('Bitte Datum und Uhrzeit der Sperrung angeben.');
            if ($data['lock_option'] !== 'termin') $data['lock_at'] = '';
            if (!isset(MAILBOX_ACTIONS[$type][$data['mailbox_action']])) $errors[] = t('Bitte festlegen, was mit dem Postfach passiert.');
            if ($data['successor_email'] !== '' && !filter_var($data['successor_email'], FILTER_VALIDATE_EMAIL)) $errors[] = t('Die E-Mail-Adresse der Nachfolge/Vertretung ist ungültig.');
            $needsSucc = in_array($data['mailbox_action'], ['freigeben', 'weiterleiten', 'zugriff'], true) || $data['data_action'] === 'uebergeben';
            if ($needsSucc && $data['successor_email'] === '') $errors[] = t('Bitte die E-Mail-Adresse der Nachfolge bzw. Vertretung angeben.');
        }
        if ($type === 'offboarding' && !isset(DATA_ACTIONS[$data['data_action']])) $errors[] = t('Bitte festlegen, was mit den persönlichen Dateien passiert.');
        if ($type !== 'stilllegung') { $data['return_date'] = ''; $data['absence_reason'] = ''; }
        if ($type === 'reaktivierung') {
            foreach (['lock_option', 'lock_at', 'mailbox_action', 'successor_name', 'successor_email', 'forward_until', 'auto_reply', 'data_action', 'phone_action'] as $f) $data[$f] = '';
        }
        if ($type !== 'offboarding') $data['data_action'] = '';
        if ($data['employee_no'] !== '') {
            $dup = row("SELECT id FROM requests WHERE company_id = ? AND employee_no = ? AND type = ? AND status NOT IN ('freigegeben','storniert') AND id <> ?",
                [$cid, $data['employee_no'], $type, $id ?: 0]);
            if ($dup) $errors[] = t('Für diese Mitarbeiternummer gibt es bereits eine offene {type} (#{id}).', ['type' => $typeLabel, 'id' => $dup['id']]);
        }

        if (!$errors) {
            $save = $data;
            $save['department_name'] = $dep['name'];
            $save['position_name'] = $pos['name'];
            $save['auto_reply'] = $data['auto_reply'];
            $save['updated_at'] = now();
            db()->beginTransaction();
            if ($existing) {
                update('requests', $id, $save);
            } else {
                $save += ['type' => $type, 'source_request_id' => $source ? (int)$source['id'] : null, 'company_id' => $cid,
                    'created_by' => $u['id'], 'status' => is_admin() ? 'in_bearbeitung' : 'eingereicht', 'created_at' => now()];
                $id = insert('requests', $save);
            }
            sync_request_items($id, $selected, $itemNotes, $extras, $catalogIds, with_lang('de', fn() => change_tasks($type, $data)));
            request_log($id, 'system', $existing ? 'Auftrag geändert' : (is_admin() ? $typeLabel . t(' vom IT-Service angelegt und in Bearbeitung genommen') : $typeLabel . t(' eingereicht')));
            db()->commit();
            employee_sync_request($id);

            $r = load_request($id);
            // Betreff und Text erst beim Versand bilden, damit jeder Empfänger seine Sprache bekommt
            $subject = fn() => ($existing ? t('Geändert: ') : t('Neu: ')) . request_subject($r) . ' (' . $r['company_name'] . ')';
            $body = fn() => t("Firma: {company}\nMitarbeiter/in: {name} (Pers.-Nr. {no})\n", ['company' => $r['company_name'], 'name' => $r['first_name'] . ' ' . $r['last_name'], 'no' => $r['employee_no']])
                . t(TYPE_DATE_LABELS[$type]) . ': ' . fmt_date($r['entry_date'])
                . ($type === 'stilllegung' ? t("\nGrund: {reason}\nGeplante Rückkehr: ", ['reason' => t($r['absence_reason'])]) . ($r['return_date'] ? fmt_date($r['return_date']) : t('offen')) : '')
                . t("\nErstellt/geändert von: {name}\n\nZum Auftrag: ", ['name' => $u['name']]) . request_link($id);
            if (is_staff_role($u['role'])) {
                if (!$existing && post('notify') === '1') send_mail_t(company_recipients($cid, $u['id']), fn() => [$subject(), t("Unser IT-Service hat für Ihre Firma einen Auftrag angelegt und bearbeitet ihn.\n\n") . $body()], $cid);
            } else {
                send_mail_t((array)cfg('mail.admin_recipients', []), fn() => [$subject(), $body()]);
            }
            flash('success', $existing ? t('Der Auftrag wurde aktualisiert.') : (is_admin() ? t('Die {type} wurde angelegt und ist in Bearbeitung.', ['type' => $typeLabel]) : t('Die {type} wurde eingereicht. Sie werden über Statusänderungen informiert.', ['type' => $typeLabel])));
            redirect('request', ['id' => $id]);
        }
    }

    $tpl = template_map($cid);
    $posDept = [];
    foreach ($poss as $p) $posDept[(int)$p['id']] = (int)$p['department_id'];
    $jsData = ['dep' => $tpl['dep'], 'pos' => $tpl['pos'], 'posDept' => $posDept, 'isNew' => !$existing, 'domain' => ''];
    $people = (!$existing && !$source && !$employee) ? employees_for_change($cid, $type) : [];

    $hints = [
        'offboarding'   => t('Markieren Sie alles, was zurückgegeben bzw. entzogen werden muss. Aus einem früheren Auftrag übernommene Einträge sind bereits ausgewählt.'),
        'stilllegung'   => t('Markieren Sie, was während der Abwesenheit deaktiviert bzw. pausiert werden soll. Bei Geräten können Sie in der Bemerkung angeben, ob sie beim Mitarbeitenden verbleiben.'),
        'reaktivierung' => t('Markieren Sie, was zur Rückkehr wieder bereitgestellt werden soll. Aus der Stilllegung übernommene Einträge sind bereits ausgewählt.'),
    ];
    $title = $existing ? t('Bearbeiten: ') . request_subject($existing) : t('Neu: {type}', ['type' => $typeLabel]);
    render_page($title, function () use ($type, $typeLabel, $company, $cid, $data, $errors, $deps, $poss, $catalog, $selected, $itemNotes, $extras, $jsData, $existing, $source, $people, $hints, $employee) {
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>'; ?>
        <p class="muted"><?= e(t('Firma:')) ?> <strong><?= e($company['name']) ?></strong> · <?= e(t(REQUEST_TYPE_HINTS[$type])) ?></p>
        <?php if ($employee && !$existing): ?>
            <div class="alert info"><?= e(t('Daten aus der')) ?> <a href="<?= e(url('employee_edit', ['id' => $employee['id']] + (is_admin() ? ['company' => $cid] : []))) ?>"><?= e(t('Mitarbeiterliste')) ?></a> <?= e(t('übernommen')) ?><?= $source ? (e(t(', Ausstattung aus')) . ' <a href="') . e(url('request', ['id' => $source['id']])) . '">' . e(t(REQUEST_TYPES[$source['type']])) . ' #' . (int)$source['id'] . '</a>' : t(' (kein früherer Auftrag im Portal – bitte Ausstattung unten auswählen)') ?><?= e(t('. Bitte prüfen und ergänzen.')) ?></div>
        <?php elseif ($source && !$existing): ?>
            <div class="alert info"><?= e(t('Daten und Ausstattung wurden aus')) ?> <a href="<?= e(url('request', ['id' => $source['id']])) ?>"><?= e(t(REQUEST_TYPES[$source['type']])) ?> #<?= (int)$source['id'] ?></a> <?= e(t('übernommen. Bitte prüfen und ergänzen.')) ?></div>
        <?php elseif ($people): ?>
            <form method="get" class="card form inline-form">
                <input type="hidden" name="r" value="change_new"><input type="hidden" name="type" value="<?= e($type) ?>">
                <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
                <label class="grow"><?= e(t('Mitarbeiter/in aus der Mitarbeiterliste wählen')) ?>
                    <select name="employee" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                    <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['last_name'] . ', ' . $p['first_name'] . t(' (Pers.-Nr. ') . $p['employee_no'] . ', ' . (t(EMPLOYEE_STATUSES[$p['status']] ?? $p['status'])) . ')') ?></option><?php endforeach; ?>
                    </select></label>
                <button class="btn"><?= e(t('Übernehmen')) ?></button>
            </form>
            <p class="muted small"><?= e(t('Oder füllen Sie die Angaben unten manuell aus. Tipp: Unter')) ?> <a href="<?= e(url('employees', is_admin() ? ['company' => $cid] : [])) ?>"><?= e(t('Mitarbeiter')) ?></a> <?= e(t('können Sie auch Personen ergänzen, die nicht über das Portal eingerichtet wurden.')) ?></p>
        <?php endif; ?>
        <form method="post" class="form request-form" id="request-form"
              action="<?= e($existing ? url('request_edit', ['id' => $existing['id']]) : url('change_new', ['type' => $type] + ($employee ? ['employee' => $employee['id']] : ($source ? ['from' => $source['id']] : [])) + (is_admin() ? ['company' => $cid] : []))) ?>">
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
                    <label><?= e(t('Abteilung *')) ?>
                        <select name="department_id" id="department_id" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                        <?php foreach ($deps as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$data['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                        </select></label>
                    <label><?= e(t('Position *')) ?>
                        <select name="position_id" id="position_id" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                        <?php foreach ($poss as $p): ?><option value="<?= (int)$p['id'] ?>" data-dep="<?= (int)$p['department_id'] ?>" <?= (int)$data['position_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                        </select></label>
                </div>
            </div>

            <div class="card">
                <h2>2. <?= $type === 'offboarding' ? t('Austritt') : ($type === 'stilllegung' ? t('Abwesenheit') : t('Rückkehr')) ?></h2>
                <div class="grid3">
                    <label><?= e(t(TYPE_DATE_LABELS[$type])) ?> *<input type="date" name="entry_date" required value="<?= e($data['entry_date']) ?>"></label>
                    <?php if ($type === 'stilllegung'): ?>
                        <label><?= e(t('Grund *')) ?>
                            <select name="absence_reason" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                            <?php foreach (ABSENCE_REASONS as $reason): ?><option value="<?= e($reason) ?>" <?= $data['absence_reason'] === $reason ? 'selected' : '' ?>><?= e(t($reason)) ?></option><?php endforeach; ?>
                            </select></label>
                        <label><?= e(t('Geplante Rückkehr')) ?><input type="date" name="return_date" value="<?= e($data['return_date']) ?>"></label>
                    <?php endif; ?>
                    <?php if ($type === 'reaktivierung'): ?>
                        <label><?= e(t('Standort / Büro')) ?><input name="location" maxlength="190" value="<?= e($data['location']) ?>"></label>
                        <label><?= e(t('Vorgesetzte/r')) ?><input name="manager_name" maxlength="190" value="<?= e($data['manager_name']) ?>"></label>
                    <?php endif; ?>
                    <?php if ($type !== 'reaktivierung'): ?>
                        <label><?= $type === 'offboarding' ? t('Konto sperren') : t('Konto deaktivieren') ?> *
                            <select name="lock_option" id="lock_option"><?php foreach (LOCK_OPTIONS as $k => $l): ?><option value="<?= e($k) ?>" <?= $data['lock_option'] === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></label>
                        <label data-show-if="lock_option=termin"><?= e(t('Zeitpunkt der Sperrung')) ?><input type="datetime-local" name="lock_at" value="<?= e($data['lock_at']) ?>"></label>
                    <?php endif; ?>
                </div>
                <?php if ($type === 'stilllegung'): ?>
                    <p class="muted small"><?= e(t('Gesundheitsdaten oder Details zum Grund der Abwesenheit gehören nicht in das Portal – die Auswahl oben genügt.')) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($type !== 'reaktivierung'): ?>
            <div class="card">
                <h2><?= e(t('3. Postfach, Dateien und Telefon')) ?></h2>
                <div class="grid3">
                    <label><?= e(t('Postfach *')) ?>
                        <select name="mailbox_action" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                        <?php foreach (MAILBOX_ACTIONS[$type] as $k => $l): ?><option value="<?= e($k) ?>" <?= $data['mailbox_action'] === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?>
                        </select></label>
                    <label><?= $type === 'offboarding' ? t('Nachfolger/in') : t('Vertretung') ?> <?= e(t('(Name)')) ?><input name="successor_name" maxlength="190" value="<?= e($data['successor_name']) ?>"></label>
                    <label><?= $type === 'offboarding' ? t('Nachfolger/in') : t('Vertretung') ?> <?= e(t('(E-Mail)')) ?><input type="email" name="successor_email" maxlength="190" value="<?= e($data['successor_email']) ?>"></label>
                    <?php if ($type === 'offboarding'): ?>
                        <label><?= e(t('Weiterleitung befristen bis')) ?><input type="date" name="forward_until" value="<?= e($data['forward_until']) ?>"></label>
                        <label><?= e(t('Persönliche Dateien *')) ?>
                            <select name="data_action" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                            <?php foreach (DATA_ACTIONS as $k => $l): ?><option value="<?= e($k) ?>" <?= $data['data_action'] === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?>
                            </select></label>
                    <?php endif; ?>
                    <label><?= e(t('Telefon / Rufnummer')) ?><input name="phone_action" maxlength="190" value="<?= e($data['phone_action']) ?>" placeholder="<?= e(t('z. B. Umleitung auf Durchwahl 123')) ?>"></label>
                </div>
                <label><?= e(t('Text der Abwesenheitsnotiz (optional)')) ?><textarea name="auto_reply" rows="3" placeholder="<?= $type === 'offboarding' ? t('z. B. Frau Müller ist nicht mehr im Unternehmen. Bitte wenden Sie sich an …') : t('z. B. Ich bin bis voraussichtlich … nicht erreichbar. Bitte wenden Sie sich an …') ?>"><?= e($data['auto_reply']) ?></textarea></label>
            </div>
            <?php endif; ?>

            <div class="card">
                <h2><?= $type === 'reaktivierung' ? '3' : '4' ?><?= e(t('. Ausstattung, Zugänge und Freigaben')) ?></h2>
                <p class="muted"><?= e($hints[$type]) ?></p>
                <?php foreach (ITEM_TYPES as $itype => $label): ?>
                    <fieldset>
                        <legend><?= e(item_type_label($itype, $type)) ?></legend>
                        <div class="checks">
                        <?php foreach ($catalog[$itype] as $it): $iid = (int)$it['id']; $on = in_array($iid, $selected, true); ?>
                            <div class="check-row">
                                <label class="check"><input type="checkbox" name="items[]" value="<?= $iid ?>" <?= $on ? 'checked' : '' ?>>
                                    <?= e(t_parts($it['name'])) ?><?= $it['description'] ? ' <span class="muted small">' . e($it['description']) . '</span>' : '' ?></label>
                                <input class="item-note" name="item_note[<?= $iid ?>]" value="<?= e($itemNotes[$iid] ?? '') ?>" placeholder="<?= $itype === 'device' && $type === 'stilllegung' ? t('z. B. verbleibt beim Mitarbeitenden') : t('Bemerkung') ?>" maxlength="500">
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$catalog[$itype]): ?><p class="muted small"><?= e(t('Keine Einträge im Katalog.')) ?></p><?php endif; ?>
                        </div>
                        <label class="small"><?= e(t('Weitere (eine pro Zeile)')) ?><textarea name="extra[<?= e($itype) ?>]" rows="2"><?= e($extras[$itype]) ?></textarea></label>
                    </fieldset>
                <?php endforeach; ?>
                <p class="muted small"><?= e(t('Aufgaben wie Konto sperren, Postfach und Abwesenheitsnotiz werden automatisch aus Ihren Angaben in die Checkliste übernommen.')) ?></p>
            </div>

            <div class="card">
                <h2><?= e(t('Hinweise')) ?></h2>
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
                <button class="btn primary"><?= $existing ? t('Änderungen speichern') : e($typeLabel) . ' absenden' ?></button>
                <a class="btn" href="<?= e($existing ? url('request', ['id' => $existing['id']]) : url('dashboard')) ?>"><?= e(t('Abbrechen')) ?></a>
            </div>
        </form>
    <?php });
}
