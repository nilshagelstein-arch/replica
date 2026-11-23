<?php
/**
 * App „Formulare“: Editor im Stil von Word (Menüband, A4-Seite, Rechentabellen)
 * und Ausfüllen direkt im Dokument.
 */

/** Einfache Linien-Symbole (20×20) für das Menüband */
function ic(string $name): string
{
    $p = [
        'undo' => '<path d="M7 5 3 9l4 4"/><path d="M3 9h9a5 5 0 0 1 0 10H9"/>',
        'redo' => '<path d="m13 5 4 4-4 4"/><path d="M17 9H8a5 5 0 0 0 0 10h3"/>',
        'clear' => '<path d="M4 4h10M9 4 6 16"/><path d="m12 12 5 5m0-5-5 5"/>',
        'list-ul' => '<circle cx="4" cy="5" r="1.2" fill="currentColor"/><circle cx="4" cy="10" r="1.2" fill="currentColor"/><circle cx="4" cy="15" r="1.2" fill="currentColor"/><path d="M8 5h9M8 10h9M8 15h9"/>',
        'list-ol' => '<path d="M3 3.5h1.5V7M3 7h3M3 12.5c0-1 2.5-1 2.5 0S3 14 3 15.5h3"/><path d="M9 5h8M9 10h8M9 15h8"/>',
        'outdent' => '<path d="M3 4h14M9 8h8M9 12h8M3 16h14"/><path d="M6 8 3 10l3 2"/>',
        'indent' => '<path d="M3 4h14M9 8h8M9 12h8M3 16h14"/><path d="m3 8 3 2-3 2"/>',
        'al-left' => '<path d="M3 4h14M3 8h9M3 12h14M3 16h9"/>',
        'al-center' => '<path d="M3 4h14M5.5 8h9M3 12h14M5.5 16h9"/>',
        'al-right' => '<path d="M3 4h14M8 8h9M3 12h14M8 16h9"/>',
        'al-justify' => '<path d="M3 4h14M3 8h14M3 12h14M3 16h14"/>',
        'spacing' => '<path d="M9 5h8M9 10h8M9 15h8"/><path d="M4 3v14M2 5l2-2 2 2M2 15l2 2 2-2"/>',
        'table' => '<rect x="3" y="4" width="14" height="12" rx="1"/><path d="M3 8h14M3 12h14M8 4v12M12.5 4v12"/>',
        'calc' => '<rect x="3" y="3" width="14" height="14" rx="1"/><path d="M3 7.5h14M8 3v14"/><path d="M10.5 11h4M12.5 9v4M10.5 15h4"/>',
        'image' => '<rect x="3" y="4" width="14" height="12" rx="1"/><circle cx="7.5" cy="8" r="1.5"/><path d="m3 15 4.5-4 3 2.5 2.5-2 4 3.5"/>',
        'logo' => '<circle cx="10" cy="10" r="6.5"/><path d="M7 12.5 10 6l3 6.5M8 10.5h4"/>',
        'link' => '<path d="M8.5 11.5a3 3 0 0 0 4.2 0l2.6-2.6a3 3 0 0 0-4.2-4.2l-1 1"/><path d="M11.5 8.5a3 3 0 0 0-4.2 0l-2.6 2.6a3 3 0 0 0 4.2 4.2l1-1"/>',
        'hr' => '<path d="M3 10h14"/><path d="M6 6h8M6 14h8" opacity=".4"/>',
        'pagebreak' => '<path d="M5 3v4h10V3M5 17v-4h10v4"/><path d="M2 10h3M7 10h2M11 10h2M15 10h3"/>',
        'sign' => '<path d="M3 15c2-4 3-9 5-9s-1 8 1 8 2-4 4-4 1 3 2 3h2"/><path d="M3 18h14"/>',
        'symbol' => '<path d="M14 6.5A5 5 0 1 0 14 13.5"/><path d="M4 9h7M4 11.5h7"/>',
        'f-text' => '<rect x="2.5" y="6" width="15" height="8" rx="1.5"/><path d="M5.5 8.5v3"/>',
        'f-multi' => '<rect x="2.5" y="3.5" width="15" height="13" rx="1.5"/><path d="M5.5 7h9M5.5 10h9M5.5 13h5"/>',
        'f-num' => '<rect x="2.5" y="6" width="15" height="8" rx="1.5"/><path d="M6 8.5h1v3M10 8.5h2v1.5h-2v1.5h2"/>',
        'f-date' => '<rect x="3" y="4.5" width="14" height="12" rx="1.5"/><path d="M3 8.5h14M7 3v3M13 3v3"/><path d="M6.5 11.5h2M11.5 11.5h2M6.5 14h2"/>',
        'f-check' => '<rect x="3.5" y="3.5" width="13" height="13" rx="2"/><path d="m6.5 10 2.5 2.5 4.5-5"/>',
        'f-select' => '<rect x="2.5" y="6" width="15" height="8" rx="1.5"/><path d="m11.5 9 2 2 2-2"/>',
        'ph' => '<path d="M7 4c-2 0-2 1.5-2 3s-1 3-2 3c1 0 2 1.5 2 3s0 3 2 3M13 4c2 0 2 1.5 2 3s1 3 2 3c-1 0-2 1.5-2 3s0 3-2 3"/>',
        'person' => '<circle cx="10" cy="7" r="3.2"/><path d="M3.5 17c1-3.5 3.5-5 6.5-5s5.5 1.5 6.5 5"/>',
        'building' => '<path d="M4 17V4h8v13M12 8h4v9M2.5 17h15"/><path d="M6.5 7h3M6.5 10h3M6.5 13h3"/>',
        'calendar' => '<rect x="3" y="4.5" width="14" height="12" rx="1.5"/><path d="M3 8.5h14M7 3v3M13 3v3"/>',
        'row-above' => '<rect x="3" y="9" width="14" height="8" rx="1"/><path d="M3 13h14M10 2v5M7.5 4.5h5"/>',
        'row-below' => '<rect x="3" y="3" width="14" height="8" rx="1"/><path d="M3 7h14M10 13v5M7.5 15.5h5"/>',
        'col-left' => '<rect x="9" y="3" width="8" height="14" rx="1"/><path d="M13 3v14M2 10h5M4.5 7.5v5"/>',
        'col-right' => '<rect x="3" y="3" width="8" height="14" rx="1"/><path d="M7 3v14M13 10h5M15.5 7.5v5"/>',
        'delete' => '<path d="M4 6h12M8 6V4h4v2M6 6l1 11h6l1-11"/>',
        'merge' => '<rect x="3" y="4" width="14" height="12" rx="1"/><path d="m6 10 2-2m-2 2 2 2m-2-2h8m0 0-2-2m2 2-2 2"/>',
        'split' => '<rect x="3" y="4" width="14" height="12" rx="1"/><path d="M10 4v12"/><path d="M5 10h3M12 10h3"/>',
        'border' => '<rect x="3" y="3" width="14" height="14"/><path d="M3 10h14M10 3v14" opacity=".45"/>',
        'fill' => '<path d="m4 10 6-6 6 6-6 6z"/><path d="M15.5 13.5s1.5 1.8 1.5 2.7a1.5 1.5 0 0 1-3 0c0-.9 1.5-2.7 1.5-2.7z"/>',
        'v-top' => '<path d="M3 3h14M10 15V6M7 9l3-3 3 3"/>',
        'v-middle' => '<path d="M3 10h14M10 3v4M8 5l2 2 2-2M10 17v-4M8 15l2-2 2 2"/>',
        'v-bottom' => '<path d="M3 17h14M10 5v9M7 11l3 3 3-3"/>',
        'sum' => '<path d="M15 4H5l5 6-5 6h10"/>',
        'fx' => '<path d="M9 4c-2 0-2 2-2 4v8c0 2-2 2-2 2M4.5 9h5"/><path d="m11 10 5 6m0-6-5 6"/>',
        'input-cell' => '<rect x="3" y="5" width="14" height="10" rx="1"/><path d="M7 8v4M6 8h2M6 12h2"/>',
        'grow' => '<rect x="3" y="3" width="14" height="9" rx="1"/><path d="M3 7.5h14M10 14v4M8 16h4"/>',
        'widths' => '<path d="M3 4v12M17 4v12M10 4v12"/><path d="m5 10 2-2v4zm10 0-2-2v4z" fill="currentColor"/>',
        'margins' => '<rect x="4" y="2.5" width="12" height="15" rx="1"/><rect x="6.5" y="5" width="7" height="10" stroke-dasharray="1.5 1.5"/>',
        'header' => '<rect x="4" y="2.5" width="12" height="15" rx="1"/><path d="M6.5 5.5h7M6.5 7.5h4" stroke-width="2"/><path d="M6.5 11h7M6.5 13.5h7" opacity=".4"/>',
        'footer' => '<rect x="4" y="2.5" width="12" height="15" rx="1"/><path d="M6.5 6h7M6.5 8.5h7" opacity=".4"/><path d="M6.5 14.5h7" stroke-width="2"/>',
        'font' => '<path d="M4 16 9 4h1l5 12M6 12h7"/>',
        'save' => '<path d="M4 3h10l3 3v11H4z"/><path d="M7 3v4h6V3M7 17v-5h7v5"/>',
        'print' => '<path d="M6 7V3h8v4M6 14H4V8h12v6h-2"/><rect x="6" y="11.5" width="8" height="5.5"/>',
        'back' => '<path d="M12 4 6 10l6 6"/>',
        'zoom-in' => '<circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4M6 8.5h5M8.5 6v5"/>',
        'zoom-out' => '<circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4M6 8.5h5"/>',
        'edit' => '<path d="m12.5 4.5 3 3L7 16H4v-3z"/>',
        'users' => '<circle cx="7.5" cy="7" r="2.8"/><path d="M2.5 16c.6-3 2.5-4.5 5-4.5s4.4 1.5 5 4.5"/><circle cx="14" cy="6.5" r="2.2"/><path d="M13.5 11c2 0 3.5 1.2 4 3.8"/>',
        'chevron' => '<path d="m6 8 4 4 4-4"/>',
        'copy' => '<rect x="7" y="7" width="10" height="10" rx="1"/><path d="M13 7V3H3v10h4"/>',
    ][$name] ?? '<circle cx="10" cy="10" r="6"/>';
    return '<svg class="ic" viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Eigenständige Vollbildseite (ohne Portal-Navigation) für Editor und Dokumentansicht */
function render_app_shell(string $title, callable $content, string $bodyClass, array $scripts): void
{
    $flashes = take_flashes();
    ?><!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e(t(cfg('app_name', 'Kundenportal'))) ?></title>
<link rel="icon" href="assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="<?= e($bodyClass) ?>">
<?php foreach ($flashes as [$type, $msg]): ?>
    <div class="toast <?= e($type) ?>" role="status"><?= e($msg) ?></div>
<?php endforeach; ?>
<?php $content(); ?>
<script src="index.php?r=i18n_js&amp;l=<?= e(current_lang()) ?>"></script>
<script src="assets/app.js"></script>
<?php foreach ($scripts as $s): ?><script src="assets/<?= e($s) ?>"></script><?php endforeach; ?>
</body>
</html>
<?php
}

function rb_btn(string $icon, string $label, array $data, bool $big = false, string $title = ''): string
{
    $attrs = '';
    foreach ($data as $k => $v) $attrs .= ' data-' . $k . '="' . e($v) . '"';
    return '<button type="button" class="rb' . ($big ? ' rb-big' : '') . '"' . $attrs . ' title="' . e($title ?: $label) . '">'
        . ic($icon) . '<span>' . e($label) . '</span></button>';
}

function rb_group(string $label, string $inner): string
{
    return '<div class="rb-group"><div class="rb-items">' . $inner . '</div><div class="rb-label">' . e($label) . '</div></div>';
}

/**
 * Der Editor. $regions: [['region'=>'header|body|footer', 'field'=>Feldname, 'html'=>…, 'locked'=>bool]]
 * $mode: 'form' (Formular mit Briefkopf/Fußzeile) oder 'layout' (nur eine Vorlage)
 */
function render_word_editor(int $cid, array $regions, array $page, string $mode, array $extra = []): void
{
    $catalog = placeholder_catalog($cid);
    $fontOpts = '';
    foreach (DOC_FONTS as $f) $fontOpts .= '<option value="' . e($f) . '">' . e($f) . '</option>';
    $sizes = ['8', '9', '10', '10,5', '11', '12', '14', '16', '18', '20', '24', '28', '36', '48'];
    $sizeOpts = '';
    foreach ($sizes as $s) $sizeOpts .= '<option value="' . $s . '">';
    $isForm = $mode === 'form';
    ?>
    <div class="word" data-word data-mode="<?= e($mode) ?>">
        <div class="rb-tabs" role="tablist">
            <button type="button" class="rb-tab active" data-tab="start" role="tab"><?= e(t('Start')) ?></button>
            <button type="button" class="rb-tab" data-tab="insert" role="tab"><?= e(t('Einfügen')) ?></button>
            <button type="button" class="rb-tab" data-tab="fields" role="tab"><?= e(t('Felder')) ?></button>
            <?php if ($isForm): ?><button type="button" class="rb-tab" data-tab="layout" role="tab"><?= e(t('Layout')) ?></button><?php endif; ?>
            <button type="button" class="rb-tab rb-tab-ctx" data-tab="table" role="tab" hidden><?= e(t('Tabelle')) ?></button>
        </div>
        <div class="ribbon">
            <!-- Start -->
            <div class="rb-panel active" data-panel="start">
                <?= rb_group(t('Bearbeiten'), rb_btn('undo', t('Rückgängig'), ['cmd' => 'undo']) . rb_btn('redo', t('Wiederholen'), ['cmd' => 'redo']) . rb_btn('clear', t('Format löschen'), ['cmd' => 'removeFormat'])) ?>
                <div class="rb-group"><div class="rb-items rb-font">
                    <div class="rb-row">
                        <select data-font aria-label="<?= e(t('Schriftart')) ?>"><?= $fontOpts ?></select>
                        <input data-size list="kp-sizes" aria-label="<?= e(t('Schriftgröße in Punkt')) ?>" value="11" inputmode="decimal">
                        <datalist id="kp-sizes"><?= $sizeOpts ?></datalist>
                        <button type="button" class="rb rb-sm" data-size-step="1" title="<?= e(t('Schrift vergrößern')) ?>"><b>A</b><sup>+</sup></button>
                        <button type="button" class="rb rb-sm" data-size-step="-1" title="<?= e(t('Schrift verkleinern')) ?>"><b>A</b><sup>−</sup></button>
                    </div>
                    <div class="rb-row">
                        <button type="button" class="rb rb-sm" data-cmd="bold" title="<?= e(t('Fett (Strg+B)')) ?>"><b>F</b></button>
                        <button type="button" class="rb rb-sm" data-cmd="italic" title="<?= e(t('Kursiv (Strg+I)')) ?>"><i>K</i></button>
                        <button type="button" class="rb rb-sm" data-cmd="underline" title="<?= e(t('Unterstrichen (Strg+U)')) ?>"><u>U</u></button>
                        <button type="button" class="rb rb-sm" data-cmd="strikeThrough" title="<?= e(t('Durchgestrichen')) ?>"><s><?= e(t('ab')) ?></s></button>
                        <button type="button" class="rb rb-sm" data-cmd="superscript" title="<?= e(t('Hochgestellt')) ?>"><?= e(t('x')) ?><sup>2</sup></button>
                        <button type="button" class="rb rb-sm" data-cmd="subscript" title="<?= e(t('Tiefgestellt')) ?>"><?= e(t('x')) ?><sub>2</sub></button>
                        <span class="rb-split"><button type="button" class="rb rb-sm" data-color-apply="fore" title="<?= e(t('Schriftfarbe')) ?>"><span class="glyph-a">A</span><span class="color-bar" data-bar="fore"></span></button><button type="button" class="rb rb-drop" data-pop="pop-fore" title="<?= e(t('Schriftfarbe wählen')) ?>"><?= ic('chevron') ?></button>
                            <div class="pop palette" id="pop-fore" data-palette="fore"></div></span>
                        <span class="rb-split"><button type="button" class="rb rb-sm" data-color-apply="back" title="<?= e(t('Texthervorhebung')) ?>"><span class="glyph-hl"><?= e(t('ab')) ?></span><span class="color-bar" data-bar="back"></span></button><button type="button" class="rb rb-drop" data-pop="pop-back" title="<?= e(t('Hervorhebungsfarbe wählen')) ?>"><?= ic('chevron') ?></button>
                            <div class="pop palette" id="pop-back" data-palette="back"></div></span>
                    </div>
                </div><div class="rb-label"><?= e(t('Schriftart')) ?></div></div>
                <div class="rb-group"><div class="rb-items rb-font">
                    <div class="rb-row">
                        <button type="button" class="rb rb-sm" data-cmd="insertUnorderedList" title="<?= e(t('Aufzählung')) ?>"><?= ic('list-ul') ?></button>
                        <button type="button" class="rb rb-sm" data-cmd="insertOrderedList" title="<?= e(t('Nummerierung')) ?>"><?= ic('list-ol') ?></button>
                        <button type="button" class="rb rb-sm" data-indent="-1" title="<?= e(t('Einzug verkleinern')) ?>"><?= ic('outdent') ?></button>
                        <button type="button" class="rb rb-sm" data-indent="1" title="<?= e(t('Einzug vergrößern')) ?>"><?= ic('indent') ?></button>
                    </div>
                    <div class="rb-row">
                        <button type="button" class="rb rb-sm" data-align="left" title="<?= e(t('Linksbündig (Strg+L)')) ?>"><?= ic('al-left') ?></button>
                        <button type="button" class="rb rb-sm" data-align="center" title="<?= e(t('Zentriert (Strg+E)')) ?>"><?= ic('al-center') ?></button>
                        <button type="button" class="rb rb-sm" data-align="right" title="<?= e(t('Rechtsbündig (Strg+R)')) ?>"><?= ic('al-right') ?></button>
                        <button type="button" class="rb rb-sm" data-align="justify" title="<?= e(t('Blocksatz (Strg+J)')) ?>"><?= ic('al-justify') ?></button>
                        <span class="rb-split"><button type="button" class="rb rb-sm" data-pop="pop-spacing" title="<?= e(t('Zeilen- und Absatzabstand')) ?>"><?= ic('spacing') ?><?= ic('chevron') ?></button>
                            <div class="pop menu" id="pop-spacing">
                                <?php foreach (['1' => '1,0', '1.15' => '1,15', '1.4' => '1,4', '1.5' => '1,5', '2' => '2,0', '2.5' => '2,5'] as $v => $l): ?>
                                    <button type="button" data-line-height="<?= $v ?>"><?= e(t('Zeilenabstand')) ?> <?= $l ?></button>
                                <?php endforeach; ?>
                                <hr><button type="button" data-para-space="6pt"><?= e(t('Abstand nach Absatz: 6 pt')) ?></button>
                                <button type="button" data-para-space="12pt"><?= e(t('Abstand nach Absatz: 12 pt')) ?></button>
                                <button type="button" data-para-space="0"><?= e(t('Kein Abstand nach Absatz')) ?></button>
                            </div></span>
                    </div>
                </div><div class="rb-label"><?= e(t('Absatz')) ?></div></div>
                <div class="rb-group"><div class="rb-items rb-styles">
                    <button type="button" class="st-card" data-pstyle="p"><span class="st-normal"><?= e(t('AaBbCc')) ?></span><small><?= e(t('Standard')) ?></small></button>
                    <button type="button" class="st-card" data-pstyle="title"><span class="st-title"><?= e(t('AaBb')) ?></span><small><?= e(t('Titel')) ?></small></button>
                    <button type="button" class="st-card" data-pstyle="h1"><span class="st-h1"><?= e(t('AaBbC')) ?></span><small><?= e(t('Überschrift 1')) ?></small></button>
                    <button type="button" class="st-card" data-pstyle="h2"><span class="st-h2"><?= e(t('AaBbCc')) ?></span><small><?= e(t('Überschrift 2')) ?></small></button>
                    <button type="button" class="st-card" data-pstyle="h3"><span class="st-h3"><?= e(t('AaBbCc')) ?></span><small><?= e(t('Überschrift 3')) ?></small></button>
                    <button type="button" class="st-card" data-pstyle="quote"><span class="st-quote"><?= e(t('AaBbCc')) ?></span><small><?= e(t('Zitat')) ?></small></button>
                    <button type="button" class="st-card" data-pstyle="small"><span class="st-small"><?= e(t('AaBbCcDd')) ?></span><small><?= e(t('Klein')) ?></small></button>
                </div><div class="rb-label"><?= e(t('Formatvorlagen')) ?></div></div>
            </div>

            <!-- Einfügen -->
            <div class="rb-panel" data-panel="insert">
                <div class="rb-group"><div class="rb-items">
                    <span class="rb-split"><button type="button" class="rb rb-big" data-pop="pop-table" title="<?= e(t('Tabelle einfügen')) ?>"><?= ic('table') ?><span><?= e(t('Tabelle')) ?></span></button>
                        <div class="pop table-picker" id="pop-table"><div class="tp-grid" data-table-grid></div><div class="tp-label" data-table-label><?= e(t('Tabelle einfügen')) ?></div></div></span>
                    <span class="rb-split"><button type="button" class="rb rb-big" data-pop="pop-calc" title="<?= e(t('Tabelle mit Formeln wie in Excel')) ?>"><?= ic('calc') ?><span><?= e(t('Rechentabelle')) ?></span></button>
                        <div class="pop menu" id="pop-calc">
                            <button type="button" data-calc-preset="positions"><strong><?= e(t('Positionen mit MwSt.')) ?></strong><br><small><?= e(t('Pos., Leistung, Menge, Einzelpreis, Gesamt, Netto, MwSt. 19 %, Brutto')) ?></small></button>
                            <button type="button" data-calc-preset="hours"><strong><?= e(t('Stundenzettel')) ?></strong><br><small><?= e(t('Datum, Tätigkeit, Stunden, Summe')) ?></small></button>
                            <button type="button" data-calc-preset="empty"><strong><?= e(t('Leeres Rechenblatt 5 × 4')) ?></strong><br><small><?= e(t('mit Spalten A–E wie in Excel')) ?></small></button>
                        </div></span>
                </div><div class="rb-label"><?= e(t('Tabellen')) ?></div></div>
                <?= rb_group(t('Bilder'), rb_btn('image', t('Bild'), ['insert' => 'image'], true) . rb_btn('logo', t('Logo'), ['ph' => 'logo'], true, t('Logo der Firma (unter Einstellungen hinterlegt)'))) ?>
                <?= rb_group(t('Seiten'), rb_btn('pagebreak', t('Seitenumbruch'), ['insert' => 'pagebreak'], true) . rb_btn('hr', t('Linie'), ['insert' => 'hr'], true)) ?>
                <div class="rb-group"><div class="rb-items">
                    <?= rb_btn('link', t('Link'), ['insert' => 'link'], true) ?>
                    <?= rb_btn('sign', t('Unterschrift'), ['insert' => 'signature'], true, t('Ort, Datum und Unterschriftslinie')) ?>
                    <?= rb_btn('calendar', t('Datum'), ['ph' => 'datum'], true, t('Aktuelles Datum')) ?>
                    <span class="rb-split"><button type="button" class="rb rb-big" data-pop="pop-sym" title="<?= e(t('Symbol einfügen')) ?>"><?= ic('symbol') ?><span><?= e(t('Symbol')) ?></span></button>
                        <div class="pop symbols" id="pop-sym"><?php foreach (['☐', '☒', '✓', '✗', '€', '§', '•', '–', '—', '→', '©', '®', '™', '°', '±', '×', '÷', '½', '¼', '²', '³', '„', '“', '‚', '‘', '»', '«', '…', '☎', '✉'] as $sym): ?><button type="button" data-symbol="<?= e($sym) ?>"><?= e($sym) ?></button><?php endforeach; ?></div></span>
                </div><div class="rb-label"><?= e(t('Text')) ?></div></div>
            </div>

            <!-- Felder -->
            <div class="rb-panel" data-panel="fields">
                <div class="rb-group"><div class="rb-items">
                    <?php $phGroups = ['Kunde' => ['person', t('Kunde')], 'Eigene Firma' => ['building', t('Eigene Firma')], 'Allgemein' => ['ph', t('Allgemein')]];
                    // Platzhalter des Kündigungsschreibens nur, wenn die Firma „Verträge & Laufzeiten“ nutzt
                    if (!empty($GLOBALS['forms_cid']) && company_has_app((int)$GLOBALS['forms_cid'], 'vertraege')) $phGroups['Vertrag (Kündigung)'] = ['ph', t('Vertrag')];
                    foreach ($phGroups as $group => [$icon, $label]): $pid = 'pop-ph-' . md5($group); ?>
                        <span class="rb-split"><button type="button" class="rb rb-big" data-pop="<?= $pid ?>"><?= ic($icon) ?><span><?= e($label) ?> ▾</span></button>
                            <div class="pop menu ph-menu" id="<?= $pid ?>">
                                <?php foreach ($catalog[$group] as $k => $l): ?><button type="button" data-ph="<?= e($k) ?>"><?= e($l) ?> <code>{{<?= e($k) ?>}}</code></button><?php endforeach; ?>
                            </div></span>
                    <?php endforeach; ?>
                </div><div class="rb-label"><?= e(t('Daten einsetzen')) ?></div></div>
                <?php if ($isForm): ?>
                <?= rb_group(t('Ausfüllfelder'),
                    rb_btn('f-text', t('Eingabefeld'), ['field' => 'eingabe'], true, t('Einzeiliges Feld zum Ausfüllen'))
                    . rb_btn('f-multi', t('Textfeld'), ['field' => 'text'], true, t('Mehrzeiliges Feld zum Ausfüllen'))
                    . rb_btn('f-num', t('Zahl'), ['field' => 'zahl'], true, t('Zahlenfeld'))
                    . rb_btn('f-date', t('Datum'), ['field' => 'datum'], true, t('Datumsfeld mit Kalender'))
                    . rb_btn('f-check', t('Ankreuzfeld'), ['field' => 'ankreuzen'], true, t('Kästchen zum Ankreuzen'))
                    . rb_btn('f-select', t('Auswahlliste'), ['field' => 'auswahl'], true, t('Liste mit festen Antworten'))) ?>
                <?php endif; ?>
                <div class="rb-group rb-hint"><div class="rb-items"><p><?= e(t('Grüne Felder werden beim Ausfüllen mit Daten aus der Kundendatenbank ersetzt.')) ?><?= $isForm ? ('<br>' . e(t('Gelbe Felder füllt man direkt im Dokument aus.'))) : '' ?></p></div><div class="rb-label"><?= e(t('Hinweis')) ?></div></div>
            </div>

            <!-- Layout -->
            <div class="rb-panel" data-panel="layout">
                <div class="rb-group"><div class="rb-items">
                    <span class="rb-split"><button type="button" class="rb rb-big" data-pop="pop-margins"><?= ic('margins') ?><span><?= e(t('Seitenränder ▾')) ?></span></button>
                        <div class="pop menu" id="pop-margins">
                            <button type="button" data-margins="20,20,15,25"><strong><?= e(t('Normal (DIN 5008)')) ?></strong><br><small><?= e(t('oben 20 · rechts 20 · unten 15 · links 25 mm')) ?></small></button>
                            <button type="button" data-margins="12.7,12.7,12.7,12.7"><strong><?= e(t('Schmal')) ?></strong><br><small><?= e(t('alle Seiten 12,7 mm')) ?></small></button>
                            <button type="button" data-margins="25,25,20,25"><strong><?= e(t('Mittel')) ?></strong><br><small><?= e(t('oben 25 · rechts 25 · unten 20 · links 25 mm')) ?></small></button>
                            <button type="button" data-margins="25,30,25,30"><strong><?= e(t('Breit')) ?></strong><br><small><?= e(t('oben 25 · rechts 30 · unten 25 · links 30 mm')) ?></small></button>
                            <hr><div class="margin-inputs"><label><?= e(t('oben')) ?> <input type="number" step="0.5" min="0" max="60" data-margin="0"></label><label><?= e(t('rechts')) ?> <input type="number" step="0.5" min="0" max="60" data-margin="1"></label>
                                <label><?= e(t('unten')) ?> <input type="number" step="0.5" min="0" max="60" data-margin="2"></label><label><?= e(t('links')) ?> <input type="number" step="0.5" min="0" max="60" data-margin="3"></label></div>
                        </div></span>
                </div><div class="rb-label"><?= e(t('Seite einrichten')) ?></div></div>
                <div class="rb-group"><div class="rb-items rb-font">
                    <div class="rb-row"><label class="rb-lbl"><?= e(t('Schrift')) ?></label><select data-page-font aria-label="<?= e(t('Grundschrift')) ?>"><?= $fontOpts ?></select></div>
                    <div class="rb-row"><label class="rb-lbl"><?= e(t('Größe')) ?></label><input data-page-size list="kp-sizes" aria-label="<?= e(t('Grundschriftgröße')) ?>" inputmode="decimal" class="rb-num">
                        <label class="rb-lbl"><?= e(t('Zeilen')) ?></label><select data-page-ls aria-label="<?= e(t('Zeilenabstand')) ?>"><?php foreach (['1' => '1,0', '1.15' => '1,15', '1.4' => '1,4', '1.5' => '1,5', '2' => '2,0'] as $v => $l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?></select></div>
                </div><div class="rb-label"><?= e(t('Grundschrift des Dokuments')) ?></div></div>
                <?php if ($isForm): ?>
                <div class="rb-group"><div class="rb-items rb-font">
                    <div class="rb-row"><?= ic('header') ?><select data-layout-select="header" aria-label="<?= e(t('Briefkopf')) ?>"><?php foreach ($extra['header_options'] as $id => $l): ?><option value="<?= (int)$id ?>"><?= e($l) ?></option><?php endforeach; ?></select>
                        <?php if (!empty($extra['can_manage'])): ?><button type="button" class="rb rb-sm" data-hf-edit="header" title="<?= e(t('Briefkopf direkt auf der Seite bearbeiten')) ?>"><?= ic('edit') ?></button><?php endif; ?></div>
                    <div class="rb-row"><?= ic('footer') ?><select data-layout-select="footer" aria-label="<?= e(t('Fußzeile')) ?>"><?php foreach ($extra['footer_options'] as $id => $l): ?><option value="<?= (int)$id ?>"><?= e($l) ?></option><?php endforeach; ?></select>
                        <?php if (!empty($extra['can_manage'])): ?><button type="button" class="rb rb-sm" data-hf-edit="footer" title="<?= e(t('Fußzeile direkt auf der Seite bearbeiten')) ?>"><?= ic('edit') ?></button><?php endif; ?></div>
                </div><div class="rb-label"><?= e(t('Briefkopf & Fußzeile')) ?></div></div>
                <div class="rb-group"><div class="rb-items rb-font">
                    <div class="rb-row"><label class="rb-lbl"><?= e(t('Ordner')) ?></label><select form="word-form" name="folder_id" aria-label="<?= e(t('Ordner')) ?>"><option value="0"><?= e(t('(ohne Ordner)')) ?></option>
                        <?php foreach (folder_options($cid) as $fid => $label): ?><option value="<?= $fid ?>" <?= (int)$extra['folder_id'] === $fid ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div class="rb-row"><label class="rb-lbl"><?= e(t('Info')) ?></label><input form="word-form" name="description" value="<?= e($extra['description']) ?>" maxlength="500" placeholder="<?= e(t('Kurzbeschreibung')) ?>" class="rb-wide"></div>
                </div><div class="rb-label"><?= e(t('Eigenschaften')) ?></div></div>
                <?php endif; ?>
            </div>

            <!-- Tabellentools (kontextabhängig) -->
            <div class="rb-panel" data-panel="table">
                <?= rb_group(t('Zeilen & Spalten'),
                    rb_btn('row-above', t('Oberhalb'), ['tbl' => 'row-above'], true, t('Zeile oberhalb einfügen'))
                    . rb_btn('row-below', t('Unterhalb'), ['tbl' => 'row-below'], true, t('Zeile unterhalb einfügen'))
                    . rb_btn('col-left', t('Links'), ['tbl' => 'col-left'], true, t('Spalte links einfügen'))
                    . rb_btn('col-right', t('Rechts'), ['tbl' => 'col-right'], true, t('Spalte rechts einfügen'))
                    . '<span class="rb-split"><button type="button" class="rb rb-big" data-pop="pop-tdel">' . ic('delete') . ('<span>' . e(t('Löschen ▾')) . '</span></button><div class="pop menu" id="pop-tdel">')
                    . ('<button type="button" data-tbl="del-row">' . e(t('Zeilen löschen')) . '</button><button type="button" data-tbl="del-col">' . e(t('Spalten löschen')) . '</button><button type="button" data-tbl="del-table">' . e(t('Tabelle löschen')) . '</button></div></span>')) ?>
                <?= rb_group(t('Zusammenführen'), rb_btn('merge', t('Verbinden'), ['tbl' => 'merge'], true, t('Markierte Zellen verbinden')) . rb_btn('split', t('Teilen'), ['tbl' => 'split'], true, t('Verbundene Zelle wieder teilen'))) ?>
                <div class="rb-group"><div class="rb-items">
                    <span class="rb-split"><button type="button" class="rb rb-big" data-pop="pop-borders"><?= ic('border') ?><span><?= e(t('Rahmen ▾')) ?></span></button>
                        <div class="pop menu" id="pop-borders"><button type="button" data-tbl="b-all"><?= e(t('Alle Rahmenlinien')) ?></button><button type="button" data-tbl="b-outer"><?= e(t('Rahmenlinien außen')) ?></button>
                            <button type="button" data-tbl="b-bottom"><?= e(t('Rahmenlinie unten')) ?></button><button type="button" data-tbl="b-top"><?= e(t('Rahmenlinie oben')) ?></button><button type="button" data-tbl="b-none"><?= e(t('Kein Rahmen')) ?></button></div></span>
                    <span class="rb-split"><button type="button" class="rb rb-big" data-pop="pop-shade"><?= ic('fill') ?><span><?= e(t('Schattierung ▾')) ?></span></button>
                        <div class="pop palette" id="pop-shade" data-palette="cell"></div></span>
                </div><div class="rb-label"><?= e(t('Rahmen')) ?></div></div>
                <div class="rb-group"><div class="rb-items rb-font">
                    <div class="rb-row"><button type="button" class="rb rb-sm" data-tbl="v-top" title="<?= e(t('Oben ausrichten')) ?>"><?= ic('v-top') ?></button><button type="button" class="rb rb-sm" data-tbl="v-middle" title="<?= e(t('Vertikal zentrieren')) ?>"><?= ic('v-middle') ?></button><button type="button" class="rb rb-sm" data-tbl="v-bottom" title="<?= e(t('Unten ausrichten')) ?>"><?= ic('v-bottom') ?></button></div>
                    <div class="rb-row"><button type="button" class="rb rb-sm" data-tbl="h-left" title="<?= e(t('Links')) ?>"><?= ic('al-left') ?></button><button type="button" class="rb rb-sm" data-tbl="h-center" title="<?= e(t('Zentriert')) ?>"><?= ic('al-center') ?></button><button type="button" class="rb rb-sm" data-tbl="h-right" title="<?= e(t('Rechts')) ?>"><?= ic('al-right') ?></button></div>
                </div><div class="rb-label"><?= e(t('Ausrichtung')) ?></div></div>
                <div class="rb-group"><div class="rb-items">
                    <?= rb_btn('sum', t('AutoSumme'), ['tbl' => 'autosum'], true, t('Summe der Zahlen darüber (=SUMME)')) ?>
                    <?= rb_btn('fx', t('Formel'), ['tbl' => 'formula'], true, t('Formel in der Formelleiste eingeben')) ?>
                    <div class="rb-stack">
                        <select data-number-format aria-label="<?= e(t('Zahlenformat')) ?>"><option value=""><?= e(t('Standard')) ?></option><option value="zahl"><?= e(t('Zahl (1.234,50)')) ?></option><option value="ganz"><?= e(t('Ganzzahl')) ?></option><option value="euro"><?= e(t('Währung (€)')) ?></option><option value="prozent"><?= e(t('Prozent')) ?></option></select>
                        <?php if ($isForm): ?><button type="button" class="rb rb-line" data-tbl="input" title="<?= e(t('Zelle beim Ausfüllen beschreibbar machen')) ?>"><?= ic('input-cell') ?><span><?= e(t('Eingabezelle')) ?></span></button>
                        <button type="button" class="rb rb-line" data-tbl="grow" title="<?= e(t('Beim Ausfüllen dürfen Zeilen ergänzt werden')) ?>"><?= ic('grow') ?><span><?= e(t('Zeilen ergänzbar')) ?></span></button><?php endif; ?>
                    </div>
                </div><div class="rb-label"><?= e(t('Rechnen')) ?></div></div>
                <?= rb_group(t('Größe'), rb_btn('widths', t('Gleich breit'), ['tbl' => 'equal'], true, t('Spalten gleich breit machen'))) ?>
            </div>
        </div>

        <div class="formula-bar">
            <span class="fb-name" data-fb-name>–</span>
            <span class="fb-fx"><?= ic('fx') ?></span>
            <input data-fb-input placeholder="<?= e(t('Zelle in einer Tabelle wählen, dann Wert oder Formel eingeben, z. B. =SUMME(E2:E4)')) ?>" disabled aria-label="<?= e(t('Formelleiste')) ?>">
        </div>

        <div class="canvas" data-canvas>
            <div class="ruler" data-ruler></div>
            <div class="page" data-page>
                <?php foreach ($regions as $r): ?>
                    <?php if ($r['region'] !== 'body'): ?>
                        <div class="hf-tag hf-tag-<?= e($r['region']) ?>"><?= $r['region'] === 'header' ? t('Briefkopf') : t('Fußzeile') ?><span data-hf-name="<?= e($r['region']) ?>"></span> <?= e(t('· Doppelklick zum Bearbeiten')) ?></div>
                    <?php endif; ?>
                    <div class="region region-<?= e($r['region']) ?> doc-content<?= !empty($r['locked']) ? ' locked' : '' ?>" data-region="<?= e($r['region']) ?>"
                         contenteditable="<?= empty($r['locked']) ? 'true' : 'false' ?>" spellcheck="true" lang="<?= e(current_lang()) ?>"><?= $r['html'] ?: '<p><br></p>' ?></div>
                <?php endforeach; ?>
                <div class="cell-heads" data-cell-heads hidden></div>
            </div>
        </div>

        <div class="statusbar">
            <span data-st-pages><?= e(t('Seite 1 von 1')) ?></span>
            <span data-st-words><?= e(t('0 Wörter')) ?></span>
            <span class="st-hint" data-st-hint></span>
            <span class="st-zoom">
                <button type="button" class="rb rb-sm" data-zoom="-10" title="<?= e(t('Verkleinern')) ?>"><?= ic('zoom-out') ?></button>
                <input type="range" min="50" max="200" step="10" value="100" data-zoom-range aria-label="<?= e(t('Zoom')) ?>">
                <button type="button" class="rb rb-sm" data-zoom="10" title="<?= e(t('Vergrößern')) ?>"><?= ic('zoom-in') ?></button>
                <span data-zoom-label>100 %</span>
            </span>
        </div>
        <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" data-image-input hidden>
        <script type="application/json" data-word-config><?= json_encode([
            'page' => $page,
            'layouts' => $extra['layouts'] ?? new stdClass(),
            'defaults' => $extra['defaults'] ?? new stdClass(),
            'canManage' => !empty($extra['can_manage']),
            'header' => (int)($extra['header_id'] ?? 0),
            'footer' => (int)($extra['footer_id'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// Formular bearbeiten (Vollbild)
// ---------------------------------------------------------------------------

function page_form_edit(): void
{
    $cid = forms_company_id();
    $u = current_user();
    $id = query_int('id');
    $form = $id ? load_form($cid, $id) : null;
    $canManage = forms_can_manage();
    $data = $form ?? ['name' => '', 'description' => '', 'body' => '', 'header_id' => 0, 'footer_id' => 0,
        'folder_id' => query_int('folder') ?: null, 'page_settings' => null];

    if (is_post()) {
        require_post_csrf();
        $data['name'] = mb_substr(post('name'), 0, 190) ?: t('Neues Formular');
        $data['description'] = mb_substr(post('description'), 0, 500);
        $data['body'] = sanitize_html((string)($_POST['body'] ?? ''));
        $folder = (int)post('folder_id', 0);
        $data['folder_id'] = $folder && row('SELECT id FROM form_folders WHERE id = ? AND company_id = ?', [$folder, $cid]) ? $folder : null;
        $data['page_settings'] = json_encode(page_settings((string)post('page')));
        $notes = [];
        foreach (['header', 'footer'] as $kind) {
            $v = (int)post($kind . '_id', 0);
            if ($v > 0 && !row('SELECT id FROM form_layouts WHERE id = ? AND company_id = ? AND kind = ?', [$v, $cid, $kind])) $v = 0;
            $v = max(-1, $v);
            // Briefkopf/Fußzeile wurde auf der Seite bearbeitet
            if ($canManage && $v >= 0 && post($kind . '_dirty') === '1') {
                $html = sanitize_html((string)($_POST[$kind . '_html'] ?? ''));
                $newName = mb_substr(post($kind . '_new_name'), 0, 190);
                if ($newName !== '') {
                    $v = insert('form_layouts', ['company_id' => $cid, 'kind' => $kind, 'name' => $newName, 'body' => $html, 'is_default' => 0,
                        'created_at' => now(), 'updated_at' => now()]);
                    $notes[] = ($kind === 'header' ? t('Neuer Briefkopf') : t('Neue Fußzeile')) . t(' „{x}“ wurde angelegt.', ['x' => $newName]);
                } else {
                    $target = $v > 0 ? row('SELECT * FROM form_layouts WHERE id = ?', [$v])
                        : row('SELECT * FROM form_layouts WHERE company_id = ? AND kind = ? ORDER BY is_default DESC, id LIMIT 1', [$cid, $kind]);
                    if ($target && $target['body'] !== $html) {
                        update('form_layouts', (int)$target['id'], ['body' => $html, 'updated_at' => now()]);
                        $notes[] = ($kind === 'header' ? t('Briefkopf') : t('Fußzeile')) . t(' „{x}“ wurde aktualisiert (gilt für alle Formulare, die ihn verwenden).', ['x' => $target['name']]);
                    }
                }
            }
            $data[$kind . '_id'] = $v;
        }
        $save = ['name' => $data['name'], 'description' => $data['description'], 'body' => $data['body'],
            'folder_id' => $data['folder_id'], 'header_id' => $data['header_id'], 'footer_id' => $data['footer_id'],
            'page_settings' => $data['page_settings'], 'updated_by' => $u['id'], 'updated_at' => now()];
        if ($form) {
            update('forms', (int)$form['id'], $save);
            audit('form_updated', $data['name'], $cid);
        } else {
            $id = insert('forms', $save + ['company_id' => $cid, 'created_by' => $u['id'], 'created_at' => now()]);
            audit('form_created', $data['name'], $cid);
        }
        flash('success', trim('Gespeichert. ' . implode(' ', $notes)));
        if (post('next') === 'use') fredirect('form_use', ['id' => $id]);
        fredirect('form_edit', ['id' => $id]);
    }

    $layouts = ['header' => [], 'footer' => []];
    $defaults = [];
    foreach (rows('SELECT id, kind, name, body, is_default FROM form_layouts WHERE company_id = ? ORDER BY name', [$cid]) as $l) {
        $layouts[$l['kind']][(int)$l['id']] = ['name' => $l['name'], 'body' => (string)$l['body']];
        if ((int)$l['is_default'] && !isset($defaults[$l['kind']])) $defaults[$l['kind']] = (int)$l['id'];
    }
    foreach (['header', 'footer'] as $k) if (!isset($defaults[$k]) && $layouts[$k]) $defaults[$k] = (int)array_key_first($layouts[$k]);
    $resolve = function (string $kind, int $choice) use ($layouts, $defaults) {
        if ($choice < 0) return '';
        $lid = $choice > 0 && isset($layouts[$kind][$choice]) ? $choice : ($defaults[$kind] ?? 0);
        return $layouts[$kind][$lid]['body'] ?? '';
    };
    $page = page_settings($data['page_settings'] ?? null);
    $title = $form ? $data['name'] : t('Neues Formular');

    render_app_shell($title, function () use ($cid, $form, $data, $layouts, $defaults, $resolve, $page, $canManage) {
        $back = furl('forms', $data['folder_id'] ? ['folder' => (int)$data['folder_id']] : []); ?>
        <form method="post" id="word-form" class="word-form" action="<?= e(furl('form_edit', $form ? ['id' => $form['id']] : ['folder' => (int)$data['folder_id']])) ?>" data-editor-form>
            <?= csrf_field() ?>
            <textarea name="body" hidden></textarea><textarea name="header_html" hidden></textarea><textarea name="footer_html" hidden></textarea>
            <input type="hidden" name="header_id" value="<?= (int)$data['header_id'] ?>"><input type="hidden" name="footer_id" value="<?= (int)$data['footer_id'] ?>">
            <input type="hidden" name="header_dirty" value="0"><input type="hidden" name="footer_dirty" value="0">
            <input type="hidden" name="header_new_name" value=""><input type="hidden" name="footer_new_name" value="">
            <input type="hidden" name="page" value="">
        </form>
        <header class="word-title">
            <a class="wt-back" href="<?= e($back) ?>" title="<?= e(t('Zurück zu den Formularen')) ?>"><?= ic('back') ?></a>
            <span class="wt-app">📝</span>
            <input class="wt-name" form="word-form" name="name" value="<?= e($data['name']) ?>" placeholder="<?= e(t('Name des Formulars')) ?>" maxlength="190" required aria-label="<?= e(t('Name des Formulars')) ?>">
            <span class="wt-state" data-save-state></span>
            <span class="wt-actions">
                <?php if ($form): ?><a class="wt-btn" href="<?= e(furl('form_use', ['id' => $form['id'], 'preview' => 1])) ?>" target="_blank" rel="noopener"><?= ic('print') ?> <?= e(t('Vorschau')) ?></a><?php endif; ?>
                <button class="wt-btn" form="word-form" name="next" value="use"><?= ic('edit') ?> <?= e(t('Speichern & ausfüllen')) ?></button>
                <button class="wt-btn primary" form="word-form" title="<?= e(t('Speichern (Strg+S)')) ?>"><?= ic('save') ?> <?= e(t('Speichern')) ?></button>
            </span>
        </header>
        <?php
        $hOpts = [0 => t('Standard der Firma'), -1 => t('Ohne Briefkopf')];
        foreach ($layouts['header'] as $lid => $l) $hOpts[$lid] = t($l['name']);
        $fOpts = [0 => t('Standard der Firma'), -1 => t('Ohne Fußzeile')];
        foreach ($layouts['footer'] as $lid => $l) $fOpts[$lid] = t($l['name']);
        render_word_editor($cid, [
            ['region' => 'header', 'html' => $resolve('header', (int)$data['header_id']), 'locked' => true],
            ['region' => 'body', 'html' => (string)$data['body']],
            ['region' => 'footer', 'html' => $resolve('footer', (int)$data['footer_id']), 'locked' => true],
        ], $page, 'form', [
            'layouts' => $layouts, 'defaults' => $defaults, 'can_manage' => $canManage,
            'header_id' => (int)$data['header_id'], 'footer_id' => (int)$data['footer_id'],
            'header_options' => $hOpts, 'footer_options' => $fOpts,
            'folder_id' => (int)$data['folder_id'], 'description' => (string)$data['description'],
        ]);
    }, 'word-app', ['calc.js', 'forms.js']);
}

// ---------------------------------------------------------------------------
// Ausfüllen direkt im Dokument
// ---------------------------------------------------------------------------

/** Beispielkunde für Vorschauen */
function sample_customer(int $cid): array
{
    return row('SELECT * FROM customers WHERE company_id = ? ORDER BY id LIMIT 1', [$cid])
        ?? ['customer_no' => '10001', 'company_name' => t('Musterfirma GmbH'), 'salutation' => 'Frau', 'title' => '',
            'first_name' => t('Erika'), 'last_name' => t('Mustermann'), 'street' => t('Musterstraße 1'), 'zip' => '26122',
            'city' => t('Oldenburg'), 'country' => t('Deutschland'), 'email' => 'erika@example.de', 'phone' => '0441 123456',
            'notes' => '', 'extra' => '{}'];
}

/** A4-Blätter eines Formulars, je Kunde ein Blatt */
function render_sheets(int $cid, array $form, array $customers, bool $interactive, array $extra = []): void
{
    $u = current_user();
    $header = layout_for($cid, 'header', (int)$form['header_id']);
    $footer = layout_for($cid, 'footer', (int)$form['footer_id']);
    $logo = logo_tag($cid);
    $page = page_settings($form['page_settings'] ?? null);
    $base = company_values($cid) + [
        'datum' => date(lang_date_format()), 'datum.lang' => german_date_long(time()),
        'benutzer.name' => (string)$u['name'], 'benutzer.email' => (string)$u['email'],
    ];
    foreach ($customers as $i => $c) {
        $values = $extra + $base + customer_values($c, $cid); ?>
        <section class="sheet" style="<?= e(page_style($page)) ?>" data-sheet="<?= $i ?>">
            <?php if ($header !== ''): ?><header class="doc-header doc-content"><?= fill_placeholders($header, $values, $logo, $interactive) ?></header><?php endif; ?>
            <div class="doc-body doc-content"><?= fill_placeholders((string)$form['body'], $values, $logo, $interactive) ?></div>
            <?php if ($footer !== ''): ?><footer class="doc-footer doc-content"><?= fill_placeholders($footer, $values, $logo, $interactive) ?></footer><?php endif; ?>
        </section>
    <?php }
}

function page_form_use(): void
{
    $cid = forms_company_id();
    $form = load_form($cid, query_int('id'));
    $preview = isset($_GET['preview']);
    $full = (string)$form['body'] . layout_for($cid, 'header', (int)$form['header_id']) . layout_for($cid, 'footer', (int)$form['footer_id']);
    $needsCustomer = uses_customer($full);
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['kunden'] ?? ''))))));
    $ids = array_slice($ids, 0, 500);
    $selected = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $selected = rows("SELECT * FROM customers WHERE company_id = ? AND id IN ($in) ORDER BY company_name, last_name, first_name", array_merge([$cid], $ids));
    }
    $sheets = $selected ?: [$preview ? sample_customer($cid) : null];
    $search = trim((string)($_GET['q'] ?? ''));
    $where = 'company_id = ?';
    $params = [$cid];
    if ($search !== '') {
        $where .= ' AND (customer_no LIKE ? OR company_name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR city LIKE ? OR email LIKE ?)';
        $params = array_merge($params, array_fill(0, 6, '%' . $search . '%'));
    }
    $total = $needsCustomer ? (int)row("SELECT COUNT(*) AS n FROM customers WHERE $where", $params)['n'] : 0;
    $list = $needsCustomer ? rows("SELECT * FROM customers WHERE $where ORDER BY company_name, last_name, first_name LIMIT 300", $params) : [];
    if ($selected) audit('form_opened', $form['name'] . ' (' . count($selected) . ' Kunde(n))', $cid);

    render_app_shell($form['name'], function () use ($cid, $form, $preview, $needsCustomer, $selected, $sheets, $search, $total, $list, $ids) { ?>
        <header class="word-title fill-title">
            <a class="wt-back" href="<?= e(furl('forms', $form['folder_id'] ? ['folder' => (int)$form['folder_id']] : [])) ?>" title="<?= e(t('Zurück zu den Formularen')) ?>"><?= ic('back') ?></a>
            <span class="wt-app">📝</span>
            <span class="wt-doc"><?= e($form['name']) ?><?= $preview ? (' <span class="tag warn">' . e(t('Vorschau mit Beispieldaten')) . '</span>') : '' ?></span>
            <span class="wt-actions">
                <?php if ($needsCustomer && !$preview): ?>
                    <button type="button" class="wt-btn" data-pop="pop-customers"><?= ic('users') ?>
                        <?= $selected ? (count($selected) === 1 ? e(customer_label($selected[0])) : count($selected) . t(' Kunden (Serienbrief)')) : t('Kunden wählen') ?></button>
                <?php endif; ?>
                <label class="wt-date"><?= ic('calendar') ?><input type="date" value="<?= date('Y-m-d') ?>" data-doc-date aria-label="<?= e(t('Datum des Dokuments')) ?>"></label>
                <button type="button" class="wt-btn" data-fill-reset title="<?= e(t('Alle Eingaben leeren')) ?>"><?= e(t('Leeren')) ?></button>
                <a class="wt-btn" href="<?= e(furl('form_edit', ['id' => $form['id']])) ?>"><?= ic('edit') ?> <?= e(t('Bearbeiten')) ?></a>
                <button type="button" class="wt-btn primary" data-print><?= ic('print') ?> <?= e(t('Drucken / PDF')) ?></button>
            </span>
        </header>
        <?php if ($needsCustomer && !$preview): ?>
        <div class="pop customers-pop<?= !$selected ? ' open' : '' ?>" id="pop-customers">
            <form method="get" class="cust-search">
                <input type="hidden" name="r" value="form_use"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
                <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
                <?php if ($ids): ?><input type="hidden" name="kunden" value="<?= e(implode(',', $ids)) ?>"><?php endif; ?>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('Kunden suchen (Name, Nummer, Ort …)')) ?>" autofocus>
                <button class="btn small"><?= e(t('Suchen')) ?></button>
            </form>
            <?php if (!$list): ?>
                <p class="muted"><?= $search !== '' ? t('Keine Kunden gefunden.') : t('Die Kundendatenbank ist noch leer.') ?>
                    <a href="<?= e(furl('customer_edit')) ?>"><?= e(t('Kunden anlegen')) ?></a></p>
            <?php else: ?>
                <p class="muted small"><?= e(t('Ein Kunde = ein Dokument. Mehrere Kunden = Serienbrief, je Kunde eine Seite.')) ?>
                    <?= $total > count($list) ? t('Erste {n} von {m} Treffern.', ['n' => count($list), 'm' => $total]) : '' ?></p>
                <div class="pick-list" id="customer-pick">
                    <?php foreach ($list as $c): ?>
                        <label class="check"><input type="checkbox" value="<?= (int)$c['id'] ?>" data-customer <?= in_array((int)$c['id'], $ids, true) ? 'checked' : '' ?>> <?= e(customer_label($c)) ?>
                            <?php if ($c['city'] !== ''): ?><span class="muted small"><?= e($c['city']) ?></span><?php endif; ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="buttons"><button type="button" class="linkbtn small" data-check-all="#customer-pick"><?= e(t('alle / keine')) ?></button>
                    <button type="button" class="btn primary" data-apply-customers data-base="<?= e(furl('form_use', ['id' => $form['id']])) ?>"><?= e(t('Übernehmen')) ?></button></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="fill-canvas" data-fill data-form-id="<?= (int)$form['id'] ?>">
            <?php if ($needsCustomer && !$selected && !$preview): ?>
                <div class="fill-note"><?= e(t('Wählen Sie oben rechts unter')) ?> <strong><?= e(t('Kunden wählen')) ?></strong> <?= e(t('den Empfänger. Bis dahin bleiben die Kundendaten leer.')) ?></div>
            <?php endif; ?>
            <?php render_sheets($cid, $form, $sheets, true); ?>
        </div>
    <?php }, 'fill-app', ['calc.js', 'fill.js']);
}

