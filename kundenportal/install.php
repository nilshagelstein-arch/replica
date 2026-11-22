<?php
/**
 * Einmalige Installation: legt Tabellen und den ersten Administrator an.
 * Nach erfolgreicher Installation wird die Datei gesperrt (app/data/installed.lock).
 * Aus Sicherheitsgründen danach am besten vom Server löschen.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/schema.php';
start_session();

$lock = APP_DIR . '/data/installed.lock';
$errors = [];
$done = false;

if (is_file($lock)) {
    render_page(t('Installation'), function () {
        echo ('<div class="card"><p>' . e(t('Das Portal ist bereits installiert. Bitte löschen Sie')) . ' <code>' . e(t('install.php')) . '</code> ' . e(t('vom Server.')) . '</p>')
            . ('<p><a class="btn" href="index.php">' . e(t('Zur Anmeldung')) . '</a></p></div>');
    });
    exit;
}

try {
    db()->query('SELECT 1');
} catch (Throwable $ex) {
    $errors[] = t('Keine Verbindung zur Datenbank: ') . $ex->getMessage();
}

if (!$errors && is_post()) {
    require_post_csrf();
    $name  = post('name');
    $email = strtolower(post('email'));
    $pw    = (string)($_POST['password'] ?? '');
    $pw2   = (string)($_POST['password2'] ?? '');
    if ($name === '') $errors[] = t('Bitte einen Namen angeben.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('Bitte eine gültige E-Mail-Adresse angeben.');
    if ($e = validate_password($pw)) $errors[] = $e;
    if ($pw !== $pw2) $errors[] = t('Die Passwörter stimmen nicht überein.');

    if (!$errors) {
        run_migrations();
        if (row('SELECT id FROM users WHERE email = ?', [$email])) {
            $errors[] = t('Ein Benutzer mit dieser E-Mail-Adresse existiert bereits.');
        } else {
            insert('users', [
                'company_id'    => null,
                'email'         => $email,
                'name'          => $name,
                'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'role'          => 'superadmin',
                'active'        => 1,
                'created_at'    => now(),
            ]);
            if (!row('SELECT id FROM catalog_items LIMIT 1')) {
                $defaults = [
                    'device'     => [t('Notebook'), t('Desktop-PC'), t('Monitor'), t('Dockingstation'), t('Headset'), t('Tastatur & Maus'), t('Smartphone'), t('Mobilfunkvertrag / SIM')],
                    'license'    => [t('Microsoft 365 Business Basic'), t('Microsoft 365 Business Standard'), t('Microsoft 365 Business Premium'), t('Adobe Acrobat Pro')],
                    'software'   => [t('Microsoft Office'), t('Microsoft Teams'), t('Adobe Acrobat Reader'), t('Webbrowser (Chrome/Edge)'), t('Virenschutz')],
                    'drive'      => [],
                    'permission' => [t('E-Mail-Postfach'), t('VPN-Zugang'), t('WLAN-Zugang'), t('Zutrittskarte / Schlüssel')],
                ];
                foreach ($defaults as $type => $names) {
                    foreach ($names as $i => $n) {
                        insert('catalog_items', ['company_id' => null, 'type' => $type, 'name' => $n,
                            'description' => '', 'active' => 1, 'sort' => $i]);
                    }
                }
            }
            if (!is_dir(dirname($lock))) mkdir(dirname($lock), 0770, true);
            file_put_contents($lock, now());
            $done = true;
        }
    }
}

render_page(t('Installation'), function () use ($errors, $done) {
    if ($done) {
        echo ('<div class="card"><h2>' . e(t('Installation abgeschlossen')) . '</h2>')
            . ('<p>' . e(t('Die Datenbank wurde eingerichtet und der Administrator angelegt. Unter')) . ' <em>' . e(t('Apps & Freigaben')) . '</em> ' . e(t('legen Sie fest, welche Firma welche Apps nutzen darf. Bei der ersten Anmeldung')) . ' ')
            . (e(t('richten Sie die Zwei-Faktor-Authentifizierung mit einer Authenticator-App ein.')) . '</p>')
            . ('<p><strong>' . e(t('Wichtig:')) . '</strong> ' . e(t('Löschen Sie jetzt die Datei')) . ' <code>' . e(t('install.php')) . '</code> ' . e(t('vom Server.')) . '</p>')
            . ('<p><a class="btn primary" href="index.php">' . e(t('Zur Anmeldung')) . '</a></p></div>');
        return;
    }
    foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>';
    ?>
    <div class="card narrow">
        <h2><?= e(t('Superadmin anlegen')) ?></h2>
        <p class="muted"><?= e(t('Datenbank:')) ?> <?= e(cfg('db.driver')) ?> <?= e(t('· Dieser Zugang ist der einzige Superadmin: Er verwaltet alle Firmen und als Einziger die Administratoren-Konten.')) ?></p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <label><?= e(t('Name')) ?><input name="name" required value="<?= e(post('name')) ?>"></label>
            <label><?= e(t('E-Mail-Adresse')) ?><input type="email" name="email" required value="<?= e(post('email')) ?>"></label>
            <label><?= e(t('Passwort (mind. 10 Zeichen, Buchstaben und Ziffern)')) ?><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
            <label><?= e(t('Passwort wiederholen')) ?><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
            <button class="btn primary"><?= e(t('Installation starten')) ?></button>
        </form>
    </div>
    <?php
});
