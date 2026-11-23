<?php
/** @var ?array $user  @var array $flashes  @var string $title  @var callable $content  @var string $route */
$appName = t(cfg('app_name', 'Kundenportal'));
$nav = [];
$subnav = [];
$onboardingRoutes = ['employees', 'employee_edit', 'dashboard', 'request_new', 'change_new', 'request_edit', 'request', 'structure', 'catalog', 'templates'];
if ($user && company_pending($user)) {
    $nav[] = ['home', t('Start'), $route === 'home'];
} elseif ($user) {
    $nav[] = ['home', t('Start'), $route === 'home'];
    $myApps = array_column(apps_for_user(), null, 'slug');
    if (isset($myApps['onboarding'])) {
        $nav[] = ['dashboard', t($myApps['onboarding']['name']), in_array($route, $onboardingRoutes, true)];
        if (in_array($route, $onboardingRoutes, true)) {
            $curType = (string)($_GET['type'] ?? '');
            $subnav[] = ['dashboard', t('Aufträge'), [], $route === 'dashboard'];
            $subnav[] = ['employees', t('Mitarbeiter'), [], in_array($route, ['employees', 'employee_edit'], true)];
            if (!is_staff_role($user['role']) || admin_can('onboarding', true)) {
                $subnav[] = ['request_new', t('+ Onboarding'), [], $route === 'request_new'];
                $subnav[] = ['change_new', t('+ Offboarding'), ['type' => 'offboarding'], $route === 'change_new' && $curType === 'offboarding'];
                $subnav[] = ['change_new', t('+ Stilllegung'), ['type' => 'stilllegung'], $route === 'change_new' && $curType === 'stilllegung'];
                $subnav[] = ['change_new', t('+ Reaktivierung'), ['type' => 'reaktivierung'], $route === 'change_new' && $curType === 'reaktivierung'];
            }
            if (in_array($user['role'], ['superadmin', 'admin', 'firma_admin'], true)) {
                $subnav[] = ['structure', t('Abteilungen & Positionen'), [], $route === 'structure'];
                $subnav[] = ['catalog', t('Katalog'), [], $route === 'catalog'];
                $subnav[] = ['templates', t('Vorlagen'), [], $route === 'templates'];
            }
        }
    }
    $formsRoutes = ['forms', 'form_edit', 'form_layouts', 'form_settings', 'form_use', 'customers', 'customer_edit', 'customer_fields', 'customer_import'];
    if (isset($myApps['formulare'])) {
        $inForms = in_array($route, $formsRoutes, true);
        $nav[] = ['forms', t($myApps['formulare']['name']), $inForms];
        if ($inForms) {
            $fp = is_staff_role($user['role']) && !empty($GLOBALS['forms_cid']) ? ['company' => (int)$GLOBALS['forms_cid']] : [];
            $subnav[] = ['forms', t('Formulare'), $fp, in_array($route, ['forms', 'form_edit', 'form_use'], true)];
            $subnav[] = ['customers', t('Kundendatenbank'), $fp, in_array($route, ['customers', 'customer_edit', 'customer_fields', 'customer_import'], true)];
            if (in_array($user['role'], ['superadmin', 'admin', 'firma_admin'], true)) {
                $subnav[] = ['form_layouts', t('Briefkopf & Fußzeile'), $fp, $route === 'form_layouts'];
                $subnav[] = ['form_settings', t('Logo, Briefpapier & API'), $fp, $route === 'form_settings'];
            }
        }
    }
    if (isset($myApps['tickets'])) {
        $inTickets = in_array($route, ['tickets', 'ticket', 'ticket_new', 'ticket_settings', 'ticket_inbox'], true);
        $nav[] = ['tickets', t($myApps['tickets']['name']), $inTickets];
    }
    if (isset($myApps['inventar'])) {
        $nav[] = ['inventory', t($myApps['inventar']['name']), str_starts_with($route, 'inv_') || $route === 'inventory'];
    }
    if (isset($myApps['vertraege'])) {
        $nav[] = ['contracts', t($myApps['vertraege']['name']), str_starts_with($route, 'contract')];
    }
    if (isset($myApps['links'])) {
        $nav[] = ['links', t($myApps['links']['name']), in_array($route, ['links', 'link_edit'], true)];
    }
    if (isset($myApps['datensicherung']) && $user['role'] === 'firma_admin') {
        $nav[] = ['company_backups', t($myApps['datensicherung']['name']), $route === 'company_backups'];
    }
    if (is_staff_role($user['role'])) {
        $adminNav = [
            'companies' => ['companies', t('Firmen'), in_array($route, ['companies', 'company_edit'], true)],
            'users'     => ['users', t('Benutzer'), in_array($route, ['users', 'user_edit'], true)],
            'apps'      => ['apps', t('Apps & Freigaben'), $route === 'apps'],
            'mail'      => ['mail_settings', t('E-Mail-Versand'), $route === 'mail_settings'],
            'audit'     => ['audit', t('Protokoll'), $route === 'audit'],
            'backups'   => ['backups', t('Datensicherung'), in_array($route, ['backups', 'company_backups'], true)],
            'updates'   => ['updates', t('Updates'), $route === 'updates'],
        ];
        foreach ($adminNav as $area => $item) if (admin_can($area)) $nav[] = $item;
    } elseif ($user['role'] === 'firma_admin') {
        $nav[] = ['company_edit', t('Firmenprofil'), $route === 'company_edit'];
        $nav[] = ['users', t('Benutzer'), in_array($route, ['users', 'user_edit'], true)];
        $nav[] = ['mail_settings', t('E-Mail-Versand'), $route === 'mail_settings'];
    }
}
// App, zu der die aktuelle Seite gehört (für das große Symbol links oben)
$pageApp = null;
if ($user && !company_pending($user)) {
    $routeApps = [
        'onboarding'     => array_merge($onboardingRoutes, ['request_print']),
        'formulare'      => ['forms', 'form_edit', 'form_layouts', 'form_settings', 'form_use', 'customers', 'customer_edit', 'customer_fields', 'customer_import'],
        'datensicherung' => ['company_backups'],
        'links'          => ['links', 'link_edit'],
        'tickets'        => ['tickets', 'ticket', 'ticket_new', 'ticket_settings', 'ticket_inbox'],
        'vertraege'      => ['contracts', 'contract', 'contract_settings'],
    ];
    foreach ($routeApps as $slug => $routes) {
        if (in_array($route, $routes, true)) {
            $pageApp = row('SELECT id, name, icon FROM apps WHERE slug = ?', [$slug]);
            break;
        }
    }
}
$companyName = '';
if ($user && $user['company_id']) {
    $companyName = (string)(row('SELECT name FROM companies WHERE id = ?', [$user['company_id']])['name'] ?? '');
}
?><!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($appName) ?></title>
<link rel="icon" href="assets/logo.svg" type="image/svg+xml">
<link rel="icon" href="assets/favicon-32.png" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<meta name="theme-color" content="#16263a">
<link rel="stylesheet" href="assets/style.css">
<?php if ($user): ?><link rel="stylesheet" href="index.php?r=app_css"><?php endif; ?>
</head>
<body>
<header class="topbar">
    <div class="brand"><a href="index.php"><img src="assets/logo.svg" width="30" height="30" alt=""><span><?= e($appName) ?></span></a></div>
    <?php if (!$user): ?><?= lang_switcher() ?><?php endif; ?>
    <?php if ($user): ?>
    <div class="who">
        <span><?= e($user['name']) ?></span>
        <span class="muted"><?= $companyName ? e($companyName) : e(($user['role'] ?? '') === 'dienstleister' ? t('Externer Dienstleister') : t('Administration')) ?></span>
        <a href="<?= e(url('account')) ?>"><?= e(t('Mein Konto')) ?></a>
        <form method="post" action="<?= e(url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="linklike"><?= e(t('Abmelden')) ?></button></form>
    </div>
    <?php endif; ?>
