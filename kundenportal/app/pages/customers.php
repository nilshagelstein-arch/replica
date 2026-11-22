<?php
/**
 * App „Formulare“: Kundendatenbank je Firma mit eigenen Feldern, CSV-Import und -Export.
 */

function load_customer(int $cid, int $id): array
{
    $c = row('SELECT * FROM customers WHERE id = ? AND company_id = ?', [$id, $cid]);
    if (!$c) abort(404, 'Der Kunde wurde nicht gefunden.');
    return $c;
}

function page_customers(): void
{
    $cid = forms_company_id();
    $search = trim((string)($_GET['q'] ?? ''));
    $page = max(1, query_int('seite'));
    $per = 50;
    $where = 'company_id = ?';
    $params = [$cid];
    if ($search !== '') {
        $where .= ' AND (customer_no LIKE ? OR company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR city LIKE ? OR email LIKE ? OR extra LIKE ?)';
        $params = array_merge($params, array_fill(0, 7, '%' . $search . '%'));
    }
    $total = (int)row("SELECT COUNT(*) AS n FROM customers WHERE $where", $params)['n'];
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $list = rows("SELECT * FROM customers WHERE $where ORDER BY company_name, last_name, first_name LIMIT $per OFFSET " . (($page - 1) * $per), $params);

    render_page('Kundendatenbank', function () use ($cid, $search, $list, $total, $page, $pages) { ?>
        <?= forms_subtitle($cid) ?>
        <div class="toolbar">
            <form method="get" class="toolbar">
                <input type="hidden" name="r" value="customers">
                <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, Nummer, Ort, E-Mail …">
                <button class="btn small">Suchen</button>
                <?php if ($search !== ''): ?><a href="<?= e(furl('customers')) ?>">Suche aufheben</a><?php endif; ?>
            </form>
            <span class="push-right buttons">
                <?php if (forms_can_manage()): ?>
                    <a class="btn" href="<?= e(furl('customer_import')) ?>">CSV-Import</a>
                    <a class="btn" href="<?= e(furl('customer_export', $search !== '' ? ['q' => $search] : [])) ?>">CSV-Export</a>
                    <a class="btn" href="<?= e(furl('customer_fields')) ?>">Felder</a>
                <?php endif; ?>
                <a class="btn primary" href="<?= e(furl('customer_edit')) ?>">+ Neuer Kunde</a>
            </span>
        </div>
        <div class="card">
            <p class="muted small"><?= $total ?> Kunde(n)<?= $search !== '' ? ' gefunden' : '' ?></p>
            <?php if ($list): ?>
            <table class="table">
                <thead><tr><th>Nr.</th><th>Firma</th><th>Name</th><th>Ort</th><th>E-Mail</th><th>Telefon</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $c): ?>
                    <tr>
                        <td><?= e($c['customer_no']) ?></td>
                        <td><?= e($c['company_name']) ?></td>
                        <td><a href="<?= e(furl('customer_edit', ['id' => $c['id']])) ?>"><?= e(trim($c['salutation'] . ' ' . $c['title'] . ' ' . $c['first_name'] . ' ' . $c['last_name']) ?: '–') ?></a></td>
                        <td><?= e(trim($c['zip'] . ' ' . $c['city'])) ?></td>
                        <td><?= $c['email'] !== '' ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '' ?></td>
                        <td><?= e($c['phone']) ?></td>
                        <td class="actions"><a href="<?= e(furl('customer_edit', ['id' => $c['id']])) ?>">Bearbeiten</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($pages > 1): ?>
                <p class="pager">
                    <?php for ($p = 1; $p <= $pages; $p++): if ($pages > 12 && abs($p - $page) > 3 && $p !== 1 && $p !== $pages) { if ($p === 2 || $p === $pages - 1) echo ' … '; continue; } ?>
                        <?php if ($p === $page): ?><strong><?= $p ?></strong><?php else: ?><a href="<?= e(furl('customers', ['seite' => $p] + ($search !== '' ? ['q' => $search] : []))) ?>"><?= $p ?></a><?php endif; ?>
                    <?php endfor; ?>
                </p>
            <?php endif; ?>
            <?php elseif ($search === ''): ?>
                <p>Noch keine Kunden erfasst. Legen Sie Kunden einzeln an<?= forms_can_manage() ? ', importieren Sie eine CSV-Datei oder binden Sie ein anderes Programm über die API an' : '' ?>.</p>
            <?php endif; ?>
        </div>
    <?php });
}

