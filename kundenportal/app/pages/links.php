<?php
/**
 * App „Linksammlung“: Startseite je Firma im Stil von SharePoint mit Reitern,
 * Überschriften (Bereichen) und Link-Kacheln mit Bild und Beschreibung.
 *
 * Ansehen dürfen alle Benutzer einer Firma mit Freigabe. Bearbeiten dürfen
 * Firmen-Administratoren und der Dienstleister (Schalter „Seite bearbeiten“).
 */
declare(strict_types=1);

const LINKS_APP = 'links';
const LINK_IMAGE_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
const LINK_IMAGE_MAX_BYTES = 3145728;   // Upload bis 3 MB, gespeichert wird auf höchstens 900 px Breite verkleinert
const LINK_IMAGE_MAX_WIDTH = 900;
const LINK_COLORS = [1 => 'Blau', 2 => 'Petrol', 3 => 'Grün', 4 => 'Orange', 5 => 'Rot', 6 => 'Lila', 7 => 'Grau', 8 => 'Nachtblau'];
const LINK_LAYOUTS = ['tiles' => 'Kacheln mit Bild', 'compact' => 'Kompakte Kacheln', 'list' => 'Liste'];

// ---------------------------------------------------------------------------
// Firmenkontext, Rechte, Hilfsfunktionen
// ---------------------------------------------------------------------------

function links_company_id(): int
{
    $u = require_app(LINKS_APP);
    $cid = context_company_id();
    if (!$cid) abort(404, 'Es ist noch keine Firma angelegt.');
    require_company_access($cid);
    if (!row('SELECT id FROM companies WHERE id = ?', [$cid])) abort(404);
    if (!is_staff_role($u['role']) && !company_has_app($cid, LINKS_APP)) abort(403);
    $GLOBALS['links_cid'] = $cid;
    if (!row('SELECT id FROM link_tabs WHERE company_id = ?', [$cid])) {
        insert('link_tabs', ['company_id' => $cid, 'name' => 'Start', 'sort' => 10, 'created_at' => now()]);
    }
    return $cid;
}

function links_can_manage(): bool
{
    $u = current_user();
    return $u && in_array($u['role'], ['superadmin', 'admin', 'firma_admin'], true);
}

function links_require_manage(): void
{
    if (!links_can_manage()) abort(403, 'Die Linksammlung dürfen nur Firmen-Administratoren bearbeiten.');
}

/** URL innerhalb der App; Dienstleister behalten die gewählte Firma */
function lurl(string $route, array $params = []): string
{
    if (is_admin() && !empty($GLOBALS['links_cid'])) $params = ['company' => (int)$GLOBALS['links_cid']] + $params;
    return url($route, $params);
}

function lredirect(string $route, array $params = []): never
{
    header('Location: ' . lurl($route, $params));
    exit;
}

function links_app_name(): string
{
    return (string)(row('SELECT name FROM apps WHERE slug = ?', [LINKS_APP])['name'] ?? 'Linksammlung');
}

/** Adresse prüfen und vervollständigen (www.beispiel.de → https://www.beispiel.de). Null = ungültig. */
function links_normalize_url(string $u): ?string
{
    $u = trim($u);
    if ($u === '') return null;
    if (filter_var($u, FILTER_VALIDATE_EMAIL)) return 'mailto:' . $u;
    if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $u) && preg_match('#^[\p{L}0-9-]+(\.[\p{L}0-9-]+)+(:\d+)?([/?\#].*)?$#u', $u)) $u = 'https://' . $u;
    if (preg_match('#^https?://[^\s<>"]+$#i', $u)) return mb_substr($u, 0, 1000);
    if (preg_match('#^mailto:[^\s<>"]+$#i', $u)) return mb_substr($u, 0, 1000);
    if (preg_match('#^tel:\+?[0-9 ()/-]{3,40}$#i', $u)) return $u;
    return null;
}

/** Kurze Herkunftsangabe für die Kachel */
function link_host(string $url): string
{
    if (stripos($url, 'mailto:') === 0) return 'E-Mail';
    if (stripos($url, 'tel:') === 0) return 'Telefon';
    $h = (string)parse_url($url, PHP_URL_HOST);
    return preg_replace('/^www\./i', '', $h);
}

function link_is_web(string $url): bool
{
    return (bool)preg_match('#^https?://#i', $url);
}

