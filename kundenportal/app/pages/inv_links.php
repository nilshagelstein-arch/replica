<?php
/**
 * App „Geräte & Lizenzen“ – Stufe 4: Verknüpfungen
 *
 * - „Meine Geräte“: jeder Benutzer der Firma sieht die Geräte und Lizenzen des Mitarbeiters mit seiner
 *   E-Mail-Adresse (Mitarbeiterliste), unterschreibt eigene Übergabeprotokolle und meldet Defekte als Ticket.
 * - Tickets: betroffenes Gerät beim Anlegen (tickets.device_id), Tickets je Gerät.
 * - On-/Offboarding: Karte im Auftrag mit Ausgabe aus dem Lager und Lizenzplätzen bzw. Rückgabeliste.
 */

declare(strict_types=1);

/** Mitarbeiter-Datensatz des angemeldeten Benutzers (gleiche E-Mail-Adresse in der Mitarbeiterliste der Firma) */
function inv_my_employee(int $cid): ?array
{
    static $cache = [];
    $u = current_user();
    if (!$u || is_staff_role($u['role']) || (int)($u['company_id'] ?? 0) !== $cid || trim((string)$u['email']) === '') return null;
    $key = $cid . ':' . (int)$u['id'];
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = row("SELECT * FROM employees WHERE company_id = ? AND LOWER(email) = ? AND status <> 'ausgeschieden' ORDER BY id DESC LIMIT 1",
            [$cid, mb_strtolower(trim((string)$u['email']))]) ?: null;
    }
    return $cache[$key];
}

/** Darf der Benutzer ein Ticket zu diesem Gerät melden (App Tickets freigeschaltet, mindestens eine Queue) */
function inv_can_report(int $cid): bool
{
    $u = current_user();
    if (!$u || is_staff_role($u['role']) || $u['role'] === 'dienstleister') return false;
    return company_has_app($cid, 'tickets') && (bool)row('SELECT id FROM ticket_queues WHERE company_id = ? AND active = 1 LIMIT 1', [$cid]);
}

