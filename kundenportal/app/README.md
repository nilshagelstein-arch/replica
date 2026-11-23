# app – Kernbibliothek des Kundenportals

## Overview

Fachlogik und Infrastruktur, die alle Einstiegspunkte (`../index.php`, `../api.php`, `../cron.php`, `../install.php`) und Seiten-Handler gemeinsam nutzen:

- Konfiguration, Datenbank und Session
- Rollen- und Rechtemodell, Mandantentrennung
- Datenbankschema und Migrationen
- 2FA (TOTP) und QR-Codes
- E-Mail-Versand (SMTP, Microsoft Graph, Gmail)
- Datensicherung (lokal und Google Drive)
- Mitarbeiterliste
- Formular- und Kundendatenbank-Bibliothek
- Update-Pakete
- Mehrsprachigkeit (Deutsch, Englisch, Französisch)

Der ganze Ordner ist per `.htaccess` (`Require all denied`) vor direktem Zugriff geschützt.

## Interface

- **Unterverzeichnisse:**
  - [pages/README.md](./pages/README.md): `page_*`-Handler je Route
  - [views/README.md](./views/README.md): gemeinsames HTML-Layout
  - `data/`: Laufzeitdaten (siehe Side Effects). Versioniert ist dort nur `data/.htaccess`.
- **`bootstrap.php`**: wird von jedem Einstiegspunkt geladen.
  - Konstanten:
    - `ROLES`: `superadmin`, `admin`, `firma_admin`, `firma_user`
    - `STATUSES`: `eingereicht`, `in_bearbeitung`, `rueckfrage`, `eingerichtet`, `freigegeben`, `storniert`. Die ersten vier bilden `OPEN_STATUSES`.
    - `REQUEST_TYPES`: `onboarding`, `offboarding`, `stilllegung`, `reaktivierung`
    - `ITEM_TYPES`, `ABSENCE_REASONS`, `MAILBOX_ACTIONS`, `DATA_ACTIONS`, `LOCK_OPTIONS`
    - `PERM_NONE`/`PERM_VIEW`/`PERM_EDIT` (0/1/2), `ADMIN_AREAS`, `APP_AREAS`
  - Helfer:
    - Allgemein: `cfg`, `e`, `url`, `redirect`, `flash`, `abort`
    - Datenbank: `db`, `q`, `row`, `rows`, `insert`, `update`
    - Protokoll: `audit`
    - Session und CSRF: `start_session`, `csrf_token`, `csrf_field`, `require_post_csrf`
    - Ausgabe: `render_page`, `company_switcher`
    - Passwortregeln: `validate_password`
  - Wächter: `require_login`, `require_admin`, `require_manager`, `require_superadmin`, `require_area`, `require_app`, `require_all_companies`, `require_company_access`, `can_access_company`, `company_scope_sql`, `admin_can`, `admin_company_ids`, `context_company_id`, `apps_for_user`, `company_has_app` und `company_pending`

  Quelle: `[bootstrap.php:*]`
- **`schema.php`**:
  - `SCHEMA_VERSION` (= 31; ab 31 `ct_notices` (gemeldete Fristen), `ct_feeds` (Kalender-Abos, Token nur als SHA-256 plus verschlüsselt) und `companies.ct_lead_days`/`ct_pdf_*`; ab 29 gibt es die App „Verträge & Laufzeiten“ mit `ct_types`, `contracts`, `ct_log`, `ct_files`, `ct_group_perms` und den Schaltern `companies.ct_provider_access`/`ct_provider_costs`; ab 30 `ct_prices` (Preishistorie), `ct_links` (Verknüpfung mit Geräten/Lizenzen) und `companies.ct_inv_licenses`), `BUILTIN_APPS` (`onboarding`, `formulare`, `datensicherung`, `links`, `tickets`, `inventar`, `vertraege`)
  - `schema_statements()` für MySQL und SQLite
  - `run_migrations()`, `ensure_schema()`
  - Tabellen: `companies`, `users`, `user_tokens`, `departments`, `positions`, `catalog_items`, `template_items`, `requests`, `request_items`, `request_log`, `audit_log`, `apps`, `company_apps`, `link_tabs`, `link_sections`, `links`, `mail_accounts`, `settings`, `form_folders`, `forms`, `form_layouts`, `form_branding`, `customer_fields`, `customers`, `api_tokens`, `employees`
