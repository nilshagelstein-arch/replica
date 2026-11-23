<?php
/**
 * App „Geräte & Lizenzen“ – Stufe 5: Erinnerungen und Übersichten
 *
 * - „Demnächst fällig“: Lizenzende, Kündigungsfrist, Garantieende, überfällige Reparatur, nicht unterschriebene
 *   Protokolle, überbelegte Lizenzen, Mindestbestand unterschritten.
 * - Erinnerungsmails über den Cronjob (und höchstens stündlich bei Seitenaufrufen): je Firma eine Sammelmail an die
 *   Firmen-Admins (Bestand zusätzlich an Gruppen mit „bearbeiten“) und – mit Einsicht der Firma – an den Dienstleister
 *   (mail.admin_recipients). Jeder Punkt wird nur einmal gemeldet (inv_notices).
 * - Kostenübersicht, CSV-Export und -Import für Geräte und Lizenzen.
 */

declare(strict_types=1);

const INV_LEAD_DEFAULT = 60;          // Tage Vorlauf
const INV_UNSIGNED_DAYS = 7;          // Protokoll nicht unterschrieben nach … Tagen
const INV_REMINDER_INTERVAL = 3600;   // Sekunden zwischen zwei Läufen bei Seitenaufrufen
const INV_DUE_LABELS = ['lic_end' => 'Lizenz läuft ab', 'lic_cancel' => 'Kündigungsfrist endet', 'warranty' => 'Garantie läuft ab',
    'repair' => 'Reparatur überfällig', 'unsigned' => 'Protokoll nicht unterschrieben', 'over' => 'Lizenz überbelegt', 'stock' => 'Mindestbestand unterschritten'];

function inv_lead_days(int $cid): int
{
    $v = (int)(row('SELECT inv_lead_days FROM companies WHERE id = ?', [$cid])['inv_lead_days'] ?? 0);
    return $v > 0 ? $v : INV_LEAD_DEFAULT;
}

/**
 * Alle anstehenden Punkte einer Firma.
 * Jeder Punkt: kind, ref (ID), key (für „nur einmal melden“), date, text, route + id für den Link.
 */
