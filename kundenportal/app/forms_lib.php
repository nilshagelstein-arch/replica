<?php
/**
 * App „Formulare“: gemeinsame Logik für Formulare, Briefkopf/Fußzeile,
 * Kundendatenbank, Platzhalter und API.
 */
declare(strict_types=1);

const FORMS_APP = 'formulare';

/** Standardfelder der Kundendatenbank: Schlüssel => [Bezeichnung, Spalte] */
const CUSTOMER_STD_FIELDS = [
    'nummer'   => ['Kundennummer', 'customer_no'],
    'firma'    => ['Firma', 'company_name'],
    'anrede'   => ['Anrede', 'salutation'],
    'titel'    => ['Titel', 'title'],
    'vorname'  => ['Vorname', 'first_name'],
    'nachname' => ['Nachname', 'last_name'],
    'strasse'  => ['Straße und Hausnummer', 'street'],
    'plz'      => ['PLZ', 'zip'],
    'ort'      => ['Ort', 'city'],
    'land'     => ['Land', 'country'],
    'email'    => ['E-Mail', 'email'],
    'telefon'  => ['Telefon', 'phone'],
    'notiz'    => ['Notiz', 'notes'],
];

const SALUTATIONS = ['', 'Herr', 'Frau', 'Divers', 'Firma'];

/** Briefpapier-Angaben (form_branding) => [Bezeichnung, Platzhalter] */
const BRANDING_FIELDS = [
    'website'       => ['Website', 'website'],
    'management'    => ['Geschäftsführung / Inhaber/in', 'geschaeftsfuehrung'],
    'register_info' => ['Registergericht und -nummer', 'register'],
    'vat_id'        => ['USt-IdNr.', 'ustid'],
    'tax_no'        => ['Steuernummer', 'steuernummer'],
    'bank'          => ['Bank', 'bank'],
    'iban'          => ['IBAN', 'iban'],
    'bic'           => ['BIC', 'bic'],
];

const LOGO_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
const LOGO_MAX_BYTES = 1048576;

// ---------------------------------------------------------------------------
// Firmenkontext und Rechte
// ---------------------------------------------------------------------------

/** Firma, in der gearbeitet wird; Dienstleister wählen sie per ?company= */
function forms_company_id(): int
{
    $u = require_app(FORMS_APP);
    $cid = context_company_id();
    if (!$cid) abort(404, t('Es ist noch keine Firma angelegt.'));
    require_company_access($cid);
    if (!row('SELECT id FROM companies WHERE id = ?', [$cid])) abort(404);
    if (!is_staff_role($u['role']) && !company_has_app($cid, FORMS_APP)) abort(403);
    $GLOBALS['forms_cid'] = $cid;
    forms_ensure_defaults($cid);
    return $cid;
}

/** Firmen-Administratoren und Dienstleister dürfen Logo, Vorlagen, Felder, Import und API verwalten */
function forms_can_manage(): bool
{
    $u = current_user();
    if ($u && is_staff_role($u['role'])) return admin_can('forms', true);
    return $u && $u['role'] === 'firma_admin';
}

function forms_require_manage(): void
{
    if (!forms_can_manage()) abort(403, t('Diese Einstellungen dürfen nur Firmen-Administratoren ändern.'));
}

/** URL innerhalb der App; Dienstleister behalten die gewählte Firma */
function furl(string $route, array $params = [], ?int $companyId = null): string
{
    if (is_admin()) {
        $cid = $companyId ?? (int)($GLOBALS['forms_cid'] ?? 0);
        if ($cid) $params = ['company' => $cid] + $params;
    }
    return url($route, $params);
}

function fredirect(string $route, array $params = []): never
{
    header('Location: ' . furl($route, $params));
    exit;
}

// ---------------------------------------------------------------------------
// HTML-Bereinigung (alles, was aus dem Editor kommt, läuft hier durch)
// ---------------------------------------------------------------------------

const HTML_ALLOWED = [
    'p' => [], 'div' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [],
    'strong' => [], 'em' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [],
    'br' => [], 'hr' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
    'table' => ['data-grow'], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
    'th' => ['colspan', 'rowspan', 'data-formula', 'data-format', 'data-input'],
    'td' => ['colspan', 'rowspan', 'data-formula', 'data-format', 'data-input'],
    'span' => [], 'a' => ['href'], 'img' => ['src', 'alt'],
];
const HTML_RENAME = ['b' => 'strong', 'i' => 'em', 'strike' => 's', 'del' => 's', 'ins' => 'u', 'font' => 'span', 'h5' => 'h4', 'h6' => 'h4'];
const HTML_DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea',
    'svg', 'math', 'link', 'meta', 'base', 'noscript', 'template', 'video', 'audio', 'canvas', 'head', 'title'];
const HTML_CLASSES = ['al-left', 'al-center', 'al-right', 'al-justify', 'fs-small', 'fs-large', 'fs-xlarge',
    'ph', 'ph-f', 'page-break', 'tbl-grid', 'tbl-plain', 'sig', 'img-s', 'img-m', 'img-l', 'c-muted', 'c-accent', 'v-top', 'v-bottom', 'w-50'];

