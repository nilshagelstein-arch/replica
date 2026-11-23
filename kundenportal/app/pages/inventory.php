<?php
/**
 * App „Geräte & Lizenzen“ (Inventar) – Stufe 1: Geräte, Standorte/Lager, Rechte
 *
 * - Je Firma getrennt; Firmen-Admins dürfen alles, Gruppen (Abteilungen) bekommen „ansehen“ oder „bearbeiten“.
 * - Der Dienstleister (Superadmin, Verwaltungsadmins) sieht das Inventar nur mit Freigabe der Firma
 *   (companies.inv_provider_access) und dann nach seinem Bereichsrecht „inventory“.
 * - BitLocker-Schlüssel liegen verschlüsselt in der Datenbank und werden nur auf Knopfdruck angezeigt (Protokoll).
 */

declare(strict_types=1);

const INV_APP = 'inventar';
const INV_STATUSES = ['lager' => 'An Lager', 'einsatz' => 'Im Einsatz', 'reparatur' => 'In Reparatur', 'ausgemustert' => 'Ausgemustert', 'entsorgt' => 'Entsorgt'];
const INV_TYPES_DEFAULT = ['Notebook', 'PC', 'Monitor', 'Smartphone', 'Tablet', 'Drucker', 'Switch', 'Firewall', 'Access Point', 'Server', 'NAS', 'Telefon', 'Sonstiges'];
const INV_PERM_NONE = 0;
const INV_PERM_VIEW = 1;
const INV_PERM_EDIT = 2;
/** Felder, deren Änderung im Verlauf steht (Feld => Bezeichnung) */
const INV_LOG_FIELDS = ['inv_no' => 'Inventarnummer', 'manufacturer' => 'Hersteller', 'model' => 'Modell', 'serial' => 'Seriennummer', 'room' => 'Raum',
    'purchase_date' => 'Kaufdatum', 'supplier' => 'Lieferant', 'warranty_until' => 'Garantie bis', 'os' => 'Betriebssystem', 'ip' => 'IP-Adresse',
    'mac' => 'MAC-Adresse', 'phone_no' => 'Telefonnummer', 'sim_no' => 'SIM-Nummer', 'mobile_contract' => 'Mobilfunkvertrag'];

/** Firma aus der Anfrage, mit App-Freigabe und Zugriffsprüfung */
function inv_company_id(): int
{
    $u = require_app(INV_APP);
    $cid = context_company_id();
    if (!$cid) abort(404, t('Es ist noch keine Firma angelegt.'));
    require_company_access($cid);
    if (!row('SELECT id FROM companies WHERE id = ?', [$cid])) abort(404);
    if (!is_staff_role($u['role']) && !company_has_app($cid, INV_APP)) abort(403);
    $GLOBALS['inv_cid'] = $cid;
    inv_seed($cid);
    return $cid;
}

function iurl(string $route, array $params = []): string
{
    if (is_admin() && !empty($GLOBALS['inv_cid'])) $params = ['company' => (int)$GLOBALS['inv_cid']] + $params;
    return url($route, $params);
}

function iredirect(string $route, array $params = []): never
{
    header('Location: ' . iurl($route, $params));
    exit;
}

/** Standard-Gerätetypen beim ersten Aufruf einer Firma */
function inv_seed(int $cid): void
{
    if (row('SELECT id FROM inv_types WHERE company_id = ? LIMIT 1', [$cid])) return;
    foreach (INV_TYPES_DEFAULT as $i => $name) insert('inv_types', ['company_id' => $cid, 'name' => $name, 'sort' => ($i + 1) * 10, 'active' => 1]);
}

function inv_company(int $cid): array
{
    static $cache = [];
    return $cache[$cid] ??= (row('SELECT id, name, inv_provider_access, inv_number_mode, inv_handover_text, inv_lead_days, inv_tools, inv_tools_other, inv_my_keys FROM companies WHERE id = ?', [$cid]) ?? []);
}

/** Recht des angemeldeten Benutzers im Inventar der Firma: 0 kein, 1 ansehen, 2 bearbeiten */
function inv_perm(int $cid): int
{
    static $cache = [];
    $u = current_user();
    if (!$u) return INV_PERM_NONE;
    $key = $cid . ':' . (int)$u['id'];
    if (isset($cache[$key])) return $cache[$key];
    if (is_staff_role($u['role'])) {
        $p = (int)(inv_company($cid)['inv_provider_access'] ?? 0) === 1 ? admin_perm('inventory') : PERM_NONE;
        // eingeschränkte Verwaltungsadmins nur für ihre Firmen
        $ids = admin_company_ids($u);
        if ($ids !== null && !in_array($cid, $ids, true)) $p = PERM_NONE;
        return $cache[$key] = $p >= PERM_EDIT ? INV_PERM_EDIT : ($p >= PERM_VIEW ? INV_PERM_VIEW : INV_PERM_NONE);
    }
    if ((int)($u['company_id'] ?? 0) !== $cid) return $cache[$key] = INV_PERM_NONE;
    if ($u['role'] === 'firma_admin') return $cache[$key] = INV_PERM_EDIT;
    $p = row('SELECT MAX(g.perm) AS p FROM inv_group_perms g JOIN user_departments ud ON ud.department_id = g.department_id
        WHERE g.company_id = ? AND ud.user_id = ?', [$cid, $u['id']]);
    return $cache[$key] = min(INV_PERM_EDIT, (int)($p['p'] ?? 0));
}

/** Einstellungen (Standorte, Typen, Rechte) pflegen: Firmen-Admins und der Dienstleister mit Bearbeiten-Recht */
function inv_can_manage(int $cid): bool
{
    $u = current_user();
    // Dienstleister nur mit Einsicht-Freigabe der Firma und Bearbeiten-Recht für genau diese Firma
    if (is_staff_role($u['role'])) return inv_perm($cid) === INV_PERM_EDIT;
    return $u['role'] === 'firma_admin' && (int)$u['company_id'] === $cid;
}

function inv_staff_blocked(int $cid): bool
{
    return is_staff_role(current_user()['role']) && (int)(inv_company($cid)['inv_provider_access'] ?? 0) !== 1;
}

function inv_files_dir(int $cid): string
{
    $dir = APP_DIR . '/data/inventory/' . $cid;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

/** SQL-Zusatz: Kosten-Einträge im Verlauf ausblenden (auch ältere Einträge ohne Art „cost“) */
const INV_LOG_NO_COSTS = " AND kind <> 'cost' AND body NOT LIKE 'Kosten: %' AND body NOT LIKE 'Preis: %'";

function inv_log(int $cid, int $deviceId, string $body, string $kind = 'event'): void
{
    if ($kind === 'event' && (str_starts_with($body, 'Preis: ') || str_starts_with($body, 'Kosten: '))) $kind = 'cost';
    insert('inv_log', ['company_id' => $cid, 'device_id' => $deviceId, 'user_id' => current_user()['id'] ?? null, 'kind' => $kind, 'body' => $body, 'created_at' => now()]);
}

/** Nächste automatische Inventarnummer der Firma im laufenden Jahr, z. B. INV-2026-0001 */
function inv_next_number(int $cid): string
{
    $prefix = 'INV-' . date('Y') . '-';
    $max = 0;
    foreach (rows('SELECT inv_no FROM inv_devices WHERE company_id = ? AND inv_no LIKE ?', [$cid, $prefix . '%']) as $r) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string)$r['inv_no'], $m)) $max = max($max, (int)$m[1]);
    }
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/** Betrag „1.299,00“ oder „1299.5“ in Cent; leer = null */
function inv_parse_price(string $s): ?int
{
    $s = trim(str_replace(['€', ' ', "\u{00a0}"], '', $s));
    if ($s === '') return null;
    if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
    return is_numeric($s) ? (int)round((float)$s * 100) : null;
}

function inv_fmt_price(?int $cents): string
{
    return $cents === null ? '' : number_format($cents / 100, 2, ',', '.') . ' €';
}

