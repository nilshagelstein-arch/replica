<?php
/**
 * App „Verträge & Laufzeiten“ – Stufe 5: Angebot anfragen und Vertriebsübersicht
 *
 * - „Angebot anfragen“ im Vertrag (Recht „bearbeiten“): Es entsteht ein Ticket in der Standard-Queue der Firma, das
 *   sofort an den betreuenden Dienstleister übergeben wird (Portal-Betreiber oder ein Dienstleister aus den Tickets;
 *   betreut die Firma den Vertrag selbst, wählt sie den Empfänger). Ohne App „Tickets“ geht die Anfrage per E-Mail.
 *   Jede Anfrage steht in ct_offers und im Verlauf des Vertrags.
 * - Vertriebsübersicht für den Portal-Betreiber (Bereich „contracts“): Verträge aller Firmen mit eingeschalteter
 *   Einsicht, deren Ende oder Kündigungsfrist in den nächsten 3/6/12 Monaten liegt. Nur Verträge, die der
 *   Portal-Betreiber betreut, oder alle, wenn die Firma das freigibt (companies.ct_provider_all).
 *   Kosten nur mit companies.ct_provider_costs. CSV-Export.
 */

declare(strict_types=1);

/** Mögliche Empfänger einer Anfrage: ['it' => Name, 'p<ID>' => Name, …] */
function ct_offer_targets(int $cid): array
{
    $out = ['it' => t('IT-Dienstleister (Portal-Betreiber)')];
    foreach (rows('SELECT id, name FROM ticket_providers WHERE company_id = ? AND active = 1 ORDER BY name', [$cid]) as $p) $out['p' . $p['id']] = $p['name'];
    return $out;
}

/** Standard-Empfänger eines Vertrags oder '' (Firma betreut selbst) */
function ct_offer_default(array $c): string
{
    if ($c['managed_by'] === 'it') return 'it';
    if ($c['managed_by'] === 'provider' && $c['provider_id']) return 'p' . (int)$c['provider_id'];
    return '';
}

/** Vorschlag für den Anfragetext */
function ct_offer_text(array $c, array $dl): string
{
    if ($dl['by'] !== '') return t('„{x}“ verlängert sich zum {e}, Kündigung bis {b}. Bitte vergleichen und ein Angebot machen.', ['x' => $c['title'], 'e' => fmt_date($dl['possible']), 'b' => fmt_date($dl['by'])]);
    if ($dl['end'] !== '') return t('„{x}“ läuft am {e} aus. Bitte vergleichen und ein Angebot machen.', ['x' => $c['title'], 'e' => fmt_date($dl['end'])]);
    return t('Bitte für „{x}“ vergleichen und ein Angebot machen.', ['x' => $c['title']]);
}

