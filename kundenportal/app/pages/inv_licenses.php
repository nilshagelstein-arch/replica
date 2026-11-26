<?php
/**
 * App „Geräte & Lizenzen“ – Stufe 2: Lizenzen
 *
 * Arten: Abo, Kauflizenz, Wartung/Support. Plätze werden an Mitarbeiter oder Geräte vergeben.
 * Lizenzschlüssel liegen verschlüsselt in der Datenbank; „Anzeigen“ nur mit Recht „bearbeiten“, protokolliert.
 * Laufzeit mit Kündigungsfrist; Abos mit automatischer Verlängerung rücken nach Ablauf selbst weiter.
 * Verlauf und Anhänge nutzen inv_log / inv_files mit license_id (device_id = 0).
 */

declare(strict_types=1);

const INV_LIC_KINDS = ['abo' => 'Abo', 'kauf' => 'Kauflizenz', 'wartung' => 'Wartung / Support'];
const INV_LIC_INTERVALS = ['monat' => 'je Monat', 'jahr' => 'je Jahr', 'einmalig' => 'einmalig'];
const INV_NOTICE_UNITS = ['days' => 'Tage', 'weeks' => 'Wochen', 'months' => 'Monate'];
const INV_LIC_STATES = ['aktiv' => 'Aktiv', 'bald' => 'Läuft bald ab', 'abgelaufen' => 'Abgelaufen', 'gekuendigt' => 'Gekündigt', 'beendet' => 'Beendet'];
/** Vorlauf für „läuft bald ab“ und (in Stufe 5) die Erinnerungen */
const INV_LIC_SOON_DAYS = 60;

function inv_tabs(string $active): void
{
    if (!empty($GLOBALS['in_settings'])) return; // im Rahmen der Einstellungen ohne App-Reiter
    $tabs = ['inventory' => t('Geräte'), 'inv_licenses' => t('Lizenzen'), 'inv_handovers' => t('Übergaben')];
    if (!empty($GLOBALS['inv_cid']) && inv_can_see_costs((int)$GLOBALS['inv_cid'])) $tabs['inv_costs'] = t('Kosten');
    if (!empty($GLOBALS['inv_cid']) && inv_my_employee((int)$GLOBALS['inv_cid'])) $tabs['inv_my'] = t('Meine Geräte'); ?>
    <nav class="type-tabs">
        <?php foreach ($tabs as $route => $label): ?><a class="<?= $route === $active ? 'active' : '' ?>" href="<?= e(iurl($route)) ?>"><?= e($label) ?></a><?php endforeach; ?>
    </nav>
<?php }

function inv_lic_log(int $cid, int $licenseId, string $body, string $kind = 'event', ?int $userId = -1): void
{
    insert('inv_log', ['company_id' => $cid, 'device_id' => 0, 'license_id' => $licenseId, 'user_id' => $userId === -1 ? (current_user()['id'] ?? null) : $userId,
        'kind' => $kind, 'body' => $body, 'created_at' => now()]);
}

/** Letzter Tag, an dem noch gekündigt werden kann (Ende minus Kündigungsfrist), oder '' */
function inv_lic_cancel_by(array $l): string
{
    if ($l['end_date'] === '' || (int)$l['notice_value'] <= 0) return '';
    $unit = ['days' => 'days', 'weeks' => 'weeks', 'months' => 'months'][$l['notice_unit']] ?? 'months';
    return date('Y-m-d', strtotime($l['end_date'] . ' -' . (int)$l['notice_value'] . ' ' . $unit));
}

/** Zustand für Anzeige und Filter */
function inv_lic_state(array $l): string
{
    $today = date('Y-m-d');
    if ($l['status'] === 'beendet') return 'beendet';
    if ($l['status'] === 'gekuendigt') return $l['end_date'] !== '' && $l['end_date'] < $today ? 'beendet' : 'gekuendigt';
    if ($l['end_date'] === '') return 'aktiv';
    if ($l['end_date'] < $today) return 'abgelaufen';
    $soon = date('Y-m-d', strtotime('+' . INV_LIC_SOON_DAYS . ' days'));
    $cancelBy = inv_lic_cancel_by($l);
    $cancelSoon = $cancelBy !== '' && $cancelBy >= $today && $cancelBy <= $soon;
    // Verlängert sich die Lizenz selbst, zählt nur die Kündigungsfrist – und das nur bei Laufzeiten ab einem Jahr
    // (Monatsabos wären sonst ständig „bald fällig“)
    if ((int)($l['auto_renew'] ?? 0) === 1) return $cancelSoon && (int)($l['renew_months'] ?? 12) >= 12 ? 'bald' : 'aktiv';
    if ($l['end_date'] <= $soon || $cancelSoon) return 'bald';
    return 'aktiv';
}

/** Kosten je Monat in Cent (einmalige Kosten zählen nicht) */
function inv_lic_monthly(array $l): int
{
    if ($l['cost_cents'] === null || in_array(inv_lic_state($l), ['beendet', 'abgelaufen'], true)) return 0;
    return match ($l['cost_interval']) { 'monat' => (int)$l['cost_cents'], 'jahr' => (int)round($l['cost_cents'] / 12), default => 0 };
}

/** Abos mit automatischer Verlängerung nach Ablauf weiterschieben (beim Aufruf, höchstens einmal je Anfrage und Firma) */
function inv_lic_renew(int $cid): void
{
    static $done = [];
    if (isset($done[$cid])) return;
    $done[$cid] = true;
    $today = date('Y-m-d');
    foreach (rows("SELECT * FROM inv_licenses WHERE company_id = ? AND status = 'aktiv' AND auto_renew = 1 AND renew_months > 0 AND end_date <> '' AND end_date < ?", [$cid, $today]) as $l) {
        $end = $l['end_date'];
        for ($i = 0; $i < 600 && $end < $today; $i++) $end = date('Y-m-d', strtotime($end . ' +' . (int)$l['renew_months'] . ' months'));
        update('inv_licenses', (int)$l['id'], ['end_date' => $end, 'updated_at' => now()]);
        inv_lic_log($cid, (int)$l['id'], 'Automatisch verlängert bis: ' . fmt_date($end), 'event', null);
    }
}