- **`totp.php`**: `totp_generate_secret`, `totp_code`, `totp_verify`, `totp_uri`, `base32_encode`, `base32_decode` (RFC 6238).
- **`qrcode.php`**: `QrCodeM::svg($text)` erzeugt eine SVG-Grafik (Byte-Modus, Fehlerkorrektur M, Versionen 1–40) `[qrcode.php:QrCodeM]`.
- **`mail.php`**:
  - Konstante `MAIL_PROVIDERS` (`smtp`, `microsoft`, `google`)
  - Geheimnisse: `encrypt_secret`, `decrypt_secret`, `secret_key`
  - Einstellungen: `setting_get`, `setting_set`
  - OAuth: `oauth_app`, `oauth_endpoints`, `oauth_token_request`, `oauth_access_token`
  - Versand: `http_call`, `SmtpClient`, `send_mail`, `send_via_account`, `send_via_php_mail`, `mail_account_ready`, `company_recipients`
- **`backup.php`**:
  - Portal-Sicherung: `backup_create`, `backup_list`, `backup_path`, `backup_prune`, `backup_daily`, `backup_cron_token`, `backup_cron_url`
  - Firmen-Sicherung (`CBACKUP_APP` = `datensicherung`): `cbackup_create`, `cbackup_path`, `cbackup_prune`, `cbackup_daily`
  - Google-Drive-Brücke über Apps Script: `gdrive_connect`, `gdrive_upload`, `gdrive_prune`, `gdrive_after_backup`, `gdrive_handle_post`, `gdrive_script_code`
  - Standardwerte: `BACKUP_KEEP_DEFAULT` = 14, `GDRIVE_KEEP_DEFAULT` = 30, `GDRIVE_MAX_BYTES` = 35 MiB
- **`employees.php`**: `EMPLOYEE_STATUSES` (`geplant`, `aktiv`, `stillgelegt`, `ausgeschieden`), `EMPLOYEE_FIELDS`, `employee_sync_request`, `employee_find`, `load_employee`, `employee_actions`, `employees_ensure_backfill`.
- **`forms_lib.php`**:
  - Konstanten: `FORMS_APP` (= `formulare`), `CUSTOMER_STD_FIELDS`, `BRANDING_FIELDS`, `LOGO_MIMES`, `LOGO_MAX_BYTES`, `FIELD_TYPES`, `NUMBER_FORMATS`, `PAGE_DEFAULTS`
  - Zugriff: `forms_company_id`, `forms_can_manage`, `forms_require_manage`
  - HTML: `sanitize_html`
  - Platzhalter: `placeholder_catalog`, `fill_placeholders`. Unterstützt werden `kunde.*`, `firma.*`, `datum`, `datum.lang`, `benutzer.*`, `logo` und `seitenumbruch` sowie die Eingabefelder `eingabe:`, `text:`, `zahl:`, `datum:`, `ankreuzen:` und `auswahl:` (Format `typ:Bezeichnung|Option|…`).
  - Briefkopf und Fußzeile: `layout_presets`, `layout_for`
  - Kunden: `customer_save`
  - CSV: `csv_read`, `csv_map_headers` und `csv_cell_safe`/`csv_cell_unescape` (Schutz gegen Formel-Injection beim Export, wird beim Import wieder entfernt)
  - API-Schlüssel: `api_token_generate`, `api_token_lookup`
- **`pdf.php`**: `SimplePdf` erzeugt PDFs ohne Fremdbibliothek (A4, Helvetica/WinAnsi, Tabellen mit Seitenumbruch und Seitenzahlen).
- **`mail.php`/`i18n.php`**: `send_mail()`, `send_mail_t()`, `send_via_account()` und `build_mime()` nehmen optional Anhänge `[['name', 'mime', 'data'], …]` (SMTP, Microsoft Graph, Gmail, PHP `mail()`).
- **`updates.php`**: `updates_dir`, `update_embedded_info`, `update_check_package`, `update_extract_zip`, `update_valid_id`, `update_meta_path`, `update_store`, `updates_list`, `update_get`, `update_save_meta`, `update_apply`. Das Paket erzeugt `../../tools/build-update.php` (siehe [../../tools/README.md](../../tools/README.md)).

