<?php
/**
 * Datenbankschema – funktioniert mit MySQL/MariaDB und SQLite.
 */
function schema_statements(string $driver): array
{
    $mysql = $driver === 'mysql';
    $pk    = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk    = $mysql ? 'INT UNSIGNED' : 'INTEGER';
    $tail  = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $ts    = 'VARCHAR(19)'; // 'Y-m-d H:i:s', von PHP gesetzt
    $long  = $mysql ? 'MEDIUMTEXT' : 'TEXT'; // Formularinhalte und Logos (bis 16 MB)

    $tables = [];

    $tables[] = "CREATE TABLE IF NOT EXISTS companies (
        id $pk,
        name VARCHAR(190) NOT NULL,
        short_name VARCHAR(50) NOT NULL DEFAULT '',
        street VARCHAR(190) NOT NULL DEFAULT '',
        zip VARCHAR(20) NOT NULL DEFAULT '',
        city VARCHAR(120) NOT NULL DEFAULT '',
        phone VARCHAR(60) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        contact_name VARCHAR(190) NOT NULL DEFAULT '',
        contact_email VARCHAR(190) NOT NULL DEFAULT '',
        contact_phone VARCHAR(60) NOT NULL DEFAULT '',
        email_domain VARCHAR(190) NOT NULL DEFAULT '',
        it_notes TEXT,
        active INT NOT NULL DEFAULT 1,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS users (
        id $pk,
        company_id $fk NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        name VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL,
        totp_secret VARCHAR(64) NULL,
        totp_enabled INT NOT NULL DEFAULT 0,
        totp_last_step INT NOT NULL DEFAULT 0,
        active INT NOT NULL DEFAULT 1,
        must_change_password INT NOT NULL DEFAULT 0,
        failed_attempts INT NOT NULL DEFAULT 0,
        locked_until $ts NULL,
        last_login $ts NULL,
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS departments (
        id $pk,
        company_id $fk NOT NULL,
        name VARCHAR(190) NOT NULL,
        active INT NOT NULL DEFAULT 1
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS positions (
        id $pk,
        company_id $fk NOT NULL,
        department_id $fk NULL,
        name VARCHAR(190) NOT NULL,
        active INT NOT NULL DEFAULT 1
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS catalog_items (
        id $pk,
        company_id $fk NULL,
        type VARCHAR(20) NOT NULL,
        name VARCHAR(190) NOT NULL,
        description VARCHAR(500) NOT NULL DEFAULT '',
        active INT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS template_items (
        id $pk,
        company_id $fk NOT NULL,
        department_id $fk NULL,
        position_id $fk NULL,
        catalog_item_id $fk NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS requests (
        id $pk,
        company_id $fk NOT NULL,
        created_by $fk NOT NULL,
        status VARCHAR(20) NOT NULL,
        salutation VARCHAR(20) NOT NULL DEFAULT '',
        first_name VARCHAR(120) NOT NULL,
        last_name VARCHAR(120) NOT NULL,
        employee_no VARCHAR(60) NOT NULL,
        department_id $fk NULL,
        position_id $fk NULL,
        department_name VARCHAR(190) NOT NULL DEFAULT '',
        position_name VARCHAR(190) NOT NULL DEFAULT '',
        entry_date VARCHAR(10) NOT NULL,
        location VARCHAR(190) NOT NULL DEFAULT '',
        manager_name VARCHAR(190) NOT NULL DEFAULT '',
        email_wish VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(60) NOT NULL DEFAULT '',
        notes TEXT,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        completed_at $ts NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS request_items (
        id $pk,
        request_id $fk NOT NULL,
        catalog_item_id $fk NULL,
        type VARCHAR(20) NOT NULL,
        name VARCHAR(190) NOT NULL,
        note VARCHAR(500) NOT NULL DEFAULT '',
        done INT NOT NULL DEFAULT 0,
        done_by $fk NULL,
        done_at $ts NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS request_log (
        id $pk,
        request_id $fk NOT NULL,
        user_id $fk NULL,
        kind VARCHAR(20) NOT NULL,
        status_from VARCHAR(20) NULL,
        status_to VARCHAR(20) NULL,
        message TEXT,
        internal INT NOT NULL DEFAULT 0,
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS audit_log (
        id $pk,
        user_id $fk NULL,
        company_id $fk NULL,
        action VARCHAR(60) NOT NULL,
        details VARCHAR(500) NOT NULL DEFAULT '',
        ip VARCHAR(45) NOT NULL DEFAULT '',
        created_at $ts NOT NULL
    )$tail";

    // App-Verzeichnis: interne Apps (Bereich dieses Portals) oder externe Links
    $tables[] = "CREATE TABLE IF NOT EXISTS apps (
        id $pk,
        slug VARCHAR(60) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(500) NOT NULL DEFAULT '',
        kind VARCHAR(10) NOT NULL DEFAULT 'extern',
        target VARCHAR(500) NOT NULL DEFAULT '',
        icon VARCHAR(16) NOT NULL DEFAULT '',
        color VARCHAR(7) NOT NULL DEFAULT '#1f5fa8',
        active INT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS company_apps (
        id $pk,
        company_id $fk NOT NULL,
        app_id $fk NOT NULL,
        granted_by $fk NULL,
        granted_at $ts NOT NULL
    )$tail";

    // App „Linksammlung“: Reiter, Überschriften (Bereiche) und Links je Firma
    $tables[] = "CREATE TABLE IF NOT EXISTS link_tabs (
        id $pk,
        company_id $fk NOT NULL,
        name VARCHAR(120) NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS link_sections (
        id $pk,
        company_id $fk NOT NULL,
        tab_id $fk NOT NULL,
        name VARCHAR(190) NOT NULL,
        description VARCHAR(500) NOT NULL DEFAULT '',
        layout VARCHAR(10) NOT NULL DEFAULT 'tiles',
        sort INT NOT NULL DEFAULT 0,
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS links (
        id $pk,
        company_id $fk NOT NULL,
        section_id $fk NOT NULL,
        title VARCHAR(190) NOT NULL,
        url VARCHAR(1000) NOT NULL,
        description VARCHAR(1000) NOT NULL DEFAULT '',
        icon VARCHAR(16) NOT NULL DEFAULT '',
        color INT NOT NULL DEFAULT 1,
        image_mime VARCHAR(40) NOT NULL DEFAULT '',
        image_data $long,
        image_fit VARCHAR(10) NOT NULL DEFAULT 'contain',
        new_window INT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        created_by $fk NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$tail";

    // E-Mail-Versand: zentrales Konto (company_id NULL) oder Konto je Firma
    $tables[] = "CREATE TABLE IF NOT EXISTS mail_accounts (
        id $pk,
        company_id $fk NULL,
        provider VARCHAR(20) NOT NULL DEFAULT 'smtp',
        from_email VARCHAR(190) NOT NULL DEFAULT '',
        from_name VARCHAR(190) NOT NULL DEFAULT '',
        reply_to VARCHAR(190) NOT NULL DEFAULT '',
        smtp_host VARCHAR(190) NOT NULL DEFAULT '',
        smtp_port INT NOT NULL DEFAULT 587,
        smtp_security VARCHAR(10) NOT NULL DEFAULT 'tls',
        smtp_user VARCHAR(190) NOT NULL DEFAULT '',
        secret_enc TEXT NULL,
        oauth_refresh_enc TEXT NULL,
        oauth_access_enc TEXT NULL,
        oauth_expires INT NOT NULL DEFAULT 0,
        active INT NOT NULL DEFAULT 1,
        last_ok_at $ts NULL,
        last_error VARCHAR(500) NOT NULL DEFAULT '',
        last_error_at $ts NULL,
        updated_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS settings (
        id $pk,
        name VARCHAR(100) NOT NULL UNIQUE,
        value TEXT NULL
    )$tail";

    // --- App „Formulare“ -------------------------------------------------------
    $tables[] = "CREATE TABLE IF NOT EXISTS form_folders (
        id $pk,
        company_id $fk NOT NULL,
        parent_id $fk NULL,
        name VARCHAR(190) NOT NULL,
        created_at $ts NOT NULL
    )$tail";

    // header_id/footer_id: 0 = Standard der Firma, -1 = keine, > 0 = bestimmte Vorlage
    $tables[] = "CREATE TABLE IF NOT EXISTS forms (
        id $pk,
        company_id $fk NOT NULL,
        folder_id $fk NULL,
        name VARCHAR(190) NOT NULL,
        description VARCHAR(500) NOT NULL DEFAULT '',
        body $long,
        header_id INT NOT NULL DEFAULT 0,
        footer_id INT NOT NULL DEFAULT 0,
        created_by $fk NULL,
        updated_by $fk NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS form_layouts (
        id $pk,
        company_id $fk NOT NULL,
        kind VARCHAR(10) NOT NULL,
        name VARCHAR(190) NOT NULL,
        body $long,
        is_default INT NOT NULL DEFAULT 0,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS form_branding (
        id $pk,
        company_id $fk NOT NULL UNIQUE,
        logo_mime VARCHAR(40) NOT NULL DEFAULT '',
        logo_data $long,
        website VARCHAR(190) NOT NULL DEFAULT '',
        management VARCHAR(190) NOT NULL DEFAULT '',
        register_info VARCHAR(190) NOT NULL DEFAULT '',
        vat_id VARCHAR(60) NOT NULL DEFAULT '',
        tax_no VARCHAR(60) NOT NULL DEFAULT '',
        bank VARCHAR(190) NOT NULL DEFAULT '',
        iban VARCHAR(60) NOT NULL DEFAULT '',
        bic VARCHAR(20) NOT NULL DEFAULT '',
        updated_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS customer_fields (
        id $pk,
        company_id $fk NOT NULL,
        field_key VARCHAR(60) NOT NULL,
        label VARCHAR(120) NOT NULL,
        sort INT NOT NULL DEFAULT 0
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS customers (
        id $pk,
        company_id $fk NOT NULL,
        customer_no VARCHAR(60) NOT NULL DEFAULT '',
        company_name VARCHAR(190) NOT NULL DEFAULT '',
        salutation VARCHAR(20) NOT NULL DEFAULT '',
        title VARCHAR(40) NOT NULL DEFAULT '',
        first_name VARCHAR(120) NOT NULL DEFAULT '',
        last_name VARCHAR(120) NOT NULL DEFAULT '',
        street VARCHAR(190) NOT NULL DEFAULT '',
        zip VARCHAR(20) NOT NULL DEFAULT '',
        city VARCHAR(120) NOT NULL DEFAULT '',
        country VARCHAR(80) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(60) NOT NULL DEFAULT '',
        notes TEXT,
        extra TEXT,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS api_tokens (
        id $pk,
        company_id $fk NOT NULL,
        name VARCHAR(120) NOT NULL,
        prefix VARCHAR(20) NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        can_write INT NOT NULL DEFAULT 0,
        active INT NOT NULL DEFAULT 1,
        created_by $fk NULL,
        created_at $ts NOT NULL,
        last_used_at $ts NULL
    )$tail";

    // Mitarbeiterliste je Firma (App On- & Offboarding)
    $tables[] = "CREATE TABLE IF NOT EXISTS employees (
        id $pk,
        company_id $fk NOT NULL,
        employee_no VARCHAR(60) NOT NULL,
        salutation VARCHAR(20) NOT NULL DEFAULT '',
        first_name VARCHAR(120) NOT NULL,
        last_name VARCHAR(120) NOT NULL,
        department_id $fk NULL,
        position_id $fk NULL,
        department_name VARCHAR(190) NOT NULL DEFAULT '',
        position_name VARCHAR(190) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(60) NOT NULL DEFAULT '',
        location VARCHAR(190) NOT NULL DEFAULT '',
        manager_name VARCHAR(190) NOT NULL DEFAULT '',
        entry_date VARCHAR(10) NOT NULL DEFAULT '',
        exit_date VARCHAR(10) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'aktiv',
        notes TEXT,
        last_request_id $fk NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$tail";

    // Einmal-Links: E-Mail-Bestätigung (verify) und Passwort zurücksetzen (reset); gespeichert wird nur der Hash
    $tables[] = "CREATE TABLE IF NOT EXISTS user_tokens (
        id $pk,
        user_id $fk NOT NULL,
        purpose VARCHAR(20) NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at $ts NOT NULL,
        used_at $ts NULL,
        created_at $ts NOT NULL
    )$tail";

    $indexes = [
        'idx_employees_co'    => 'employees (company_id, employee_no)',
        'idx_usertokens_hash' => 'user_tokens (token_hash)',
        'idx_usertokens_user' => 'user_tokens (user_id, purpose)',
        'idx_audit_ip'        => 'audit_log (action, ip, created_at)',
        'idx_formfolders_co'  => 'form_folders (company_id, parent_id)',
        'idx_forms_co'        => 'forms (company_id, folder_id)',
        'idx_formlayouts_co'  => 'form_layouts (company_id, kind)',
        'idx_custfields_co'   => 'customer_fields (company_id)',
        'idx_customers_co'    => 'customers (company_id, last_name)',
        'idx_customers_no'    => 'customers (company_id, customer_no)',
        'idx_apitokens_pref'  => 'api_tokens (prefix)',
        'idx_mailacc_company' => 'mail_accounts (company_id)',
        'idx_compapps'        => 'company_apps (company_id, app_id)',
        'idx_users_company'   => 'users (company_id)',
        'idx_dep_company'     => 'departments (company_id)',
        'idx_pos_company'     => 'positions (company_id)',
        'idx_cat_company'     => 'catalog_items (company_id, type)',
        'idx_tpl_company'     => 'template_items (company_id)',
        'idx_req_company'     => 'requests (company_id, status)',
        'idx_reqitems_req'    => 'request_items (request_id)',
        'idx_reqlog_req'      => 'request_log (request_id)',
        'idx_audit_created'   => 'audit_log (created_at)',
        'idx_linktabs_co'     => 'link_tabs (company_id, sort)',
        'idx_linksec_tab'     => 'link_sections (company_id, tab_id, sort)',
        'idx_links_sec'       => 'links (company_id, section_id, sort)',
    ];
    foreach ($indexes as $name => $def) {
        $tables[] = $mysql
            ? "CREATE INDEX $name ON $def"
            : "CREATE INDEX IF NOT EXISTS $name ON $def";
    }
    return $tables;
}

const SCHEMA_VERSION = 12;

/** Im Portal eingebaute Apps (kind = intern, target = Startseite der App) */
const BUILTIN_APPS = [
    'onboarding' => ['name' => 'On- & Offboarding', 'description' => 'Neue Mitarbeitende einrichten lassen, Austritte abwickeln und Zugänge bei Elternzeit & Co. vorübergehend stilllegen.',
        'target' => 'dashboard', 'icon' => '👤', 'color' => '#1f5fa8', 'sort' => 10],
    'formulare' => ['name' => 'Formulare', 'description' => 'Briefe und Formulare mit eigenem Editor gestalten und mit Daten aus der Kundendatenbank ausfüllen. Mit Logo, Briefkopf und Fußzeile.',
        'target' => 'forms', 'icon' => '📝', 'color' => '#0f7b6c', 'sort' => 20],
    'datensicherung' => ['name' => 'Datensicherung', 'description' => 'Tägliche Sicherung aller Daten Ihrer Firma – auf Wunsch automatisch in Ihr eigenes Google Drive.',
        'target' => 'company_backups', 'icon' => '💾', 'color' => '#6b4fbb', 'sort' => 30],
    'links' => ['name' => 'Linksammlung', 'description' => 'Alle wichtigen Links der Firma an einem Ort – mit Bild, Beschreibung, Überschriften und Reitern, wie eine Intranet-Startseite.',
        'target' => 'links', 'icon' => '🔗', 'color' => '#c2410c', 'sort' => 40],
];

/**
 * Legt fehlende Tabellen an und bringt die Datenbank auf den aktuellen Stand.
 * Läuft bei der Installation und automatisch nach einem Update der Dateien.
 */
function run_migrations(): void
{
    $driver = cfg('db.driver', 'mysql');
    foreach (schema_statements($driver) as $sql) {
        try {
            db()->exec($sql);
        } catch (PDOException $ex) {
            if (stripos($sql, 'CREATE INDEX') !== 0) throw $ex; // vorhandene Indizes ignorieren
        }
    }
    // Spalten, die in älteren Versionen fehlen
    $columns = [
        ['users', 'must_change_password', 'INT NOT NULL DEFAULT 0'],
        // Offboarding (ab Version 3)
        ['requests', 'type', "VARCHAR(20) NOT NULL DEFAULT 'onboarding'"],
        ['requests', 'source_request_id', 'INT NULL'],
        ['requests', 'lock_option', "VARCHAR(20) NOT NULL DEFAULT ''"],
        ['requests', 'lock_at', "VARCHAR(16) NOT NULL DEFAULT ''"],
        ['requests', 'mailbox_action', "VARCHAR(20) NOT NULL DEFAULT ''"],
        ['requests', 'successor_name', "VARCHAR(190) NOT NULL DEFAULT ''"],
        ['requests', 'successor_email', "VARCHAR(190) NOT NULL DEFAULT ''"],
        ['requests', 'forward_until', "VARCHAR(10) NOT NULL DEFAULT ''"],
        ['requests', 'auto_reply', 'TEXT NULL'],
        ['requests', 'data_action', "VARCHAR(20) NOT NULL DEFAULT ''"],
        ['requests', 'phone_action', "VARCHAR(190) NOT NULL DEFAULT ''"],
        ['requests', 'return_date', "VARCHAR(10) NOT NULL DEFAULT ''"],
        ['requests', 'absence_reason', "VARCHAR(60) NOT NULL DEFAULT ''"],
        // Formulare: Seitenränder und Grundschrift (ab Version 7)
        ['forms', 'page_settings', 'TEXT NULL'],
        ['form_layouts', 'page_settings', 'TEXT NULL'],
        // Selbstregistrierung, E-Mail-Bestätigung, 2FA-Antrag (ab Version 8); bestehende Konten gelten als bestätigt
        ['users', 'email_verified', 'INT NOT NULL DEFAULT 1'],
        ['users', 'totp_reset_requested_at', 'VARCHAR(19) NULL'],
        ['companies', 'registration_status', "VARCHAR(20) NOT NULL DEFAULT ''"],
        ['companies', 'requested_apps', "VARCHAR(500) NOT NULL DEFAULT ''"],
        ['companies', 'registered_at', 'VARCHAR(19) NULL'],
        // Linksammlung: Bild einpassen oder ausfüllen (ab Version 12)
        ['links', 'image_fit', "VARCHAR(10) NOT NULL DEFAULT 'contain'"],
    ];
    foreach ($columns as [$table, $col, $ddl]) {
        try {
            db()->query("SELECT $col FROM $table LIMIT 1");
        } catch (PDOException $ex) {
            db()->exec("ALTER TABLE $table ADD COLUMN $col $ddl");
        }
    }
    // Anzeigename der eingebauten App aktualisieren, solange er nicht angepasst wurde
    q("UPDATE apps SET name = ?, description = ? WHERE slug = 'onboarding' AND name = 'Onboarding'",
        [BUILTIN_APPS['onboarding']['name'], BUILTIN_APPS['onboarding']['description']]);
    foreach (BUILTIN_APPS as $slug => $a) {
        if (!row('SELECT id FROM apps WHERE slug = ?', [$slug])) {
            $appId = insert('apps', ['slug' => $slug, 'name' => $a['name'], 'description' => $a['description'], 'kind' => 'intern',
                'target' => $a['target'], 'icon' => $a['icon'], 'color' => $a['color'], 'active' => 1, 'sort' => $a['sort'],
                'created_at' => now()]);
            // bestehende Firmen behalten das Onboarding (vor Einführung der App-Freigaben)
            if ($slug === 'onboarding') {
                foreach (rows('SELECT id FROM companies') as $c) {
                    insert('company_apps', ['company_id' => $c['id'], 'app_id' => $appId, 'granted_by' => null, 'granted_at' => now()]);
                }
            }
        }
    }
    // genau ein Superadmin: gibt es noch keinen, wird der zuerst angelegte aktive Administrator dazu
    if (!row("SELECT id FROM users WHERE role = 'superadmin'")) {
        $first = row("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1");
        if ($first) q("UPDATE users SET role = 'superadmin' WHERE id = ?", [$first['id']]);
    }
    $file = APP_DIR . '/data/schema.version';
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0770, true);
    $old = is_file($file) ? (int)file_get_contents($file) : 0;
    file_put_contents($file, (string)max($old, SCHEMA_VERSION));
}

function ensure_schema(): void
{
    $file = APP_DIR . '/data/schema.version';
    $v = is_file($file) ? (int)file_get_contents($file) : 1;
    if ($v < SCHEMA_VERSION) {
        run_migrations();
        return;
    }
    // Sicherheitsnetz: fehlen Tabellen/Spalten der aktuellen Version (z. B. weil schema.version
    // schon einen höheren Stand hatte), Migration trotzdem ausführen
    try {
        db()->query('SELECT u.email_verified, u.totp_reset_requested_at FROM users u LIMIT 1');
        db()->query('SELECT registration_status, requested_apps, registered_at FROM companies LIMIT 1');
        db()->query('SELECT id FROM user_tokens LIMIT 1');
        db()->query('SELECT id FROM employees LIMIT 1');
        db()->query('SELECT image_fit FROM links LIMIT 1');
    } catch (PDOException $ex) {
        run_migrations();
    }
}
