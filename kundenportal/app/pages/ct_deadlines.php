<?php
/**
 * App „Verträge & Laufzeiten“ – Stufe 3: Fristen
 *
 * - Ereignisse je Vertrag: Kündigungsfrist endet, automatische Verlängerung, Vertragsende, Preisänderung
 *   (nur mit „Kosten sehen“). Lizenzen aus dem Inventar erscheinen in Kalender und PDF, erinnert werden sie
 *   weiter vom Inventar (nichts doppelt).
 * - „Demnächst fällig“ auf der Startseite der App (Vorlauf je Firma, Standard 60 Tage).
 * - Fristenkalender (nächste 3/6/12 Monate, Filter nach Art und Anbieter), PDF zum Herunterladen oder per Mail,
 *   automatisch monatlich oder vierteljährlich an festgelegte Empfänger.
 * - Kalender-Abo (ICS) je Benutzer mit geheimem Link; nur Verträge, die der Benutzer sehen darf.
 * - Erinnerungen über den Cronjob (und höchstens stündlich bei Seitenaufrufen): jeder Punkt nur einmal (ct_notices).
 *   Dabei werden abgelaufene Verträge auf „Beendet“ gesetzt und verlängerte auf „Verlängert“.
 */

declare(strict_types=1);

const CT_LEAD_DEFAULT = 60;
const CT_PRICE_LEAD_DAYS = 14;
const CT_RENEW_NOTICE_DAYS = 14;       // Verlängerung nur melden, wenn die Frist höchstens so lange her ist
const CT_REMINDER_INTERVAL = 3600;
const CT_EVENT_LABELS = ['cancel' => 'Kündigungsfrist endet', 'renew' => 'Verlängert sich', 'end' => 'Vertrag endet', 'price' => 'Preisänderung'];
const CT_MONTHS = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
const CT_PDF_INTERVALS = ['' => 'aus', 'monat' => 'monatlich (am Monatsanfang)', 'quartal' => 'vierteljährlich (Januar, April, Juli, Oktober)'];
const CT_MAIL_MAX = 10;

function ct_lead_days(int $cid): int
{
    $v = (int)(ct_company($cid)['ct_lead_days'] ?? 0);
    return $v > 0 ? $v : CT_LEAD_DEFAULT;
}

function ct_month_label(string $date): string
{
    return t(CT_MONTHS[(int)substr($date, 5, 2)]) . ' ' . substr($date, 0, 4);
}

/**
 * Fristen-Ereignisse einer Firma zwischen $from und $to.
 * $opts: costs (Preisänderungen und Beträge), inv (Lizenzen aus dem Inventar), inv_check (Leserecht im Inventar prüfen),
 *        type (Vertragsart-ID oder 'inv'), partner (Text).
 * Jedes Ereignis: date, kind, title, id, inv, type_name, partner, hint, cents, interval.
 */
function ct_events(int $cid, string $from, string $to, array $opts = []): array
{
    $costs = !empty($opts['costs']);
    $type = $opts['type'] ?? 0;
    $partner = (string)($opts['partner'] ?? '');
    $list = [];
    if ($type !== 'inv') {
        $w = "c.company_id = ? AND c.status IN ('aktiv', 'kuendigung_freigabe', 'verlaengert', 'gekuendigt')";
        $p = [$cid];
        if ($type) { $w .= ' AND c.type_id = ?'; $p[] = (int)$type; }
        if ($partner !== '') { $w .= ' AND c.partner = ?'; $p[] = $partner; }
        $list = rows("SELECT c.*, t.name AS type_name FROM contracts c LEFT JOIN ct_types t ON t.id = c.type_id WHERE $w", $p);
    }
    if (!empty($opts['inv']) && !$type || $type === 'inv') {
        foreach (ct_inv_license_rows($cid, '', $opts['inv_check'] ?? true) as $l) {
            if ($partner !== '' && $l['partner'] !== $partner) continue;
            if ($l['status'] !== 'beendet') $list[] = $l;
        }
    }
    $prices = $costs ? ct_prices_for($cid) : [];
    $out = [];
    foreach ($list as $c) {
        $inv = !empty($c['_inv']);
        $dl = ct_deadlines($c, $from);
        $price = ct_price_at($c, $inv ? [] : ($prices[(int)$c['id']] ?? []), $from);
        $base = ['id' => (int)$c['id'], 'inv' => $inv, 'title' => (string)$c['title'], 'type_name' => $c['type_name'] !== null ? t((string)$c['type_name']) : '',
            'partner' => (string)$c['partner'], 'cents' => $costs ? $price['cents'] : null, 'interval' => $price['interval']];
        $add = function (string $date, string $kind, string $hint) use (&$out, $base, $from, $to) {
            if ($date !== '' && $date >= $from && $date <= $to) $out[] = ['date' => $date, 'kind' => $kind, 'hint' => $hint] + $base;
        };
        $running = in_array($c['status'], CT_RUNNING, true);
        if ($running && (int)$c['auto_renew'] && $dl['by'] !== '') {
            $next = (int)$c['renew_months'] > 0 ? ct_end_from_start((new DateTimeImmutable($dl['possible']))->modify('+1 day')->format('Y-m-d'), (int)$c['renew_months']) : '';
            $add($dl['by'], 'cancel', t('zum {d}', ['d' => fmt_date($dl['possible'])]) . ($next !== '' ? ' · ' . t('sonst Verlängerung bis {d}', ['d' => fmt_date($next)]) : ''));
            $add($dl['possible'], 'renew', $next !== '' ? t('verlängert sich ohne Kündigung bis {d}', ['d' => fmt_date($next)]) : '');
        } elseif ($dl['end'] !== '') {
            $add($dl['end'], 'end', $c['status'] === 'gekuendigt' ? t('gekündigt') : t('endet ohne Verlängerung'));
        }
        if ($costs && !$inv) {
            foreach ($prices[(int)$c['id']] ?? [] as $pr) {
                $add((string)$pr['valid_from'], 'price', t('neu: {x}', ['x' => ct_fmt_price($pr['cost_cents'] === null ? null : (int)$pr['cost_cents']) . ' ' . t(CT_INTERVALS[$pr['cost_interval']] ?? '')]));
            }
        }
    }
    usort($out, fn($a, $b) => [$a['date'], array_search($a['kind'], array_keys(CT_EVENT_LABELS), true), mb_strtolower($a['title'])]
        <=> [$b['date'], array_search($b['kind'], array_keys(CT_EVENT_LABELS), true), mb_strtolower($b['title'])]);
    return $out;
}

