<?php
/**
 * Zentrale Einstellungen (Stufe 1): eine Seite „Einstellungen“ mit Kategorien.
 *
 * Die bisherigen Einstellungsseiten der Apps bleiben unter ihrer Adresse erreichbar und behalten ihre
 * Rechteprüfung und ihre Formulare. Sie werden nur im Rahmen der Einstellungen angezeigt: links die
 * Kategorien, rechts der Inhalt. Die Route „settings“ führt zur passenden Kategorie.
 */

/**
 * Kategorien für eine Firma, gefiltert nach den Rechten des angemeldeten Benutzers.
 * Jede Kategorie: key, label, group ('firma'|'apps'), pages [[route, label, params]].
 */
function settings_categories(int $cid): array
{
    $u = current_user();
    if (!$u || !$cid) return [];
    $role = (string)$u['role'];
    $staff = is_staff_role($role);
    $own = $role === 'firma_admin' && (int)$u['company_id'] === $cid;
    if (!$staff && !$own) return [];
    if ($staff && !can_access_company($cid)) return [];
    $p = $staff ? ['company' => $cid] : [];
    $has = fn (string $slug) => company_has_app($cid, $slug);

    $cats = [];
    // Firma
    if ($own || admin_can('companies')) {
        $cats[] = ['key' => 'firma', 'label' => t('Firmenprofil'), 'group' => 'firma',
            'pages' => [['company_edit', t('Firmenprofil'), $staff ? ['id' => $cid] : []]]];
    }
    if ($own || admin_can('users')) {
        $cats[] = ['key' => 'benutzer', 'label' => t('Benutzer'), 'group' => 'firma', 'pages' => [['users', t('Benutzer'), $p]]];
    }
    if ($has('onboarding') && ($own || admin_can('onboarding'))) {
        $cats[] = ['key' => 'abteilungen', 'label' => t('Abteilungen & Positionen'), 'group' => 'firma',
            'pages' => [['structure', t('Abteilungen & Positionen'), $p]]];
    }
    if (groups_access($cid)) {
        $cats[] = ['key' => 'gruppen', 'label' => t('Gruppen'), 'group' => 'firma', 'pages' => [['group_members', t('Gruppen'), $p]]];
    }
    if (rights_access($cid)) {
        $cats[] = ['key' => 'rechte', 'label' => t('Gruppenrechte'), 'group' => 'firma', 'pages' => [['rights', t('Gruppenrechte'), $p]]];
    }
    if ($own || admin_can('mail')) {
        $cats[] = ['key' => 'email', 'label' => t('E-Mail-Versand'), 'group' => 'firma', 'pages' => [['mail_settings', t('E-Mail-Versand'), $p]]];
    }
    // Einsicht: nur wenn die Firma mindestens eine App mit Einsicht-Schalter hat; ändern darf nur die Firma selbst
    if (provider_access_rows($cid) && ($own || admin_can('companies'))) {
        $cats[] = ['key' => 'einsicht', 'label' => t('Einsicht für den IT-Dienstleister'), 'group' => 'firma',
            'pages' => [['provider_access', t('Einsicht für den IT-Dienstleister'), $p]]];
    }
    // Datensicherung: auch ohne freigeschaltete App sichtbar (dann mit Hinweis und Anfrage)
    if ($own || admin_can('backups')) {
        $cats[] = ['key' => 'datensicherung', 'label' => t('Datensicherung'), 'group' => 'firma',
            'pages' => [['backup_settings', t('Datensicherung'), $p]]];
    }
    // Apps
    if ($has('onboarding') && ($own || admin_can('onboarding'))) {
        $cats[] = ['key' => 'onboarding', 'label' => t('Mitarbeiter-Onboarding'), 'group' => 'apps', 'pages' => [
            ['catalog', t('Katalog'), $p], ['templates', t('Vorlagen'), $p]]];
    }
    if ($has(TICKETS_APP) && ($own || admin_can('tickets', true))) {
        $cats[] = ['key' => 'tickets', 'label' => t('Tickets'), 'group' => 'apps',
            'pages' => [['ticket_settings', t('Queues & Einstellungen'), $p]]];
    }
    if ($has(INV_APP) && ($own || ($staff && admin_can('inventory') && inv_perm($cid) === INV_PERM_EDIT))) {
        $cats[] = ['key' => 'inventar', 'label' => t('Geräte & Lizenzen'), 'group' => 'apps', 'pages' => [
            ['inv_settings', t('Einstellungen'), $p], ['inv_integrations', t('Anbindungen'), $p]]];
    }
    if ($has(CT_APP) && ($own || ($staff && admin_can('contracts') && ct_rights($cid)['perm'] === CT_PERM_EDIT))) {
        $cats[] = ['key' => 'vertraege', 'label' => t('Verträge & Laufzeiten'), 'group' => 'apps',
            'pages' => [['contract_settings', t('Einstellungen'), $p]]];
    }
    if ($has(FORMS_APP) && ($own || admin_can('forms', true))) {
        $cats[] = ['key' => 'formulare', 'label' => t('Formulare'), 'group' => 'apps', 'pages' => [
            ['form_settings', t('Logo, Briefpapier & API'), $p], ['form_layouts', t('Briefkopf & Fußzeile'), $p]]];
    }
    if ($has(WK_APP) && ($own || ($staff && admin_can('docs') && wk_staff_perm($cid, $u) === WK_PERM_EDIT))) {
        $cats[] = ['key' => 'dokumente', 'label' => t('Dokumente & Anleitungen'), 'group' => 'apps',
            'pages' => [['doc_settings', t('Einstellungen'), $p]]];
    }
    return $cats;
}