function inv_date(string $s): string
{
    $s = trim($s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false ? $s : '';
}

function inv_load_device(int $id, int $cid): ?array
{
    $d = row('SELECT d.*, t.name AS type_name, l.name AS location_name, l.is_store, e.first_name, e.last_name, e.employee_no, e.status AS employee_status
        FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id LEFT JOIN inv_locations l ON l.id = d.location_id LEFT JOIN employees e ON e.id = d.employee_id
        WHERE d.id = ? AND d.company_id = ?', [$id, $cid]);
    if (!$d) return null;
    $d['employee_name'] = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
    return $d;
}

/** Mitarbeiter der Firma zur Auswahl (ohne Ausgeschiedene, außer dem aktuell zugeordneten) */
function inv_employees(int $cid, ?int $keep = null): array
{
    return rows("SELECT id, first_name, last_name, employee_no, status FROM employees WHERE company_id = ? AND (status <> 'ausgeschieden' OR id = ?)
        ORDER BY last_name, first_name", [$cid, (int)$keep]);
}

// ---------------------------------------------------------------------------
// Übersicht
// ---------------------------------------------------------------------------

function page_inventory(): void
{
    $cid = inv_company_id();
    $perm = inv_perm($cid);
    $co = inv_company($cid);
    if ($perm === INV_PERM_NONE && !inv_staff_blocked($cid)) { page_inv_my(); return; }
    if ($perm === INV_PERM_NONE) {
        render_page(t('Geräte & Lizenzen'), function () use ($cid) {
            echo company_switcher($cid, 'inventory'); ?>
            <div class="alert info"><?= e(inv_staff_blocked($cid)
                ? t('Das Inventar dieser Firma ist nur intern sichtbar. Die Firma kann die Einsicht für den IT-Dienstleister unter Einstellungen einschalten.')
                : t('Sie haben keinen Zugriff auf das Inventar. Die Rechte vergeben die Administratoren Ihrer Firma. Eine Übersicht „Meine Geräte“ folgt.')) ?></div>
            <?php if (inv_can_manage($cid)): ?><p><a class="btn" href="<?= e(iurl('inv_settings')) ?>"><?= e(t('Einstellungen')) ?></a></p><?php endif;
        });
        return;
    }
    $types = rows('SELECT * FROM inv_types WHERE company_id = ? ORDER BY sort, name', [$cid]);
    $locations = rows('SELECT * FROM inv_locations WHERE company_id = ? ORDER BY sort, name', [$cid]);
    $status = (string)($_GET['status'] ?? 'aktiv');
    if ($status !== 'alle' && $status !== 'aktiv' && !isset(INV_STATUSES[$status])) $status = 'aktiv';
    $type = query_int('type');
    $loc = query_int('location');
    $search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

    $where = ['d.company_id = ?'];
    $p = [$cid];
    if ($status === 'aktiv') $where[] = "d.status IN ('lager', 'einsatz', 'reparatur')";
    elseif ($status !== 'alle') { $where[] = 'd.status = ?'; $p[] = $status; }
    if ($type) { $where[] = 'd.type_id = ?'; $p[] = $type; }
    if ($loc) { $where[] = 'd.location_id = ?'; $p[] = $loc; }
    if ($search !== '') {
        $where[] = "(d.inv_no LIKE ? OR d.manufacturer LIKE ? OR d.model LIKE ? OR d.serial LIKE ? OR d.room LIKE ? OR d.ip LIKE ? OR d.mac LIKE ? OR d.phone_no LIKE ?
            OR e.first_name LIKE ? OR e.last_name LIKE ?)";
        for ($i = 0; $i < 10; $i++) $p[] = '%' . $search . '%';
    }
    $list = rows('SELECT d.*, t.name AS type_name, l.name AS location_name, e.first_name, e.last_name
        FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id LEFT JOIN inv_locations l ON l.id = d.location_id LEFT JOIN employees e ON e.id = d.employee_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY d.inv_no LIMIT 1000', $p);
    $counts = array_fill_keys(array_keys(INV_STATUSES), 0);
    foreach (rows('SELECT status, COUNT(*) AS n FROM inv_devices WHERE company_id = ? GROUP BY status', [$cid]) as $c) $counts[$c['status']] = (int)$c['n'];
    // Bestand an Lager je Standort und Typ
    $stock = [];
    foreach (rows("SELECT d.location_id, d.type_id, l.name AS location_name, t.name AS type_name, COUNT(*) AS n FROM inv_devices d
        JOIN inv_locations l ON l.id = d.location_id LEFT JOIN inv_types t ON t.id = d.type_id
        WHERE d.company_id = ? AND d.status = 'lager' GROUP BY d.location_id, d.type_id, l.name, t.name", [$cid]) as $s) {
        $stock[$s['location_id'] . ':' . (int)$s['type_id']] = $s + ['min' => 0];
    }
    // Mindestbestand: auch Kombinationen ohne Gerät an Lager zeigen
    foreach (rows('SELECT m.location_id, m.type_id, m.min_qty, l.name AS location_name, t.name AS type_name FROM inv_min_stock m
        JOIN inv_locations l ON l.id = m.location_id JOIN inv_types t ON t.id = m.type_id WHERE m.company_id = ? AND m.min_qty > 0', [$cid]) as $m) {
        $k = $m['location_id'] . ':' . (int)$m['type_id'];
        $stock[$k] = ($stock[$k] ?? ['location_name' => $m['location_name'], 'type_name' => $m['type_name'], 'n' => 0]) + ['min' => 0];
        $stock[$k]['min'] = (int)$m['min_qty'];
    }
    uasort($stock, fn($a, $b) => [$a['location_name'], (string)$a['type_name']] <=> [$b['location_name'], (string)$b['type_name']]);
    $soon = date('Y-m-d', strtotime('+60 days'));

    render_page(t('Geräte & Lizenzen'), function () use ($cid, $perm, $types, $locations, $status, $type, $loc, $search, $list, $counts, $stock, $soon) {
        echo company_switcher($cid, 'inventory');
        inv_tabs('inventory'); ?>
        <div class="toolbar">
            <?php if ($perm >= INV_PERM_EDIT): ?><a class="btn primary" href="<?= e(iurl('inv_device', ['id' => 'new'])) ?>"><?= e(t('+ Gerät erfassen')) ?></a><?php endif; ?>
            <?php if ($perm >= INV_PERM_EDIT): ?><a class="btn" href="<?= e(iurl('inv_handover_new', ['kind' => 'ausgabe'])) ?>"><?= e(t('Geräte ausgeben')) ?></a><?php endif; ?>
            <a class="btn" href="<?= e(iurl('inv_labels', array_filter(['status' => $status, 'type' => $type ?: null, 'location' => $loc ?: null]))) ?>" target="_blank" rel="noopener"><?= e(t('Etiketten drucken')) ?></a>
            <?php if (inv_can_see_costs($cid)): ?><a class="btn" href="<?= e(iurl('inv_costs')) ?>"><?= e(t('Kostenübersicht')) ?></a><?php endif; ?>
            <a class="btn" href="<?= e(iurl('inv_export', ['what' => 'devices'])) ?>"><?= e(t('CSV-Export')) ?></a>
            <?php if ($perm >= INV_PERM_EDIT): ?><a class="btn" href="<?= e(iurl('inv_import', ['what' => 'devices'])) ?>"><?= e(t('CSV-Import')) ?></a><?php endif; ?>
            <?php if (inv_can_manage($cid)): ?><a class="btn" href="<?= e(iurl('inv_settings')) ?>"><?= e(t('Einstellungen')) ?></a><?php endif; ?>
        </div>
        <?php inv_due_box($cid); ?>
        <div class="tiles">
            <?php foreach (['aktiv' => t('Alle aktiven')] + array_map('t', INV_STATUSES) as $k => $label):
                $n = $k === 'aktiv' ? $counts['lager'] + $counts['einsatz'] + $counts['reparatur'] : $counts[$k]; ?>
                <a class="tile <?= $status === $k ? 'active' : '' ?>" href="<?= e(iurl('inventory', ['status' => $k])) ?>"><strong><?= $n ?></strong><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
        </div>
        <form method="get" class="toolbar">
            <input type="hidden" name="r" value="inventory">
            <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('Suche: Nummer, Modell, Seriennummer, Person, IP …')) ?>">
            <select name="type"><option value=""><?= e(t('alle Typen')) ?></option>
                <?php foreach ($types as $tp): ?><option value="<?= (int)$tp['id'] ?>" <?= $type === (int)$tp['id'] ? 'selected' : '' ?>><?= e(t($tp['name'])) ?></option><?php endforeach; ?></select>
            <select name="location"><option value=""><?= e(t('alle Standorte')) ?></option>
                <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $loc === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select>
            <select name="status">
                <option value="aktiv" <?= $status === 'aktiv' ? 'selected' : '' ?>><?= e(t('Alle aktiven')) ?></option>
                <?php foreach (INV_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?>
                <option value="alle" <?= $status === 'alle' ? 'selected' : '' ?>><?= e(t('Alle')) ?></option>
            </select>
            <button class="btn"><?= e(t('Filtern')) ?></button>
        </form>
        <div class="card">
            <table class="table inv-table">
                <thead><tr><th><?= e(t('Nummer')) ?></th><th><?= e(t('Typ')) ?></th><th><?= e(t('Gerät')) ?></th><th><?= e(t('Seriennummer')) ?></th>
                    <th><?= e(t('Status')) ?></th><th><?= e(t('Standort')) ?></th><th><?= e(t('Mitarbeiter')) ?></th><th><?= e(t('Garantie bis')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($list as $d): ?>
                    <tr><td><a href="<?= e(iurl('inv_device', ['id' => $d['id']])) ?>"><strong><?= e($d['inv_no']) ?></strong></a></td>
                        <td><?= e($d['type_name'] !== null ? t($d['type_name']) : '–') ?></td>
                        <td><?= e(trim($d['manufacturer'] . ' ' . $d['model']) ?: '–') ?></td>
                        <td><?= e($d['serial']) ?></td>
                        <td><span class="badge inv-<?= e($d['status']) ?>"><?= e(t(INV_STATUSES[$d['status']] ?? $d['status'])) ?></span></td>
                        <td><?= e(trim(($d['location_name'] ?? '') . ($d['room'] !== '' ? ' · ' . $d['room'] : '')) ?: '–') ?></td>
                        <td><?= e(trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')) ?: '–') ?></td>
                        <td><?php if ($d['warranty_until'] !== ''): ?><span class="<?= $d['warranty_until'] < date('Y-m-d') ? 'muted' : ($d['warranty_until'] <= $soon ? 'warn-text' : '') ?>"><?= fmt_date($d['warranty_until']) ?></span><?php else: ?>–<?php endif; ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$list): ?><tr><td colspan="8" class="muted"><?= e(t('Keine Geräte gefunden.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
            <?php if (count($list) >= 1000): ?><p class="muted small"><?= e(t('Es werden höchstens 1000 Geräte angezeigt. Bitte die Suche eingrenzen.')) ?></p><?php endif; ?>
        </div>
        <div class="card">
            <h2><?= e(t('Bestand an Lager')) ?></h2>
            <?php if (!$stock): ?><p class="muted"><?= e(t('Derzeit liegt kein Gerät an einem Lager.')) ?></p><?php else: ?>
            <table class="table"><thead><tr><th><?= e(t('Standort')) ?></th><th><?= e(t('Typ')) ?></th><th class="num"><?= e(t('Anzahl')) ?></th><th class="num"><?= e(t('Mindestbestand')) ?></th></tr></thead><tbody>
            <?php foreach ($stock as $s): $low = $s['min'] > 0 && (int)$s['n'] < $s['min']; ?><tr><td><?= e($s['location_name']) ?></td><td><?= e($s['type_name'] !== null ? t($s['type_name']) : '–') ?></td>
                <td class="num"><?php if ($low): ?><span class="tag danger"><?= (int)$s['n'] ?></span><?php else: ?><?= (int)$s['n'] ?><?php endif; ?></td><td class="num"><?= $s['min'] > 0 ? (int)$s['min'] : '–' ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Gerät ansehen / erfassen / bearbeiten
// ---------------------------------------------------------------------------

function page_inv_device(): void
{
    $cid = inv_company_id();
    $perm = inv_perm($cid);
    if ($perm === INV_PERM_NONE) abort(403);
    $isNew = ($_GET['id'] ?? '') === 'new';
    if ($isNew && $perm < INV_PERM_EDIT) abort(403);
    $d = $isNew ? null : inv_load_device(query_int('id'), $cid);
    if (!$isNew && !$d) abort(404);
    $co = inv_company($cid);
    $types = rows('SELECT * FROM inv_types WHERE company_id = ? AND (active = 1 OR id = ?) ORDER BY sort, name', [$cid, (int)($d['type_id'] ?? 0)]);
    $locations = rows('SELECT * FROM inv_locations WHERE company_id = ? AND (active = 1 OR id = ?) ORDER BY sort, name', [$cid, (int)($d['location_id'] ?? 0)]);
    $employees = $perm >= INV_PERM_EDIT ? inv_employees($cid, $d ? (int)$d['employee_id'] : null) : [];
    $log = $d ? rows('SELECT l.*, u.name AS user_name FROM inv_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.device_id = ?'
        . (inv_can_see_costs($cid) ? '' : INV_LOG_NO_COSTS) . ' ORDER BY l.id DESC LIMIT 200', [$d['id']]) : [];
    $files = $d ? rows('SELECT * FROM inv_files WHERE device_id = ? ORDER BY id', [$d['id']]) : [];
    $licenses = $d ? rows('SELECT DISTINCT l.id, l.product, l.kind, l.status, l.end_date, l.notice_value, l.notice_unit, l.auto_renew, l.renew_months
        FROM inv_licenses l LEFT JOIN inv_license_assign a ON a.license_id = l.id
        WHERE l.company_id = ? AND (a.device_id = ? OR l.device_id = ?) ORDER BY l.product', [$cid, $d['id'], $d['id']]) : [];
    $revealed = $GLOBALS['inv_revealed'] ?? null;
    $title = $isNew ? t('Gerät erfassen') : $d['inv_no'] . ' · ' . (trim($d['manufacturer'] . ' ' . $d['model']) ?: t($d['type_name'] ?? 'Gerät'));

    render_page($title, function () use ($cid, $perm, $co, $d, $isNew, $types, $locations, $employees, $log, $files, $licenses, $revealed) {
        $edit = $perm >= INV_PERM_EDIT;
        $v = fn(string $k) => (string)($d[$k] ?? ''); ?>
        <p><a href="<?= e(iurl('inventory')) ?>">← <?= e(t('Geräte & Lizenzen')) ?></a></p>
        <div class="grid2 inv-grid">
        <div>
        <?php if ($edit): ?>
        <form method="post" action="<?= e(iurl('inv_action', $isNew ? [] : ['id' => $d['id']])) ?>" class="form card">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <h2><?= e(t('Stammdaten')) ?></h2>
            <div class="grid2">
                <label><?= e(t('Inventarnummer')) ?>
                    <input name="inv_no" maxlength="60" value="<?= e($v('inv_no')) ?>" <?= ($co['inv_number_mode'] ?? 'auto') === 'free' && $isNew ? 'required' : '' ?>
                        placeholder="<?= e(($co['inv_number_mode'] ?? 'auto') === 'auto' ? t('automatisch, z. B. {x}', ['x' => inv_next_number($cid)]) : '') ?>"></label>
                <label><?= e(t('Typ *')) ?> <select name="type_id" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                    <?php foreach ($types as $tp): ?><option value="<?= (int)$tp['id'] ?>" <?= (int)($d['type_id'] ?? 0) === (int)$tp['id'] ? 'selected' : '' ?>><?= e(t($tp['name'])) ?></option><?php endforeach; ?></select></label>
                <label><?= e(t('Hersteller')) ?> <input name="manufacturer" maxlength="120" value="<?= e($v('manufacturer')) ?>"></label>
                <label><?= e(t('Modell')) ?> <input name="model" maxlength="190" value="<?= e($v('model')) ?>"></label>
                <label><?= e(t('Seriennummer')) ?> <input name="serial" maxlength="120" value="<?= e($v('serial')) ?>"></label>
                <label><?= e(t('Status')) ?> <select name="status">
                    <?php foreach (INV_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($d['status'] ?? 'lager') === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></label>
            </div>
            <h3><?= e(t('Standort und Person')) ?></h3>
            <div class="grid2">
                <label><?= e(t('Standort / Lager')) ?> <select name="location_id"><option value=""><?= e(t('– kein Standort –')) ?></option>
                    <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)($d['location_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?><?= (int)$l['is_store'] ? ' (' . e(t('Lager')) . ')' : '' ?></option><?php endforeach; ?></select></label>
                <label><?= e(t('Raum')) ?> <input name="room" maxlength="120" value="<?= e($v('room')) ?>"></label>
                <label><?= e(t('Zugeordnet an')) ?> <select name="employee_id"><option value=""><?= e(t('– niemand –')) ?></option>
                    <?php foreach ($employees as $em): ?><option value="<?= (int)$em['id'] ?>" <?= (int)($d['employee_id'] ?? 0) === (int)$em['id'] ? 'selected' : '' ?>><?= e($em['last_name'] . ', ' . $em['first_name']) ?><?= $em['employee_no'] !== '' ? ' (' . e($em['employee_no']) . ')' : '' ?><?= $em['status'] !== 'aktiv' ? ' – ' . e(t(EMPLOYEE_STATUSES[$em['status']] ?? $em['status'])) : '' ?></option><?php endforeach; ?></select></label>
            </div>
            <?php if (!$employees): ?><p class="muted small"><?= e(t('Mitarbeiter kommen aus der Mitarbeiterliste der App On- & Offboarding.')) ?></p><?php endif; ?>
            <h3><?= e(t('Kauf')) ?></h3>
            <div class="grid2">
                <label><?= e(t('Kaufdatum')) ?> <input type="date" name="purchase_date" value="<?= e($v('purchase_date')) ?>"></label>
                <?php if (inv_can_see_costs($cid)): ?><label><?= e(t('Preis (€)')) ?> <input name="price" inputmode="decimal" maxlength="20" value="<?= e(isset($d['price_cents']) && $d['price_cents'] !== null ? number_format((int)$d['price_cents'] / 100, 2, ',', '.') : '') ?>"></label><?php endif; ?>
                <label><?= e(t('Lieferant')) ?> <input name="supplier" maxlength="190" value="<?= e($v('supplier')) ?>"></label>
                <label><?= e(t('Garantie bis')) ?> <input type="date" name="warranty_until" value="<?= e($v('warranty_until')) ?>"></label>
            </div>
            <h3><?= e(t('Technik')) ?></h3>
            <div class="grid2">
                <label><?= e(t('Betriebssystem')) ?> <input name="os" maxlength="120" value="<?= e($v('os')) ?>"></label>
                <label><?= e(t('IP-Adresse')) ?> <input name="ip" maxlength="64" value="<?= e($v('ip')) ?>"></label>
                <label><?= e(t('MAC-Adresse')) ?> <input name="mac" maxlength="64" value="<?= e($v('mac')) ?>"></label>
                <label><?= e(t('BitLocker-Schlüssel')) ?> <input name="bitlocker" maxlength="200" autocomplete="off"
                    placeholder="<?= e(!empty($d['bitlocker_enc']) ? t('gespeichert – leer lassen, um ihn zu behalten') : '') ?>"></label>
            </div>
            <?php if (!empty($d['bitlocker_enc'])): ?><label class="check"><input type="checkbox" name="bitlocker_clear" value="1"> <?= e(t('Gespeicherten BitLocker-Schlüssel löschen')) ?></label><?php endif; ?>
            <h3><?= e(t('Mobil')) ?></h3>
            <div class="grid2">
                <label><?= e(t('Telefonnummer')) ?> <input name="phone_no" maxlength="60" value="<?= e($v('phone_no')) ?>"></label>
                <label><?= e(t('SIM-/eSIM-Nummer')) ?> <input name="sim_no" maxlength="60" value="<?= e($v('sim_no')) ?>"></label>
                <label><?= e(t('Mobilfunkvertrag')) ?> <input name="mobile_contract" maxlength="190" value="<?= e($v('mobile_contract')) ?>"></label>
            </div>
            <label><?= e(t('Notiz')) ?> <textarea name="notes" rows="3"><?= e($v('notes')) ?></textarea></label>
            <button class="btn primary"><?= e($isNew ? t('Gerät anlegen') : t('Speichern')) ?></button>
        </form>
        <?php else: ?>
        <div class="card">
            <h2><?= e(t('Stammdaten')) ?></h2>
            <dl class="facts">
                <?php foreach ([t('Inventarnummer') => $v('inv_no'), t('Typ') => $d['type_name'] !== null ? t($d['type_name']) : '', t('Hersteller') => $v('manufacturer'), t('Modell') => $v('model'),
                    t('Seriennummer') => $v('serial'), t('Status') => t(INV_STATUSES[$d['status']] ?? $d['status']), t('Standort') => trim(($d['location_name'] ?? '') . ($d['room'] !== '' ? ' · ' . $d['room'] : '')),
                    t('Zugeordnet an') => (string)$d['employee_name'], t('Kaufdatum') => $d['purchase_date'] !== '' ? fmt_date($d['purchase_date']) : '', t('Preis') => inv_can_see_costs($cid) ? inv_fmt_price($d['price_cents'] !== null ? (int)$d['price_cents'] : null) : '',
                    t('Lieferant') => $v('supplier'), t('Garantie bis') => $d['warranty_until'] !== '' ? fmt_date($d['warranty_until']) : '', t('Betriebssystem') => $v('os'),
                    t('IP-Adresse') => $v('ip'), t('MAC-Adresse') => $v('mac'), t('Telefonnummer') => $v('phone_no'), t('SIM-/eSIM-Nummer') => $v('sim_no'), t('Mobilfunkvertrag') => $v('mobile_contract')] as $k => $val):
                    if ($val === '') continue; ?>
                    <dt><?= e($k) ?></dt><dd><?= e($val) ?></dd>
                <?php endforeach; ?>
            </dl>
            <?php if ($v('notes') !== ''): ?><p class="pre"><?= e($v('notes')) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
        <div>
        <?php if (!$isNew): ?>
            <?php inv_lifecycle_card($cid, $d, $edit); ?>
            <?php inv_device_tickets_card($cid, $d); ?>
            <?php if (function_exists('ct_inventory_card')) ct_inventory_card($cid, 'device', (int)$d['id']); ?>
            <?php if ($edit && !empty($d['bitlocker_enc'])): ?>
            <div class="card">
                <h3><?= e(t('BitLocker-Schlüssel')) ?></h3>
                <?php if ($revealed !== null): ?>
                    <p><code class="secret"><?= e($revealed) ?></code></p>
                    <p class="muted small"><?= e(t('Das Anzeigen wurde protokolliert. Der Schlüssel verschwindet beim nächsten Seitenaufruf.')) ?></p>
                <?php else: ?>
                    <form method="post" action="<?= e(iurl('inv_action', ['id' => $d['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="reveal">
                        <button class="btn"><?= e(t('Anzeigen')) ?></button></form>
                    <p class="muted small"><?= e(t('Jedes Anzeigen steht im Protokoll.')) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="card">
                <h3><?= e(t('Lizenzen')) ?></h3>
                <?php if ($licenses): ?><ul class="inv-log">
                    <?php foreach ($licenses as $lc): ?><li><a href="<?= e(iurl('inv_license', ['id' => $lc['id']])) ?>"><?= e($lc['product']) ?></a> <?= inv_lic_state_badge(inv_lic_state($lc)) ?>
                        <span class="muted small">· <?= e(t(INV_LIC_KINDS[$lc['kind']] ?? $lc['kind'])) ?><?= $lc['end_date'] !== '' ? ' · ' . e(t('bis')) . ' ' . fmt_date($lc['end_date']) : '' ?></span></li><?php endforeach; ?></ul>
                <?php else: ?><p class="muted"><?= e(t('Keine Lizenzen zugeordnet.')) ?></p><?php endif; ?>
            </div>
            <div class="card">
                <h3><?= e(t('Anhänge')) ?></h3>
                <?php if ($files): ?><ul class="file-list">
                    <?php foreach ($files as $f): ?><li><a href="<?= e(iurl('inv_file', ['id' => $f['id']])) ?>"><?= e($f['filename']) ?></a> <span class="muted small"><?= e(number_format($f['size'] / 1024, 0, ',', '.')) ?> KB · <?= fmt_dt($f['created_at']) ?></span>
                        <?php if ($edit): ?><form method="post" action="<?= e(iurl('inv_action', ['id' => $d['id']])) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="file_delete"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                            <button class="btn small danger" data-confirm="<?= e(t('Anhang „{x}“ löschen?', ['x' => $f['filename']])) ?>">✕</button></form><?php endif; ?></li>
                    <?php endforeach; ?></ul>
                <?php else: ?><p class="muted"><?= e(t('Keine Anhänge.')) ?></p><?php endif; ?>
                <?php if ($edit): ?>
                <form method="post" action="<?= e(iurl('inv_action', ['id' => $d['id']])) ?>" enctype="multipart/form-data" class="form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
                    <label><?= e(t('Rechnung, Foto, Lieferschein …')) ?> <input type="file" name="files[]" multiple required></label>
                    <button class="btn small"><?= e(t('Hochladen')) ?></button>
                </form>
                <?php endif; ?>
            </div>
            <div class="card">
                <h3><?= e(t('Verlauf')) ?></h3>
                <?php if ($edit): ?>
                <form method="post" action="<?= e(iurl('inv_action', ['id' => $d['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="note">
                    <label><?= e(t('Notiz zum Verlauf')) ?> <input name="body" maxlength="2000" required placeholder="<?= e(t('z. B. Akku getauscht, Display defekt')) ?>"></label>
                    <button class="btn small"><?= e(t('Eintragen')) ?></button></form>
                <?php endif; ?>
                <ul class="inv-log">
                <?php foreach ($log as $l): ?>
                    <li><span class="muted small"><?= fmt_dt($l['created_at']) ?> · <?= e($l['user_name'] ?? t('System')) ?></span><br>
                        <?= $l['kind'] === 'note' ? '📝 ' : '' ?><?= e(t_parts((string)$l['body'])) ?></li>
                <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($edit && (in_array($d['status'], ['ausgemustert', 'entsorgt'], true) || current_user()['role'] === 'firma_admin' || is_staff_role(current_user()['role']))): ?>
            <form method="post" action="<?= e(iurl('inv_action', ['id' => $d['id']])) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <p class="muted small"><?= e(t('Versehentlich angelegt? Ausgemusterte Geräte bitte nicht löschen, damit der Verlauf erhalten bleibt.')) ?></p>
                <button class="btn small danger" data-confirm="<?= e(t('Gerät {x} mit Verlauf und Anhängen endgültig löschen?', ['x' => $d['inv_no']])) ?>"><?= e(t('Gerät löschen')) ?></button></form>
            <?php endif; ?>
        <?php else: ?>
            <div class="card"><p class="muted"><?= e(t('Nach dem Anlegen können Sie Anhänge hochladen und den Verlauf führen.')) ?></p></div>
        <?php endif; ?>
        </div>
        </div>
    <?php });
}

/** Aktionen am Gerät (nur mit Recht „bearbeiten“) */
function page_inv_action(): void
{
    $cid = inv_company_id();
    if (!is_post()) abort(405);
    require_post_csrf();
    if (inv_perm($cid) < INV_PERM_EDIT) abort(403);
    $action = (string)post('action');
    $idParam = (string)($_GET['id'] ?? '');
    $d = $idParam !== '' ? inv_load_device((int)$idParam, $cid) : null;
    if ($idParam !== '' && !$d) abort(404);
    $back = fn() => iredirect('inv_device', ['id' => $d['id']]);

    if ($action === 'save') {
        $co = inv_company($cid);
        $type = row('SELECT * FROM inv_types WHERE id = ? AND company_id = ?', [(int)post('type_id', 0), $cid]);
        $loc = (int)post('location_id', 0) ? row('SELECT * FROM inv_locations WHERE id = ? AND company_id = ?', [(int)post('location_id', 0), $cid]) : null;
        $emp = (int)post('employee_id', 0) ? row('SELECT id, first_name, last_name FROM employees WHERE id = ? AND company_id = ?', [(int)post('employee_id', 0), $cid]) : null;
        $status = (string)post('status');
        $err = null;
        if (!$type) $err = t('Bitte einen Gerätetyp wählen.');
        if (!isset(INV_STATUSES[$status])) $status = 'lager';
        if ((int)post('location_id', 0) && !$loc) abort(400);
        if ((int)post('employee_id', 0) && !$emp) abort(400);
        $f = fn(string $k, int $max) => mb_substr(trim((string)post($k)), 0, $max);
        $data = ['type_id' => $type['id'] ?? null, 'manufacturer' => $f('manufacturer', 120), 'model' => $f('model', 190), 'serial' => $f('serial', 120),
            'status' => $status, 'location_id' => $loc['id'] ?? null, 'room' => $f('room', 120), 'employee_id' => $emp['id'] ?? null,
            'purchase_date' => inv_date((string)post('purchase_date')), 'price_cents' => inv_can_see_costs($cid) ? inv_parse_price((string)post('price')) : ($d['price_cents'] ?? null), 'supplier' => $f('supplier', 190),
            'warranty_until' => inv_date((string)post('warranty_until')), 'os' => $f('os', 120), 'ip' => $f('ip', 64), 'mac' => strtoupper($f('mac', 64)),
            'phone_no' => $f('phone_no', 60), 'sim_no' => $f('sim_no', 60), 'mobile_contract' => $f('mobile_contract', 190), 'notes' => $f('notes', 5000), 'updated_at' => now()];
        $invNo = $f('inv_no', 60);
        if ($invNo === '' && ($co['inv_number_mode'] ?? 'auto') === 'free') $err ??= t('Bitte eine Inventarnummer angeben.');
        if ($invNo === '') $invNo = $d['inv_no'] ?? inv_next_number($cid);
        if (row('SELECT id FROM inv_devices WHERE company_id = ? AND LOWER(inv_no) = LOWER(?) AND id <> ?', [$cid, $invNo, (int)($d['id'] ?? 0)])) {
            $err ??= t('Die Inventarnummer {x} ist bereits vergeben.', ['x' => $invNo]);
        }
        if ($data['serial'] !== '' && ($dup = row('SELECT inv_no FROM inv_devices WHERE company_id = ? AND serial = ? AND id <> ?', [$cid, $data['serial'], (int)($d['id'] ?? 0)]))) {
            flash('info', t('Hinweis: Die Seriennummer steht auch bei {x}.', ['x' => $dup['inv_no']]));
        }
        if ($err) { flash('error', $err); $d ? $back() : iredirect('inv_device', ['id' => 'new']); }
        $data['inv_no'] = $invNo;
        $bit = trim((string)post('bitlocker'));
        if ($bit !== '') $data['bitlocker_enc'] = encrypt_secret(mb_substr($bit, 0, 200));
        elseif (post('bitlocker_clear') === '1') $data['bitlocker_enc'] = null;

        if (!$d) {
            $id = insert('inv_devices', $data + ['company_id' => $cid, 'created_by' => current_user()['id'], 'created_at' => now()]);
            inv_log($cid, $id, 'Gerät angelegt: ' . $invNo);
            if ($loc) inv_log($cid, $id, 'Standort: ' . $loc['name']);
            if ($emp) inv_log($cid, $id, 'Zugeordnet an: ' . trim($emp['first_name'] . ' ' . $emp['last_name']));
            audit('inventory.create', $invNo, $cid);
            flash('success', t('Das Gerät {x} wurde angelegt.', ['x' => $invNo]));
            iredirect('inv_device', ['id' => $id]);
        }
        // Änderungen im Verlauf festhalten
        $changes = [];
        if ($d['status'] !== $status) $changes[] = 'Status: ' . INV_STATUSES[$status];
        if ((int)$d['type_id'] !== (int)$data['type_id']) $changes[] = 'Typ: ' . ($type['name'] ?? '–');
        if ((int)$d['location_id'] !== (int)$data['location_id']) $changes[] = 'Standort: ' . ($loc['name'] ?? '–');
        if ((int)$d['employee_id'] !== (int)$data['employee_id']) $changes[] = $emp ? 'Zugeordnet an: ' . trim($emp['first_name'] . ' ' . $emp['last_name']) : 'Zuordnung aufgehoben: ' . ($d['employee_name'] ?: '–');
        if (($d['price_cents'] === null ? null : (int)$d['price_cents']) !== $data['price_cents']) $changes[] = 'Preis: ' . (inv_fmt_price($data['price_cents']) ?: '–');
        foreach (INV_LOG_FIELDS as $k => $label) {
            $new = $k === 'inv_no' ? $invNo : $data[$k];
            if ((string)$d[$k] !== (string)$new) $changes[] = $label . ': ' . ($new !== '' ? (in_array($k, ['purchase_date', 'warranty_until'], true) ? fmt_date($new) : $new) : '–');
        }
        if (array_key_exists('bitlocker_enc', $data)) $changes[] = $data['bitlocker_enc'] === null ? 'BitLocker-Schlüssel gelöscht' : 'BitLocker-Schlüssel geändert';
        if ($d['notes'] !== $data['notes']) $changes[] = 'Notiz geändert';
        update('inv_devices', (int)$d['id'], $data);
        if ($d['status'] !== $status && in_array($status, ['ausgemustert', 'entsorgt'], true) && function_exists('ct_retire_hint')) ct_retire_hint($cid, (int)$d['id']);
        foreach ($changes as $c) inv_log($cid, (int)$d['id'], $c);
        if ($changes) audit('inventory.update', $invNo . ': ' . implode('; ', array_map(fn($c) => mb_substr($c, 0, 60), $changes)), $cid);
        flash('success', $changes ? t('Gespeichert.') : t('Keine Änderungen.'));
        $back();
    }
    if (!$d) abort(400);
    switch ($action) {
        case 'reveal':
            $plain = decrypt_secret($d['bitlocker_enc'] ?? null);
            if ($plain === '') { flash('error', t('Es ist kein BitLocker-Schlüssel gespeichert.')); $back(); }
            inv_log($cid, (int)$d['id'], 'BitLocker-Schlüssel angezeigt');
            audit('inventory.reveal_bitlocker', $d['inv_no'], $cid);
            // direkt anzeigen statt umleiten, damit der Schlüssel nicht in der Sitzung landet
            $_GET['id'] = (string)$d['id'];
            $GLOBALS['inv_revealed'] = $plain;
            header('Cache-Control: no-store');
            page_inv_device();
            return;
        case 'note':
            $body = mb_substr(trim((string)post('body')), 0, 2000);
            if ($body !== '') inv_log($cid, (int)$d['id'], $body, 'note');
            $back();
        case 'upload':
            [$files, $err] = ticket_uploads();
            if ($err) { flash('error', $err); $back(); }
            $dir = inv_files_dir($cid);
            foreach ($files as $f) {
                $stored = $d['id'] . '-' . bin2hex(random_bytes(12)) . '.bin';
                if (!move_uploaded_file($f['tmp'], $dir . '/' . $stored)) continue;
                @chmod($dir . '/' . $stored, 0640);
                $mime = function_exists('mime_content_type') ? (string)@mime_content_type($dir . '/' . $stored) : 'application/octet-stream';
                insert('inv_files', ['company_id' => $cid, 'device_id' => $d['id'], 'filename' => $f['name'], 'mime' => mb_substr($mime, 0, 100), 'size' => $f['size'],
                    'stored_name' => $stored, 'user_id' => current_user()['id'], 'created_at' => now()]);
                inv_log($cid, (int)$d['id'], 'Anhang hinzugefügt: ' . $f['name']);
            }
            flash('success', t('Die Dateien wurden hochgeladen.'));
            $back();
        case 'file_delete':
            $f = row('SELECT * FROM inv_files WHERE id = ? AND device_id = ?', [(int)post('file_id', 0), $d['id']]);
            if ($f) {
                @unlink(inv_files_dir($cid) . '/' . basename((string)$f['stored_name']));
                q('DELETE FROM inv_files WHERE id = ?', [$f['id']]);
                inv_log($cid, (int)$d['id'], 'Anhang gelöscht: ' . $f['filename']);
            }
            $back();
        case 'delete':
            $u = current_user();
            if (!in_array($d['status'], ['ausgemustert', 'entsorgt'], true) && $u['role'] !== 'firma_admin' && !is_staff_role($u['role'])) abort(403);
            foreach (rows('SELECT stored_name FROM inv_files WHERE device_id = ?', [$d['id']]) as $f) @unlink(inv_files_dir($cid) . '/' . basename((string)$f['stored_name']));
            q('DELETE FROM inv_files WHERE device_id = ?', [$d['id']]);
            q('DELETE FROM inv_log WHERE device_id = ?', [$d['id']]);
            q('DELETE FROM inv_license_assign WHERE device_id = ?', [$d['id']]);
            q('DELETE FROM ct_links WHERE device_id = ? AND company_id = ?', [$d['id'], $cid]);
            q('UPDATE inv_licenses SET device_id = NULL WHERE device_id = ?', [$d['id']]);
            q('DELETE FROM inv_devices WHERE id = ?', [$d['id']]);
            audit('inventory.delete', $d['inv_no'], $cid);
            flash('success', t('Das Gerät {x} wurde gelöscht.', ['x' => $d['inv_no']]));
            iredirect('inventory');
    }
    abort(400);
}

function page_inv_file(): void
{
    $cid = inv_company_id();
    if (inv_perm($cid) === INV_PERM_NONE) abort(403);
    $f = row('SELECT * FROM inv_files WHERE id = ? AND company_id = ?', [query_int('id'), $cid]);
    if (!$f) abort(404);
    $path = inv_files_dir($cid) . '/' . basename((string)$f['stored_name']);
    if (!is_file($path)) abort(404);
    $inline = in_array($f['mime'], ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'], true);
    header('Content-Type: ' . ($inline ? $f['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode((string)$f['filename']));
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

// ---------------------------------------------------------------------------
// Einstellungen: Nummern, Typen, Standorte, Rechte, Einsicht für den Dienstleister
// ---------------------------------------------------------------------------

function page_inv_settings(): void
{
    $cid = inv_company_id();
    if (!inv_can_manage($cid)) abort(403, t('Die Einstellungen pflegen die Administratoren der Firma.'));
    $staff = is_staff_role(current_user()['role']);
    $co = inv_company($cid);

    if (is_post()) {
        require_post_csrf();
        $action = (string)post('action');
        switch ($action) {
            case 'general':
                $data = ['inv_number_mode' => post('number_mode') === 'free' ? 'free' : 'auto',
                    'inv_handover_text' => mb_substr(trim(str_replace("\r", '', (string)post('handover_text'))), 0, 3000),
                    'inv_lead_days' => max(7, min(365, (int)post('lead_days', INV_LEAD_DEFAULT))),
                    'inv_my_keys' => post('my_keys') === '1' ? 1 : 0];
                // Die Einsicht für den Dienstleister schaltet nur die Firma selbst
                if (!$staff) $data['inv_provider_access'] = post('provider_access') === '1' ? 1 : 0;
                update('companies', $cid, $data);
                audit('inventory.settings', json_encode($data), $cid);
                flash('success', t('Die Einstellungen wurden gespeichert.'));
                break;
            case 'type_save':
                $name = mb_substr(trim((string)post('name')), 0, 120);
                $id = (int)post('type_id', 0);
                if ($name === '') { flash('error', t('Bitte einen Namen angeben.')); break; }
                if ($id) {
                    if (!row('SELECT id FROM inv_types WHERE id = ? AND company_id = ?', [$id, $cid])) abort(404);
                    update('inv_types', $id, ['name' => $name, 'active' => post('active') === '1' ? 1 : 0, 'sort' => (int)post('sort', 0)]);
                } else {
                    $max = (int)(row('SELECT MAX(sort) AS m FROM inv_types WHERE company_id = ?', [$cid])['m'] ?? 0);
                    insert('inv_types', ['company_id' => $cid, 'name' => $name, 'sort' => $max + 10, 'active' => 1]);
                }
                flash('success', t('Der Gerätetyp wurde gespeichert.'));
                break;
            case 'location_save':
                $name = mb_substr(trim((string)post('name')), 0, 190);
                $id = (int)post('location_id', 0);
                if ($name === '') { flash('error', t('Bitte einen Namen angeben.')); break; }
                $data = ['name' => $name, 'address' => mb_substr(trim((string)post('address')), 0, 255), 'is_store' => post('is_store') === '1' ? 1 : 0, 'sort' => (int)post('sort', 0)];
                if ($id) {
                    if (!row('SELECT id FROM inv_locations WHERE id = ? AND company_id = ?', [$id, $cid])) abort(404);
                    update('inv_locations', $id, $data + ['active' => post('active') === '1' ? 1 : 0]);
                } else {
                    insert('inv_locations', $data + ['company_id' => $cid, 'active' => 1]);
                }
                audit('inventory.location', $name, $cid);
                flash('success', t('Der Standort wurde gespeichert.'));
                break;
            case 'min_stock':
                q('DELETE FROM inv_min_stock WHERE company_id = ?', [$cid]);
                $locIds = array_column(rows('SELECT id FROM inv_locations WHERE company_id = ? AND is_store = 1', [$cid]), 'id');
                $typeIds = array_column(rows('SELECT id FROM inv_types WHERE company_id = ?', [$cid]), 'id');
                foreach ((array)($_POST['min'] ?? []) as $lid => $byType) {
                    if (!in_array((int)$lid, array_map('intval', $locIds), true) || !is_array($byType)) continue;
                    foreach ($byType as $tid => $qty) {
                        $qty = max(0, min(9999, (int)$qty));
                        if ($qty > 0 && in_array((int)$tid, array_map('intval', $typeIds), true)) insert('inv_min_stock', ['company_id' => $cid, 'location_id' => (int)$lid, 'type_id' => (int)$tid, 'min_qty' => $qty]);
                    }
                }
                audit('inventory.min_stock', '', $cid);
                flash('success', t('Der Mindestbestand wurde gespeichert.'));
                break;
            case 'perms':
                $deps = rows('SELECT id FROM departments WHERE company_id = ?', [$cid]);
                q('DELETE FROM inv_group_perms WHERE company_id = ?', [$cid]);
                foreach ($deps as $dp) {
                    $p = (int)(($_POST['perm'] ?? [])[$dp['id']] ?? 0);
                    if ($p > 0) insert('inv_group_perms', ['company_id' => $cid, 'department_id' => $dp['id'], 'perm' => min(INV_PERM_EDIT, $p)]);
                }
                audit('inventory.perms', json_encode($_POST['perm'] ?? []), $cid);
                flash('success', t('Die Rechte wurden gespeichert.'));
                break;
            default:
                abort(400);
        }
        iredirect('inv_settings');
    }

    $types = rows('SELECT t.*, (SELECT COUNT(*) FROM inv_devices d WHERE d.type_id = t.id) AS n FROM inv_types t WHERE t.company_id = ? ORDER BY t.sort, t.name', [$cid]);
    $locations = rows('SELECT l.*, (SELECT COUNT(*) FROM inv_devices d WHERE d.location_id = l.id) AS n FROM inv_locations l WHERE l.company_id = ? ORDER BY l.sort, l.name', [$cid]);
    $deps = rows('SELECT d.id, d.name, COALESCE(g.perm, 0) AS perm FROM departments d LEFT JOIN inv_group_perms g ON g.department_id = d.id AND g.company_id = d.company_id
        WHERE d.company_id = ? AND d.active = 1 ORDER BY d.name', [$cid]);

    render_page(t('Einstellungen') . ' · ' . t('Geräte & Lizenzen'), function () use ($cid, $co, $staff, $types, $locations, $deps) {
        echo company_switcher($cid, 'inv_settings'); ?>
        <p><a href="<?= e(iurl('inventory')) ?>">← <?= e(t('Geräte & Lizenzen')) ?></a></p>
        <form method="post" class="form card">
            <?= csrf_field() ?><input type="hidden" name="action" value="general">
            <h2><?= e(t('Allgemein')) ?></h2>
            <label><?= e(t('Inventarnummern')) ?> <select name="number_mode">
                <option value="auto" <?= ($co['inv_number_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>><?= e(t('automatisch fortlaufend je Jahr (INV-2026-0001)')) ?></option>
                <option value="free" <?= ($co['inv_number_mode'] ?? 'auto') === 'free' ? 'selected' : '' ?>><?= e(t('frei vergeben (eigene Nummern)')) ?></option>
            </select></label>
            <p class="muted small"><?= e(t('Auch bei automatischen Nummern kann eine eigene Nummer eingetragen werden; bestehende Nummern bleiben erhalten.')) ?></p>
            <label><?= e(t('Hinweistext im Übergabeprotokoll')) ?> <textarea name="handover_text" rows="4" placeholder="<?= e(t(INV_HO_TEXT_DEFAULT)) ?>"><?= e((string)($co['inv_handover_text'] ?? '')) ?></textarea></label>
            <p class="muted small"><?= e(t('Leer lassen für den Standardtext. Der Text wird beim Erstellen eines Protokolls übernommen.')) ?></p>
            <label><?= e(t('Vorlauf für Erinnerungen (Tage)')) ?> <input type="number" name="lead_days" min="7" max="365" value="<?= (int)($co['inv_lead_days'] ?? INV_LEAD_DEFAULT) ?: INV_LEAD_DEFAULT ?>" class="narrow-input"></label>
            <p class="muted small"><?= e(t('So viele Tage vor Lizenzende, Kündigungsfrist und Garantieende erscheint der Punkt unter „Demnächst fällig“ und die Firmen-Admins bekommen eine E-Mail – mit Einsicht auch der IT-Dienstleister.')) ?></p>
            <label class="check"><input type="checkbox" name="my_keys" value="1" <?= (int)($co['inv_my_keys'] ?? 0) ? 'checked' : '' ?>>
                <?= e(t('Mitarbeiter dürfen die Schlüssel ihrer eigenen Lizenzen unter „Meine Geräte“ anzeigen')) ?></label>
            <p class="muted small"><?= e(t('Jedes Anzeigen wird im Verlauf der Lizenz protokolliert.')) ?></p>
            <label class="check"><input type="checkbox" name="provider_access" value="1" <?= (int)($co['inv_provider_access'] ?? 0) ? 'checked' : '' ?> <?= $staff ? 'disabled' : '' ?>>
                <?= e(t('Einsicht für den IT-Dienstleister: Unser IT-Dienstleister darf unser Inventar sehen und pflegen')) ?></label>
            <?php if ($staff): ?><p class="muted small"><?= e(t('Diese Einsicht kann nur ein Administrator der Firma ein- oder ausschalten.')) ?></p><?php endif; ?>
            <button class="btn primary"><?= e(t('Speichern')) ?></button>
        </form>

        <div class="card">
            <h2><?= e(t('Rechte je Gruppe')) ?></h2>
            <p class="muted"><?= e(t('Firmen-Administratoren dürfen immer alles. Weitere Rechte vergeben Sie je Gruppe (Abteilung); wer mehreren Gruppen angehört, bekommt das höchste Recht.')) ?></p>
            <?php if (!$deps): ?><p class="muted"><?= e(t('Es sind noch keine Abteilungen angelegt (App On- & Offboarding → Struktur).')) ?></p><?php else: ?>
            <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="perms">
                <table class="table"><thead><tr><th><?= e(t('Gruppe')) ?></th><th><?= e(t('Recht')) ?></th></tr></thead><tbody>
                <?php foreach ($deps as $dp): ?><tr><td><?= e($dp['name']) ?></td><td><select name="perm[<?= (int)$dp['id'] ?>]">
                    <?php foreach ([0 => t('kein Zugriff'), 1 => t('ansehen'), 2 => t('bearbeiten')] as $k => $l): ?><option value="<?= $k ?>" <?= (int)$dp['perm'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select></td></tr><?php endforeach; ?>
                </tbody></table>
                <button class="btn primary"><?= e(t('Rechte speichern')) ?></button>
            </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><?= e(t('Standorte und Lager')) ?></h2>
            <p class="muted"><?= e(t('Jeder Standort hat seinen eigenen Bestand. Als Lager markierte Standorte erscheinen in der Bestandsübersicht.')) ?></p>
            <?php foreach ($locations as $l): ?>
                <details class="queue-box"><summary><strong><?= e($l['name']) ?></strong> <?= (int)$l['is_store'] ? '<span class="tag">' . e(t('Lager')) . '</span>' : '' ?>
                    <?= (int)$l['active'] ? '' : '<span class="tag">' . e(t('inaktiv')) . '</span>' ?> <span class="muted small">· <?= e(t('{n} Geräte', ['n' => (int)$l['n']])) ?></span></summary>
                    <?php inv_location_form($l); ?></details>
            <?php endforeach; ?>
            <details class="queue-box" <?= $locations ? '' : 'open' ?>><summary><strong><?= e(t('+ Standort hinzufügen')) ?></strong></summary><?php inv_location_form(null); ?></details>
        </div>

        <?php $stores = array_values(array_filter($locations, fn($l) => (int)$l['is_store'] && (int)$l['active'])); $activeTypes = array_values(array_filter($types, fn($tp) => (int)$tp['active']));
        $mins = []; foreach (rows('SELECT location_id, type_id, min_qty FROM inv_min_stock WHERE company_id = ?', [$cid]) as $m) $mins[$m['location_id']][$m['type_id']] = (int)$m['min_qty']; ?>
        <div class="card">
            <h2><?= e(t('Mindestbestand')) ?></h2>
            <p class="muted"><?= e(t('Liegen an einem Lager weniger Geräte eines Typs als hier angegeben, erscheint eine Bestandswarnung und die Firmen-Admins und Gruppen mit „bearbeiten“ bekommen eine E-Mail. Leer oder 0 = keine Warnung.')) ?></p>
            <?php if (!$stores): ?><p class="muted"><?= e(t('Bitte zuerst einen Standort als Lager anlegen.')) ?></p><?php else: ?>
            <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="min_stock">
                <div class="table-scroll"><table class="table"><thead><tr><th><?= e(t('Typ')) ?></th><?php foreach ($stores as $l): ?><th class="num"><?= e($l['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach ($activeTypes as $tp): ?><tr><td><?= e(t($tp['name'])) ?></td><?php foreach ($stores as $l): $v = $mins[$l['id']][$tp['id']] ?? 0; ?>
                    <td class="num"><input type="number" min="0" max="9999" class="narrow-input" name="min[<?= (int)$l['id'] ?>][<?= (int)$tp['id'] ?>]" value="<?= $v ?: '' ?>" aria-label="<?= e(t($tp['name']) . ' · ' . $l['name']) ?>"></td><?php endforeach; ?></tr><?php endforeach; ?>
                </tbody></table></div>
                <button class="btn primary"><?= e(t('Mindestbestand speichern')) ?></button>
            </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><?= e(t('Gerätetypen')) ?></h2>
            <table class="table"><thead><tr><th><?= e(t('Name')) ?></th><th class="num"><?= e(t('Reihenfolge')) ?></th><th><?= e(t('Aktiv')) ?></th><th class="num"><?= e(t('Geräte')) ?></th><th></th></tr></thead><tbody>
            <?php foreach ($types as $tp): $fid = 'type-' . (int)$tp['id']; ?>
                <tr><td><input form="<?= $fid ?>" name="name" maxlength="120" value="<?= e($tp['name']) ?>" required></td>
                    <td class="num"><input form="<?= $fid ?>" type="number" name="sort" value="<?= (int)$tp['sort'] ?>" class="narrow-input"></td>
                    <td><input form="<?= $fid ?>" type="checkbox" name="active" value="1" <?= (int)$tp['active'] ? 'checked' : '' ?>></td>
                    <td class="num"><?= (int)$tp['n'] ?></td>
                    <td><form method="post" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="type_save"><input type="hidden" name="type_id" value="<?= (int)$tp['id'] ?>">
                        <button class="btn small"><?= e(t('Speichern')) ?></button></form></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <form method="post" class="toolbar"><?= csrf_field() ?><input type="hidden" name="action" value="type_save">
                <input name="name" maxlength="120" required placeholder="<?= e(t('Neuer Gerätetyp, z. B. Beamer')) ?>"> <button class="btn"><?= e(t('Hinzufügen')) ?></button></form>
        </div>

        <div class="card">
            <h2><?= e(t('Anbindungen')) ?> <span class="tag"><?= e(t('demnächst')) ?></span></h2>
            <p class="muted"><?= e(t('Geräte sollen später automatisch aus diesen Systemen übernommen werden:')) ?> Terra Management Cloud, NinjaOne, PRTG, Microsoft Intune / Entra ID, TANSS.</p>
            <p><a class="btn" href="<?= e(iurl('inv_integrations')) ?>"><?= e(t('Genutzte Systeme angeben')) ?></a></p>
        </div>
    <?php });
}

function inv_location_form(?array $l): void
{ ?>
    <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="location_save"><input type="hidden" name="location_id" value="<?= (int)($l['id'] ?? 0) ?>">
        <div class="grid2">
            <label><?= e(t('Name *')) ?> <input name="name" maxlength="190" required value="<?= e($l['name'] ?? '') ?>" placeholder="<?= e(t('z. B. Zentrale, Lager Keller, Filiale Nord')) ?>"></label>
            <label><?= e(t('Adresse')) ?> <input name="address" maxlength="255" value="<?= e($l['address'] ?? '') ?>"></label>
            <label><?= e(t('Reihenfolge')) ?> <input type="number" name="sort" value="<?= (int)($l['sort'] ?? 0) ?>"></label>
        </div>
        <label class="check"><input type="checkbox" name="is_store" value="1" <?= !$l || (int)$l['is_store'] ? 'checked' : '' ?>> <?= e(t('Ist ein Lager (Bestand wird gezählt)')) ?></label>
        <?php if ($l): ?><label class="check"><input type="checkbox" name="active" value="1" <?= (int)$l['active'] ? 'checked' : '' ?>> <?= e(t('Aktiv (auswählbar)')) ?></label><?php endif; ?>
        <button class="btn primary"><?= e(t('Standort speichern')) ?></button>
    </form>
<?php }
