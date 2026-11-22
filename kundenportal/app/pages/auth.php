<?php
/**
 * Anmeldung (Passwort + Zwei-Faktor), 2FA-Einrichtung, Abmeldung, Mein Konto.
 */

function login_fail(?array $u): void
{
    if ($u) {
        $attempts = (int)$u['failed_attempts'] + 1;
        $data = ['failed_attempts' => $attempts];
        if ($attempts >= (int)cfg('max_login_attempts', 5)) {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + 60 * (int)cfg('lockout_minutes', 15));
            $data['failed_attempts'] = 0;
            insert('audit_log', ['user_id' => $u['id'], 'company_id' => $u['company_id'], 'action' => 'login_locked',
                'details' => 'Konto nach Fehlversuchen gesperrt', 'ip' => client_ip(), 'created_at' => now()]);
        }
        update('users', (int)$u['id'], $data);
    }
    usleep(random_int(200000, 500000));
}

function is_locked(array $u): bool
{
    return $u['locked_until'] && strtotime($u['locked_until']) > time();
}

function user_company_active(array $u): bool
{
    if (!$u['company_id']) return true;
    $c = row('SELECT active FROM companies WHERE id = ?', [$u['company_id']]);
    return $c && (int)$c['active'] === 1;
}

function finish_login(array $u, ?int $step = null): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending_uid'], $_SESSION['pending_at'], $_SESSION['setup_uid'], $_SESSION['setup_secret']);
    $_SESSION['uid'] = (int)$u['id'];
    $data = ['last_login' => now(), 'failed_attempts' => 0, 'locked_until' => null];
    if ($step !== null) $data['totp_last_step'] = $step;
    update('users', (int)$u['id'], $data);
    audit('login', 'Anmeldung erfolgreich', $u['company_id'] ? (int)$u['company_id'] : null);
}

function page_login(): void
{
    if (current_user()) redirect('home');
    $error = '';
    if (is_post()) {
        require_post_csrf();
        $email = strtolower(post('email'));
        $pw = (string)($_POST['password'] ?? '');
        $u = row('SELECT * FROM users WHERE email = ?', [$email]);
        // Hash auch bei unbekannter Adresse prüfen (gleiche Antwortzeit)
        $hash = $u['password_hash'] ?? password_hash(random_bytes(8), PASSWORD_DEFAULT);
        $ok = password_verify($pw, $hash);

        if ($u && is_locked($u)) {
            $error = 'Zu viele Fehlversuche. Das Konto ist vorübergehend gesperrt – bitte später erneut versuchen.';
        } elseif (!$u || !$ok || !(int)$u['active'] || !user_company_active($u)) {
            login_fail($ok ? null : $u);
            $error = 'E-Mail-Adresse oder Passwort ist falsch, oder der Zugang ist deaktiviert.';
        } elseif (!(int)($u['email_verified'] ?? 1)) {
            if (!token_recently_sent((int)$u['id'], 'verify', 10)) send_verification_mail($u);
            $error = 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse. Wir haben Ihnen einen Bestätigungslink geschickt (bitte auch im Spam-Ordner nachsehen). Ein Klick auf den Link genügt, danach können Sie sich anmelden.';
        } else {
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                update('users', (int)$u['id'], ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
            }
            session_regenerate_id(true);
            if ((int)$u['totp_enabled']) {
                $_SESSION['pending_uid'] = (int)$u['id'];
                $_SESSION['pending_at'] = time();
                redirect('login_2fa');
            }
            $_SESSION['setup_uid'] = (int)$u['id'];
            $_SESSION['pending_at'] = time();
            redirect('setup_2fa');
        }
    }
    render_page('Anmeldung', function () use ($error) { ?>
        <div class="card narrow">
            <img src="assets/logo.svg" class="login-logo" width="72" height="72" alt="">
            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <label>E-Mail-Adresse<input type="email" name="email" required autofocus autocomplete="username" value="<?= e(post('email')) ?>"></label>
                <label>Passwort<input type="password" name="password" required autocomplete="current-password"></label>
                <button class="btn primary">Weiter</button>
            </form>
            <p class="small"><a href="<?= e(url('forgot_password')) ?>">Passwort vergessen?</a></p>
            <?php if (registration_enabled()): ?>
            <p class="small">Noch kein Zugang? <a href="<?= e(url('register')) ?>">Firma registrieren</a></p>
            <?php endif; ?>
            <p class="muted small">Die Anmeldung erfolgt mit Zwei-Faktor-Authentifizierung (Authenticator-App).</p>
        </div>
    <?php });
}