/** Geräte, die im Ticket-Formular zur Auswahl stehen: eigene; für Bearbeiter und den Dienstleister alle aktiven */
function inv_ticket_devices(int $cid, bool $all): array
{
    if (!company_has_app($cid, INV_APP)) return [];
    if ($all) {
        return rows("SELECT d.id, d.inv_no, d.manufacturer, d.model, t.name AS type_name FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id
            WHERE d.company_id = ? AND d.status IN ('lager', 'einsatz', 'reparatur') ORDER BY d.inv_no", [$cid]);
    }
    $emp = inv_my_employee($cid);
    if (!$emp) return [];
    return rows("SELECT d.id, d.inv_no, d.manufacturer, d.model, t.name AS type_name FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id
        WHERE d.company_id = ? AND d.employee_id = ? AND d.status IN ('einsatz', 'reparatur') ORDER BY d.inv_no", [$cid, $emp['id']]);
}

// ---------------------------------------------------------------------------
// Meine Geräte
// ---------------------------------------------------------------------------

function page_inv_my(): void
{
    $cid = inv_company_id();
    $emp = inv_my_employee($cid);
    $perm = inv_perm($cid);
    $devices = $emp ? rows("SELECT d.*, t.name AS type_name, l.name AS location_name FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id
        LEFT JOIN inv_locations l ON l.id = d.location_id WHERE d.company_id = ? AND d.employee_id = ? AND d.status IN ('einsatz', 'reparatur') ORDER BY d.inv_no", [$cid, $emp['id']]) : [];
    $licenses = $emp ? rows("SELECT l.id, l.product, l.manufacturer, l.kind, l.end_date, l.status, l.notice_value, l.notice_unit, l.auto_renew, l.renew_months,
            CASE WHEN l.license_key_enc IS NULL OR l.license_key_enc = '' THEN 0 ELSE 1 END AS has_key
        FROM inv_license_assign a JOIN inv_licenses l ON l.id = a.license_id WHERE a.employee_id = ? AND a.company_id = ? ORDER BY l.product", [$emp['id'], $cid]) : [];
    $open = $emp ? rows("SELECT * FROM inv_handovers WHERE company_id = ? AND employee_id = ? AND status = 'offen' ORDER BY id DESC", [$cid, $emp['id']]) : [];
    $done = $emp ? rows("SELECT * FROM inv_handovers WHERE company_id = ? AND employee_id = ? AND status = 'unterschrieben' ORDER BY id DESC LIMIT 20", [$cid, $emp['id']]) : [];
    $report = inv_can_report($cid);
    $showKeys = (int)(inv_company($cid)['inv_my_keys'] ?? 0) === 1;
    $revealed = [];
    if (is_post()) {
        require_post_csrf();
        if (!$emp || !$showKeys || post('action') !== 'reveal_key') abort(403);
        $lid = (int)post('license_id', 0);
        $l = null;
        foreach ($licenses as $x) if ((int)$x['id'] === $lid) $l = $x;
        if (!$l) abort(404);
        $plain = decrypt_secret(row('SELECT license_key_enc FROM inv_licenses WHERE id = ? AND company_id = ?', [$lid, $cid])['license_key_enc'] ?? null);
        if ($plain !== '') {
            inv_lic_log($cid, $lid, 'Lizenzschlüssel angezeigt (Meine Geräte: ' . trim($emp['first_name'] . ' ' . $emp['last_name']) . ')');
            audit('inventory.reveal_license_key_own', $l['product'], $cid);
            $revealed[$lid] = $plain;
            header('Cache-Control: no-store');
        }
    }

    render_page(t('Meine Geräte'), function () use ($cid, $emp, $perm, $devices, $licenses, $open, $done, $report, $showKeys, $revealed) {
        if ($perm > INV_PERM_NONE) inv_tabs('inv_my');
        if (!$emp): ?>
            <div class="alert info"><?= e(t('Ihrem Konto ist kein Eintrag in der Mitarbeiterliste zugeordnet. Die Zuordnung erfolgt über die E-Mail-Adresse – bitte wenden Sie sich an die Administratoren Ihrer Firma.')) ?></div>
        <?php return; endif; ?>
        <?php if ($open): ?>
        <div class="alert info"><?= e(t('Bitte unterschreiben Sie Ihr Übergabeprotokoll:')) ?>
            <?php foreach ($open as $h): ?> <a href="<?= e(iurl('inv_handover', ['id' => $h['id']])) ?>"><?= e(t(INV_HO_KINDS[$h['kind']])) ?> · <?= fmt_date($h['handover_date']) ?></a><?php endforeach; ?></div>
        <?php endif; ?>
        <div class="card">
            <h2><?= e(t('Geräte')) ?></h2>
            <?php if (!$devices): ?><p class="muted"><?= e(t('Ihnen sind derzeit keine Geräte zugeordnet.')) ?></p><?php else: ?>
            <table class="table">
                <thead><tr><th><?= e(t('Nummer')) ?></th><th><?= e(t('Gerät')) ?></th><th><?= e(t('Seriennummer')) ?></th><th><?= e(t('Status')) ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($devices as $d): ?>
                    <tr><td><?= $perm > INV_PERM_NONE ? '<a href="' . e(iurl('inv_device', ['id' => $d['id']])) . '">' . e($d['inv_no']) . '</a>' : e($d['inv_no']) ?></td>
                        <td><?= e(inv_device_label($d)) ?><?= $d['phone_no'] !== '' ? '<br><span class="muted small">' . e($d['phone_no']) . '</span>' : '' ?></td>
                        <td><?= e($d['serial']) ?></td>
                        <td><span class="badge inv-<?= e($d['status']) ?>"><?= e(t(INV_STATUSES[$d['status']])) ?></span></td>
                        <td><?php if ($report && $d['status'] === 'einsatz'): ?><a class="btn small" href="<?= e(url('ticket_new', ['device' => $d['id']])) ?>"><?= e(t('Defekt melden')) ?></a><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="card">
            <h2><?= e(t('Lizenzen')) ?></h2>
            <?php if (!$licenses): ?><p class="muted"><?= e(t('Ihnen sind keine Lizenzen zugeordnet.')) ?></p><?php else: ?>
            <ul class="inv-log">
                <?php foreach ($licenses as $l): ?><li><strong><?= e($l['product']) ?></strong> <span class="muted small"><?= e(trim($l['manufacturer'] . ' · ' . t(INV_LIC_KINDS[$l['kind']] ?? $l['kind']), ' ·')) ?></span>
                    <?= inv_lic_state_badge(inv_lic_state($l)) ?>
                    <?php if ($showKeys && (int)$l['has_key']): ?>
                        <?php if (isset($revealed[(int)$l['id']])): ?><br><code class="secret"><?= e($revealed[(int)$l['id']]) ?></code>
                        <?php else: ?><form method="post" action="<?= e(iurl('inv_my')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reveal_key"><input type="hidden" name="license_id" value="<?= (int)$l['id'] ?>">
                            <button class="btn small"><?= e(t('Schlüssel anzeigen')) ?></button></form><?php endif; ?>
                    <?php endif; ?></li><?php endforeach; ?>
            </ul>
            <p class="muted small"><?= e($showKeys ? t('Das Anzeigen eines Schlüssels wird protokolliert. Bitte geben Sie Lizenzschlüssel nicht weiter.') : t('Lizenzschlüssel erhalten Sie bei Bedarf von Ihren Administratoren.')) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($done): ?>
        <div class="card">
            <h2><?= e(t('Meine Protokolle')) ?></h2>
            <ul class="inv-log"><?php foreach ($done as $h): ?><li><a href="<?= e(iurl('inv_handover', ['id' => $h['id']])) ?>"><?= e(t(INV_HO_KINDS[$h['kind']])) ?> · <?= fmt_date($h['handover_date']) ?></a>
                <span class="muted small"><?= e(t('unterschrieben')) ?> <?= fmt_date(substr($h['signed_at'], 0, 10)) ?></span></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>
    <?php });
}

/** Darf der Benutzer dieses Protokoll sehen (Recht im Inventar oder es ist sein eigenes) */
function inv_handover_visible(int $cid, array $h): bool
{
    if (inv_perm($cid) > INV_PERM_NONE) return true;
    $emp = inv_my_employee($cid);
    return $emp && (int)$h['employee_id'] === (int)$emp['id'];
}

// ---------------------------------------------------------------------------
// Tickets je Gerät
// ---------------------------------------------------------------------------

/** Tickets zu einem Gerät – nur für Bearbeiter der Firma bzw. den Dienstleister mit Einsicht in die Tickets */
function inv_device_tickets_card(int $cid, array $d): void
{
    if (!company_has_app($cid, 'tickets') || !function_exists('tickets_my_queue_ids')) return;
    $u = current_user();
    $staff = is_staff_role($u['role']);
    if ($staff ? !(admin_can('tickets', false) && tickets_staff_may_see($cid)) : !($u['role'] === 'firma_admin' || tickets_my_queue_ids($cid))) return;
    $list = rows('SELECT id, number, subject, status, created_at FROM tickets WHERE company_id = ? AND device_id = ? ORDER BY id DESC LIMIT 30', [$cid, $d['id']]); ?>
    <div class="card">
        <h3><?= e(t('Tickets')) ?></h3>
        <?php if (!$list): ?><p class="muted"><?= e(t('Keine Tickets zu diesem Gerät.')) ?></p><?php else: ?>
        <ul class="inv-log"><?php foreach ($list as $tk): ?><li><a href="<?= e(url('ticket', ['id' => $tk['id']] + ($staff ? ['company' => $cid] : []))) ?>">#<?= e($tk['number']) ?> <?= e($tk['subject']) ?></a>
            <span class="muted small">· <?= e(t(TICKET_STATUSES[$tk['status']] ?? $tk['status'])) ?> · <?= fmt_date(substr($tk['created_at'], 0, 10)) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
<?php }

// ---------------------------------------------------------------------------
// On- und Offboarding
// ---------------------------------------------------------------------------

/** Karte „Geräte & Lizenzen“ im Auftrag (Onboarding: Ausgabe und Lizenzplätze; Offboarding: Rückgabeliste) */
function inv_request_card(array $r): void
{
    $cid = (int)$r['company_id'];
    if (!in_array($r['type'], ['onboarding', 'offboarding'], true) || !company_has_app($cid, INV_APP)) return;
    $GLOBALS['inv_cid'] = $cid;
    if (inv_perm($cid) < INV_PERM_EDIT) return;
    $emp = trim((string)$r['employee_no']) !== '' ? employee_find($cid, (string)$r['employee_no']) : null;
    $devices = $emp ? rows("SELECT d.*, t.name AS type_name FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id
        WHERE d.company_id = ? AND d.employee_id = ? AND d.status IN ('einsatz', 'reparatur') ORDER BY d.inv_no", [$cid, $emp['id']]) : [];
    $licenses = $emp ? rows('SELECT a.id AS assign_id, l.id, l.product, l.seats FROM inv_license_assign a JOIN inv_licenses l ON l.id = a.license_id
        WHERE a.employee_id = ? AND a.company_id = ? ORDER BY l.product', [$emp['id'], $cid]) : [];
    $open = $emp ? (int)row("SELECT COUNT(*) AS n FROM inv_handovers WHERE company_id = ? AND employee_id = ? AND status = 'offen'", [$cid, $emp['id']])['n'] : 0;
    $free = [];
    if ($emp && $r['type'] === 'onboarding') {
        $have = array_map('intval', array_column($licenses, 'id'));
        foreach (rows("SELECT l.id, l.product, l.seats, (SELECT COUNT(*) FROM inv_license_assign a WHERE a.license_id = l.id) AS used
            FROM inv_licenses l WHERE l.company_id = ? AND l.status = 'aktiv' ORDER BY l.product", [$cid]) as $l) {
            if (!in_array((int)$l['id'], $have, true)) $free[] = $l;
        }
    }
    $stock = $r['type'] === 'onboarding' ? (int)row("SELECT COUNT(*) AS n FROM inv_devices WHERE company_id = ? AND status = 'lager'", [$cid])['n'] : 0; ?>
    <div class="card">
        <h2><?= e(t('Geräte & Lizenzen')) ?></h2>
        <?php if (!$emp): ?>
            <p class="muted"><?= e(t('Der Mitarbeiter steht noch nicht in der Mitarbeiterliste (Personalnummer fehlt oder Auftrag noch nicht gespeichert).')) ?></p>
        <?php else: ?>
            <?php if ($r['type'] === 'offboarding'): ?><p><strong><?= e(t('Rückgabeliste')) ?></strong> – <?= e(t('alles, was {x} zurückgeben muss:', ['x' => trim($emp['first_name'] . ' ' . $emp['last_name'])])) ?></p><?php endif; ?>
            <div class="grid2">
                <div>
                    <h3><?= e(t('Geräte')) ?> (<?= count($devices) ?>)</h3>
                    <?php if ($devices): ?><ul class="inv-log"><?php foreach ($devices as $d): ?><li><a href="<?= e(iurl('inv_device', ['id' => $d['id']])) ?>"><?= e($d['inv_no']) ?></a> · <?= e(inv_device_label($d)) ?></li><?php endforeach; ?></ul>
                    <?php else: ?><p class="muted small"><?= e(t('Keine Geräte zugeordnet.')) ?></p><?php endif; ?>
                    <div class="btn-row">
                        <?php if ($r['type'] === 'onboarding'): ?>
                            <a class="btn small <?= $stock ? 'primary' : '' ?>" href="<?= e(iurl('inv_handover_new', ['kind' => 'ausgabe', 'employee' => $emp['id']])) ?>"><?= e(t('Geräte aus dem Lager ausgeben')) ?></a>
                            <span class="muted small"><?= e(t('{n} Geräte an Lager', ['n' => $stock])) ?></span>
                        <?php elseif ($devices): ?>
                            <a class="btn small primary" href="<?= e(iurl('inv_handover_new', ['kind' => 'rueckgabe', 'employee' => $emp['id']])) ?>"><?= e(t('Rücknahme erfassen')) ?></a>
                        <?php endif; ?>
                    </div>
                    <?php if ($open): ?><p class="small"><a href="<?= e(iurl('inv_handovers', ['f' => 'offen'])) ?>"><?= e(t('{n} Protokolle noch nicht unterschrieben', ['n' => $open])) ?></a></p><?php endif; ?>
                </div>
                <div>
                    <h3><?= e(t('Lizenzen')) ?> (<?= count($licenses) ?>)</h3>
                    <?php if ($licenses): ?><ul class="inv-log"><?php foreach ($licenses as $l): ?><li><a href="<?= e(iurl('inv_license', ['id' => $l['id']])) ?>"><?= e($l['product']) ?></a></li><?php endforeach; ?></ul>
                    <?php else: ?><p class="muted small"><?= e(t('Keine Lizenzen zugeordnet.')) ?></p><?php endif; ?>
                    <?php if ($r['type'] === 'onboarding' && $free): ?>
                    <form method="post" action="<?= e(iurl('inv_req_action', ['id' => $r['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="assign_licenses">
                        <?php foreach ($free as $l): $full = (int)$l['seats'] > 0 && (int)$l['used'] >= (int)$l['seats']; ?>
                            <label class="check"><input type="checkbox" name="licenses[]" value="<?= (int)$l['id'] ?>"> <?= e($l['product']) ?>
                                <span class="muted small">(<?= (int)$l['used'] ?> / <?= (int)$l['seats'] > 0 ? (int)$l['seats'] : '∞' ?>)</span><?= $full ? ' <span class="tag warn">' . e(t('voll')) . '</span>' : '' ?></label>
                        <?php endforeach; ?>
                        <button class="btn small"><?= e(t('Lizenzplätze vergeben')) ?></button></form>
                    <?php elseif ($r['type'] === 'offboarding' && $licenses): ?>
                    <form method="post" action="<?= e(iurl('inv_req_action', ['id' => $r['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="release_licenses">
                        <button class="btn small" data-confirm="<?= e(t('Alle Lizenzplätze dieses Mitarbeiters freigeben?')) ?>"><?= e(t('Alle Lizenzplätze freigeben')) ?></button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php }

function page_inv_req_action(): void
{
    if (!is_post()) abort(405);
    require_post_csrf();
    $r = load_request(query_int('id'));
    $cid = (int)$r['company_id'];
    if (!company_has_app($cid, INV_APP)) abort(403);
    $GLOBALS['inv_cid'] = $cid;
    if (inv_perm($cid) < INV_PERM_EDIT) abort(403);
    $emp = employee_find($cid, (string)$r['employee_no']);
    if (!$emp) abort(400);
    $name = trim($emp['first_name'] . ' ' . $emp['last_name']);
    $n = 0;
    if (post('action') === 'assign_licenses' && $r['type'] === 'onboarding') {
        foreach (array_unique(array_map('intval', (array)($_POST['licenses'] ?? []))) as $lid) {
            $l = row("SELECT * FROM inv_licenses WHERE id = ? AND company_id = ? AND status = 'aktiv'", [$lid, $cid]);
            if (!$l || row('SELECT id FROM inv_license_assign WHERE license_id = ? AND employee_id = ?', [$lid, $emp['id']])) continue;
            insert('inv_license_assign', ['company_id' => $cid, 'license_id' => $lid, 'employee_id' => $emp['id'], 'device_id' => null, 'assigned_at' => now(), 'user_id' => current_user()['id']]);
            inv_lic_log($cid, $lid, 'Platz vergeben an: ' . $name . ' (Onboarding #' . $r['id'] . ')');
            $n++;
        }
        flash('success', t('{n} Lizenzplätze vergeben.', ['n' => $n]));
    } elseif (post('action') === 'release_licenses' && $r['type'] === 'offboarding') {
        foreach (rows('SELECT a.id, a.license_id FROM inv_license_assign a WHERE a.employee_id = ? AND a.company_id = ?', [$emp['id'], $cid]) as $a) {
            q('DELETE FROM inv_license_assign WHERE id = ?', [$a['id']]);
            inv_lic_log($cid, (int)$a['license_id'], 'Platz freigegeben: ' . $name . ' (Offboarding #' . $r['id'] . ')');
            $n++;
        }
        flash('success', t('{n} Lizenzplätze freigegeben.', ['n' => $n]));
    } else {
        abort(400);
    }
    audit('inventory.request_' . post('action'), $name . ': ' . $n, $cid);
    redirect('request', ['id' => $r['id']]);
}
