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
    $showAdmins = is_staff_role($u['role']) && isset($_GET['admins']);
    if ($showAdmins) {
        $list = rows("SELECT * FROM users WHERE role IN ('superadmin', 'admin')
            ORDER BY CASE role WHEN 'superadmin' THEN 0 ELSE 1 END, active DESC, name");
        $companyId = 0;
        $title = 'Benutzer: Administratoren (Dienstleister)';
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
        if (is_staff_role($u['role'])) {
            echo '<div class="toolbar">' . ($showAdmins ? '' : company_switcher($companyId, 'users'));
            echo $showAdmins
                ? '<a class="btn" href="' . e(url('users')) . '">Firmenbenutzer anzeigen</a>'
                : '<a class="btn" href="' . e(url('users', ['admins' => 1])) . '">Administratoren anzeigen</a>';
            echo '</div>';
        }
        $newParams = $showAdmins ? ['new' => 1, 'admin' => 1] : ['new' => 1, 'company' => $companyId];
        $canCreate = !$showAdmins || is_superadmin(); ?>
        <?php if ($showAdmins): ?>
            <p class="muted">Superadmins verwalten die Administratoren: Nur sie dürfen Verwaltungsadmins anlegen, deren Rolle ändern, sie deaktivieren oder löschen.
                Verwaltungsadmins verwalten alle Firmen, Benutzer der Firmen, Apps und Aufträge.</p>
        <?php endif; ?>
        <?php if ($canCreate): ?><p><a class="btn primary" href="<?= e(url('user_edit', $newParams)) ?>">+ Neuen <?= $showAdmins ? 'Administrator' : 'Benutzer' ?> anlegen</a></p><?php endif; ?>
        <div class="card">
            <table class="table">
                <thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>2FA</th><th>Letzte Anmeldung</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $row): ?>
                    <tr class="<?= (int)$row['active'] ? '' : 'inactive' ?>">
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['email']) ?></td>
                        <td><?= e(ROLES[$row['role']] ?? $row['role']) ?><?php if ($row['role'] === 'admin'): ?><br><span class="muted small"><?= e(admin_rights_summary($row)) ?></span><?php endif; ?></td>
                        <td><?= (int)$row['totp_enabled'] ? '✓ eingerichtet' : '<span class="muted">bei nächster Anmeldung</span>' ?><?= $row['totp_reset_requested_at'] ? ' <span class="badge st-rueckfrage">Reset beantragt</span>' : '' ?><?= (int)$row['email_verified'] ? '' : ' <span class="badge">E-Mail unbestätigt</span>' ?></td>
                        <td><?= fmt_dt($row['last_login']) ?></td>
                        <td><?= (int)$row['active'] ? 'aktiv' : 'deaktiviert' ?><?= $row['locked_until'] && strtotime($row['locked_until']) > time() ? ' · <strong>gesperrt</strong>' : '' ?></td>
                        <td class="actions"><?php if (!is_staff_role($row['role']) || is_superadmin()): ?><a href="<?= e(url('user_edit', ['id' => $row['id']])) ?>">Bearbeiten</a><?php elseif ((int)$row['id'] === $u['id']): ?><a href="<?= e(url('account')) ?>">Mein Konto</a><?php endif; ?></td>
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
        if (is_staff_role($target['role']) && !is_staff_role($me['role'])) abort(403);
        // Administratoren-Konten verwaltet nur der Superadmin; das eigene Konto über "Mein Konto"
        if (is_staff_role($target['role']) && $me['role'] !== 'superadmin') {
            if ((int)$target['id'] === $me['id']) redirect('account');
            abort(403, 'Administratoren können nur von einem Superadmin bearbeitet werden.');
        }
        if ($target['company_id']) require_company_access((int)$target['company_id']);
        $companyId = $target['company_id'] ? (int)$target['company_id'] : null;
    } else {
        if (is_staff_role($me['role']) && isset($_GET['admin'])) {
            if ($me['role'] !== 'superadmin') abort(403, 'Nur ein Superadmin darf Administratoren anlegen.');
            $companyId = null;
        } else {
            $companyId = is_staff_role($me['role']) ? query_int('company') : (int)$me['company_id'];
            if (!$companyId || !row('SELECT id FROM companies WHERE id = ?', [$companyId])) abort(404);
            require_company_access($companyId);
        }
    }
    $isSelf = $target && (int)$target['id'] === $me['id'];
    // Es gibt genau einen Superadmin: die Rolle wird nicht vergeben, sondern nur übertragen
    $allowedRoles = $companyId === null ? ['admin'] : ['firma_user', 'firma_admin'];
    if ($target && $target['role'] === 'superadmin') $allowedRoles = ['superadmin'];
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
            // Der Superadmin bleibt immer aktiv und behält seine Rolle (Wechsel nur per Übertragung)
            if ($target && $target['role'] === 'superadmin') { $data['active'] = 1; $data['role'] = 'superadmin'; }
            if ($data['name'] === '') $errors[] = 'Bitte einen Namen angeben.';
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
            $dup = row('SELECT id FROM users WHERE email = ? AND id <> ?', [$data['email'], $id ?: 0]);
            if ($dup) $errors[] = 'Diese E-Mail-Adresse wird bereits verwendet.';
            // Rechte eines Verwaltungsadmins (nur der Superadmin legt sie fest)
            $permSave = [];
            if ($companyId === null && $data['role'] === 'admin' && $me['role'] === 'superadmin') {
                if (post('perm_mode') === 'full') {
                    $permSave = ['admin_perms' => null, 'admin_companies' => ''];
                } else {
                    $perms = [];
                    foreach (array_keys(ADMIN_AREAS) as $area) {
                        $perms[$area] = max(PERM_NONE, min(PERM_EDIT, (int)(($_POST['perm'] ?? [])[$area] ?? PERM_NONE)));
                    }
                    if ($perms['audit'] > PERM_VIEW) $perms['audit'] = PERM_VIEW;
                    if ($perms['updates'] > PERM_VIEW) $perms['updates'] = PERM_VIEW;
                    $permSave = ['admin_perms' => json_encode($perms)];
                }
                if (post('company_mode') === 'some') {
                    $valid = array_map('intval', array_column(rows('SELECT id FROM companies'), 'id'));
                    $ids = array_values(array_intersect($valid, array_map('intval', (array)($_POST['companies'] ?? []))));
                    if (!$ids) $errors[] = 'Bitte mindestens eine Firma auswählen oder „alle Firmen“ wählen.';
                    $permSave['admin_companies'] = implode(',', $ids);
                } else {
                    $permSave['admin_companies'] = '';
                }
                $data = array_merge($data, $permSave);
            }
            if (!$errors) {
                $save = ['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'], 'active' => $data['active']] + $permSave;
                if ($target) {
                    update('users', $id, $save);
                    if ($permSave && (($target['admin_perms'] ?? null) !== ($save['admin_perms'] ?? null) || ($target['admin_companies'] ?? '') !== ($save['admin_companies'] ?? ''))) {
                        audit('admin_rights_changed', $save['email'] . ': ' . admin_rights_summary($save), null);
                    }
                    audit('user_updated', $save['email'] . ($target['role'] !== $save['role'] ? ' (Rolle: ' . ROLES[$target['role']] . ' → ' . ROLES[$save['role']] . ')' : ''), $companyId);
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
            if ($me['role'] !== 'superadmin') abort(403, 'Die Zwei-Faktor-Anmeldung kann nur der Superadmin zurücksetzen.');
            update('users', $id, ['totp_enabled' => 0, 'totp_secret' => null, 'totp_last_step' => 0, 'totp_reset_requested_at' => null]);
            audit('user_2fa_reset', $target['email'], $companyId);
            send_mail([$target['email']], 'Zwei-Faktor-Anmeldung zurückgesetzt',
                "Guten Tag " . $target['name'] . ",\n\nIhre Zwei-Faktor-Anmeldung wurde zurückgesetzt. Melden Sie sich mit Ihrem Passwort an und richten Sie die Authenticator-App neu ein:\n\n"
                . portal_link('login') . "\n\nWaren Sie das nicht? Bitte melden Sie sich sofort bei Ihrem IT-Dienstleister.", $companyId);
            flash('success', 'Die Zwei-Faktor-Authentifizierung wurde zurückgesetzt. Der Benutzer richtet sie bei der nächsten Anmeldung neu ein.');
            redirect('user_edit', ['id' => $id]);
        } elseif ($target && !$isSelf && $action === 'delete') {
            // Löschen nur für Administratoren-Konten und nur durch den Superadmin (Kundenkonten werden deaktiviert)
            if ($me['role'] !== 'superadmin' || !is_staff_role($target['role'])) abort(403);
            if ($target['role'] === 'superadmin') abort(400);
            q('DELETE FROM users WHERE id = ?', [$id]);
            audit('user_deleted', $target['name'] . ' <' . $target['email'] . '>, ' . ROLES[$target['role']], null);
            flash('success', 'Das Konto von ' . $target['name'] . ' wurde gelöscht.');
            redirect('users', ['admins' => 1]);
        } elseif ($target && !$isSelf && $action === 'transfer_superadmin') {
            if ($me['role'] !== 'superadmin' || $target['role'] !== 'admin' || !(int)$target['active']) abort(403);
            if (!password_verify((string)($_POST['current_password'] ?? ''), $me['password_hash'])) {
                $errors[] = 'Ihr Passwort ist falsch. Die Superadmin-Rolle wurde nicht übertragen.';
            } else {
                db()->beginTransaction();
                update('users', $me['id'], ['role' => 'admin']);
                update('users', $id, ['role' => 'superadmin']);
                db()->commit();
                audit('superadmin_transferred', 'Superadmin-Rolle übertragen an ' . $target['name'] . ' <' . $target['email'] . '>', null);
                flash('success', 'Die Superadmin-Rolle wurde an ' . $target['name'] . ' übertragen. Sie sind jetzt Verwaltungsadmin.');
                redirect('users', ['admins' => 1]);
            }
        } elseif ($target && $action === 'verify_email') {
            update('users', $id, ['email_verified' => 1]);
            q("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'verify'", [$id]);
            audit('email_verified_by_admin', $target['email'], $companyId);
            flash('success', 'Die E-Mail-Adresse wurde als bestätigt markiert. Der Benutzer kann sich jetzt anmelden.');
            redirect('user_edit', ['id' => $id]);
        } elseif ($target && $action === 'resend_verification') {
            send_verification_mail($target);
            audit('verification_resent', $target['email'], $companyId);
            flash('success', 'Der Bestätigungslink wurde erneut an ' . $target['email'] . ' gesendet. Kommt nichts an, prüfen Sie unter „E-Mail-Versand“ das Postfach und senden Sie eine Testmail.');
            redirect('user_edit', ['id' => $id]);
        } elseif ($target && $action === 'unlock') {
            update('users', $id, ['failed_attempts' => 0, 'locked_until' => null]);
            audit('user_unlocked', $target['email'], $companyId);
            flash('success', 'Die Sperre wurde aufgehoben.');
            redirect('user_edit', ['id' => $id]);
        }
        if (!$errors && !$newPassword) redirect('user_edit', ['id' => $id]);
    }

    $backParams = $companyId === null ? ['admins' => 1] : (is_staff_role($me['role']) ? ['company' => $companyId] : []);
    $showRights = $companyId === null && ($data['role'] ?? '') === 'admin' && $me['role'] === 'superadmin';
    render_page($target ? 'Benutzer bearbeiten' : 'Neuer Benutzer', function () use ($data, $errors, $target, $allowedRoles, $isSelf, $newPassword, $backParams, $companyId, $showRights) {
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
                    <select name="role" <?= $isSelf || count($allowedRoles) === 1 ? 'disabled' : '' ?>>
                        <?php foreach ($allowedRoles as $r): ?>
                            <option value="<?= e($r) ?>" <?= $data['role'] === $r ? 'selected' : '' ?>><?= e(ROLES[$r]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <?php if ($companyId === null): ?>
            <p class="muted small">Was ein Verwaltungsadmin darf, legen Sie unten unter „Rechte“ fest. Es gibt genau einen Superadmin; er darf immer alles, verwaltet die Administratoren-Konten und kann seine Rolle an einen Verwaltungsadmin übertragen.</p>
            <?php if ($showRights) render_admin_rights_fields($data); ?>
            <?php else: ?>
            <p class="muted small">Firmen-Benutzer können Aufträge erstellen und verfolgen. Firmen-Administratoren pflegen zusätzlich Firmenprofil, Benutzer, Abteilungen, Positionen, Katalog und Vorlagen der Firma.</p>
            <?php endif; ?>
            <label class="check"><input type="checkbox" name="active" value="1" <?= (int)$data['active'] ? 'checked' : '' ?> <?= $isSelf || ($data['role'] ?? '') === 'superadmin' ? 'disabled' : '' ?>> Zugang aktiv</label>
            <div class="buttons">
                <button class="btn primary"><?= $target ? 'Speichern' : 'Benutzer anlegen' ?></button>
                <a class="btn" href="<?= e(url('users', $backParams)) ?>">Zurück zur Liste</a>
            </div>
        </form>
        <?php if ($target && !(int)$target['email_verified']): ?>
        <div class="card review">
            <h2>E-Mail-Adresse noch nicht bestätigt</h2>
            <p class="muted">Ohne Bestätigung ist keine Anmeldung möglich. Ist die Bestätigungsmail nicht angekommen, können Sie sie erneut senden oder die Adresse selbst bestätigen.</p>
            <div class="buttons">
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="resend_verification">
                    <button class="btn">Bestätigungslink erneut senden</button></form>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="verify_email">
                    <button class="btn primary" data-confirm="E-Mail-Adresse <?= e($target['email']) ?> als bestätigt markieren?">Als bestätigt markieren</button></form>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($target && !$isSelf): ?>
        <div class="card">
            <h2>Zugang zurücksetzen</h2>
            <div class="buttons">
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password">
                    <button class="btn" data-confirm="Neues Startpasswort erzeugen? Das bisherige Passwort wird ungültig.">Neues Startpasswort</button></form>
                <?php if (is_superadmin()): ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset_2fa">
                    <button class="btn" data-confirm="Zwei-Faktor-Authentifizierung zurücksetzen? Bitte vorher die Identität prüfen (z. B. Rückruf).">2FA zurücksetzen</button></form>
                <?php endif; ?>
                <?php if ($target['locked_until'] && strtotime($target['locked_until']) > time()): ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="unlock">
                    <button class="btn">Sperre aufheben</button></form>
                <?php endif; ?>
            </div>
            <?php if ($target['totp_reset_requested_at']): ?>
                <p class="alert info small">Zurücksetzen der 2FA beantragt am <?= fmt_dt($target['totp_reset_requested_at']) ?>. Bitte Identität prüfen (z. B. telefonischer Rückruf).</p>
            <?php endif; ?>
            <?php if (!is_superadmin()): ?><p class="muted small">Die Zwei-Faktor-Anmeldung kann nur der Superadmin zurücksetzen.</p><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($target && !$isSelf && is_superadmin() && $target['role'] === 'admin'): ?>
        <div class="card danger-zone">
            <h2>Superadmin-Bereich</h2>
            <?php if ((int)$target['active']): ?>
            <form method="post" class="form inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="transfer_superadmin">
                <label class="grow">Superadmin-Rolle an <?= e($target['name']) ?> übertragen – zur Bestätigung Ihr Passwort
                    <input type="password" name="current_password" required autocomplete="current-password"></label>
                <button class="btn" data-confirm="Superadmin-Rolle an <?= e($target['name']) ?> übertragen? Sie werden dadurch Verwaltungsadmin.">Rolle übertragen</button>
            </form>
            <?php endif; ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <button class="btn danger" data-confirm="Konto von <?= e($target['name']) ?> endgültig löschen? Dies kann nicht rückgängig gemacht werden.">Administrator löschen</button></form>
        </div>
        <?php endif; ?>
    <?php });
}

