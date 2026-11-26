<?php
/**
 * App „Dokumente & Anleitungen“ – Stufe 6: Verknüpfungen mit den anderen Apps
 *
 * - Karte „Dokumentation & Zugangsdaten“ an Gerät (Inventar), Vertrag, Ticket und Auftrag (On-/Offboarding):
 *   Wiki-Seiten (auch „Für alle Kunden“) und Tresor-Einträge verknüpfen. Angezeigt wird nur, was der Betrachter
 *   selbst sehen darf; von Tresor-Einträgen nur Titel und Ordner, geöffnet wird im Tresor (Recht je Ordner).
 * - Auf der Wiki-Seite: „Verknüpft mit“ (Geräte, Verträge, Tickets, Aufträge – nur mit Recht in der jeweiligen App).
 * - Ticket → „Als Anleitung übernehmen“: Entwurf mit Problem und Lösung aus dem Verlauf, mit dem Ticket verknüpft.
 * - Offboarding: „Zugangsdaten ändern“ – Tresor-Einträge, die die ausscheidende Person angezeigt, kopiert oder
 *   exportiert hat (aus dem Tresor-Protokoll), mit Haken „geändert“.
 */

declare(strict_types=1);

const WK_LINK_TYPES = ['device', 'contract', 'ticket', 'request'];

/** Darf der Benutzer am Ziel Verknüpfungen pflegen? (und gehört das Ziel zur Firma) */
function wkl_target(int $cid, string $type, int $id): ?array
{
    $u = current_user();
    switch ($type) {
        case 'device':
            if (!company_has_app($cid, 'inventar') || !function_exists('inv_perm')) return null;
            $d = row('SELECT id, inv_no, manufacturer, model FROM inv_devices WHERE id = ? AND company_id = ?', [$id, $cid]);
            if (!$d) return null;
            $GLOBALS['inv_cid'] = $cid;
            $p = inv_perm($cid);
            return ['label' => trim($d['inv_no'] . ' · ' . $d['manufacturer'] . ' ' . $d['model']), 'view' => $p > 0, 'edit' => $p >= INV_PERM_EDIT,
                'url' => url('inv_device', ['id' => $id] + (is_admin() ? ['company' => $cid] : []))];
        case 'contract':
            if (!company_has_app($cid, 'vertraege') || !function_exists('ct_perm')) return null;
            $c = row('SELECT id, title FROM contracts WHERE id = ? AND company_id = ?', [$id, $cid]);
            if (!$c) return null;
            $p = ct_perm($cid);
            return ['label' => $c['title'], 'view' => $p > 0, 'edit' => $p >= CT_PERM_EDIT, 'url' => url('contract', ['id' => $id] + (is_admin() ? ['company' => $cid] : []))];
        case 'ticket':
            if (!company_has_app($cid, 'tickets') || !function_exists('load_ticket')) return null;
            $tk = load_ticket($id);
            if (!$tk || (int)$tk['company_id'] !== $cid) return null;
            return ['label' => '#' . $tk['number'] . ' ' . $tk['subject'], 'view' => ticket_can_view($tk), 'edit' => ticket_can_process($tk),
                'url' => url('ticket', ['id' => $id] + (is_staff_role($u['role']) ? ['company' => $cid] : []))];
        case 'request':
            $r = row('SELECT id, company_id, type, first_name, last_name, created_by FROM requests WHERE id = ? AND company_id = ?', [$id, $cid]);
            if (!$r) return null;
            $staff = is_staff_role($u['role']);
            $view = $staff ? admin_perm('onboarding') >= PERM_VIEW && (admin_company_ids() === null || in_array($cid, admin_company_ids(), true))
                : (int)$u['company_id'] === $cid && company_has_app($cid, 'onboarding');
            $edit = $view && ($staff || $u['role'] === 'firma_admin' || (int)$r['created_by'] === (int)$u['id']);
            return ['label' => t(ucfirst((string)$r['type'])) . ': ' . trim($r['first_name'] . ' ' . $r['last_name']), 'view' => $view, 'edit' => $edit,
                'url' => url('request', ['id' => $id])];
    }
    return null;
}

