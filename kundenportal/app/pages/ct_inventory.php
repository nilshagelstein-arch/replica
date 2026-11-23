<?php
/**
 * App „Verträge & Laufzeiten“ – Stufe 2: Verbindung zum Inventar („Geräte & Lizenzen“)
 *
 * - Ansicht: Abo- und Wartungslizenzen aus dem Inventar erscheinen in der Vertragsliste („aus dem Inventar“),
 *   gepflegt werden sie weiter im Inventar. Sie zählen in den laufenden Kosten mit. Ist eine Lizenz mit einem
 *   Vertrag verknüpft, deckt der Vertrag sie ab und sie erscheint nicht zusätzlich (nichts doppelt).
 *   Sichtbar nur mit Leserecht im Inventar; abschaltbar je Firma (companies.ct_inv_licenses).
 * - Verknüpfung: Ein Vertrag gehört zu Geräten und/oder Lizenzen (ct_links). Beim Gerät bzw. bei der Lizenz
 *   steht umgekehrt der Vertrag. Beim Ausmustern/Entsorgen eines Geräts mit laufendem Vertrag kommt ein Hinweis.
 */

declare(strict_types=1);

/** Hat die Firma die Inventar-App? */
function ct_inv_available(int $cid): bool
{
    return function_exists('inv_perm') && company_has_app($cid, 'inventar');
}

/** Darf der angemeldete Benutzer das Inventar der Firma sehen? */
function ct_inv_can_view(int $cid): bool
{
    return ct_inv_available($cid) && inv_perm($cid) >= INV_PERM_VIEW;
}

/** Link in eine andere App der Firma (für Dienstleister mit Firmenangabe) */
function ct_app_url(int $cid, string $route, array $params = []): string
{
    return url($route, (is_admin() ? ['company' => $cid] : []) + $params);
}

/**
 * Abo- und Wartungslizenzen aus dem Inventar als Zeilen der Vertragsliste (gleiche Felder wie ein Vertrag).
 * Lizenzen, die mit einem Vertrag verknüpft sind, fehlen hier bewusst.
 */