/** Schriftarten, die der Editor anbietet (überall vorhandene Systemschriften) */
const DOC_FONTS = ['Arial', 'Calibri', 'Cambria', 'Georgia', 'Helvetica', 'Segoe UI', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana', 'Courier New'];
const NUMBER_FORMATS = ['', 'zahl', 'ganz', 'euro', 'prozent'];

/** Erlaubte CSS-Eigenschaften mit Prüfmuster für den Wert */
function style_rules(): array
{
    $len = '(?:\d{1,3}(?:\.\d{1,2})?)(?:mm|pt|px|em|cm)';
    $color = '(?:#[0-9a-f]{3}|#[0-9a-f]{6}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)|transparent)';
    $border = "(?:none|0|(?:\d(?:\.\d)?px\s+(?:solid|dashed|dotted|double)\s+$color))";
    return [
        'font-family'      => '[A-Za-z0-9 ,"\'\-]{1,80}',
        'font-size'        => '\d{1,2}(?:\.\d)?pt|' . $len,
        'font-weight'      => 'bold|normal|[1-9]00',
        'font-style'       => 'italic|normal',
        'text-decoration'  => '(?:none|underline|line-through)(?:\s+(?:underline|line-through))?',
        'text-decoration-line' => '(?:none|underline|line-through)(?:\s+(?:underline|line-through))?',
        'color'            => $color,
        'background-color' => $color,
        'text-align'       => 'left|center|right|justify|start|end',
        'vertical-align'   => 'top|middle|bottom|baseline|super|sub',
        'line-height'      => '\d(?:\.\d{1,2})?|normal',
        'margin-left'      => '0|' . $len,
        'margin-top'       => '0|' . $len,
        'margin-bottom'    => '0|' . $len,
        'text-indent'      => '-?(?:0|' . $len . ')',
        'padding-left'     => '0|' . $len,
        'width'            => '\d{1,3}(?:\.\d{1,3})?(?:%|mm|px)',
        'border-top'       => $border, 'border-right' => $border, 'border-bottom' => $border, 'border-left' => $border,
        'border'           => $border,
    ];
}

function sanitize_style(string $style): string
{
    $rules = style_rules();
    $out = [];
    // Rahmen-Einzelangaben (border-style, border-top-width …), wie Chrome sie schreibt, zusammenfassen
    $decls = [];
    foreach (explode(';', $style) as $decl) {
        if (!str_contains($decl, ':')) continue;
        [$prop, $val] = array_map('trim', explode(':', $decl, 2));
        $decls[strtolower($prop)] = $val;
    }
    foreach (['', '-top', '-right', '-bottom', '-left'] as $side) {
        $st = $decls["border{$side}-style"] ?? null;
        if ($st === null) continue;
        $st = strtolower(trim(explode(' ', trim($st))[0]));
        $w = trim(explode(' ', trim($decls["border{$side}-width"] ?? '1px'))[0]);
        $c = trim($decls["border{$side}-color"] ?? '#555555');
        if (str_contains($c, ' ') && !str_starts_with($c, 'rgb')) $c = explode(' ', $c)[0];
        if ($c === 'currentcolor' || $c === 'initial') $c = '#555555';
        $decls["border{$side}"] = in_array($st, ['none', 'hidden'], true) ? 'none' : "$w $st $c";
        foreach (['-style', '-width', '-color'] as $x) unset($decls["border{$side}{$x}"]);
    }
    unset($decls['border-image']);
    if (($decls['border'] ?? '') === 'medium') $decls['border'] = 'none';
    foreach ($decls as $prop => $val) {
        $val = trim(preg_replace('/\s*!important$/i', '', (string)$val));
        if (!isset($rules[$prop]) || $val === '' || strlen($val) > 120) continue;
        if (!preg_match('/^(?:' . $rules[$prop] . ')$/i', $val)) continue;
        if ($prop === 'text-decoration-line') $prop = 'text-decoration';
        $out[$prop] = $prop . ': ' . $val;
    }
    return implode('; ', $out);
}

function sanitize_html(string $html): string
{
    $html = trim($html);
    if ($html === '') return '';
    if (strlen($html) > 8 * 1024 * 1024) $html = substr($html, 0, 8 * 1024 * 1024);
    $html = str_replace("\u{200B}", '', $html);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('__root');
    if (!$root) return '';
    sanitize_children($doc, $root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return trim($out);
}

function sanitize_children(DOMDocument $doc, DOMNode $parent): void
{
    $children = [];
    foreach ($parent->childNodes as $c) $children[] = $c;
    foreach ($children as $node) {
        if ($node instanceof DOMText) continue;
        if (!($node instanceof DOMElement)) { $parent->removeChild($node); continue; }
        $tag = strtolower($node->nodeName);
        if (in_array($tag, HTML_DROP, true)) { $parent->removeChild($node); continue; }
        if ($tag === 'font') {
            // <font face/color/size> aus execCommand in Stile umwandeln
            $st = (string)$node->getAttribute('style');
            if ($node->hasAttribute('face')) $st .= ';font-family:' . $node->getAttribute('face');
            if ($node->hasAttribute('color')) $st .= ';color:' . $node->getAttribute('color');
            $sizes = ['1' => '8pt', '2' => '10pt', '3' => '11pt', '4' => '13pt', '5' => '16pt', '6' => '20pt', '7' => '28pt'];
            if (isset($sizes[(string)$node->getAttribute('size')])) $st .= ';font-size:' . $sizes[(string)$node->getAttribute('size')];
            $node->setAttribute('style', $st);
        }
        if (isset(HTML_RENAME[$tag])) {
            $new = $doc->createElement(HTML_RENAME[$tag]);
            foreach ($node->attributes as $a) $new->setAttribute($a->nodeName, $a->nodeValue);
            while ($node->firstChild) $new->appendChild($node->firstChild);
            $parent->replaceChild($new, $node);
            $node = $new;
            $tag = HTML_RENAME[$tag];
        }
        sanitize_children($doc, $node);
        if (!isset(HTML_ALLOWED[$tag])) {
            // unbekanntes Element: Inhalt behalten, Hülle entfernen
            while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
            $parent->removeChild($node);
            continue;
        }
        $keepClass = implode(' ', array_values(array_intersect(
            preg_split('/\s+/', (string)$node->getAttribute('class')) ?: [], HTML_CLASSES)));
        $style = sanitize_style((string)$node->getAttribute('style'));
        $attrs = [];
        foreach ($node->attributes as $a) $attrs[] = $a->nodeName;
        $isPh = in_array('ph', explode(' ', $keepClass), true);
        foreach ($attrs as $a) {
            $an = strtolower($a);
            $val = $node->getAttribute($a);
            $ok = false;
            if (in_array($an, HTML_ALLOWED[$tag], true)) {
                if ($an === 'href') $ok = (bool)preg_match('#^(https?://|mailto:|tel:)#i', trim($val));
                elseif ($an === 'src') $ok = (bool)preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#', $val);
                elseif ($an === 'colspan' || $an === 'rowspan') $ok = ctype_digit($val) && (int)$val > 0 && (int)$val <= 50;
                elseif ($an === 'data-formula') $ok = (bool)preg_match('/^=[A-Za-z0-9ÄÖÜäöü_+\-*\/^%&(),;:.<>="\' ]{1,300}$/u', $val);
                elseif ($an === 'data-format') $ok = in_array($val, NUMBER_FORMATS, true) && $val !== '';
                elseif ($an === 'data-input' || $an === 'data-grow') $ok = $val === '1';
                else $ok = true;
            }
            if (!$ok) $node->removeAttribute($a);
        }
        if ($keepClass !== '') $node->setAttribute('class', $keepClass);
        if ($style !== '') $node->setAttribute('style', $style);
        if ($isPh && $tag === 'span') $node->setAttribute('contenteditable', 'false');
        if ($tag === 'img' && !$node->hasAttribute('src')) { $parent->removeChild($node); continue; }
        if ($tag === 'a' && $node->hasAttribute('href')) $node->setAttribute('rel', 'noopener');
        if ($tag === 'span' && !$node->attributes->length && $node->parentNode) {
            // nackte <span> ohne Wirkung entfernen
            while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
            $parent->removeChild($node);
        }
    }
}

// ---------------------------------------------------------------------------
// Seiteneinstellungen je Formular (Ränder, Grundschrift)
// ---------------------------------------------------------------------------

const PAGE_DEFAULTS = ['m' => [20, 20, 15, 25], 'font' => 'Arial', 'size' => 11, 'ls' => 1.4];

function page_settings(?string $json): array
{
    $p = json_decode((string)$json, true);
    $p = is_array($p) ? $p : [];
    $m = isset($p['m']) && is_array($p['m']) && count($p['m']) === 4 ? array_map(fn($x) => max(0, min(60, (float)$x)), array_values($p['m'])) : PAGE_DEFAULTS['m'];
    $font = in_array($p['font'] ?? '', DOC_FONTS, true) ? $p['font'] : PAGE_DEFAULTS['font'];
    $size = (float)($p['size'] ?? PAGE_DEFAULTS['size']);
    $size = $size >= 6 && $size <= 30 ? $size : PAGE_DEFAULTS['size'];
    $ls = (float)($p['ls'] ?? PAGE_DEFAULTS['ls']);
    $ls = $ls >= 0.8 && $ls <= 3 ? $ls : PAGE_DEFAULTS['ls'];
    return ['m' => $m, 'font' => $font, 'size' => $size, 'ls' => $ls];
}

/** Inline-Stil für ein Blatt: Ränder und Grundschrift */
function page_style(array $p): string
{
    [$t, $r, $b, $l] = $p['m'];
    $font = $p['font'] === 'Times New Roman' || $p['font'] === 'Georgia' || $p['font'] === 'Cambria' ? "'{$p['font']}', serif" : "'{$p['font']}', Arial, sans-serif";
    return "padding: {$t}mm {$r}mm {$b}mm {$l}mm; font-family: $font; font-size: {$p['size']}pt; line-height: {$p['ls']}";
}

// ---------------------------------------------------------------------------
// Platzhalter und Felder
// ---------------------------------------------------------------------------

const PH_REGEX = '/\{\{\s*([^{}<>]{1,200}?)\s*\}\}/u';
/** Feldtypen, die beim Ausfüllen eingegeben werden */
const FIELD_TYPES = ['eingabe' => 'Eingabefeld', 'text' => 'Textfeld (mehrzeilig)', 'zahl' => 'Zahlenfeld', 'datum' => 'Datumsfeld',
    'ankreuzen' => 'Ankreuzfeld', 'auswahl' => 'Auswahlliste'];

/** Alle Platzhalter-Schlüssel in einem Text (ohne Klammern) */
function placeholders_in(string $html): array
{
    preg_match_all(PH_REGEX, $html, $m);
    return array_values(array_unique(array_map(fn($k) => trim(html_entity_decode($k, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $m[1])));
}

/** Manuelle Felder eines Formulars: [['key'=>…, 'label'=>…, 'type'=>…, 'options'=>[…]]] */
function form_inputs(string $html): array
{
    $out = [];
    foreach (placeholders_in($html) as $k) {
        if (preg_match('/^(eingabe|text|zahl|datum|ankreuzen|auswahl):\s*(.+)$/iu', $k, $m)) {
            $parts = array_map('trim', explode('|', $m[2]));
            $out[$k] = ['key' => $k, 'type' => strtolower($m[1]), 'label' => array_shift($parts), 'options' => array_values(array_filter($parts, 'strlen'))];
        }
    }
    return array_values($out);
}

function uses_customer(string $html): bool
{
    foreach (placeholders_in($html) as $k) if (stripos($k, 'kunde.') === 0) return true;
    return false;
}

/** Platzhaltergruppen für die Auswahl im Editor */
function placeholder_catalog(int $companyId): array
{
    $kunde = [];
    foreach (CUSTOMER_STD_FIELDS as $k => [$label]) $kunde['kunde.' . $k] = t($label);
    $kunde['kunde.name'] = t('Name (Vorname Nachname)');
    $kunde['kunde.anschrift'] = t('Anschrift (mehrzeilig)');
    $kunde['kunde.briefanrede'] = t('Briefanrede („Sehr geehrte Frau …“)');
    foreach (customer_custom_fields($companyId) as $f) $kunde['kunde.' . $f['field_key']] = $f['label'];

    $firma = ['firma.name' => t('Firmenname'), 'firma.strasse' => t('Straße'), 'firma.plz' => t('PLZ'), 'firma.ort' => t('Ort'),
        'firma.telefon' => t('Telefon'), 'firma.email' => t('E-Mail'), 'firma.ansprechpartner' => t('Ansprechpartner/in'),
        'firma.absenderzeile' => t('Absenderzeile (eine Zeile)')];
    foreach (BRANDING_FIELDS as [$label, $key]) $firma['firma.' . $key] = t($label);

    return [
        'Kunde' => $kunde,
        'Eigene Firma' => $firma,
        'Allgemein' => ['logo' => t('Logo'), 'datum' => t('Datum (20.11.2026)'), 'datum.lang' => t('Datum ausgeschrieben'),
            'benutzer.name' => t('Name des Erstellers'), 'benutzer.email' => t('E-Mail des Erstellers')],
        // Nur im Kündigungsschreiben der App „Verträge & Laufzeiten“ gefüllt
        'Vertrag (Kündigung)' => ['vertrag.bezeichnung' => t('Vertragsbezeichnung'), 'vertrag.partner' => t('Vertragspartner'),
            'vertrag.nummer' => t('Vertragsnummer'), 'vertrag.kundennummer' => t('Kundennummer'), 'vertrag.referenz' => t('Vertrags- und Kundennummer (eine Zeile)'),
            'vertrag.termin' => t('Kündigungstermin'), 'vertrag.beginn' => t('Vertragsbeginn')],
    ];
}

function german_date_long(int $ts): string
{
    // Monatsnamen in der aktuellen Sprache; Deutsch „22. November 2026“, sonst „22 November 2026“ bzw. „22 novembre 2026“
    $months = [1 => t('Januar'), t('Februar'), t('März'), t('April'), t('Mai'), t('Juni'), t('Juli'), t('August'), t('September'), t('Oktober'), t('November'), t('Dezember')];
    return date('j', $ts) . (current_lang() === 'de' ? '. ' : ' ') . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Werte eines Kunden als Platzhalter (kunde.*) */
function customer_values(?array $c, int $companyId): array
{
    $v = [];
    foreach (CUSTOMER_STD_FIELDS as $k => [, $col]) $v['kunde.' . $k] = (string)($c[$col] ?? '');
    $extra = $c ? (json_decode((string)($c['extra'] ?? ''), true) ?: []) : [];
    foreach (customer_custom_fields($companyId) as $f) $v['kunde.' . $f['field_key']] = (string)($extra[$f['field_key']] ?? '');
    $person = trim(implode(' ', array_filter([$v['kunde.titel'], $v['kunde.vorname'], $v['kunde.nachname']])));
    $v['kunde.name'] = trim($v['kunde.vorname'] . ' ' . $v['kunde.nachname']);
    $sal = $v['kunde.anrede'];
    $nameLine = trim((in_array($sal, ['Herr', 'Frau'], true) ? $sal . ' ' : '') . $person);
    $lines = array_filter([$v['kunde.firma'], $nameLine, $v['kunde.strasse'],
        trim($v['kunde.plz'] . ' ' . $v['kunde.ort']),
        in_array(mb_strtolower($v['kunde.land']), ['', 'deutschland', 'de', 'germany'], true) ? '' : mb_strtoupper($v['kunde.land'])]);
    $v['kunde.anschrift'] = implode("\n", $lines);
    $last = trim(implode(' ', array_filter([$v['kunde.titel'], $v['kunde.nachname']])));
    if ($sal === 'Herr' && $last !== '') $v['kunde.briefanrede'] = t('Sehr geehrter Herr ') . $last . ',';
    elseif ($sal === 'Frau' && $last !== '') $v['kunde.briefanrede'] = t('Sehr geehrte Frau ') . $last . ',';
    elseif ($sal === 'Divers' && $v['kunde.name'] !== '') $v['kunde.briefanrede'] = t('Guten Tag ') . $person . ',';
    else $v['kunde.briefanrede'] = t('Sehr geehrte Damen und Herren,');
    return $v;
}

/** Werte der eigenen Firma (firma.*) */
function company_values(int $companyId): array
{
    $c = row('SELECT * FROM companies WHERE id = ?', [$companyId]) ?? [];
    $b = forms_branding($companyId);
    $v = [
        'firma.name' => (string)($c['name'] ?? ''), 'firma.strasse' => (string)($c['street'] ?? ''),
        'firma.plz' => (string)($c['zip'] ?? ''), 'firma.ort' => (string)($c['city'] ?? ''),
        'firma.telefon' => (string)($c['phone'] ?? ''), 'firma.email' => (string)($c['email'] ?? ''),
        'firma.ansprechpartner' => (string)($c['contact_name'] ?? ''),
    ];
    foreach (BRANDING_FIELDS as $col => [, $key]) $v['firma.' . $key] = (string)($b[$col] ?? '');
    $v['firma.absenderzeile'] = implode(' · ', array_filter([$v['firma.name'], $v['firma.strasse'], trim($v['firma.plz'] . ' ' . $v['firma.ort'])]));
    return $v;
}

/**
 * Setzt Werte in ein Formular ein. Werte sind Text und werden maskiert,
 * Zeilenumbrüche werden zu <br>. „logo“ wird zum Bild.
 * $interactive = true: Eingabefelder werden zu ausfüllbaren Elementen (Ausfüllen im Dokument),
 * sonst werden sie mit den Werten aus $values ersetzt.
 */
function fill_placeholders(string $html, array $values, string $logoTag, bool $interactive = false): string
{
    // Platzhalter-Markierungen aus dem Editor entfernen, nur der Text bleibt
    $html = preg_replace('#<span class="ph(?: ph-f)?"[^>]*>(.*?)</span>#su', '$1', $html) ?? $html;
    return preg_replace_callback(PH_REGEX, function ($m) use ($values, $logoTag, $interactive) {
        $k = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $lk = mb_strtolower($k);
        if ($lk === 'logo') return $logoTag;
        if ($lk === 'seitenumbruch') return '<span class="page-break-inline"></span>';
        if ($interactive && ($lk === 'datum' || $lk === 'datum.lang')) {
            return '<span class="fill-auto" data-auto="' . e($lk) . '">' . e($values[$lk] ?? '') . '</span>';
        }
        if (preg_match('/^(eingabe|text|zahl|datum|ankreuzen|auswahl):\s*(.+)$/iu', $k, $f)) {
            $type = strtolower($f[1]);
            $parts = array_map('trim', explode('|', $f[2]));
            $label = array_shift($parts);
            $val = (string)($values[$k] ?? '');
            if (!$interactive) {
                if ($type === 'ankreuzen') return '<span class="box">' . ($val !== '' ? '☒' : '☐') . '</span>';
                return nl2br(e($val), false);
            }
            $key = e($k);
            switch ($type) {
                case 'ankreuzen':
                    return '<button type="button" class="fill-check" data-key="' . $key . '" aria-pressed="false" aria-label="' . e($label) . '">☐</button>';
                case 'auswahl':
                    $o = '<option value=""></option>';
                    foreach ($parts as $opt) if ($opt !== '') $o .= '<option>' . e($opt) . '</option>';
                    return '<select class="fill-select" data-key="' . $key . '" aria-label="' . e($label) . '">' . $o . '</select><span class="fill-print"></span>';
                case 'datum':
                    return '<input type="date" class="fill-date" data-key="' . $key . '" aria-label="' . e($label) . '"><span class="fill-print"></span>';
                default:
                    $cls = 'fill' . ($type === 'text' ? ' fill-multi' : '') . ($type === 'zahl' ? ' fill-num' : '');
                    return '<span class="' . $cls . '" contenteditable="true" spellcheck="true" data-key="' . $key . '" data-label="' . e($label) . '"'
                        . ($type === 'zahl' ? ' inputmode="decimal"' : '') . '></span>';
            }
        }
        if (array_key_exists($k, $values)) return nl2br(e($values[$k]), false);
        if (array_key_exists($lk, $values)) return nl2br(e($values[$lk]), false);
        return '';
    }, $html) ?? $html;
}

// ---------------------------------------------------------------------------
// Briefpapier, Logo, Briefkopf/Fußzeile
// ---------------------------------------------------------------------------

function forms_branding(int $companyId): array
{
    static $cache = [];
    if (!isset($cache[$companyId])) {
        $cache[$companyId] = row('SELECT * FROM form_branding WHERE company_id = ?', [$companyId]) ?? [];
    }
    return $cache[$companyId];
}

function logo_tag(int $companyId, string $class = 'doc-logo'): string
{
    $b = forms_branding($companyId);
    if (empty($b['logo_data']) || !isset(LOGO_MIMES[$b['logo_mime']])) return '';
    return '<img class="' . e($class) . '" alt="Logo" src="data:' . e($b['logo_mime']) . ';base64,' . $b['logo_data'] . '">';
}

/** Vorgefertigte Vorlagen für Briefkopf und Fußzeile */
function layout_presets(): array
{
    $ph = fn(string $k) => '<span class="ph" contenteditable="false">{{' . $k . '}}</span>';
    return [
        'header' => [
            'klassisch' => [t('Klassisch – Logo rechts, Anschriftfeld nach DIN 5008'),
                '<p class="al-right">' . $ph('logo') . '</p>'
                . '<table class="tbl-plain"><tbody><tr>'
                . '<td class="w-50 v-top"><p class="fs-small c-muted"><u>' . $ph('firma.absenderzeile') . '</u></p><p>' . $ph('kunde.anschrift') . '</p></td>'
                . '<td class="v-top"><p class="al-right fs-small">' . $ph('firma.name') . '<br>' . $ph('firma.strasse') . '<br>' . $ph('firma.plz') . ' ' . $ph('firma.ort')
                . ('<br><br>' . e(t('Telefon')) . ' ') . $ph('firma.telefon') . '<br>' . $ph('firma.email') . '<br>' . $ph('firma.website') . '</p></td>'
                . '</tr></tbody></table>'
                . '<p class="al-right">' . $ph('firma.ort') . ', ' . $ph('datum') . '</p>'],
            'modern' => [t('Modern – Logo links, Kontaktdaten rechts, Linie'),
                '<table class="tbl-plain"><tbody><tr><td class="v-top">' . $ph('logo') . '</td>'
                . '<td class="v-top"><p class="al-right fs-small c-muted">' . $ph('firma.name') . ' · ' . $ph('firma.strasse') . ' · ' . $ph('firma.plz') . ' ' . $ph('firma.ort')
                . '<br>' . $ph('firma.telefon') . ' · ' . $ph('firma.email') . '</p></td></tr></tbody></table><hr>'
                . '<p class="fs-small c-muted"><u>' . $ph('firma.absenderzeile') . '</u></p><p>' . $ph('kunde.anschrift') . '</p>'
                . '<p class="al-right">' . $ph('datum.lang') . '</p>'],
            'zentriert' => [t('Zentriert – Logo und Firmenname mittig'),
                '<p class="al-center">' . $ph('logo') . '</p><p class="al-center fs-small c-muted">' . $ph('firma.absenderzeile')
                . ' · ' . $ph('firma.telefon') . ' · ' . $ph('firma.email') . '</p><hr><p>' . $ph('kunde.anschrift') . '</p><p class="al-right">' . $ph('datum') . '</p>'],
            'schlicht' => [t('Schlicht – nur Logo'),
                '<p class="al-right">' . $ph('logo') . '</p>'],
        ],
        'footer' => [
            'spalten' => [t('Drei Spalten – Anschrift, Kontakt, Bank'),
                '<hr><table class="tbl-plain fs-small c-muted"><tbody><tr>'
                . '<td class="v-top">' . $ph('firma.name') . '<br>' . $ph('firma.strasse') . '<br>' . $ph('firma.plz') . ' ' . $ph('firma.ort') . '<br>' . $ph('firma.geschaeftsfuehrung') . '</td>'
                . ('<td class="v-top">' . e(t('Telefon')) . ' ') . $ph('firma.telefon') . '<br>' . $ph('firma.email') . '<br>' . $ph('firma.website') . ('<br>' . e(t('USt-IdNr.')) . ' ') . $ph('firma.ustid') . '</td>'
                . '<td class="v-top">' . $ph('firma.bank') . ('<br>' . e(t('IBAN')) . ' ') . $ph('firma.iban') . ('<br>' . e(t('BIC')) . ' ') . $ph('firma.bic') . '<br>' . $ph('firma.register') . '</td>'
                . '</tr></tbody></table>'],
            'einzeilig' => [t('Einzeilig – zentriert'),
                '<hr><p class="al-center fs-small c-muted">' . $ph('firma.absenderzeile') . ' · ' . $ph('firma.telefon') . ' · ' . $ph('firma.email') . ' · ' . $ph('firma.website') . '</p>'],
            'rechtlich' => [t('Mit Pflichtangaben – zweizeilig'),
                '<hr><p class="al-center fs-small c-muted">' . $ph('firma.absenderzeile') . ' · ' . $ph('firma.telefon') . ' · ' . $ph('firma.email')
                . ('<br>' . e(t('Geschäftsführung:')) . ' ') . $ph('firma.geschaeftsfuehrung') . ' · ' . $ph('firma.register') . t(' · USt-IdNr. ') . $ph('firma.ustid') . t(' · IBAN ') . $ph('firma.iban') . '</p>'],
        ],
    ];
}

/** Legt beim ersten Aufruf Standard-Briefkopf, -Fußzeile und ein Beispielformular an */
function forms_ensure_defaults(int $companyId): void
{
    if (row('SELECT id FROM form_layouts WHERE company_id = ? LIMIT 1', [$companyId])) return;
    // Nur ein Aufruf darf anlegen: settings.name ist UNIQUE, ein zweiter gleichzeitiger Aufruf scheitert hier
    try {
        insert('settings', ['name' => 'forms_seeded_c' . $companyId, 'value' => now()]);
    } catch (PDOException $ex) {
        return;
    }
    $p = layout_presets();
    foreach (['header' => 'klassisch', 'footer' => 'spalten'] as $kind => $key) {
        insert('form_layouts', ['company_id' => $companyId, 'kind' => $kind,
            'name' => $kind === 'header' ? 'Standard-Briefkopf' : 'Standard-Fußzeile',
            'body' => $p[$kind][$key][1], 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    if (!row('SELECT id FROM forms WHERE company_id = ? LIMIT 1', [$companyId])) {
        $ph = fn(string $k) => '<span class="ph" contenteditable="false">{{' . $k . '}}</span>';
        $phf = fn(string $k) => '<span class="ph ph-f" contenteditable="false">{{' . $k . '}}</span>';
        $folder = insert('form_folders', ['company_id' => $companyId, 'parent_id' => null, 'name' => 'Briefe', 'created_at' => now()]);
        insert('forms', ['company_id' => $companyId, 'folder_id' => $folder, 'name' => 'Beispiel: Anschreiben',
            'description' => 'Einfacher Brief mit Briefkopf, Anrede aus der Kundendatenbank und einem Eingabefeld',
            'body' => '<h2>' . $phf('eingabe:Betreff') . '</h2><p>' . $ph('kunde.briefanrede') . '</p>'
                . '<p>' . $phf('text:Brieftext') . '</p><p>Mit freundlichen Grüßen</p><p><br></p>'
                . '<p>' . $ph('benutzer.name') . '<br>' . $ph('firma.name') . '</p>',
            'header_id' => 0, 'footer_id' => 0, 'created_by' => current_user()['id'] ?? null, 'updated_by' => null,
            'created_at' => now(), 'updated_at' => now()]);
    }
}

function layout_for(int $companyId, string $kind, int $choice): string
{
    if ($choice < 0) return '';
    $l = $choice > 0
        ? row('SELECT body FROM form_layouts WHERE id = ? AND company_id = ? AND kind = ?', [$choice, $companyId, $kind])
        : null;
    $l ??= row('SELECT body FROM form_layouts WHERE company_id = ? AND kind = ? ORDER BY is_default DESC, id LIMIT 1', [$companyId, $kind]);
    return (string)($l['body'] ?? '');
}

// ---------------------------------------------------------------------------
// Kundendatenbank
// ---------------------------------------------------------------------------

function customer_custom_fields(int $companyId, bool $refresh = false): array
{
    if ($refresh) unset($GLOBALS['__custfields'][$companyId]);
    return $GLOBALS['__custfields'][$companyId] ??= rows('SELECT * FROM customer_fields WHERE company_id = ? ORDER BY sort, id', [$companyId]);
}

/** Alle Felder (Standard + eigene): key => label */
function customer_all_fields(int $companyId): array
{
    $f = [];
    foreach (CUSTOMER_STD_FIELDS as $k => [$label]) $f[$k] = t($label);
    foreach (customer_custom_fields($companyId) as $c) $f[$c['field_key']] = $c['label'];
    return $f;
}

function field_key_from_label(string $label): string
{
    $s = mb_strtolower(trim($label));
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    return substr($s, 0, 50);
}

/** Kunde als Liste mit deutschen Schlüsseln (für CSV und API) */
function customer_export_row(array $c, int $companyId): array
{
    $out = [];
    foreach (CUSTOMER_STD_FIELDS as $k => [, $col]) $out[$k] = (string)($c[$col] ?? '');
    $extra = json_decode((string)($c['extra'] ?? ''), true) ?: [];
    foreach (customer_custom_fields($companyId) as $f) $out[$f['field_key']] = (string)($extra[$f['field_key']] ?? '');
    return $out;
}

/**
 * Speichert einen Kunden aus Werten mit deutschen Schlüsseln.
 * $existing = vorhandener Datensatz (Aktualisierung) oder null (neu).
 * Nur übergebene Schlüssel werden geändert. Gibt die ID zurück.
 */
function customer_save(int $companyId, array $values, ?array $existing): int
{
    $data = [];
    foreach (CUSTOMER_STD_FIELDS as $k => [, $col]) {
        if (!array_key_exists($k, $values)) continue;
        $v = trim((string)$values[$k]);
        $max = $col === 'notes' ? 5000 : ($col === 'salutation' ? 20 : 190);
        $data[$col] = mb_substr($v, 0, $max);
    }
    if (isset($data['salutation'])) {
        $s = mb_convert_case($data['salutation'], MB_CASE_TITLE);
        $data['salutation'] = in_array($s, SALUTATIONS, true) ? $s : ($s === 'Hr.' ? 'Herr' : ($s === 'Fr.' ? 'Frau' : mb_substr($data['salutation'], 0, 20)));
    }
    $extra = $existing ? (json_decode((string)($existing['extra'] ?? ''), true) ?: []) : [];
    $changedExtra = false;
    foreach (customer_custom_fields($companyId) as $f) {
        if (array_key_exists($f['field_key'], $values)) {
            $extra[$f['field_key']] = mb_substr(trim((string)$values[$f['field_key']]), 0, 2000);
            $changedExtra = true;
        }
    }
    if ($changedExtra || !$existing) $data['extra'] = json_encode($extra, JSON_UNESCAPED_UNICODE);
    $data['updated_at'] = now();
    if ($existing) {
        update('customers', (int)$existing['id'], $data);
        return (int)$existing['id'];
    }
    $data += ['company_id' => $companyId, 'created_at' => now()];
    return insert('customers', $data);
}

/** Kurzbezeichnung eines Kunden für Listen */
function customer_label(array $c): string
{
    $name = trim($c['first_name'] . ' ' . $c['last_name']);
    $parts = array_filter([$c['company_name'], $name]);
    $label = implode(' – ', $parts) ?: t('(ohne Namen)');
    return ($c['customer_no'] !== '' ? $c['customer_no'] . ' · ' : '') . $label;
}

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------

/** Liest eine CSV-Datei (Trennzeichen und Zeichensatz werden erkannt) */
function csv_read(string $raw): array
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $first = strtok($raw, "\n") ?: '';
    $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delim = (string)array_key_first($counts);
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rowsOut = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;
        $rowsOut[] = array_map(fn($x) => csv_cell_unescape((string)$x), $r);
    }
    fclose($fh);
    return $rowsOut;
}

/**
 * Schutz vor Formel-Injection beim CSV-Export: Werte, die Excel/LibreOffice als Formel
 * ausführen würden (Beginn mit = + - @ Tab CR), bekommen ein ' vorangestellt.
 */
function csv_cell_safe(mixed $v): string
{
    $v = (string)$v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

/** Gegenstück für den Import: entfernt das von csv_cell_safe() vorangestellte ', damit ein Export unverändert wieder eingelesen werden kann */
function csv_cell_unescape(string $v): string
{
    return preg_match('/^\'[=+\-@\t\r]/', $v) ? substr($v, 1) : $v;
}

function csv_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return (string)preg_replace('/[^a-z0-9]/', '', $s);
}

/** Ordnet CSV-Spaltenüberschriften den Feldern zu: Spaltenindex => Feldschlüssel */
function csv_map_headers(array $headers, int $companyId): array
{
    $aliases = [
        'nummer' => ['kundennummer', 'kundennr', 'kdnr', 'nr', 'nummer', 'customerno', 'customernumber', 'id'],
        'firma' => ['firma', 'firmenname', 'unternehmen', 'company', 'organisation'],
        'anrede' => ['anrede', 'salutation'], 'titel' => ['titel', 'title'],
        'vorname' => ['vorname', 'firstname'], 'nachname' => ['nachname', 'name', 'lastname', 'familienname'],
        'strasse' => ['strasse', 'strasseundhausnummer', 'adresse', 'anschrift', 'street', 'address'],
        'plz' => ['plz', 'postleitzahl', 'zip', 'zipcode', 'postalcode'], 'ort' => ['ort', 'stadt', 'city', 'wohnort'],
        'land' => ['land', 'country'], 'email' => ['email', 'emailadresse', 'mail'],
        'telefon' => ['telefon', 'tel', 'telefonnummer', 'phone', 'mobil'], 'notiz' => ['notiz', 'notizen', 'bemerkung', 'notes'],
    ];
    $lookup = [];
    foreach ($aliases as $k => $list) foreach ($list as $a) $lookup[$a] ??= $k;
    // Spaltenköpfe auf Deutsch und in der eingestellten Sprache erkennen (Export und Import passen so in jeder Sprache zusammen)
    foreach (CUSTOMER_STD_FIELDS as $k => [$label]) {
        foreach (LANGS as $code => $_) $lookup[csv_norm(with_lang($code, fn() => t($label)))] ??= $k;
    }
    foreach (customer_all_fields($companyId) as $k => $label) {
        $lookup[csv_norm($label)] = $k;
        $lookup[csv_norm($k)] = $k;
    }
    $map = [];
    foreach ($headers as $i => $h) {
        $n = csv_norm($h);
        if ($n !== '' && isset($lookup[$n]) && !in_array($lookup[$n], $map, true)) $map[$i] = $lookup[$n];
    }
    return $map;
}

// ---------------------------------------------------------------------------
// API-Schlüssel
// ---------------------------------------------------------------------------

/** Erzeugt einen Schlüssel; zurück: [Klartext, Präfix, Hash] */
function api_token_generate(): array
{
    $prefix = bin2hex(random_bytes(4));
    $secret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $plain = 'kp_' . $prefix . '_' . $secret;
    return [$plain, $prefix, hash('sha256', $plain)];
}

function api_token_lookup(string $plain): ?array
{
    if (!preg_match('/^kp_([0-9a-f]{8})_[A-Za-z0-9_-]{20,}$/', $plain, $m)) return null;
    $t = row('SELECT * FROM api_tokens WHERE prefix = ? AND active = 1', [$m[1]]);
    if (!$t || !hash_equals($t['token_hash'], hash('sha256', $plain))) return null;
    return $t;
}
