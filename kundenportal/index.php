<?php
/**
 * Einstiegspunkt (Front-Controller) des Kundenportals.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

if (!is_file(APP_DIR . '/data/installed.lock')) {
    header('Location: install.php');
    exit;
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// Formular-Editor und Dokumente brauchen Inline-Stile (Schriftart, Größe, Farbe); Inhalte werden vorher serverseitig gefiltert
$styleSrc = in_array((string)($_GET['r'] ?? ''), ['form_edit', 'form_layouts', 'form_use', 'form_print', 'contract_letter'], true) ? "'self' 'unsafe-inline'" : "'self'";
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src $styleSrc; script-src 'self'; form-action 'self'; frame-ancestors 'none'");

require APP_DIR . '/schema.php';
ensure_schema();
start_session();
if (!current_user()) apply_lang_param();

require APP_DIR . '/pages/auth.php';
require APP_DIR . '/pages/selfservice.php';
require APP_DIR . '/pages/requests.php';
require APP_DIR . '/pages/changes.php';
require APP_DIR . '/employees.php';
require APP_DIR . '/pages/employees.php';
require APP_DIR . '/pages/companies.php';
require APP_DIR . '/pages/users.php';
require APP_DIR . '/pages/masterdata.php';
require APP_DIR . '/pages/apps.php';
require APP_DIR . '/pages/mailsettings.php';
require APP_DIR . '/forms_lib.php';
require APP_DIR . '/pages/forms.php';
require APP_DIR . '/pages/form_editor.php';
require APP_DIR . '/pages/customers.php';
require APP_DIR . '/backup.php';
require APP_DIR . '/pages/backups.php';
require APP_DIR . '/pages/company_backups.php';
require APP_DIR . '/updates.php';
require APP_DIR . '/pages/updates.php';
require APP_DIR . '/pages/links.php';
require APP_DIR . '/pages/tickets.php';
require APP_DIR . '/pages/ticket_stats.php';
require APP_DIR . '/pages/inventory.php';
require APP_DIR . '/pages/inv_licenses.php';
require APP_DIR . '/pages/inv_lifecycle.php';
require APP_DIR . '/pages/inv_links.php';
require APP_DIR . '/pages/inv_reminders.php';
require APP_DIR . '/pages/inv_integrations.php';
require APP_DIR . '/pages/contracts.php';
require APP_DIR . '/pages/ct_inventory.php';
require APP_DIR . '/pdf.php';
require APP_DIR . '/pages/ct_deadlines.php';
require APP_DIR . '/pages/ct_cancel.php';
require APP_DIR . '/pages/ct_sales.php';
require APP_DIR . '/pages/ct_data.php';
require APP_DIR . '/pages/ct_extract.php';
require APP_DIR . '/pages/docs.php';
require APP_DIR . '/pages/docs_more.php';
require APP_DIR . '/pages/docs_files.php';
require APP_DIR . '/pages/docs_vault.php';
require APP_DIR . '/pages/docs_links.php';
require APP_DIR . '/ticket_mail.php';
require APP_DIR . '/pages/settings.php';
require APP_DIR . '/pages/settings_rights.php';
require APP_DIR . '/pages/administration.php';

$routes = [
    'login'          => 'page_login',
    'login_2fa'      => 'page_login_2fa',
    'setup_2fa'      => 'page_setup_2fa',
    'logout'         => 'page_logout',
    'register'        => 'page_register',
    'verify_email'    => 'page_verify_email',
    'forgot_password' => 'page_forgot_password',
    'reset_password'  => 'page_reset_password',
    'account'        => 'page_account',
    'home'           => 'page_home',
    'settings'       => 'page_central_settings',
    'provider_access' => 'page_provider_access',
    'rights'         => 'page_rights',
    'administration' => 'page_administration',
    'backup_settings' => 'page_backup_settings',
    'apps'           => 'page_apps',
    'app_css'        => 'page_app_css',
    'i18n_js'        => 'page_i18n_js',
    'mail_settings'  => 'page_mail_settings',
    'oauth_start'    => 'page_oauth_start',
    'oauth_callback' => 'page_oauth_callback',
    'dashboard'      => 'page_dashboard',
    'request_new'    => 'page_request_form',
    'change_new'     => 'page_change_form',
    'request_edit'   => 'page_request_form',
    'request'        => 'page_request_detail',
    'request_action' => 'page_request_action',
    'request_print'  => 'page_request_print',
    'companies'      => 'page_companies',
    'company_edit'   => 'page_company_edit',
    'users'          => 'page_users',
    'user_edit'      => 'page_user_edit',
    'structure'      => 'page_structure',
    'catalog'        => 'page_catalog',
    'templates'      => 'page_templates',
    'audit'          => 'page_audit',
    'updates'        => 'page_updates',
    'employees'      => 'page_employees',
    'employee_edit'  => 'page_employee_edit',
    'backups'        => 'page_backups',
    'backup_download' => 'page_backup_download',
    'company_backups' => 'page_company_backups',
    'company_backup_download' => 'page_company_backup_download',
    // App „Formulare“
    'forms'           => 'page_forms',
    'form_folder'     => 'page_form_folder',
    'form_action'     => 'page_form_action',
    'form_edit'       => 'page_form_edit',
    'form_layouts'    => 'page_form_layouts',
    'form_settings'   => 'page_form_settings',
    'form_use'        => 'page_form_use',
    'form_print'      => 'page_form_print',
    'customers'       => 'page_customers',
    'customer_edit'   => 'page_customer_edit',
    'customer_fields' => 'page_customer_fields',
    'customer_import' => 'page_customer_import',
    'customer_export' => 'page_customer_export',
    // App „Linksammlung“
    'links'           => 'page_links',
    'link_edit'       => 'page_link_edit',
    'link_action'     => 'page_link_action',
    'link_image'      => 'page_link_image',
    'link_suggest'    => 'page_link_suggest',
    // App „Tickets“
    'tickets'         => 'page_tickets',
    'ticket_new'      => 'page_ticket_new',
    'ticket'          => 'page_ticket',
    'ticket_action'   => 'page_ticket_action',
    'ticket_file'     => 'page_ticket_file',
    'ticket_settings' => 'page_ticket_settings',
    'ticket_inbox'   => 'page_ticket_inbox',
    'ticket_mb_oauth' => 'page_ticket_mb_oauth',
    'ticket_stats'    => 'page_ticket_stats',
    // App „Geräte & Lizenzen“
    'inventory'       => 'page_inventory',
    'inv_device'      => 'page_inv_device',
    'inv_action'      => 'page_inv_action',
    'inv_file'        => 'page_inv_file',
    'inv_settings'    => 'page_inv_settings',
    'inv_licenses'    => 'page_inv_licenses',
    'inv_license'     => 'page_inv_license',
    'inv_lic_action'  => 'page_inv_lic_action',
    'inv_handovers'   => 'page_inv_handovers',
    'inv_handover'    => 'page_inv_handover',
    'inv_handover_new'=> 'page_inv_handover_new',
    'inv_ho_action'   => 'page_inv_ho_action',
    'inv_life'        => 'page_inv_life',
    'inv_labels'      => 'page_inv_labels',
    'inv_my'          => 'page_inv_my',
    'inv_costs'       => 'page_inv_costs',
    'inv_export'      => 'page_inv_export',
    'inv_import'      => 'page_inv_import',
    'inv_integrations'=> 'page_inv_integrations',
    'inv_req_action'  => 'page_inv_req_action',
    // App „Verträge & Laufzeiten“
    'contracts'         => 'page_contracts',
    'contract'          => 'page_contract',
    'contract_action'   => 'page_contract_action',
    'contract_file'     => 'page_contract_file',
    'contract_settings' => 'page_contract_settings',
    'contract_deadlines'=> 'page_contract_deadlines',
    'contract_pdf'      => 'page_contract_pdf',
    'contract_ics'      => 'page_contract_ics',
    'contract_letter'   => 'page_contract_letter',
    'contract_letter_pdf' => 'page_contract_letter_pdf',
    'contract_sales'    => 'page_contract_sales',
    'contract_costs'    => 'page_contract_costs',
    'contract_export'   => 'page_contract_export',
    'contract_import'   => 'page_contract_import',
    'contract_extract'  => 'page_contract_extract',
    // App „Dokumente & Anleitungen“
    'docs'            => 'page_docs',
    'doc_space'       => 'page_doc_space',
    'doc'             => 'page_doc',
    'doc_edit'        => 'page_doc_edit',
    'doc_action'      => 'page_doc_action',
    'doc_space_edit'  => 'page_doc_space_edit',
    'doc_settings'    => 'page_doc_settings',
    'doc_search'      => 'page_doc_search',
    'doc_history'     => 'page_doc_history',
    'doc_version'     => 'page_doc_version',
    'doc_diff'        => 'page_doc_diff',
    'doc_pdf'         => 'page_doc_pdf',
    'doc_templates'   => 'page_doc_templates',
    'doc_template_edit' => 'page_doc_template_edit',
    'doc_file'        => 'page_doc_file',
    'doc_vault'       => 'page_doc_vault',
    'doc_vault_api'   => 'page_doc_vault_api',
    'doc_link_action' => 'page_doc_link_action',
    'doc_from_ticket' => 'page_doc_from_ticket',
    'doc_offboard_check' => 'page_doc_offboard_check',
];

/**
 * Bereiche der Verwaltung je Seite: [Bereich, nötiges Recht beim Aufruf].
 * Speichern (POST) braucht immer „bearbeiten“. Gilt nur für Verwaltungsadmins; Firmenrollen prüft jede Seite selbst.
 */
