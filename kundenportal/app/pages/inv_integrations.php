<?php
/**
 * App „Geräte & Lizenzen“ – Stufe 6: Anbindungen (Vorschau)
 *
 * Platzhalter für die spätere automatische Erfassung aus RMM- und Verwaltungssystemen, die wiederkehrende
 * Wartung und die Doku-App. Die Firma kann schon jetzt angeben, welche Systeme sie nutzt (companies.inv_tools);
 * der Dienstleister sieht diese Angaben firmenübergreifend und kann danach planen.
 *
 * Außerdem: Karte „Geräte & Lizenzen“ beim Mitarbeiter in der Mitarbeiterliste.
 */

declare(strict_types=1);

/** Bekannte Systeme: Schlüssel => [Name, was später übernommen wird] */
const INV_TOOLS = [
    'terra'  => ['Terra Management Cloud', 'Geräte, Seriennummern, Garantie und Lizenzen aus der Terra Cloud'],
    'ninja'  => ['NinjaOne', 'Geräte mit Betriebssystem, IP, zuletzt online, angemeldetem Benutzer und Software'],
    'prtg'   => ['PRTG', 'Netzwerkgeräte (Switches, Firewalls, Access Points) mit IP und Status'],
    'intune' => ['Microsoft Intune / Entra ID', 'Notebooks und Smartphones mit Benutzer, Compliance und BitLocker-Schlüssel; Microsoft-365-Lizenzen'],
    'tanss'  => ['TANSS', 'Geräte und Verträge aus TANSS; Tickets mit Gerätebezug'],
];

function inv_tools_of(array $co): array
{
    return array_values(array_intersect(array_filter(explode(',', (string)($co['inv_tools'] ?? ''))), array_keys(INV_TOOLS)));
}

