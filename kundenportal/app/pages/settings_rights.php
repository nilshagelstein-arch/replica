<?php
/**
 * Zentrale Einstellungen, Stufe 3: Kategorie „Gruppenrechte“ (früher „Gruppen & Rechte“).
 *
 * Eine Tabelle Abteilung × App liest und schreibt die vorhandenen Rechte-Tabellen der Apps:
 *   inv_group_perms (Geräte & Lizenzen), ct_group_perms (Verträge, Kosten sehen, Kündigungen freigeben),
 *   ticket_queue_groups (Bearbeiter je Queue), wk_space_perms (Wiki-Bereiche) und
 *   wk_vault_folder_perms (Tresor-Ordner, nur Zeilen für Abteilungen; Rechte einzelner Personen bleiben im Ordner).
 * Queues, Bereiche und Ordner stehen in ausklappbaren Abschnitten unter der Übersicht.
 * Jede Änderung landet mit vorher/nachher im Protokoll (audit_log, Aktion settings.rights).
 *
 * Firmen-Admins dürfen alles. Betreiber dürfen nur die Spalten ändern, die sie schon in den Apps ändern durften
 * (Inventar/Verträge/Wiki nur mit Einsicht und Bearbeiten-Recht, Tickets mit Bereichsrecht); den Tresor nie.
 */

const RIGHTS_LEVELS = [0 => '–', 1 => 'Ansehen', 2 => 'Bearbeiten'];

/** Wer darf die Kategorie sehen bzw. welche Teile ändern? null = gar nicht */
function rights_access(int $cid): ?array
{
    $u = current_user();
    if (!$u || !$cid) return null;
    $staff = is_staff_role($u['role']);
    $own = $u['role'] === 'firma_admin' && (int)$u['company_id'] === $cid;
    if (!$own && !($staff && can_access_company($cid) && admin_can('companies'))) return null;
    $has = fn (string $slug) => company_has_app($cid, $slug);
    $apps = [
        'inv'     => $has(INV_APP),
        'ct'      => $has(CT_APP),
        'tickets' => $has(TICKETS_APP),
        'wiki'    => $has(WK_APP),
        'vault'   => $has(WK_APP) && $own,   // Ordnernamen und Schlüsselverteilung: nur die Firma selbst
    ];
    if (!array_filter($apps)) return null;
    $edit = $own ? $apps : [
        'inv'     => $apps['inv'] && admin_can('inventory', true) && inv_perm($cid) === INV_PERM_EDIT,
        'ct'      => $apps['ct'] && admin_can('contracts', true) && ct_rights($cid)['perm'] === CT_PERM_EDIT,
        'tickets' => $apps['tickets'] && admin_can('tickets', true),
        'wiki'    => $apps['wiki'] && admin_can('docs', true) && wk_staff_perm($cid, $u) === WK_PERM_EDIT,
        'vault'   => false,
    ];
    return ['own' => $own, 'staff' => $staff, 'apps' => $apps, 'edit' => $edit];
}