/** Link auf den Vertrag bzw. die Lizenz eines Ereignisses */
function ct_event_url(int $cid, array $ev, bool $absolute = false): string
{
    $u = ct_app_url($cid, $ev['inv'] ? 'inv_license' : 'contract', ['id' => $ev['id']]);
    return $absolute ? rtrim((string)cfg('base_url', ''), '/') . '/' . $u : $u;
}

// ---------------------------------------------------------------------------
// „Demnächst fällig“
// ---------------------------------------------------------------------------

function ct_due_items(int $cid, bool $costs): array
{
    $today = date('Y-m-d');
    $items = [];
    foreach (ct_events($cid, $today, date('Y-m-d', strtotime('+' . ct_lead_days($cid) . ' days')), ['costs' => $costs, 'inv' => true]) as $ev) {
        if ($ev['kind'] === 'renew') continue;
        if ($ev['kind'] === 'price' && $ev['date'] > date('Y-m-d', strtotime('+' . CT_PRICE_LEAD_DAYS . ' days'))) continue;
        $items[] = $ev;
    }
    return $items;
}

function ct_due_box(int $cid, bool $costs): void
{
    $items = ct_due_items($cid, $costs);
    if (!$items) return; ?>
    <div class="card due-box">
        <h2><?= e(t('Demnächst fällig')) ?> <span class="count"><?= count($items) ?></span></h2>
        <ul class="inv-log">
        <?php foreach (array_slice($items, 0, 15) as $ev): ?>
            <li><span class="tag <?= $ev['kind'] === 'cancel' ? 'danger' : 'warn' ?>"><?= e(t(CT_EVENT_LABELS[$ev['kind']])) ?></span>
                <a href="<?= e(ct_event_url($cid, $ev)) ?>"><?= e($ev['title']) ?></a> <span class="muted small">· <?= fmt_date($ev['date']) ?><?= $ev['hint'] !== '' ? ' · ' . e($ev['hint']) : '' ?></span></li>
        <?php endforeach; ?>
        </ul>
        <?php if (count($items) > 15): ?><p class="muted small"><?= e(t('… und {n} weitere.', ['n' => count($items) - 15])) ?></p><?php endif; ?>
        <p class="muted small"><?= e(t('Vorlauf: {n} Tage.', ['n' => ct_lead_days($cid)])) ?> <a href="<?= e(cturl('contract_deadlines')) ?>"><?= e(t('Zum Fristenkalender')) ?></a></p>
    </div>
<?php }

// ---------------------------------------------------------------------------
// Fristenkalender
// ---------------------------------------------------------------------------

/** Filter aus der Anfrage (GET oder POST) */
function ct_calendar_filter(int $cid): array
{
    $src = is_post() ? $_POST : $_GET;
    $months = (int)($src['months'] ?? 12);
    if (!in_array($months, [3, 6, 12], true)) $months = 12;
    $type = (string)($src['type'] ?? '');
    $type = $type === 'inv' ? 'inv' : (int)$type;
    if (is_int($type) && $type && !row('SELECT id FROM ct_types WHERE id = ? AND company_id = ?', [$type, $cid])) $type = 0;
    return ['months' => $months, 'type' => $type, 'partner' => mb_substr(trim((string)($src['partner'] ?? '')), 0, 190)];
}

function ct_calendar_events(int $cid, array $f, bool $costs, bool $invCheck = true): array
{
    $today = date('Y-m-d');
    return ct_events($cid, $today, date('Y-m-d', strtotime("+{$f['months']} months")),
        ['costs' => $costs, 'inv' => true, 'inv_check' => $invCheck, 'type' => $f['type'], 'partner' => $f['partner']]);
}