function page_customer_edit(): void
{
    $cid = forms_company_id();
    $id = query_int('id');
    $c = $id ? load_customer($cid, $id) : null;
    $custom = customer_custom_fields($cid);
    $values = $c ? customer_export_row($c, $cid) : array_fill_keys(array_keys(customer_all_fields($cid)), '');
    $errors = [];

    if (is_post()) {
        require_post_csrf();
        if (post('action') === 'delete' && $c) {
            q('DELETE FROM customers WHERE id = ?', [$c['id']]);
            audit('customer_deleted', customer_label($c), $cid);
            flash('success', 'Der Kunde wurde gelöscht.');
            fredirect('customers');
        }
        foreach (array_keys(customer_all_fields($cid)) as $k) $values[$k] = post('f_' . $k);
        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Die E-Mail-Adresse ist ungültig.';
        if ($values['firma'] === '' && $values['nachname'] === '') $errors[] = 'Bitte mindestens Firma oder Nachname angeben.';
        if ($values['nummer'] !== '') {
            $dup = row('SELECT id FROM customers WHERE company_id = ? AND customer_no = ? AND id <> ?', [$cid, $values['nummer'], $c['id'] ?? 0]);
            if ($dup) $errors[] = 'Die Kundennummer ist bereits vergeben.';
        }
        if (!$errors) {
            $newId = customer_save($cid, $values, $c);
            audit($c ? 'customer_updated' : 'customer_created', trim($values['nummer'] . ' ' . $values['firma'] . ' ' . $values['nachname']), $cid);
            flash('success', 'Der Kunde wurde gespeichert.');
            if (post('next') === 'new') fredirect('customer_edit');
            fredirect('customer_edit', ['id' => $newId]);
        }
    }

    render_page($c ? 'Kunde bearbeiten' : 'Neuer Kunde', function () use ($cid, $c, $custom, $values, $errors) {
        $std = CUSTOMER_STD_FIELDS;
        $input = function (string $k, string $label, string $type = 'text') use ($values) {
            echo '<label>' . e($label) . '<input type="' . $type . '" name="f_' . e($k) . '" value="' . e($values[$k] ?? '') . '" maxlength="190"></label>';
        }; ?>
        <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <div class="card">
                <div class="grid3">
                    <?php $input('nummer', $std['nummer'][0]); $input('firma', $std['firma'][0]); ?>
                    <label>Anrede<select name="f_anrede">
                        <?php foreach (SALUTATIONS as $s): ?><option value="<?= e($s) ?>" <?= ($values['anrede'] ?? '') === $s ? 'selected' : '' ?>><?= $s === '' ? '–' : e($s) ?></option><?php endforeach; ?>
                        <?php if (!in_array($values['anrede'] ?? '', SALUTATIONS, true)): ?><option selected><?= e($values['anrede']) ?></option><?php endif; ?>
                    </select></label>
                    <?php $input('titel', $std['titel'][0]); $input('vorname', $std['vorname'][0]); $input('nachname', $std['nachname'][0]);
                    $input('strasse', $std['strasse'][0]); $input('plz', $std['plz'][0]); $input('ort', $std['ort'][0]);
                    $input('land', $std['land'][0]); $input('email', $std['email'][0], 'email'); $input('telefon', $std['telefon'][0]); ?>
                </div>
                <?php if ($custom): ?>
                    <h3>Weitere Felder</h3>
                    <div class="grid3"><?php foreach ($custom as $f) $input($f['field_key'], $f['label']); ?></div>
                <?php endif; ?>
                <label class="mt">Notiz<textarea name="f_notiz" rows="3" maxlength="5000"><?= e($values['notiz'] ?? '') ?></textarea></label>
            </div>
            <div class="buttons">
                <button class="btn primary">Speichern</button>
                <?php if (!$c): ?><button class="btn" name="next" value="new">Speichern und nächsten anlegen</button><?php endif; ?>
                <a class="btn" href="<?= e(furl('customers')) ?>">Zur Liste</a>
                <?php if ($c): ?><span class="muted small">angelegt <?= fmt_dt($c['created_at']) ?> · geändert <?= fmt_dt($c['updated_at']) ?></span><?php endif; ?>
            </div>
        </form>
        <?php if ($c): ?>
        <form method="post" class="danger-inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <button class="btn small danger" data-confirm="Kunden „<?= e(customer_label($c)) ?>“ endgültig löschen?">Kunden löschen</button></form>
        <?php endif; ?>
    <?php });
}