const ROUTE_AREAS = [
    'companies' => ['companies', PERM_VIEW], 'company_edit' => ['companies', PERM_VIEW],
    'users' => ['users', PERM_VIEW], 'user_edit' => ['users', PERM_VIEW],
    'apps' => ['apps', PERM_VIEW],
    'mail_settings' => ['mail', PERM_VIEW], 'oauth_start' => ['mail', PERM_EDIT], 'oauth_callback' => ['mail', PERM_EDIT],
    'audit' => ['audit', PERM_VIEW],
    'updates' => ['updates', PERM_VIEW],
    'backups' => ['backups', PERM_VIEW], 'backup_download' => ['backups', PERM_VIEW],
    'company_backups' => ['backups', PERM_VIEW], 'company_backup_download' => ['backups', PERM_VIEW],
    'backup_settings' => ['backups', PERM_VIEW], 'provider_access' => ['companies', PERM_VIEW],
    'rights' => ['companies', PERM_VIEW],
    'dashboard' => ['onboarding', PERM_VIEW], 'request' => ['onboarding', PERM_VIEW], 'request_print' => ['onboarding', PERM_VIEW],
    'request_new' => ['onboarding', PERM_EDIT], 'change_new' => ['onboarding', PERM_EDIT], 'request_edit' => ['onboarding', PERM_EDIT],
    'request_action' => ['onboarding', PERM_EDIT],
    'employees' => ['onboarding', PERM_VIEW], 'employee_edit' => ['onboarding', PERM_VIEW],
    'structure' => ['onboarding', PERM_VIEW], 'catalog' => ['onboarding', PERM_VIEW], 'templates' => ['onboarding', PERM_VIEW],
    'forms' => ['forms', PERM_VIEW], 'form_use' => ['forms', PERM_VIEW], 'form_print' => ['forms', PERM_VIEW],
    'form_folder' => ['forms', PERM_EDIT], 'form_action' => ['forms', PERM_EDIT], 'form_edit' => ['forms', PERM_EDIT],
    'form_layouts' => ['forms', PERM_VIEW], 'form_settings' => ['forms', PERM_VIEW],
    'customers' => ['forms', PERM_VIEW], 'customer_edit' => ['forms', PERM_VIEW], 'customer_export' => ['forms', PERM_VIEW],
    'customer_fields' => ['forms', PERM_VIEW], 'customer_import' => ['forms', PERM_EDIT],
    'links' => ['links', PERM_VIEW], 'link_image' => ['links', PERM_VIEW],
    'link_edit' => ['links', PERM_EDIT], 'link_action' => ['links', PERM_EDIT], 'link_suggest' => ['links', PERM_EDIT],
    'tickets' => ['tickets', PERM_VIEW], 'ticket' => ['tickets', PERM_VIEW], 'ticket_file' => ['tickets', PERM_VIEW],
    'ticket_new' => ['tickets', PERM_EDIT], 'ticket_action' => ['tickets', PERM_EDIT], 'ticket_settings' => ['tickets', PERM_VIEW],
    'ticket_inbox' => ['tickets', PERM_VIEW], 'ticket_mb_oauth' => ['tickets', PERM_EDIT], 'ticket_stats' => ['tickets', PERM_VIEW],
    'inventory' => ['inventory', PERM_VIEW], 'inv_device' => ['inventory', PERM_VIEW], 'inv_file' => ['inventory', PERM_VIEW],
    'inv_action' => ['inventory', PERM_EDIT], 'inv_settings' => ['inventory', PERM_VIEW],
    'inv_licenses' => ['inventory', PERM_VIEW], 'inv_license' => ['inventory', PERM_VIEW], 'inv_lic_action' => ['inventory', PERM_EDIT],
    'inv_handovers' => ['inventory', PERM_VIEW], 'inv_handover' => ['inventory', PERM_VIEW], 'inv_handover_new' => ['inventory', PERM_EDIT],
    'inv_ho_action' => ['inventory', PERM_EDIT], 'inv_life' => ['inventory', PERM_EDIT], 'inv_labels' => ['inventory', PERM_VIEW],
    'inv_my' => ['inventory', PERM_VIEW], 'inv_req_action' => ['inventory', PERM_EDIT],
    'inv_costs' => ['inventory', PERM_VIEW], 'inv_export' => ['inventory', PERM_VIEW], 'inv_import' => ['inventory', PERM_EDIT],
    'inv_integrations' => ['inventory', PERM_VIEW],
    'contracts' => ['contracts', PERM_VIEW], 'contract' => ['contracts', PERM_VIEW], 'contract_file' => ['contracts', PERM_VIEW],
    'contract_action' => ['contracts', PERM_EDIT], 'contract_settings' => ['contracts', PERM_VIEW],
    'contract_deadlines' => ['contracts', PERM_VIEW], 'contract_pdf' => ['contracts', PERM_VIEW],
    'contract_letter' => ['contracts', PERM_VIEW], 'contract_letter_pdf' => ['contracts', PERM_VIEW],
    'contract_sales' => ['contracts', PERM_VIEW],
    'contract_costs' => ['contracts', PERM_VIEW], 'contract_export' => ['contracts', PERM_VIEW], 'contract_import' => ['contracts', PERM_EDIT],
    'contract_extract' => ['contracts', PERM_EDIT],
    'docs' => ['docs', PERM_VIEW], 'doc_space' => ['docs', PERM_VIEW], 'doc' => ['docs', PERM_VIEW], 'doc_search' => ['docs', PERM_VIEW],
    'doc_edit' => ['docs', PERM_EDIT], 'doc_action' => ['docs', PERM_EDIT], 'doc_space_edit' => ['docs', PERM_EDIT], 'doc_settings' => ['docs', PERM_VIEW],
    'doc_history' => ['docs', PERM_VIEW], 'doc_version' => ['docs', PERM_VIEW], 'doc_diff' => ['docs', PERM_VIEW], 'doc_pdf' => ['docs', PERM_VIEW],
    'doc_templates' => ['docs', PERM_VIEW], 'doc_template_edit' => ['docs', PERM_EDIT], 'doc_file' => ['docs', PERM_VIEW],
    'doc_vault' => ['docs', PERM_VIEW], 'doc_vault_api' => ['docs', PERM_EDIT],
    'doc_link_action' => ['docs', PERM_EDIT], 'doc_from_ticket' => ['docs', PERM_EDIT], 'doc_offboard_check' => ['docs', PERM_EDIT],
];