/** Vorschau eines Briefkopfs oder einer Fußzeile */
function page_form_print(): void
{
    $cid = forms_company_id();
    $l = row('SELECT * FROM form_layouts WHERE id = ? AND company_id = ?', [query_int('layout'), $cid]);
    if (!$l) {
        if (query_int('id')) fredirect('form_use', ['id' => query_int('id'), 'preview' => 1]);
        abort(404);
    }
    $form = ['name' => t('Vorschau: ') . $l['name'], 'body' => ('<p>' . e(t('Hier steht der Inhalt des Formulars.')) . '</p>'), 'folder_id' => null,
        'header_id' => $l['kind'] === 'header' ? (int)$l['id'] : 0, 'footer_id' => $l['kind'] === 'footer' ? (int)$l['id'] : 0, 'page_settings' => null];
    render_app_shell($form['name'], function () use ($cid, $form) { ?>
        <header class="word-title fill-title"><span class="wt-app">📝</span><span class="wt-doc"><?= e($form['name']) ?> <span class="tag warn"><?= e(t('Vorschau mit Beispieldaten')) ?></span></span>
            <span class="wt-actions"><button type="button" class="wt-btn" data-close><?= e(t('Schließen')) ?></button><button type="button" class="wt-btn primary" data-print><?= ic('print') ?> <?= e(t('Drucken / PDF')) ?></button></span></header>
        <div class="fill-canvas"><?php render_sheets($cid, $form, [sample_customer($cid)], false); ?></div>
    <?php }, 'fill-app', ['calc.js']);
}
