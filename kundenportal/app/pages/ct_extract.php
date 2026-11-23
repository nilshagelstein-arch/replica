<?php
/**
 * App „Verträge & Laufzeiten“ – Stufe 7: Vertragsdaten aus PDF auslesen
 *
 * - Standard ohne KI und ohne Datenübertragung: Der Server liest den Text der PDF (app/pdftext.php) und sucht darin
 *   Vertragspartner mit Anschrift, Vertrags- und Kundennummer, Beginn, Ende, Mindestlaufzeit, Kündigungsfrist,
 *   automatische Verlängerung und Betrag mit Zeitraum. Alles sind Vorschläge, die der Benutzer prüft und übernimmt.
 * - Für gescannte PDFs (nur Bild, kein Text) kann die Firma selbst die Texterkennung „Mistral OCR“ einschalten
 *   (Mistral AI, Paris, Verarbeitung in der EU). Dazu hinterlegt sie ihren eigenen API-Schlüssel und bestätigt
 *   Auftragsverarbeitungsvertrag und Ausschluss des Trainings. Vor jeder Übertragung steht auf der Seite, welcher Dienst
 *   genutzt wird, und der Benutzer muss zustimmen. Der Dienstleister kann die Funktion nicht für die Firma einschalten.
 * - Zwei Wege: Dokument eines bestehenden Vertrags auslesen und Felder gezielt übernehmen, oder neuen Vertrag aus einer
 *   PDF erfassen (Formular vorausgefüllt, die PDF wird beim Anlegen als Dokument „Vertrag“ angehängt).
 */

declare(strict_types=1);

require_once APP_DIR . '/pdftext.php';

const CT_OCR_URL = 'https://api.mistral.ai/v1/ocr';
const CT_OCR_MODEL = 'mistral-ocr-latest';
const CT_EXTRACT_FIELDS = ['title' => 'Bezeichnung', 'type_id' => 'Vertragsart', 'partner' => 'Vertragspartner', 'partner_street' => 'Straße des Anbieters',
    'partner_zip' => 'PLZ des Anbieters', 'partner_city' => 'Ort des Anbieters', 'partner_email' => 'E-Mail des Anbieters', 'contract_no' => 'Vertragsnummer',
    'customer_no' => 'Kundennummer', 'start_date' => 'Beginn', 'end_date' => 'Ende', 'min_term_months' => 'Mindestlaufzeit', 'notice' => 'Kündigungsfrist',
    'renew' => 'Automatische Verlängerung', 'cost' => 'Kosten'];
/** Stichworte für die Vertragsart (Standardnamen der Vertragsarten) */
const CT_EXTRACT_TYPE_WORDS = [
    'Mobilfunk' => '/\b(mobilfunk|sim-?karte|mobil\s?\w*\s?(?:tarif|vertrag)|handyvertrag|business\s+mobil|smartphone-?tarif|5g-?tarif)/iu',
    'Internet und Telefonie' => '/\b(dsl|vdsl|glasfaser|internetanschluss|festnetz|telefonanschluss|sip-?trunk|kabelinternet|breitband)/iu',
    'Software-Abo' => '/\b(software|lizenz(?:en)?|subscription|abonnement|microsoft\s*365|office\s*365|saas|cloud-?abo)/iu',
    'Wartung und Support' => '/\b(wartungsvertrag|wartung|supportvertrag|service-?vertrag|managed\s+service)/iu',
    'Hosting und Domains' => '/\b(hosting|webspace|domain|server-?miete|rechenzentrum|colocation)/iu',
    'Leasing und Miete' => '/\b(leasing(?:vertrag)?|mietvertrag\s+(?:für|über)\s+(?:geräte|drucker|kopierer)|kopierer|multifunktionssystem)/iu',
    'Versicherung' => '/\b(versicherung(?:sschein|svertrag)?|police|versicherungsnehmer)/iu',
    'Strom und Gas' => '/\b(strom(?:liefer)?vertrag|gasliefer|erdgas|kwh|stromtarif|energieliefer)/iu',
    'Fahrzeuge und Leasing' => '/\b(fahrzeug|kfz|pkw|kennzeichen)/iu',
    'Miete und Pacht' => '/\b(gewerbemiet|mietfläche|pachtvertrag|büroräume)/iu',
];
const CT_MONTHS_DE = ['januar' => 1, 'jan' => 1, 'februar' => 2, 'feb' => 2, 'märz' => 3, 'maerz' => 3, 'mär' => 3, 'april' => 4, 'apr' => 4, 'mai' => 5,
    'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9, 'oktober' => 10, 'okt' => 10,
    'november' => 11, 'nov' => 11, 'dezember' => 12, 'dez' => 12];

// ---------------------------------------------------------------------------
// Erkennung
// ---------------------------------------------------------------------------

/** Datumsangabe als JJJJ-MM-TT, sonst '' */
function ct_x_date(string $s): string
{
    $s = trim($s);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/^(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{2,4})$/', $s, $m)) [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/^(\d{1,2})\.\s?([\pL]+)\.?\s+(\d{4})$/u', $s, $m) && isset(CT_MONTHS_DE[mb_strtolower($m[2])])) [$d, $mo, $y] = [(int)$m[1], CT_MONTHS_DE[mb_strtolower($m[2])], (int)$m[3]];
    else return '';
    if ($y < 100) $y += 2000;
    if ($y < 1990 || $y > 2100 || !checkdate($mo, $d, $y)) return '';
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

/** Zahlwörter durch Ziffern ersetzen („drei Monate“ → „3 Monate“) */
function ct_x_numbers(string $s): string
{
    static $words = ['sechsunddreißig' => 36, 'achtundvierzig' => 48, 'vierundzwanzig' => 24, 'achtzehn' => 18, 'sechzig' => 60, 'zwölf' => 12, 'zwoelf' => 12,
        'elf' => 11, 'zehn' => 10, 'neun' => 9, 'acht' => 8, 'sieben' => 7, 'sechs' => 6, 'fünf' => 5, 'fuenf' => 5, 'vier' => 4, 'drei' => 3, 'zwei' => 2,
        'einem' => 1, 'einen' => 1, 'einer' => 1, 'eines' => 1, 'eins' => 1, 'ein' => 1];
    foreach ($words as $w => $n) $s = preg_replace('/\b' . $w . '\b(?=\s+(?:weitere[nsr]?\s+)?(?:volle[n]?\s+)?(?:Kalender)?(?:Tag|Woche|Monat|Jahr))/iu', (string)$n, $s) ?? $s;
    return $s;
}

/** Name ohne Rechtsform und Sonderzeichen, zum Vergleich mit der eigenen Firma */
function ct_x_norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/\b(gmbh|mbh|ag|se|kg|ohg|gbr|ug|e\.?\s?k|e\.?\s?v|co|ltd|limited|inc|llc|haftungsbeschränkt)\b/u', ' ', $s) ?? $s;
    return trim(preg_replace('/[^\pL\pN]+/u', '', $s) ?? $s);
}

