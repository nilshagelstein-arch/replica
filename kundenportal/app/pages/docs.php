<?php
/**
 * App „Dokumente & Anleitungen“ – Stufe 1: Wiki-Grundgerüst (Stufe 2 in docs_more.php)
 *
 * - Je Firma getrennt: Bereiche (z. B. je Abteilung) mit einem beliebig tiefen Seitenbaum.
 * - Rechte je Bereich über Gruppen (Abteilungen): „ansehen“ oder „bearbeiten“; dazu der Schalter
 *   „alle Mitarbeiter dürfen lesen“. Firmen-Admins dürfen alles.
 * - Der Dienstleister (Superadmin, Verwaltungsadmins) sieht das Wiki nur mit Freigabe der Firma
 *   (companies.wk_provider_access) und dann nach seinem Bereichsrecht „docs“ (eingeschränkte Admins nur für ihre Firmen).
 * - Seiteninhalte kommen aus dem Editor (assets/wiki.js) und laufen durch sanitize_html() aus forms_lib.php.
 *   Inhaltsverzeichnis (div.wk-toc) und Sprungmarken der Überschriften entstehen erst bei der Anzeige.
 */

declare(strict_types=1);

const WK_APP = 'dokumente';
const WK_PERM_NONE = 0;
const WK_PERM_VIEW = 1;
const WK_PERM_EDIT = 2;
/** Höchstgröße einer Seite (Text und eingefügte Bilder) */
const WK_BODY_MAX = 6 * 1024 * 1024;
/** Höchste Verschachtelung im Seitenbaum (Schutz vor Endlosschleifen bei kaputten Daten) */
const WK_MAX_DEPTH = 30;

// ---------------------------------------------------------------------------
// Zugriff und Rechte
// ---------------------------------------------------------------------------

/** Firma aus der Anfrage, mit App-Freigabe und Zugriffsprüfung */
function wk_company_id(): int
{
    $u = require_app(WK_APP);
    // Seiten des Dienstleisters für alle Kunden (intern Firma 0): lesen alle Firmen mit der App, pflegen der Dienstleister
    if ((string)($_GET['hub'] ?? '') === '1') {
        if (!is_staff_role($u['role']) && !((int)($u['company_id'] ?? 0) > 0 && company_has_app((int)$u['company_id'], WK_APP))) abort(403);
        $GLOBALS['wk_cid'] = 0;
        $GLOBALS['wk_hub'] = true;
        return 0;
    }
    $cid = context_company_id();
    if (!$cid) abort(404, t('Es ist noch keine Firma angelegt.'));
    require_company_access($cid);
    if (!row('SELECT id FROM companies WHERE id = ?', [$cid])) abort(404);
    if (!is_staff_role($u['role']) && !company_has_app($cid, WK_APP)) abort(403);
    $GLOBALS['wk_cid'] = $cid;
    return $cid;
}

function wkurl(string $route, array $params = []): string
{
    if (!empty($GLOBALS['wk_hub'])) $params = ['hub' => 1] + $params;
    elseif ((is_admin() || is_provider_user()) && !empty($GLOBALS['wk_cid'])) $params = ['company' => (int)$GLOBALS['wk_cid']] + $params;
    return url($route, $params);
}

function wk_redirect(string $route, array $params = []): never
{
    header('Location: ' . wkurl($route, $params));
    exit;
}

/** Bereich „Für alle Kunden“ (Seiten des Dienstleisters) */
function wk_is_hub(int $cid): bool
{
    return $cid === 0;
}

/** Firmenauswahl für den Dienstleister (nicht im Bereich „Für alle Kunden“) */
function wk_switcher(int $cid, string $route): string
{
    return wk_is_hub($cid) ? '' : company_switcher($cid, $route);
}

function wk_company(int $cid): array
{
    if (wk_is_hub($cid)) return ['id' => 0, 'name' => t('Für alle Kunden'), 'wk_provider_access' => 1];
    static $cache = [];
    return $cache[$cid] ??= (row('SELECT id, name, wk_provider_access FROM companies WHERE id = ?', [$cid]) ?? []);
}

/** Dienstleister ohne Einsicht-Freigabe der Firma */
function wk_staff_blocked(int $cid): bool
{
    return is_staff_role(current_user()['role']) && (int)(wk_company($cid)['wk_provider_access'] ?? 0) !== 1;
}

/** Recht des Dienstleisters in dieser Firma (gilt für alle Bereiche) */
function wk_staff_perm(int $cid, array $u): int
{
    if ((int)(wk_company($cid)['wk_provider_access'] ?? 0) !== 1) return WK_PERM_NONE;
    $p = admin_perm('docs', $u);
    $ids = admin_company_ids($u);
    // Für alle Kunden: lesen mit Bereichsrecht, pflegen nur mit Zugriff auf alle Firmen
    if (wk_is_hub($cid)) return $p >= PERM_EDIT && $ids === null ? WK_PERM_EDIT : ($p >= PERM_VIEW ? WK_PERM_VIEW : WK_PERM_NONE);
    if ($ids !== null && !in_array($cid, $ids, true)) $p = PERM_NONE;
    return $p >= PERM_EDIT ? WK_PERM_EDIT : ($p >= PERM_VIEW ? WK_PERM_VIEW : WK_PERM_NONE);
}

/**
 * Rechte des Benutzers je Bereich: [space_id => 0|1|2] für alle Bereiche der Firma.
 * Firmen-Admins: überall bearbeiten. Sonst höchstes Recht über die eigenen Gruppen; ohne Gruppenrecht
 * „ansehen“, wenn der Bereich für alle Mitarbeiter lesbar ist.
 */
