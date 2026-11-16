<?php
/**
 * Benutzerverwaltung: Admins für alle Firmen, Firmen-Admins für die eigene Firma.
 */

function random_password(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < 14; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
    return $pw . random_int(10, 99);
}

function page_users(): void
{
    $u = require_manager();
    $showAdmins = $u['role'] === 'admin' && isset($_GET['admins']);
    if ($showAdmins) {
        $list = rows("SELECT * FROM users WHERE role = 'admin' ORDER BY name");
        $companyId = 0;
        $title = 'Benutzer: Administratoren';
    } else {
        $companyId = context_company_id();
        if (!$companyId) {
            flash('info', 'Bitte legen Sie zuerst eine Firma an.');
            redirect('companies');
        }
        require_company_access($companyId);
        $list = rows('SELECT * FROM users WHERE company_id = ? ORDER BY active DESC, name', [$companyId]);
        $cname = row('SELECT name FROM companies WHERE id = ?', [$companyId])['name'] ?? '';
        $title = 'Benutzer: ' . $cname;
    }
    render_page($title, function () use ($u, $list, $companyId, $showAdmins) {
        if ($u['role'] === 'admin') {
            echo '<div class="toolbar">' . ($showAdmins ? '' : company_switcher($companyId, 'users'));
            echo $showAdmins
                ? '<a class="btn" href="' . e(url('users')) . '">Firmenbenutzer anzeigen</a>'
                : '<a class="btn" href="' . e(url('users', ['admins' => 1])) . '">Administratoren anzeigen</a>';
            echo '</div>';
        }
        $newParams = $showAdmins ? ['new' => 1, 'admin' => 1] : ['new' => 1, 'company' => $companyId]; ?>
        <p><a class="btn primary" href="<?= e(url('user_edit', $newParams)) ?>">+ Neuen Benutzer anlegen</a></p>
        <div class="card">
            <table class="table">
                <thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>2FA</th><th>Letzte Anmeldung</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $row): ?>
                    <tr class="<?= (int)$row['active'] ? '' : 'inactive' ?>">
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['email']) ?></td>
                        <td><?= e(ROLES[$row['role']] ?? $row['role']) ?></td>
                        <td><?= (int)$row['totp_enabled'] ? '✓ eingerichtet' : '<span class="muted">bei nächster Anmeldung</span>' ?></td>
                        <td><?= fmt_dt($row['last_login']) ?></td>
                        <td><?= (int)$row['active'] ? 'aktiv' : 'deaktiviert' ?><?= $row['locked_until'] && strtotime($row['locked_until']) > time() ? ' · <strong>gesperrt</strong>' : '' ?></td>
                        <td class="actions"><a href="<?= e(url('user_edit', ['id' => $row['id']])) ?>">Bearbeiten</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$list): ?><tr><td colspan="7" class="muted">Noch keine Benutzer.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php });
}