function ct_x_amount(string $s): ?int
{
    $s = str_replace([' ', "\u{00A0}"], '', $s);
    if (!preg_match('/^\d{1,3}(?:\.\d{3})*,\d{2}$|^\d+,\d{2}$|^\d+\.\d{2}$/', $s)) return null;
    if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
    return (int)round((float)$s * 100);
}

/**
 * Vorschläge aus dem Text eines Vertragsdokuments
 * @return array Feld => ['value' => …, 'hint' => Fundstelle]
 */
function ct_extract_fields(string $text, int $cid): array
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
    $flat = ct_x_numbers(preg_replace('/\s+/u', ' ', implode(' ', $lines)) ?? '');
    $out = [];
    $hint = function (int $pos, int $len) use ($flat): string {
        $a = max(0, $pos - 40);
        $s = mb_strcut($flat, $a, $len + 80);
        return ($a > 0 ? '…' : '') . trim($s) . '…';
    };
    $find = function (array $patterns, int $group = 1) use ($flat, $hint): ?array {
        foreach ($patterns as $p) {
            if (preg_match($p, $flat, $m, PREG_OFFSET_CAPTURE)) return ['m' => array_map(fn($x) => $x[0], $m), 'hint' => $hint($m[0][1], strlen($m[0][0]))];
        }
        return null;
    };
    $D = '(\d{1,2}\.\s?\d{1,2}\.\s?\d{2,4}|\d{1,2}\.\s?(?:Januar|Februar|März|Maerz|April|Mai|Juni|Juli|August|September|Oktober|November|Dezember)\s+\d{4}|\d{4}-\d{2}-\d{2})';

    // Nummern
    $no = '([A-Z0-9][A-Za-z0-9\-\/\.]{1,38}[A-Za-z0-9])';
    if ($r = $find(['/\b(?:Vertrags|Auftrags|Kundenvertrags|Policen|Versicherungsschein|Contract|Abonnement|Abo|Leasingvertrags?|Mietvertrags?|Subscription)[\s-]?(?:nummer|nr\.?|no\.?|number|ID)\s*[:.]?\s*' . $no . '/iu',
        '/\bVertrag\s+(?:Nr\.?|Nummer)\s*[:.]?\s*' . $no . '/iu'])) $out['contract_no'] = ['value' => rtrim($r['m'][1], '.'), 'hint' => $r['hint']];
    if ($r = $find(['/\b(?:Kunden|Customer|Mitglieds|Debitoren)[\s-]?(?:nummer|nr\.?|no\.?|number|ID)\s*[:.]?\s*' . $no . '/iu'])) {
        $out['customer_no'] = ['value' => rtrim($r['m'][1], '.'), 'hint' => $r['hint']];
    }

    // Laufzeit „vom … bis …“
    if ($r = $find(['/\b(?:Laufzeit|Vertragslaufzeit|Vertragsdauer)\s*:?\s*(?:vom\s+)?' . $D . '\s*(?:-|–|bis(?: zum)?)\s*' . $D . '/iu'])) {
        if (($a = ct_x_date($r['m'][1])) !== '') $out['start_date'] = ['value' => $a, 'hint' => $r['hint']];
        if (($b = ct_x_date($r['m'][2])) !== '' && $b > ($a ?: '0')) $out['end_date'] = ['value' => $b, 'hint' => $r['hint']];
    }
    if (!isset($out['start_date']) && ($r = $find([
        '/\b(?:Vertragsbeginn|Beginn der (?:Vertrags|Miet)?laufzeit|Laufzeitbeginn|Leistungsbeginn|Vertragsstart|Startdatum|Beginn|Bereitstellungsdatum|Versicherungsbeginn|Lieferbeginn|Mietbeginn|Leasingbeginn|Abonnementbeginn)\s*(?:ist\s+der|am|ab|zum|:)?\s*(?:dem\s+)?' . $D . '/iu',
        '/\bbeginnt\s+(?:am|ab|mit dem|zum)\s+' . $D . '/iu',
        '/\bgültig\s+ab\s+(?:dem\s+)?' . $D . '/iu',
    ])) && ($a = ct_x_date($r['m'][1])) !== '') $out['start_date'] = ['value' => $a, 'hint' => $r['hint']];
    if (!isset($out['end_date']) && ($r = $find([
        '/\b(?:Vertragsende|Laufzeitende|Ende der (?:Vertrags|Mindest)?laufzeit|Enddatum|Ablaufdatum|Ablauf)\s*(?:am|zum|:)?\s*(?:dem\s+)?' . $D . '/iu',
        '/\bendet\s+(?:am|zum|mit Ablauf des)\s+' . $D . '/iu',
        '/\b(?:läuft|gilt|gültig)\s+bis\s+(?:zum\s+)?' . $D . '/iu',
    ])) && ($b = ct_x_date($r['m'][1])) !== '') $out['end_date'] = ['value' => $b, 'hint' => $r['hint']];

    // Mindestlaufzeit
    if ($r = $find([
        '/\b(?:Mindest(?:vertrags)?laufzeit|Erstlaufzeit|Grundlaufzeit|Grundmietzeit|Mietzeit|Vertragslaufzeit|Vertragsdauer|Laufzeit)\b[^\d.]{0,30}?(\d{1,3})\s*(Monat|Jahr)/iu',
        '/\b(\d{1,3})\s*(Monate|Jahre)\s+(?:Mindest)?(?:vertrags)?laufzeit/iu',
    ])) {
        $n = (int)$r['m'][1] * (mb_strtolower(mb_substr($r['m'][2], 0, 4)) === 'jahr' ? 12 : 1);
        if ($n > 0 && $n <= 600) $out['min_term_months'] = ['value' => $n, 'hint' => $r['hint']];
    }

    // Kündigungsfrist
    if ($r = $find([
        '/\bKündigungsfrist\b[^\d.]{0,30}?(\d{1,3})\s*(Tag|Woche|Monat)/iu',
        '/\b(?:mit\s+einer|unter\s+Einhaltung\s+einer)\s+Frist\s+von\s+(\d{1,3})\s*(Tag|Woche|Monat)/iu',
        '/\b(\d{1,3})\s*(Tage|Wochen|Monate)n?\s+(?:vor|zum)\s+(?:dem\s+)?(?:Ablauf|Ende|Laufzeitende|Vertragsende)/iu',
        '/\bkündbar\b[^.]{0,30}?\bFrist\s+von\s+(\d{1,3})\s*(Tag|Woche|Monat)/iu',
    ])) {
        $unit = match (mb_strtolower(mb_substr($r['m'][2], 0, 3))) { 'tag' => 'days', 'woc' => 'weeks', default => 'months' };
        $out['notice'] = ['value' => ['value' => (int)$r['m'][1], 'unit' => $unit], 'hint' => $r['hint']];
    } elseif ($r = $find(['/\b(?:monatlich|zum\s+Monatsende)\s+kündbar/iu'])) {
        $out['notice'] = ['value' => ['value' => 1, 'unit' => 'months'], 'hint' => $r['hint']];
    }

    // Verlängerung
    if ($r = $find(['/\b(?:keine\s+automatische\s+Verlängerung|verlängert\s+sich\s+nicht|endet\s+automatisch|ohne\s+dass\s+es\s+einer\s+Kündigung\s+bedarf)/iu'])) {
        $out['renew'] = ['value' => ['auto' => 0, 'months' => 0], 'hint' => $r['hint']];
    } elseif ($r = $find([
        '/\b(?:verlängert\s+sich|Verlängerung)[^.]{0,50}?\b(?:um|für)\s+(?:jeweils\s+)?(?:weitere[nsr]?\s+)?(\d{1,3})\s*(?:weitere[nsr]?\s+)?(Monat|Jahr)/iu',
    ])) {
        $n = (int)$r['m'][1] * (mb_strtolower(mb_substr($r['m'][2], 0, 4)) === 'jahr' ? 12 : 1);
        if ($n > 0 && $n <= 120) $out['renew'] = ['value' => ['auto' => 1, 'months' => $n], 'hint' => $r['hint']];
    } elseif ($r = $find(['/\bverlängert\s+sich[^.]{0,60}?auf\s+unbestimmte\s+Zeit/iu'])) {
        $out['renew'] = ['value' => ['auto' => 1, 'months' => 1], 'hint' => $r['hint']];
    } elseif ($r = $find(['/\bverlängert\s+sich\s+(?:automatisch|stillschweigend)/iu'])) {
        $out['renew'] = ['value' => ['auto' => 1, 'months' => 0], 'hint' => $r['hint']];
    }

    // Betrag mit Zeitraum (zeilenweise, damit der Zeitraum zum Betrag passt)
    $cands = [];
    foreach ($lines as $i => $l) {
        if (!preg_match_all('/(?:(?:€|EUR)\s*(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}))|(?:(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2})\s*(?:€|EUR\b|Euro\b))/u', $l, $mm, PREG_SET_ORDER)) continue;
        $ctx = $l . ' ' . ($lines[$i + 1] ?? '');
        $interval = null;
        if (preg_match('/einmalig|Einrichtung|Bereitstellungs|Anschlusspreis|Aktivierung|Anschlussgebühr/iu', $l)) $interval = 'einmalig';
        elseif (preg_match('/vierteljährl|quartal/iu', $ctx)) $interval = 'quartal';
        elseif (preg_match('/monatlich|pro\s+Monat|\/\s*Monat|\bmtl\b|je\s+Monat|im\s+Monat|Monatspreis|Grundgebühr|Grundpreis|monthly/iu', $ctx)) $interval = 'monat';
        elseif (preg_match('/jährlich|pro\s+Jahr|\/\s*Jahr|p\.\s?a\.|je\s+Jahr|im\s+Jahr|Jahresbeitrag|Jahrespreis|Jahresgebühr|yearly|annual/iu', $ctx)) $interval = 'jahr';
        if ($interval === null) continue;
        foreach ($mm as $x) {
            $cents = ct_x_amount($x[1] !== '' ? $x[1] : ($x[2] ?? ''));
            if ($cents === null || $cents <= 0) continue;
            $cands[] = ['cents' => $cents, 'interval' => $interval, 'line' => $l, 'total' => (bool)preg_match('/gesamt|summe|total|insgesamt/iu', $l)];
        }
    }
    // Ersatzweg für schmale Tabellenspalten („89,85 / € / monat / lich“): Umgebung im Fließtext prüfen
    if (!array_filter($cands, fn($c) => $c['interval'] !== 'einmalig')
        && preg_match_all('/(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2})\s*(?:€|EUR\b|Euro\b)/u', $flat, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($mm as $x) {
            $pos = $x[0][1];
            $before = substr($flat, max(0, $pos - 45), min(45, $pos));
            $after = substr($flat, $pos + strlen($x[0][0]), 30);
            if (preg_match('/einmalig|Einrichtung|Bereitstellung|Anschluss|Aktivierung/iu', $before)) continue;
            $interval = null;
            foreach ([$after, $before] as $ctx) {
                if (preg_match('/viertel\s?jährl|quartal/iu', $ctx)) $interval = 'quartal';
                elseif (preg_match('/monat\s?lich|pro\s+Monat|\/\s*Monat|\bmtl\b|Grundgebühr|Grundpreis/iu', $ctx)) $interval = 'monat';
                elseif (preg_match('/jähr\s?lich|pro\s+Jahr|\/\s*Jahr|p\.\s?a\./iu', $ctx)) $interval = 'jahr';
                if ($interval) break;
            }
            $cents = ct_x_amount($x[1][0]);
            if ($interval && $cents) $cands[] = ['cents' => $cents, 'interval' => $interval, 'line' => trim($before . $x[0][0] . $after), 'total' => (bool)preg_match('/gesamt|summe|total/iu', $before)];
        }
    }
    $recurring = array_values(array_filter($cands, fn($c) => $c['interval'] !== 'einmalig'));
    $pick = null;
    foreach ($recurring as $c) if ($c['total']) { $pick = $c; break; }
    $pick ??= $recurring[0] ?? null;
    if ($pick) $out['cost'] = ['value' => ['cents' => $pick['cents'], 'interval' => $pick['interval']], 'hint' => $pick['line']];

    // Vertragspartner: zuerst ausdrücklich benannt, sonst Briefkopf mit Rechtsform (nicht die eigene Firma)
    $own = ct_x_norm((string)(row('SELECT name FROM companies WHERE id = ?', [$cid])['name'] ?? ''));
    $isOwn = fn(string $n) => $own !== '' && ($n = ct_x_norm($n)) !== '' && (str_contains($n, $own) || str_contains($own, $n));
    $legal = '/\b(GmbH|AG|SE|KG|OHG|GbR|UG|mbH|e\.\s?K\.|e\.\s?V\.|Ltd\.?|Limited|Inc\.?|LLC|S\.A\.?S?\.?|B\.V\.|Versicherung(?:s-?AG)?|Stadtwerke)\b/u';
    $partnerIdx = null;
    foreach ($lines as $i => $l) {
        if (preg_match('/^(?:Vertragspartner|Anbieter|Auftragnehmer|Lieferant|Versicherer|Leasinggeber|Vermieter)\s*:\s*(.{2,120})$/iu', $l, $m) && !$isOwn($m[1])) {
            $out['partner'] = ['value' => trim($m[1]), 'hint' => $l];
            break;
        }
    }
    if (!isset($out['partner'])) {
        foreach (array_slice($lines, 0, 30, true) as $i => $l) {
            foreach (preg_split('/\s+[·|•]\s+/u', $l) ?: [] as $seg) {
                $seg = trim($seg);
                if (mb_strlen($seg) > 70 || !preg_match($legal, $seg) || $isOwn($seg)) continue;
                if (preg_match('/^(Sitz|Geschäftsführ|Registergericht|Amtsgericht|HRB|USt|Vorstand|Bank|IBAN)/iu', $seg)) continue;
                if (preg_match('/\b(?:hiermit|wir|Ihr|Sie|der|die|das)\b.*\b(?:Vertrag|kündigen)/iu', $seg)) continue;
                $out['partner'] = ['value' => $seg, 'hint' => $l];
                $partnerIdx = $i;
                break 2;
            }
        }
    }
    // Anschrift: Zeilen nach dem Briefkopf bzw. Teile derselben Zeile
    if ($partnerIdx !== null) {
        $after = [];
        $segs = preg_split('/\s+[·|•]\s+/u', $lines[$partnerIdx]) ?: [];
        if (count($segs) > 1) $after = array_slice($segs, 1);
        $after = array_merge($after, array_slice($lines, $partnerIdx + 1, 4));
        foreach ($after as $a) {
            $a = trim($a);
            if (!isset($out['partner_street']) && preg_match('/^(?:[\pL][\pL\s.\'\-]{2,60}\s\d{1,4}\s?[a-zA-Z]?(?:\s?[-\/]\s?\d{1,4}[a-zA-Z]?)?|Postfach\s+[\d\s]{2,12})$/u', $a)) {
                $out['partner_street'] = ['value' => $a, 'hint' => $a];
            } elseif (!isset($out['partner_zip']) && preg_match('/^(?:D-)?(\d{5})\s+([\pL][\pL\s.\-\/()]{1,60})$/u', $a, $m)) {
                $out['partner_zip'] = ['value' => $m[1], 'hint' => $a];
                $out['partner_city'] = ['value' => trim($m[2]), 'hint' => $a];
                break;
            }
        }
        if (isset($out['partner_street']) && !isset($out['partner_zip'])) unset($out['partner_street']);
    }

    // E-Mail des Anbieters (keine Adressen der eigenen Firma)
    $ownDomains = [];
    foreach (rows('SELECT email FROM users WHERE company_id = ?', [$cid]) as $u) $ownDomains[mb_strtolower((string)substr(strrchr((string)$u['email'], '@') ?: '', 1))] = true;
    if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $flat, $em)) {
        $best = null; $score = -1;
        foreach (array_unique($em[0]) as $mail) {
            $dom = mb_strtolower(substr(strrchr($mail, '@'), 1));
            if (isset($ownDomains[$dom]) || !filter_var($mail, FILTER_VALIDATE_EMAIL)) continue;
            $s = preg_match('/k(?:ue|ü)ndig|vertrag|kontakt|kundenservice|service/i', $mail) ? 2 : (preg_match('/info|office/i', $mail) ? 1 : 0);
            if ($s > $score) { $best = $mail; $score = $s; }
        }
        if ($best) $out['partner_email'] = ['value' => mb_strtolower($best), 'hint' => $best];
    }

    // Bezeichnung: Produkt oder Tarif
    foreach ($lines as $l) {
        if (preg_match('/^(?:Vertragsbestätigung|Auftragsbestätigung|Vertragsübersicht|Ihr Vertrag|Tarif|Produkt|Leistung|Vertrag über)\s*[:\-–]?\s*(.{3,80})$/iu', $l, $m)
            && !preg_match('/^(?:Leistung\s+Preis|Preis)$/iu', trim($m[1]))) {
            $out['title'] = ['value' => trim(trim($m[1]), '„“"\'.:'), 'hint' => $l];
            break;
        }
    }
    if (!isset($out['title']) && ($r = $find(['/\b(?:Ihr|Der)\s+(?:Vertrag|Tarif)\s+[„"]([^“"]{3,60})[“"]/u']))) $out['title'] = ['value' => $r['m'][1], 'hint' => $r['hint']];

    // Vertragsart nach Stichworten
    $types = rows('SELECT id, name FROM ct_types WHERE company_id = ? AND active = 1', [$cid]);
    foreach (CT_EXTRACT_TYPE_WORDS as $name => $re) {
        if (!preg_match($re, $flat, $m)) continue;
        foreach ($types as $tp) if (mb_strtolower($tp['name']) === mb_strtolower($name)) { $out['type_id'] = ['value' => (int)$tp['id'], 'hint' => $m[0]]; break 2; }
    }
    return $out;
}

