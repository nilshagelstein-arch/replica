<?php
/**
 * App „Verträge & Laufzeiten“ – Stufe 1: Grundgerüst
 *
 * - Je Firma getrennt: Verträge mit Art, Partner, Laufzeit, Kündigungsfrist, Kosten, betreuendem Dienstleister,
 *   Status, Verlauf und Dokumenten.
 * - Rechte über Gruppen (Abteilungen) wie im Inventar: „ansehen“ oder „bearbeiten“, dazu je Gruppe die Zusatzrechte
 *   „Kosten sehen“ und „Freigabe Kündigung“ (die Freigabe selbst folgt mit Stufe 4).
 * - Wer keine Kosten sehen darf, sieht in Liste, Detail und Verlauf keine Beträge und kann sie auch nicht ändern.
 * - Stufe 2: Preishistorie (ct_prices, neuer Preis mit „gültig ab“); Verbindung zum Inventar in ct_inventory.php.
 * - Der Dienstleister (Superadmin, Verwaltungsadmins) sieht die Verträge nur mit Freigabe der Firma
 *   (companies.ct_provider_access) und dann nach seinem Bereichsrecht „contracts“; Kosten nur mit companies.ct_provider_costs.
 */

declare(strict_types=1);

const CT_APP = 'vertraege';
const CT_PERM_NONE = 0;
const CT_PERM_VIEW = 1;
const CT_PERM_EDIT = 2;
const CT_STATUSES = ['aktiv' => 'Aktiv', 'kuendigung_freigabe' => 'Kündigung in Freigabe', 'kuendigung_freigegeben' => 'Kündigung freigegeben',
    'gekuendigt' => 'Gekündigt', 'verlaengert' => 'Verlängert', 'beendet' => 'Beendet'];
/** Status, die als laufender Vertrag zählen */
const CT_RUNNING = ['aktiv', 'kuendigung_freigabe', 'kuendigung_freigegeben', 'verlaengert'];
/** Status, die nur der Kündigungsablauf setzt (nicht im Formular wählbar) */
const CT_FLOW_STATUSES = ['kuendigung_freigabe', 'kuendigung_freigegeben'];
/** SQL-Liste der laufenden und gekündigten (noch nicht beendeten) Verträge */
const CT_OPEN_SQL = "('aktiv', 'kuendigung_freigabe', 'kuendigung_freigegeben', 'verlaengert', 'gekuendigt')";
const CT_TYPES_DEFAULT = ['Internet und Telefonie', 'Mobilfunk', 'Software-Abo', 'Wartung und Support', 'Leasing und Miete', 'Hosting und Domains',
    'IT-Dienstleistung', 'Versicherung', 'Strom und Gas', 'Miete und Pacht', 'Fahrzeuge und Leasing', 'Sonstiges'];
const CT_INTERVALS = ['monat' => 'pro Monat', 'quartal' => 'pro Quartal', 'jahr' => 'pro Jahr', 'einmalig' => 'einmalig'];
const CT_PAYMENTS = ['' => '– nicht angegeben –', 'lastschrift' => 'Lastschrift', 'rechnung' => 'Rechnung / Überweisung', 'kreditkarte' => 'Kreditkarte',
    'paypal' => 'PayPal', 'sonstiges' => 'Sonstiges'];
const CT_NOTICE_UNITS = ['days' => 'Tage', 'weeks' => 'Wochen', 'months' => 'Monate'];
const CT_DOC_KINDS = ['vertrag' => 'Vertrag', 'agb' => 'AGB', 'rechnung' => 'Rechnung', 'kuendigung' => 'Kündigungsbestätigung', 'sonstiges' => 'Sonstiges'];
/** Felder, deren Änderung im Verlauf steht (Feld => Bezeichnung); Kostenfelder stehen getrennt (Verlauf nur mit „Kosten sehen“) */
const CT_LOG_FIELDS = ['title' => 'Bezeichnung', 'partner' => 'Vertragspartner', 'contract_no' => 'Vertragsnummer', 'customer_no' => 'Kundennummer',
    'partner_contact' => 'Ansprechpartner beim Anbieter', 'partner_street' => 'Straße des Anbieters', 'partner_zip' => 'PLZ des Anbieters',
    'partner_city' => 'Ort des Anbieters', 'partner_email' => 'E-Mail des Anbieters', 'start_date' => 'Beginn', 'end_date' => 'Ende', 'cancelled_to' => 'Gekündigt zum'];
const CT_DATE_FIELDS = ['start_date', 'end_date', 'cancelled_to'];

// ---------------------------------------------------------------------------
// Zugriff und Rechte
// ---------------------------------------------------------------------------

/** Firma aus der Anfrage, mit App-Freigabe und Zugriffsprüfung */
function ct_company_id(): int
{
    $u = require_app(CT_APP);
    $cid = context_company_id();
    if (!$cid) abort(404, t('Es ist noch keine Firma angelegt.'));
    require_company_access($cid);
    if (!row('SELECT id FROM companies WHERE id = ?', [$cid])) abort(404);
    if (!is_staff_role($u['role']) && !company_has_app($cid, CT_APP)) abort(403);
    $GLOBALS['ct_cid'] = $cid;
    ct_seed($cid);
    return $cid;
}

function cturl(string $route, array $params = []): string
{
    if (is_admin() && !empty($GLOBALS['ct_cid'])) $params = ['company' => (int)$GLOBALS['ct_cid']] + $params;
    return url($route, $params);
}

function ct_redirect(string $route, array $params = []): never
{
    header('Location: ' . cturl($route, $params));
    exit;
}

/** Standard-Vertragsarten beim ersten Aufruf einer Firma */
function ct_seed(int $cid): void
{
    if (row('SELECT id FROM ct_types WHERE company_id = ? LIMIT 1', [$cid])) return;
    foreach (CT_TYPES_DEFAULT as $i => $name) insert('ct_types', ['company_id' => $cid, 'name' => $name, 'sort' => ($i + 1) * 10, 'active' => 1]);
}

function ct_company(int $cid): array
{
    static $cache = [];
    return $cache[$cid] ??= (row('SELECT id, name, ct_provider_access, ct_provider_costs, ct_provider_all, ct_inv_licenses, ct_lead_days, ct_pdf_interval, ct_pdf_recipients, ct_pdf_costs FROM companies WHERE id = ?', [$cid]) ?? []);
}

function ct_staff_blocked(int $cid): bool
{
    return is_staff_role(current_user()['role']) && (int)(ct_company($cid)['ct_provider_access'] ?? 0) !== 1;
}