/** Alle Routen, die im Rahmen der Einstellungen erscheinen können. */
const SETTINGS_ROUTES = ['company_edit', 'users', 'structure', 'mail_settings', 'catalog', 'templates', 'ticket_settings',
    'inv_settings', 'inv_integrations', 'contract_settings', 'form_settings', 'form_layouts', 'doc_settings',
    'provider_access', 'backup_settings', 'rights', 'group_members'];

/** Firma, deren Einstellungen die aktuelle Seite zeigt (0 = nicht im Rahmen der Einstellungen). */
function settings_frame_company(string $route): int
{
    $u = current_user();
    if (!$u || !in_array($route, SETTINGS_ROUTES, true)) return 0;
    if (!is_staff_role($u['role'])) return $u['role'] === 'firma_admin' ? (int)$u['company_id'] : 0;
    // Betreiber: nur mit ausdrücklich gewählter Firma; ohne Angabe sind Benutzer- und Mail-Seite Portal-Verwaltung
    if ($route === 'company_edit') return isset($_GET['new']) ? 0 : query_int('id');
    if (in_array($route, ['users', 'mail_settings'], true) && !query_int('company')) return 0;
    if ($route === 'users' && isset($_GET['admins'])) return 0;
    return query_int('company') ?: (int)context_company_id();
}

/**
 * Rahmen der Einstellungen für die aktuelle Seite: [Firma, Kategorien, aktive Kategorie] oder null.
 */
function settings_frame(string $route): ?array
{
    $cid = settings_frame_company($route);
    if (!$cid) return null;
    $cats = settings_categories($cid);
    foreach ($cats as $c) {
        foreach ($c['pages'] as [$r]) {
            if ($r === $route) return ['cid' => $cid, 'cats' => $cats, 'active' => $c['key']];
        }
    }
    return null;
}

/** Hat der angemeldete Benutzer überhaupt Einstellungen (für den Punkt im Zahnrad)? */
function settings_available(): bool
{
    $u = current_user();
    if (!$u || company_pending($u)) return false;
    if (is_staff_role($u['role'])) {
        $first = row('SELECT id FROM companies WHERE 1 = 1' . company_scope_sql('id') . ' ORDER BY name LIMIT 1');
        return $first && settings_categories((int)$first['id']);
    }
    return (bool)settings_categories((int)$u['company_id']);
}

/** Seite „Einstellungen“: führt zur gewünschten (oder ersten) Kategorie. */
function page_central_settings(): void
{
    $u = require_login();
    $cid = is_staff_role($u['role']) ? (int)context_company_id() : (int)$u['company_id'];
    $cats = settings_categories($cid);
    if (!$cats) abort(403, t('Die Einstellungen pflegen die Administratoren der Firma.'));
    $want = (string)($_GET['cat'] ?? '');
    $cat = $cats[0];
    foreach ($cats as $c) if ($c['key'] === $want) { $cat = $c; break; }
    [$route, , $params] = $cat['pages'][0];
    redirect($route, $params);
}