function page_customer_fields(): void
{
    $cid = forms_company_id();
    forms_require_manage();
    if (is_post()) {
        require_post_csrf();
        $action = post('action');
        if ($action === 'add') {
            $label = mb_substr(post('label'), 0, 120);
            $key = field_key_from_label(post('field_key') ?: $label);
            if ($label === '' || $key === '') {
                flash('error', 'Bitte eine Bezeichnung angeben.');
            } elseif (isset(customer_all_fields($cid)[$key]) || in_array($key, ['name', 'anschrift', 'briefanrede', 'id'], true)) {
                flash('error', "Der Feldname „{$key}“ ist schon vergeben.");
            } else {
                $sort = (int)(row('SELECT MAX(sort) AS m FROM customer_fields WHERE company_id = ?', [$cid])['m'] ?? 0) + 10;
                insert('customer_fields', ['company_id' => $cid, 'field_key' => $key, 'label' => $label, 'sort' => $sort]);
                audit('customer_field_added', $label, $cid);
                flash('success', "Das Feld „{$label}“ wurde angelegt. Platzhalter: {{kunde.{$key}}}");
            }
        } elseif ($action === 'save') {
            foreach ((array)($_POST['label'] ?? []) as $fid => $label) {
                $label = mb_substr(trim((string)$label), 0, 120);
                if ($label !== '') q('UPDATE customer_fields SET label = ?, sort = ? WHERE id = ? AND company_id = ?',
                    [$label, (int)($_POST['sort'][$fid] ?? 0), (int)$fid, $cid]);
            }
            flash('success', 'Die Felder wurden gespeichert.');
        } elseif ($action === 'delete') {
            $f = row('SELECT * FROM customer_fields WHERE id = ? AND company_id = ?', [(int)post('id', 0), $cid]);
            if (!$f) abort(404);
            q('DELETE FROM customer_fields WHERE id = ?', [$f['id']]);
            audit('customer_field_deleted', $f['label'], $cid);
            flash('success', 'Das Feld wurde entfernt. Gespeicherte Werte bleiben erhalten und erscheinen wieder, wenn Sie das Feld mit demselben Namen neu anlegen.');
        }
        fredirect('customer_fields');
    }
    $custom = customer_custom_fields($cid);
    render_page('Felder der Kundendatenbank', function () use ($cid, $custom) { ?>
        <?= forms_subtitle($cid) ?>
        <div class="grid2">
            <div class="card">
                <h2>Standardfelder</h2>
                <table class="table compact"><thead><tr><th>Bezeichnung</th><th>Platzhalter</th></tr></thead><tbody>
                    <?php foreach (CUSTOMER_STD_FIELDS as $k => [$label]): ?><tr><td><?= e($label) ?></td><td><code>{{kunde.<?= e($k) ?>}}</code></td></tr><?php endforeach; ?>
                    <tr><td>Name (Vorname Nachname)</td><td><code>{{kunde.name}}</code></td></tr>
                    <tr><td>Anschrift, mehrzeilig</td><td><code>{{kunde.anschrift}}</code></td></tr>
                    <tr><td>Briefanrede</td><td><code>{{kunde.briefanrede}}</code></td></tr>
                </tbody></table>
            </div>
            <div class="card">
                <h2>Eigene Felder</h2>
                <?php if ($custom): ?>
                <form method="post" id="fields-form"><?= csrf_field() ?><input type="hidden" name="action" value="save"></form>
                <table class="table compact"><thead><tr><th>Bezeichnung</th><th>Platzhalter</th><th class="num">Sort.</th><th></th></tr></thead><tbody>
                    <?php foreach ($custom as $f): ?>
                        <tr><td><input form="fields-form" name="label[<?= (int)$f['id'] ?>]" value="<?= e($f['label']) ?>" maxlength="120"></td>
                            <td><code>{{kunde.<?= e($f['field_key']) ?>}}</code></td>
                            <td class="num"><input form="fields-form" type="number" class="tiny" name="sort[<?= (int)$f['id'] ?>]" value="<?= (int)$f['sort'] ?>"></td>
                            <td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button class="btn small danger" data-confirm="Feld „<?= e($f['label']) ?>“ entfernen?">Entfernen</button></form></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
                <button class="btn" form="fields-form">Änderungen speichern</button>
                <?php else: ?><p class="muted">Noch keine eigenen Felder.</p><?php endif; ?>
                <h3>Feld hinzufügen</h3>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="add">
                    <input name="label" placeholder="Bezeichnung, z. B. Vertragsnummer" required maxlength="120">
                    <input name="field_key" placeholder="Feldname (optional), z. B. vertragsnr" maxlength="50" pattern="[A-Za-z0-9_]*">
                    <button class="btn primary">Hinzufügen</button></form>
            </div>
        </div>
    <?php });
}