/** Wert eines Vorschlags für die Anzeige */
function ct_x_show(string $field, $v): string
{
    return match ($field) {
        'start_date', 'end_date' => $v !== '' && $v !== null ? date('d.m.Y', strtotime((string)$v)) : '–',
        'min_term_months' => (int)$v ? t('{n} Monate', ['n' => (int)$v]) : '–',
        'notice' => is_array($v) && $v['value'] ? $v['value'] . ' ' . t((int)$v['value'] === 1 ? (['days' => 'Tag', 'weeks' => 'Woche'][$v['unit']] ?? 'Monat') : (CT_NOTICE_UNITS[$v['unit']] ?? 'Monate')) : '–',
        'renew' => is_array($v) ? ($v['auto'] ? ($v['months'] ? t('automatisch um {n} Monate', ['n' => $v['months']]) : t('automatisch')) : t('keine')) : '–',
        'cost' => is_array($v) && $v['cents'] !== null ? ct_fmt_price((int)$v['cents']) . ' ' . t(CT_INTERVALS[$v['interval']] ?? '') : '–',
        'type_id' => ($tn = (string)(row('SELECT name FROM ct_types WHERE id = ?', [(int)$v])['name'] ?? '')) !== '' ? t($tn) : '–',
        default => (string)$v !== '' ? (string)$v : '–',
    };
}