/** Linke Spalte mit den Kategorien (und für Betreiber der Firmenauswahl). */
function settings_sidebar(array $frame, string $route): string
{
    $html = '<aside class="set-side">';
    if (is_admin()) $html .= company_switcher($frame['cid'], 'settings', ['cat' => $frame['active']]);
    $groups = ['firma' => t('Firma'), 'apps' => t('Apps')];
    foreach ($groups as $g => $glabel) {
        $items = array_filter($frame['cats'], fn ($c) => $c['group'] === $g);
        if (!$items) continue;
        $html .= '<div class="set-group">' . e($glabel) . '</div><ul>';
        foreach ($items as $c) {
            $active = $c['key'] === $frame['active'];
            [$r, , $params] = $c['pages'][0];
            $html .= '<li><a href="' . e(url($r, $params)) . '"' . ($active ? ' class="active"' : '') . '>' . e($c['label']) . '</a>';
            if ($active && count($c['pages']) > 1) {
                $html .= '<ul>';
                foreach ($c['pages'] as [$pr, $pl, $pp]) {
                    $html .= '<li><a href="' . e(url($pr, $pp)) . '"' . ($pr === $route ? ' class="active"' : '') . '>' . e($pl) . '</a></li>';
                }
                $html .= '</ul>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
    }
    // Mobil: Auswahlliste statt Spalte
    $html .= '<form method="get" class="set-pick"><input type="hidden" name="r" value="settings">';
    if (is_admin()) $html .= '<input type="hidden" name="company" value="' . (int)$frame['cid'] . '">';
    $html .= '<label>' . e(t('Kategorie')) . ' <select name="cat" class="js-autosubmit">';
    foreach ($frame['cats'] as $c) {
        $html .= '<option value="' . e($c['key']) . '"' . ($c['key'] === $frame['active'] ? ' selected' : '') . '>' . e($c['label']) . '</option>';
    }
    $html .= '</select></label> <noscript><button class="btn small">' . e(t('Öffnen')) . '</button></noscript></form>';
    return $html . '</aside>';
}

// ---------------------------------------------------------------------------
// Stufe 2: Einsicht für den IT-Dienstleister, gesammelt an einer Stelle
// ---------------------------------------------------------------------------

/**
 * Einsicht-Schalter je App, nur für freigeschaltete Apps der Firma.
 * Jede Zeile: key, app, column, label, hint, extra [[column, label]] (Zusatzfreigaben, nur mit Einsicht).
 */
function provider_access_rows(int $cid): array
{
    $all = [
        ['key' => 'tickets', 'app' => 'tickets', 'column' => 'ticket_provider_access', 'label' => 'Tickets',
            'hint' => 'Ihr IT-Dienstleister sieht und bearbeitet die Tickets Ihrer Firma. Tickets, die Sie ihm ausdrücklich übergeben, sieht er auch ohne Einsicht.', 'extra' => []],
        ['key' => 'inventar', 'app' => 'inventar', 'column' => 'inv_provider_access', 'label' => 'Geräte & Lizenzen',
            'hint' => 'Ihr IT-Dienstleister sieht und pflegt Ihr Inventar und bekommt die Erinnerungen zu Lizenzen und Garantien.', 'extra' => []],
        ['key' => 'vertraege', 'app' => 'vertraege', 'column' => 'ct_provider_access', 'label' => 'Verträge & Laufzeiten',
            'hint' => 'Ihr IT-Dienstleister sieht und pflegt Ihre Verträge und bekommt die Fristen der Verträge, die er betreut.', 'extra' => [
                ['ct_provider_costs', '… und dabei auch die Kosten sehen'],
                ['ct_provider_all', '… und in seiner Vertriebsübersicht auch Verträge sehen, die andere Dienstleister oder wir selbst betreuen']]],
        ['key' => 'dienstleister', 'app' => 'tickets', 'column' => 'staff_provider_admin', 'label' => 'Externe Dienstleister verwalten',
            'hint' => 'Ihr IT-Dienstleister darf für Sie wie ein Firmen-Administrator externe Dienstleister (z. B. den Kopierer-Service) anlegen, Ihrer Firma zuordnen und ihre Rechte anpassen.', 'extra' => []],
        ['key' => 'dokumente', 'app' => 'dokumente', 'column' => 'wk_provider_access', 'label' => 'Dokumente & Anleitungen',
            'hint' => 'Ihr IT-Dienstleister sieht und pflegt Ihr Wiki. Den Passwort-Tresor sieht er nur in Ordnern, in die Sie ihn ausdrücklich aufnehmen.', 'extra' => []],
    ];
    return array_values(array_filter($all, fn ($r) => company_has_app($cid, $r['app'])));
}

/** Letzte Änderung je Schalter aus dem Protokoll: key => [Zeitpunkt, Name] */
function provider_access_changes(int $cid): array
{
    $out = [];
    foreach (rows("SELECT a.details, a.created_at, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
        WHERE a.company_id = ? AND a.action = 'settings.provider_access' ORDER BY a.id DESC LIMIT 200", [$cid]) as $a) {
        $d = json_decode((string)$a['details'], true);
        if (!is_array($d)) continue;
        foreach (array_keys($d) as $k) if (!isset($out[$k])) $out[$k] = [(string)$a['created_at'], (string)($a['name'] ?? '')];
    }
    return $out;
}

/** Seite „Einsicht für den IT-Dienstleister“: alle Schalter der Firma; schalten darf nur ein Firmen-Admin */
function page_provider_access(): void
{
    $u = require_login();
    $staff = is_staff_role($u['role']);
    if ($staff) {
        $cid = query_int('company') ?: (int)context_company_id();
        require_company_access($cid);
    } else {
        if ($u['role'] !== 'firma_admin') abort(403, t('Die Einstellungen pflegen die Administratoren der Firma.'));
        $cid = (int)$u['company_id'];
    }
    $rows = provider_access_rows($cid);
    if (!$rows) abort(404, t('Für diese Firma ist keine App mit Einsicht-Schalter freigeschaltet.'));
    $co = row('SELECT * FROM companies WHERE id = ?', [$cid]);
    if (!$co) abort(404);

    if (is_post()) {
        require_post_csrf();
        // Die Einsicht für den Dienstleister schaltet nur die Firma selbst
        if ($staff) abort(403, t('Diese Einsicht kann nur ein Administrator der Firma ein- oder ausschalten.'));
        $on = (array)($_POST['access'] ?? []);
        $data = [];
        $changed = [];
        foreach ($rows as $r) {
            $v = !empty($on[$r['key']]) ? 1 : 0;
            $data[$r['column']] = $v;
            if ((int)$co[$r['column']] !== $v) $changed[$r['key']] = $v;
            foreach ($r['extra'] as [$col]) {
                $x = $v && !empty(($_POST['extra'] ?? [])[$col]) ? 1 : 0;
                $data[$col] = $x;
                if ((int)$co[$col] !== $x) $changed[$r['key'] . '.' . $col] = $x;
            }
        }
        if ($changed) {
            update('companies', $cid, $data);
            audit('settings.provider_access', json_encode($changed), $cid);
            flash('success', t('Die Einstellungen wurden gespeichert.'));
        } else {
            flash('success', t('Es gab keine Änderungen.'));
        }
        redirect('provider_access');
    }

    $changes = provider_access_changes($cid);
    render_page(t('Einsicht für den IT-Dienstleister'), function () use ($cid, $co, $rows, $staff, $changes) {
        echo company_switcher($cid, 'provider_access'); ?>
        <form method="post" class="form card">
            <?= csrf_field() ?>
            <h2><?= e(t('Einsicht für den IT-Dienstleister')) ?></h2>
            <p class="muted"><?= e(t('Hier legen Sie fest, in welche Apps Ihr IT-Dienstleister Einblick bekommt. Ausschalten wirkt sofort. Was er im Einzelnen darf, regeln seine eigenen Rechte; der Schalter ist Ihre Freigabe.')) ?></p>
            <table class="table access-table">
                <thead><tr><th><?= e(t('App')) ?></th><th><?= e(t('Einsicht')) ?></th><th><?= e(t('wenn eingeschaltet')) ?></th><th><?= e(t('zuletzt geändert')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $v = (int)$co[$r['column']]; $ch = $changes[$r['key']] ?? null; ?>
                    <tr>
                        <td><strong><?= e(t($r['label'])) ?></strong></td>
                        <td><label class="check"><input type="checkbox" name="access[<?= e($r['key']) ?>]" value="1" <?= $v ? 'checked' : '' ?> <?= $staff ? 'disabled' : '' ?>>
                            <?= e($v ? t('eingeschaltet') : t('aus')) ?></label></td>
                        <td><?= e(t($r['hint'])) ?>
                            <?php foreach ($r['extra'] as [$col, $lbl]): ?>
                                <label class="check small"><input type="checkbox" name="extra[<?= e($col) ?>]" value="1" <?= (int)$co[$col] ? 'checked' : '' ?> <?= $staff ? 'disabled' : '' ?>> <?= e(t($lbl)) ?></label>
                            <?php endforeach; ?></td>
                        <td class="muted small"><?= $ch ? e(fmt_dt($ch[0]) . ($ch[1] !== '' ? ' · ' . $ch[1] : '')) : '–' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted small"><?= e(t('Zusatzfreigaben gelten nur, solange die Einsicht für die App eingeschaltet ist. Ihre eigenen Rechte über Gruppen gelten unabhängig davon.')) ?></p>
            <?php if ($staff): ?><p class="muted small"><?= e(t('Diese Einsicht kann nur ein Administrator der Firma ein- oder ausschalten.')) ?></p>
            <?php else: ?><button class="btn primary"><?= e(t('Speichern')) ?></button><?php endif; ?>
        </form>
    <?php });
}

/** Kurzer Hinweis in einer App: Einsicht an/aus mit Link zur Kategorie (ersetzt die früheren Einzelschalter) */
function provider_access_note(int $cid, string $column): string
{
    $v = (int)(row("SELECT $column FROM companies WHERE id = ?", [$cid])[$column] ?? 0);
    $link = url('provider_access', is_staff_role(current_user()['role']) ? ['company' => $cid] : []);
    return '<p class="muted small">' . e(t('Einsicht für den IT-Dienstleister:')) . ' <strong>' . e($v ? t('eingeschaltet') : t('aus')) . '</strong> · <a href="'
        . e($link) . '">' . e(t('unter Einstellungen ändern')) . '</a></p>';
}

// ---------------------------------------------------------------------------
// Datensicherung: Einstellungsteil der App, auch ohne Freischaltung sichtbar
// ---------------------------------------------------------------------------

function page_backup_settings(): void
{
    $u = require_login();
    $staff = is_staff_role($u['role']);
    if ($staff) {
        $cid = query_int('company') ?: (int)context_company_id();
        require_company_access($cid);
    } else {
        if ($u['role'] !== 'firma_admin') abort(403, t('Die Datensicherung verwalten nur Firmen-Administratoren.'));
        $cid = (int)$u['company_id'];
    }
    if (!$cid || !row('SELECT id FROM companies WHERE id = ?', [$cid])) abort(404);
    $granted = company_has_app($cid, CBACKUP_APP);
    $params = $staff ? ['company' => $cid] : [];
    $self = url('backup_settings', $params);

    if (is_post()) {
        require_post_csrf();
        $action = (string)post('action');
        if ($action === 'request_app') {
            if ($staff || $granted) abort(400);
            if (setting_get(gk('cbackup_requested', $cid)) === date('Y-m-d')) {
                flash('success', t('Ihre Anfrage ist bereits bei Ihrem IT-Dienstleister.'));
            } else {
                $coName = (string)(row('SELECT name FROM companies WHERE id = ?', [$cid])['name'] ?? '');
                send_mail_t(registration_admin_recipients(), fn () => [t('App-Anfrage: Datensicherung für {x}', ['x' => $coName]),
                    t("Die Firma „{x}“ möchte die App „Datensicherung“ nutzen.\n\nAngefragt von: {n} <{m}>\n\nFreischalten:\n", ['x' => $coName, 'n' => $u['name'], 'm' => $u['email']])
                    . portal_link('company_edit', ['id' => $cid])]);
                setting_set(gk('cbackup_requested', $cid), date('Y-m-d'));
                audit('cbackup.request', '', $cid);
                flash('success', t('Ihre Anfrage wurde an Ihren IT-Dienstleister gesendet.'));
            }
        } elseif (!$granted && !$staff) {
            abort(403, t('Diese App ist für Ihre Firma nicht freigeschaltet. Bitte wenden Sie sich an Ihren IT-Dienstleister.'));
        } elseif ($action === 'settings') {
            setting_set(gk('cbackup_auto', $cid), post('auto') === '1' ? '1' : '0');
            setting_set(gk('cbackup_notify', $cid), post('notify') === '1' ? '1' : '0');
            setting_set(gk('cbackup_keep', $cid), (string)min(365, max(1, (int)post('keep', CBACKUP_KEEP_DEFAULT))));
            audit('cbackup.settings', 'automatisch: ' . (post('auto') === '1' ? 'ja' : 'nein'), $cid);
            flash('success', t('Einstellungen gespeichert.'));
        } elseif ($action === 'gdrive_upload' || !gdrive_handle_post($action, $cid, cbackup_dir($cid))) {
            abort(400);
        }
        header('Location: ' . $self);
        exit;
    }

    $auto = setting_get(gk('cbackup_auto', $cid), '1') === '1';
    $requested = setting_get(gk('cbackup_requested', $cid));
    render_page(t('Datensicherung'), function () use ($cid, $self, $params, $granted, $staff, $auto, $requested) {
        echo company_switcher($cid, 'backup_settings');
        if (!$granted && !$staff): ?>
            <div class="card">
                <h2><?= e(t('Datensicherung')) ?></h2>
                <p><?= e(t('Tägliche Sicherung aller Daten Ihrer Firma – auf Wunsch automatisch in Ihr eigenes Google Drive.')) ?></p>
                <div class="alert info"><?= e(t('Die App „Datensicherung“ ist für Ihre Firma noch nicht freigeschaltet. Ihr IT-Dienstleister kann sie für Sie freischalten.')) ?></div>
                <?php if ($requested !== ''): ?>
                    <p class="muted small"><?= e(t('Zuletzt angefragt am {d}.', ['d' => fmt_date($requested)])) ?></p>
                <?php endif; ?>
                <form method="post" action="<?= e($self) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="request_app">
                    <button class="btn primary"><?= e(t('Freischaltung anfragen')) ?></button></form>
            </div>
        <?php return; endif;
        if (!$granted): ?>
            <div class="alert info"><?= e(t('Die App „Datensicherung“ ist für diese Firma nicht freigeschaltet. Manuelle Sicherungen sind möglich, die tägliche Sicherung läuft erst nach der Freigabe unter')) ?> <a href="<?= e(url('apps')) ?>"><?= e(t('Apps & Freigaben')) ?></a>.</div>
        <?php endif; ?>
        <p><a href="<?= e(url('company_backups', $params)) ?>"><?= e(t('Zu den Sicherungen')) ?></a></p>
        <form method="post" action="<?= e($self) ?>" class="card form"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
            <h2><?= e(t('Tägliche Sicherung')) ?></h2>
            <label class="check"><input type="checkbox" name="auto" value="1" <?= $auto ? 'checked' : '' ?>> <?= e(t('Jede Nacht automatisch sichern')) ?></label>
            <label><?= e(t('Automatische Sicherungen im Portal behalten')) ?>
                <input type="number" name="keep" min="1" max="365" value="<?= (int)setting_get(gk('cbackup_keep', $cid), (string)CBACKUP_KEEP_DEFAULT) ?>"></label>
            <label class="check"><input type="checkbox" name="notify" value="1" <?= setting_get(gk('cbackup_notify', $cid), '1') === '1' ? 'checked' : '' ?>> <?= e(t('Bei Fehlern E-Mail an die Firmen-Administratoren senden')) ?></label>
            <button class="btn primary"><?= e(t('Speichern')) ?></button>
        </form>
        <?php gdrive_card($cid, $self);
    });
}