function page_customer_export(): void
{
    $cid = forms_company_id();
    forms_require_manage();
    $fields = customer_all_fields($cid);
    $search = trim((string)($_GET['q'] ?? ''));
    $where = 'company_id = ?';
    $params = [$cid];
    if ($search !== '') {
        $where .= ' AND (customer_no LIKE ? OR company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR city LIKE ? OR email LIKE ? OR extra LIKE ?)';
        $params = array_merge($params, array_fill(0, 7, '%' . $search . '%'));
    }
    $company = row('SELECT name FROM companies WHERE id = ?', [$cid]);
    $file = 'kunden-' . field_key_from_label((string)($company['name'] ?? 'firma')) . '-' . date('Y-m-d') . '.csv';
    audit('customers_exported', $search, $cid);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_values($fields), ';', '"', '');
    if (isset($_GET['vorlage'])) { fclose($out); exit; }
    $st = q("SELECT * FROM customers WHERE $where ORDER BY company_name, last_name, first_name", $params);
    while ($c = $st->fetch()) {
        $r = customer_export_row($c, $cid);
        fputcsv($out, array_map('csv_cell_safe', array_map(fn($k) => $r[$k] ?? '', array_keys($fields))), ';', '"', '');
    }
    fclose($out);
    exit;
}

function page_customer_import(): void
{
    $cid = forms_company_id();
    forms_require_manage();
    $result = null;
    if (is_post()) {
        require_post_csrf();
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Bitte eine CSV-Datei auswählen.');
            fredirect('customer_import');
        }
        if ($f['size'] > 10 * 1024 * 1024) {
            flash('error', 'Die Datei ist zu groß (höchstens 10 MB).');
            fredirect('customer_import');
        }
        $data = csv_read((string)file_get_contents($f['tmp_name']));
        if (count($data) < 2) {
            flash('error', 'Die Datei enthält keine Datenzeilen. Die erste Zeile muss die Spaltenüberschriften enthalten.');
            fredirect('customer_import');
        }
        $headers = array_shift($data);
        $mode = post('mode') === 'add' ? 'add' : 'upsert';
        if (post('create_fields') === '1') {
            $mapped = csv_map_headers($headers, $cid);
            $sort = (int)(row('SELECT MAX(sort) AS m FROM customer_fields WHERE company_id = ?', [$cid])['m'] ?? 0);
            foreach ($headers as $i => $h) {
                $h = trim($h);
                $key = field_key_from_label($h);
                if (isset($mapped[$i]) || $h === '' || $key === '' || isset(customer_all_fields($cid)[$key])) continue;
                $sort += 10;
                insert('customer_fields', ['company_id' => $cid, 'field_key' => $key, 'label' => mb_substr($h, 0, 120), 'sort' => $sort]);
                customer_custom_fields($cid, true);
            }
        }
        $map = csv_map_headers($headers, $cid);
        if (!$map) {
            flash('error', 'Keine Spalte konnte einem Feld zugeordnet werden. Laden Sie die Vorlage herunter, um die erwarteten Überschriften zu sehen.');
            fredirect('customer_import');
        }
        $created = $updated = $skipped = 0;
        $problems = [];
        $hasNo = in_array('nummer', $map, true);
        db()->beginTransaction();
        try {
            foreach ($data as $lineNo => $r) {
                $vals = [];
                foreach ($map as $i => $k) $vals[$k] = (string)($r[$i] ?? '');
                if (implode('', array_map('trim', $vals)) === '') continue;
                $existing = null;
                if ($hasNo && trim($vals['nummer']) !== '') {
                    $existing = row('SELECT * FROM customers WHERE company_id = ? AND customer_no = ?', [$cid, trim($vals['nummer'])]);
                }
                if ($existing && $mode === 'add') { $skipped++; continue; }
                if (!$existing && trim(($vals['firma'] ?? '') . ($vals['nachname'] ?? '') . ($vals['vorname'] ?? '')) === '') {
                    $skipped++;
                    if (count($problems) < 10) $problems[] = 'Zeile ' . ($lineNo + 2) . ': kein Name und keine Firma';
                    continue;
                }
                customer_save($cid, $vals, $existing);
                $existing ? $updated++ : $created++;
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            throw $ex;
        }
        $unmapped = array_values(array_filter(array_map(fn($i) => isset($map[$i]) ? null : trim($headers[$i]), array_keys($headers)), fn($x) => $x !== null && $x !== ''));
        audit('customers_imported', "neu $created, aktualisiert $updated, übersprungen $skipped", $cid);
        $result = compact('created', 'updated', 'skipped', 'problems', 'unmapped', 'map');
        $result['labels'] = customer_all_fields($cid);
        $result['headers'] = $headers;
    }

    render_page('Kunden importieren', function () use ($cid, $result) { ?>
        <?= forms_subtitle($cid) ?>
        <?php if ($result): ?>
            <div class="alert success"><p><strong>Import abgeschlossen:</strong> <?= $result['created'] ?> neu angelegt, <?= $result['updated'] ?> aktualisiert, <?= $result['skipped'] ?> übersprungen.</p>
                <?php foreach ($result['problems'] as $p): ?><p class="small"><?= e($p) ?></p><?php endforeach; ?></div>
            <div class="card">
                <h2>Zuordnung der Spalten</h2>
                <table class="table compact"><thead><tr><th>Spalte in der Datei</th><th>Feld</th></tr></thead><tbody>
                    <?php foreach ($result['headers'] as $i => $h): ?>
                        <tr><td><?= e($h) ?></td><td><?= isset($result['map'][$i]) ? e($result['labels'][$result['map'][$i]] ?? $result['map'][$i]) : '<span class="muted">nicht übernommen</span>' ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
                <p><a class="btn primary" href="<?= e(furl('customers')) ?>">Zur Kundendatenbank</a></p>
            </div>
        <?php endif; ?>
        <div class="grid2">
            <div class="card">
                <h2>CSV-Datei hochladen</h2>
                <form method="post" enctype="multipart/form-data" class="form">
                    <?= csrf_field() ?>
                    <label>CSV-Datei (Excel: „Speichern unter → CSV UTF-8“)<input type="file" name="csv" accept=".csv,text/csv,text/plain" required></label>
                    <label class="check"><input type="radio" name="mode" value="upsert" checked> Neue Kunden anlegen und vorhandene (gleiche Kundennummer) aktualisieren</label>
                    <label class="check"><input type="radio" name="mode" value="add"> Nur neue Kunden anlegen, vorhandene nicht ändern</label>
                    <label class="check"><input type="checkbox" name="create_fields" value="1"> Unbekannte Spalten als eigene Felder anlegen</label>
                    <button class="btn primary">Importieren</button>
                </form>
            </div>
            <div class="card">
                <h2>So muss die Datei aussehen</h2>
                <ul class="small">
                    <li>Erste Zeile: Spaltenüberschriften, z. B. <code>Kundennummer;Firma;Anrede;Vorname;Nachname;Straße;PLZ;Ort;E-Mail</code></li>
                    <li>Trennzeichen Semikolon, Komma oder Tabulator; Umlaute als UTF-8 oder Windows-Zeichensatz werden erkannt</li>
                    <li>Gängige Überschriften wie „Kd-Nr“, „Name“, „Postleitzahl“ oder „Stadt“ werden automatisch zugeordnet</li>
                    <li>Mit Kundennummer werden vorhandene Kunden beim nächsten Import aktualisiert statt doppelt angelegt</li>
                </ul>
                <p><a class="btn" href="<?= e(furl('customer_export', ['vorlage' => 1])) ?>">Leere Vorlage herunterladen</a>
                   <a class="btn" href="<?= e(furl('customer_export')) ?>">Alle Kunden exportieren</a></p>
            </div>
        </div>
    <?php });
}
