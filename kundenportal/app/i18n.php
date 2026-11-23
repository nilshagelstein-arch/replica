<?php
/**
 * Mehrsprachigkeit (Deutsch, Englisch, Französisch).
 *
 * Quelltexte sind Deutsch und dienen zugleich als Schlüssel: t('Speichern') liefert je nach Sprache
 * „Save“ oder „Enregistrer“. Übersetzungen liegen in app/lang/<code>.php (Array Deutsch => Übersetzung).
 * Fehlt eine Übersetzung, erscheint der deutsche Text.
 *
 * Sprache je Anfrage:
 *  1. vorübergehend gesetzte Sprache (with_lang, z. B. beim Mailversand an einen Empfänger)
 *  2. angemeldeter Benutzer: eigene Sprache, sonst Sprache seiner Firma
 *  3. ohne Anmeldung: auf der Seite gewählte Sprache (Session/Cookie), sonst Browser-Sprache
 *  4. Deutsch
 */

const LANGS = ['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français'];
const DEFAULT_LANG = 'de';

function lang_valid(?string $l): ?string
{
    $l = strtolower(trim((string)$l));
    return isset(LANGS[$l]) ? $l : null;
}

/** Sprache aus dem Accept-Language-Kopf des Browsers */
function browser_lang(): ?string
{
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $code = lang_valid(substr(trim(explode(';', $part)[0]), 0, 2));
        if ($code) return $code;
    }
    return null;
}

/** Sprache einer Firma (Standard für ihre Benutzer und Mails) */
function company_lang(?int $companyId): string
{
    static $cache = [];
    if (!$companyId) return DEFAULT_LANG;
    if (!array_key_exists($companyId, $cache)) {
        try {
            $cache[$companyId] = lang_valid(row('SELECT lang FROM companies WHERE id = ?', [$companyId])['lang'] ?? null);
        } catch (Throwable $e) {
            $cache[$companyId] = null;
        }
    }
    return $cache[$companyId] ?? DEFAULT_LANG;
}

/** Wirksame Sprache eines Benutzers: eigene Einstellung, sonst die seiner Firma */
function user_lang(array $u): string
{
    return lang_valid($u['lang'] ?? null) ?? company_lang(isset($u['company_id']) ? (int)$u['company_id'] : null);
}

/** Auf der Anmeldeseite gewählte Sprache (Besucher ohne Anmeldung) */
function visitor_lang(): string
{
    return lang_valid($_SESSION['lang'] ?? null) ?? lang_valid($_COOKIE['kp_lang'] ?? null) ?? browser_lang() ?? DEFAULT_LANG;
}

function current_lang(): string
{
    if (!empty($GLOBALS['lang_stack'])) return end($GLOBALS['lang_stack']);
    try {
        $u = function_exists('current_user') && session_status() === PHP_SESSION_ACTIVE ? current_user() : null;
    } catch (Throwable $e) {
        $u = null;
    }
    return $u ? user_lang($u) : visitor_lang();
}

/** Führt $fn in einer bestimmten Sprache aus (z. B. Mail an einen Empfänger in seiner Sprache) */
function with_lang(string $lang, callable $fn)
{
    $GLOBALS['lang_stack'][] = lang_valid($lang) ?? DEFAULT_LANG;
    try {
        return $fn();
    } finally {
        array_pop($GLOBALS['lang_stack']);
    }
}

function lang_dict(string $lang): array
{
    static $dicts = [];
    if ($lang === DEFAULT_LANG) return [];
    if (!isset($dicts[$lang])) {
        $f = APP_DIR . '/lang/' . $lang . '.php';
        $dicts[$lang] = is_file($f) ? (array)require $f : [];
    }
    return $dicts[$lang];
}

/**
 * Übersetzt einen deutschen Text. Platzhalter in geschweiften Klammern werden ersetzt:
 * t('Hallo {name}', ['name' => 'Anna']).
 */
function t(string $de, array $vars = []): string
{
    $s = lang_dict(current_lang())[$de] ?? $de;
    if ($vars) {
        $map = [];
        foreach ($vars as $k => $v) $map['{' . $k . '}'] = (string)$v;
        $s = strtr($s, $map);
    }
    return $s;
}

