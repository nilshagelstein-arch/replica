<?php
/**
 * App „Verträge & Laufzeiten“ – Stufe 4: Kündigung mit Freigabe
 *
 * Ablauf (Tabelle ct_cancellations, je Vertrag höchstens ein offener Vorgang):
 *  1. Vorbereiten (Recht „bearbeiten“): Termin (nächstmöglich oder Wunschdatum) und interner Grund. Das Portal prüft,
 *     ob die Frist zu halten ist. Das Schreiben entsteht aus der Vorlage „Kündigungsschreiben“ der App „Formulare“
 *     (je Firma anpassbar, Platzhalter vertrag.*) und wird als Text festgehalten.
 *     Vertrag: Status „Kündigung in Freigabe“.
 *  2. Freigabe durch Firmen-Admins oder Gruppen mit „Freigabe Kündigung“; wer vorbereitet hat, darf nicht selbst
 *     freigeben (Vier-Augen-Prinzip, je Firma abschaltbar). Ablehnen nur mit Begründung → alter Status zurück.
 *     Vertrag: „Kündigung freigegeben“.
 *  3. Versand: als PDF/Druck selbst verschicken (Post, Fax, Portal des Anbieters) und als versendet markieren, oder
 *     per E-Mail mit PDF an den Anbieter. Vertrag: „Gekündigt zum …“.
 *  4. Bestätigung des Anbieters hochladen (Dokument „Kündigungsbestätigung“), Termin ggf. korrigieren.
 *  Erinnerungen über den Cronjob: Freigabe nach 3 Tagen erneut, fehlende Bestätigung nach 14 Tagen.
 */

declare(strict_types=1);

const CT_CANCEL_STATES = ['freigabe' => 'wartet auf Freigabe', 'freigegeben' => 'freigegeben, noch nicht versendet', 'versendet' => 'versendet, Bestätigung fehlt',
    'bestaetigt' => 'bestätigt', 'abgelehnt' => 'abgelehnt', 'zurueckgezogen' => 'zurückgezogen'];
const CT_CANCEL_OPEN = ['freigabe', 'freigegeben', 'versendet'];
const CT_SENT_VIA = ['mail' => 'E-Mail mit PDF', 'post' => 'Post', 'einschreiben' => 'Einschreiben', 'fax' => 'Fax', 'portal' => 'Kundenportal des Anbieters', 'sonstiges' => 'Sonstiges'];
const CT_APPROVE_REMIND_DAYS = 3;
const CT_CONFIRM_REMIND_DAYS = 14;

function ct_cancel_open(int $cid, int $contractId): ?array
{
    return row("SELECT * FROM ct_cancellations WHERE company_id = ? AND contract_id = ? AND status IN ('freigabe', 'freigegeben', 'versendet') ORDER BY id DESC LIMIT 1",
        [$cid, $contractId]) ?: null;
}

function ct_four_eyes(int $cid): bool
{
    return (int)(row('SELECT ct_four_eyes FROM companies WHERE id = ?', [$cid])['ct_four_eyes'] ?? 1) === 1;
}