function pending_expired(): bool
{
    return time() - (int)($_SESSION['pending_at'] ?? 0) > 300;
}

function page_login_2fa(): void
{
    $uid = $_SESSION['pending_uid'] ?? null;
    if (!$uid || pending_expired()) {
        unset($_SESSION['pending_uid']);
        flash('info', 'Bitte melden Sie sich erneut an.');
        redirect('login');
    }
    $error = '';
    if (is_post()) {
        require_post_csrf();
        $u = row('SELECT * FROM users WHERE id = ?', [$uid]);
        if (!$u || !(int)$u['active'] || is_locked($u)) {
            unset($_SESSION['pending_uid']);
            flash('error', 'Die Anmeldung ist nicht möglich.');
            redirect('login');
        }
        if (post('action') === 'request_reset') {
            request_2fa_reset($u);
            unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
            redirect('login');
        }
        $step = totp_verify((string)$u['totp_secret'], post('code'), (int)$u['totp_last_step']);
        if ($step !== null) {
            finish_login($u, $step);
            redirect('home');
        }
        login_fail($u);
        $error = 'Der Code ist ungültig oder abgelaufen.';
    }
    render_page('Bestätigungscode', function () use ($error) { ?>
        <div class="card narrow">
            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
            <p>Geben Sie den 6-stelligen Code aus Ihrer Authenticator-App ein.</p>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <label>Code<input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code" class="code"></label>
                <button class="btn primary">Anmelden</button>
            </form>
            <details class="small">
                <summary>Kein Zugriff mehr auf die Authenticator-App?</summary>
                <p class="muted">Aus Sicherheitsgründen kann nur der Administrator des Portals die Zwei-Faktor-Anmeldung zurücksetzen.
                    Er prüft vorher Ihre Identität. Danach richten Sie die App bei der nächsten Anmeldung neu ein.</p>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="request_reset">
                    <button class="btn small">Zurücksetzen beantragen</button></form>
            </details>
        </div>
    <?php });
}

