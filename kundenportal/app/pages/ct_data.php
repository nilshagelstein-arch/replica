<?php
/**
 * App „Verträge & Laufzeiten“ – Stufe 6: Übersichten und Schnittstellen
 *
 * - Kostenübersicht (nur „Kosten sehen“): laufende Kosten je Monat und Jahr, nach Vertragsart und Anbieter,
 *   Hochrechnung der nächsten 12 Monate (mit Preisänderungen, Vertragsenden und Beginn), einmalige Kosten,
 *   wahlweise mit den Lizenzen aus dem Inventar; Ausblick der Fristen.
 * - CSV-Export (Verträge, Fristen, Preishistorie) und CSV-Import mit Vorschau und Spaltenerkennung;
 *   bestehende Verträge werden über Vertragspartner + Vertragsnummer erkannt.
 * - Gemeinsame Feldlogik für Import und REST-API (api.php, Pfade /vertraege, /fristen, /vertragsarten).
 *   API-Schlüssel je Firma mit scope „vertraege“, lesen oder schreiben, Preise nur mit see_costs.
 */

declare(strict_types=1);

/** Austauschfelder (CSV-Spalten und API-Schlüssel): Schlüssel => [Bezeichnung, nur mit Kosten] */
const CT_COLS = [
    'bezeichnung' => ['Bezeichnung', false], 'art' => ['Vertragsart', false], 'vertragspartner' => ['Vertragspartner', false],
    'vertragsnummer' => ['Vertragsnummer', false], 'kundennummer' => ['Kundennummer', false], 'ansprechpartner' => ['Ansprechpartner beim Anbieter', false],
    'anbieter_strasse' => ['Straße des Anbieters', false], 'anbieter_plz' => ['PLZ des Anbieters', false], 'anbieter_ort' => ['Ort des Anbieters', false],
    'anbieter_email' => ['E-Mail für Kündigungen', false], 'status' => ['Status', false], 'beginn' => ['Beginn', false],
    'mindestlaufzeit_monate' => ['Mindestlaufzeit (Monate)', false], 'ende' => ['Ende', false], 'verlaengerung_automatisch' => ['Automatische Verlängerung', false],
    'verlaengerung_monate' => ['Verlängerung um (Monate)', false], 'kuendigungsfrist' => ['Kündigungsfrist', false], 'kuendigungsfrist_einheit' => ['Einheit der Kündigungsfrist', false],
    'gekuendigt_zum' => ['Gekündigt zum', false], 'betreuung' => ['Betreuung', false], 'kosten' => ['Kosten', true], 'kosten_zeitraum' => ['Zeitraum', true],
    'zahlungsart' => ['Zahlungsart', true], 'notiz' => ['Notiz', false],
];
/** Nur lesbar (Export und API) */
const CT_COLS_READONLY = ['naechstes_ende' => 'Nächstmögliches Ende', 'kuendigen_bis' => 'Kündigen bis'];
const CT_YES = ['ja', 'yes', 'oui', 'j', 'y', 'x', '1', 'true', 'wahr'];

// ---------------------------------------------------------------------------
// Werte normalisieren (Import und API)
// ---------------------------------------------------------------------------

function ct_in_date(string $s): string
{
    $s = trim($s);
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})$/', $s, $m)) {
        $y = (int)$m[3] < 100 ? 2000 + (int)$m[3] : (int)$m[3];
        return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : '';
    }
    return ct_date(substr($s, 0, 10));
}

/** Wert gegen eine Liste von Schlüsseln/Bezeichnungen (auch EN/FR) prüfen */
function ct_in_choice(string $v, array $options, array $extra = []): ?string
{
    $n = csv_norm($v);
    if ($n === '') return null;
    foreach ($extra as $alias => $key) if (csv_norm((string)$alias) === $n) return $key;
    foreach ($options as $key => $label) {
        if (csv_norm((string)$key) === $n || csv_norm((string)$label) === $n) return (string)$key;
        foreach (['en', 'fr'] as $lang) if (csv_norm(with_lang($lang, fn() => t((string)$label))) === $n) return (string)$key;
    }
    return null;
}

/**
 * Eingabewerte (Schlüssel aus CT_COLS) prüfen und in Datenbankspalten übersetzen.
 * Gibt [Daten, Fehler, Hinweise] zurück. Felder, die nicht übergeben werden, bleiben unverändert.
 */
