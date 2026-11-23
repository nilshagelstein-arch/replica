<?php
/**
 * Tickets – E-Mail-Eingang (Stufe 4).
 *
 * Postfächer je Queue oder firmenweit (dann entscheiden Regeln bzw. die Standard-Queue) werden über cron.php
 * und bei Seitenaufrufen abgerufen. Anbieter: IMAP (IONOS, Strato, Gmail mit App-Passwort, eigener Server …),
 * Microsoft 365 (Graph, Anmeldung mit Microsoft) und Gmail / Google Workspace (Gmail API, Anmeldung mit Google).
 *
 * Verarbeitung einer Mail:
 *   1. automatische Antworten und eigene Mails ignorieren (Schleifenschutz)
 *   2. Sperrliste der Firma → abgelehnt; vom Anbieter als Spam markiert → Spam;
 *      dieselbe Nachricht vom selben Absender zum 5. Mal in 24 Std. → Spam
 *   3. Betreff mit [#2026-0001] → Antwort im Ticket (Ersteller, Gruppe oder Bearbeiter)
 *   4. bekannter Absender (Konto der Firma) → neues Ticket; unbekannter Absender → Prüfliste
 * Verarbeitete Mails werden im Postfach als gelesen markiert; abgerufen werden nur ungelesene.
 */
declare(strict_types=1);

const TICKET_MAIL_PROVIDERS = ['imap' => 'IMAP (IONOS, Strato, Gmail mit App-Passwort, eigener Mailserver …)',
    'microsoft' => 'Microsoft 365 / Exchange Online (Anmeldung mit Microsoft)', 'google' => 'Gmail / Google Workspace (Anmeldung mit Google)'];
const TICKET_MAIL_FIELDS = ['from' => 'Absender enthält', 'subject' => 'Betreff enthält', 'to' => 'Empfänger enthält'];
const TICKET_INBOX_STATUSES = ['review' => 'Zu prüfen', 'ticket' => 'Neues Ticket', 'reply' => 'Antwort', 'accepted' => 'Als Ticket übernommen',
    'rejected' => 'Abgelehnt', 'spam' => 'Spam', 'ignored' => 'Ignoriert', 'discarded' => 'Verworfen'];
const TICKET_MAIL_MAX_BYTES = 26214400;   // größere Mails werden nicht übernommen (25 MB)
const TICKET_MAIL_PER_RUN = 25;           // Mails je Postfach und Lauf
const TICKET_MAIL_REPEAT = 5;             // ab der 5. gleichen Mail in 24 Std. → Spam

// ---------------------------------------------------------------------------
// MIME-Parser
// ---------------------------------------------------------------------------

/** Text in UTF-8 umwandeln */
function mailp_utf8(string $s, string $charset = ''): string
{
    $cs = strtolower(trim($charset, "\"' "));
    if ($cs === '' || $cs === 'utf-8' || $cs === 'utf8' || $cs === 'us-ascii') {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
    if ($cs === 'iso-8859-1' || $cs === 'latin1') $cs = 'windows-1252';
    $out = @iconv($cs, 'UTF-8//IGNORE', $s);
    if ($out === false || $out === '') {
        try { $out = mb_convert_encoding($s, 'UTF-8', $cs); } catch (Throwable) { $out = mb_convert_encoding($s, 'UTF-8', 'Windows-1252'); }
    }
    return $out;
}

/** Kopfzeile dekodieren (RFC 2047) */
function mailp_decode_header(string $v): string
{
    $v = trim($v);
    if (str_contains($v, '=?')) {
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d !== false && $d !== '') return trim($d);
        return trim(mb_decode_mimeheader($v));
    }
    return mailp_utf8($v);
}

/** [Kopf-Text, Rumpf] */
function mailp_split(string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    if (str_starts_with($raw, "\n")) return ['', substr($raw, 1)];
    $pos = strpos($raw, "\n\n");
    return $pos === false ? [$raw, ''] : [substr($raw, 0, $pos), substr($raw, $pos + 2)];
}

/** Kopfzeilen: kleingeschriebener Name => Liste der Werte (roh) */
function mailp_headers(string $h): array
{
    $h = preg_replace("/\n[ \t]+/", ' ', $h);
    $out = [];
    foreach (explode("\n", (string)$h) as $line) {
        $p = strpos($line, ':');
        if ($p === false || $p === 0) continue;
        $out[strtolower(trim(substr($line, 0, $p)))][] = trim(substr($line, $p + 1));
    }
    return $out;
}

function mailp_h(array $headers, string $name): string
{
    return (string)($headers[$name][0] ?? '');
}

/** Parameter einer Kopfzeile, z. B. boundary, charset, filename (auch RFC 2231: filename*=UTF-8''…) */
function mailp_param(string $value, string $name): string
{
    $name = strtolower($name);
    $parts = [];
    if (preg_match_all('/;\s*([a-z0-9_.*-]+)\s*=\s*("((?:[^"\\\\]|\\\\.)*)"|[^;]*)/i', $value, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $parts[strtolower($x[1])] = isset($x[3]) && $x[3] !== '' || str_starts_with($x[2], '"') ? stripcslashes($x[3] ?? '') : trim($x[2]);
    }
    if (isset($parts[$name])) return mailp_decode_header($parts[$name]);
    // RFC 2231, auch in Abschnitten (name*0*=…; name*1*=…)
    $joined = '';
    $encoded = false;
    for ($i = 0; $i < 50; $i++) {
        if (isset($parts["$name*$i*"])) { $joined .= $parts["$name*$i*"]; $encoded = true; }
        elseif (isset($parts["$name*$i"])) $joined .= $parts["$name*$i"];
        else break;
    }
    if ($joined === '' && isset($parts["$name*"])) { $joined = $parts["$name*"]; $encoded = true; }
    if ($joined === '') return '';
    if ($encoded && preg_match("/^([^']*)'[^']*'(.*)$/s", $joined, $mm)) return mailp_utf8(rawurldecode($mm[2]), $mm[1]);
    return $encoded ? rawurldecode($joined) : $joined;
}

/** Adresse aus „Name <a@b.de>“ */
function mailp_address(string $v): array
{
    $v = mailp_decode_header($v);
    if (preg_match('/<([^>]+@[^>]+)>/', $v, $m)) {
        $email = strtolower(trim($m[1]));
        $name = trim(str_replace($m[0], '', $v), " \t\"'");
    } elseif (preg_match('/[^\s<>"]+@[^\s<>",;]+/', $v, $m)) {
        $email = strtolower($m[0]);
        $name = '';
    } else {
        return ['', ''];
    }
    return [filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '', mb_substr($name, 0, 190)];
}

function mailp_decode_body(string $body, string $cte): string
{
    $cte = strtolower(trim($cte));
    if ($cte === 'base64') return (string)base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body));
    if ($cte === 'quoted-printable') return quoted_printable_decode(str_replace("\n", "\r\n", $body));
    return $body;
}

/** HTML grob in Text umwandeln */
function mailp_html2text(string $html): string
{
    $html = preg_replace('#<(script|style|head)\b.*?</\1>#is', '', $html);
    $html = preg_replace('#<br\s*/?>#i', "\n", (string)$html);
    $html = preg_replace('#</(p|div|tr|li|h[1-6]|blockquote|table)>#i', "\n", (string)$html);
    $html = preg_replace('#<li\b[^>]*>#i', '- ', (string)$html);
    $text = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
    $text = preg_replace("/ *\n */", "\n", (string)$text);
    return trim((string)preg_replace("/\n{3,}/", "\n\n", (string)$text));
}

/**
 * Mail zerlegen: ['headers', 'from_email', 'from_name', 'to', 'subject', 'message_id', 'text', 'attachments' => [[name, mime, data]]]
 */
function mailp_parse(string $raw): array
{
    [$h, $body] = mailp_split($raw);
    $headers = mailp_headers($h);
    $res = ['text' => '', 'html' => '', 'attachments' => []];
    mailp_walk($headers, $body, $res, 0);
    [$fromEmail, $fromName] = mailp_address(mailp_h($headers, 'reply-to') !== '' && mailp_h($headers, 'from') === '' ? mailp_h($headers, 'reply-to') : mailp_h($headers, 'from'));
    $to = [];
    foreach (['to', 'cc', 'delivered-to', 'x-original-to'] as $k) {
        foreach ($headers[$k] ?? [] as $v) foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $v) ?: [] as $one) {
            [$e] = mailp_address($one);
            if ($e !== '') $to[$e] = true;
        }
    }
    $text = $res['text'] !== '' ? $res['text'] : mailp_html2text($res['html']);
    return [
        'headers' => $headers,
        'from_email' => $fromEmail, 'from_name' => $fromName,
        'to' => array_keys($to),
        'subject' => mb_substr(mailp_decode_header(mailp_h($headers, 'subject')), 0, 250),
        'message_id' => mb_substr(trim(mailp_h($headers, 'message-id'), '<> '), 0, 250),
        'text' => trim(str_replace("\r", '', $text)),
        'attachments' => $res['attachments'],
    ];
}

