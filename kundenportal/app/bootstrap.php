<?php
/**
 * Gemeinsamer Start: Konfiguration, Datenbank, Session, Hilfsfunktionen.
 */
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

if (!is_file(ROOT_DIR . '/config.php')) {
    http_response_code(500);
    exit('Bitte zuerst config.sample.php nach config.php kopieren und anpassen, dann install.php aufrufen.');
}
$GLOBALS['config'] = require ROOT_DIR . '/config.php';
date_default_timezone_set(cfg('timezone', 'Europe/Berlin'));

require_once APP_DIR . '/totp.php';
require_once APP_DIR . '/qrcode.php';
require_once APP_DIR . '/mail.php';

// ---------------------------------------------------------------------------
// Konstanten
// ---------------------------------------------------------------------------
const ROLES = [
    'admin'       => 'Administrator (Dienstleister)',
    'firma_admin' => 'Firmen-Administrator',
    'firma_user'  => 'Firmen-Benutzer',
];

const STATUSES = [
    'eingereicht'    => 'Eingereicht',
    'in_bearbeitung' => 'In Bearbeitung',
    'rueckfrage'     => 'Rückfrage',
    'eingerichtet'   => 'Eingerichtet',
    'freigegeben'    => 'Freigegeben',
    'storniert'      => 'Storniert',
];
const OPEN_STATUSES = ['eingereicht', 'in_bearbeitung', 'rueckfrage', 'eingerichtet'];

const ITEM_TYPES = [
    'device'     => 'Geräte',
    'license'    => 'Lizenzen',
    'software'   => 'Programme',
    'drive'      => 'Netzlaufwerke',
    'permission' => 'Freigaben / Berechtigungen',
];

