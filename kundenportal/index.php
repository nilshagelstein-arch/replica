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
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'");

require APP_DIR . '/schema.php';
ensure_schema();
start_session();

require APP_DIR . '/pages/auth.php';
require APP_DIR . '/pages/requests.php';
require APP_DIR . '/pages/changes.php';
require APP_DIR . '/pages/companies.php';
require APP_DIR . '/pages/users.php';
require APP_DIR . '/pages/masterdata.php';
require APP_DIR . '/pages/apps.php';
require APP_DIR . '/pages/mailsettings.php';
require APP_DIR . '/forms_lib.php';
require APP_DIR . '/pages/forms.php';
require APP_DIR . '/pages/customers.php';

$routes = [
    'login'          => 'page_login',
    'login_2fa'      => 'page_login_2fa',
    'setup_2fa'      => 'page_setup_2fa',
    'logout'         => 'page_logout',
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