/** Wer freigeben darf: aktive Firmen-Admins und Mitglieder von Gruppen mit „Freigabe Kündigung“ (ohne $exclude) */
function ct_approvers(int $cid, ?int $exclude = null): array
{
    $list = rows("SELECT id, name, email FROM users WHERE company_id = ? AND role = 'firma_admin' AND active = 1", [$cid]);
    $list = array_merge($list, rows('SELECT DISTINCT u.id, u.name, u.email FROM users u JOIN user_departments ud ON ud.user_id = u.id
        JOIN ct_group_perms g ON g.department_id = ud.department_id WHERE g.company_id = ? AND g.perm >= 1 AND g.approve_cancel = 1 AND u.active = 1 AND u.company_id = ?', [$cid, $cid]));
    $out = [];
    foreach ($list as $u) if ((int)$u['id'] !== (int)$exclude) $out[(int)$u['id']] = $u;
    return array_values($out);
}

/** Darf der angemeldete Benutzer diesen Vorgang freigeben? */
function ct_can_approve(int $cid, array $k): bool
{
    $u = current_user();
    if (!ct_rights($cid)['approve']) return false;
    return !ct_four_eyes($cid) || (int)$k['prepared_by'] !== (int)$u['id'];
}

// ---------------------------------------------------------------------------
// Vorlage „Kündigungsschreiben“ in der App „Formulare“
// ---------------------------------------------------------------------------

/** Standardtext der Vorlage (in der Sprache der Firma) */
function ct_cancel_default_body(): string
{
    $ph = fn(string $k) => '<span class="ph" contenteditable="false">{{' . $k . '}}</span>';
    return '<h2>' . e(t('Kündigung')) . ' ' . $ph('vertrag.bezeichnung') . '</h2>'
        . '<p>' . $ph('vertrag.referenz') . '</p>'
        . '<p>' . $ph('kunde.briefanrede') . '</p>'
        . '<p>' . e(t('hiermit kündigen wir den oben genannten Vertrag fristgerecht zum')) . ' ' . $ph('vertrag.termin') . ', '
        . e(t('hilfsweise zum nächstmöglichen Termin.')) . '</p>'
        . '<p>' . e(t('Bitte bestätigen Sie uns die Kündigung und das Vertragsende schriftlich.')) . '</p>'
        . '<p>' . e(t('Mit freundlichen Grüßen')) . '</p><p><br></p>'
        . '<p>' . $ph('benutzer.name') . '<br>' . $ph('firma.name') . '</p>';
}

/** Vorlage der Firma; legt sie beim ersten Aufruf im Ordner „Verträge“ der App „Formulare“ an */
function ct_cancel_template(int $cid): array
{
    $co = row('SELECT ct_cancel_form_id FROM companies WHERE id = ?', [$cid]);
    $id = (int)($co['ct_cancel_form_id'] ?? 0);
    $form = $id ? row('SELECT * FROM forms WHERE id = ? AND company_id = ?', [$id, $cid]) : null;
    if ($form) return $form;
    forms_ensure_defaults($cid);
    $folder = row("SELECT id FROM form_folders WHERE company_id = ? AND parent_id IS NULL AND name = 'Verträge'", [$cid]);
    $folderId = $folder ? (int)$folder['id'] : insert('form_folders', ['company_id' => $cid, 'parent_id' => null, 'name' => 'Verträge', 'created_at' => now()]);
    $id = with_lang(company_lang($cid), fn() => insert('forms', ['company_id' => $cid, 'folder_id' => $folderId, 'name' => t('Kündigungsschreiben (Verträge)'),
        'description' => t('Vorlage für Kündigungen aus der App „Verträge & Laufzeiten“. Platzhalter „Vertrag“ werden beim Vorbereiten gefüllt.'),
        'body' => ct_cancel_default_body(), 'header_id' => 0, 'footer_id' => 0, 'created_by' => null, 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()]));
    update('companies', $cid, ['ct_cancel_form_id' => $id]);
    return row('SELECT * FROM forms WHERE id = ?', [$id]);
}

/** Werte für die Platzhalter: vertrag.*, Anbieter als „Kunde“ (Anschrift, Briefanrede) */
function ct_cancel_values(array $c, string $termin): array
{
    $ref = array_filter([$c['contract_no'] !== '' ? t('Vertragsnummer') . ': ' . $c['contract_no'] : '', $c['customer_no'] !== '' ? t('Kundennummer') . ': ' . $c['customer_no'] : '']);
    return ['vertrag.bezeichnung' => (string)$c['title'], 'vertrag.partner' => (string)$c['partner'], 'vertrag.nummer' => (string)$c['contract_no'],
        'vertrag.kundennummer' => (string)$c['customer_no'], 'vertrag.referenz' => implode(' · ', $ref), 'vertrag.termin' => fmt_date($termin),
        'vertrag.beginn' => $c['start_date'] !== '' ? fmt_date($c['start_date']) : ''];
}

/** Anbieter als Kundendatensatz, damit Briefkopf ({{kunde.anschrift}}) und Anrede passen */
function ct_partner_as_customer(array $c): array
{
    return ['customer_no' => '', 'company_name' => (string)$c['partner'], 'salutation' => 'Firma', 'title' => '', 'first_name' => '', 'last_name' => '',
        'street' => (string)($c['partner_street'] ?? ''), 'zip' => (string)($c['partner_zip'] ?? ''), 'city' => (string)($c['partner_city'] ?? ''),
        'country' => '', 'email' => (string)($c['partner_email'] ?? ''), 'phone' => '', 'notes' => '', 'extra' => '{}'];
}

/** Text des Schreibens mit eingesetzten Werten (ohne Briefkopf/Fußzeile) */
function ct_cancel_body(int $cid, array $c, string $termin): string
{
    $tpl = ct_cancel_template($cid);
    $u = current_user();
    $values = ct_cancel_values($c, $termin) + company_values($cid) + [
        'datum' => date(lang_date_format()), 'datum.lang' => german_date_long(time()),
        'benutzer.name' => (string)($u['name'] ?? ''), 'benutzer.email' => (string)($u['email'] ?? ''),
    ] + customer_values(ct_partner_as_customer($c), $cid);
    return sanitize_html(fill_placeholders((string)$tpl['body'], $values, ''));
}

// ---------------------------------------------------------------------------
// Schreiben als HTML (Drucken) und als PDF (Mailanhang, Download)
// ---------------------------------------------------------------------------

function ct_cancel_load(int $cid, int $id): array
{
    $k = row('SELECT * FROM ct_cancellations WHERE id = ? AND company_id = ?', [$id, $cid]);
    if (!$k) abort(404);
    $c = ct_load((int)$k['contract_id'], $cid);
    if (!$c) abort(404);
    return [$k, $c];
}

/** Druckansicht mit Briefkopf und Fußzeile der Firma (Drucken / als PDF speichern im Browser) */
function page_contract_letter(): void
{
    $cid = ct_company_id();
    if (ct_perm($cid) < CT_PERM_VIEW) abort(403);
    [$k, $c] = ct_cancel_load($cid, query_int('id'));
    $tpl = ct_cancel_template($cid);
    $form = ['name' => t('Kündigung') . ' · ' . $c['title'], 'body' => (string)$k['letter_html'], 'header_id' => (int)$tpl['header_id'], 'footer_id' => (int)$tpl['footer_id'],
        'page_settings' => $tpl['page_settings'] ?? null];
    $preparer = row('SELECT name, email FROM users WHERE id = ?', [$k['prepared_by']]) ?? ['name' => '', 'email' => ''];
    $extra = ct_cancel_values($c, (string)$k['effective_date']) + ['benutzer.name' => (string)$preparer['name'], 'benutzer.email' => (string)$preparer['email']];
    render_app_shell($form['name'], function () use ($cid, $form, $c, $k, $extra) { ?>
        <header class="word-title fill-title"><span class="wt-app">📄</span><span class="wt-doc"><?= e($form['name']) ?>
            <?php if ($k['status'] === 'freigabe'): ?><span class="tag warn"><?= e(t('Entwurf – noch nicht freigegeben')) ?></span><?php endif; ?></span>
            <span class="wt-actions"><a class="wt-btn" href="<?= e(cturl('contract', ['id' => $c['id']])) ?>"><?= e(t('Zurück zum Vertrag')) ?></a>
                <a class="wt-btn" href="<?= e(cturl('contract_letter_pdf', ['id' => $k['id']])) ?>"><?= e(t('PDF')) ?></a>
                <button type="button" class="wt-btn primary" data-print><?= e(t('Drucken')) ?></button></span></header>
        <div class="fill-canvas"><?php render_sheets($cid, $form, [ct_partner_as_customer($c)], false, $extra); ?></div>
    <?php }, 'fill-app', []);
}

/** HTML des Schreibens in Absätze zerlegen: [['text' => …, 'bold' => bool, 'list' => bool], …] */
function ct_html_blocks(string $html): array
{
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8"><div id="r">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR);
    $root = $doc->getElementById('r');
    $out = [];
    $text = function (DOMNode $n) use (&$text): string {
        $s = '';
        foreach ($n->childNodes as $ch) {
            if ($ch instanceof DOMText) $s .= $ch->nodeValue;
            elseif ($ch->nodeName === 'br') $s .= "\n";
            elseif (in_array($ch->nodeName, ['td', 'th'], true)) $s .= $text($ch) . '   ';
            elseif ($ch->nodeName === 'tr') $s .= rtrim($text($ch)) . "\n";
            else $s .= $text($ch);
        }
        return $s;
    };
    $walk = function (DOMNode $parent) use (&$walk, &$out, $text) {
        foreach ($parent->childNodes as $n) {
            $name = strtolower($n->nodeName);
            if (in_array($name, ['ul', 'ol'], true)) {
                foreach ($n->childNodes as $li) if (strtolower($li->nodeName) === 'li') $out[] = ['text' => trim($text($li)), 'bold' => false, 'list' => true];
            } elseif (in_array($name, ['div', 'section', 'blockquote'], true)) {
                $walk($n);
            } elseif ($n instanceof DOMText) {
                if (trim((string)$n->nodeValue) !== '') $out[] = ['text' => trim((string)$n->nodeValue), 'bold' => false, 'list' => false];
            } else {
                $t = preg_replace("/[ \t]+/u", ' ', $text($n));
                $t = trim(preg_replace("/ *\n */u", "\n", (string)$t));
                $out[] = ['text' => $t, 'bold' => in_array($name, ['h1', 'h2', 'h3', 'h4'], true), 'list' => false];
            }
        }
    };
    if ($root) $walk($root);
    return $out;
}

/** Kündigungsschreiben als PDF (DIN-5008-ähnlich: Logo, Absenderzeile, Anschrift, Datum, Text) */
function ct_cancel_pdf(int $cid, array $k, array $c): string
{
    $v = company_values($cid);
    $b = forms_branding($cid);
    $pdf = new SimplePdf(false, $v['firma.absenderzeile'] . ($v['firma.telefon'] !== '' ? ' · ' . $v['firma.telefon'] : '') . ($v['firma.email'] !== '' ? ' · ' . $v['firma.email'] : ''));
    $pdf->margin = 62;
    $top = $pdf->h;
    // Logo rechts oben
    if (!empty($b['logo_data']) && ($img = SimplePdf::toJpeg((string)base64_decode((string)$b['logo_data'])))) {
        [$jpg, $iw, $ih] = $img;
        $h = 48; $w = $h * $iw / max(1, $ih);
        if ($w > 170) { $w = 170; $h = $w * $ih / max(1, $iw); }
        $pdf->imageJpeg($jpg, $pdf->w - $pdf->margin - $w, $top - 40 - $h, $w, $h);
    }
    // Absenderzeile und Anschrift (Anschriftfeld ab ca. 45 mm von oben)
    $y = $top - 128;
    $pdf->text($pdf->margin, $y, SimplePdf::fit($v['firma.absenderzeile'], 250, 7), 7, false, [90, 100, 110]);
    $pdf->line($pdf->margin, $y - 2, $pdf->margin + min(250, SimplePdf::width($v['firma.absenderzeile'], 7)), $y - 2, 0.3, [90, 100, 110]);
    $addr = array_filter([(string)$c['partner'], (string)$c['partner_street'], trim($c['partner_zip'] . ' ' . $c['partner_city'])]);
    $y -= 18;
    foreach ($addr as $line) { $pdf->text($pdf->margin, $y, $line, 10.5); $y -= 14; }
    // Absenderblock rechts
    $ry = $top - 128;
    foreach (array_filter([$v['firma.name'], $v['firma.strasse'], trim($v['firma.plz'] . ' ' . $v['firma.ort']), $v['firma.telefon'], $v['firma.email']]) as $i => $line) {
        $pdf->text($pdf->w - $pdf->margin, $ry, $line, 8.5, $i === 0, [60, 70, 85], 'right');
        $ry -= 12;
    }
    // Datum
    $date = trim(($v['firma.ort'] !== '' ? $v['firma.ort'] . ', ' : '') . fmt_date((string)($k['sent_at'] ?: ($k['decided_at'] ?: $k['prepared_at']))));
    $pdf->text($pdf->w - $pdf->margin, $top - 270, $date, 10.5, false, [0, 0, 0], 'right');
    $pdf->y = $top - 300;
    foreach (ct_html_blocks((string)$k['letter_html']) as $bl) {
        if ($bl['text'] === '') { $pdf->y -= 8; continue; }
        $pdf->paragraph($bl['text'], $bl['bold'] ? 12 : 10.5, $bl['bold'], $bl['bold'] ? 10 : 7, $bl['list'] ? 12 : 0, $bl['list'] ? '•' : '');
    }
    return $pdf->output(t('Kündigung') . ' ' . $c['title']);
}

function ct_cancel_pdf_name(array $c): string
{
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)iconv('UTF-8', 'ASCII//TRANSLIT', (string)$c['title'])) ?: 'Vertrag';
    return 'Kuendigung-' . trim($name, '-') . '.pdf';
}