/** Aktueller Stand aller Rechte der Firma */
function rights_load(int $cid, array $acc): array
{
    $deps = rows('SELECT id, name FROM departments WHERE company_id = ? AND active = 1 ORDER BY name', [$cid]);
    $s = ['deps' => $deps, 'inv' => [], 'ct' => [], 'queues' => [], 'q' => [], 'spaces' => [], 'sp' => [], 'folders' => [], 'fp' => [], 'fpeople' => [], 'fwait' => []];
    if ($acc['apps']['inv']) {
        foreach (rows('SELECT department_id, perm FROM inv_group_perms WHERE company_id = ?', [$cid]) as $r) $s['inv'][(int)$r['department_id']] = (int)$r['perm'];
    }
    if ($acc['apps']['ct']) {
        foreach (rows('SELECT department_id, perm, see_costs, approve_cancel FROM ct_group_perms WHERE company_id = ?', [$cid]) as $r) {
            $s['ct'][(int)$r['department_id']] = [(int)$r['perm'], (int)$r['see_costs'], (int)$r['approve_cancel']];
        }
    }
    if ($acc['apps']['tickets']) {
        $s['queues'] = rows('SELECT id, name, active FROM ticket_queues WHERE company_id = ? ORDER BY sort, name', [$cid]);
        foreach (rows('SELECT g.queue_id, g.department_id FROM ticket_queue_groups g JOIN ticket_queues q ON q.id = g.queue_id WHERE q.company_id = ?', [$cid]) as $r) {
            $s['q'][(int)$r['department_id']][(int)$r['queue_id']] = 1;
        }
    }
    if ($acc['apps']['wiki']) {
        $s['spaces'] = rows('SELECT id, name, all_read FROM wk_spaces WHERE company_id = ? ORDER BY sort, name', [$cid]);
        foreach (rows('SELECT space_id, department_id, perm FROM wk_space_perms WHERE company_id = ?', [$cid]) as $r) {
            $s['sp'][(int)$r['department_id']][(int)$r['space_id']] = (int)$r['perm'];
        }
    }
    if ($acc['apps']['vault']) {
        $s['folders'] = rows('SELECT id, name, key_version FROM wk_vault_folders WHERE company_id = ? ORDER BY sort, name', [$cid]);
        foreach (rows('SELECT folder_id, department_id, user_id, perm FROM wk_vault_folder_perms WHERE company_id = ?', [$cid]) as $r) {
            if ($r['department_id'] !== null) $s['fp'][(int)$r['department_id']][(int)$r['folder_id']] = (int)$r['perm'];
            elseif ($r['user_id'] !== null && (int)$r['perm'] > 0) $s['fpeople'][(int)$r['folder_id']] = ($s['fpeople'][(int)$r['folder_id']] ?? 0) + 1;
        }
        // „wartet“: ein Mitglied der Abteilung hat noch keinen Schlüssel der aktuellen Version (bekommt ihn beim nächsten Öffnen durch ein Mitglied)
        foreach ($s['folders'] as $f) {
            $have = array_flip(array_map('intval', array_column(rows('SELECT user_id FROM wk_vault_keys WHERE company_id = ? AND folder_id = ? AND key_version = ?',
                [$cid, $f['id'], $f['key_version']]), 'user_id')));
            foreach ($deps as $d) {
                if (($s['fp'][(int)$d['id']][(int)$f['id']] ?? 0) < 1) continue;
                foreach (rows('SELECT ud.user_id FROM user_departments ud JOIN users u ON u.id = ud.user_id WHERE ud.department_id = ? AND u.active = 1', [$d['id']]) as $m) {
                    if (!isset($have[(int)$m['user_id']])) { $s['fwait'][(int)$d['id']][(int)$f['id']] = 1; break; }
                }
            }
        }
    }
    return $s;
}

/** Stand als flache Liste „Schlüssel => Wert“ (für den Vergleich vorher/nachher im Protokoll) */
function rights_flat(array $s, array $acc): array
{
    $out = [];
    $names = array_column($s['deps'], 'name', 'id');
    foreach ($names as $did => $dn) {
        if ($acc['apps']['inv']) $out["$dn · " . 'Geräte & Lizenzen'] = $s['inv'][$did] ?? 0;
        if ($acc['apps']['ct']) {
            [$p, $c, $a] = $s['ct'][$did] ?? [0, 0, 0];
            $out["$dn · " . 'Verträge'] = $p;
            $out["$dn · " . 'Kosten sehen'] = $c;
            $out["$dn · " . 'Kündigungen freigeben'] = $a;
        }
        foreach ($s['queues'] as $q) $out["$dn · Queue " . $q['name']] = $s['q'][$did][(int)$q['id']] ?? 0;
        foreach ($s['spaces'] as $sp) $out["$dn · Bereich " . $sp['name']] = $s['sp'][$did][(int)$sp['id']] ?? 0;
        foreach ($s['folders'] as $f) $out["$dn · Ordner " . $f['name']] = $s['fp'][$did][(int)$f['id']] ?? 0;
    }
    return $out;
}

