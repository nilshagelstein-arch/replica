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
    render_page('Installation', function () {
        echo '<div class="card"><p>Das Portal ist bereits installiert. Bitte löschen Sie <code>install.php</code> vom Server.</p>'
            . '<p><a class="btn" href="index.php">Zur Anmeldung</a></p></div>';
    });
    exit;
}

try {
    db()->query('SELECT 1');
} catch (Throwable $ex) {
    $errors[] = 'Keine Verbindung zur Datenbank: ' . $ex->getMessage();
}

if (!$errors && is_post()) {
    require_post_csrf();
    $name  = post('name');
    $email = strtolower(post('email'));
    $pw    = (string)($_POST['password'] ?? '');
    $pw2   = (string)($_POST['password2'] ?? '');
    if ($name === '') $errors[] = 'Bitte einen Namen angeben.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    if ($e = validate_password($pw)) $errors[] = $e;
    if ($pw !== $pw2) $errors[] = 'Die Passwörter stimmen nicht überein.';

    if (!$errors) {
        $driver = cfg('db.driver', 'mysql');
        foreach (schema_statements($driver) as $sql) {
            try {
                db()->exec($sql);
            } catch (PDOException $ex) {
                // bereits vorhandene Indizes ignorieren
                if (stripos($sql, 'CREATE INDEX') !== 0) throw $ex;
            }
        }
        if (row('SELECT id FROM users WHERE email = ?', [$email])) {
            $errors[] = 'Ein Benutzer mit dieser E-Mail-Adresse existiert bereits.';
        } else {
            insert('users', [
                'company_id'    => null,
                'email'         => $email,
                'name'          => $name,
                'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'role'          => 'admin',
                'active'        => 1,
                'created_at'    => now(),
            ]);
            if (!row('SELECT id FROM catalog_items LIMIT 1')) {
                $defaults = [
                    'device'     => ['Notebook', 'Desktop-PC', 'Monitor', 'Dockingstation', 'Headset', 'Tastatur & Maus', 'Smartphone', 'Mobilfunkvertrag / SIM'],
                    'license'    => ['Microsoft 365 Business Basic', 'Microsoft 365 Business Standard', 'Microsoft 365 Business Premium', 'Adobe Acrobat Pro'],
                    'software'   => ['Microsoft Office', 'Microsoft Teams', 'Adobe Acrobat Reader', 'Webbrowser (Chrome/Edge)', 'Virenschutz'],
                    'drive'      => [],
                    'permission' => ['E-Mail-Postfach', 'VPN-Zugang', 'WLAN-Zugang', 'Zutrittskarte / Schlüssel'],
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

render_page('Installation', function () use ($errors, $done) {
    if ($done) {
        echo '<div class="card"><h2>Installation abgeschlossen</h2>'
            . '<p>Die Datenbank wurde eingerichtet und der Administrator angelegt. Bei der ersten Anmeldung '
            . 'richten Sie die Zwei-Faktor-Authentifizierung mit einer Authenticator-App ein.</p>'
            . '<p><strong>Wichtig:</strong> Löschen Sie jetzt die Datei <code>install.php</code> vom Server.</p>'
            . '<p><a class="btn primary" href="index.php">Zur Anmeldung</a></p></div>';
        return;
    }
    foreach ($errors as $err) echo '<div class="alert error">' . e($err) . '</div>';
    ?>
    <div class="card narrow">
        <h2>Ersten Administrator anlegen</h2>
        <p class="muted">Datenbank: <?= e(cfg('db.driver')) ?> · Dieser Zugang verwaltet später alle Firmen.</p>
        <form method="post" class="form">
            <?= csrf_field() ?>
            <label>Name<input name="name" required value="<?= e(post('name')) ?>"></label>
            <label>E-Mail-Adresse<input type="email" name="email" required value="<?= e(post('email')) ?>"></label>
            <label>Passwort (mind. 10 Zeichen, Buchstaben und Ziffern)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
            <label>Passwort wiederholen<input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
            <button class="btn primary">Installation starten</button>
        </form>
    </div>
    <?php
});