/** Aktueller Wert eines Vertrags im Format der Vorschläge */
function ct_x_current(array $c, string $field)
{
    return match ($field) {
        'notice' => ['value' => (int)$c['notice_value'], 'unit' => (string)$c['notice_unit']],
        'renew' => ['auto' => (int)$c['auto_renew'], 'months' => (int)$c['auto_renew'] ? (int)$c['renew_months'] : 0],
        'cost' => ['cents' => $c['cost_cents'] === null ? null : (int)$c['cost_cents'], 'interval' => (string)$c['cost_interval']],
        'min_term_months', 'type_id' => (int)$c[$field],
        default => (string)$c[$field],
    };
}

function ct_x_same(string $field, $cur, $new): bool
{
    if ($field === 'renew') return $cur['auto'] === $new['auto'] && (!$new['months'] || $cur['months'] === $new['months']);
    if (is_array($cur)) return $cur == $new;
    return mb_strtolower(trim((string)$cur)) === mb_strtolower(trim((string)$new));
}

function ct_x_empty(string $field, $cur): bool
{
    return match ($field) {
        'notice' => true,   // hat immer einen Standardwert; als „leer“ behandeln, damit der Fund vorgeschlagen wird
        'renew' => true,
        'cost' => $cur['cents'] === null,
        'min_term_months', 'type_id' => (int)$cur === 0,
        default => trim((string)$cur) === '',
    };
}

