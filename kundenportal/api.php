<?php
/**
 * REST-Schnittstelle der Kundendatenbank (App „Formulare“).
 *
 * Anmeldung:  Authorization: Bearer kp_…   oder   X-API-Key: kp_…
 * Pfade:      /api.php/kunden, /api.php/kunden/{id}, /api.php/kunden/nummer/{nr}, /api.php/felder
 *             (alternativ /api.php?pfad=kunden/12, falls der Server keine Pfade hinter .php erlaubt)
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/schema.php';
require APP_DIR . '/forms_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
    header('Strict-Transport-Security: max-age=31536000');
}

function api_out(int $code, $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function api_error(int $code, string $msg): never
{
    api_out($code, ['fehler' => $msg]);
}

function api_customer(array $c, int $cid): array
{
    return ['id' => (int)$c['id']] + customer_export_row($c, $cid)
        + ['erstellt' => $c['created_at'], 'geaendert' => $c['updated_at']];
}

/** Prüft Feldwerte aus JSON; unbekannte Schlüssel werden gemeldet */
function api_values(array $in, int $cid): array
{
    $fields = customer_all_fields($cid);
    $vals = [];
    $unknown = [];
    foreach ($in as $k => $v) {
        if (in_array($k, ['id', 'erstellt', 'geaendert'], true)) continue;
        if (!isset($fields[$k])) { $unknown[] = $k; continue; }
        if (!is_scalar($v) && $v !== null) api_error(422, "Feld „{$k}“ muss Text sein.");
        $vals[$k] = (string)($v ?? '');
    }
    if ($unknown) api_error(422, 'Unbekannte Felder: ' . implode(', ', $unknown) . '. Verfügbare Felder liefert GET /felder.');
    if (($vals['email'] ?? '') !== '' && !filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) api_error(422, 'Die E-Mail-Adresse ist ungültig.');
    return $vals;
}

if (!is_file(APP_DIR . '/data/installed.lock')) api_error(503, 'Das Portal ist noch nicht installiert.');
ensure_schema();

// --- Anmeldung -----------------------------------------------------------------
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$plain = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
$token = $plain !== '' ? api_token_lookup($plain) : null;
if (!$token) {
    usleep(300000);
    header('WWW-Authenticate: Bearer');
    api_error(401, 'Kein gültiger API-Schlüssel.');
}
$cid = (int)$token['company_id'];
$company = row('SELECT active FROM companies WHERE id = ?', [$cid]);
if (!$company || !(int)$company['active']) api_error(403, 'Die Firma ist deaktiviert.');
if (!company_has_app($cid, FORMS_APP)) api_error(403, 'Die App „Formulare“ ist für diese Firma nicht freigeschaltet.');
if (($token['last_used_at'] ?? '') < date('Y-m-d H:i:s', time() - 60)) q('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [now(), $token['id']]);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$canWrite = (int)$token['can_write'] === 1;
$path = trim((string)($_SERVER['PATH_INFO'] ?? ($_GET['pfad'] ?? $_GET['path'] ?? '')), '/');
$parts = $path === '' ? [] : explode('/', $path);
$parts[0] = strtolower($parts[0] ?? '');
if ($parts[0] === 'customers') $parts[0] = 'kunden';
if ($parts[0] === 'fields') $parts[0] = 'felder';

$body = null;
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    if (!$canWrite) api_error(403, 'Dieser API-Schlüssel darf nur lesen.');
    $raw = (string)file_get_contents('php://input', false, null, 0, 5 * 1024 * 1024);
    $body = json_decode($raw, true);
    if (!is_array($body)) api_error(400, 'Der Inhalt muss gültiges JSON sein.');
}
if ($method === 'DELETE' && !$canWrite) api_error(403, 'Dieser API-Schlüssel darf nur lesen.');
$tokenLabel = 'API: ' . $token['name'];

// --- Routen --------------------------------------------------------------------
if ($parts[0] === '' ) {
    api_out(200, ['firma_id' => $cid, 'schreibrechte' => $canWrite, 'pfade' => ['kunden', 'kunden/{id}', 'kunden/nummer/{nr}', 'felder']]);
}

if ($parts[0] === 'felder' && $method === 'GET') {
    $out = [];
    foreach (customer_all_fields($cid) as $k => $label) $out[] = ['feld' => $k, 'bezeichnung' => $label, 'eigenes_feld' => !isset(CUSTOMER_STD_FIELDS[$k])];
    api_out(200, $out);
}

if ($parts[0] !== 'kunden') api_error(404, 'Unbekannter Pfad.');