</header>
<?php if ($nav): ?>
<nav class="mainnav">
    <?php foreach ($nav as [$r, $label, $active]): ?>
        <a href="<?= e(url($r)) ?>" class="<?= $active ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if ($subnav): ?>
<nav class="subnav">
    <?php foreach ($subnav as [$r, $label, $params, $active]): ?>
        <a href="<?= e(url($r, $params)) ?>" class="<?= $active ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<main>
    <?php if ($pageApp): ?>
    <div class="page-head app-<?= (int)$pageApp['id'] ?>">
        <span class="app-icon app-icon-lg" title="<?= e(t($pageApp['name'])) ?>" aria-hidden="true"><?= e($pageApp['icon'] ?: mb_substr($pageApp['name'], 0, 1)) ?></span>
        <div>
            <?php if (t($pageApp['name']) !== $title): ?><div class="page-app"><?= e(t($pageApp['name'])) ?></div><?php endif; ?>
            <h1><?= e($title) ?></h1>
        </div>
    </div>
    <?php else: ?>
    <h1><?= e($title) ?></h1>
    <?php endif; ?>
    <?php foreach ($flashes as [$type, $msg]): ?>
        <div class="alert <?= e($type) ?>"><?= e($msg) ?></div>
    <?php endforeach; ?>
    <?php $content(); ?>
</main>
<script src="index.php?r=i18n_js&amp;l=<?= e(current_lang()) ?>"></script>
<script src="assets/app.js"></script>
<?php if ($user && $route === 'link_edit'): ?><script src="assets/links.js"></script><?php endif; ?>
<?php if ($user && $route === 'form_layouts'): ?><script src="assets/calc.js"></script><script src="assets/forms.js"></script><?php endif; ?>
</body>
</html>