/** Speichern: nur die Spalten, die der Benutzer ändern darf; jede Tabelle wird für die Firma neu geschrieben */
function rights_save(int $cid, array $acc, array $before): void
{
    $deps = array_map(fn ($d) => (int)$d['id'], $before['deps']);
    $lvl = fn ($v) => min(2, max(0, (int)$v));
    $in = fn (string $k) => is_array($_POST[$k] ?? null) ? $_POST[$k] : [];
    if ($acc['edit']['inv']) {
        $perm = $in('inv');
        q('DELETE FROM inv_group_perms WHERE company_id = ?', [$cid]);
        foreach ($deps as $d) if ($p = $lvl($perm[$d] ?? 0)) insert('inv_group_perms', ['company_id' => $cid, 'department_id' => $d, 'perm' => $p]);
    }
    if ($acc['edit']['ct']) {
        [$perm, $costs, $appr] = [$in('ct'), $in('ct_costs'), $in('ct_approve')];
        q('DELETE FROM ct_group_perms WHERE company_id = ?', [$cid]);
        foreach ($deps as $d) {
            $p = $lvl($perm[$d] ?? 0);
            // „Kosten sehen“ und „Kündigungen freigeben“ nur zusammen mit mindestens Ansehen
            if ($p) insert('ct_group_perms', ['company_id' => $cid, 'department_id' => $d, 'perm' => $p,
                'see_costs' => !empty($costs[$d]) ? 1 : 0, 'approve_cancel' => !empty($appr[$d]) ? 1 : 0]);
        }
    }
    if ($acc['edit']['tickets'] && $before['queues']) {
        $sel = $in('q');
        $qids = array_map(fn ($q) => (int)$q['id'], $before['queues']);
        $ph = implode(',', array_fill(0, count($qids), '?'));
        q("DELETE FROM ticket_queue_groups WHERE queue_id IN ($ph)", $qids);
        foreach ($deps as $d) foreach ($qids as $qid) {
            if (!empty($sel[$d][$qid])) insert('ticket_queue_groups', ['queue_id' => $qid, 'department_id' => $d]);
        }
    }
    if ($acc['edit']['wiki'] && $before['spaces']) {
        $sel = $in('sp');
        q('DELETE FROM wk_space_perms WHERE company_id = ?', [$cid]);
        foreach ($deps as $d) foreach ($before['spaces'] as $sp) {
            if ($p = $lvl($sel[$d][(int)$sp['id']] ?? 0)) insert('wk_space_perms', ['company_id' => $cid, 'space_id' => (int)$sp['id'], 'department_id' => $d, 'perm' => $p]);
        }
    }
    if ($acc['edit']['vault'] && $before['folders']) {
        // nur Abteilungszeilen; Personen bleiben. Neue Mitglieder bekommen den Schlüssel, sobald ein Mitglied den Tresor öffnet;
        // entzogene Rechte lösen dort wie bisher den Schlüsselwechsel aus.
        $sel = $in('fp');
        q('DELETE FROM wk_vault_folder_perms WHERE company_id = ? AND department_id IS NOT NULL', [$cid]);
        foreach ($deps as $d) foreach ($before['folders'] as $f) {
            if ($p = $lvl($sel[$d][(int)$f['id']] ?? 0)) insert('wk_vault_folder_perms', ['company_id' => $cid, 'folder_id' => (int)$f['id'], 'department_id' => $d, 'user_id' => null, 'perm' => $p]);
        }
    }
}

/** Protokoll: geänderte Zellen als „Abteilung · Spalte: alt → neu“, aufgeteilt auf Einträge zu höchstens 500 Zeichen */
function rights_log(int $cid, array $old, array $new): int
{
    $lines = [];
    foreach ($new as $k => $v) if (($old[$k] ?? 0) !== $v) $lines[] = $k . ': ' . ($old[$k] ?? 0) . '→' . $v;
    $chunk = '';
    foreach ($lines as $l) {
        if ($chunk !== '' && mb_strlen($chunk) + mb_strlen($l) + 2 > 500) { audit('settings.rights', $chunk, $cid); $chunk = ''; }
        $chunk .= ($chunk === '' ? '' : '; ') . $l;
    }
    if ($chunk !== '') audit('settings.rights', $chunk, $cid);
    return count($lines);
}