function page_setup_2fa(): void
{
    $loggedIn = current_user();
    $uid = $_SESSION['setup_uid'] ?? null;
    if (!$uid || (!$loggedIn && pending_expired())) {
        unset($_SESSION['setup_uid'], $_SESSION['setup_secret']);
        redirect($loggedIn ? 'account' : 'login');
    }
    $u = row('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$u || !(int)$u['active']) redirect('login');
    // Erst-Einrichtung nur, wenn noch keine 2FA aktiv ist – sonst nur aus "Mein Konto"
    if ((int)$u['totp_enabled'] && !$loggedIn) redirect('login');

    if (empty($_SESSION['setup_secret'])) $_SESSION['setup_secret'] = totp_generate_secret();
    $secret = $_SESSION['setup_secret'];
    $error = '';

    if (is_post()) {
        require_post_csrf();
        $step = totp_verify($secret, post('code'));
        if ($step !== null) {
            update('users', (int)$u['id'], ['totp_secret' => $secret, 'totp_enabled' => 1, 'totp_last_step' => $step]);
            insert('audit_log', ['user_id' => $u['id'], 'company_id' => $u['company_id'], 'action' => '2fa_setup',
                'details' => 'Zwei-Faktor-Authentifizierung eingerichtet', 'ip' => client_ip(), 'created_at' => now()]);
            if ($loggedIn) {
                unset($_SESSION['setup_uid'], $_SESSION['setup_secret']);
                flash('success', 'Die Zwei-Faktor-Authentifizierung wurde neu eingerichtet.');
                redirect('account');
            }
            finish_login($u, $step);
            flash('success', 'Die Zwei-Faktor-Authentifizierung ist eingerichtet. Willkommen!');
            redirect('home');
        }
        $error = 'Der Code stimmt nicht. Prüfen Sie die Uhrzeit Ihres Smartphones und versuchen Sie es erneut.';
    }

    $uri = totp_uri($secret, $u['email'], (string)cfg('totp_issuer', cfg('app_name', 'Kundenportal')));
    $qr = QrCodeM::svg($uri, 220);
    $qrData = 'data:image/svg+xml;base64,' . base64_encode($qr);
    render_page('Zwei-Faktor-Authentifizierung einrichten', function () use ($error, $secret, $qrData) { ?>
        <div class="card narrow">
            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
            <ol class="steps">
                <li>Installieren Sie eine Authenticator-App auf Ihrem Smartphone, z. B. Microsoft Authenticator, Google Authenticator oder FreeOTP.</li>
                <li>Scannen Sie diesen QR-Code mit der App:
                    <div class="qr"><img src="<?= e($qrData) ?>" width="220" height="220" alt="QR-Code für die Authenticator-App"></div>
                    <details><summary>QR-Code lässt sich nicht scannen?</summary>
                        <p>Geben Sie diesen Schlüssel manuell ein (zeitbasiert, 6 Ziffern):</p>
                        <p class="secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></p>
                    </details>
                </li>
                <li>Geben Sie den angezeigten 6-stelligen Code ein:</li>
            </ol>
            <form method="post" class="form">
                <?= csrf_field() ?>
                <label>Code<input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code" class="code"></label>
                <button class="btn primary">Bestätigen</button>
            </form>
        </div>
    <?php });
}

function page_logout(): void
{
    require_post_csrf();
    if (current_user()) audit('logout');
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
    session_start();
    flash('success', 'Sie wurden abgemeldet.');
    redirect('login');
}

function page_account(): void
{
    $u = require_login();
    $errors = [];
    if (is_post()) {
        require_post_csrf();
        $action = post('action');
        $current = (string)($_POST['current_password'] ?? '');
        if (!password_verify($current, $u['password_hash'])) {
            $errors[] = 'Das aktuelle Passwort ist falsch.';
        } elseif ($action === 'password') {
            $new = (string)($_POST['new_password'] ?? '');
            if ($msg = validate_password($new)) $errors[] = $msg;
            elseif ($new !== ($_POST['new_password2'] ?? '')) $errors[] = 'Die neuen Passwörter stimmen nicht überein.';
            elseif (password_verify($new, $u['password_hash'])) $errors[] = 'Das neue Passwort muss sich vom bisherigen unterscheiden.';
            else {
                update('users', $u['id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0]);
                session_regenerate_id(true);
                audit('password_changed', 'Eigenes Passwort geändert');
                flash('success', 'Ihr Passwort wurde geändert.');
                redirect('account');
            }
        } elseif ($action === 'reset2fa') {
            $_SESSION['setup_uid'] = $u['id'];
            unset($_SESSION['setup_secret']);
            redirect('setup_2fa');
        }
    }
    render_page('Mein Konto', function () use ($u, $errors) {
        foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>'; ?>
        <div class="grid2">
            <div class="card">
                <h2>Kontodaten</h2>
                <dl class="facts">
                    <dt>Name</dt><dd><?= e($u['name']) ?></dd>
                    <dt>E-Mail</dt><dd><?= e($u['email']) ?></dd>
                    <dt>Rolle</dt><dd><?= e(ROLES[$u['role']] ?? $u['role']) ?></dd>
                    <dt>Letzte Anmeldung</dt><dd><?= fmt_dt($u['last_login']) ?></dd>
                </dl>
            </div>
            <div class="card">
                <h2>Passwort ändern</h2>
                <form method="post" class="form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="password">
                    <label>Aktuelles Passwort<input type="password" name="current_password" required autocomplete="current-password"></label>
                    <label>Neues Passwort (mind. 10 Zeichen, Buchstaben und Ziffern)<input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>
                    <label>Neues Passwort wiederholen<input type="password" name="new_password2" required minlength="10" autocomplete="new-password"></label>
                    <button class="btn primary">Passwort speichern</button>
                </form>
            </div>
            <?php if (!(int)$u['must_change_password']): ?>
            <div class="card">
                <h2>Authenticator-App wechseln</h2>
                <p class="muted">Neues Smartphone? Richten Sie die Zwei-Faktor-Anmeldung neu ein. Der alte Code ist danach ungültig.</p>
                <form method="post" class="form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reset2fa">
                    <label>Aktuelles Passwort<input type="password" name="current_password" required autocomplete="current-password"></label>
                    <button class="btn">2FA neu einrichten</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    <?php });
}