/** Rechte eines Benutzers (Standard: angemeldeter Benutzer): ['perm' => 0|1|2, 'costs' => bool, 'approve' => bool] */
function ct_rights(int $cid, ?array $u = null): array
{
    static $cache = [];
    $u ??= current_user();
    $none = ['perm' => CT_PERM_NONE, 'costs' => false, 'approve' => false];
    if (!$u) return $none;
    $key = $cid . ':' . (int)$u['id'];
    if (isset($cache[$key])) return $cache[$key];
    if (is_staff_role($u['role'])) {
        $co = ct_company($cid);
        if ((int)($co['ct_provider_access'] ?? 0) !== 1) return $cache[$key] = $none;
        $p = admin_perm('contracts', $u);
        // eingeschränkte Verwaltungsadmins nur für ihre Firmen
        $ids = admin_company_ids($u);
        if ($ids !== null && !in_array($cid, $ids, true)) $p = PERM_NONE;
        $perm = $p >= PERM_EDIT ? CT_PERM_EDIT : ($p >= PERM_VIEW ? CT_PERM_VIEW : CT_PERM_NONE);
        return $cache[$key] = ['perm' => $perm, 'costs' => $perm > CT_PERM_NONE && (int)($co['ct_provider_costs'] ?? 0) === 1, 'approve' => false];
    }
    if ((int)($u['company_id'] ?? 0) !== $cid) return $cache[$key] = $none;
    if ($u['role'] === 'firma_admin') return $cache[$key] = ['perm' => CT_PERM_EDIT, 'costs' => true, 'approve' => true];
    $g = row('SELECT MAX(g.perm) AS p, MAX(g.see_costs) AS c, MAX(g.approve_cancel) AS a FROM ct_group_perms g
        JOIN user_departments ud ON ud.department_id = g.department_id WHERE g.company_id = ? AND ud.user_id = ?', [$cid, $u['id']]);
    $perm = min(CT_PERM_EDIT, (int)($g['p'] ?? 0));
    return $cache[$key] = ['perm' => $perm, 'costs' => $perm > CT_PERM_NONE && (int)($g['c'] ?? 0) === 1, 'approve' => $perm > CT_PERM_NONE && (int)($g['a'] ?? 0) === 1];
}

function ct_perm(int $cid): int
{
    return ct_rights($cid)['perm'];
}

function ct_can_costs(int $cid): bool
{
    return ct_rights($cid)['costs'];
}

/** Einstellungen (Arten, Rechte, Schalter) pflegen: Firmen-Admins und der Dienstleister mit Bearbeiten-Recht */
function ct_can_manage(int $cid): bool
{
    $u = current_user();
    // Dienstleister nur mit Einsicht-Freigabe der Firma und Bearbeiten-Recht für genau diese Firma
    if (is_staff_role($u['role'])) return ct_rights($cid)['perm'] === CT_PERM_EDIT;
    return $u['role'] === 'firma_admin' && (int)$u['company_id'] === $cid;
}

// ---------------------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------------------

function ct_files_dir(int $cid): string
{
    $dir = APP_DIR . '/data/contracts/' . $cid;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

/** Verlaufseintrag; kind „cost“ sehen nur Benutzer mit „Kosten sehen“ */
function ct_log(int $cid, int $contractId, string $body, string $kind = 'event'): void
{
    insert('ct_log', ['company_id' => $cid, 'contract_id' => $contractId, 'user_id' => current_user()['id'] ?? null, 'kind' => $kind, 'body' => $body, 'created_at' => now()]);
}

function ct_date(string $s): string
{
    $s = trim($s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false ? $s : '';
}

function ct_parse_price(string $s): ?int
{
    $s = trim(str_replace(['€', ' ', "\u{00a0}"], '', $s));
    if ($s === '') return null;
    if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
    return is_numeric($s) && (float)$s >= 0 && (float)$s < 100000000 ? (int)round((float)$s * 100) : null;
}

function ct_fmt_price(?int $cents): string
{
    return $cents === null ? '' : number_format($cents / 100, 2, ',', '.') . ' €';
}

/** Monat zu einem Datum addieren, ohne über das Monatsende zu springen (31.01. + 1 Monat = 28./29.02.) */
function ct_add_months(string $date, int $months): string
{
    $d = new DateTimeImmutable($date);
    $day = (int)$d->format('d');
    $first = $d->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
    $last = (int)$first->format('t');
    return $first->setDate((int)$first->format('Y'), (int)$first->format('m'), min($day, $last))->format('Y-m-d');
}

/** Ende aus Beginn und Mindestlaufzeit: Beginn + n Monate − 1 Tag (01.01.2025 + 24 Monate = 31.12.2026) */
function ct_end_from_start(string $start, int $months): string
{
    if ($start === '' || $months <= 0) return '';
    $target = ct_add_months($start, $months);
    // fehlt der entsprechende Tag im Zielmonat (Beginn 31.01., 1 Monat), endet der Zeitraum am Monatsletzten (§ 188 BGB)
    if ((int)substr($start, 8, 2) > (int)substr($target, 8, 2)) return $target;
    return (new DateTimeImmutable($target))->modify('-1 day')->format('Y-m-d');
}

/**
 * Nächstes Vertragsende: gekündigt → „gekündigt zum“; mit automatischer Verlängerung wird ein verstrichenes Ende
 * um die Verlängerung fortgeschrieben, bis es in der Zukunft liegt. '' = unbefristet / unbekannt.
 */
function ct_next_end(array $c, ?string $today = null): string
{
    $today ??= date('Y-m-d');
    if ($c['status'] === 'gekuendigt' && ($c['cancelled_to'] ?? '') !== '') return $c['cancelled_to'];
    $end = (string)$c['end_date'];
    if ($end === '') return '';
    if ((int)$c['auto_renew'] && (int)$c['renew_months'] > 0 && in_array($c['status'], CT_RUNNING, true)) {
        // neuer Zeitraum beginnt am Tag nach dem alten Ende und läuft renew_months Monate
        for ($i = 0; $end < $today && $i < 1200; $i++) {
            $next = (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
            $end = ct_end_from_start($next, (int)$c['renew_months']);
        }
    }
    return $end;
}

/**
 * Fristen eines Vertrags:
 *  - end:      Ende des laufenden Zeitraums ('' = unbefristet)
 *  - possible: nächstmögliches Ende, zu dem noch fristgerecht gekündigt werden kann (bei verpasster Frist
 *              und automatischer Verlängerung ein Zeitraum später)
 *  - by:       letzter Tag für die Kündigung zu „possible“ ('' = keine Kündigung nötig/möglich)
 *  - missed:   die Frist für das laufende Ende ist schon verstrichen
 */
function ct_deadlines(array $c, ?string $today = null): array
{
    $today ??= date('Y-m-d');
    $end = ct_next_end($c, $today);
    $out = ['end' => $end, 'possible' => $end, 'by' => '', 'missed' => false];
    // nur laufende Verträge mit Ende und automatischer Verlängerung müssen gekündigt werden
    if ($end === '' || !in_array($c['status'], CT_RUNNING, true) || !(int)$c['auto_renew']) return $out;
    $by = ct_cancel_by_end($c, $end);
    for ($i = 0; $by < $today && (int)$c['renew_months'] > 0 && $i < 1200; $i++) {
        $out['missed'] = true;
        $end = ct_end_from_start((new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d'), (int)$c['renew_months']);
        $by = ct_cancel_by_end($c, $end);
    }
    return ['possible' => $end, 'by' => $by] + $out;
}

/** Letzter Tag, an dem zu einem bestimmten Ende noch gekündigt werden kann */
function ct_cancel_by_end(array $c, string $end): string
{
    if ((int)$c['notice_value'] <= 0) return $end;
    $n = (int)$c['notice_value'];
    return match ($c['notice_unit']) {
        'days' => (new DateTimeImmutable($end))->modify("-$n days")->format('Y-m-d'),
        'weeks' => (new DateTimeImmutable($end))->modify('-' . ($n * 7) . ' days')->format('Y-m-d'),
        default => ct_add_months($end, -$n),
    };
}

/** Preishistorie je Vertrag: contract_id => Einträge aufsteigend nach „gültig ab“ */
function ct_prices_for(int $cid, ?array $ids = null): array
{
    $sql = 'SELECT * FROM ct_prices WHERE company_id = ?';
    if ($ids !== null) {
        if (!$ids) return [];
        $sql .= ' AND contract_id IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
    $out = [];
    foreach (rows($sql . ' ORDER BY valid_from, id', [$cid]) as $r) $out[(int)$r['contract_id']][] = $r;
    return $out;
}

/** Gültiger Preis an einem Tag: letzter Eintrag mit „gültig ab“ <= Tag, sonst der Grundpreis aus dem Vertrag */
function ct_price_at(array $c, array $prices, ?string $date = null): array
{
    $date ??= date('Y-m-d');
    $cur = ['cents' => $c['cost_cents'] === null ? null : (int)$c['cost_cents'], 'interval' => (string)$c['cost_interval'], 'from' => '', 'id' => 0];
    foreach ($prices as $p) {
        if ($p['valid_from'] <= $date) $cur = ['cents' => $p['cost_cents'] === null ? null : (int)$p['cost_cents'], 'interval' => (string)$p['cost_interval'], 'from' => (string)$p['valid_from'], 'id' => (int)$p['id']];
    }
    return $cur;
}

/** Nächste künftige Preisänderung oder null */
function ct_next_price(array $prices, ?string $date = null): ?array
{
    $date ??= date('Y-m-d');
    foreach ($prices as $p) if ($p['valid_from'] > $date) return $p;
    return null;
}

/** Kosten je Monat in Cent (einmalige Kosten = 0); nur laufende Verträge; $price = gültiger Preis aus ct_price_at() */
function ct_monthly(array $c, ?array $price = null): int
{
    $cents = $price ? $price['cents'] : ($c['cost_cents'] === null ? null : (int)$c['cost_cents']);
    $interval = $price ? $price['interval'] : (string)$c['cost_interval'];
    if ($cents === null || !in_array($c['status'], array_merge(CT_RUNNING, ['gekuendigt']), true)) return 0;
    if ($c['status'] === 'gekuendigt' && ($c['cancelled_to'] ?? '') !== '' && $c['cancelled_to'] < date('Y-m-d')) return 0;
    return match ($interval) {
        'monat' => $cents,
        'quartal' => (int)round($cents / 3),
        'jahr' => (int)round($cents / 12),
        default => 0,
    };
}

function ct_notice_text(array $c): string
{
    if ((int)$c['notice_value'] <= 0) return '';
    return (int)$c['notice_value'] . ' ' . t(CT_NOTICE_UNITS[$c['notice_unit']] ?? 'Monate');
}

/** Betreuender Dienstleister als Text */
function ct_managed_label(array $c): string
{
    return match ($c['managed_by']) {
        'it' => t('IT-Dienstleister (Portal-Betreiber)'),
        'provider' => (string)($c['provider_name'] ?? '') !== '' ? (string)$c['provider_name'] : t('anderer Dienstleister'),
        default => t('Firma selbst'),
    };
}

function ct_status_badge(array $c): string
{
    $label = t(CT_STATUSES[$c['status']] ?? $c['status']);
    if ($c['status'] === 'gekuendigt' && ($c['cancelled_to'] ?? '') !== '') $label = t('Gekündigt zum {x}', ['x' => fmt_date($c['cancelled_to'])]);
    return '<span class="badge ct-' . e($c['status']) . '">' . e($label) . '</span>';
}

function ct_load(int $id, int $cid): ?array
{
    return row('SELECT c.*, t.name AS type_name, p.name AS provider_name, u.name AS responsible_name, u.email AS responsible_email
        FROM contracts c LEFT JOIN ct_types t ON t.id = c.type_id LEFT JOIN ticket_providers p ON p.id = c.provider_id LEFT JOIN users u ON u.id = c.responsible_user_id
        WHERE c.id = ? AND c.company_id = ?', [$id, $cid]);
}

/** Benutzer der Firma als verantwortliche Person */
function ct_users(int $cid, ?int $keep = null): array
{
    return rows("SELECT id, name, email FROM users WHERE company_id = ? AND role IN ('firma_admin', 'firma_user') AND (active = 1 OR id = ?) ORDER BY name", [$cid, (int)$keep]);
}

function ct_tabs(string $active): void
{
    if (!empty($GLOBALS['in_settings'])) return; // im Rahmen der Einstellungen ohne App-Reiter
    $cid = (int)($GLOBALS['ct_cid'] ?? 0);
    $tabs = ['contracts' => t('Verträge'), 'contract_deadlines' => t('Fristenkalender')];
    if ($cid && ct_can_costs($cid)) $tabs['contract_costs'] = t('Kosten');
    if ($cid && ct_can_manage($cid)) $tabs['contract_settings'] = t('Einstellungen');
    if (is_staff_role(current_user()['role']) && admin_can('contracts')) $tabs['contract_sales'] = t('Vertriebsübersicht'); ?>
    <nav class="type-tabs">
        <?php foreach ($tabs as $route => $label): ?><a class="<?= $route === $active ? 'active' : '' ?>" href="<?= e(cturl($route)) ?>"><?= e($label) ?></a><?php endforeach; ?>
    </nav>
<?php }

// ---------------------------------------------------------------------------
// Übersicht
// ---------------------------------------------------------------------------

function page_contracts(): void
{
    $cid = ct_company_id();
    $perm = ct_perm($cid);
    if ($perm === CT_PERM_NONE) {
        render_page(t('Verträge & Laufzeiten'), function () use ($cid) {
            echo company_switcher($cid, 'contracts'); ?>
            <div class="alert info"><?= e(ct_staff_blocked($cid)
                ? t('Die Verträge dieser Firma sind nur intern sichtbar. Die Firma kann die Einsicht für den IT-Dienstleister unter Einstellungen einschalten.')
                : t('Sie haben keinen Zugriff auf die Verträge. Die Rechte vergeben die Administratoren Ihrer Firma.')) ?></div>
            <?php if (ct_can_manage($cid)): ?><p><a class="btn" href="<?= e(cturl('contract_settings')) ?>"><?= e(t('Einstellungen')) ?></a></p><?php endif;
        });
        return;
    }
    $costs = ct_can_costs($cid);
    $types = rows('SELECT * FROM ct_types WHERE company_id = ? ORDER BY sort, name', [$cid]);
    $status = (string)($_GET['status'] ?? 'laufend');
    if (!in_array($status, ['laufend', 'alle'], true) && !isset(CT_STATUSES[$status])) $status = 'laufend';
    $type = query_int('type');
    $managed = (string)($_GET['managed'] ?? '');
    if (!in_array($managed, ['', 'it', 'provider', 'company'], true)) $managed = '';
    $search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

    $where = ['c.company_id = ?'];
    $p = [$cid];
    if ($status === 'laufend') $where[] = 'c.status IN ' . CT_OPEN_SQL;
    elseif ($status !== 'alle') { $where[] = 'c.status = ?'; $p[] = $status; }
    if ($type) { $where[] = 'c.type_id = ?'; $p[] = $type; }
    if ($managed !== '') { $where[] = 'c.managed_by = ?'; $p[] = $managed; }
    if ($search !== '') {
        $where[] = '(c.title LIKE ? OR c.partner LIKE ? OR c.contract_no LIKE ? OR c.customer_no LIKE ? OR c.partner_contact LIKE ?)';
        for ($i = 0; $i < 5; $i++) $p[] = '%' . $search . '%';
    }
    $list = rows('SELECT c.*, t.name AS type_name, p.name AS provider_name FROM contracts c LEFT JOIN ct_types t ON t.id = c.type_id
        LEFT JOIN ticket_providers p ON p.id = c.provider_id WHERE ' . implode(' AND ', $where) . ' LIMIT 2000', $p);
    $today = date('Y-m-d');
    $prices = $costs ? ct_prices_for($cid) : [];
    foreach ($list as &$c) {
        $c['_price'] = ct_price_at($c, $prices[(int)$c['id']] ?? [], $today);
        $c['_next'] = ct_next_price($prices[(int)$c['id']] ?? [], $today);
    }
    unset($c);
    // Abo- und Wartungslizenzen aus dem Inventar (nur ohne Filter nach Art/Betreuung)
    $invAll = ct_inv_license_rows($cid);
    if (!$type && $managed === '') {
        foreach ($search === '' ? $invAll : ct_inv_license_rows($cid, $search) as $l) {
            if ($status === 'alle' || ($status === 'laufend' && $l['status'] !== 'beendet') || $l['status'] === $status) {
                $list[] = $l + ['_price' => ct_price_at($l, [], $today), '_next' => null];
            }
        }
    }
    foreach ($list as &$c) { $dl = ct_deadlines($c, $today); $c['_end'] = $dl['possible']; $c['_by'] = $dl['by']; }
    unset($c);
    // nach nächster Frist sortieren, ohne Frist ans Ende
    usort($list, fn($a, $b) => [($a['_by'] ?: $a['_end']) === '' ? 1 : 0, $a['_by'] ?: $a['_end'], mb_strtolower($a['title'])]
        <=> [($b['_by'] ?: $b['_end']) === '' ? 1 : 0, $b['_by'] ?: $b['_end'], mb_strtolower($b['title'])]);
    $counts = array_fill_keys(array_keys(CT_STATUSES), 0);
    foreach (rows('SELECT status, COUNT(*) AS n FROM contracts WHERE company_id = ? GROUP BY status', [$cid]) as $r) $counts[$r['status']] = (int)$r['n'];
    $monthly = 0;
    if ($costs) {
        foreach (rows('SELECT * FROM contracts WHERE company_id = ?', [$cid]) as $c) $monthly += ct_monthly($c, ct_price_at($c, $prices[(int)$c['id']] ?? [], $today));
        foreach ($invAll as $l) $monthly += ct_monthly($l);
    }
    $soon = date('Y-m-d', strtotime('+90 days'));

    render_page(t('Verträge & Laufzeiten'), function () use ($cid, $perm, $costs, $types, $status, $type, $managed, $search, $list, $counts, $monthly, $soon, $today) {
        echo company_switcher($cid, 'contracts');
        ct_tabs('contracts'); ?>
        <div class="toolbar">
            <?php if ($perm >= CT_PERM_EDIT): ?><a class="btn primary" href="<?= e(cturl('contract', ['id' => 'new'])) ?>"><?= e(t('+ Vertrag erfassen')) ?></a><?php endif; ?>
            <a class="btn" href="<?= e(cturl('contract_deadlines')) ?>"><?= e(t('Fristenkalender')) ?></a>
            <?php if ($costs): ?><a class="btn" href="<?= e(cturl('contract_costs')) ?>"><?= e(t('Kostenübersicht')) ?></a><?php endif; ?>
            <a class="btn" href="<?= e(cturl('contract_export')) ?>"><?= e(t('CSV-Export')) ?></a>
            <?php if ($perm >= CT_PERM_EDIT): ?><a class="btn" href="<?= e(cturl('contract_extract')) ?>"><?= e(t('Aus PDF erfassen')) ?></a><a class="btn" href="<?= e(cturl('contract_import')) ?>"><?= e(t('CSV-Import')) ?></a><?php endif; ?>
            <?php if (ct_can_manage($cid)): ?><a class="btn" href="<?= e(cturl('contract_settings')) ?>"><?= e(t('Einstellungen')) ?></a><?php endif; ?>
        </div>
        <?php ct_due_box($cid, $costs); ?>
        <div class="tiles">
            <?php $tiles = ['laufend' => [t('Laufend'), $counts['aktiv'] + $counts['kuendigung_freigabe'] + $counts['kuendigung_freigegeben'] + $counts['verlaengert'] + $counts['gekuendigt']]];
            foreach (CT_STATUSES as $k => $l) $tiles[$k] = [t($l), $counts[$k]];
            $tiles['alle'] = [t('Alle'), array_sum($counts)];
            foreach ($tiles as $k => [$label, $n]): ?>
                <a class="tile <?= $status === $k ? 'active' : '' ?>" href="<?= e(cturl('contracts', ['status' => $k])) ?>"><strong><?= (int)$n ?></strong><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
            <?php if ($costs): ?><div class="tile"><strong><?= e(ct_fmt_price($monthly)) ?></strong><span><?= e(t('laufende Kosten pro Monat')) ?></span></div><?php endif; ?>
        </div>
        <form method="get" class="toolbar">
            <input type="hidden" name="r" value="contracts">
            <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('Suche: Bezeichnung, Partner, Vertrags- oder Kundennummer …')) ?>">
            <select name="type"><option value=""><?= e(t('alle Vertragsarten')) ?></option>
                <?php foreach ($types as $tp): ?><option value="<?= (int)$tp['id'] ?>" <?= $type === (int)$tp['id'] ? 'selected' : '' ?>><?= e(t($tp['name'])) ?></option><?php endforeach; ?></select>
            <select name="managed"><option value=""><?= e(t('jede Betreuung')) ?></option>
                <?php foreach (['it' => t('IT-Dienstleister (Portal-Betreiber)'), 'provider' => t('anderer Dienstleister'), 'company' => t('Firma selbst')] as $k => $l): ?>
                <option value="<?= e($k) ?>" <?= $managed === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <select name="status">
                <option value="laufend" <?= $status === 'laufend' ? 'selected' : '' ?>><?= e(t('Laufend')) ?></option>
                <?php foreach (CT_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?>
                <option value="alle" <?= $status === 'alle' ? 'selected' : '' ?>><?= e(t('Alle')) ?></option>
            </select>
            <button class="btn"><?= e(t('Filtern')) ?></button>
        </form>
        <div class="card">
            <div class="table-scroll"><table class="table">
                <thead><tr><th><?= e(t('Vertrag')) ?></th><th><?= e(t('Art')) ?></th><th><?= e(t('Vertragspartner')) ?></th><th><?= e(t('Kündigen bis')) ?></th>
                    <th><?= e(t('Ende')) ?></th><?php if ($costs): ?><th class="num"><?= e(t('Kosten')) ?></th><?php endif; ?><th><?= e(t('Betreuung')) ?></th><th><?= e(t('Status')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($list as $c): ?>
                    <tr><td><?php if (!empty($c['_inv'])): ?><a href="<?= e(ct_app_url($cid, 'inv_license', ['id' => $c['id']])) ?>"><strong><?= e($c['title']) ?></strong></a> <span class="tag"><?= e(t('aus dem Inventar')) ?></span>
                            <?php else: ?><a href="<?= e(cturl('contract', ['id' => $c['id']])) ?>"><strong><?= e($c['title']) ?></strong></a><?php endif; ?>
                            <?php if ($c['contract_no'] !== ''): ?><br><span class="muted small"><?= e($c['contract_no']) ?></span><?php endif; ?></td>
                        <td><?= e($c['type_name'] !== null ? t($c['type_name']) : '–') ?></td>
                        <td><?= e($c['partner'] ?: '–') ?></td>
                        <td><?php if ($c['_by'] !== ''): ?><span class="<?= $c['_by'] <= $soon ? 'tag warn' : '' ?>"><?= fmt_date($c['_by']) ?></span><?php elseif ($c['_end'] !== '' && in_array($c['status'], CT_RUNNING, true)): ?><span class="muted small"><?= e(t('endet ohne Kündigung')) ?></span><?php else: ?>–<?php endif; ?></td>
                        <td><?php if ($c['_end'] !== ''): ?><?= fmt_date($c['_end']) ?><?php if ((int)$c['auto_renew'] && in_array($c['status'], CT_RUNNING, true)): ?> <span class="muted small" title="<?= e(t('verlängert sich automatisch')) ?>">↻</span><?php endif; ?><?php else: ?><span class="muted"><?= e(t('unbefristet')) ?></span><?php endif; ?></td>
                        <?php if ($costs): $pr = $c['_price']; ?><td class="num nowrap"><?= $pr['cents'] !== null ? e(ct_fmt_price($pr['cents'])) . '<br><span class="muted small">' . e(t(CT_INTERVALS[$pr['interval']] ?? INV_LIC_INTERVALS[$pr['interval']] ?? '')) . '</span>' : '–' ?>
                            <?php if ($c['_next']): ?><br><span class="tag warn"><?= e(t('ab {d}: {x}', ['d' => fmt_date($c['_next']['valid_from']), 'x' => ct_fmt_price($c['_next']['cost_cents'] === null ? null : (int)$c['_next']['cost_cents'])])) ?></span><?php endif; ?></td><?php endif; ?>
                        <td><?= !empty($c['_inv']) ? '–' : e(ct_managed_label($c)) ?></td>
                        <td><?= ct_status_badge($c) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$list): ?><tr><td colspan="<?= $costs ? 8 : 7 ?>" class="muted"><?= e(t('Keine Verträge gefunden.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Vertrag ansehen / erfassen / bearbeiten
// ---------------------------------------------------------------------------

function page_contract(): void
{
    $cid = ct_company_id();
    $perm = ct_perm($cid);
    if ($perm === CT_PERM_NONE) abort(403);
    $costs = ct_can_costs($cid);
    $isNew = ($_GET['id'] ?? '') === 'new';
    if ($isNew && $perm < CT_PERM_EDIT) abort(403);
    $c = $isNew ? null : ct_load(query_int('id'), $cid);
    if (!$isNew && !$c) abort(404);
    $types = rows('SELECT * FROM ct_types WHERE company_id = ? AND (active = 1 OR id = ?) ORDER BY sort, name', [$cid, (int)($c['type_id'] ?? 0)]);
    $providers = rows('SELECT id, name FROM ticket_providers WHERE company_id = ? AND (active = 1 OR id = ?) ORDER BY name', [$cid, (int)($c['provider_id'] ?? 0)]);
    $users = $perm >= CT_PERM_EDIT ? ct_users($cid, $c ? (int)$c['responsible_user_id'] : null) : [];
    $log = $c ? rows('SELECT l.*, u.name AS user_name FROM ct_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.contract_id = ? AND l.company_id = ?'
        . ($costs ? '' : " AND l.kind <> 'cost'") . ' ORDER BY l.id DESC LIMIT 300', [$c['id'], $cid]) : [];
    $files = $c ? rows('SELECT * FROM ct_files WHERE contract_id = ? AND company_id = ? ORDER BY id', [$c['id'], $cid]) : [];
    $prices = $c && $costs ? (ct_prices_for($cid, [(int)$c['id']])[(int)$c['id']] ?? []) : [];
    $title = $isNew ? t('Vertrag erfassen') : $c['title'];
    // Stufe 7: Vorbelegung aus einer ausgelesenen PDF
    $prefill = $isNew && ($_GET['from'] ?? '') !== '' ? ct_extract_prefill($cid, (string)$_GET['from']) : null;
    if ($prefill) $c = $prefill['c'];

    render_page($title, function () use ($cid, $perm, $costs, $c, $isNew, $types, $providers, $users, $log, $files, $prices, $prefill) {
        $edit = $perm >= CT_PERM_EDIT;
        $v = fn(string $k) => (string)($c[$k] ?? '');
        $status = $c['status'] ?? 'aktiv'; ?>
        <p><a href="<?= e(cturl('contracts')) ?>">← <?= e(t('Verträge & Laufzeiten')) ?></a></p>
        <div class="grid2 inv-grid">
        <div>
        <?php if ($edit): ?>
        <form method="post" action="<?= e(cturl('contract_action', $isNew ? [] : ['id' => $c['id']])) ?>" class="form card">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <?php if ($prefill): ?><input type="hidden" name="from_token" value="<?= e($prefill['token']) ?>">
            <div class="alert info"><?= e(t('Vorausgefüllt aus „{x}“ ({n} Angaben gefunden). Bitte alles prüfen – die PDF wird beim Anlegen als Dokument „Vertrag“ angehängt.', ['x' => $prefill['name'], 'n' => $prefill['count']])) ?></div><?php endif; ?>
            <h2><?= e(t('Stammdaten')) ?></h2>
            <div class="grid2">
                <label><?= e(t('Bezeichnung *')) ?> <input name="title" maxlength="190" required value="<?= e($v('title')) ?>" placeholder="<?= e(t('z. B. Mobilfunk Geschäftsführung')) ?>"></label>
                <label><?= e(t('Vertragsart *')) ?> <select name="type_id" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                    <?php foreach ($types as $tp): ?><option value="<?= (int)$tp['id'] ?>" <?= (int)($c['type_id'] ?? 0) === (int)$tp['id'] ? 'selected' : '' ?>><?= e(t($tp['name'])) ?></option><?php endforeach; ?></select></label>
                <label><?= e(t('Vertragspartner *')) ?> <input name="partner" maxlength="190" required value="<?= e($v('partner')) ?>" placeholder="<?= e(t('z. B. Telekom, Stadtwerke')) ?>"></label>
                <label><?= e(t('Vertragsnummer')) ?> <input name="contract_no" maxlength="120" value="<?= e($v('contract_no')) ?>"></label>
                <label><?= e(t('Kundennummer')) ?> <input name="customer_no" maxlength="120" value="<?= e($v('customer_no')) ?>"></label>
                <label><?= e(t('Ansprechpartner beim Anbieter')) ?> <input name="partner_contact" maxlength="255" value="<?= e($v('partner_contact')) ?>" placeholder="<?= e(t('Name, Telefon, E-Mail')) ?>"></label>
                <label><?= e(t('Straße des Anbieters')) ?> <input name="partner_street" maxlength="190" value="<?= e($v('partner_street')) ?>"></label>
                <label><?= e(t('PLZ und Ort des Anbieters')) ?> <span class="inline-inputs"><input name="partner_zip" maxlength="20" class="narrow-input" value="<?= e($v('partner_zip')) ?>" aria-label="<?= e(t('PLZ des Anbieters')) ?>">
                    <input name="partner_city" maxlength="120" value="<?= e($v('partner_city')) ?>" aria-label="<?= e(t('Ort des Anbieters')) ?>"></span></label>
                <label><?= e(t('E-Mail für Kündigungen')) ?> <input type="email" name="partner_email" maxlength="190" value="<?= e($v('partner_email')) ?>" placeholder="<?= e(t('z. B. kuendigung@anbieter.de')) ?>"></label>
                <label><?= e(t('Verantwortlich in der Firma')) ?> <select name="responsible_user_id"><option value=""><?= e(t('– niemand –')) ?></option>
                    <?php foreach ($users as $us): ?><option value="<?= (int)$us['id'] ?>" <?= (int)($c['responsible_user_id'] ?? 0) === (int)$us['id'] ? 'selected' : '' ?>><?= e($us['name']) ?> (<?= e($us['email']) ?>)</option><?php endforeach; ?></select></label>
                <label><?= e(t('Status')) ?> <select name="status">
                    <?php foreach (CT_STATUSES as $k => $l): if (in_array($k, CT_FLOW_STATUSES, true) && $status !== $k) continue; ?>
                        <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></label>
                <label><?= e(t('Gekündigt zum')) ?> <input type="date" name="cancelled_to" value="<?= e($v('cancelled_to')) ?>"></label>
            </div>
            <p class="muted small"><?= e(t('„Gekündigt zum“ nur bei Status „Gekündigt“ ausfüllen; leer = zum Vertragsende.')) ?></p>
            <h3><?= e(t('Laufzeit')) ?></h3>
            <div class="grid2">
                <label><?= e(t('Beginn')) ?> <input type="date" name="start_date" value="<?= e($v('start_date')) ?>"></label>
                <label><?= e(t('Mindestlaufzeit (Monate)')) ?> <input type="number" name="min_term_months" min="0" max="600" value="<?= (int)($c['min_term_months'] ?? 0) ?: '' ?>"></label>
                <label><?= e(t('Ende')) ?> <input type="date" name="end_date" value="<?= e($v('end_date')) ?>"></label>
                <label><?= e(t('Kündigungsfrist')) ?> <span class="inline-inputs"><input type="number" name="notice_value" min="0" max="999" class="narrow-input" value="<?= (int)($c['notice_value'] ?? 3) ?>">
                    <select name="notice_unit"><?php foreach (CT_NOTICE_UNITS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($c['notice_unit'] ?? 'months') === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></span></label>
            </div>
            <p class="muted small"><?= e(t('Ohne Ende rechnet das Portal das Ende aus Beginn und Mindestlaufzeit aus. Bleibt beides leer, gilt der Vertrag als unbefristet.')) ?></p>
            <label class="check"><input type="checkbox" name="auto_renew" value="1" <?= !$c || (int)$c['auto_renew'] ? 'checked' : '' ?>> <?= e(t('Verlängert sich automatisch, wenn nicht gekündigt wird')) ?></label>
            <label><?= e(t('Verlängerung um (Monate)')) ?> <input type="number" name="renew_months" min="1" max="120" class="narrow-input" value="<?= (int)($c['renew_months'] ?? 12) ?: 12 ?>"></label>
            <?php if ($costs): ?>
            <h3><?= e(t('Kosten')) ?></h3>
            <div class="grid2">
                <label><?= e(t('Betrag (€)')) ?> <input name="cost" inputmode="decimal" maxlength="20" value="<?= e(isset($c['cost_cents']) && $c['cost_cents'] !== null ? number_format((int)$c['cost_cents'] / 100, 2, ',', '.') : '') ?>"></label>
                <label><?= e(t('Zeitraum')) ?> <select name="cost_interval"><?php foreach (CT_INTERVALS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($c['cost_interval'] ?? 'monat') === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></label>
                <label><?= e(t('Zahlungsart')) ?> <select name="payment_method"><?php foreach (CT_PAYMENTS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($c['payment_method'] ?? '') === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></label>
            </div>
            <p class="muted small"><?= e(t('Grundpreis ab Vertragsbeginn, wie auf der Rechnung (z. B. netto). Spätere Preisänderungen tragen Sie in der Preishistorie mit „gültig ab“ ein.')) ?></p>
            <?php endif; ?>
            <h3><?= e(t('Betreuung')) ?></h3>
            <?php $mb = ($c['managed_by'] ?? 'it') === 'provider' ? 'p' . (int)$c['provider_id'] : ($c['managed_by'] ?? 'it'); ?>
            <label><?= e(t('Betreuender Dienstleister')) ?> <select name="managed">
                <option value="it" <?= $mb === 'it' ? 'selected' : '' ?>><?= e(t('IT-Dienstleister (Portal-Betreiber)')) ?></option>
                <?php foreach ($providers as $pr): ?><option value="p<?= (int)$pr['id'] ?>" <?= $mb === 'p' . (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?></option><?php endforeach; ?>
                <option value="company" <?= $mb === 'company' ? 'selected' : '' ?>><?= e(t('Firma selbst (kein Dienstleister)')) ?></option>
            </select></label>
            <p class="muted small"><?= e(t('Weitere Dienstleister legen Sie in den Ticket-Einstellungen an. An den betreuenden Dienstleister geht später „Angebot anfragen“.')) ?></p>
            <label><?= e(t('Notiz')) ?> <textarea name="notes" rows="3"><?= e($v('notes')) ?></textarea></label>
            <button class="btn primary"><?= e($isNew ? t('Vertrag anlegen') : t('Speichern')) ?></button>
        </form>
        <?php else: ?>
        <div class="card">
            <h2><?= e(t('Stammdaten')) ?></h2>
            <dl class="facts">
                <?php foreach ([t('Bezeichnung') => $v('title'), t('Vertragsart') => $c['type_name'] !== null ? t($c['type_name']) : '', t('Vertragspartner') => $v('partner'),
                    t('Vertragsnummer') => $v('contract_no'), t('Kundennummer') => $v('customer_no'), t('Ansprechpartner beim Anbieter') => $v('partner_contact'),
                    t('Anschrift des Anbieters') => trim($v('partner_street') . ', ' . trim($v('partner_zip') . ' ' . $v('partner_city')), ', '), t('E-Mail für Kündigungen') => $v('partner_email'),
                    t('Verantwortlich in der Firma') => (string)($c['responsible_name'] ?? ''), t('Betreuender Dienstleister') => ct_managed_label($c)] as $k => $val):
                    if ($val === '') continue; ?>
                    <dt><?= e($k) ?></dt><dd><?= e($val) ?></dd>
                <?php endforeach; ?>
                <?php $pr = $costs ? ct_price_at($c, $prices) : null; if ($pr && $pr['cents'] !== null): ?><dt><?= e(t('Kosten')) ?></dt><dd><?= e(ct_fmt_price($pr['cents']) . ' ' . t(CT_INTERVALS[$pr['interval']] ?? '')) ?><?= $c['payment_method'] !== '' ? ' · ' . e(t(CT_PAYMENTS[$c['payment_method']] ?? $c['payment_method'])) : '' ?></dd><?php endif; ?>
            </dl>
            <?php if ($v('notes') !== ''): ?><p class="pre"><?= e($v('notes')) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
        <div>
        <?php if (!$isNew): $dl = ct_deadlines($c); ?>
            <div class="card">
                <h3><?= e(t('Laufzeit und Fristen')) ?></h3>
                <p><?= ct_status_badge($c) ?></p>
                <dl class="facts">
                    <?php if ($c['start_date'] !== ''): ?><dt><?= e(t('Beginn')) ?></dt><dd><?= fmt_date($c['start_date']) ?></dd><?php endif; ?>
                    <?php if ((int)$c['min_term_months'] > 0): ?><dt><?= e(t('Mindestlaufzeit')) ?></dt><dd><?= e(t('{n} Monate', ['n' => (int)$c['min_term_months']])) ?></dd><?php endif; ?>
                    <dt><?= e(t('Ende des laufenden Zeitraums')) ?></dt><dd><?= $dl['end'] !== '' ? fmt_date($dl['end']) : e(t('unbefristet')) ?></dd>
                    <?php if ((int)$c['auto_renew']): ?><dt><?= e(t('Verlängerung')) ?></dt><dd><?= e(t('automatisch um {n} Monate', ['n' => (int)$c['renew_months']])) ?></dd><?php endif; ?>
                    <?php if (ct_notice_text($c) !== ''): ?><dt><?= e(t('Kündigungsfrist')) ?></dt><dd><?= e(ct_notice_text($c)) ?></dd><?php endif; ?>
                    <?php if ($dl['by'] !== ''): ?>
                        <?php if ($dl['missed']): ?><dt><?= e(t('Nächstmögliches Ende')) ?></dt><dd><?= fmt_date($dl['possible']) ?> <span class="muted small"><?= e(t('(Frist zum laufenden Ende verstrichen)')) ?></span></dd><?php endif; ?>
                        <dt><?= e(t('Kündigen bis')) ?></dt><dd><strong><?= fmt_date($dl['by']) ?></strong></dd>
                    <?php elseif ($dl['end'] !== '' && in_array($c['status'], CT_RUNNING, true)): ?><dt><?= e(t('Kündigung')) ?></dt><dd><?= e(t('nicht nötig, der Vertrag endet ohne Verlängerung')) ?></dd><?php endif; ?>
                </dl>
                <?php if (in_array($c['status'], CT_RUNNING, true)): ?><p class="muted small"><?= e(t('An Fristen erinnert das Portal per E-Mail.')) ?></p><?php endif; ?>
            </div>
            <?php ct_cancel_card($cid, $c, $edit); ?>
            <?php ct_offer_card($cid, $c, $edit, $costs); ?>
            <?php if ($costs) ct_prices_card($c, $prices, $edit); ?>
            <?php ct_links_card($cid, $c, $edit); ?>
            <?php if (function_exists('wkl_card')) wkl_card($cid, 'contract', (int)$c['id']); ?>
            <div class="card">
                <h3><?= e(t('Dokumente')) ?></h3>
                <?php if ($files): ?><ul class="file-list">
                    <?php foreach ($files as $f): ?><li><span class="tag"><?= e(t(CT_DOC_KINDS[$f['doc_kind']] ?? 'Sonstiges')) ?></span> <a href="<?= e(cturl('contract_file', ['id' => $f['id']])) ?>"><?= e($f['filename']) ?></a>
                        <span class="muted small"><?= e(number_format($f['size'] / 1024, 0, ',', '.')) ?> KB · <?= fmt_dt($f['created_at']) ?></span>
                        <?php if ($edit && ($f['mime'] === 'application/pdf' || str_ends_with(strtolower((string)$f['filename']), '.pdf'))): ?><a class="btn small" href="<?= e(cturl('contract_extract', ['id' => $c['id'], 'file' => $f['id']])) ?>"><?= e(t('Daten auslesen')) ?></a><?php endif; ?>
                        <?php if ($edit): ?><form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="file_delete"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                            <button class="btn small danger" data-confirm="<?= e(t('Dokument „{x}“ löschen?', ['x' => $f['filename']])) ?>">✕</button></form><?php endif; ?></li>
                    <?php endforeach; ?></ul>
                <?php else: ?><p class="muted"><?= e(t('Keine Dokumente.')) ?></p><?php endif; ?>
                <?php if ($edit): ?>
                <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" enctype="multipart/form-data" class="form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
                    <label><?= e(t('Art des Dokuments')) ?> <select name="doc_kind"><?php foreach (CT_DOC_KINDS as $k => $l): ?><option value="<?= e($k) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select></label>
                    <label><?= e(t('Dateien (PDF, Bilder, Office; höchstens 10 MB je Datei)')) ?> <input type="file" name="files[]" multiple required></label>
                    <button class="btn small"><?= e(t('Hochladen')) ?></button>
                </form>
                <?php endif; ?>
            </div>
            <div class="card">
                <h3><?= e(t('Verlauf')) ?></h3>
                <?php if ($edit): ?>
                <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="note">
                    <label><?= e(t('Notiz zum Verlauf')) ?> <input name="body" maxlength="2000" required placeholder="<?= e(t('z. B. Angebot für Verlängerung angefragt')) ?>"></label>
                    <button class="btn small"><?= e(t('Eintragen')) ?></button></form>
                <?php endif; ?>
                <ul class="inv-log">
                <?php foreach ($log as $l): ?>
                    <li><span class="muted small"><?= fmt_dt($l['created_at']) ?> · <?= e($l['user_name'] ?? t('System')) ?></span><br>
                        <?= $l['kind'] === 'note' ? '📝 ' : '' ?><?= e(t_parts((string)$l['body'])) ?></li>
                <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($edit && (current_user()['role'] === 'firma_admin' || is_staff_role(current_user()['role']))): ?>
            <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <p class="muted small"><?= e(t('Versehentlich angelegt? Beendete Verträge bitte auf „Beendet“ setzen statt löschen, damit der Verlauf erhalten bleibt.')) ?></p>
                <button class="btn small danger" data-confirm="<?= e(t('Vertrag „{x}“ mit Verlauf und Dokumenten endgültig löschen?', ['x' => $c['title']])) ?>"><?= e(t('Vertrag löschen')) ?></button></form>
            <?php endif; ?>
        <?php else: ?>
            <div class="card"><p class="muted"><?= e(t('Nach dem Anlegen können Sie Dokumente hochladen und den Verlauf führen.')) ?></p></div>
        <?php endif; ?>
        </div>
        </div>
    <?php });
}

/** Aktionen am Vertrag (nur mit Recht „bearbeiten“) */
function page_contract_action(): void
{
    $cid = ct_company_id();
    if (!is_post()) abort(405);
    require_post_csrf();
    if (ct_perm($cid) < CT_PERM_EDIT) abort(403);
    $costs = ct_can_costs($cid);
    $action = (string)post('action');
    $idParam = (string)($_GET['id'] ?? '');
    $c = $idParam !== '' ? ct_load((int)$idParam, $cid) : null;
    if ($idParam !== '' && !$c) abort(404);
    $back = fn() => ct_redirect('contract', ['id' => $c['id']]);

    if ($action === 'save') {
        $f = fn(string $k, int $max) => mb_substr(trim((string)post($k)), 0, $max);
        $type = row('SELECT * FROM ct_types WHERE id = ? AND company_id = ?', [(int)post('type_id', 0), $cid]);
        $resp = (int)post('responsible_user_id', 0) ? row("SELECT id, name FROM users WHERE id = ? AND company_id = ? AND role IN ('firma_admin', 'firma_user')", [(int)post('responsible_user_id', 0), $cid]) : null;
        if ((int)post('responsible_user_id', 0) && !$resp) abort(400);
        $managed = (string)post('managed');
        $provider = null;
        if (preg_match('/^p(\d+)$/', $managed, $m)) {
            $provider = row('SELECT id, name FROM ticket_providers WHERE id = ? AND company_id = ?', [(int)$m[1], $cid]);
            if (!$provider) abort(400);
            $managed = 'provider';
        } elseif (!in_array($managed, ['it', 'company'], true)) $managed = 'it';
        $status = (string)post('status');
        // Status des Kündigungsablaufs setzt nur der Ablauf selbst; solange er läuft, bleibt der Status
        if (!isset(CT_STATUSES[$status]) || (in_array($status, CT_FLOW_STATUSES, true) && ($c['status'] ?? '') !== $status)) $status = $c['status'] ?? 'aktiv';
        if (in_array($c['status'] ?? '', CT_FLOW_STATUSES, true)) $status = $c['status'];
        $unit = (string)post('notice_unit');
        $data = ['title' => $f('title', 190), 'type_id' => $type['id'] ?? null, 'partner' => $f('partner', 190), 'contract_no' => $f('contract_no', 120),
            'customer_no' => $f('customer_no', 120), 'partner_contact' => $f('partner_contact', 255), 'responsible_user_id' => $resp['id'] ?? null,
            'partner_street' => $f('partner_street', 190), 'partner_zip' => $f('partner_zip', 20), 'partner_city' => $f('partner_city', 120),
            'partner_email' => filter_var($f('partner_email', 190), FILTER_VALIDATE_EMAIL) ? mb_strtolower($f('partner_email', 190)) : '',
            'status' => $status, 'start_date' => ct_date((string)post('start_date')), 'min_term_months' => max(0, min(600, (int)post('min_term_months', 0))),
            'end_date' => ct_date((string)post('end_date')), 'auto_renew' => post('auto_renew') === '1' ? 1 : 0, 'renew_months' => max(1, min(120, (int)post('renew_months', 12))),
            'notice_value' => max(0, min(999, (int)post('notice_value', 0))), 'notice_unit' => isset(CT_NOTICE_UNITS[$unit]) ? $unit : 'months',
            'cancelled_to' => $status === 'gekuendigt' ? ct_date((string)post('cancelled_to')) : '',
            'managed_by' => $managed, 'provider_id' => $provider['id'] ?? null, 'notes' => $f('notes', 5000), 'updated_at' => now()];
        if ($data['end_date'] === '' && $data['start_date'] !== '' && $data['min_term_months'] > 0) $data['end_date'] = ct_end_from_start($data['start_date'], $data['min_term_months']);
        // ohne Datum: zum nächstmöglichen Ende kündigen (bei verstrichener Verlängerung nicht zum alten Ende)
        if ($status === 'gekuendigt' && $data['cancelled_to'] === '') $data['cancelled_to'] = ct_deadlines(['status' => 'aktiv'] + $data)['possible'];
        if ($costs) {
            $interval = (string)post('cost_interval');
            $payment = (string)post('payment_method');
            $raw = trim((string)post('cost'));
            $data['cost_cents'] = ct_parse_price($raw);
            $data['cost_interval'] = isset(CT_INTERVALS[$interval]) ? $interval : 'monat';
            $data['payment_method'] = isset(CT_PAYMENTS[$payment]) ? $payment : '';
        }
        $err = null;
        if ($data['title'] === '') $err = t('Bitte eine Bezeichnung angeben.');
        elseif (!$type) $err = t('Bitte eine Vertragsart wählen.');
        elseif ($data['partner'] === '') $err = t('Bitte den Vertragspartner angeben.');
        elseif ($costs && $raw !== '' && $data['cost_cents'] === null) $err = t('Der Betrag „{x}“ ist keine gültige Zahl.', ['x' => $raw]);
        elseif ($data['start_date'] !== '' && $data['end_date'] !== '' && $data['end_date'] < $data['start_date']) $err = t('Das Ende liegt vor dem Beginn.');
        elseif ($f('partner_email', 190) !== '' && $data['partner_email'] === '') $err = t('Die E-Mail-Adresse des Anbieters ist ungültig.');
        if ($err) { flash('error', $err); $c ? $back() : ct_redirect('contract', ['id' => 'new'] + ((string)post('from_token') !== '' ? ['from' => (string)post('from_token')] : [])); }

        if (!$c) {
            $id = insert('contracts', $data + ['company_id' => $cid, 'created_by' => current_user()['id'], 'created_at' => now()]);
            ct_log($cid, $id, 'Vertrag angelegt: ' . $data['title']);
            if ((string)post('from_token') !== '') ct_extract_attach($cid, $id, (string)post('from_token'));
            if ($costs && $data['cost_cents'] !== null) ct_log($cid, $id, 'Kosten: ' . ct_fmt_price($data['cost_cents']) . ' ' . CT_INTERVALS[$data['cost_interval']], 'cost');
            audit('contract.create', $data['title'], $cid);
            flash('success', t('Der Vertrag „{x}“ wurde angelegt.', ['x' => $data['title']]));
            ct_redirect('contract', ['id' => $id]);
        }
        // Änderungen im Verlauf festhalten
        $changes = [];
        if ($c['status'] !== $status) $changes[] = 'Status: ' . CT_STATUSES[$status];
        if ((int)$c['type_id'] !== (int)$data['type_id']) $changes[] = 'Vertragsart: ' . ($type['name'] ?? '–');
        foreach (CT_LOG_FIELDS as $k => $label) {
            if ((string)$c[$k] !== (string)$data[$k]) $changes[] = $label . ': ' . ($data[$k] !== '' ? (in_array($k, CT_DATE_FIELDS, true) ? fmt_date($data[$k]) : $data[$k]) : '–');
        }
        if ((int)$c['min_term_months'] !== $data['min_term_months']) $changes[] = 'Mindestlaufzeit: ' . ($data['min_term_months'] ?: '–');
        if ((int)$c['auto_renew'] !== $data['auto_renew'] || ($data['auto_renew'] && (int)$c['renew_months'] !== $data['renew_months'])) {
            $changes[] = $data['auto_renew'] ? 'Automatische Verlängerung: ' . $data['renew_months'] : 'Automatische Verlängerung aus';
        }
        if ((int)$c['notice_value'] !== $data['notice_value'] || $c['notice_unit'] !== $data['notice_unit']) {
            $changes[] = 'Kündigungsfrist: ' . ($data['notice_value'] ? $data['notice_value'] . ' ' . CT_NOTICE_UNITS[$data['notice_unit']] : '–');
        }
        if ((int)$c['responsible_user_id'] !== (int)$data['responsible_user_id']) $changes[] = 'Verantwortlich: ' . ($resp['name'] ?? '–');
        if ($c['managed_by'] !== $data['managed_by'] || (int)$c['provider_id'] !== (int)$data['provider_id']) {
            $changes[] = 'Betreuung: ' . ($managed === 'provider' ? $provider['name'] : ($managed === 'it' ? 'IT-Dienstleister (Portal-Betreiber)' : 'Firma selbst'));
        }
        if ($c['notes'] !== $data['notes']) $changes[] = 'Notiz geändert';
        $costChanges = [];
        if ($costs) {
            if (($c['cost_cents'] === null ? null : (int)$c['cost_cents']) !== $data['cost_cents'] || $c['cost_interval'] !== $data['cost_interval']) {
                $costChanges[] = 'Kosten: ' . ($data['cost_cents'] !== null ? ct_fmt_price($data['cost_cents']) . ' ' . CT_INTERVALS[$data['cost_interval']] : '–');
            }
            if ($c['payment_method'] !== $data['payment_method']) $costChanges[] = 'Zahlungsart: ' . ($data['payment_method'] !== '' ? CT_PAYMENTS[$data['payment_method']] : '–');
        }
        update('contracts', (int)$c['id'], $data);
        foreach ($changes as $ch) ct_log($cid, (int)$c['id'], $ch);
        foreach ($costChanges as $ch) ct_log($cid, (int)$c['id'], $ch, 'cost');
        if ($changes || $costChanges) audit('contract.update', $data['title'] . ': ' . implode('; ', array_map(fn($x) => mb_substr($x, 0, 60), array_merge($changes, $costChanges ? ['Kosten geändert'] : []))), $cid);
        flash('success', $changes || $costChanges ? t('Gespeichert.') : t('Keine Änderungen.'));
        $back();
    }
    if (!$c) abort(400);
    if (ct_links_action($cid, $c, $action)) $back();
    if (ct_cancel_action($cid, $c, $action)) $back();
    if (ct_offer_action($cid, $c, $action)) $back();
    switch ($action) {
        case 'price_add':
            if (!$costs) abort(403);
            $from = ct_date((string)post('valid_from'));
            $raw = trim((string)post('cost'));
            $cents = ct_parse_price($raw);
            $interval = (string)post('cost_interval');
            if (!isset(CT_INTERVALS[$interval])) $interval = 'monat';
            if ($from === '') { flash('error', t('Bitte angeben, ab wann der neue Preis gilt.')); $back(); }
            if ($cents === null) { flash('error', t('Der Betrag „{x}“ ist keine gültige Zahl.', ['x' => $raw])); $back(); }
            if ($c['start_date'] !== '' && $from <= $c['start_date']) { flash('error', t('Der neue Preis muss nach dem Vertragsbeginn gelten. Den Preis ab Beginn ändern Sie oben im Vertrag.')); $back(); }
            if (row('SELECT id FROM ct_prices WHERE contract_id = ? AND valid_from = ?', [$c['id'], $from])) { flash('error', t('Für den {d} ist schon ein Preis eingetragen.', ['d' => fmt_date($from)])); $back(); }
            insert('ct_prices', ['company_id' => $cid, 'contract_id' => $c['id'], 'valid_from' => $from, 'cost_cents' => $cents, 'cost_interval' => $interval,
                'note' => mb_substr(trim((string)post('note')), 0, 255), 'user_id' => current_user()['id'], 'created_at' => now()]);
            ct_log($cid, (int)$c['id'], 'Neuer Preis ab ' . fmt_date($from) . ': ' . ct_fmt_price($cents) . ' ' . CT_INTERVALS[$interval], 'cost');
            audit('contract.price', $c['title'] . ': ' . $from, $cid);
            flash('success', t('Der neue Preis ab {d} wurde eingetragen.', ['d' => fmt_date($from)]));
            $back();
        case 'price_delete':
            if (!$costs) abort(403);
            $pr = row('SELECT * FROM ct_prices WHERE id = ? AND contract_id = ? AND company_id = ?', [(int)post('price_id', 0), $c['id'], $cid]);
            if ($pr) {
                q('DELETE FROM ct_prices WHERE id = ?', [$pr['id']]);
                ct_log($cid, (int)$c['id'], 'Preis ab ' . fmt_date($pr['valid_from']) . ' gelöscht', 'cost');
            }
            $back();
        case 'note':
            $body = mb_substr(trim((string)post('body')), 0, 2000);
            if ($body !== '') ct_log($cid, (int)$c['id'], $body, 'note');
            $back();
        case 'upload':
            [$files, $err] = ticket_uploads();
            if ($err) { flash('error', $err); $back(); }
            if (!$files) { flash('error', t('Bitte eine Datei auswählen.')); $back(); }
            $kind = (string)post('doc_kind');
            if (!isset(CT_DOC_KINDS[$kind])) $kind = 'sonstiges';
            $dir = ct_files_dir($cid);
            $n = 0;
            foreach ($files as $fl) {
                $stored = $c['id'] . '-' . bin2hex(random_bytes(12)) . '.bin';
                if (!move_uploaded_file($fl['tmp'], $dir . '/' . $stored)) continue;
                @chmod($dir . '/' . $stored, 0640);
                $mime = function_exists('mime_content_type') ? (string)@mime_content_type($dir . '/' . $stored) : 'application/octet-stream';
                insert('ct_files', ['company_id' => $cid, 'contract_id' => $c['id'], 'doc_kind' => $kind, 'filename' => $fl['name'], 'mime' => mb_substr($mime, 0, 100),
                    'size' => $fl['size'], 'stored_name' => $stored, 'user_id' => current_user()['id'], 'created_at' => now()]);
                ct_log($cid, (int)$c['id'], 'Dokument hinzugefügt: ' . CT_DOC_KINDS[$kind] . ' – ' . $fl['name']);
                $n++;
            }
            flash($n ? 'success' : 'error', $n ? t('Die Dokumente wurden hochgeladen.') : t('Die Datei konnte nicht gespeichert werden.'));
            $back();
        case 'file_delete':
            $fl = row('SELECT * FROM ct_files WHERE id = ? AND contract_id = ? AND company_id = ?', [(int)post('file_id', 0), $c['id'], $cid]);
            if ($fl) {
                @unlink(ct_files_dir($cid) . '/' . basename((string)$fl['stored_name']));
                q('DELETE FROM ct_files WHERE id = ?', [$fl['id']]);
                ct_log($cid, (int)$c['id'], 'Dokument gelöscht: ' . $fl['filename']);
            }
            $back();
        case 'delete':
            $u = current_user();
            if ($u['role'] !== 'firma_admin' && !is_staff_role($u['role'])) abort(403);
            foreach (rows('SELECT stored_name FROM ct_files WHERE contract_id = ? AND company_id = ?', [$c['id'], $cid]) as $fl) @unlink(ct_files_dir($cid) . '/' . basename((string)$fl['stored_name']));
            q('DELETE FROM ct_files WHERE contract_id = ? AND company_id = ?', [$c['id'], $cid]);
            q('DELETE FROM ct_log WHERE contract_id = ? AND company_id = ?', [$c['id'], $cid]);
            q('DELETE FROM ct_prices WHERE contract_id = ? AND company_id = ?', [$c['id'], $cid]);
            q('DELETE FROM ct_links WHERE contract_id = ? AND company_id = ?', [$c['id'], $cid]);
            q('DELETE FROM ct_cancellations WHERE contract_id = ? AND company_id = ?', [$c['id'], $cid]);
            q('DELETE FROM ct_offers WHERE contract_id = ? AND company_id = ?', [$c['id'], $cid]);
            q('DELETE FROM contracts WHERE id = ? AND company_id = ?', [$c['id'], $cid]);
            audit('contract.delete', $c['title'], $cid);
            flash('success', t('Der Vertrag „{x}“ wurde gelöscht.', ['x' => $c['title']]));
            ct_redirect('contracts');
    }
    abort(400);
}

function page_contract_file(): void
{
    $cid = ct_company_id();
    if (ct_perm($cid) === CT_PERM_NONE) abort(403);
    $f = row('SELECT * FROM ct_files WHERE id = ? AND company_id = ?', [query_int('id'), $cid]);
    if (!$f) abort(404);
    $path = ct_files_dir($cid) . '/' . basename((string)$f['stored_name']);
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
// Einstellungen: Vertragsarten, Rechte je Gruppe, Erinnerungen (Einsicht: zentrale Einstellungen)
// ---------------------------------------------------------------------------

function page_contract_settings(): void
{
    $cid = ct_company_id();
    if (!ct_can_manage($cid)) abort(403, t('Die Einstellungen pflegen die Administratoren der Firma.'));
    $staff = is_staff_role(current_user()['role']);
    $co = ct_company($cid);

    if (is_post()) {
        require_post_csrf();
        $action = (string)post('action');
        if (ct_api_settings_post($cid, $action)) ct_redirect('contract_settings');
        if (ct_ocr_settings_post($cid, $action)) ct_redirect('contract_settings');
        switch ($action) {
            case 'reminders':
                $to = trim((string)post('pdf_recipients'));
                $list = ct_parse_recipients($to);
                if ($to !== '' && !$list) { flash('error', t('Bitte gültige E-Mail-Adressen angeben (höchstens {n}).', ['n' => CT_MAIL_MAX])); break; }
                $interval = (string)post('pdf_interval');
                $data = ['ct_lead_days' => max(7, min(365, (int)post('lead_days', CT_LEAD_DEFAULT))),
                    'ct_pdf_interval' => isset(CT_PDF_INTERVALS[$interval]) ? $interval : '', 'ct_pdf_recipients' => implode(', ', $list)];
                // Kosten im PDF nur, wer selbst Kosten sehen darf
                if (ct_can_costs($cid)) $data['ct_pdf_costs'] = post('pdf_costs') === '1' ? 1 : 0;
                if ($data['ct_pdf_interval'] !== '' && !$list) { flash('error', t('Für den automatischen Versand bitte Empfänger angeben.')); break; }
                update('companies', $cid, $data);
                audit('contract.settings', json_encode($data, JSON_UNESCAPED_UNICODE), $cid);
                flash('success', t('Die Einstellungen wurden gespeichert.'));
                break;
            case 'cancel':
                if ($staff) abort(403);
                update('companies', $cid, ['ct_four_eyes' => post('four_eyes') === '1' ? 1 : 0]);
                audit('contract.settings', 'ct_four_eyes=' . (post('four_eyes') === '1' ? 1 : 0), $cid);
                flash('success', t('Die Einstellungen wurden gespeichert.'));
                break;
            case 'view':
                update('companies', $cid, ['ct_inv_licenses' => post('inv_licenses') === '1' ? 1 : 0]);
                audit('contract.settings', 'ct_inv_licenses=' . (post('inv_licenses') === '1' ? 1 : 0), $cid);
                flash('success', t('Die Einstellungen wurden gespeichert.'));
                break;
            case 'type_save':
                $name = mb_substr(trim((string)post('name')), 0, 120);
                $id = (int)post('type_id', 0);
                if ($name === '') { flash('error', t('Bitte einen Namen angeben.')); break; }
                if ($id) {
                    if (!row('SELECT id FROM ct_types WHERE id = ? AND company_id = ?', [$id, $cid])) abort(404);
                    update('ct_types', $id, ['name' => $name, 'active' => post('active') === '1' ? 1 : 0, 'sort' => (int)post('sort', 0)]);
                } else {
                    $max = (int)(row('SELECT MAX(sort) AS m FROM ct_types WHERE company_id = ?', [$cid])['m'] ?? 0);
                    insert('ct_types', ['company_id' => $cid, 'name' => $name, 'sort' => $max + 10, 'active' => 1]);
                }
                audit('contract.type', $name, $cid);
                flash('success', t('Die Vertragsart wurde gespeichert.'));
                break;
            default:
                abort(400);
        }
        ct_redirect('contract_settings');
    }

    $types = rows('SELECT t.*, (SELECT COUNT(*) FROM contracts c WHERE c.type_id = t.id) AS n FROM ct_types t WHERE t.company_id = ? ORDER BY t.sort, t.name', [$cid]);
    $deps = rows('SELECT d.id, d.name, COALESCE(g.perm, 0) AS perm, COALESCE(g.see_costs, 0) AS see_costs, COALESCE(g.approve_cancel, 0) AS approve_cancel
        FROM departments d LEFT JOIN ct_group_perms g ON g.department_id = d.id AND g.company_id = d.company_id
        WHERE d.company_id = ? AND d.active = 1 ORDER BY d.name', [$cid]);

    render_page(t('Einstellungen') . ' · ' . t('Verträge & Laufzeiten'), function () use ($cid, $co, $staff, $types, $deps) {
        echo company_switcher($cid, 'contract_settings');
        ct_tabs('contract_settings'); ?>
        <div class="card">
            <h2><?= e(t('Einsicht für den IT-Dienstleister')) ?></h2>
            <?= provider_access_note($cid, 'ct_provider_access') ?>
        </div>

        <form method="post" class="form card">
            <?= csrf_field() ?><input type="hidden" name="action" value="reminders">
            <h2><?= e(t('Erinnerungen und Fristenübersicht')) ?></h2>
            <label><?= e(t('Vorlauf für Erinnerungen (Tage)')) ?> <input type="number" name="lead_days" min="7" max="365" class="narrow-input" value="<?= (int)($co['ct_lead_days'] ?? CT_LEAD_DEFAULT) ?: CT_LEAD_DEFAULT ?>"></label>
            <p class="muted small"><?= e(t('So viele Tage vor dem Ende einer Kündigungsfrist oder eines Vertrags erscheint der Punkt unter „Demnächst fällig“, und die Firmen-Admins und die verantwortliche Person bekommen eine E-Mail – bei Einsicht auch Ihr IT-Dienstleister für die Verträge, die er betreut. Preisänderungen melden wir 14 Tage vorher an alle mit „Kosten sehen“.')) ?></p>
            <label><?= e(t('Fristenübersicht als PDF automatisch senden')) ?> <select name="pdf_interval">
                <?php foreach (CT_PDF_INTERVALS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($co['ct_pdf_interval'] ?? '') === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('Empfänger (mehrere mit Komma trennen)')) ?> <input name="pdf_recipients" maxlength="1000" value="<?= e((string)($co['ct_pdf_recipients'] ?? '')) ?>" placeholder="<?= e(t('z. B. geschaeftsfuehrung@firma.de')) ?>"></label>
            <?php if (ct_can_costs($cid)): ?><label class="check"><input type="checkbox" name="pdf_costs" value="1" <?= (int)($co['ct_pdf_costs'] ?? 0) ? 'checked' : '' ?>> <?= e(t('Kosten im PDF zeigen')) ?></label><?php endif; ?>
            <p class="muted small"><?= e(t('Monatlich mit den Fristen der nächsten 3 Monate, vierteljährlich mit den nächsten 6 Monaten.')) ?></p>
            <button class="btn primary"><?= e(t('Speichern')) ?></button>
        </form>

        <?php $tpl = ct_cancel_template($cid); $fourEyes = (int)(row('SELECT ct_four_eyes FROM companies WHERE id = ?', [$cid])['ct_four_eyes'] ?? 1); ?>
        <form method="post" class="form card">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
            <h2><?= e(t('Kündigungen')) ?></h2>
            <label class="check"><input type="checkbox" name="four_eyes" value="1" <?= $fourEyes ? 'checked' : '' ?> <?= $staff ? 'disabled' : '' ?>>
                <?= e(t('Vier-Augen-Prinzip: Wer eine Kündigung vorbereitet, darf sie nicht selbst freigeben')) ?></label>
            <p class="muted small"><?= e(t('Für kleine Firmen mit nur einer berechtigten Person abschalten. Freigeben dürfen Firmen-Admins und Gruppen mit „Freigabe Kündigung“.')) ?></p>
            <p><?= e(t('Vorlage des Kündigungsschreibens: „{x}“', ['x' => $tpl['name']])) ?>
                <?php if (company_has_app($cid, 'formulare') && (!$staff || admin_can('forms', true))): ?>
                    · <a href="<?= e(url('form_edit', ['id' => $tpl['id']] + (is_admin() ? ['company' => $cid] : []))) ?>"><?= e(t('in „Formulare“ bearbeiten')) ?></a><?php endif; ?></p>
            <p class="muted small"><?= e(t('Briefkopf, Logo und Fußzeile kommen aus der App „Formulare“. Platzhalter wie {{vertrag.termin}} werden beim Vorbereiten gefüllt.')) ?></p>
            <?php if (!$staff): ?><button class="btn primary"><?= e(t('Speichern')) ?></button><?php endif; ?>
        </form>

        <?php if (company_has_app($cid, 'inventar')): ?>
        <form method="post" class="form card">
            <?= csrf_field() ?><input type="hidden" name="action" value="view">
            <h2><?= e(t('Lizenzen aus dem Inventar')) ?></h2>
            <label class="check"><input type="checkbox" name="inv_licenses" value="1" <?= (int)($co['ct_inv_licenses'] ?? 1) ? 'checked' : '' ?>>
                <?= e(t('Abo- und Wartungslizenzen aus „Geräte & Lizenzen“ in der Vertragsliste und den Kosten zeigen')) ?></label>
            <p class="muted small"><?= e(t('Gepflegt werden sie weiter im Inventar. Ist eine Lizenz mit einem Vertrag verknüpft, erscheint nur der Vertrag. Die Lizenzen sieht nur, wer im Inventar Leserecht hat.')) ?></p>
            <button class="btn primary"><?= e(t('Speichern')) ?></button>
        </form>
        <?php endif; ?>

        <div class="card">
            <h2><?= e(t('Rechte je Gruppe')) ?></h2>
            <p class="muted"><?= e(t('Die Rechte der Abteilungen für alle Apps stehen gesammelt in den Einstellungen.')) ?> <a href="<?= e(url('rights', is_admin() ? ['company' => $cid] : [])) ?>"><?= e(t('Gruppenrechte öffnen')) ?></a></p>
        </div>

        <?php ct_api_settings_card($cid); ?>
        <?php ct_ocr_settings_card($cid); ?>

        <div class="card">
            <h2><?= e(t('Vertragsarten')) ?></h2>
            <table class="table"><thead><tr><th><?= e(t('Name')) ?></th><th class="num"><?= e(t('Reihenfolge')) ?></th><th><?= e(t('Aktiv')) ?></th><th class="num"><?= e(t('Verträge')) ?></th><th></th></tr></thead><tbody>
            <?php foreach ($types as $tp): $fid = 'cttype-' . (int)$tp['id']; ?>
                <tr><td><input form="<?= $fid ?>" name="name" maxlength="120" value="<?= e($tp['name']) ?>" required aria-label="<?= e(t('Name')) ?>"></td>
                    <td class="num"><input form="<?= $fid ?>" type="number" name="sort" value="<?= (int)$tp['sort'] ?>" class="narrow-input" aria-label="<?= e(t('Reihenfolge')) ?>"></td>
                    <td><input form="<?= $fid ?>" type="checkbox" name="active" value="1" <?= (int)$tp['active'] ? 'checked' : '' ?> aria-label="<?= e(t('Aktiv')) ?>"></td>
                    <td class="num"><?= (int)$tp['n'] ?></td>
                    <td><form method="post" id="<?= $fid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="type_save"><input type="hidden" name="type_id" value="<?= (int)$tp['id'] ?>">
                        <button class="btn small"><?= e(t('Speichern')) ?></button></form></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <form method="post" class="toolbar"><?= csrf_field() ?><input type="hidden" name="action" value="type_save">
                <input name="name" maxlength="120" required placeholder="<?= e(t('Neue Vertragsart, z. B. Reinigung')) ?>"> <button class="btn"><?= e(t('Hinzufügen')) ?></button></form>
        </div>
    <?php });
}

/** Karte „Preishistorie“ (nur mit „Kosten sehen“) */
function ct_prices_card(array $c, array $prices, bool $edit): void
{
    $cur = ct_price_at($c, $prices); ?>
    <div class="card">
        <h3><?= e(t('Preishistorie')) ?></h3>
        <div class="table-scroll"><table class="table"><thead><tr><th><?= e(t('Gültig ab')) ?></th><th class="num"><?= e(t('Betrag')) ?></th><th></th></tr></thead><tbody>
            <tr><td><?= $c['start_date'] !== '' ? fmt_date($c['start_date']) : e(t('Beginn')) ?></td>
                <td class="num nowrap"><?= $c['cost_cents'] !== null ? e(ct_fmt_price((int)$c['cost_cents']) . ' ' . t(CT_INTERVALS[$c['cost_interval']] ?? '')) : '–' ?></td>
                <td><?= $cur['id'] === 0 ? '<span class="tag">' . e(t('gilt jetzt')) . '</span>' : '' ?></td></tr>
            <?php foreach ($prices as $p): $future = $p['valid_from'] > date('Y-m-d'); ?>
            <tr><td><?= fmt_date($p['valid_from']) ?><?php if ($p['note'] !== ''): ?><br><span class="muted small"><?= e($p['note']) ?></span><?php endif; ?></td>
                <td class="num nowrap"><?= e(ct_fmt_price($p['cost_cents'] === null ? null : (int)$p['cost_cents']) . ' ' . t(CT_INTERVALS[$p['cost_interval']] ?? '')) ?></td>
                <td class="nowrap"><?= $cur['id'] === (int)$p['id'] ? '<span class="tag">' . e(t('gilt jetzt')) . '</span>' : ($future ? '<span class="tag warn">' . e(t('künftig')) . '</span>' : '') ?>
                    <?php if ($edit): ?><form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="inline-form inline-mini"><?= csrf_field() ?><input type="hidden" name="action" value="price_delete"><input type="hidden" name="price_id" value="<?= (int)$p['id'] ?>">
                        <button class="btn small danger" data-confirm="<?= e(t('Preis ab {d} löschen?', ['d' => fmt_date($p['valid_from'])])) ?>" aria-label="<?= e(t('Preis löschen')) ?>">✕</button></form><?php endif; ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php if ($edit): ?>
        <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="price_add">
            <div class="grid2">
                <label><?= e(t('Gültig ab')) ?> <input type="date" name="valid_from" required></label>
                <label><?= e(t('Neuer Betrag (€)')) ?> <input name="cost" inputmode="decimal" maxlength="20" required></label>
                <label><?= e(t('Zeitraum')) ?> <select name="cost_interval"><?php foreach (CT_INTERVALS as $k => $l): ?><option value="<?= e($k) ?>" <?= $cur['interval'] === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select></label>
                <label><?= e(t('Anlass')) ?> <input name="note" maxlength="255" placeholder="<?= e(t('z. B. Preiserhöhung laut Schreiben')) ?>"></label>
            </div>
            <button class="btn small"><?= e(t('Neuen Preis eintragen')) ?></button>
        </form>
        <p class="muted small"><?= e(t('Liste und Summen rechnen immer mit dem am jeweiligen Tag gültigen Preis.')) ?></p>
        <?php endif; ?>
    </div>
<?php }