// ---------------------------------------------------------------------------
// Texterkennung (OCR) über Mistral – nur wenn die Firma sie eingeschaltet hat
// ---------------------------------------------------------------------------

function ct_ocr_settings(int $cid): array
{
    $r = row('SELECT ct_ocr_enabled, ct_ocr_key, ct_ocr_confirmed_by, ct_ocr_confirmed_at FROM companies WHERE id = ?', [$cid]) ?? [];
    return ['enabled' => (int)($r['ct_ocr_enabled'] ?? 0) === 1 && (string)($r['ct_ocr_key'] ?? '') !== '', 'has_key' => (string)($r['ct_ocr_key'] ?? '') !== '',
        'by' => $r['ct_ocr_confirmed_by'] ?? null, 'at' => (string)($r['ct_ocr_confirmed_at'] ?? '')];
}

/** Text einer PDF per Mistral OCR; [Text, Fehler] */
function ct_ocr_text(int $cid, string $pdf): array
{
    $row = row('SELECT ct_ocr_enabled, ct_ocr_key FROM companies WHERE id = ?', [$cid]);
    $key = decrypt_secret($row['ct_ocr_key'] ?? null);
    if (!(int)($row['ct_ocr_enabled'] ?? 0) || $key === '') return [null, t('Die Texterkennung ist für diese Firma nicht eingeschaltet.')];
    $body = json_encode(['model' => CT_OCR_MODEL, 'document' => ['type' => 'document_url', 'document_url' => 'data:application/pdf;base64,' . base64_encode($pdf)]]);
    try {
        [$status, $resp] = http_call('POST', (string)cfg('mistral_ocr_url', CT_OCR_URL), ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'], $body, 90);
    } catch (Throwable $ex) {
        return [null, t('Die Texterkennung ist nicht erreichbar: {x}', ['x' => $ex->getMessage()])];
    }
    if ($status === 401 || $status === 403) return [null, t('Der API-Schlüssel für die Texterkennung wurde abgelehnt.')];
    if ($status === 429) return [null, t('Die Texterkennung meldet zu viele Anfragen (Kontingent erschöpft). Bitte später erneut versuchen.')];
    $data = json_decode($resp, true);
    if ($status !== 200 || !is_array($data) || !isset($data['pages'])) return [null, t('Die Texterkennung hat einen Fehler gemeldet (HTTP {n}).', ['n' => $status])];
    $text = '';
    foreach ($data['pages'] as $p) $text .= (string)($p['markdown'] ?? '') . "\n\n";
    // Markdown-Auszeichnungen entfernen, Tabellenzellen als Leerzeichen
    $text = preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/^#+\s*/m', '/\*\*|__/', '/^\s*\|?[-:\s|]+\|?\s*$/m', '/\s*\|\s*/'], ['', '', '', '', ' '], $text) ?? $text;
    return [trim($text), null];
}

/** Einstellungen der Texterkennung; true = erledigt */
function ct_ocr_settings_post(int $cid, string $action): bool
{
    if ($action !== 'ocr') return false;
    $u = current_user();
    // nur die Firma selbst (Firmen-Admin) entscheidet über die Übertragung an einen Dritten
    if ($u['role'] !== 'firma_admin' || (int)$u['company_id'] !== $cid) abort(403);
    if (post('ocr_off') === '1') {
        update('companies', $cid, ['ct_ocr_enabled' => 0, 'ct_ocr_key' => null, 'ct_ocr_confirmed_by' => null, 'ct_ocr_confirmed_at' => null]);
        audit('contract.ocr_off', 'Mistral OCR', $cid);
        flash('success', t('Die Texterkennung ist ausgeschaltet, der API-Schlüssel wurde gelöscht.'));
        return true;
    }
    $key = trim((string)post('ocr_key'));
    $has = ct_ocr_settings($cid)['has_key'];
    if ($key === '' && !$has) { flash('error', t('Bitte den API-Schlüssel von Mistral eintragen.')); return true; }
    if (post('confirm_dpa') !== '1' || post('confirm_training') !== '1') {
        flash('error', t('Bitte beide Bestätigungen setzen. Ohne sie bleibt die Texterkennung aus.'));
        return true;
    }
    $data = ['ct_ocr_enabled' => 1, 'ct_ocr_confirmed_by' => $u['id'], 'ct_ocr_confirmed_at' => now()];
    if ($key !== '') $data['ct_ocr_key'] = encrypt_secret(mb_substr($key, 0, 200));
    update('companies', $cid, $data);
    audit('contract.ocr_on', 'Mistral OCR', $cid);
    flash('success', t('Die Texterkennung über Mistral OCR ist eingeschaltet. Vor jeder Übertragung wird nachgefragt.'));
    return true;
}

function ct_ocr_settings_card(int $cid): void
{
    $s = ct_ocr_settings($cid);
    $u = current_user();
    $canChange = $u['role'] === 'firma_admin' && (int)$u['company_id'] === $cid;
    $by = $s['by'] ? (string)(row('SELECT name FROM users WHERE id = ?', [(int)$s['by']])['name'] ?? '') : ''; ?>
    <div class="card">
        <h2><?= e(t('Daten aus PDF auslesen')) ?></h2>
        <p><?= e(t('Standard: Der Server liest den Text der PDF selbst und sucht Daten, Fristen und Beträge. Es werden keine Daten an Dritte übertragen und es entstehen keine Kosten.')) ?></p>
        <p class="muted"><?= e(t('Gescannte PDFs enthalten nur Bilder. Für sie kann die Firma die Texterkennung „Mistral OCR“ von Mistral AI (Paris, Verarbeitung in der EU) mit einem eigenen Konto einschalten. Dann wird die jeweilige PDF zur Texterkennung an Mistral übertragen – nur nach Rückfrage bei jedem Dokument.')) ?></p>
        <p><strong><?= e(t('Status:')) ?></strong> <?= $s['enabled'] ? '<span class="tag success">' . e(t('eingeschaltet')) . '</span> ' . e(t('bestätigt von {n} am {d}', ['n' => $by ?: '–', 'd' => $s['at'] !== '' ? fmt_dt($s['at']) : '–'])) : '<span class="tag">' . e(t('aus')) . '</span>' ?></p>
        <?php if (!$canChange): ?>
            <p class="muted small"><?= e(t('Ein- und ausschalten kann nur ein Firmen-Admin der Firma selbst.')) ?></p>
        <?php else: ?>
        <form method="post" class="form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="ocr">
            <label><?= e(t('API-Schlüssel von Mistral (console.mistral.ai)')) ?> <input type="password" name="ocr_key" maxlength="200" placeholder="<?= e($s['has_key'] ? t('gespeichert – leer lassen, um ihn zu behalten') : '') ?>"></label>
            <label class="check"><input type="checkbox" name="confirm_dpa" value="1" <?= $s['enabled'] ? 'checked' : '' ?>> <?= e(t('Wir haben den Auftragsverarbeitungsvertrag (DPA) von Mistral AI für unser Konto abgeschlossen.')) ?></label>
            <label class="check"><input type="checkbox" name="confirm_training" value="1" <?= $s['enabled'] ? 'checked' : '' ?>> <?= e(t('Wir haben geprüft, dass Mistral die übertragenen Dokumente in unserem Tarif nicht zum Training verwendet und nicht dauerhaft speichert.')) ?></label>
            <p class="muted small"><?= e(t('Kosten und Mengengrenzen richten sich nach Ihrem Mistral-Konto. Der Schlüssel wird verschlüsselt gespeichert und ist nicht in der Datensicherung enthalten.')) ?></p>
            <div class="toolbar"><button class="btn primary"><?= e($s['enabled'] ? t('Speichern') : t('Texterkennung einschalten')) ?></button>
                <?php if ($s['has_key']): ?><button class="btn danger" name="ocr_off" value="1" data-confirm="<?= e(t('Texterkennung ausschalten und API-Schlüssel löschen?')) ?>"><?= e(t('Ausschalten')) ?></button><?php endif; ?></div>
        </form>
        <?php endif; ?>
    </div>
<?php }

// ---------------------------------------------------------------------------
// Seite: auslesen, prüfen, übernehmen
// ---------------------------------------------------------------------------

function ct_extract_tmp(int $cid, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) return null;
    $t = $_SESSION['ct_extract'][$token] ?? null;
    if (!$t || (int)$t['cid'] !== $cid) return null;
    $path = ct_files_dir($cid) . '/tmp-' . $token . '.bin';
    return is_file($path) ? $t + ['path' => $path, 'token' => $token] : null;
}

