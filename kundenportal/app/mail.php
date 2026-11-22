<?php
/**
 * E-Mail-Versand.
 *
 * Reihenfolge: Postfach der Firma (falls eingerichtet) → zentrales Postfach → PHP mail() des Webhosters.
 * Unterstützt SMTP mit Anmeldung (STARTTLS/SSL), Microsoft 365 (Graph API, OAuth2) und
 * Gmail / Google Workspace (Gmail API, OAuth2). Zugangsdaten werden verschlüsselt gespeichert.
 */

const MAIL_PROVIDERS = [
    'smtp'      => 'SMTP (z. B. IONOS, Strato, eigener Mailserver)',
    'microsoft' => 'Microsoft 365 / Exchange Online (Anmeldung mit Microsoft)',
    'google'    => 'Gmail / Google Workspace (Anmeldung mit Google)',
];

// ---------------------------------------------------------------------------
// Verschlüsselung und Einstellungen
// ---------------------------------------------------------------------------
function secret_key(): string
{
    static $key = null;
    if ($key !== null) return $key;
    $cfgKey = (string)cfg('secret_key', '');
    if ($cfgKey !== '') return $key = hash('sha256', $cfgKey, true);
    $file = APP_DIR . '/data/secret.key';
    if (!is_file($file)) {
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0770, true);
        file_put_contents($file, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), LOCK_EX);
        @chmod($file, 0600);
    }
    return $key = base64_decode(trim((string)file_get_contents($file)));
}

function encrypt_secret(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, secret_key()));
}

function decrypt_secret(?string $enc): string
{
    if (!$enc) return '';
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
        substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), secret_key());
    return $plain === false ? '' : $plain;
}

function setting_get(string $name, string $default = ''): string
{
    $r = row('SELECT value FROM settings WHERE name = ?', [$name]);
    return $r ? (string)$r['value'] : $default;
}

function setting_set(string $name, string $value): void
{
    if (row('SELECT id FROM settings WHERE name = ?', [$name])) {
        q('UPDATE settings SET value = ? WHERE name = ?', [$value, $name]);
    } else {
        insert('settings', ['name' => $name, 'value' => $value]);
    }
}

// ---------------------------------------------------------------------------
// OAuth2 (Microsoft 365 / Google)
// ---------------------------------------------------------------------------
function oauth_app(string $provider): array
{
    return [
        'client_id'     => setting_get("oauth_{$provider}_client_id"),
        'client_secret' => decrypt_secret(setting_get("oauth_{$provider}_client_secret")),
        'tenant'        => setting_get("oauth_{$provider}_tenant", 'organizations') ?: 'organizations',
    ];
}

function oauth_app_configured(string $provider): bool
{
    $a = oauth_app($provider);
    return $a['client_id'] !== '' && $a['client_secret'] !== '';
}

function oauth_redirect_uri(): string
{
    return rtrim((string)cfg('base_url', ''), '/') . '/index.php?r=oauth_callback';
}

function oauth_endpoints(string $provider): array
{
    $tenant = rawurlencode(oauth_app('microsoft')['tenant']);
    $defaults = [
        'microsoft' => [
            'authorize' => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize",
            'token'     => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token",
            'profile'   => 'https://graph.microsoft.com/v1.0/me',
            'send'      => 'https://graph.microsoft.com/v1.0/me/sendMail',
            'scope'     => 'offline_access openid email https://graph.microsoft.com/User.Read https://graph.microsoft.com/Mail.Send',
        ],
        'google' => [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token'     => 'https://oauth2.googleapis.com/token',
            'profile'   => 'https://openidconnect.googleapis.com/v1/userinfo',
            'send'      => 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            'scope'     => 'openid email https://www.googleapis.com/auth/gmail.send',
        ],
    ];
    // nur für Tests: Endpunkte per config.php umleiten
    return array_merge($defaults[$provider], (array)cfg("oauth_endpoints.$provider", []));
}

function http_call(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException(t('Verbindung fehlgeschlagen: ') . $err);
    return [$status, (string)$resp];
}