function mailp_walk(array $headers, string $body, array &$res, int $depth): void
{
    if ($depth > 10) return;
    $ct = mailp_h($headers, 'content-type') ?: 'text/plain';
    $type = strtolower(trim(explode(';', $ct)[0]));
    $cte = mailp_h($headers, 'content-transfer-encoding');
    $disp = mailp_h($headers, 'content-disposition');
    $filename = mailp_param($disp, 'filename') ?: mailp_param($ct, 'name');
    $isAttach = stripos($disp, 'attachment') === 0 || ($filename !== '' && !str_starts_with($type, 'multipart/'));

    if (str_starts_with($type, 'multipart/')) {
        $b = mailp_param($ct, 'boundary');
        if ($b === '') return;
        $chunks = preg_split('/^--' . preg_quote($b, '/') . '(--)?[ \t]*$/m', str_replace("\r\n", "\n", $body)) ?: [];
        array_shift($chunks);   // Präambel
        foreach ($chunks as $chunk) {
            $chunk = preg_replace('/^\n/', '', $chunk, 1);
            if (trim((string)$chunk) === '') continue;
            [$ph, $pb] = mailp_split((string)$chunk);
            mailp_walk(mailp_headers($ph), $pb, $res, $depth + 1);
        }
        return;
    }
    $data = mailp_decode_body($body, $cte);
    if ($type === 'message/rfc822' && !$isAttach) { $isAttach = true; $filename = 'nachricht.eml'; }
    if ($isAttach) {
        // eingebettete Bilder ohne Anhang-Kennzeichen (z. B. Logos in Signaturen) auslassen
        if (stripos($disp, 'attachment') !== 0 && mailp_h($headers, 'content-id') !== '' && str_starts_with($type, 'image/')) return;
        $res['attachments'][] = ['name' => $filename !== '' ? $filename : 'anhang', 'mime' => $type, 'data' => $data];
        return;
    }
    if ($type === 'text/plain') $res['text'] .= ($res['text'] !== '' ? "\n" : '') . mailp_utf8($data, mailp_param($ct, 'charset'));
    elseif ($type === 'text/html') $res['html'] .= mailp_utf8($data, mailp_param($ct, 'charset'));
}

/** Zitierte frühere Nachrichten und Signatur abschneiden (Antworten per Mail) */
function mailp_strip_quote(string $text): string
{
    $orig = trim($text);
    $patterns = [
        '/^\s*-{2,}\s*(Original Message|Ursprüngliche Nachricht|Message d.origine|Forwarded message|Weitergeleitete Nachricht)\s*-{0,}/imu',
        '/^\s*(Am|On|Le)\s[^\n]{4,250}?(\n[^\n]{0,200}?)?(schrieb|wrote|a écrit)[^\n]{0,200}:\s*$/imu',
        '/^\s*(Von|From|De)\s*:\s.*\n\s*(Gesendet|Sent|Envoyé|Datum|Date)\s*:/imu',
        '/^_{20,}\s*$/mu',
        '/^-- ?$/mu',
    ];
    $cut = strlen($text);
    foreach ($patterns as $p) {
        if (preg_match($p, $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] < $cut && $m[0][1] > 0) $cut = $m[0][1];
    }
    $text = substr($text, 0, $cut);
    // Zeilen mit „>“ am Ende entfernen
    $lines = explode("\n", rtrim($text));
    while ($lines && (str_starts_with(ltrim(end($lines)), '>') || trim(end($lines)) === '')) array_pop($lines);
    $text = trim(implode("\n", $lines));
    return $text !== '' ? $text : $orig;
}

// ---------------------------------------------------------------------------
// IMAP-Client (ohne PHP-Erweiterung „imap“, die bei vielen Hostern fehlt)
// ---------------------------------------------------------------------------

final class ImapClient
{
    /** @var resource */
    private $s;
    private int $tag = 0;

    public function __construct(string $host, int $port, string $security)
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => !cfg('mail.smtp_insecure_tls', false), 'verify_peer_name' => !cfg('mail.smtp_insecure_tls', false),
            'SNI_enabled' => true, 'peer_name' => $host,
        ]]);
        $ip = public_ip($host, (bool)cfg('mail.smtp_allow_private', false));
        if ($ip === null) throw new RuntimeException(t('Der Server {x} wurde nicht gefunden oder liegt in einem internen Netz.', ['x' => $host]));
        $remote = ($security === 'ssl' ? 'ssl://' : 'tcp://') . (str_contains($ip, ':') ? "[$ip]" : $ip) . ':' . $port;
        $s = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new RuntimeException(t('Keine Verbindung zu {x}', ['x' => "$host:$port ($errstr)"]));
        stream_set_timeout($s, 30);
        $this->s = $s;
        $greet = $this->line();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greet)) throw new RuntimeException('IMAP: ' . trim($greet));
        if ($security === 'tls') {
            $this->cmd('STARTTLS');
            if (!stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException(t('TLS-Verschlüsselung konnte nicht aufgebaut werden.'));
            }
        }
    }

    private function line(): string
    {
        $l = fgets($this->s, 65536);
        if ($l === false) {
            $meta = stream_get_meta_data($this->s);
            throw new RuntimeException($meta['timed_out'] ?? false ? t('Zeitüberschreitung beim Mailserver.') : t('Verbindung zum Mailserver unterbrochen.'));
        }
        return $l;
    }

    private function read(int $n): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $chunk = fread($this->s, min(65536, $n - strlen($out)));
            if ($chunk === false || $chunk === '') throw new RuntimeException(t('Verbindung zum Mailserver unterbrochen.'));
            $out .= $chunk;
        }
        return $out;
    }

    /** Befehl senden; gibt [Zeilen, Literale] zurück, wirft bei NO/BAD */
    public function cmd(string $command, bool $hideInError = false): array
    {
        $tag = 'A' . (++$this->tag);
        fwrite($this->s, "$tag $command\r\n");
        $lines = [];
        $literals = [];
        while (true) {
            $l = $this->line();
            while (preg_match('/\{(\d+)\}\r?\n$/', $l, $m)) {
                $literals[] = $this->read((int)$m[1]);
                $l = $this->line();
            }
            if (str_starts_with($l, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK/i', $l)) {
                    throw new RuntimeException('IMAP: ' . ($hideInError ? '' : strtok($command, ' ') . ' – ') . trim(substr($l, strlen($tag) + 1)));
                }
                return [$lines, $literals];
            }
            $lines[] = $l;
        }
    }

    private static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    public function login(string $user, string $pass): void
    {
        $this->cmd('LOGIN ' . self::quote($user) . ' ' . self::quote($pass), true);
    }

    public function select(string $folder): void
    {
        $this->cmd('SELECT ' . self::quote($folder !== '' ? $folder : 'INBOX'));
    }

    /** UIDs der ungelesenen Mails */
    public function unseen(): array
    {
        [$lines] = $this->cmd('UID SEARCH UNSEEN');
        $ids = [];
        foreach ($lines as $l) if (preg_match('/^\* SEARCH\s*(.*)$/i', trim($l), $m)) foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $x) if (ctype_digit($x)) $ids[] = (int)$x;
        sort($ids);
        return $ids;
    }

    public function fetch(int $uid): string
    {
        [, $lit] = $this->cmd("UID FETCH $uid (BODY.PEEK[])");
        return (string)($lit[0] ?? '');
    }

    public function size(int $uid): int
    {
        [$lines] = $this->cmd("UID FETCH $uid (RFC822.SIZE)");
        foreach ($lines as $l) if (preg_match('/RFC822\.SIZE (\d+)/i', $l, $m)) return (int)$m[1];
        return 0;
    }

    public function markSeen(int $uid): void
    {
        $this->cmd("UID STORE $uid +FLAGS (\\Seen)");
    }

    public function logout(): void
    {
        try { $this->cmd('LOGOUT'); } catch (Throwable) {}
        @fclose($this->s);
    }
}

// ---------------------------------------------------------------------------
// Postfächer abrufen
// ---------------------------------------------------------------------------

/** Access-Token eines Postfachs (Microsoft/Google), erneuert per Refresh-Token */
function ticket_mb_token(array $mb): string
{
    if ((int)$mb['oauth_expires'] > time() + 60) {
        $t = decrypt_secret($mb['oauth_access_enc']);
        if ($t !== '') return $t;
    }
    $refresh = decrypt_secret($mb['oauth_refresh_enc']);
    if ($refresh === '') throw new RuntimeException(t('Das Postfach ist nicht (mehr) verbunden. Bitte erneut anmelden.'));
    $params = ['grant_type' => 'refresh_token', 'refresh_token' => $refresh];
    if ($mb['provider'] === 'microsoft') $params['scope'] = oauth_endpoints('microsoft')['mailbox_scope'];
    $data = oauth_token_request($mb['provider'], $params);
    $upd = ['oauth_access_enc' => encrypt_secret($data['access_token']), 'oauth_expires' => time() + (int)($data['expires_in'] ?? 3600)];
    if (!empty($data['refresh_token'])) $upd['oauth_refresh_enc'] = encrypt_secret($data['refresh_token']);
    update('ticket_mailboxes', (int)$mb['id'], $upd);
    return $data['access_token'];
}

/** Ist das Postfach vollständig eingerichtet? */
function ticket_mb_ready(array $mb): bool
{
    if ($mb['provider'] === 'imap') return $mb['imap_host'] !== '' && $mb['imap_user'] !== '' && !empty($mb['secret_enc']);
    return !empty($mb['oauth_refresh_enc']) && oauth_app_configured($mb['provider']);
}