- **`i18n.php`**: Mehrsprachigkeit.
  - `t('Deutscher Text', ['x' => …])` übersetzt; der deutsche Quelltext ist zugleich der Schlüssel in `lang/en.php` und `lang/fr.php`. Fehlt ein Eintrag, erscheint Deutsch. Platzhalter stehen in `{…}`.
  - Sprache je Anfrage `[i18n.php:current_lang]`: `with_lang()` (z. B. Mailversand) → Benutzer (`users.lang`, leer = Firma) → Firma (`companies.lang`) → ohne Anmeldung `?lang=` (Session/Cookie `kp_lang`) bzw. Browser-Sprache → Deutsch.
  - `send_mail_t($to, fn() => [$betreff, $text], $firmaId)` baut die Mail je Empfängersprache neu (`lang_for_email()`: Benutzer mit dieser Adresse, sonst Firma).
  - Browser-Skripte holen ihr Wörterbuch über `index.php?r=i18n_js` (`window.KP_T`); welche Texte das sind, steht in `lang/js_keys.php`.
  - Gespeicherte Werte (Status, Gründe, Anrede, Platzhalter `{{kunde.…}}`, API-Feldnamen) bleiben deutsch; übersetzt wird nur die Anzeige.

## Invariants

- **Session** `[bootstrap.php:start_session]`:
  - Cookie `ONBSESSID` mit `httponly`, `SameSite=Lax` und `secure` bei HTTPS. `session.use_strict_mode=1`.
  - Nach `session_lifetime_minutes` (Standard 60) ohne Aktivität wird die Session geleert und die ID neu erzeugt.
- **CSRF:** `require_post_csrf()` verlangt POST und ein passendes `_csrf`-Token (32 Zufallsbytes, Vergleich mit `hash_equals`), sonst 400.
- **Angemeldete Benutzer:** `current_user()` akzeptiert nur aktive Benutzer einer existierenden, aktiven Firma. `require_login()` erzwingt den Passwortwechsel (`must_change_password`). Firmen mit offener Freischaltung dürfen nur `home`, `account` und `setup_2fa` aufrufen.
- **Rechtemodell** `[bootstrap.php:admin_perm]`, `[bootstrap.php:admin_company_ids]`:
  - Der Superadmin hat immer `PERM_EDIT`.
  - Ein Admin ohne gespeicherte `admin_perms` hat überall `PERM_EDIT`. Das ist die gespeicherte Form der Einstellung „Uneingeschränkt“ in der Benutzerverwaltung, kein Versehen. Fehlt in eingeschränkten Rechten ein Bereich, gilt `PERM_NONE`.
  - Ein früherer Superadmin behält nach der Übertragung als `admin` seine bisherigen, meist uneingeschränkten Rechte. Der neue Superadmin kann sie danach einschränken.
  - `context_company_id()`: Eine ausdrücklich angefragte, aber nicht erlaubte Firma ergibt 403. Nur ohne Angabe wird die erste erlaubte Firma gewählt.
  - `client_ip()` liest `REMOTE_ADDR`. Das ist nur richtig, wenn das Portal direkt beim Webhoster läuft und kein Proxy oder CDN davor sitzt (Stand 22.11.2026). Mit Proxy würden alle IP-Begrenzungen für alle Benutzer gemeinsam gelten.
  - Ein leeres `admin_companies` bedeutet „alle Firmen“.
  - `company_scope_sql()` hängt `IN (ids)` an, bei leerer Liste `IN (0)`.
