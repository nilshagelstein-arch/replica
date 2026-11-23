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

    // App „Tickets“: Helpdesk je Firma (Queues, Tickets, Verlauf, Anhänge)
    $tables[] = "CREATE TABLE IF NOT EXISTS ticket_queues (
        id $pk,
        company_id $fk NOT NULL,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(500) NOT NULL DEFAULT '',
        active INT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS ticket_queue_members (
        id $pk,
        queue_id $fk NOT NULL,
        user_id $fk NOT NULL
    )$tail";

    // Gruppen (= Abteilungen) als Bearbeiter einer Queue
    $tables[] = "CREATE TABLE IF NOT EXISTS ticket_queue_groups (
        id $pk,
        queue_id $fk NOT NULL,
        department_id $fk NOT NULL
    )$tail";

    // Mitarbeiter-Konten in Abteilungen (Gruppen); ein Konto kann in mehreren sein
    $tables[] = "CREATE TABLE IF NOT EXISTS user_departments (
        id $pk,
        user_id $fk NOT NULL,
        department_id $fk NOT NULL
    )$tail";

    // E-Mail-Eingang der Tickets: Postfächer (je Queue oder firmenweit), Zuordnungsregeln, Eingangsprotokoll/Prüfliste
    $tables[] = "CREATE TABLE IF NOT EXISTS ticket_mailboxes (
        id $pk,
        company_id $fk NOT NULL,
        queue_id $fk NULL,
        address VARCHAR(190) NOT NULL DEFAULT '',
        provider VARCHAR(20) NOT NULL DEFAULT 'imap',
        imap_host VARCHAR(190) NOT NULL DEFAULT '',
        imap_port INT NOT NULL DEFAULT 993,
        imap_security VARCHAR(10) NOT NULL DEFAULT 'ssl',
        imap_user VARCHAR(190) NOT NULL DEFAULT '',
        imap_folder VARCHAR(190) NOT NULL DEFAULT 'INBOX',
        secret_enc TEXT NULL,
        oauth_refresh_enc TEXT NULL,
        oauth_access_enc TEXT NULL,
        oauth_expires INT NOT NULL DEFAULT 0,
        active INT NOT NULL DEFAULT 1,
        last_check_at VARCHAR(19) NOT NULL DEFAULT '',
        last_ok_at VARCHAR(19) NOT NULL DEFAULT '',
        last_error VARCHAR(500) NOT NULL DEFAULT '',
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS ticket_mail_rules (
        id $pk,
        company_id $fk NOT NULL,
        field VARCHAR(10) NOT NULL DEFAULT 'subject',
        pattern VARCHAR(190) NOT NULL,
        queue_id $fk NOT NULL,
        sort INT NOT NULL DEFAULT 0
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS ticket_inbox (
        id $pk,
        company_id $fk NOT NULL,
        mailbox_id $fk NULL,
        message_id VARCHAR(250) NOT NULL DEFAULT '',
        from_email VARCHAR(190) NOT NULL DEFAULT '',
        from_name VARCHAR(190) NOT NULL DEFAULT '',
        to_email VARCHAR(500) NOT NULL DEFAULT '',
        subject VARCHAR(250) NOT NULL DEFAULT '',
        body TEXT,
        content_hash VARCHAR(64) NOT NULL DEFAULT '',
        attachment_count INT NOT NULL DEFAULT 0,
        stored_name VARCHAR(80) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'review',
        reason VARCHAR(190) NOT NULL DEFAULT '',
        ticket_id $fk NULL,
        handled_by $fk NULL,
        handled_at VARCHAR(19) NOT NULL DEFAULT '',
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS tickets (
        id $pk,
        company_id $fk NOT NULL,
        seq_year INT NOT NULL,
        seq_no INT NOT NULL,
        number VARCHAR(20) NOT NULL,
        queue_id $fk NULL,
        subject VARCHAR(190) NOT NULL,
        priority VARCHAR(20) NOT NULL DEFAULT 'einschraenkung',
        category VARCHAR(60) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'offen',
        created_by $fk NULL,
        entered_by $fk NULL,
        employee_id $fk NULL,
        assigned_to $fk NULL,
        follow_up_at VARCHAR(10) NOT NULL DEFAULT '',
        resolved_at VARCHAR(19) NOT NULL DEFAULT '',
        closed_at VARCHAR(19) NOT NULL DEFAULT '',
        viewing_by $fk NULL,
        viewing_at VARCHAR(19) NOT NULL DEFAULT '',
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL,
        last_activity_at $ts NOT NULL,
        UNIQUE (company_id, seq_year, seq_no)
    )$tail";

    // App „Geräte & Lizenzen“ (Inventar): Gerätetypen, Standorte/Lager, Geräte, Verlauf, Anhänge, Rechte je Gruppe
    $tables[] = "CREATE TABLE IF NOT EXISTS inv_types (
        id $pk,
        company_id $fk NOT NULL,
        name VARCHAR(120) NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        active INT NOT NULL DEFAULT 1
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS inv_locations (
        id $pk,
        company_id $fk NOT NULL,
        name VARCHAR(190) NOT NULL,
        address VARCHAR(255) NOT NULL DEFAULT '',
        is_store INT NOT NULL DEFAULT 1,
        sort INT NOT NULL DEFAULT 0,
        active INT NOT NULL DEFAULT 1
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS inv_devices (
        id $pk,
        company_id $fk NOT NULL,
        inv_no VARCHAR(60) NOT NULL,
        type_id $fk NULL,
        manufacturer VARCHAR(120) NOT NULL DEFAULT '',
        model VARCHAR(190) NOT NULL DEFAULT '',
        serial VARCHAR(120) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'lager',
        location_id $fk NULL,
        room VARCHAR(120) NOT NULL DEFAULT '',
        employee_id $fk NULL,
        purchase_date VARCHAR(10) NOT NULL DEFAULT '',
        price_cents INT NULL,
        supplier VARCHAR(190) NOT NULL DEFAULT '',
        warranty_until VARCHAR(10) NOT NULL DEFAULT '',
        os VARCHAR(120) NOT NULL DEFAULT '',
        ip VARCHAR(64) NOT NULL DEFAULT '',
        mac VARCHAR(64) NOT NULL DEFAULT '',
        bitlocker_enc TEXT,
        phone_no VARCHAR(60) NOT NULL DEFAULT '',
        sim_no VARCHAR(60) NOT NULL DEFAULT '',
        mobile_contract VARCHAR(190) NOT NULL DEFAULT '',
        notes TEXT,
        created_by $fk NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS inv_log (
        id $pk,
        company_id $fk NOT NULL,
        device_id $fk NOT NULL,
        user_id $fk NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'event',
        body TEXT,
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS inv_files (
        id $pk,
        company_id $fk NOT NULL,
        device_id $fk NOT NULL,
        filename VARCHAR(190) NOT NULL,
        mime VARCHAR(100) NOT NULL DEFAULT '',
        size INT NOT NULL DEFAULT 0,
        stored_name VARCHAR(120) NOT NULL,
        user_id $fk NULL,
        created_at $ts NOT NULL
    )$tail";

    // Lizenzen (Stufe 2): Abo, Kauflizenz, Wartung/Support; Plätze werden an Mitarbeiter oder Geräte vergeben
    $tables[] = "CREATE TABLE IF NOT EXISTS inv_licenses (
        id $pk,
        company_id $fk NOT NULL,
        product VARCHAR(190) NOT NULL,
        manufacturer VARCHAR(120) NOT NULL DEFAULT '',
        kind VARCHAR(20) NOT NULL DEFAULT 'abo',
        seats INT NOT NULL DEFAULT 1,
        license_key_enc TEXT,
        contract_no VARCHAR(120) NOT NULL DEFAULT '',
        supplier VARCHAR(190) NOT NULL DEFAULT '',
        start_date VARCHAR(10) NOT NULL DEFAULT '',
        end_date VARCHAR(10) NOT NULL DEFAULT '',
        notice_value INT NOT NULL DEFAULT 0,
        notice_unit VARCHAR(10) NOT NULL DEFAULT 'months',
        auto_renew INT NOT NULL DEFAULT 0,
        renew_months INT NOT NULL DEFAULT 12,
        cost_cents INT NULL,
        cost_interval VARCHAR(10) NOT NULL DEFAULT 'monat',
        device_id $fk NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'aktiv',
        cancelled_at VARCHAR(10) NOT NULL DEFAULT '',
        notes TEXT,
        created_by $fk NULL,
        created_at $ts NOT NULL,
        updated_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS inv_license_assign (
        id $pk,
        company_id $fk NOT NULL,
        license_id $fk NOT NULL,
        employee_id $fk NULL,
        device_id $fk NULL,
        assigned_at $ts NOT NULL,
        user_id $fk NULL
    )$tail";

    // Lebenszyklus (Stufe 3): Übergabe- und Rückgabeprotokolle mit Unterschrift
    $tables[] = "CREATE TABLE IF NOT EXISTS inv_handovers (
        id $pk,
        company_id $fk NOT NULL,
        kind VARCHAR(20) NOT NULL DEFAULT 'ausgabe',
        employee_id $fk NULL,
        employee_name VARCHAR(190) NOT NULL DEFAULT '',
        handover_date VARCHAR(10) NOT NULL DEFAULT '',
        accessories TEXT,
        note TEXT,
        terms TEXT,
        status VARCHAR(20) NOT NULL DEFAULT 'offen',
        sign_name VARCHAR(190) NOT NULL DEFAULT '',
        signature_png $long,
        signed_at VARCHAR(19) NOT NULL DEFAULT '',
        signed_by $fk NULL,
        created_by $fk NULL,
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS inv_handover_items (
        id $pk,
        handover_id $fk NOT NULL,
        device_id $fk NULL,
        inv_no VARCHAR(60) NOT NULL DEFAULT '',
        description VARCHAR(255) NOT NULL DEFAULT '',
        serial VARCHAR(120) NOT NULL DEFAULT '',
        item_condition VARCHAR(30) NOT NULL DEFAULT ''
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS inv_group_perms (
        id $pk,
        company_id $fk NOT NULL,
        department_id $fk NOT NULL,
        perm INT NOT NULL DEFAULT 0
    )$tail";

    // Dienstleister je Firma (Stufe 5): externe Partner mit Portal-Zugang (Rolle „dienstleister“) oder nur per Mail
    $tables[] = "CREATE TABLE IF NOT EXISTS ticket_providers (
        id $pk,
        company_id $fk NOT NULL,
        name VARCHAR(190) NOT NULL,
        email VARCHAR(190) NOT NULL DEFAULT '',
        user_id $fk NULL,
        see_all INT NOT NULL DEFAULT 0,
        notes VARCHAR(500) NOT NULL DEFAULT '',
        active INT NOT NULL DEFAULT 1,
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS ticket_messages (
        id $pk,
        ticket_id $fk NOT NULL,
        company_id $fk NOT NULL,
        user_id $fk NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'message',
        body TEXT,
        status_from VARCHAR(20) NOT NULL DEFAULT '',
        status_to VARCHAR(20) NOT NULL DEFAULT '',
        created_at $ts NOT NULL
    )$tail";

    $tables[] = "CREATE TABLE IF NOT EXISTS ticket_attachments (
        id $pk,
        ticket_id $fk NOT NULL,
        message_id $fk NOT NULL,
        company_id $fk NOT NULL,
        filename VARCHAR(190) NOT NULL,
        mime VARCHAR(100) NOT NULL DEFAULT '',
        size INT NOT NULL DEFAULT 0,
        stored_name VARCHAR(80) NOT NULL,
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
        'idx_tkq_co'          => 'ticket_queues (company_id, sort)',
        'idx_tkqm_q'          => 'ticket_queue_members (queue_id, user_id)',
        'idx_tkqg_q'          => 'ticket_queue_groups (queue_id, department_id)',
        'idx_tkmb_co'         => 'ticket_mailboxes (company_id)',
        'idx_tkrule_co'       => 'ticket_mail_rules (company_id, sort)',
        'idx_tkin_co'         => 'ticket_inbox (company_id, status, created_at)',
        'idx_tkin_msg'        => 'ticket_inbox (company_id, message_id)',
        'idx_tkin_hash'       => 'ticket_inbox (company_id, from_email, content_hash)',
        'idx_udep_u'          => 'user_departments (user_id, department_id)',
        'idx_udep_d'          => 'user_departments (department_id)',
        'idx_tk_queue'        => 'tickets (company_id, queue_id, status)',
        'idx_inv_dev'         => 'inv_devices (company_id, status)',
        'idx_inv_dev_emp'     => 'inv_devices (company_id, employee_id)',
        'idx_inv_log'         => 'inv_log (device_id, id)',
        'idx_inv_lic'         => 'inv_licenses (company_id, status)',
        'idx_inv_lic_as'      => 'inv_license_assign (license_id)',
        'idx_tk_creator'      => 'tickets (company_id, created_by)',
        'idx_tk_assigned'     => 'tickets (company_id, assigned_to)',
        'idx_tkmsg_t'         => 'ticket_messages (ticket_id, id)',
        'idx_tkatt_t'         => 'ticket_attachments (ticket_id)',
    ];
    foreach ($indexes as $name => $def) {
        $tables[] = $mysql
            ? "CREATE INDEX $name ON $def"
            : "CREATE INDEX IF NOT EXISTS $name ON $def";
    }
    return $tables;
}

const SCHEMA_VERSION = 25;

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
    'tickets' => ['name' => 'Tickets', 'description' => 'Interner Helpdesk: Anliegen in Queues melden, bearbeiten und verfolgen – mit Verlauf, Anhängen und Benachrichtigungen.',
        'target' => 'tickets', 'icon' => '🎫', 'color' => '#b4235a', 'sort' => 15],
    'inventar' => ['name' => 'Geräte & Lizenzen', 'description' => 'Inventar der Firma: Geräte mit Standort, Zuordnung und Verlauf, Lager mit Bestand – Lizenzen folgen.',
        'target' => 'inventory', 'icon' => '💻', 'color' => '#0e7490', 'sort' => 17],
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
        // Rechte der Verwaltungsadmins (ab Version 13); leer = uneingeschränkt
        ['users', 'admin_perms', 'TEXT NULL'],
        ['users', 'admin_companies', "VARCHAR(2000) NOT NULL DEFAULT ''"],
        // Linksammlung: Bild einpassen oder ausfüllen (ab Version 12)
        ['links', 'image_fit', "VARCHAR(10) NOT NULL DEFAULT 'contain'"],
        // Änderungsantrag für abgeschlossene Aufträge (ab Version 14); gesetzt = Antrag offen
        ['requests', 'change_requested_at', 'VARCHAR(19) NULL'],
        ['requests', 'change_requested_by', 'INT NULL'],
        ['requests', 'change_reason', 'TEXT NULL'],
        // Mehrsprachigkeit (ab Version 15): Sprache der Firma, Benutzer leer = Sprache der Firma
        ['companies', 'lang', "VARCHAR(5) NOT NULL DEFAULT 'de'"],
        ['users', 'lang', "VARCHAR(5) NOT NULL DEFAULT ''"],
        // App „Tickets“ (ab Version 16): Einstellungen je Firma
        ['companies', 'ticket_autoclose_days', 'INT NOT NULL DEFAULT 7'],
        ['ticket_queues', 'lead_user_id', 'INT NULL'],
        ['tickets', 'visibility', "VARCHAR(10) NOT NULL DEFAULT 'me'"],
        ['tickets', 'group_id', 'INT NULL'],
        ['tickets', 'waiting_since', "VARCHAR(19) NOT NULL DEFAULT ''"],
        ['tickets', 'reminder_level', 'INT NOT NULL DEFAULT 0'],
        ['tickets', 'due_at', "VARCHAR(10) NOT NULL DEFAULT ''"],
        ['tickets', 'due_notified', 'INT NOT NULL DEFAULT 0'],
        ['companies', 'ticket_times', 'TEXT NULL'],
        ['companies', 'ticket_default_queue_id', 'INT NULL'],
        ['companies', 'ticket_blocklist', 'TEXT NULL'],
        ['companies', 'ticket_provider_access', 'INT NOT NULL DEFAULT 0'],
        ['companies', 'ticket_categories', 'TEXT NULL'],
        // Dienstleister (ab Version 20): Übergabe je Ticket; Nachrichten von Absendern ohne Konto
        ['tickets', 'provider_id', 'INT NULL'],
        // Auswertung (ab Version 21): Mitarbeiter-Statistik je Firma, zunächst aus
        ['companies', 'ticket_staff_stats', 'INT NOT NULL DEFAULT 0'],
        // Inventar (ab Version 22): Einsicht für den Dienstleister, Art der Inventarnummer
        ['companies', 'inv_provider_access', 'INT NOT NULL DEFAULT 0'],
        ['companies', 'inv_number_mode', "VARCHAR(10) NOT NULL DEFAULT 'auto'"],
        // Lizenzen (ab Version 23): Verlauf und Anhänge auch für Lizenzen (device_id = 0)
        ['inv_log', 'license_id', 'INT NULL'],
        ['inv_files', 'license_id', 'INT NULL'],
        // Lebenszyklus (ab Version 24): Reparatur, Ausmusterung, Entsorgung mit Nachweis; Hinweistext der Übergabe; Scans der Protokolle
        ['inv_devices', 'repair_reason', "VARCHAR(255) NOT NULL DEFAULT ''"],
        ['inv_devices', 'repair_vendor', "VARCHAR(190) NOT NULL DEFAULT ''"],
        ['inv_devices', 'repair_until', "VARCHAR(10) NOT NULL DEFAULT ''"],
        ['inv_devices', 'retire_reason', "VARCHAR(255) NOT NULL DEFAULT ''"],
        ['inv_devices', 'retired_at', "VARCHAR(10) NOT NULL DEFAULT ''"],
        ['inv_devices', 'disposal_date', "VARCHAR(10) NOT NULL DEFAULT ''"],
        ['inv_devices', 'disposal_vendor', "VARCHAR(190) NOT NULL DEFAULT ''"],
        ['inv_devices', 'wipe_method', "VARCHAR(190) NOT NULL DEFAULT ''"],
        ['inv_devices', 'wipe_by', "VARCHAR(190) NOT NULL DEFAULT ''"],
        ['inv_devices', 'wipe_confirmed', 'INT NOT NULL DEFAULT 0'],
        ['inv_files', 'handover_id', 'INT NULL'],
        ['companies', 'inv_handover_text', 'TEXT NULL'],
        // Verknüpfungen (ab Version 25): betroffenes Gerät im Ticket
        ['tickets', 'device_id', 'INT NULL'],
        ['tickets', 'provider_internal', 'INT NOT NULL DEFAULT 0'],
        ['tickets', 'provider_since', "VARCHAR(19) NOT NULL DEFAULT ''"],
        ['ticket_messages', 'author_name', "VARCHAR(190) NOT NULL DEFAULT ''"],
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
        db()->query('SELECT u.email_verified, u.totp_reset_requested_at, u.admin_perms, u.admin_companies FROM users u LIMIT 1');
        db()->query('SELECT registration_status, requested_apps, registered_at FROM companies LIMIT 1');
        db()->query('SELECT id FROM user_tokens LIMIT 1');
        db()->query('SELECT id FROM employees LIMIT 1');
        db()->query('SELECT image_fit FROM links LIMIT 1');
        db()->query('SELECT change_requested_at, change_requested_by, change_reason FROM requests LIMIT 1');
        db()->query('SELECT id FROM tickets LIMIT 1');
        db()->query('SELECT ticket_autoclose_days, ticket_provider_access FROM companies LIMIT 1');
        db()->query('SELECT t.visibility, t.group_id, q.lead_user_id FROM tickets t, ticket_queues q LIMIT 1');
        db()->query('SELECT id FROM user_departments LIMIT 1');
        db()->query('SELECT id FROM ticket_queue_groups LIMIT 1');
        db()->query('SELECT waiting_since, reminder_level, due_at, due_notified FROM tickets LIMIT 1');
        db()->query('SELECT ticket_times, ticket_default_queue_id, ticket_blocklist FROM companies LIMIT 1');
        db()->query('SELECT id FROM ticket_mailboxes LIMIT 1');
        db()->query('SELECT id FROM ticket_providers LIMIT 1');
        db()->query('SELECT provider_id, provider_internal, provider_since FROM tickets LIMIT 1');
        db()->query('SELECT author_name FROM ticket_messages LIMIT 1');
        db()->query('SELECT ticket_staff_stats FROM companies LIMIT 1');
        db()->query('SELECT inv_provider_access, inv_number_mode FROM companies LIMIT 1');
        db()->query('SELECT id FROM inv_devices LIMIT 1');
        db()->query('SELECT id FROM inv_licenses LIMIT 1');
        db()->query('SELECT license_id FROM inv_log LIMIT 1');
        db()->query('SELECT license_id FROM inv_files LIMIT 1');
        db()->query('SELECT id FROM inv_handovers LIMIT 1');
        db()->query('SELECT wipe_confirmed, repair_until FROM inv_devices LIMIT 1');
        db()->query('SELECT handover_id FROM inv_files LIMIT 1');
        db()->query('SELECT device_id FROM tickets LIMIT 1');
        db()->query('SELECT id FROM ticket_mail_rules LIMIT 1');
        db()->query('SELECT id FROM ticket_inbox LIMIT 1');
    } catch (PDOException $ex) {
        run_migrations();
    }
}