/** Kurzbeschreibung der Rechte eines Verwaltungsadmins */
function admin_rights_summary(array $u): string
{
    $u['role'] = 'admin';
    $perms = admin_perms_of($u);
    $ids = admin_company_ids($u);
    $firms = $ids === null ? 'alle Firmen' : (count($ids) === 1 ? '1 Firma' : count($ids) . ' Firmen');
    if ($perms === null) return 'alle Bereiche bearbeiten · ' . $firms;
    $edit = count(array_filter($perms, fn($v) => (int)$v === PERM_EDIT));
    $view = count(array_filter($perms, fn($v) => (int)$v === PERM_VIEW));
    $parts = [];
    if ($edit) $parts[] = $edit . ' Bereich' . ($edit === 1 ? '' : 'e') . ' bearbeiten';
    if ($view) $parts[] = $view . ' ansehen';
    return ($parts ? implode(', ', $parts) : 'keine Bereiche') . ' · ' . $firms;
}

/** Eingabefelder für die Rechte eines Verwaltungsadmins (im Formular „Benutzer bearbeiten“) */
function render_admin_rights_fields(array $data): void
{
    $perms = admin_perms_of(['role' => 'admin'] + $data);
    $full = $perms === null;
    $ids = admin_company_ids(['role' => 'admin'] + $data);
    $companies = rows('SELECT id, name, active FROM companies ORDER BY active DESC, name'); ?>
    <fieldset class="rights">
        <legend>Rechte</legend>
        <label class="check"><input type="radio" name="perm_mode" value="full" <?= $full ? 'checked' : '' ?>> <strong>Uneingeschränkt</strong> – darf alle Bereiche ansehen und bearbeiten</label>
        <label class="check"><input type="radio" name="perm_mode" value="custom" <?= $full ? '' : 'checked' ?>> <strong>Eingeschränkt</strong> – Rechte je Bereich festlegen</label>
        <div class="rights-detail" data-show-if="perm_mode=custom">
            <div class="btn-row rights-presets">
                <span class="muted small">Schnellauswahl:</span>
                <button type="button" class="btn small" data-perm-preset="2">alles bearbeiten</button>
                <button type="button" class="btn small" data-perm-preset="1">alles nur ansehen</button>
                <button type="button" class="btn small" data-perm-preset="0">nichts</button>
            </div>
            <table class="table compact rights-table">
                <thead><tr><th>Bereich</th><?php foreach (PERM_LABELS as $lv => $lbl): ?><th class="center"><?= e($lbl) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach (ADMIN_AREAS as $area => [$name, $hint]):
                    $cur = $full ? PERM_EDIT : (int)($perms[$area] ?? PERM_NONE);
                    $maxLv = in_array($area, ['audit', 'updates'], true) ? PERM_VIEW : PERM_EDIT;
                    if ($cur > $maxLv) $cur = $maxLv; ?>
                    <tr><td><strong><?= e($name) ?></strong><br><span class="muted small"><?= e($hint) ?></span></td>
                        <?php foreach (PERM_LABELS as $lv => $lbl): ?>
                            <td class="center"><?php if ($lv <= $maxLv): ?><input type="radio" name="perm[<?= e($area) ?>]" value="<?= $lv ?>" <?= $cur === $lv ? 'checked' : '' ?> aria-label="<?= e($name . ': ' . $lbl) ?>"><?php else: ?><span class="muted">–</span><?php endif; ?></td>
                        <?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <h3>Firmen</h3>
        <label class="check"><input type="radio" name="company_mode" value="all" <?= $ids === null ? 'checked' : '' ?>> Alle Firmen (auch neu hinzukommende)</label>
        <label class="check"><input type="radio" name="company_mode" value="some" <?= $ids !== null ? 'checked' : '' ?>> Nur ausgewählte Firmen</label>
        <div data-show-if="company_mode=some">
            <input type="search" class="filter-input" placeholder="Firmen filtern …" data-filter-list="#rights-companies">
            <div class="pick-list" id="rights-companies">
                <?php foreach ($companies as $c): ?>
                    <label class="check"><input type="checkbox" name="companies[]" value="<?= (int)$c['id'] ?>" <?= $ids !== null && in_array((int)$c['id'], $ids, true) ? 'checked' : '' ?>> <?= e($c['name']) ?><?= (int)$c['active'] ? '' : ' <span class="tag">inaktiv</span>' ?></label>
                <?php endforeach; ?>
            </div>
            <p class="muted small">Mit ausgewählten Firmen sieht der Administrator nur deren Daten. Firmenübergreifendes (neue Firmen, eigene Apps, zentrales Postfach, Portal-Sicherungen, Katalogeinträge für alle Firmen) bleibt Administratoren mit Zugriff auf alle Firmen vorbehalten.</p>
        </div>
    </fieldset>
<?php }