/** PDF-Inhalt der Quelle (Dokument eines Vertrags oder neue Datei); [Bytes, Name, Cache-Schlüssel] */
function ct_extract_source(int $cid, ?array $c, int $fileId, string $token): array
{
    if ($c && $fileId) {
        $f = row('SELECT * FROM ct_files WHERE id = ? AND contract_id = ? AND company_id = ?', [$fileId, $c['id'], $cid]);
        if (!$f) abort(404);
        $path = ct_files_dir($cid) . '/' . basename((string)$f['stored_name']);
        if (!is_file($path)) abort(404);
        return [(string)file_get_contents($path), (string)$f['filename'], 'f' . $f['id']];
    }
    $tmp = ct_extract_tmp($cid, $token);
    if (!$tmp) { flash('error', t('Die hochgeladene Datei ist nicht mehr vorhanden. Bitte erneut hochladen.')); ct_redirect('contract_extract'); }
    return [(string)file_get_contents($tmp['path']), (string)$tmp['name'], 't' . $token];
}

/** Text der Quelle: zuerst direkt, sonst eine schon erfolgte Texterkennung dieser Sitzung; [Text, Quelle 'pdf'|'ocr'|''] */
function ct_extract_text(string $pdf, string $cacheKey): array
{
    $ocr = $_SESSION['ct_ocr_cache'][$cacheKey] ?? null;
    if (is_string($ocr) && $ocr !== '') return [$ocr, 'ocr'];
    $text = PdfText::extract($pdf);
    // weniger als ein paar Wörter: vermutlich gescannt
    return [$text, preg_match_all('/\pL{3,}/u', $text) >= 8 ? 'pdf' : ''];
}