// ---------------------------------------------------------------------------
// Allgemeine Helfer
// ---------------------------------------------------------------------------
function cfg(string $key, $default = null)
{
    $v = $GLOBALS['config'];
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function fmt_dt(?string $s): string
{
    if (!$s) return '–';
    $t = strtotime($s);
    return $t ? date('d.m.Y H:i', $t) : e($s);
}

function fmt_date(?string $s): string
{
    if (!$s) return '–';
    $t = strtotime($s);
    return $t ? date('d.m.Y', $t) : e($s);
}

function url(string $route, array $params = []): string
{
    $q = array_merge(['r' => $route], $params);
    return 'index.php?' . http_build_query($q);
}

function redirect(string $route, array $params = []): never
{
    header('Location: ' . url($route, $params));
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function post(string $key, $default = '')
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function query_int(string $key): int
{
    return (int)($_GET[$key] ?? 0);
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function abort(int $code, string $msg = ''): never
{
    http_response_code($code);
    $titles = [403 => 'Kein Zugriff', 404 => 'Nicht gefunden', 400 => 'Ungültige Anfrage'];
    render_page($titles[$code] ?? 'Fehler', function () use ($msg, $code) {
        echo '<div class="card"><p>' . e($msg ?: ($code === 403
            ? 'Sie haben keine Berechtigung für diese Seite.'
            : 'Die angeforderte Seite wurde nicht gefunden.')) . '</p>';
        echo '<p><a class="btn" href="' . e(url('home')) . '">Zur Startseite</a></p></div>';
    });
    exit;
}

// ---------------------------------------------------------------------------
// Datenbank
// ---------------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg('db');
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $dir = dirname($c['sqlite_path']);
        if (!is_dir($dir)) mkdir($dir, 0770, true);
        $pdo = new PDO('sqlite:' . $c['sqlite_path']);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $c['host'], (int)($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['password']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = "INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' .
        implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')';
    q($sql, $data);
    return (int)db()->lastInsertId();
}

function update(string $table, int $id, array $data): void
{
    $set = implode(',', array_map(fn($c) => "$c = :$c", array_keys($data)));
    $data['__id'] = $id;
    q("UPDATE $table SET $set WHERE id = :__id", $data);
}

function audit(string $action, string $details = '', ?int $companyId = null): void
{
    $u = current_user();
    insert('audit_log', [
        'user_id'    => $u['id'] ?? null,
        'company_id' => $companyId ?? ($u['company_id'] ?? null),
        'action'     => $action,
        'details'    => mb_substr($details, 0, 500),
        'ip'         => client_ip(),
        'created_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// Session & CSRF
// ---------------------------------------------------------------------------
function start_session(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('ONBSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    $limit = (int)cfg('session_lifetime_minutes', 60) * 60;
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $limit) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'][] = ['info', 'Sie wurden wegen Inaktivität abgemeldet.'];
    }
    $_SESSION['last_seen'] = time();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') abort(400);
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        abort(400, 'Das Formular ist abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.');
    }
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

// ---------------------------------------------------------------------------
// Benutzer, Rollen, Mandantentrennung
// ---------------------------------------------------------------------------
function current_user(): ?array
{
    static $cacheUid = -1, $cache = null;
    $uid = $_SESSION['uid'] ?? null;
    if ($uid === $cacheUid) return $cache;
    $cacheUid = $uid;
    if (!$uid) return $cache = null;
    $u = row('SELECT * FROM users WHERE id = ? AND active = 1', [$uid]);
    if ($u && $u['company_id']) {
        $c = row('SELECT active FROM companies WHERE id = ?', [$u['company_id']]);
        if (!$c || !(int)$c['active']) $u = null;
    }
    if (!$u) {
        unset($_SESSION['uid']);
        return $cache = null;
    }
    $u['id'] = (int)$u['id'];
    $u['company_id'] = $u['company_id'] !== null ? (int)$u['company_id'] : null;
    return $cache = $u;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function is_company_admin(): bool
{
    return (current_user()['role'] ?? '') === 'firma_admin';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login');
    if ((int)$u['must_change_password'] && ($_GET['r'] ?? '') !== 'account') {
        flash('info', 'Bitte vergeben Sie zuerst ein eigenes Passwort.');
        redirect('account');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') abort(403);
    return $u;
}

/** Firmen-Admin (für die eigene Firma) oder Admin */
function require_manager(): array
{
    $u = require_login();
    if (!in_array($u['role'], ['admin', 'firma_admin'], true)) abort(403);
    return $u;
}

/**
 * Prüft, ob der angemeldete Benutzer auf die Firma zugreifen darf.
 * Admins dürfen alles, Firmenbenutzer nur ihre eigene Firma.
 */
function can_access_company(int $companyId): bool
{
    $u = current_user();
    if (!$u) return false;
    return $u['role'] === 'admin' || $u['company_id'] === $companyId;
}

function require_company_access(int $companyId): void
{
    if (!can_access_company($companyId)) abort(403);
}

/** Firma, auf die sich eine Pflegeseite bezieht (Admins wählen per ?company=) */
function context_company_id(): int
{
    $u = require_login();
    if ($u['role'] === 'admin') {
        $id = query_int('company') ?: (int)post('company_id', 0);
        if (!$id) {
            $first = row('SELECT id FROM companies ORDER BY name LIMIT 1');
            $id = (int)($first['id'] ?? 0);
        }
        return $id;
    }
    return (int)$u['company_id'];
}

// ---------------------------------------------------------------------------
// App-Freigaben
// ---------------------------------------------------------------------------
function company_has_app(int $companyId, string $slug): bool
{
    return (bool)row('SELECT ca.id FROM company_apps ca JOIN apps a ON a.id = ca.app_id
        WHERE ca.company_id = ? AND a.slug = ? AND a.active = 1', [$companyId, $slug]);
}

/** Apps, die der angemeldete Benutzer sehen darf (Admins: alle aktiven) */
function apps_for_user(): array
{
    $u = current_user();
    if (!$u) return [];
    if ($u['role'] === 'admin') return rows('SELECT * FROM apps WHERE active = 1 ORDER BY sort, name');
    return rows('SELECT a.* FROM apps a JOIN company_apps ca ON ca.app_id = a.id
        WHERE ca.company_id = ? AND a.active = 1 ORDER BY a.sort, a.name', [$u['company_id']]);
}

/** Bricht ab, wenn die Firma des Benutzers die App nicht freigeschaltet hat */
function require_app(string $slug): array
{
    $u = require_login();
    if ($u['role'] !== 'admin' && !company_has_app((int)$u['company_id'], $slug)) {
        abort(403, 'Diese App ist für Ihre Firma nicht freigeschaltet. Bitte wenden Sie sich an Ihren IT-Dienstleister.');
    }
    return $u;
}

/** Firmen, die eine App nutzen dürfen (für Admin-Auswahllisten) */
function companies_with_app(string $slug): array
{
    return rows('SELECT c.id, c.name, c.active FROM companies c JOIN company_apps ca ON ca.company_id = c.id
        JOIN apps a ON a.id = ca.app_id WHERE a.slug = ? ORDER BY c.name', [$slug]);
}

function companies_for_select(): array
{
    if (is_admin()) return rows('SELECT id, name, active FROM companies ORDER BY name');
    $u = current_user();
    return rows('SELECT id, name, active FROM companies WHERE id = ?', [$u['company_id']]);
}

// ---------------------------------------------------------------------------
// Darstellung
// ---------------------------------------------------------------------------
function status_badge(string $status): string
{
    return '<span class="badge st-' . e($status) . '">' . e(STATUSES[$status] ?? $status) . '</span>';
}

function render_page(string $title, callable $content): void
{
    $user = current_user();
    $flashes = take_flashes();
    $route = $_GET['r'] ?? 'dashboard';
    require APP_DIR . '/views/layout.php';
}

function company_switcher(int $current, string $route, array $extra = []): string
{
    if (!is_admin()) return '';
    $html = '<form method="get" class="company-switch"><input type="hidden" name="r" value="' . e($route) . '">';
    foreach ($extra as $k => $v) $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    $html .= '<label>Firma: <select name="company" onchange="this.form.submit()">';
    foreach (companies_for_select() as $c) {
        $sel = (int)$c['id'] === $current ? ' selected' : '';
        $html .= '<option value="' . (int)$c['id'] . "\"$sel>" . e($c['name']) . ((int)$c['active'] ? '' : ' (inaktiv)') . '</option>';
    }
    $html .= '</select></label><noscript><button class="btn small">Wechseln</button></noscript></form>';
    return $html;
}

function validate_password(string $pw): ?string
{
    if (mb_strlen($pw) < 10) return 'Das Passwort muss mindestens 10 Zeichen lang sein.';
    if (!preg_match('/[A-Za-zÄÖÜäöüß]/u', $pw) || !preg_match('/\d/', $pw)) {
        return 'Das Passwort muss Buchstaben und Ziffern enthalten.';
    }
    return null;
}