function wkl_page_url(int $pageCompany, int $pageId, int $cid): string
{
    if ($pageCompany === 0) return url('doc', ['hub' => 1, 'id' => $pageId]);
    return url('doc', ['id' => $pageId] + (is_admin() ? ['company' => $cid] : []));
}

/** Wiki-Seiten, die der Benutzer verknüpfen kann (sichtbar, eigene Firma und „Für alle Kunden“) */
function wkl_page_options(int $cid): array
{
    $out = [];
    foreach ([$cid, 0] as $c) {
        $perms = array_keys(array_filter(wk_space_perms($c), fn($p) => $p > 0));
        if (!$perms) continue;
        foreach (rows('SELECT p.*, s.name AS space_name FROM wk_pages p JOIN wk_spaces s ON s.id = p.space_id WHERE p.company_id = ? AND p.space_id IN ('
            . implode(',', array_map('intval', $perms)) . ') ORDER BY s.sort, s.name, p.title LIMIT 1000', [$c]) as $p) {
            if (!wk_page_visible($c, $p)) continue;
            $out[] = ['id' => (int)$p['id'], 'pc' => $c, 'label' => ($c === 0 ? t('Für alle Kunden') . ' · ' : '') . $p['space_name'] . ' › ' . $p['title']];
        }
    }
    return $out;
}

/** Tresor-Einträge, die der Benutzer sieht (nur Titel und Ordner) */
function wkl_vault_options(int $cid): array
{
    if (!function_exists('wkv_perm') || !wkv_company($cid)) return [];
    $u = current_user();
    $out = [];
    foreach (rows('SELECT id, name FROM wk_vault_folders WHERE company_id = ? ORDER BY sort, name', [$cid]) as $f) {
        if (wkv_perm($cid, (int)$f['id'], $u) < 1) continue;
        foreach (rows('SELECT id, title FROM wk_vault_entries WHERE company_id = ? AND folder_id = ? ORDER BY title', [$cid, $f['id']]) as $e) {
            $out[] = ['id' => (int)$e['id'], 'label' => $f['name'] . ' › ' . $e['title']];
        }
    }
    return $out;
}

