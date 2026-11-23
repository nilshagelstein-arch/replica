<?php
/**
 * App „Geräte & Lizenzen“ – Stufe 3: Lebenszyklus
 *
 * - Ausgabe und Rückgabe mit Übergabeprotokoll (fest im Inventar): Unterschrift am Bildschirm
 *   (Canvas, gespeichert als PNG) oder Ausdruck und Upload des unterschriebenen Scans.
 * - Reparatur (Grund, Dienstleister, geplante Rückgabe), Ausmustern, Entsorgen mit Nachweis
 *   (Datum, Entsorger, Datenlöschung bestätigt, Nachweis als Anhang).
 * - QR-Code-Etiketten zum Drucken; der Code führt zur Geräteseite im Portal.
 */

declare(strict_types=1);

const INV_HO_KINDS = ['ausgabe' => 'Ausgabe', 'rueckgabe' => 'Rückgabe'];
const INV_ITEM_CONDITIONS = ['ok' => 'in Ordnung', 'beschaedigt' => 'beschädigt', 'zubehoer' => 'Zubehör fehlt', 'fehlt' => 'nicht zurückgegeben'];
const INV_HO_TEXT_DEFAULT = 'Ich bestätige den Erhalt der oben aufgeführten Geräte in ordnungsgemäßem Zustand. Die Geräte bleiben Eigentum der Firma, sind sorgfältig zu behandeln und ausschließlich dienstlich zu nutzen. Verlust oder Schäden melde ich unverzüglich. Beim Austritt gebe ich alle Geräte vollständig zurück.';
const INV_HO_RETURN_TEXT = 'Die oben aufgeführten Geräte wurden im angegebenen Zustand zurückgegeben.';
const INV_SIGNATURE_MAX = 400000; // Zeichen der Data-URL

function inv_ho_text(int $cid): string
{
    $t = trim((string)(row('SELECT inv_handover_text FROM companies WHERE id = ?', [$cid])['inv_handover_text'] ?? ''));
    return $t !== '' ? $t : t(INV_HO_TEXT_DEFAULT);
}

function inv_device_label(array $d): string
{
    return trim(($d['type_name'] !== null && $d['type_name'] !== '' ? t($d['type_name']) . ' ' : '') . $d['manufacturer'] . ' ' . $d['model']);
}