- **App-Zugriff:** `require_app()` verlangt von Mitarbeitenden des Dienstleisters das Bereichsrecht aus `APP_AREAS` und von Firmenbenutzern eine Freigabe in `company_apps` für eine aktive App.
- **Externe Dienstleister (Rolle `dienstleister`):** Konto ohne `company_id`. Die Firmen, die den Dienstleister in `ticket_providers` mit `user_id` eingetragen haben, liefert `provider_company_ids()`; nur dort greifen `can_access_company()` und `context_company_id()`. `index.php` lässt für diese Rolle nur `PROVIDER_ROUTES` zu (Anmeldung, eigenes Konto, App „Tickets“). Ein Ticket sieht der Dienstleister nur, solange es bei ihm liegt (`beim_dienstleister`), oder mit `see_all`. Interne Notizen (`kind = 'note'`) sieht er nie, Ersteller sehen den Austausch mit dem Dienstleister (`kind = 'provider'`) nicht.
- **Passwortregeln:** mindestens 10 Zeichen, mindestens ein Buchstabe und eine Ziffer `[bootstrap.php:validate_password]`.
- **Superadmin:** `run_migrations()` stellt sicher, dass es mindestens einen Superadmin gibt: Fehlt er, wird der aktive Admin mit der kleinsten ID befördert. Weitere Superadmins werden nicht herabgestuft. Dass genau einer existiert, sichern die Seiten (Übertragung in `users.php`) zusammen mit `active_superadmin_count()`.
- **TOTP** `[totp.php:totp_verify]`:
  - Code mit 6 Ziffern, Toleranz ±1 Schritt zu 30 s
  - Schritte `<= lastStep` werden abgewiesen (Schutz vor Wiederverwendung)
  - Geheimnis mit 160 Bit
- **HTML-Bereinigung** `[forms_lib.php:sanitize_html]`:
  - Whitelist für Tags, Attribute, Klassen und CSS.
  - `href` nur `https?://`, `mailto:` oder `tel:`, mit `rel=noopener`.
  - `img src` nur als `data:image/(png|jpe?g|gif|webp);base64`.
  - Formeln nur mit `=…` und höchstens 300 Zeichen.
  - Eingaben bis 8 MiB, Parser mit `LIBXML_NONET`.
- **Platzhalter:** Werte werden mit `e()` maskiert, nur `logo` wird als fertiges `<img>` eingefügt. Unbekannte Platzhalter werden leer `[forms_lib.php:fill_placeholders]`.
- **API-Schlüssel:** Format `kp_<8 hex>_<base64url>`. Gespeichert wird nur der SHA-256-Hash. Die Suche läuft über Präfix und `active=1` mit `hash_equals` `[forms_lib.php:api_token_lookup]`.
- **Geheimnisse** `[mail.php:encrypt_secret]`, `[mail.php:secret_key]`:
  - Verschlüsselung mit libsodium `crypto_secretbox` und zufälliger Nonce.
  - Schlüssel: `sha256(cfg secret_key)` oder `data/secret.key` (0600).
- **Mailversand:**
  - Reihenfolge je Empfänger: Postfach der Firma → zentrales Postfach → PHP `mail()` bzw. `mail.log`. Fehler werden nur protokolliert `[mail.php:send_mail]`.
  - SMTP: Die TLS-Prüfung ist standardmäßig an. `mail.smtp_insecure_tls` schaltet sie ab `[mail.php:SmtpClient]`.
  - SMTP: `SmtpClient` verbindet sich nur mit öffentlichen Adressen. Den Hostnamen löst `public_ip()` auf, verbunden wird mit genau dieser geprüften IP. So wirkt der Schutz auch gegen DNS-Rebinding, und das Zertifikat wird weiter gegen den Hostnamen geprüft. Ausnahme zum Testen: `mail.smtp_allow_private`.
- **`public_ip()`** `[bootstrap.php:public_ip]`: gemeinsamer SSRF-Schutz für ausgehende Verbindungen (Linksammlung, SMTP). Ein Hostname gilt nur, wenn alle aufgelösten IPv4-Adressen öffentlich sind: keine privaten, reservierten, Loopback- oder CGNAT-Adressen (100.64.0.0/10), keine IPv6-Literale.
  - `mail_header_clean()` entfernt CR, LF und NUL aus Headern (Schutz gegen Header-Injection). `mime_encode_header()`, `build_mime()` und `SmtpClient` (MAIL FROM/RCPT TO) nutzen die Funktion.