function ct_inv_license_rows(int $cid, string $search = ''): array
{
    if (!ct_inv_can_view($cid) || !(int)(ct_company($cid)['ct_inv_licenses'] ?? 1)) return [];
    inv_lic_renew($cid);
    $p = [$cid];
    $w = '';
    if ($search !== '') {
        $w = ' AND (l.product LIKE ? OR l.manufacturer LIKE ? OR l.supplier LIKE ? OR l.contract_no LIKE ?)';
        for ($i = 0; $i < 4; $i++) $p[] = '%' . $search . '%';
    }
    $out = [];
    foreach (rows("SELECT l.* FROM inv_licenses l WHERE l.company_id = ? AND l.kind IN ('abo', 'wartung')
        AND NOT EXISTS (SELECT 1 FROM ct_links k WHERE k.license_id = l.id AND k.company_id = l.company_id)$w ORDER BY l.product", $p) as $l) {
        $st = inv_lic_state($l);
        $status = match ($st) { 'gekuendigt' => 'gekuendigt', 'beendet', 'abgelaufen' => 'beendet', default => 'aktiv' };
        $out[] = ['_inv' => true, 'id' => (int)$l['id'], 'title' => (string)$l['product'], 'contract_no' => (string)$l['contract_no'],
            'type_name' => INV_LIC_KINDS[$l['kind']] ?? $l['kind'], 'partner' => $l['supplier'] !== '' ? (string)$l['supplier'] : (string)$l['manufacturer'],
            'status' => $status, 'cancelled_to' => $status === 'gekuendigt' ? (string)$l['end_date'] : '', 'end_date' => (string)$l['end_date'],
            'auto_renew' => $status === 'aktiv' ? (int)$l['auto_renew'] : 0, 'renew_months' => (int)$l['renew_months'],
            'notice_value' => (int)$l['notice_value'], 'notice_unit' => (string)$l['notice_unit'],
            'cost_cents' => $l['cost_cents'], 'cost_interval' => (string)$l['cost_interval'], 'managed_by' => '', 'provider_name' => null];
    }
    return $out;
}

/** Beschriftung eines verknüpften Geräts bzw. einer Lizenz */
function ct_link_label(array $k): string
{
    if ($k['device_id']) return trim($k['inv_no'] . ' · ' . trim($k['manufacturer'] . ' ' . $k['model']), ' ·');
    return (string)$k['product'];
}

/** Karte „Geräte und Lizenzen“ im Vertrag */
function ct_links_card(int $cid, array $c, bool $edit): void
{
    if (!ct_inv_available($cid)) return;
    $view = ct_inv_can_view($cid);
    $items = rows('SELECT k.id, k.device_id, k.license_id, d.inv_no, d.manufacturer, d.model, d.status AS device_status, l.product, l.kind AS license_kind, l.status AS license_status
        FROM ct_links k LEFT JOIN inv_devices d ON d.id = k.device_id LEFT JOIN inv_licenses l ON l.id = k.license_id
        WHERE k.contract_id = ? AND k.company_id = ? ORDER BY k.license_id IS NULL DESC, d.inv_no, l.product', [$c['id'], $cid]);
    $devices = $licenses = [];
    if ($edit && $view) {
        $linkedD = array_filter(array_map(fn($k) => (int)$k['device_id'], $items));
        $linkedL = array_filter(array_map(fn($k) => (int)$k['license_id'], $items));
        foreach (rows("SELECT id, inv_no, manufacturer, model FROM inv_devices WHERE company_id = ? AND status <> 'entsorgt' ORDER BY inv_no", [$cid]) as $d) {
            if (!in_array((int)$d['id'], $linkedD, true)) $devices[] = $d;
        }
        foreach (rows("SELECT id, product FROM inv_licenses WHERE company_id = ? AND status <> 'beendet' ORDER BY product", [$cid]) as $l) {
            if (!in_array((int)$l['id'], $linkedL, true)) $licenses[] = $l;
        }
    } ?>
    <div class="card">
        <h3><?= e(t('Geräte und Lizenzen')) ?></h3>
        <?php if ($items): ?><ul class="inv-log">
            <?php foreach ($items as $k): $label = ct_link_label($k); ?>
                <li><span class="tag"><?= e($k['device_id'] ? t('Gerät') : t('Lizenz')) ?></span>
                    <?php if ($view): ?><a href="<?= e(ct_app_url($cid, $k['device_id'] ? 'inv_device' : 'inv_license', ['id' => $k['device_id'] ?: $k['license_id']])) ?>"><?= e($label) ?></a><?php else: ?><?= e($label) ?><?php endif; ?>
                    <?php if ($k['device_id'] && in_array($k['device_status'], ['ausgemustert', 'entsorgt'], true)): ?><span class="tag warn"><?= e(t(INV_STATUSES[$k['device_status']])) ?></span><?php endif; ?>
                    <?php if ($k['license_id'] && $k['license_status'] === 'beendet'): ?><span class="tag warn"><?= e(t('Beendet')) ?></span><?php endif; ?>
                    <?php if ($edit): ?><form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="inline-form inline-mini"><?= csrf_field() ?><input type="hidden" name="action" value="link_remove"><input type="hidden" name="link_id" value="<?= (int)$k['id'] ?>">
                        <button class="btn small danger" data-confirm="<?= e(t('Verknüpfung mit „{x}“ entfernen?', ['x' => $label])) ?>" aria-label="<?= e(t('Verknüpfung entfernen')) ?>">✕</button></form><?php endif; ?></li>
            <?php endforeach; ?></ul>
        <?php else: ?><p class="muted"><?= e(t('Mit keinem Gerät und keiner Lizenz verknüpft.')) ?></p><?php endif; ?>
        <?php if ($edit && $view && ($devices || $licenses)): ?>
        <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="link_add">
            <label><?= e(t('Verknüpfen mit')) ?> <select name="target" required><option value=""><?= e(t('– bitte wählen –')) ?></option>
                <?php if ($devices): ?><optgroup label="<?= e(t('Geräte')) ?>"><?php foreach ($devices as $d): ?><option value="d<?= (int)$d['id'] ?>"><?= e(trim($d['inv_no'] . ' · ' . trim($d['manufacturer'] . ' ' . $d['model']), ' ·')) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                <?php if ($licenses): ?><optgroup label="<?= e(t('Lizenzen')) ?>"><?php foreach ($licenses as $l): ?><option value="l<?= (int)$l['id'] ?>"><?= e($l['product']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
            </select></label>
            <button class="btn small"><?= e(t('Verknüpfen')) ?></button>
        </form>
        <p class="muted small"><?= e(t('z. B. Mobilfunkvertrag zu den Smartphones, Wartungsvertrag zur Firewall. Verknüpfte Lizenzen erscheinen nicht zusätzlich in der Vertragsliste.')) ?></p>
        <?php elseif ($edit && !$view): ?><p class="muted small"><?= e(t('Zum Verknüpfen brauchen Sie Leserecht in „Geräte & Lizenzen“.')) ?></p><?php endif; ?>
    </div>
<?php }

/** Aktionen „link_add“ / „link_remove“ aus page_contract_action; true = erledigt */
function ct_links_action(int $cid, array $c, string $action): bool
{
    if ($action === 'link_add') {
        if (!ct_inv_can_view($cid)) abort(403);
        $target = (string)post('target');
        if (!preg_match('/^([dl])(\d+)$/', $target, $m)) { flash('error', t('Bitte ein Gerät oder eine Lizenz wählen.')); return true; }
        $isDev = $m[1] === 'd';
        $item = $isDev ? row('SELECT id, inv_no, manufacturer, model FROM inv_devices WHERE id = ? AND company_id = ?', [(int)$m[2], $cid])
            : row('SELECT id, product FROM inv_licenses WHERE id = ? AND company_id = ?', [(int)$m[2], $cid]);
        if (!$item) abort(400);
        $col = $isDev ? 'device_id' : 'license_id';
        if (row("SELECT id FROM ct_links WHERE contract_id = ? AND $col = ?", [$c['id'], $item['id']])) return true;
        insert('ct_links', ['company_id' => $cid, 'contract_id' => $c['id'], $col => $item['id'], 'user_id' => current_user()['id'], 'created_at' => now()]);
        $label = $isDev ? trim($item['inv_no'] . ' · ' . trim($item['manufacturer'] . ' ' . $item['model']), ' ·') : $item['product'];
        ct_log($cid, (int)$c['id'], ($isDev ? 'Verknüpft mit Gerät: ' : 'Verknüpft mit Lizenz: ') . $label);
        if ($isDev) inv_log($cid, (int)$item['id'], 'Vertrag verknüpft: ' . $c['title']);
        else inv_lic_log($cid, (int)$item['id'], 'Vertrag verknüpft: ' . $c['title']);
        flash('success', t('Verknüpft mit „{x}“.', ['x' => $label]));
        return true;
    }
    if ($action === 'link_remove') {
        $k = row('SELECT k.*, d.inv_no, d.manufacturer, d.model, l.product FROM ct_links k LEFT JOIN inv_devices d ON d.id = k.device_id LEFT JOIN inv_licenses l ON l.id = k.license_id
            WHERE k.id = ? AND k.contract_id = ? AND k.company_id = ?', [(int)post('link_id', 0), $c['id'], $cid]);
        if (!$k) return true;
        q('DELETE FROM ct_links WHERE id = ?', [$k['id']]);
        ct_log($cid, (int)$c['id'], 'Verknüpfung entfernt: ' . ct_link_label($k));
        if ($k['device_id'] && function_exists('inv_log')) inv_log($cid, (int)$k['device_id'], 'Vertrag nicht mehr verknüpft: ' . $c['title']);
        if ($k['license_id'] && function_exists('inv_lic_log')) inv_lic_log($cid, (int)$k['license_id'], 'Vertrag nicht mehr verknüpft: ' . $c['title']);
        flash('success', t('Die Verknüpfung wurde entfernt.'));
        return true;
    }
    return false;
}

/** Verknüpfte Verträge eines Geräts bzw. einer Lizenz (nur, wenn der Benutzer die Verträge sehen darf) */
function ct_contracts_for_item(int $cid, string $kind, int $id): ?array
{
    if (!company_has_app($cid, CT_APP) || ct_perm($cid) < CT_PERM_VIEW) return null;
    $col = $kind === 'device' ? 'device_id' : 'license_id';
    return rows("SELECT c.* FROM ct_links k JOIN contracts c ON c.id = k.contract_id WHERE k.company_id = ? AND k.$col = ? ORDER BY c.title", [$cid, $id]);
}

/** Karte „Verträge“ beim Gerät bzw. bei der Lizenz im Inventar */
function ct_inventory_card(int $cid, string $kind, int $id): void
{
    $list = ct_contracts_for_item($cid, $kind, $id);
    if ($list === null) return; ?>
    <div class="card">
        <h3><?= e(t('Verträge')) ?></h3>
        <?php if ($list): ?><ul class="inv-log">
            <?php foreach ($list as $c): $dl = ct_deadlines($c); ?>
                <li><a href="<?= e(ct_app_url($cid, 'contract', ['id' => $c['id']])) ?>"><?= e($c['title']) ?></a> <?= ct_status_badge($c) ?>
                    <span class="muted small">· <?= e($c['partner']) ?><?php if ($dl['possible'] !== ''): ?> · <?= e(t('bis')) ?> <?= fmt_date($dl['possible']) ?><?php endif; ?>
                    <?php if ($dl['by'] !== ''): ?> · <?= e(t('kündigen bis {d}', ['d' => fmt_date($dl['by'])])) ?><?php endif; ?></span></li>
            <?php endforeach; ?></ul>
        <?php else: ?><p class="muted"><?= e(t('Kein Vertrag verknüpft. Verknüpfen können Sie im Vertrag unter „Geräte und Lizenzen“.')) ?></p><?php endif; ?>
    </div>
<?php }

/** Hinweis beim Ausmustern/Entsorgen eines Geräts, zu dem noch ein Vertrag läuft */
function ct_retire_hint(int $cid, int $deviceId): void
{
    $list = ct_contracts_for_item($cid, 'device', $deviceId);
    foreach ($list ?? [] as $c) {
        if (!in_array($c['status'], CT_RUNNING, true)) continue;
        $dl = ct_deadlines($c);
        flash('info', $dl['by'] !== ''
            ? t('Zu diesem Gerät läuft noch der Vertrag „{x}“ (kündigen bis {d}). Vertrag kündigen?', ['x' => $c['title'], 'd' => fmt_date($dl['by'])])
            : t('Zu diesem Gerät läuft noch der Vertrag „{x}“. Vertrag kündigen?', ['x' => $c['title']]));
    }
}