function page_user_edit(): void
{
    $me = require_manager();
    $id = query_int('id');
    $target = $id ? row('SELECT * FROM users WHERE id = ?', [$id]) : null;
    if ($id && !$target) abort(404);

    if ($target) {
        if ($target['role'] === 'admin' && $me['role'] !== 'admin') abort(403);
        if ($target['company_id']) require_company_access((int)$target['company_id']);
        $companyId = $target['company_id'] ? (int)$target['company_id'] : null;
    } else {
        if ($me['role'] === 'admin' && isset($_GET['admin'])) {
            $companyId = null;
        } else {
            $companyId = $me['role'] === 'admin' ? query_int('company') : (int)$me['company_id'];
            if (!$companyId || !row('SELECT id FROM companies WHERE id = ?', [$companyId])) abort(404);
            require_company_access($companyId);
        }
    }
    $isSelf = $target && (int)$target['id'] === $me['id'];
    $allowedRoles = $companyId === null ? ['admin'] : ['firma_user', 'firma_admin'];
    $data = $target ?? ['name' => '', 'email' => '', 'role' => $allowedRoles[0], 'active' => 1];
    $errors = [];
    $newPassword = null;

    if (is_post()) {
        require_post_csrf();
        $action = post('action', 'save');
        if ($action === 'save') {
            $data['name'] = mb_substr(post('name'), 0, 190);
            $data['email'] = strtolower(mb_substr(post('email'), 0, 190));
            $role = post('role', $allowedRoles[0]);
            $data['role'] = in_array($role, $allowedRoles, true) ? $role : $allowedRoles[0];
            $data['active'] = post('active') === '1' ? 1 : 0;
            if ($isSelf) { $data['active'] = 1; $data['role'] = $target['role']; }
            if ($data['name'] === '') $errors[] = 'Bitte einen Namen angeben.';
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
            $dup = row('SELECT id FROM users WHERE email = ? AND id <> ?', [$data['email'], $id ?: 0]);
            if ($dup) $errors[] = 'Diese E-Mail-Adresse wird bereits verwendet.';
            if (!$errors) {
                $save = ['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'], 'active' => $data['active']];
                if ($target) {
                    update('users', $id, $save);
                    audit('user_updated', $save['email'], $companyId);
                    flash('success', 'Der Benutzer wurde gespeichert.');
                } else {
                    $newPassword = random_password();
                    $save += ['company_id' => $companyId, 'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        'must_change_password' => 1, 'created_at' => now()];
                    $id = insert('users', $save);
                    audit('user_created', $save['email'], $companyId);
                    $target = row('SELECT * FROM users WHERE id = ?', [$id]);
                    $data = $target;
                }
            }
        } elseif ($target && !$isSelf && $action === 'reset_password') {
            $newPassword = random_password();
            update('users', $id, ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'must_change_password' => 1, 'failed_attempts' => 0, 'locked_until' => null]);
            audit('user_password_reset', $target['email'], $companyId);
        } elseif ($target && !$isSelf && $action === 'reset_2fa') {
            update('users', $id, ['totp_enabled' => 0, 'totp_secret' => null, 'totp_last_step' => 0]);
            audit('user_2fa_reset', $target['email'], $companyId);
            flash('success', 'Die Zwei-Faktor-Authentifizierung wurde zurückgesetzt. Der Benutzer richtet sie bei der nächsten Anmeldung neu ein.');
            redirect('user_edit', ['id' => $id]);
        } elseif ($target && $action === 'unlock') {
            update('users', $id, ['failed_attempts' => 0, 'locked_until' => null]);
            audit('user_unlocked', $target['email'], $companyId);
            flash('success', 'Die Sperre wurde aufgehoben.');
            redirect('user_edit', ['id' => $id]);
        }
        if (!$errors && !$newPassword) redirect('user_edit', ['id' => $id]);
    }

    $backParams = $companyId === null ? ['admins' => 1] : ($me['role'] === 'admin' ? ['company' => $companyId] : []);
    render_page($target ? 'Benutzer bearbeiten' : 'Neuer Benutzer', function () use ($data, $errors, $target, $allowedRoles, $isSelf, $newPassword, $backParams, $companyId) {
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>';
        if ($newPassword): ?>
            <div class="alert success">
                <p><strong>Startpasswort für <?= e($data['email']) ?>:</strong></p>
                <p class="secret"><?= e($newPassword) ?></p>
                <p>Teilen Sie das Passwort auf sicherem Weg mit (z. B. telefonisch). Es wird nur jetzt angezeigt.
                    Bei der ersten Anmeldung muss der Benutzer ein eigenes Passwort vergeben und die Authenticator-App einrichten.</p>
            </div>
        <?php endif; ?>
        <?php if ($companyId): ?>
            <p class="muted">Firma: <?= e(row('SELECT name FROM companies WHERE id = ?', [$companyId])['name'] ?? '') ?></p>
        <?php endif; ?>
        <form method="post" class="card form" <?= $target ? 'action="' . e(url('user_edit', ['id' => $target['id']])) . '"' : '' ?>>
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <div class="grid2">
                <label>Name *<input name="name" required maxlength="190" value="<?= e($data['name']) ?>"></label>
                <label>E-Mail-Adresse (Benutzername) *<input type="email" name="email" required maxlength="190" value="<?= e($data['email']) ?>"></label>
                <label>Rolle
                    <select name="role" <?= $isSelf ? 'disabled' : '' ?>>
                        <?php foreach ($allowedRoles as $r): ?>
                            <option value="<?= e($r) ?>" <?= $data['role'] === $r ? 'selected' : '' ?>><?= e(ROLES[$r]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <p class="muted small">Firmen-Benutzer können Aufträge erstellen und verfolgen. Firmen-Administratoren pflegen zusätzlich Firmenprofil, Benutzer, Abteilungen, Positionen, Katalog und Vorlagen der Firma.</p>
            <label class="check"><input type="checkbox" name="active" value="1" <?= (int)$data['active'] ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>> Zugang aktiv</label>
            <div class="buttons">
                <button class="btn primary"><?= $target ? 'Speichern' : 'Benutzer anlegen' ?></button>
                <a class="btn" href="<?= e(url('users', $backParams)) ?>">Zurück zur Liste</a>
            </div>
        </form>
        <?php if ($target && !$isSelf): ?>
        <div class="card">
            <h2>Zugang zurücksetzen</h2>
            <div class="buttons">
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password">
                    <button class="btn" data-confirm="Neues Startpasswort erzeugen? Das bisherige Passwort wird ungültig.">Neues Startpasswort</button></form>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset_2fa">
                    <button class="btn" data-confirm="Zwei-Faktor-Authentifizierung zurücksetzen?">2FA zurücksetzen</button></form>
                <?php if ($target['locked_until'] && strtotime($target['locked_until']) > time()): ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="unlock">
                    <button class="btn">Sperre aufheben</button></form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php });
}