function page_rights(): void
{
    $u = require_login();
    $cid = is_staff_role($u['role']) ? (query_int('company') ?: (int)context_company_id()) : (int)$u['company_id'];
    $acc = rights_access($cid);
    if (!$acc) abort(403, t('Die Einstellungen pflegen die Administratoren der Firma.'));
    $params = $acc['staff'] ? ['company' => $cid] : [];

    if (is_post()) {
        require_post_csrf();
        if (!array_filter($acc['edit'])) abort(403);
        $before = rights_load($cid, $acc);
        $old = rights_flat($before, $acc);
        db()->beginTransaction();
        try {
            rights_save($cid, $acc, $before);
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            throw $ex;
        }
        $n = rights_log($cid, $old, rights_flat(rights_load($cid, $acc), $acc));
        flash('success', $n ? t('Die Rechte wurden gespeichert ({n} Änderungen).', ['n' => $n]) : t('Es gab keine Änderungen.'));
        redirect('rights', $params + (isset($_GET['view']) ? ['view' => (string)$_GET['view']] : []));
    }

    $s = rights_load($cid, $acc);
    $admins = array_column(rows("SELECT name FROM users WHERE company_id = ? AND role = 'firma_admin' AND active = 1 ORDER BY name", [$cid]), 'name');
    $log = rows("SELECT a.details, a.created_at, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
        WHERE a.company_id = ? AND a.action = 'settings.rights' ORDER BY a.id DESC LIMIT 15", [$cid]);
    $person = null;
    $people = rows("SELECT id, name FROM users WHERE company_id = ? AND active = 1 AND role = 'firma_user' ORDER BY name", [$cid]);
    if (($pid = query_int('person')) && in_array($pid, array_map('intval', array_column($people, 'id')), true)) {
        $mine = array_map('intval', array_column(rows('SELECT department_id FROM user_departments WHERE user_id = ?', [$pid]), 'department_id'));
        $person = ['id' => $pid, 'deps' => $mine];
    }

    render_page(t('Gruppenrechte'), function () use ($cid, $acc, $s, $admins, $log, $people, $person, $params) {
        echo company_switcher($cid, 'rights');
        $levels = array_map('t', RIGHTS_LEVELS);
        $sel = function (string $name, int $val, bool $on, string $label) use ($levels) {
            $h = '<select name="' . e($name) . '" aria-label="' . e($label) . '"' . ($on ? '' : ' disabled') . '>';
            foreach ($levels as $k => $l) $h .= '<option value="' . $k . '"' . ($val === $k ? ' selected' : '') . '>' . e($l) . '</option>';
            return $h . '</select>';
        };
        $chk = fn (string $name, int $val, bool $on, string $label) => '<input type="checkbox" name="' . e($name) . '" value="1"' . ($val ? ' checked' : '')
            . ($on ? '' : ' disabled') . ' aria-label="' . e($label) . '">';
        $count = fn (array $m, string $one, string $many) => ($n = count(array_filter($m))) ? t($n === 1 ? $one : $many, ['n' => $n]) : '–';
        $deps = $s['deps']; $a = $acc['apps']; $ed = $acc['edit'];
        $canSave = (bool)array_filter($ed); ?>
        <div class="card">
            <h2><?= e(t('Gruppenrechte')) ?></h2>
            <p class="muted"><?= e(t('Rechte vergeben Sie je Abteilung. Wer in mehreren Abteilungen ist, bekommt jeweils das höchste Recht. Gespeichert wird die ganze Tabelle auf einmal.')) ?></p>
            <p class="muted small"><?= e(t('Wer in welcher Gruppe ist, legen Sie unter „Gruppen“ fest.')) ?> <a href="<?= e(url('group_members', $params)) ?>"><?= e(t('Gruppen öffnen')) ?></a></p>
            <p class="muted small"><?= e(t('Firmen-Administratoren dürfen immer alles:')) ?> <?= e($admins ? implode(', ', $admins) : '–') ?></p>
            <p class="tabs"><a href="<?= e(url('rights', $params)) ?>" class="<?= $person ? '' : 'active' ?>"><?= e(t('Nach Abteilung')) ?></a>
                <a href="<?= e(url('rights', $params + ['view' => 'person'])) ?>" class="<?= $person || isset($_GET['view']) ? 'active' : '' ?>"><?= e(t('Nach Person')) ?></a></p>
        </div>
        <?php if ($person || isset($_GET['view'])): ?>
            <form method="get" class="card form"><input type="hidden" name="r" value="rights"><input type="hidden" name="view" value="person">
                <?php if ($params): ?><input type="hidden" name="company" value="<?= (int)$cid ?>"><?php endif; ?>
                <label><?= e(t('Person')) ?> <select name="person" class="js-autosubmit"><option value="0">–</option>
                    <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $person && $person['id'] === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                </select></label> <noscript><button class="btn small"><?= e(t('Anzeigen')) ?></button></noscript>
                <?php if ($person): $pd = $person['deps']; $dn = array_column($deps, 'name', 'id');
                    $max = fn (array $byDep) => max(array_merge([0], array_map(fn ($d) => (int)($byDep[$d] ?? 0), $pd)));
                    $rowsOut = [];
                    if ($a['inv']) $rowsOut[] = [t('Geräte & Lizenzen'), $levels[$max($s['inv'])]];
                    if ($a['ct']) {
                        $rowsOut[] = [t('Verträge'), $levels[$max(array_map(fn ($x) => $x[0], $s['ct']))]];
                        $rowsOut[] = [t('Kosten sehen'), $max(array_map(fn ($x) => $x[0] ? $x[1] : 0, $s['ct'])) ? t('ja') : '–'];
                        $rowsOut[] = [t('Kündigungen freigeben'), $max(array_map(fn ($x) => $x[0] ? $x[2] : 0, $s['ct'])) ? t('ja') : '–'];
                    }
                    foreach ($s['queues'] as $q) $rowsOut[] = [t('Queue „{x}“', ['x' => $q['name']]), $max(array_map(fn ($m) => (int)($m[(int)$q['id']] ?? 0), $s['q'])) ? t('Bearbeiter') : '–'];
                    foreach ($s['spaces'] as $sp) $rowsOut[] = [t('Bereich „{x}“', ['x' => $sp['name']]), (int)$sp['all_read'] && !$max(array_map(fn ($m) => (int)($m[(int)$sp['id']] ?? 0), $s['sp']))
                        ? t('Ansehen (alle Mitarbeiter)') : $levels[$max(array_map(fn ($m) => (int)($m[(int)$sp['id']] ?? 0), $s['sp']))]];
                    foreach ($s['folders'] as $f) $rowsOut[] = [t('Tresor-Ordner „{x}“', ['x' => $f['name']]), $levels[$max(array_map(fn ($m) => (int)($m[(int)$f['id']] ?? 0), $s['fp']))]]; ?>
                    <p class="muted small"><?= e(t('Abteilungen:')) ?> <?= e($pd ? implode(', ', array_map(fn ($d) => $dn[$d] ?? '?', $pd)) : t('keine')) ?></p>
                    <table class="table compact"><thead><tr><th><?= e(t('App / Bereich')) ?></th><th><?= e(t('Recht')) ?></th></tr></thead><tbody>
                    <?php foreach ($rowsOut as [$k, $v]): ?><tr><td><?= e($k) ?></td><td><?= e($v) ?></td></tr><?php endforeach; ?>
                    </tbody></table>
                    <?php if ($a['vault']): ?><p class="muted small"><?= e(t('Rechte für einzelne Personen im Tresor sind hier nicht enthalten; sie stehen im jeweiligen Ordner.')) ?></p><?php endif; ?>
                <?php endif; ?>
            </form>
            <?php return; endif; ?>

        <?php if (!$deps): ?>
            <div class="card"><p class="muted"><?= e(t('Es sind noch keine Abteilungen angelegt.')) ?>
                <?php if (company_has_app($cid, 'onboarding')): ?><a href="<?= e(url('structure', $params)) ?>"><?= e(t('Abteilungen anlegen')) ?></a><?php endif; ?></p></div>
            <?php return; endif; ?>

        <form method="post" class="card form">
            <?= csrf_field() ?>
            <div class="table-scroll rights-scroll"><table class="table rights-table">
                <thead><tr><th><?= e(t('Abteilung')) ?></th>
                    <?php if ($a['inv']): ?><th><?= e(t('Geräte & Lizenzen')) ?></th><?php endif; ?>
                    <?php if ($a['ct']): ?><th><?= e(t('Verträge')) ?></th><th><?= e(t('Kosten sehen')) ?></th><th><?= e(t('Kündigungen freigeben')) ?></th><?php endif; ?>
                    <?php if ($a['tickets']): ?><th><a href="#r-tickets"><?= e(t('Tickets')) ?> ▸</a></th><?php endif; ?>
                    <?php if ($a['wiki']): ?><th><a href="#r-wiki"><?= e(t('Dokumente')) ?> ▸</a></th><?php endif; ?>
                    <?php if ($a['vault']): ?><th><a href="#r-vault"><?= e(t('Tresor')) ?> ▸</a></th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($deps as $d): $id = (int)$d['id']; $dn = $d['name']; [$cp, $cc, $ca] = $s['ct'][$id] ?? [0, 0, 0]; ?>
                    <tr><th scope="row"><?= e($dn) ?></th>
                        <?php if ($a['inv']): ?><td><?= $sel("inv[$id]", (int)($s['inv'][$id] ?? 0), $ed['inv'], t('Geräte & Lizenzen') . ' · ' . $dn) ?></td><?php endif; ?>
                        <?php if ($a['ct']): ?><td><?= $sel("ct[$id]", $cp, $ed['ct'], t('Verträge') . ' · ' . $dn) ?></td>
                            <td><?= $chk("ct_costs[$id]", $cp ? $cc : 0, $ed['ct'], t('Kosten sehen') . ' · ' . $dn) ?></td>
                            <td><?= $chk("ct_approve[$id]", $cp ? $ca : 0, $ed['ct'], t('Kündigungen freigeben') . ' · ' . $dn) ?></td><?php endif; ?>
                        <?php if ($a['tickets']): ?><td class="muted small"><?= e($s['queues'] ? t('{n} von {m} Queues', ['n' => count(array_filter($s['q'][$id] ?? [])), 'm' => count($s['queues'])]) : '–') ?></td><?php endif; ?>
                        <?php if ($a['wiki']): ?><td class="muted small"><?= e($count($s['sp'][$id] ?? [], '{n} Bereich', '{n} Bereiche')) ?></td><?php endif; ?>
                        <?php if ($a['vault']): ?><td class="muted small"><?= e($count($s['fp'][$id] ?? [], '{n} Ordner', '{n} Ordner')) ?><?= !empty($s['fwait'][$id]) ? ' · ' . e(t('wartet')) : '' ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($a['ct']): ?><p class="muted small"><?= e(t('„Kosten sehen“ und „Kündigungen freigeben“ wirken nur zusammen mit „Ansehen“ oder „Bearbeiten“ bei den Verträgen.')) ?></p><?php endif; ?>

            <?php if ($a['tickets']): ?>
            <details class="queue-box" id="r-tickets"><summary><strong><?= e(t('Tickets: Bearbeiter je Queue')) ?></strong></summary>
                <?php if (!$s['queues']): ?><p class="muted"><?= e(t('Es sind noch keine Queues angelegt.')) ?></p><?php else: ?>
                <p class="muted small"><?= e(t('Angehakte Abteilungen bearbeiten die Tickets der Queue und bekommen bei neuen Tickets eine Mail. Einzelne Personen und die Teamleitung stellen Sie in der Queue selbst ein.')) ?></p>
                <div class="table-scroll"><table class="table compact"><thead><tr><th><?= e(t('Abteilung')) ?></th>
                    <?php foreach ($s['queues'] as $q): ?><th><?= e($q['name']) ?><?= (int)$q['active'] ? '' : ' <span class="tag">' . e(t('inaktiv')) . '</span>' ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach ($deps as $d): $id = (int)$d['id']; ?><tr><th scope="row"><?= e($d['name']) ?></th>
                    <?php foreach ($s['queues'] as $q): $qid = (int)$q['id']; ?><td><?= $chk("q[$id][$qid]", (int)($s['q'][$id][$qid] ?? 0), $ed['tickets'], $q['name'] . ' · ' . $d['name']) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?></tbody></table></div>
                <?php endif; ?>
            </details>
            <?php endif; ?>

            <?php if ($a['wiki']): ?>
            <details class="queue-box" id="r-wiki"><summary><strong><?= e(t('Dokumente & Anleitungen: Rechte je Bereich')) ?></strong></summary>
                <?php if (!$s['spaces']): ?><p class="muted"><?= e(t('Es sind noch keine Bereiche angelegt.')) ?></p><?php else: ?>
                <div class="table-scroll"><table class="table compact"><thead><tr><th><?= e(t('Abteilung')) ?></th>
                    <?php foreach ($s['spaces'] as $sp): ?><th><?= e($sp['name']) ?><?= (int)$sp['all_read'] ? '<br><span class="muted small">' . e(t('alle lesen')) . '</span>' : '' ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach ($deps as $d): $id = (int)$d['id']; ?><tr><th scope="row"><?= e($d['name']) ?></th>
                    <?php foreach ($s['spaces'] as $sp): $sid = (int)$sp['id']; ?><td><?= $sel("sp[$id][$sid]", (int)($s['sp'][$id][$sid] ?? 0), $ed['wiki'], $sp['name'] . ' · ' . $d['name']) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?></tbody></table></div>
                <p class="muted small"><?= e(t('„alle lesen“ stellen Sie im Bereich selbst ein; dann dürfen alle Mitarbeiter den Bereich ansehen.')) ?></p>
                <?php endif; ?>
            </details>
            <?php endif; ?>

            <?php if ($a['vault']): ?>
            <details class="queue-box" id="r-vault"><summary><strong><?= e(t('Tresor: Rechte je Ordner')) ?></strong></summary>
                <?php if (!$s['folders']): ?><p class="muted"><?= e(t('Es sind noch keine Tresor-Ordner angelegt.')) ?></p><?php else: ?>
                <div class="table-scroll"><table class="table compact"><thead><tr><th><?= e(t('Abteilung')) ?></th>
                    <?php foreach ($s['folders'] as $f): $np = (int)($s['fpeople'][(int)$f['id']] ?? 0); ?><th><?= e($f['name']) ?><?= $np ? '<br><span class="muted small">' . e(t('+ {n} Personen', ['n' => $np])) . '</span>' : '' ?></th><?php endforeach; ?></tr></thead><tbody>
                <?php foreach ($deps as $d): $id = (int)$d['id']; ?><tr><th scope="row"><?= e($d['name']) ?></th>
                    <?php foreach ($s['folders'] as $f): $fid = (int)$f['id']; ?><td><?= $sel("fp[$id][$fid]", (int)($s['fp'][$id][$fid] ?? 0), $ed['vault'], $f['name'] . ' · ' . $d['name']) ?>
                        <?= !empty($s['fwait'][$id][$fid]) ? '<span class="tag">' . e(t('wartet')) . '</span>' : '' ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?></tbody></table></div>
                <p class="muted small"><?= e(t('Ein neues Recht greift erst, wenn ein bisheriges Mitglied den Tresor öffnet und den Ordnerschlüssel weitergibt; bis dahin steht „wartet“ in der Zelle. Entzogene Rechte lösen beim nächsten Öffnen einen Schlüsselwechsel aus. Rechte für einzelne Personen pflegen Sie im Ordner selbst.')) ?></p>
                <?php endif; ?>
            </details>
            <?php endif; ?>

            <?php if ($canSave): ?><button class="btn primary"><?= e(t('Rechte speichern')) ?></button>
            <?php else: ?><p class="muted small"><?= e(t('Diese Rechte kann nur ein Administrator der Firma ändern.')) ?></p><?php endif; ?>
            <?php if ($acc['staff'] && $canSave && array_filter($ed) !== array_filter($a)): ?>
                <p class="muted small"><?= e(t('Ausgegraute Spalten darf nur die Firma selbst ändern bzw. erst mit ihrer Einsicht-Freigabe.')) ?></p><?php endif; ?>
        </form>

        <div class="card">
            <h2><?= e(t('Protokoll')) ?></h2>
            <?php if (!$log): ?><p class="muted"><?= e(t('Noch keine Änderungen über diese Seite.')) ?></p><?php else: ?>
            <table class="table compact"><thead><tr><th><?= e(t('Zeitpunkt')) ?></th><th><?= e(t('Von')) ?></th><th><?= e(t('Änderung (vorher → nachher; 0 = –, 1 = Ansehen/ja, 2 = Bearbeiten)')) ?></th></tr></thead><tbody>
            <?php foreach ($log as $l): ?><tr><td><?= e(fmt_dt($l['created_at'])) ?></td><td><?= e((string)($l['name'] ?? '–')) ?></td><td class="small"><?= e($l['details']) ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <?php endif; ?>
        </div>
    <?php });
}

// ---------------------------------------------------------------------------
// Kategorie „Gruppen“: wer in welcher Abteilung (Gruppe) ist – ersetzt die Gruppen im Benutzerformular
// und den Abschnitt „Gruppen“ in den Ticket-Einstellungen
// ---------------------------------------------------------------------------

/** Darf die Mitgliedschaften sehen [, ändern]? */
function groups_access(int $cid): ?array
{
    $u = current_user();
    if (!$u || !$cid) return null;
    $own = $u['role'] === 'firma_admin' && (int)$u['company_id'] === $cid;
    if ($own) return ['edit' => true, 'staff' => false];
    if (is_staff_role($u['role']) && can_access_company($cid) && admin_can('users')) return ['edit' => admin_can('users', true), 'staff' => true];
    return null;
}

function page_group_members(): void
{
    $u = require_login();
    $cid = is_staff_role($u['role']) ? (query_int('company') ?: (int)context_company_id()) : (int)$u['company_id'];
    $acc = groups_access($cid);
    if (!$acc) abort(403, t('Die Einstellungen pflegen die Administratoren der Firma.'));
    $params = $acc['staff'] ? ['company' => $cid] : [];
    $deps = departments_of($cid);
    $people = rows("SELECT id, name, role, active FROM users WHERE company_id = ? AND role IN ('firma_admin', 'firma_user') ORDER BY active DESC, name", [$cid]);
    $depIds = array_map(fn ($d) => (int)$d['id'], $deps);
    $member = [];
    if ($depIds) {
        foreach (rows('SELECT user_id, department_id FROM user_departments WHERE department_id IN (' . implode(',', $depIds) . ')') as $r) {
            $member[(int)$r['user_id']][(int)$r['department_id']] = 1;
        }
    }

    if (is_post()) {
        require_post_csrf();
        if (!$acc['edit']) abort(403);
        $sel = is_array($_POST['m'] ?? null) ? $_POST['m'] : [];
        $depNames = array_column($deps, 'name', 'id');
        $changes = [];
        db()->beginTransaction();
        foreach ($people as $p) {
            $pid = (int)$p['id'];
            $want = array_keys(array_filter((array)($sel[$pid] ?? [])));
            $want = array_values(array_intersect(array_map('intval', $want), $depIds));
            $had = array_keys($member[$pid] ?? []);
            $add = array_diff($want, $had);
            $del = array_diff($had, $want);
            if (!$add && !$del) continue;
            user_departments_set_for_user($pid, $want, $depIds);
            foreach ($add as $d) $changes[] = $p['name'] . ' +' . ($depNames[$d] ?? $d);
            foreach ($del as $d) $changes[] = $p['name'] . ' −' . ($depNames[$d] ?? $d);
        }
        db()->commit();
        if ($changes) {
            foreach (array_chunk($changes, 12) as $chunk) audit('settings.groups', mb_substr(implode('; ', $chunk), 0, 500), $cid);
            flash('success', t('Die Gruppen wurden gespeichert ({n} Änderungen).', ['n' => count($changes)]));
        } else {
            flash('success', t('Es gab keine Änderungen.'));
        }
        redirect('group_members', $params);
    }

    render_page(t('Gruppen'), function () use ($cid, $acc, $deps, $people, $member, $params) {
        echo company_switcher($cid, 'group_members'); ?>
        <div class="card">
            <h2><?= e(t('Gruppen')) ?></h2>
            <p class="muted"><?= e(t('Gruppen sind die Abteilungen der Firma. Hier legen Sie fest, wer in welcher Gruppe ist; ein Konto kann in mehreren Gruppen sein. Was eine Gruppe in den Apps darf, steht unter „Gruppenrechte“.')) ?>
                <a href="<?= e(url('rights', $params)) ?>"><?= e(t('Gruppenrechte öffnen')) ?></a></p>
            <?php if (!$deps): ?>
                <p class="muted"><?= e(t('Es sind noch keine Abteilungen angelegt.')) ?>
                <?php if (company_has_app($cid, 'onboarding')): ?><a href="<?= e(url('structure', $params)) ?>"><?= e(t('Abteilungen anlegen')) ?></a><?php endif; ?></p>
            <?php elseif (!$people): ?>
                <p class="muted"><?= e(t('Noch keine Benutzer.')) ?></p>
            <?php else: ?>
            <form method="post" class="form"><?= csrf_field() ?>
                <div class="table-scroll rights-scroll"><table class="table rights-table">
                    <thead><tr><th><?= e(t('Benutzer')) ?></th><?php foreach ($deps as $d): ?><th><?= e($d['name']) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($people as $p): $pid = (int)$p['id']; ?>
                        <tr class="<?= (int)$p['active'] ? '' : 'inactive' ?>"><th scope="row"><?= e($p['name']) ?><br><span class="muted small"><?= e(role_label($p['role'])) ?></span></th>
                        <?php foreach ($deps as $d): $did = (int)$d['id']; ?>
                            <td><input type="checkbox" name="m[<?= $pid ?>][<?= $did ?>]" value="1" <?= !empty($member[$pid][$did]) ? 'checked' : '' ?> <?= $acc['edit'] ? '' : 'disabled' ?> aria-label="<?= e($d['name'] . ' · ' . $p['name']) ?>"></td>
                        <?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if ($acc['edit']): ?><button class="btn primary"><?= e(t('Gruppen speichern')) ?></button><?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    <?php });
}
