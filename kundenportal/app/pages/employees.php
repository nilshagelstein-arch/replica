<?php
/**
 * On- & Offboarding → Mitarbeiter: Liste je Firma, Bearbeiten, CSV-Import/-Export,
 * Absprung in Offboarding, Stilllegung und Reaktivierung mit vorbelegten Daten.
 * Ansehen und Aufträge starten: alle mit der App. Pflegen und importieren: Firmen-Admins und Dienstleister.
 */

function employees_company(): array
{
    $u = require_app('onboarding');
    $cid = context_company_id();
    if (!$cid) {
        flash('info', 'Es ist noch keine Firma angelegt.');
        redirect('home');
    }
    require_company_access($cid);
    $company = row('SELECT * FROM companies WHERE id = ?', [$cid]);
    if (!$company) abort(404);
    return [$u, $cid, $company];
}

function can_manage_employees(array $u): bool
{
    return in_array($u['role'], ['superadmin', 'admin', 'firma_admin'], true);
}

function employee_date_in(string $v): string
{
    $v = trim($v);
    if ($v === '') return '';
    foreach (['!Y-m-d', '!d.m.Y', '!d.m.y', '!j.n.Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d && $d->format(ltrim($fmt, '!')) === $v) return $d->format('Y-m-d');
    }
    return '';
}

function page_employees(): void
{
    [$u, $cid, $company] = employees_company();
    employees_ensure_backfill();
    $manage = can_manage_employees($u);
    $params = is_admin() ? ['company' => $cid] : [];

    if (is_post()) {
        require_post_csrf();
        if (!$manage) abort(403);
        if (post('action') === 'import') {
            employees_import($cid, $params);
        }
        abort(400);
    }

    $status = (string)($_GET['status'] ?? 'aktuell');
    $search = trim((string)($_GET['q'] ?? ''));
    $where = ['company_id = ?'];
    $p = [$cid];
    if ($status === 'aktuell') $where[] = "status IN ('geplant','aktiv','stillgelegt')";
    elseif (isset(EMPLOYEE_STATUSES[$status])) { $where[] = 'status = ?'; $p[] = $status; }
    else $status = 'alle';
    if ($search !== '') {
        $where[] = '(first_name LIKE ? OR last_name LIKE ? OR employee_no LIKE ? OR email LIKE ? OR department_name LIKE ?)';
        array_push($p, "%$search%", "%$search%", "%$search%", "%$search%", "%$search%");
    }
    $list = rows('SELECT * FROM employees WHERE ' . implode(' AND ', $where) . ' ORDER BY last_name, first_name', $p);

    if (($_GET['export'] ?? '') === 'csv') {
        if (!$manage) abort(403); // Export nur für Firmen-Admins und den IT-Service
        audit('employees.export', count($list) . ' Mitarbeitende', $cid);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mitarbeiter-' . preg_replace('/[^a-z0-9]+/i', '-', $company['name']) . '-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Personalnummer', 'Anrede', 'Vorname', 'Nachname', 'Abteilung', 'Position', 'E-Mail', 'Telefon', 'Standort', 'Vorgesetzte/r', 'Eintritt', 'Austritt', 'Status', 'Notiz'], ';', '"', '');
        foreach ($list as $e) {
            fputcsv($out, array_map('csv_cell_safe', [$e['employee_no'], $e['salutation'], $e['first_name'], $e['last_name'], $e['department_name'], $e['position_name'], $e['email'],
                $e['phone'], $e['location'], $e['manager_name'], $e['entry_date'] ? date('d.m.Y', strtotime($e['entry_date'])) : '', $e['exit_date'] ? date('d.m.Y', strtotime($e['exit_date'])) : '',
                EMPLOYEE_STATUSES[$e['status']] ?? $e['status'], $e['notes']]), ';', '"', ''); // Schutz vor Formeln in Excel
        }
        fclose($out);
        exit;
    }

    $counts = array_fill_keys(array_keys(EMPLOYEE_STATUSES), 0);
    foreach (rows('SELECT status, COUNT(*) AS n FROM employees WHERE company_id = ? GROUP BY status', [$cid]) as $c) $counts[$c['status']] = (int)$c['n'];
    $open = employee_open_requests($cid);

    render_page('Mitarbeiter', function () use ($u, $cid, $company, $manage, $params, $list, $status, $search, $counts, $open) {
        if (is_admin()): ?><div class="toolbar"><?= company_switcher($cid, 'employees', ['status' => $status]) ?></div><?php endif; ?>
        <p class="muted">Mitarbeitende von <strong><?= e($company['name']) ?></strong>. Die Liste wird mit jedem abgeschlossenen Auftrag automatisch aktualisiert.
            Über die Knöpfe starten Sie Offboarding, Stilllegung oder Reaktivierung mit allen Daten und der bisherigen Ausstattung.</p>
        <div class="tiles">
            <a class="tile <?= $status === 'aktuell' ? 'active' : '' ?>" href="<?= e(url('employees', $params + ['status' => 'aktuell'])) ?>"><strong><?= $counts['geplant'] + $counts['aktiv'] + $counts['stillgelegt'] ?></strong><span>Aktuell</span></a>
            <?php foreach (EMPLOYEE_STATUSES as $k => $label): ?>
                <a class="tile <?= $status === $k ? 'active' : '' ?>" href="<?= e(url('employees', $params + ['status' => $k])) ?>"><strong><?= $counts[$k] ?></strong><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
            <a class="tile <?= $status === 'alle' ? 'active' : '' ?>" href="<?= e(url('employees', $params + ['status' => 'alle'])) ?>"><strong><?= array_sum($counts) ?></strong><span>Alle</span></a>
        </div>
        <form method="get" class="toolbar">
            <input type="hidden" name="r" value="employees"><input type="hidden" name="status" value="<?= e($status) ?>">
            <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, Personalnummer, E-Mail, Abteilung">
            <button class="btn">Suchen</button>
            <span class="grow"></span>
            <?php if ($manage): ?><a class="btn primary" href="<?= e(url('employee_edit', $params)) ?>">+ Mitarbeiter/in</a><?php endif; ?>
            <?php if ($manage): ?><a class="btn" href="<?= e(url('employees', $params + ['status' => $status, 'q' => $search, 'export' => 'csv'])) ?>">CSV exportieren</a><?php endif; ?>
        </form>
        <div class="card">
        <?php if (!$list): ?>
            <p class="muted">Keine Mitarbeitenden gefunden.<?= $manage ? ' Legen Sie welche an oder importieren Sie eine CSV-Datei (unten).' : '' ?></p>
        <?php else: ?>
            <table class="table employees">
                <thead><tr><th>Name</th><th>Pers.-Nr.</th><th>Abteilung / Position</th><th>E-Mail</th><th>Eintritt / Austritt</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $e): ?>
                    <tr>
                        <td><a href="<?= e(url('employee_edit', $params + ['id' => $e['id']])) ?>"><strong><?= e($e['last_name'] . ', ' . $e['first_name']) ?></strong></a></td>
                        <td><?= e($e['employee_no']) ?></td>
                        <td><?= e($e['department_name']) ?><br><span class="muted small"><?= e($e['position_name']) ?></span></td>
                        <td><?= $e['email'] !== '' ? '<a href="mailto:' . e($e['email']) . '">' . e($e['email']) . '</a>' : '<span class="muted">–</span>' ?></td>
                        <td class="nowrap"><?= $e['entry_date'] ? fmt_date($e['entry_date']) : '–' ?><?= $e['exit_date'] ? '<br><span class="muted small">bis ' . fmt_date($e['exit_date']) . '</span>' : '' ?></td>
                        <td><?= employee_status_badge($e['status']) ?>
                            <?php foreach ($open[$e['employee_no']] ?? [] as $or): ?><br><a class="small" href="<?= e(url('request', ['id' => $or['id']])) ?>"><?= e(REQUEST_TYPES[$or['type']]) ?> #<?= (int)$or['id'] ?> offen</a><?php endforeach; ?></td>
                        <td class="actions nowrap">
                            <?php if (empty($open[$e['employee_no']])): foreach (employee_actions($e) as $t): ?>
                                <a class="btn small" href="<?= e(url('change_new', ['type' => $t, 'employee' => $e['id']] + $params)) ?>"><?= e(REQUEST_TYPES[$t]) ?></a>
                            <?php endforeach; endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
        <?php if ($manage): ?>
        <details class="card">
            <summary><strong>CSV importieren</strong> – bestehende Mitarbeitende einmalig übernehmen</summary>
            <form method="post" enctype="multipart/form-data" class="form" action="<?= e(url('employees', $params)) ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="company_id" value="<?= (int)$cid ?>">
                <p class="muted small">Erste Zeile mit Spaltennamen, Trennzeichen Semikolon oder Komma (z. B. aus Excel „CSV UTF-8“).
                    Pflicht: <strong>Personalnummer, Vorname, Nachname</strong>. Optional: Anrede, Abteilung, Position, E-Mail, Telefon, Standort, Vorgesetzte/r,
                    Eintritt, Austritt (TT.MM.JJJJ), Status (Aktiv, Stillgelegt, Ausgeschieden, Geplant), Notiz.
                    Vorhandene Personalnummern werden aktualisiert. Tipp: Exportieren Sie die Liste als Vorlage.</p>
                <label>CSV-Datei<input type="file" name="csv" accept=".csv,text/csv" required></label>
                <button class="btn primary">Importieren</button>
            </form>
        </details>
        <?php endif; ?>
    <?php });
}