function oauth_token_request(string $provider, array $params): array
{
    $app = oauth_app($provider);
    $params += ['client_id' => $app['client_id'], 'client_secret' => $app['client_secret']];
    [$status, $body] = http_call('POST', oauth_endpoints($provider)['token'],
        ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($params));
    $data = json_decode($body, true) ?: [];
    if ($status !== 200 || empty($data['access_token'])) {
        throw new RuntimeException(t('Anmeldung beim Anbieter fehlgeschlagen: ') . ($data['error_description'] ?? $data['error'] ?? "HTTP $status"));
    }
    return $data;
}

/** Gültiges Access-Token für ein Konto (erneuert es bei Bedarf per Refresh-Token) */
function oauth_access_token(array $acc): string
{
    if ((int)$acc['oauth_expires'] > time() + 60) {
        $t = decrypt_secret($acc['oauth_access_enc']);
        if ($t !== '') return $t;
    }
    $refresh = decrypt_secret($acc['oauth_refresh_enc']);
    if ($refresh === '') throw new RuntimeException(t('Das Postfach ist nicht (mehr) verbunden. Bitte erneut anmelden.'));
    $params = ['grant_type' => 'refresh_token', 'refresh_token' => $refresh];
    if ($acc['provider'] === 'microsoft') $params['scope'] = oauth_endpoints('microsoft')['scope'];
    $data = oauth_token_request($acc['provider'], $params);
    $upd = ['oauth_access_enc' => encrypt_secret($data['access_token']), 'oauth_expires' => time() + (int)($data['expires_in'] ?? 3600)];
    if (!empty($data['refresh_token'])) $upd['oauth_refresh_enc'] = encrypt_secret($data['refresh_token']);
    update('mail_accounts', (int)$acc['id'], $upd);
    return $data['access_token'];
}

// ---------------------------------------------------------------------------
// Nachricht aufbauen
// ---------------------------------------------------------------------------
function mail_header_clean(string $s): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
}

function mime_encode_header(string $s): string
{
    $s = mail_header_clean($s);
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function build_mime(string $fromEmail, string $fromName, string $to, string $subject, string $body, string $replyTo = ''): string
{
    $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
    $h = [
        'From: ' . ($fromName !== '' ? mime_encode_header($fromName) . ' ' : '') . '<' . mail_header_clean($fromEmail) . '>',
        'To: <' . mail_header_clean($to) . '>',
        'Subject: ' . mime_encode_header($subject),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: Kundenportal',
        'Auto-Submitted: auto-generated',
    ];
    if ($replyTo !== '') $h[] = 'Reply-To: <' . mail_header_clean($replyTo) . '>';
    return implode("\r\n", $h) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
}

// ---------------------------------------------------------------------------
// SMTP
// ---------------------------------------------------------------------------
final class SmtpClient
{
    /** @var resource */
    private $s;
    private array $caps = [];

    public function __construct(string $host, int $port, string $security)
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => !cfg('mail.smtp_insecure_tls', false),
            'verify_peer_name' => !cfg('mail.smtp_insecure_tls', false),
            'SNI_enabled' => true, 'peer_name' => $host,
        ]]);
        // Nur öffentliche Server (Schutz vor SSRF ins interne Netz); verbunden wird mit der geprüften IP,
        // das Zertifikat wird weiter gegen den Hostnamen geprüft (peer_name/SNI)
        $ip = public_ip($host, (bool)cfg('mail.smtp_allow_private', false));
        if ($ip === null) throw new RuntimeException(t('Der SMTP-Server {x} wurde nicht gefunden oder liegt in einem internen Netz.', ['x' => $host]));
        $remote = ($security === 'ssl' ? 'ssl://' : 'tcp://') . $ip . ':' . $port;
        $s = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new RuntimeException(t('Keine Verbindung zu {x}', ['x' => "$host:$port ($errstr)"]));
        stream_set_timeout($s, 20);
        $this->s = $s;
        $this->expect([220]);
        $this->ehlo();
        if ($security === 'tls') {
            if (!isset($this->caps['STARTTLS'])) throw new RuntimeException(t('Der Server unterstützt kein STARTTLS.'));
            $this->cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException(t('TLS-Verschlüsselung konnte nicht aufgebaut werden.'));
            }
            $this->ehlo();
        }
    }

    private function ehlo(): void
    {
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost')) ?: 'localhost';
        $lines = $this->cmd('EHLO ' . $host, [250]);
        $this->caps = [];
        foreach ($lines as $l) {
            $parts = explode(' ', strtoupper(trim(substr($l, 4))));
            $this->caps[$parts[0]] = array_slice($parts, 1);
        }
    }

    private function read(): array
    {
        $lines = [];
        while (($line = fgets($this->s, 2048)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        if (!$lines) throw new RuntimeException(t('Der Mailserver antwortet nicht.'));
        return $lines;
    }

    private function expect(array $codes): array
    {
        $lines = $this->read();
        $code = (int)substr(end($lines), 0, 3);
        if (!in_array($code, $codes, true)) throw new RuntimeException(t('Mailserver: ') . mb_substr(implode(' ', $lines), 0, 300));
        return $lines;
    }

    public function cmd(string $c, array $codes): array
    {
        fwrite($this->s, $c . "\r\n");
        return $this->expect($codes);
    }

    public function auth(string $user, string $pass, ?string $oauthToken = null): void
    {
        $methods = $this->caps['AUTH'] ?? [];
        if ($oauthToken !== null) {
            $this->cmd('AUTH XOAUTH2 ' . base64_encode("user=$user\x01auth=Bearer $oauthToken\x01\x01"), [235]);
        } elseif (in_array('PLAIN', $methods, true)) {
            $this->cmd('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235]);
        } else {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode($user), [334]);
            $this->cmd(base64_encode($pass), [235]);
        }
    }

    public function send(string $from, string $to, string $mime): void
    {
        $this->cmd('MAIL FROM:<' . mail_header_clean($from) . '>', [250]);
        $this->cmd('RCPT TO:<' . mail_header_clean($to) . '>', [250, 251]);
        $this->cmd('DATA', [354]);
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $mime));
        fwrite($this->s, str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
        $this->expect([250]);
    }

    public function quit(): void
    {
        @fwrite($this->s, "QUIT\r\n");
        @fclose($this->s);
    }
}