function page_contract_letter_pdf(): void
{
    $cid = ct_company_id();
    if (ct_perm($cid) < CT_PERM_VIEW) abort(403);
    [$k, $c] = ct_cancel_load($cid, query_int('id'));
    $data = ct_cancel_pdf($cid, $k, $c);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: inline; filename="' . ct_cancel_pdf_name($c) . '"');
    header('Cache-Control: private, no-store');
    echo $data;
    exit;
}

// ---------------------------------------------------------------------------
// Karte im Vertrag
// ---------------------------------------------------------------------------

function ct_cancel_card(int $cid, array $c, bool $edit): void
{
    $open = ct_cancel_open($cid, (int)$c['id']);
    $last = $open ?: (row('SELECT * FROM ct_cancellations WHERE company_id = ? AND contract_id = ? ORDER BY id DESC LIMIT 1', [$cid, $c['id']]) ?: null);
    $u = current_user();
    $names = fn($id) => $id ? (string)(row('SELECT name FROM users WHERE id = ?', [$id])['name'] ?? '–') : '–';
    $dl = ct_deadlines($c);
    $canPrepare = $edit && !$open && in_array($c['status'], ['aktiv', 'verlaengert'], true);
    if (!$last && !$canPrepare) return; ?>
    <div class="card">
        <h3><?= e(t('Kündigung')) ?></h3>
        <?php if ($last && (!$canPrepare || $open)): ?>
            <p><span class="tag <?= in_array($last['status'], ['abgelehnt', 'zurueckgezogen'], true) ? '' : ($last['status'] === 'bestaetigt' ? '' : 'warn') ?>"><?= e(t(CT_CANCEL_STATES[$last['status']])) ?></span>
                <?= e(t('zum {d}', ['d' => fmt_date($last['effective_date'])])) ?></p>
            <dl class="facts">
                <dt><?= e(t('Vorbereitet')) ?></dt><dd><?= e($names($last['prepared_by'])) ?> · <?= fmt_dt($last['prepared_at']) ?></dd>
                <?php if ($last['reason'] !== ''): ?><dt><?= e(t('Grund (intern)')) ?></dt><dd><?= e($last['reason']) ?></dd><?php endif; ?>
                <?php if ((int)$last['out_of_time']): ?><dt><?= e(t('Hinweis')) ?></dt><dd class="warn-text"><?= e(t('Termin liegt vor dem nächstmöglichen Ende')) ?></dd><?php endif; ?>
                <?php if ($last['decided_at']): ?><dt><?= e($last['status'] === 'abgelehnt' ? t('Abgelehnt') : t('Freigegeben')) ?></dt><dd><?= e($names($last['decided_by'])) ?> · <?= fmt_dt($last['decided_at']) ?><?= $last['decision_note'] !== '' ? '<br>' . e($last['decision_note']) : '' ?></dd><?php endif; ?>
                <?php if ($last['sent_at']): ?><dt><?= e(t('Versendet')) ?></dt><dd><?= e(t(CT_SENT_VIA[$last['sent_via']] ?? $last['sent_via'])) ?> · <?= fmt_date(substr((string)$last['sent_at'], 0, 10)) ?><?= $last['sent_to'] !== '' ? ' · ' . e($last['sent_to']) : '' ?></dd><?php endif; ?>
                <?php if ($last['confirmed_at']): ?><dt><?= e(t('Bestätigt')) ?></dt><dd><?= fmt_dt($last['confirmed_at']) ?></dd><?php endif; ?>
            </dl>
            <?php if ($last['letter_html'] !== '' && in_array($last['status'], ['freigabe', 'freigegeben', 'versendet', 'bestaetigt'], true)): ?>
            <p><a class="btn small" href="<?= e(cturl('contract_letter', ['id' => $last['id']])) ?>" target="_blank" rel="noopener"><?= e(t('Schreiben ansehen / drucken')) ?></a>
                <a class="btn small" href="<?= e(cturl('contract_letter_pdf', ['id' => $last['id']])) ?>" target="_blank" rel="noopener"><?= e(t('PDF')) ?></a></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($open && $open['status'] === 'freigabe'): ?>
            <?php if (ct_can_approve($cid, $open)): ?>
            <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="cancel_id" value="<?= (int)$open['id'] ?>">
                <label><?= e(t('Anmerkung (bei Ablehnung Pflicht)')) ?> <input name="note" maxlength="500"></label>
                <div class="toolbar"><button class="btn primary" name="action" value="cancel_approve"><?= e(t('Freigeben')) ?></button>
                    <button class="btn danger" name="action" value="cancel_reject"><?= e(t('Ablehnen')) ?></button></div>
            </form>
            <?php elseif (ct_rights($cid)['approve'] && ct_four_eyes($cid)): ?>
                <p class="muted small"><?= e(t('Sie haben die Kündigung vorbereitet. Freigeben muss eine andere berechtigte Person (Vier-Augen-Prinzip).')) ?></p>
            <?php else: ?>
                <p class="muted small"><?= e(t('Wartet auf Freigabe durch: {x}', ['x' => implode(', ', array_column(ct_approvers($cid, ct_four_eyes($cid) ? (int)$open['prepared_by'] : null), 'name')) ?: '–'])) ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($open && $open['status'] === 'freigegeben' && $edit): ?>
            <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_send_mail"><input type="hidden" name="cancel_id" value="<?= (int)$open['id'] ?>">
                <h4><?= e(t('Per E-Mail an den Anbieter')) ?></h4>
                <label><?= e(t('An')) ?> <input type="email" name="to" required maxlength="190" value="<?= e((string)$c['partner_email']) ?>"></label>
                <label class="check"><input type="checkbox" name="copy" value="1" checked> <?= e(t('Kopie an mich ({x})', ['x' => $u['email']])) ?></label>
                <button class="btn primary" data-confirm="<?= e(t('Kündigung jetzt per E-Mail an den Anbieter senden?')) ?>"><?= e(t('Kündigung senden')) ?></button>
                <p class="muted small"><?= e(t('Das Schreiben geht als PDF im Anhang mit, Antworten kommen an Sie. Verlangt der Vertrag die Schriftform, schicken Sie es besser per Post oder Einschreiben.')) ?></p>
            </form>
            <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_mark_sent"><input type="hidden" name="cancel_id" value="<?= (int)$open['id'] ?>">
                <h4><?= e(t('Selbst versendet')) ?></h4>
                <div class="grid2">
                    <label><?= e(t('Versandart')) ?> <select name="via"><?php foreach (CT_SENT_VIA as $kk => $l): if ($kk === 'mail') continue; ?><option value="<?= e($kk) ?>"><?= e(t($l)) ?></option><?php endforeach; ?></select></label>
                    <label><?= e(t('Versendet am')) ?> <input type="date" name="sent_on" value="<?= date('Y-m-d') ?>" required></label>
                </div>
                <button class="btn"><?= e(t('Als versendet markieren')) ?></button>
            </form>
        <?php endif; ?>

        <?php if ($open && $open['status'] === 'versendet' && $edit): ?>
            <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_confirm"><input type="hidden" name="cancel_id" value="<?= (int)$open['id'] ?>">
                <h4><?= e(t('Bestätigung des Anbieters')) ?></h4>
                <label><?= e(t('Kündigungsbestätigung (PDF, Bild, E-Mail)')) ?> <input type="file" name="files[]" required></label>
                <label><?= e(t('Bestätigt zum')) ?> <input type="date" name="confirmed_to" value="<?= e((string)$open['effective_date']) ?>" required></label>
                <p class="muted small"><?= e(t('Bestätigt der Anbieter einen anderen Termin, tragen Sie diesen ein.')) ?></p>
                <button class="btn primary"><?= e(t('Bestätigung speichern')) ?></button>
            </form>
        <?php endif; ?>

        <?php if ($open && in_array($open['status'], ['freigabe', 'freigegeben'], true) && $edit
            && ((int)$open['prepared_by'] === (int)$u['id'] || $u['role'] === 'firma_admin' || is_staff_role($u['role']))): ?>
            <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_withdraw"><input type="hidden" name="cancel_id" value="<?= (int)$open['id'] ?>">
                <button class="btn small" data-confirm="<?= e(t('Kündigung zurückziehen? Der Vertrag läuft dann normal weiter.')) ?>"><?= e(t('Zurückziehen')) ?></button></form>
        <?php endif; ?>

        <?php if ($canPrepare): $possible = $dl['possible'] ?: $dl['end']; ?>
            <form method="post" action="<?= e(cturl('contract_action', ['id' => $c['id']])) ?>" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_prepare">
                <?php if ($last): ?><hr><?php endif; ?>
                <h4><?= e(t('Kündigung vorbereiten')) ?></h4>
                <?php if ($possible !== ''): ?>
                <p class="small"><?= $dl['by'] !== '' ? e(t('Nächstmöglich zum {d}, Kündigung muss bis {b} beim Anbieter sein.', ['d' => fmt_date($possible), 'b' => fmt_date($dl['by'])]))
                    : e(t('Der Vertrag endet am {d} ohne Verlängerung.', ['d' => fmt_date($possible)])) ?>
                    <?php if ($dl['by'] !== '' && $dl['by'] <= date('Y-m-d', strtotime('+7 days'))): ?><br><strong class="warn-text"><?= e(t('Die Frist endet bald – bitte zügig freigeben und versenden.')) ?></strong><?php endif; ?></p>
                <?php endif; ?>
                <label class="check"><input type="radio" name="mode" value="next" checked> <?= e(t('zum nächstmöglichen Termin')) ?><?= $possible !== '' ? ' (' . fmt_date($possible) . ')' : '' ?></label>
                <label class="check"><input type="radio" name="mode" value="date"> <?= e(t('zum Wunschdatum')) ?> <input type="date" name="date" aria-label="<?= e(t('Wunschdatum')) ?>"></label>
                <label><?= e(t('Grund (nur intern)')) ?> <input name="reason" maxlength="500" placeholder="<?= e(t('z. B. günstigeres Angebot, wird nicht mehr gebraucht')) ?>"></label>
                <label class="check"><input type="checkbox" name="force" value="1"> <?= e(t('Auch wenn der Termin vor dem nächstmöglichen Ende liegt (z. B. Sonderkündigung)')) ?></label>
                <button class="btn primary"><?= e(t('Kündigung vorbereiten')) ?></button>
                <p class="muted small"><?= e(t('Das Schreiben entsteht aus der Vorlage „Kündigungsschreiben (Verträge)“ der App „Formulare“. Danach muss die Kündigung freigegeben werden.')) ?></p>
            </form>
        <?php endif; ?>
    </div>
<?php }

