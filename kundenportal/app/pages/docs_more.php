<?php
/**
 * App „Dokumente & Anleitungen“ – Stufe 2: Vorlagen, Versionsverlauf mit Vergleich und Wiederherstellung,
 * Kommentare mit @-Erwähnung, Seiten beobachten (E-Mail bei Änderungen) und PDF-Export (Seite oder Teilbaum).
 *
 * - Jede Speicherung mit geändertem Titel oder Inhalt legt eine Version an (wk_versions). Seiten aus Stufe 1
 *   bekommen beim ersten Speichern ihren alten Stand als Version 1.
 * - Wiederherstellen speichert den alten Stand als neue Version (nichts geht verloren).
 * - Kommentieren und Beobachten darf jeder, der die Seite lesen darf. Mails gehen nur an Personen, die die Seite
 *   (noch) lesen dürfen; der Auslöser bekommt keine.
 * - Vorlagen: eingebaute (WK_BUILTIN_TEMPLATES, Texte übersetzt) und eigene je Firma (wk_templates).
 */

declare(strict_types=1);

const WK_BUILTIN_TEMPLATES = ['anleitung', 'richtlinie', 'besprechung', 'notfall', 'netzwerk'];
/** Höchstzahl Zeilen für den Vergleich zweier Versionen */
const WK_DIFF_MAX_LINES = 3000;
/** Mails an Beobachter bei Änderungen höchstens alle 30 Minuten je Seite und Person (Kommentare immer) */
const WK_WATCH_QUIET_MINUTES = 30;
/** Höchstzahl Seiten im PDF eines Teilbaums */
const WK_PDF_MAX_PAGES = 200;

// ---------------------------------------------------------------------------
// Inhalt in Blöcke zerlegen (für Vergleich und PDF)
// ---------------------------------------------------------------------------

/**
 * Zerlegt bereinigtes Seiten-HTML in eine flache Liste von Blöcken:
 * h (level, text), p (text), li (text, marker, depth), table (rows, head), img (src, alt), hr, toc – jeweils mit 'box' (Infobox-Klasse oder '').
 */
function wk_blocks(string $html): array
{
    $html = trim($html);
    if ($html === '') return [];
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('__root');
    if (!$root) return [];
    $out = [];
    wk_blocks_walk($root, $out, '', 0);
    return $out;
}

function wk_class_has(DOMElement $el, string $cls): bool
{
    return in_array($cls, preg_split('/\s+/', (string)$el->getAttribute('class')) ?: [], true);
}

/** Text eines Elements (Statusmarker in Klammern, Zeilenumbrüche erhalten); Bilder werden gesammelt */
function wk_inline_text(DOMNode $node, array &$imgs, bool $skipLists = false): string
{
    $t = '';
    foreach ($node->childNodes as $c) {
        if ($c instanceof DOMText) { $t .= $c->nodeValue; continue; }
        if (!($c instanceof DOMElement)) continue;
        $tag = strtolower($c->nodeName);
        if ($tag === 'br') { $t .= "\n"; continue; }
        if ($tag === 'img') { $imgs[] = ['src' => $c->getAttribute('src'), 'alt' => $c->getAttribute('alt')]; continue; }
        if ($skipLists && ($tag === 'ul' || $tag === 'ol')) continue;
        if ($tag === 'span' && wk_class_has($c, 'wk-st')) { $t .= ' [' . trim($c->textContent) . '] '; continue; }
        $t .= wk_inline_text($c, $imgs, $skipLists);
    }
    return $t;
}

function wk_clean_text(string $s): string
{
    $s = str_replace("\u{00A0}", ' ', $s);
    $lines = array_map(fn($l) => trim(preg_replace('/[ \t\r]+/u', ' ', $l)), explode("\n", $s));
    return trim(implode("\n", $lines));
}

function wk_blocks_walk(DOMNode $parent, array &$out, string $box, int $depth): void
{
    $loose = '';
    $flush = function () use (&$loose, &$out, $box) {
        $txt = wk_clean_text($loose);
        if ($txt !== '') $out[] = ['type' => 'p', 'text' => $txt, 'box' => $box];
        $loose = '';
    };
    foreach ($parent->childNodes as $n) {
        if ($n instanceof DOMText) { $loose .= $n->nodeValue; continue; }
        if (!($n instanceof DOMElement)) continue;
        $tag = strtolower($n->nodeName);
        $imgs = [];
        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4':
                $flush();
                $out[] = ['type' => 'h', 'level' => max(2, (int)substr($tag, 1)), 'text' => wk_clean_text(wk_inline_text($n, $imgs)), 'box' => $box];
                break;
            case 'p': case 'blockquote':
                $flush();
                $txt = wk_clean_text(wk_inline_text($n, $imgs));
                if ($txt !== '') $out[] = ['type' => 'p', 'text' => $txt, 'box' => $box, 'quote' => $tag === 'blockquote'];
                break;
            case 'ul': case 'ol':
                $flush();
                $i = 0;
                foreach ($n->childNodes as $li) {
                    if (!($li instanceof DOMElement) || strtolower($li->nodeName) !== 'li') continue;
                    $i++;
                    $liImgs = [];
                    $out[] = ['type' => 'li', 'text' => wk_clean_text(wk_inline_text($li, $liImgs, true)), 'marker' => $tag === 'ol' ? $i . '.' : '•', 'depth' => $depth, 'box' => $box];
                    foreach ($liImgs as $im) $out[] = ['type' => 'img'] + $im + ['box' => $box];
                    foreach ($li->childNodes as $sub) {
                        if ($sub instanceof DOMElement && in_array(strtolower($sub->nodeName), ['ul', 'ol'], true)) {
                            $wrap = $sub->ownerDocument->createElement('div');
                            $wrap->appendChild($sub->cloneNode(true));
                            wk_blocks_walk($wrap, $out, $box, $depth + 1);
                        }
                    }
                }
                break;
            case 'table':
                $flush();
                $rows = [];
                $head = false;
                foreach ((new DOMXPath($n->ownerDocument))->query('.//tr', $n) as $k => $tr) {
                    $cells = [];
                    foreach ($tr->childNodes as $cell) {
                        if (!($cell instanceof DOMElement) || !in_array(strtolower($cell->nodeName), ['td', 'th'], true)) continue;
                        if ($k === 0 && strtolower($cell->nodeName) === 'th') $head = true;
                        $cells[] = str_replace("\n", ' ', wk_clean_text(wk_inline_text($cell, $imgs)));
                    }
                    if ($cells) $rows[] = $cells;
                }
                if ($rows) $out[] = ['type' => 'table', 'rows' => $rows, 'head' => $head, 'box' => $box];
                break;
            case 'hr':
                $flush();
                $out[] = ['type' => 'hr', 'box' => $box];
                break;
            case 'img':
                $flush();
                $out[] = ['type' => 'img', 'src' => $n->getAttribute('src'), 'alt' => $n->getAttribute('alt'), 'box' => $box];
                break;
            case 'div':
                $flush();
                if (wk_class_has($n, 'wk-toc')) { $out[] = ['type' => 'toc', 'box' => $box]; break; }
                $nb = $box;
                foreach (['wk-info', 'wk-note', 'wk-warn', 'wk-ok'] as $bc) if (wk_class_has($n, $bc)) $nb = $bc;
                wk_blocks_walk($n, $out, $nb, $depth);
                break;
            default:
                // Inline-Element auf Blockebene: gehört zum losen Text
                $loose .= wk_inline_text($n, $imgs);
        }
        foreach ($imgs as $im) $out[] = ['type' => 'img'] + $im + ['box' => $box];
    }
    $flush();
}

