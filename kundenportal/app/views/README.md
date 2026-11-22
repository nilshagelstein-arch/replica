# views – Seitenlayout

## Overview

`layout.php` ist das gemeinsame HTML-Gerüst aller Portalseiten: `<title>` als „Titel · App-Name“ mit `robots noindex, nofollow`, Kopfleiste mit Benutzer, Firmenname (oder „Administration“), „Mein Konto“ und Abmelden, Hauptnavigation, App-Unternavigation, Seitenkopf mit App-Symbol, Hinweismeldungen (Flashes) und der eigentliche Seiteninhalt. Die Datei definiert keine Funktionen. Sie wird mit vorbereiteten Variablen eingebunden und ruft nur Helfer aus `../bootstrap.php` auf (`cfg`, `e`, `url`, `row`, `csrf_field`, `is_staff_role`, `admin_can`, `apps_for_user`, `company_pending`).

## Interface

- Erwartete Eingabevariablen `[layout.php:$user]`, `[layout.php:$flashes]`, `[layout.php:$title]`, `[layout.php:$content]`, `[layout.php:$route]`:
  - `$user`: `?array` mit `role`, `name`, `company_id`
  - `$flashes`: Liste von `[$type, $msg]`
  - `$title`: Seitentitel
  - `$content`: `callable` ohne Argumente, erzeugt den Seiteninhalt
  - `$route`: aktuelle Route (`?r=`)
- Optional: `$GLOBALS['forms_cid']` hängt für Mitarbeitende des Dienstleisters `company=<id>` an die Formular-Unternavigation an `[layout.php:$fp]`. `$_GET['type']` markiert den aktiven `change_new`-Eintrag `[layout.php:$curType]`.
- Hauptnavigation `[layout.php:$nav]`: `home`, je App aus `apps_for_user()`. Für Firmenbenutzer sind das die in `company_apps` freigeschalteten Apps, für Mitarbeitende des Dienstleisters alle aktiven Apps mit mindestens Leserecht. Die App-Einträge sind `dashboard` (onboarding), `forms` (formulare), `links` (links) und `company_backups` (datensicherung). Dazu die Verwaltungsbereiche aus `[layout.php:$adminNav]` (`companies`, `users`, `apps`, `mail`, `audit`, `backups`, `updates`) bzw. für Firmen-Admins `company_edit`, `users` und `mail_settings`.
- Unternavigation `[layout.php:$subnav]`: erscheint nur, wenn die aktuelle Route in `$onboardingRoutes` bzw. `$formsRoutes` steht. Seitenkopf mit App-Symbol über `$routeApps` (Route → App, z. B. `company_backups` → `datensicherung`). Einträge:
  - On- & Offboarding: `dashboard`, `employees`, `request_new`, `change_new` (offboarding, stilllegung, reaktivierung), `structure`, `catalog`, `templates`
  - Formulare: `forms`, `customers`, `form_layouts`, `form_settings`
- Eingebundene Assets `[layout.php:$route]`:
  - immer: `assets/style.css`, Favicons, Logo und `assets/app.js` (am Ende von `<body>`)
  - nur wenn angemeldet:
    - `index.php?r=app_css`
    - auf `link_edit`: `assets/links.js`
    - auf `form_layouts`: `assets/calc.js` und `assets/forms.js`

## Invariants

- Ohne `$user` wird keine Navigation ausgegeben. Leere `nav`/`subnav` werden nicht gerendert `[layout.php:$nav]`.
- Für Firmen mit offener Freischaltung (`company_pending($user)`) gibt es nur den Eintrag `home`, keine App-Navigation `[layout.php:company_pending]`.
- App-Einträge erscheinen nur, wenn der Slug in `apps_for_user()` enthalten ist. „Datensicherung“ sehen nur Benutzer mit Rolle `firma_admin` `[layout.php:$myApps]`.
- Die Einträge zum Anlegen von Aufträgen (`request_new`, `change_new`) erscheinen nur, wenn `!is_staff_role(role) || admin_can('onboarding', true)` gilt `[layout.php:admin_can]`.
- `structure`, `catalog`, `templates`, `form_layouts` und `form_settings` stehen nur den Rollen `superadmin`, `admin` und `firma_admin` zur Verfügung (striktes `in_array`) `[layout.php:$subnav]`.
- Verwaltungseinträge für Mitarbeitende des Dienstleisters sind jeweils durch `admin_can($area)` geschützt `[layout.php:$adminNav]`.
- Jede dynamische Ausgabe läuft durch `e()`. Die App-ID wird mit `(int)` umgewandelt `[layout.php:e]`.
- Das Abmelden ist ein POST-Formular mit `csrf_field()` `[layout.php:csrf_field]`.

## Side Effects

- Gibt ein vollständiges HTML-Dokument aus und ruft `$content()` auf `[layout.php:$content]`.
- Lesende DB-Abfragen:
  - über `row()`: `SELECT id, name, icon FROM apps WHERE slug = ?`, nur wenn die Route in `$routeApps` steht `[layout.php:$pageApp]`
  - über `row()`: `SELECT name FROM companies WHERE id = ?`, nur mit `company_id` `[layout.php:$companyName]`
  - über Helfer: `company_pending()` (`companies.registration_status`) und `apps_for_user()` (`apps`, `company_apps`)
- Setzt selbst keine Header und schreibt nicht in die Session.

## Verification

- Run: `<test-command>` (kein automatisierter Test im Repository; Rauchtest: `php -S 127.0.0.1:8080` in `kundenportal/`, siehe [../../README.md](../../README.md))