// ---------------------------------------------------------------------------
// Aktionen
// ---------------------------------------------------------------------------

function ct_cancel_notify(int $cid, array $c, array $to, callable $compose): void
{
    $to = array_values(array_unique(array_filter(array_map('strtolower', $to))));
    if ($to) send_mail_t($to, $compose, $cid);
}

function ct_cancel_link(int $cid, array $c): string
{
    return rtrim((string)cfg('base_url', ''), '/') . '/index.php?r=contract&id=' . $c['id'] . '&company=' . $cid;
}

/** Aktionen cancel_* aus page_contract_action; true = erledigt */
function ct_cancel_action(int $cid, array $c, string $action): bool
{
    if (!str_starts_with($action, 'cancel_')) return false;
    $u = current_user();
    $back = fn() => ct_redirect('contract', ['id' => $c['id']]);
    $co = ct_company($cid);

    if ($action === 'cancel_prepare') {
        if (!in_array($c['status'], ['aktiv', 'verlaengert'], true) || ct_cancel_open($cid, (int)$c['id'])) { flash('error', t('Für diesen Vertrag läuft bereits eine Kündigung oder er ist nicht aktiv.')); $back(); }
        $dl = ct_deadlines($c);
        $possible = $dl['possible'] ?: $dl['end'];
        $mode = post('mode') === 'date' ? 'date' : 'next';
        $date = $mode === 'date' ? ct_date((string)post('date')) : $possible;
        if ($date === '') { flash('error', $mode === 'date' ? t('Bitte ein Wunschdatum angeben.') : t('Der Vertrag hat kein Ende. Bitte ein Wunschdatum angeben.')); $back(); }
        if ($date < date('Y-m-d')) { flash('error', t('Der Termin liegt in der Vergangenheit.')); $back(); }
        $early = $possible !== '' && $date < $possible;
        if ($early && post('force') !== '1') {
            flash('error', t('Zum {d} ist die Kündigung nicht mehr fristgerecht möglich; nächstmöglich ist der {p}. Für eine Sonderkündigung bitte das Häkchen setzen.', ['d' => fmt_date($date), 'p' => fmt_date($possible)]));
            $back();
        }
        $approvers = ct_approvers($cid, ct_four_eyes($cid) ? (int)$u['id'] : null);
        if (!$approvers) { flash('error', t('Niemand außer Ihnen darf Kündigungen freigeben. Bitte vergeben Sie das Recht „Freigabe Kündigung“ oder schalten Sie das Vier-Augen-Prinzip in den Einstellungen aus.')); $back(); }
        $body = ct_cancel_body($cid, $c, $date);
        $kid = insert('ct_cancellations', ['company_id' => $cid, 'contract_id' => $c['id'], 'status' => 'freigabe', 'mode' => $mode, 'effective_date' => $date,
            'reason' => mb_substr(trim((string)post('reason')), 0, 500), 'prev_status' => $c['status'], 'out_of_time' => $early ? 1 : 0, 'letter_html' => $body,
            'prepared_by' => $u['id'], 'prepared_at' => now(), 'created_at' => now()]);
        update('contracts', (int)$c['id'], ['status' => 'kuendigung_freigabe', 'updated_at' => now()]);
        ct_log($cid, (int)$c['id'], 'Kündigung vorbereitet zum ' . fmt_date($date) . ($early ? ' (vor dem nächstmöglichen Ende)' : ''));
        audit('contract.cancel_prepare', $c['title'] . ' → ' . $date, $cid);
        $link = ct_cancel_link($cid, $c);
        ct_cancel_notify($cid, $c, array_column($approvers, 'email'), fn() => [t('Kündigung zur Freigabe: {x}', ['x' => $c['title']]),
            t("{name} hat die Kündigung des Vertrags „{title}“ ({partner}) zum {d} vorbereitet und bittet um Freigabe.\n\n{link}", ['name' => $u['name'], 'title' => $c['title'],
                'partner' => $c['partner'], 'd' => fmt_date($date), 'link' => $link])]);
        flash('success', t('Die Kündigung ist vorbereitet und wartet auf Freigabe.'));
        if ($early) flash('info', t('Hinweis: Der Termin liegt vor dem nächstmöglichen Ende.'));
        $back();
    }

    $k = row('SELECT * FROM ct_cancellations WHERE id = ? AND contract_id = ? AND company_id = ?', [(int)post('cancel_id', 0), $c['id'], $cid]);
    if (!$k) abort(404);
    $preparer = row('SELECT id, name, email FROM users WHERE id = ?', [$k['prepared_by']]);
    $link = ct_cancel_link($cid, $c);

    switch ($action) {
        case 'cancel_approve':
        case 'cancel_reject':
            if ($k['status'] !== 'freigabe') $back();
            if (!ct_can_approve($cid, $k)) abort(403);
            $note = mb_substr(trim((string)post('note')), 0, 500);
            $ok = $action === 'cancel_approve';
            if (!$ok && $note === '') { flash('error', t('Bitte begründen Sie die Ablehnung.')); $back(); }
            update('ct_cancellations', (int)$k['id'], ['status' => $ok ? 'freigegeben' : 'abgelehnt', 'decided_by' => $u['id'], 'decided_at' => now(), 'decision_note' => $note]);
            update('contracts', (int)$c['id'], ['status' => $ok ? 'kuendigung_freigegeben' : ($k['prev_status'] ?: 'aktiv'), 'updated_at' => now()]);
            ct_log($cid, (int)$c['id'], ($ok ? 'Kündigung freigegeben' : 'Kündigung abgelehnt') . ($note !== '' ? ': ' . $note : ''));
            audit($ok ? 'contract.cancel_approve' : 'contract.cancel_reject', $c['title'], $cid);
            if ($preparer && (int)$preparer['id'] !== (int)$u['id']) {
                ct_cancel_notify($cid, $c, [$preparer['email']], fn() => [($ok ? t('Kündigung freigegeben: {x}', ['x' => $c['title']]) : t('Kündigung abgelehnt: {x}', ['x' => $c['title']])),
                    ($ok ? t("{name} hat die Kündigung des Vertrags „{title}“ freigegeben. Sie kann jetzt versendet werden.", ['name' => $u['name'], 'title' => $c['title']])
                        : t("{name} hat die Kündigung des Vertrags „{title}“ abgelehnt.", ['name' => $u['name'], 'title' => $c['title']]))
                    . ($note !== '' ? "\n\n" . t('Anmerkung: {x}', ['x' => $note]) : '') . "\n\n" . $link]);
            }
            flash('success', $ok ? t('Die Kündigung ist freigegeben und kann versendet werden.') : t('Die Kündigung wurde abgelehnt.'));
            $back();
        case 'cancel_withdraw':
            if (!in_array($k['status'], ['freigabe', 'freigegeben'], true)) $back();
            if ((int)$k['prepared_by'] !== (int)$u['id'] && $u['role'] !== 'firma_admin' && !is_staff_role($u['role'])) abort(403);
            update('ct_cancellations', (int)$k['id'], ['status' => 'zurueckgezogen', 'decided_by' => $u['id'], 'decided_at' => now()]);
            update('contracts', (int)$c['id'], ['status' => $k['prev_status'] ?: 'aktiv', 'updated_at' => now()]);
            ct_log($cid, (int)$c['id'], 'Kündigung zurückgezogen');
            audit('contract.cancel_withdraw', $c['title'], $cid);
            flash('success', t('Die Kündigung wurde zurückgezogen.'));
            $back();
        case 'cancel_send_mail':
        case 'cancel_mark_sent':
            if ($k['status'] !== 'freigegeben') $back();
            $mail = $action === 'cancel_send_mail';
            $to = $mail ? mb_strtolower(trim((string)post('to'))) : '';
            if ($mail && !filter_var($to, FILTER_VALIDATE_EMAIL)) { flash('error', t('Bitte eine gültige E-Mail-Adresse des Anbieters angeben.')); $back(); }
            $via = $mail ? 'mail' : (string)post('via');
            if (!isset(CT_SENT_VIA[$via])) $via = 'post';
            $sentOn = $mail ? date('Y-m-d') : (ct_date((string)post('sent_on')) ?: date('Y-m-d'));
            if ($sentOn > date('Y-m-d')) { flash('error', t('Das Versanddatum liegt in der Zukunft.')); $back(); }
            update('ct_cancellations', (int)$k['id'], ['status' => 'versendet', 'sent_by' => $u['id'], 'sent_at' => $sentOn . ' ' . date('H:i:s'), 'sent_via' => $via, 'sent_to' => $to]);
            $k = row('SELECT * FROM ct_cancellations WHERE id = ?', [$k['id']]);
            if ($mail) {
                $pdf = ct_cancel_pdf($cid, $k, $c);
                $text = implode("\n\n", array_map(fn($b) => $b['text'], ct_html_blocks((string)$k['letter_html'])));
                $GLOBALS['mail_reply_to'] = (string)$u['email'];
                $recipients = [$to];
                if (post('copy') === '1') $recipients[] = (string)$u['email'];
                with_lang(company_lang($cid), function () use ($recipients, $c, $text, $pdf, $cid, $co) {
                    send_mail($recipients, t('Kündigung') . ' ' . $c['title'] . ($c['contract_no'] !== '' ? ' – ' . t('Vertragsnummer') . ' ' . $c['contract_no'] : '') . ' – ' . $co['name'],
                        $text, $cid, [['name' => ct_cancel_pdf_name($c), 'mime' => 'application/pdf', 'data' => $pdf]]);
                });
                unset($GLOBALS['mail_reply_to']);
            }
            update('contracts', (int)$c['id'], ['status' => 'gekuendigt', 'cancelled_to' => $k['effective_date'], 'updated_at' => now()]);
            ct_log($cid, (int)$c['id'], 'Kündigung versendet: ' . CT_SENT_VIA[$via] . ($to !== '' ? ' an ' . $to : '') . ' · ' . fmt_date($sentOn));
            audit('contract.cancel_sent', $c['title'] . ' · ' . $via . ($to !== '' ? ' · ' . $to : ''), $cid);
            flash('success', $mail ? t('Die Kündigung wurde an {x} gesendet.', ['x' => $to]) : t('Die Kündigung ist als versendet vermerkt.'));
            flash('info', t('Laden Sie die Bestätigung des Anbieters hoch, sobald sie da ist. Ohne Bestätigung erinnern wir nach {n} Tagen.', ['n' => CT_CONFIRM_REMIND_DAYS]));
            $back();
        case 'cancel_confirm':
            if ($k['status'] !== 'versendet') $back();
            $to = ct_date((string)post('confirmed_to'));
            if ($to === '') { flash('error', t('Bitte das bestätigte Vertragsende angeben.')); $back(); }
            [$files, $err] = ticket_uploads();
            if ($err) { flash('error', $err); $back(); }
            if (!$files) { flash('error', t('Bitte die Bestätigung als Datei hochladen.')); $back(); }
            $dir = ct_files_dir($cid);
            foreach ($files as $fl) {
                $stored = $c['id'] . '-' . bin2hex(random_bytes(12)) . '.bin';
                if (!move_uploaded_file($fl['tmp'], $dir . '/' . $stored)) continue;
                @chmod($dir . '/' . $stored, 0640);
                $mime = function_exists('mime_content_type') ? (string)@mime_content_type($dir . '/' . $stored) : 'application/octet-stream';
                insert('ct_files', ['company_id' => $cid, 'contract_id' => $c['id'], 'doc_kind' => 'kuendigung', 'filename' => $fl['name'], 'mime' => mb_substr($mime, 0, 100),
                    'size' => $fl['size'], 'stored_name' => $stored, 'user_id' => $u['id'], 'created_at' => now()]);
            }
            update('ct_cancellations', (int)$k['id'], ['status' => 'bestaetigt', 'confirmed_at' => now(), 'confirmed_by' => $u['id'], 'effective_date' => $to]);
            update('contracts', (int)$c['id'], ['status' => 'gekuendigt', 'cancelled_to' => $to, 'updated_at' => now()]);
            ct_log($cid, (int)$c['id'], 'Kündigung bestätigt zum ' . fmt_date($to) . ($to !== $k['effective_date'] ? ' (statt ' . fmt_date($k['effective_date']) . ')' : ''));
            audit('contract.cancel_confirm', $c['title'] . ' → ' . $to, $cid);
            flash('success', t('Die Bestätigung ist gespeichert. Der Vertrag ist gekündigt zum {d}.', ['d' => fmt_date($to)]));
            $back();
    }
    abort(400);
}