/** Textzeilen einer Seite für den Vergleich */
function wk_diff_lines(string $html): array
{
    $lines = [];
    foreach (wk_blocks($html) as $b) {
        $pre = $b['box'] !== '' ? '▌ ' : '';
        switch ($b['type']) {
            case 'h': $lines[] = $pre . str_repeat('#', $b['level'] - 1) . ' ' . $b['text']; break;
            case 'p': foreach (explode("\n", $b['text']) as $l) if ($l !== '') $lines[] = $pre . $l; break;
            case 'li': $lines[] = $pre . str_repeat('  ', $b['depth']) . $b['marker'] . ' ' . $b['text']; break;
            case 'table': foreach ($b['rows'] as $r) $lines[] = $pre . '| ' . implode(' | ', $r) . ' |'; break;
            case 'img': $lines[] = $pre . '[' . t('Bild') . ($b['alt'] !== '' ? ': ' . $b['alt'] : '') . ' · ' . substr(hash('crc32b', $b['src']), 0, 6) . ']'; break;
            case 'hr': $lines[] = $pre . '———'; break;
            case 'toc': $lines[] = $pre . '[' . t('Inhaltsverzeichnis') . ']'; break;
        }
    }
    return $lines;
}

/** Zeilenvergleich (längste gemeinsame Teilfolge): Liste von ['=', Zeile] | ['-', Zeile] | ['+', Zeile] */
function wk_diff(array $a, array $b): array
{
    // gleiche Anfangs- und Endzeilen abtrennen, das spart Rechenzeit
    $pre = 0;
    while ($pre < count($a) && $pre < count($b) && $a[$pre] === $b[$pre]) $pre++;
    $suf = 0;
    while ($suf < count($a) - $pre && $suf < count($b) - $pre && $a[count($a) - 1 - $suf] === $b[count($b) - 1 - $suf]) $suf++;
    $ma = array_slice($a, $pre, count($a) - $pre - $suf);
    $mb = array_slice($b, $pre, count($b) - $pre - $suf);
    $out = [];
    for ($i = 0; $i < $pre; $i++) $out[] = ['=', $a[$i]];
    $n = count($ma); $m = count($mb);
    if ($n * $m > 2500000) {
        // zu groß für den genauen Vergleich: alles Alte entfernt, alles Neue hinzugefügt
        foreach ($ma as $l) $out[] = ['-', $l];
        foreach ($mb as $l) $out[] = ['+', $l];
    } else {
        $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $L[$i][$j] = $ma[$i] === $mb[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $i = 0; $j = 0;
        while ($i < $n && $j < $m) {
            if ($ma[$i] === $mb[$j]) { $out[] = ['=', $ma[$i]]; $i++; $j++; }
            elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $out[] = ['-', $ma[$i]]; $i++; }
            else { $out[] = ['+', $mb[$j]]; $j++; }
        }
        for (; $i < $n; $i++) $out[] = ['-', $ma[$i]];
        for (; $j < $m; $j++) $out[] = ['+', $mb[$j]];
    }
    for ($i = count($a) - $suf; $i < count($a); $i++) $out[] = ['=', $a[$i]];
    return $out;
}

// ---------------------------------------------------------------------------
// Versionen
// ---------------------------------------------------------------------------

function wk_version_add(int $cid, int $pageId, string $title, string $body, ?int $userId, string $note = '', ?string $at = null): int
{
    $no = (int)(row('SELECT MAX(version_no) AS m FROM wk_versions WHERE company_id = ? AND page_id = ?', [$cid, $pageId])['m'] ?? 0) + 1;
    insert('wk_versions', ['company_id' => $cid, 'page_id' => $pageId, 'version_no' => $no, 'title' => $title, 'body' => $body,
        'user_id' => $userId, 'note' => mb_substr($note, 0, 190), 'created_at' => $at ?? now()]);
    return $no;
}

/** Seiten aus Stufe 1 ohne Verlauf: alten Stand als Version 1 sichern */
function wk_version_ensure_initial(int $cid, array $page): void
{
    if (row('SELECT id FROM wk_versions WHERE company_id = ? AND page_id = ? LIMIT 1', [$cid, $page['id']])) return;
    wk_version_add($cid, (int)$page['id'], (string)$page['title'], (string)$page['body'], $page['updated_by'] !== null ? (int)$page['updated_by'] : null, '', (string)$page['updated_at']);
}