function page_contract_extract(): void
{
    $cid = ct_company_id();
    if (ct_perm($cid) < CT_PERM_EDIT) abort(403);
    $costs = ct_can_costs($cid);
    $id = query_int('id');
    $c = $id ? ct_load($id, $cid) : null;
    if ($id && !$c) abort(404);
    $fileId = query_int('file');
    $token = (string)($_GET['from'] ?? '');
    $hasSource = ($c && $fileId) || $token !== '';
    $params = $c ? ['id' => $c['id'], 'file' => $fileId] : ($token !== '' ? ['from' => $token] : []);

    if (is_post()) {
        require_post_csrf();
        $action = (string)post('action');
        if ($action === 'upload_new') {
            // alte Zwischendateien (älter als ein Tag) aufräumen
            foreach (glob(ct_files_dir($cid) . '/tmp-*.bin') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
            [$files, $err] = ticket_uploads();
            if ($err) { flash('error', $err); ct_redirect('contract_extract'); }
            $fl = $files[0] ?? null;
            if (!$fl) { flash('error', t('Bitte eine Datei auswählen.')); ct_redirect('contract_extract'); }
            $head = (string)file_get_contents($fl['tmp'], false, null, 0, 1024);
            if (strtolower(pathinfo($fl['name'], PATHINFO_EXTENSION)) !== 'pdf' || !str_contains($head, '%PDF')) {
                flash('error', t('Bitte eine PDF-Datei hochladen.'));
                ct_redirect('contract_extract');
            }
            $tk = bin2hex(random_bytes(12));
            if (!move_uploaded_file($fl['tmp'], ct_files_dir($cid) . '/tmp-' . $tk . '.bin')) { flash('error', t('Die Datei konnte nicht gespeichert werden.')); ct_redirect('contract_extract'); }
            @chmod(ct_files_dir($cid) . '/tmp-' . $tk . '.bin', 0640);
            $_SESSION['ct_extract'] = array_slice(($_SESSION['ct_extract'] ?? []), -4, null, true);
            $_SESSION['ct_extract'][$tk] = ['cid' => $cid, 'name' => $fl['name'], 'size' => $fl['size']];
            ct_redirect('contract_extract', ['from' => $tk]);
        }
        if (!$hasSource) abort(400);
        [$pdf, $name, $cacheKey] = ct_extract_source($cid, $c, $fileId, $token);
        if ($action === 'ocr') {
            if (!ct_ocr_settings($cid)['enabled']) abort(403);
            if (post('consent') !== '1') { flash('error', t('Bitte bestätigen, dass die PDF an Mistral AI übertragen werden darf.')); ct_redirect('contract_extract', $params); }
            [$text, $err] = ct_ocr_text($cid, $pdf);
            if ($err !== null) { flash('error', $err); ct_redirect('contract_extract', $params); }
            $_SESSION['ct_ocr_cache'] = array_slice(($_SESSION['ct_ocr_cache'] ?? []), -4, null, true);
            $_SESSION['ct_ocr_cache'][$cacheKey] = mb_substr((string)$text, 0, 200000);
            audit('contract.ocr', $name, $cid);
            if ($c) ct_log($cid, (int)$c['id'], 'Text per Mistral OCR erkannt: ' . $name);
            flash('success', t('Der Text wurde erkannt.'));
            ct_redirect('contract_extract', $params);
        }
        if ($action === 'apply' && $c) {
            [$text] = ct_extract_text($pdf, $cacheKey);
            $found = ct_extract_fields($text, $cid);
            $chosen = array_keys(array_filter((array)($_POST['apply'] ?? []), fn($v) => $v === '1'));
            $data = [];
            $changes = [];
            $costChanges = [];
            foreach ($chosen as $field) {
                if (!isset($found[$field], CT_EXTRACT_FIELDS[$field])) continue;
                $v = $found[$field]['value'];
                switch ($field) {
                    case 'notice': $data['notice_value'] = (int)$v['value']; $data['notice_unit'] = $v['unit']; break;
                    case 'renew': $data['auto_renew'] = (int)$v['auto']; if ($v['months']) $data['renew_months'] = (int)$v['months']; break;
                    case 'cost': if (!$costs) continue 2; $data['cost_cents'] = (int)$v['cents']; $data['cost_interval'] = $v['interval']; break;
                    case 'partner_email': $data[$field] = mb_strtolower((string)$v); break;
                    default: $data[$field] = $v;
                }
                $entry = CT_EXTRACT_FIELDS[$field] . ': ' . ct_x_show($field, $v);
                if ($field === 'cost') $costChanges[] = $entry; else $changes[] = $entry;
            }
            if (!$data) { flash('error', t('Bitte mindestens ein Feld zum Übernehmen auswählen.')); ct_redirect('contract_extract', $params); }
            // Ende aus Beginn und Mindestlaufzeit, wenn kein Ende bekannt ist
            $start = $data['start_date'] ?? (string)$c['start_date'];
            $min = $data['min_term_months'] ?? (int)$c['min_term_months'];
            if (($data['end_date'] ?? (string)$c['end_date']) === '' && $start !== '' && $min > 0) {
                $data['end_date'] = ct_end_from_start($start, $min);
                $changes[] = 'Ende: ' . fmt_date($data['end_date']);
            }
            if (($data['end_date'] ?? (string)$c['end_date']) !== '' && $start !== '' && ($data['end_date'] ?? (string)$c['end_date']) < $start) {
                flash('error', t('Das Ende liegt vor dem Beginn.'));
                ct_redirect('contract_extract', $params);
            }
            update('contracts', (int)$c['id'], $data + ['updated_at' => now()]);
            ct_log($cid, (int)$c['id'], 'Aus Dokument übernommen: ' . $name);
            foreach ($changes as $ch) ct_log($cid, (int)$c['id'], $ch);
            foreach ($costChanges as $ch) ct_log($cid, (int)$c['id'], $ch, 'cost');
            audit('contract.extract', $c['title'] . ': ' . count($data) . ' Felder', $cid);
            flash('success', t('Die ausgewählten Angaben wurden übernommen.'));
            ct_redirect('contract', ['id' => $c['id']]);
        }
        abort(400);
    }

    if (!$hasSource) {
        // Einstieg: neuen Vertrag aus PDF erfassen
        render_page(t('Vertrag aus PDF erfassen'), function () use ($cid) {
            $ocr = ct_ocr_settings($cid); ?>
            <p><a href="<?= e(cturl('contracts')) ?>">← <?= e(t('Verträge & Laufzeiten')) ?></a></p>
            <div class="card">
                <h2><?= e(t('Vertrag aus PDF erfassen')) ?></h2>
                <p><?= e(t('Laden Sie einen Vertrag, eine Auftrags- oder Vertragsbestätigung als PDF hoch. Das Portal sucht Vertragspartner, Nummern, Laufzeit, Kündigungsfrist und Kosten und füllt damit das Formular vor. Sie prüfen alles, bevor der Vertrag angelegt wird.')) ?></p>
                <?php ct_extract_privacy_note($ocr['enabled']); ?>
                <form method="post" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="upload_new">
                    <label><?= e(t('PDF-Datei (höchstens 10 MB)')) ?> <input type="file" name="files[]" accept="application/pdf,.pdf" required></label>
                    <button class="btn primary"><?= e(t('Hochladen und auslesen')) ?></button>
                </form>
            </div>
        <?php });
        return;
    }

    [$pdf, $name, $cacheKey] = ct_extract_source($cid, $c, $fileId, $token);
    [$text, $source] = ct_extract_text($pdf, $cacheKey);
    $found = $source !== '' ? ct_extract_fields($text, $cid) : [];
    if (!$costs) unset($found['cost']);
    if ($token !== '') $_SESSION['ct_extract'][$token]['fields'] = $found;
    $ocr = ct_ocr_settings($cid);

    render_page(t('Daten aus PDF auslesen'), function () use ($cid, $c, $token, $name, $text, $source, $found, $ocr, $params) { ?>
        <p><a href="<?= e($c ? cturl('contract', ['id' => $c['id']]) : cturl('contracts')) ?>">← <?= e($c ? $c['title'] : t('Verträge & Laufzeiten')) ?></a></p>
        <div class="card">
            <h2><?= e(t('Daten aus „{x}“', ['x' => $name])) ?></h2>
            <?php if ($source === 'pdf'): ?><p class="muted"><?= e(t('Text direkt aus der PDF gelesen – ohne KI, ohne Übertragung an Dritte.')) ?></p>
            <?php elseif ($source === 'ocr'): ?><p class="muted"><?= e(t('Text per Texterkennung „Mistral OCR“ (Mistral AI, EU) erkannt.')) ?></p>
            <?php else: ?>
                <div class="alert warn"><?= e(t('In dieser PDF wurde kein Text gefunden. Vermutlich ist sie gescannt (nur Bild).')) ?></div>
            <?php endif; ?>
            <?php if ($source !== 'ocr'): ?>
                <?php if ($ocr['enabled']): ?>
                <form method="post" class="form ct-ocr"><?= csrf_field() ?><input type="hidden" name="action" value="ocr">
                    <p><strong><?= e(t('Texterkennung mit Mistral OCR')) ?></strong> – <?= e(t('Dienst: Mistral AI SAS, Paris (Verarbeitung in der EU). Die komplette PDF „{x}“ wird dazu an Mistral übertragen.', ['x' => $name])) ?></p>
                    <label class="check"><input type="checkbox" name="consent" value="1" required> <?= e(t('Ich bin einverstanden, dass diese PDF zur Texterkennung an Mistral AI übertragen wird.')) ?></label>
                    <button class="btn"><?= e($source === '' ? t('Text erkennen lassen') : t('Stattdessen per Texterkennung lesen')) ?></button>
                </form>
                <?php elseif ($source === ''): ?>
                <p class="muted small"><?= e(t('Für gescannte PDFs kann ein Firmen-Admin in den Einstellungen die Texterkennung „Mistral OCR“ einschalten. Ohne sie bitte die Angaben von Hand eintragen.')) ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($c): // bestehender Vertrag: Felder gezielt übernehmen ?>
        <div class="card">
            <h3><?= e(t('Gefundene Angaben')) ?></h3>
            <?php if (!$found): ?><p class="muted"><?= e(t('Es wurden keine passenden Angaben gefunden.')) ?></p><?php else: ?>
            <p class="muted small"><?= e(t('Vorschläge ohne Gewähr: Bitte mit dem Dokument vergleichen. Leere Felder sind vorausgewählt, abweichende Werte nicht.')) ?></p>
            <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="apply">
                <table class="table"><thead><tr><th></th><th><?= e(t('Feld')) ?></th><th><?= e(t('Bisher')) ?></th><th><?= e(t('Gefunden')) ?></th><th><?= e(t('Fundstelle')) ?></th></tr></thead><tbody>
                <?php foreach (CT_EXTRACT_FIELDS as $field => $label): if (!isset($found[$field])) continue;
                    $cur = ct_x_current($c, $field); $new = $found[$field]['value']; $same = ct_x_same($field, $cur, $new); $empty = ct_x_empty($field, $cur); ?>
                    <tr><td><?php if (!$same): ?><input type="checkbox" name="apply[<?= e($field) ?>]" value="1" <?= $empty ? 'checked' : '' ?> aria-label="<?= e(t('übernehmen') . ' · ' . t($label)) ?>"><?php else: ?>✓<?php endif; ?></td>
                        <td><?= e(t($label)) ?></td><td><?= e(ct_x_show($field, $cur)) ?></td>
                        <td><strong><?= e(ct_x_show($field, $new)) ?></strong><?= $same ? ' <span class="tag success">' . e(t('stimmt überein')) . '</span>' : (!$empty ? ' <span class="tag warn">' . e(t('abweichend')) . '</span>' : '') ?></td>
                        <td class="muted small"><?= e(mb_substr((string)$found[$field]['hint'], 0, 160)) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <button class="btn primary"><?= e(t('Ausgewählte übernehmen')) ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php else: // neuer Vertrag ?>
        <div class="card">
            <h3><?= e(t('Gefundene Angaben')) ?></h3>
            <?php if ($found): ?>
            <dl class="facts"><?php foreach (CT_EXTRACT_FIELDS as $field => $label): if (!isset($found[$field])) continue; ?>
                <dt><?= e(t($label)) ?></dt><dd><?= e(ct_x_show($field, $found[$field]['value'])) ?> <span class="muted small">· <?= e(mb_substr((string)$found[$field]['hint'], 0, 120)) ?></span></dd><?php endforeach; ?></dl>
            <?php else: ?><p class="muted"><?= e(t('Es wurden keine passenden Angaben gefunden. Sie können den Vertrag trotzdem anlegen, die PDF wird angehängt.')) ?></p><?php endif; ?>
            <p><a class="btn primary" href="<?= e(cturl('contract', ['id' => 'new', 'from' => $token])) ?>"><?= e(t('Weiter zum Formular')) ?></a></p>
            <p class="muted small"><?= e(t('Im Formular sind die Angaben vorausgefüllt. Beim Anlegen wird die PDF als Dokument „Vertrag“ gespeichert.')) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($text !== ''): ?>
        <details class="card"><summary><?= e(t('Gelesenen Text anzeigen')) ?></summary><pre class="pre ct-text"><?= e(mb_substr($text, 0, 20000)) ?></pre></details>
        <?php endif; ?>
    <?php });
}

function ct_extract_privacy_note(bool $ocrEnabled): void
{ ?>
    <p class="muted small"><?= e(t('Datenschutz: Die PDF wird auf diesem Server ausgelesen, ohne KI und ohne Übertragung an Dritte.')) ?>
        <?= $ocrEnabled ? e(t('Nur wenn Sie bei einer gescannten PDF ausdrücklich „Text erkennen lassen“ wählen, wird sie an Mistral AI (EU) übertragen.')) : '' ?></p>
<?php }

/** Vorbelegung des Formulars „Vertrag erfassen“ aus einer ausgelesenen PDF; null = keine */
function ct_extract_prefill(int $cid, string $token): ?array
{
    $tmp = ct_extract_tmp($cid, $token);
    if (!$tmp) return null;
    $f = $tmp['fields'] ?? [];
    $val = fn(string $k, $d = '') => $f[$k]['value'] ?? $d;
    $c = ['title' => (string)$val('title', (string)$val('partner')), 'type_id' => (int)$val('type_id', 0), 'partner' => (string)$val('partner'),
        'contract_no' => (string)$val('contract_no'), 'customer_no' => (string)$val('customer_no'), 'partner_contact' => '', 'partner_street' => (string)$val('partner_street'),
        'partner_zip' => (string)$val('partner_zip'), 'partner_city' => (string)$val('partner_city'), 'partner_email' => (string)$val('partner_email'),
        'responsible_user_id' => 0, 'status' => 'aktiv', 'cancelled_to' => '', 'start_date' => (string)$val('start_date'), 'end_date' => (string)$val('end_date'),
        'min_term_months' => (int)$val('min_term_months', 0), 'notice_value' => 3, 'notice_unit' => 'months', 'auto_renew' => 1, 'renew_months' => 12,
        'cost_cents' => null, 'cost_interval' => 'monat', 'payment_method' => '', 'managed_by' => 'it', 'provider_id' => 0, 'notes' => ''];
    if (isset($f['notice'])) { $c['notice_value'] = (int)$f['notice']['value']['value']; $c['notice_unit'] = $f['notice']['value']['unit']; }
    if (isset($f['renew'])) { $c['auto_renew'] = (int)$f['renew']['value']['auto']; if ($f['renew']['value']['months']) $c['renew_months'] = (int)$f['renew']['value']['months']; }
    if (isset($f['cost'])) { $c['cost_cents'] = (int)$f['cost']['value']['cents']; $c['cost_interval'] = $f['cost']['value']['interval']; }
    return ['c' => $c, 'name' => (string)$tmp['name'], 'count' => count($f), 'token' => $token];
}

/** Nach dem Anlegen: PDF als Dokument „Vertrag“ anhängen */
function ct_extract_attach(int $cid, int $contractId, string $token): void
{
    $tmp = ct_extract_tmp($cid, $token);
    if (!$tmp) return;
    $stored = $contractId . '-' . bin2hex(random_bytes(12)) . '.bin';
    if (!@rename($tmp['path'], ct_files_dir($cid) . '/' . $stored)) return;
    $fid = insert('ct_files', ['company_id' => $cid, 'contract_id' => $contractId, 'doc_kind' => 'vertrag', 'filename' => $tmp['name'], 'mime' => 'application/pdf',
        'size' => (int)filesize(ct_files_dir($cid) . '/' . $stored), 'stored_name' => $stored, 'user_id' => current_user()['id'], 'created_at' => now()]);
    ct_log($cid, $contractId, 'Aus PDF erfasst: ' . $tmp['name']);
    unset($_SESSION['ct_extract'][$token]);
    // erkannter Text gehört jetzt zum Dokument
    if (isset($_SESSION['ct_ocr_cache']['t' . $token])) { $_SESSION['ct_ocr_cache']['f' . $fid] = $_SESSION['ct_ocr_cache']['t' . $token]; unset($_SESSION['ct_ocr_cache']['t' . $token]); }
}