- **Datensicherung:**
  - Sicherungsnamen werden per Regex geprüft (Schutz gegen Pfadangaben). Firmen-Sicherungen bleiben auf den eigenen Ordner `firma-<cid>` beschränkt `[backup.php:backup_path]`, `[backup.php:cbackup_path]`.
  - Es läuft immer nur eine Sicherung (`flock`). Optional mit AES-256 verschlüsselt `[backup.php:backup_create]`.
  - Automatische Sicherung höchstens einmal täglich. Gelöscht werden nur automatische Sicherungen `[backup.php:backup_daily]`, `[backup.php:backup_prune]`.
  - Firmenexport ohne Passwörter, TOTP-Geheimnisse und Postfach-Zugangsdaten `[backup.php:cbackup_data]`.
  - Die Drive-URL muss `https://script.google.com/macros/s/…/exec` sein `[backup.php:gdrive_connect]`.
- **Mitarbeiterstatus** `[employees.php:employee_sync_request]`:
  - Ein freigegebener Auftrag setzt den Status: Onboarding → `aktiv`, Offboarding → `ausgeschieden`, Stilllegung → `stillgelegt`, Reaktivierung → `aktiv`.
  - Ein offenes Onboarding legt `geplant` an, ändert aber keinen bestehenden Eintrag mit anderem Status.
  - Ein storniertes Onboarding löscht den zugehörigen Eintrag `geplant`.
- **Update-Pakete** `[updates.php:update_check_package]`, `[updates.php:update_apply]`:
  - Das ZIP muss `index.php` und `app/bootstrap.php` enthalten.
  - Paket-IDs müssen `^\d{8}-\d{6}-[a-f0-9]{8}$` entsprechen (Schutz gegen Pfadangaben) `[updates.php:update_valid_id]`.
  - Nie überschrieben werden `config.php` und `app/data/*`. Ausnahme: `app/data/.htaccess` wird überschrieben.
  - `install.php` wird übersprungen, sobald `installed.lock` existiert. Einträge mit `..` oder führendem `/` werden übersprungen.

## Side Effects

- **Datenbank:**
  - `ensure_schema()` und `run_migrations()` führen `CREATE`/`ALTER TABLE` aus und legen fehlende `BUILTIN_APPS` an. Beim ersten Anlegen der Apps wird `onboarding` für alle bestehenden Firmen in `company_apps` freigeschaltet.
  - `audit()` schreibt in `audit_log`.
  - `setting_set()` schreibt in `settings`.
  - `customer_save()` schreibt in `customers`.
  - `employee_sync_request()` schreibt in `employees`.
  - `oauth_access_token()` aktualisiert `mail_accounts`.
  - `forms_ensure_defaults()` legt Standardvorlagen und ein Beispielformular an, und zwar genau einmal je Firma (Markierung `forms_seeded_c<id>` in `settings`).
- **Dateien unter `data/`:**
  - `schema.version`, `secret.key`, `mail.log`, `installed.lock`, bei SQLite die Datenbank
  - `backups/backup-*.zip|.sql.gz` mit `backups/.lock`, `.htaccess` und `index.html`
  - `backups/firma-<cid>/firma-<cid>-…-(auto|manuell)-<hex8>.zip`
  - `updates/<id>.php|.json`, temporär `update-<hex>.zip`
- **Programmdateien:** `update_apply()` schreibt Dateien unter dem Portalordner und ruft `opcache_reset` auf.
- **Externe Dienste:**
  - SMTP-Server nach Konfiguration
  - `login.microsoftonline.com` und `graph.microsoft.com` (`/me/sendMail`, `/me`)
  - `accounts.google.com`, `oauth2.googleapis.com`, `openidconnect.googleapis.com` und `gmail.googleapis.com`
  - `script.google.com` mit Weiterleitung auf `script.googleusercontent.com` (Apps-Script-Brücke für Drive)
- **Mails bei Fehlern:** `backup_daily()` und `gdrive_after_backup()` benachrichtigen `mail.admin_recipients` bzw. `company_recipients()`, wenn `backup_notify` bzw. `cbackup_notify_c<cid>` aktiv ist. Sie schreiben außerdem `backup_last_*` und `gdrive_*` in `settings`.
- **Session und Header:** Session-Cookie, `Location` bei `redirect()`, HTTP-Statuscodes bei `abort()`. Die Sicherheitsheader wie CSP setzt `../index.php`, nicht diese Bibliothek.

## Verification

- Run: `<test-command>` (kein automatisierter Test im Repository; Rauchtest: `php -S 127.0.0.1:8080` in `kundenportal/` mit `'driver' => 'sqlite'`, siehe [../README.md](../README.md))
