<?php
/**
 * Administration → Externe Dienstleister: Verwaltungsadmins legen Dienstleister-Zugänge (Rolle „dienstleister“)
 * an und ordnen sie Firmen mit der App „Tickets“ zu. Ein Zugang kann für mehrere Firmen arbeiten;
 * je Firma entsteht ein Eintrag in ticket_providers (wie bei der Anlage durch die Firma selbst).
 * Dürfen: Superadmin und Verwaltungsadmins mit Bearbeiten-Recht für „Benutzer“ oder „Tickets“,
 * jeweils nur für Firmen, auf die sie Zugriff haben.
 */

function provider_admin_can(): bool
{
    return is_admin() && (admin_can('users', true) || admin_can('tickets', true));
}

/** Firmen mit App „Tickets“, auf die der Admin zugreifen darf */
function provider_admin_companies(): array
{
    return array_values(array_filter(rows('SELECT id, name FROM companies WHERE active = 1' . company_scope_sql('id') . ' ORDER BY name'),
        fn ($c) => company_has_app((int)$c['id'], TICKETS_APP)));
}

function page_provider_accounts(): void
{
    require_login();
    if (!provider_admin_can()) abort(403);
    $list = rows("SELECT u.id, u.name, u.email, u.active, u.last_login, u.totp_enabled FROM users u WHERE u.role = 'dienstleister' ORDER BY u.active DESC, u.name");
    $links = [];
    foreach (rows('SELECT p.user_id, p.active, p.see_all, c.name AS company FROM ticket_providers p JOIN companies c ON c.id = p.company_id
        WHERE p.user_id IS NOT NULL' . company_scope_sql('p.company_id') . ' ORDER BY c.name') as $r) $links[(int)$r['user_id']][] = $r;
    render_page(t('Externe Dienstleister'), function () use ($list, $links) { ?>
        <p class="muted"><?= e(t('Dienstleister-Zugänge (z. B. Kopierer-Service, Telefonanlage) mit eigenem Portal-Login. Ein Zugang kann für mehrere Firmen arbeiten und sieht dort die Tickets, die ihm übergeben werden. Dienstleister ohne Portal-Zugang (nur per E-Mail) legen die Firmen unter Einstellungen → Benutzer an.')) ?></p>
        <p><a class="btn primary" href="<?= e(url('provider_account', ['new' => 1])) ?>"><?= e(t('+ Externen Dienstleister anlegen')) ?></a></p>
        <div class="card">
            <table class="table">
                <thead><tr><th><?= e(t('Name')) ?></th><th><?= e(t('E-Mail')) ?></th><th><?= e(t('Firmen')) ?></th><th>2FA</th><th><?= e(t('Letzte Anmeldung')) ?></th><th><?= e(t('Status')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $u): $ls = $links[(int)$u['id']] ?? []; ?>
                    <tr class="<?= (int)$u['active'] ? '' : 'inactive' ?>">
                        <td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td>
                        <td><?= $ls ? e(implode(', ', array_map(fn ($l) => $l['company'] . ((int)$l['active'] ? '' : ' (' . t('inaktiv') . ')'), $ls))) : '<span class="muted">–</span>' ?></td>
                        <td><?= (int)$u['totp_enabled'] ? t('✓ eingerichtet') : ('<span class="muted">' . e(t('bei nächster Anmeldung')) . '</span>') ?></td>
                        <td><?= fmt_dt($u['last_login']) ?></td>
                        <td><?= (int)$u['active'] ? e(t('aktiv')) : e(t('deaktiviert')) ?></td>
                        <td class="actions"><a href="<?= e(url('provider_account', ['id' => $u['id']])) ?>"><?= e(t('Bearbeiten')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$list): ?><tr><td colspan="7" class="muted"><?= e(t('Noch keine externen Dienstleister mit Portal-Zugang.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php });
}

function page_provider_account(): void
{
    require_login();
    if (!provider_admin_can()) abort(403);
    $id = query_int('id');
    $acct = $id ? row("SELECT * FROM users WHERE id = ? AND role = 'dienstleister'", [$id]) : null;
    if ($id && !$acct) abort(404);
    $companies = provider_admin_companies();
    $cids = array_map(fn ($c) => (int)$c['id'], $companies);
    $cnames = array_column($companies, 'name', 'id');
    $rows = [];
    if ($acct) foreach (rows('SELECT * FROM ticket_providers WHERE user_id = ?', [$id]) as $r) $rows[(int)$r['company_id']] = $r;
    $data = $acct ?? ['name' => '', 'email' => '', 'active' => 1];
    $errors = [];

    if (is_post()) {
        require_post_csrf();
        $action = (string)post('action', 'save');
        if ($action === 'invite') {
            if (!$acct || $acct['last_login'] || !(int)$acct['active']) abort(400);
            $first = $rows ? ($cnames[array_key_first($rows)] ?? '') : '';
            ticket_provider_invite($acct, $first !== '' ? $first : t(cfg('app_name', 'Kundenportal')));
            audit('provider.invite', $acct['email'], null);
            flash('success', t('Die Einladung wurde erneut an {x} geschickt.', ['x' => $acct['email']]));
            redirect('provider_account', ['id' => $id]);
        }
        $data['name'] = mb_substr(trim((string)post('name')), 0, 190);
        $data['email'] = strtolower(mb_substr(trim((string)post('email')), 0, 190));
        $data['active'] = $acct ? (post('active') === '1' ? 1 : 0) : 1;
        if ($data['name'] === '') $errors[] = t('Bitte einen Namen angeben.');
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = t('Bitte eine gültige E-Mail-Adresse angeben.');
        elseif (row('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ?', [$data['email'], $id ?: 0])) $errors[] = t('Diese E-Mail-Adresse wird bereits verwendet.');
        $want = [];
        foreach ((array)($_POST['co'] ?? []) as $cid => $on) if ($on && in_array((int)$cid, $cids, true)) $want[(int)$cid] = !empty(($_POST['see_all'] ?? [])[$cid]) ? 1 : 0;
        if (!$errors) {
            $new = false;
            if ($acct) {
                update('users', $id, ['name' => $data['name'], 'email' => $data['email'], 'active' => $data['active']]);
            } else {
                $id = insert('users', ['company_id' => null, 'email' => $data['email'], 'name' => $data['name'], 'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                    'role' => 'dienstleister', 'active' => 1, 'email_verified' => 1, 'must_change_password' => 0, 'created_at' => now()]);
                $new = true;
            }
            // Zuordnungen nur für Firmen, die dieser Admin sieht; andere bleiben unverändert
            $log = [];
            foreach ($cids as $cid) {
                $has = $rows[$cid] ?? null;
                if (isset($want[$cid])) {
                    $vals = ['name' => $data['name'], 'email' => $data['email'], 'user_id' => $id, 'see_all' => $want[$cid], 'active' => 1];
                    if ($has) update('ticket_providers', (int)$has['id'], $vals);
                    else insert('ticket_providers', $vals + ['company_id' => $cid, 'notes' => '', 'created_at' => now()]);
                    if (!$has || !(int)$has['active']) $log[] = '+' . $cnames[$cid];
                } elseif ($has && (int)$has['active']) {
                    // an Tickets beteiligt: deaktivieren statt löschen (Verlauf bleibt)
                    if (row('SELECT id FROM tickets WHERE provider_id = ? LIMIT 1', [$has['id']])) update('ticket_providers', (int)$has['id'], ['active' => 0]);
                    else q('DELETE FROM ticket_providers WHERE id = ?', [$has['id']]);
                    $log[] = '−' . $cnames[$cid];
                }
            }
            audit($new ? 'provider.create' : 'provider.update', mb_substr($data['email'] . ($log ? ' ' . implode(' ', $log) : ''), 0, 500), null);
            if ($new) {
                $acct = row('SELECT * FROM users WHERE id = ?', [$id]);
                $first = $want ? ($cnames[array_key_first($want)] ?? '') : '';
                ticket_provider_invite($acct, $first !== '' ? $first : t(cfg('app_name', 'Kundenportal')));
                flash('success', t('Der Dienstleister wurde angelegt. Er bekommt eine Einladung an {x}, um sein Passwort zu vergeben.', ['x' => $data['email']]));
            } else {
                flash('success', t('Der Dienstleister wurde gespeichert.'));
            }
            redirect('provider_account', ['id' => $id]);
        }
    }

    render_page($acct ? t('Externer Dienstleister: {x}', ['x' => $acct['name']]) : t('Externen Dienstleister anlegen'),
        function () use ($acct, $data, $errors, $companies, $rows) {
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>'; ?>
        <p><a href="<?= e(url('provider_accounts')) ?>"><?= e(t('Zurück zur Liste')) ?></a></p>
        <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="action" value="save">
            <div class="grid2">
                <label><?= e(t('Name *')) ?> <input name="name" maxlength="190" required value="<?= e($data['name']) ?>" placeholder="<?= e(t('z. B. Kopierer-Service Müller')) ?>"></label>
                <label><?= e(t('E-Mail-Adresse (Benutzername) *')) ?> <input type="email" name="email" maxlength="190" required value="<?= e($data['email']) ?>"></label>
            </div>
            <?php if ($acct): ?><label class="check"><input type="checkbox" name="active" value="1" <?= (int)$data['active'] ? 'checked' : '' ?>> <?= e(t('Zugang aktiv')) ?></label><?php endif; ?>
            <h3><?= e(t('Firmen')) ?></h3>
            <?php if (!$companies): ?><p class="muted"><?= e(t('Keine Firma mit der App „Tickets“ freigeschaltet.')) ?></p><?php else: ?>
            <table class="table compact"><thead><tr><th><?= e(t('Firma')) ?></th><th><?= e(t('Zugeordnet')) ?></th><th><?= e(t('Darf alle Tickets der Firma sehen')) ?></th></tr></thead><tbody>
            <?php foreach ($companies as $c): $cid = (int)$c['id']; $r = $rows[$cid] ?? null; $on = $r && (int)$r['active']; ?>
                <tr><td><?= e($c['name']) ?></td>
                    <td><input type="checkbox" name="co[<?= $cid ?>]" value="1" <?= $on ? 'checked' : '' ?> aria-label="<?= e(t('Zugeordnet') . ' · ' . $c['name']) ?>"></td>
                    <td><input type="checkbox" name="see_all[<?= $cid ?>]" value="1" <?= $r && (int)$r['see_all'] ? 'checked' : '' ?> aria-label="<?= e(t('Darf alle Tickets der Firma sehen') . ' · ' . $c['name']) ?>"></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <p class="muted small"><?= e(t('Ohne „alle Tickets“ sieht der Dienstleister nur die Tickets, die ihm übergeben werden. Die Firma sieht ihn danach in ihrer Benutzerliste und kann ihn dort ebenfalls anpassen.')) ?></p>
            <?php endif; ?>
            <button class="btn primary"><?= $acct ? e(t('Speichern')) : e(t('Anlegen und einladen')) ?></button>
        </form>
        <?php if ($acct && !$acct['last_login'] && (int)$acct['active']): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="invite"><button class="btn"><?= e(t('Einladung erneut senden')) ?></button></form>
        <?php endif; ?>
    <?php });
}