function ct_values_in(int $cid, array $in, bool $costs, ?array $existing): array
{
    $d = [];
    $err = [];
    $notes = [];
    $s = fn(string $k, int $max) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
    $has = fn(string $k) => array_key_exists($k, $in);
    foreach (['bezeichnung' => ['title', 190], 'vertragspartner' => ['partner', 190], 'vertragsnummer' => ['contract_no', 120], 'kundennummer' => ['customer_no', 120],
        'ansprechpartner' => ['partner_contact', 255], 'anbieter_strasse' => ['partner_street', 190], 'anbieter_plz' => ['partner_zip', 20],
        'anbieter_ort' => ['partner_city', 120], 'notiz' => ['notes', 5000]] as $k => [$col, $max]) {
        if ($has($k)) $d[$col] = $s($k, $max);
    }
    if ($has('anbieter_email')) {
        $m = mb_strtolower($s('anbieter_email', 190));
        if ($m !== '' && !filter_var($m, FILTER_VALIDATE_EMAIL)) $err[] = t('Die E-Mail-Adresse des Anbieters ist ungültig.');
        else $d['partner_email'] = $m;
    }
    if ($has('art')) {
        $name = $s('art', 120);
        if ($name !== '') {
            $type = null;
            foreach (rows('SELECT id, name FROM ct_types WHERE company_id = ?', [$cid]) as $tp) {
                if (ct_in_choice($name, [$tp['id'] => $tp['name']]) !== null) { $type = (int)$tp['id']; break; }
            }
            if (!$type) {
                $max = (int)(row('SELECT MAX(sort) AS m FROM ct_types WHERE company_id = ?', [$cid])['m'] ?? 0);
                $type = insert('ct_types', ['company_id' => $cid, 'name' => $name, 'sort' => $max + 10, 'active' => 1]);
                $notes[] = t('Neue Vertragsart angelegt: {x}', ['x' => $name]);
            }
            $d['type_id'] = $type;
        }
    }
    if ($has('status') && $s('status', 40) !== '') {
        $any = ct_in_choice($s('status', 40), CT_STATUSES);
        $st = ct_in_choice($s('status', 40), array_diff_key(CT_STATUSES, array_flip(CT_FLOW_STATUSES)));
        // unveränderter Status (z. B. aus einem Export während einer laufenden Kündigung) ist keine Änderung
        if ($existing && $any !== null && $any === $existing['status']) $st = $any;
        if ($existing && $st === $existing['status']) { /* nichts zu tun */ }
        elseif ($st === null) $err[] = t('Unbekannter Status „{x}“ (erlaubt: aktiv, gekündigt, verlängert, beendet).', ['x' => $s('status', 40)]);
        elseif ($existing && in_array($existing['status'], CT_FLOW_STATUSES, true)) $notes[] = t('Status nicht geändert: Für den Vertrag läuft gerade eine Kündigung.');
        else $d['status'] = $st;
    }
    foreach (['beginn' => 'start_date', 'ende' => 'end_date', 'gekuendigt_zum' => 'cancelled_to'] as $k => $col) {
        if (!$has($k)) continue;
        $v = $s($k, 20);
        $d[$col] = $v === '' ? '' : ct_in_date($v);
        if ($v !== '' && $d[$col] === '') $err[] = t('Ungültiges Datum „{x}“ (TT.MM.JJJJ oder JJJJ-MM-TT).', ['x' => $v]);
    }
    foreach (['mindestlaufzeit_monate' => ['min_term_months', 0, 600], 'verlaengerung_monate' => ['renew_months', 1, 120], 'kuendigungsfrist' => ['notice_value', 0, 999]] as $k => [$col, $min, $max]) {
        if (!$has($k) || $s($k, 10) === '') continue;
        if (!preg_match('/^\d+$/', $s($k, 10))) { $err[] = t('„{f}“ muss eine ganze Zahl sein.', ['f' => t(CT_COLS[$k][0])]); continue; }
        $d[$col] = max($min, min($max, (int)$s($k, 10)));
    }
    if ($has('verlaengerung_automatisch') && $s('verlaengerung_automatisch', 10) !== '') {
        $v = $in['verlaengerung_automatisch'];
        $d['auto_renew'] = (is_bool($v) ? $v : in_array(mb_strtolower(trim((string)$v)), CT_YES, true)) ? 1 : 0;
    }
    if ($has('kuendigungsfrist_einheit') && $s('kuendigungsfrist_einheit', 20) !== '') {
        $u = ct_in_choice($s('kuendigungsfrist_einheit', 20), CT_NOTICE_UNITS, ['tag' => 'days', 'woche' => 'weeks', 'monat' => 'months', 'day' => 'days', 'week' => 'weeks', 'month' => 'months']);
        if ($u === null) $err[] = t('Unbekannte Einheit „{x}“ (Tage, Wochen, Monate).', ['x' => $s('kuendigungsfrist_einheit', 20)]);
        else $d['notice_unit'] = $u;
    }
    if ($has('betreuung') && $s('betreuung', 190) !== '') {
        $b = $s('betreuung', 190);
        $m = ct_in_choice($b, ['it' => 'IT-Dienstleister (Portal-Betreiber)', 'company' => 'Firma selbst'], ['portalbetreiber' => 'it', 'itdienstleister' => 'it', 'firma' => 'company', 'selbst' => 'company']);
        if ($m !== null) { $d['managed_by'] = $m; $d['provider_id'] = null; }
        elseif ($p = row('SELECT id FROM ticket_providers WHERE company_id = ? AND LOWER(name) = LOWER(?)', [$cid, $b])) { $d['managed_by'] = 'provider'; $d['provider_id'] = (int)$p['id']; }
        else $err[] = t('Unbekannte Betreuung „{x}“ (IT-Dienstleister, Firma selbst oder Name eines Dienstleisters aus den Tickets).', ['x' => $b]);
    }
    $costKeys = ['kosten', 'kosten_zeitraum', 'zahlungsart'];
    $givenCost = array_filter($costKeys, fn($k) => $has($k) && $s($k, 40) !== '');
    if ($givenCost && !$costs) {
        $notes[] = t('Kosten ignoriert: Dafür fehlt das Recht „Kosten sehen“.');
    } elseif ($costs) {
        if ($has('kosten')) {
            $raw = is_int($in['kosten']) || is_float($in['kosten']) ? str_replace('.', ',', (string)$in['kosten']) : $s('kosten', 30);
            $d['cost_cents'] = ct_parse_price($raw);
            if ($raw !== '' && $d['cost_cents'] === null) $err[] = t('Der Betrag „{x}“ ist keine gültige Zahl.', ['x' => $raw]);
        }
        if ($has('kosten_zeitraum') && $s('kosten_zeitraum', 30) !== '') {
            $i = ct_in_choice($s('kosten_zeitraum', 30), CT_INTERVALS, ['monatlich' => 'monat', 'monthly' => 'monat', 'monat' => 'monat', 'quartal' => 'quartal', 'vierteljaehrlich' => 'quartal',
                'quarterly' => 'quartal', 'jaehrlich' => 'jahr', 'jahr' => 'jahr', 'yearly' => 'jahr', 'annual' => 'jahr', 'once' => 'einmalig']);
            if ($i === null) $err[] = t('Unbekannter Zeitraum „{x}“ (Monat, Quartal, Jahr, einmalig).', ['x' => $s('kosten_zeitraum', 30)]);
            else $d['cost_interval'] = $i;
        }
        if ($has('zahlungsart') && $s('zahlungsart', 40) !== '') {
            $z = ct_in_choice($s('zahlungsart', 40), array_diff_key(CT_PAYMENTS, ['' => 1]), ['ueberweisung' => 'rechnung', 'sepa' => 'lastschrift']);
            $d['payment_method'] = $z ?? 'sonstiges';
        }
    }
    // Export/API liefern den heute gültigen Preis: gleicher Preis wie heute = keine Änderung des Grundpreises
    if ($existing && array_key_exists('cost_cents', $d)) {
        $now = ct_price_at($existing, ct_prices_for($cid, [(int)$existing['id']])[(int)$existing['id']] ?? []);
        if ($now['id'] !== 0 && $d['cost_cents'] === $now['cents'] && ($d['cost_interval'] ?? $now['interval']) === $now['interval']) unset($d['cost_cents'], $d['cost_interval']);
    }
    // Pflichtfelder und Plausibilität (mit bestehenden Werten zusammen)
    $m = $d + ($existing ?? []);
    if (trim((string)($m['title'] ?? '')) === '') $err[] = t('Bitte eine Bezeichnung angeben.');
    if (trim((string)($m['partner'] ?? '')) === '') $err[] = t('Bitte den Vertragspartner angeben.');
    if (!$existing && empty($d['type_id'])) {
        $d['type_id'] = (int)(row("SELECT id FROM ct_types WHERE company_id = ? AND name = 'Sonstiges'", [$cid])['id'] ?? 0) ?: null;
    }
    if (($m['end_date'] ?? '') === '' && ($m['start_date'] ?? '') !== '' && (int)($m['min_term_months'] ?? 0) > 0 && !isset($d['end_date'])) {
        $d['end_date'] = ct_end_from_start((string)$m['start_date'], (int)$m['min_term_months']);
    } elseif (($d['end_date'] ?? null) === '' && ($m['start_date'] ?? '') !== '' && (int)($m['min_term_months'] ?? 0) > 0) {
        $d['end_date'] = ct_end_from_start((string)$m['start_date'], (int)$m['min_term_months']);
    }
    $m = $d + ($existing ?? []);
    if (($m['start_date'] ?? '') !== '' && ($m['end_date'] ?? '') !== '' && $m['end_date'] < $m['start_date']) $err[] = t('Das Ende liegt vor dem Beginn.');
    if (($m['status'] ?? 'aktiv') === 'gekuendigt' && ($m['cancelled_to'] ?? '') === '') $d['cancelled_to'] = (string)($m['end_date'] ?? '');
    if (isset($d['status']) && $d['status'] !== 'gekuendigt' && !$has('gekuendigt_zum')) $d['cancelled_to'] = '';
    return [$d, $err, $notes];
}

