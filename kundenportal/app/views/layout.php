<?php
/** @var ?array $user  @var array $flashes  @var string $title  @var callable $content  @var string $route */
$appName = cfg('app_name', 'Kundenportal');
$nav = [];
$subnav = [];
$onboardingRoutes = ['dashboard', 'request_new', 'change_new', 'request_edit', 'request', 'structure', 'catalog', 'templates'];
if ($user) {
    $nav[] = ['home', 'Start', $route === 'home'];
    $myApps = array_column(apps_for_user(), null, 'slug');
    if (isset($myApps['onboarding'])) {
        $nav[] = ['dashboard', $myApps['onboarding']['name'], in_array($route, $onboardingRoutes, true)];
        if (in_array($route, $onboardingRoutes, true)) {
            $curType = (string)($_GET['type'] ?? '');
            $subnav[] = ['dashboard', 'Aufträge', [], $route === 'dashboard'];
            $subnav[] = ['request_new', '+ Onboarding', [], $route === 'request_new'];
            $subnav[] = ['change_new', '+ Offboarding', ['type' => 'offboarding'], $route === 'change_new' && $curType === 'offboarding'];
            $subnav[] = ['change_new', '+ Stilllegung', ['type' => 'stilllegung'], $route === 'change_new' && $curType === 'stilllegung'];
            $subnav[] = ['change_new', '+ Reaktivierung', ['type' => 'reaktivierung'], $route === 'change_new' && $curType === 'reaktivierung'];
            if (in_array($user['role'], ['admin', 'firma_admin'], true)) {
                $subnav[] = ['structure', 'Abteilungen & Positionen', [], $route === 'structure'];
                $subnav[] = ['catalog', 'Katalog', [], $route === 'catalog'];
                $subnav[] = ['templates', 'Vorlagen', [], $route === 'templates'];
            }
        }
    }
    if ($user['role'] === 'admin') {
        $nav[] = ['companies', 'Firmen', in_array($route, ['companies', 'company_edit'], true)];
        $nav[] = ['users', 'Benutzer', in_array($route, ['users', 'user_edit'], true)];
        $nav[] = ['apps', 'Apps & Freigaben', $route === 'apps'];
        $nav[] = ['mail_settings', 'E-Mail-Versand', $route === 'mail_settings'];
        $nav[] = ['audit', 'Protokoll', $route === 'audit'];
    } elseif ($user['role'] === 'firma_admin') {
        $nav[] = ['company_edit', 'Firmenprofil', $route === 'company_edit'];
        $nav[] = ['users', 'Benutzer', in_array($route, ['users', 'user_edit'], true)];
        $nav[] = ['mail_settings', 'E-Mail-Versand', $route === 'mail_settings'];
    }
}
$companyName = '';
if ($user && $user['company_id']) {
    $companyName = (string)(row('SELECT name FROM companies WHERE id = ?', [$user['company_id']])['name'] ?? '');
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($appName) ?></title>
<link rel="stylesheet" href="assets/style.css">
<?php if ($user): ?><link rel="stylesheet" href="index.php?r=app_css"><?php endif; ?>
</head>
<body>
<header class="topbar">
    <div class="brand"><a href="index.php"><?= e($appName) ?></a></div>
    <?php if ($user): ?>
    <div class="who">
        <span><?= e($user['name']) ?></span>
        <span class="muted"><?= $companyName ? e($companyName) : 'Administration' ?></span>
        <a href="<?= e(url('account')) ?>">Mein Konto</a>
        <form method="post" action="<?= e(url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="linklike">Abmelden</button></form>
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
    <h1><?= e($title) ?></h1>
    <?php foreach ($flashes as [$type, $msg]): ?>
        <div class="alert <?= e($type) ?>"><?= e($msg) ?></div>
    <?php endforeach; ?>
    <?php $content(); ?>
</main>
<script src="assets/app.js"></script>
</body>
</html>
