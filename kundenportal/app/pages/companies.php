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
    $appNames = [];
    foreach (rows('SELECT ca.company_id, a.name FROM company_apps ca JOIN apps a ON a.id = ca.app_id ORDER BY a.sort, a.name') as $g) {
        $appNames[(int)$g['company_id']][] = $g['name'];
    }
    render_page('Firmen', function () use ($list, $appNames) { ?>
        <p><a class="btn primary" href="<?= e(url('company_edit', ['new' => 1])) ?>">+ Neue Firma anlegen</a></p>
        <div class="card">
        <?php if (!$list): ?>
            <p class="muted">Noch keine Firmen angelegt.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Firma</th><th>Ort</th><th>Ansprechpartner/in</th><th>Apps</th><th class="num">Benutzer</th><th class="num">Offene Aufträge</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $c): ?>
                    <tr class="<?= (int)$c['active'] ? '' : 'inactive' ?>">
                        <td><strong><?= e($c['name']) ?></strong><?= $c['short_name'] ? ' <span class="muted">(' . e($c['short_name']) . ')</span>' : '' ?></td>
                        <td><?= e(trim($c['zip'] . ' ' . $c['city'])) ?></td>
                        <td><?= e($c['contact_name']) ?></td>
                        <td><?= $appNames[(int)$c['id']] ?? null ? e(implode(', ', $appNames[(int)$c['id']])) : '<span class="muted">keine</span>' ?></td>
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

        $wantApps = array_map('intval', (array)($_POST['apps'] ?? []));
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
            if ($u['role'] === 'admin') {
                $valid = array_map('intval', array_column(rows('SELECT id FROM apps'), 'id'));
                $want = array_intersect($valid, $wantApps);
                $have = array_map('intval', array_column(rows('SELECT app_id FROM company_apps WHERE company_id = ?', [$id]), 'app_id'));
                foreach (array_diff($want, $have) as $aid) {
                    insert('company_apps', ['company_id' => $id, 'app_id' => $aid, 'granted_by' => $u['id'], 'granted_at' => now()]);
                    audit('app_granted', 'App-ID ' . $aid, $id);
                }
                foreach (array_diff($have, $want) as $aid) {
                    q('DELETE FROM company_apps WHERE company_id = ? AND app_id = ?', [$id, $aid]);
                    audit('app_revoked', 'App-ID ' . $aid, $id);
                }
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
            <?php if ($u['role'] === 'admin'):
                $allApps = rows('SELECT * FROM apps ORDER BY sort, name');
                $has = $id ? array_map('intval', array_column(rows('SELECT app_id FROM company_apps WHERE company_id = ?', [$id]), 'app_id')) : [];
                if (is_post()) $has = array_map('intval', (array)($_POST['apps'] ?? [])); ?>
                <fieldset><legend>Freigeschaltete Apps</legend>
                    <div class="checks">
                    <?php foreach ($allApps as $a): ?>
                        <label class="check"><input type="checkbox" name="apps[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $has, true) ? 'checked' : '' ?>>
                            <?= e(($a['icon'] ? $a['icon'] . ' ' : '') . $a['name']) ?><?= (int)$a['active'] ? '' : ' <span class="tag">inaktiv</span>' ?></label>
                    <?php endforeach; ?>
                    </div>
                </fieldset>
                <label class="check"><input type="checkbox" name="active" value="1" <?= (int)$data['active'] ? 'checked' : '' ?>> Firma aktiv (Benutzer können sich anmelden und Aufträge senden)</label>
            <?php endif; ?>
            <div class="buttons">
                <button class="btn primary"><?= $isNew ? 'Firma anlegen' : 'Speichern' ?></button>
                <?php if ($u['role'] === 'admin'): ?><a class="btn" href="<?= e(url('companies')) ?>">Zurück</a><?php endif; ?>
            </div>
        </form>
        <?php if (!$isNew && $u['role'] !== 'admin'): $mine = apps_for_user(); ?>
        <div class="card">
            <h2>Freigeschaltete Apps</h2>
            <p><?= $mine ? e(implode(', ', array_column($mine, 'name'))) : '<span class="muted">keine</span>' ?></p>
            <p class="muted small">Weitere Apps schaltet Ihr IT-Dienstleister frei.</p>
        </div>
        <?php endif; ?>
        <?php if (!$isNew): ?>
        <div class="card">
            <h2>Weitere Einstellungen</h2>
            <p class="links">
                <?php $p = $u['role'] === 'admin' ? ['company' => $id] : []; ?>
                <a href="<?= e(url('users', $p)) ?>">Benutzer verwalten</a>
                <?php if ($u['role'] === 'admin' || company_has_app($id, 'onboarding')): ?>
                <a href="<?= e(url('structure', $p)) ?>">Onboarding: Abteilungen & Positionen</a>
                <a href="<?= e(url('catalog', $p)) ?>">Onboarding: Netzlaufwerke, Freigaben & Katalog</a>
                <a href="<?= e(url('templates', $p)) ?>">Onboarding: Vorlagen je Abteilung/Position</a>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
    <?php });
}
