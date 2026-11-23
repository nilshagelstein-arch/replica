<?php
/**
 * Gemeinsamer Start: Konfiguration, Datenbank, Session, Hilfsfunktionen.
 */
declare(strict_types=1);

define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));
require_once APP_DIR . '/i18n.php';

if (!is_file(ROOT_DIR . '/config.php')) {
    http_response_code(500);
    exit(t('Bitte zuerst config.sample.php nach config.php kopieren und anpassen, dann install.php aufrufen.'));
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
    'superadmin'  => 'Superadmin (Dienstleister)',
    'admin'       => 'Verwaltungsadmin (Dienstleister)',
    'firma_admin' => 'Firmen-Administrator',
    'firma_user'  => 'Firmen-Benutzer',
    // Externer Dienstleister: ohne eigene Firma, Zugriff nur auf die App „Tickets“ der Firmen, die ihn eingetragen haben
    'dienstleister' => 'Externer Dienstleister',
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

const REQUEST_TYPES = [
    'onboarding'    => 'Onboarding',
    'offboarding'   => 'Offboarding',
    'stilllegung'   => 'Stilllegung',
    'reaktivierung' => 'Reaktivierung',
];

const REQUEST_TYPE_HINTS = [
    'onboarding'    => 'Neue/r Mitarbeiter/in: Arbeitsplatz, Zugänge und Freigaben einrichten',
    'offboarding'   => 'Austritt: Zugänge sperren, Geräte zurücknehmen, Postfach und Daten regeln',
    'stilllegung'   => 'Vorübergehende Abwesenheit (z. B. Elternzeit): Zugänge ruhen lassen, Lizenzen pausieren',
    'reaktivierung' => 'Rückkehr nach einer Stilllegung: Zugänge und Ausstattung wieder bereitstellen',
];

/** Bezeichnung des Stichtags je Auftragstyp */
const TYPE_DATE_LABELS = [
    'onboarding'    => 'Eintrittsdatum',
    'offboarding'   => 'Austrittsdatum (letzter Arbeitstag)',
    'stilllegung'   => 'Beginn der Abwesenheit',
    'reaktivierung' => 'Rückkehrdatum (erster Arbeitstag)',
];

/** abweichende Statusbezeichnungen je Auftragstyp */
const TYPE_STATUS_LABELS = [
    'offboarding' => ['eingerichtet' => 'Durchgeführt', 'freigegeben' => 'Abgeschlossen'],
    'stilllegung' => ['eingerichtet' => 'Durchgeführt', 'freigegeben' => 'Abgeschlossen'],
];

/** Überschriften der Checkliste je Auftragstyp */
const TYPE_ITEM_LABELS = [
    'offboarding' => [
        'device' => 'Geräte zurücknehmen', 'license' => 'Lizenzen entziehen', 'software' => 'Programmzugänge entfernen',
        'drive' => 'Laufwerkszugriffe entfernen', 'permission' => 'Freigaben / Berechtigungen entziehen',
    ],
    'stilllegung' => [
        'device' => 'Geräte (Rückgabe oder Verbleib laut Bemerkung)', 'license' => 'Lizenzen pausieren', 'software' => 'Programmzugänge deaktivieren',
        'drive' => 'Laufwerkszugriffe deaktivieren', 'permission' => 'Freigaben / Berechtigungen deaktivieren',
    ],
    'reaktivierung' => [
        'device' => 'Geräte bereitstellen', 'license' => 'Lizenzen wieder zuweisen', 'software' => 'Programmzugänge reaktivieren',
        'drive' => 'Laufwerkszugriffe reaktivieren', 'permission' => 'Freigaben / Berechtigungen reaktivieren',
    ],
];

const ABSENCE_REASONS = ['Elternzeit', 'Mutterschutz', 'Pflegezeit', 'Sabbatical', 'Sonstige längere Abwesenheit'];

const MAILBOX_ACTIONS = [
    'offboarding' => [
        'sperren'      => 'Postfach sperren, Inhalte aufbewahren',
        'freigeben'    => 'In freigegebenes Postfach umwandeln, Zugriff für Nachfolger/in',
        'weiterleiten' => 'E-Mails an Nachfolger/in weiterleiten',
        'loeschen'     => 'Postfach nach Aufbewahrungsfrist löschen',
    ],
    'stilllegung' => [
        'abwesenheit'  => 'Nur Abwesenheitsnotiz',
        'weiterleiten' => 'Abwesenheitsnotiz und Weiterleitung an Vertretung',
        'zugriff'      => 'Abwesenheitsnotiz und Zugriff für Vertretung',
    ],
];

const DATA_ACTIONS = [
    'uebergeben'  => 'Persönliche Dateien (z. B. OneDrive/Home-Laufwerk) an Nachfolger/in übergeben',
    'archivieren' => 'Dateien archivieren',
    'loeschen'    => 'Dateien löschen',
];

const LOCK_OPTIONS = [
    'austritt' => 'Am Stichtag nach Dienstschluss',
    'sofort'   => 'Sofort',
    'termin'   => 'Zu einem bestimmten Zeitpunkt',
];

function status_label(string $status, string $type = 'onboarding'): string
{
    return t(TYPE_STATUS_LABELS[$type][$status] ?? (STATUSES[$status] ?? $status));
}

function item_type_label(string $itemType, string $requestType = 'onboarding'): string
{
    if ($itemType === 'task') return t('Aufgaben');
    return t(TYPE_ITEM_LABELS[$requestType][$itemType] ?? (ITEM_TYPES[$itemType] ?? $itemType));
}

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
    return $t ? date(lang_date_format(true), $t) : e($s);
}