/** Karte „Angebot anfragen“ im Vertrag */
function ct_offer_card(int $cid, array $c, bool $edit, bool $costs): void
{
    $offers = rows('SELECT o.*, u.name AS user_name, t.number AS ticket_number FROM ct_offers o LEFT JOIN users u ON u.id = o.user_id LEFT JOIN tickets t ON t.id = o.ticket_id
        WHERE o.company_id = ? AND o.contract_id = ? ORDER BY o.id DESC LIMIT 5', [$cid, $c['id']]);
    if (!$edit && !$offers) return;
    if (!in_array($c['status'], array_merge(CT_RUNNING, ['gekuendigt']), true) && !$offers) return;
    $dl = ct_deadlines($c);
    $soon = ($dl['by'] !== '' && $dl['by'] <= date('Y-m-d', strtotime('+' . ct_lead_days($cid) . ' days')))
        || ($dl['by'] === '' && $dl['end'] !== '' && $dl['end'] <= date('Y-m-d', strtotime('+' . ct_lead_days($cid) . ' days')));
    $targets = ct_offer_targets($cid);
    $def = ct_offer_default($c);
    $tickets = company_has_app($cid, 'tickets') && row('SELECT id FROM ticket_queues WHERE company_id = ? LIMIT 1', [$cid]); ?>
    <div class="card<?= $soon && $edit ? ' due-box' : '' ?>">
        <h3><?= e(t('Angebot anfragen')) ?></h3>
        <?php if ($offers): ?><ul class="inv-log">
            <?php foreach ($offers as $o): ?><li><span class="muted small"><?= fmt_dt($o['created_at']) ?> · <?= e($o['user_name'] ?? '–') ?></span><br>
                <?= e(t('an {x}', ['x' => $o['target_name']])) ?>
                <?php if ($o['ticket_id']): ?> · <a href="<?= e(ct_app_url($cid, 'ticket', ['id' => $o['ticket_id']])) ?>"><?= e(t('Ticket #{n}', ['n' => $o['ticket_number'] ?? $o['ticket_id']])) ?></a><?php else: ?> · <?= e(t('per E-Mail')) ?><?php endif; ?></li><?php endforeach; ?>
        </ul><?php endif; ?>
        <?php if ($edit && in_array($c['status'], array_merge(CT_RUNNING, ['gekuendigt']), true)): ?>
        <?php if ($soon): ?><p class="small"><strong><?= e(t('Der Vertrag läuft bald aus – jetzt ist ein guter Zeitpunkt für ein Vergleichsangebot.')) ?></strong></p><?php endif; ?>
        <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="offer_request">
            <label><?= e(t('An')) ?> <select name="target" required>
                <?php if ($def === ''): ?><option value=""><?= e(t('– bitte wählen –')) ?></option><?php endif; ?>
                <?php foreach ($targets as $k => $l): ?><option value="<?= e($k) ?>" <?= $def === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select></label>
            <label><?= e(t('Nachricht')) ?> <textarea name="message" rows="3" maxlength="4000" required><?= e(ct_offer_text($c, $dl)) ?></textarea></label>
            <?php if ($costs): ?><label class="check"><input type="checkbox" name="with_costs" value="1"> <?= e(t('Aktuelle Kosten mitsenden')) ?></label><?php endif; ?>
            <button class="btn <?= $soon ? 'primary' : '' ?>"><?= e(t('Angebot anfragen')) ?></button>
            <p class="muted small"><?= e($tickets ? t('Es entsteht ein Ticket, das direkt an den Dienstleister übergeben wird. Antworten sehen Sie im Ticket.')
                : t('Die Anfrage geht per E-Mail an den Dienstleister; Antworten kommen an Sie.')) ?></p>
        </form>
        <?php endif; ?>
    </div>
<?php }

/** Inhalt der Anfrage (Vertragsdaten, auf Wunsch Kosten, Nachricht) */
function ct_offer_body(int $cid, array $c, string $message, bool $withCosts, bool $withLink): string
{
    $dl = ct_deadlines($c);
    $lines = [$message, '', t('Vertrag') . ': ' . $c['title'], t('Vertragspartner') . ': ' . $c['partner']];
    if ($c['type_name'] !== null) $lines[] = t('Vertragsart') . ': ' . t((string)$c['type_name']);
    if ($c['contract_no'] !== '') $lines[] = t('Vertragsnummer') . ': ' . $c['contract_no'];
    if ($c['customer_no'] !== '') $lines[] = t('Kundennummer') . ': ' . $c['customer_no'];
    if ($c['start_date'] !== '') $lines[] = t('Beginn') . ': ' . fmt_date($c['start_date']);
    if ($dl['end'] !== '') $lines[] = t('Ende des laufenden Zeitraums') . ': ' . fmt_date($dl['end']);
    if ($dl['by'] !== '') $lines[] = t('Kündigen bis') . ': ' . fmt_date($dl['by']) . ' (' . t('zum {d}', ['d' => fmt_date($dl['possible'])]) . ')';
    if ((int)$c['auto_renew']) $lines[] = t('Verlängerung') . ': ' . t('automatisch um {n} Monate', ['n' => (int)$c['renew_months']]);
    if (ct_notice_text($c) !== '') $lines[] = t('Kündigungsfrist') . ': ' . ct_notice_text($c);
    if ($withCosts) {
        $p = ct_price_at($c, ct_prices_for($cid, [(int)$c['id']])[(int)$c['id']] ?? []);
        if ($p['cents'] !== null) $lines[] = t('Aktuelle Kosten') . ': ' . ct_fmt_price($p['cents']) . ' ' . t(CT_INTERVALS[$p['interval']] ?? '');
    }
    if ($withLink) $lines[] = "\n" . rtrim((string)cfg('base_url', ''), '/') . '/index.php?r=contract&id=' . $c['id'] . '&company=' . $cid;
    return implode("\n", $lines);
}

/** Aktion „offer_request“ aus page_contract_action; true = erledigt */
function ct_offer_action(int $cid, array $c, string $action): bool
{
    if ($action !== 'offer_request') return false;
    $u = current_user();
    $back = fn() => ct_redirect('contract', ['id' => $c['id']]);
    $target = (string)post('target');
    $targets = ct_offer_targets($cid);
    if (!isset($targets[$target])) { flash('error', t('Bitte wählen, an wen die Anfrage geht.')); $back(); }
    $message = mb_substr(trim(str_replace("\r", '', (string)post('message'))), 0, 4000);
    if ($message === '') { flash('error', t('Bitte eine Nachricht eingeben.')); $back(); }
    $withCosts = ct_can_costs($cid) && post('with_costs') === '1';
    $prov = $target === 'it' ? null : row('SELECT * FROM ticket_providers WHERE id = ? AND company_id = ? AND active = 1', [(int)substr($target, 1), $cid]);
    if ($target !== 'it' && !$prov) abort(400);
    $name = $targets[$target];
    $dl = ct_deadlines($c);
    $subject = $dl['by'] !== '' ? t('Angebot: {x} – Kündigung bis {b}', ['x' => $c['title'], 'b' => fmt_date($dl['by'])])
        : ($dl['end'] !== '' ? t('Angebot: {x} läuft am {e} aus', ['x' => $c['title'], 'e' => fmt_date($dl['end'])]) : t('Angebot: {x}', ['x' => $c['title']]));
    $subject = mb_substr($subject, 0, 190);
    $queue = company_has_app($cid, 'tickets')
        ? (row('SELECT q.id FROM ticket_queues q JOIN companies c ON c.ticket_default_queue_id = q.id WHERE c.id = ? AND q.company_id = ?', [$cid, $cid])
            ?? row('SELECT id FROM ticket_queues WHERE company_id = ? ORDER BY sort, id LIMIT 1', [$cid]))
        : null;
    $ticketId = null;
    if ($queue) {
        // Ticket anlegen und sofort an den Dienstleister übergeben
        $body = ct_offer_body($cid, $c, $message, $withCosts, false);
        $ticketId = ticket_create_record($cid, (int)$queue['id'], (int)$u['id'], (int)$u['id'], $subject, $body, 'frage');
        update('tickets', $ticketId, ['provider_id' => $prov ? (int)$prov['id'] : null, 'provider_internal' => $prov ? 0 : 1, 'provider_since' => now(), 'category' => '']);
        $tk = ticket_set_status_sys(load_ticket($ticketId), 'beim_dienstleister', 'Übergeben an: ' . ($prov ? $prov['name'] : 'IT-Dienstleister (Portal-Betreiber)'), (int)$u['id'], false);
        ticket_log($ticketId, $cid, (int)$u['id'], 'event', 'Angebot angefragt aus „Verträge & Laufzeiten“: ' . $c['title']);
        $company = (string)ct_company($cid)['name'];
        ticket_notify_provider($tk, fn() => t("{company} bittet um ein Angebot.\nVon: {name}\n\n", ['company' => $company, 'name' => $u['name']]) . $body);
    } else {
        // ohne App „Tickets“: E-Mail an den Dienstleister, Antworten an den Anfragenden
        $to = $prov ? array_values(array_filter([(string)$prov['email']])) : (array)cfg('mail.admin_recipients', []);
        if ($prov && $prov['user_id'] && ($pu = row('SELECT email FROM users WHERE id = ? AND active = 1', [$prov['user_id']]))) $to[] = $pu['email'];
        $to = array_values(array_unique(array_filter(array_map('strtolower', $to))));
        if (!$to) { flash('error', t('Für {x} ist keine E-Mail-Adresse hinterlegt.', ['x' => $name])); $back(); }
        $GLOBALS['mail_reply_to'] = (string)$u['email'];
        $company = (string)ct_company($cid)['name'];
        $withLink = $target === 'it' && (int)(ct_company($cid)['ct_provider_access'] ?? 0) === 1;
        send_mail_t($to, fn() => [$subject . ' – ' . $company, t("{company} bittet um ein Angebot.\nVon: {name} ({email})\n\n", ['company' => $company, 'name' => $u['name'], 'email' => $u['email']])
            . ct_offer_body($cid, $c, $message, $withCosts, $withLink)], $cid);
        unset($GLOBALS['mail_reply_to']);
    }
    insert('ct_offers', ['company_id' => $cid, 'contract_id' => $c['id'], 'target' => $target, 'target_name' => mb_substr($name, 0, 190), 'ticket_id' => $ticketId,
        'with_costs' => $withCosts ? 1 : 0, 'message' => $message, 'user_id' => $u['id'], 'created_at' => now()]);
    ct_log($cid, (int)$c['id'], 'Angebot angefragt bei: ' . $name . ($ticketId ? ' (Ticket #' . (row('SELECT number FROM tickets WHERE id = ?', [$ticketId])['number'] ?? $ticketId) . ')' : ' (E-Mail)'));
    audit('contract.offer', $c['title'] . ' → ' . $name, $cid);
    flash('success', $ticketId ? t('Die Anfrage ist als Ticket an {x} übergeben.', ['x' => $name]) : t('Die Anfrage wurde per E-Mail an {x} gesendet.', ['x' => $name]));
    $back();
}

// ---------------------------------------------------------------------------
// Vertriebsübersicht (nur Portal-Betreiber)
// ---------------------------------------------------------------------------

function ct_sales_filter(): array
{
    $months = (int)($_GET['months'] ?? 6);
    if (!in_array($months, [3, 6, 12], true)) $months = 6;
    return ['months' => $months, 'company' => query_int('firma'), 'type' => mb_substr(trim((string)($_GET['type'] ?? '')), 0, 120),
        'partner' => mb_substr(trim((string)($_GET['partner'] ?? '')), 0, 190), 'scope' => ($_GET['scope'] ?? '') === 'alle' ? 'alle' : 'meine'];
}

/** Zeilen der Vertriebsübersicht */
function ct_sales_rows(array $f): array
{
    $u = current_user();
    $ids = admin_company_ids($u);
    $today = date('Y-m-d');
    $until = date('Y-m-d', strtotime("+{$f['months']} months"));
    $companies = rows("SELECT c.id, c.name, c.ct_provider_costs, c.ct_provider_all FROM companies c JOIN company_apps ca ON ca.company_id = c.id JOIN apps a ON a.id = ca.app_id
        WHERE a.slug = ? AND a.active = 1 AND c.active = 1 AND c.ct_provider_access = 1 ORDER BY c.name", [CT_APP]);
    $out = [];
    foreach ($companies as $co) {
        $cid = (int)$co['id'];
        if ($ids !== null && !in_array($cid, $ids, true)) continue;
        if ($f['company'] && $f['company'] !== $cid) continue;
        $all = (int)$co['ct_provider_all'] === 1;
        $where = 'c.company_id = ? AND c.status IN ' . CT_OPEN_SQL . ($all ? '' : " AND c.managed_by = 'it'");
        if ($f['scope'] === 'meine') $where .= " AND c.managed_by = 'it'";
        $p = [$cid];
        if ($f['partner'] !== '') { $where .= ' AND c.partner = ?'; $p[] = $f['partner']; }
        if ($f['type'] !== '') { $where .= ' AND t.name = ?'; $p[] = $f['type']; }
        $list = rows("SELECT c.*, t.name AS type_name, pr.name AS provider_name FROM contracts c LEFT JOIN ct_types t ON t.id = c.type_id
            LEFT JOIN ticket_providers pr ON pr.id = c.provider_id WHERE $where", $p);
        $prices = (int)$co['ct_provider_costs'] === 1 ? ct_prices_for($cid) : [];
        foreach ($list as $c) {
            $dl = ct_deadlines($c, $today);
            $date = $dl['by'] !== '' ? $dl['by'] : ($dl['end'] !== '' ? $dl['end'] : '');
            if ($date === '' || $date < $today || $date > $until) continue;
            $offer = row('SELECT created_at, target_name FROM ct_offers WHERE company_id = ? AND contract_id = ? ORDER BY id DESC LIMIT 1', [$cid, $c['id']]);
            $price = (int)$co['ct_provider_costs'] === 1 ? ct_price_at($c, $prices[(int)$c['id']] ?? [], $today) : null;
            $out[] = ['company_id' => $cid, 'company' => $co['name'], 'c' => $c, 'date' => $date, 'kind' => $dl['by'] !== '' ? 'cancel' : 'end', 'dl' => $dl,
                'price' => $price, 'offer' => $offer ?: null];
        }
    }
    usort($out, fn($a, $b) => [$a['date'], $a['company'], $a['c']['title']] <=> [$b['date'], $b['company'], $b['c']['title']]);
    return $out;
}

function page_contract_sales(): void
{
    $u = require_login();
    if (!is_staff_role($u['role'])) abort(403);
    require_area('contracts');
    $f = ct_sales_filter();
    $list = ct_sales_rows($f);
    if (($_GET['export'] ?? '') === 'csv') {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, [t('Firma'), t('Vertrag'), t('Art'), t('Vertragspartner'), t('Vertragsnummer'), t('Betreuung'), t('Kündigen bis'), t('Ende'), t('Kosten'), t('Zeitraum'), t('Angebot angefragt')], ';', '"', '');
        foreach ($list as $r) {
            $c = $r['c'];
            fputcsv($fh, array_map('csv_cell_safe', [$r['company'], $c['title'], $c['type_name'] !== null ? t((string)$c['type_name']) : '', $c['partner'], $c['contract_no'],
                ct_managed_label($c), $r['dl']['by'] !== '' ? fmt_date($r['dl']['by']) : '', $r['dl']['possible'] !== '' ? fmt_date($r['dl']['possible']) : '',
                $r['price'] && $r['price']['cents'] !== null ? number_format($r['price']['cents'] / 100, 2, ',', '') : '', $r['price'] ? t(CT_INTERVALS[$r['price']['interval']] ?? '') : '',
                $r['offer'] ? fmt_date(substr((string)$r['offer']['created_at'], 0, 10)) : '']), ';', '"', '');
        }
        rewind($fh);
        $csv = "\xEF\xBB\xBF" . stream_get_contents($fh);
        audit('contract.sales_export', count($list) . ' Zeilen');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="vertriebsuebersicht-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: private, no-store');
        echo $csv;
        exit;
    }
    // Auswahllisten aus den sichtbaren Verträgen (ohne Zeitraum-Filter)
    $all = ct_sales_rows(['months' => 12, 'company' => 0, 'type' => '', 'partner' => '', 'scope' => 'alle']);
    $companies = array_unique(array_column($all, 'company', 'company_id'));
    $types = array_values(array_unique(array_filter(array_map(fn($r) => (string)$r['c']['type_name'], $all))));
    $partners = array_values(array_unique(array_filter(array_map(fn($r) => (string)$r['c']['partner'], $all))));
    sort($types); sort($partners); asort($companies);
    $hidden = (int)(row("SELECT COUNT(*) AS n FROM companies c JOIN company_apps ca ON ca.company_id = c.id JOIN apps a ON a.id = ca.app_id
        WHERE a.slug = ? AND c.active = 1 AND c.ct_provider_access = 0", [CT_APP])['n'] ?? 0);
    $q = array_filter(['months' => $f['months'], 'firma' => $f['company'] ?: null, 'type' => $f['type'] ?: null, 'partner' => $f['partner'] ?: null, 'scope' => $f['scope']]);

    render_page(t('Vertriebsübersicht'), function () use ($f, $list, $companies, $types, $partners, $hidden, $q) { ?>
        <p class="muted"><?= e(t('Verträge Ihrer Kunden, deren Kündigungsfrist oder Ende in den nächsten Monaten liegt – nur Firmen, die Ihnen Einsicht geben. Verträge anderer Dienstleister erscheinen nur, wenn die Firma das freigibt.')) ?></p>
        <form method="get" class="toolbar">
            <input type="hidden" name="r" value="contract_sales">
            <select name="months" aria-label="<?= e(t('Zeitraum')) ?>"><?php foreach ([3, 6, 12] as $m): ?><option value="<?= $m ?>" <?= $f['months'] === $m ? 'selected' : '' ?>><?= e(t('nächste {n} Monate', ['n' => $m])) ?></option><?php endforeach; ?></select>
            <select name="scope" aria-label="<?= e(t('Betreuung')) ?>"><option value="meine" <?= $f['scope'] === 'meine' ? 'selected' : '' ?>><?= e(t('von uns betreut')) ?></option>
                <option value="alle" <?= $f['scope'] === 'alle' ? 'selected' : '' ?>><?= e(t('alle freigegebenen')) ?></option></select>
            <select name="firma" aria-label="<?= e(t('Firma')) ?>"><option value=""><?= e(t('alle Firmen')) ?></option>
                <?php foreach ($companies as $id => $n): ?><option value="<?= (int)$id ?>" <?= $f['company'] === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
            <select name="type" aria-label="<?= e(t('Vertragsart')) ?>"><option value=""><?= e(t('alle Vertragsarten')) ?></option>
                <?php foreach ($types as $tp): ?><option value="<?= e($tp) ?>" <?= $f['type'] === $tp ? 'selected' : '' ?>><?= e(t($tp)) ?></option><?php endforeach; ?></select>
            <select name="partner" aria-label="<?= e(t('Vertragspartner')) ?>"><option value=""><?= e(t('alle Anbieter')) ?></option>
                <?php foreach ($partners as $pa): ?><option value="<?= e($pa) ?>" <?= $f['partner'] === $pa ? 'selected' : '' ?>><?= e($pa) ?></option><?php endforeach; ?></select>
            <button class="btn"><?= e(t('Filtern')) ?></button>
            <a class="btn" href="<?= e(url('contract_sales', $q + ['export' => 'csv'])) ?>"><?= e(t('CSV-Export')) ?></a>
        </form>
        <div class="card">
            <?php if (!$list): ?><p class="muted"><?= e(t('In diesem Zeitraum laufen keine passenden Verträge aus.')) ?></p><?php else: ?>
            <div class="table-scroll"><table class="table">
                <thead><tr><th><?= e(t('Fällig')) ?></th><th><?= e(t('Firma')) ?></th><th><?= e(t('Vertrag')) ?></th><th><?= e(t('Art')) ?></th><th><?= e(t('Vertragspartner')) ?></th>
                    <th class="num"><?= e(t('Kosten')) ?></th><th><?= e(t('Betreuung')) ?></th><th><?= e(t('Angebot')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($list as $r): $c = $r['c']; ?>
                    <tr><td class="nowrap"><?= fmt_date($r['date']) ?><br><span class="tag <?= $r['kind'] === 'cancel' ? 'danger' : '' ?>"><?= e($r['kind'] === 'cancel' ? t('Kündigungsfrist') : t('Vertragsende')) ?></span></td>
                        <td><?= e($r['company']) ?></td>
                        <td><a href="<?= e(url('contract', ['id' => $c['id'], 'company' => $r['company_id']])) ?>"><strong><?= e($c['title']) ?></strong></a><?= ct_status_badge($c) !== '' && $c['status'] !== 'aktiv' ? ' ' . ct_status_badge($c) : '' ?></td>
                        <td><?= e($c['type_name'] !== null ? t((string)$c['type_name']) : '–') ?></td>
                        <td><?= e($c['partner']) ?></td>
                        <td class="num nowrap"><?php if ($r['price'] === null): ?><span class="muted small"><?= e(t('nicht freigegeben')) ?></span><?php elseif ($r['price']['cents'] !== null): ?><?= e(ct_fmt_price($r['price']['cents'])) ?><br><span class="muted small"><?= e(t(CT_INTERVALS[$r['price']['interval']] ?? '')) ?></span><?php else: ?>–<?php endif; ?></td>
                        <td><?= e(ct_managed_label($c)) ?></td>
                        <td><?= $r['offer'] ? e(fmt_date(substr((string)$r['offer']['created_at'], 0, 10))) . '<br><span class="muted small">' . e($r['offer']['target_name']) . '</span>' : '–' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
        <?php if ($hidden): ?><p class="muted small"><?= e(t('{n} Firma(en) mit der App haben die Einsicht für Sie nicht eingeschaltet und erscheinen hier nicht.', ['n' => $hidden])) ?></p><?php endif; ?>
    <?php });
}