/** Erinnerungen aus dem Cronjob: Freigabe nach 3 Tagen erneut, fehlende Bestätigung nach 14 Tagen */
function ct_cancel_reminders(int $cid, array $co): int
{
    $n = 0;
    $base = rtrim((string)cfg('base_url', ''), '/');
    foreach (rows("SELECT k.*, c.title, c.partner FROM ct_cancellations k JOIN contracts c ON c.id = k.contract_id
        WHERE k.company_id = ? AND k.status = 'freigabe' AND k.approve_reminded_at IS NULL AND k.prepared_at <= ?",
        [$cid, date('Y-m-d H:i:s', strtotime('-' . CT_APPROVE_REMIND_DAYS . ' days'))]) as $k) {
        update('ct_cancellations', (int)$k['id'], ['approve_reminded_at' => now()]);
        $to = array_column(ct_approvers($cid, ct_four_eyes($cid) ? (int)$k['prepared_by'] : null), 'email');
        if ($to) send_mail_t($to, fn() => [t('Erinnerung: Kündigung wartet auf Freigabe – {x}', ['x' => $k['title']]),
            t("Die Kündigung des Vertrags „{title}“ ({partner}) zum {d} wartet seit {n} Tagen auf Freigabe.\n\n{link}", ['title' => $k['title'], 'partner' => $k['partner'],
                'd' => fmt_date($k['effective_date']), 'n' => CT_APPROVE_REMIND_DAYS, 'link' => $base . '/index.php?r=contract&id=' . $k['contract_id'] . '&company=' . $cid])], $cid);
        $n++;
    }
    foreach (rows("SELECT k.*, c.title, c.partner FROM ct_cancellations k JOIN contracts c ON c.id = k.contract_id
        WHERE k.company_id = ? AND k.status = 'versendet' AND k.confirm_reminded_at IS NULL AND k.sent_at <= ?",
        [$cid, date('Y-m-d H:i:s', strtotime('-' . CT_CONFIRM_REMIND_DAYS . ' days'))]) as $k) {
        update('ct_cancellations', (int)$k['id'], ['confirm_reminded_at' => now()]);
        $to = array_column(rows("SELECT email FROM users WHERE company_id = ? AND active = 1 AND (role = 'firma_admin' OR id = ?)", [$cid, $k['prepared_by']]), 'email');
        if ($to) send_mail_t($to, fn() => [t('Kündigungsbestätigung fehlt – {x}', ['x' => $k['title']]),
            t("Für die Kündigung des Vertrags „{title}“ ({partner}) liegt seit {n} Tagen keine Bestätigung vor. Bitte beim Anbieter nachfragen und die Bestätigung im Portal hochladen.\n\n{link}",
                ['title' => $k['title'], 'partner' => $k['partner'], 'n' => CT_CONFIRM_REMIND_DAYS, 'link' => $base . '/index.php?r=contract&id=' . $k['contract_id'] . '&company=' . $cid])], $cid);
        $n++;
    }
    return $n;
}
