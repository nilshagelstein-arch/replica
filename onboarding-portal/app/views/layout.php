<?php
/** @var ?array $user  @var array $flashes  @var string $title  @var callable $content  @var string $route */
$appName = cfg('app_name', 'Onboarding-Portal');
$nav = [];
if ($user) {
    $nav[] = ['dashboard', 'Aufträge'];
    $nav[] = ['request_new', 'Neuer Auftrag'];
    if ($user['role'] === 'admin') {
        $nav[] = ['companies', 'Firmen'];
    } elseif ($user['role'] === 'firma_admin') {
        $nav[] = ['company_edit', 'Firmenprofil'];
    }
    if (in_array($user['role'], ['admin', 'firma_admin'], true)) {
        $nav[] = ['users', 'Benutzer'];
        $nav[] = ['structure', 'Abteilungen & Positionen'];
        $nav[] = ['catalog', 'Katalog'];
        $nav[] = ['templates', 'Vorlagen'];
    }
    if ($user['role'] === 'admin') $nav[] = ['audit', 'Protokoll'];
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
    <?php foreach ($nav as [$r, $label]): ?>
        <a href="<?= e(url($r)) ?>" class="<?= $route === $r ? 'active' : '' ?>"><?= e($label) ?></a>
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