function inv_due_items(int $cid): array
{
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime('+' . inv_lead_days($cid) . ' days'));
    $out = [];
    foreach (rows("SELECT * FROM inv_licenses WHERE company_id = ? AND status = 'aktiv'", [$cid]) as $l) {
        if (!(int)$l['auto_renew'] && $l['end_date'] !== '' && $l['end_date'] >= $today && $l['end_date'] <= $until) {
            $out[] = ['kind' => 'lic_end', 'ref' => (int)$l['id'], 'key' => $l['end_date'], 'date' => $l['end_date'], 'text' => $l['product'], 'route' => 'inv_license'];
        }
        $cb = inv_lic_cancel_by($l);
        if ($cb !== '' && $cb >= $today && $cb <= $until && ((int)$l['auto_renew'] ? (int)$l['renew_months'] >= 12 : true)) {
            $out[] = ['kind' => 'lic_cancel', 'ref' => (int)$l['id'], 'key' => $cb, 'date' => $cb, 'text' => $l['product'], 'route' => 'inv_license'];
        }
    }
    foreach (rows("SELECT l.id, l.product, l.seats, COUNT(a.id) AS used FROM inv_licenses l JOIN inv_license_assign a ON a.license_id = l.id
        WHERE l.company_id = ? AND l.seats > 0 AND l.status <> 'beendet' GROUP BY l.id, l.product, l.seats", [$cid]) as $l) {
        if ((int)$l['used'] > (int)$l['seats']) $out[] = ['kind' => 'over', 'ref' => (int)$l['id'], 'key' => 'over', 'date' => $today,
            'text' => $l['product'] . ' (' . (int)$l['used'] . ' / ' . (int)$l['seats'] . ')', 'route' => 'inv_license'];
    }
    foreach (rows("SELECT id, inv_no, manufacturer, model, warranty_until FROM inv_devices WHERE company_id = ? AND status IN ('lager', 'einsatz', 'reparatur')
        AND warranty_until <> '' AND warranty_until >= ? AND warranty_until <= ?", [$cid, $today, $until]) as $d) {
        $out[] = ['kind' => 'warranty', 'ref' => (int)$d['id'], 'key' => $d['warranty_until'], 'date' => $d['warranty_until'],
            'text' => trim($d['inv_no'] . ' ' . $d['manufacturer'] . ' ' . $d['model']), 'route' => 'inv_device'];
    }
    foreach (rows("SELECT id, inv_no, manufacturer, model, repair_until FROM inv_devices WHERE company_id = ? AND status = 'reparatur' AND repair_until <> '' AND repair_until < ?", [$cid, $today]) as $d) {
        $out[] = ['kind' => 'repair', 'ref' => (int)$d['id'], 'key' => $d['repair_until'], 'date' => $d['repair_until'],
            'text' => trim($d['inv_no'] . ' ' . $d['manufacturer'] . ' ' . $d['model']), 'route' => 'inv_device'];
    }
    foreach (rows("SELECT id, kind, employee_name, handover_date, created_at FROM inv_handovers WHERE company_id = ? AND status = 'offen' AND created_at <= ?",
        [$cid, date('Y-m-d H:i:s', strtotime('-' . INV_UNSIGNED_DAYS . ' days'))]) as $h) {
        $out[] = ['kind' => 'unsigned', 'ref' => (int)$h['id'], 'key' => 'x', 'date' => $h['handover_date'],
            'text' => t(INV_HO_KINDS[$h['kind']] ?? $h['kind']) . ' · ' . $h['employee_name'], 'route' => 'inv_handover'];
    }
    foreach (rows("SELECT m.id, m.min_qty, l.name AS location_name, t.name AS type_name,
            (SELECT COUNT(*) FROM inv_devices d WHERE d.company_id = m.company_id AND d.location_id = m.location_id AND d.type_id = m.type_id AND d.status = 'lager') AS n
        FROM inv_min_stock m JOIN inv_locations l ON l.id = m.location_id JOIN inv_types t ON t.id = m.type_id WHERE m.company_id = ? AND m.min_qty > 0", [$cid]) as $m) {
        if ((int)$m['n'] < (int)$m['min_qty']) $out[] = ['kind' => 'stock', 'ref' => (int)$m['id'], 'key' => 'low', 'date' => $today,
            'text' => t($m['type_name']) . ' · ' . $m['location_name'] . ': ' . t('{n} von mindestens {m}', ['n' => (int)$m['n'], 'm' => (int)$m['min_qty']]), 'route' => 'inventory'];
    }
    usort($out, fn($a, $b) => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);
    return $out;
}

/** Erinnerungen aller Firmen prüfen und neue Punkte melden. Gibt Protokollzeilen zurück. */
function inv_reminders_run(): array
{
    $lines = [];
    foreach (rows("SELECT c.id, c.name, c.inv_provider_access FROM companies c JOIN company_apps ca ON ca.company_id = c.id JOIN apps a ON a.id = ca.app_id
        WHERE a.slug = 'inventar' AND a.active = 1 AND c.active = 1") as $co) {
        $cid = (int)$co['id'];
        $items = inv_due_items($cid);
        // Erledigte Zustandsmeldungen (Überbelegung, Bestand) vergessen, damit sie beim nächsten Mal wieder kommen
        $activeStates = [];
        foreach ($items as $i) if (in_array($i['kind'], ['over', 'stock'], true)) $activeStates[$i['kind'] . ':' . $i['ref']] = true;
        foreach (rows("SELECT id, kind, ref_id FROM inv_notices WHERE company_id = ? AND kind IN ('over', 'stock')", [$cid]) as $n) {
            if (!isset($activeStates[$n['kind'] . ':' . $n['ref_id']])) q('DELETE FROM inv_notices WHERE id = ?', [$n['id']]);
        }
        $new = [];
        foreach ($items as $i) {
            if (row('SELECT id FROM inv_notices WHERE company_id = ? AND kind = ? AND ref_id = ? AND ref_key = ?', [$cid, $i['kind'], $i['ref'], $i['key']])) continue;
            insert('inv_notices', ['company_id' => $cid, 'kind' => $i['kind'], 'ref_id' => $i['ref'], 'ref_key' => $i['key'], 'sent_at' => now()]);
            $new[] = $i;
        }
        if (!$new) continue;
        $to = array_column(rows("SELECT email FROM users WHERE company_id = ? AND role = 'firma_admin' AND active = 1", [$cid]), 'email');
        if (array_filter($new, fn($i) => $i['kind'] === 'stock')) {
            $to = array_merge($to, array_column(rows('SELECT DISTINCT u.email FROM users u JOIN user_departments ud ON ud.user_id = u.id
                JOIN inv_group_perms g ON g.department_id = ud.department_id WHERE g.company_id = ? AND g.perm >= 2 AND u.active = 1 AND u.company_id = ?', [$cid, $cid]), 'email'));
        }
        if ((int)$co['inv_provider_access'] === 1) $to = array_merge($to, (array)cfg('mail.admin_recipients', []));
        $to = array_values(array_unique(array_filter(array_map('strtolower', $to))));
        if (!$to) continue;
        $base = rtrim((string)cfg('base_url', ''), '/');
        try {
            send_mail_t($to, function () use ($new, $co, $base, $cid) {
                $body = t("Im Inventar von {company} steht Folgendes an:\n\n", ['company' => $co['name']]);
                foreach ($new as $i) {
                    $body .= '• ' . t(INV_DUE_LABELS[$i['kind']]) . ($i['kind'] === 'over' || $i['kind'] === 'stock' ? '' : ' (' . fmt_date($i['date']) . ')') . ': ' . $i['text'] . "\n  "
                        . $base . '/index.php?r=' . $i['route'] . ($i['route'] !== 'inventory' ? '&id=' . $i['ref'] : '') . '&company=' . $cid . "\n";
                }
                return [t('Geräte & Lizenzen: {n} Punkte stehen an', ['n' => count($new)]), $body . t("\nAlle anstehenden Punkte finden Sie in der App „Geräte & Lizenzen“ unter „Demnächst fällig“.")];
            }, $cid);
            $lines[] = $co['name'] . ': ' . count($new) . ' Erinnerungen an ' . count($to) . ' Empfänger';
        } catch (Throwable $ex) {
            error_log('[kundenportal] Inventar-Erinnerung: ' . $ex->getMessage());
        }
    }
    return $lines;
}

function inv_reminders_tick(): void
{
    try {
        $last = setting_get('inv_reminder_last');
        if ($last !== '' && (int)$last > time() - INV_REMINDER_INTERVAL) return;
        if ($last === '') setting_set('inv_reminder_last', '0');
        if (q('UPDATE settings SET value = ? WHERE name = ? AND value = ?', [(string)time(), 'inv_reminder_last', $last === '' ? '0' : $last])->rowCount() !== 1) return;
        inv_reminders_run();
    } catch (Throwable $ex) {
        error_log('[kundenportal] Inventar-Erinnerung: ' . $ex->getMessage());
    }
}

/** Kasten „Demnächst fällig“ auf der Übersicht */
function inv_due_box(int $cid): void
{
    $items = inv_due_items($cid);
    if (!$items) return; ?>
    <div class="card due-box">
        <h2><?= e(t('Demnächst fällig')) ?> <span class="count"><?= count($items) ?></span></h2>
        <ul class="inv-log">
        <?php foreach (array_slice($items, 0, 20) as $i): ?>
            <li><span class="tag <?= in_array($i['kind'], ['repair', 'over', 'stock'], true) ? 'danger' : 'warn' ?>"><?= e(t(INV_DUE_LABELS[$i['kind']])) ?></span>
                <?php if ($i['route'] === 'inventory'): ?><?= e($i['text']) ?><?php else: ?><a href="<?= e(iurl($i['route'], ['id' => $i['ref']])) ?>"><?= e($i['text']) ?></a><?php endif; ?>
                <?php if (!in_array($i['kind'], ['over', 'stock'], true)): ?><span class="muted small">· <?= fmt_date($i['date']) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
        </ul>
        <?php if (count($items) > 20): ?><p class="muted small"><?= e(t('… und {n} weitere.', ['n' => count($items) - 20])) ?></p><?php endif; ?>
        <p class="muted small"><?= e(t('Vorlauf: {n} Tage. Die Firmen-Admins bekommen zu jedem Punkt einmal eine Erinnerung per E-Mail.', ['n' => inv_lead_days($cid)])) ?></p>
    </div>
<?php }

// ---------------------------------------------------------------------------
// Kostenübersicht
// ---------------------------------------------------------------------------

function inv_can_see_costs(int $cid): bool
{
    $u = current_user();
    if (is_staff_role($u['role'])) return inv_perm($cid) >= INV_PERM_VIEW;
    return $u['role'] === 'firma_admin';
}

function page_inv_costs(): void
{
    $cid = inv_company_id();
    if (!inv_can_see_costs($cid)) abort(403, t('Die Kostenübersicht sehen die Administratoren der Firma.'));
    inv_lic_renew($cid);
    $lics = rows('SELECT * FROM inv_licenses WHERE company_id = ? ORDER BY product', [$cid]);
    $byProduct = [];
    $monthly = 0;
    $oneOff = [];
    foreach ($lics as $l) {
        $m = inv_lic_monthly($l);
        if ($m > 0) { $byProduct[] = ['l' => $l, 'm' => $m]; $monthly += $m; }
        if ($l['cost_interval'] === 'einmalig' && $l['cost_cents'] !== null) $oneOff[] = $l;
    }
    usort($byProduct, fn($a, $b) => $b['m'] <=> $a['m']);
    $byManu = [];
    foreach ($byProduct as $p) { $k = $p['l']['manufacturer'] !== '' ? $p['l']['manufacturer'] : t('ohne Hersteller'); $byManu[$k] = ($byManu[$k] ?? 0) + $p['m']; }
    arsort($byManu);
    $purchases = rows("SELECT SUBSTR(d.purchase_date, 1, 4) AS y, t.name AS type_name, COUNT(*) AS n, SUM(COALESCE(d.price_cents, 0)) AS sum_c
        FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id WHERE d.company_id = ? AND d.purchase_date <> '' GROUP BY SUBSTR(d.purchase_date, 1, 4), t.name ORDER BY y DESC, sum_c DESC", [$cid]);
    $years = [];
    foreach ($purchases as $p) { $years[$p['y']]['rows'][] = $p; $years[$p['y']]['sum'] = ($years[$p['y']]['sum'] ?? 0) + (int)$p['sum_c']; }
    $inUse = row("SELECT COUNT(*) AS n, SUM(COALESCE(price_cents, 0)) AS s FROM inv_devices WHERE company_id = ? AND status IN ('einsatz', 'reparatur')", [$cid]);
    $inStock = row("SELECT COUNT(*) AS n, SUM(COALESCE(price_cents, 0)) AS s FROM inv_devices WHERE company_id = ? AND status = 'lager'", [$cid]);
    // Ausblick 12 Monate: Lizenzenden/Verlängerungen und Garantieenden
    $until = date('Y-m-d', strtotime('+12 months'));
    $today = date('Y-m-d');
    $outlook = [];
    foreach ($lics as $l) {
        if ($l['status'] !== 'aktiv' || $l['end_date'] === '' || $l['end_date'] < $today || $l['end_date'] > $until) continue;
        $outlook[] = ['date' => $l['end_date'], 'what' => (int)$l['auto_renew'] ? t('Verlängerung') : t('Lizenzende'), 'text' => $l['product'],
            'cost' => $l['cost_cents'] !== null && $l['cost_interval'] === 'jahr' ? (int)$l['cost_cents'] : null, 'route' => 'inv_license', 'id' => $l['id']];
    }
    foreach (rows("SELECT id, inv_no, manufacturer, model, warranty_until FROM inv_devices WHERE company_id = ? AND status IN ('lager', 'einsatz', 'reparatur')
        AND warranty_until >= ? AND warranty_until <= ?", [$cid, $today, $until]) as $d) {
        $outlook[] = ['date' => $d['warranty_until'], 'what' => t('Garantieende'), 'text' => trim($d['inv_no'] . ' ' . $d['manufacturer'] . ' ' . $d['model']), 'cost' => null, 'route' => 'inv_device', 'id' => $d['id']];
    }
    usort($outlook, fn($a, $b) => strcmp($a['date'], $b['date']));

    render_page(t('Kostenübersicht') . ' · ' . t('Geräte & Lizenzen'), function () use ($cid, $byProduct, $monthly, $byManu, $oneOff, $years, $inUse, $inStock, $outlook) {
        echo company_switcher($cid, 'inv_costs');
        inv_tabs('inv_costs'); ?>
        <div class="tiles">
            <div class="tile"><strong><?= e(inv_fmt_price($monthly)) ?></strong><span><?= e(t('Lizenzen je Monat')) ?></span></div>
            <div class="tile"><strong><?= e(inv_fmt_price($monthly * 12)) ?></strong><span><?= e(t('Lizenzen je Jahr')) ?></span></div>
            <div class="tile"><strong><?= (int)$inUse['n'] ?></strong><span><?= e(t('Geräte im Einsatz · Anschaffung {x}', ['x' => inv_fmt_price((int)$inUse['s'])])) ?></span></div>
            <div class="tile"><strong><?= (int)$inStock['n'] ?></strong><span><?= e(t('Geräte an Lager · Anschaffung {x}', ['x' => inv_fmt_price((int)$inStock['s'])])) ?></span></div>
        </div>
        <div class="grid2">
            <div class="card">
                <h2><?= e(t('Laufende Lizenzkosten')) ?></h2>
                <table class="table"><thead><tr><th><?= e(t('Produkt')) ?></th><th class="num"><?= e(t('je Monat')) ?></th><th class="num"><?= e(t('je Jahr')) ?></th></tr></thead><tbody>
                <?php foreach ($byProduct as $p): ?><tr><td><a href="<?= e(iurl('inv_license', ['id' => $p['l']['id']])) ?>"><?= e($p['l']['product']) ?></a></td><td class="num"><?= e(inv_fmt_price($p['m'])) ?></td><td class="num"><?= e(inv_fmt_price($p['m'] * 12)) ?></td></tr><?php endforeach; ?>
                <?php if (!$byProduct): ?><tr><td colspan="3" class="muted"><?= e(t('Keine laufenden Lizenzkosten erfasst.')) ?></td></tr><?php else: ?>
                <tr class="sum"><td><strong><?= e(t('Summe')) ?></strong></td><td class="num"><strong><?= e(inv_fmt_price($monthly)) ?></strong></td><td class="num"><strong><?= e(inv_fmt_price($monthly * 12)) ?></strong></td></tr><?php endif; ?>
                </tbody></table>
                <?php if ($byManu): ?><h3><?= e(t('Nach Hersteller')) ?></h3>
                <table class="table"><tbody><?php foreach ($byManu as $k => $v): ?><tr><td><?= e($k) ?></td><td class="num"><?= e(inv_fmt_price($v)) ?> <span class="muted small"><?= e(t('je Monat')) ?></span></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
                <?php if ($oneOff): ?><h3><?= e(t('Einmalige Lizenzkosten')) ?></h3>
                <table class="table"><tbody><?php foreach ($oneOff as $l): ?><tr><td><?= e($l['product']) ?></td><td class="num"><?= e(inv_fmt_price((int)$l['cost_cents'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
            </div>
            <div class="card">
                <h2><?= e(t('Gerätekäufe je Jahr')) ?></h2>
                <?php if (!$years): ?><p class="muted"><?= e(t('Keine Kaufdaten erfasst.')) ?></p><?php endif; ?>
                <?php foreach ($years as $y => $g): ?>
                    <h3><?= e((string)$y) ?> · <?= e(inv_fmt_price((int)$g['sum'])) ?></h3>
                    <table class="table"><tbody><?php foreach ($g['rows'] as $p): ?><tr><td><?= e($p['type_name'] ? t($p['type_name']) : '–') ?></td><td class="num"><?= (int)$p['n'] ?> ×</td><td class="num"><?= e(inv_fmt_price((int)$p['sum_c'])) ?></td></tr><?php endforeach; ?></tbody></table>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card">
            <h2><?= e(t('Ausblick: nächste 12 Monate')) ?></h2>
            <p class="muted small"><?= e(t('Verlängerungen, auslaufende Lizenzen und Garantien – Grundlage für Angebote und Budgetplanung.')) ?></p>
            <table class="table"><thead><tr><th><?= e(t('Datum')) ?></th><th><?= e(t('Was')) ?></th><th><?= e(t('Betrifft')) ?></th><th class="num"><?= e(t('Jahreskosten')) ?></th></tr></thead><tbody>
            <?php foreach ($outlook as $o): ?><tr><td><?= fmt_date($o['date']) ?></td><td><?= e($o['what']) ?></td><td><a href="<?= e(iurl($o['route'], ['id' => $o['id']])) ?>"><?= e($o['text']) ?></a></td><td class="num"><?= $o['cost'] !== null ? e(inv_fmt_price($o['cost'])) : '' ?></td></tr><?php endforeach; ?>
            <?php if (!$outlook): ?><tr><td colspan="4" class="muted"><?= e(t('Nichts in den nächsten 12 Monaten.')) ?></td></tr><?php endif; ?>
            </tbody></table>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// CSV-Export und -Import
// ---------------------------------------------------------------------------

const INV_DEVICE_COLS = ['inv_no' => 'Inventarnummer', 'type' => 'Typ', 'manufacturer' => 'Hersteller', 'model' => 'Modell', 'serial' => 'Seriennummer',
    'status' => 'Status', 'location' => 'Standort', 'room' => 'Raum', 'employee_no' => 'Personalnummer', 'employee_email' => 'E-Mail Mitarbeiter',
    'purchase_date' => 'Kaufdatum', 'price' => 'Preis', 'supplier' => 'Lieferant', 'warranty_until' => 'Garantie bis', 'os' => 'Betriebssystem',
    'ip' => 'IP-Adresse', 'mac' => 'MAC-Adresse', 'phone_no' => 'Telefonnummer', 'sim_no' => 'SIM-Nummer', 'mobile_contract' => 'Mobilfunkvertrag', 'notes' => 'Notiz'];
const INV_LICENSE_COLS = ['product' => 'Produkt', 'manufacturer' => 'Hersteller', 'kind' => 'Art', 'seats' => 'Plätze', 'contract_no' => 'Vertragsnummer',
    'supplier' => 'Lieferant', 'start_date' => 'Beginn', 'end_date' => 'Ende', 'notice_months' => 'Kündigungsfrist (Monate)', 'auto_renew' => 'Automatische Verlängerung',
    'renew_months' => 'Verlängerung (Monate)', 'cost' => 'Kosten', 'cost_interval' => 'Zeitraum', 'notes' => 'Notiz'];

function inv_csv_out(string $name, array $head, array $rowsOut): never
{
    // ohne Kostenrecht: Spalten Preis/Kosten/Zeitraum weglassen (ein Re-Import ändert sie dann nicht)
    if (!empty($GLOBALS['inv_cid']) && !inv_can_see_costs((int)$GLOBALS['inv_cid'])) {
        $drop = array_keys(array_intersect($head, [t('Preis'), t('Kosten'), t('Zeitraum')]));
        foreach ($drop as $i) unset($head[$i]);
        foreach ($rowsOut as &$r) foreach ($drop as $i) unset($r[$i]);
        unset($r);
        $head = array_values($head);
        $rowsOut = array_map('array_values', $rowsOut);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $head, ';', '"', '');
    foreach ($rowsOut as $r) fputcsv($out, array_map('csv_cell_safe', $r), ';', '"', '');
    fclose($out);
    exit;
}

function page_inv_export(): void
{
    $cid = inv_company_id();
    if (inv_perm($cid) === INV_PERM_NONE) abort(403);
    $what = (string)($_GET['what'] ?? 'devices');
    audit('inventory.export', $what, $cid);
    if ($what === 'licenses') {
        $rowsOut = [];
        foreach (rows('SELECT * FROM inv_licenses WHERE company_id = ? ORDER BY product', [$cid]) as $l) {
            $rowsOut[] = [$l['product'], $l['manufacturer'], t(INV_LIC_KINDS[$l['kind']] ?? $l['kind']), (int)$l['seats'], $l['contract_no'], $l['supplier'],
                $l['start_date'] !== '' ? fmt_date($l['start_date']) : '', $l['end_date'] !== '' ? fmt_date($l['end_date']) : '',
                $l['notice_unit'] === 'months' ? (int)$l['notice_value'] : '', (int)$l['auto_renew'] ? t('ja') : t('nein'), (int)$l['renew_months'],
                $l['cost_cents'] !== null ? number_format((int)$l['cost_cents'] / 100, 2, ',', '') : '', t(INV_LIC_INTERVALS[$l['cost_interval']] ?? ''), (string)$l['notes']];
        }
        inv_csv_out('lizenzen', array_map('t', array_values(INV_LICENSE_COLS)), $rowsOut);
    }
    if ($what === 'assignments') {
        $rowsOut = [];
        foreach (rows('SELECT l.product, e.employee_no, e.first_name, e.last_name, d.inv_no, a.assigned_at FROM inv_license_assign a JOIN inv_licenses l ON l.id = a.license_id
            LEFT JOIN employees e ON e.id = a.employee_id LEFT JOIN inv_devices d ON d.id = a.device_id WHERE a.company_id = ? ORDER BY l.product, e.last_name', [$cid]) as $a) {
            $rowsOut[] = [$a['product'], (string)$a['employee_no'], trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')), (string)$a['inv_no'], fmt_date(substr($a['assigned_at'], 0, 10))];
        }
        inv_csv_out('lizenzvergabe', [t('Produkt'), t('Personalnummer'), t('Mitarbeiter'), t('Gerät'), t('Vergeben am')], $rowsOut);
    }
    $rowsOut = [];
    foreach (rows('SELECT d.*, t.name AS type_name, l.name AS location_name, e.employee_no, e.email AS employee_email FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id
        LEFT JOIN inv_locations l ON l.id = d.location_id LEFT JOIN employees e ON e.id = d.employee_id WHERE d.company_id = ? ORDER BY d.inv_no', [$cid]) as $d) {
        $rowsOut[] = [$d['inv_no'], $d['type_name'] ? t($d['type_name']) : '', $d['manufacturer'], $d['model'], $d['serial'], t(INV_STATUSES[$d['status']] ?? $d['status']),
            (string)$d['location_name'], $d['room'], (string)$d['employee_no'], (string)$d['employee_email'], $d['purchase_date'] !== '' ? fmt_date($d['purchase_date']) : '',
            $d['price_cents'] !== null ? number_format((int)$d['price_cents'] / 100, 2, ',', '') : '', $d['supplier'], $d['warranty_until'] !== '' ? fmt_date($d['warranty_until']) : '',
            $d['os'], $d['ip'], $d['mac'], $d['phone_no'], $d['sim_no'], $d['mobile_contract'], (string)$d['notes']];
    }
    inv_csv_out('geraete', array_map('t', array_values(INV_DEVICE_COLS)), $rowsOut);
}

/** Datum aus CSV: 31.12.2026, 31/12/2026 oder 2026-12-31 */
function inv_csv_date(string $s): string
{
    $s = trim($s);
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})$/', $s, $m)) {
        $y = (int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3];
        return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : '';
    }
    return inv_date($s);
}

/** Spaltenköpfe einer CSV den Feldern zuordnen (deutsch, englisch, französisch oder Feldname) */
function inv_csv_map(array $head, array $cols): array
{
    $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string)$s)));
    $alias = [];
    foreach ($cols as $k => $label) {
        $alias[$norm($k)] = $k;
        $alias[$norm($label)] = $k;
        foreach (['en', 'fr'] as $lang) with_lang($lang, function () use (&$alias, $norm, $k, $label) { $alias[$norm(t($label))] = $k; });
    }
    $alias += ['nr' => 'inv_no', 'nummer' => 'inv_no', 'inventarnr' => 'inv_no', 'sn' => 'serial', 'serial' => 'serial', 'seriennr' => 'serial', 'mac' => 'mac', 'ip' => 'ip',
        'email' => 'employee_email', 'mitarbeiter' => 'employee_email', 'preis' => 'price', 'kosten' => 'cost'];
    $map = [];
    foreach ($head as $i => $h) if (isset($alias[$norm($h)]) && isset($cols[$alias[$norm($h)]]) && !in_array($alias[$norm($h)], $map, true)) $map[$i] = $alias[$norm($h)];
    return $map;
}

function page_inv_import(): void
{
    $cid = inv_company_id();
    if (inv_perm($cid) < INV_PERM_EDIT) abort(403);
    $what = (string)($_GET['what'] ?? post('what', 'devices')) === 'licenses' ? 'licenses' : 'devices';
    $cols = $what === 'licenses' ? INV_LICENSE_COLS : INV_DEVICE_COLS;
    $result = null;
    $preview = null;
    if (is_post()) {
        require_post_csrf();
        $f = $_FILES['csv'] ?? null;
        $raw = '';
        if (post('stage') === 'import') {
            $raw = (string)($_SESSION['inv_import'][$what] ?? '');
        } elseif ($f && (int)$f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']) && (int)$f['size'] <= 5 * 1024 * 1024) {
            $raw = (string)file_get_contents($f['tmp_name']);
        }
        if ($raw === '') { flash('error', t('Bitte eine CSV-Datei auswählen (höchstens 5 MB).')); iredirect('inv_import', ['what' => $what]); }
        $data = csv_read($raw);
        $head = array_shift($data) ?? [];
        $map = inv_csv_map($head, $cols);
        $need = $what === 'licenses' ? 'product' : 'type';
        if (!in_array($need, $map, true)) {
            flash('error', t('Die Spalte „{x}“ fehlt. Bitte die erste Zeile mit den Spaltennamen prüfen.', ['x' => t($cols[$need])]));
            iredirect('inv_import', ['what' => $what]);
        }
        $rowsIn = [];
        foreach (array_slice($data, 0, 2000) as $line) {
            $r = [];
            foreach ($map as $i => $k) $r[$k] = trim((string)($line[$i] ?? ''));
            $rowsIn[] = $r;
        }
        if (post('stage') !== 'import') {
            $_SESSION['inv_import'][$what] = $raw;
            $preview = ['map' => $map, 'head' => $head, 'rows' => $rowsIn, 'total' => count($data)];
        } else {
            unset($_SESSION['inv_import'][$what]);
            $result = $what === 'licenses' ? inv_import_licenses($cid, $rowsIn) : inv_import_devices($cid, $rowsIn);
            audit('inventory.import_' . $what, $result['ok'] . ' / ' . count($rowsIn), $cid);
        }
    }

    render_page(t('Import') . ' · ' . t('Geräte & Lizenzen'), function () use ($cid, $what, $cols, $preview, $result) { ?>
        <p><a href="<?= e(iurl($what === 'licenses' ? 'inv_licenses' : 'inventory')) ?>">← <?= e(t('Geräte & Lizenzen')) ?></a></p>
        <nav class="type-tabs">
            <a class="<?= $what === 'devices' ? 'active' : '' ?>" href="<?= e(iurl('inv_import', ['what' => 'devices'])) ?>"><?= e(t('Geräte importieren')) ?></a>
            <a class="<?= $what === 'licenses' ? 'active' : '' ?>" href="<?= e(iurl('inv_import', ['what' => 'licenses'])) ?>"><?= e(t('Lizenzen importieren')) ?></a>
        </nav>
        <?php if ($result): ?>
            <div class="card">
                <h2><?= e(t('Ergebnis')) ?></h2>
                <p><?= e(t('{n} Einträge angelegt, {u} aktualisiert, {s} übersprungen.', ['n' => $result['ok'], 'u' => $result['upd'], 's' => count($result['errors'])])) ?></p>
                <?php if ($result['errors']): ?><ul class="small"><?php foreach (array_slice($result['errors'], 0, 50) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <p><a class="btn" href="<?= e(iurl($what === 'licenses' ? 'inv_licenses' : 'inventory')) ?>"><?= e(t('Zur Übersicht')) ?></a></p>
            </div>
        <?php elseif ($preview): ?>
            <div class="card">
                <h2><?= e(t('Vorschau')) ?></h2>
                <p><?= e(t('{n} Zeilen erkannt. Zugeordnete Spalten:', ['n' => $preview['total']])) ?>
                    <?php foreach ($preview['map'] as $i => $k): ?><span class="tag"><?= e($preview['head'][$i]) ?> → <?= e(t($cols[$k])) ?></span> <?php endforeach; ?></p>
                <?php $ignored = array_diff_key($preview['head'], $preview['map']); if ($ignored): ?><p class="muted small"><?= e(t('Nicht erkannt und ignoriert:')) ?> <?= e(implode(', ', $ignored)) ?></p><?php endif; ?>
                <div class="table-scroll"><table class="table small"><thead><tr><?php foreach ($preview['map'] as $k): ?><th><?= e(t($cols[$k])) ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach (array_slice($preview['rows'], 0, 10) as $r): ?><tr><?php foreach ($preview['map'] as $k): ?><td><?= e($r[$k] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
                </tbody></table></div>
                <form method="post" action="<?= e(iurl('inv_import', ['what' => $what])) ?>"><?= csrf_field() ?><input type="hidden" name="stage" value="import"><input type="hidden" name="what" value="<?= e($what) ?>">
                    <button class="btn primary"><?= e(t('Jetzt importieren')) ?></button> <a class="btn" href="<?= e(iurl('inv_import', ['what' => $what])) ?>"><?= e(t('Abbrechen')) ?></a></form>
            </div>
        <?php else: ?>
            <form method="post" action="<?= e(iurl('inv_import', ['what' => $what])) ?>" enctype="multipart/form-data" class="form card">
                <?= csrf_field() ?><input type="hidden" name="what" value="<?= e($what) ?>">
                <p><?= e(t('CSV-Datei mit einer Kopfzeile (Trennzeichen ; oder ,), z. B. aus Excel „CSV UTF-8“. Erkannte Spalten:')) ?></p>
                <p class="small"><?php foreach ($cols as $k => $l): ?><span class="tag"><?= e(t($l)) ?></span> <?php endforeach; ?></p>
                <p class="muted small"><?= e($what === 'licenses'
                    ? t('Pflicht ist „Produkt“. Gibt es ein Produkt mit gleicher Vertragsnummer schon, wird es aktualisiert. Art: Abo, Kauflizenz oder Wartung; Zeitraum: Monat, Jahr oder einmalig.')
                    : t('Pflicht ist „Typ“. Unbekannte Typen und Standorte werden angelegt. Gibt es die Inventarnummer schon, wird das Gerät aktualisiert. Mitarbeiter werden über Personalnummer oder E-Mail zugeordnet. Ohne Inventarnummer wird automatisch nummeriert.')) ?></p>
                <p><a href="<?= e(iurl('inv_export', ['what' => $what])) ?>"><?= e(t('Aktuellen Bestand als Vorlage exportieren')) ?></a></p>
                <label><?= e(t('CSV-Datei')) ?> <input type="file" name="csv" accept=".csv,text/csv" required></label>
                <button class="btn primary"><?= e(t('Vorschau anzeigen')) ?></button>
            </form>
        <?php endif; ?>
    <?php });
}

function inv_import_devices(int $cid, array $rowsIn): array
{
    $ok = 0; $upd = 0; $errors = [];
    $types = array_change_key_case(array_column(rows('SELECT id, name FROM inv_types WHERE company_id = ?', [$cid]), 'id', 'name'));
    $locs = array_change_key_case(array_column(rows('SELECT id, name FROM inv_locations WHERE company_id = ?', [$cid]), 'id', 'name'));
    $statusMap = [];
    foreach (INV_STATUSES as $k => $l) { $statusMap[mb_strtolower($k)] = $k; $statusMap[mb_strtolower($l)] = $k; foreach (['en', 'fr'] as $lg) with_lang($lg, function () use (&$statusMap, $k, $l) { $statusMap[mb_strtolower(t($l))] = $k; }); }
    foreach ($rowsIn as $n => $r) {
        $line = $n + 2;
        $tname = trim($r['type'] ?? '');
        if ($tname === '') { $errors[] = t('Zeile {n}: Typ fehlt.', ['n' => $line]); continue; }
        $tk = mb_strtolower($tname);
        if (!isset($types[$tk])) {
            foreach (INV_TYPES_DEFAULT as $def) foreach (['en', 'fr'] as $lg) with_lang($lg, function () use (&$types, $tk, $def) { if (mb_strtolower(t($def)) === $tk && isset($types[mb_strtolower($def)])) $types[$tk] = $types[mb_strtolower($def)]; });
        }
        if (!isset($types[$tk])) $types[$tk] = insert('inv_types', ['company_id' => $cid, 'name' => mb_substr($tname, 0, 120), 'sort' => 900, 'active' => 1]);
        $locId = null;
        if (($ln = trim((string)($r['location'] ?? ''))) !== '') {
            $lk = mb_strtolower($ln);
            if (!isset($locs[$lk])) $locs[$lk] = insert('inv_locations', ['company_id' => $cid, 'name' => mb_substr($ln, 0, 190), 'address' => '', 'is_store' => 1, 'sort' => 900, 'active' => 1]);
            $locId = $locs[$lk];
        }
        $emp = null;
        if (($en = trim($r['employee_no'] ?? '')) !== '') $emp = row('SELECT id FROM employees WHERE company_id = ? AND employee_no = ?', [$cid, $en]);
        if (!$emp && ($em = trim($r['employee_email'] ?? '')) !== '') $emp = row('SELECT id FROM employees WHERE company_id = ? AND LOWER(email) = ?', [$cid, mb_strtolower($em)]);
        $status = $statusMap[mb_strtolower(trim((string)($r['status'] ?? '')))] ?? null;
        // Beim Aktualisieren werden nur Spalten übernommen, die in der Datei vorkommen
        $data = ['type_id' => $types[$tk], 'updated_at' => now()];
        if ($status !== null) $data['status'] = $status;
        if (array_key_exists('location', $r)) $data['location_id'] = $locId;
        if (array_key_exists('employee_no', $r) || array_key_exists('employee_email', $r)) $data['employee_id'] = $emp['id'] ?? null;
        foreach (['manufacturer' => 120, 'model' => 190, 'serial' => 120, 'room' => 120, 'supplier' => 190, 'os' => 120, 'ip' => 64, 'mac' => 64, 'phone_no' => 60, 'sim_no' => 60, 'mobile_contract' => 190, 'notes' => 5000] as $k => $max) {
            if (array_key_exists($k, $r)) $data[$k] = mb_substr($r[$k], 0, $max);
        }
        if (isset($data['mac'])) $data['mac'] = strtoupper($data['mac']);
        foreach (['purchase_date', 'warranty_until'] as $k) if (array_key_exists($k, $r)) $data[$k] = inv_csv_date($r[$k]);
        if (array_key_exists('price', $r) && inv_can_see_costs($cid)) $data['price_cents'] = inv_parse_price($r['price']);
        $invNo = mb_substr(trim($r['inv_no'] ?? ''), 0, 60);
        $existing = $invNo !== '' ? row('SELECT id FROM inv_devices WHERE company_id = ? AND LOWER(inv_no) = LOWER(?)', [$cid, $invNo]) : null;
        if ($existing) {
            update('inv_devices', (int)$existing['id'], $data);
            inv_log($cid, (int)$existing['id'], 'Per CSV-Import aktualisiert');
            $upd++;
        } else {
            if ($invNo === '') $invNo = inv_next_number($cid);
            $data += ['status' => !empty($data['employee_id']) ? 'einsatz' : 'lager', 'location_id' => null, 'employee_id' => null];
            $id = insert('inv_devices', $data + ['company_id' => $cid, 'inv_no' => $invNo, 'created_by' => current_user()['id'], 'created_at' => now()]
                + array_fill_keys(['manufacturer', 'model', 'serial', 'room', 'supplier', 'os', 'ip', 'mac', 'phone_no', 'sim_no', 'mobile_contract', 'purchase_date', 'warranty_until'], ''));
            inv_log($cid, $id, 'Gerät angelegt: ' . $invNo . ' (CSV-Import)');
            $ok++;
        }
    }
    return ['ok' => $ok, 'upd' => $upd, 'errors' => $errors];
}

function inv_import_licenses(int $cid, array $rowsIn): array
{
    $ok = 0; $upd = 0; $errors = [];
    $kindMap = ['abo' => 'abo', 'subscription' => 'abo', 'abonnement' => 'abo', 'kauf' => 'kauf', 'kauflizenz' => 'kauf', 'perpetual licence' => 'kauf', 'licence perpétuelle' => 'kauf',
        'wartung' => 'wartung', 'support' => 'wartung', 'wartung / support' => 'wartung', 'maintenance / support' => 'wartung', 'maintenance' => 'wartung'];
    $intMap = ['monat' => 'monat', 'je monat' => 'monat', 'monatlich' => 'monat', 'per month' => 'monat', 'month' => 'monat', 'par mois' => 'monat', 'mois' => 'monat',
        'jahr' => 'jahr', 'je jahr' => 'jahr', 'jährlich' => 'jahr', 'per year' => 'jahr', 'year' => 'jahr', 'par an' => 'jahr', 'an' => 'jahr',
        'einmalig' => 'einmalig', 'one-off' => 'einmalig', 'unique' => 'einmalig'];
    $yes = fn($s) => in_array(mb_strtolower(trim($s)), ['1', 'ja', 'j', 'x', 'yes', 'y', 'oui', 'true'], true);
    foreach ($rowsIn as $n => $r) {
        $line = $n + 2;
        $product = mb_substr(trim($r['product'] ?? ''), 0, 190);
        if ($product === '') { $errors[] = t('Zeile {n}: Produkt fehlt.', ['n' => $line]); continue; }
        $data = ['product' => $product, 'updated_at' => now()];
        foreach (['manufacturer' => 120, 'contract_no' => 120, 'supplier' => 190, 'notes' => 5000] as $k => $max) if (array_key_exists($k, $r)) $data[$k] = mb_substr($r[$k], 0, $max);
        if (array_key_exists('kind', $r)) $data['kind'] = $kindMap[mb_strtolower(trim($r['kind']))] ?? 'abo';
        if (array_key_exists('seats', $r)) $data['seats'] = max(0, min(100000, (int)$r['seats']));
        foreach (['start_date', 'end_date'] as $k) if (array_key_exists($k, $r)) $data[$k] = inv_csv_date($r[$k]);
        if (array_key_exists('notice_months', $r)) { $data['notice_value'] = max(0, min(999, (int)$r['notice_months'])); $data['notice_unit'] = 'months'; }
        if (array_key_exists('auto_renew', $r)) $data['auto_renew'] = $yes($r['auto_renew']) ? 1 : 0;
        if (array_key_exists('renew_months', $r) && (int)$r['renew_months'] > 0) $data['renew_months'] = min(120, (int)$r['renew_months']);
        if (array_key_exists('cost', $r) && inv_can_see_costs($cid)) $data['cost_cents'] = inv_parse_price($r['cost']);
        if (array_key_exists('cost_interval', $r) && inv_can_see_costs($cid)) $data['cost_interval'] = $intMap[mb_strtolower(trim($r['cost_interval']))] ?? 'monat';
        $contract = trim($r['contract_no'] ?? '');
        $existing = $contract !== '' ? row('SELECT id FROM inv_licenses WHERE company_id = ? AND product = ? AND contract_no = ?', [$cid, $product, $contract]) : null;
        if ($existing) {
            update('inv_licenses', (int)$existing['id'], $data);
            inv_lic_log($cid, (int)$existing['id'], 'Per CSV-Import aktualisiert');
            $upd++;
        } else {
            $id = insert('inv_licenses', $data + ['company_id' => $cid, 'status' => 'aktiv', 'created_by' => current_user()['id'], 'created_at' => now()]);
            inv_lic_log($cid, $id, 'Lizenz angelegt: ' . $product . ' (CSV-Import)');
            $ok++;
        }
    }
    return ['ok' => $ok, 'upd' => $upd, 'errors' => $errors];
}
