<?php
/**
 * App-Startseite und Verwaltung der App-Freigaben je Firma.
 */

function app_href(array $a): string
{
    return $a['kind'] === 'intern' ? url($a['target']) : $a['target'];
}

function valid_color(string $c): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '#1f5fa8';
}

/** Farbdefinitionen der App-Kacheln als Stylesheet (Content-Security-Policy erlaubt keine Inline-Styles) */
function page_app_css(): void
{
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: no-cache');
    foreach (rows('SELECT id, color FROM apps') as $a) {
        $c = valid_color((string)$a['color']);
        echo ".app-{$a['id']} .app-icon{background:{$c}}.app-{$a['id']}{border-top-color:{$c}}\n";
    }
    exit;
}

/** Kurzinfo je interner App für die Kachel */
function app_badge(array $a): string
{
    if ($a['slug'] !== 'onboarding') return '';
    $u = current_user();
    $in = "'" . implode("','", OPEN_STATUSES) . "'";
    $n = is_staff_role($u['role'])
        ? (int)row("SELECT COUNT(*) AS n FROM requests WHERE status IN ($in)")['n']
        : (int)row("SELECT COUNT(*) AS n FROM requests WHERE status IN ($in) AND company_id = ?", [$u['company_id']])['n'];
    return $n ? $n . ' offen' : '';
}

function page_home(): void
{
    $u = require_login();
    $apps = apps_for_user();
    $grantCounts = [];
    if (is_staff_role($u['role'])) {
        foreach (rows('SELECT app_id, COUNT(*) AS n FROM company_apps GROUP BY app_id') as $g) $grantCounts[(int)$g['app_id']] = (int)$g['n'];
    }
    render_page('Willkommen, ' . $u['name'], function () use ($u, $apps, $grantCounts) { ?>
        <p class="muted">Wählen Sie eine Anwendung.</p>
        <?php if (!$apps): ?>
            <div class="card"><p>Für Ihre Firma sind noch keine Apps freigeschaltet. Bitte wenden Sie sich an Ihren IT-Dienstleister.</p></div>
        <?php else: ?>
        <div class="app-grid">
            <?php foreach ($apps as $a): $ext = $a['kind'] !== 'intern'; $badge = app_badge($a); ?>
                <a class="app-tile app-<?= (int)$a['id'] ?>" href="<?= e(app_href($a)) ?>" <?= $ext ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <span class="app-icon"><?= e($a['icon'] ?: mb_substr($a['name'], 0, 1)) ?></span>
                    <span class="app-name"><?= e($a['name']) ?><?= $ext ? ' <span class="muted small">↗</span>' : '' ?></span>
                    <span class="app-desc"><?= e($a['description']) ?></span>
                    <span class="app-foot">
                        <?php if ($badge): ?><span class="badge st-eingereicht"><?= e($badge) ?></span><?php endif; ?>
                        <?php if (is_staff_role($u['role'])): ?><span class="muted small">freigeschaltet für <?= $grantCounts[(int)$a['id']] ?? 0 ?> Firma/Firmen</span><?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (is_staff_role($u['role'])): ?>
            <p><a class="btn" href="<?= e(url('apps')) ?>">Apps &amp; Freigaben verwalten</a></p>
        <?php endif;
    });
}