function employees_import(int $cid, array $params): never
{
    $f = $_FILES['csv'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 5 * 1048576) {
        flash('error', 'Bitte eine CSV-Datei (max. 5 MB) auswählen.');
        redirect('employees', $params);
    }
    $rowsIn = csv_read((string)file_get_contents($f['tmp_name']));
    $head = array_shift($rowsIn) ?? [];
    $aliases = [
        'employee_no' => ['personalnummer', 'persnr', 'personalnr', 'mitarbeiternummer', 'mitarbeiternr', 'nummer', 'nr', 'employeeno', 'employeeid', 'id'],
        'salutation' => ['anrede', 'salutation'], 'first_name' => ['vorname', 'firstname'], 'last_name' => ['nachname', 'name', 'lastname', 'familienname'],
        'department_name' => ['abteilung', 'department', 'bereich'], 'position_name' => ['position', 'stelle', 'funktion', 'jobtitle', 'titel'],
        'email' => ['email', 'emailadresse', 'mail'], 'phone' => ['telefon', 'tel', 'phone', 'durchwahl', 'mobil'], 'location' => ['standort', 'buero', 'location'],
        'manager_name' => ['vorgesetzte', 'vorgesetzter', 'vorgesetzter', 'manager', 'fuehrungskraft'], 'entry_date' => ['eintritt', 'eintrittsdatum', 'startdate'],
        'exit_date' => ['austritt', 'austrittsdatum', 'enddate'], 'status' => ['status'], 'notes' => ['notiz', 'notizen', 'bemerkung', 'notes'],
    ];
    $map = [];
    foreach ($head as $i => $h) {
        $n = csv_norm($h);
        foreach ($aliases as $field => $names) {
            if (in_array($n, $names, true) && !in_array($field, $map, true)) { $map[$i] = $field; break; }
        }
    }
    foreach (['employee_no', 'first_name', 'last_name'] as $need) {
        if (!in_array($need, $map, true)) {
            flash('error', 'Die CSV-Datei braucht die Spalten Personalnummer, Vorname und Nachname. Gefunden: ' . implode(', ', $head));
            redirect('employees', $params);
        }
    }
    $deps = [];
    foreach (departments_of($cid, false) as $d) $deps[mb_strtolower($d['name'])] = (int)$d['id'];
    $poss = [];
    foreach (positions_of($cid, false) as $p) $poss[mb_strtolower($p['name'])] = (int)$p['id'];
    $statusMap = ['aktiv' => 'aktiv', 'active' => 'aktiv', 'stillgelegt' => 'stillgelegt', 'ruhend' => 'stillgelegt', 'ausgeschieden' => 'ausgeschieden',
        'inaktiv' => 'ausgeschieden', 'geplant' => 'geplant', 'eintrittgeplant' => 'geplant'];
    $new = $upd = $skip = 0;
    db()->beginTransaction();
    foreach ($rowsIn as $line) {
        $v = array_fill_keys(array_keys($aliases), '');
        foreach ($map as $i => $field) $v[$field] = mb_substr(trim((string)($line[$i] ?? '')), 0, $field === 'notes' ? 2000 : 190);
        if ($v['employee_no'] === '' || $v['first_name'] === '' || $v['last_name'] === '') { $skip++; continue; }
        $save = ['salutation' => $v['salutation'], 'first_name' => $v['first_name'], 'last_name' => $v['last_name'],
            'department_name' => $v['department_name'], 'position_name' => $v['position_name'],
            'department_id' => $deps[mb_strtolower($v['department_name'])] ?? null, 'position_id' => $poss[mb_strtolower($v['position_name'])] ?? null,
            'email' => filter_var($v['email'], FILTER_VALIDATE_EMAIL) ? $v['email'] : '', 'phone' => $v['phone'], 'location' => $v['location'],
            'manager_name' => $v['manager_name'], 'entry_date' => employee_date_in($v['entry_date']), 'exit_date' => employee_date_in($v['exit_date']),
            'status' => $statusMap[csv_norm($v['status'])] ?? ($v['exit_date'] !== '' ? 'ausgeschieden' : 'aktiv'), 'notes' => $v['notes'], 'updated_at' => now()];
        $e = employee_find($cid, $v['employee_no']);
        if ($e) {
            // leere Zellen überschreiben nichts
            foreach ($save as $k => $val) if (($val === '' || $val === null) && !in_array($k, ['updated_at'], true)) unset($save[$k]);
            if (trim($v['status']) === '' && $v['exit_date'] === '') unset($save['status']);
            update('employees', (int)$e['id'], $save);
            $upd++;
        } else {
            insert('employees', $save + ['company_id' => $cid, 'employee_no' => $v['employee_no'], 'last_request_id' => null, 'created_at' => now()]);
            $new++;
        }
    }
    db()->commit();
    audit('employees.import', "$new neu, $upd aktualisiert, $skip übersprungen", $cid);
    flash('success', "Import abgeschlossen: $new neu, $upd aktualisiert" . ($skip ? ", $skip Zeilen ohne Personalnummer/Name übersprungen" : '') . '.');
    redirect('employees', $params + ['status' => 'alle']);
}