function fmt_date(?string $s): string
{
    if (!$s) return '–';
    $t = strtotime($s);
    return $t ? date(lang_date_format(), $t) : e($s);
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

/**
 * Löst einen Hostnamen auf und liefert eine öffentliche IPv4-Adresse, sonst null.
 * Abgelehnt werden private, reservierte, Loopback-, Link-Local- und CGNAT-Adressen (100.64.0.0/10),
 * auch wenn nur eine der aufgelösten Adressen intern ist. Schutz vor SSRF bei ausgehenden Verbindungen.
 * Aufrufer sollen sich mit der gelieferten IP verbinden (nicht erneut mit dem Namen), damit DNS-Rebinding nicht greift.
 */
function public_ip(string $host, bool $allowPrivate = false): ?string
{
    $host = trim($host, '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
    if (!$ips) return null;
    if ($allowPrivate) return $ips[0];
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | (defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : 0);
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) return null;
        if (str_contains($ip, ':')) return null; // IPv6-Literale (auch ::ffff:127.0.0.1) nicht zulassen
        $n = ip2long($ip);
        if ($n === false || ($n & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) return null; // CGNAT
    }
    return $ips[0];
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function abort(int $code, string $msg = ''): never
{
    http_response_code($code);
    $titles = [403 => t('Kein Zugriff'), 404 => t('Nicht gefunden'), 400 => t('Ungültige Anfrage')];
    render_page($titles[$code] ?? t('Fehler'), function () use ($msg, $code) {
        echo '<div class="card"><p>' . e($msg ?: ($code === 403
            ? t('Sie haben keine Berechtigung für diese Seite.')
            : t('Die angeforderte Seite wurde nicht gefunden.'))) . '</p>';
        echo '<p><a class="btn" href="' . e(url('home')) . ('">' . e(t('Zur Startseite')) . '</a></p></div>');
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
        $_SESSION['flash'][] = ['info', t('Sie wurden wegen Inaktivität abgemeldet.')];
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
        abort(400, t('Das Formular ist abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.'));
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

/** Superadmin oder Verwaltungsadmin (Dienstleister) */
function is_staff_role(string $role): bool
{
    return $role === 'admin' || $role === 'superadmin';
}

function is_superadmin(): bool
{
    return (current_user()['role'] ?? '') === 'superadmin';
}

function require_superadmin(): array
{
    $u = require_login();
    if ($u['role'] !== 'superadmin') abort(403, t('Diese Aktion ist nur für Superadmins erlaubt.'));
    return $u;
}

/** Anzahl aktiver Superadmins (es muss immer mindestens einer übrig bleiben) */
function active_superadmin_count(): int
{
    return (int)row("SELECT COUNT(*) AS n FROM users WHERE role = 'superadmin' AND active = 1")['n'];
}

function is_admin(): bool
{
    return is_staff_role((string)(current_user()['role'] ?? ''));
}

function is_company_admin(): bool
{
    return (current_user()['role'] ?? '') === 'firma_admin';
}

// ---------------------------------------------------------------------------
// Rechte der Verwaltungsadmins: je Bereich kein Zugriff / ansehen / bearbeiten,
// dazu alle oder ausgewählte Firmen. Superadmins dürfen immer alles.
// Verwaltungsadmins ohne gespeicherte Rechte (Altbestand) dürfen alles.
// ---------------------------------------------------------------------------
const PERM_NONE = 0;
const PERM_VIEW = 1;
const PERM_EDIT = 2;
const PERM_LABELS = [PERM_NONE => 'kein Zugriff', PERM_VIEW => 'ansehen', PERM_EDIT => 'bearbeiten'];
const ADMIN_AREAS = [
    'companies'  => ['Firmen', 'Firmenprofile ansehen und pflegen, Registrierungen freischalten'],
    'users'      => ['Benutzer der Firmen', 'Benutzerkonten der Firmen anlegen, ändern, sperren'],
    'apps'       => ['Apps & Freigaben', 'Freischalten von Apps je Firma; eigene Apps anlegen nur mit Zugriff auf alle Firmen'],
    'mail'       => ['E-Mail-Versand', 'Postfächer der Firmen; das zentrale Postfach nur mit Zugriff auf alle Firmen'],
    'onboarding' => ['On- & Offboarding', 'Aufträge, Mitarbeiterliste, Abteilungen, Katalog und Vorlagen'],
    'forms'      => ['Formulare', 'Formulare, Kundendatenbank, Briefkopf, Logo und API'],
    'links'      => ['Linksammlung', 'Reiter, Überschriften und Links der Firmen'],
    'tickets'    => ['Tickets', 'Queues und Einstellungen der Firmen; Tickets nur mit Einsicht-Freigabe der Firma'],
    'backups'    => ['Datensicherung', 'Sicherungen der Firmen; Portal-Sicherungen nur mit Zugriff auf alle Firmen'],
    'audit'      => ['Protokoll', 'Protokoll der Aktionen (nur ansehen)'],
    'updates'    => ['Updates', 'Update-Pakete ansehen und herunterladen (einspielen nur Superadmin)'],
];
/** Welche App zu welchem Bereich gehört */
const APP_AREAS = ['onboarding' => 'onboarding', 'formulare' => 'forms', 'links' => 'links', 'datensicherung' => 'backups', 'tickets' => 'tickets'];

/** Gespeicherte Bereichsrechte eines Verwaltungsadmins (null = uneingeschränkt) */
function admin_perms_of(array $u): ?array
{
    if (($u['role'] ?? '') !== 'admin') return null;
    $p = json_decode((string)($u['admin_perms'] ?? ''), true);
    return is_array($p) ? $p : null;
}

/** Recht des angemeldeten (oder übergebenen) Benutzers in einem Bereich der Verwaltung */
function admin_perm(string $area, ?array $u = null): int
{
    $u ??= current_user();
    if (!$u || !is_staff_role($u['role'])) return PERM_NONE;
    if ($u['role'] === 'superadmin') return PERM_EDIT;
    $p = admin_perms_of($u);
    if ($p === null) return PERM_EDIT;
    return max(PERM_NONE, min(PERM_EDIT, (int)($p[$area] ?? PERM_NONE)));
}

function admin_can(string $area, bool $edit = false): bool
{
    return admin_perm($area) >= ($edit ? PERM_EDIT : PERM_VIEW);
}

/** Für Dienstleister: Bereich prüfen (Firmenrollen werden hier nicht eingeschränkt) */
function require_area(string $area, bool $edit = false): void
{
    if (!is_admin() || admin_can($area, $edit)) return;
    $name = t(ADMIN_AREAS[$area][0] ?? $area);
    abort(403, $edit && admin_can($area)
        ? t('Sie dürfen den Bereich „{name}“ nur ansehen, nicht ändern.', ['name' => $name])
        : t('Sie haben keinen Zugriff auf den Bereich „{name}“.', ['name' => $name]));
}

/** Firmen, die ein Verwaltungsadmin betreuen darf; null = alle */
function admin_company_ids(?array $u = null): ?array
{
    $u ??= current_user();
    if (!$u || ($u['role'] ?? '') !== 'admin') return null;
    $raw = trim((string)($u['admin_companies'] ?? ''));
    if ($raw === '') return null;
    return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
}

/** Darf der Dienstleister firmenübergreifende Einstellungen ändern (alle Firmen)? */
function admin_all_companies(): bool
{
    return is_admin() && admin_company_ids() === null;
}

function require_all_companies(): void
{
    if (!admin_all_companies()) abort(403, t('Diese Einstellung gilt für alle Firmen und ist nur für Administratoren mit Zugriff auf alle Firmen möglich.'));
}

/** SQL-Bedingung für Listen des Dienstleisters, z. B. " AND c.id IN (3,5)" (leer = alle Firmen) */
function company_scope_sql(string $col, bool $allowNull = false): string
{
    $ids = admin_company_ids();
    if ($ids === null) return '';
    $in = $ids ? implode(',', $ids) : '0';
    return $allowNull ? " AND ($col IN ($in) OR $col IS NULL)" : " AND $col IN ($in)";
}

function require_login(): array
{
    $u = current_user();
    if (!$u) redirect('login');
    if ((int)$u['must_change_password'] && ($_GET['r'] ?? '') !== 'account') {
        flash('info', t('Bitte vergeben Sie zuerst ein eigenes Passwort.'));
        redirect('account');
    }
    // Selbst registrierte Firma vor der Freischaltung: nur Startseite und eigenes Konto
    if (company_pending($u) && !in_array($_GET['r'] ?? '', ['home', 'account', 'setup_2fa'], true)) {
        flash('info', t('Ihre Registrierung wird noch geprüft. Nach der Freischaltung stehen Ihnen die Module zur Verfügung.'));
        redirect('home');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!is_staff_role($u['role'])) abort(403);
    return $u;
}

/** Firmen-Admin (für die eigene Firma) oder Admin */
function require_manager(): array
{
    $u = require_login();
    if (!in_array($u['role'], ['superadmin', 'admin', 'firma_admin'], true)) abort(403);
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
    if (is_staff_role($u['role'])) {
        $ids = admin_company_ids($u);
        return $ids === null || in_array($companyId, $ids, true);
    }
    if ($u['role'] === 'dienstleister') return in_array($companyId, provider_company_ids(), true);
    return $u['company_id'] === $companyId;
}

function require_company_access(int $companyId): void
{
    if (!can_access_company($companyId)) abort(403);
}

/** Firma, auf die sich eine Pflegeseite bezieht (Admins wählen per ?company=) */
function context_company_id(): int
{
    $u = require_login();
    if (is_staff_role($u['role'])) {
        $id = query_int('company') ?: (int)post('company_id', 0);
        // Ausdrücklich angefragte, aber nicht erlaubte Firma: ablehnen statt still auf eine andere Firma umzuschalten
        // (sonst landet z. B. ein POST nach Einschränkung der Rechte bei der falschen Firma)
        if ($id && !can_access_company($id)) abort(403);
        if (!$id) {
            // ohne Angabe: erste erlaubte Firma als Startansicht
            $first = row('SELECT id FROM companies WHERE 1 = 1' . company_scope_sql('id') . ' ORDER BY name LIMIT 1');
            $id = (int)($first['id'] ?? 0);
        }
        return $id;
    }
    if ($u['role'] === 'dienstleister') {
        $ids = provider_company_ids();
        $id = query_int('company') ?: (int)post('company_id', 0);
        if ($id && !in_array($id, $ids, true)) abort(403);
        return $id ?: (int)($ids[0] ?? 0);
    }
    return (int)$u['company_id'];
}

/** Externer Dienstleister: Firmen, die ihn eingetragen haben (aktiv, mit App „Tickets“), nach Name */
function provider_company_ids(?array $u = null): array
{
    static $cache = [];
    $u ??= current_user();
    if (!$u || ($u['role'] ?? '') !== 'dienstleister') return [];
    $uid = (int)$u['id'];
    return $cache[$uid] ??= array_map('intval', array_column(rows("SELECT DISTINCT c.id, c.name FROM ticket_providers p
        JOIN companies c ON c.id = p.company_id JOIN company_apps ca ON ca.company_id = c.id JOIN apps a ON a.id = ca.app_id
        WHERE p.user_id = ? AND p.active = 1 AND c.active = 1 AND a.slug = 'tickets' AND a.active = 1 ORDER BY c.name", [$uid]), 'id'));
}

function is_provider_user(): bool
{
    return (current_user()['role'] ?? '') === 'dienstleister';
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
    if (is_staff_role($u['role'])) {
        return array_values(array_filter(rows('SELECT * FROM apps WHERE active = 1 ORDER BY sort, name'),
            fn($a) => !isset(APP_AREAS[$a['slug']]) || admin_perm(APP_AREAS[$a['slug']], $u) >= PERM_VIEW));
    }
    if ($u['role'] === 'dienstleister') {
        return provider_company_ids($u) ? rows("SELECT * FROM apps WHERE slug = 'tickets' AND active = 1") : [];
    }
    return rows('SELECT a.* FROM apps a JOIN company_apps ca ON ca.app_id = a.id
        WHERE ca.company_id = ? AND a.active = 1 ORDER BY a.sort, a.name', [$u['company_id']]);
}

/** Firma wartet auf Freischaltung durch den Dienstleister */
function company_pending(?array $u): bool
{
    if (!$u || !$u['company_id']) return false;
    $c = row('SELECT registration_status FROM companies WHERE id = ?', [$u['company_id']]);
    return $c && $c['registration_status'] === 'pending';
}

/** Bricht ab, wenn die Firma des Benutzers die App nicht freigeschaltet hat */
function require_app(string $slug): array
{
    $u = require_login();
    if (is_staff_role($u['role'])) {
        if (isset(APP_AREAS[$slug])) require_area(APP_AREAS[$slug]);
        return $u;
    }
    if ($u['role'] === 'dienstleister') {
        if ($slug !== 'tickets' || !provider_company_ids()) abort(403, t('Für Ihren Zugang ist derzeit keine Firma freigegeben.'));
        return $u;
    }
    if (!company_has_app((int)$u['company_id'], $slug)) {
        abort(403, t('Diese App ist für Ihre Firma nicht freigeschaltet. Bitte wenden Sie sich an Ihren IT-Dienstleister.'));
    }
    return $u;
}

/** Firmen, die eine App nutzen dürfen (für Admin-Auswahllisten) */
function companies_with_app(string $slug): array
{
    return rows('SELECT c.id, c.name, c.active FROM companies c JOIN company_apps ca ON ca.company_id = c.id
        JOIN apps a ON a.id = ca.app_id WHERE a.slug = ?' . company_scope_sql('c.id') . ' ORDER BY c.name', [$slug]);
}

function companies_for_select(): array
{
    if (is_admin()) return rows('SELECT id, name, active FROM companies WHERE 1 = 1' . company_scope_sql('id') . ' ORDER BY name');
    if (is_provider_user()) {
        $ids = provider_company_ids();
        return $ids ? rows('SELECT id, name, active FROM companies WHERE id IN (' . implode(',', $ids) . ') ORDER BY name') : [];
    }
    $u = current_user();
    return rows('SELECT id, name, active FROM companies WHERE id = ?', [$u['company_id']]);
}

// ---------------------------------------------------------------------------
// Darstellung
// ---------------------------------------------------------------------------
function status_badge(string $status, string $type = 'onboarding'): string
{
    return '<span class="badge st-' . e($status) . '">' . e(status_label($status, $type)) . '</span>';
}

function type_badge(string $type): string
{
    return '<span class="badge type-' . e($type) . '">' . e(t(REQUEST_TYPES[$type] ?? $type)) . '</span>';
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
    if (!is_admin() && !(is_provider_user() && count(provider_company_ids()) > 1)) return '';
    $html = '<form method="get" class="company-switch"><input type="hidden" name="r" value="' . e($route) . '">';
    foreach ($extra as $k => $v) $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    $html .= ('<label>' . e(t('Firma:')) . ' <select name="company" onchange="this.form.submit()">');
    foreach (companies_for_select() as $c) {
        $sel = (int)$c['id'] === $current ? ' selected' : '';
        $html .= '<option value="' . (int)$c['id'] . "\"$sel>" . e($c['name']) . ((int)$c['active'] ? '' : t(' (inaktiv)')) . '</option>';
    }
    $html .= ('</select></label><noscript><button class="btn small">' . e(t('Wechseln')) . '</button></noscript></form>');
    return $html;
}

function validate_password(string $pw): ?string
{
    if (mb_strlen($pw) < 10) return t('Das Passwort muss mindestens 10 Zeichen lang sein.');
    if (!preg_match('/[A-Za-zÄÖÜäöüß]/u', $pw) || !preg_match('/\d/', $pw)) {
        return t('Das Passwort muss Buchstaben und Ziffern enthalten.');
    }
    return null;
}
