<?php
/**
 * Firmen (Mandanten): Übersicht für Administratoren, Firmenprofil für Firmen.
 */

const COMPANY_FIELDS = [
    'name'          => ['Firmenname', 'text', true],
    'short_name'    => ['Kurzname / Kürzel', 'text', false],
    'street'        => ['Straße und Hausnummer', 'text', false],
    'zip'           => ['PLZ', 'text', false],
    'city'          => ['Ort', 'text', false],
    'phone'         => ['Telefon', 'text', false],
    'email'         => ['Allgemeine E-Mail', 'email', false],
    'contact_name'  => ['Ansprechpartner/in', 'text', false],
    'contact_email' => ['E-Mail Ansprechpartner/in (erhält Statusmeldungen)', 'email', false],
    'contact_phone' => ['Telefon Ansprechpartner/in', 'text', false],
    'email_domain'  => ['E-Mail-Domain für neue Postfächer (z. B. firma.de)', 'text', false],
];

function page_companies(): void
{
    require_admin();
    $list = rows("SELECT c.*,
            (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.active = 1) AS user_count,
            (SELECT COUNT(*) FROM requests r WHERE r.company_id = c.id AND r.status IN ('eingereicht','in_bearbeitung','rueckfrage','eingerichtet')) AS open_count
        FROM companies c ORDER BY c.active DESC, c.name");
    render_page('Firmen', function () use ($list) { ?>
        <p><a class="btn primary" href="<?= e(url('company_edit', ['new' => 1])) ?>">+ Neue Firma anlegen</a></p>
        <div class="card">
        <?php if (!$list): ?>
            <p class="muted">Noch keine Firmen angelegt.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Firma</th><th>Ort</th><th>Ansprechpartner/in</th><th class="num">Benutzer</th><th class="num">Offene Aufträge</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $c): ?>
                    <tr class="<?= (int)$c['active'] ? '' : 'inactive' ?>">
                        <td><strong><?= e($c['name']) ?></strong><?= $c['short_name'] ? ' <span class="muted">(' . e($c['short_name']) . ')</span>' : '' ?></td>
                        <td><?= e(trim($c['zip'] . ' ' . $c['city'])) ?></td>
                        <td><?= e($c['contact_name']) ?></td>
                        <td class="num"><?= (int)$c['user_count'] ?></td>
                        <td class="num"><?= (int)$c['open_count'] ?></td>
                        <td><?= (int)$c['active'] ? 'aktiv' : 'inaktiv' ?></td>
                        <td class="actions">
                            <a href="<?= e(url('company_edit', ['id' => $c['id']])) ?>">Profil</a>
                            <a href="<?= e(url('users', ['company' => $c['id']])) ?>">Benutzer</a>
                            <a href="<?= e(url('dashboard', ['company' => $c['id']])) ?>">Aufträge</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    <?php });
}

function page_company_edit(): void
{
    $u = require_manager();
    $isNew = false;
    if ($u['role'] === 'admin') {
        $id = query_int('id');
        $isNew = !$id && isset($_GET['new']);
        if (!$id && !$isNew) redirect('companies');
    } else {
        $id = (int)$u['company_id'];
    }
    $company = $id ? row('SELECT * FROM companies WHERE id = ?', [$id]) : null;
    if (!$isNew && !$company) abort(404);
    if ($company) require_company_access((int)$company['id']);

    $errors = [];
    $data = $company ?? array_fill_keys(array_keys(COMPANY_FIELDS), '') + ['it_notes' => '', 'active' => 1];

    if (is_post()) {
        require_post_csrf();
        foreach (COMPANY_FIELDS as $f => [$label, $type, $required]) {
            $data[$f] = mb_substr(post($f), 0, 190);
            if ($required && $data[$f] === '') $errors[] = "Bitte „{$label}“ ausfüllen.";
            if ($type === 'email' && $data[$f] !== '' && !filter_var($data[$f], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "„{$label}“ ist keine gültige E-Mail-Adresse.";
            }
        }
        $data['email_domain'] = strtolower(ltrim($data['email_domain'], '@'));
        $data['it_notes'] = mb_substr(post('it_notes'), 0, 5000);
        if ($u['role'] === 'admin') $data['active'] = post('active') === '1' ? 1 : 0;

        if (!$errors) {
            $save = array_intersect_key($data, COMPANY_FIELDS + ['it_notes' => 1, 'active' => 1]);
            $save['updated_at'] = now();
            if ($isNew) {
                $save['created_at'] = now();
                $id = insert('companies', $save);
                audit('company_created', $save['name'], $id);
                flash('success', 'Die Firma wurde angelegt. Legen Sie jetzt Benutzer, Abteilungen und Positionen an.');
            } else {
                update('companies', $id, $save);
                audit('company_updated', $save['name'], $id);
                flash('success', 'Das Firmenprofil wurde gespeichert.');
            }
            redirect('company_edit', $u['role'] === 'admin' ? ['id' => $id] : []);
        }
    }

    $title = $isNew ? 'Neue Firma' : 'Firmenprofil: ' . $data['name'];
    render_page($title, function () use ($data, $errors, $u, $isNew, $id) {
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>'; ?>
        <form method="post" class="card form">
            <?= csrf_field() ?>
            <div class="grid2">
                <?php foreach (COMPANY_FIELDS as $f => [$label, $type, $required]): ?>
                    <label><?= e($label) ?><?= $required ? ' *' : '' ?>
                        <input type="<?= $type === 'email' ? 'email' : 'text' ?>" name="<?= e($f) ?>" value="<?= e($data[$f]) ?>" <?= $required ? 'required' : '' ?> maxlength="190">
                    </label>
                <?php endforeach; ?>
            </div>
            <label>Hinweise für die IT (z. B. Standorte, Druckerumgebung, Besonderheiten)
                <textarea name="it_notes" rows="5"><?= e($data['it_notes']) ?></textarea>
            </label>
            <?php if ($u['role'] === 'admin'): ?>
                <label class="check"><input type="checkbox" name="active" value="1" <?= (int)$data['active'] ? 'checked' : '' ?>> Firma aktiv (Benutzer können sich anmelden und Aufträge senden)</label>
            <?php endif; ?>
            <div class="buttons">
                <button class="btn primary"><?= $isNew ? 'Firma anlegen' : 'Speichern' ?></button>
                <?php if ($u['role'] === 'admin'): ?><a class="btn" href="<?= e(url('companies')) ?>">Zurück</a><?php endif; ?>
            </div>
        </form>
        <?php if (!$isNew): ?>
        <div class="card">
            <h2>Weitere Einstellungen</h2>
            <p class="links">
                <?php $p = $u['role'] === 'admin' ? ['company' => $id] : []; ?>
                <a href="<?= e(url('users', $p)) ?>">Benutzer verwalten</a>
                <a href="<?= e(url('structure', $p)) ?>">Abteilungen & Positionen</a>
                <a href="<?= e(url('catalog', $p)) ?>">Netzlaufwerke, Freigaben & firmeneigene Einträge</a>
                <a href="<?= e(url('templates', $p)) ?>">Vorlagen je Abteilung/Position</a>
            </p>
        </div>
        <?php endif; ?>
    <?php });
}
