# replica Blueprint

## System Overview

Das Repository enthält das **Kundenportal**, ein mandantenfähiges PHP-Webportal ohne Composer und ohne Build-Schritt. Kundenfirmen eines IT-Dienstleisters melden sich mit Passwort und 2FA an und nutzen die für sie freigeschalteten Apps:

- On- & Offboarding
- Formulare mit Kundendatenbank und REST-API
- Datensicherung
- Linksammlung
- Tickets
- Geräte & Lizenzen
- Verträge & Laufzeiten

Der Dienstleister verwaltet Firmen, Benutzer, App-Freigaben, E-Mail-Versand, Sicherungen und Updates. Dazu gehört ein Build-Werkzeug, das das Portal in ein selbstinstallierendes Update-Paket verpackt.

```
Browser ──> kundenportal/index.php ──> app/pages/*.php ──> app/*.php ──> MySQL/SQLite
   │            (?r=route, CSP)        (page_* Handler)    (Rechte, Schema,
   │                                                         Mail, Backup)
   ├──> kundenportal/api.php (Bearer kp_… ──> customers bzw. contracts je nach Schlüssel)
   └──> assets/*.js (nur links.js ruft ?r=link_suggest)
Cron ──> kundenportal/cron.php ──> backup_daily ──> app/data/backups/ (+ Google Apps Script)
Entwickler ──> tools/build-update.php ──> build/update.php ──> app/updates.php (update_apply)
```

## Subdirectories & System Boundaries

- **kundenportal:** [./kundenportal/README.md](./kundenportal/README.md). Die Web-Anwendung mit den Einstiegspunkten `index.php` (Front-Controller), `api.php` (REST-API der Kundendatenbank), `cron.php` (tägliche Sicherung) und `install.php` (Ersteinrichtung).
  - **kundenportal/app:** [./kundenportal/app/README.md](./kundenportal/app/README.md). Kernbibliothek: Rechte- und Rollenmodell, Mandantentrennung, Schema mit Migrationen, TOTP, QR-Codes, Mail, Sicherung, Mitarbeiterliste, Formulare und Update-Pakete.
    - **kundenportal/app/pages:** [./kundenportal/app/pages/README.md](./kundenportal/app/pages/README.md). `page_*`-Handler je Route.
    - **kundenportal/app/views:** [./kundenportal/app/views/README.md](./kundenportal/app/views/README.md). Gemeinsames HTML-Layout und Navigation.
  - **kundenportal/assets:** [./kundenportal/assets/README.md](./kundenportal/assets/README.md). Browser-Skripte (Formulareditor, Rechen-Engine `KPCalc`, Ausfüllansicht, Bildvorschläge) und Styles.
- **tools:** [./tools/README.md](./tools/README.md). `build-update.php` erzeugt `build/update.php` aus `kundenportal/`.

## Global Invariants

- **Mandantentrennung:**
  - Firmendaten werden immer mit `company_id` gelesen und geschrieben.
  - Verwaltungsadmins sind über `company_scope_sql()` und `can_access_company()` auf ihre Firmen beschränkt.
  - API-Schlüssel gelten nur für die eigene Firma.
- **Rechte:**
  - Rollen sind `superadmin`, `admin`, `firma_admin` und `firma_user`.
  - Verwaltungsadmins haben je Bereich `PERM_NONE`, `PERM_VIEW` oder `PERM_EDIT`. `index.php` prüft das über `ROUTE_AREAS`.
  - Firmenbenutzer brauchen eine Freigabe in `company_apps`.
- **CSRF:** Jeder schreibende POST der Weboberfläche ist durch `require_post_csrf()` geschützt. Die REST-API `api.php` schreibt ohne CSRF-Prüfung; sie authentifiziert stattdessen über API-Schlüssel mit `can_write=1`.
- **Unantastbare Daten:** `config.php` und `app/data/` gehören nicht zum Programmcode. Weder das Update-Paket (`tools/build-update.php`) noch `update_apply()` überschreiben sie.
- **Kein Direktzugriff:** `app/` und `app/data/` sind per `.htaccess` gesperrt.

## Global Side Effects

- **Datenbank** (MySQL/MariaDB oder SQLite): 26 Tabellen aus `kundenportal/app/schema.php`. Das Schema wird bei jedem Aufruf über `ensure_schema()` migriert.
- **Dateisystem** `kundenportal/app/data/`: `secret.key`, `schema.version`, `installed.lock`, `mail.log`, `backups/` und `updates/`. Bei SQLite liegt dort auch die Datenbank (Standard `portal.sqlite`). Während eines Updates entsteht vorübergehend `update-*.zip`.
- **Externe Dienste:**
  - SMTP-Server
  - Microsoft Entra und Graph sowie Google OAuth und Gmail API für den Mailversand
  - Google Apps Script für Drive-Sicherungen
  - öffentliche Webseiten für Bildvorschläge in der Linksammlung
- **Build:** `tools/build-update.php` schreibt `build/update.php`. `build/` steht in `.gitignore`.

## System Verification

- Run: `<test-command>` (das Repository hat weder eine Testsuite noch ein Build-Manifest; Rauchtest: `cd kundenportal && php -S 127.0.0.1:8080` mit SQLite-Konfiguration, siehe [./kundenportal/README.md](./kundenportal/README.md))