/**
 * Ungelesene Mails eines Postfachs holen und verarbeiten.
 * $handler(string $raw): string – gibt den Status zurück. Mit $dryRun nur zählen (Verbindungstest).
 * Gibt ['count' => n, 'results' => [Status => Anzahl]] zurück; wirft bei Verbindungsfehlern.
 */
function ticket_mb_fetch(array $mb, callable $handler, bool $dryRun = false, int $max = TICKET_MAIL_PER_RUN): array
{
    $results = [];
    $count = 0;
    $handle = function (string $raw) use ($handler, &$results) {
        try {
            $st = $handler($raw);
        } catch (Throwable $ex) {
            error_log('[kundenportal] Ticket-Mail: ' . $ex->getMessage());
            $st = 'error';
        }
        $results[$st] = ($results[$st] ?? 0) + 1;
    };
    if ($mb['provider'] === 'imap') {
        $c = new ImapClient($mb['imap_host'], (int)$mb['imap_port'], $mb['imap_security']);
        try {
            $c->login($mb['imap_user'], decrypt_secret($mb['secret_enc']));
            $c->select($mb['imap_folder']);
            $uids = $c->unseen();
            $count = count($uids);
            if (!$dryRun) foreach (array_slice($uids, 0, $max) as $uid) {
                $size = $c->size($uid);
                $handle($size > TICKET_MAIL_MAX_BYTES ? "Subject: (zu groß)\r\nX-Portal-Too-Large: $size\r\n\r\n" : $c->fetch($uid));
                $c->markSeen($uid);
            }
        } finally {
            $c->logout();
        }
    } elseif ($mb['provider'] === 'microsoft') {
        $base = oauth_endpoints('microsoft')['mail_base'];
        $auth = ['Authorization: Bearer ' . ticket_mb_token($mb)];
        [$st, $body] = http_call('GET', $base . '/mailFolders/inbox/messages?' . http_build_query(['$filter' => 'isRead eq false', '$top' => $max, '$select' => 'id']), $auth + [1 => 'Accept: application/json']);
        if ($st !== 200) throw new RuntimeException('Microsoft 365: ' . mb_substr($body, 0, 300));
        $list = json_decode($body, true)['value'] ?? [];
        $count = count($list);
        if (!$dryRun) foreach ($list as $msg) {
            $id = rawurlencode((string)$msg['id']);
            [$st, $raw] = http_call('GET', "$base/messages/$id/\$value", $auth);
            if ($st !== 200) throw new RuntimeException('Microsoft 365: ' . mb_substr($raw, 0, 300));
            $handle(strlen($raw) > TICKET_MAIL_MAX_BYTES ? "Subject: (zu groß)\r\nX-Portal-Too-Large: " . strlen($raw) . "\r\n\r\n" : $raw);
            http_call('PATCH', "$base/messages/$id", $auth + [1 => 'Content-Type: application/json'], json_encode(['isRead' => true]));
        }
    } elseif ($mb['provider'] === 'google') {
        $base = oauth_endpoints('google')['mail_base'];
        $auth = ['Authorization: Bearer ' . ticket_mb_token($mb)];
        [$st, $body] = http_call('GET', $base . '/messages?' . http_build_query(['q' => 'in:inbox is:unread', 'maxResults' => $max]), $auth + [1 => 'Accept: application/json']);
        if ($st !== 200) throw new RuntimeException('Gmail: ' . mb_substr($body, 0, 300));
        $list = json_decode($body, true)['messages'] ?? [];
        $count = count($list);
        if (!$dryRun) foreach ($list as $msg) {
            $id = rawurlencode((string)$msg['id']);
            [$st, $resp] = http_call('GET', "$base/messages/$id?format=raw", $auth + [1 => 'Accept: application/json']);
            if ($st !== 200) throw new RuntimeException('Gmail: ' . mb_substr($resp, 0, 300));
            $raw = (string)base64_decode(strtr((string)(json_decode($resp, true)['raw'] ?? ''), '-_', '+/'));
            $handle($raw);
            http_call('POST', "$base/messages/$id/modify", $auth + [1 => 'Content-Type: application/json'], json_encode(['removeLabelIds' => ['UNREAD']]));
        }
    } else {
        throw new RuntimeException(t('Unbekannter Anbieter'));
    }
    return ['count' => $count, 'results' => $results];
}

/** Ein Postfach abrufen und Ergebnis/Fehler am Postfach vermerken */
function ticket_mb_run(array $mb): array
{
    update('ticket_mailboxes', (int)$mb['id'], ['last_check_at' => now()]);
    try {
        $r = ticket_mb_fetch($mb, fn(string $raw) => ticket_mail_process($mb, $raw));
        update('ticket_mailboxes', (int)$mb['id'], ['last_ok_at' => now(), 'last_error' => '']);
        return $r;
    } catch (Throwable $ex) {
        update('ticket_mailboxes', (int)$mb['id'], ['last_error' => mb_substr($ex->getMessage(), 0, 500)]);
        error_log('[kundenportal] Ticket-Postfach ' . $mb['id'] . ': ' . $ex->getMessage());
        return ['count' => 0, 'results' => [], 'error' => $ex->getMessage()];
    }
}