// GET /kunden
if (count($parts) === 1 && $method === 'GET') {
    $per = min(500, max(1, (int)($_GET['pro_seite'] ?? $_GET['per_page'] ?? 100)));
    $page = max(1, (int)($_GET['seite'] ?? $_GET['page'] ?? 1));
    $search = trim((string)($_GET['suche'] ?? $_GET['q'] ?? ''));
    $where = 'company_id = ?';
    $params = [$cid];
    if ($search !== '') {
        $where .= ' AND (customer_no LIKE ? OR company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR city LIKE ? OR email LIKE ?)';
        $params = array_merge($params, array_fill(0, 6, '%' . $search . '%'));
    }
    if (!empty($_GET['geaendert_seit'])) {
        $where .= ' AND updated_at >= ?';
        $params[] = date('Y-m-d H:i:s', strtotime((string)$_GET['geaendert_seit']) ?: 0);
    }
    $total = (int)row("SELECT COUNT(*) AS n FROM customers WHERE $where", $params)['n'];
    $list = rows("SELECT * FROM customers WHERE $where ORDER BY id LIMIT $per OFFSET " . (($page - 1) * $per), $params);
    api_out(200, ['gesamt' => $total, 'seite' => $page, 'pro_seite' => $per,
        'kunden' => array_map(fn($c) => api_customer($c, $cid), $list)]);
}

// POST /kunden  (ein Objekt oder eine Liste; mit vorhandener Nummer wird aktualisiert)
if (count($parts) === 1 && $method === 'POST') {
    $list = array_is_list($body) ? $body : [$body];
    if (count($list) > 1000) api_error(413, 'Höchstens 1000 Kunden pro Aufruf.');
    $results = [];
    db()->beginTransaction();
    foreach ($list as $item) {
        if (!is_array($item)) { db()->rollBack(); api_error(422, 'Jeder Eintrag muss ein Objekt sein.'); }
        $vals = api_values($item, $cid);
        $existing = ($vals['nummer'] ?? '') !== ''
            ? row('SELECT * FROM customers WHERE company_id = ? AND customer_no = ?', [$cid, trim($vals['nummer'])]) : null;
        if (!$existing && trim(($vals['firma'] ?? '') . ($vals['nachname'] ?? '') . ($vals['vorname'] ?? '')) === '') {
            db()->rollBack();
            api_error(422, 'Für neue Kunden ist mindestens „firma“ oder „nachname“ nötig.');
        }
        $id = customer_save($cid, $vals, $existing);
        $results[] = ['id' => $id, 'aktion' => $existing ? 'aktualisiert' : 'angelegt'];
    }
    db()->commit();
    audit('api_customers_saved', $tokenLabel . ' (' . count($results) . ')', $cid);
    if (!array_is_list($body)) {
        $c = load_customer_api($cid, $results[0]['id']);
        api_out($results[0]['aktion'] === 'angelegt' ? 201 : 200, api_customer($c, $cid));
    }
    api_out(200, ['ergebnisse' => $results]);
}

function load_customer_api(int $cid, int $id): array
{
    $c = row('SELECT * FROM customers WHERE id = ? AND company_id = ?', [$id, $cid]);
    if (!$c) api_error(404, 'Kunde nicht gefunden.');
    return $c;
}

// /kunden/nummer/{nr}
if (count($parts) === 3 && $parts[1] === 'nummer') {
    $c = row('SELECT * FROM customers WHERE company_id = ? AND customer_no = ?', [$cid, rawurldecode($parts[2])]);
    if (!$c) api_error(404, 'Kunde nicht gefunden.');
    $parts = ['kunden', (string)$c['id']];
}

// /kunden/{id}
if (count($parts) === 2 && ctype_digit($parts[1])) {
    $c = load_customer_api($cid, (int)$parts[1]);
    if ($method === 'GET') api_out(200, api_customer($c, $cid));
    if ($method === 'PUT' || $method === 'PATCH') {
        $vals = api_values($body, $cid);
        if (($vals['nummer'] ?? '') !== '' && row('SELECT id FROM customers WHERE company_id = ? AND customer_no = ? AND id <> ?', [$cid, $vals['nummer'], $c['id']])) {
            api_error(409, 'Die Kundennummer ist bereits vergeben.');
        }
        if ($method === 'PUT') $vals += array_fill_keys(array_keys(customer_all_fields($cid)), '');
        customer_save($cid, $vals, $c);
        audit('api_customer_updated', $tokenLabel . ' #' . $c['id'], $cid);
        api_out(200, api_customer(load_customer_api($cid, (int)$c['id']), $cid));
    }
    if ($method === 'DELETE') {
        q('DELETE FROM customers WHERE id = ?', [$c['id']]);
        audit('api_customer_deleted', $tokenLabel . ' #' . $c['id'], $cid);
        api_out(200, ['geloescht' => (int)$c['id']]);
    }
    api_error(405, 'Methode nicht erlaubt.');
}

api_error(404, 'Unbekannter Pfad.');