/** Speichert geprüfte Werte; protokolliert Änderungen. Gibt die Vertrags-ID zurück. */
function ct_save_values(int $cid, array $d, ?array $existing, string $source): int
{
    $labels = ['title' => 'Bezeichnung', 'partner' => 'Vertragspartner', 'contract_no' => 'Vertragsnummer', 'customer_no' => 'Kundennummer', 'partner_contact' => 'Ansprechpartner beim Anbieter',
        'partner_street' => 'Straße des Anbieters', 'partner_zip' => 'PLZ des Anbieters', 'partner_city' => 'Ort des Anbieters', 'partner_email' => 'E-Mail des Anbieters',
        'start_date' => 'Beginn', 'end_date' => 'Ende', 'cancelled_to' => 'Gekündigt zum', 'min_term_months' => 'Mindestlaufzeit', 'renew_months' => 'Verlängerung',
        'notice_value' => 'Kündigungsfrist', 'notice_unit' => 'Kündigungsfrist', 'auto_renew' => 'Automatische Verlängerung', 'managed_by' => 'Betreuung', 'provider_id' => 'Betreuung',
        'type_id' => 'Vertragsart', 'status' => 'Status', 'notes' => 'Notiz'];
    $costCols = ['cost_cents' => 'Kosten', 'cost_interval' => 'Kosten', 'payment_method' => 'Zahlungsart'];
    if (!$existing) {
        $id = insert('contracts', $d + ['company_id' => $cid, 'status' => 'aktiv', 'created_by' => current_user()['id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        ct_log($cid, $id, 'Vertrag angelegt: ' . $d['title'] . ' (' . $source . ')');
        if (isset($d['cost_cents']) && $d['cost_cents'] !== null) ct_log($cid, $id, 'Kosten: ' . ct_fmt_price((int)$d['cost_cents']) . ' ' . CT_INTERVALS[$d['cost_interval'] ?? 'monat'], 'cost');
        return $id;
    }
    $changes = [];
    $costChanges = [];
    foreach ($d as $col => $v) {
        $old = $existing[$col] ?? null;
        if ((string)$old === (string)($v ?? '') && ($old === null) === ($v === null)) continue;
        if (isset($costCols[$col])) $costChanges[$costCols[$col]] = $costCols[$col] . ' (' . $source . ')';
        elseif (isset($labels[$col])) {
            $shown = in_array($col, CT_DATE_FIELDS, true) && $v !== '' ? fmt_date((string)$v) : ($col === 'status' ? (CT_STATUSES[$v] ?? $v) : (string)$v);
            $changes[$labels[$col]] = $labels[$col] . (in_array($col, ['notes', 'type_id', 'provider_id', 'managed_by', 'notice_unit', 'auto_renew'], true) ? ' geändert' : ': ' . ($shown !== '' ? $shown : '–'));
        }
    }
    if (!$changes && !$costChanges) return (int)$existing['id'];
    update('contracts', (int)$existing['id'], $d + ['updated_at' => now()]);
    foreach ($changes as $c) ct_log($cid, (int)$existing['id'], $c . ' (' . $source . ')');
    foreach ($costChanges as $c) ct_log($cid, (int)$existing['id'], $c . ' geändert', 'cost');
    return (int)$existing['id'];
}

/** Vertrag als Austauschzeile (CSV: lesbare Texte; API: Rohwerte) */
function ct_values_out(array $c, bool $costs, array $prices, bool $api): array
{
    $dl = ct_deadlines($c);
    $date = fn(string $v) => $v === '' ? ($api ? null : '') : ($api ? $v : fmt_date($v));
    $price = ct_price_at($c, $prices);
    $out = ['bezeichnung' => $c['title'], 'art' => $c['type_name'] !== null ? ($api ? (string)$c['type_name'] : t((string)$c['type_name'])) : '',
        'vertragspartner' => $c['partner'], 'vertragsnummer' => $c['contract_no'], 'kundennummer' => $c['customer_no'], 'ansprechpartner' => $c['partner_contact'],
        'anbieter_strasse' => $c['partner_street'], 'anbieter_plz' => $c['partner_zip'], 'anbieter_ort' => $c['partner_city'], 'anbieter_email' => $c['partner_email'],
        'status' => $api ? $c['status'] : t(CT_STATUSES[$c['status']] ?? $c['status']), 'beginn' => $date((string)$c['start_date']),
        'mindestlaufzeit_monate' => $api ? (int)$c['min_term_months'] : ((int)$c['min_term_months'] ?: ''), 'ende' => $date((string)$c['end_date']),
        'verlaengerung_automatisch' => $api ? (bool)(int)$c['auto_renew'] : ((int)$c['auto_renew'] ? t('ja') : t('nein')), 'verlaengerung_monate' => (int)$c['renew_months'],
        'kuendigungsfrist' => (int)$c['notice_value'], 'kuendigungsfrist_einheit' => $api ? $c['notice_unit'] : t(CT_NOTICE_UNITS[$c['notice_unit']] ?? $c['notice_unit']),
        'gekuendigt_zum' => $date((string)$c['cancelled_to']),
        'betreuung' => $api ? ($c['managed_by'] === 'provider' ? (string)($c['provider_name'] ?? '') : $c['managed_by']) : ct_managed_label($c)];
    if ($costs) {
        $out['kosten'] = $api ? ($price['cents'] === null ? null : $price['cents'] / 100) : ($price['cents'] === null ? '' : number_format($price['cents'] / 100, 2, ',', ''));
        $out['kosten_zeitraum'] = $api ? $price['interval'] : t(CT_INTERVALS[$price['interval']] ?? '');
        $out['zahlungsart'] = $api ? $c['payment_method'] : ($c['payment_method'] !== '' ? t(CT_PAYMENTS[$c['payment_method']] ?? '') : '');
    }
    $out['notiz'] = (string)$c['notes'];
    $out['naechstes_ende'] = $date($dl['possible']);
    $out['kuendigen_bis'] = $date($dl['by']);
    return $out;
}

function ct_contracts_full(int $cid, string $where = '', array $p = []): array
{
    return rows('SELECT c.*, t.name AS type_name, pr.name AS provider_name FROM contracts c LEFT JOIN ct_types t ON t.id = c.type_id
        LEFT JOIN ticket_providers pr ON pr.id = c.provider_id WHERE c.company_id = ?' . ($where !== '' ? ' AND ' . $where : '') . ' ORDER BY c.title, c.id', array_merge([$cid], $p));
}

// ---------------------------------------------------------------------------
// CSV-Export und -Import
// ---------------------------------------------------------------------------

function ct_csv_out(string $name, array $head, array $rowsOut): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $head, ';', '"', '');
    foreach ($rowsOut as $r) fputcsv($out, array_map('csv_cell_safe', $r), ';', '"', '');
    fclose($out);
    exit;
}

function page_contract_export(): void
{
    $cid = ct_company_id();
    $r = ct_rights($cid);
    if ($r['perm'] === CT_PERM_NONE) abort(403);
    $what = (string)($_GET['what'] ?? 'vertraege');
    audit('contract.export', $what, $cid);
    if ($what === 'preise') {
        if (!$r['costs']) abort(403);
        $rowsOut = [];
        foreach (ct_contracts_full($cid) as $c) {
            $rowsOut[] = [$c['title'], $c['partner'], $c['contract_no'], $c['start_date'] !== '' ? fmt_date($c['start_date']) : t('Beginn'),
                $c['cost_cents'] !== null ? number_format((int)$c['cost_cents'] / 100, 2, ',', '') : '', t(CT_INTERVALS[$c['cost_interval']] ?? ''), t('Grundpreis')];
            foreach (ct_prices_for($cid, [(int)$c['id']])[(int)$c['id']] ?? [] as $p) {
                $rowsOut[] = [$c['title'], $c['partner'], $c['contract_no'], fmt_date($p['valid_from']), $p['cost_cents'] !== null ? number_format((int)$p['cost_cents'] / 100, 2, ',', '') : '',
                    t(CT_INTERVALS[$p['cost_interval']] ?? ''), $p['note']];
            }
        }
        ct_csv_out('vertraege-preise', [t('Bezeichnung'), t('Vertragspartner'), t('Vertragsnummer'), t('Gültig ab'), t('Betrag'), t('Zeitraum'), t('Anlass')], $rowsOut);
    }
    if ($what === 'fristen') {
        $rowsOut = [];
        foreach (ct_events($cid, date('Y-m-d'), date('Y-m-d', strtotime('+12 months')), ['costs' => $r['costs'], 'inv' => true]) as $ev) {
            $row = [fmt_date($ev['date']), t(CT_EVENT_LABELS[$ev['kind']]), $ev['title'] . ($ev['inv'] ? ' (' . t('Inventar') . ')' : ''), $ev['type_name'], $ev['partner'], $ev['hint']];
            if ($r['costs']) $row[] = $ev['cents'] !== null ? number_format($ev['cents'] / 100, 2, ',', '') . ' ' . t(CT_INTERVALS[$ev['interval']] ?? '') : '';
            $rowsOut[] = $row;
        }
        ct_csv_out('vertraege-fristen', array_merge([t('Datum'), t('Ereignis'), t('Vertrag'), t('Art'), t('Vertragspartner'), t('Hinweis')], $r['costs'] ? [t('Kosten')] : []), $rowsOut);
    }
    $prices = $r['costs'] ? ct_prices_for($cid) : [];
    $keys = array_keys(array_filter(CT_COLS, fn($x) => !$x[1] || $r['costs']));
    $head = array_map(fn($k) => t(CT_COLS[$k][0]), $keys);
    $head = array_merge($head, array_map('t', array_values(CT_COLS_READONLY)));
    $rowsOut = [];
    foreach (ct_contracts_full($cid) as $c) {
        $v = ct_values_out($c, $r['costs'], $prices[(int)$c['id']] ?? [], false);
        $rowsOut[] = array_merge(array_map(fn($k) => $v[$k] ?? '', $keys), [$v['naechstes_ende'], $v['kuendigen_bis']]);
    }
    ct_csv_out('vertraege', $head, $rowsOut);
}

/** Spaltenköpfe den Feldern zuordnen (Bezeichnung in DE/EN/FR oder Feldname) */
function ct_csv_map(array $head): array
{
    $alias = [];
    foreach (CT_COLS as $k => [$label]) {
        $alias[csv_norm($k)] = $k;
        $alias[csv_norm($label)] = $k;
        foreach (['en', 'fr'] as $lang) $alias[csv_norm(with_lang($lang, fn() => t($label)))] = $k;
    }
    $alias += ['titel' => 'bezeichnung', 'name' => 'bezeichnung', 'vertrag' => 'bezeichnung', 'partner' => 'vertragspartner', 'anbieter' => 'vertragspartner',
        'lieferant' => 'vertragspartner', 'typ' => 'art', 'kategorie' => 'art', 'vertragsnr' => 'vertragsnummer', 'kundennr' => 'kundennummer', 'start' => 'beginn',
        'laufzeitende' => 'ende', 'enddatum' => 'ende', 'betrag' => 'kosten', 'preis' => 'kosten', 'intervall' => 'kosten_zeitraum', 'email' => 'anbieter_email',
        'verlaengerung' => 'verlaengerung_automatisch', 'autoverlaengerung' => 'verlaengerung_automatisch', 'kuendigungsfristmonate' => 'kuendigungsfrist'];
    $map = [];
    foreach ($head as $i => $h) {
        $k = $alias[csv_norm((string)$h)] ?? null;
        if ($k !== null && !in_array($k, $map, true)) $map[$i] = $k;
    }
    return $map;
}

/**
 * Bestehender Vertrag zu einer Importzeile: Vertragspartner + Vertragsnummer;
 * ohne Vertragsnummer Vertragspartner + Bezeichnung (nur Verträge ohne Nummer).
 */
function ct_find_existing(int $cid, array $vals): ?array
{
    $partner = trim((string)($vals['vertragspartner'] ?? ''));
    $no = trim((string)($vals['vertragsnummer'] ?? ''));
    $title = trim((string)($vals['bezeichnung'] ?? ''));
    if ($partner === '') return null;
    if ($no !== '') return row('SELECT * FROM contracts WHERE company_id = ? AND LOWER(partner) = LOWER(?) AND LOWER(contract_no) = LOWER(?) ORDER BY id LIMIT 1', [$cid, $partner, $no]) ?: null;
    if ($title === '') return null;
    return row("SELECT * FROM contracts WHERE company_id = ? AND LOWER(partner) = LOWER(?) AND LOWER(title) = LOWER(?) AND contract_no = '' ORDER BY id LIMIT 1", [$cid, $partner, $title]) ?: null;
}

function page_contract_import(): void
{
    $cid = ct_company_id();
    $r = ct_rights($cid);
    if ($r['perm'] < CT_PERM_EDIT) abort(403);
    $preview = null;
    $result = null;
    if (is_post()) {
        require_post_csrf();
        $raw = '';
        if (post('stage') === 'import') $raw = (string)($_SESSION['ct_import'][$cid] ?? '');
        else {
            $f = $_FILES['csv'] ?? null;
            if ($f && (int)$f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']) && (int)$f['size'] <= 5 * 1024 * 1024) $raw = (string)file_get_contents($f['tmp_name']);
        }
        if ($raw === '') { flash('error', t('Bitte eine CSV-Datei auswählen (höchstens 5 MB).')); ct_redirect('contract_import'); }
        $data = csv_read($raw);
        $head = array_shift($data) ?? [];
        $map = ct_csv_map($head);
        foreach (['bezeichnung', 'vertragspartner'] as $need) {
            if (!in_array($need, $map, true)) { flash('error', t('Die Spalte „{x}“ fehlt. Bitte die erste Zeile mit den Spaltennamen prüfen.', ['x' => t(CT_COLS[$need][0])])); ct_redirect('contract_import'); }
        }
        $rowsIn = [];
        foreach (array_slice($data, 0, 2000) as $line) {
            $vals = [];
            foreach ($map as $i => $k) $vals[$k] = trim((string)($line[$i] ?? ''));
            if (implode('', $vals) === '') continue;
            $rowsIn[] = $vals;
        }
        if (post('stage') !== 'import') {
            $_SESSION['ct_import'][$cid] = $raw;
            $plan = [];
            foreach (array_slice($rowsIn, 0, 15) as $vals) $plan[] = ['vals' => $vals, 'existing' => ct_find_existing($cid, $vals) !== null];
            $preview = ['map' => $map, 'head' => $head, 'plan' => $plan, 'total' => count($rowsIn),
                'costs_ignored' => !$r['costs'] && array_intersect(['kosten', 'kosten_zeitraum', 'zahlungsart'], $map)];
        } else {
            unset($_SESSION['ct_import'][$cid]);
            $ok = 0; $upd = 0; $same = 0; $errors = []; $notes = [];
            foreach ($rowsIn as $n => $vals) {
                $existing = ct_find_existing($cid, $vals);
                // leere Zellen ändern bestehende Verträge nicht
                if ($existing) $vals = array_filter($vals, fn($v) => $v !== '');
                [$d, $err, $nt] = ct_values_in($cid, $vals, $r['costs'], $existing);
                $line = t('Zeile {n}', ['n' => $n + 2]);
                if ($err) { $errors[] = $line . ': ' . implode(' ', $err); continue; }
                foreach ($nt as $x) $notes[$x] = $x;
                $before = $existing ? json_encode($existing) : '';
                $id = ct_save_values($cid, $d, $existing, 'Import');
                if (!$existing) $ok++;
                elseif (json_encode(row('SELECT * FROM contracts WHERE id = ?', [$id])) !== $before) $upd++;
                else $same++;
            }
            audit('contract.import', "$ok neu, $upd aktualisiert, " . count($errors) . ' Fehler', $cid);
            $result = ['ok' => $ok, 'upd' => $upd, 'same' => $same, 'errors' => $errors, 'notes' => array_values($notes)];
        }
    }

    render_page(t('Import') . ' · ' . t('Verträge & Laufzeiten'), function () use ($cid, $r, $preview, $result) { ?>
        <p><a href="<?= e(cturl('contracts')) ?>">← <?= e(t('Verträge & Laufzeiten')) ?></a></p>
        <?php if ($result): ?>
            <div class="card">
                <h2><?= e(t('Ergebnis')) ?></h2>
                <p><?= e(t('{n} Verträge angelegt, {u} aktualisiert, {s} unverändert, {e} mit Fehlern übersprungen.', ['n' => $result['ok'], 'u' => $result['upd'], 's' => $result['same'], 'e' => count($result['errors'])])) ?></p>
                <?php foreach ($result['notes'] as $nt): ?><p class="muted small"><?= e($nt) ?></p><?php endforeach; ?>
                <?php if ($result['errors']): ?><ul class="small"><?php foreach (array_slice($result['errors'], 0, 50) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <p><a class="btn" href="<?= e(cturl('contracts', ['status' => 'alle'])) ?>"><?= e(t('Zur Übersicht')) ?></a></p>
            </div>
        <?php elseif ($preview): ?>
            <div class="card">
                <h2><?= e(t('Vorschau')) ?></h2>
                <p><?= e(t('{n} Zeilen erkannt. Zugeordnete Spalten:', ['n' => $preview['total']])) ?>
                    <?php foreach ($preview['map'] as $i => $k): ?><span class="tag"><?= e($preview['head'][$i]) ?> → <?= e(t(CT_COLS[$k][0])) ?></span> <?php endforeach; ?></p>
                <?php $ignored = array_diff_key($preview['head'], $preview['map']); if ($ignored): ?><p class="muted small"><?= e(t('Nicht erkannt und ignoriert:')) ?> <?= e(implode(', ', $ignored)) ?></p><?php endif; ?>
                <?php if ($preview['costs_ignored']): ?><p class="warn-text small"><?= e(t('Kosten ignoriert: Dafür fehlt das Recht „Kosten sehen“.')) ?></p><?php endif; ?>
                <div class="table-scroll"><table class="table small"><thead><tr><th><?= e(t('Aktion')) ?></th><?php foreach ($preview['map'] as $k): ?><th><?= e(t(CT_COLS[$k][0])) ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach ($preview['plan'] as $p): ?><tr><td><span class="tag <?= $p['existing'] ? 'warn' : '' ?>"><?= e($p['existing'] ? t('aktualisieren') : t('neu')) ?></span></td>
                    <?php foreach ($preview['map'] as $k): ?><td><?= e($p['vals'][$k] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
                </tbody></table></div>
                <?php if ($preview['total'] > 15): ?><p class="muted small"><?= e(t('… und {n} weitere Zeilen.', ['n' => $preview['total'] - 15])) ?></p><?php endif; ?>
                <form method="post" action="<?= e(cturl('contract_import')) ?>" class="toolbar"><?= csrf_field() ?><input type="hidden" name="stage" value="import">
                    <button class="btn primary"><?= e(t('Jetzt importieren')) ?></button> <a class="btn" href="<?= e(cturl('contract_import')) ?>"><?= e(t('Abbrechen')) ?></a></form>
            </div>
        <?php else: ?>
            <form method="post" action="<?= e(cturl('contract_import')) ?>" enctype="multipart/form-data" class="form card">
                <?= csrf_field() ?>
                <h2><?= e(t('Verträge importieren')) ?></h2>
                <p><?= e(t('CSV-Datei mit einer Kopfzeile (Trennzeichen ; oder ,), z. B. aus Excel „CSV UTF-8“. Erkannte Spalten:')) ?></p>
                <p class="small"><?php foreach (CT_COLS as $k => [$l, $costOnly]): if ($costOnly && !$r['costs']) continue; ?><span class="tag"><?= e(t($l)) ?></span> <?php endforeach; ?></p>
                <p class="muted small"><?= e(t('Pflicht sind „Bezeichnung“ und „Vertragspartner“. Gibt es schon einen Vertrag mit gleichem Vertragspartner und gleicher Vertragsnummer (ohne Nummer: gleicher Bezeichnung), wird er aktualisiert; leere Zellen lassen bestehende Werte unverändert. Unbekannte Vertragsarten werden angelegt. Datum als TT.MM.JJJJ oder JJJJ-MM-TT; Zeitraum: Monat, Quartal, Jahr, einmalig; Einheit: Tage, Wochen, Monate; Betreuung: IT-Dienstleister, Firma selbst oder Name eines Dienstleisters.')) ?></p>
                <p><a href="<?= e(cturl('contract_export')) ?>"><?= e(t('Aktuelle Verträge als Vorlage exportieren')) ?></a></p>
                <label><?= e(t('CSV-Datei')) ?> <input type="file" name="csv" accept=".csv,text/csv" required></label>
                <button class="btn primary"><?= e(t('Vorschau anzeigen')) ?></button>
            </form>
        <?php endif; ?>
    <?php });
}

// ---------------------------------------------------------------------------
// Kostenübersicht
// ---------------------------------------------------------------------------

/** Kosten eines Vertrags im Monat (Monatsanfang $month = 'Y-m-01'), umgerechnet auf den Monat; einmalige Kosten im Monat des Beginns */
function ct_cost_in_month(array $c, array $prices, string $month): int
{
    $mEnd = date('Y-m-t', strtotime($month));
    if (($c['start_date'] ?? '') !== '' && $c['start_date'] > $mEnd) return 0;
    $end = '';
    if ($c['status'] === 'gekuendigt') $end = (string)($c['cancelled_to'] ?: $c['end_date']);
    elseif ($c['status'] === 'beendet') return 0;
    elseif (!(int)$c['auto_renew']) $end = (string)$c['end_date'];
    if ($end !== '' && $end < $month) return 0;
    $p = ct_price_at($c, $prices, max($month, (string)($c['start_date'] ?? '')));
    if ($p['cents'] === null) return 0;
    return match ($p['interval']) {
        'monat' => $p['cents'], 'quartal' => (int)round($p['cents'] / 3), 'jahr' => (int)round($p['cents'] / 12),
        'einmalig' => (($c['start_date'] ?? '') !== '' && substr($c['start_date'], 0, 7) === substr($month, 0, 7)) ? $p['cents'] : 0,
        default => 0,
    };
}

function page_contract_costs(): void
{
    $cid = ct_company_id();
    $r = ct_rights($cid);
    if ($r['perm'] === CT_PERM_NONE) abort(403);
    if (!$r['costs']) abort(403, t('Die Kostenübersicht sehen nur Benutzer mit dem Recht „Kosten sehen“.'));
    $invPossible = ct_inv_can_view($cid) && (int)(ct_company($cid)['ct_inv_licenses'] ?? 1);
    $withInv = $invPossible && ($_GET['inv'] ?? '1') !== '0';
    $list = ct_contracts_full($cid, 'c.status IN ' . CT_OPEN_SQL);
    $prices = ct_prices_for($cid);
    if ($withInv) foreach (ct_inv_license_rows($cid) as $l) if ($l['status'] !== 'beendet') $list[] = $l + ['start_date' => ''];
    $today = date('Y-m-d');
    $byType = []; $byPartner = []; $monthly = 0; $oneOff = [];
    foreach ($list as $c) {
        $pr = !empty($c['_inv']) ? [] : ($prices[(int)$c['id']] ?? []);
        $m = ct_monthly($c, ct_price_at($c, $pr, $today));
        $p = ct_price_at($c, $pr, $today);
        if ($p['interval'] === 'einmalig' && $p['cents'] !== null) $oneOff[] = ['c' => $c, 'cents' => $p['cents']];
        if ($m <= 0) continue;
        $monthly += $m;
        $tn = $c['type_name'] !== null ? t((string)$c['type_name']) : t('ohne Art');
        $byType[$tn] = ($byType[$tn] ?? 0) + $m;
        $pn = (string)$c['partner'] !== '' ? (string)$c['partner'] : '–';
        $byPartner[$pn] = ($byPartner[$pn] ?? 0) + $m;
    }
    arsort($byType); arsort($byPartner);
    // Hochrechnung der nächsten 12 Monate
    $forecast = [];
    $first = date('Y-m-01');
    for ($i = 0; $i < 12; $i++) {
        $month = date('Y-m-01', strtotime("$first +$i months"));
        $sum = 0;
        foreach ($list as $c) $sum += ct_cost_in_month($c, !empty($c['_inv']) ? [] : ($prices[(int)$c['id']] ?? []), $month);
        $forecast[$month] = $sum;
    }
    $events = ct_events($cid, $today, date('Y-m-d', strtotime('+12 months')), ['costs' => true, 'inv' => $withInv]);
    $max = max([1] + $forecast);
    // Stufe 7: Vorjahresvergleich (auch mit beendeten Verträgen)
    $year = max((int)date('Y') - 10, min((int)date('Y') + 1, query_int('jahr') ?: (int)date('Y')));
    $all = ct_contracts_full($cid);
    if ($withInv) foreach (ct_inv_license_rows($cid) as $l) $all[] = $l + ['start_date' => ''];
    $cmp = [$year - 1 => ct_year_costs($all, $prices, $year - 1), $year => ct_year_costs($all, $prices, $year)];

    render_page(t('Kostenübersicht') . ' · ' . t('Verträge & Laufzeiten'), function () use ($cid, $monthly, $byType, $byPartner, $forecast, $oneOff, $events, $max, $withInv, $invPossible, $year, $cmp) {
        echo company_switcher($cid, 'contract_costs');
        ct_tabs('contract_costs'); ?>
        <div class="toolbar">
            <?php if ($invPossible): ?><a class="btn" href="<?= e(cturl('contract_costs', ['inv' => $withInv ? '0' : '1'])) ?>"><?= e($withInv ? t('ohne Lizenzen aus dem Inventar') : t('mit Lizenzen aus dem Inventar')) ?></a><?php endif; ?>
            <a class="btn" href="<?= e(cturl('contract_export', ['what' => 'preise'])) ?>"><?= e(t('Preishistorie als CSV')) ?></a>
        </div>
        <div class="tiles">
            <div class="tile"><strong><?= e(ct_fmt_price($monthly)) ?></strong><span><?= e(t('laufende Kosten pro Monat')) ?></span></div>
            <div class="tile"><strong><?= e(ct_fmt_price($monthly * 12)) ?></strong><span><?= e(t('pro Jahr (bei heutigen Preisen)')) ?></span></div>
            <div class="tile"><strong><?= e(ct_fmt_price(array_sum($forecast))) ?></strong><span><?= e(t('nächste 12 Monate (mit Änderungen)')) ?></span></div>
        </div>
        <p class="muted small"><?= e(t('Alle Beträge wie eingetragen (z. B. netto). Vierteljährliche und jährliche Kosten sind auf den Monat umgerechnet.')) ?>
            <?= $withInv ? e(t('Lizenzen aus dem Inventar sind enthalten.')) : '' ?></p>
        <div class="grid2">
            <div class="card">
                <h2><?= e(t('Nach Vertragsart')) ?></h2>
                <?php ct_cost_table($byType, $monthly); ?>
            </div>
            <div class="card">
                <h2><?= e(t('Nach Anbieter')) ?></h2>
                <?php ct_cost_table($byPartner, $monthly); ?>
            </div>
        </div>
        <div class="card">
            <h2><?= e(t('Hochrechnung der nächsten 12 Monate')) ?></h2>
            <p class="muted small"><?= e(t('Berücksichtigt Preisänderungen, gekündigte und auslaufende Verträge sowie den Vertragsbeginn. Einmalige Kosten zählen im Monat des Beginns.')) ?></p>
            <table class="table"><thead><tr><th><?= e(t('Monat')) ?></th><th class="num"><?= e(t('Kosten')) ?></th><th></th></tr></thead><tbody>
            <?php foreach ($forecast as $m => $sum): ?><tr><td><?= e(ct_month_label($m)) ?></td><td class="num nowrap"><?= e(ct_fmt_price($sum)) ?></td>
                <td class="ct-bar"><progress max="<?= (int)$max ?>" value="<?= (int)$sum ?>"></progress></td></tr><?php endforeach; ?>
            <tr><th><?= e(t('Summe')) ?></th><th class="num nowrap"><?= e(ct_fmt_price(array_sum($forecast))) ?></th><th></th></tr>
            </tbody></table>
        </div>
        <?php ct_year_compare_card($year, $cmp, $withInv); ?>
        <?php if ($oneOff): ?>
        <div class="card">
            <h2><?= e(t('Einmalige Kosten')) ?></h2>
            <table class="table"><tbody>
            <?php foreach ($oneOff as $o): ?><tr><td><?= e($o['c']['title']) ?></td><td><?= e($o['c']['partner']) ?></td><td><?= ($o['c']['start_date'] ?? '') !== '' ? fmt_date($o['c']['start_date']) : '–' ?></td>
                <td class="num nowrap"><?= e(ct_fmt_price($o['cents'])) ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <?php endif; ?>
        <div class="card">
            <h2><?= e(t('Ausblick: Fristen und Preisänderungen')) ?></h2>
            <?php if (!$events): ?><p class="muted"><?= e(t('In diesem Zeitraum stehen keine Fristen an.')) ?></p><?php else: ?>
            <ul class="inv-log">
            <?php foreach ($events as $ev): ?><li><span class="muted small"><?= fmt_date($ev['date']) ?></span>
                <span class="tag <?= $ev['kind'] === 'cancel' ? 'danger' : ($ev['kind'] === 'end' ? '' : 'warn') ?>"><?= e(t(CT_EVENT_LABELS[$ev['kind']])) ?></span>
                <a href="<?= e(ct_event_url($cid, $ev)) ?>"><?= e($ev['title']) ?></a> <span class="muted small">· <?= e($ev['hint']) ?></span></li><?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <p><a href="<?= e(cturl('contract_deadlines')) ?>"><?= e(t('Zum Fristenkalender')) ?></a></p>
        </div>
    <?php });
}

/**
 * Kosten eines Vertrags in einem Monat, auch rückblickend: beendete Verträge zählen bis zu ihrem Ende
 * (Gekündigt zum, Vertragsende oder – ohne Datum – der Tag, an dem sie auf „Beendet“ gesetzt wurden).
 */
function ct_cost_in_month_hist(array $c, array $prices, string $month): int
{
    if ($c['status'] !== 'beendet') return ct_cost_in_month($c, $prices, $month);
    $end = (string)(($c['cancelled_to'] ?? '') ?: ($c['end_date'] ?? '') ?: substr((string)($c['updated_at'] ?? ''), 0, 10));
    if ($end === '' || $end < $month) return 0;
    return ct_cost_in_month(['status' => 'gekuendigt', 'cancelled_to' => $end] + $c, $prices, $month);
}

/** Kosten eines Kalenderjahres: ['months' => [JJJJ-MM-01 => Cent], 'types' => [Art => Cent], 'total' => Cent] */
function ct_year_costs(array $list, array $prices, int $year): array
{
    $months = []; $types = [];
    for ($m = 1; $m <= 12; $m++) {
        $month = sprintf('%04d-%02d-01', $year, $m);
        $sum = 0;
        foreach ($list as $c) {
            $v = ct_cost_in_month_hist($c, !empty($c['_inv']) ? [] : ($prices[(int)$c['id']] ?? []), $month);
            if ($v <= 0) continue;
            $sum += $v;
            $tn = ($c['type_name'] ?? null) !== null ? t((string)$c['type_name']) : t('ohne Art');
            $types[$tn] = ($types[$tn] ?? 0) + $v;
        }
        $months[$month] = $sum;
    }
    return ['months' => $months, 'types' => $types, 'total' => array_sum($months)];
}

function ct_diff_cell(int $old, int $new): string
{
    $d = $new - $old;
    $cls = $d > 0 ? 'up' : ($d < 0 ? 'down' : '');
    $pct = $old > 0 ? ' (' . ($d > 0 ? '+' : '') . number_format($d / $old * 100, 1, ',', '.') . ' %)' : '';
    return '<td class="num nowrap ' . $cls . '">' . ($d > 0 ? '+' : '') . e(ct_fmt_price($d)) . e($pct) . '</td>';
}

function ct_year_compare_card(int $year, array $cmp, bool $withInv): void
{
    $prev = $cmp[$year - 1]; $cur = $cmp[$year];
    $thisMonth = date('Y-m-01');
    $types = array_unique(array_merge(array_keys($prev['types']), array_keys($cur['types'])));
    usort($types, fn($a, $b) => ($cur['types'][$b] ?? 0) <=> ($cur['types'][$a] ?? 0) ?: strcmp($a, $b));
    $hasForecast = $cur['months'] && array_key_last($cur['months']) >= $thisMonth; ?>
    <div class="card" id="vorjahr">
        <h2><?= e(t('Vorjahresvergleich {a} – {b}', ['a' => $year - 1, 'b' => $year])) ?></h2>
        <div class="toolbar">
            <a class="btn small" href="<?= e(cturl('contract_costs', ['jahr' => $year - 1, 'inv' => $withInv ? '1' : '0'])) ?>#vorjahr">‹ <?= e((string)($year - 2)) ?> – <?= e((string)($year - 1)) ?></a>
            <?php if ($year < (int)date('Y') + 1): ?><a class="btn small" href="<?= e(cturl('contract_costs', ['jahr' => $year + 1, 'inv' => $withInv ? '1' : '0'])) ?>#vorjahr"><?= e((string)$year) ?> – <?= e((string)($year + 1)) ?> ›</a><?php endif; ?>
        </div>
        <div class="tiles">
            <div class="tile"><strong><?= e(ct_fmt_price($prev['total'])) ?></strong><span><?= e((string)($year - 1)) ?></span></div>
            <div class="tile"><strong><?= e(ct_fmt_price($cur['total'])) ?></strong><span><?= e((string)$year) ?><?= $hasForecast ? ' ' . e(t('(mit Hochrechnung)')) : '' ?></span></div>
            <div class="tile"><strong><?= e(($cur['total'] - $prev['total'] > 0 ? '+' : '') . ct_fmt_price($cur['total'] - $prev['total'])) ?></strong>
                <span><?= e(t('Veränderung')) ?><?= $prev['total'] > 0 ? ' · ' . e(($cur['total'] >= $prev['total'] ? '+' : '') . number_format(($cur['total'] - $prev['total']) / $prev['total'] * 100, 1, ',', '.') . ' %') : '' ?></span></div>
        </div>
        <p class="muted small"><?= e(t('Berechnet aus den eingetragenen Verträgen und Preisen (keine Buchhaltungsdaten). Beendete Verträge zählen bis zu ihrem Ende; Verträge ohne Beginn zählen, als ob sie schon im Vorjahr liefen. Monate ab heute sind hochgerechnet.')) ?></p>
        <div class="grid2">
            <div>
                <h3><?= e(t('Nach Vertragsart')) ?></h3>
                <?php if (!$types): ?><p class="muted"><?= e(t('Keine Kosten in diesem Zeitraum.')) ?></p><?php else: ?>
                <table class="table"><thead><tr><th></th><th class="num"><?= e((string)($year - 1)) ?></th><th class="num"><?= e((string)$year) ?></th><th class="num"><?= e(t('Veränderung')) ?></th></tr></thead><tbody>
                <?php foreach ($types as $tn): $a = $prev['types'][$tn] ?? 0; $b = $cur['types'][$tn] ?? 0; ?>
                    <tr><td><?= e($tn) ?></td><td class="num nowrap"><?= e(ct_fmt_price($a)) ?></td><td class="num nowrap"><?= e(ct_fmt_price($b)) ?></td><?= ct_diff_cell($a, $b) ?></tr>
                <?php endforeach; ?>
                <tr><th><?= e(t('Summe')) ?></th><th class="num nowrap"><?= e(ct_fmt_price($prev['total'])) ?></th><th class="num nowrap"><?= e(ct_fmt_price($cur['total'])) ?></th><?= ct_diff_cell($prev['total'], $cur['total']) ?></tr>
                </tbody></table>
                <?php endif; ?>
            </div>
            <div>
                <h3><?= e(t('Nach Monat')) ?></h3>
                <table class="table"><thead><tr><th><?= e(t('Monat')) ?></th><th class="num"><?= e((string)($year - 1)) ?></th><th class="num"><?= e((string)$year) ?></th><th class="num"><?= e(t('Veränderung')) ?></th></tr></thead><tbody>
                <?php foreach (array_keys($cur['months']) as $i => $m): $pm = sprintf('%04d-%s', $year - 1, substr($m, 5)); $a = $prev['months'][$pm] ?? 0; $b = $cur['months'][$m]; ?>
                    <tr><td><?= e(ct_month_label($m)) ?><?= $m >= $thisMonth ? ' <span class="muted small">' . e(t('Hochrechnung')) . '</span>' : '' ?></td>
                        <td class="num nowrap"><?= e(ct_fmt_price($a)) ?></td><td class="num nowrap"><?= e(ct_fmt_price($b)) ?></td><?= ct_diff_cell($a, $b) ?></tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
    </div>
<?php }

function ct_cost_table(array $rows, int $total): void
{
    if (!$rows) { echo '<p class="muted">' . e(t('Keine laufenden Kosten erfasst.')) . '</p>'; return; } ?>
    <table class="table"><thead><tr><th></th><th class="num"><?= e(t('pro Monat')) ?></th><th class="num"><?= e(t('pro Jahr')) ?></th><th class="num"><?= e(t('Anteil')) ?></th></tr></thead><tbody>
    <?php foreach ($rows as $k => $m): ?><tr><td><?= e((string)$k) ?></td><td class="num nowrap"><?= e(ct_fmt_price($m)) ?></td><td class="num nowrap"><?= e(ct_fmt_price($m * 12)) ?></td>
        <td class="num"><?= $total > 0 ? number_format($m / $total * 100, 0, ',', '.') . ' %' : '–' ?></td></tr><?php endforeach; ?>
    </tbody></table>
<?php }

// ---------------------------------------------------------------------------
// API-Schlüssel (Einstellungen)
// ---------------------------------------------------------------------------

/** POST-Aktionen der Schlüsselverwaltung; true = erledigt */
function ct_api_settings_post(int $cid, string $action): bool
{
    if ($action === 'api_create') {
        $name = mb_substr(trim((string)post('name')), 0, 120) ?: t('API-Zugang');
        [$plain, $prefix, $hash] = api_token_generate();
        insert('api_tokens', ['company_id' => $cid, 'name' => $name, 'prefix' => $prefix, 'token_hash' => $hash, 'can_write' => post('can_write') === '1' ? 1 : 0,
            'scope' => 'vertraege', 'see_costs' => ct_can_costs($cid) && post('see_costs') === '1' ? 1 : 0, 'active' => 1, 'created_by' => current_user()['id'], 'created_at' => now()]);
        audit('contract.api_token_created', $name, $cid);
        $_SESSION['ct_new_api_token'] = $plain;
        flash('success', t('Der API-Schlüssel wurde erzeugt. Kopieren Sie ihn jetzt, er wird nur einmal angezeigt.'));
        return true;
    }
    if ($action === 'api_revoke') {
        $tk = row("SELECT * FROM api_tokens WHERE id = ? AND company_id = ? AND scope = 'vertraege'", [(int)post('id', 0), $cid]);
        if (!$tk) abort(404);
        q('DELETE FROM api_tokens WHERE id = ?', [$tk['id']]);
        audit('contract.api_token_revoked', $tk['name'], $cid);
        flash('success', t('Der API-Schlüssel wurde gelöscht und funktioniert nicht mehr.'));
        return true;
    }
    return false;
}

function ct_api_settings_card(int $cid): void
{
    $tokens = rows("SELECT t.*, u.name AS creator FROM api_tokens t LEFT JOIN users u ON u.id = t.created_by WHERE t.company_id = ? AND t.scope = 'vertraege' ORDER BY t.created_at DESC", [$cid]);
    $new = $_SESSION['ct_new_api_token'] ?? null;
    unset($_SESSION['ct_new_api_token']);
    $base = rtrim((string)cfg('base_url', ''), '/') . '/api.php'; ?>
    <div class="card">
        <h2><?= e(t('Schnittstelle (API)')) ?></h2>
        <p class="muted"><?= e(t('Verträge aus anderen Programmen lesen, anlegen und ändern sowie Fristen abfragen. Jeder Schlüssel gilt nur für diese Firma.')) ?></p>
        <?php if ($new): ?><div class="alert success"><?= e(t('Neuer Schlüssel (nur jetzt sichtbar):')) ?> <code class="secret"><?= e($new) ?></code></div><?php endif; ?>
        <?php if ($tokens): ?><table class="table"><thead><tr><th><?= e(t('Bezeichnung')) ?></th><th><?= e(t('Rechte')) ?></th><th><?= e(t('Zuletzt benutzt')) ?></th><th></th></tr></thead><tbody>
            <?php foreach ($tokens as $tk): ?><tr><td><?= e($tk['name']) ?> <code class="muted"><?= e($tk['prefix']) ?>…</code></td>
                <td><?= e((int)$tk['can_write'] ? t('lesen und schreiben') : t('nur lesen')) ?><?= (int)$tk['see_costs'] ? ' · ' . e(t('mit Kosten')) : '' ?></td>
                <td><?= $tk['last_used_at'] ? fmt_dt($tk['last_used_at']) : '–' ?></td>
                <td><form method="post" class="inline-form inline-mini"><?= csrf_field() ?><input type="hidden" name="action" value="api_revoke"><input type="hidden" name="id" value="<?= (int)$tk['id'] ?>">
                    <button class="btn small danger" data-confirm="<?= e(t('API-Schlüssel „{x}“ löschen? Programme, die ihn nutzen, verlieren sofort den Zugriff.', ['x' => $tk['name']])) ?>"><?= e(t('Löschen')) ?></button></form></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
        <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="api_create">
            <label><?= e(t('Bezeichnung')) ?> <input name="name" maxlength="120" required placeholder="<?= e(t('z. B. Buchhaltung, CRM')) ?>"></label>
            <label class="check"><input type="checkbox" name="can_write" value="1"> <?= e(t('darf Verträge anlegen und ändern')) ?></label>
            <?php if (ct_can_costs($cid)): ?><label class="check"><input type="checkbox" name="see_costs" value="1"> <?= e(t('darf Kosten lesen und schreiben')) ?></label><?php endif; ?>
            <button class="btn primary"><?= e(t('Schlüssel erzeugen')) ?></button>
        </form>
        <details class="api-doc"><summary><?= e(t('Kurzanleitung zur Schnittstelle')) ?></summary>
            <p><?= e(t('Adresse:')) ?> <code><?= e($base) ?></code> · <code>Authorization: Bearer kp_…</code></p>
            <table class="table compact">
                <tr><td><code>GET /api.php/vertraege?status=laufend&amp;seite=1&amp;pro_seite=100</code></td><td><?= e(t('Verträge auflisten (status: laufend, alle, aktiv, gekuendigt, …)')) ?></td></tr>
                <tr><td><code>GET /api.php/vertraege/{id}</code></td><td><?= e(t('einen Vertrag abrufen, mit Preishistorie (bei Kostenrecht)')) ?></td></tr>
                <tr><td><code>POST /api.php/vertraege</code></td><td><?= e(t('Vertrag anlegen; mit gleichem Vertragspartner und gleicher Vertragsnummer wird aktualisiert. Auch eine Liste ist möglich.')) ?></td></tr>
                <tr><td><code>PATCH /api.php/vertraege/{id}</code></td><td><?= e(t('einzelne Felder ändern')) ?></td></tr>
                <tr><td><code>GET /api.php/fristen?monate=12</code></td><td><?= e(t('Fristen und Preisänderungen der nächsten Monate')) ?></td></tr>
                <tr><td><code>GET /api.php/vertragsarten</code></td><td><?= e(t('Vertragsarten der Firma')) ?></td></tr>
            </table>
            <p class="muted small"><?= e(t('Felder:')) ?> <?= e(implode(', ', array_keys(CT_COLS))) ?>. <?= e(t('Datum als JJJJ-MM-TT, Kosten als Zahl (z. B. 49.9).')) ?></p>
        </details>
    </div>
<?php }

// ---------------------------------------------------------------------------
// REST-API (aus api.php aufgerufen; $token ist geprüft und hat scope „vertraege“)
// ---------------------------------------------------------------------------

function ct_api(array $token, array $parts, string $method, ?array $body): never
{
    $cid = (int)$token['company_id'];
    if (!company_has_app($cid, CT_APP)) api_error(403, t('Die App „Verträge & Laufzeiten“ ist für diese Firma nicht freigeschaltet.'));
    $costs = (int)$token['see_costs'] === 1;
    $label = 'API: ' . $token['name'];
    $res = $parts[0];
    if ($res === 'vertragsarten' && $method === 'GET') {
        api_out(200, array_map(fn($tp) => ['id' => (int)$tp['id'], 'name' => $tp['name'], 'aktiv' => (bool)(int)$tp['active']],
            rows('SELECT * FROM ct_types WHERE company_id = ? ORDER BY sort, name', [$cid])));
    }
    if ($res === 'fristen' && $method === 'GET') {
        $months = max(1, min(24, (int)($_GET['monate'] ?? $_GET['months'] ?? 12)));
        $ev = ct_events($cid, date('Y-m-d'), date('Y-m-d', strtotime("+$months months")), ['costs' => $costs, 'inv' => false]);
        api_out(200, array_map(fn($e) => ['datum' => $e['date'], 'ereignis' => $e['kind'], 'vertrag_id' => $e['id'], 'bezeichnung' => $e['title'],
            'vertragspartner' => $e['partner'], 'hinweis' => $e['hint']] + ($costs ? ['kosten' => $e['cents'] === null ? null : $e['cents'] / 100, 'kosten_zeitraum' => $e['interval']] : []), $ev));
    }
    if ($res !== 'vertraege') api_error(404, t('Unbekannter Pfad.'));
    $out = function (array $c) use ($cid, $costs, $parts): array {
        $prices = $costs ? (ct_prices_for($cid, [(int)$c['id']])[(int)$c['id']] ?? []) : [];
        $v = ['id' => (int)$c['id']] + ct_values_out($c, $costs, $prices, true) + ['geaendert' => $c['updated_at']];
        if ($costs && count($parts) === 2) {
            $v['preishistorie'] = array_merge([['gueltig_ab' => $c['start_date'] ?: null, 'kosten' => $c['cost_cents'] === null ? null : $c['cost_cents'] / 100, 'kosten_zeitraum' => $c['cost_interval']]],
                array_map(fn($p) => ['gueltig_ab' => $p['valid_from'], 'kosten' => $p['cost_cents'] === null ? null : $p['cost_cents'] / 100, 'kosten_zeitraum' => $p['cost_interval'], 'anlass' => $p['note']], $prices));
        }
        return $v;
    };
    $load = function (int $id) use ($cid): array {
        $c = ct_contracts_full($cid, 'c.id = ?', [$id])[0] ?? null;
        if (!$c) api_error(404, t('Vertrag nicht gefunden.'));
        return $c;
    };
    $check = function (array $in) {
        $unknown = array_diff(array_keys($in), array_keys(CT_COLS), ['id', 'geaendert', 'naechstes_ende', 'kuendigen_bis', 'preishistorie']);
        if ($unknown) api_error(422, t('Unbekannte Felder: ') . implode(', ', $unknown));
        foreach ($in as $k => $v) if (!is_scalar($v) && $v !== null) api_error(422, t('Feld „{x}“ muss Text oder Zahl sein.', ['x' => $k]));
        return array_intersect_key($in, CT_COLS);
    };
    if (count($parts) === 1 && $method === 'GET') {
        $per = min(500, max(1, (int)($_GET['pro_seite'] ?? $_GET['per_page'] ?? 100)));
        $page = max(1, (int)($_GET['seite'] ?? $_GET['page'] ?? 1));
        $status = (string)($_GET['status'] ?? 'laufend');
        $where = $status === 'alle' ? '' : ($status === 'laufend' ? 'c.status IN ' . CT_OPEN_SQL : 'c.status = ?');
        $p = ($status === 'alle' || $status === 'laufend') ? [] : [$status];
        if (!empty($_GET['geaendert_seit'])) { $where .= ($where !== '' ? ' AND ' : '') . 'c.updated_at >= ?'; $p[] = date('Y-m-d H:i:s', strtotime((string)$_GET['geaendert_seit']) ?: 0); }
        $all = ct_contracts_full($cid, $where, $p);
        api_out(200, ['gesamt' => count($all), 'seite' => $page, 'pro_seite' => $per, 'vertraege' => array_map($out, array_slice($all, ($page - 1) * $per, $per))]);
    }
    if (count($parts) === 1 && $method === 'POST') {
        $list = array_is_list($body) ? $body : [$body];
        if (count($list) > 1000) api_error(413, t('Höchstens 1000 Verträge pro Aufruf.'));
        $results = [];
        db()->beginTransaction();
        foreach ($list as $i => $item) {
            if (!is_array($item)) { db()->rollBack(); api_error(422, t('Jeder Eintrag muss ein Objekt sein.')); }
            $vals = $check($item);
            $existing = ct_find_existing($cid, $vals);
            [$d, $err, $notes] = ct_values_in($cid, $vals, $costs, $existing);
            if ($err) { db()->rollBack(); api_error(422, (count($list) > 1 ? t('Eintrag {n}: ', ['n' => $i + 1]) : '') . implode(' ', $err)); }
            $id = ct_save_values($cid, $d, $existing, $label);
            $results[] = ['id' => $id, 'aktion' => $existing ? 'aktualisiert' : 'angelegt'] + ($notes ? ['hinweise' => $notes] : []);
        }
        db()->commit();
        audit('contract.api_saved', $label . ' (' . count($results) . ')', $cid);
        if (!array_is_list($body)) api_out($results[0]['aktion'] === 'angelegt' ? 201 : 200, $out($load($results[0]['id'])) + (isset($results[0]['hinweise']) ? ['hinweise' => $results[0]['hinweise']] : []));
        api_out(200, ['ergebnisse' => $results]);
    }
    if (count($parts) === 2 && ctype_digit($parts[1])) {
        $c = $load((int)$parts[1]);
        if ($method === 'GET') api_out(200, $out($c));
        if ($method === 'PATCH' || $method === 'PUT') {
            $raw = row('SELECT * FROM contracts WHERE id = ?', [$c['id']]);
            [$d, $err, $notes] = ct_values_in($cid, $check($body ?? []), $costs, $raw);
            if ($err) api_error(422, implode(' ', $err));
            ct_save_values($cid, $d, $raw, $label);
            audit('contract.api_updated', $label . ' #' . $c['id'], $cid);
            api_out(200, $out($load((int)$c['id'])) + ($notes ? ['hinweise' => $notes] : []));
        }
        api_error(405, t('Methode nicht erlaubt.'));
    }
    api_error(404, t('Unbekannter Pfad.'));
}