function wk_space_perms(int $cid, ?array $u = null): array
{
    static $cache = [];
    $u ??= current_user();
    if (!$u) return [];
    $key = $cid . ':' . (int)$u['id'];
    if (isset($cache[$key])) return $cache[$key];
    $spaces = rows('SELECT id, all_read FROM wk_spaces WHERE company_id = ?', [$cid]);
    $out = [];
    if (is_staff_role($u['role'])) {
        $p = wk_staff_perm($cid, $u);
        foreach ($spaces as $s) $out[(int)$s['id']] = $p;
        return $cache[$key] = $out;
    }
    // externer Dienstleister: Recht der Firma für alle Bereiche; „Für alle Kunden“ nicht
    if ($u['role'] === 'dienstleister') {
        $p = !wk_is_hub($cid) && company_has_app($cid, WK_APP) ? min(WK_PERM_EDIT, (int)(provider_row($cid, $u)['wk_perm'] ?? 0)) : WK_PERM_NONE;
        foreach ($spaces as $s) $out[(int)$s['id']] = $p;
        return $cache[$key] = $out;
    }
    if (wk_is_hub($cid)) {
        $p = (int)($u['company_id'] ?? 0) > 0 && company_has_app((int)$u['company_id'], WK_APP) ? WK_PERM_VIEW : WK_PERM_NONE;
        foreach ($spaces as $s) $out[(int)$s['id']] = $p;
        return $cache[$key] = $out;
    }
    if ((int)($u['company_id'] ?? 0) !== $cid) return $cache[$key] = array_fill_keys(array_map(fn($s) => (int)$s['id'], $spaces), WK_PERM_NONE);
    if ($u['role'] === 'firma_admin') {
        foreach ($spaces as $s) $out[(int)$s['id']] = WK_PERM_EDIT;
        return $cache[$key] = $out;
    }
    $grp = [];
    foreach (rows('SELECT p.space_id, MAX(p.perm) AS perm FROM wk_space_perms p JOIN user_departments ud ON ud.department_id = p.department_id
        WHERE p.company_id = ? AND ud.user_id = ? GROUP BY p.space_id', [$cid, $u['id']]) as $r) $grp[(int)$r['space_id']] = min(WK_PERM_EDIT, (int)$r['perm']);
    foreach ($spaces as $s) {
        $p = $grp[(int)$s['id']] ?? WK_PERM_NONE;
        if ($p === WK_PERM_NONE && (int)$s['all_read'] === 1) $p = WK_PERM_VIEW;
        $out[(int)$s['id']] = $p;
    }
    return $cache[$key] = $out;
}

function wk_space_perm(int $cid, int $spaceId): int
{
    return wk_space_perms($cid)[$spaceId] ?? WK_PERM_NONE;
}

/** Bereiche anlegen, Rechte vergeben, Einstellungen: Firmen-Admins und der Dienstleister mit Bearbeiten-Recht */
function wk_can_manage(int $cid): bool
{
    $u = current_user();
    if (is_staff_role($u['role'])) return wk_staff_perm($cid, $u) === WK_PERM_EDIT;
    return !wk_is_hub($cid) && $u['role'] === 'firma_admin' && (int)$u['company_id'] === $cid;
}

/** Bereich laden; null, wenn er nicht zur Firma gehört */
function wk_space(int $cid, int $id): ?array
{
    return $id ? row('SELECT * FROM wk_spaces WHERE id = ? AND company_id = ?', [$id, $cid]) : null;
}

function wk_page(int $cid, int $id): ?array
{
    return $id ? row('SELECT * FROM wk_pages WHERE id = ? AND company_id = ?', [$id, $cid]) : null;
}

/** Bereich mit mindestens dem Recht $need, sonst 403/404 */
function wk_require_space(int $cid, int $id, int $need): array
{
    $s = wk_space($cid, $id);
    if (!$s) abort(404);
    if (wk_space_perm($cid, (int)$s['id']) < $need) {
        abort(403, wk_staff_blocked($cid)
            ? t('Das Wiki dieser Firma ist nur intern sichtbar. Die Firma kann die Einsicht für den IT-Dienstleister unter Einstellungen einschalten.')
            : t('Sie haben keinen Zugriff auf diesen Bereich. Die Rechte vergeben die Administratoren Ihrer Firma.'));
    }
    return $s;
}

function wk_log(int $cid, string $action, string $details): void
{
    audit('docs.' . $action, $details, $cid);
}

// ---------------------------------------------------------------------------
// Seitenbaum
// ---------------------------------------------------------------------------

/** Alle Seiten eines Bereichs (ohne Inhalt), gruppiert nach übergeordneter Seite: [parent_id|0 => [Seiten …]] */
function wk_children_map(int $cid, int $spaceId): array
{
    $map = [];
    foreach (rows('SELECT id, parent_id, title, sort, status, updated_at FROM wk_pages WHERE company_id = ? AND space_id = ? ORDER BY sort, title, id', [$cid, $spaceId]) as $p) {
        $map[(int)($p['parent_id'] ?? 0)][] = $p;
    }
    if (wk_space_perm($cid, $spaceId) >= WK_PERM_EDIT) return $map;
    // Leser: Entwürfe samt Unterseiten ausblenden
    $out = [];
    $stack = [0];
    while ($stack) {
        $parent = array_pop($stack);
        foreach ($map[$parent] ?? [] as $p) {
            if ($p['status'] !== 'published') continue;
            $out[$parent][] = $p;
            $stack[] = (int)$p['id'];
        }
    }
    return $out;
}

/** Kette der übergeordneten Seiten (oberste zuerst), ohne die Seite selbst */
function wk_ancestors(int $cid, array $page): array
{
    $out = [];
    $seen = [(int)$page['id'] => true];
    $pid = (int)($page['parent_id'] ?? 0);
    while ($pid && !isset($seen[$pid]) && count($out) < WK_MAX_DEPTH) {
        $seen[$pid] = true;
        $p = row('SELECT id, parent_id, title, status FROM wk_pages WHERE id = ? AND company_id = ?', [$pid, $cid]);
        if (!$p) break;
        array_unshift($out, $p);
        $pid = (int)($p['parent_id'] ?? 0);
    }
    return $out;
}

/** IDs einer Seite und aller Unterseiten */
function wk_subtree_ids(array $map, int $id): array
{
    $ids = [$id];
    $stack = [$id];
    while ($stack && count($ids) < 10000) {
        $cur = array_pop($stack);
        foreach ($map[$cur] ?? [] as $c) {
            $cid = (int)$c['id'];
            if (in_array($cid, $ids, true)) continue;
            $ids[] = $cid;
            $stack[] = $cid;
        }
    }
    return $ids;
}

/** Seitenbaum als verschachtelte Liste; geöffnet ist der Pfad zur aktuellen Seite */
function wk_tree_html(array $map, int $parent, int $current, array $openIds, int $depth = 0): string
{
    if (empty($map[$parent]) || $depth > WK_MAX_DEPTH) return '';
    $html = '<ul>';
    foreach ($map[$parent] as $p) {
        $id = (int)$p['id'];
        $hasKids = !empty($map[$id]);
        $open = $hasKids && (in_array($id, $openIds, true) || $id === $current);
        $html .= '<li class="' . ($id === $current ? 'current ' : '') . ($hasKids ? ($open ? 'open' : 'closed') : 'leaf') . '">';
        if ($hasKids) $html .= '<button type="button" class="wk-twisty" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-label="' . e(t('Unterseiten ein- oder ausklappen')) . '"></button>';
        $html .= '<a href="' . e(wkurl('doc', ['id' => $id])) . '">' . e($p['title']) . (($p['status'] ?? '') === 'draft' ? ' <span class="tag">' . e(t('Entwurf')) . '</span>' : '') . '</a>';
        if ($hasKids) $html .= wk_tree_html($map, $id, $current, $openIds, $depth + 1);
        $html .= '</li>';
    }
    return $html . '</ul>';
}

/** Auswahlliste „übergeordnete Seite“ (ohne die Seite selbst und ihre Unterseiten) */
function wk_parent_options(array $map, int $parent, array $exclude, int $selected, int $depth = 0): string
{
    if (empty($map[$parent]) || $depth > WK_MAX_DEPTH) return '';
    $html = '';
    foreach ($map[$parent] as $p) {
        $id = (int)$p['id'];
        if (in_array($id, $exclude, true)) continue;
        $html .= '<option value="' . $id . '"' . ($id === $selected ? ' selected' : '') . '>' . str_repeat('  ', $depth) . e(($depth ? '└ ' : '') . $p['title']) . '</option>';
        $html .= wk_parent_options($map, $id, $exclude, $selected, $depth + 1);
    }
    return $html;
}

/** Seitenleiste mit Bereich und Seitenbaum */
function wk_sidebar(int $cid, array $space, array $map, int $current, array $openIds): void
{
    $perm = wk_space_perm($cid, (int)$space['id']); ?>
    <aside class="wk-side card">
        <a class="wk-space-title" href="<?= e(wkurl('doc_space', ['id' => $space['id']])) ?>"><span class="wk-icon"><?= e($space['icon'] ?: '📁') ?></span> <?= e($space['name']) ?></a>
        <nav class="wk-tree" aria-label="<?= e(t('Seitenbaum')) ?>">
            <?= wk_tree_html($map, 0, $current, $openIds) ?: '<p class="muted small">' . e(t('Noch keine Seiten.')) . '</p>' ?>
        </nav>
        <?php if ($perm >= WK_PERM_EDIT): ?><a class="btn small" href="<?= e(wkurl('doc_edit', ['space' => $space['id']])) ?>"><?= e(t('+ Seite')) ?></a><?php endif; ?>
    </aside>
<?php }

// ---------------------------------------------------------------------------
// Anzeige der Inhalte
// ---------------------------------------------------------------------------

/**
 * Bereinigter Seiteninhalt für die Anzeige: Überschriften bekommen Sprungmarken,
 * Platzhalter für das Inhaltsverzeichnis werden durch die Gliederung ersetzt.
 */
function wk_render_body(string $html): string
{
    $html = trim($html);
    if ($html === '') return '';
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('__root');
    if (!$root) return '';
    $xp = new DOMXPath($doc);
    $heads = [];
    $n = 0;
    foreach ($xp->query('.//h1|.//h2|.//h3|.//h4', $root) as $h) {
        $id = 'abschnitt-' . (++$n);
        $h->setAttribute('id', $id);
        $heads[] = [(int)substr($h->nodeName, 1), trim($h->textContent), $id];
    }
    foreach (iterator_to_array($xp->query('.//div[contains(concat(" ", normalize-space(@class), " "), " wk-toc ")]', $root)) as $toc) {
        $nav = $doc->createElement('nav');
        $nav->setAttribute('class', 'wk-toc');
        $title = $doc->createElement('strong');
        $title->appendChild($doc->createTextNode(t('Inhalt')));
        $nav->appendChild($title);
        $list = $doc->createElement('ul');
        foreach ($heads as [$level, $text, $id]) {
            if ($text === '') continue;
            $li = $doc->createElement('li');
            $li->setAttribute('class', 'lvl' . $level);
            $a = $doc->createElement('a');
            $a->setAttribute('href', '#' . $id);
            $a->appendChild($doc->createTextNode($text));
            $li->appendChild($a);
            $list->appendChild($li);
        }
        if (!$heads) {
            $li = $doc->createElement('li');
            $li->appendChild($doc->createTextNode(t('Die Seite hat noch keine Überschriften.')));
            $list->appendChild($li);
        }
        $nav->appendChild($list);
        $toc->parentNode->replaceChild($nav, $toc);
    }
    // Links nach außen in neuem Fenster
    foreach ($xp->query('.//a[@href]', $root) as $a) {
        if (preg_match('#^https?://#i', $a->getAttribute('href'))) { $a->setAttribute('target', '_blank'); $a->setAttribute('rel', 'noopener noreferrer'); }
    }
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}

/** Kurzer Textauszug (für Suche und Listen) */
function wk_excerpt(string $html, string $needle = '', int $len = 220): string
{
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>', '</h2>', '</h3>', '</td>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($needle !== '' && ($pos = mb_stripos($text, $needle)) !== false) {
        $start = max(0, $pos - 80);
        return ($start > 0 ? '… ' : '') . mb_substr($text, $start, $len) . (mb_strlen($text) > $start + $len ? ' …' : '');
    }
    return mb_substr($text, 0, $len) . (mb_strlen($text) > $len ? ' …' : '');
}

function wk_user_name(?int $id): string
{
    static $cache = [];
    if (!$id) return '–';
    return $cache[$id] ??= (string)(row('SELECT name FROM users WHERE id = ?', [$id])['name'] ?? '–');
}

/** Kopf der App: Reiter und Suche */
function wk_tabs(int $cid, string $active, string $search = ''): void
{
    if (!empty($GLOBALS['in_settings'])) return; // im Rahmen der Einstellungen ohne App-Reiter
    $tabs = ['docs' => t('Bereiche')];
    // Tresor: nicht für externe Dienstleister
    if (!wk_is_hub($cid) && !is_provider_user()) $tabs['doc_vault'] = t('Tresor');
    if (wk_can_manage($cid)) { $tabs['doc_templates'] = t('Vorlagen'); if (!wk_is_hub($cid)) $tabs['doc_settings'] = t('Einstellungen'); } ?>
    <div class="wk-head">
        <nav class="type-tabs">
            <?php foreach ($tabs as $route => $label): ?><a class="<?= $route === $active ? 'active' : '' ?>" href="<?= e(wkurl($route)) ?>"><?= e($label) ?></a><?php endforeach; ?>
        </nav>
        <form method="get" class="wk-search" role="search">
            <input type="hidden" name="r" value="doc_search">
            <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="<?= e(t('Wiki durchsuchen …')) ?>" aria-label="<?= e(t('Wiki durchsuchen')) ?>">
            <button class="btn"><?= e(t('Suchen')) ?></button>
        </form>
    </div>
<?php }

// ---------------------------------------------------------------------------
// Übersicht der Bereiche
// ---------------------------------------------------------------------------

function page_docs(): void
{
    $cid = wk_company_id();
    $hub = wk_is_hub($cid);
    $perms = wk_space_perms($cid);
    $readable = array_keys(array_filter($perms, fn($p) => $p > WK_PERM_NONE));
    $editable = array_keys(array_filter($perms, fn($p) => $p >= WK_PERM_EDIT));
    $vis = wk_visible_sql($cid, 'p');
    $in = $readable ? implode(',', array_map('intval', $readable)) : '0';
    $spaces = $readable ? rows("SELECT s.*, (SELECT COUNT(*) FROM wk_pages p WHERE p.space_id = s.id AND $vis) AS n FROM wk_spaces s
        WHERE s.company_id = ? AND s.id IN ($in) ORDER BY s.sort, s.name", [$cid]) : [];
    $recent = $readable ? rows("SELECT p.id, p.title, p.status, p.updated_at, p.updated_by, s.name AS space_name, s.icon FROM wk_pages p JOIN wk_spaces s ON s.id = p.space_id
        WHERE p.company_id = ? AND p.space_id IN ($in) AND $vis ORDER BY p.updated_at DESC, p.id DESC LIMIT 10", [$cid]) : [];
    $ein = $editable ? implode(',', array_map('intval', $editable)) : '0';
    $soon = date('Y-m-d', strtotime('+14 days'));
    $review = $editable ? rows("SELECT p.id, p.title, p.review_due, p.owner_id, s.name AS space_name FROM wk_pages p JOIN wk_spaces s ON s.id = p.space_id
        WHERE p.company_id = ? AND p.space_id IN ($ein) AND p.status = 'published' AND p.review_due <> '' AND p.review_due <= ? ORDER BY p.review_due LIMIT 50", [$cid, $soon]) : [];
    $drafts = $editable ? rows("SELECT p.id, p.title, p.updated_at, p.updated_by, s.name AS space_name FROM wk_pages p JOIN wk_spaces s ON s.id = p.space_id
        WHERE p.company_id = ? AND p.space_id IN ($ein) AND p.status = 'draft' ORDER BY p.updated_at DESC LIMIT 50", [$cid]) : [];
    $manage = wk_can_manage($cid);
    $hasAny = (bool)row('SELECT id FROM wk_spaces WHERE company_id = ? LIMIT 1', [$cid]);
    // Seiten des Dienstleisters für alle Kunden (nur außerhalb dieses Bereichs anzeigen)
    $hubSpaces = [];
    if (!$hub) {
        $hp = wk_space_perms(0);
        $hr = array_keys(array_filter($hp, fn($p) => $p > WK_PERM_NONE));
        $hvis = wk_visible_sql(0, 'p');
        if ($hr) $hubSpaces = rows("SELECT s.*, (SELECT COUNT(*) FROM wk_pages p WHERE p.space_id = s.id AND $hvis) AS n FROM wk_spaces s
            WHERE s.company_id = 0 AND s.id IN (" . implode(',', array_map('intval', $hr)) . ') ORDER BY s.sort, s.name');
        if (!is_staff_role(current_user()['role'])) $hubSpaces = array_values(array_filter($hubSpaces, fn($s) => (int)$s['n'] > 0));
    }
    $staffHub = !$hub && is_staff_role(current_user()['role']) && admin_perm('docs') >= PERM_VIEW;

    render_page($hub ? t('Für alle Kunden') . ' · ' . t('Dokumente & Anleitungen') : t('Dokumente & Anleitungen'),
        function () use ($cid, $hub, $spaces, $recent, $manage, $hasAny, $perms, $review, $drafts, $hubSpaces, $staffHub) {
        echo wk_switcher($cid, 'docs');
        $blocked = wk_staff_blocked($cid);
        if ($blocked): wk_tabs($cid, 'docs'); ?>
            <div class="alert info"><?= e(t('Das Wiki dieser Firma ist nur intern sichtbar. Die Firma kann die Einsicht für den IT-Dienstleister unter Einstellungen einschalten.')) ?></div>
        <?php else:
        wk_tabs($cid, 'docs');
        if ($hub): ?>
            <div class="alert info"><?= e(t('Diese Seiten sehen alle Firmen, für die „Dokumente & Anleitungen“ freigeschaltet ist. Entwürfe sehen nur Sie und Ihre Admins. Firmen können eine Seite in ihr eigenes Wiki kopieren und anpassen.')) ?></div>
        <?php endif; ?>
        <?php if ($manage): ?>
        <div class="toolbar">
            <a class="btn primary" href="<?= e(wkurl('doc_space_edit')) ?>"><?= e(t('+ Bereich')) ?></a>
        </div>
        <?php endif; ?>
        <?php if ($review || $drafts): ?>
        <div class="grid2 wk-todo">
            <?php if ($review): ?>
            <div class="card">
                <h2><?= e(t('Zu prüfen')) ?></h2>
                <ul class="wk-list">
                    <?php foreach ($review as $r): $over = $r['review_due'] < date('Y-m-d'); ?>
                        <li><a href="<?= e(wkurl('doc', ['id' => $r['id']])) ?>"><?= e($r['title']) ?></a>
                            <span class="tag <?= $over ? 'warn' : '' ?>"><?= e($over ? t('überfällig seit {d}', ['d' => fmt_date($r['review_due'])]) : t('fällig am {d}', ['d' => fmt_date($r['review_due'])])) ?></span>
                            <span class="muted small"><?= e($r['space_name']) ?><?= $r['owner_id'] ? ' · ' . e(wk_user_name((int)$r['owner_id'])) : '' ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <?php if ($drafts): ?>
            <div class="card">
                <h2><?= e(t('Entwürfe')) ?></h2>
                <ul class="wk-list">
                    <?php foreach ($drafts as $d): ?>
                        <li><a href="<?= e(wkurl('doc', ['id' => $d['id']])) ?>"><?= e($d['title']) ?></a>
                            <span class="muted small"><?= e($d['space_name']) ?> · <?= fmt_dt($d['updated_at']) ?> · <?= e(wk_user_name((int)$d['updated_by'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!$spaces): ?>
            <div class="card">
                <?php if ($manage && !$hasAny && !$hub): ?>
                    <h2><?= e(t('Willkommen im Wiki')) ?></h2>
                    <p><?= e(t('Legen Sie Bereiche an, zum Beispiel einen je Abteilung oder für „IT“, „Richtlinien“ und „Notfall“. In jedem Bereich entsteht ein Seitenbaum mit Anleitungen und Handbüchern.')) ?></p>
                    <div class="btn-row">
                        <a class="btn primary" href="<?= e(wkurl('doc_space_edit')) ?>"><?= e(t('+ Bereich')) ?></a>
                        <form method="post" action="<?= e(wkurl('doc_action')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="spaces_from_departments">
                            <button class="btn"><?= e(t('Bereiche aus den Abteilungen anlegen')) ?></button></form>
                    </div>
                <?php elseif ($hub && $manage): ?>
                    <p><?= e(t('Legen Sie einen Bereich an, zum Beispiel „Anleitungen“ oder „Sicherheit“. Seine veröffentlichten Seiten erscheinen bei allen Kunden.')) ?></p>
                <?php else: ?>
                    <p class="muted"><?= e(t('Es gibt noch keine Bereiche, auf die Sie Zugriff haben.')) ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="wk-spaces">
                <?php foreach ($spaces as $s) wk_space_tile($s, wkurl('doc_space', ['id' => $s['id']]), ($perms[(int)$s['id']] ?? 0) === WK_PERM_VIEW); ?>
            </div>
            <?php if ($recent): ?>
            <div class="card">
                <h2><?= e(t('Zuletzt geändert')) ?></h2>
                <table class="table">
                    <thead><tr><th><?= e(t('Seite')) ?></th><th><?= e(t('Bereich')) ?></th><th><?= e(t('Geändert')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $p): ?>
                        <tr><td><a href="<?= e(wkurl('doc', ['id' => $p['id']])) ?>"><?= e($p['title']) ?></a><?= $p['status'] === 'draft' ? ' <span class="tag">' . e(t('Entwurf')) . '</span>' : '' ?></td>
                            <td><?= e(($p['icon'] ?: '📁') . ' ' . $p['space_name']) ?></td>
                            <td class="nowrap"><?= fmt_dt($p['updated_at']) ?> · <?= e(wk_user_name((int)$p['updated_by'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; // blocked ?>
        <?php if ($hubSpaces || $staffHub): ?>
            <div class="card wk-hub">
                <h2><?= e(is_staff_role(current_user()['role']) ? t('Für alle Kunden') : t('Anleitungen Ihres IT-Dienstleisters')) ?></h2>
                <?php if (is_staff_role(current_user()['role'])): ?><p class="muted small"><?= e(t('Diese Seiten sehen alle Firmen mit „Dokumente & Anleitungen“.')) ?> <a href="<?= e(url('docs', ['hub' => 1])) ?>"><?= e(t('Öffnen und pflegen')) ?></a></p><?php endif; ?>
                <?php if ($hubSpaces): ?><div class="wk-spaces">
                    <?php foreach ($hubSpaces as $s) wk_space_tile($s, url('doc_space', ['hub' => 1, 'id' => $s['id']]), false); ?>
                </div><?php endif; ?>
            </div>
        <?php endif;
    });
}

/** Kachel eines Bereichs */
function wk_space_tile(array $s, string $href, bool $readOnly): void
{ ?>
    <a class="wk-space-tile" href="<?= e($href) ?>">
        <span class="wk-icon big"><?= e($s['icon'] ?: '📁') ?></span>
        <strong><?= e($s['name']) ?></strong>
        <?php if ($s['description'] !== ''): ?><span class="muted small"><?= e($s['description']) ?></span><?php endif; ?>
        <span class="muted small"><?= e((int)$s['n'] === 1 ? t('1 Seite') : t('{n} Seiten', ['n' => (int)$s['n']])) ?><?php if ($readOnly): ?> · <?= e(t('nur lesen')) ?><?php endif; ?></span>
    </a>
<?php }

/**
 * SQL-Bedingung „Seite sichtbar“: veröffentlicht, oder Entwurf in einem Bereich, den der Benutzer bearbeiten darf.
 * $alias = Tabellenkürzel von wk_pages.
 */
function wk_visible_sql(int $cid, string $alias = 'p'): string
{
    $edit = array_keys(array_filter(wk_space_perms($cid), fn($p) => $p >= WK_PERM_EDIT));
    $a = preg_replace('/[^a-z]/', '', $alias);
    return "($a.status = 'published'" . ($edit ? " OR $a.space_id IN (" . implode(',', array_map('intval', $edit)) . ')' : '') . ')';
}

/** Darf der Benutzer diese Seite sehen? (Entwürfe nur mit Bearbeiten-Recht im Bereich) */
function wk_page_visible(int $cid, array $page): bool
{
    $p = wk_space_perm($cid, (int)$page['space_id']);
    if ($p >= WK_PERM_EDIT) return true;
    if ($p < WK_PERM_VIEW || ($page['status'] ?? 'published') !== 'published') return false;
    foreach (wk_ancestors($cid, $page) as $a) if (($a['status'] ?? 'published') !== 'published') return false;
    return true;
}

// ---------------------------------------------------------------------------
// Bereich ansehen
// ---------------------------------------------------------------------------

function page_doc_space(): void
{
    $cid = wk_company_id();
    $space = wk_require_space($cid, query_int('id'), WK_PERM_VIEW);
    $perm = wk_space_perm($cid, (int)$space['id']);
    $map = wk_children_map($cid, (int)$space['id']);
    $top = $map[0] ?? [];

    render_page($space['name'] . ' · ' . t('Dokumente & Anleitungen'), function () use ($cid, $space, $perm, $map, $top) {
        echo wk_switcher($cid, 'docs');
        wk_tabs($cid, 'docs'); ?>
        <div class="wk-layout">
            <?php wk_sidebar($cid, $space, $map, 0, []); ?>
            <section class="wk-main">
                <nav class="wk-crumbs"><a href="<?= e(wkurl('docs')) ?>"><?= e(t('Bereiche')) ?></a> › <span><?= e($space['name']) ?></span></nav>
                <div class="card">
                    <div class="wk-title-row">
                        <h1><span class="wk-icon"><?= e($space['icon'] ?: '📁') ?></span> <?= e($space['name']) ?></h1>
                        <div class="btn-row">
                            <?php if ($perm >= WK_PERM_EDIT): ?><a class="btn primary" href="<?= e(wkurl('doc_edit', ['space' => $space['id']])) ?>"><?= e(t('+ Seite')) ?></a><?php endif; ?>
                            <?php if (wk_can_manage($cid)): ?><a class="btn" href="<?= e(wkurl('doc_space_edit', ['id' => $space['id']])) ?>"><?= e(t('Bereich bearbeiten')) ?></a><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($space['description'] !== ''): ?><p><?= nl2br(e($space['description'])) ?></p><?php endif; ?>
                    <?php if ($top): ?>
                        <h2><?= e(t('Seiten')) ?></h2>
                        <ul class="wk-list">
                            <?php foreach ($top as $p): $kids = count($map[(int)$p['id']] ?? []); ?>
                                <li><a href="<?= e(wkurl('doc', ['id' => $p['id']])) ?>"><?= e($p['title']) ?></a>
                                    <?php if ($kids): ?><span class="muted small"><?= e($kids === 1 ? t('1 Unterseite') : t('{n} Unterseiten', ['n' => $kids])) ?></span><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted"><?= e(t('In diesem Bereich gibt es noch keine Seiten.')) ?></p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Seite ansehen
// ---------------------------------------------------------------------------

function page_doc(): void
{
    $cid = wk_company_id();
    $page = wk_page($cid, query_int('id'));
    if (!$page) abort(404);
    $space = wk_require_space($cid, (int)$page['space_id'], WK_PERM_VIEW);
    if (!wk_page_visible($cid, $page)) abort(404);
    $perm = wk_space_perm($cid, (int)$space['id']);
    $map = wk_children_map($cid, (int)$space['id']);
    $anc = wk_ancestors($cid, $page);
    $openIds = array_map(fn($a) => (int)$a['id'], $anc);
    $kids = $map[(int)$page['id']] ?? [];
    $siblings = $map[(int)($page['parent_id'] ?? 0)] ?? [];
    $pos = array_search((int)$page['id'], array_map(fn($s) => (int)$s['id'], $siblings), true);
    $body = wk_render_body((string)$page['body']);
    $watching = wk_is_watching($cid, (int)$page['id'], (int)current_user()['id']);
    $versionCount = (int)(row('SELECT COUNT(*) AS n FROM wk_versions WHERE company_id = ? AND page_id = ?', [$cid, $page['id']])['n'] ?? 0);

    render_page($page['title'] . ' · ' . $space['name'], function () use ($cid, $space, $page, $perm, $map, $anc, $openIds, $kids, $siblings, $pos, $body, $watching, $versionCount) {
        echo wk_switcher($cid, 'docs');
        wk_tabs($cid, 'docs'); ?>
        <div class="wk-layout">
            <?php wk_sidebar($cid, $space, $map, (int)$page['id'], $openIds); ?>
            <section class="wk-main">
                <nav class="wk-crumbs"><a href="<?= e(wkurl('docs')) ?>"><?= e(t('Bereiche')) ?></a> ›
                    <a href="<?= e(wkurl('doc_space', ['id' => $space['id']])) ?>"><?= e($space['name']) ?></a>
                    <?php foreach ($anc as $a): ?> › <a href="<?= e(wkurl('doc', ['id' => $a['id']])) ?>"><?= e($a['title']) ?></a><?php endforeach; ?></nav>
                <article class="card wk-article">
                    <div class="wk-title-row">
                        <h1><?= e($page['title']) ?><?= $page['status'] === 'draft' ? ' <span class="tag warn">' . e(t('Entwurf')) . '</span>' : '' ?></h1>
                        <?php if ($perm >= WK_PERM_EDIT): ?>
                        <div class="btn-row">
                            <a class="btn primary" href="<?= e(wkurl('doc_edit', ['id' => $page['id']])) ?>"><?= e(t('Bearbeiten')) ?></a>
                            <a class="btn" href="<?= e(wkurl('doc_edit', ['space' => $space['id'], 'parent' => $page['id']])) ?>"><?= e(t('+ Unterseite')) ?></a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="wk-meta">
                        <span class="muted small"><?= e(t('Zuletzt geändert am {d} von {n}', ['d' => fmt_dt($page['updated_at']), 'n' => wk_user_name((int)$page['updated_by'])])) ?></span>
                        <span class="wk-meta-links">
                            <a href="<?= e(wkurl('doc_history', ['id' => $page['id']])) ?>"><?= e($versionCount > 1 ? t('Verlauf ({n} Versionen)', ['n' => $versionCount]) : t('Verlauf')) ?></a>
                            <a href="<?= e(wkurl('doc_pdf', ['id' => $page['id']])) ?>"><?= e(t('PDF')) ?></a>
                            <?php if ($kids): ?><a href="<?= e(wkurl('doc_pdf', ['id' => $page['id'], 'tree' => 1])) ?>"><?= e(t('PDF mit Unterseiten')) ?></a><?php endif; ?>
                            <?php if (!wk_is_hub($cid)): ?><a href="#kommentare"><?= e(t('Kommentare')) ?></a><?php endif; ?>
                            <form method="post" action="<?= e(wkurl('doc_action')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
                                <button class="btn small <?= $watching ? '' : 'primary' ?>" name="action" value="<?= $watching ? 'unwatch' : 'watch' ?>"><?= e($watching ? t('👁 Nicht mehr beobachten') : t('👁 Beobachten')) ?></button></form>
                        </span>
                    </div>
                    <?php wk_status_bar($cid, $page, $perm); ?>
                    <div class="wk-body"><?= $body !== '' ? $body : '<p class="muted">' . e(t('Diese Seite ist noch leer.')) . '</p>' ?></div>
                    <?php if ($kids): ?>
                        <div class="wk-children">
                            <h2><?= e(t('Unterseiten')) ?></h2>
                            <ul class="wk-list"><?php foreach ($kids as $k): ?><li><a href="<?= e(wkurl('doc', ['id' => $k['id']])) ?>"><?= e($k['title']) ?></a></li><?php endforeach; ?></ul>
                        </div>
                    <?php endif; ?>
                </article>
                <?php wk_files_card($cid, $page, $perm); ?>
                <?php if (function_exists('wkl_backlinks_card')) wkl_backlinks_card($cid, $page); ?>
                <?php if (wk_is_hub($cid)) wk_copy_card($page); else wk_comments_card($cid, $page, $space); ?>
                <?php if ($perm >= WK_PERM_EDIT): ?>
                <div class="card wk-tools">
                    <form method="post" action="<?= e(wkurl('doc_action')) ?>" class="btn-row">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
                        <span class="muted small"><?= e(t('Reihenfolge:')) ?></span>
                        <button class="btn small" name="action" value="move_up" <?= $pos === 0 ? 'disabled' : '' ?>><?= e(t('↑ nach oben')) ?></button>
                        <button class="btn small" name="action" value="move_down" <?= $pos === count($siblings) - 1 ? 'disabled' : '' ?>><?= e(t('↓ nach unten')) ?></button>
                        <?php if (wk_can_manage($cid)): ?><button class="btn small" name="action" value="save_template"><?= e(t('Als Vorlage speichern')) ?></button><?php endif; ?>
                    </form>
                    <form method="post" action="<?= e(wkurl('doc_action')) ?>" data-confirm="<?= e(t('Diese Seite wirklich löschen? Das lässt sich nicht rückgängig machen.')) ?>">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$page['id'] ?>">
                        <button class="btn small danger" name="action" value="delete" <?= $kids ? 'disabled title="' . e(t('Bitte zuerst die Unterseiten verschieben oder löschen.')) . '"' : '' ?>><?= e(t('Seite löschen')) ?></button>
                    </form>
                </div>
                <?php endif; ?>
            </section>
        </div>
    <?php });
}

/** Werkzeugleiste und Editor (assets/wiki.js); ohne JavaScript bleibt das HTML-Textfeld */
function wk_editor(string $body): void
{ ?>
            <div class="wk-toolbar" id="wk-toolbar" role="toolbar" aria-label="<?= e(t('Formatierung')) ?>" hidden>
                <select data-block aria-label="<?= e(t('Absatzformat')) ?>">
                    <option value="p"><?= e(t('Standard')) ?></option><option value="h2"><?= e(t('Überschrift 1')) ?></option>
                    <option value="h3"><?= e(t('Überschrift 2')) ?></option><option value="h4"><?= e(t('Überschrift 3')) ?></option>
                </select>
                <span class="wk-sep"></span>
                <button type="button" data-cmd="bold" title="<?= e(t('Fett (Strg+B)')) ?>"><strong>F</strong></button>
                <button type="button" data-cmd="italic" title="<?= e(t('Kursiv (Strg+I)')) ?>"><em>K</em></button>
                <button type="button" data-cmd="underline" title="<?= e(t('Unterstrichen (Strg+U)')) ?>"><u>U</u></button>
                <button type="button" data-cmd="strikeThrough" title="<?= e(t('Durchgestrichen')) ?>"><s>S</s></button>
                <span class="wk-sep"></span>
                <button type="button" data-cmd="insertUnorderedList" title="<?= e(t('Aufzählung')) ?>">•≡</button>
                <button type="button" data-cmd="insertOrderedList" title="<?= e(t('Nummerierung')) ?>">1≡</button>
                <button type="button" data-cmd="outdent" title="<?= e(t('Einzug verkleinern')) ?>">⇤</button>
                <button type="button" data-cmd="indent" title="<?= e(t('Einzug vergrößern')) ?>">⇥</button>
                <span class="wk-sep"></span>
                <button type="button" data-act="link" title="<?= e(t('Link einfügen')) ?>">🔗</button>
                <button type="button" data-cmd="unlink" title="<?= e(t('Link entfernen')) ?>">⛓</button>
                <button type="button" data-act="image" title="<?= e(t('Bild einfügen')) ?>">🖼</button>
                <button type="button" data-act="table" title="<?= e(t('Tabelle einfügen')) ?>">▦</button>
                <button type="button" data-cmd="insertHorizontalRule" title="<?= e(t('Trennlinie')) ?>">―</button>
                <span class="wk-sep"></span>
                <select data-box aria-label="<?= e(t('Infobox')) ?>">
                    <option value=""><?= e(t('Infobox …')) ?></option>
                    <option value="wk-info"><?= e(t('Info')) ?></option><option value="wk-note"><?= e(t('Hinweis')) ?></option>
                    <option value="wk-warn"><?= e(t('Warnung')) ?></option><option value="wk-ok"><?= e(t('Erledigt / Tipp')) ?></option>
                    <option value="none"><?= e(t('Infobox entfernen')) ?></option>
                </select>
                <select data-status aria-label="<?= e(t('Statusmarker')) ?>">
                    <option value=""><?= e(t('Status …')) ?></option>
                    <option value="grey"><?= e(t('Grau')) ?></option><option value="blue"><?= e(t('Blau')) ?></option><option value="green"><?= e(t('Grün')) ?></option>
                    <option value="yellow"><?= e(t('Gelb')) ?></option><option value="red"><?= e(t('Rot')) ?></option>
                </select>
                <button type="button" data-act="toc" title="<?= e(t('Inhaltsverzeichnis einfügen')) ?>">☰</button>
                <span class="wk-sep"></span>
                <span class="wk-tablebtns" hidden>
                    <button type="button" data-act="row" title="<?= e(t('Zeile darunter einfügen')) ?>">+▤</button>
                    <button type="button" data-act="col" title="<?= e(t('Spalte rechts einfügen')) ?>">+▥</button>
                    <button type="button" data-act="delrow" title="<?= e(t('Zeile löschen')) ?>">−▤</button>
                    <button type="button" data-act="delcol" title="<?= e(t('Spalte löschen')) ?>">−▥</button>
                    <span class="wk-sep"></span>
                </span>
                <button type="button" data-cmd="removeFormat" title="<?= e(t('Formatierung entfernen')) ?>">⌫</button>
                <button type="button" data-cmd="undo" title="<?= e(t('Rückgängig (Strg+Z)')) ?>">↶</button>
                <button type="button" data-cmd="redo" title="<?= e(t('Wiederholen (Strg+Y)')) ?>">↷</button>
                <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" id="wk-image-file" hidden>
            </div>
            <div class="wk-editor wk-body" id="wk-editor" hidden></div>
            <textarea name="body" id="wk-body" rows="18" class="wk-fallback"><?= e($body) ?></textarea>
<?php }

// ---------------------------------------------------------------------------
// Seite anlegen / bearbeiten
// ---------------------------------------------------------------------------

function page_doc_edit(): void
{
    $cid = wk_company_id();
    $id = query_int('id');
    $page = $id ? wk_page($cid, $id) : null;
    if ($id && !$page) abort(404);
    $space = wk_require_space($cid, $page ? (int)$page['space_id'] : query_int('space'), WK_PERM_EDIT);
    $sid = (int)$space['id'];
    $map = wk_children_map($cid, $sid);
    $exclude = $page ? wk_subtree_ids($map, (int)$page['id']) : [];
    $parent = $page ? (int)($page['parent_id'] ?? 0) : query_int('parent');
    if ($parent && (in_array($parent, $exclude, true) || (int)(wk_page($cid, $parent)['space_id'] ?? 0) !== $sid)) $parent = 0;
    $err = '';
    $title = $page['title'] ?? '';
    $body = (string)($page['body'] ?? '');
    if (!$page && !is_post()) {
        $tpl = (string)($_GET['tpl'] ?? '');
        if ($tpl === '') { wk_template_chooser($cid, $space, $parent); return; }
        if ($tpl !== 'leer' && ($t = wk_template_body($cid, $tpl))) $body = (string)$t['body'];
    }

    if (is_post()) {
        require_post_csrf();
        $title = mb_substr(trim((string)post('title')), 0, 190);
        $raw = (string)post('body');
        $parent = (int)post('parent_id', 0);
        if ($parent && (in_array($parent, $exclude, true) || (int)(wk_page($cid, $parent)['space_id'] ?? 0) !== $sid)) $parent = 0;
        if ($title === '') $err = t('Bitte einen Titel angeben.');
        elseif (strlen($raw) > WK_BODY_MAX) $err = t('Die Seite ist zu groß (höchstens {n} MB inklusive Bilder). Bitte Bilder verkleinern oder auf mehrere Seiten verteilen.', ['n' => WK_BODY_MAX / 1048576]);
        if ($err === '') {
            $body = sanitize_html($raw);
            $data = ['title' => $title, 'body' => $body, 'parent_id' => $parent ?: null, 'updated_by' => current_user()['id'], 'updated_at' => now()]
                + wk_review_post($cid, $sid, $page, post('publish') === '1');
            if ($data['review_due'] !== ($page['review_due'] ?? '')) $data['review_notified'] = '';
            if ($page) {
                if ((int)($page['parent_id'] ?? 0) !== $parent) {
                    $data['sort'] = (int)(row('SELECT MAX(sort) AS m FROM wk_pages WHERE company_id = ? AND space_id = ? AND ' . ($parent ? 'parent_id = ?' : 'parent_id IS NULL'),
                        array_merge([$cid, $sid], $parent ? [$parent] : []))['m'] ?? 0) + 10;
                }
                update('wk_pages', (int)$page['id'], $data);
                $pid = (int)$page['id'];
                wk_log($cid, 'page_update', '#' . $pid . ' ' . $title);
                wk_after_save($cid, $page, $pid, $title, $body);
            } else {
                $data += ['company_id' => $cid, 'space_id' => $sid, 'created_by' => current_user()['id'], 'created_at' => now(),
                    'sort' => (int)(row('SELECT MAX(sort) AS m FROM wk_pages WHERE company_id = ? AND space_id = ? AND ' . ($parent ? 'parent_id = ?' : 'parent_id IS NULL'),
                        array_merge([$cid, $sid], $parent ? [$parent] : []))['m'] ?? 0) + 10];
                $pid = insert('wk_pages', $data);
                wk_log($cid, 'page_create', '#' . $pid . ' ' . $title);
                wk_after_save($cid, null, $pid, $title, $body);
            }
            update('wk_spaces', $sid, ['updated_at' => now()]);
            flash('success', $data['status'] === 'draft' ? t('Die Seite wurde als Entwurf gespeichert. Leser sehen sie erst nach dem Veröffentlichen.') : t('Die Seite wurde gespeichert.'));
            wk_redirect('doc', ['id' => $pid]);
        }
        $body = sanitize_html(substr($raw, 0, WK_BODY_MAX));
    }

    $heading = $page ? t('Seite bearbeiten') : t('Neue Seite');
    render_page($heading . ' · ' . $space['name'], function () use ($cid, $space, $page, $map, $exclude, $parent, $title, $body, $err, $heading) { ?>
        <nav class="wk-crumbs"><a href="<?= e(wkurl('docs')) ?>"><?= e(t('Bereiche')) ?></a> ›
            <a href="<?= e(wkurl('doc_space', ['id' => $space['id']])) ?>"><?= e($space['name']) ?></a> › <span><?= e($heading) ?></span></nav>
        <?php if ($err !== ''): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>
        <form method="post" class="form card wk-edit" id="wk-form">
            <?= csrf_field() ?>
            <div class="wk-edit-head">
                <label class="grow"><?= e(t('Titel')) ?> <input name="title" required maxlength="190" value="<?= e($title) ?>" autofocus></label>
                <label><?= e(t('Übergeordnete Seite')) ?> <select name="parent_id">
                    <option value="0"><?= e(t('– oberste Ebene im Bereich –')) ?></option>
                    <?= wk_parent_options($map, 0, $exclude, $parent) ?></select></label>
            </div>
            <?php wk_editor($body); ?>
            <p class="muted small"><?= e(t('Tipp: Bilder lassen sich auch per Kopieren und Einfügen oder Ziehen einfügen. Strg+S speichert.')) ?></p>
            <?php wk_review_fields($cid, (int)$space['id'], $page); ?>
            <div class="btn-row">
                <?php if (($page['status'] ?? 'draft') === 'published'): ?>
                    <button class="btn primary"><?= e(t('Speichern')) ?></button>
                <?php else: ?>
                    <button class="btn primary" name="publish" value="1"><?= e(t('Speichern und veröffentlichen')) ?></button>
                    <button class="btn" name="publish" value="0"><?= e(t('Als Entwurf speichern')) ?></button>
                <?php endif; ?>
                <a class="btn" href="<?= e($page ? wkurl('doc', ['id' => $page['id']]) : wkurl('doc_space', ['id' => $space['id']])) ?>"><?= e(t('Abbrechen')) ?></a>
            </div>
        </form>
    <?php });
}

// ---------------------------------------------------------------------------
// Aktionen (Reihenfolge, Löschen, Bereiche aus Abteilungen)
// ---------------------------------------------------------------------------

function page_doc_action(): void
{
    $cid = wk_company_id();
    if (!is_post()) abort(405);
    require_post_csrf();
    $action = (string)post('action');

    if ($action === 'spaces_from_departments') {
        if (!wk_can_manage($cid) || wk_is_hub($cid)) abort(403);
        $n = 0;
        foreach (rows('SELECT id, name FROM departments WHERE company_id = ? AND active = 1 ORDER BY name', [$cid]) as $d) {
            if (row('SELECT id FROM wk_spaces WHERE company_id = ? AND name = ?', [$cid, $d['name']])) continue;
            $sid = insert('wk_spaces', ['company_id' => $cid, 'name' => mb_substr($d['name'], 0, 120), 'description' => '', 'icon' => '📁', 'all_read' => 0,
                'sort' => ($n + 1) * 10, 'created_by' => current_user()['id'], 'created_at' => now(), 'updated_at' => now()]);
            insert('wk_space_perms', ['company_id' => $cid, 'space_id' => $sid, 'department_id' => (int)$d['id'], 'perm' => WK_PERM_EDIT]);
            $n++;
        }
        wk_log($cid, 'spaces_from_departments', (string)$n);
        flash($n ? 'success' : 'info', $n ? t('{n} Bereiche wurden angelegt. Die jeweilige Abteilung darf ihren Bereich bearbeiten.', ['n' => $n])
            : t('Es gibt keine Abteilungen ohne eigenen Bereich. Abteilungen pflegen Sie unter On- & Offboarding → Abteilungen & Positionen.'));
        wk_redirect('docs');
    }

    $page = wk_page($cid, (int)post('id', 0));
    if (!$page) abort(404);
    $sid = (int)$page['space_id'];
    wk_require_space($cid, $sid, WK_PERM_VIEW);
    if (!wk_page_visible($cid, $page)) abort(404);
    // Kommentare, Beobachten, Wiederherstellen, Als Vorlage speichern, Freigabe, Dateien (Rechte prüfen die Funktionen selbst)
    wk_action_more($cid, $page, $action);
    wk_action_stage3($cid, $page, $action);
    wk_require_space($cid, $sid, WK_PERM_EDIT);
    switch ($action) {
        case 'move_up':
        case 'move_down':
            $map = wk_children_map($cid, $sid);
            $sib = array_values($map[(int)($page['parent_id'] ?? 0)] ?? []);
            $ids = array_map(fn($s) => (int)$s['id'], $sib);
            $i = array_search((int)$page['id'], $ids, true);
            $j = $action === 'move_up' ? $i - 1 : $i + 1;
            if ($i !== false && isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                foreach ($ids as $k => $pid) q('UPDATE wk_pages SET sort = ? WHERE id = ? AND company_id = ?', [($k + 1) * 10, $pid, $cid]);
            }
            wk_redirect('doc', ['id' => $page['id']]);
        case 'delete':
            if (row('SELECT id FROM wk_pages WHERE parent_id = ? AND company_id = ? LIMIT 1', [$page['id'], $cid])) {
                flash('error', t('Bitte zuerst die Unterseiten verschieben oder löschen.'));
                wk_redirect('doc', ['id' => $page['id']]);
            }
            q('DELETE FROM wk_pages WHERE id = ? AND company_id = ?', [$page['id'], $cid]);
            wk_page_cleanup($cid, (int)$page['id']);
            foreach (rows('SELECT id FROM wk_files WHERE company_id = ? AND page_id = ?', [$cid, $page['id']]) as $f) wk_file_delete($cid, (int)$f['id']);
            wk_log($cid, 'page_delete', '#' . $page['id'] . ' ' . $page['title']);
            flash('success', t('Die Seite wurde gelöscht.'));
            if ($page['parent_id']) wk_redirect('doc', ['id' => $page['parent_id']]);
            wk_redirect('doc_space', ['id' => $sid]);
        default:
            abort(400);
    }
}

// ---------------------------------------------------------------------------
// Bereich anlegen / bearbeiten (mit Rechten je Gruppe)
// ---------------------------------------------------------------------------

function page_doc_space_edit(): void
{
    $cid = wk_company_id();
    if (!wk_can_manage($cid)) abort(403, t('Bereiche pflegen die Administratoren der Firma.'));
    $id = query_int('id');
    $space = $id ? wk_space($cid, $id) : null;
    if ($id && !$space) abort(404);
    $deps = wk_is_hub($cid) ? [] : rows('SELECT id, name FROM departments WHERE company_id = ? AND active = 1 ORDER BY name', [$cid]);
    $perms = [];
    if ($space) foreach (rows('SELECT department_id, perm FROM wk_space_perms WHERE space_id = ? AND company_id = ?', [$space['id'], $cid]) as $p) $perms[(int)$p['department_id']] = (int)$p['perm'];

    if (is_post()) {
        require_post_csrf();
        if (post('action') === 'delete') {
            if (!$space) abort(404);
            if (row('SELECT id FROM wk_pages WHERE space_id = ? AND company_id = ? LIMIT 1', [$space['id'], $cid])) {
                flash('error', t('Der Bereich enthält noch Seiten. Bitte zuerst die Seiten löschen.'));
                wk_redirect('doc_space_edit', ['id' => $space['id']]);
            }
            q('DELETE FROM wk_space_perms WHERE space_id = ? AND company_id = ?', [$space['id'], $cid]);
            q('DELETE FROM wk_spaces WHERE id = ? AND company_id = ?', [$space['id'], $cid]);
            wk_log($cid, 'space_delete', '#' . $space['id'] . ' ' . $space['name']);
            flash('success', t('Der Bereich wurde gelöscht.'));
            wk_redirect('docs');
        }
        $name = mb_substr(trim((string)post('name')), 0, 120);
        $icon = mb_substr(trim((string)post('icon')), 0, 4);
        $data = ['name' => $name, 'icon' => $icon, 'description' => mb_substr(trim((string)post('description')), 0, 500),
            'all_read' => post('all_read') === '1' ? 1 : 0, 'sort' => (int)post('sort', 0), 'updated_at' => now()];
        if ($name === '') {
            flash('error', t('Bitte einen Namen angeben.'));
            wk_redirect('doc_space_edit', $space ? ['id' => $space['id']] : []);
        }
        if ($space) {
            update('wk_spaces', (int)$space['id'], $data);
            $sid = (int)$space['id'];
        } else {
            if (!$data['sort']) $data['sort'] = (int)(row('SELECT MAX(sort) AS m FROM wk_spaces WHERE company_id = ?', [$cid])['m'] ?? 0) + 10;
            $sid = insert('wk_spaces', $data + ['company_id' => $cid, 'created_by' => current_user()['id'], 'created_at' => now()]);
        }
        q('DELETE FROM wk_space_perms WHERE space_id = ? AND company_id = ?', [$sid, $cid]);
        $saved = [];
        foreach ($deps as $d) {
            $p = min(WK_PERM_EDIT, max(0, (int)(($_POST['perm'] ?? [])[(int)$d['id']] ?? 0)));
            if ($p > 0) {
                insert('wk_space_perms', ['company_id' => $cid, 'space_id' => $sid, 'department_id' => (int)$d['id'], 'perm' => $p]);
                $saved[(int)$d['id']] = $p;
            }
        }
        wk_log($cid, $space ? 'space_update' : 'space_create', '#' . $sid . ' ' . $name . ' ' . json_encode(['all_read' => $data['all_read'], 'perms' => $saved]));
        flash('success', t('Der Bereich wurde gespeichert.'));
        wk_redirect('doc_space', ['id' => $sid]);
    }

    $heading = $space ? t('Bereich bearbeiten') : t('Neuer Bereich');
    render_page($heading . ' · ' . t('Dokumente & Anleitungen'), function () use ($cid, $space, $deps, $perms, $heading) {
        echo wk_switcher($cid, 'docs');
        wk_tabs($cid, 'docs'); ?>
        <form method="post" class="form card">
            <?= csrf_field() ?>
            <h2><?= e($heading) ?></h2>
            <div class="grid2">
                <label><?= e(t('Name')) ?> <input name="name" required maxlength="120" value="<?= e($space['name'] ?? '') ?>" placeholder="<?= e(t('z. B. IT, Personal, Richtlinien, Notfall')) ?>"></label>
                <label><?= e(t('Symbol (Emoji)')) ?> <input name="icon" maxlength="4" class="narrow-input" value="<?= e($space['icon'] ?? '📁') ?>"></label>
            </div>
            <label><?= e(t('Beschreibung')) ?> <textarea name="description" rows="2" maxlength="500"><?= e($space['description'] ?? '') ?></textarea></label>
            <label><?= e(t('Reihenfolge')) ?> <input type="number" name="sort" class="narrow-input" value="<?= (int)($space['sort'] ?? 0) ?>"></label>
            <?php if (wk_is_hub($cid)): ?>
            <p class="muted small"><?= e(t('Veröffentlichte Seiten dieses Bereichs sehen alle Firmen mit „Dokumente & Anleitungen“. Pflegen dürfen Superadmin und Verwaltungsadmins mit Zugriff auf alle Firmen.')) ?></p>
            <?php else: ?>
            <h2><?= e(t('Wer darf was?')) ?></h2>
            <label class="check"><input type="checkbox" name="all_read" value="1" <?= (int)($space['all_read'] ?? 0) ? 'checked' : '' ?>> <?= e(t('Alle Mitarbeiter der Firma dürfen diesen Bereich lesen')) ?></label>
            <?php if ($deps): ?>
            <table class="table">
                <thead><tr><th><?= e(t('Gruppe (Abteilung)')) ?></th><th><?= e(t('Recht')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($deps as $d): $p = $perms[(int)$d['id']] ?? 0; ?>
                    <tr><td><?= e($d['name']) ?></td>
                        <td><select name="perm[<?= (int)$d['id'] ?>]">
                            <?php foreach ([WK_PERM_NONE => t('kein Zugriff'), WK_PERM_VIEW => t('ansehen'), WK_PERM_EDIT => t('bearbeiten')] as $k => $l): ?>
                                <option value="<?= $k ?>" <?= $p === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="muted small"><?= e(t('Es gibt noch keine Abteilungen. Abteilungen pflegen Sie unter On- & Offboarding → Abteilungen & Positionen.')) ?></p>
            <?php endif; ?>
            <p class="muted small"><?= e(t('Firmen-Administratoren dürfen immer alles. Der IT-Dienstleister sieht das Wiki nur, wenn Sie das unter Einstellungen erlauben.')) ?></p>
            <?php endif; ?>
            <div class="btn-row">
                <button class="btn primary"><?= e(t('Speichern')) ?></button>
                <a class="btn" href="<?= e($space ? wkurl('doc_space', ['id' => $space['id']]) : wkurl('docs')) ?>"><?= e(t('Abbrechen')) ?></a>
            </div>
        </form>
        <?php if ($space): ?>
        <form method="post" class="card danger-zone" data-confirm="<?= e(t('Diesen Bereich wirklich löschen?')) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <h2><?= e(t('Bereich löschen')) ?></h2>
            <p class="muted small"><?= e(t('Nur leere Bereiche lassen sich löschen.')) ?></p>
            <button class="btn danger"><?= e(t('Bereich löschen')) ?></button>
        </form>
        <?php endif;
    });
}

// ---------------------------------------------------------------------------
// Einstellungen
// ---------------------------------------------------------------------------

function page_doc_settings(): void
{
    $cid = wk_company_id();
    if (wk_is_hub($cid)) abort(404);
    if (!wk_can_manage($cid)) abort(403, t('Die Einstellungen pflegen die Administratoren der Firma.'));
    $staff = is_staff_role(current_user()['role']);
    $co = wk_company($cid);
    if (is_post()) abort(400);
    render_page(t('Einstellungen') . ' · ' . t('Dokumente & Anleitungen'), function () use ($cid, $co, $staff) {
        echo wk_switcher($cid, 'doc_settings');
        wk_tabs($cid, 'doc_settings'); ?>
        <div class="card">
            <h2><?= e(t('Einsicht für den IT-Dienstleister')) ?></h2>
            <?= provider_access_note($cid, 'wk_provider_access') ?>
        </div>
        <div class="card">
            <h2><?= e(t('Bereiche aus den Abteilungen')) ?></h2>
            <p class="muted small"><?= e(t('Legt für jede Abteilung ohne eigenen Bereich einen Bereich an. Die Abteilung darf ihn bearbeiten, alle anderen sehen ihn nicht, bis Sie das ändern.')) ?></p>
            <form method="post" action="<?= e(wkurl('doc_action')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="spaces_from_departments">
                <button class="btn"><?= e(t('Bereiche aus den Abteilungen anlegen')) ?></button></form>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Suche
// ---------------------------------------------------------------------------

/** Treffer in einem Wiki (Firma oder „Für alle Kunden“): Titel, Text und Dateinamen sichtbarer Seiten */
function wk_search_in(int $cid, string $q): array
{
    $readable = array_keys(array_filter(wk_space_perms($cid), fn($p) => $p > WK_PERM_NONE));
    if (!$readable) return [];
    // „!“ als Escape-Zeichen: funktioniert in MySQL und SQLite gleich (Backslash nicht)
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
    $in = implode(',', array_map('intval', $readable));
    $vis = wk_visible_sql($cid, 'p');
    $cand = rows("SELECT p.*, s.name AS space_name, s.icon FROM wk_pages p JOIN wk_spaces s ON s.id = p.space_id
        WHERE p.company_id = ? AND p.space_id IN ($in) AND $vis AND (p.title LIKE ? ESCAPE '!' OR p.body LIKE ? ESCAPE '!'
            OR EXISTS (SELECT 1 FROM wk_files f WHERE f.page_id = p.id AND f.company_id = p.company_id AND f.name LIKE ? ESCAPE '!'))
        ORDER BY p.updated_at DESC LIMIT 300", [$cid, $like, $like, $like]);
    $hits = [];
    foreach ($cand as $p) {
        if (!wk_page_visible($cid, $p)) continue;
        $inTitle = mb_stripos($p['title'], $q) !== false;
        $text = wk_excerpt((string)$p['body'], $q, 100000);
        $files = rows("SELECT name FROM wk_files WHERE company_id = ? AND page_id = ? AND name LIKE ? ESCAPE '!'", [$cid, $p['id'], $like]);
        // Treffer nur im HTML (z. B. in Bilddaten) zählen nicht
        if (!$inTitle && mb_stripos($text, $q) === false && !$files) continue;
        $hits[] = ['id' => $p['id'], 'title' => $p['title'], 'status' => $p['status'], 'updated_at' => $p['updated_at'], 'space_name' => $p['space_name'], 'icon' => $p['icon'],
            '_rank' => $inTitle ? 0 : 1, '_excerpt' => wk_excerpt((string)$p['body'], $q), '_files' => array_column($files, 'name'), '_hub' => wk_is_hub($cid)];
        if (count($hits) >= 50) break;
    }
    return $hits;
}

function page_doc_search(): void
{
    $cid = wk_company_id();
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $hits = [];
    if ($q !== '' && mb_strlen($q) >= 2) {
        $hits = wk_search_in($cid, $q);
        // Firmen finden auch die Seiten ihres IT-Dienstleisters
        if (!wk_is_hub($cid)) $hits = array_merge($hits, wk_search_in(0, $q));
        usort($hits, fn($a, $b) => [$a['_rank'], $b['updated_at']] <=> [$b['_rank'], $a['updated_at']]);
    }
    render_page(t('Suche') . ' · ' . t('Dokumente & Anleitungen'), function () use ($cid, $q, $hits) {
        echo wk_switcher($cid, 'docs');
        wk_tabs($cid, '', $q); ?>
        <div class="card">
            <h2><?= e(t('Suchergebnisse für „{q}“', ['q' => $q])) ?></h2>
            <?php if (mb_strlen($q) < 2): ?><p class="muted"><?= e(t('Bitte mindestens 2 Zeichen eingeben.')) ?></p>
            <?php elseif (!$hits): ?><p class="muted"><?= e(t('Keine Seiten gefunden.')) ?></p>
            <?php else: ?>
                <ul class="wk-hits">
                    <?php foreach ($hits as $h): ?>
                        <li><a href="<?= e($h['_hub'] ? url('doc', ['hub' => 1, 'id' => $h['id']]) : wkurl('doc', ['id' => $h['id']])) ?>"><strong><?= e($h['title']) ?></strong></a>
                            <?= $h['status'] === 'draft' ? '<span class="tag">' . e(t('Entwurf')) . '</span>' : '' ?>
                            <span class="muted small"><?= e(($h['_hub'] ? t('Für alle Kunden') . ' · ' : '') . ($h['icon'] ?: '📁') . ' ' . $h['space_name']) ?> · <?= fmt_dt($h['updated_at']) ?></span>
                            <?php if ($h['_excerpt'] !== ''): ?><br><span class="small"><?= e($h['_excerpt']) ?></span><?php endif; ?>
                            <?php if ($h['_files']): ?><br><span class="small">📎 <?= e(implode(', ', $h['_files'])) ?></span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php });
}