/** Karte „Dokumentation & Zugangsdaten“ an einem Gerät, Vertrag, Ticket oder Auftrag */
function wkl_card(int $cid, string $type, int $id): void
{
    if (!company_has_app($cid, WK_APP)) return;
    $target = wkl_target($cid, $type, $id);
    if (!$target || !$target['view']) return;
    $u = current_user();
    $links = rows('SELECT * FROM wk_links WHERE company_id = ? AND target_type = ? AND target_id = ? ORDER BY id', [$cid, $type, $id]);
    $pages = [];
    $entries = [];
    $hidden = 0;
    foreach ($links as $l) {
        if ($l['page_id']) {
            $pc = (int)$l['page_company'];
            $p = wk_page($pc, (int)$l['page_id']);
            if ($p && wk_page_visible($pc, $p)) $pages[] = ['link' => $l, 'page' => $p, 'pc' => $pc];
            elseif ($p) $hidden++;
        } elseif ($l['vault_entry_id']) {
            $e = row('SELECT e.id, e.title, e.folder_id, f.name AS folder_name FROM wk_vault_entries e JOIN wk_vault_folders f ON f.id = e.folder_id
                WHERE e.id = ? AND e.company_id = ?', [$l['vault_entry_id'], $cid]);
            if ($e && function_exists('wkv_perm') && wkv_perm($cid, (int)$e['folder_id'], $u) > 0) $entries[] = ['link' => $l, 'entry' => $e];
            elseif ($e) $hidden++;
        }
    }
    $edit = $target['edit'];
    if (!$links && !$edit) return;
    $back = $_SERVER['REQUEST_URI'] ?? ''; ?>
    <div class="card wkl-card" id="dokumentation">
        <h3><?= e(t('Dokumentation & Zugangsdaten')) ?></h3>
        <?php if ($pages): ?><ul class="inv-log">
            <?php foreach ($pages as $x): ?><li>📄 <a href="<?= e(wkl_page_url($x['pc'], (int)$x['page']['id'], $cid)) ?>"><?= e($x['page']['title']) ?></a>
                <?php if ($x['pc'] === 0): ?><span class="tag"><?= e(t('Für alle Kunden')) ?></span><?php endif; ?>
                <?php if ($edit): ?><?= wkl_remove_form($cid, $type, $id, (int)$x['link']['id'], $back) ?><?php endif; ?></li><?php endforeach; ?>
        </ul><?php endif; ?>
        <?php if ($entries): ?><ul class="inv-log">
            <?php foreach ($entries as $x): ?><li>🔑 <a href="<?= e(url('doc_vault', is_admin() ? ['company' => $cid] : []) . '#entry=' . (int)$x['entry']['id']) ?>"><?= e($x['entry']['title']) ?></a>
                <span class="muted small">· <?= e(t('Tresor')) ?> › <?= e($x['entry']['folder_name']) ?></span>
                <?php if ($edit): ?><?= wkl_remove_form($cid, $type, $id, (int)$x['link']['id'], $back) ?><?php endif; ?></li><?php endforeach; ?>
        </ul><?php endif; ?>
        <?php if (!$pages && !$entries): ?><p class="muted small"><?= e(t('Noch keine Anleitungen oder Zugangsdaten verknüpft.')) ?></p><?php endif; ?>
        <?php if ($hidden): ?><p class="muted small"><?= e(t('{n} weitere Verknüpfungen sind für Sie nicht sichtbar.', ['n' => $hidden])) ?></p><?php endif; ?>
        <?php if ($edit):
            $po = wkl_page_options($cid);
            $vo = wkl_vault_options($cid); ?>
            <form method="post" action="<?= e(url('doc_link_action', is_admin() ? ['company' => $cid] : [])) ?>" class="form wkl-add">
                <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="target_type" value="<?= e($type) ?>">
                <input type="hidden" name="target_id" value="<?= (int)$id ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                <?php if ($po): ?><label><?= e(t('Anleitung verknüpfen')) ?> <select name="page"><option value=""><?= e(t('– Seite wählen –')) ?></option>
                    <?php foreach ($po as $o): ?><option value="<?= e($o['pc'] . ':' . $o['id']) ?>"><?= e($o['label']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <?php if ($vo): ?><label><?= e(t('Zugangsdaten verknüpfen')) ?> <select name="entry"><option value=""><?= e(t('– Eintrag wählen –')) ?></option>
                    <?php foreach ($vo as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['label']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <?php if ($po || $vo): ?><button class="btn small"><?= e(t('Verknüpfen')) ?></button><?php else: ?><p class="muted small"><?= e(t('Es gibt noch keine Seiten oder Tresor-Einträge, die Sie verknüpfen können.')) ?></p><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
<?php }

function wkl_remove_form(int $cid, string $type, int $id, int $linkId, string $back): string
{
    return '<form method="post" action="' . e(url('doc_link_action', is_admin() ? ['company' => $cid] : [])) . '" class="inline">' . csrf_field()
        . '<input type="hidden" name="action" value="remove"><input type="hidden" name="target_type" value="' . e($type) . '"><input type="hidden" name="target_id" value="' . $id . '">'
        . '<input type="hidden" name="link_id" value="' . $linkId . '"><input type="hidden" name="back" value="' . e($back) . '">'
        . ' <button class="btn-link small" title="' . e(t('Verknüpfung entfernen')) . '">' . e(t('entfernen')) . '</button></form>';
}

/** Zurück zur Seite, von der die Aktion kam (nur interne Adressen des Portals) */
function wkl_back(string $fallback): never
{
    $b = (string)post('back');
    if (!preg_match('#^/[^/\\\\]#', $b) && !preg_match('#^/?index\.php\?#', $b)) $b = $fallback;
    header('Location: ' . ($b !== '' ? $b : $fallback));
    exit;
}

function page_doc_link_action(): void
{
    if (!is_post()) abort(405);
    require_post_csrf();
    require_app(WK_APP);
    $cid = context_company_id();
    require_company_access($cid);
    if (!company_has_app($cid, WK_APP)) abort(403);
    $type = (string)post('target_type');
    $id = (int)post('target_id', 0);
    if (!in_array($type, WK_LINK_TYPES, true)) abort(400);
    $target = wkl_target($cid, $type, $id);
    if (!$target || !$target['edit']) abort(403);
    $u = current_user();
    if (post('action') === 'remove') {
        q('DELETE FROM wk_links WHERE id = ? AND company_id = ? AND target_type = ? AND target_id = ?', [(int)post('link_id', 0), $cid, $type, $id]);
        audit('docs.link_remove', $type . ' #' . $id, $cid);
        flash('success', t('Die Verknüpfung wurde entfernt.'));
        wkl_back($target['url']);
    }
    $added = 0;
    $page = (string)post('page');
    if (preg_match('/^(\d+):(\d+)$/', $page, $m)) {
        $pc = (int)$m[1] === 0 ? 0 : $cid;
        $p = wk_page($pc, (int)$m[2]);
        if (!$p || !wk_page_visible($pc, $p)) abort(403);
        if (!row('SELECT id FROM wk_links WHERE company_id = ? AND target_type = ? AND target_id = ? AND page_id = ? AND page_company = ?', [$cid, $type, $id, $p['id'], $pc])) {
            insert('wk_links', ['company_id' => $cid, 'target_type' => $type, 'target_id' => $id, 'page_id' => $p['id'], 'page_company' => $pc, 'vault_entry_id' => null,
                'created_by' => $u['id'], 'created_at' => now()]);
            $added++;
        }
    }
    $entry = (int)post('entry', 0);
    if ($entry) {
        $e = row('SELECT id, folder_id FROM wk_vault_entries WHERE id = ? AND company_id = ?', [$entry, $cid]);
        if (!$e || !function_exists('wkv_perm') || wkv_perm($cid, (int)$e['folder_id'], $u) < 1) abort(403);
        if (!row('SELECT id FROM wk_links WHERE company_id = ? AND target_type = ? AND target_id = ? AND vault_entry_id = ?', [$cid, $type, $id, $e['id']])) {
            insert('wk_links', ['company_id' => $cid, 'target_type' => $type, 'target_id' => $id, 'page_id' => null, 'page_company' => 0, 'vault_entry_id' => $e['id'],
                'created_by' => $u['id'], 'created_at' => now()]);
            $added++;
        }
    }
    if ($added) {
        audit('docs.link_add', $type . ' #' . $id, $cid);
        flash('success', t('Verknüpft.'));
    } else {
        flash('info', t('Bitte eine Seite oder einen Tresor-Eintrag wählen.'));
    }
    wkl_back($target['url']);
}

/** Auf der Wiki-Seite: wo die Seite verknüpft ist (nur Ziele, die der Betrachter sehen darf) */
function wkl_backlinks_card(int $cid, array $page): void
{
    $u = current_user();
    // Seiten „Für alle Kunden“: nur Verknüpfungen der eigenen Firma (bzw. der gewählten Firma beim Dienstleister)
    if (wk_is_hub($cid)) {
        $own = is_staff_role($u['role']) ? 0 : (int)$u['company_id'];
        if (!$own) return;
        $links = rows('SELECT * FROM wk_links WHERE company_id = ? AND page_id = ? AND page_company = 0', [$own, $page['id']]);
        $tc = $own;
    } else {
        $links = rows('SELECT * FROM wk_links WHERE company_id = ? AND page_id = ? AND page_company = ?', [$cid, $page['id'], $cid]);
        $tc = $cid;
    }
    $items = [];
    foreach ($links as $l) {
        $t = wkl_target($tc, (string)$l['target_type'], (int)$l['target_id']);
        if ($t && $t['view']) $items[] = [$l['target_type'], $t];
    }
    if (!$items) return;
    $labels = ['device' => t('Gerät'), 'contract' => t('Vertrag'), 'ticket' => t('Ticket'), 'request' => t('Auftrag')]; ?>
    <div class="card">
        <h2><?= e(t('Verknüpft mit')) ?></h2>
        <ul class="inv-log"><?php foreach ($items as [$ty, $t]): ?><li><span class="tag"><?= e($labels[$ty] ?? $ty) ?></span> <a href="<?= e($t['url']) ?>"><?= e($t['label']) ?></a></li><?php endforeach; ?></ul>
    </div>
<?php }

// ---------------------------------------------------------------------------
// Ticket → Anleitung
// ---------------------------------------------------------------------------

/** Karte im Ticket: „Als Anleitung übernehmen“ (Bearbeiter mit Bearbeiten-Recht in mindestens einem Wiki-Bereich) */
function wkl_ticket_to_doc_card(array $tk): void
{
    $cid = (int)$tk['company_id'];
    if (!company_has_app($cid, WK_APP) || !ticket_can_process($tk)) return;
    $ids = array_keys(array_filter(wk_space_perms($cid), fn($p) => $p >= WK_PERM_EDIT));
    if (!$ids) return;
    $spaces = rows('SELECT id, name FROM wk_spaces WHERE company_id = ? AND id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY sort, name', [$cid]); ?>
    <div class="card">
        <h3><?= e(t('Als Anleitung übernehmen')) ?></h3>
        <form method="post" action="<?= e(url('doc_from_ticket', ['ticket' => $tk['id']] + (is_admin() ? ['company' => $cid] : []))) ?>" class="form">
            <?= csrf_field() ?>
            <label><?= e(t('Bereich')) ?> <select name="space"><?php foreach ($spaces as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
            <button class="btn small"><?= e(t('Entwurf anlegen')) ?></button>
            <p class="muted small"><?= e(t('Legt einen Entwurf mit Problem und Lösung aus diesem Ticket an. Interne Notizen werden nicht übernommen. Bitte vor dem Veröffentlichen prüfen und Persönliches entfernen.')) ?></p>
        </form>
    </div>
<?php }

function page_doc_from_ticket(): void
{
    if (!is_post()) abort(405);
    require_post_csrf();
    $cid = wk_company_id();
    if (wk_is_hub($cid)) abort(404);
    $tk = function_exists('load_ticket') ? load_ticket(query_int('ticket')) : null;
    if (!$tk || (int)$tk['company_id'] !== $cid || !ticket_can_process($tk)) abort(403);
    $space = wk_require_space($cid, (int)post('space', 0), WK_PERM_EDIT);
    $msgs = rows("SELECT m.*, u.name AS user_name FROM ticket_messages m LEFT JOIN users u ON u.id = m.user_id
        WHERE m.ticket_id = ? AND m.kind = 'message' AND m.body IS NOT NULL AND m.body <> '' ORDER BY m.id", [$tk['id']]);
    $first = $msgs[0] ?? null;
    $solution = null;
    foreach (array_reverse($msgs) as $m) if ($first && $m['id'] !== $first['id'] && (int)$m['user_id'] !== (int)$tk['created_by']) { $solution = $m; break; }
    $para = fn(?array $m) => $m ? implode('', array_map(fn($l) => '<p>' . e($l) . '</p>', array_filter(preg_split('/\R{2,}/', trim((string)$m['body'])) ?: [], fn($x) => trim($x) !== ''))) : '<p><br></p>';
    $body = '<div class="wk-info"><p>' . e(t('Übernommen aus Ticket #{n} vom {d}.', ['n' => $tk['number'], 'd' => fmt_date(substr((string)$tk['created_at'], 0, 10))])) . '</p></div>'
        . '<h2>' . e(t('Problem')) . '</h2>' . $para($first)
        . '<h2>' . e(t('Lösung')) . '</h2>' . $para($solution);
    $body = sanitize_html(str_replace("\n", '<br>', $body));
    $me = current_user();
    $sort = (int)(row('SELECT MAX(sort) AS m FROM wk_pages WHERE company_id = ? AND space_id = ? AND parent_id IS NULL', [$cid, $space['id']])['m'] ?? 0) + 10;
    $pid = insert('wk_pages', ['company_id' => $cid, 'space_id' => $space['id'], 'parent_id' => null, 'title' => mb_substr((string)$tk['subject'], 0, 190), 'body' => $body,
        'status' => 'draft', 'sort' => $sort, 'created_by' => $me['id'], 'updated_by' => $me['id'], 'created_at' => now(), 'updated_at' => now()]);
    wk_version_add($cid, $pid, (string)$tk['subject'], $body, (int)$me['id']);
    wk_watch_set($cid, $pid, (int)$me['id'], true);
    insert('wk_links', ['company_id' => $cid, 'target_type' => 'ticket', 'target_id' => $tk['id'], 'page_id' => $pid, 'page_company' => $cid, 'vault_entry_id' => null,
        'created_by' => $me['id'], 'created_at' => now()]);
    wk_log($cid, 'page_from_ticket', '#' . $pid . ' ← Ticket #' . $tk['number']);
    flash('success', t('Der Entwurf wurde angelegt und mit dem Ticket verknüpft. Prüfen und ergänzen Sie ihn, bevor Sie ihn veröffentlichen.'));
    wk_redirect('doc_edit', ['id' => $pid]);
}

// ---------------------------------------------------------------------------
// Offboarding: Zugangsdaten ändern
// ---------------------------------------------------------------------------

/** Portal-Konto der Person aus dem Auftrag (über die E-Mail-Adresse in der Mitarbeiterliste oder im Auftrag) */
function wkl_request_user(array $r): ?array
{
    $cid = (int)$r['company_id'];
    $mails = [];
    if (trim((string)$r['employee_no']) !== '' && function_exists('employee_find')) {
        $emp = employee_find($cid, (string)$r['employee_no']);
        if ($emp && trim((string)$emp['email']) !== '') $mails[] = strtolower(trim((string)$emp['email']));
    }
    if (trim((string)($r['email_wish'] ?? '')) !== '') $mails[] = strtolower(trim((string)$r['email_wish']));
    foreach (array_unique($mails) as $m) {
        $u = row('SELECT * FROM users WHERE company_id = ? AND LOWER(email) = ?', [$cid, $m]);
        if ($u) return $u;
    }
    return null;
}

function wkl_offboarding_card(array $r): void
{
    $cid = (int)$r['company_id'];
    if ($r['type'] !== 'offboarding' || !company_has_app($cid, WK_APP) || !function_exists('wkv_company') || !wkv_company($cid)) return;
    $me = current_user();
    $person = wkl_request_user($r);
    $rows = [];
    if ($person) {
        foreach (rows("SELECT l.entry_id, MAX(l.created_at) AS last_at, COUNT(*) AS n FROM wk_vault_log l WHERE l.company_id = ? AND l.user_id = ?
            AND l.entry_id IS NOT NULL AND l.action IN ('view', 'copy', 'export_read') GROUP BY l.entry_id", [$cid, $person['id']]) as $x) {
            $e = row('SELECT e.id, e.title, e.folder_id, f.name AS folder_name FROM wk_vault_entries e JOIN wk_vault_folders f ON f.id = e.folder_id WHERE e.id = ? AND e.company_id = ?', [$x['entry_id'], $cid]);
            if (!$e || wkv_perm($cid, (int)$e['folder_id'], $me) < WK_V_PERM_EDIT) continue;
            $done = row('SELECT c.done_at, u.name FROM wk_offboard_checks c LEFT JOIN users u ON u.id = c.done_by WHERE c.request_id = ? AND c.entry_id = ?', [$r['id'], $e['id']]);
            $rows[] = $e + ['last_at' => $x['last_at'], 'n' => (int)$x['n'], 'done' => $done];
        }
    }
    // Ohne Bearbeiten-Recht in einem Ordner sieht man die Karte nicht (außer Firmen-Admins, für den Hinweis)
    if (!$rows && !wkv_is_company_admin($cid)) return; ?>
    <div class="card" id="zugangsdaten">
        <h2><?= e(t('Zugangsdaten ändern')) ?></h2>
        <?php if (!$person): ?>
            <p class="muted"><?= e(t('Zu diesem Auftrag wurde kein Portal-Konto gefunden (über die E-Mail-Adresse in der Mitarbeiterliste). Ohne Konto gibt es keine Tresor-Zugriffe.')) ?></p>
        <?php elseif (!$rows): ?>
            <p class="muted"><?= e(t('{n} hat keine Tresor-Einträge angezeigt oder kopiert, die Sie bearbeiten dürfen.', ['n' => $person['name']])) ?></p>
        <?php else: ?>
            <p><?= e(t('{n} hat diese Zugangsdaten angezeigt oder kopiert. Ändern Sie die Passwörter beim jeweiligen Dienst und tragen Sie sie im Tresor neu ein.', ['n' => $person['name']])) ?></p>
            <table class="table">
                <thead><tr><th><?= e(t('Eintrag')) ?></th><th><?= e(t('Zuletzt angesehen')) ?></th><th><?= e(t('Geändert')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $x): ?>
                    <tr><td><a href="<?= e(url('doc_vault', is_admin() ? ['company' => $cid] : []) . '#entry=' . (int)$x['id']) ?>"><?= e($x['title']) ?></a><br><span class="muted small"><?= e($x['folder_name']) ?></span></td>
                        <td class="nowrap"><?= fmt_dt($x['last_at']) ?> <span class="muted small">(<?= (int)$x['n'] ?>×)</span></td>
                        <td><form method="post" action="<?= e(url('doc_offboard_check', ['request' => $r['id']] + (is_admin() ? ['company' => $cid] : []))) ?>" class="inline">
                            <?= csrf_field() ?><input type="hidden" name="entry" value="<?= (int)$x['id'] ?>">
                            <?php if ($x['done']): ?><span class="tag"><?= e(t('erledigt')) ?></span> <span class="muted small"><?= fmt_dt($x['done']['done_at']) ?> · <?= e($x['done']['name'] ?? '') ?></span>
                                <button class="btn-link small" name="done" value="0"><?= e(t('zurücknehmen')) ?></button>
                            <?php else: ?><button class="btn small" name="done" value="1"><?= e(t('Passwort geändert')) ?></button><?php endif; ?>
                        </form></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted small"><?= e(t('Der Zugriff auf den Tresor endet automatisch, sobald das Konto deaktiviert oder aus den Gruppen entfernt wird; die betroffenen Ordner werden dann neu verschlüsselt.')) ?></p>
        <?php endif; ?>
    </div>
<?php }

function page_doc_offboard_check(): void
{
    if (!is_post()) abort(405);
    require_post_csrf();
    $r = row('SELECT * FROM requests WHERE id = ?', [query_int('request')]);
    if (!$r || $r['type'] !== 'offboarding') abort(404);
    $cid = (int)$r['company_id'];
    require_company_access($cid);
    $t = wkl_target($cid, 'request', (int)$r['id']);
    if (!$t || !$t['view']) abort(403);
    $e = row('SELECT id, folder_id, title FROM wk_vault_entries WHERE id = ? AND company_id = ?', [(int)post('entry', 0), $cid]);
    if (!$e || wkv_perm($cid, (int)$e['folder_id'], current_user()) < WK_V_PERM_EDIT) abort(403);
    q('DELETE FROM wk_offboard_checks WHERE request_id = ? AND entry_id = ?', [$r['id'], $e['id']]);
    if (post('done') === '1') insert('wk_offboard_checks', ['company_id' => $cid, 'request_id' => $r['id'], 'entry_id' => $e['id'], 'done_by' => current_user()['id'], 'done_at' => now()]);
    audit('docs.offboard_check', '#' . $r['id'] . ' ' . $e['title'] . ' = ' . (post('done') === '1' ? '1' : '0'), $cid);
    header('Location: ' . url('request', ['id' => $r['id']]) . '#zugangsdaten');
    exit;
}