function page_contract_deadlines(): void
{
    $cid = ct_company_id();
    $r = ct_rights($cid);
    if ($r['perm'] === CT_PERM_NONE) abort(403);
    $u = current_user();
    $f = ct_calendar_filter($cid);

    if (is_post()) {
        require_post_csrf();
        $action = (string)post('action');
        if ($action === 'ics_create' || $action === 'ics_reset') {
            q('DELETE FROM ct_feeds WHERE company_id = ? AND user_id = ?', [$cid, $u['id']]);
            $token = bin2hex(random_bytes(24));
            insert('ct_feeds', ['company_id' => $cid, 'user_id' => $u['id'], 'token_hash' => hash('sha256', $token), 'token_enc' => encrypt_secret($token), 'created_at' => now()]);
            audit('contract.ics', $action === 'ics_reset' ? 'neu erzeugt' : 'angelegt', $cid);
            flash('success', $action === 'ics_reset' ? t('Der Kalender-Link wurde neu erzeugt. Der alte Link funktioniert nicht mehr.') : t('Ihr Kalender-Link ist eingerichtet.'));
        } elseif ($action === 'ics_delete') {
            q('DELETE FROM ct_feeds WHERE company_id = ? AND user_id = ?', [$cid, $u['id']]);
            audit('contract.ics', 'gelöscht', $cid);
            flash('success', t('Der Kalender-Link wurde gelöscht.'));
        } elseif ($action === 'pdf_mail') {
            if ($r['perm'] < CT_PERM_EDIT) abort(403);
            $to = ct_parse_recipients((string)post('recipients'));
            if (!$to) { flash('error', t('Bitte mindestens eine gültige E-Mail-Adresse angeben (höchstens {n}).', ['n' => CT_MAIL_MAX])); }
            else {
                $withCosts = $r['costs'] && post('with_costs') === '1';
                ct_send_pdf($cid, $to, $f, $withCosts, true, trim((string)post('message')));
                audit('contract.pdf_mail', implode(', ', $to), $cid);
                flash('success', t('Die Fristenübersicht wurde an {x} gesendet.', ['x' => implode(', ', $to)]));
            }
        } else {
            abort(400);
        }
        ct_redirect('contract_deadlines', ['months' => $f['months'], 'type' => $f['type'] ?: null, 'partner' => $f['partner'] !== '' ? $f['partner'] : null]);
    }

    $events = ct_calendar_events($cid, $f, $r['costs']);
    $types = rows('SELECT id, name FROM ct_types WHERE company_id = ? ORDER BY sort, name', [$cid]);
    $partners = array_column(rows("SELECT DISTINCT partner FROM contracts WHERE company_id = ? AND partner <> '' ORDER BY partner", [$cid]), 'partner');
    $feed = row('SELECT * FROM ct_feeds WHERE company_id = ? AND user_id = ?', [$cid, $u['id']]);
    $feedUrl = $feed ? rtrim((string)cfg('base_url', ''), '/') . '/index.php?' . http_build_query(['r' => 'contract_ics', 't' => decrypt_secret($feed['token_enc'])]) : '';
    $q = ['months' => $f['months'], 'type' => $f['type'] ?: null, 'partner' => $f['partner'] !== '' ? $f['partner'] : null];

    render_page(t('Fristenkalender') . ' · ' . t('Verträge & Laufzeiten'), function () use ($cid, $r, $f, $events, $types, $partners, $feedUrl, $q) {
        echo company_switcher($cid, 'contract_deadlines');
        ct_tabs('contract_deadlines'); ?>
        <form method="get" class="toolbar">
            <input type="hidden" name="r" value="contract_deadlines">
            <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
            <select name="months" aria-label="<?= e(t('Zeitraum')) ?>"><?php foreach ([3, 6, 12] as $m): ?><option value="<?= $m ?>" <?= $f['months'] === $m ? 'selected' : '' ?>><?= e(t('nächste {n} Monate', ['n' => $m])) ?></option><?php endforeach; ?></select>
            <select name="type" aria-label="<?= e(t('Vertragsart')) ?>"><option value=""><?= e(t('alle Vertragsarten')) ?></option>
                <?php foreach ($types as $tp): ?><option value="<?= (int)$tp['id'] ?>" <?= $f['type'] === (int)$tp['id'] ? 'selected' : '' ?>><?= e(t($tp['name'])) ?></option><?php endforeach; ?>
                <?php if (ct_inv_can_view($cid)): ?><option value="inv" <?= $f['type'] === 'inv' ? 'selected' : '' ?>><?= e(t('Lizenzen aus dem Inventar')) ?></option><?php endif; ?></select>
            <select name="partner" aria-label="<?= e(t('Vertragspartner')) ?>"><option value=""><?= e(t('alle Anbieter')) ?></option>
                <?php foreach ($partners as $pa): ?><option value="<?= e($pa) ?>" <?= $f['partner'] === $pa ? 'selected' : '' ?>><?= e($pa) ?></option><?php endforeach; ?></select>
            <button class="btn"><?= e(t('Filtern')) ?></button>
            <a class="btn" href="<?= e(cturl('contract_pdf', array_filter($q, fn($v) => $v !== null))) ?>"><?= e(t('PDF herunterladen')) ?></a>
        </form>
        <div class="card">
            <?php if (!$events): ?><p class="muted"><?= e(t('In diesem Zeitraum stehen keine Fristen an.')) ?></p><?php else: ?>
            <div class="table-scroll"><table class="table">
                <thead><tr><th><?= e(t('Datum')) ?></th><th><?= e(t('Ereignis')) ?></th><th><?= e(t('Vertrag')) ?></th><th><?= e(t('Art')) ?></th><th><?= e(t('Vertragspartner')) ?></th>
                    <?php if ($r['costs']): ?><th class="num"><?= e(t('Kosten')) ?></th><?php endif; ?><th><?= e(t('Hinweis')) ?></th></tr></thead>
                <tbody>
                <?php $month = ''; foreach ($events as $ev): if (substr($ev['date'], 0, 7) !== $month): $month = substr($ev['date'], 0, 7); ?>
                    <tr class="group-row"><th colspan="<?= $r['costs'] ? 7 : 6 ?>"><?= e(ct_month_label($ev['date'])) ?></th></tr>
                <?php endif; ?>
                    <tr><td class="nowrap"><?= fmt_date($ev['date']) ?></td>
                        <td class="nowrap"><span class="tag <?= $ev['kind'] === 'cancel' ? 'danger' : ($ev['kind'] === 'end' ? '' : 'warn') ?>"><?= e(t(CT_EVENT_LABELS[$ev['kind']])) ?></span></td>
                        <td><a href="<?= e(ct_event_url($cid, $ev)) ?>"><?= e($ev['title']) ?></a><?= $ev['inv'] ? ' <span class="tag">' . e(t('aus dem Inventar')) . '</span>' : '' ?></td>
                        <td><?= e($ev['type_name']) ?></td><td><?= e($ev['partner']) ?></td>
                        <?php if ($r['costs']): ?><td class="num nowrap"><?= $ev['cents'] !== null ? e(ct_fmt_price($ev['cents'])) . '<br><span class="muted small">' . e(t(CT_INTERVALS[$ev['interval']] ?? '')) . '</span>' : '–' ?></td><?php endif; ?>
                        <td class="small"><?= e($ev['hint']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
        <div class="grid2">
            <div class="card">
                <h2><?= e(t('Kalender-Abo')) ?></h2>
                <p class="muted"><?= e(t('Abonnieren Sie die Fristen in Outlook, Google Kalender oder auf dem Smartphone. Der Kalender zeigt nur Verträge, die Sie sehen dürfen, und aktualisiert sich selbst.')) ?></p>
                <?php if ($feedUrl !== ''): ?>
                    <label><?= e(t('Ihr persönlicher Link')) ?> <input readonly value="<?= e($feedUrl) ?>" class="mono"></label>
                    <p class="muted small"><?= e(t('Geben Sie den Link nicht weiter: Wer ihn kennt, sieht Ihre Fristen. Bei Verdacht erzeugen Sie einfach einen neuen.')) ?></p>
                    <p class="muted small"><?= e(t('Outlook: Kalender hinzufügen → Aus dem Internet. Google Kalender: Weitere Kalender → Per URL.')) ?></p>
                    <form method="post" class="toolbar"><?= csrf_field() ?>
                        <button class="btn small" name="action" value="ics_reset" data-confirm="<?= e(t('Neuen Link erzeugen? Der alte funktioniert dann nicht mehr.')) ?>"><?= e(t('Neuen Link erzeugen')) ?></button>
                        <button class="btn small danger" name="action" value="ics_delete"><?= e(t('Link löschen')) ?></button></form>
                <?php else: ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ics_create"><button class="btn"><?= e(t('Kalender-Link erzeugen')) ?></button></form>
                <?php endif; ?>
            </div>
            <?php if ($r['perm'] >= CT_PERM_EDIT): ?>
            <form method="post" class="card form"><?= csrf_field() ?><input type="hidden" name="action" value="pdf_mail">
                <input type="hidden" name="months" value="<?= (int)$f['months'] ?>"><input type="hidden" name="type" value="<?= e((string)$f['type']) ?>"><input type="hidden" name="partner" value="<?= e($f['partner']) ?>">
                <h2><?= e(t('PDF per E-Mail senden')) ?></h2>
                <label><?= e(t('Empfänger (mehrere mit Komma trennen)')) ?> <input name="recipients" required maxlength="1000" placeholder="<?= e(t('z. B. geschaeftsfuehrung@firma.de')) ?>"></label>
                <label><?= e(t('Nachricht (optional)')) ?> <textarea name="message" rows="2" maxlength="2000"></textarea></label>
                <?php if ($r['costs']): ?><label class="check"><input type="checkbox" name="with_costs" value="1"> <?= e(t('Kosten mitsenden')) ?></label><?php endif; ?>
                <p class="muted small"><?= e(t('Gesendet wird die Übersicht mit den Filtern oben. Regelmäßigen Versand richten die Administratoren unter Einstellungen ein.')) ?></p>
                <button class="btn primary"><?= e(t('Senden')) ?></button>
            </form>
            <?php endif; ?>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// PDF
// ---------------------------------------------------------------------------

function ct_parse_recipients(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $a) {
        $a = strtolower(trim($a));
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) $out[] = $a;
    }
    $out = array_values(array_unique($out));
    return count($out) > CT_MAIL_MAX ? [] : $out;
}

/** PDF der Fristenübersicht (in der aktuellen Sprache) */
function ct_pdf(int $cid, array $f, bool $costs, bool $invCheck = true): string
{
    $co = ct_company($cid);
    $events = ct_calendar_events($cid, $f, $costs, $invCheck);
    $appName = t(cfg('app_name', 'Kundenportal'));
    $pdf = new SimplePdf(true, $appName . ' · ' . $co['name'] . ' · ' . t('Stand {d}', ['d' => date(lang_date_format(true))]));
    $pdf->text($pdf->margin, $pdf->y - 16, t('Fristenübersicht') . ' – ' . $co['name'], 16, true);
    $pdf->y -= 26;
    $sub = [t('nächste {n} Monate', ['n' => $f['months']]) . ' (' . fmt_date(date('Y-m-d')) . ' – ' . fmt_date(date('Y-m-d', strtotime("+{$f['months']} months"))) . ')'];
    if ($f['type'] === 'inv') $sub[] = t('Lizenzen aus dem Inventar');
    elseif ($f['type']) $sub[] = t((string)(row('SELECT name FROM ct_types WHERE id = ?', [$f['type']])['name'] ?? ''));
    if ($f['partner'] !== '') $sub[] = $f['partner'];
    $pdf->text($pdf->margin, $pdf->y - 9, implode(' · ', $sub), 9, false, [90, 100, 110]);
    $pdf->y -= 22;
    if (!$events) {
        $pdf->text($pdf->margin, $pdf->y - 10, t('In diesem Zeitraum stehen keine Fristen an.'), 10);
        return $pdf->output(t('Fristenübersicht'));
    }
    $cols = [['label' => t('Datum'), 'w' => 8], ['label' => t('Ereignis'), 'w' => 13], ['label' => t('Vertrag'), 'w' => 20, 'wrap' => true], ['label' => t('Art'), 'w' => 11, 'wrap' => true],
        ['label' => t('Vertragspartner'), 'w' => 13, 'wrap' => true]];
    if ($costs) $cols[] = ['label' => t('Kosten'), 'w' => 14, 'align' => 'right'];
    $cols[] = ['label' => t('Hinweis'), 'w' => 21, 'wrap' => true];
    $rowsOut = [];
    $month = '';
    foreach ($events as $ev) {
        if (substr($ev['date'], 0, 7) !== $month) { $month = substr($ev['date'], 0, 7); $rowsOut[] = ['_group' => ct_month_label($ev['date'])]; }
        $row = [fmt_date($ev['date']), t(CT_EVENT_LABELS[$ev['kind']]), $ev['title'] . ($ev['inv'] ? ' (' . t('Inventar') . ')' : ''), $ev['type_name'], $ev['partner']];
        if ($costs) $row[] = $ev['cents'] !== null ? ct_fmt_price($ev['cents']) . ' ' . t(CT_INTERVALS[$ev['interval']] ?? '') : '–';
        $row[] = $ev['hint'];
        $rowsOut[] = $row;
    }
    $pdf->table($cols, $rowsOut);
    return $pdf->output(t('Fristenübersicht'));
}

function ct_pdf_filename(int $cid): string
{
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', (string)ct_company($cid)['name']) ?: 'Firma');
    return 'Fristen-' . trim($name, '-') . '-' . date('Y-m-d') . '.pdf';
}

function page_contract_pdf(): void
{
    $cid = ct_company_id();
    $r = ct_rights($cid);
    if ($r['perm'] === CT_PERM_NONE) abort(403);
    $data = ct_pdf($cid, ct_calendar_filter($cid), $r['costs']);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: attachment; filename="' . ct_pdf_filename($cid) . '"');
    header('Cache-Control: private, no-store');
    echo $data;
    exit;
}

/** PDF per Mail; jede Sprache bekommt ihr eigenes PDF */
function ct_send_pdf(int $cid, array $to, array $f, bool $costs, bool $invCheck, string $message = '', string $kind = 'manuell'): void
{
    $co = ct_company($cid);
    $sender = current_user()['name'] ?? '';
    $groups = [];
    foreach ($to as $addr) $groups[lang_for_email($addr, $cid)][] = $addr;
    foreach ($groups as $lang => $addrs) {
        with_lang($lang, function () use ($cid, $addrs, $f, $costs, $invCheck, $message, $kind, $co, $sender) {
            $data = ct_pdf($cid, $f, $costs, $invCheck);
            $body = $kind === 'auto'
                ? t("anbei die aktuelle Fristenübersicht der Verträge von {company}.\n\nSie erhalten diese Übersicht regelmäßig; die Einstellung pflegen die Administratoren der Firma in der App „Verträge & Laufzeiten“.", ['company' => $co['name']])
                : t("anbei die Fristenübersicht der Verträge von {company}, gesendet von {name}.", ['company' => $co['name'], 'name' => $sender]);
            if ($message !== '') $body .= "\n\n" . $message;
            send_mail($addrs, t('Fristenübersicht: {company}', ['company' => $co['name']]), t('Guten Tag,') . "\n\n" . $body, $cid,
                [['name' => ct_pdf_filename($cid), 'mime' => 'application/pdf', 'data' => $data]]);
        });
    }
}

// ---------------------------------------------------------------------------
// Kalender-Abo (ICS) – ohne Anmeldung, über den geheimen Link
// ---------------------------------------------------------------------------

function ct_ics_text(string $s): string
{
    return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $s);
}

/** Zeilen auf 75 Oktette falten (RFC 5545) */
function ct_ics_fold(string $line): string
{
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--; // nicht mitten im UTF-8-Zeichen
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line . "\r\n";
}

function page_contract_ics(): void
{
    $token = (string)($_GET['t'] ?? '');
    $feed = preg_match('/^[a-f0-9]{48}$/', $token) ? row('SELECT * FROM ct_feeds WHERE token_hash = ?', [hash('sha256', $token)]) : null;
    if (!$feed || !hash_equals((string)$feed['token_hash'], hash('sha256', $token))) { http_response_code(404); exit('Not found'); }
    $cid = (int)$feed['company_id'];
    $u = row('SELECT * FROM users WHERE id = ? AND active = 1', [$feed['user_id']]);
    $co = row('SELECT id, name, active FROM companies WHERE id = ?', [$cid]);
    $ok = $u && $co && (int)$co['active'] && company_has_app($cid, CT_APP)
        && (is_staff_role($u['role']) || ((int)$u['company_id'] === $cid && in_array($u['role'], ['firma_admin', 'firma_user'], true)));
    $r = $ok ? ct_rights($cid, $u) : ['perm' => 0, 'costs' => false];
    if ($r['perm'] === CT_PERM_NONE) { http_response_code(404); exit('Not found'); }
    q('UPDATE ct_feeds SET last_used_at = ? WHERE id = ?', [now(), $feed['id']]);
    $base = rtrim((string)cfg('base_url', ''), '/');
    $ics = with_lang(user_lang($u), function () use ($cid, $r, $co, $base) {
        $host = parse_url($base, PHP_URL_HOST) ?: 'kundenportal';
        $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Kundenportal//Vertraege//DE\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
        $out .= ct_ics_fold('X-WR-CALNAME:' . ct_ics_text(t('Vertragsfristen') . ' – ' . $co['name']));
        $out .= "X-PUBLISHED-TTL:PT6H\r\nREFRESH-INTERVAL;VALUE=DURATION:PT6H\r\n";
        // Lizenzen aus dem Inventar nicht: deren Leserecht hängt an der Sitzung, und erinnert werden sie vom Inventar
        foreach (ct_events($cid, date('Y-m-d', strtotime('-1 month')), date('Y-m-d', strtotime('+24 months')), ['costs' => $r['costs'], 'inv' => false]) as $ev) {
            $d = str_replace('-', '', $ev['date']);
            $next = (new DateTimeImmutable($ev['date']))->modify('+1 day')->format('Ymd');
            $out .= "BEGIN:VEVENT\r\n";
            $out .= 'UID:ct-' . $ev['id'] . '-' . $ev['kind'] . '-' . $d . '@' . $host . "\r\n";
            $out .= 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
            $out .= "DTSTART;VALUE=DATE:$d\r\nDTEND;VALUE=DATE:$next\r\nTRANSP:TRANSPARENT\r\n";
            $out .= ct_ics_fold('SUMMARY:' . ct_ics_text(t(CT_EVENT_LABELS[$ev['kind']]) . ': ' . $ev['title']));
            $desc = trim($ev['partner'] . ($ev['hint'] !== '' ? "\n" . $ev['hint'] : '') . ($ev['cents'] !== null ? "\n" . ct_fmt_price($ev['cents']) . ' ' . t(CT_INTERVALS[$ev['interval']] ?? '') : ''));
            if ($desc !== '') $out .= ct_ics_fold('DESCRIPTION:' . ct_ics_text($desc));
            $out .= ct_ics_fold('URL:' . $base . '/index.php?r=contract&id=' . $ev['id'] . '&company=' . $cid);
            $out .= "END:VEVENT\r\n";
        }
        return $out . "END:VCALENDAR\r\n";
    });
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="vertragsfristen.ics"');
    header('Cache-Control: private, max-age=900');
    header('X-Robots-Tag: noindex');
    echo $ics;
    exit;
}

// ---------------------------------------------------------------------------
// Erinnerungen und automatischer PDF-Versand
// ---------------------------------------------------------------------------

/** Firmen-Admins, verantwortliche Person, Gruppen mit „Kosten sehen“ und der IT-Dienstleister je nach Ereignis */
function ct_recipients_for(int $cid, array $co, array $c, string $kind): array
{
    $to = array_column(rows("SELECT email FROM users WHERE company_id = ? AND role = 'firma_admin' AND active = 1", [$cid]), 'email');
    if ($kind === 'price') {
        $to = array_merge($to, array_column(rows('SELECT DISTINCT u.email FROM users u JOIN user_departments ud ON ud.user_id = u.id
            JOIN ct_group_perms g ON g.department_id = ud.department_id WHERE g.company_id = ? AND g.perm >= 1 AND g.see_costs = 1 AND u.active = 1 AND u.company_id = ?', [$cid, $cid]), 'email'));
        if ((int)$co['ct_provider_access'] === 1 && (int)$co['ct_provider_costs'] === 1 && $c['managed_by'] === 'it') $to = array_merge($to, (array)cfg('mail.admin_recipients', []));
    } elseif ($kind !== 'renew') {
        if ($c['responsible_user_id']) {
            $rp = row('SELECT email FROM users WHERE id = ? AND company_id = ? AND active = 1', [$c['responsible_user_id'], $cid]);
            if ($rp) $to[] = $rp['email'];
        }
        if ((int)$co['ct_provider_access'] === 1 && $c['managed_by'] === 'it') $to = array_merge($to, (array)cfg('mail.admin_recipients', []));
    }
    return array_values(array_unique(array_filter(array_map('strtolower', $to))));
}

function ct_reminders_run(): array
{
    $lines = [];
    $today = date('Y-m-d');
    $base = rtrim((string)cfg('base_url', ''), '/');
    foreach (rows("SELECT c.* FROM companies c JOIN company_apps ca ON ca.company_id = c.id JOIN apps a ON a.id = ca.app_id
        WHERE a.slug = ? AND a.active = 1 AND c.active = 1", [CT_APP]) as $co) {
        $cid = (int)$co['id'];
        try {
            // Status nachziehen: gekündigte und nicht verlängerte Verträge nach dem Ende auf „Beendet“
            foreach (rows("SELECT * FROM contracts WHERE company_id = ? AND status IN ('aktiv', 'verlaengert', 'gekuendigt')", [$cid]) as $c) {
                $end = $c['status'] === 'gekuendigt' ? ($c['cancelled_to'] ?: $c['end_date']) : ((int)$c['auto_renew'] ? '' : $c['end_date']);
                if ($end !== '' && $end < $today) {
                    update('contracts', (int)$c['id'], ['status' => 'beendet', 'updated_at' => now()]);
                    insert('ct_log', ['company_id' => $cid, 'contract_id' => $c['id'], 'user_id' => null, 'kind' => 'event', 'body' => 'Status: Beendet (Vertragsende ' . fmt_date($end) . ')', 'created_at' => now()]);
                }
            }
            $lead = date('Y-m-d', strtotime('+' . ((int)$co['ct_lead_days'] ?: CT_LEAD_DEFAULT) . ' days'));
            $priceLead = date('Y-m-d', strtotime('+' . CT_PRICE_LEAD_DAYS . ' days'));
            $byMail = [];
            $contracts = rows("SELECT * FROM contracts WHERE company_id = ? AND status IN ('aktiv', 'kuendigung_freigabe', 'verlaengert', 'gekuendigt')", [$cid]);
            $prices = ct_prices_for($cid);
            foreach ($contracts as $c) {
                $dl = ct_deadlines($c, $today);
                $running = in_array($c['status'], CT_RUNNING, true);
                $yearly = !(int)$c['auto_renew'] || (int)$c['renew_months'] >= 12; // Monatsverträge wären sonst ständig fällig
                $items = [];
                if ($running && $dl['by'] !== '' && $dl['by'] <= $lead && $yearly) {
                    $items[] = ['cancel', $dl['by'], t('Kündigungsfrist endet') . ' (' . fmt_date($dl['by']) . ', ' . t('zum {d}', ['d' => fmt_date($dl['possible'])]) . ')'];
                }
                if ($dl['end'] !== '' && $dl['end'] >= $today && $dl['end'] <= $lead && (!$running || !(int)$c['auto_renew'])) {
                    $items[] = ['end', $dl['end'], t('Vertrag endet') . ' (' . fmt_date($dl['end']) . ')'];
                }
                // Verlängerung: Frist zum laufenden Ende ist gerade verstrichen
                if ($running && (int)$c['auto_renew'] && $dl['missed'] && $yearly) {
                    $missedBy = ct_cancel_by_end($c, $dl['end']);
                    if ($missedBy >= date('Y-m-d', strtotime('-' . CT_RENEW_NOTICE_DAYS . ' days'))) {
                        $until = ct_end_from_start((new DateTimeImmutable($dl['end']))->modify('+1 day')->format('Y-m-d'), (int)$c['renew_months']);
                        $items[] = ['renew', $dl['end'], t('Automatisch verlängert bis {d}', ['d' => fmt_date($until)])];
                    }
                }
                foreach ($prices[(int)$c['id']] ?? [] as $pr) {
                    if ($pr['valid_from'] >= $today && $pr['valid_from'] <= $priceLead) {
                        $items[] = ['price', $pr['valid_from'], t('Preisänderung ab {d}: {x}', ['d' => fmt_date($pr['valid_from']),
                            'x' => ct_fmt_price($pr['cost_cents'] === null ? null : (int)$pr['cost_cents']) . ' ' . t(CT_INTERVALS[$pr['cost_interval']] ?? '')])];
                    }
                }
                foreach ($items as [$kind, $key, $text]) {
                    if (row('SELECT id FROM ct_notices WHERE company_id = ? AND kind = ? AND ref_id = ? AND ref_key = ?', [$cid, $kind, $c['id'], $key])) continue;
                    insert('ct_notices', ['company_id' => $cid, 'kind' => $kind, 'ref_id' => $c['id'], 'ref_key' => $key, 'sent_at' => now()]);
                    if ($kind === 'renew') {
                        if ($c['status'] === 'aktiv') update('contracts', (int)$c['id'], ['status' => 'verlaengert', 'updated_at' => now()]);
                        insert('ct_log', ['company_id' => $cid, 'contract_id' => $c['id'], 'user_id' => null, 'kind' => 'event',
                            'body' => 'Automatisch verlängert bis: ' . fmt_date(ct_end_from_start((new DateTimeImmutable($dl['end']))->modify('+1 day')->format('Y-m-d'), (int)$c['renew_months'])), 'created_at' => now()]);
                    }
                    foreach (ct_recipients_for($cid, $co, $c, $kind) as $addr) $byMail[$addr][] = ['kind' => $kind, 'c' => $c, 'key' => $key];
                }
            }
            foreach ($byMail as $addr => $list) {
                send_mail_t([$addr], function () use ($list, $co, $cid, $base, $today) {
                    $body = t("Bei den Verträgen von {company} steht Folgendes an:\n\n", ['company' => $co['name']]);
                    foreach ($list as $i) {
                        $c = $i['c'];
                        $dl = ct_deadlines($c, $today);
                        $line = match ($i['kind']) {
                            'cancel' => t('Kündigungsfrist endet am {d} (zum {e})', ['d' => fmt_date($dl['by']), 'e' => fmt_date($dl['possible'])]),
                            'end' => t('Vertrag endet am {d}', ['d' => fmt_date($i['key'])]),
                            'renew' => t('Automatisch verlängert bis {d}', ['d' => fmt_date(ct_end_from_start((new DateTimeImmutable($i['key']))->modify('+1 day')->format('Y-m-d'), (int)$c['renew_months']))]),
                            default => t('Neuer Preis ab {d}', ['d' => fmt_date($i['key'])]),
                        };
                        $body .= '• ' . $c['title'] . ' (' . $c['partner'] . '): ' . $line . "\n  " . $base . '/index.php?r=contract&id=' . $c['id'] . '&company=' . $cid . "\n";
                    }
                    return [t('Verträge: {n} Fristen stehen an', ['n' => count($list)]), $body . t("\nAlle Fristen finden Sie in der App „Verträge & Laufzeiten“ im Fristenkalender.")];
                }, $cid);
            }
            if ($byMail) $lines[] = $co['name'] . ': Vertragsfristen an ' . count($byMail) . ' Empfänger';
            // Fristenübersicht automatisch per Mail
            $interval = (string)$co['ct_pdf_interval'];
            $to = ct_parse_recipients((string)$co['ct_pdf_recipients']);
            $period = date('Y-m');
            $due = $interval === 'monat' || ($interval === 'quartal' && in_array((int)date('n'), [1, 4, 7, 10], true));
            if ($due && $to && setting_get('ct_pdf_last_' . $cid) !== $period) {
                setting_set('ct_pdf_last_' . $cid, $period);
                $GLOBALS['ct_cid'] = $cid;
                ct_send_pdf($cid, $to, ['months' => $interval === 'quartal' ? 6 : 3, 'type' => 0, 'partner' => ''],
                    (int)$co['ct_pdf_costs'] === 1, false, '', 'auto');
                audit('contract.pdf_auto', implode(', ', $to), $cid);
                $lines[] = $co['name'] . ': Fristenübersicht an ' . count($to) . ' Empfänger';
            }
        } catch (Throwable $ex) {
            error_log('[kundenportal] Vertragsfristen ' . $cid . ': ' . $ex->getMessage());
        }
    }
    return $lines;
}

function ct_reminders_tick(): void
{
    try {
        $last = setting_get('ct_reminder_last');
        if ($last !== '' && (int)$last > time() - CT_REMINDER_INTERVAL) return;
        if ($last === '') setting_set('ct_reminder_last', '0');
        if (q('UPDATE settings SET value = ? WHERE name = ? AND value = ?', [(string)time(), 'ct_reminder_last', $last === '' ? '0' : $last])->rowCount() !== 1) return;
        ct_reminders_run();
    } catch (Throwable $ex) {
        error_log('[kundenportal] Vertragsfristen: ' . $ex->getMessage());
    }
}
