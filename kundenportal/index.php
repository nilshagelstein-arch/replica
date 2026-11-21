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
$styleSrc = in_array((string)($_GET['r'] ?? ''), ['form_edit', 'form_layouts', 'form_use', 'form_print'], true) ? "'self' 'unsafe-inline'" : "'self'";
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src $styleSrc; script-src 'self'; form-action 'self'; frame-ancestors 'none'");

require APP_DIR . '/schema.php';
ensure_schema();
start_session();

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
    'apps'           => 'page_apps',
    'app_css'        => 'page_app_css',
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
];

$r = (string)($_GET['r'] ?? '');
if ($r === '') {
    redirect(current_user() ? 'home' : 'login');
}
if (!isset($routes[$r])) abort(404);

try {
    $routes[$r]();
} catch (PDOException $ex) {
    error_log('[onboarding] ' . $ex->getMessage());
    abort(500, 'Es ist ein Datenbankfehler aufgetreten. Bitte versuchen Sie es später erneut.');
}
