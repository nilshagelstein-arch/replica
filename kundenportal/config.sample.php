<?php
/**
 * Konfiguration des Kundenportals.
 * Diese Datei nach "config.php" kopieren und anpassen.
 */
return [
    // Name, der im Portal angezeigt wird
    'app_name' => 'Kundenportal',

    // Öffentliche Adresse des Portals (ohne abschließenden Schrägstrich),
    // wird für Links in E-Mails verwendet
    'base_url' => 'https://www.example.de/onboarding',

    // Datenbank – auf normalem Webhosting: MySQL/MariaDB
    'db' => [
        'driver'   => 'mysql',          // 'mysql' oder 'sqlite'
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'onboarding',
        'user'     => 'onboarding_user',
        'password' => 'GEHEIM',
        // nur für driver = sqlite (z. B. zum lokalen Testen):
        'sqlite_path' => __DIR__ . '/app/data/portal.sqlite',
    ],

    // E-Mail-Benachrichtigungen (über PHP mail() des Webhosters)
    'mail' => [
        'enabled'    => true,
        'from'       => 'onboarding@example.de',
        'from_name'  => 'Kundenportal',
        // Empfänger für neue Aufträge und Rückmeldungen der Firmen
        'admin_recipients' => ['it-service@example.de'],
    ],

    // Name des Ausstellers in der Authenticator-App
    'totp_issuer' => 'Kundenportal',

    // Sicherheit
    'session_lifetime_minutes' => 60,   // Abmeldung nach Inaktivität
    'max_login_attempts'       => 5,    // danach Sperre
    'lockout_minutes'          => 15,

    'timezone' => 'Europe/Berlin',
];