function page_inv_integrations(): void
{
    $cid = inv_company_id();
    if (!inv_can_manage($cid)) abort(403, t('Die Anbindungen pflegen die Administratoren der Firma.'));
    $co = inv_company($cid);
    if (is_post()) {
        require_post_csrf();
        $tools = array_values(array_intersect((array)($_POST['tools'] ?? []), array_keys(INV_TOOLS)));
        $data = ['inv_tools' => implode(',', $tools), 'inv_tools_other' => mb_substr(trim((string)post('tools_other')), 0, 190)];
        update('companies', $cid, $data);
        audit('inventory.tools', json_encode($data, JSON_UNESCAPED_UNICODE), $cid);
        flash('success', t('Gespeichert. Wir melden uns, sobald die Anbindung verfügbar ist.'));
        iredirect('inv_integrations');
    }
    $mine = inv_tools_of($co);
    // Übersicht für den Dienstleister: welche Firma nutzt welches System
    $all = [];
    if (is_admin()) {
        $ids = admin_company_ids();
        foreach (rows("SELECT c.id, c.name, c.inv_tools, c.inv_tools_other FROM companies c JOIN company_apps ca ON ca.company_id = c.id JOIN apps a ON a.id = ca.app_id
            WHERE a.slug = 'inventar' AND c.active = 1 ORDER BY c.name") as $c) {
            if ($ids !== null && !in_array((int)$c['id'], $ids, true)) continue;
            $all[] = $c;
        }
    }

    render_page(t('Anbindungen') . ' · ' . t('Geräte & Lizenzen'), function () use ($cid, $co, $mine, $all) {
        echo company_switcher($cid, 'inv_integrations');
        inv_tabs('inv_integrations'); ?>
        <div class="alert info"><?= e(t('Diese Bereiche sind in Vorbereitung. Geben Sie schon jetzt an, welche Systeme Sie nutzen – so wissen wir, welche Anbindung wir zuerst bauen.')) ?></div>

        <form method="post" class="card form">
            <?= csrf_field() ?>
            <h2><?= e(t('Automatische Erfassung')) ?> <span class="tag"><?= e(t('demnächst')) ?></span></h2>
            <p class="muted"><?= e(t('Geräte und Lizenzen sollen regelmäßig aus diesen Systemen übernommen werden. Neue Geräte landen als Vorschlag im Inventar; bestehende werden über die Seriennummer erkannt und aktualisiert.')) ?></p>
            <div class="int-grid">
            <?php foreach (INV_TOOLS as $k => [$name, $what]): ?>
                <label class="int-card <?= in_array($k, $mine, true) ? 'on' : '' ?>">
                    <span class="int-head"><input type="checkbox" name="tools[]" value="<?= e($k) ?>" <?= in_array($k, $mine, true) ? 'checked' : '' ?>> <strong><?= e($name) ?></strong></span>
                    <span class="muted small"><?= e(t($what)) ?></span>
                    <span class="tag"><?= e(t('in Vorbereitung')) ?></span>
                </label>
            <?php endforeach; ?>
            </div>
            <label><?= e(t('Weiteres System, das Sie nutzen')) ?> <input name="tools_other" maxlength="190" value="<?= e((string)($co['inv_tools_other'] ?? '')) ?>" placeholder="<?= e(t('z. B. Datto RMM, Lansweeper, baramundi')) ?>"></label>
            <button class="btn primary"><?= e(t('Speichern')) ?></button>
        </form>

        <div class="grid2">
            <div class="card preview-card">
                <h2><?= e(t('Automatisierung')) ?> <span class="tag"><?= e(t('demnächst')) ?></span></h2>
                <p class="muted"><?= e(t('Wiederkehrende Aufgaben je Gerät oder Gerätetyp – mit Erinnerung und Nachweis im Geräteverlauf.')) ?></p>
                <table class="table small"><thead><tr><th><?= e(t('Aufgabe')) ?></th><th><?= e(t('Rhythmus')) ?></th><th><?= e(t('Nächster Termin')) ?></th></tr></thead><tbody>
                    <tr><td><?= e(t('Server-Updates einspielen')) ?></td><td><?= e(t('alle 3 Monate')) ?></td><td class="muted">–</td></tr>
                    <tr><td><?= e(t('DGUV-V3-Prüfung')) ?></td><td><?= e(t('jährlich')) ?></td><td class="muted">–</td></tr>
                    <tr><td><?= e(t('Firmware Firewall prüfen')) ?></td><td><?= e(t('monatlich')) ?></td><td class="muted">–</td></tr>
                </tbody></table>
                <p class="muted small"><?= e(t('Beispiel – hier noch ohne Funktion.')) ?></p>
            </div>
            <div class="card preview-card">
                <h2><?= e(t('Dokumentation')) ?> <span class="tag"><?= e(t('demnächst')) ?></span></h2>
                <p class="muted"><?= e(t('Zugangsdaten und Konfiguration gehören nicht ins Inventar, sondern in die spätere Doku-App. Dort werden sie verschlüsselt gespeichert und mit dem Gerät verknüpft.')) ?></p>
                <ul class="small">
                    <li><?= e(t('Zugangsdaten zu Geräten und Diensten (mit „Anzeigen“-Knopf und Protokoll)')) ?></li>
                    <li><?= e(t('Netzwerkplan, IP-Bereiche, VLANs')) ?></li>
                    <li><?= e(t('Konfiguration und Anleitungen je Gerät')) ?></li>
                </ul>
                <p class="muted small"><?= e(t('Beispiel – hier noch ohne Funktion.')) ?></p>
            </div>
        </div>

        <?php if ($all): ?>
        <div class="card">
            <h2><?= e(t('Genutzte Systeme aller Firmen')) ?></h2>
            <p class="muted small"><?= e(t('Nur für den IT-Dienstleister sichtbar: Grundlage, welche Anbindung zuerst gebaut wird.')) ?></p>
            <?php $sum = array_fill_keys(array_keys(INV_TOOLS), 0); foreach ($all as $c) foreach (inv_tools_of($c) as $k) $sum[$k]++; ?>
            <p><?php foreach (INV_TOOLS as $k => [$name]): ?><span class="tag"><?= e($name) ?>: <?= (int)$sum[$k] ?></span> <?php endforeach; ?></p>
            <table class="table"><thead><tr><th><?= e(t('Firma')) ?></th><th><?= e(t('Systeme')) ?></th><th><?= e(t('Weiteres')) ?></th></tr></thead><tbody>
            <?php foreach ($all as $c): $tl = inv_tools_of($c); ?>
                <tr><td><a href="<?= e(url('inv_integrations', ['company' => $c['id']])) ?>"><?= e($c['name']) ?></a></td>
                    <td><?= $tl ? e(implode(', ', array_map(fn($k) => INV_TOOLS[$k][0], $tl))) : '<span class="muted">–</span>' ?></td>
                    <td><?= e((string)$c['inv_tools_other']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php endif; ?>
    <?php });
}

/** Karte beim Mitarbeiter (Mitarbeiterliste): Geräte und Lizenzen, sofern die Firma die App hat und Einsicht besteht */
function inv_employee_card(array $emp): void
{
    $cid = (int)$emp['company_id'];
    if (!company_has_app($cid, INV_APP)) return;
    $GLOBALS['inv_cid'] = $cid;
    if (inv_perm($cid) === INV_PERM_NONE) return;
    $devices = rows('SELECT d.id, d.inv_no, d.manufacturer, d.model, d.status, t.name AS type_name FROM inv_devices d LEFT JOIN inv_types t ON t.id = d.type_id
        WHERE d.company_id = ? AND d.employee_id = ? ORDER BY d.inv_no', [$cid, $emp['id']]);
    $lics = rows('SELECT l.id, l.product FROM inv_license_assign a JOIN inv_licenses l ON l.id = a.license_id WHERE a.company_id = ? AND a.employee_id = ? ORDER BY l.product', [$cid, $emp['id']]);
    $open = rows("SELECT id, kind, handover_date FROM inv_handovers WHERE company_id = ? AND employee_id = ? AND status = 'offen' ORDER BY id DESC", [$cid, $emp['id']]); ?>
    <div class="card">
        <h2><?= e(t('Geräte & Lizenzen')) ?></h2>
        <?php if (!$devices && !$lics): ?><p class="muted"><?= e(t('Keine Geräte oder Lizenzen zugeordnet.')) ?></p><?php endif; ?>
        <?php if ($devices): ?><table class="table compact"><tbody>
            <?php foreach ($devices as $d): ?><tr><td><a href="<?= e(iurl('inv_device', ['id' => $d['id']])) ?>"><strong><?= e($d['inv_no']) ?></strong></a></td>
                <td><?= e($d['type_name'] !== null ? t($d['type_name']) : '–') ?></td><td><?= e(trim($d['manufacturer'] . ' ' . $d['model'])) ?></td>
                <td><span class="badge inv-<?= e($d['status']) ?>"><?= e(t(INV_STATUSES[$d['status']] ?? $d['status'])) ?></span></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
        <?php if ($lics): ?><p><?= e(t('Lizenzen:')) ?> <?php foreach ($lics as $i => $l): ?><?= $i ? ', ' : '' ?><a href="<?= e(iurl('inv_license', ['id' => $l['id']])) ?>"><?= e($l['product']) ?></a><?php endforeach; ?></p><?php endif; ?>
        <?php foreach ($open as $h): ?><p><span class="tag warn"><?= e(t('Protokoll nicht unterschrieben')) ?></span> <a href="<?= e(iurl('inv_handover', ['id' => $h['id']])) ?>"><?= e(t(INV_HO_KINDS[$h['kind']] ?? $h['kind'])) ?> · <?= fmt_date($h['handover_date']) ?></a></p><?php endforeach; ?>
        <?php if (inv_perm($cid) >= INV_PERM_EDIT && $emp['status'] === 'aktiv'): ?><p><a class="btn small" href="<?= e(iurl('inv_handover_new', ['kind' => 'ausgabe', 'employee' => $emp['id']])) ?>"><?= e(t('Geräte ausgeben')) ?></a></p><?php endif; ?>
    </div>
<?php }