/** Lizenzplätze eines Geräts freigeben (beim Ausmustern) */
function inv_release_device_licenses(int $cid, int $deviceId): int
{
    $n = 0;
    foreach (rows('SELECT a.id, l.id AS license_id, l.product FROM inv_license_assign a JOIN inv_licenses l ON l.id = a.license_id WHERE a.device_id = ? AND a.company_id = ?', [$deviceId, $cid]) as $a) {
        q('DELETE FROM inv_license_assign WHERE id = ?', [$a['id']]);
        inv_lic_log($cid, (int)$a['license_id'], 'Platz freigegeben: ' . (row('SELECT inv_no FROM inv_devices WHERE id = ?', [$deviceId])['inv_no'] ?? ''));
        $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------------
// Übergaben: Liste
// ---------------------------------------------------------------------------

function page_inv_handovers(): void
{
    $cid = inv_company_id();
    $perm = inv_perm($cid);
    if ($perm === INV_PERM_NONE) { page_inventory(); return; }
    $filter = (string)($_GET['f'] ?? 'alle');
    $where = 'h.company_id = ?';
    if ($filter === 'offen') $where .= " AND h.status = 'offen'";
    $list = rows("SELECT h.* FROM inv_handovers h WHERE $where ORDER BY h.id DESC LIMIT 500", [$cid]);
    if ($list) {
        $nos = [];
        foreach (rows('SELECT handover_id, inv_no FROM inv_handover_items WHERE handover_id IN (' . implode(',', array_map(fn($h) => (int)$h['id'], $list)) . ') ORDER BY inv_no') as $i) $nos[(int)$i['handover_id']][] = $i['inv_no'];
        foreach ($list as &$h) { $h['n'] = count($nos[(int)$h['id']] ?? []); $h['nos'] = implode(', ', $nos[(int)$h['id']] ?? []); }
        unset($h);
    }
    $open = (int)row("SELECT COUNT(*) AS n FROM inv_handovers WHERE company_id = ? AND status = 'offen'", [$cid])['n'];
    render_page(t('Geräte & Lizenzen'), function () use ($cid, $perm, $filter, $list, $open) {
        echo company_switcher($cid, 'inv_handovers');
        inv_tabs('inv_handovers'); ?>
        <div class="toolbar">
            <?php if ($perm >= INV_PERM_EDIT): ?>
                <a class="btn primary" href="<?= e(iurl('inv_handover_new', ['kind' => 'ausgabe'])) ?>"><?= e(t('+ Geräte ausgeben')) ?></a>
                <a class="btn" href="<?= e(iurl('inv_handover_new', ['kind' => 'rueckgabe'])) ?>"><?= e(t('Rückgabe erfassen')) ?></a>
            <?php endif; ?>
        </div>
        <nav class="type-tabs">
            <a class="<?= $filter !== 'offen' ? 'active' : '' ?>" href="<?= e(iurl('inv_handovers')) ?>"><?= e(t('Alle Protokolle')) ?></a>
            <a class="<?= $filter === 'offen' ? 'active' : '' ?>" href="<?= e(iurl('inv_handovers', ['f' => 'offen'])) ?>"><?= e(t('Nicht unterschrieben')) ?><?= $open ? ' <span class="count">' . $open . '</span>' : '' ?></a>
        </nav>
        <div class="card">
            <table class="table">
                <thead><tr><th><?= e(t('Datum')) ?></th><th><?= e(t('Art')) ?></th><th><?= e(t('Mitarbeiter')) ?></th><th><?= e(t('Geräte')) ?></th><th><?= e(t('Unterschrift')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($list as $h): ?>
                    <tr><td><a href="<?= e(iurl('inv_handover', ['id' => $h['id']])) ?>"><?= fmt_date($h['handover_date']) ?></a></td>
                        <td><?= e(t(INV_HO_KINDS[$h['kind']] ?? $h['kind'])) ?></td>
                        <td><?= e($h['employee_name']) ?></td>
                        <td><?= (int)$h['n'] ?> <span class="muted small"><?= e(mb_substr((string)$h['nos'], 0, 80)) ?></span></td>
                        <td><?= $h['status'] === 'unterschrieben' ? '<span class="badge inv-einsatz">' . e(t('unterschrieben')) . '</span> <span class="muted small">' . fmt_date(substr($h['signed_at'], 0, 10)) . '</span>'
                            : '<span class="badge inv-reparatur">' . e(t('offen')) . '</span>' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$list): ?><tr><td colspan="5" class="muted"><?= e(t('Noch keine Übergabeprotokolle.')) ?></td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Ausgabe / Rückgabe erfassen
// ---------------------------------------------------------------------------

function page_inv_handover_new(): void
{
    $cid = inv_company_id();
    if (inv_perm($cid) < INV_PERM_EDIT) abort(403);
    $kind = ($_GET['kind'] ?? post('kind', 'ausgabe')) === 'rueckgabe' ? 'rueckgabe' : 'ausgabe';
    $employees = inv_employees($cid);
    $locations = rows('SELECT * FROM inv_locations WHERE company_id = ? AND active = 1 ORDER BY sort, name', [$cid]);

    if (is_post()) {
        require_post_csrf();
        $emp = row('SELECT id, first_name, last_name FROM employees WHERE id = ? AND company_id = ?', [(int)post('employee_id', 0), $cid]);
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['devices'] ?? []))));
        $back = fn() => iredirect('inv_handover_new', ['kind' => $kind, 'employee' => (int)post('employee_id', 0)]);
        if (!$emp) { flash('error', t('Bitte einen Mitarbeiter wählen.')); $back(); }
        if (!$ids) { flash('error', t('Bitte mindestens ein Gerät auswählen.')); $back(); }
        $in = implode(',', $ids);
        $devs = rows("SELECT d.*, t.name AS type_name FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id WHERE d.company_id = ? AND d.id IN ($in) ORDER BY d.inv_no", [$cid]);
        if (count($devs) !== count($ids)) abort(400);
        foreach ($devs as $d) {
            if ($kind === 'ausgabe' && $d['status'] !== 'lager') { flash('error', t('{x} liegt nicht im Lager und kann nicht ausgegeben werden.', ['x' => $d['inv_no']])); $back(); }
            if ($kind === 'rueckgabe' && (int)$d['employee_id'] !== (int)$emp['id']) { flash('error', t('{x} ist diesem Mitarbeiter nicht zugeordnet.', ['x' => $d['inv_no']])); $back(); }
        }
        $loc = $kind === 'rueckgabe' && (int)post('location_id', 0) ? row('SELECT * FROM inv_locations WHERE id = ? AND company_id = ?', [(int)post('location_id', 0), $cid]) : null;
        $date = inv_date((string)post('handover_date')) ?: date('Y-m-d');
        $name = trim($emp['first_name'] . ' ' . $emp['last_name']);
        $hid = insert('inv_handovers', ['company_id' => $cid, 'kind' => $kind, 'employee_id' => $emp['id'], 'employee_name' => $name, 'handover_date' => $date,
            'accessories' => mb_substr(trim((string)post('accessories')), 0, 2000), 'note' => mb_substr(trim((string)post('note')), 0, 2000),
            'terms' => $kind === 'ausgabe' ? inv_ho_text($cid) : t(INV_HO_RETURN_TEXT), 'status' => 'offen', 'created_by' => current_user()['id'], 'created_at' => now()]);
        $conds = (array)($_POST['condition'] ?? []);
        foreach ($devs as $d) {
            $cond = $kind === 'rueckgabe' ? (isset(INV_ITEM_CONDITIONS[$conds[$d['id']] ?? '']) ? $conds[$d['id']] : 'ok') : '';
            insert('inv_handover_items', ['handover_id' => $hid, 'device_id' => $d['id'], 'inv_no' => $d['inv_no'], 'description' => mb_substr(inv_device_label($d), 0, 255),
                'serial' => $d['serial'], 'item_condition' => $cond]);
            if ($kind === 'ausgabe') {
                update('inv_devices', (int)$d['id'], ['status' => 'einsatz', 'employee_id' => $emp['id'], 'updated_at' => now()]);
                inv_log($cid, (int)$d['id'], 'Ausgegeben an: ' . $name);
            } elseif ($cond === 'fehlt') {
                inv_log($cid, (int)$d['id'], 'Rückgabe: nicht zurückgegeben');
            } else {
                update('inv_devices', (int)$d['id'], ['status' => 'lager', 'employee_id' => null, 'location_id' => $loc['id'] ?? $d['location_id'], 'updated_at' => now()]);
                inv_log($cid, (int)$d['id'], 'Zurückgegeben von: ' . $name . ' (' . INV_ITEM_CONDITIONS[$cond] . ')');
                if ($loc && (int)$loc['id'] !== (int)$d['location_id']) inv_log($cid, (int)$d['id'], 'Standort: ' . $loc['name']);
            }
        }
        audit('inventory.handover_' . $kind, $name . ': ' . implode(', ', array_column($devs, 'inv_no')), $cid);
        flash('success', $kind === 'ausgabe' ? t('Die Geräte sind ausgegeben. Bitte das Protokoll unterschreiben lassen.') : t('Die Rückgabe ist erfasst. Bitte das Protokoll unterschreiben lassen.'));
        iredirect('inv_handover', ['id' => $hid]);
    }

    $empSel = query_int('employee');
    $pre = query_int('device');
    $devices = $kind === 'ausgabe'
        ? rows("SELECT d.*, t.name AS type_name, l.name AS location_name FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id LEFT JOIN inv_locations l ON l.id = d.location_id
            WHERE d.company_id = ? AND d.status = 'lager' ORDER BY l.name, t.name, d.inv_no", [$cid])
        : ($empSel ? rows("SELECT d.*, t.name AS type_name FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id
            WHERE d.company_id = ? AND d.employee_id = ? AND d.status IN ('einsatz', 'reparatur') ORDER BY d.inv_no", [$cid, $empSel]) : []);
    if ($pre && $kind === 'rueckgabe' && !$empSel) {
        $pd = row('SELECT employee_id FROM inv_devices WHERE id = ? AND company_id = ?', [$pre, $cid]);
        if ($pd && $pd['employee_id']) iredirect('inv_handover_new', ['kind' => 'rueckgabe', 'employee' => (int)$pd['employee_id'], 'device' => $pre]);
    }

    render_page($kind === 'ausgabe' ? t('Geräte ausgeben') : t('Rückgabe erfassen'), function () use ($cid, $kind, $employees, $locations, $empSel, $pre, $devices) { ?>
        <p><a href="<?= e(iurl('inv_handovers')) ?>">← <?= e(t('Übergaben')) ?></a></p>
        <?php if ($kind === 'rueckgabe'): ?>
        <form method="get" class="toolbar card">
            <input type="hidden" name="r" value="inv_handover_new"><input type="hidden" name="kind" value="rueckgabe">
            <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
            <label><?= e(t('Mitarbeiter')) ?> <select name="employee"><option value=""><?= e(t('– bitte wählen –')) ?></option>
                <?php foreach ($employees as $em): ?><option value="<?= (int)$em['id'] ?>" <?= $empSel === (int)$em['id'] ? 'selected' : '' ?>><?= e($em['last_name'] . ', ' . $em['first_name']) ?></option><?php endforeach; ?></select></label>
            <button class="btn"><?= e(t('Geräte anzeigen')) ?></button>
        </form>
        <?php endif; ?>
        <?php if ($kind === 'ausgabe' || $empSel): ?>
        <form method="post" action="<?= e(iurl('inv_handover_new', ['kind' => $kind])) ?>" class="form card">
            <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>">
            <?php if ($kind === 'ausgabe'): ?>
                <label><?= e(t('Mitarbeiter *')) ?> <select name="employee_id" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                    <?php foreach ($employees as $em): ?><option value="<?= (int)$em['id'] ?>" <?= $empSel === (int)$em['id'] ? 'selected' : '' ?>><?= e($em['last_name'] . ', ' . $em['first_name']) ?><?= $em['employee_no'] !== '' ? ' (' . e($em['employee_no']) . ')' : '' ?></option><?php endforeach; ?></select></label>
            <?php else: ?><input type="hidden" name="employee_id" value="<?= (int)$empSel ?>"><?php endif; ?>
            <h3><?= e($kind === 'ausgabe' ? t('Geräte aus dem Lager') : t('Geräte des Mitarbeiters')) ?></h3>
            <?php if (!$devices): ?><p class="muted"><?= e($kind === 'ausgabe' ? t('Es liegt kein Gerät im Lager.') : t('Diesem Mitarbeiter sind keine Geräte zugeordnet.')) ?></p><?php else: ?>
            <table class="table">
                <thead><tr><th></th><th><?= e(t('Nummer')) ?></th><th><?= e(t('Gerät')) ?></th><th><?= e(t('Seriennummer')) ?></th><th><?= e($kind === 'ausgabe' ? t('Standort') : t('Zustand')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($devices as $d): ?>
                    <tr><td><input type="checkbox" name="devices[]" value="<?= (int)$d['id'] ?>" <?= $pre === (int)$d['id'] || $kind === 'rueckgabe' ? 'checked' : '' ?>></td>
                        <td><?= e($d['inv_no']) ?></td><td><?= e(inv_device_label($d)) ?></td><td><?= e($d['serial']) ?></td>
                        <td><?php if ($kind === 'ausgabe'): ?><?= e($d['location_name'] ?? '–') ?><?php else: ?>
                            <select name="condition[<?= (int)$d['id'] ?>]"><?php foreach (INV_ITEM_CONDITIONS as $k => $l): ?><option value="<?= e($k) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <div class="grid2">
                <label><?= e(t('Datum')) ?> <input type="date" name="handover_date" value="<?= date('Y-m-d') ?>"></label>
                <?php if ($kind === 'rueckgabe'): ?>
                <label><?= e(t('Zurück an Standort / Lager')) ?> <select name="location_id"><option value=""><?= e(t('– bisheriger Standort –')) ?></option>
                    <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>
            </div>
            <label><?= e(t('Zubehör')) ?> <input name="accessories" maxlength="2000" placeholder="<?= e(t('z. B. Netzteil, Dockingstation, Tasche')) ?>"></label>
            <label><?= e(t('Bemerkung')) ?> <textarea name="note" rows="2"></textarea></label>
            <?php if ($devices): ?><button class="btn primary"><?= e($kind === 'ausgabe' ? t('Ausgeben und Protokoll erstellen') : t('Rückgabe buchen und Protokoll erstellen')) ?></button><?php endif; ?>
        </form>
        <?php endif; ?>
    <?php });
}

// ---------------------------------------------------------------------------
// Protokoll ansehen, unterschreiben, drucken
// ---------------------------------------------------------------------------

function page_inv_handover(): void
{
    $cid = inv_company_id();
    $perm = inv_perm($cid);
    $h = row('SELECT * FROM inv_handovers WHERE id = ? AND company_id = ?', [query_int('id'), $cid]);
    if (!$h) abort(404);
    if (!inv_handover_visible($cid, $h)) abort(403);
    $own = ($me = inv_my_employee($cid)) && (int)$h['employee_id'] === (int)$me['id'];
    $items = rows('SELECT * FROM inv_handover_items WHERE handover_id = ? ORDER BY inv_no', [$h['id']]);
    $files = rows('SELECT * FROM inv_files WHERE handover_id = ? ORDER BY id', [$h['id']]);
    $co = row('SELECT name, street, zip, city FROM companies WHERE id = ?', [$cid]) ?? ['name' => ''];
    $signer = $h['signed_by'] ? row('SELECT name FROM users WHERE id = ?', [$h['signed_by']]) : null;
    $title = t(INV_HO_KINDS[$h['kind']]) . ' · ' . $h['employee_name'] . ' · ' . fmt_date($h['handover_date']);

    render_page($title, function () use ($cid, $perm, $h, $items, $files, $co, $signer, $own) {
        $edit = $perm >= INV_PERM_EDIT;
        $logo = function_exists('logo_tag') ? logo_tag($cid, 'ho-logo') : ''; ?>
        <p class="no-print"><a href="<?= e(iurl($perm > INV_PERM_NONE ? 'inv_handovers' : 'inv_my')) ?>">← <?= e($perm > INV_PERM_NONE ? t('Übergaben') : t('Meine Geräte')) ?></a></p>
        <div class="toolbar no-print">
            <button type="button" class="btn" data-print><?= e(t('Drucken / als PDF speichern')) ?></button>
        </div>
        <article class="card ho-doc">
            <header class="ho-head">
                <div><?= $logo ?><strong><?= e($co['name']) ?></strong><?php if (!empty($co['street'])): ?><br><span class="small"><?= e(trim($co['street'] . ', ' . ($co['zip'] ?? '') . ' ' . ($co['city'] ?? ''))) ?></span><?php endif; ?></div>
                <div class="ho-meta small"><?= e(t('Protokoll Nr. {n}', ['n' => (int)$h['id']])) ?><br><?= e(t('Datum')) ?>: <?= fmt_date($h['handover_date']) ?></div>
            </header>
            <h2><?= e($h['kind'] === 'ausgabe' ? t('Übergabeprotokoll – Ausgabe von Arbeitsmitteln') : t('Rückgabeprotokoll – Rückgabe von Arbeitsmitteln')) ?></h2>
            <p><?= e($h['kind'] === 'ausgabe' ? t('Empfänger/in') : t('Zurückgegeben von')) ?>: <strong><?= e($h['employee_name']) ?></strong></p>
            <table class="table ho-items">
                <thead><tr><th><?= e(t('Inventarnummer')) ?></th><th><?= e(t('Gerät')) ?></th><th><?= e(t('Seriennummer')) ?></th><?php if ($h['kind'] === 'rueckgabe'): ?><th><?= e(t('Zustand')) ?></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($items as $i): ?>
                    <tr><td><?php if ($i['device_id'] && $perm > INV_PERM_NONE): ?><a href="<?= e(iurl('inv_device', ['id' => $i['device_id']])) ?>"><?= e($i['inv_no']) ?></a><?php else: ?><?= e($i['inv_no']) ?><?php endif; ?></td>
                        <td><?= e($i['description']) ?></td><td><?= e($i['serial']) ?></td>
                        <?php if ($h['kind'] === 'rueckgabe'): ?><td><?= e(t(INV_ITEM_CONDITIONS[$i['item_condition']] ?? $i['item_condition'])) ?></td><?php endif; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($h['accessories'] !== ''): ?><p><?= e(t('Zubehör')) ?>: <?= e($h['accessories']) ?></p><?php endif; ?>
            <?php if ($h['note'] !== ''): ?><p><?= e(t('Bemerkung')) ?>: <?= e($h['note']) ?></p><?php endif; ?>
            <p class="ho-terms"><?= nl2br(e((string)$h['terms'])) ?></p>
            <div class="ho-sign">
                <div class="ho-sign-box">
                    <?php if ($h['signature_png']): ?><img class="ho-signature" alt="<?= e(t('Unterschrift')) ?>" src="<?= e($h['signature_png']) ?>"><?php endif; ?>
                    <div class="ho-line"><?= e(t('Ort, Datum, Unterschrift {x}', ['x' => $h['employee_name']])) ?>
                        <?php if ($h['status'] === 'unterschrieben'): ?><br><span class="small"><?= e(t('Unterschrieben von {n} am {d}', ['n' => $h['sign_name'] ?: $h['employee_name'], 'd' => fmt_dt($h['signed_at'])])) ?></span><?php endif; ?></div>
                </div>
                <div class="ho-sign-box"><div class="ho-line"><?= e(t('Unterschrift für die Firma')) ?></div></div>
            </div>
        </article>

        <?php if ($h['status'] === 'offen' && ($edit || $own)): ?>
        <div class="grid2 no-print">
            <form method="post" action="<?= e(iurl('inv_ho_action', ['id' => $h['id']])) ?>" class="form card">
                <?= csrf_field() ?><input type="hidden" name="action" value="sign"><input type="hidden" name="signature" value="">
                <h3><?= e(t('Am Bildschirm unterschreiben')) ?></h3>
                <canvas class="sign-pad" width="600" height="200" data-sign aria-label="<?= e(t('Unterschriftenfeld')) ?>"></canvas>
                <p><button type="button" class="btn small" data-sign-clear><?= e(t('Löschen')) ?></button></p>
                <label><?= e(t('Name der unterschreibenden Person')) ?> <input name="sign_name" maxlength="190" required value="<?= e($h['employee_name']) ?>"></label>
                <button class="btn primary"><?= e(t('Unterschrift speichern')) ?></button>
            </form>
            <?php if ($edit): ?>
            <form method="post" action="<?= e(iurl('inv_ho_action', ['id' => $h['id']])) ?>" enctype="multipart/form-data" class="form card">
                <?= csrf_field() ?><input type="hidden" name="action" value="scan">
                <h3><?= e(t('Auf Papier unterschrieben')) ?></h3>
                <p class="muted small"><?= e(t('Protokoll drucken, unterschreiben lassen und den Scan hier hochladen.')) ?></p>
                <label><?= e(t('Scan (PDF oder Bild)')) ?> <input type="file" name="files[]" required></label>
                <button class="btn"><?= e(t('Hochladen und als unterschrieben markieren')) ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($files && $edit): ?>
        <div class="card no-print"><h3><?= e(t('Unterschriebener Scan')) ?></h3><ul class="file-list">
            <?php foreach ($files as $f): ?><li><a href="<?= e(iurl('inv_file', ['id' => $f['id']])) ?>"><?= e($f['filename']) ?></a> <span class="muted small"><?= fmt_dt($f['created_at']) ?></span></li><?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
        <?php if ($edit && $h['status'] === 'offen'): ?>
        <form method="post" action="<?= e(iurl('inv_ho_action', ['id' => $h['id']])) ?>" class="card no-print"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <p class="muted small"><?= e(t('Das Protokoll löschen ändert die Geräte nicht; die Ausgabe bzw. Rückgabe bleibt gebucht.')) ?></p>
            <button class="btn small danger" data-confirm="<?= e(t('Protokoll löschen?')) ?>"><?= e(t('Protokoll löschen')) ?></button></form>
        <?php endif; ?>
    <?php });
}

function page_inv_ho_action(): void
{
    $cid = inv_company_id();
    if (!is_post()) abort(405);
    require_post_csrf();
    $h = row('SELECT * FROM inv_handovers WHERE id = ? AND company_id = ?', [query_int('id'), $cid]);
    if (!$h) abort(404);
    // Mitarbeiter unterschreiben ihr eigenes Protokoll selbst; alles andere braucht „bearbeiten“
    $me = inv_my_employee($cid);
    $own = $me && (int)$h['employee_id'] === (int)$me['id'];
    if (inv_perm($cid) < INV_PERM_EDIT && !($own && post('action') === 'sign')) abort(403);
    $back = fn() => iredirect('inv_handover', ['id' => $h['id']]);
    $logItems = function (string $msg) use ($h, $cid) {
        foreach (rows('SELECT device_id FROM inv_handover_items WHERE handover_id = ? AND device_id IS NOT NULL', [$h['id']]) as $i) inv_log($cid, (int)$i['device_id'], $msg);
    };
    switch ((string)post('action')) {
        case 'sign':
            if ($h['status'] !== 'offen') $back();
            $sig = (string)post('signature');
            $name = mb_substr(trim((string)post('sign_name')), 0, 190);
            $raw = str_starts_with($sig, 'data:image/png;base64,') && strlen($sig) <= INV_SIGNATURE_MAX ? base64_decode(substr($sig, 22), true) : false;
            $info = $raw !== false ? @getimagesizefromstring($raw) : false;
            if (!$info || $info[2] !== IMAGETYPE_PNG || $name === '') { flash('error', t('Bitte im Feld unterschreiben und den Namen angeben.')); $back(); }
            update('inv_handovers', (int)$h['id'], ['status' => 'unterschrieben', 'signature_png' => 'data:image/png;base64,' . base64_encode($raw),
                'sign_name' => $name, 'signed_at' => now(), 'signed_by' => current_user()['id']]);
            $logItems('Protokoll unterschrieben (' . INV_HO_KINDS[$h['kind']] . ', Nr. ' . $h['id'] . ')');
            audit('inventory.handover_signed', 'Nr. ' . $h['id'] . ' ' . $name, $cid);
            flash('success', t('Die Unterschrift wurde gespeichert.'));
            $back();
        case 'scan':
            [$files, $err] = ticket_uploads();
            if ($err || !$files) { flash('error', $err ?? t('Bitte eine Datei auswählen.')); $back(); }
            $dir = inv_files_dir($cid);
            foreach ($files as $fl) {
                $stored = 'H' . $h['id'] . '-' . bin2hex(random_bytes(12)) . '.bin';
                if (!move_uploaded_file($fl['tmp'], $dir . '/' . $stored)) continue;
                @chmod($dir . '/' . $stored, 0640);
                $mime = function_exists('mime_content_type') ? (string)@mime_content_type($dir . '/' . $stored) : 'application/octet-stream';
                insert('inv_files', ['company_id' => $cid, 'device_id' => 0, 'handover_id' => $h['id'], 'filename' => $fl['name'], 'mime' => mb_substr($mime, 0, 100),
                    'size' => $fl['size'], 'stored_name' => $stored, 'user_id' => current_user()['id'], 'created_at' => now()]);
            }
            if ($h['status'] === 'offen') {
                update('inv_handovers', (int)$h['id'], ['status' => 'unterschrieben', 'sign_name' => $h['employee_name'], 'signed_at' => now(), 'signed_by' => current_user()['id']]);
                $logItems('Protokoll unterschrieben (Scan, Nr. ' . $h['id'] . ')');
            }
            audit('inventory.handover_scan', 'Nr. ' . $h['id'], $cid);
            flash('success', t('Der Scan wurde gespeichert.'));
            $back();
        case 'delete':
            if ($h['status'] !== 'offen') abort(403);
            q('DELETE FROM inv_handover_items WHERE handover_id = ?', [$h['id']]);
            q('DELETE FROM inv_handovers WHERE id = ?', [$h['id']]);
            audit('inventory.handover_delete', 'Nr. ' . $h['id'], $cid);
            flash('success', t('Das Protokoll wurde gelöscht.'));
            iredirect('inv_handovers');
    }
    abort(400);
}

// ---------------------------------------------------------------------------
// Reparatur, Ausmustern, Entsorgen
// ---------------------------------------------------------------------------

function page_inv_life(): void
{
    $cid = inv_company_id();
    if (!is_post()) abort(405);
    require_post_csrf();
    if (inv_perm($cid) < INV_PERM_EDIT) abort(403);
    $d = inv_load_device(query_int('id'), $cid);
    if (!$d) abort(404);
    $id = (int)$d['id'];
    $back = fn() => iredirect('inv_device', ['id' => $id]);
    $f = fn(string $k, int $max) => mb_substr(trim((string)post($k)), 0, $max);
    switch ((string)post('action')) {
        case 'repair_start':
            if (!in_array($d['status'], ['lager', 'einsatz'], true)) $back();
            $reason = $f('repair_reason', 255);
            if ($reason === '') { flash('error', t('Bitte den Grund der Reparatur angeben.')); $back(); }
            update('inv_devices', $id, ['status' => 'reparatur', 'repair_reason' => $reason, 'repair_vendor' => $f('repair_vendor', 190),
                'repair_until' => inv_date((string)post('repair_until')), 'updated_at' => now()]);
            inv_log($cid, $id, 'In Reparatur: ' . $reason . ($f('repair_vendor', 190) !== '' ? ' (' . $f('repair_vendor', 190) . ')' : ''));
            flash('success', t('Das Gerät ist in Reparatur.'));
            $back();
        case 'repair_end':
            if ($d['status'] !== 'reparatur') $back();
            $loc = (int)post('location_id', 0) ? row('SELECT * FROM inv_locations WHERE id = ? AND company_id = ?', [(int)post('location_id', 0), $cid]) : null;
            $to = $d['employee_id'] ? 'einsatz' : 'lager';
            update('inv_devices', $id, ['status' => $to, 'repair_until' => '', 'location_id' => $loc['id'] ?? $d['location_id'], 'updated_at' => now()]);
            inv_log($cid, $id, 'Aus der Reparatur zurück' . ($f('repair_result', 255) !== '' ? ': ' . $f('repair_result', 255) : ''));
            flash('success', t('Das Gerät ist zurück aus der Reparatur.'));
            $back();
        case 'retire':
            if (!in_array($d['status'], ['lager', 'einsatz', 'reparatur'], true)) $back();
            $reason = $f('retire_reason', 255);
            if ($reason === '') { flash('error', t('Bitte einen Grund angeben.')); $back(); }
            $date = inv_date((string)post('retired_at')) ?: date('Y-m-d');
            update('inv_devices', $id, ['status' => 'ausgemustert', 'retire_reason' => $reason, 'retired_at' => $date, 'employee_id' => null, 'updated_at' => now()]);
            if ($d['employee_id']) inv_log($cid, $id, 'Zuordnung aufgehoben: ' . $d['employee_name']);
            $n = inv_release_device_licenses($cid, $id);
            inv_log($cid, $id, 'Ausgemustert: ' . $reason . ($n ? ' · Lizenzplätze freigegeben: ' . $n : ''));
            audit('inventory.retire', $d['inv_no'], $cid);
            flash('success', t('Das Gerät ist ausgemustert.'));
            $back();
        case 'reactivate':
            if ($d['status'] !== 'ausgemustert') $back();
            update('inv_devices', $id, ['status' => 'lager', 'retire_reason' => '', 'retired_at' => '', 'updated_at' => now()]);
            inv_log($cid, $id, 'Wieder in Betrieb genommen (an Lager)');
            $back();
        case 'dispose':
            if ($d['status'] !== 'ausgemustert') { flash('error', t('Bitte das Gerät zuerst ausmustern.')); $back(); }
            $vendor = $f('disposal_vendor', 190);
            if ($vendor === '' || post('wipe_confirmed') !== '1' && post('no_data') !== '1') {
                flash('error', t('Bitte den Entsorger angeben und die Datenlöschung bestätigen (oder „Gerät speichert keine Daten“ wählen).'));
                $back();
            }
            [$files, $err] = ticket_uploads();
            if ($err) { flash('error', $err); $back(); }
            $date = inv_date((string)post('disposal_date')) ?: date('Y-m-d');
            $noData = post('no_data') === '1';
            update('inv_devices', $id, ['status' => 'entsorgt', 'disposal_date' => $date, 'disposal_vendor' => $vendor,
                'wipe_method' => $noData ? t('Gerät speichert keine Daten') : $f('wipe_method', 190), 'wipe_by' => $noData ? '' : $f('wipe_by', 190),
                'wipe_confirmed' => $noData ? 0 : 1, 'updated_at' => now()]);
            $dir = inv_files_dir($cid);
            foreach ($files as $fl) {
                $stored = $id . '-' . bin2hex(random_bytes(12)) . '.bin';
                if (!move_uploaded_file($fl['tmp'], $dir . '/' . $stored)) continue;
                @chmod($dir . '/' . $stored, 0640);
                $mime = function_exists('mime_content_type') ? (string)@mime_content_type($dir . '/' . $stored) : 'application/octet-stream';
                insert('inv_files', ['company_id' => $cid, 'device_id' => $id, 'filename' => $fl['name'], 'mime' => mb_substr($mime, 0, 100), 'size' => $fl['size'],
                    'stored_name' => $stored, 'user_id' => current_user()['id'], 'created_at' => now()]);
                inv_log($cid, $id, 'Anhang hinzugefügt: ' . $fl['name']);
            }
            inv_log($cid, $id, 'Entsorgt: ' . fmt_date($date) . ' · ' . $vendor . ($noData ? '' : ' · Datenlöschung bestätigt: ' . ($f('wipe_method', 190) ?: '–') . ($f('wipe_by', 190) !== '' ? ', ' . $f('wipe_by', 190) : '')));
            audit('inventory.dispose', $d['inv_no'] . ' · ' . $vendor, $cid);
            flash('success', t('Die Entsorgung ist dokumentiert.'));
            $back();
    }
    abort(400);
}

/** Karte „Lebenszyklus“ auf der Geräteseite */
function inv_lifecycle_card(int $cid, array $d, bool $edit): void
{
    $locations = rows('SELECT id, name FROM inv_locations WHERE company_id = ? AND active = 1 ORDER BY sort, name', [$cid]);
    $hos = rows('SELECT h.id, h.kind, h.handover_date, h.employee_name, h.status FROM inv_handovers h JOIN inv_handover_items i ON i.handover_id = h.id
        WHERE i.device_id = ? AND h.company_id = ? ORDER BY h.id DESC LIMIT 20', [$d['id'], $cid]);
    $act = fn(string $a) => '<input type="hidden" name="action" value="' . e($a) . '">'; ?>
    <div class="card">
        <h3><?= e(t('Lebenszyklus')) ?></h3>
        <?php if ($d['status'] === 'reparatur'): ?>
            <p><?= e(t('In Reparatur: {x}', ['x' => $d['repair_reason']])) ?><?= $d['repair_vendor'] !== '' ? ' · ' . e($d['repair_vendor']) : '' ?>
                <?= $d['repair_until'] !== '' ? '<br><span class="' . ($d['repair_until'] < date('Y-m-d') ? 'warn-text' : 'muted') . ' small">' . e(t('Rückgabe geplant: {d}', ['d' => fmt_date($d['repair_until'])])) . '</span>' : '' ?></p>
        <?php elseif ($d['status'] === 'ausgemustert'): ?>
            <p><?= e(t('Ausgemustert am {d}: {x}', ['d' => fmt_date($d['retired_at']), 'x' => $d['retire_reason']])) ?></p>
        <?php elseif ($d['status'] === 'entsorgt'): ?>
            <dl class="facts small">
                <dt><?= e(t('Entsorgt am')) ?></dt><dd><?= fmt_date($d['disposal_date']) ?></dd>
                <dt><?= e(t('Entsorger')) ?></dt><dd><?= e($d['disposal_vendor']) ?></dd>
                <dt><?= e(t('Datenlöschung')) ?></dt><dd><?= (int)$d['wipe_confirmed'] ? '✔ ' . e(trim($d['wipe_method'] . ($d['wipe_by'] !== '' ? ' · ' . $d['wipe_by'] : ''))) : e($d['wipe_method']) ?></dd>
            </dl>
        <?php endif; ?>
        <?php if ($edit): ?>
        <div class="btn-row">
            <?php if ($d['status'] === 'lager'): ?><a class="btn small primary" href="<?= e(iurl('inv_handover_new', ['kind' => 'ausgabe', 'device' => $d['id']])) ?>"><?= e(t('Ausgeben')) ?></a><?php endif; ?>
            <?php if ($d['status'] === 'einsatz' && $d['employee_id']): ?><a class="btn small" href="<?= e(iurl('inv_handover_new', ['kind' => 'rueckgabe', 'device' => $d['id']])) ?>"><?= e(t('Rücknahme')) ?></a><?php endif; ?>
            <a class="btn small" href="<?= e(iurl('inv_labels', ['ids' => $d['id']])) ?>" target="_blank" rel="noopener"><?= e(t('Etikett drucken')) ?></a>
        </div>
        <?php if (in_array($d['status'], ['lager', 'einsatz'], true)): ?>
            <details><summary><?= e(t('In Reparatur geben')) ?></summary>
            <form method="post" action="<?= e(iurl('inv_life', ['id' => $d['id']])) ?>" class="form"><?= csrf_field() ?><?= $act('repair_start') ?>
                <label><?= e(t('Grund *')) ?> <input name="repair_reason" maxlength="255" required></label>
                <label><?= e(t('Reparatur durch')) ?> <input name="repair_vendor" maxlength="190"></label>
                <label><?= e(t('Rückgabe geplant am')) ?> <input type="date" name="repair_until"></label>
                <button class="btn small"><?= e(t('In Reparatur geben')) ?></button></form></details>
        <?php endif; ?>
        <?php if ($d['status'] === 'reparatur'): ?>
            <form method="post" action="<?= e(iurl('inv_life', ['id' => $d['id']])) ?>" class="form"><?= csrf_field() ?><?= $act('repair_end') ?>
                <label><?= e(t('Ergebnis')) ?> <input name="repair_result" maxlength="255" placeholder="<?= e(t('z. B. Display getauscht')) ?>"></label>
                <?php if (!$d['employee_id']): ?><label><?= e(t('Zurück an Standort / Lager')) ?> <select name="location_id"><option value=""><?= e(t('– bisheriger Standort –')) ?></option>
                    <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <button class="btn small"><?= e(t('Aus der Reparatur zurück')) ?></button></form>
        <?php endif; ?>
        <?php if (in_array($d['status'], ['lager', 'einsatz', 'reparatur'], true)): ?>
            <details><summary><?= e(t('Ausmustern')) ?></summary>
            <form method="post" action="<?= e(iurl('inv_life', ['id' => $d['id']])) ?>" class="form"><?= csrf_field() ?><?= $act('retire') ?>
                <label><?= e(t('Grund *')) ?> <input name="retire_reason" maxlength="255" required placeholder="<?= e(t('z. B. defekt, veraltet, Leasingende')) ?>"></label>
                <label><?= e(t('Datum')) ?> <input type="date" name="retired_at" value="<?= date('Y-m-d') ?>"></label>
                <p class="muted small"><?= e(t('Die Zuordnung zum Mitarbeiter und die Lizenzplätze des Geräts werden freigegeben.')) ?></p>
                <button class="btn small" data-confirm="<?= e(t('Gerät ausmustern?')) ?>"><?= e(t('Ausmustern')) ?></button></form></details>
        <?php endif; ?>
        <?php if ($d['status'] === 'ausgemustert'): ?>
            <details open><summary><?= e(t('Entsorgen mit Nachweis')) ?></summary>
            <form method="post" action="<?= e(iurl('inv_life', ['id' => $d['id']])) ?>" enctype="multipart/form-data" class="form"><?= csrf_field() ?><?= $act('dispose') ?>
                <label><?= e(t('Entsorgt am')) ?> <input type="date" name="disposal_date" value="<?= date('Y-m-d') ?>"></label>
                <label><?= e(t('Entsorger *')) ?> <input name="disposal_vendor" maxlength="190" required></label>
                <label><?= e(t('Verfahren der Datenlöschung')) ?> <input name="wipe_method" maxlength="190" placeholder="<?= e(t('z. B. DIN 66399, Blancco, Festplatte geschreddert')) ?>"></label>
                <label><?= e(t('Gelöscht durch')) ?> <input name="wipe_by" maxlength="190"></label>
                <label class="check"><input type="checkbox" name="wipe_confirmed" value="1"> <?= e(t('Ich bestätige, dass alle Daten vollständig gelöscht wurden.')) ?></label>
                <label class="check"><input type="checkbox" name="no_data" value="1"> <?= e(t('Gerät speichert keine Daten')) ?></label>
                <label><?= e(t('Entsorgungsnachweis')) ?> <input type="file" name="files[]" multiple></label>
                <button class="btn small"><?= e(t('Entsorgung dokumentieren')) ?></button></form></details>
            <form method="post" action="<?= e(iurl('inv_life', ['id' => $d['id']])) ?>"><?= csrf_field() ?><?= $act('reactivate') ?>
                <button class="btn small"><?= e(t('Wieder in Betrieb nehmen')) ?></button></form>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($hos): ?>
            <h3><?= e(t('Protokolle')) ?></h3>
            <ul class="inv-log">
            <?php foreach ($hos as $h): ?><li><a href="<?= e(iurl('inv_handover', ['id' => $h['id']])) ?>"><?= e(t(INV_HO_KINDS[$h['kind']])) ?> · <?= fmt_date($h['handover_date']) ?> · <?= e($h['employee_name']) ?></a>
                <?= $h['status'] === 'offen' ? '<span class="badge inv-reparatur">' . e(t('nicht unterschrieben')) . '</span>' : '' ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php }

// ---------------------------------------------------------------------------
// QR-Code-Etiketten
// ---------------------------------------------------------------------------

function page_inv_labels(): void
{
    $cid = inv_company_id();
    if (inv_perm($cid) === INV_PERM_NONE) abort(403);
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
    $where = ['d.company_id = ?'];
    $p = [$cid];
    if ($ids) {
        $where[] = 'd.id IN (' . implode(',', array_slice($ids, 0, 500)) . ')';
    } else {
        $status = (string)($_GET['status'] ?? 'aktiv');
        if ($status === 'aktiv') $where[] = "d.status IN ('lager', 'einsatz', 'reparatur')";
        elseif (isset(INV_STATUSES[$status])) { $where[] = 'd.status = ?'; $p[] = $status; }
        if (query_int('type')) { $where[] = 'd.type_id = ?'; $p[] = query_int('type'); }
        if (query_int('location')) { $where[] = 'd.location_id = ?'; $p[] = query_int('location'); }
    }
    $list = rows('SELECT d.id, d.inv_no, d.manufacturer, d.model, d.serial, t.name AS type_name FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY d.inv_no LIMIT 500', $p);
    $co = row('SELECT name FROM companies WHERE id = ?', [$cid]);
    $base = rtrim((string)cfg('base_url', ''), '/');
    header('Content-Type: text/html; charset=utf-8'); ?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head><meta charset="utf-8"><title><?= e(t('Etiketten')) ?> · <?= e($co['name'] ?? '') ?></title>
<link rel="stylesheet" href="assets/style.css"><script src="index.php?r=i18n_js&amp;l=<?= e(current_lang()) ?>" defer></script><script src="assets/app.js" defer></script></head>
<body class="labels-page">
<div class="labels-bar no-print">
    <strong><?= e(t('{n} Etiketten', ['n' => count($list)])) ?></strong>
    <button type="button" class="btn primary" data-print><?= e(t('Drucken')) ?></button>
    <span class="muted small"><?= e(t('Format: 3 Spalten, passend für A4-Etikettenbögen 70 × 37 mm (Druck ohne Skalierung).')) ?></span>
</div>
<div class="labels">
<?php foreach ($list as $d): ?>
    <div class="label">
        <div class="label-qr"><?= QrCodeM::svg($base . '/index.php?r=inv_device&id=' . (int)$d['id'] . '&company=' . $cid, 96) ?></div>
        <div class="label-text"><strong><?= e($d['inv_no']) ?></strong><br><span><?= e(trim(($d['type_name'] ? t($d['type_name']) . ' · ' : '') . $d['manufacturer'] . ' ' . $d['model'])) ?></span>
            <?php if ($d['serial'] !== ''): ?><br><span class="small">S/N <?= e($d['serial']) ?></span><?php endif; ?><br><span class="small"><?= e($co['name'] ?? '') ?></span></div>
    </div>
<?php endforeach; ?>
</div>
</body></html>
<?php
    exit;
}
