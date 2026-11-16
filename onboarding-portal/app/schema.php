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

    $indexes = [
        'idx_users_company'   => 'users (company_id)',
        'idx_dep_company'     => 'departments (company_id)',
        'idx_pos_company'     => 'positions (company_id)',
        'idx_cat_company'     => 'catalog_items (company_id, type)',
        'idx_tpl_company'     => 'template_items (company_id)',
        'idx_req_company'     => 'requests (company_id, status)',
        'idx_reqitems_req'    => 'request_items (request_id)',
        'idx_reqlog_req'      => 'request_log (request_id)',
        'idx_audit_created'   => 'audit_log (created_at)',
    ];
    foreach ($indexes as $name => $def) {
        $tables[] = $mysql
            ? "CREATE INDEX $name ON $def"
            : "CREATE INDEX IF NOT EXISTS $name ON $def";
    }
    return $tables;
}