function page_employee_edit(): void
{
    $id = query_int('id');
    if ($id) {
        $u = require_app('onboarding');
        $e = load_employee($id);
        $cid = (int)$e['company_id'];
        $company = row('SELECT * FROM companies WHERE id = ?', [$cid]);
    } else {
        [$u, $cid, $company] = employees_company();
        $e = null;
    }
    $manage = can_manage_employees($u);
    if (!$e && !$manage) abort(403);
    $params = is_admin() ? ['company' => $cid] : [];
    $data = array_fill_keys(EMPLOYEE_FIELDS, '');
    $data['status'] = 'aktiv';
    if ($e) foreach (EMPLOYEE_FIELDS as $f) $data[$f] = (string)($e[$f] ?? '');
    $errors = [];

    if (is_post()) {
        require_post_csrf();
        if (!$manage) abort(403);
        if ($e && post('action') === 'delete') {
            q('DELETE FROM employees WHERE id = ?', [$e['id']]);
            audit('employees.delete', $e['employee_no'] . ' ' . $e['first_name'] . ' ' . $e['last_name'], $cid);
            flash('success', 'Der Eintrag wurde aus der Mitarbeiterliste gelöscht. Bisherige Aufträge bleiben erhalten.');
            redirect('employees', $params);
        }
        foreach (EMPLOYEE_FIELDS as $f) $data[$f] = mb_substr(trim((string)post($f)), 0, $f === 'notes' ? 2000 : 190);
        foreach (['entry_date', 'exit_date'] as $f) if ($data[$f] !== '' && employee_date_in($data[$f]) === '') $errors[] = 'Bitte ein gültiges Datum angeben.';
        if ($data['first_name'] === '' || $data['last_name'] === '') $errors[] = 'Bitte Vor- und Nachnamen angeben.';
        if ($data['employee_no'] === '') $errors[] = 'Bitte die Personalnummer angeben.';
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Die E-Mail-Adresse ist ungültig.';
        if (!isset(EMPLOYEE_STATUSES[$data['status']])) $errors[] = 'Bitte einen Status wählen.';
        $dup = $data['employee_no'] !== '' ? employee_find($cid, $data['employee_no']) : null;
        if ($dup && (!$e || (int)$dup['id'] !== (int)$e['id'])) $errors[] = 'Diese Personalnummer ist bereits vergeben (' . $dup['first_name'] . ' ' . $dup['last_name'] . ').';
        $dep = (int)$data['department_id'] ? row('SELECT * FROM departments WHERE id = ? AND company_id = ?', [(int)$data['department_id'], $cid]) : null;
        $pos = (int)$data['position_id'] ? row('SELECT * FROM positions WHERE id = ? AND company_id = ?', [(int)$data['position_id'], $cid]) : null;
        if (!$errors) {
            $save = $data;
            $save['department_id'] = $dep ? (int)$dep['id'] : null;
            $save['position_id'] = $pos ? (int)$pos['id'] : null;
            $save['department_name'] = $dep ? $dep['name'] : ($e && !(int)$data['department_id'] ? $e['department_name'] : '');
            $save['position_name'] = $pos ? $pos['name'] : ($e && !(int)$data['position_id'] ? $e['position_name'] : '');
            $save['updated_at'] = now();
            if ($e) {
                update('employees', (int)$e['id'], $save);
                audit('employees.update', $data['employee_no'], $cid);
            } else {
                $id = insert('employees', $save + ['company_id' => $cid, 'last_request_id' => null, 'created_at' => now()]);
                audit('employees.create', $data['employee_no'], $cid);
            }
            flash('success', 'Gespeichert.');
            redirect('employee_edit', $params + ['id' => $id]);
        }
    }

    $deps = departments_of($cid);
    $poss = positions_of($cid);
    $history = $e ? rows('SELECT id, type, status, entry_date, created_at FROM requests WHERE company_id = ? AND employee_no = ? ORDER BY id DESC', [$cid, $e['employee_no']]) : [];
    $hasOpen = (bool)array_filter($history, fn($h) => in_array($h['status'], OPEN_STATUSES, true));
    $title = $e ? $e['first_name'] . ' ' . $e['last_name'] : 'Neue/r Mitarbeiter/in';

    render_page($title, function () use ($e, $data, $errors, $deps, $poss, $history, $hasOpen, $manage, $params, $company) {
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>'; ?>
        <p class="muted"><a href="<?= e(url('employees', $params)) ?>">← Mitarbeiterliste</a> · Firma: <strong><?= e($company['name']) ?></strong></p>
        <?php if ($e): ?>
        <div class="card">
            <p><?= employee_status_badge($e['status']) ?> Personalnummer <strong><?= e($e['employee_no']) ?></strong></p>
            <?php if ($hasOpen): ?>
                <p class="muted small">Es läuft bereits ein Auftrag für diese Person (siehe Verlauf).</p>
            <?php elseif (employee_actions($e)): ?>
                <div class="buttons">
                <?php foreach (employee_actions($e) as $t): ?>
                    <a class="btn primary" href="<?= e(url('change_new', ['type' => $t, 'employee' => $e['id']] + $params)) ?>"><?= e(REQUEST_TYPES[$t]) ?> starten</a>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <form method="post" class="card form">
            <?= csrf_field() ?>
            <h2>Stammdaten</h2>
            <fieldset <?= $manage ? '' : 'disabled' ?>>
            <div class="grid3">
                <label>Anrede<select name="salutation"><?php foreach (['', 'Frau', 'Herr', 'Divers'] as $s): ?><option <?= $data['salutation'] === $s ? 'selected' : '' ?> value="<?= e($s) ?>"><?= $s === '' ? '–' : e($s) ?></option><?php endforeach; ?></select></label>
                <label>Vorname *<input name="first_name" required maxlength="120" value="<?= e($data['first_name']) ?>"></label>
                <label>Nachname *<input name="last_name" required maxlength="120" value="<?= e($data['last_name']) ?>"></label>
                <label>Personalnummer *<input name="employee_no" required maxlength="60" value="<?= e($data['employee_no']) ?>"></label>
                <label>Abteilung<select name="department_id"><option value="">–</option>
                    <?php foreach ($deps as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$data['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                    </select><?php if (!(int)$data['department_id'] && $data['department_name'] !== ''): ?><span class="muted small">bisher: <?= e($data['department_name']) ?></span><?php endif; ?></label>
                <label>Position<select name="position_id"><option value="">–</option>
                    <?php foreach ($poss as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$data['position_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                    </select><?php if (!(int)$data['position_id'] && $data['position_name'] !== ''): ?><span class="muted small">bisher: <?= e($data['position_name']) ?></span><?php endif; ?></label>
                <label>E-Mail<input type="email" name="email" maxlength="190" value="<?= e($data['email']) ?>"></label>
                <label>Telefon<input name="phone" maxlength="60" value="<?= e($data['phone']) ?>"></label>
                <label>Standort / Büro<input name="location" maxlength="190" value="<?= e($data['location']) ?>"></label>
                <label>Vorgesetzte/r<input name="manager_name" maxlength="190" value="<?= e($data['manager_name']) ?>"></label>
                <label>Eintritt<input type="date" name="entry_date" value="<?= e($data['entry_date']) ?>"></label>
                <label>Austritt<input type="date" name="exit_date" value="<?= e($data['exit_date']) ?>"></label>
                <label>Status<select name="status"><?php foreach (EMPLOYEE_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $data['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <label>Notiz<textarea name="notes" rows="2"><?= e($data['notes']) ?></textarea></label>
            <?php if ($manage): ?><div class="buttons"><button class="btn primary">Speichern</button></div><?php endif; ?>
            </fieldset>
            <?php if ($e): ?><p class="muted small">Der Status wird mit jedem abgeschlossenen Auftrag automatisch gesetzt. Von Hand ändern Sie ihn nur für Fälle außerhalb des Portals.</p><?php endif; ?>
        </form>
        <?php if ($e): ?>
        <div class="card">
            <h2>Verlauf</h2>
            <?php if (!$history): ?><p class="muted">Noch keine Aufträge im Portal.</p><?php else: ?>
            <table class="table compact"><thead><tr><th>Nr.</th><th>Art</th><th>Stichtag</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($history as $h): ?>
                <tr><td><a href="<?= e(url('request', ['id' => $h['id']])) ?>">#<?= (int)$h['id'] ?></a></td><td><?= type_badge($h['type']) ?></td>
                    <td><?= fmt_date($h['entry_date']) ?></td><td><?= status_badge($h['status'], $h['type']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table><?php endif; ?>
        </div>
        <?php if ($manage): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <button class="btn danger small" data-confirm="Diesen Eintrag aus der Mitarbeiterliste löschen? Aufträge bleiben erhalten.">Aus Liste löschen</button></form>
        <?php endif; endif; ?>
    <?php });
}