/** Protokoll aufräumen (einmal täglich): erledigte Einträge nach 90 Tagen, gespeicherte Spam-Mails nach 30 Tagen */
function ticket_inbox_prune(): void
{
    if (setting_get('tickets_inbox_pruned') === date('Y-m-d')) return;
    setting_set('tickets_inbox_pruned', date('Y-m-d'));
    foreach (rows("SELECT id, company_id, stored_name FROM ticket_inbox WHERE stored_name <> '' AND status <> 'review' AND created_at < ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]) as $e) {
        @unlink(ticket_files_dir((int)$e['company_id']) . '/' . basename((string)$e['stored_name']));
        update('ticket_inbox', (int)$e['id'], ['stored_name' => '']);
    }
    q("DELETE FROM ticket_inbox WHERE status <> 'review' AND created_at < ?", [date('Y-m-d H:i:s', time() - 90 * 86400)]);
}

/** Alle aktiven, eingerichteten Postfächer abrufen (höchstens einmal je Minute je Postfach) */
function tickets_mail_fetch_all(): array
{
    $out = [];
    ticket_inbox_prune();
    $limit = date('Y-m-d H:i:s', time() - 60);
    foreach (rows("SELECT * FROM ticket_mailboxes WHERE active = 1 AND (last_check_at = '' OR last_check_at < ?)", [$limit]) as $mb) {
        if (!ticket_mb_ready($mb)) continue;
        if (!company_has_app((int)$mb['company_id'], TICKETS_APP)) continue;
        $r = ticket_mb_run($mb);
        if ($r['results'] || isset($r['error'])) {
            $out[] = $mb['address'] . ': ' . (isset($r['error']) ? $r['error'] : json_encode($r['results']));
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Verarbeitung
// ---------------------------------------------------------------------------

/** Automatische Antworten, Abwesenheitsnotizen, Unzustellbarkeitsberichte, Mailinglisten, eigene Mails */
function ticket_mail_is_auto(array $m): bool
{
    $h = $m['headers'];
    $as = strtolower(mailp_h($h, 'auto-submitted'));
    if ($as !== '' && $as !== 'no') return true;
    if (mailp_h($h, 'x-autoreply') !== '' || mailp_h($h, 'x-autorespond') !== '') return true;
    if (in_array(strtolower(mailp_h($h, 'precedence')), ['bulk', 'junk', 'list', 'auto_reply'], true)) return true;
    if (mailp_h($h, 'list-id') !== '' || mailp_h($h, 'list-unsubscribe') !== '') return true;
    if (stripos(mailp_h($h, 'x-mailer'), 'Kundenportal') !== false) return true;
    if (preg_match('/^(mailer-daemon|postmaster|no-?reply|noreply)@/i', $m['from_email'])) return true;
    return false;
}

/** Vom Anbieter als Spam gekennzeichnet */
function ticket_mail_is_spam(array $m): bool
{
    $h = $m['headers'];
    if (preg_match('/^yes/i', mailp_h($h, 'x-spam-flag')) || preg_match('/^yes/i', mailp_h($h, 'x-spam-status'))) return true;
    if (preg_match('/^\*{5,}/', mailp_h($h, 'x-spam-level'))) return true;
    $scl = mailp_h($h, 'x-ms-exchange-organization-scl');
    if ($scl !== '' && (int)$scl >= 5) return true;
    if (preg_match('/SCL:(\d+)/i', mailp_h($h, 'x-forefront-antispam-report'), $mm) && (int)$mm[1] >= 5) return true;
    return false;
}

/** Sperrliste der Firma: Adressen oder ganze Domains (@firma.de) */
function ticket_blocklist(int $cid): array
{
    $raw = (string)(row('SELECT ticket_blocklist FROM companies WHERE id = ?', [$cid])['ticket_blocklist'] ?? '');
    return array_values(array_filter(array_map(fn($l) => strtolower(trim($l)), preg_split('/[\s,;]+/', $raw) ?: [])));
}

function ticket_is_blocked(int $cid, string $email): bool
{
    $email = strtolower($email);
    foreach (ticket_blocklist($cid) as $b) {
        if ($b === $email || (str_starts_with($b, '@') && str_ends_with($email, $b)) || (!str_contains($b, '@') && str_ends_with($email, '@' . $b))) return true;
    }
    return false;
}

/** Ziel-Queue für eine neue Mail: Postfach der Queue, sonst Regeln, sonst Standard-Queue, sonst erste aktive Queue */
function ticket_mail_queue(array $mb, array $m): ?int
{
    $cid = (int)$mb['company_id'];
    $active = array_map('intval', array_column(rows('SELECT id FROM ticket_queues WHERE company_id = ? AND active = 1 ORDER BY sort, name', [$cid]), 'id'));
    if (!$active) return null;
    if ($mb['queue_id'] && in_array((int)$mb['queue_id'], $active, true)) return (int)$mb['queue_id'];
    foreach (rows('SELECT * FROM ticket_mail_rules WHERE company_id = ? ORDER BY sort, id', [$cid]) as $r) {
        if (!in_array((int)$r['queue_id'], $active, true) || trim($r['pattern']) === '') continue;
        $hay = match ($r['field']) { 'from' => $m['from_email'] . ' ' . $m['from_name'], 'to' => implode(' ', $m['to']), default => $m['subject'] };
        if (mb_stripos($hay, trim($r['pattern'])) !== false) return (int)$r['queue_id'];
    }
    $def = (int)(row('SELECT ticket_default_queue_id FROM companies WHERE id = ?', [$cid])['ticket_default_queue_id'] ?? 0);
    return in_array($def, $active, true) ? $def : $active[0];
}

/** Anhänge aus einer Mail speichern (gleiche Regeln wie beim Hochladen). Gibt die Namen ausgelassener Dateien zurück. */
function ticket_store_mail_files(array $files, int $ticketId, int $messageId, int $cid): array
{
    $dir = ticket_files_dir($cid);
    $skipped = [];
    foreach (array_slice($files, 0, 20) as $f) {
        $name = trim(preg_replace('/[\x00-\x1f\\\\\/:*?"<>|]+/u', '_', (string)$f['name']), ' .') ?: 'anhang';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, TICKET_ATTACH_EXT, true) || strlen($f['data']) > TICKET_ATTACH_MAX || $f['data'] === '') { $skipped[] = $name; continue; }
        $stored = $ticketId . '-' . bin2hex(random_bytes(12)) . '.bin';
        if (file_put_contents($dir . '/' . $stored, $f['data']) === false) { $skipped[] = $name; continue; }
        @chmod($dir . '/' . $stored, 0640);
        insert('ticket_attachments', ['ticket_id' => $ticketId, 'message_id' => $messageId, 'company_id' => $cid, 'filename' => mb_substr($name, 0, 190),
            'mime' => mb_substr((string)$f['mime'], 0, 100), 'size' => strlen($f['data']), 'stored_name' => $stored, 'created_at' => now()]);
    }
    return $skipped;
}

/** Status ändern ohne angemeldeten Benutzer (E-Mail-Eingang); benachrichtigt den Ersteller */
function ticket_set_status_sys(array $tk, string $to, string $label, ?int $userId, bool $notify = true): array
{
    if ($to === $tk['status']) return $tk;
    $data = ['status' => $to, 'updated_at' => now(), 'last_activity_at' => now()];
    if ($to === 'geloest') $data['resolved_at'] = now();
    if (!in_array($to, TICKET_CLOSED, true)) { $data['resolved_at'] = ''; $data['closed_at'] = ''; }
    if ($to !== 'zurueckgestellt') $data['follow_up_at'] = '';
    if (in_array($to, ['offen', 'wieder_eroeffnet'], true) && $tk['waiting_since'] === '') { $data['waiting_since'] = now(); $data['reminder_level'] = 0; }
    update('tickets', (int)$tk['id'], $data);
    ticket_log((int)$tk['id'], (int)$tk['company_id'], $userId, 'event', $label, ['status_from' => $tk['status'], 'status_to' => $to]);
    $from = $tk['status'];
    $tk = load_ticket((int)$tk['id']);
    $t2 = $tk;
    if ($notify) ticket_notify_creator($t2, fn() => t("Der Status Ihres Tickets hat sich geändert: {from} → {to}", [
        'from' => t(TICKET_STATUSES[$from]), 'to' => t(TICKET_STATUSES[$t2['status']])]));
    return $tk;
}

/** Neues Ticket anlegen (E-Mail-Eingang, Übernahme aus der Prüfliste). Gibt die Ticket-ID zurück. */
function ticket_create_record(int $cid, int $queueId, int $createdBy, ?int $enteredBy, string $subject, string $body, string $priority, array $files = []): int
{
    $id = 0;
    for ($try = 0; $try < 3 && !$id; $try++) {
        [$year, $seq, $number] = ticket_next_number($cid);
        try {
            $id = insert('tickets', ['company_id' => $cid, 'seq_year' => $year, 'seq_no' => $seq, 'number' => $number, 'queue_id' => $queueId,
                'subject' => mb_substr($subject !== '' ? $subject : t('(ohne Betreff)'), 0, 190), 'priority' => $priority, 'category' => '', 'status' => 'offen',
                'created_by' => $createdBy, 'entered_by' => $enteredBy, 'employee_id' => null, 'visibility' => 'me', 'group_id' => null,
                'waiting_since' => now(), 'reminder_level' => 0, 'due_at' => '', 'due_notified' => 0,
                'assigned_to' => null, 'follow_up_at' => '', 'resolved_at' => '', 'closed_at' => '', 'viewing_by' => null, 'viewing_at' => '',
                'created_at' => now(), 'updated_at' => now(), 'last_activity_at' => now()]);
        } catch (PDOException $ex) {
            if ($try === 2) throw $ex;
        }
    }
    $msgId = ticket_log($id, $cid, $createdBy, 'message', $body !== '' ? $body : '–');
    $skipped = $files ? ticket_store_mail_files($files, $id, $msgId, $cid) : [];
    if ($skipped) ticket_log($id, $cid, null, 'event', 'Anhänge nicht übernommen: ' . implode(', ', $skipped));
    return $id;
}

/** Mail speichern (für die Prüfliste); gibt den Dateinamen zurück */
function ticket_inbox_store_raw(int $cid, string $raw): string
{
    $name = 'inbox-' . bin2hex(random_bytes(12)) . '.eml';
    file_put_contents(ticket_files_dir($cid) . '/' . $name, $raw);
    @chmod(ticket_files_dir($cid) . '/' . $name, 0640);
    return $name;
}

/** Betreff ohne Re:/AW:/WG: usw. */
function ticket_mail_clean_subject(string $s): string
{
    return trim((string)preg_replace('/^((re|aw|wg|fw|fwd|tr|antw|sv|vs)\s*(\[\d+\])?\s*:\s*)+/iu', '', trim($s)));
}

/**
 * Eine Mail verarbeiten. Gibt den Status zurück (ticket, reply, review, rejected, spam, ignored, duplicate).
 */
function ticket_mail_process(array $mb, string $raw): string
{
    $cid = (int)$mb['company_id'];
    $m = mailp_parse($raw);
    $msgid = $m['message_id'] !== '' ? $m['message_id'] : 'sha256:' . hash('sha256', $raw);
    if (row('SELECT id FROM ticket_inbox WHERE company_id = ? AND message_id = ?', [$cid, $msgid])) return 'duplicate';
    $from = $m['from_email'];
    $subject = $m['subject'];
    $hash = hash('sha256', $from . "\n" . mb_strtolower(ticket_mail_clean_subject($subject)) . "\n" . preg_replace('/\s+/u', ' ', mb_strtolower($m['text'])));
    $log = function (string $status, string $reason = '', ?int $ticketId = null, bool $keepRaw = false) use ($cid, $mb, $m, $msgid, $from, $subject, $hash, $raw) {
        insert('ticket_inbox', ['company_id' => $cid, 'mailbox_id' => (int)$mb['id'], 'message_id' => $msgid, 'from_email' => mb_substr($from, 0, 190),
            'from_name' => $m['from_name'], 'to_email' => mb_substr(implode(', ', $m['to']), 0, 500), 'subject' => $subject,
            'body' => mb_substr($m['text'], 0, 20000), 'content_hash' => $hash, 'attachment_count' => count($m['attachments']),
            'stored_name' => $keepRaw ? ticket_inbox_store_raw($cid, $raw) : '', 'status' => $status, 'reason' => mb_substr($reason, 0, 190),
            'ticket_id' => $ticketId, 'handled_by' => null, 'handled_at' => '', 'created_at' => now()]);
        return $status;
    };

    if (mailp_h($m['headers'], 'x-portal-too-large') !== '') return $log('ignored', 'Mail zu groß (über 25 MB)');
    if ($from === '') return $log('ignored', 'Kein Absender');
    if (ticket_mail_is_auto($m)) return $log('ignored', 'Automatische Nachricht');
    $own = array_map('strtolower', array_filter(array_merge(array_column(rows('SELECT address FROM ticket_mailboxes'), 'address'),
        array_column(rows('SELECT from_email FROM mail_accounts'), 'from_email'), [(string)cfg('mail.from', '')])));
    if (in_array($from, $own, true)) return $log('ignored', 'Eigene Nachricht');
    if (ticket_is_blocked($cid, $from)) return $log('rejected', 'Sperrliste');
    if (ticket_mail_is_spam($m)) return $log('spam', 'Vom Anbieter als Spam markiert', null, true);
    $same = (int)row('SELECT COUNT(*) AS n FROM ticket_inbox WHERE company_id = ? AND from_email = ? AND content_hash = ? AND created_at >= ?',
        [$cid, $from, $hash, date('Y-m-d H:i:s', time() - 86400)])['n'];
    if ($same >= TICKET_MAIL_REPEAT - 1) return $log('spam', 'Gleiche Nachricht mehrfach in 24 Std.: ' . ($same + 1) . '×');

    $user = row('SELECT * FROM users WHERE LOWER(email) = ? AND company_id = ? AND active = 1', [$from, $cid]);
    $GLOBALS['ticket_actor'] = $user ?: null;
    try {
        // Antwort auf ein Ticket?
        $refClosed = null;
        if (preg_match('/\[#(\d{4}-\d{3,})\]/', $subject, $mm)) {
            $tk = row('SELECT id FROM tickets WHERE company_id = ? AND number = ?', [$cid, $mm[1]]);
            $tk = $tk ? load_ticket((int)$tk['id']) : null;
            if ($tk) {
                if (!$user) return $log('review', 'Antwort von unbekanntem Absender: #' . $tk['number'], (int)$tk['id'], true);
                $role = ticket_mail_role($tk, $user);
                if ($role === null) return $log('review', 'Absender ist am Ticket nicht beteiligt: #' . $tk['number'], (int)$tk['id'], true);
                if ($tk['status'] === 'geschlossen') {
                    $refClosed = $tk;
                } else {
                    ticket_mail_reply($tk, $user, $role, $m);
                    return $log('reply', '', (int)$tk['id']);
                }
            }
        }
        if (!$user) return $log('review', 'Unbekannter Absender', null, true);
        $queueId = ticket_mail_queue($mb, $m);
        if (!$queueId) return $log('review', 'Keine aktive Queue', null, true);
        $prio = preg_match('/^(1|2)\b/', mailp_h($m['headers'], 'x-priority')) || strtolower(mailp_h($m['headers'], 'importance')) === 'high' ? 'blockiert' : 'einschraenkung';
        $body = $m['text'];
        if ($refClosed) $body = t('Bezug: Ticket #{n} (geschlossen)', ['n' => $refClosed['number']]) . "\n\n" . mailp_strip_quote($body);
        $id = ticket_create_record($cid, $queueId, (int)$user['id'], null, ticket_mail_clean_subject(preg_replace('/\s*\[#\d{4}-\d{3,}\]\s*/', ' ', $subject)), $body, $prio, $m['attachments']);
        ticket_log($id, $cid, null, 'event', 'Per E-Mail eingegangen');
        $tk = load_ticket($id);
        ticket_notify_processors($tk, fn() => t("Neues Ticket in der Queue „{queue}“\nVon: {name}\nDringlichkeit: {prio}\n\n", [
            'queue' => $tk['queue_name'], 'name' => $tk['creator_name'] ?? '–', 'prio' => t(TICKET_PRIORITIES[$tk['priority']])]) . $body, true);
        ticket_send([$user['email']], $tk, fn() => t('Ihr Ticket #{n} ist eingegangen. Sie werden über jede Änderung informiert.', ['n' => $tk['number']]));
        audit('ticket.create_mail', '#' . $tk['number'] . ' ' . $tk['subject'], $cid);
        return $log('ticket', '', $id);
    } finally {
        unset($GLOBALS['ticket_actor']);
    }
}

/** Rolle eines Benutzers am Ticket: requester (Ersteller oder Gruppe), processor oder null */
function ticket_mail_role(array $tk, array $user): ?string
{
    $uid = (int)$user['id'];
    if ((int)$tk['created_by'] === $uid) return 'requester';
    foreach (ticket_queue_processors((int)$tk['queue_id'], (int)$tk['company_id']) as $p) if ((int)$p['id'] === $uid) return 'processor';
    if (($tk['visibility'] ?? 'me') === 'group' && $tk['group_id'] && isset(user_groups($uid)[(int)$tk['group_id']])) return 'requester';
    return null;
}

/** Antwort per Mail ins Ticket übernehmen (wie eine Antwort im Portal) */
function ticket_mail_reply(array $tk, array $user, string $role, array $m): void
{
    $id = (int)$tk['id'];
    $cid = (int)$tk['company_id'];
    $body = mailp_strip_quote($m['text']);
    if ($body === '') $body = '–';
    $msgId = ticket_log($id, $cid, (int)$user['id'], 'message', $body);
    $skipped = ticket_store_mail_files($m['attachments'], $id, $msgId, $cid);
    if ($skipped) ticket_log($id, $cid, null, 'event', 'Anhänge nicht übernommen: ' . implode(', ', $skipped));
    update('tickets', $id, ['updated_at' => now(), 'last_activity_at' => now()]);
    $isCreator = (int)$tk['created_by'] === (int)$user['id'];
    if ($role === 'requester') {
        ticket_wait_start($id);
        ticket_notify_processors($tk, fn() => t("{name} hat geantwortet:\n\n", ['name' => $user['name']]) . $body, false, $isCreator ? [] : [(int)$tk['created_by']]);
        if (!$isCreator) ticket_notify_creator($tk, fn() => t("{name} hat auf Ihr Ticket geantwortet:\n\n", ['name' => $user['name']]) . $body);
        $tk = load_ticket($id);
        if ($tk['status'] === 'rueckfrage') ticket_set_status_sys($tk, $tk['assigned_to'] ? 'in_bearbeitung' : 'offen', 'Rückfrage beantwortet', (int)$user['id']);
        elseif ($tk['status'] === 'geloest') ticket_set_status_sys($tk, 'wieder_eroeffnet', 'Vom Ersteller wieder eröffnet', (int)$user['id']);
    } else {
        ticket_wait_stop($id);
        ticket_notify_creator($tk, fn() => t("{name} hat auf Ihr Ticket geantwortet:\n\n", ['name' => $user['name']]) . $body);
    }
}


// ---------------------------------------------------------------------------
// Einstellungen: Postfächer, Regeln, Standard-Queue, Sperrliste (Teil von „Queues & Einstellungen“)
// ---------------------------------------------------------------------------

/** POST-Aktionen der Einstellungsseite; gibt true zurück, wenn behandelt (leitet dann um) */
function ticket_mail_settings_post(int $cid, string $action): bool
{
    $queueIds = array_map('intval', array_column(rows('SELECT id FROM ticket_queues WHERE company_id = ?', [$cid]), 'id'));
    $mbFor = function () use ($cid): array {
        $mb = row('SELECT * FROM ticket_mailboxes WHERE id = ? AND company_id = ?', [(int)post('mailbox_id', 0), $cid]);
        if (!$mb) abort(404);
        return $mb;
    };
    switch ($action) {
        case 'mb_save':
            $id = (int)post('mailbox_id', 0);
            $old = $id ? $mbFor() : null;
            $provider = isset(TICKET_MAIL_PROVIDERS[post('provider')]) ? (string)post('provider') : 'imap';
            $qid = (int)post('queue_id', 0);
            $data = ['address' => strtolower(mb_substr(trim((string)post('address')), 0, 190)), 'provider' => $provider,
                'queue_id' => in_array($qid, $queueIds, true) ? $qid : null, 'active' => post('active') === '1' ? 1 : 0,
                'imap_host' => mb_substr(trim((string)post('imap_host')), 0, 190), 'imap_port' => max(1, min(65535, (int)post('imap_port', 993))),
                'imap_security' => in_array(post('imap_security'), ['ssl', 'tls', 'none'], true) ? (string)post('imap_security') : 'ssl',
                'imap_user' => mb_substr(trim((string)post('imap_user')), 0, 190), 'imap_folder' => mb_substr(trim((string)post('imap_folder')) ?: 'INBOX', 0, 190)];
            if (!filter_var($data['address'], FILTER_VALIDATE_EMAIL)) { flash('error', t('Bitte eine gültige E-Mail-Adresse angeben.')); tredirect('ticket_settings'); }
            if ($provider === 'imap' && $data['imap_user'] === '') $data['imap_user'] = $data['address'];
            $pw = (string)post('imap_password');
            if ($pw !== '') $data['secret_enc'] = encrypt_secret($pw);
            if ($old && $old['provider'] !== $provider) $data += ['oauth_refresh_enc' => null, 'oauth_access_enc' => null, 'oauth_expires' => 0];
            if ($old) update('ticket_mailboxes', $id, $data + ['last_error' => '']);
            else $id = insert('ticket_mailboxes', $data + ['company_id' => $cid, 'created_at' => now()]);
            audit('ticket.mailbox', $data['address'] . ' (' . $provider . ')', $cid);
            flash('success', $provider !== 'imap' && !ticket_mb_ready(row('SELECT * FROM ticket_mailboxes WHERE id = ?', [$id]))
                ? t('Das Postfach wurde gespeichert. Melden Sie es jetzt mit {x} an.', ['x' => $provider === 'microsoft' ? 'Microsoft' : 'Google'])
                : t('Das Postfach wurde gespeichert. Prüfen Sie es mit „Verbindung testen“.'));
            tredirect('ticket_settings', ['mb' => $id]);
        case 'mb_delete':
            $mb = $mbFor();
            q('DELETE FROM ticket_mailboxes WHERE id = ?', [$mb['id']]);
            audit('ticket.mailbox_delete', $mb['address'], $cid);
            flash('success', t('Das Postfach wurde entfernt.'));
            tredirect('ticket_settings');
        case 'mb_test':
        case 'mb_fetch':
            $mb = $mbFor();
            if (!ticket_mb_ready($mb)) { flash('error', t('Das Postfach ist noch nicht vollständig eingerichtet.')); tredirect('ticket_settings', ['mb' => $mb['id']]); }
            if ($action === 'mb_test') {
                try {
                    $r = ticket_mb_fetch($mb, fn() => 'none', true);
                    update('ticket_mailboxes', (int)$mb['id'], ['last_ok_at' => now(), 'last_error' => '']);
                    flash('success', t('Verbindung in Ordnung. Ungelesene Mails im Posteingang: {n}', ['n' => $r['count']]));
                } catch (Throwable $ex) {
                    update('ticket_mailboxes', (int)$mb['id'], ['last_error' => mb_substr($ex->getMessage(), 0, 500)]);
                    flash('error', t('Verbindung fehlgeschlagen: ') . $ex->getMessage());
                }
            } else {
                $r = ticket_mb_run($mb);
                if (isset($r['error'])) flash('error', t('Abruf fehlgeschlagen: ') . $r['error']);
                else {
                    $parts = [];
                    foreach ($r['results'] as $st => $n) $parts[] = $n . ' × ' . t(TICKET_INBOX_STATUSES[$st] ?? $st);
                    flash('success', $parts ? t('Abgerufen: {x}', ['x' => implode(', ', $parts)]) : t('Keine neuen Mails.'));
                }
            }
            tredirect('ticket_settings', ['mb' => $mb['id']]);
        case 'rule_add':
            $field = isset(TICKET_MAIL_FIELDS[post('field')]) ? (string)post('field') : 'subject';
            $pattern = mb_substr(trim((string)post('pattern')), 0, 190);
            $qid = (int)post('queue_id', 0);
            if ($pattern === '' || !in_array($qid, $queueIds, true)) { flash('error', t('Bitte Text und Queue angeben.')); tredirect('ticket_settings'); }
            $sort = (int)(row('SELECT MAX(sort) AS m FROM ticket_mail_rules WHERE company_id = ?', [$cid])['m'] ?? 0) + 10;
            insert('ticket_mail_rules', ['company_id' => $cid, 'field' => $field, 'pattern' => $pattern, 'queue_id' => $qid, 'sort' => $sort]);
            audit('ticket.rule', $field . ': ' . $pattern, $cid);
            flash('success', t('Die Regel wurde angelegt.'));
            tredirect('ticket_settings');
        case 'rule_delete':
            q('DELETE FROM ticket_mail_rules WHERE id = ? AND company_id = ?', [(int)post('rule_id', 0), $cid]);
            flash('success', t('Die Regel wurde gelöscht.'));
            tredirect('ticket_settings');
        case 'mail_settings':
            $def = (int)post('default_queue_id', 0);
            $list = array_values(array_unique(array_filter(array_map(fn($l) => strtolower(mb_substr(trim($l), 0, 190)), preg_split('/[\s,;]+/', (string)post('blocklist')) ?: []))));
            update('companies', $cid, ['ticket_default_queue_id' => in_array($def, $queueIds, true) ? $def : null, 'ticket_blocklist' => implode("\n", array_slice($list, 0, 500))]);
            audit('ticket.mail_settings', count($list) . ' gesperrt', $cid);
            flash('success', t('Die Einstellungen wurden gespeichert.'));
            tredirect('ticket_settings');
    }
    return false;
}

function ticket_mail_settings_section(int $cid, array $queues): void
{
    $mbs = rows('SELECT * FROM ticket_mailboxes WHERE company_id = ? ORDER BY address', [$cid]);
    $rules = rows('SELECT * FROM ticket_mail_rules WHERE company_id = ? ORDER BY sort, id', [$cid]);
    $co = row('SELECT ticket_default_queue_id, ticket_blocklist FROM companies WHERE id = ?', [$cid]);
    $qnames = array_column($queues, 'name', 'id');
    $open = query_int('mb');
    $mayRead = !is_staff_role(current_user()['role']) || tickets_staff_may_see($cid);
    $review = $mayRead ? (int)row("SELECT COUNT(*) AS n FROM ticket_inbox WHERE company_id = ? AND status = 'review'", [$cid])['n'] : 0; ?>
    <div class="card" id="mail">
        <h2><?= e(t('E-Mail-Eingang')) ?></h2>
        <p class="muted"><?= e(t('Tickets per E-Mail: Mails an ein Postfach werden zu Tickets, Antworten mit der Ticketnummer im Betreff landen im Verlauf. Absender müssen ein Konto in der Firma haben; Mails unbekannter Absender kommen in die Prüfliste. Abgerufen werden ungelesene Mails im Posteingang, danach sind sie als gelesen markiert – das Postfach sollte daher nur für die Tickets genutzt werden.')) ?></p>
        <?php if ($review): ?><p><a class="btn" href="<?= e(turl('ticket_inbox')) ?>">📥 <?= e(t('Prüfliste: {n} Mails', ['n' => $review])) ?></a></p><?php endif; ?>
        <?php foreach ($mbs as $mb): ?>
            <details class="queue-box" <?= $open === (int)$mb['id'] ? 'open' : '' ?>>
                <summary><strong><?= e($mb['address']) ?></strong> <?= (int)$mb['active'] ? '' : '<span class="tag">' . e(t('inaktiv')) . '</span>' ?>
                    <span class="muted small">· <?= e($mb['queue_id'] && isset($qnames[$mb['queue_id']]) ? t('Queue: {x}', ['x' => $qnames[$mb['queue_id']]]) : t('alle Queues (Regeln)')) ?>
                    · <?= e($mb['provider'] === 'imap' ? 'IMAP' : ($mb['provider'] === 'microsoft' ? 'Microsoft 365' : 'Google')) ?>
                    <?php if ($mb['last_error'] !== ''): ?> · <span class="tag danger"><?= e(t('Fehler')) ?></span><?php elseif ($mb['last_ok_at'] !== ''): ?> · <?= e(t('zuletzt abgerufen {x}', ['x' => ticket_age($mb['last_ok_at'])])) ?><?php elseif (!ticket_mb_ready($mb)): ?> · <span class="tag warn"><?= e(t('nicht eingerichtet')) ?></span><?php endif; ?></span></summary>
                <?php if ($mb['last_error'] !== ''): ?><div class="alert error"><?= e($mb['last_error']) ?></div><?php endif; ?>
                <?php ticket_mailbox_form($mb, $queues); ?>
                <div class="btn-row">
                    <?php if ($mb['provider'] !== 'imap'): ?>
                        <?php if (!oauth_app_configured($mb['provider'])): ?>
                            <p class="muted small"><?= e(t('Die Anmeldung mit {x} richtet der IT-Dienstleister einmalig unter „E-Mail-Versand“ ein (App-Registrierung).', ['x' => $mb['provider'] === 'microsoft' ? 'Microsoft' : 'Google'])) ?></p>
                        <?php else: ?>
                        <form method="post" action="<?= e(turl('ticket_mb_oauth')) ?>"><?= csrf_field() ?><input type="hidden" name="mailbox_id" value="<?= (int)$mb['id'] ?>">
                            <button class="btn primary"><?= e($mb['oauth_refresh_enc'] ? t('Erneut anmelden') : t('Mit {x} anmelden', ['x' => $mb['provider'] === 'microsoft' ? 'Microsoft' : 'Google'])) ?></button></form>
                        <?php endif; ?>
                    <?php endif; ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="mailbox_id" value="<?= (int)$mb['id'] ?>">
                        <button class="btn" name="action" value="mb_test"><?= e(t('Verbindung testen')) ?></button>
                        <button class="btn" name="action" value="mb_fetch"><?= e(t('Jetzt abrufen')) ?></button>
                        <button class="btn danger" name="action" value="mb_delete" data-confirm="<?= e(t('Postfach {x} entfernen? Die Mails im Postfach bleiben erhalten.', ['x' => $mb['address']])) ?>"><?= e(t('Entfernen')) ?></button></form>
                </div>
            </details>
        <?php endforeach; ?>
        <details class="queue-box" <?= $mbs ? '' : '' ?>>
            <summary><strong><?= e(t('+ Postfach hinzufügen')) ?></strong></summary>
            <?php ticket_mailbox_form(null, $queues); ?>
        </details>

        <h3><?= e(t('Zuordnung für Postfächer ohne feste Queue')) ?></h3>
        <p class="muted small"><?= e(t('Die erste passende Regel bestimmt die Queue; passt keine, kommt das Ticket in die Standard-Queue.')) ?></p>
        <?php if ($rules): ?>
        <table class="table">
            <tbody>
            <?php foreach ($rules as $r): ?>
                <tr><td><?= e(t(TICKET_MAIL_FIELDS[$r['field']] ?? $r['field'])) ?> „<?= e($r['pattern']) ?>“</td><td>→ <?= e($qnames[$r['queue_id']] ?? '?') ?></td>
                    <td class="right"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rule_delete"><input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                        <button class="btn small"><?= e(t('Löschen')) ?></button></form></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($queues): ?>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="rule_add">
            <select name="field" aria-label="<?= e(t('Feld')) ?>"><?php foreach (TICKET_MAIL_FIELDS as $k => $l): ?><option value="<?= e($k) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select>
            <input name="pattern" maxlength="190" required placeholder="<?= e(t('z. B. Drucker oder @lieferant.de')) ?>" aria-label="<?= e(t('Text')) ?>">
            <select name="queue_id" aria-label="<?= e(t('Queue')) ?>"><?php foreach ($queues as $q): ?><option value="<?= (int)$q['id'] ?>"><?= e($q['name']) ?></option><?php endforeach; ?></select>
            <button class="btn small"><?= e(t('Regel hinzufügen')) ?></button>
        </form>
        <?php endif; ?>

        <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="mail_settings">
            <label><?= e(t('Standard-Queue')) ?> <select name="default_queue_id"><option value="0"><?= e(t('– erste aktive Queue –')) ?></option>
                <?php foreach ($queues as $q): ?><option value="<?= (int)$q['id'] ?>" <?= (int)($co['ticket_default_queue_id'] ?? 0) === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?>
            </select></label>
            <label><?= e(t('Sperrliste (eine Adresse oder @domain.de pro Zeile)')) ?> <textarea name="blocklist" rows="4"><?= e((string)($co['ticket_blocklist'] ?? '')) ?></textarea>
                <span class="muted small"><?= e(t('Mails dieser Absender werden abgelehnt. Außerdem gelten als Spam: Mails, die der Mailanbieter als Spam kennzeichnet, und dieselbe Nachricht vom selben Absender ab dem 5. Mal innerhalb von 24 Stunden.')) ?></span></label>
            <button class="btn primary"><?= e(t('Speichern')) ?></button>
        </form>
    </div>
<?php }

function ticket_mailbox_form(?array $mb, array $queues): void
{
    $p = $mb['provider'] ?? 'imap'; ?>
    <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="mb_save"><input type="hidden" name="mailbox_id" value="<?= (int)($mb['id'] ?? 0) ?>">
        <div class="grid2">
            <label><?= e(t('E-Mail-Adresse *')) ?> <input type="email" name="address" required maxlength="190" value="<?= e($mb['address'] ?? '') ?>" placeholder="helpdesk@firma.de"></label>
            <label><?= e(t('Queue')) ?> <select name="queue_id"><option value="0"><?= e(t('– alle Queues: Regeln und Standard-Queue –')) ?></option>
                <?php foreach ($queues as $q): ?><option value="<?= (int)$q['id'] ?>" <?= (int)($mb['queue_id'] ?? 0) === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?>
            </select></label>
        </div>
        <label><?= e(t('Anbieter')) ?> <select name="provider">
            <?php foreach (TICKET_MAIL_PROVIDERS as $k => $l): ?><option value="<?= e($k) ?>" <?= $p === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?>
        </select><span class="muted small"><?= e(t('Microsoft 365 und Google: nach dem Speichern „Anmelden“ klicken. IMAP: z. B. imap.ionos.de, imap.strato.de oder imap.gmail.com (mit App-Passwort), Port 993, SSL.')) ?></span></label>
        <fieldset><legend><?= e(t('Nur für IMAP')) ?></legend>
            <div class="grid2">
                <label><?= e(t('IMAP-Server')) ?> <input name="imap_host" maxlength="190" value="<?= e($mb['imap_host'] ?? '') ?>" placeholder="imap.ionos.de"></label>
                <label><?= e(t('Port')) ?> <input type="number" name="imap_port" min="1" max="65535" value="<?= (int)($mb['imap_port'] ?? 993) ?>"></label>
                <label><?= e(t('Verschlüsselung')) ?> <select name="imap_security">
                    <?php foreach (['ssl' => 'SSL/TLS (993)', 'tls' => 'STARTTLS (143)', 'none' => t('keine (nicht empfohlen)')] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($mb['imap_security'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></label>
                <label><?= e(t('Ordner')) ?> <input name="imap_folder" maxlength="190" value="<?= e($mb['imap_folder'] ?? 'INBOX') ?>"></label>
                <label><?= e(t('Benutzername')) ?> <input name="imap_user" maxlength="190" value="<?= e($mb['imap_user'] ?? '') ?>" placeholder="<?= e(t('meist die E-Mail-Adresse')) ?>" autocomplete="off"></label>
                <label><?= e(t('Passwort')) ?> <input type="password" name="imap_password" autocomplete="new-password" placeholder="<?= e(!empty($mb['secret_enc']) ? t('gespeichert – leer lassen, um es zu behalten') : '') ?>"></label>
            </div>
        </fieldset>
        <label class="check"><input type="checkbox" name="active" value="1" <?= !$mb || (int)$mb['active'] ? 'checked' : '' ?>> <?= e(t('Aktiv (wird regelmäßig abgerufen)')) ?></label>
        <button class="btn primary"><?= e(t('Postfach speichern')) ?></button>
    </form>
<?php }

// ---------------------------------------------------------------------------
// Anmeldung mit Microsoft / Google für ein Postfach
// ---------------------------------------------------------------------------

function page_ticket_mb_oauth(): void
{
    if (!is_post()) abort(405);
    require_post_csrf();
    $cid = tickets_company_id();
    if (!tickets_can_manage()) abort(403);
    $mb = row('SELECT * FROM ticket_mailboxes WHERE id = ? AND company_id = ?', [(int)post('mailbox_id', 0), $cid]);
    if (!$mb || $mb['provider'] === 'imap') abort(400);
    $p = $mb['provider'];
    if (!oauth_app_configured($p)) abort(400, t('Die Anmeldung für diesen Anbieter ist noch nicht eingerichtet.'));
    $state = bin2hex(random_bytes(16));
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $_SESSION['oauth'] = ['state' => $state, 'verifier' => $verifier, 'provider' => $p, 'company_id' => $cid, 'uid' => current_user()['id'], 'at' => time(),
        'purpose' => 'ticket_mailbox', 'mailbox_id' => (int)$mb['id']];
    $ep = oauth_endpoints($p);
    $q = ['client_id' => oauth_app($p)['client_id'], 'response_type' => 'code', 'redirect_uri' => oauth_redirect_uri(),
        'scope' => $ep['mailbox_scope'], 'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'prompt' => $p === 'google' ? 'consent' : 'select_account', 'login_hint' => $mb['address']];
    if ($p === 'google') $q['access_type'] = 'offline';
    else $q['response_mode'] = 'query';
    $target = $ep['authorize'] . '?' . http_build_query($q);
    render_page(t('Anmeldung'), function () use ($target, $p, $mb) { ?>
        <div class="card narrow">
            <p><?= e(t('Melden Sie sich mit dem Postfach {x} an.', ['x' => $mb['address']])) ?></p>
            <p><a id="oauth-go" class="btn primary" href="<?= e($target) ?>"><?= e(t('Weiter zur Anmeldung')) ?></a></p>
        </div>
    <?php });
    exit;
}

/** Rückkehr von Microsoft/Google (aufgerufen aus page_oauth_callback) */
function ticket_mb_oauth_finish(array $o): void
{
    $cid = (int)$o['company_id'];
    require_company_access($cid);
    $back = is_admin() ? ['company' => $cid, 'mb' => $o['mailbox_id']] : ['mb' => $o['mailbox_id']];
    $mb = row('SELECT * FROM ticket_mailboxes WHERE id = ? AND company_id = ?', [$o['mailbox_id'], $cid]);
    if (!$mb || $mb['provider'] !== $o['provider']) abort(400);
    if (!empty($_GET['error'])) {
        flash('error', t('Die Anmeldung wurde abgebrochen: ') . mb_substr((string)($_GET['error_description'] ?? $_GET['error']), 0, 200));
        redirect('ticket_settings', $back);
    }
    $p = $o['provider'];
    try {
        $params = ['grant_type' => 'authorization_code', 'code' => (string)($_GET['code'] ?? ''), 'redirect_uri' => oauth_redirect_uri(), 'code_verifier' => $o['verifier']];
        if ($p === 'microsoft') $params['scope'] = oauth_endpoints('microsoft')['mailbox_scope'];
        $tok = oauth_token_request($p, $params);
        if (empty($tok['refresh_token'])) throw new RuntimeException(t('Der Anbieter hat keinen dauerhaften Zugriff erteilt (kein Refresh-Token).'));
        [$status, $body] = http_call('GET', oauth_endpoints($p)['profile'], ['Authorization: Bearer ' . $tok['access_token'], 'Accept: application/json']);
        $prof = json_decode($body, true) ?: [];
        $email = strtolower((string)($p === 'microsoft' ? ($prof['mail'] ?? $prof['userPrincipalName'] ?? '') : ($prof['email'] ?? '')));
        if ($status !== 200 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException(t('Die E-Mail-Adresse des Kontos konnte nicht ermittelt werden.'));
        update('ticket_mailboxes', (int)$mb['id'], ['oauth_refresh_enc' => encrypt_secret($tok['refresh_token']), 'oauth_access_enc' => encrypt_secret($tok['access_token']),
            'oauth_expires' => time() + (int)($tok['expires_in'] ?? 3600), 'last_error' => '', 'active' => 1]);
        audit('ticket.mailbox_connected', $p . ': ' . $email, $cid);
        flash('success', $email === $mb['address'] ? t('Das Postfach {x} ist verbunden.', ['x' => $email])
            : t('Verbunden als {x}. Achtung: Das ist nicht die eingetragene Adresse {y}.', ['x' => $email, 'y' => $mb['address']]));
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('ticket_settings', $back);
}

// ---------------------------------------------------------------------------
// Prüfliste: Mails unbekannter Absender, Spam, Protokoll
// ---------------------------------------------------------------------------

function page_ticket_inbox(): void
{
    $cid = tickets_company_id();
    if (!tickets_can_manage()) abort(403, t('Die Prüfliste sehen die Administratoren der Firma.'));
    $u = current_user();
    if (is_staff_role($u['role']) && !tickets_staff_may_see($cid)) abort(403, t('Die Tickets dieser Firma sind nur intern sichtbar.'));
    $users = rows('SELECT id, name, email FROM users WHERE company_id = ? AND active = 1 ORDER BY name', [$cid]);
    $queues = rows('SELECT id, name FROM ticket_queues WHERE company_id = ? AND active = 1 ORDER BY sort, name', [$cid]);

    if (is_post()) {
        require_post_csrf();
        $e = row('SELECT * FROM ticket_inbox WHERE id = ? AND company_id = ?', [(int)post('id', 0), $cid]);
        if (!$e) abort(404);
        $done = function (string $status, ?int $ticketId = null) use ($e, $u, $cid) {
            update('ticket_inbox', (int)$e['id'], ['status' => $status, 'ticket_id' => $ticketId ?? ($e['ticket_id'] ?: null), 'handled_by' => $u['id'], 'handled_at' => now(), 'stored_name' => '']);
            if ($e['stored_name'] !== '') @unlink(ticket_files_dir($cid) . '/' . basename((string)$e['stored_name']));
        };
        $action = (string)post('action');
        if ($action === 'accept') {
            $uid = (int)post('user_id', 0);
            $qid = (int)post('queue_id', 0);
            if (!in_array($uid, array_map('intval', array_column($users, 'id')), true) || !in_array($qid, array_map('intval', array_column($queues, 'id')), true)) {
                flash('error', t('Bitte Person und Queue wählen.'));
                tredirect('ticket_inbox');
            }
            $files = [];
            $body = (string)$e['body'];
            if ($e['stored_name'] !== '' && is_file($f = ticket_files_dir($cid) . '/' . basename((string)$e['stored_name']))) {
                $m = mailp_parse((string)file_get_contents($f));
                $files = $m['attachments'];
                $body = $m['text'];
            }
            $intro = t('Per E-Mail von {x}', ['x' => trim($e['from_name'] . ' <' . $e['from_email'] . '>')]);
            $id = ticket_create_record($cid, $qid, $uid, (int)$u['id'], ticket_mail_clean_subject((string)$e['subject']), $intro . "\n\n" . $body, 'einschraenkung', $files);
            ticket_log($id, $cid, (int)$u['id'], 'event', 'Aus der Prüfliste übernommen');
            $tk = load_ticket($id);
            ticket_notify_processors($tk, fn() => t("Neues Ticket in der Queue „{queue}“\nVon: {name}\nDringlichkeit: {prio}\n\n", [
                'queue' => $tk['queue_name'], 'name' => $tk['creator_name'] ?? '–', 'prio' => t(TICKET_PRIORITIES[$tk['priority']])]) . $body, true);
            ticket_notify_creator($tk, fn() => t("Für Sie wurde ein Ticket angelegt. Sie werden über jede Änderung informiert.\n\n") . $body);
            $done('accepted', $id);
            audit('ticket.inbox_accept', $e['from_email'] . ' → #' . $tk['number'], $cid);
            flash('success', t('Das Ticket #{n} wurde angelegt.', ['n' => $tk['number']]));
            tredirect('ticket_inbox');
        }
        if ($action === 'block') {
            $list = ticket_blocklist($cid);
            if (!in_array($e['from_email'], $list, true)) $list[] = $e['from_email'];
            update('companies', $cid, ['ticket_blocklist' => implode("\n", array_slice($list, 0, 500))]);
            $done('rejected');
            audit('ticket.inbox_block', $e['from_email'], $cid);
            flash('success', t('{x} steht jetzt auf der Sperrliste.', ['x' => $e['from_email']]));
            tredirect('ticket_inbox');
        }
        if ($action === 'discard') {
            $done('discarded');
            flash('success', t('Die Mail wurde verworfen.'));
            tredirect('ticket_inbox');
        }
        if ($action === 'review') {
            update('ticket_inbox', (int)$e['id'], ['status' => 'review', 'handled_by' => null, 'handled_at' => '']);
            flash('success', t('Die Mail steht wieder in der Prüfliste.'));
            tredirect('ticket_inbox');
        }
        tredirect('ticket_inbox');
    }

    $view = in_array($_GET['view'] ?? '', ['review', 'spam', 'all'], true) ? (string)$_GET['view'] : 'review';
    $where = match ($view) { 'review' => "i.status = 'review'", 'spam' => "i.status IN ('spam', 'rejected')", default => '1 = 1' };
    $list = rows("SELECT i.*, t.number AS ticket_number FROM ticket_inbox i LEFT JOIN tickets t ON t.id = i.ticket_id
        WHERE i.company_id = ? AND $where ORDER BY i.id DESC LIMIT 200", [$cid]);
    $counts = ['review' => (int)row("SELECT COUNT(*) AS n FROM ticket_inbox WHERE company_id = ? AND status = 'review'", [$cid])['n'],
        'spam' => (int)row("SELECT COUNT(*) AS n FROM ticket_inbox WHERE company_id = ? AND status IN ('spam', 'rejected') AND created_at >= ?", [$cid, date('Y-m-d H:i:s', time() - 7 * 86400)])['n']];

    render_page(t('E-Mail-Prüfliste'), function () use ($cid, $view, $list, $counts, $users, $queues) {
        if (is_admin()) echo company_switcher($cid, 'ticket_inbox'); ?>
        <p><a href="<?= e(turl('tickets')) ?>"><?= e(t('← Zur Übersicht')) ?></a> · <a href="<?= e(turl('ticket_settings')) ?>#mail"><?= e(t('E-Mail-Eingang einrichten')) ?></a></p>
        <nav class="type-tabs">
            <?php foreach (['review' => t('Zu prüfen'), 'spam' => t('Spam & abgelehnt'), 'all' => t('Protokoll')] as $k => $label): ?>
                <a class="<?= $k === $view ? 'active' : '' ?>" href="<?= e(turl('ticket_inbox', ['view' => $k])) ?>"><?= e($label) ?><?= !empty($counts[$k]) ? ' <span class="count">' . $counts[$k] . '</span>' : '' ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($view === 'review'): ?><p class="muted"><?= e(t('Mails von Absendern ohne Konto in der Firma. Übernehmen Sie eine Mail als Ticket für eine Person der Firma, sperren Sie den Absender oder verwerfen Sie die Mail.')) ?></p><?php endif; ?>
        <?php if (!$list): ?><div class="card"><p class="muted"><?= e(t('Keine Mails.')) ?></p></div><?php endif; ?>
        <?php foreach ($list as $e): ?>
            <div class="card inbox-item">
                <div class="meta"><strong><?= e($e['from_name'] !== '' ? $e['from_name'] : $e['from_email']) ?></strong> <span class="muted">&lt;<?= e($e['from_email']) ?>&gt;</span>
                    · <span title="<?= e(fmt_dt($e['created_at'])) ?>"><?= e(ticket_age($e['created_at'])) ?></span>
                    · <span class="tag <?= in_array($e['status'], ['spam', 'rejected'], true) ? 'danger' : ($e['status'] === 'review' ? 'warn' : '') ?>"><?= e(t(TICKET_INBOX_STATUSES[$e['status']] ?? $e['status'])) ?></span>
                    <?php if ($e['reason'] !== ''): ?><span class="muted small"><?= e(t_parts($e['reason'])) ?></span><?php endif; ?>
                    <?php if ($e['ticket_number']): ?> · <a href="<?= e(turl('ticket', ['id' => $e['ticket_id']])) ?>">#<?= e($e['ticket_number']) ?></a><?php endif; ?></div>
                <p><strong><?= e($e['subject'] !== '' ? $e['subject'] : t('(ohne Betreff)')) ?></strong><?= (int)$e['attachment_count'] ? ' <span class="muted small">📎 ' . (int)$e['attachment_count'] . '</span>' : '' ?></p>
                <?php if ($e['status'] === 'review' || $view !== 'all'): ?><div class="prewrap small inbox-body"><?= e(mb_substr((string)$e['body'], 0, 1500)) ?><?= mb_strlen((string)$e['body']) > 1500 ? ' …' : '' ?></div><?php endif; ?>
                <?php if ($e['status'] === 'review'): ?>
                    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="action" value="accept">
                        <label><?= e(t('Ticket für')) ?> <select name="user_id" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                            <?php foreach ($users as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
                        <label><?= e(t('Queue')) ?> <select name="queue_id"><?php foreach ($queues as $q): ?><option value="<?= (int)$q['id'] ?>"><?= e($q['name']) ?></option><?php endforeach; ?></select></label>
                        <button class="btn small primary"><?= e(t('Als Ticket übernehmen')) ?></button></form>
                    <form method="post" class="btn-row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                        <button class="btn small" name="action" value="discard"><?= e(t('Verwerfen')) ?></button>
                        <button class="btn small danger" name="action" value="block" data-confirm="<?= e(t('{x} sperren? Weitere Mails dieses Absenders werden abgelehnt.', ['x' => $e['from_email']])) ?>"><?= e(t('Absender sperren')) ?></button></form>
                <?php elseif (in_array($e['status'], ['spam', 'rejected', 'discarded'], true)): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                        <button class="btn small" name="action" value="review"><?= e(t('Doch prüfen')) ?></button></form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php });
}