/**
 * Seiten ohne Verwaltungsbereich (Anmeldung, Selbstbedienung, Startseite, eigenes Konto).
 * Jede andere Route MUSS in ROUTE_AREAS stehen – sonst bekommen Verwaltungsadmins 403 (sicherer Standard,
 * damit eine neue Seite nicht versehentlich ohne Rechteprüfung erreichbar ist).
 */
const ROUTES_WITHOUT_AREA = ['login', 'login_2fa', 'setup_2fa', 'logout', 'register', 'verify_email',
    'forgot_password', 'reset_password', 'account', 'home', 'app_css', 'i18n_js', 'contract_ics', 'settings', 'administration'];

$r = (string)($_GET['r'] ?? '');
if ($r === '') {
    redirect(current_user() ? 'home' : 'login');
}
if (!isset($routes[$r])) abort(404);

if (is_admin()) {
    if (isset(ROUTE_AREAS[$r])) {
        [$area, $level] = ROUTE_AREAS[$r];
        require_area($area, is_post() || $level === PERM_EDIT);
    } elseif (!in_array($r, ROUTES_WITHOUT_AREA, true)) {
        error_log("[kundenportal] Route '$r' fehlt in ROUTE_AREAS – Zugriff für Verwaltungsadmins gesperrt");
        abort(403);
    }
}