/**
 * Übersetzt zusammengesetzte, gespeicherte Texte wie „Benutzerkonto sperren – Sofort“ oder
 * „Postfach: Postfach sperren, Inhalte aufbewahren“ Stück für Stück (Trenner „ – “ und „: “).
 * Unbekannte Teile (z. B. Namen) bleiben unverändert.
 */
function t_parts(string $s): string
{
    $dict = lang_dict(current_lang());
    if (!$dict || isset($dict[$s])) return $dict[$s] ?? $s;
    $parts = preg_split('/( – |: )/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    for ($i = 0; $i < count($parts); $i += 2) {
        $sep = $parts[$i + 1] ?? '';
        if ($sep !== '' && isset($dict[$parts[$i] . $sep])) { $out .= $dict[$parts[$i] . $sep]; continue; }
        $out .= ($dict[$parts[$i]] ?? $parts[$i]) . $sep;
    }
    return $out;
}

/** Auswahlliste der Sprachen; $inherit = Beschriftung für „keine eigene Einstellung“ */
function lang_select(string $name, ?string $current, ?string $inherit = null): string
{
    $html = '<select name="' . e($name) . '">';
    if ($inherit !== null) $html .= '<option value="">' . e($inherit) . '</option>';
    foreach (LANGS as $code => $label) {
        $html .= '<option value="' . $code . '"' . ($current === $code ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select>';
}

/** Sprachumschalter für Seiten ohne Anmeldung (Anmeldung, Registrierung, Passwort vergessen …) */
function lang_switcher(): string
{
    $cur = current_lang();
    $params = $_GET;
    $html = '<nav class="lang-switch" aria-label="' . e(t('Sprache')) . '">';
    foreach (LANGS as $code => $label) {
        $params['lang'] = $code;
        $html .= $code === $cur
            ? '<strong>' . e($label) . '</strong>'
            : '<a href="index.php?' . e(http_build_query($params)) . '" hreflang="' . $code . '" lang="' . $code . '">' . e($label) . '</a>';
    }
    return $html . '</nav>';
}

/** Übernimmt ?lang= für Besucher ohne Anmeldung (Session und Cookie für ein Jahr) */
function apply_lang_param(): void
{
    $l = lang_valid($_GET['lang'] ?? null);
    if (!$l) return;
    $_SESSION['lang'] = $l;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie('kp_lang', $l, ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}

/** Sprache für einen Mail-Empfänger: Benutzer mit dieser Adresse, sonst Firma, sonst Deutsch */
function lang_for_email(string $email, ?int $companyId = null): string
{
    try {
        $u = row('SELECT lang, company_id FROM users WHERE LOWER(email) = LOWER(?) ORDER BY active DESC, id LIMIT 1', [$email]);
    } catch (Throwable $ex) {
        $u = null;
    }
    if ($u) return user_lang($u);
    return company_lang($companyId);
}

/**
 * Sendet eine Mail an jeden Empfänger in seiner Sprache.
 * $compose wird je Sprache einmal aufgerufen und liefert [Betreff, Text]; darin t() verwenden.
 */
function send_mail_t(array $to, callable $compose, ?int $companyId = null, ?string $forceLang = null, array $attachments = []): void
{
    $groups = [];
    foreach (array_unique(array_filter(array_map('trim', $to))) as $addr) {
        $groups[$forceLang ?? lang_for_email($addr, $companyId)][] = $addr;
    }
    foreach ($groups as $lang => $addrs) {
        with_lang($lang, function () use ($compose, $addrs, $companyId, $attachments) {
            [$subject, $body] = $compose();
            send_mail($addrs, $subject, $body, $companyId, $attachments);
        });
    }
}

/** Datumsformate je Sprache */
function lang_date_format(bool $withTime = false): string
{
    $f = ['de' => 'd.m.Y', 'en' => 'd/m/Y', 'fr' => 'd/m/Y'][current_lang()] ?? 'd.m.Y';
    return $withTime ? $f . ' H:i' : $f;
}

/** Wörterbuch für die Browser-Skripte (assets/*.js nutzen window.KP_T) */
function page_i18n_js(): void
{
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    $lang = current_lang();
    $dict = [];
    $jsKeys = require APP_DIR . '/lang/js_keys.php';
    foreach ($jsKeys as $k) $dict[$k] = t($k);
    echo 'window.KP_LANG=' . json_encode($lang) . ';window.KP_T=' . json_encode($dict, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ";\n";
    exit;
}
