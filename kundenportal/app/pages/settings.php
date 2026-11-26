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
    if ($own || admin_can('mail')) {
        $cats[] = ['key' => 'email', 'label' => t('E-Mail-Versand'), 'group' => 'firma', 'pages' => [['mail_settings', t('E-Mail-Versand'), $p]]];
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
    'inv_settings', 'inv_integrations', 'contract_settings', 'form_settings', 'form_layouts', 'doc_settings'];

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
