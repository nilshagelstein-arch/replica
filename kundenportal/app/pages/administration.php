<?php
/**
 * Zentrale Einstellungen, Stufe 4: Seite „Administration“ für den Portalbetreiber (Superadmin, Admin).
 *
 * Bündelt die Verwaltung, die bisher als einzelne Punkte im Hauptmenü stand: Firmen, Benutzer, Apps & Freigaben,
 * zentraler E-Mail-Versand, Portal-Sicherung, Updates und Protokoll. Jeder Punkt erscheint nur mit Bereichsrecht.
 * Dazu „Einstellungen einer Firma“ mit Firmenauswahl. Auf den Verwaltungsseiten zeigt eine Leiste
 * „Administration“ die Punkte als Reiter (siehe views/layout.php).
 */

/** Punkte der Administration: Bereich => [Route, Titel, Beschreibung, weitere Routen derselben Seite] */
function admin_sections(): array
{
    return [
        'companies' => ['companies', 'Firmen', 'Kundenfirmen anlegen, freischalten und bearbeiten', ['company_edit']],
        'users'     => ['users', 'Benutzer', 'Konten aller Firmen und Verwaltungsadmins mit ihren Bereichsrechten', ['user_edit']],
        'apps'      => ['apps', 'Apps & Freigaben', 'Welche Firma welche App nutzen darf; externe Apps verwalten', []],
        'mail'      => ['mail_settings', 'E-Mail-Versand', 'Zentraler Versand des Portals (SMTP, Microsoft 365, Gmail)', ['oauth_start', 'oauth_callback']],
        'backups'   => ['backups', 'Datensicherung', 'Sicherungen des ganzen Portals und Google-Drive-Anbindung', ['backup_download']],
        'updates'   => ['updates', 'Updates', 'Update-Pakete einspielen und den Verlauf ansehen', []],
        'audit'     => ['audit', 'Protokoll', 'Wer hat wann was geändert', []],
    ];
}

/** Sichtbare Punkte für den angemeldeten Betreiber */
function admin_sections_for_user(): array
{
    $u = current_user();
    if (!$u || !is_staff_role($u['role'])) return [];
    return array_filter(admin_sections(), fn ($area) => admin_can($area), ARRAY_FILTER_USE_KEY);
}

/**
 * Gehört die Seite zur Administration (und nicht zu den Einstellungen einer Firma)?
 * Benutzer- und Mail-Seite nur ohne gewählte Firma; das Firmenprofil nur bei „neue Firma“ oder aus der Firmenliste.
 */
function admin_section_active(string $route): ?string
{
    if (!is_admin()) return null;
    foreach (admin_sections_for_user() as $area => [$r, , , $more]) {
        if ($route !== $r && !in_array($route, $more, true)) continue;
        if (in_array($route, ['users', 'mail_settings', 'user_edit'], true) && query_int('company')) return null;
        return $area;
    }
    return null;
}

function page_administration(): void
{
    $u = require_login();
    if (!is_staff_role($u['role'])) abort(403);
    $sections = admin_sections_for_user();
    // Offene Punkte als kleine Hinweise an den Kacheln
    $badges = [];
    if (isset($sections['companies'])) {
        $n = (int)row("SELECT COUNT(*) AS n FROM companies WHERE registration_status = 'pending'" . company_scope_sql('id'))['n'];
        if ($n) $badges['companies'] = t('{n} Registrierung(en) offen', ['n' => $n]);
    }
    if (isset($sections['users']) && is_superadmin()) {
        $n = (int)row('SELECT COUNT(*) AS n FROM users WHERE totp_reset_requested_at IS NOT NULL AND active = 1')['n'];
        if ($n) $badges['users'] = t('{n} 2FA-Zurücksetzung(en) angefragt', ['n' => $n]);
    }
    if (isset($sections['backups'])) {
        $err = setting_get('backup_last_error');
        if ($err !== '') $badges['backups'] = t('Letzte Sicherung fehlgeschlagen');
    }
    $companies = rows('SELECT id, name FROM companies WHERE 1 = 1' . company_scope_sql('id') . ' ORDER BY name');
    $cur = (int)context_company_id();

    render_page(t('Administration'), function () use ($sections, $badges, $companies, $cur) { ?>
        <?php if ($companies): ?>
        <form method="get" class="card form admin-company">
            <input type="hidden" name="r" value="settings">
            <h2><?= e(t('Einstellungen einer Firma')) ?></h2>
            <p class="muted small"><?= e(t('Firmenprofil, Benutzer, Rechte und die Einstellungen der Apps einer Kundenfirma – so, wie die Firma sie selbst sieht, soweit Ihre Rechte und ihre Einsicht-Freigaben reichen.')) ?></p>
            <label><?= e(t('Firma')) ?> <select name="company">
                <?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $cur ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select></label>
            <button class="btn primary"><?= e(t('Einstellungen öffnen')) ?></button>
        </form>
        <?php endif; ?>
        <div class="app-grid">
            <?php foreach ($sections as $area => [$r, $label, $desc]): ?>
                <a class="app-tile" href="<?= e(url($r)) ?>">
                    <span class="app-name"><?= e(t($label)) ?></span>
                    <span class="app-desc"><?= e(t($desc)) ?></span>
                    <?php if (isset($badges[$area])): ?><span class="app-foot"><span class="badge"><?= e($badges[$area]) ?></span></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if (!$sections): ?><p class="muted"><?= e(t('Für Ihr Konto sind keine Verwaltungsbereiche freigegeben.')) ?></p><?php endif; ?>
    <?php });
}
