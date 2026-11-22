<?php
/**
 * Selbstbedienung: Registrierung von Firmen, E-Mail-Bestätigung, Passwort vergessen,
 * Freischaltung durch den Dienstleister und Antrag auf Zurücksetzen der 2FA.
 */

// ---------------------------------------------------------------------------
// Helfer
// ---------------------------------------------------------------------------
function registration_enabled(): bool
{
    return (bool)cfg('registration.enabled', true);
}

/** Absoluter Link für E-Mails */
function portal_link(string $route, array $params = []): string
{
    // Trennzeichen fest auf "&" – manche Server stellen arg_separator.output auf "&amp;", das zerstört Links in Text-Mails
    return rtrim((string)cfg('base_url', ''), '/') . '/index.php?' . http_build_query(array_merge(['r' => $route], $params), '', '&');
}

function superadmin_emails(): array
{
    return array_column(rows("SELECT email FROM users WHERE role = 'superadmin' AND active = 1"), 'email');
}

/** Empfänger für neue Registrierungen: zentrale Admin-Adressen und der Superadmin */
function registration_admin_recipients(): array
{
    return array_merge((array)cfg('mail.admin_recipients', []), superadmin_emails());
}

/** Wie oft eine Aktion von dieser IP in den letzten $minutes Minuten protokolliert wurde */
function ip_action_count(string $action, int $minutes): int
{
    return (int)row('SELECT COUNT(*) AS n FROM audit_log WHERE action = ? AND ip = ? AND created_at > ?',
        [$action, client_ip(), date('Y-m-d H:i:s', time() - 60 * $minutes)])['n'];
}

function log_public(string $action, string $details, ?int $userId = null, ?int $companyId = null): void
{
    insert('audit_log', ['user_id' => $userId, 'company_id' => $companyId, 'action' => $action,
        'details' => mb_substr($details, 0, 500), 'ip' => client_ip(), 'created_at' => now()]);
}