// ---------------------------------------------------------------------------
// Versand über ein Konto
// ---------------------------------------------------------------------------
function mail_account_for(?int $companyId): ?array
{
    return $companyId
        ? row('SELECT * FROM mail_accounts WHERE company_id = ?', [$companyId])
        : row('SELECT * FROM mail_accounts WHERE company_id IS NULL');
}

function mail_account_ready(?array $acc): bool
{
    if (!$acc || !(int)$acc['active'] || $acc['from_email'] === '') return false;
    if ($acc['provider'] === 'smtp') return $acc['smtp_host'] !== '';
    return !empty($acc['oauth_refresh_enc']) && oauth_app_configured($acc['provider']);
}

function send_via_account(array $acc, string $to, string $subject, string $body): void
{
    $replyTo = (string)$acc['reply_to'];
    try {
        if ($acc['provider'] === 'smtp') {
            $smtp = new SmtpClient($acc['smtp_host'], (int)$acc['smtp_port'], $acc['smtp_security']);
            if ($acc['smtp_user'] !== '') $smtp->auth($acc['smtp_user'], decrypt_secret($acc['secret_enc']));
            $smtp->send($acc['from_email'], $to, build_mime($acc['from_email'], $acc['from_name'], $to, $subject, $body, $replyTo));
            $smtp->quit();
        } elseif ($acc['provider'] === 'microsoft') {
            $msg = ['subject' => mail_header_clean($subject), 'body' => ['contentType' => 'Text', 'content' => $body],
                'toRecipients' => [['emailAddress' => ['address' => $to]]]];
            if ($replyTo !== '') $msg['replyTo'] = [['emailAddress' => ['address' => $replyTo]]];
            [$status, $resp] = http_call('POST', oauth_endpoints('microsoft')['send'],
                ['Authorization: Bearer ' . oauth_access_token($acc), 'Content-Type: application/json'],
                json_encode(['message' => $msg, 'saveToSentItems' => true]));
            if ($status !== 202) throw new RuntimeException(t('Microsoft 365: ') . mb_substr($resp, 0, 300));
        } elseif ($acc['provider'] === 'google') {
            $raw = rtrim(strtr(base64_encode(build_mime($acc['from_email'], $acc['from_name'], $to, $subject, $body, $replyTo)), '+/', '-_'), '=');
            [$status, $resp] = http_call('POST', oauth_endpoints('google')['send'],
                ['Authorization: Bearer ' . oauth_access_token($acc), 'Content-Type: application/json'], json_encode(['raw' => $raw]));
            if ($status !== 200) throw new RuntimeException('Gmail: ' . mb_substr($resp, 0, 300));
        } else {
            throw new RuntimeException(t('Unbekannter Anbieter'));
        }
        update('mail_accounts', (int)$acc['id'], ['last_ok_at' => now()]);
    } catch (Throwable $ex) {
        update('mail_accounts', (int)$acc['id'], ['last_error' => mb_substr($ex->getMessage(), 0, 500), 'last_error_at' => now()]);
        throw $ex;
    }
}