// Externe Dienstleister: nur Anmeldung, eigenes Konto und die App „Tickets“ (Start leitet dorthin)
const PROVIDER_ROUTES = ['login', 'login_2fa', 'setup_2fa', 'logout', 'forgot_password', 'reset_password', 'verify_email', 'account',
    'home', 'app_css', 'i18n_js', 'tickets', 'ticket', 'ticket_action', 'ticket_file'];
if (is_provider_user()) {
    if (!in_array($r, PROVIDER_ROUTES, true)) abort(403);
    if ($r === 'home' && !(int)current_user()['must_change_password']) redirect('tickets');
}

// Zeitsteuerung und E-Mail-Abruf der Tickets, falls kein (stündlicher) Cronjob läuft: höchstens alle 5 Minuten,
// nach der Antwort an den Browser (die Seite wartet also nicht auf den Mailserver)
if (current_user()) register_shutdown_function(function () {
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    tickets_timers_tick();
    inv_reminders_tick();
    ct_reminders_tick();
    wk_reviews_tick();
});

try {
    $routes[$r]();
} catch (PDOException $ex) {
    error_log('[onboarding] ' . $ex->getMessage());
    abort(500, t('Es ist ein Datenbankfehler aufgetreten. Bitte versuchen Sie es später erneut.'));
}