/** Bild für eine Kachel prüfen und verkleinern. Liefert [mime, base64] oder eine Fehlermeldung. */
function links_read_image(array $f): array|string
{
    if ($f['error'] !== UPLOAD_ERR_OK) return 'Das Bild konnte nicht hochgeladen werden.';
    if ($f['size'] > LINK_IMAGE_MAX_BYTES) return 'Das Bild ist zu groß (höchstens 3 MB).';
    $info = @getimagesize($f['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!isset(LINK_IMAGE_MIMES[$mime])) return 'Bitte ein Bild im Format PNG, JPG, GIF oder WebP hochladen.';
    $raw = (string)file_get_contents($f['tmp_name']);
    // Große Bilder verkleinern, wenn der Server die GD-Erweiterung hat (spart Platz und Ladezeit)
    if ($info[0] > LINK_IMAGE_MAX_WIDTH && $mime !== 'image/gif' && function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring($raw);
        if ($src) {
            $w = LINK_IMAGE_MAX_WIDTH;
            $h = max(1, (int)round($info[1] * $w / $info[0]));
            $dst = imagecreatetruecolor($w, $h);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
            ob_start();
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($dst, null, 85),
                'image/webp' => function_exists('imagewebp') ? imagewebp($dst, null, 85) : false,
                default      => imagepng($dst, null, 7),
            };
            $out = (string)ob_get_clean();
            if ($ok && $out !== '' && strlen($out) < strlen($raw)) $raw = $out;
        }
    }
    return [$mime, base64_encode($raw)];
}

/** Reihenfolge neu durchnummerieren und optional einen Eintrag um eine Stelle verschieben */
function links_reorder(string $table, string $scopeCol, int $scopeVal, int $cid, int $moveId = 0, int $dir = 0): void
{
    if (!in_array($table, ['link_tabs', 'link_sections', 'links'], true)) return;
    $where = $scopeCol === '' ? 'company_id = ?' : "company_id = ? AND $scopeCol = ?";
    $params = $scopeCol === '' ? [$cid] : [$cid, $scopeVal];
    $ids = array_map('intval', array_column(rows("SELECT id FROM $table WHERE $where ORDER BY sort, id", $params), 'id'));
    $i = array_search($moveId, $ids, true);
    if ($i !== false && $dir !== 0) {
        $j = $i + ($dir < 0 ? -1 : 1);
        if ($j >= 0 && $j < count($ids)) [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    }
    foreach ($ids as $n => $id) q("UPDATE $table SET sort = ? WHERE id = ?", [($n + 1) * 10, $id]);
}

function links_next_sort(string $table, string $scopeCol, int $scopeVal, int $cid): int
{
    if (!in_array($table, ['link_tabs', 'link_sections', 'links'], true)) return 10;
    $where = $scopeCol === '' ? 'company_id = ?' : "company_id = ? AND $scopeCol = ?";
    $params = $scopeCol === '' ? [$cid] : [$cid, $scopeVal];
    return (int)(row("SELECT MAX(sort) AS m FROM $table WHERE $where", $params)['m'] ?? 0) + 10;
}

// ---------------------------------------------------------------------------
// Ansicht (und Bearbeiten-Modus)
// ---------------------------------------------------------------------------

function page_links(): void
{
    $cid = links_company_id();
    $tabs = rows('SELECT * FROM link_tabs WHERE company_id = ? ORDER BY sort, id', [$cid]);
    $tabId = query_int('tab');
    if (!in_array($tabId, array_map('intval', array_column($tabs, 'id')), true)) $tabId = (int)$tabs[0]['id'];
    $tab = null;
    foreach ($tabs as $t) if ((int)$t['id'] === $tabId) $tab = $t;
    $edit = links_can_manage() && query_int('edit') === 1;
    $search = trim((string)($_GET['q'] ?? ''));

    $linkCols = 'l.id, l.section_id, l.title, l.url, l.description, l.icon, l.color, l.new_window, l.sort, l.updated_at,
        CASE WHEN l.image_mime <> \'\' THEN 1 ELSE 0 END AS has_image';
    if ($search !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 100)) . '%';
        $results = rows("SELECT $linkCols, s.name AS section_name, t.name AS tab_name, t.id AS tab_id
            FROM links l JOIN link_sections s ON s.id = l.section_id JOIN link_tabs t ON t.id = s.tab_id
            WHERE l.company_id = ? AND (l.title LIKE ? OR l.description LIKE ? OR l.url LIKE ? OR s.name LIKE ?)
            ORDER BY t.sort, s.sort, l.sort, l.id LIMIT 200", [$cid, $like, $like, $like, $like]);
        $sections = [];
        $bySection = [];
    } else {
        $results = [];
        $sections = rows('SELECT * FROM link_sections WHERE company_id = ? AND tab_id = ? ORDER BY sort, id', [$cid, $tabId]);
        $bySection = [];
        if ($sections) {
            $in = implode(',', array_map('intval', array_column($sections, 'id')));
            foreach (rows("SELECT $linkCols FROM links l WHERE l.company_id = ? AND l.section_id IN ($in) ORDER BY l.sort, l.id", [$cid]) as $l) {
                $bySection[(int)$l['section_id']][] = $l;
            }
        }
    }

    render_page(links_app_name(), function () use ($cid, $tabs, $tab, $tabId, $edit, $search, $sections, $bySection, $results) {
        $base = ['tab' => $tabId] + ($edit ? ['edit' => 1] : []);
        if (is_admin()) echo company_switcher($cid, 'links', $edit ? ['edit' => 1] : []);
        ?>
        <div class="links-bar">
            <nav class="link-tabs" aria-label="Reiter">
                <?php foreach ($tabs as $t): ?>
                    <a href="<?= e(lurl('links', ['tab' => $t['id']] + ($edit ? ['edit' => 1] : []))) ?>" class="<?= (int)$t['id'] === $tabId && $search === '' ? 'active' : '' ?>"><?= e($t['name']) ?></a>
                <?php endforeach; ?>
                <?php if ($edit): ?>
                <details class="more">
                    <summary class="link-tab-add" title="Reiter hinzufügen">+ Reiter</summary>
                    <div class="more-menu">
                        <form method="post" action="<?= e(lurl('link_action')) ?>" class="form">
                            <?= csrf_field() ?><input type="hidden" name="action" value="tab_add">
                            <label>Name des neuen Reiters<input name="name" required maxlength="120" placeholder="z. B. Personal, IT, Vertrieb"></label>
                            <button class="btn primary small">Reiter anlegen</button>
                        </form>
                    </div>
                </details>
                <?php endif; ?>
            </nav>
            <div class="links-tools">
                <form method="get" class="link-search" role="search">
                    <input type="hidden" name="r" value="links">
                    <?php if (is_admin()): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
                    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Links durchsuchen …" aria-label="Links durchsuchen">
                </form>
                <?php if (links_can_manage()): ?>
                    <?php if ($edit): ?>
                        <a class="btn primary" href="<?= e(lurl('links', ['tab' => $tabId])) ?>">Fertig</a>
                    <?php else: ?>
                        <a class="btn" href="<?= e(lurl('links', ['tab' => $tabId, 'edit' => 1])) ?>">✎ Seite bearbeiten</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($search !== ''): ?>
            <p class="muted"><?= count($results) ?> Treffer für „<?= e($search) ?>“ · <a href="<?= e(lurl('links', ['tab' => $tabId])) ?>">Suche beenden</a></p>
            <?php if ($results): ?>
                <div class="link-grid layout-tiles">
                    <?php foreach ($results as $l) links_tile($l, false, $l['tab_name'] . ' › ' . $l['section_name']); ?>
                </div>
            <?php endif; ?>
            <?php return;
        endif; ?>

        <?php if ($edit): ?>
            <div class="card link-edit-hint">
                <strong>Bearbeiten-Modus.</strong> Neue Überschriften fügen Sie unten hinzu, Links mit „+ Link hinzufügen“ im jeweiligen Bereich.
                Mit ◀ ▶ und ▲ ▼ ändern Sie die Reihenfolge.
                <details class="more">
                    <summary class="btn small">Reiter „<?= e($tab['name']) ?>“ …</summary>
                    <div class="more-menu">
                        <form method="post" action="<?= e(lurl('link_action')) ?>" class="form">
                            <?= csrf_field() ?><input type="hidden" name="action" value="tab_save"><input type="hidden" name="id" value="<?= $tabId ?>">
                            <label>Name<input name="name" value="<?= e($tab['name']) ?>" required maxlength="120"></label>
                            <button class="btn small primary">Umbenennen</button>
                        </form>
                        <div class="btn-row">
                            <?php foreach ([-1 => '◀ nach links', 1 => 'nach rechts ▶'] as $dir => $label): ?>
                            <form method="post" action="<?= e(lurl('link_action')) ?>" class="inline"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="tab_move"><input type="hidden" name="id" value="<?= $tabId ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
                                <button class="btn small"><?= $label ?></button></form>
                            <?php endforeach; ?>
                        </div>
                        <form method="post" action="<?= e(lurl('link_action')) ?>"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="tab_delete"><input type="hidden" name="id" value="<?= $tabId ?>">
                            <button class="btn small danger" data-confirm="Reiter „<?= e($tab['name']) ?>“ mit allen Überschriften und Links löschen?">Reiter löschen</button></form>
                    </div>
                </details>
            </div>
        <?php endif; ?>

        <?php if (!$sections): ?>
            <div class="card link-empty">
                <p><strong>Auf diesem Reiter gibt es noch keine Links.</strong></p>
                <?php if (links_can_manage() && !$edit): ?>
                    <p><a class="btn primary" href="<?= e(lurl('links', ['tab' => $tabId, 'edit' => 1])) ?>">Seite bearbeiten und Links anlegen</a></p>
                <?php elseif (!links_can_manage()): ?>
                    <p class="muted">Links werden von Ihrem Firmen-Administrator gepflegt.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($sections as $s): $sid = (int)$s['id']; $items = $bySection[$sid] ?? []; ?>
            <section class="link-section">
                <div class="ls-head">
                    <h2><?= e($s['name']) ?></h2>
                    <?php if ($edit): ?>
                    <div class="ls-tools">
                        <?php foreach ([-1 => '▲', 1 => '▼'] as $dir => $sym): ?>
                        <form method="post" action="<?= e(lurl('link_action')) ?>" class="inline"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="section_move"><input type="hidden" name="id" value="<?= $sid ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
                            <button class="btn small" title="<?= $dir < 0 ? 'nach oben' : 'nach unten' ?>"><?= $sym ?></button></form>
                        <?php endforeach; ?>
                        <details class="more">
                            <summary class="btn small">✎ Überschrift</summary>
                            <div class="more-menu">
                                <form method="post" action="<?= e(lurl('link_action')) ?>" class="form">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="section_save"><input type="hidden" name="id" value="<?= $sid ?>">
                                    <?php links_section_fields($cid, $s); ?>
                                    <button class="btn small primary">Speichern</button>
                                </form>
                                <form method="post" action="<?= e(lurl('link_action')) ?>"><?= csrf_field() ?>
                                    <input type="hidden" name="action" value="section_delete"><input type="hidden" name="id" value="<?= $sid ?>">
                                    <button class="btn small danger" data-confirm="Überschrift „<?= e($s['name']) ?>“ mit <?= count($items) ?> Link(s) löschen?">Überschrift und Links löschen</button></form>
                            </div>
                        </details>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($s['description'] !== ''): ?><p class="muted ls-desc"><?= e($s['description']) ?></p><?php endif; ?>
                <div class="link-grid layout-<?= e(isset(LINK_LAYOUTS[$s['layout']]) ? $s['layout'] : 'tiles') ?>">
                    <?php foreach ($items as $l) links_tile($l, $edit); ?>
                    <?php if ($edit): ?>
                        <a class="link-tile lt-add" href="<?= e(lurl('link_edit', ['section' => $sid])) ?>"><span>＋</span> Link hinzufügen</a>
                    <?php endif; ?>
                </div>
                <?php if (!$items && !$edit): ?><p class="muted small">Noch keine Links in diesem Bereich.</p><?php endif; ?>
            </section>
        <?php endforeach; ?>

        <?php if ($edit): ?>
            <div class="card">
                <h2>Überschrift hinzufügen</h2>
                <form method="post" action="<?= e(lurl('link_action')) ?>" class="form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="section_add"><input type="hidden" name="tab_id" value="<?= $tabId ?>">
                    <div class="grid2">
                        <label>Überschrift / Kategorie<input name="name" required maxlength="190" placeholder="z. B. Wichtige Programme, Formulare, Ansprechpartner"></label>
                        <label>Darstellung<select name="layout"><?php foreach (LINK_LAYOUTS as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
                    </div>
                    <label>Beschreibung (optional)<input name="description" maxlength="500"></label>
                    <button class="btn primary">Überschrift anlegen</button>
                </form>
            </div>
        <?php endif;
    });
}

/** Felder einer Überschrift (für das Bearbeiten-Menü) */
function links_section_fields(int $cid, array $s): void
{
    $tabs = rows('SELECT id, name FROM link_tabs WHERE company_id = ? ORDER BY sort, id', [$cid]); ?>
    <label>Überschrift<input name="name" value="<?= e($s['name']) ?>" required maxlength="190"></label>
    <label>Beschreibung<input name="description" value="<?= e($s['description']) ?>" maxlength="500"></label>
    <label>Darstellung<select name="layout"><?php foreach (LINK_LAYOUTS as $k => $v): ?><option value="<?= $k ?>" <?= $s['layout'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label>Reiter<select name="tab_id"><?php foreach ($tabs as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === (int)$s['tab_id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></label>
<?php }

/** Eine Link-Kachel */
function links_tile(array $l, bool $edit, string $where = ''): void
{
    $id = (int)$l['id'];
    $color = isset(LINK_COLORS[(int)$l['color']]) ? (int)$l['color'] : 1;
    $newWin = (int)$l['new_window'] && link_is_web($l['url']);
    $initial = $l['icon'] !== '' ? $l['icon'] : mb_strtoupper(mb_substr($l['title'], 0, 1));
    ?>
    <div class="lt-wrap">
        <a class="link-tile lc-<?= $color ?>" href="<?= e($l['url']) ?>" <?= $newWin ? 'target="_blank" rel="noopener noreferrer"' : 'rel="noreferrer"' ?> title="<?= e($l['url']) ?>">
            <?php if ((int)$l['has_image']): ?>
                <span class="lt-img"><img src="<?= e(lurl('link_image', ['id' => $id, 'v' => substr(md5((string)$l['updated_at']), 0, 8)])) ?>" alt="" loading="lazy"></span>
            <?php else: ?>
                <span class="lt-img lt-ph"><span><?= e($initial) ?></span></span>
            <?php endif; ?>
            <span class="lt-body">
                <span class="lt-title"><?= e($l['title']) ?></span>
                <?php if ($l['description'] !== ''): ?><span class="lt-desc"><?= e($l['description']) ?></span><?php endif; ?>
                <span class="lt-host"><?= e($where !== '' ? $where : link_host($l['url'])) ?><?= $newWin ? ' ↗' : '' ?></span>
            </span>
        </a>
        <?php if ($edit): ?>
        <div class="lt-tools">
            <?php foreach ([-1 => '◀', 1 => '▶'] as $dir => $sym): ?>
            <form method="post" action="<?= e(lurl('link_action')) ?>" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="link_move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
                <button class="btn small" title="<?= $dir < 0 ? 'nach vorne' : 'nach hinten' ?>"><?= $sym ?></button></form>
            <?php endforeach; ?>
            <a class="btn small" href="<?= e(lurl('link_edit', ['id' => $id])) ?>">✎ Bearbeiten</a>
            <form method="post" action="<?= e(lurl('link_action')) ?>" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="link_delete"><input type="hidden" name="id" value="<?= $id ?>">
                <button class="btn small danger" title="Löschen" data-confirm="Link „<?= e($l['title']) ?>“ löschen?">🗑</button></form>
        </div>
        <?php endif; ?>
    </div>
<?php }

// ---------------------------------------------------------------------------
// Link anlegen / bearbeiten
// ---------------------------------------------------------------------------

function page_link_edit(): void
{
    $cid = links_company_id();
    links_require_manage();
    $me = current_user();
    $id = query_int('id') ?: (int)post('id', 0);
    $link = $id ? row('SELECT * FROM links WHERE id = ? AND company_id = ?', [$id, $cid]) : null;
    if ($id && !$link) abort(404);
    $sectionId = $link ? (int)$link['section_id'] : (query_int('section') ?: (int)post('section_id', 0));
    $sections = rows('SELECT s.id, s.name, s.tab_id, t.name AS tab_name FROM link_sections s JOIN link_tabs t ON t.id = s.tab_id
        WHERE s.company_id = ? ORDER BY t.sort, t.id, s.sort, s.id', [$cid]);
    if (!$sections) {
        flash('info', 'Legen Sie zuerst eine Überschrift an.');
        lredirect('links', ['edit' => 1]);
    }

    $form = $link ?: ['title' => '', 'url' => '', 'description' => '', 'icon' => '', 'color' => 1, 'new_window' => 1, 'section_id' => $sectionId, 'image_mime' => ''];
    $error = '';
    if (is_post()) {
        require_post_csrf();
        if (post('action') === 'delete' && $link) {
            q('DELETE FROM links WHERE id = ?', [$link['id']]);
            audit('link_deleted', $link['title'], $cid);
            flash('success', 'Der Link wurde gelöscht.');
            lredirect('links', ['tab' => links_tab_of_section((int)$link['section_id'], $cid), 'edit' => 1]);
        }
        $form = array_merge($form, [
            'title' => mb_substr(trim(post('title')), 0, 190),
            'url' => trim(post('url')),
            'description' => mb_substr(trim(post('description')), 0, 1000),
            'icon' => mb_substr(trim(post('icon')), 0, 4),
            'color' => isset(LINK_COLORS[(int)post('color', 1)]) ? (int)post('color', 1) : 1,
            'new_window' => post('new_window') === '1' ? 1 : 0,
            'section_id' => (int)post('section_id', 0),
        ]);
        $url = links_normalize_url($form['url']);
        $validSection = in_array($form['section_id'], array_map('intval', array_column($sections, 'id')), true);
        $img = null;
        $f = $_FILES['image'] ?? null;
        if ($f && $f['error'] !== UPLOAD_ERR_NO_FILE) $img = links_read_image($f);

        if ($form['title'] === '') $error = 'Bitte einen Titel angeben.';
        elseif ($url === null) $error = 'Bitte eine gültige Adresse angeben, z. B. https://www.beispiel.de, eine E-Mail-Adresse oder tel:+49…';
        elseif (!$validSection) $error = 'Bitte einen Bereich (Überschrift) wählen.';
        elseif (is_string($img)) $error = $img;
        else {
            $data = ['title' => $form['title'], 'url' => $url, 'description' => $form['description'], 'icon' => $form['icon'],
                'color' => $form['color'], 'new_window' => $form['new_window'], 'section_id' => $form['section_id'], 'updated_at' => now()];
            if (is_array($img)) { $data['image_mime'] = $img[0]; $data['image_data'] = $img[1]; }
            elseif (post('remove_image') === '1') { $data['image_mime'] = ''; $data['image_data'] = null; }
            if ($link) {
                if ((int)$link['section_id'] !== $form['section_id']) $data['sort'] = links_next_sort('links', 'section_id', $form['section_id'], $cid);
                update('links', (int)$link['id'], $data);
                audit('link_updated', $form['title'], $cid);
                flash('success', 'Der Link wurde gespeichert.');
            } else {
                insert('links', $data + ['company_id' => $cid, 'sort' => links_next_sort('links', 'section_id', $form['section_id'], $cid),
                    'image_mime' => '', 'created_by' => $me['id'], 'created_at' => now()]);
                audit('link_created', $form['title'], $cid);
                flash('success', 'Der Link wurde angelegt.');
            }
            if (post('next') === 'another') lredirect('link_edit', ['section' => $form['section_id']]);
            lredirect('links', ['tab' => links_tab_of_section($form['section_id'], $cid), 'edit' => 1]);
        }
    }

    $backTab = $form['section_id'] ? links_tab_of_section((int)$form['section_id'], $cid) : 0;
    render_page($link ? 'Link bearbeiten' : 'Link hinzufügen', function () use ($link, $form, $sections, $error, $backTab) { ?>
        <p><a href="<?= e(lurl('links', ['tab' => $backTab, 'edit' => 1])) ?>">← zurück zur <?= e(links_app_name()) ?></a></p>
        <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
        <div class="grid2">
        <form method="post" enctype="multipart/form-data" class="card form">
            <?= csrf_field() ?>
            <?php if ($link): ?><input type="hidden" name="id" value="<?= (int)$link['id'] ?>"><?php endif; ?>
            <label>Titel<input name="title" value="<?= e($form['title']) ?>" required maxlength="190" autofocus placeholder="z. B. DATEV Unternehmen online"></label>
            <label>Adresse<input name="url" value="<?= e($form['url']) ?>" required maxlength="1000" placeholder="https://… , E-Mail-Adresse oder tel:+49…"></label>
            <label>Beschreibung<textarea name="description" rows="3" maxlength="1000" placeholder="Wofür ist der Link? Kurz und knapp, z. B. „Belege hochladen und Auswertungen ansehen“"><?= e($form['description']) ?></textarea></label>
            <label>Bereich (Überschrift)
                <select name="section_id" required>
                    <?php $lastTab = null; foreach ($sections as $s):
                        if ($s['tab_id'] !== $lastTab) { if ($lastTab !== null) echo '</optgroup>'; echo '<optgroup label="Reiter: ' . e($s['tab_name']) . '">'; $lastTab = $s['tab_id']; } ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$form['section_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; if ($lastTab !== null) echo '</optgroup>'; ?>
                </select>
            </label>
            <fieldset>
                <legend>Bild</legend>
                <?php if ($link && $link['image_mime'] !== ''): ?>
                    <img class="link-preview" src="<?= e(lurl('link_image', ['id' => $link['id'], 'v' => substr(md5((string)$link['updated_at']), 0, 8)])) ?>" alt="Aktuelles Bild">
                    <label class="check"><input type="checkbox" name="remove_image" value="1"> Bild entfernen</label>
                <?php endif; ?>
                <label><?= $link && $link['image_mime'] !== '' ? 'Neues Bild hochladen' : 'Bild hochladen' ?> (PNG, JPG, GIF oder WebP, max. 3 MB)
                    <input type="file" name="image" accept="image/png,image/jpeg,image/gif,image/webp"></label>
                <p class="muted small">Am besten ein Querformat (16:9), z. B. ein Screenshot oder Logo. Ohne Bild zeigt die Kachel Symbol und Farbe.</p>
                <div class="grid2">
                    <label>Symbol ohne Bild<input name="icon" value="<?= e($form['icon']) ?>" maxlength="4" placeholder="z. B. 📊 oder DV"></label>
                    <div>
                        <span class="field-label">Farbe</span>
                        <div class="color-picks">
                            <?php foreach (LINK_COLORS as $n => $name): ?>
                                <label class="color-pick lc-<?= $n ?>" title="<?= e($name) ?>"><input type="radio" name="color" value="<?= $n ?>" <?= (int)$form['color'] === $n ? 'checked' : '' ?> aria-label="<?= e($name) ?>"></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </fieldset>
            <label class="check"><input type="checkbox" name="new_window" value="1" <?= (int)$form['new_window'] ? 'checked' : '' ?>> In neuem Fenster öffnen</label>
            <div class="btn-row">
                <button class="btn primary">Speichern</button>
                <?php if (!$link): ?><button class="btn" name="next" value="another">Speichern und weiteren Link anlegen</button><?php endif; ?>
            </div>
        </form>
        <div class="card">
            <h2>So sieht die Kachel aus</h2>
            <p class="muted small">Vorschau nach dem Speichern. Das Bild wird oben angezeigt, darunter Titel, Beschreibung und die Adresse.</p>
            <?php if ($link): ?>
                <div class="link-grid layout-tiles one">
                    <?php links_tile($link + ['has_image' => $link['image_mime'] !== '' ? 1 : 0], false); ?>
                </div>
                <form method="post" class="danger-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$link['id'] ?>"><input type="hidden" name="action" value="delete">
                    <button class="btn small danger" data-confirm="Link „<?= e($link['title']) ?>“ löschen?">Link löschen</button></form>
            <?php endif; ?>
        </div>
        </div>
    <?php });
}

function links_tab_of_section(int $sectionId, int $cid): int
{
    return (int)(row('SELECT tab_id FROM link_sections WHERE id = ? AND company_id = ?', [$sectionId, $cid])['tab_id'] ?? 0);
}

// ---------------------------------------------------------------------------
// Aktionen (Reiter, Überschriften, Reihenfolge, Löschen)
// ---------------------------------------------------------------------------

function page_link_action(): void
{
    $cid = links_company_id();
    links_require_manage();
    if (!is_post()) abort(405);
    require_post_csrf();
    $action = post('action');
    $id = (int)post('id', 0);
    $dir = (int)post('dir', 0) < 0 ? -1 : 1;
    $tabId = 0;

    if ($action === 'tab_add') {
        $name = mb_substr(trim(post('name')), 0, 120);
        if ($name === '') { flash('error', 'Bitte einen Namen angeben.'); lredirect('links', ['edit' => 1]); }
        $tabId = insert('link_tabs', ['company_id' => $cid, 'name' => $name, 'sort' => links_next_sort('link_tabs', '', 0, $cid), 'created_at' => now()]);
        audit('link_tab_created', $name, $cid);
        flash('success', 'Der Reiter wurde angelegt. Fügen Sie jetzt Überschriften und Links hinzu.');
    } elseif (str_starts_with($action, 'tab_')) {
        $tab = row('SELECT * FROM link_tabs WHERE id = ? AND company_id = ?', [$id, $cid]) ?? abort(404);
        $tabId = (int)$tab['id'];
        if ($action === 'tab_save') {
            $name = mb_substr(trim(post('name')), 0, 120);
            if ($name !== '') { update('link_tabs', $tabId, ['name' => $name]); audit('link_tab_updated', $name, $cid); flash('success', 'Der Reiter wurde umbenannt.'); }
        } elseif ($action === 'tab_move') {
            links_reorder('link_tabs', '', 0, $cid, $tabId, $dir);
        } elseif ($action === 'tab_delete') {
            $secIds = array_map('intval', array_column(rows('SELECT id FROM link_sections WHERE tab_id = ? AND company_id = ?', [$tabId, $cid]), 'id'));
            if ($secIds) q('DELETE FROM links WHERE company_id = ? AND section_id IN (' . implode(',', $secIds) . ')', [$cid]);
            q('DELETE FROM link_sections WHERE tab_id = ? AND company_id = ?', [$tabId, $cid]);
            q('DELETE FROM link_tabs WHERE id = ?', [$tabId]);
            audit('link_tab_deleted', $tab['name'], $cid);
            flash('success', 'Der Reiter „' . $tab['name'] . '“ wurde gelöscht.');
            $tabId = 0;
        } else abort(400);
    } elseif ($action === 'section_add') {
        $tabId = (int)post('tab_id', 0);
        if (!row('SELECT id FROM link_tabs WHERE id = ? AND company_id = ?', [$tabId, $cid])) abort(404);
        $name = mb_substr(trim(post('name')), 0, 190);
        if ($name === '') { flash('error', 'Bitte eine Überschrift angeben.'); lredirect('links', ['tab' => $tabId, 'edit' => 1]); }
        insert('link_sections', ['company_id' => $cid, 'tab_id' => $tabId, 'name' => $name, 'description' => mb_substr(trim(post('description')), 0, 500),
            'layout' => isset(LINK_LAYOUTS[post('layout')]) ? post('layout') : 'tiles', 'sort' => links_next_sort('link_sections', 'tab_id', $tabId, $cid), 'created_at' => now()]);
        audit('link_section_created', $name, $cid);
        flash('success', 'Die Überschrift wurde angelegt.');
    } elseif (str_starts_with($action, 'section_')) {
        $s = row('SELECT * FROM link_sections WHERE id = ? AND company_id = ?', [$id, $cid]) ?? abort(404);
        $tabId = (int)$s['tab_id'];
        if ($action === 'section_save') {
            $name = mb_substr(trim(post('name')), 0, 190);
            $newTab = (int)post('tab_id', $tabId);
            if (!row('SELECT id FROM link_tabs WHERE id = ? AND company_id = ?', [$newTab, $cid])) $newTab = $tabId;
            $data = ['description' => mb_substr(trim(post('description')), 0, 500), 'layout' => isset(LINK_LAYOUTS[post('layout')]) ? post('layout') : 'tiles', 'tab_id' => $newTab];
            if ($name !== '') $data['name'] = $name;
            if ($newTab !== $tabId) $data['sort'] = links_next_sort('link_sections', 'tab_id', $newTab, $cid);
            update('link_sections', (int)$s['id'], $data);
            audit('link_section_updated', $data['name'] ?? $s['name'], $cid);
            flash('success', $newTab !== $tabId ? 'Die Überschrift wurde mit ihren Links auf einen anderen Reiter verschoben.' : 'Die Überschrift wurde gespeichert.');
            $tabId = $newTab;
        } elseif ($action === 'section_move') {
            links_reorder('link_sections', 'tab_id', $tabId, $cid, (int)$s['id'], $dir);
        } elseif ($action === 'section_delete') {
            q('DELETE FROM links WHERE section_id = ? AND company_id = ?', [$s['id'], $cid]);
            q('DELETE FROM link_sections WHERE id = ?', [$s['id']]);
            audit('link_section_deleted', $s['name'], $cid);
            flash('success', 'Die Überschrift „' . $s['name'] . '“ wurde gelöscht.');
        } else abort(400);
    } elseif ($action === 'link_move' || $action === 'link_delete') {
        $l = row('SELECT id, title, section_id FROM links WHERE id = ? AND company_id = ?', [$id, $cid]) ?? abort(404);
        $tabId = links_tab_of_section((int)$l['section_id'], $cid);
        if ($action === 'link_move') {
            links_reorder('links', 'section_id', (int)$l['section_id'], $cid, (int)$l['id'], $dir);
        } else {
            q('DELETE FROM links WHERE id = ?', [$l['id']]);
            audit('link_deleted', $l['title'], $cid);
            flash('success', 'Der Link wurde gelöscht.');
        }
    } else {
        abort(400);
    }
    lredirect('links', ($tabId ? ['tab' => $tabId] : []) + ['edit' => 1]);
}

// ---------------------------------------------------------------------------
// Bilder ausliefern (nur für angemeldete Benutzer der Firma)
// ---------------------------------------------------------------------------

function page_link_image(): void
{
    $u = require_login();
    $l = row('SELECT company_id, image_mime, image_data FROM links WHERE id = ?', [query_int('id')]);
    if (!$l || $l['image_mime'] === '' || !isset(LINK_IMAGE_MIMES[$l['image_mime']])) abort(404);
    $cid = (int)$l['company_id'];
    if (!can_access_company($cid) || (!is_staff_role($u['role']) && !company_has_app($cid, LINKS_APP))) abort(403);
    header('Content-Type: ' . $l['image_mime']);
    header('Cache-Control: private, max-age=604800');
    header('Content-Disposition: inline');
    echo base64_decode((string)$l['image_data']);
    exit;
}
