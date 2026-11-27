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
        $providers = [];
        $title = t('Benutzer: Administratoren (Dienstleister)');
    } else {
        $companyId = context_company_id();
        if (!$companyId) {
            flash('info', t('Bitte legen Sie zuerst eine Firma an.'));
            redirect('companies');
        }
        require_company_access($companyId);
        $list = rows('SELECT * FROM users WHERE company_id = ? ORDER BY active DESC, name', [$companyId]);
        $providers = users_company_providers($companyId);
        $cname = row('SELECT name FROM companies WHERE id = ?', [$companyId])['name'] ?? '';
        $title = t('Benutzer: ') . $cname;
    }
    render_page($title, function () use ($u, $list, $companyId, $showAdmins, $providers) {
        if (is_staff_role($u['role'])) {
            echo '<div class="toolbar">' . ($showAdmins ? '' : company_switcher($companyId, 'users'));
            echo $showAdmins
                ? '<a class="btn" href="' . e(url('users')) . ('">' . e(t('Firmenbenutzer anzeigen')) . '</a>')
                : '<a class="btn" href="' . e(url('users', ['admins' => 1])) . ('">' . e(t('Administratoren anzeigen')) . '</a>');
            echo '</div>';
        }
        $newParams = $showAdmins ? ['new' => 1, 'admin' => 1] : ['new' => 1, 'company' => $companyId];
        $canCreate = !$showAdmins || is_superadmin(); ?>
        <?php if ($showAdmins): ?>
            <p class="muted"><?= e(t('Superadmins verwalten die Administratoren: Nur sie dürfen Verwaltungsadmins anlegen, deren Rolle ändern, sie deaktivieren oder löschen. Verwaltungsadmins verwalten alle Firmen, Benutzer der Firmen, Apps und Aufträge.')) ?></p>
        <?php endif; ?>
        <?php if ($canCreate): ?><p><a class="btn primary" href="<?= e(url('user_edit', $newParams)) ?>"><?= e($showAdmins ? t('+ Neuen Administrator anlegen') : t('+ Neuen Benutzer anlegen')) ?></a></p><?php endif; ?>
        <div class="card">
            <table class="table">
                <thead><tr><th><?= e(t('Name')) ?></th><th><?= e(t('E-Mail')) ?></th><th><?= e(t('Rolle')) ?></th><th>2FA</th><th><?= e(t('Letzte Anmeldung')) ?></th><th><?= e(t('Status')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $row): ?>
                    <tr class="<?= (int)$row['active'] ? '' : 'inactive' ?>">
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['email']) ?></td>
                        <td><?= e(role_label($row['role'])) ?><?php if ($row['role'] === 'admin'): ?><br><span class="muted small"><?= e(admin_rights_summary($row)) ?></span><?php endif; ?></td>
                        <td><?= (int)$row['totp_enabled'] ? t('✓ eingerichtet') : ('<span class="muted">' . e(t('bei nächster Anmeldung')) . '</span>') ?><?= $row['totp_reset_requested_at'] ? (' <span class="badge st-rueckfrage">' . e(t('Reset beantragt')) . '</span>') : '' ?><?= (int)$row['email_verified'] ? '' : (' <span class="badge">' . e(t('E-Mail unbestätigt')) . '</span>') ?></td>
                        <td><?= fmt_dt($row['last_login']) ?></td>
                        <td><?= (int)$row['active'] ? e(t('aktiv')) : e(t('deaktiviert')) ?><?= $row['locked_until'] && strtotime($row['locked_until']) > time() ? (' · <strong>' . e(t('gesperrt')) . '</strong>') : '' ?></td>
                        <td class="actions"><?php if (!is_staff_role($row['role']) || is_superadmin()): ?><a href="<?= e(url('user_edit', ['id' => $row['id']])) ?>"><?= e(t('Bearbeiten')) ?></a><?php elseif ((int)$row['id'] === $u['id']): ?><a href="<?= e(url('account')) ?>"><?= e(t('Mein Konto')) ?></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($providers as $p): ?>
                    <tr class="<?= (int)$p['active'] ? '' : 'inactive' ?>">
                        <td><?= e($p['name']) ?></td>
                        <td><?= e($p['email']) ?></td>
                        <td><?= e(t('Externer Dienstleister')) ?><br><span class="muted small"><?= e((int)$p['see_all'] ? t('sieht alle Tickets') : t('sieht übergebene Tickets')) ?></span></td>
                        <td><?= $p['user_id'] ? ((int)$p['totp_enabled'] ? t('✓ eingerichtet') : ('<span class="muted">' . e(t('bei nächster Anmeldung')) . '</span>')) : ('<span class="muted">' . e(t('nur per E-Mail')) . '</span>') ?></td>
                        <td><?= $p['user_id'] ? fmt_dt($p['last_login']) : '–' ?></td>
                        <td><?= (int)$p['active'] ? e(t('aktiv')) : e(t('deaktiviert')) ?></td>
                        <td class="actions"><a href="<?= e(url('provider_edit', ['id' => $p['id']] + (is_admin() ? ['company' => $companyId] : []))) ?>"><?= e(t('Bearbeiten')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$list && !$providers): ?><tr><td colspan="7" class="muted"><?= e(t('Noch keine Benutzer.')) ?></td></tr><?php endif; ?>
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
            abort(403, t('Administratoren können nur von einem Superadmin bearbeitet werden.'));
        }
        if ($target['company_id']) require_company_access((int)$target['company_id']);
        $companyId = $target['company_id'] ? (int)$target['company_id'] : null;
    } else {
        if (is_staff_role($me['role']) && isset($_GET['admin'])) {
            if ($me['role'] !== 'superadmin') abort(403, t('Nur ein Superadmin darf Administratoren anlegen.'));
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
    // Externe Dienstleister (App „Tickets“): beim Anlegen als Rolle wählbar, gespeichert als Dienstleister der Firma
    $providerRole = !$target && $companyId && company_has_app($companyId, TICKETS_APP);
    if ($providerRole) $allowedRoles[] = 'dienstleister';
    $data = $target ?? ['name' => '', 'email' => '', 'role' => $allowedRoles[0], 'active' => 1, 'lang' => ''];
    // Gruppen (Abteilungen) des Kontos, z. B. für die Ticket-Queues
    // Gruppen (Abteilungen) pflegt man unter Einstellungen → Gruppen; das Formular ändert sie nicht mehr
    $groupDeps = [];
    $groupIds = array_map('intval', array_column($groupDeps, 'id'));
    $userGroups = $target && $groupDeps ? array_map('intval', array_column(rows('SELECT department_id FROM user_departments WHERE user_id = ?', [$target['id']]), 'department_id')) : [];
    $errors = [];
    $newPassword = null;

    if (is_post()) {
        require_post_csrf();
        $action = post('action', 'save');
        if ($action === 'save' && $providerRole && post('role') === 'dienstleister') {
            $_POST['provider_id'] = '0';
            ticket_providers_post($companyId, 'provider_save', fn () => redirect('users', is_staff_role($me['role']) ? ['company' => $companyId] : []));
        }
        if ($action === 'save') {
            $data['name'] = mb_substr(post('name'), 0, 190);
            $data['email'] = strtolower(mb_substr(post('email'), 0, 190));
            $role = post('role', $allowedRoles[0]);
            $data['role'] = in_array($role, $allowedRoles, true) ? $role : $allowedRoles[0];
            $data['active'] = post('active') === '1' ? 1 : 0;
            $data['lang'] = lang_valid(post('lang')) ?? '';
            if ($isSelf) { $data['active'] = 1; $data['role'] = $target['role']; }
            // Der Superadmin bleibt immer aktiv und behält seine Rolle (Wechsel nur per Übertragung)
            if ($target && $target['role'] === 'superadmin') { $data['active'] = 1; $data['role'] = 'superadmin'; }
            if ($data['name'] === '') $errors[] = t('Bitte einen Namen angeben.');
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = t('Bitte eine gültige E-Mail-Adresse angeben.');
            $dup = row('SELECT id FROM users WHERE email = ? AND id <> ?', [$data['email'], $id ?: 0]);
            if ($dup) $errors[] = t('Diese E-Mail-Adresse wird bereits verwendet.');
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
                    if (!$ids) $errors[] = t('Bitte mindestens eine Firma auswählen oder „alle Firmen“ wählen.');
                    $permSave['admin_companies'] = implode(',', $ids);
                } else {
                    $permSave['admin_companies'] = '';
                }
                $data = array_merge($data, $permSave);
            }
            if (!$errors) {
                $save = ['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'], 'active' => $data['active'], 'lang' => $data['lang']] + $permSave;
                if ($target) {
                    update('users', $id, $save);
                    if ($permSave && (($target['admin_perms'] ?? null) !== ($save['admin_perms'] ?? null) || ($target['admin_companies'] ?? '') !== ($save['admin_companies'] ?? ''))) {
                        audit('admin_rights_changed', $save['email'] . ': ' . admin_rights_summary($save), null);
                    }
                    audit('user_updated', $save['email'] . ($target['role'] !== $save['role'] ? t(' (Rolle: ') . ROLES[$target['role']] . ' → ' . ROLES[$save['role']] . ')' : ''), $companyId);
                    flash('success', t('Der Benutzer wurde gespeichert.'));
                } else {
                    $newPassword = random_password();
                    $save += ['company_id' => $companyId, 'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        'must_change_password' => 1, 'created_at' => now()];
                    $id = insert('users', $save);
                    audit('user_created', $save['email'], $companyId);
                    $target = row('SELECT * FROM users WHERE id = ?', [$id]);
                    $data = $target;
                }
                if ($groupDeps) {
                    $userGroups = array_values(array_intersect(array_map('intval', (array)($_POST['groups'] ?? [])), $groupIds));
                    user_departments_set_for_user((int)$id, $userGroups, $groupIds);
                }
            }
        } elseif ($target && !$isSelf && $action === 'reset_password') {
            $newPassword = random_password();
            update('users', $id, ['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'must_change_password' => 1, 'failed_attempts' => 0, 'locked_until' => null]);
            audit('user_password_reset', $target['email'], $companyId);
        } elseif ($target && !$isSelf && $action === 'reset_2fa') {
            if ($me['role'] !== 'superadmin') abort(403, t('Die Zwei-Faktor-Anmeldung kann nur der Superadmin zurücksetzen.'));
            update('users', $id, ['totp_enabled' => 0, 'totp_secret' => null, 'totp_last_step' => 0, 'totp_reset_requested_at' => null]);
            audit('user_2fa_reset', $target['email'], $companyId);
            send_mail_t([$target['email']], fn() => [t('Zwei-Faktor-Anmeldung zurückgesetzt'), t('Guten Tag ') . $target['name'] . t(",\n\nIhre Zwei-Faktor-Anmeldung wurde zurückgesetzt. Melden Sie sich mit Ihrem Passwort an und richten Sie die Authenticator-App neu ein:\n\n")
                . portal_link('login') . t("\n\nWaren Sie das nicht? Bitte melden Sie sich sofort bei Ihrem IT-Dienstleister.")], $companyId);
            flash('success', t('Die Zwei-Faktor-Authentifizierung wurde zurückgesetzt. Der Benutzer richtet sie bei der nächsten Anmeldung neu ein.'));
            redirect('user_edit', ['id' => $id]);
        } elseif ($target && !$isSelf && $action === 'delete') {
            // Löschen nur für Administratoren-Konten und nur durch den Superadmin (Kundenkonten werden deaktiviert)
            if ($me['role'] !== 'superadmin' || !is_staff_role($target['role'])) abort(403);
            if ($target['role'] === 'superadmin') abort(400);
            q('DELETE FROM users WHERE id = ?', [$id]);
            audit('user_deleted', $target['name'] . ' <' . $target['email'] . '>, ' . ROLES[$target['role']], null);
            flash('success', t('Das Konto von ') . $target['name'] . t(' wurde gelöscht.'));
            redirect('users', ['admins' => 1]);
        } elseif ($target && !$isSelf && $action === 'transfer_superadmin') {
            if ($me['role'] !== 'superadmin' || $target['role'] !== 'admin' || !(int)$target['active']) abort(403);
            if (!password_verify((string)($_POST['current_password'] ?? ''), $me['password_hash'])) {
                $errors[] = t('Ihr Passwort ist falsch. Die Superadmin-Rolle wurde nicht übertragen.');
            } else {
                db()->beginTransaction();
                update('users', $me['id'], ['role' => 'admin']);
                update('users', $id, ['role' => 'superadmin']);
                db()->commit();
                audit('superadmin_transferred', 'Superadmin-Rolle übertragen an ' . $target['name'] . ' <' . $target['email'] . '>', null);
                flash('success', t('Die Superadmin-Rolle wurde an ') . $target['name'] . t(' übertragen. Sie sind jetzt Verwaltungsadmin.'));
                redirect('users', ['admins' => 1]);
            }
        } elseif ($target && $action === 'verify_email') {
            update('users', $id, ['email_verified' => 1]);
            q("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'verify'", [$id]);
            audit('email_verified_by_admin', $target['email'], $companyId);
            flash('success', t('Die E-Mail-Adresse wurde als bestätigt markiert. Der Benutzer kann sich jetzt anmelden.'));
            redirect('user_edit', ['id' => $id]);
        } elseif ($target && $action === 'resend_verification') {
            send_verification_mail($target);
            audit('verification_resent', $target['email'], $companyId);
            flash('success', t('Der Bestätigungslink wurde erneut an ') . $target['email'] . t(' gesendet. Kommt nichts an, prüfen Sie unter „E-Mail-Versand“ das Postfach und senden Sie eine Testmail.'));
            redirect('user_edit', ['id' => $id]);
        } elseif ($target && $action === 'unlock') {
            update('users', $id, ['failed_attempts' => 0, 'locked_until' => null]);
            audit('user_unlocked', $target['email'], $companyId);
            flash('success', t('Die Sperre wurde aufgehoben.'));
            redirect('user_edit', ['id' => $id]);
        }
        if (!$errors && !$newPassword) redirect('user_edit', ['id' => $id]);
    }

    $backParams = $companyId === null ? ['admins' => 1] : (is_staff_role($me['role']) ? ['company' => $companyId] : []);
    $showRights = $companyId === null && ($data['role'] ?? '') === 'admin' && $me['role'] === 'superadmin';
    render_page($target ? t('Benutzer bearbeiten') : t('Neuer Benutzer'), function () use ($data, $errors, $target, $allowedRoles, $isSelf, $newPassword, $backParams, $companyId, $showRights, $groupDeps, $userGroups) {
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>';
        if ($newPassword): ?>
            <div class="alert success">
                <p><strong><?= e(t('Startpasswort für')) ?> <?= e($data['email']) ?>:</strong></p>
                <p class="secret"><?= e($newPassword) ?></p>
                <p><?= e(t('Teilen Sie das Passwort auf sicherem Weg mit (z. B. telefonisch). Es wird nur jetzt angezeigt. Bei der ersten Anmeldung muss der Benutzer ein eigenes Passwort vergeben und die Authenticator-App einrichten.')) ?></p>
            </div>
        <?php endif; ?>
        <?php if ($companyId): ?>
            <p class="muted"><?= e(t('Firma:')) ?> <?= e(row('SELECT name FROM companies WHERE id = ?', [$companyId])['name'] ?? '') ?></p>
        <?php endif; ?>
        <form method="post" class="card form" <?= $target ? 'action="' . e(url('user_edit', ['id' => $target['id']])) . '"' : '' ?>>
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <div class="grid2">
                <label><?= e(t('Name *')) ?><input name="name" required maxlength="190" value="<?= e($data['name']) ?>"></label>
                <label><?= e(t('E-Mail-Adresse (Benutzername) *')) ?><input type="email" name="email" required maxlength="190" value="<?= e($data['email']) ?>"></label>
                <label><?= e(t('Rolle')) ?>
                    <select name="role" <?= $isSelf || count($allowedRoles) === 1 ? 'disabled' : '' ?>>
                        <?php foreach ($allowedRoles as $r): ?>
                            <option value="<?= e($r) ?>" <?= $data['role'] === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label data-show-if="role!=dienstleister"><?= e(t('Sprache')) ?>
                    <?= lang_select('lang', lang_valid($data['lang'] ?? null), $companyId
                        ? t('Sprache der Firma ({x})', ['x' => LANGS[company_lang($companyId)]])
                        : t('Standard ({x})', ['x' => LANGS[DEFAULT_LANG]])) ?>
                    <span class="muted small"><?= e(t('Für Oberfläche und E-Mails dieses Benutzers.')) ?></span>
                </label>
            </div>
            <?php if ($companyId === null): ?>
            <p class="muted small"><?= e(t('Was ein Verwaltungsadmin darf, legen Sie unten unter „Rechte“ fest. Es gibt genau einen Superadmin; er darf immer alles, verwaltet die Administratoren-Konten und kann seine Rolle an einen Verwaltungsadmin übertragen.')) ?></p>
            <?php if ($showRights) render_admin_rights_fields($data); ?>
            <?php else: ?>
            <p class="muted small" data-show-if="role!=dienstleister"><?= e(t('Benutzer arbeiten mit den freigeschalteten Apps nach den Rechten ihrer Abteilungen. Administratoren pflegen zusätzlich Firmenprofil, Benutzer, Rechte und die Einstellungen der Firma.')) ?></p>
            <?php if (in_array('dienstleister', $allowedRoles, true)): ?>
            <fieldset data-show-if="role=dienstleister"><legend><?= e(t('Externer Dienstleister')) ?></legend>
                <p class="muted small"><?= e(t('Z. B. der Kopierer-Service. Bearbeiter können ihm Tickets übergeben; er sieht die Beschreibung und die öffentlichen Nachrichten, nie interne Notizen. Mit Portal-Zugang arbeitet er im Portal (auch für mehrere Firmen mit einem Zugang), sonst nur per E-Mail.')) ?></p>
                <label class="check"><input type="checkbox" name="portal" value="1" checked> <?= e(t('Portal-Zugang (der Dienstleister bekommt eine Einladung mit Zwei-Faktor-Anmeldung)')) ?></label>
                <label class="check"><input type="checkbox" name="see_all" value="1"> <?= e(t('Darf alle Tickets der Firma sehen (sonst nur die übergebenen)')) ?></label>
                <label><?= e(t('Notiz')) ?> <input name="notes" maxlength="500" placeholder="<?= e(t('z. B. Vertragsnummer, Telefon')) ?>"></label>
            </fieldset>
            <?php endif; ?>
            <p class="muted small" data-show-if="role!=dienstleister"><?= e(t('Gruppen (Abteilungen) legen Sie unter Einstellungen → Gruppen fest.')) ?></p>
            <?php endif; ?>
            <label class="check"><input type="checkbox" name="active" value="1" <?= (int)$data['active'] ? 'checked' : '' ?> <?= $isSelf || ($data['role'] ?? '') === 'superadmin' ? 'disabled' : '' ?>> <?= e(t('Zugang aktiv')) ?></label>
            <div class="buttons">
                <button class="btn primary"><?= $target ? t('Speichern') : t('Benutzer anlegen') ?></button>
                <a class="btn" href="<?= e(url('users', $backParams)) ?>"><?= e(t('Zurück zur Liste')) ?></a>
            </div>
        </form>
        <?php if ($target && !(int)$target['email_verified']): ?>
        <div class="card review">
            <h2><?= e(t('E-Mail-Adresse noch nicht bestätigt')) ?></h2>
            <p class="muted"><?= e(t('Ohne Bestätigung ist keine Anmeldung möglich. Ist die Bestätigungsmail nicht angekommen, können Sie sie erneut senden oder die Adresse selbst bestätigen.')) ?></p>
            <div class="buttons">
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="resend_verification">
                    <button class="btn"><?= e(t('Bestätigungslink erneut senden')) ?></button></form>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="verify_email">
                    <button class="btn primary" data-confirm="<?= e(t('E-Mail-Adresse {x} als bestätigt markieren?', ['x' => $target['email']])) ?>"><?= e(t('Als bestätigt markieren')) ?></button></form>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($target && !$isSelf): ?>
        <div class="card">
            <h2><?= e(t('Zugang zurücksetzen')) ?></h2>
            <div class="buttons">
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password">
                    <button class="btn" data-confirm="<?= e(t('Neues Startpasswort erzeugen? Das bisherige Passwort wird ungültig.')) ?>"><?= e(t('Neues Startpasswort')) ?></button></form>
                <?php if (is_superadmin()): ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset_2fa">
                    <button class="btn" data-confirm="<?= e(t('Zwei-Faktor-Authentifizierung zurücksetzen? Bitte vorher die Identität prüfen (z. B. Rückruf).')) ?>"><?= e(t('2FA zurücksetzen')) ?></button></form>
                <?php endif; ?>
                <?php if ($target['locked_until'] && strtotime($target['locked_until']) > time()): ?>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="unlock">
                    <button class="btn"><?= e(t('Sperre aufheben')) ?></button></form>
                <?php endif; ?>
            </div>
            <?php if ($target['totp_reset_requested_at']): ?>
                <p class="alert info small"><?= e(t('Zurücksetzen der 2FA beantragt am')) ?> <?= fmt_dt($target['totp_reset_requested_at']) ?><?= e(t('. Bitte Identität prüfen (z. B. telefonischer Rückruf).')) ?></p>
            <?php endif; ?>
            <?php if (!is_superadmin()): ?><p class="muted small"><?= e(t('Die Zwei-Faktor-Anmeldung kann nur der Superadmin zurücksetzen.')) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($target && !$isSelf && is_superadmin() && $target['role'] === 'admin'): ?>
        <div class="card danger-zone">
            <h2><?= e(t('Superadmin-Bereich')) ?></h2>
            <?php if ((int)$target['active']): ?>
            <form method="post" class="form inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="transfer_superadmin">
                <label class="grow"><?= e(t('Superadmin-Rolle an')) ?> <?= e($target['name']) ?> <?= e(t('übertragen – zur Bestätigung Ihr Passwort')) ?>
                    <input type="password" name="current_password" required autocomplete="current-password"></label>
                <button class="btn" data-confirm="<?= e(t('Superadmin-Rolle an {x} übertragen? Sie werden dadurch Verwaltungsadmin.', ['x' => $target['name']])) ?>"><?= e(t('Rolle übertragen')) ?></button>
            </form>
            <?php endif; ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <button class="btn danger" data-confirm="<?= e(t('Konto von {x} endgültig löschen? Dies kann nicht rückgängig gemacht werden.', ['x' => $target['name']])) ?>"><?= e(t('Administrator löschen')) ?></button></form>
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
    $firms = $ids === null ? t('alle Firmen') : (count($ids) === 1 ? t('1 Firma') : t('{n} Firmen', ['n' => count($ids)]));
    if ($perms === null) return t('alle Bereiche bearbeiten · ') . $firms;
    $edit = count(array_filter($perms, fn($v) => (int)$v === PERM_EDIT));
    $view = count(array_filter($perms, fn($v) => (int)$v === PERM_VIEW));
    $parts = [];
    if ($edit) $parts[] = $edit === 1 ? t('1 Bereich bearbeiten') : t('{n} Bereiche bearbeiten', ['n' => $edit]);
    if ($view) $parts[] = t('{n} ansehen', ['n' => $view]);
    return ($parts ? implode(', ', $parts) : t('keine Bereiche')) . ' · ' . $firms;
}

/** Eingabefelder für die Rechte eines Verwaltungsadmins (im Formular „Benutzer bearbeiten“) */
function render_admin_rights_fields(array $data): void
{
    $perms = admin_perms_of(['role' => 'admin'] + $data);
    $full = $perms === null;
    $ids = admin_company_ids(['role' => 'admin'] + $data);
    $companies = rows('SELECT id, name, active FROM companies ORDER BY active DESC, name'); ?>
    <fieldset class="rights">
        <legend><?= e(t('Rechte')) ?></legend>
        <label class="check"><input type="radio" name="perm_mode" value="full" <?= $full ? 'checked' : '' ?>> <strong><?= e(t('Uneingeschränkt')) ?></strong> <?= e(t('– darf alle Bereiche ansehen und bearbeiten')) ?></label>
        <label class="check"><input type="radio" name="perm_mode" value="custom" <?= $full ? '' : 'checked' ?>> <strong><?= e(t('Eingeschränkt')) ?></strong> <?= e(t('– Rechte je Bereich festlegen')) ?></label>
        <div class="rights-detail" data-show-if="perm_mode=custom">
            <div class="btn-row rights-presets">
                <span class="muted small"><?= e(t('Schnellauswahl:')) ?></span>
                <button type="button" class="btn small" data-perm-preset="2"><?= e(t('alles bearbeiten')) ?></button>
                <button type="button" class="btn small" data-perm-preset="1"><?= e(t('alles nur ansehen')) ?></button>
                <button type="button" class="btn small" data-perm-preset="0"><?= e(t('nichts')) ?></button>
            </div>
            <table class="table compact rights-table">
                <thead><tr><th><?= e(t('Bereich')) ?></th><?php foreach (PERM_LABELS as $lv => $lbl): ?><th class="center"><?= e(t($lbl)) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach (ADMIN_AREAS as $area => [$name, $hint]):
                    $cur = $full ? PERM_EDIT : (int)($perms[$area] ?? PERM_NONE);
                    $maxLv = in_array($area, ['audit', 'updates'], true) ? PERM_VIEW : PERM_EDIT;
                    if ($cur > $maxLv) $cur = $maxLv; ?>
                    <tr><td><strong><?= e(t($name)) ?></strong><br><span class="muted small"><?= e(t($hint)) ?></span></td>
                        <?php foreach (PERM_LABELS as $lv => $lbl): ?>
                            <td class="center"><?php if ($lv <= $maxLv): ?><input type="radio" name="perm[<?= e($area) ?>]" value="<?= $lv ?>" <?= $cur === $lv ? 'checked' : '' ?> aria-label="<?= e(t($name) . ': ' . t($lbl)) ?>"><?php else: ?><span class="muted">–</span><?php endif; ?></td>
                        <?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <h3><?= e(t('Firmen')) ?></h3>
        <label class="check"><input type="radio" name="company_mode" value="all" <?= $ids === null ? 'checked' : '' ?>> <?= e(t('Alle Firmen (auch neu hinzukommende)')) ?></label>
        <label class="check"><input type="radio" name="company_mode" value="some" <?= $ids !== null ? 'checked' : '' ?>> <?= e(t('Nur ausgewählte Firmen')) ?></label>
        <div data-show-if="company_mode=some">
            <input type="search" class="filter-input" placeholder="<?= e(t('Firmen filtern …')) ?>" data-filter-list="#rights-companies">
            <div class="pick-list" id="rights-companies">
                <?php foreach ($companies as $c): ?>
                    <label class="check"><input type="checkbox" name="companies[]" value="<?= (int)$c['id'] ?>" <?= $ids !== null && in_array((int)$c['id'], $ids, true) ? 'checked' : '' ?>> <?= e($c['name']) ?><?= (int)$c['active'] ? '' : (' <span class="tag">' . e(t('inaktiv')) . '</span>') ?></label>
                <?php endforeach; ?>
            </div>
            <p class="muted small"><?= e(t('Mit ausgewählten Firmen sieht der Administrator nur deren Daten. Firmenübergreifendes (neue Firmen, eigene Apps, zentrales Postfach, Portal-Sicherungen, Katalogeinträge für alle Firmen) bleibt Administratoren mit Zugriff auf alle Firmen vorbehalten.')) ?></p>
        </div>
    </fieldset>
<?php }

// ---------------------------------------------------------------------------
// Externe Dienstleister in der Benutzerverwaltung (Daten: ticket_providers, App „Tickets“)
// ---------------------------------------------------------------------------

/** Dienstleister einer Firma mit Angaben zum Portal-Zugang; leer ohne App „Tickets“ */
function users_company_providers(int $cid): array
{
    if (!company_has_app($cid, TICKETS_APP)) return [];
    return rows('SELECT p.*, u.last_login, u.totp_enabled, u.active AS user_active FROM ticket_providers p LEFT JOIN users u ON u.id = p.user_id
        WHERE p.company_id = ? ORDER BY p.active DESC, p.name', [$cid]);
}

function page_provider_edit(): void
{
    $me = require_manager();
    $id = query_int('id');
    $p = row('SELECT p.*, u.last_login, u.active AS user_active FROM ticket_providers p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$id]);
    if (!$p) abort(404);
    $cid = (int)$p['company_id'];
    if (!is_staff_role($me['role']) && $cid !== (int)$me['company_id']) abort(403);
    require_company_access($cid);
    $list = is_staff_role($me['role']) ? ['company' => $cid] : [];
    if (is_post()) {
        require_post_csrf();
        $action = (string)post('action');
        $_POST['provider_id'] = (string)$id;
        $back = $action === 'provider_delete' ? fn () => redirect('users', $list) : fn () => redirect('provider_edit', ['id' => $id] + $list);
        if (!ticket_providers_post($cid, $action, $back)) abort(400);
    }
    $open = (int)(row("SELECT COUNT(*) AS n FROM tickets WHERE provider_id = ? AND status = 'beim_dienstleister'", [$id])['n'] ?? 0);
    render_page(t('Externer Dienstleister: {x}', ['x' => $p['name']]), function () use ($p, $list, $open) { ?>
        <p><a href="<?= e(url('users', $list)) ?>"><?= e(t('Zurück zur Liste')) ?></a></p>
        <div class="card">
            <p class="muted small"><?= $p['user_id'] ? e(t('Portal-Zugang')) . ($p['last_login'] ? '' : ' (' . e(t('noch nicht angemeldet')) . ')') : e(t('nur per E-Mail')) ?>
                <?php if ($open): ?> · <?= e(t('{n} Tickets offen', ['n' => $open])) ?><?php endif; ?></p>
            <?php ticket_provider_form($p); ?>
            <div class="btn-row">
            <?php if ($p['user_id'] && !$p['last_login'] && (int)$p['user_active']): ?>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="provider_invite">
                    <button class="btn small"><?= e(t('Einladung erneut senden')) ?></button></form>
            <?php endif; ?>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="provider_delete">
                    <button class="btn small danger" data-confirm="<?= e(t('Dienstleister „{x}“ entfernen?', ['x' => $p['name']])) ?>"><?= e(t('Entfernen')) ?></button></form>
            </div>
        </div>
    <?php });
}