function page_apps(): void
{
    $me = require_admin();
    if (is_post()) {
        require_post_csrf();
        $action = post('action');
        if ($action === 'matrix') {
            $grant = (array)($_POST['grant'] ?? []);
            $appIds = array_map('intval', array_column(rows('SELECT id FROM apps'), 'id'));
            $changes = 0;
            foreach (rows('SELECT id, name FROM companies') as $c) {
                $cid = (int)$c['id'];
                $want = array_values(array_intersect($appIds, array_map('intval', (array)($grant[$cid] ?? []))));
                $have = array_map('intval', array_column(rows('SELECT app_id FROM company_apps WHERE company_id = ?', [$cid]), 'app_id'));
                foreach (array_diff($want, $have) as $aid) {
                    insert('company_apps', ['company_id' => $cid, 'app_id' => $aid, 'granted_by' => $me['id'], 'granted_at' => now()]);
                    audit('app_granted', 'App-ID ' . $aid . ' für ' . $c['name'], $cid);
                    $changes++;
                }
                foreach (array_diff($have, $want) as $aid) {
                    q('DELETE FROM company_apps WHERE company_id = ? AND app_id = ?', [$cid, $aid]);
                    audit('app_revoked', 'App-ID ' . $aid . ' für ' . $c['name'], $cid);
                    $changes++;
                }
            }
            flash('success', $changes ? "Die Freigaben wurden gespeichert ($changes Änderungen)." : 'Keine Änderungen.');
        } elseif ($action === 'add' || $action === 'save') {
            $name = mb_substr(post('name'), 0, 120);
            $data = [
                'name' => $name,
                'description' => mb_substr(post('description'), 0, 500),
                'icon' => mb_substr(post('icon'), 0, 4),
                'color' => valid_color(post('color')),
                'sort' => (int)post('sort', 0),
            ];
            $app = $action === 'save' ? row('SELECT * FROM apps WHERE id = ?', [(int)post('id', 0)]) : null;
            if ($action === 'save' && !$app) abort(404);
            if (!$app || $app['kind'] === 'extern') {
                $target = post('target');
                if (!preg_match('#^https?://[^\s<>"]+$#i', $target)) {
                    flash('error', 'Bitte eine gültige Adresse angeben, die mit https:// beginnt.');
                    redirect('apps');
                }
                $data['target'] = mb_substr($target, 0, 500);
            }
            if ($name === '') {
                flash('error', 'Bitte einen Namen angeben.');
            } elseif ($app) {
                $data['active'] = post('active') === '1' ? 1 : 0;
                update('apps', (int)$app['id'], $data);
                audit('app_updated', $name);
                flash('success', 'Die App wurde gespeichert.');
            } else {
                $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: 'app')), '-') ?: 'app';
                $slug = $base;
                for ($i = 2; row('SELECT id FROM apps WHERE slug = ?', [$slug]); $i++) $slug = $base . '-' . $i;
                insert('apps', $data + ['slug' => $slug, 'kind' => 'extern', 'active' => 1, 'created_at' => now()]);
                audit('app_created', $name);
                flash('success', 'Die App wurde angelegt. Schalten Sie sie jetzt für die gewünschten Firmen frei.');
            }
        } elseif ($action === 'delete') {
            $app = row('SELECT * FROM apps WHERE id = ?', [(int)post('id', 0)]);
            if (!$app || $app['kind'] === 'intern') abort(403);
            q('DELETE FROM company_apps WHERE app_id = ?', [$app['id']]);
            q('DELETE FROM apps WHERE id = ?', [$app['id']]);
            audit('app_deleted', $app['name']);
            flash('success', 'Die App wurde gelöscht.');
        }
        redirect('apps');
    }

    $apps = rows('SELECT * FROM apps ORDER BY sort, name');
    $companies = rows('SELECT id, name, active FROM companies ORDER BY active DESC, name');
    $granted = [];
    foreach (rows('SELECT company_id, app_id FROM company_apps') as $g) $granted[(int)$g['company_id']][(int)$g['app_id']] = true;

    render_page('Apps & Freigaben', function () use ($apps, $companies, $granted) { ?>
        <div class="card">
            <h2>Welche Firma darf welche App nutzen?</h2>
            <?php if (!$companies): ?>
                <p class="muted">Noch keine Firmen angelegt. <a href="<?= e(url('company_edit', ['new' => 1])) ?>">Firma anlegen</a></p>
            <?php else: ?>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="matrix">
                <div class="matrix-wrap">
                <table class="table matrix">
                    <thead><tr><th>Firma</th>
                        <?php foreach ($apps as $a): ?>
                            <th class="center app-<?= (int)$a['id'] ?>-col"><?= e($a['name']) ?><?= (int)$a['active'] ? '' : '<br><span class="tag">inaktiv</span>' ?>
                                <br><button type="button" class="linkbtn small" data-toggle-col="<?= (int)$a['id'] ?>">alle / keine</button></th>
                        <?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($companies as $c): ?>
                        <tr class="<?= (int)$c['active'] ? '' : 'inactive' ?>">
                            <td><a href="<?= e(url('company_edit', ['id' => $c['id']])) ?>"><?= e($c['name']) ?></a><?= (int)$c['active'] ? '' : ' <span class="tag">inaktiv</span>' ?></td>
                            <?php foreach ($apps as $a): ?>
                                <td class="center"><input type="checkbox" name="grant[<?= (int)$c['id'] ?>][]" value="<?= (int)$a['id'] ?>" data-col="<?= (int)$a['id'] ?>"
                                    aria-label="<?= e($a['name'] . ' für ' . $c['name']) ?>" <?= isset($granted[(int)$c['id']][(int)$a['id']]) ? 'checked' : '' ?>></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <button class="btn primary">Freigaben speichern</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Apps</h2>
            <p class="muted">Interne Apps sind Bereiche dieses Portals. Externe Apps sind Links zu anderen Anwendungen,
                die auf der Startseite der freigeschalteten Firmen erscheinen. Diese Anwendungen haben eine eigene Anmeldung.</p>
            <table class="table compact">
                <thead><tr><th>Symbol</th><th>Name</th><th>Beschreibung</th><th>Ziel</th><th>Farbe</th><th class="num">Sort.</th><th>Aktiv</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($apps as $a): $f = 'app' . $a['id']; ?>
                    <tr class="<?= (int)$a['active'] ? '' : 'inactive' ?>">
                        <td><input form="<?= $f ?>" name="icon" value="<?= e($a['icon']) ?>" maxlength="4" class="tiny"></td>
                        <td><input form="<?= $f ?>" name="name" value="<?= e($a['name']) ?>" required maxlength="120"></td>
                        <td><input form="<?= $f ?>" name="description" value="<?= e($a['description']) ?>" maxlength="500"></td>
                        <td><?php if ($a['kind'] === 'intern'): ?><span class="tag">intern</span><?php else: ?>
                            <input form="<?= $f ?>" type="url" name="target" value="<?= e($a['target']) ?>" required maxlength="500"><?php endif; ?></td>
                        <td><input form="<?= $f ?>" type="color" name="color" value="<?= e(valid_color($a['color'])) ?>"></td>
                        <td class="num"><input form="<?= $f ?>" type="number" name="sort" value="<?= (int)$a['sort'] ?>" class="tiny"></td>
                        <td><input form="<?= $f ?>" type="checkbox" name="active" value="1" <?= (int)$a['active'] ? 'checked' : '' ?>></td>
                        <td class="actions">
                            <form id="<?= $f ?>" method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="save">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn small">Speichern</button></form>
                            <?php if ($a['kind'] !== 'intern'): ?>
                            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn small danger" data-confirm="App „<?= e($a['name']) ?>“ löschen? Alle Freigaben dafür werden entfernt.">Löschen</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <h3>Externe App hinzufügen</h3>
            <form method="post" class="form inline-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="add">
                <input name="icon" placeholder="Symbol, z. B. 🎫" maxlength="4" class="tiny">
                <input name="name" placeholder="Name, z. B. Ticketsystem" required maxlength="120">
                <input type="url" name="target" placeholder="https://…" required maxlength="500">
                <input name="description" placeholder="Kurzbeschreibung" maxlength="500">
                <input type="color" name="color" value="#1f5fa8" aria-label="Farbe">
                <button class="btn primary">Hinzufügen</button>
            </form>
        </div>
    <?php });
}