function wk_versions(int $cid, int $pageId): array
{
    return rows('SELECT v.id, v.version_no, v.title, v.note, v.user_id, v.created_at, LENGTH(v.body) AS size, u.name AS user_name
        FROM wk_versions v LEFT JOIN users u ON u.id = v.user_id WHERE v.company_id = ? AND v.page_id = ? ORDER BY v.version_no DESC', [$cid, $pageId]);
}

function wk_version(int $cid, int $pageId, int $no): ?array
{
    return row('SELECT * FROM wk_versions WHERE company_id = ? AND page_id = ? AND version_no = ?', [$cid, $pageId, $no]);
}

/** Seite mit Leserecht laden (für Verlauf, PDF usw.) */
function wk_require_page(int $cid, int $id, int $need = WK_PERM_VIEW): array
{
    $page = wk_page($cid, $id);
    if (!$page) abort(404);
    $space = wk_require_space($cid, (int)$page['space_id'], $need);
    return [$page, $space];
}

function page_doc_history(): void
{
    $cid = wk_company_id();
    [$page, $space] = wk_require_page($cid, query_int('id'));
    $versions = wk_versions($cid, (int)$page['id']);
    $canEdit = wk_space_perm($cid, (int)$space['id']) >= WK_PERM_EDIT;
    render_page(t('Verlauf') . ' · ' . $page['title'], function () use ($cid, $page, $space, $versions, $canEdit) {
        echo company_switcher($cid, 'docs');
        wk_tabs($cid, 'docs'); ?>
        <nav class="wk-crumbs"><a href="<?= e(wkurl('docs')) ?>"><?= e(t('Bereiche')) ?></a> ›
            <a href="<?= e(wkurl('doc_space', ['id' => $space['id']])) ?>"><?= e($space['name']) ?></a> ›
            <a href="<?= e(wkurl('doc', ['id' => $page['id']])) ?>"><?= e($page['title']) ?></a> › <span><?= e(t('Verlauf')) ?></span></nav>
        <div class="card">
            <h2><?= e(t('Verlauf von „{t}“', ['t' => $page['title']])) ?></h2>
            <?php if (!$versions): ?>
                <p class="muted"><?= e(t('Für diese Seite gibt es noch keine gespeicherten Versionen. Der Verlauf beginnt mit der nächsten Änderung.')) ?></p>
            <?php else: ?>
            <form method="get" class="wk-history">
                <input type="hidden" name="r" value="doc_diff"><input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
                <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
                <table class="table">
                    <thead><tr><th><?= e(t('Alt')) ?></th><th><?= e(t('Neu')) ?></th><th><?= e(t('Version')) ?></th><th><?= e(t('Gespeichert')) ?></th><th><?= e(t('Titel')) ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($versions as $k => $v): $current = $k === 0; ?>
                        <tr>
                            <td><input type="radio" name="a" value="<?= (int)$v['version_no'] ?>" <?= $k === 1 ? 'checked' : '' ?> aria-label="<?= e(t('Version {n} als alten Stand wählen', ['n' => $v['version_no']])) ?>"></td>
                            <td><input type="radio" name="b" value="<?= (int)$v['version_no'] ?>" <?= $current ? 'checked' : '' ?> aria-label="<?= e(t('Version {n} als neuen Stand wählen', ['n' => $v['version_no']])) ?>"></td>
                            <td><strong><?= (int)$v['version_no'] ?></strong><?php if ($current): ?> <span class="tag"><?= e(t('aktuell')) ?></span><?php endif; ?></td>
                            <td class="nowrap"><?= fmt_dt($v['created_at']) ?> · <?= e($v['user_name'] ?? '–') ?>
                                <?php if (str_starts_with($v['note'], 'restore:')): ?><br><span class="muted small"><?= e(t('Wiederhergestellt aus Version {n}', ['n' => (int)substr($v['note'], 8)])) ?></span><?php endif; ?></td>
                            <td><?= e($v['title']) ?></td>
                            <td class="nowrap"><a class="btn small" href="<?= e(wkurl('doc_version', ['id' => $page['id'], 'v' => $v['version_no']])) ?>"><?= e(t('Ansehen')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($versions) > 1): ?><button class="btn primary"><?= e(t('Ausgewählte Versionen vergleichen')) ?></button><?php endif; ?>
            </form>
            <?php endif; ?>
            <?php if ($canEdit): ?><p class="muted small"><?= e(t('Eine alte Version öffnen Sie mit „Ansehen“ und stellen sie dort wieder her. Dabei entsteht eine neue Version, der aktuelle Stand bleibt im Verlauf.')) ?></p><?php endif; ?>
        </div>
    <?php });
}

function page_doc_version(): void
{
    $cid = wk_company_id();
    [$page, $space] = wk_require_page($cid, query_int('id'));
    $v = wk_version($cid, (int)$page['id'], query_int('v'));
    if (!$v) abort(404);
    $latest = (int)(row('SELECT MAX(version_no) AS m FROM wk_versions WHERE company_id = ? AND page_id = ?', [$cid, $page['id']])['m'] ?? 0);
    $canEdit = wk_space_perm($cid, (int)$space['id']) >= WK_PERM_EDIT;
    $body = wk_render_body((string)$v['body']);
    render_page(t('Version {n}', ['n' => $v['version_no']]) . ' · ' . $page['title'], function () use ($cid, $page, $space, $v, $latest, $canEdit, $body) {
        echo company_switcher($cid, 'docs');
        wk_tabs($cid, 'docs'); ?>
        <nav class="wk-crumbs"><a href="<?= e(wkurl('docs')) ?>"><?= e(t('Bereiche')) ?></a> ›
            <a href="<?= e(wkurl('doc_space', ['id' => $space['id']])) ?>"><?= e($space['name']) ?></a> ›
            <a href="<?= e(wkurl('doc', ['id' => $page['id']])) ?>"><?= e($page['title']) ?></a> ›
            <a href="<?= e(wkurl('doc_history', ['id' => $page['id']])) ?>"><?= e(t('Verlauf')) ?></a> › <span><?= e(t('Version {n}', ['n' => $v['version_no']])) ?></span></nav>
        <div class="alert <?= (int)$v['version_no'] === $latest ? 'info' : 'warn' ?>">
            <?= e((int)$v['version_no'] === $latest ? t('Das ist die aktuelle Version.') : t('Sie sehen eine ältere Version vom {d}.', ['d' => fmt_dt($v['created_at'])])) ?>
            <div class="btn-row wk-mt">
                <?php if ((int)$v['version_no'] !== $latest): ?>
                    <a class="btn small" href="<?= e(wkurl('doc_diff', ['id' => $page['id'], 'a' => $v['version_no'], 'b' => $latest])) ?>"><?= e(t('Mit aktueller Version vergleichen')) ?></a>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(wkurl('doc_action')) ?>" data-confirm="<?= e(t('Diese Version wiederherstellen? Der aktuelle Stand bleibt im Verlauf erhalten.')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>"><input type="hidden" name="v" value="<?= (int)$v['version_no'] ?>">
                        <button class="btn small primary" name="action" value="restore"><?= e(t('Diese Version wiederherstellen')) ?></button></form>
                    <?php endif; ?>
                <?php endif; ?>
                <a class="btn small" href="<?= e(wkurl('doc_history', ['id' => $page['id']])) ?>"><?= e(t('Zurück zum Verlauf')) ?></a>
            </div>
        </div>
        <article class="card wk-article">
            <h1><?= e($v['title']) ?></h1>
            <p class="muted small"><?= e(t('Version {n}, gespeichert am {d} von {u}', ['n' => $v['version_no'], 'd' => fmt_dt($v['created_at']), 'u' => wk_user_name($v['user_id'] !== null ? (int)$v['user_id'] : null)])) ?></p>
            <div class="wk-body"><?= $body !== '' ? $body : '<p class="muted">' . e(t('Diese Seite ist noch leer.')) . '</p>' ?></div>
        </article>
    <?php });
}

function page_doc_diff(): void
{
    $cid = wk_company_id();
    [$page, $space] = wk_require_page($cid, query_int('id'));
    $a = wk_version($cid, (int)$page['id'], query_int('a'));
    $b = wk_version($cid, (int)$page['id'], query_int('b'));
    if (!$a || !$b) abort(404);
    if ((int)$a['version_no'] > (int)$b['version_no']) [$a, $b] = [$b, $a];
    $la = array_slice(wk_diff_lines((string)$a['body']), 0, WK_DIFF_MAX_LINES);
    $lb = array_slice(wk_diff_lines((string)$b['body']), 0, WK_DIFF_MAX_LINES);
    $diff = wk_diff($la, $lb);
    $added = count(array_filter($diff, fn($d) => $d[0] === '+'));
    $removed = count(array_filter($diff, fn($d) => $d[0] === '-'));
    render_page(t('Vergleich') . ' · ' . $page['title'], function () use ($cid, $page, $space, $a, $b, $diff, $added, $removed) {
        echo company_switcher($cid, 'docs');
        wk_tabs($cid, 'docs'); ?>
        <nav class="wk-crumbs"><a href="<?= e(wkurl('docs')) ?>"><?= e(t('Bereiche')) ?></a> ›
            <a href="<?= e(wkurl('doc_space', ['id' => $space['id']])) ?>"><?= e($space['name']) ?></a> ›
            <a href="<?= e(wkurl('doc', ['id' => $page['id']])) ?>"><?= e($page['title']) ?></a> ›
            <a href="<?= e(wkurl('doc_history', ['id' => $page['id']])) ?>"><?= e(t('Verlauf')) ?></a> › <span><?= e(t('Vergleich')) ?></span></nav>
        <div class="card">
            <h2><?= e(t('Version {a} → Version {b}', ['a' => $a['version_no'], 'b' => $b['version_no']])) ?></h2>
            <p class="muted small"><?= e(t('{a}: {d1} von {u1} · {b}: {d2} von {u2}', ['a' => t('Version {n}', ['n' => $a['version_no']]), 'd1' => fmt_dt($a['created_at']), 'u1' => wk_user_name($a['user_id'] !== null ? (int)$a['user_id'] : null),
                'b' => t('Version {n}', ['n' => $b['version_no']]), 'd2' => fmt_dt($b['created_at']), 'u2' => wk_user_name($b['user_id'] !== null ? (int)$b['user_id'] : null)])) ?></p>
            <?php if ($a['title'] !== $b['title']): ?>
                <p><?= e(t('Titel:')) ?> <del class="wk-del"><?= e($a['title']) ?></del> → <ins class="wk-ins"><?= e($b['title']) ?></ins></p>
            <?php endif; ?>
            <p><span class="wk-ins"><?= e(t('{n} Zeilen hinzugefügt', ['n' => $added])) ?></span> · <span class="wk-del-text"><?= e(t('{n} Zeilen entfernt', ['n' => $removed])) ?></span></p>
            <?php if (!$added && !$removed): ?><p class="muted"><?= e(t('Der Text ist in beiden Versionen gleich (Unterschiede höchstens in der Formatierung).')) ?></p><?php endif; ?>
            <div class="wk-diff">
                <?php $ctx = 3; $n = count($diff);
                $show = array_fill(0, $n, false);
                foreach ($diff as $i => $d) if ($d[0] !== '=') for ($k = max(0, $i - $ctx); $k <= min($n - 1, $i + $ctx); $k++) $show[$k] = true;
                $gap = false;
                foreach ($diff as $i => [$op, $line]):
                    if (!$show[$i]) { if (!$gap) { echo '<div class="wk-diff-gap">…</div>'; $gap = true; } continue; }
                    $gap = false; ?>
                    <div class="wk-diff-line <?= $op === '+' ? 'add' : ($op === '-' ? 'del' : '') ?>"><span class="wk-diff-op"><?= $op === '=' ? '&nbsp;' : e($op) ?></span><?= e($line) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Vorlagen
// ---------------------------------------------------------------------------

/** Eingebaute Vorlagen (in der Sprache des Benutzers): [key => [name, description, body]] */
function wk_builtin_templates(): array
{
    $p = fn(string $s) => '<p>' . e($s) . '</p>';
    $h = fn(string $s, int $l = 2) => "<h$l>" . e($s) . "</h$l>";
    $li = fn(array $items, string $tag = 'ul') => "<$tag>" . implode('', array_map(fn($i) => '<li>' . e($i) . '</li>', $items)) . "</$tag>";
    $box = fn(string $cls, string $s) => '<div class="' . $cls . '"><p>' . e($s) . '</p></div>';
    $toc = '<div class="wk-toc">' . e(t('Inhaltsverzeichnis')) . '</div>';
    $tbl = function (array $head, int $rows) {
        $h = '<table class="tbl-grid"><thead><tr>' . implode('', array_map(fn($c) => '<th>' . e($c) . '</th>', $head)) . '</tr></thead><tbody>';
        for ($i = 0; $i < $rows; $i++) $h .= '<tr>' . str_repeat('<td><br></td>', count($head)) . '</tr>';
        return $h . '</tbody></table>';
    };
    return [
        'anleitung' => [t('Anleitung'), t('Schritt für Schritt erklären, wie etwas geht'),
            $box('wk-info', t('Worum geht es und für wen ist diese Anleitung?'))
            . $h(t('Voraussetzungen')) . $li([t('Was wird benötigt?'), t('Welche Rechte oder Zugänge?')])
            . $h(t('Schritte')) . $li([t('Erster Schritt'), t('Zweiter Schritt'), t('Dritter Schritt')], 'ol')
            . $box('wk-warn', t('Worauf muss man besonders achten?'))
            . $h(t('Fehlerbehebung')) . $p(t('Häufige Probleme und ihre Lösung.'))
            . $h(t('Ansprechpartner')) . $p(t('Wer hilft bei Fragen?'))],
        'richtlinie' => [t('Richtlinie'), t('Regeln mit Geltungsbereich, Verantwortung und Gültigkeit'),
            $toc . $h(t('Zweck')) . $p(t('Warum gibt es diese Richtlinie?'))
            . $h(t('Geltungsbereich')) . $p(t('Für wen und für welche Systeme gilt sie?'))
            . $h(t('Regelungen')) . $li([t('Regel 1'), t('Regel 2'), t('Regel 3')], 'ol')
            . $h(t('Verantwortlichkeiten')) . $tbl([t('Rolle'), t('Aufgabe')], 3)
            . $h(t('Verstöße')) . $p(t('Was passiert bei Verstößen?'))
            . $h(t('Gültigkeit')) . $tbl([t('Version'), t('Gültig ab'), t('Freigegeben von')], 1)],
        'besprechung' => [t('Besprechungsnotiz'), t('Teilnehmer, Themen, Entscheidungen und Aufgaben'),
            $tbl([t('Datum'), t('Ort'), t('Teilnehmer')], 1)
            . $h(t('Themen')) . $li([t('Thema 1'), t('Thema 2')], 'ol')
            . $h(t('Entscheidungen')) . $li([t('Was wurde beschlossen?')])
            . $h(t('Aufgaben')) . $tbl([t('Aufgabe'), t('Verantwortlich'), t('Bis'), t('Status')], 3)],
        'notfall' => [t('Notfallhandbuch'), t('Was im IT-Notfall zu tun ist, mit Kontakten und Wiederanlauf'),
            $box('wk-warn', t('Im Notfall: Ruhe bewahren, nichts ausschalten, sofort die Notfallnummer anrufen.'))
            . $toc . $h(t('Notfallkontakte')) . $tbl([t('Rolle'), t('Name'), t('Telefon'), t('Erreichbarkeit')], 4)
            . $h(t('Meldeweg')) . $li([t('Wer meldet an wen?'), t('Welche Angaben werden gebraucht?')], 'ol')
            . $h(t('Sofortmaßnahmen')) . $h(t('Ransomware / Verschlüsselung'), 3) . $li([t('Betroffene Geräte vom Netzwerk trennen (Kabel ziehen, WLAN aus)'), t('Nicht ausschalten'), t('IT-Dienstleister informieren')], 'ol')
            . $h(t('Ausfall Internet oder Server'), 3) . $li([t('Störung prüfen und melden'), t('Ersatzlösung nutzen')], 'ol')
            . $h(t('Wiederanlauf')) . $tbl([t('System'), t('Priorität'), t('Max. Ausfallzeit'), t('Zuständig')], 4)
            . $h(t('Nach dem Notfall')) . $li([t('Ursache dokumentieren'), t('Meldepflichten prüfen (z. B. Datenschutz, 72 Stunden)'), t('Maßnahmen ableiten')])],
        'netzwerk' => [t('Netzwerkdokumentation'), t('Netze, Geräte, Internetanschluss und Zuständigkeiten'),
            $box('wk-info', t('Zugangsdaten gehören nicht hierher, sondern in den Passwort-Tresor.'))
            . $h(t('Internetanschluss')) . $tbl([t('Anbieter'), t('Anschluss'), t('Feste IP'), t('Hotline')], 1)
            . $h(t('Netze und VLANs')) . $tbl([t('Name'), t('VLAN'), t('Netz'), t('Gateway'), t('DHCP'), t('Zweck')], 4)
            . $h(t('Netzwerkgeräte')) . $tbl([t('Gerät'), t('Modell'), t('IP-Adresse'), t('Standort'), t('Firmware')], 4)
            . $h(t('WLAN')) . $tbl([t('SSID'), t('Netz'), t('Verschlüsselung'), t('Zweck')], 2)
            . $h(t('Netzwerkplan')) . $p(t('Plan als Bild einfügen.'))],
    ];
}

/** Vorlage nach Schlüssel „b:anleitung“ oder „c:12“; null, wenn unbekannt */
function wk_template_body(int $cid, string $key): ?array
{
    if (str_starts_with($key, 'b:')) {
        $all = wk_builtin_templates();
        $k = substr($key, 2);
        return isset($all[$k]) ? ['name' => $all[$k][0], 'body' => $all[$k][2]] : null;
    }
    if (str_starts_with($key, 'c:')) {
        $t = row('SELECT name, body FROM wk_templates WHERE id = ? AND company_id = ?', [(int)substr($key, 2), $cid]);
        return $t ?: null;
    }
    return null;
}

/** Auswahl der Vorlage beim Anlegen einer Seite */
function wk_template_chooser(int $cid, array $space, int $parent): void
{
    $own = rows('SELECT id, name, description FROM wk_templates WHERE company_id = ? ORDER BY name', [$cid]);
    $base = ['space' => $space['id']] + ($parent ? ['parent' => $parent] : []);
    render_page(t('Neue Seite') . ' · ' . $space['name'], function () use ($cid, $space, $own, $base) { ?>
        <nav class="wk-crumbs"><a href="<?= e(wkurl('docs')) ?>"><?= e(t('Bereiche')) ?></a> ›
            <a href="<?= e(wkurl('doc_space', ['id' => $space['id']])) ?>"><?= e($space['name']) ?></a> › <span><?= e(t('Neue Seite')) ?></span></nav>
        <div class="card">
            <h2><?= e(t('Womit möchten Sie beginnen?')) ?></h2>
            <div class="wk-spaces">
                <a class="wk-space-tile" href="<?= e(wkurl('doc_edit', $base + ['tpl' => 'leer'])) ?>"><span class="wk-icon big">📄</span><strong><?= e(t('Leere Seite')) ?></strong></a>
                <?php foreach ($own as $tp): ?>
                    <a class="wk-space-tile" href="<?= e(wkurl('doc_edit', $base + ['tpl' => 'c:' . $tp['id']])) ?>"><span class="wk-icon big">⭐</span><strong><?= e($tp['name']) ?></strong>
                        <?php if ($tp['description'] !== ''): ?><span class="muted small"><?= e($tp['description']) ?></span><?php endif; ?><span class="muted small"><?= e(t('eigene Vorlage')) ?></span></a>
                <?php endforeach; ?>
                <?php foreach (wk_builtin_templates() as $k => [$name, $desc]): ?>
                    <a class="wk-space-tile" href="<?= e(wkurl('doc_edit', $base + ['tpl' => 'b:' . $k])) ?>"><span class="wk-icon big">📋</span><strong><?= e($name) ?></strong><span class="muted small"><?= e($desc) ?></span></a>
                <?php endforeach; ?>
            </div>
            <?php if (wk_can_manage($cid)): ?><p class="muted small"><?= e(t('Eigene Vorlagen pflegen Sie im Reiter „Vorlagen“.')) ?> <a href="<?= e(wkurl('doc_templates')) ?>"><?= e(t('Zu den Vorlagen')) ?></a></p><?php endif; ?>
        </div>
    <?php });
}

function page_doc_templates(): void
{
    $cid = wk_company_id();
    if (!wk_can_manage($cid)) abort(403, t('Vorlagen pflegen die Administratoren der Firma.'));
    $list = rows('SELECT t.*, u.name AS user_name FROM wk_templates t LEFT JOIN users u ON u.id = t.created_by WHERE t.company_id = ? ORDER BY t.name', [$cid]);
    render_page(t('Vorlagen') . ' · ' . t('Dokumente & Anleitungen'), function () use ($cid, $list) {
        echo company_switcher($cid, 'doc_templates');
        wk_tabs($cid, 'doc_templates'); ?>
        <div class="toolbar"><a class="btn primary" href="<?= e(wkurl('doc_template_edit')) ?>"><?= e(t('+ Vorlage')) ?></a></div>
        <div class="card">
            <h2><?= e(t('Eigene Vorlagen')) ?></h2>
            <?php if (!$list): ?><p class="muted"><?= e(t('Noch keine eigenen Vorlagen. Legen Sie eine an oder speichern Sie eine vorhandene Seite mit „Als Vorlage speichern“.')) ?></p>
            <?php else: ?>
            <table class="table"><thead><tr><th><?= e(t('Name')) ?></th><th><?= e(t('Beschreibung')) ?></th><th><?= e(t('Geändert')) ?></th></tr></thead><tbody>
                <?php foreach ($list as $tp): ?><tr><td><a href="<?= e(wkurl('doc_template_edit', ['id' => $tp['id']])) ?>"><?= e($tp['name']) ?></a></td>
                    <td><?= e($tp['description'] ?: '–') ?></td><td class="nowrap"><?= fmt_dt($tp['updated_at']) ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </div>
        <div class="card">
            <h2><?= e(t('Eingebaute Vorlagen')) ?></h2>
            <p class="muted small"><?= e(t('Diese Vorlagen stehen allen Firmen zur Verfügung. Zum Anpassen legen Sie eine Seite damit an und speichern sie als eigene Vorlage.')) ?></p>
            <ul class="wk-list"><?php foreach (wk_builtin_templates() as [$name, $desc]): ?><li><strong><?= e($name) ?></strong> – <span class="muted"><?= e($desc) ?></span></li><?php endforeach; ?></ul>
        </div>
    <?php });
}

function page_doc_template_edit(): void
{
    $cid = wk_company_id();
    if (!wk_can_manage($cid)) abort(403, t('Vorlagen pflegen die Administratoren der Firma.'));
    $id = query_int('id');
    $tp = $id ? row('SELECT * FROM wk_templates WHERE id = ? AND company_id = ?', [$id, $cid]) : null;
    if ($id && !$tp) abort(404);
    $name = $tp['name'] ?? '';
    $desc = $tp['description'] ?? '';
    $body = (string)($tp['body'] ?? '');
    $err = '';
    if (is_post()) {
        require_post_csrf();
        if (post('action') === 'delete') {
            if (!$tp) abort(404);
            q('DELETE FROM wk_templates WHERE id = ? AND company_id = ?', [$tp['id'], $cid]);
            wk_log($cid, 'template_delete', '#' . $tp['id'] . ' ' . $tp['name']);
            flash('success', t('Die Vorlage wurde gelöscht.'));
            wk_redirect('doc_templates');
        }
        $name = mb_substr(trim((string)post('name')), 0, 120);
        $desc = mb_substr(trim((string)post('description')), 0, 255);
        $raw = (string)post('body');
        if ($name === '') $err = t('Bitte einen Namen angeben.');
        elseif (strlen($raw) > WK_BODY_MAX) $err = t('Die Seite ist zu groß (höchstens {n} MB inklusive Bilder). Bitte Bilder verkleinern oder auf mehrere Seiten verteilen.', ['n' => WK_BODY_MAX / 1048576]);
        $body = sanitize_html(substr($raw, 0, WK_BODY_MAX));
        if ($err === '') {
            $data = ['name' => $name, 'description' => $desc, 'body' => $body, 'updated_at' => now()];
            if ($tp) update('wk_templates', (int)$tp['id'], $data);
            else $id = insert('wk_templates', $data + ['company_id' => $cid, 'created_by' => current_user()['id'], 'created_at' => now()]);
            wk_log($cid, $tp ? 'template_update' : 'template_create', '#' . ($tp['id'] ?? $id) . ' ' . $name);
            flash('success', t('Die Vorlage wurde gespeichert.'));
            wk_redirect('doc_templates');
        }
    }
    $heading = $tp ? t('Vorlage bearbeiten') : t('Neue Vorlage');
    render_page($heading . ' · ' . t('Dokumente & Anleitungen'), function () use ($cid, $tp, $name, $desc, $body, $err, $heading) {
        echo company_switcher($cid, 'doc_templates');
        wk_tabs($cid, 'doc_templates');
        if ($err !== ''): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
        <form method="post" class="form card wk-edit" id="wk-form">
            <?= csrf_field() ?>
            <h2><?= e($heading) ?></h2>
            <div class="wk-edit-head">
                <label class="grow"><?= e(t('Name')) ?> <input name="name" required maxlength="120" value="<?= e($name) ?>"></label>
                <label class="grow"><?= e(t('Beschreibung')) ?> <input name="description" maxlength="255" value="<?= e($desc) ?>"></label>
            </div>
            <?php wk_editor($body); ?>
            <div class="btn-row">
                <button class="btn primary"><?= e(t('Speichern')) ?></button>
                <a class="btn" href="<?= e(wkurl('doc_templates')) ?>"><?= e(t('Abbrechen')) ?></a>
            </div>
        </form>
        <?php if ($tp): ?>
        <form method="post" class="card danger-zone" data-confirm="<?= e(t('Diese Vorlage wirklich löschen? Seiten, die damit angelegt wurden, bleiben erhalten.')) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <button class="btn danger"><?= e(t('Vorlage löschen')) ?></button>
        </form>
        <?php endif;
    });
}

// ---------------------------------------------------------------------------
// Kommentare, Erwähnungen und Beobachten
// ---------------------------------------------------------------------------

/** Benutzer der Firma, die den Bereich lesen dürfen (für Erwähnungen) */
function wk_space_readers(int $cid, int $spaceId): array
{
    $out = [];
    foreach (rows("SELECT id, name, email, role, company_id, lang FROM users WHERE company_id = ? AND active = 1 ORDER BY name", [$cid]) as $u) {
        if ((wk_space_perms($cid, $u)[$spaceId] ?? 0) > WK_PERM_NONE) $out[(int)$u['id']] = $u;
    }
    return $out;
}

/** Erwähnte Personen (@Vorname Nachname) aus einem Kommentar, längste Namen zuerst */
function wk_mentions(string $text, array $readers): array
{
    $found = [];
    $cands = $readers;
    uasort($cands, fn($a, $b) => mb_strlen($b['name']) <=> mb_strlen($a['name']));
    $lower = mb_strtolower($text);
    foreach ($cands as $id => $u) {
        $n = mb_strtolower(trim((string)$u['name']));
        if ($n === '') continue;
        if (preg_match('/(^|[^\p{L}\p{N}])@' . preg_quote($n, '/') . '(?![\p{L}\p{N}])/u', $lower)) $found[$id] = $u;
    }
    return $found;
}

/** Kommentartext mit hervorgehobenen Erwähnungen (bereits maskiert) */
function wk_comment_html(string $text, array $readers): string
{
    $html = nl2br(e($text));
    foreach (wk_mentions($text, $readers) as $u) {
        $html = preg_replace('/@' . preg_quote(e($u['name']), '/') . '(?![\p{L}\p{N}])/iu', '<strong class="wk-mention">@' . e($u['name']) . '</strong>', $html);
    }
    return $html;
}

function wk_is_watching(int $cid, int $pageId, int $userId): bool
{
    return (bool)row('SELECT id FROM wk_watch WHERE company_id = ? AND page_id = ? AND user_id = ?', [$cid, $pageId, $userId]);
}

function wk_watch_set(int $cid, int $pageId, int $userId, bool $on): void
{
    if ($on && !wk_is_watching($cid, $pageId, $userId)) insert('wk_watch', ['company_id' => $cid, 'page_id' => $pageId, 'user_id' => $userId, 'created_at' => now(), 'notified_at' => '']);
    if (!$on) q('DELETE FROM wk_watch WHERE company_id = ? AND page_id = ? AND user_id = ?', [$cid, $pageId, $userId]);
}

function wk_page_link(int $pageId, int $cid): string
{
    return rtrim((string)cfg('base_url', ''), '/') . '/index.php?' . http_build_query(['r' => 'doc', 'id' => $pageId]);
}

/**
 * Benachrichtigt Beobachter (und bei Kommentaren Erwähnte). $kind: 'change' | 'comment'.
 * Versand nach der Antwort an den Browser; nur an Personen, die die Seite (noch) lesen dürfen.
 */
function wk_notify(int $cid, array $page, string $kind, string $text = '', array $mentioned = []): void
{
    $actor = current_user();
    $pageId = (int)$page['id'];
    $spaceId = (int)$page['space_id'];
    $quietBefore = date('Y-m-d H:i:s', time() - WK_WATCH_QUIET_MINUTES * 60);
    $targets = [];
    foreach (rows('SELECT w.id AS watch_id, w.notified_at, u.* FROM wk_watch w JOIN users u ON u.id = w.user_id WHERE w.company_id = ? AND w.page_id = ? AND u.active = 1', [$cid, $pageId]) as $u) {
        if ((int)$u['id'] === (int)$actor['id']) continue;
        if ((wk_space_perms($cid, $u)[$spaceId] ?? 0) === WK_PERM_NONE) continue;
        if ($kind === 'change' && $u['notified_at'] !== '' && $u['notified_at'] > $quietBefore) continue;
        $targets[(int)$u['id']] = ['u' => $u, 'why' => 'watch'];
    }
    foreach ($mentioned as $id => $u) {
        if ((int)$id === (int)$actor['id']) continue;
        $targets[(int)$id] = ['u' => $u, 'why' => 'mention'];
    }
    if (!$targets) return;
    foreach ($targets as $id => $tg) if ($tg['why'] === 'watch') q('UPDATE wk_watch SET notified_at = ? WHERE company_id = ? AND page_id = ? AND user_id = ?', [now(), $cid, $pageId, $id]);
    $link = wk_page_link($pageId, $cid);
    $title = (string)$page['title'];
    $who = (string)$actor['name'];
    register_shutdown_function(function () use ($targets, $kind, $text, $link, $title, $who, $cid) {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        foreach ($targets as $tg) {
            $u = $tg['u'];
            try {
                with_lang(user_lang($u), function () use ($u, $tg, $kind, $text, $link, $title, $who, $cid) {
                    if ($tg['why'] === 'mention') {
                        $subject = t('{n} hat Sie erwähnt: {t}', ['n' => $who, 't' => $title]);
                        $body = t('{n} hat Sie in einem Kommentar zur Seite „{t}“ erwähnt:', ['n' => $who, 't' => $title]) . "\n\n" . $text;
                    } elseif ($kind === 'comment') {
                        $subject = t('Neuer Kommentar: {t}', ['t' => $title]);
                        $body = t('{n} hat die Seite „{t}“ kommentiert:', ['n' => $who, 't' => $title]) . "\n\n" . $text;
                    } else {
                        $subject = t('Seite geändert: {t}', ['t' => $title]);
                        $body = t('{n} hat die Seite „{t}“ geändert.', ['n' => $who, 't' => $title]) . ($text !== '' ? "\n" . $text : '');
                    }
                    $body = t('Guten Tag {n},', ['n' => $u['name']]) . "\n\n" . $body . "\n\n" . t('Zur Seite:') . ' ' . $link;
                    if ($tg['why'] === 'watch') $body .= "\n\n" . t('Sie erhalten diese Nachricht, weil Sie die Seite beobachten. Abbestellen: auf der Seite „Nicht mehr beobachten“ wählen.');
                    send_mail([(string)$u['email']], $subject, $body, $cid);
                });
            } catch (Throwable $ex) {
                error_log('[kundenportal] Wiki-Benachrichtigung: ' . $ex->getMessage());
            }
        }
    });
}

/** Kommentare unter einer Seite */
function wk_comments_card(int $cid, array $page, array $space): void
{
    $comments = rows('SELECT c.*, u.name AS user_name FROM wk_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.company_id = ? AND c.page_id = ? ORDER BY c.id', [$cid, $page['id']]);
    $readers = wk_space_readers($cid, (int)$space['id']);
    $me = current_user();
    $manage = wk_can_manage($cid); ?>
    <div class="card wk-comments" id="kommentare">
        <h2><?= e(t('Kommentare')) ?> <span class="muted small">(<?= count($comments) ?>)</span></h2>
        <?php foreach ($comments as $c): ?>
            <div class="wk-comment" id="kommentar-<?= (int)$c['id'] ?>">
                <div class="wk-comment-head"><strong><?= e($c['user_name'] ?? '–') ?></strong> <span class="muted small"><?= fmt_dt($c['created_at']) ?></span>
                    <?php if ((int)$c['user_id'] === (int)$me['id'] || $manage): ?>
                    <form method="post" action="<?= e(wkurl('doc_action')) ?>" class="inline" data-confirm="<?= e(t('Diesen Kommentar löschen?')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>"><input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                        <button class="btn-link small" name="action" value="comment_delete"><?= e(t('löschen')) ?></button></form>
                    <?php endif; ?></div>
                <div class="wk-comment-body"><?= wk_comment_html((string)$c['body'], $readers) ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (!$comments): ?><p class="muted small"><?= e(t('Noch keine Kommentare.')) ?></p><?php endif; ?>
        <form method="post" action="<?= e(wkurl('doc_action')) ?>" class="form wk-comment-form">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>"><input type="hidden" name="action" value="comment_add">
            <label><?= e(t('Kommentar schreiben')) ?>
                <textarea name="body" rows="3" maxlength="5000" required id="wk-comment-text"
                    data-names="<?= e(json_encode(array_values(array_map(fn($u) => $u['name'], array_filter($readers, fn($u) => (int)$u['id'] !== (int)$me['id']))), JSON_UNESCAPED_UNICODE)) ?>"></textarea></label>
            <div class="wk-mention-list" id="wk-mention-list" hidden role="listbox"></div>
            <p class="muted small"><?= e(t('Mit @Name erwähnen Sie jemanden, der die Seite lesen darf. Er bekommt eine E-Mail.')) ?></p>
            <button class="btn primary"><?= e(t('Kommentieren')) ?></button>
        </form>
    </div>
<?php }

/** POST-Aktionen der Stufe 2 aus page_doc_action(); true = erledigt (Weiterleitung erfolgt) */
function wk_action_more(int $cid, array $page, string $action): bool
{
    $sid = (int)$page['space_id'];
    $perm = wk_space_perm($cid, $sid);
    $me = current_user();
    switch ($action) {
        case 'watch':
        case 'unwatch':
            wk_watch_set($cid, (int)$page['id'], (int)$me['id'], $action === 'watch');
            flash('success', $action === 'watch' ? t('Sie beobachten diese Seite und bekommen eine E-Mail bei Änderungen und Kommentaren.') : t('Sie beobachten diese Seite nicht mehr.'));
            wk_redirect('doc', ['id' => $page['id']]);
        case 'comment_add':
            $body = trim(str_replace("\r", '', mb_substr((string)post('body'), 0, 5000)));
            if ($body === '') { flash('error', t('Bitte einen Kommentar eingeben.')); wk_redirect('doc', ['id' => $page['id']]); }
            $cmId = insert('wk_comments', ['company_id' => $cid, 'page_id' => $page['id'], 'user_id' => $me['id'], 'body' => $body, 'created_at' => now()]);
            wk_log($cid, 'comment_add', '#' . $page['id'] . ' ' . $page['title']);
            $mentioned = wk_mentions($body, wk_space_readers($cid, $sid));
            wk_notify($cid, $page, 'comment', $body, $mentioned);
            flash('success', $mentioned ? t('Der Kommentar wurde gespeichert. Erwähnt: {n}', ['n' => implode(', ', array_map(fn($u) => $u['name'], $mentioned))]) : t('Der Kommentar wurde gespeichert.'));
            header('Location: ' . wkurl('doc', ['id' => $page['id']]) . '#kommentar-' . $cmId);
            exit;
        case 'comment_delete':
            $c = row('SELECT * FROM wk_comments WHERE id = ? AND page_id = ? AND company_id = ?', [(int)post('comment_id', 0), $page['id'], $cid]);
            if (!$c) abort(404);
            if ((int)$c['user_id'] !== (int)$me['id'] && !wk_can_manage($cid)) abort(403);
            q('DELETE FROM wk_comments WHERE id = ? AND company_id = ?', [$c['id'], $cid]);
            wk_log($cid, 'comment_delete', '#' . $page['id'] . ' ' . $page['title']);
            flash('success', t('Der Kommentar wurde gelöscht.'));
            header('Location: ' . wkurl('doc', ['id' => $page['id']]) . '#kommentare');
            exit;
        case 'restore':
            if ($perm < WK_PERM_EDIT) abort(403);
            $v = wk_version($cid, (int)$page['id'], (int)post('v', 0));
            if (!$v) abort(404);
            wk_version_ensure_initial($cid, $page);
            update('wk_pages', (int)$page['id'], ['title' => $v['title'], 'body' => $v['body'], 'updated_by' => $me['id'], 'updated_at' => now()]);
            wk_version_add($cid, (int)$page['id'], (string)$v['title'], (string)$v['body'], (int)$me['id'], 'restore:' . (int)$v['version_no']);
            wk_log($cid, 'page_restore', '#' . $page['id'] . ' v' . $v['version_no']);
            wk_notify($cid, ['title' => $v['title']] + $page, 'change', t('Version {n} wurde wiederhergestellt.', ['n' => $v['version_no']]));
            flash('success', t('Version {n} wurde wiederhergestellt.', ['n' => $v['version_no']]));
            wk_redirect('doc', ['id' => $page['id']]);
        case 'save_template':
            if (!wk_can_manage($cid)) abort(403);
            $tid = insert('wk_templates', ['company_id' => $cid, 'name' => mb_substr((string)$page['title'], 0, 120), 'description' => '', 'body' => (string)$page['body'],
                'created_by' => $me['id'], 'created_at' => now(), 'updated_at' => now()]);
            wk_log($cid, 'template_create', '#' . $tid . ' ' . $page['title']);
            flash('success', t('Die Seite wurde als Vorlage gespeichert. Passen Sie Name und Inhalt bei Bedarf an.'));
            wk_redirect('doc_template_edit', ['id' => $tid]);
    }
    return false;
}

/** Löscht alles, was an einer Seite hängt */
function wk_page_cleanup(int $cid, int $pageId): void
{
    foreach (['wk_versions', 'wk_comments', 'wk_watch'] as $tbl) q("DELETE FROM $tbl WHERE company_id = ? AND page_id = ?", [$cid, $pageId]);
}

/** Nach dem Speichern einer Seite: Version anlegen, Ersteller beobachtet, Beobachter benachrichtigen */
function wk_after_save(int $cid, ?array $old, int $pageId, string $title, string $body): void
{
    $me = current_user();
    if ($old === null) {
        wk_version_add($cid, $pageId, $title, $body, (int)$me['id']);
        wk_watch_set($cid, $pageId, (int)$me['id'], true);
        return;
    }
    if ($old['title'] === $title && (string)$old['body'] === $body) return;
    wk_version_ensure_initial($cid, $old);
    wk_version_add($cid, $pageId, $title, $body, (int)$me['id']);
    $diff = wk_diff(array_slice(wk_diff_lines((string)$old['body']), 0, WK_DIFF_MAX_LINES), array_slice(wk_diff_lines($body), 0, WK_DIFF_MAX_LINES));
    $add = count(array_filter($diff, fn($d) => $d[0] === '+'));
    $del = count(array_filter($diff, fn($d) => $d[0] === '-'));
    $page = wk_page($cid, $pageId);
    if ($page) wk_notify($cid, $page, 'change', t('{a} Zeilen hinzugefügt, {d} Zeilen entfernt.', ['a' => $add, 'd' => $del]));
}

// ---------------------------------------------------------------------------
// PDF-Export (Seite oder Seite mit allen Unterseiten)
// ---------------------------------------------------------------------------

function wk_pdf_page(SimplePdf $pdf, array $page, int $level): void
{
    $m = $pdf->margin;
    $cw = $pdf->w - 2 * $m;
    $pdf->ensure(60);
    $pdf->paragraph((string)$page['title'], $level === 0 ? 18 : 15, true, 4);
    $pdf->paragraph(t('Zuletzt geändert am {d} von {n}', ['d' => fmt_dt($page['updated_at']), 'n' => wk_user_name($page['updated_by'] !== null ? (int)$page['updated_by'] : null)]), 8, false, 10);
    $boxColors = ['wk-info' => [59, 125, 216], 'wk-note' => [124, 92, 196], 'wk-warn' => [212, 138, 0], 'wk-ok' => [47, 158, 79]];
    $heads = [];
    $blocks = wk_blocks((string)$page['body']);
    foreach ($blocks as $b) if ($b['type'] === 'h') $heads[] = $b;
    foreach ($blocks as $b) {
        $indent = $b['box'] !== '' ? 12 : 0;
        $startY = $pdf->y;
        switch ($b['type']) {
            case 'h':
                $pdf->ensure(40);
                $pdf->y -= 6;
                $pdf->paragraph($b['text'], [2 => 13.5, 3 => 12, 4 => 11][$b['level']] ?? 11, true, 4, $indent);
                break;
            case 'p':
                $pdf->paragraph($b['text'], 10, false, 6, $indent + (!empty($b['quote']) ? 12 : 0));
                break;
            case 'li':
                $pdf->paragraph($b['text'], 10, false, 2, $indent + 14 + 14 * $b['depth'], $b['marker']);
                break;
            case 'table':
                $cols = [];
                $first = $b['rows'][0];
                $n = max(array_map('count', $b['rows']));
                for ($i = 0; $i < $n; $i++) $cols[] = ['label' => $b['head'] ? ($first[$i] ?? '') : '', 'w' => 1, 'wrap' => true];
                $pdf->y -= 2;
                $pdf->table($cols, $b['head'] ? array_slice($b['rows'], 1) : $b['rows'], 8.5);
                $pdf->y -= 6;
                break;
            case 'img':
                if (!preg_match('#^data:image/[a-z]+;base64,(.+)$#s', (string)$b['src'], $mm)) break;
                $jpg = SimplePdf::toJpeg((string)base64_decode($mm[1]));
                if (!$jpg) break;
                [$data, $iw, $ih] = $jpg;
                $w = min($cw - $indent, $iw * 0.6);
                $h = $w * $ih / max(1, $iw);
                if ($h > $pdf->h - 2 * $m - 40) { $h = $pdf->h - 2 * $m - 40; $w = $h * $iw / max(1, $ih); }
                $pdf->ensure($h + 8);
                $pdf->imageJpeg($data, $m + $indent, $pdf->y - $h, $w, $h);
                $pdf->y -= $h + 8;
                break;
            case 'hr':
                $pdf->ensure(12);
                $pdf->line($m, $pdf->y - 4, $m + $cw, $pdf->y - 4);
                $pdf->y -= 12;
                break;
            case 'toc':
                if (!$heads) break;
                $pdf->paragraph(t('Inhalt'), 10, true, 2);
                foreach ($heads as $h) $pdf->paragraph($h['text'], 9.5, false, 1, 10 + 12 * ($h['level'] - 2));
                $pdf->y -= 6;
                break;
        }
        // farbiger Balken links neben Infoboxen (auf derselben Seite)
        if ($b['box'] !== '' && $pdf->y < $startY) $pdf->line($m + 3, $startY, $m + 3, $pdf->y + 4, 2.5, $boxColors[$b['box']] ?? [150, 150, 150]);
    }
}

function page_doc_pdf(): void
{
    $cid = wk_company_id();
    [$page, $space] = wk_require_page($cid, query_int('id'));
    $tree = query_int('tree') === 1;
    $co = wk_company($cid);
    $list = [[$page, 0]];
    if ($tree) {
        $map = wk_children_map($cid, (int)$space['id']);
        $walk = function (int $parent, int $level) use (&$walk, &$list, $map, $cid) {
            foreach ($map[$parent] ?? [] as $c) {
                if (count($list) >= WK_PDF_MAX_PAGES || $level > WK_MAX_DEPTH) return;
                $full = wk_page($cid, (int)$c['id']);
                if ($full) $list[] = [$full, $level];
                $walk((int)$c['id'], $level + 1);
            }
        };
        $walk((int)$page['id'], 1);
    }
    $pdf = new SimplePdf(false, ($co['name'] ?? '') . ' · ' . $space['name']);
    foreach ($list as $i => [$p, $level]) {
        if ($i > 0) $pdf->addPage();
        wk_pdf_page($pdf, $p, $level);
    }
    $data = $pdf->output((string)$page['title']);
    wk_log($cid, 'pdf', '#' . $page['id'] . ($tree ? ' +' . (count($list) - 1) : ''));
    $fn = preg_replace('/[^A-Za-z0-9._-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$page['title']) ?: 'seite');
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($data));
    header('Content-Disposition: attachment; filename="' . trim($fn, '-') . '.pdf"');
    header('Cache-Control: private, no-store');
    echo $data;
    exit;
}