function inv_load_license(int $id, int $cid): ?array
{
    return row('SELECT l.*, d.inv_no AS device_inv_no FROM inv_licenses l LEFT JOIN inv_devices d ON d.id = l.device_id WHERE l.id = ? AND l.company_id = ?', [$id, $cid]) ?: null;
}

/** Belegte Plätze je Lizenz */
function inv_lic_used(array $ids): array
{
    if (!$ids) return [];
    $out = array_fill_keys($ids, 0);
    foreach (rows('SELECT license_id, COUNT(*) AS n FROM inv_license_assign WHERE license_id IN (' . implode(',', array_map('intval', $ids)) . ') GROUP BY license_id') as $r) $out[(int)$r['license_id']] = (int)$r['n'];
    return $out;
}

function inv_lic_state_badge(string $state): string
{
    return '<span class="badge lic-' . e($state) . '">' . e(t(INV_LIC_STATES[$state])) . '</span>';
}

// ---------------------------------------------------------------------------
// Übersicht
// ---------------------------------------------------------------------------

function page_inv_licenses(): void
{
    $cid = inv_company_id();
    $perm = inv_perm($cid);
    if ($perm === INV_PERM_NONE) { page_inventory(); return; }
    inv_lic_renew($cid);
    $search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $kind = (string)($_GET['kind'] ?? '');
    if (!isset(INV_LIC_KINDS[$kind])) $kind = '';
    $state = (string)($_GET['state'] ?? 'laufend');
    $where = ['company_id = ?'];
    $p = [$cid];
    if ($kind !== '') { $where[] = 'kind = ?'; $p[] = $kind; }
    if ($search !== '') { $where[] = '(product LIKE ? OR manufacturer LIKE ? OR contract_no LIKE ? OR supplier LIKE ?)'; array_push($p, "%$search%", "%$search%", "%$search%", "%$search%"); }
    $all = rows('SELECT * FROM inv_licenses WHERE ' . implode(' AND ', $where) . ' ORDER BY product', $p);
    $used = inv_lic_used(array_map(fn($l) => (int)$l['id'], $all));
    foreach ($all as &$l) { $l['state'] = inv_lic_state($l); $l['used'] = $used[(int)$l['id']] ?? 0; $l['cancel_by'] = inv_lic_cancel_by($l); }
    unset($l);
    $counts = ['laufend' => 0, 'bald' => 0, 'over' => 0, 'ende' => 0];
    $monthly = 0;
    foreach ($all as $l) {
        if (!in_array($l['state'], ['beendet', 'abgelaufen'], true)) $counts['laufend']++; else $counts['ende']++;
        if ($l['state'] === 'bald') $counts['bald']++;
        if ((int)$l['seats'] > 0 && $l['used'] > (int)$l['seats']) $counts['over']++;
        $monthly += inv_lic_monthly($l);
    }
    $list = array_values(array_filter($all, fn($l) => match ($state) {
        'bald' => $l['state'] === 'bald', 'over' => (int)$l['seats'] > 0 && $l['used'] > (int)$l['seats'],
        'ende' => in_array($l['state'], ['beendet', 'abgelaufen'], true), 'alle' => true,
        default => !in_array($l['state'], ['beendet', 'abgelaufen'], true) }));

    render_page(t('Geräte & Lizenzen'), function () use ($cid, $perm, $search, $kind, $state, $list, $counts, $monthly) {
        echo company_switcher($cid, 'inv_licenses');
        inv_tabs('inv_licenses'); ?>
        <div class="toolbar">
            <?php if ($perm >= INV_PERM_EDIT): ?><a class="btn primary" href="<?= e(iurl('inv_license', ['id' => 'new'])) ?>"><?= e(t('+ Lizenz erfassen')) ?></a><?php endif; ?>
            <a class="btn" href="<?= e(iurl('inv_export', ['what' => 'licenses'])) ?>"><?= e(t('CSV-Export')) ?></a>
            <a class="btn" href="<?= e(iurl('inv_export', ['what' => 'assignments'])) ?>"><?= e(t('Export Lizenzvergabe')) ?></a>
            <?php if ($perm >= INV_PERM_EDIT): ?><a class="btn" href="<?= e(iurl('inv_import', ['what' => 'licenses'])) ?>"><?= e(t('CSV-Import')) ?></a><?php endif; ?>
            <?php if (inv_can_manage($cid)): ?><a class="btn" href="<?= e(iurl('inv_settings')) ?>"><?= e(t('Einstellungen')) ?></a><?php endif; ?>
        </div>
        <div class="tiles">
            <?php foreach (['laufend' => t('laufend'), 'bald' => t('läuft bald ab / Kündigung fällig'), 'over' => t('überbelegt'), 'ende' => t('abgelaufen / beendet')] as $k => $label): ?>
                <a class="tile <?= $state === $k ? 'active' : '' ?>" href="<?= e(iurl('inv_licenses', ['state' => $k])) ?>"><strong><?= $counts[$k] ?></strong><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
            <?php if (inv_can_see_costs($cid)): ?><div class="tile"><strong><?= e(inv_fmt_price($monthly)) ?></strong><span><?= e(t('laufende Kosten je Monat')) ?></span></div><?php endif; ?>
        </div>
        <form method="get" class="toolbar">
            <input type="hidden" name="r" value="inv_licenses">
            <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
            <input type="hidden" name="state" value="<?= e($state) ?>">
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('Suche: Produkt, Hersteller, Vertrag, Lieferant')) ?>">
            <select name="kind"><option value=""><?= e(t('alle Arten')) ?></option>
                <?php foreach (INV_LIC_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e(t($l)) ?></option><?php endforeach; ?></select>
            <button class="btn"><?= e(t('Filtern')) ?></button>
            <a class="btn" href="<?= e(iurl('inv_licenses', ['state' => 'alle'])) ?>"><?= e(t('Alle')) ?></a>
        </form>
        <div class="card">
            <table class="table">
                <thead><tr><th><?= e(t('Produkt')) ?></th><th><?= e(t('Art')) ?></th><th class="num"><?= e(t('Plätze')) ?></th><th><?= e(t('Status')) ?></th>
                    <th><?= e(t('Laufzeit bis')) ?></th><th><?= e(t('Kündigen bis')) ?></th><?php if ($seeCosts = inv_can_see_costs($cid)): ?><th class="num"><?= e(t('Kosten')) ?></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($list as $l): $over = (int)$l['seats'] > 0 && $l['used'] > (int)$l['seats']; ?>
                    <tr><td><a href="<?= e(iurl('inv_license', ['id' => $l['id']])) ?>"><strong><?= e($l['product']) ?></strong></a><?= $l['manufacturer'] !== '' ? '<br><span class="muted small">' . e($l['manufacturer']) . '</span>' : '' ?></td>
                        <td><?= e(t(INV_LIC_KINDS[$l['kind']] ?? $l['kind'])) ?></td>
                        <td class="num <?= $over ? 'warn-text' : '' ?>"><?= (int)$l['used'] ?> / <?= (int)$l['seats'] > 0 ? (int)$l['seats'] : '∞' ?></td>
                        <td><?= inv_lic_state_badge($l['state']) ?><?= (int)$l['auto_renew'] && $l['status'] === 'aktiv' ? ' <span class="muted small">↻</span>' : '' ?></td>
                        <td><?= $l['end_date'] !== '' ? fmt_date($l['end_date']) : '–' ?></td>
                        <td><?php if ($l['cancel_by'] !== '' && $l['status'] === 'aktiv'): ?><span class="<?= $l['cancel_by'] < date('Y-m-d') ? 'muted' : ($l['cancel_by'] <= date('Y-m-d', strtotime('+' . INV_LIC_SOON_DAYS . ' days')) ? 'warn-text' : '') ?>"><?= fmt_date($l['cancel_by']) ?></span><?php else: ?>–<?php endif; ?></td>
                        <?php if ($seeCosts): ?><td class="num"><?= $l['cost_cents'] !== null ? e(inv_fmt_price((int)$l['cost_cents'])) . ' <span class="muted small">' . e(t(INV_LIC_INTERVALS[$l['cost_interval']] ?? '')) . '</span>' : '–' ?></td><?php endif; ?></tr>
                <?php endforeach; ?>
                <?php if (!$list): ?><tr><td colspan="7" class="muted"><?= e(t('Keine Lizenzen gefunden.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
            <p class="muted small"><?= e(t('↻ = verlängert sich automatisch. „Läuft bald ab“ bedeutet: Ende oder letzter Kündigungstag in den nächsten {n} Tagen; bei automatischer Verlängerung zählt nur der Kündigungstag (ab einem Jahr Laufzeit).', ['n' => INV_LIC_SOON_DAYS])) ?></p>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Lizenz ansehen / erfassen / bearbeiten
// ---------------------------------------------------------------------------

function page_inv_license(): void
{
    $cid = inv_company_id();
    $perm = inv_perm($cid);
    if ($perm === INV_PERM_NONE) abort(403);
    inv_lic_renew($cid);
    $isNew = ($_GET['id'] ?? '') === 'new';
    if ($isNew && $perm < INV_PERM_EDIT) abort(403);
    $l = $isNew ? null : inv_load_license(query_int('id'), $cid);
    if (!$isNew && !$l) abort(404);
    $edit = $perm >= INV_PERM_EDIT;
    $assign = $l ? rows('SELECT a.*, e.first_name, e.last_name, e.employee_no, e.status AS employee_status, d.inv_no, d.manufacturer, d.model
        FROM inv_license_assign a LEFT JOIN employees e ON e.id = a.employee_id LEFT JOIN inv_devices d ON d.id = a.device_id
        WHERE a.license_id = ? ORDER BY e.last_name, e.first_name, d.inv_no', [$l['id']]) : [];
    $employees = $edit ? inv_employees($cid) : [];
    $devices = $edit ? rows("SELECT id, inv_no, manufacturer, model FROM inv_devices WHERE company_id = ? AND status IN ('lager', 'einsatz', 'reparatur') ORDER BY inv_no", [$cid]) : [];
    $log = $l ? rows('SELECT g.*, u.name AS user_name FROM inv_log g LEFT JOIN users u ON u.id = g.user_id WHERE g.license_id = ?'
        . (inv_can_see_costs($cid) ? '' : INV_LOG_NO_COSTS) . ' ORDER BY g.id DESC LIMIT 200', [$l['id']]) : [];
    $files = $l ? rows('SELECT * FROM inv_files WHERE license_id = ? ORDER BY id', [$l['id']]) : [];
    $revealed = $GLOBALS['inv_lic_revealed'] ?? null;
    $title = $isNew ? t('Lizenz erfassen') : $l['product'];

    render_page($title, function () use ($cid, $perm, $edit, $l, $isNew, $assign, $employees, $devices, $log, $files, $revealed) {
        $v = fn(string $k) => (string)($l[$k] ?? '');
        $state = $l ? inv_lic_state($l) : 'aktiv';
        $cancelBy = $l ? inv_lic_cancel_by($l) : '';
        $seats = (int)($l['seats'] ?? 1);
        $used = count($assign); ?>
        <p><a href="<?= e(iurl('inv_licenses')) ?>">← <?= e(t('Lizenzen')) ?></a></p>
        <?php if ($l): ?>
        <div class="tiles">
            <div class="tile"><strong><?= inv_lic_state_badge($state) ?></strong><span><?= e(t(INV_LIC_KINDS[$l['kind']] ?? $l['kind'])) ?></span></div>
            <div class="tile"><strong class="<?= $seats > 0 && $used > $seats ? 'warn-text' : '' ?>"><?= $used ?> / <?= $seats > 0 ? $seats : '∞' ?></strong><span><?= e(t('Plätze belegt')) ?></span></div>
            <div class="tile"><strong><?= $l['end_date'] !== '' ? fmt_date($l['end_date']) : '–' ?></strong><span><?= e(t('Laufzeit bis')) ?></span></div>
            <div class="tile"><strong><?= $cancelBy !== '' && $l['status'] === 'aktiv' ? fmt_date($cancelBy) : '–' ?></strong><span><?= e(t('Kündigen bis')) ?></span></div>
        </div>
        <?php endif; ?>
        <div class="grid2 inv-grid">
        <div>
        <?php if ($edit): ?>
        <form method="post" action="<?= e(iurl('inv_lic_action', $isNew ? [] : ['id' => $l['id']])) ?>" class="form card">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <h2><?= e(t('Lizenz')) ?></h2>
            <div class="grid2">
                <label><?= e(t('Produkt *')) ?> <input name="product" maxlength="190" required value="<?= e($v('product')) ?>" placeholder="<?= e(t('z. B. Microsoft 365 Business Standard')) ?>"></label>
                <label><?= e(t('Hersteller')) ?> <input name="manufacturer" maxlength="120" value="<?= e($v('manufacturer')) ?>"></label>
                <label><?= e(t('Art')) ?> <select name="kind">
                    <?php foreach (INV_LIC_KINDS as $k => $lb): ?><option value="<?= e($k) ?>" <?= ($l['kind'] ?? 'abo') === $k ? 'selected' : '' ?>><?= e(t($lb)) ?></option><?php endforeach; ?></select></label>
                <label><?= e(t('Anzahl Plätze')) ?> <input type="number" name="seats" min="0" max="100000" value="<?= (int)($l['seats'] ?? 1) ?>"></label>
                <label><?= e(t('Vertrags- / Kundennummer')) ?> <input name="contract_no" maxlength="120" value="<?= e($v('contract_no')) ?>"></label>
                <label><?= e(t('Lieferant')) ?> <input name="supplier" maxlength="190" value="<?= e($v('supplier')) ?>"></label>
            </div>
            <p class="muted small"><?= e(t('0 Plätze = unbegrenzt (z. B. Standortlizenz).')) ?></p>
            <label><?= e(t('Lizenzschlüssel')) ?> <input name="license_key" maxlength="1000" autocomplete="off" placeholder="<?= e(!empty($l['license_key_enc']) ? t('gespeichert – leer lassen, um ihn zu behalten') : '') ?>"></label>
            <?php if (!empty($l['license_key_enc'])): ?><label class="check"><input type="checkbox" name="key_clear" value="1"> <?= e(t('Gespeicherten Lizenzschlüssel löschen')) ?></label><?php endif; ?>
            <h3><?= e(t('Laufzeit')) ?></h3>
            <div class="grid2">
                <label><?= e(t('Beginn')) ?> <input type="date" name="start_date" value="<?= e($v('start_date')) ?>"></label>
                <label><?= e(t('Ende / nächste Verlängerung')) ?> <input type="date" name="end_date" value="<?= e($v('end_date')) ?>"></label>
                <label><?= e(t('Kündigungsfrist')) ?> <span class="inline-pair"><input type="number" name="notice_value" min="0" max="999" value="<?= (int)($l['notice_value'] ?? 0) ?>">
                    <select name="notice_unit"><?php foreach (INV_NOTICE_UNITS as $k => $lb): ?><option value="<?= e($k) ?>" <?= ($l['notice_unit'] ?? 'months') === $k ? 'selected' : '' ?>><?= e(t($lb)) ?></option><?php endforeach; ?></select></span></label>
                <label><?= e(t('Verlängert sich um (Monate)')) ?> <input type="number" name="renew_months" min="1" max="120" value="<?= (int)($l['renew_months'] ?? 12) ?>"></label>
            </div>
            <label class="check"><input type="checkbox" name="auto_renew" value="1" <?= (int)($l['auto_renew'] ?? 0) ? 'checked' : '' ?>> <?= e(t('Verlängert sich automatisch, wenn nicht gekündigt wird')) ?></label>
            <?php if (inv_can_see_costs($cid)): ?>
            <h3><?= e(t('Kosten')) ?></h3>
            <div class="grid2">
                <label><?= e(t('Betrag (€)')) ?> <input name="cost" inputmode="decimal" maxlength="20" value="<?= e(isset($l['cost_cents']) && $l['cost_cents'] !== null ? number_format((int)$l['cost_cents'] / 100, 2, ',', '.') : '') ?>"></label>
                <label><?= e(t('Zeitraum')) ?> <select name="cost_interval">
                    <?php foreach (INV_LIC_INTERVALS as $k => $lb): ?><option value="<?= e($k) ?>" <?= ($l['cost_interval'] ?? 'monat') === $k ? 'selected' : '' ?>><?= e(t($lb)) ?></option><?php endforeach; ?></select></label>
            </div>
            <p class="muted small"><?= e(t('Gesamtbetrag der Lizenz, nicht je Platz.')) ?></p>
            <?php endif; ?>
            <label><?= e(t('Gehört zu Gerät (z. B. Firewall-Lizenz, Herstellersupport)')) ?> <select name="device_id"><option value=""><?= e(t('– kein Gerät –')) ?></option>
                <?php foreach ($devices as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)($l['device_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['inv_no'] . ' · ' . trim($d['manufacturer'] . ' ' . $d['model'])) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('Notiz')) ?> <textarea name="notes" rows="3"><?= e($v('notes')) ?></textarea></label>
            <button class="btn primary"><?= e($isNew ? t('Lizenz anlegen') : t('Speichern')) ?></button>
        </form>
        <?php else: ?>
        <div class="card">
            <h2><?= e(t('Lizenz')) ?></h2>
            <dl class="facts">
                <?php foreach ([t('Produkt') => $v('product'), t('Hersteller') => $v('manufacturer'), t('Art') => t(INV_LIC_KINDS[$l['kind']] ?? $l['kind']),
                    t('Vertrags- / Kundennummer') => $v('contract_no'), t('Lieferant') => $v('supplier'), t('Beginn') => $l['start_date'] !== '' ? fmt_date($l['start_date']) : '',
                    t('Kündigungsfrist') => (int)$l['notice_value'] ? (int)$l['notice_value'] . ' ' . t(INV_NOTICE_UNITS[$l['notice_unit']] ?? '') : '',
                    t('Automatische Verlängerung') => (int)$l['auto_renew'] ? t('ja, um {n} Monate', ['n' => (int)$l['renew_months']]) : '',
                    t('Kosten') => $l['cost_cents'] !== null && inv_can_see_costs($cid) ? inv_fmt_price((int)$l['cost_cents']) . ' ' . t(INV_LIC_INTERVALS[$l['cost_interval']] ?? '') : '',
                    t('Gerät') => (string)($l['device_inv_no'] ?? '')] as $k => $val): if ($val === '') continue; ?>
                    <dt><?= e($k) ?></dt><dd><?= e($val) ?></dd>
                <?php endforeach; ?>
            </dl>
            <?php if ($v('notes') !== ''): ?><p class="pre"><?= e($v('notes')) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
        <div>
        <?php if ($l): ?>
            <div class="card">
                <h3><?= e(t('Vergeben an')) ?> (<?= $used ?> / <?= $seats > 0 ? $seats : '∞' ?>)</h3>
                <?php if ($seats > 0 && $used > $seats): ?><p class="alert error small"><?= e(t('Überbelegt: {n} Plätze mehr vergeben als vorhanden.', ['n' => $used - $seats])) ?></p><?php endif; ?>
                <ul class="inv-log">
                <?php foreach ($assign as $a): ?>
                    <li><?php if ($a['employee_id']): ?>👤 <?= e(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: t('Gelöschter Mitarbeiter')) ?><?= ($a['employee_status'] ?? '') === 'ausgeschieden' ? ' <span class="tag warn">' . e(t('ausgeschieden')) . '</span>' : '' ?>
                        <?php else: ?>💻 <a href="<?= e(iurl('inv_device', ['id' => $a['device_id']])) ?>"><?= e(($a['inv_no'] ?? '–') . ' · ' . trim(($a['manufacturer'] ?? '') . ' ' . ($a['model'] ?? ''))) ?></a><?php endif; ?>
                        <span class="muted small">· <?= fmt_date(substr($a['assigned_at'], 0, 10)) ?></span>
                        <?php if ($edit): ?><form method="post" action="<?= e(iurl('inv_lic_action', ['id' => $l['id']])) ?>" class="inline-form inline-mini"><?= csrf_field() ?><input type="hidden" name="action" value="unassign"><input type="hidden" name="assign_id" value="<?= (int)$a['id'] ?>">
                            <button class="btn small"><?= e(t('Freigeben')) ?></button></form><?php endif; ?></li>
                <?php endforeach; ?>
                <?php if (!$assign): ?><li class="muted"><?= e(t('Noch nicht vergeben.')) ?></li><?php endif; ?>
                </ul>
                <?php if ($edit && in_array($state, ['aktiv', 'bald', 'gekuendigt'], true)): ?>
                <form method="post" action="<?= e(iurl('inv_lic_action', ['id' => $l['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="assign">
                    <label><?= e(t('Mitarbeiter')) ?> <select name="employee_id"><option value=""><?= e(t('– bitte wählen –')) ?></option>
                        <?php foreach ($employees as $em): ?><option value="<?= (int)$em['id'] ?>"><?= e($em['last_name'] . ', ' . $em['first_name']) ?><?= $em['employee_no'] !== '' ? ' (' . e($em['employee_no']) . ')' : '' ?></option><?php endforeach; ?></select></label>
                    <label><?= e(t('oder Gerät')) ?> <select name="device_id"><option value=""><?= e(t('– bitte wählen –')) ?></option>
                        <?php foreach ($devices as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['inv_no'] . ' · ' . trim($d['manufacturer'] . ' ' . $d['model'])) ?></option><?php endforeach; ?></select></label>
                    <button class="btn small"><?= e(t('Platz vergeben')) ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php if ($edit && !empty($l['license_key_enc'])): ?>
            <div class="card">
                <h3><?= e(t('Lizenzschlüssel')) ?></h3>
                <?php if ($revealed !== null): ?>
                    <p><code class="secret"><?= e($revealed) ?></code></p>
                    <p class="muted small"><?= e(t('Das Anzeigen wurde protokolliert. Der Schlüssel verschwindet beim nächsten Seitenaufruf.')) ?></p>
                <?php else: ?>
                    <form method="post" action="<?= e(iurl('inv_lic_action', ['id' => $l['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="reveal">
                        <button class="btn"><?= e(t('Anzeigen')) ?></button></form>
                    <p class="muted small"><?= e(t('Jedes Anzeigen steht im Protokoll.')) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($edit): ?>
            <div class="card">
                <h3><?= e(t('Kündigung')) ?></h3>
                <?php if ($l['status'] === 'aktiv'): ?>
                    <form method="post" action="<?= e(iurl('inv_lic_action', ['id' => $l['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                        <label><?= e(t('Gekündigt am')) ?> <input type="date" name="cancelled_at" value="<?= date('Y-m-d') ?>" required></label>
                        <p class="muted small"><?= e(t('Die Lizenz läuft bis zum eingetragenen Ende weiter und verlängert sich nicht mehr.')) ?></p>
                        <button class="btn"><?= e(t('Als gekündigt markieren')) ?></button></form>
                <?php else: ?>
                    <p><?= e(t('Status: {x}', ['x' => t(INV_LIC_STATES[$state])])) ?><?= $l['cancelled_at'] !== '' ? ' · ' . e(t('gekündigt am {d}', ['d' => fmt_date($l['cancelled_at'])])) : '' ?></p>
                    <form method="post" action="<?= e(iurl('inv_lic_action', ['id' => $l['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="reactivate">
                        <button class="btn small"><?= e(t('Wieder aktiv setzen')) ?></button></form>
                <?php endif; ?>
                <?php if ($l['status'] !== 'beendet'): ?>
                    <form method="post" action="<?= e(iurl('inv_lic_action', ['id' => $l['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="end">
                        <button class="btn small" data-confirm="<?= e(t('Lizenz beenden und alle Plätze freigeben?')) ?>"><?= e(t('Beenden und Plätze freigeben')) ?></button></form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (function_exists('ct_inventory_card')) ct_inventory_card($cid, 'license', (int)$l['id']); ?>
            <div class="card">
                <h3><?= e(t('Anhänge')) ?></h3>
                <?php if ($files): ?><ul class="file-list">
                    <?php foreach ($files as $f): ?><li><a href="<?= e(iurl('inv_file', ['id' => $f['id']])) ?>"><?= e($f['filename']) ?></a> <span class="muted small"><?= fmt_dt($f['created_at']) ?></span>
                        <?php if ($edit): ?><form method="post" action="<?= e(iurl('inv_lic_action', ['id' => $l['id']])) ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="file_delete"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                            <button class="btn small danger" data-confirm="<?= e(t('Anhang „{x}“ löschen?', ['x' => $f['filename']])) ?>">✕</button></form><?php endif; ?></li>
                    <?php endforeach; ?></ul>
                <?php else: ?><p class="muted"><?= e(t('Keine Anhänge.')) ?></p><?php endif; ?>
                <?php if ($edit): ?>
                <form method="post" action="<?= e(iurl('inv_lic_action', ['id' => $l['id']])) ?>" enctype="multipart/form-data" class="form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
                    <label><?= e(t('Vertrag, Rechnung, Lizenzzertifikat …')) ?> <input type="file" name="files[]" multiple required></label>
                    <button class="btn small"><?= e(t('Hochladen')) ?></button>
                </form>
                <?php endif; ?>
            </div>
            <div class="card">
                <h3><?= e(t('Verlauf')) ?></h3>
                <ul class="inv-log">
                <?php foreach ($log as $g): ?>
                    <li><span class="muted small"><?= fmt_dt($g['created_at']) ?> · <?= e($g['user_name'] ?? t('System')) ?></span><br><?= $g['kind'] === 'note' ? '📝 ' : '' ?><?= e(t_parts((string)$g['body'])) ?></li>
                <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($edit): ?>
            <form method="post" action="<?= e(iurl('inv_lic_action', ['id' => $l['id']])) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <p class="muted small"><?= e(t('Versehentlich angelegt? Beendete Lizenzen bitte nicht löschen, damit der Verlauf erhalten bleibt.')) ?></p>
                <button class="btn small danger" data-confirm="<?= e(t('Lizenz „{x}“ mit Verlauf und Anhängen endgültig löschen?', ['x' => $l['product']])) ?>"><?= e(t('Lizenz löschen')) ?></button></form>
            <?php endif; ?>
        <?php else: ?>
            <div class="card"><p class="muted"><?= e(t('Nach dem Anlegen vergeben Sie die Plätze an Mitarbeiter oder Geräte.')) ?></p></div>
        <?php endif; ?>
        </div>
        </div>
    <?php });
}

function page_inv_lic_action(): void
{
    $cid = inv_company_id();
    if (!is_post()) abort(405);
    require_post_csrf();
    if (inv_perm($cid) < INV_PERM_EDIT) abort(403);
    $action = (string)post('action');
    $idParam = (string)($_GET['id'] ?? '');
    $l = $idParam !== '' ? inv_load_license((int)$idParam, $cid) : null;
    if ($idParam !== '' && !$l) abort(404);
    $back = fn() => iredirect('inv_license', ['id' => $l['id']]);
    $f = fn(string $k, int $max) => mb_substr(trim((string)post($k)), 0, $max);

    if ($action === 'save') {
        $product = $f('product', 190);
        if ($product === '') { flash('error', t('Bitte ein Produkt angeben.')); $l ? $back() : iredirect('inv_license', ['id' => 'new']); }
        $kind = isset(INV_LIC_KINDS[post('kind')]) ? (string)post('kind') : 'abo';
        $dev = (int)post('device_id', 0) ? row('SELECT id, inv_no FROM inv_devices WHERE id = ? AND company_id = ?', [(int)post('device_id', 0), $cid]) : null;
        if ((int)post('device_id', 0) && !$dev) abort(400);
        $data = ['product' => $product, 'manufacturer' => $f('manufacturer', 120), 'kind' => $kind, 'seats' => max(0, min(100000, (int)post('seats', 1))),
            'contract_no' => $f('contract_no', 120), 'supplier' => $f('supplier', 190), 'start_date' => inv_date((string)post('start_date')),
            'end_date' => inv_date((string)post('end_date')), 'notice_value' => max(0, min(999, (int)post('notice_value', 0))),
            'notice_unit' => isset(INV_NOTICE_UNITS[post('notice_unit')]) ? (string)post('notice_unit') : 'months',
            'auto_renew' => post('auto_renew') === '1' ? 1 : 0, 'renew_months' => max(1, min(120, (int)post('renew_months', 12))),
            // ohne Kostenrecht bleiben die Kosten unverändert (neu: leer)
            'cost_cents' => inv_can_see_costs($cid) ? inv_parse_price((string)post('cost')) : ($l['cost_cents'] ?? null),
            'cost_interval' => inv_can_see_costs($cid) ? (isset(INV_LIC_INTERVALS[post('cost_interval')]) ? (string)post('cost_interval') : 'monat') : ($l['cost_interval'] ?? 'monat'),
            'device_id' => $dev['id'] ?? null, 'notes' => $f('notes', 5000), 'updated_at' => now()];
        $key = trim((string)post('license_key'));
        if ($key !== '') $data['license_key_enc'] = encrypt_secret(mb_substr($key, 0, 1000));
        elseif (post('key_clear') === '1') $data['license_key_enc'] = null;
        if (!$l) {
            $id = insert('inv_licenses', $data + ['company_id' => $cid, 'status' => 'aktiv', 'created_by' => current_user()['id'], 'created_at' => now()]);
            inv_lic_log($cid, $id, 'Lizenz angelegt: ' . $product);
            audit('inventory.license_create', $product, $cid);
            flash('success', t('Die Lizenz „{x}“ wurde angelegt.', ['x' => $product]));
            iredirect('inv_license', ['id' => $id]);
        }
        $labels = ['product' => 'Produkt', 'manufacturer' => 'Hersteller', 'seats' => 'Anzahl Plätze', 'contract_no' => 'Vertragsnummer', 'supplier' => 'Lieferant',
            'start_date' => 'Beginn', 'end_date' => 'Ende', 'renew_months' => 'Verlängerung (Monate)'];
        $changes = [];
        foreach ($labels as $k => $lab) {
            if ((string)$l[$k] !== (string)$data[$k]) $changes[] = $lab . ': ' . ($data[$k] === '' ? '–' : (in_array($k, ['start_date', 'end_date'], true) ? fmt_date((string)$data[$k]) : (string)$data[$k]));
        }
        if ($l['kind'] !== $kind) $changes[] = 'Art: ' . INV_LIC_KINDS[$kind];
        if ((int)$l['notice_value'] !== $data['notice_value'] || $l['notice_unit'] !== $data['notice_unit']) $changes[] = 'Kündigungsfrist: ' . $data['notice_value'] . ' ' . INV_NOTICE_UNITS[$data['notice_unit']];
        if ((int)$l['auto_renew'] !== $data['auto_renew']) $changes[] = $data['auto_renew'] ? 'Automatische Verlängerung an' : 'Automatische Verlängerung aus';
        if (($l['cost_cents'] === null ? null : (int)$l['cost_cents']) !== $data['cost_cents'] || $l['cost_interval'] !== $data['cost_interval']) {
            $changes[] = 'Kosten: ' . ($data['cost_cents'] === null ? '–' : inv_fmt_price($data['cost_cents']) . ' ' . INV_LIC_INTERVALS[$data['cost_interval']]);
        }
        if ((int)$l['device_id'] !== (int)$data['device_id']) $changes[] = 'Gerät: ' . ($dev['inv_no'] ?? '–');
        if (array_key_exists('license_key_enc', $data)) $changes[] = $data['license_key_enc'] === null ? 'Lizenzschlüssel gelöscht' : 'Lizenzschlüssel geändert';
        if ((string)$l['notes'] !== $data['notes']) $changes[] = 'Notiz geändert';
        update('inv_licenses', (int)$l['id'], $data);
        foreach ($changes as $c) inv_lic_log($cid, (int)$l['id'], $c, str_starts_with($c, 'Kosten: ') ? 'cost' : 'event');
        if ($changes) audit('inventory.license_update', $product . ': ' . implode('; ', array_map(fn($c) => mb_substr($c, 0, 60), $changes)), $cid);
        flash('success', $changes ? t('Gespeichert.') : t('Keine Änderungen.'));
        $back();
    }
    if (!$l) abort(400);
    switch ($action) {
        case 'assign':
            $emp = (int)post('employee_id', 0) ? row('SELECT id, first_name, last_name FROM employees WHERE id = ? AND company_id = ?', [(int)post('employee_id', 0), $cid]) : null;
            $dev = !$emp && (int)post('device_id', 0) ? row('SELECT id, inv_no FROM inv_devices WHERE id = ? AND company_id = ?', [(int)post('device_id', 0), $cid]) : null;
            if (!$emp && !$dev) { flash('error', t('Bitte einen Mitarbeiter oder ein Gerät wählen.')); $back(); }
            if ($emp && row('SELECT id FROM inv_license_assign WHERE license_id = ? AND employee_id = ?', [$l['id'], $emp['id']])
                || $dev && row('SELECT id FROM inv_license_assign WHERE license_id = ? AND device_id = ?', [$l['id'], $dev['id']])) {
                flash('error', t('Dieser Platz ist bereits vergeben.'));
                $back();
            }
            $used = (int)row('SELECT COUNT(*) AS n FROM inv_license_assign WHERE license_id = ?', [$l['id']])['n'];
            insert('inv_license_assign', ['company_id' => $cid, 'license_id' => $l['id'], 'employee_id' => $emp['id'] ?? null, 'device_id' => $dev['id'] ?? null,
                'assigned_at' => now(), 'user_id' => current_user()['id']]);
            $who = $emp ? trim($emp['first_name'] . ' ' . $emp['last_name']) : $dev['inv_no'];
            inv_lic_log($cid, (int)$l['id'], 'Platz vergeben an: ' . $who);
            if ($dev) inv_log($cid, (int)$dev['id'], 'Lizenz zugewiesen: ' . $l['product']);
            if ((int)$l['seats'] > 0 && $used + 1 > (int)$l['seats']) flash('error', t('Achtung: Die Lizenz ist jetzt überbelegt ({n} von {s} Plätzen).', ['n' => $used + 1, 's' => (int)$l['seats']]));
            else flash('success', t('Der Platz wurde an {x} vergeben.', ['x' => $who]));
            $back();
        case 'unassign':
            $a = row('SELECT a.*, e.first_name, e.last_name, d.inv_no FROM inv_license_assign a LEFT JOIN employees e ON e.id = a.employee_id LEFT JOIN inv_devices d ON d.id = a.device_id
                WHERE a.id = ? AND a.license_id = ?', [(int)post('assign_id', 0), $l['id']]);
            if ($a) {
                q('DELETE FROM inv_license_assign WHERE id = ?', [$a['id']]);
                $who = $a['employee_id'] ? trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) : (string)($a['inv_no'] ?? '–');
                inv_lic_log($cid, (int)$l['id'], 'Platz freigegeben: ' . $who);
                if ($a['device_id']) inv_log($cid, (int)$a['device_id'], 'Lizenz entfernt: ' . $l['product']);
            }
            $back();
        case 'reveal':
            $plain = decrypt_secret($l['license_key_enc'] ?? null);
            if ($plain === '') { flash('error', t('Es ist kein Lizenzschlüssel gespeichert.')); $back(); }
            inv_lic_log($cid, (int)$l['id'], 'Lizenzschlüssel angezeigt');
            audit('inventory.reveal_license_key', $l['product'], $cid);
            $_GET['id'] = (string)$l['id'];
            $GLOBALS['inv_lic_revealed'] = $plain;
            header('Cache-Control: no-store');
            page_inv_license();
            return;
        case 'cancel':
            $d = inv_date((string)post('cancelled_at')) ?: date('Y-m-d');
            update('inv_licenses', (int)$l['id'], ['status' => 'gekuendigt', 'cancelled_at' => $d, 'updated_at' => now()]);
            inv_lic_log($cid, (int)$l['id'], 'Gekündigt am: ' . fmt_date($d));
            audit('inventory.license_cancel', $l['product'], $cid);
            flash('success', t('Die Lizenz ist als gekündigt markiert.'));
            $back();
        case 'reactivate':
            update('inv_licenses', (int)$l['id'], ['status' => 'aktiv', 'cancelled_at' => '', 'updated_at' => now()]);
            inv_lic_log($cid, (int)$l['id'], 'Wieder aktiv');
            $back();
        case 'end':
            $n = (int)row('SELECT COUNT(*) AS n FROM inv_license_assign WHERE license_id = ?', [$l['id']])['n'];
            q('DELETE FROM inv_license_assign WHERE license_id = ?', [$l['id']]);
            update('inv_licenses', (int)$l['id'], ['status' => 'beendet', 'updated_at' => now()]);
            inv_lic_log($cid, (int)$l['id'], 'Beendet, Plätze freigegeben: ' . $n);
            audit('inventory.license_end', $l['product'], $cid);
            flash('success', t('Die Lizenz ist beendet, {n} Plätze wurden freigegeben.', ['n' => $n]));
            $back();
        case 'upload':
            [$files, $err] = ticket_uploads();
            if ($err) { flash('error', $err); $back(); }
            $dir = inv_files_dir($cid);
            foreach ($files as $fl) {
                $stored = 'L' . $l['id'] . '-' . bin2hex(random_bytes(12)) . '.bin';
                if (!move_uploaded_file($fl['tmp'], $dir . '/' . $stored)) continue;
                @chmod($dir . '/' . $stored, 0640);
                $mime = function_exists('mime_content_type') ? (string)@mime_content_type($dir . '/' . $stored) : 'application/octet-stream';
                insert('inv_files', ['company_id' => $cid, 'device_id' => 0, 'license_id' => $l['id'], 'filename' => $fl['name'], 'mime' => mb_substr($mime, 0, 100),
                    'size' => $fl['size'], 'stored_name' => $stored, 'user_id' => current_user()['id'], 'created_at' => now()]);
                inv_lic_log($cid, (int)$l['id'], 'Anhang hinzugefügt: ' . $fl['name']);
            }
            flash('success', t('Die Dateien wurden hochgeladen.'));
            $back();
        case 'file_delete':
            $fl = row('SELECT * FROM inv_files WHERE id = ? AND license_id = ?', [(int)post('file_id', 0), $l['id']]);
            if ($fl) {
                @unlink(inv_files_dir($cid) . '/' . basename((string)$fl['stored_name']));
                q('DELETE FROM inv_files WHERE id = ?', [$fl['id']]);
                inv_lic_log($cid, (int)$l['id'], 'Anhang gelöscht: ' . $fl['filename']);
            }
            $back();
        case 'delete':
            foreach (rows('SELECT stored_name FROM inv_files WHERE license_id = ?', [$l['id']]) as $fl) @unlink(inv_files_dir($cid) . '/' . basename((string)$fl['stored_name']));
            q('DELETE FROM inv_files WHERE license_id = ?', [$l['id']]);
            q('DELETE FROM inv_log WHERE license_id = ?', [$l['id']]);
            q('DELETE FROM inv_license_assign WHERE license_id = ?', [$l['id']]);
            q('DELETE FROM ct_links WHERE license_id = ? AND company_id = ?', [$l['id'], $cid]);
            q('DELETE FROM inv_licenses WHERE id = ?', [$l['id']]);
            audit('inventory.license_delete', $l['product'], $cid);
            flash('success', t('Die Lizenz „{x}“ wurde gelöscht.', ['x' => $l['product']]));
            iredirect('inv_licenses');
    }
    abort(400);
}
