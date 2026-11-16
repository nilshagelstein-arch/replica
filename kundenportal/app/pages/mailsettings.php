<?php
/**
 * Einstellungen für den E-Mail-Versand: zentrales Postfach (Administratoren) und Postfach je Firma.
 */

const SMTP_ALLOWED_PORTS = [25, 465, 587, 2525];

/** Firma, deren Postfach bearbeitet wird (null = zentrales Postfach, nur Admins) */
function mail_settings_target(): ?int
{
    $u = require_manager();
    if (is_staff_role($u['role'])) {
        $cid = query_int('company') ?: (int)post('company_id', 0);
        if ($cid && !row('SELECT id FROM companies WHERE id = ?', [$cid])) abort(404);
        return $cid ?: null;
    }
    return (int)$u['company_id'];
}

function mail_settings_params(?int $cid): array
{
    return is_admin() && $cid ? ['company' => $cid] : [];
}

function ensure_mail_account(?int $cid, string $provider): array
{
    $acc = mail_account_for($cid);
    if (!$acc) {
        insert('mail_accounts', ['company_id' => $cid, 'provider' => $provider, 'updated_at' => now(), 'active' => 1]);
        $acc = mail_account_for($cid);
    }
    return $acc;
}

function page_mail_settings(): void
{
    $u = require_manager();
    $cid = mail_settings_target();
    $params = mail_settings_params($cid);
    $acc = mail_account_for($cid);

    if (is_post()) {
        require_post_csrf();
        $action = post('action');
        if ($action === 'oauth_app') {
            if (!is_admin()) abort(403);
            foreach (['microsoft', 'google'] as $p) {
                setting_set("oauth_{$p}_client_id", mb_substr(post("{$p}_client_id"), 0, 200));
                $sec = (string)($_POST["{$p}_client_secret"] ?? '');
                if ($sec !== '') setting_set("oauth_{$p}_client_secret", encrypt_secret($sec));
                if (post("{$p}_clear") === '1') setting_set("oauth_{$p}_client_secret", '');
            }
            $tenant = post('microsoft_tenant') ?: 'organizations';
            setting_set('oauth_microsoft_tenant', preg_match('/^[A-Za-z0-9.-]{1,100}$/', $tenant) ? $tenant : 'organizations');
            audit('oauth_app_saved', 'OAuth-Anwendungsdaten gespeichert');
            flash('success', 'Die Anmeldedaten für Microsoft 365 und Google wurden gespeichert.');
            redirect('mail_settings', $params);
        }
        if ($action === 'save') {
            $provider = post('provider');
            if (!isset(MAIL_PROVIDERS[$provider])) abort(400);
            $data = [
                'provider'  => $provider,
                'from_name' => mb_substr(post('from_name'), 0, 190),
                'reply_to'  => mb_substr(post('reply_to'), 0, 190),
                'active'    => post('active') === '1' ? 1 : 0,
                'updated_at' => now(),
            ];
            $errors = [];
            if ($data['reply_to'] !== '' && !filter_var($data['reply_to'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Die Antwortadresse ist ungültig.';
            if ($provider === 'smtp') {
                $data['from_email'] = mb_substr(post('from_email'), 0, 190);
                $data['smtp_host'] = strtolower(mb_substr(post('smtp_host'), 0, 190));
                $data['smtp_port'] = (int)post('smtp_port', 587);
                $data['smtp_security'] = in_array(post('smtp_security'), ['tls', 'ssl', 'none'], true) ? post('smtp_security') : 'tls';
                $data['smtp_user'] = mb_substr(post('smtp_user'), 0, 190);
                $pw = (string)($_POST['smtp_password'] ?? '');
                if ($pw !== '') $data['secret_enc'] = encrypt_secret($pw);
                if (!filter_var($data['from_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte eine gültige Absenderadresse angeben.';
                if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $data['smtp_host'])) $errors[] = 'Bitte einen gültigen SMTP-Server angeben, z. B. smtp.ionos.de.';
                if (!in_array($data['smtp_port'], SMTP_ALLOWED_PORTS, true)) $errors[] = 'Erlaubte Ports: ' . implode(', ', SMTP_ALLOWED_PORTS) . '.';
                if ($data['smtp_security'] === 'none' && $data['smtp_user'] !== '') $errors[] = 'Eine Anmeldung ohne Verschlüsselung ist nicht erlaubt. Bitte STARTTLS oder SSL wählen.';
                // Wechsel weg von OAuth: Tokens verwerfen
                $data['oauth_refresh_enc'] = null; $data['oauth_access_enc'] = null; $data['oauth_expires'] = 0;
            } elseif ($acc && $acc['provider'] !== $provider) {
                // Anbieterwechsel: alte Zugangsdaten entfernen, neue Anmeldung erforderlich
                $data += ['from_email' => '', 'secret_enc' => null, 'oauth_refresh_enc' => null, 'oauth_access_enc' => null, 'oauth_expires' => 0];
            }
            if ($errors) {
                foreach ($errors as $e) flash('error', $e);
                redirect('mail_settings', $params);
            }
            $acc = ensure_mail_account($cid, $provider);
            update('mail_accounts', (int)$acc['id'], $data + ['last_error' => '']);
            audit('mail_account_saved', MAIL_PROVIDERS[$provider], $cid);
            flash('success', $provider === 'smtp' ? 'Die Einstellungen wurden gespeichert. Senden Sie jetzt eine Testnachricht.'
                : 'Gespeichert. Melden Sie sich jetzt mit dem Postfach an, über das versendet werden soll.');
            redirect('mail_settings', $params);
        }
        if ($action === 'test') {
            if (!$acc) redirect('mail_settings', $params);
            try {
                send_via_account($acc, $u['email'], 'Testnachricht vom ' . cfg('app_name', 'Kundenportal'),
                    "Diese Testnachricht bestätigt, dass der E-Mail-Versand über dieses Postfach funktioniert.\n\nGesendet am " . date('d.m.Y H:i') . '.');
                flash('success', 'Die Testnachricht wurde an ' . $u['email'] . ' gesendet.');
            } catch (Throwable $ex) {
                flash('error', 'Versand fehlgeschlagen: ' . $ex->getMessage());
            }
            redirect('mail_settings', $params);
        }
        if ($action === 'delete') {
            if ($acc) q('DELETE FROM mail_accounts WHERE id = ?', [$acc['id']]);
            audit('mail_account_deleted', '', $cid);
            flash('success', $cid ? 'Das Postfach wurde entfernt. E-Mails gehen wieder über das zentrale Postfach.' : 'Das zentrale Postfach wurde entfernt.');
            redirect('mail_settings', $params);
        }
        abort(400);
    }

    $title = $cid ? 'E-Mail-Versand: ' . (row('SELECT name FROM companies WHERE id = ?', [$cid])['name'] ?? '') : 'E-Mail-Versand: zentrales Postfach';
    render_page($title, function () use ($u, $cid, $acc, $params) {
        if (is_admin()): ?>
            <div class="toolbar">
                <form method="get" class="company-switch"><input type="hidden" name="r" value="mail_settings">
                    <label>Postfach von: <select name="company" onchange="this.form.submit()">
                        <option value="0">Zentral (Dienstleister)</option>
                        <?php foreach (companies_for_select() as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cid === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                    </select></label><noscript><button class="btn small">Wechseln</button></noscript></form>
            </div>
        <?php endif; ?>
        <p class="muted"><?= $cid
            ? 'Benachrichtigungen an Ihre Mitarbeitenden (z. B. Statusänderungen) werden über dieses Postfach versendet. Ohne eigenes Postfach oder bei einem Fehler wird das zentrale Postfach des Dienstleisters verwendet.'
            : 'Über dieses Postfach gehen alle Benachrichtigungen, für die keine Firma ein eigenes Postfach eingerichtet hat, sowie Mails an die Administratoren. Ohne zentrales Postfach wird die Mailfunktion des Webhosters genutzt.' ?></p>
        <?php
        $provider = $acc['provider'] ?? 'smtp';
        $ready = mail_account_ready($acc);
        ?>
        <div class="card">
            <h2>Status</h2>
            <?php if (!$acc): ?>
                <p class="muted">Noch kein Postfach eingerichtet.</p>
            <?php else: ?>
                <dl class="facts">
                    <dt>Anbieter</dt><dd><?= e(MAIL_PROVIDERS[$acc['provider']]) ?></dd>
                    <dt>Absender</dt><dd><?= e($acc['from_email'] ?: '– noch nicht verbunden –') ?></dd>
                    <dt>Status</dt><dd><?= $ready ? ((int)$acc['active'] ? '<strong class="ok-text">bereit</strong>' : 'deaktiviert') : '<strong class="err-text">nicht vollständig eingerichtet</strong>' ?></dd>
                    <dt>Zuletzt erfolgreich</dt><dd><?= fmt_dt($acc['last_ok_at']) ?></dd>
                    <?php if ($acc['last_error']): ?><dt>Letzter Fehler</dt><dd class="err-text"><?= fmt_dt($acc['last_error_at']) ?>: <?= e($acc['last_error']) ?></dd><?php endif; ?>
                </dl>
                <div class="buttons">
                    <?php if ($ready): ?>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="test"><input type="hidden" name="company_id" value="<?= (int)$cid ?>">
                        <button class="btn primary">Testnachricht an <?= e($u['email']) ?> senden</button></form>
                    <?php endif; ?>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="company_id" value="<?= (int)$cid ?>">
                        <button class="btn danger" data-confirm="Postfach-Einstellungen und gespeicherte Zugangsdaten löschen?">Postfach entfernen</button></form>
                </div>
            <?php endif; ?>
        </div>

        <form method="post" class="card form">
            <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="company_id" value="<?= (int)$cid ?>">
            <h2>Einstellungen</h2>
            <label>Versandart
                <select name="provider" id="provider">
                    <?php foreach (MAIL_PROVIDERS as $k => $l): ?><option value="<?= e($k) ?>" <?= $provider === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></label>
            <div class="grid3">
                <label>Anzeigename des Absenders<input name="from_name" maxlength="190" value="<?= e($acc['from_name'] ?? '') ?>" placeholder="z. B. IT-Service Muster GmbH"></label>
                <label>Antworten an (optional)<input type="email" name="reply_to" maxlength="190" value="<?= e($acc['reply_to'] ?? '') ?>"></label>
            </div>
            <div data-show-if="provider=smtp">
                <div class="grid3">
                    <label>Absenderadresse<input type="email" name="from_email" maxlength="190" value="<?= e(($acc['provider'] ?? '') === 'smtp' ? $acc['from_email'] : '') ?>"></label>
                    <label>SMTP-Server<input name="smtp_host" maxlength="190" value="<?= e($acc['smtp_host'] ?? '') ?>" placeholder="z. B. smtp.ionos.de"></label>
                    <label>Port
                        <select name="smtp_port"><?php foreach (SMTP_ALLOWED_PORTS as $p): ?><option <?= (int)($acc['smtp_port'] ?? 587) === $p ? 'selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></label>
                    <label>Verschlüsselung
                        <select name="smtp_security">
                            <?php foreach (['tls' => 'STARTTLS (Port 587)', 'ssl' => 'SSL/TLS (Port 465)', 'none' => 'keine (nur ohne Anmeldung)'] as $k => $l): ?>
                                <option value="<?= $k ?>" <?= ($acc['smtp_security'] ?? 'tls') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?></select></label>
                    <label>Benutzername<input name="smtp_user" maxlength="190" value="<?= e($acc['smtp_user'] ?? '') ?>" autocomplete="off"></label>
                    <label>Passwort<input type="password" name="smtp_password" autocomplete="new-password" placeholder="<?= !empty($acc['secret_enc']) ? 'gespeichert – leer lassen für unverändert' : '' ?>"></label>
                </div>
                <p class="muted small">Microsoft 365 und Google erlauben SMTP mit Passwort oft nicht mehr. Nutzen Sie dafür die Anmeldung mit Microsoft bzw. Google.</p>
            </div>
            <div data-show-if="provider=microsoft"><p class="muted small">Nach dem Speichern melden Sie sich mit dem Microsoft-Konto an, über das versendet werden soll (z. B. it@ihre-firma.de). Das Portal erhält nur das Recht, E-Mails zu senden, und kann keine E-Mails lesen.</p></div>
            <div data-show-if="provider=google"><p class="muted small">Nach dem Speichern melden Sie sich mit dem Google-Konto an, über das versendet werden soll. Das Portal erhält nur das Recht, E-Mails zu senden, und kann keine E-Mails lesen.</p></div>
            <label class="check"><input type="checkbox" name="active" value="1" <?= (int)($acc['active'] ?? 1) ? 'checked' : '' ?>> Postfach für den Versand verwenden</label>
            <button class="btn primary">Speichern</button>
        </form>

        <?php if ($acc && in_array($acc['provider'], ['microsoft', 'google'], true)): $p = $acc['provider']; ?>
        <div class="card">
            <h2>Anmeldung bei <?= $p === 'microsoft' ? 'Microsoft 365' : 'Google' ?></h2>
            <?php if (!oauth_app_configured($p)): ?>
                <p class="alert info"><?= is_admin() ? 'Bitte tragen Sie zuerst unten die Anwendungsdaten für ' . ($p === 'microsoft' ? 'Microsoft' : 'Google') . ' ein (im zentralen Postfach).' : 'Diese Versandart ist noch nicht freigeschaltet. Bitte wenden Sie sich an Ihren IT-Dienstleister.' ?></p>
            <?php else: ?>
                <p><?= $acc['oauth_refresh_enc'] ? 'Verbunden als <strong>' . e($acc['from_email']) . '</strong>.' : 'Noch nicht verbunden.' ?></p>
                <form method="post" action="<?= e(url('oauth_start')) ?>"><?= csrf_field() ?><input type="hidden" name="company_id" value="<?= (int)$cid ?>">
                    <button class="btn primary"><?= $acc['oauth_refresh_enc'] ? 'Erneut anmelden / anderes Postfach' : 'Mit ' . ($p === 'microsoft' ? 'Microsoft' : 'Google') . ' anmelden' ?></button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (is_admin() && !$cid):
            $ms = oauth_app('microsoft'); $gg = oauth_app('google'); ?>
        <form method="post" class="card form">
            <?= csrf_field() ?><input type="hidden" name="action" value="oauth_app">
            <h2>Anwendungsdaten für Microsoft 365 und Google</h2>
            <p class="muted">Damit Firmen ihr Postfach per Anmeldung verbinden können, wird je Anbieter einmalig eine Anwendung registriert
                (Microsoft Entra ID → App-Registrierungen bzw. Google Cloud Console → OAuth-Client). Als Weiterleitungs-URI eintragen:</p>
            <p class="secret"><?= e(oauth_redirect_uri()) ?></p>
            <fieldset><legend>Microsoft 365</legend>
                <div class="grid3">
                    <label>Anwendungs-ID (Client-ID)<input name="microsoft_client_id" value="<?= e($ms['client_id']) ?>" autocomplete="off"></label>
                    <label>Geheimer Clientschlüssel<input type="password" name="microsoft_client_secret" autocomplete="new-password" placeholder="<?= $ms['client_secret'] !== '' ? 'gespeichert – leer lassen für unverändert' : '' ?>"></label>
                    <label>Mandant<input name="microsoft_tenant" value="<?= e($ms['tenant']) ?>"></label>
                </div>
                <p class="muted small">Berechtigungen (delegiert): Mail.Send, User.Read, offline_access. Mandant „organizations“ erlaubt Geschäftskonten aller Firmen (Mehrinstanzen-App).</p>
            </fieldset>
            <fieldset><legend>Google</legend>
                <div class="grid3">
                    <label>Client-ID<input name="google_client_id" value="<?= e($gg['client_id']) ?>" autocomplete="off"></label>
                    <label>Clientschlüssel<input type="password" name="google_client_secret" autocomplete="new-password" placeholder="<?= $gg['client_secret'] !== '' ? 'gespeichert – leer lassen für unverändert' : '' ?>"></label>
                </div>
                <p class="muted small">Benötigter Bereich: gmail.send. Für Konten außerhalb Ihrer eigenen Google-Organisation verlangt Google eine Prüfung der Anwendung.</p>
            </fieldset>
            <button class="btn primary">Anwendungsdaten speichern</button>
        </form>
        <?php endif;
    });
}

function page_oauth_start(): void
{
    $u = require_manager();
    require_post_csrf();
    $cid = mail_settings_target();
    $acc = mail_account_for($cid);
    if (!$acc || !in_array($acc['provider'], ['microsoft', 'google'], true)) abort(400);
    $p = $acc['provider'];
    if (!oauth_app_configured($p)) abort(400, 'Die Anmeldung für diesen Anbieter ist noch nicht eingerichtet.');
    $state = bin2hex(random_bytes(16));
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $_SESSION['oauth'] = ['state' => $state, 'verifier' => $verifier, 'provider' => $p, 'company_id' => $cid, 'uid' => $u['id'], 'at' => time()];
    $ep = oauth_endpoints($p);
    $q = [
        'client_id' => oauth_app($p)['client_id'], 'response_type' => 'code', 'redirect_uri' => oauth_redirect_uri(),
        'scope' => $ep['scope'], 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'prompt' => $p === 'google' ? 'consent' : 'select_account',
    ];
    if ($p === 'google') $q['access_type'] = 'offline';
    else $q['response_mode'] = 'query';
    // Weiterleitung per Link statt HTTP-Redirect: die Content-Security-Policy (form-action 'self')
    // würde eine Weiterleitung nach einem Formularversand zu einer fremden Domain blockieren.
    $target = $ep['authorize'] . '?' . http_build_query($q);
    render_page('Anmeldung', function () use ($target, $p) { ?>
        <div class="card narrow">
            <p>Sie werden zur Anmeldung bei <?= $p === 'microsoft' ? 'Microsoft' : 'Google' ?> weitergeleitet …</p>
            <p><a id="oauth-go" class="btn primary" href="<?= e($target) ?>">Weiter zur Anmeldung</a></p>
        </div>
    <?php });
    exit;
}

function page_oauth_callback(): void
{
    $u = require_manager();
    $o = $_SESSION['oauth'] ?? null;
    unset($_SESSION['oauth']);
    if (!$o || !hash_equals($o['state'], (string)($_GET['state'] ?? '')) || $o['uid'] !== $u['id'] || time() - $o['at'] > 900) {
        abort(400, 'Die Anmeldung ist abgelaufen oder ungültig. Bitte erneut versuchen.');
    }
    $cid = $o['company_id'];
    if ($cid === null && !is_admin()) abort(403);
    if ($cid !== null) require_company_access((int)$cid);
    $params = is_admin() && $cid ? ['company' => $cid] : [];
    if (!empty($_GET['error'])) {
        flash('error', 'Die Anmeldung wurde abgebrochen: ' . mb_substr((string)($_GET['error_description'] ?? $_GET['error']), 0, 200));
        redirect('mail_settings', $params);
    }
    $acc = mail_account_for($cid);
    if (!$acc || $acc['provider'] !== $o['provider']) abort(400);
    $p = $o['provider'];
    try {
        $tokenParams = ['grant_type' => 'authorization_code', 'code' => (string)($_GET['code'] ?? ''),
            'redirect_uri' => oauth_redirect_uri(), 'code_verifier' => $o['verifier']];
        if ($p === 'microsoft') $tokenParams['scope'] = oauth_endpoints('microsoft')['scope'];
        $tok = oauth_token_request($p, $tokenParams);
        if (empty($tok['refresh_token'])) throw new RuntimeException('Der Anbieter hat keinen dauerhaften Zugriff erteilt (kein Refresh-Token).');
        [$status, $body] = http_call('GET', oauth_endpoints($p)['profile'], ['Authorization: Bearer ' . $tok['access_token'], 'Accept: application/json']);
        $prof = json_decode($body, true) ?: [];
        $email = strtolower((string)($p === 'microsoft' ? ($prof['mail'] ?? $prof['userPrincipalName'] ?? '') : ($prof['email'] ?? '')));
        if ($status !== 200 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Die E-Mail-Adresse des Kontos konnte nicht ermittelt werden.');
        update('mail_accounts', (int)$acc['id'], [
            'from_email' => $email, 'oauth_refresh_enc' => encrypt_secret($tok['refresh_token']),
            'oauth_access_enc' => encrypt_secret($tok['access_token']), 'oauth_expires' => time() + (int)($tok['expires_in'] ?? 3600),
            'last_error' => '', 'active' => 1, 'updated_at' => now(),
        ]);
        audit('mail_account_connected', $p . ': ' . $email, $cid);
        flash('success', "Das Postfach $email ist verbunden. Senden Sie jetzt eine Testnachricht.");
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('mail_settings', $params);
}