/** Erzeugt einen Einmal-Link (gespeichert wird nur der Hash) */
function create_user_token(int $userId, string $purpose, int $ttlMinutes): string
{
    // Passwort-Links: nur der neueste gilt. Bestätigungslinks: alle bleiben bis zum Ablauf gültig,
    // damit auch der Link aus einer älteren Mail funktioniert
    if ($purpose === 'reset') q('DELETE FROM user_tokens WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
    else q('DELETE FROM user_tokens WHERE user_id = ? AND purpose = ? AND expires_at < ?', [$userId, $purpose, now()]);
    $token = bin2hex(random_bytes(32));
    insert('user_tokens', ['user_id' => $userId, 'purpose' => $purpose, 'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + 60 * $ttlMinutes), 'used_at' => null, 'created_at' => now()]);
    return $token;
}

/** Gültiger, unbenutzter Token oder null */
function find_user_token(string $token, string $purpose): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    return row('SELECT t.*, u.email, u.name, u.active, u.company_id FROM user_tokens t JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.purpose = ? AND t.used_at IS NULL AND t.expires_at > ?',
        [hash('sha256', $token), $purpose, now()]);
}

/** Wurde für den Benutzer in den letzten $minutes Minuten schon ein Link verschickt? */
function token_recently_sent(int $userId, string $purpose, int $minutes): bool
{
    return (bool)row('SELECT id FROM user_tokens WHERE user_id = ? AND purpose = ? AND created_at > ?',
        [$userId, $purpose, date('Y-m-d H:i:s', time() - 60 * $minutes)]);
}

function send_verification_mail(array $u): void
{
    $token = create_user_token((int)$u['id'], 'verify', 60 * 48);
    send_mail_t([$u['email']], fn() => [t('Bitte bestätigen Sie Ihre E-Mail-Adresse'), t('Guten Tag ') . $u['name'] . t(",\n\nbitte bestätigen Sie Ihre E-Mail-Adresse für das ") . t(cfg('app_name', 'Kundenportal')) . t(" über diesen Link (48 Stunden gültig):\n\n")
        . portal_link('verify_email', ['token' => $token])
        . t("\n\nFalls Sie sich nicht registriert haben, können Sie diese Nachricht ignorieren.")]);
}

function app_names_by_ids(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    return array_column(rows("SELECT name FROM apps WHERE id IN ($in) ORDER BY sort, name", $ids), 'name');
}

function requested_app_ids(array $company): array
{
    return array_values(array_filter(array_map('intval', explode(',', (string)$company['requested_apps']))));
}

// ---------------------------------------------------------------------------
// Registrierung
// ---------------------------------------------------------------------------
function page_register(): void
{
    if (!registration_enabled()) abort(404);
    if (current_user()) redirect('home');
    $apps = rows('SELECT * FROM apps WHERE active = 1 ORDER BY sort, name');
    $errors = [];
    $done = false;
    $f = ['company' => '', 'street' => '', 'zip' => '', 'city' => '', 'phone' => '', 'name' => '', 'email' => '', 'contact_phone' => ''];
    $wantApps = [];

    if (is_post()) {
        require_post_csrf();
        foreach ($f as $k => $_) $f[$k] = mb_substr(post($k), 0, 190);
        $f['email'] = strtolower($f['email']);
        $pw = (string)($_POST['password'] ?? '');
        $validIds = array_map('intval', array_column($apps, 'id'));
        $wantApps = array_values(array_intersect($validIds, array_map('intval', (array)($_POST['apps'] ?? []))));
        $tooFast = time() - (int)($_SESSION['register_form_at'] ?? 0) < 3;

        if (post('website') !== '' || $tooFast) {
            // Bot: so tun, als ob alles geklappt hätte
            $done = true;
        } elseif (ip_action_count('registration', 60) >= (int)cfg('registration.max_per_hour', 5)) {
            $errors[] = t('Zu viele Registrierungen von Ihrem Anschluss. Bitte versuchen Sie es später erneut.');
        } else {
            if ($f['company'] === '') $errors[] = t('Bitte den Firmennamen angeben.');
            if ($f['name'] === '') $errors[] = t('Bitte Ihren Namen angeben.');
            if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = t('Bitte eine gültige E-Mail-Adresse angeben.');
            if ($msg = validate_password($pw)) $errors[] = $msg;
            elseif ($pw !== ($_POST['password2'] ?? '')) $errors[] = t('Die Passwörter stimmen nicht überein.');
            if (!$wantApps) $errors[] = t('Bitte wählen Sie mindestens ein Modul aus.');
            if (post('privacy') !== '1') $errors[] = t('Bitte bestätigen Sie die Datenschutzhinweise.');
        }

        if (!$errors && !$done) {
            $existing = row('SELECT id, name FROM users WHERE email = ?', [$f['email']]);
            if ($existing) {
                // keine Auskunft, ob eine Adresse registriert ist – stattdessen Hinweis an die Adresse
                send_mail_t([$f['email']], fn() => [t('Registrierung im ') . t(cfg('app_name', 'Kundenportal')), t("Guten Tag,\n\nmit Ihrer E-Mail-Adresse wurde gerade eine Registrierung versucht. Für diese Adresse besteht bereits ein Zugang.\n\n")
                    . t("Passwort vergessen? Hier können Sie es zurücksetzen:\n") . portal_link('forgot_password')
                    . t("\n\nWenn Sie das nicht waren, können Sie diese Nachricht ignorieren.")]);
                log_public('registration', t('Registrierung mit vorhandener Adresse ') . $f['email'], (int)$existing['id']);
            } else {
                db()->beginTransaction();
                $cid = insert('companies', ['name' => $f['company'], 'short_name' => '', 'street' => $f['street'], 'zip' => $f['zip'],
                    'city' => $f['city'], 'phone' => $f['phone'], 'email' => '', 'contact_name' => $f['name'],
                    'contact_email' => $f['email'], 'contact_phone' => $f['contact_phone'], 'email_domain' => '',
                    'it_notes' => '', 'active' => 1, 'registration_status' => 'pending',
                    'requested_apps' => implode(',', $wantApps), 'registered_at' => now(), 'lang' => visitor_lang(),
                    'created_at' => now(), 'updated_at' => now()]);
                $uid = insert('users', ['company_id' => $cid, 'email' => $f['email'], 'name' => $f['name'],
                    'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'firma_admin', 'active' => 1,
                    'must_change_password' => 0, 'email_verified' => 0, 'created_at' => now()]);
                db()->commit();
                log_public('registration', t('Selbstregistrierung: ') . $f['company'] . ' / ' . $f['email'], $uid, $cid);

                $modules = implode(', ', app_names_by_ids($wantApps));
                $token = create_user_token($uid, 'verify', 60 * 48);
                send_mail_t([$f['email']], fn() => [t('Ihre Registrierung im ') . t(cfg('app_name', 'Kundenportal')), t('Guten Tag ') . $f['name'] . t(",\n\nvielen Dank für Ihre Registrierung der Firma „{x}“.\n\n", ['x' => $f['company']])
                    . t("1. Bitte bestätigen Sie Ihre E-Mail-Adresse über diesen Link (48 Stunden gültig):\n") . portal_link('verify_email', ['token' => $token]) . "\n\n"
                    . t("2. Danach melden Sie sich an und richten die Zwei-Faktor-Anmeldung mit einer Authenticator-App ein.\n\n")
                    . t("3. Wir prüfen Ihre Anfrage und schalten die gewünschten Module frei. Sie erhalten dazu eine weitere E-Mail.\n\n")
                    . t('Angefragte Module: ') . $modules]);
                send_mail_t(registration_admin_recipients(), fn() => [t('Neue Registrierung: ') . $f['company'], t("Eine Firma hat sich im Portal registriert und wartet auf Freischaltung.\n\n")
                    . t('Firma: ') . $f['company'] . t("\nAnschrift: ") . trim($f['street'] . ', ' . $f['zip'] . ' ' . $f['city'], ', ') . "\n"
                    . t('Telefon: ') . $f['phone'] . t("\nAnsprechpartner/in: ") . $f['name'] . " <" . $f['email'] . ">" . ($f['contact_phone'] ? ', ' . $f['contact_phone'] : '') . "\n"
                    . t('Angefragte Module: ') . $modules . t("\n\nPrüfen und freischalten:\n") . portal_link('company_edit', ['id' => $cid])]);
            }
            $done = true;
        }
    }
    $_SESSION['register_form_at'] = time();

    render_page($done ? t('Registrierung eingegangen') : t('Firma registrieren'), function () use ($done, $errors, $f, $apps, $wantApps) {
        if ($done): ?>
            <div class="card narrow">
                <p><strong><?= e(t('Vielen Dank!')) ?></strong> <?= e(t('Wir haben Ihnen eine E-Mail geschickt.')) ?></p>
                <ol class="steps">
                    <li><?= e(t('Bestätigen Sie Ihre E-Mail-Adresse über den Link in der E-Mail.')) ?></li>
                    <li><?= e(t('Melden Sie sich an und richten Sie die Zwei-Faktor-Anmeldung ein.')) ?></li>
                    <li><?= e(t('Sobald wir Ihre Module freigeschaltet haben, erhalten Sie eine weitere E-Mail.')) ?></li>
                </ol>
                <p><a class="btn primary" href="<?= e(url('login')) ?>"><?= e(t('Zur Anmeldung')) ?></a></p>
            </div>
        <?php return; endif;
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>'; ?>
        <form method="post" class="card form">
            <?= csrf_field() ?>
            <div class="hp" aria-hidden="true"><label><?= e(t('Website')) ?><input name="website" tabindex="-1" autocomplete="off"></label></div>
            <h2><?= e(t('Firma')) ?></h2>
            <div class="grid2">
                <label><?= e(t('Firmenname *')) ?><input name="company" required maxlength="190" value="<?= e($f['company']) ?>"></label>
                <label><?= e(t('Telefon')) ?><input name="phone" maxlength="60" value="<?= e($f['phone']) ?>"></label>
                <label><?= e(t('Straße und Hausnummer')) ?><input name="street" maxlength="190" value="<?= e($f['street']) ?>"></label>
                <div class="grid2 zipcity">
                    <label><?= e(t('PLZ')) ?><input name="zip" maxlength="20" value="<?= e($f['zip']) ?>"></label>
                    <label><?= e(t('Ort')) ?><input name="city" maxlength="120" value="<?= e($f['city']) ?>"></label>
                </div>
            </div>
            <h2><?= e(t('Ihr Zugang (Firmen-Administrator)')) ?></h2>
            <div class="grid2">
                <label><?= e(t('Ihr Name *')) ?><input name="name" required maxlength="190" autocomplete="name" value="<?= e($f['name']) ?>"></label>
                <label><?= e(t('Ihre Telefonnummer')) ?><input name="contact_phone" maxlength="60" autocomplete="tel" value="<?= e($f['contact_phone']) ?>"></label>
                <label><?= e(t('E-Mail-Adresse (Benutzername) *')) ?><input type="email" name="email" required maxlength="190" autocomplete="username" value="<?= e($f['email']) ?>"></label>
                <span></span>
                <label><?= e(t('Passwort (mind. 10 Zeichen, Buchstaben und Ziffern) *')) ?><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
                <label><?= e(t('Passwort wiederholen *')) ?><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
            </div>
            <fieldset><legend><?= e(t('Gewünschte Module *')) ?></legend>
                <p class="muted small"><?= e(t('Die Module werden nach Prüfung durch uns freigeschaltet.')) ?></p>
                <div class="checks">
                <?php foreach ($apps as $a): ?>
                    <label class="check"><input type="checkbox" name="apps[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $wantApps, true) ? 'checked' : '' ?>>
                        <span><strong><?= e(($a['icon'] ? $a['icon'] . ' ' : '') . t($a['name'])) ?></strong><?php if ($a['description']): ?><br><span class="muted small"><?= e(t($a['description'])) ?></span><?php endif; ?></span></label>
                <?php endforeach; ?>
                </div>
            </fieldset>
            <?php $privacy = (string)cfg('registration.privacy_url', ''); ?>
            <label class="check"><input type="checkbox" name="privacy" value="1" required>
                <span><?= e(t('Ich habe die')) ?> <?= $privacy ? '<a href="' . e($privacy) . ('" target="_blank" rel="noopener">' . e(t('Datenschutzhinweise')) . '</a>') : t('Datenschutzhinweise') ?> <?= e(t('gelesen und bin mit der Verarbeitung meiner Angaben zur Bearbeitung der Registrierung einverstanden. *')) ?></span></label>
            <div class="buttons">
                <button class="btn primary"><?= e(t('Registrieren')) ?></button>
                <a class="btn" href="<?= e(url('login')) ?>"><?= e(t('Ich habe schon einen Zugang')) ?></a>
            </div>
        </form>
    <?php });
}

function page_verify_email(): void
{
    $token = trim((string)($_POST['token'] ?? $_GET['token'] ?? $_GET['amp;token'] ?? ''));
    $t = find_user_token($token, 'verify');
    if ($t) {
        // Ein Klick auf den Link genügt
        update('users', (int)$t['user_id'], ['email_verified' => 1]);
        q("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'verify'", [$t['user_id']]);
        log_public('email_verified', $t['email'], (int)$t['user_id'], $t['company_id'] ? (int)$t['company_id'] : null);
        flash('success', t('Ihre E-Mail-Adresse ist bestätigt. Bitte melden Sie sich jetzt an.'));
        redirect('login');
    }
    render_page(t('E-Mail-Adresse bestätigen'), function () { ?>
        <div class="card narrow">
            <div class="alert error"><?= e(t('Der Link ist ungültig oder abgelaufen.')) ?></div>
            <p><?= e(t('Ist Ihre Adresse schon bestätigt, können Sie sich direkt anmelden. Andernfalls schicken wir Ihnen bei der Anmeldung einen neuen Link.')) ?></p>
            <p><a class="btn" href="<?= e(url('login')) ?>"><?= e(t('Zur Anmeldung')) ?></a></p>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Passwort vergessen
// ---------------------------------------------------------------------------
function page_forgot_password(): void
{
    if (current_user()) redirect('account');
    $sent = false;
    $error = '';
    if (is_post()) {
        require_post_csrf();
        $email = strtolower(mb_substr(post('email'), 0, 190));
        if (ip_action_count('password_reset_request', 60) >= 10) {
            $error = t('Zu viele Anfragen. Bitte versuchen Sie es später erneut.');
        } else {
            $u = filter_var($email, FILTER_VALIDATE_EMAIL) ? row('SELECT * FROM users WHERE email = ?', [$email]) : null;
            log_public('password_reset_request', $email, $u ? (int)$u['id'] : null, $u && $u['company_id'] ? (int)$u['company_id'] : null);
            if ($u && (int)$u['active'] && user_company_active($u) && !token_recently_sent((int)$u['id'], 'reset', 2)) {
                $token = create_user_token((int)$u['id'], 'reset', 60);
                send_mail_t([$u['email']], fn() => [t('Passwort zurücksetzen'), t('Guten Tag ') . $u['name'] . t(",\n\nüber diesen Link können Sie ein neues Passwort vergeben (60 Minuten gültig, nur einmal verwendbar):\n\n")
                    . portal_link('reset_password', ['token' => $token])
                    . t("\n\nDie Zwei-Faktor-Anmeldung bleibt unverändert.\nWenn Sie das nicht angefordert haben, ignorieren Sie diese Nachricht – Ihr Passwort bleibt dann bestehen.")],
                    $u['company_id'] ? (int)$u['company_id'] : null);
            }
            usleep(random_int(200000, 500000));
            $sent = true;
        }
    }
    render_page(t('Passwort vergessen'), function () use ($sent, $error) { ?>
        <div class="card narrow">
        <?php if ($sent): ?>
            <div class="alert success"><?= e(t('Wenn zu dieser Adresse ein Zugang besteht, haben wir Ihnen einen Link zum Zurücksetzen geschickt. Bitte prüfen Sie auch den Spam-Ordner.')) ?></div>
            <p><a class="btn" href="<?= e(url('login')) ?>"><?= e(t('Zur Anmeldung')) ?></a></p>
        <?php else: ?>
            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
            <p><?= e(t('Geben Sie Ihre E-Mail-Adresse ein. Sie erhalten einen Link, mit dem Sie ein neues Passwort vergeben.')) ?></p>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <label><?= e(t('E-Mail-Adresse')) ?><input type="email" name="email" required autofocus autocomplete="username"></label>
                <button class="btn primary"><?= e(t('Link anfordern')) ?></button>
            </form>
            <p class="small"><a href="<?= e(url('login')) ?>"><?= e(t('Zurück zur Anmeldung')) ?></a></p>
        <?php endif; ?>
        </div>
    <?php });
}

function page_reset_password(): void
{
    $token = trim((string)($_POST['token'] ?? $_GET['token'] ?? $_GET['amp;token'] ?? ''));
    $t = find_user_token($token, 'reset');
    $errors = [];
    if ($t && is_post()) {
        require_post_csrf();
        $pw = (string)($_POST['password'] ?? '');
        $u = row('SELECT * FROM users WHERE id = ?', [$t['user_id']]);
        if ($msg = validate_password($pw)) $errors[] = $msg;
        elseif ($pw !== ($_POST['password2'] ?? '')) $errors[] = t('Die Passwörter stimmen nicht überein.');
        elseif (password_verify($pw, $u['password_hash'])) $errors[] = t('Bitte wählen Sie ein anderes Passwort als bisher.');
        else {
            // Link bestätigt auch die E-Mail-Adresse
            update('users', (int)$u['id'], ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 0,
                'failed_attempts' => 0, 'locked_until' => null, 'email_verified' => 1]);
            q("DELETE FROM user_tokens WHERE user_id = ? AND purpose = 'reset'", [$u['id']]);
            log_public('password_reset', t('Passwort per E-Mail-Link zurückgesetzt'), (int)$u['id'], $u['company_id'] ? (int)$u['company_id'] : null);
            send_mail_t([$u['email']], fn() => [t('Ihr Passwort wurde geändert'), t('Guten Tag ') . $u['name'] . t(",\n\nIhr Passwort für das ") . t(cfg('app_name', 'Kundenportal')) . t(" wurde soeben geändert.\n\n")
                . t('Waren Sie das nicht? Bitte wenden Sie sich sofort an Ihren IT-Dienstleister.')], $u['company_id'] ? (int)$u['company_id'] : null);
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            unset($_SESSION['uid']);
            flash('success', t('Ihr Passwort wurde geändert. Bitte melden Sie sich mit dem neuen Passwort und Ihrem Authenticator-Code an.'));
            redirect('login');
        }
    }
    render_page(t('Neues Passwort vergeben'), function () use ($t, $token, $errors) { ?>
        <div class="card narrow">
        <?php if (!$t): ?>
            <div class="alert error"><?= e(t('Der Link ist ungültig, abgelaufen oder wurde bereits verwendet.')) ?></div>
            <p><a class="btn" href="<?= e(url('forgot_password')) ?>"><?= e(t('Neuen Link anfordern')) ?></a></p>
        <?php else:
            foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>'; ?>
            <p><?= e(t('Neues Passwort für')) ?> <strong><?= e($t['email']) ?></strong>:</p>
            <form method="post" class="form">
                <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
                <label><?= e(t('Neues Passwort (mind. 10 Zeichen, Buchstaben und Ziffern)')) ?><input type="password" name="password" required minlength="10" autofocus autocomplete="new-password"></label>
                <label><?= e(t('Neues Passwort wiederholen')) ?><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
                <button class="btn primary"><?= e(t('Passwort speichern')) ?></button>
            </form>
        <?php endif; ?>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Freischaltung durch den Dienstleister (aus dem Firmenprofil)
// ---------------------------------------------------------------------------
function handle_registration_decision(array $company, array $me): void
{
    $cid = (int)$company['id'];
    $admins = rows("SELECT * FROM users WHERE company_id = ? AND role = 'firma_admin'", [$cid]);
    $to = array_column($admins, 'email');
    if (post('action') === 'approve_registration') {
        $valid = array_map('intval', array_column(rows('SELECT id FROM apps'), 'id'));
        $grant = array_values(array_intersect($valid, array_map('intval', (array)($_POST['grant'] ?? []))));
        $have = array_map('intval', array_column(rows('SELECT app_id FROM company_apps WHERE company_id = ?', [$cid]), 'app_id'));
        foreach (array_diff($grant, $have) as $aid) {
            insert('company_apps', ['company_id' => $cid, 'app_id' => $aid, 'granted_by' => $me['id'], 'granted_at' => now()]);
        }
        update('companies', $cid, ['registration_status' => '', 'active' => 1, 'updated_at' => now()]);
        // Mit der Freischaltung gilt die Adresse als geprüft
        q("UPDATE users SET email_verified = 1 WHERE company_id = ? AND role = 'firma_admin'", [$cid]);
        $names = app_names_by_ids($grant);
        audit('registration_approved', $company['name'] . ($names ? ': ' . implode(', ', $names) : ''), $cid);
        $note = mb_substr(post('message'), 0, 2000);
        send_mail_t($to, fn() => [t('Ihr Zugang ist freigeschaltet'), t("Guten Tag,\n\nIhre Registrierung der Firma „") . $company['name'] . t("“ wurde geprüft und freigeschaltet.\n\n")
            . t('Freigeschaltete Module: ') . ($names ? implode(', ', $names) : t('noch keine')) . "\n\n"
            . ($note !== '' ? $note . "\n\n" : '')
            . t("Zur Anmeldung:\n") . portal_link('login')], $cid);
        flash('success', t('Die Firma wurde freigeschaltet und per E-Mail informiert.'));
    } elseif (post('action') === 'reject_registration') {
        update('companies', $cid, ['registration_status' => 'rejected', 'active' => 0, 'updated_at' => now()]);
        q('UPDATE users SET active = 0 WHERE company_id = ?', [$cid]);
        $reason = mb_substr(post('message'), 0, 2000);
        audit('registration_rejected', $company['name'] . ($reason !== '' ? ': ' . $reason : ''), $cid);
        send_mail_t($to, fn() => [t('Ihre Registrierung'), t("Guten Tag,\n\nleider können wir die Registrierung der Firma „") . $company['name'] . t('“ nicht freischalten.')
            . ($reason !== '' ? "\n\n" . $reason : '') . t("\n\nBei Fragen antworten Sie gern auf diese E-Mail.")]);
        flash('success', t('Die Registrierung wurde abgelehnt. Firma und Zugang sind deaktiviert.'));
    }
    redirect('company_edit', ['id' => $cid]);
}

function render_registration_review(array $company): void
{
    $requested = requested_app_ids($company);
    $allApps = rows('SELECT * FROM apps ORDER BY sort, name');
    $admin = row("SELECT id, name, email, email_verified, totp_enabled FROM users WHERE company_id = ? AND role = 'firma_admin' ORDER BY id LIMIT 1", [$company['id']]); ?>
    <div class="card review">
        <h2><?= e(t('Registrierung prüfen')) ?></h2>
        <p><?= e(t('Selbst registriert am')) ?> <?= fmt_dt($company['registered_at']) ?><?php if ($admin): ?> <?= e(t('von')) ?> <strong><?= e($admin['name']) ?></strong> &lt;<?= e($admin['email']) ?><?= e(t('> · E-Mail')) ?> <?= (int)$admin['email_verified'] ? t('✓ bestätigt') : ('<strong>' . e(t('noch nicht bestätigt')) . '</strong>') ?>
            <?= e(t('· 2FA')) ?> <?= (int)$admin['totp_enabled'] ? t('✓ eingerichtet') : 'noch nicht eingerichtet' ?><?php endif; ?></p>
        <?php if ($admin && !(int)$admin['email_verified']): ?>
            <p class="muted small"><?= e(t('Die Bestätigungsmail ist nicht angekommen? Beim Freischalten wird die Adresse automatisch bestätigt, oder')) ?>
                <a href="<?= e(url('user_edit', ['id' => $admin['id']])) ?>"><?= e(t('Benutzer öffnen')) ?></a> <?= e(t('und den Link erneut senden bzw. selbst bestätigen.')) ?></p>
        <?php endif; ?>
        <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="action" value="approve_registration">
            <fieldset><legend><?= e(t('Module freischalten (angefragte sind vorausgewählt)')) ?></legend>
                <div class="checks">
                <?php foreach ($allApps as $a): ?>
                    <label class="check"><input type="checkbox" name="grant[]" value="<?= (int)$a['id'] ?>" <?= in_array((int)$a['id'], $requested, true) ? 'checked' : '' ?>>
                        <?= e(($a['icon'] ? $a['icon'] . ' ' : '') . t($a['name'])) ?><?= in_array((int)$a['id'], $requested, true) ? (' <span class="tag">' . e(t('angefragt')) . '</span>') : '' ?></label>
                <?php endforeach; ?>
                </div>
            </fieldset>
            <label><?= e(t('Nachricht an die Firma (optional, steht in der E-Mail)')) ?><textarea name="message" rows="3"></textarea></label>
            <div class="buttons"><button class="btn primary"><?= e(t('Freischalten und Firma benachrichtigen')) ?></button></div>
        </form>
        <details>
            <summary><?= e(t('Registrierung ablehnen')) ?></summary>
            <form method="post" class="form">
                <?= csrf_field() ?><input type="hidden" name="action" value="reject_registration">
                <label><?= e(t('Begründung (optional, steht in der E-Mail)')) ?><textarea name="message" rows="3"></textarea></label>
                <button class="btn danger" data-confirm="<?= e(t('Registrierung ablehnen? Firma und Zugang werden deaktiviert.')) ?>"><?= e(t('Ablehnen')) ?></button>
            </form>
        </details>
    </div>
<?php }

// ---------------------------------------------------------------------------
// 2FA verloren: Antrag an den Superadmin
// ---------------------------------------------------------------------------
function request_2fa_reset(array $u): void
{
    $last = $u['totp_reset_requested_at'] ? strtotime($u['totp_reset_requested_at']) : 0;
    if ($last && time() - $last < 86400) {
        flash('info', t('Ihr Antrag liegt bereits vor. Sie erhalten eine E-Mail, sobald die Zwei-Faktor-Anmeldung zurückgesetzt wurde.'));
        return;
    }
    update('users', (int)$u['id'], ['totp_reset_requested_at' => now()]);
    $company = $u['company_id'] ? (row('SELECT name FROM companies WHERE id = ?', [$u['company_id']])['name'] ?? '') : t('Administration');
    log_public('2fa_reset_requested', $u['email'], (int)$u['id'], $u['company_id'] ? (int)$u['company_id'] : null);
    send_mail_t(superadmin_emails(), fn() => [t('2FA-Zurücksetzung beantragt: ') . $u['name'], $u['name'] . ' <' . $u['email'] . '> (' . $company . t(") hat keinen Zugriff mehr auf die Authenticator-App und bittet um Zurücksetzen der Zwei-Faktor-Anmeldung.\n\n")
        . t("Das Passwort wurde bei der Anfrage korrekt eingegeben. Bitte prüfen Sie die Identität (z. B. telefonischer Rückruf), bevor Sie zurücksetzen.\n\n")
        . t("Zum Benutzer:\n") . portal_link('user_edit', ['id' => $u['id']])]);
    send_mail_t([$u['email']], fn() => [t('Antrag auf Zurücksetzen der Zwei-Faktor-Anmeldung'), t('Guten Tag ') . $u['name'] . t(",\n\nwir haben Ihren Antrag erhalten. Nach Prüfung Ihrer Identität setzen wir die Zwei-Faktor-Anmeldung zurück und informieren Sie per E-Mail.\n\n")
        . t('Haben Sie den Antrag nicht gestellt? Dann kennt jemand Ihr Passwort – bitte ändern Sie es sofort über „Passwort vergessen“.')],
        $u['company_id'] ? (int)$u['company_id'] : null);
    flash('success', t('Ihr Antrag wurde an den Administrator gesendet. Sie erhalten eine E-Mail, sobald Sie die Authenticator-App neu einrichten können.'));
}