function send_via_php_mail(string $to, string $subject, string $body): void
{
    $from = cfg('mail.from', 'noreply@localhost');
    $fromName = cfg('mail.from_name', cfg('app_name', 'Kundenportal'));
    if (!cfg('mail.enabled', false)) {
        $dir = APP_DIR . '/data';
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        file_put_contents($dir . '/mail.log', '[' . now() . "] An: $to\nBetreff: $subject\n$body\n" . str_repeat('-', 60) . "\n", FILE_APPEND | LOCK_EX);
        return;
    }
    $headers = ['From: ' . mime_encode_header($fromName) . ' <' . $from . '>', 'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit', 'X-Mailer: Kundenportal'];
    if (!@mail($to, mime_encode_header($subject), $body, implode("\r\n", $headers), '-f' . $from)) {
        throw new RuntimeException(t('PHP mail() fehlgeschlagen'));
    }
}

/**
 * Sendet eine Nachricht an jede Adresse einzeln.
 * $companyId: Firma, in deren Namen versendet wird (deren Postfach wird bevorzugt).
 */
function send_mail(array $to, string $subject, string $body, ?int $companyId = null): void
{
    $to = array_values(array_unique(array_filter(array_map('strtolower', $to), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL))));
    if (!$to) return;
    $appName = t(cfg('app_name', 'Kundenportal'));
    $body .= "\n\n--\n" . $appName . t("\nDiese Nachricht wurde automatisch erzeugt.");

    $chain = [];
    if ($companyId) {
        $acc = mail_account_for($companyId);
        if (mail_account_ready($acc)) $chain[] = $acc;
    }
    $central = mail_account_for(null);
    if (mail_account_ready($central)) $chain[] = $central;

    foreach ($to as $addr) {
        $sent = false;
        foreach ($chain as $acc) {
            try {
                send_via_account($acc, $addr, $subject, $body);
                $sent = true;
                break;
            } catch (Throwable $ex) {
                error_log('[kundenportal] Mailversand über Konto ' . $acc['id'] . ' fehlgeschlagen: ' . $ex->getMessage());
            }
        }
        if (!$sent) {
            try {
                send_via_php_mail($addr, $subject, $body);
            } catch (Throwable $ex) {
                error_log('[kundenportal] ' . $ex->getMessage());
            }
        }
    }
}

function request_link(int $id): string
{
    return rtrim((string)cfg('base_url', ''), '/') . '/index.php?r=request&id=' . $id;
}

/** E-Mail-Adressen der Benutzer einer Firma, die Aufträge sehen (Ersteller + Firmen-Admins) */
function company_recipients(int $companyId, ?int $creatorId = null): array
{
    $list = array_column(rows(
        "SELECT email FROM users WHERE company_id = ? AND active = 1 AND role = 'firma_admin'", [$companyId]), 'email');
    if ($creatorId) {
        $c = row('SELECT email, role FROM users WHERE id = ? AND active = 1', [$creatorId]);
        if ($c && !is_staff_role($c['role'])) $list[] = $c['email'];
    }
    $co = row('SELECT contact_email FROM companies WHERE id = ?', [$companyId]);
    if ($co && $co['contact_email']) $list[] = $co['contact_email'];
    return $list;
}
