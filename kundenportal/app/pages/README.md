# pages – Seiten-Handler

## Overview

Jede Datei enthält die `page_*`-Funktionen einer App oder eines Verwaltungsbereichs.

**Ablauf einer Anfrage:**

1. `../../index.php` wählt anhand von `?r=<route>` den Handler aus.
2. Für Dienstleister-Konten prüft `index.php` vorher den Bereich: `ROUTE_AREAS` mit `require_area()`.
3. Der Handler prüft Anmeldung, Rolle, App-Freigabe und Firmenzugriff, bei POST zusätzlich CSRF.
4. Er schreibt über die Datenbank-Helfer, protokolliert mit `audit()` und gibt die Seite mit `render_page()` aus.

**Begriffe:**

- *Dienstleister-Konten:* `superadmin` und `admin` (Verwaltungsadmin)
- *Firmenkonten:* `firma_admin` und `firma_user`

**Woanders beschrieben:**

- Rollen, Rechtemodell, Status-Liste (`STATUSES`/`OPEN_STATUSES`) und Konfiguration: [../README.md](../README.md)
- Bereiche in `ROUTE_AREAS` und die Funktionen aus Benutzersicht: [../../README.md](../../README.md)
- Layout: [../views/README.md](../views/README.md)

## Interface

In der Spalte „POST-`action`“ stehen die Werte des Formularfelds `action`. „Speichern“ heißt: ein Formular-POST ohne `action`. Die vollständige Zuordnung von Routen zu Handlern steht in `$routes` in `../../index.php`.

| Datei | Zweck | Routen (Einstieg) | POST-`action` |
|---|---|---|---|
| `apps.php` | Startseite, App-Freigaben | `home`, `app_css`, `apps` `[apps.php:page_apps]` | `matrix`, `add`, `save`, `delete` |
| `auth.php` | Anmeldung, 2FA, eigenes Konto | `login`, `login_2fa`, `setup_2fa`, `logout`, `account` `[auth.php:page_login]` | `request_reset`, `password`, `reset2fa` |
| `backups.php` | Portal-Sicherung (Dienstleister) | `backups`, `backup_download` `[backups.php:page_backups]` | `create`, `delete`, `settings`, `token`, `gdrive_*` |
| `changes.php` | Offboarding, Stilllegung, Reaktivierung | `change_new` `[changes.php:page_change_form]` | Speichern |
| `companies.php` | Firmen, Freischaltung von Registrierungen | `companies`, `company_edit` `[companies.php:page_company_edit]` | `approve_registration`, `reject_registration`, Speichern |
| `company_backups.php` | Firmen-Sicherung (App Datensicherung) | `company_backups`, `company_backup_download` `[company_backups.php:page_company_backups]` | `create`, `delete`, `settings`, `gdrive_*` |
| `contracts.php` | App „Verträge & Laufzeiten“: Verträge mit Art, Laufzeit, Fristen, Kosten mit Preishistorie, Betreuung, Dokumenten und Verlauf; Rechte je Gruppe mit „Kosten sehen“ | `contracts`, `contract`, `contract_action`, `contract_file`, `contract_settings` `[contracts.php:page_contracts]` | `save`, `note`, `upload`, `file_delete`, `delete`, `price_add`, `price_delete`, `link_add`, `link_remove`; Einstellungen `general`, `view`, `type_save`, `perms` |
| `ct_deadlines.php` | Verträge, Stufe 3: Fristen-Ereignisse, „Demnächst fällig“, Fristenkalender, PDF (Download, Mail, automatisch), Kalender-Abo (ICS ohne Anmeldung über geheimen Link), Erinnerungen über `cron.php` | `contract_deadlines`, `contract_pdf`, `contract_ics` `[ct_deadlines.php:page_contract_deadlines]` | `ics_create`, `ics_reset`, `ics_delete`, `pdf_mail` |
| `ct_cancel.php` | Verträge, Stufe 4: Kündigung vorbereiten (Fristprüfung, Schreiben aus der Formulare-Vorlage), Freigabe mit Vier-Augen-Prinzip, Versand per Mail mit PDF oder als versendet markieren, Bestätigung hochladen, Erinnerungen | `contract_letter`, `contract_letter_pdf` `[ct_cancel.php:page_contract_letter]`; Aktionen über `contract_action` | `cancel_prepare`, `cancel_approve`, `cancel_reject`, `cancel_withdraw`, `cancel_send_mail`, `cancel_mark_sent`, `cancel_confirm`; Einstellung `cancel` |
| `ct_sales.php` | Verträge, Stufe 5: „Angebot anfragen“ als Ticket (sofort an den betreuenden Dienstleister übergeben, ohne App Tickets per E-Mail), Vertriebsübersicht über alle Firmen mit CSV-Export (nur Portal-Betreiber, nur mit Einsicht der Firma) | `contract_sales` `[ct_sales.php:page_contract_sales]`; Anfrage über `contract_action` | `offer_request` |
| `ct_data.php` | Verträge, Stufe 6: Kostenübersicht mit 12-Monats-Hochrechnung und Vorjahresvergleich (Stufe 7, auch mit beendeten Verträgen), CSV-Export (Verträge, Fristen, Preise), CSV-Import mit Vorschau, gemeinsame Feldlogik für Import und API, API-Schlüssel (scope `vertraege`) und die REST-Routen `/vertraege`, `/fristen`, `/vertragsarten` (`ct_api()`, aufgerufen aus `api.php`) | `contract_costs`, `contract_export`, `contract_import` `[ct_data.php:page_contract_costs]` | Einstellungen `api_create`, `api_revoke`; Import `stage=import` |
| `ct_extract.php` | Verträge, Stufe 7: Vertragsdaten aus PDF lesen (ohne KI, ohne Übertragung: Text über `pdftext.php`, Erkennung in `ct_extract_fields()`), Vorschläge prüfen und gezielt übernehmen oder neuen Vertrag aus PDF erfassen (Formular vorausgefüllt, PDF wird angehängt); optional Texterkennung „Mistral OCR“ für gescannte PDFs – nur wenn ein Firmen-Admin sie mit eigenem Schlüssel einschaltet, und nur nach Zustimmung je Dokument. Vorjahresvergleich steht in `ct_data.php` (`ct_year_costs`) | `contract_extract` `[ct_extract.php:page_contract_extract]` | `upload_new`, `ocr`, `apply`; Einstellung `ocr` |
| `docs.php` | Dokumente & Anleitungen, Stufe 1: Wiki mit Bereichen, Rechten je Bereich über Gruppen (`wk_space_perms`, dazu `all_read`), Seitenbaum (`wk_pages.parent_id`, Reihenfolge `sort`), Editor (`assets/wiki.js`, Inhalt über `sanitize_html()`), Anzeige mit Inhaltsverzeichnis und Sprungmarken (`wk_render_body()`), Suche; Dienstleister nur mit `companies.wk_provider_access` | `docs`, `doc_space`, `doc`, `doc_edit`, `doc_space_edit`, `doc_settings`, `doc_search` `[docs.php:page_docs]` | `doc_action`: `move_up`, `move_down`, `delete`, `spaces_from_departments`; `doc_space_edit`: `delete` |
| `docs_more.php` | Dokumente & Anleitungen, Stufe 2: Inhalt in Blöcke zerlegen (`wk_blocks()`, für Vergleich und PDF), Versionen (`wk_version_add()`, Altbestand über `wk_version_ensure_initial()`), Zeilenvergleich (`wk_diff()`), eingebaute und eigene Vorlagen, Kommentare mit @-Erwähnung (`wk_mentions()`), Beobachten und Benachrichtigung nach der Antwort (`wk_notify()`), PDF über `SimplePdf` | `doc_history`, `doc_version`, `doc_diff`, `doc_pdf`, `doc_templates`, `doc_template_edit` `[docs_more.php:page_doc_history]` | über `doc_action` (`wk_action_more()`): `watch`, `unwatch`, `comment_add`, `comment_delete`, `restore`, `save_template`; `doc_template_edit`: `delete` |
| `docs_files.php` | Dokumente & Anleitungen, Stufe 3: Entwurf/Veröffentlichen und Prüfdatum (`wk_review_post()`, `wk_status_bar()`, Erinnerung `wk_reviews_run()`/`wk_reviews_tick()`), Dateien mit Versionen (`wk_file_store()`, Ablage `app/data/docs/<Firma>/`), „In unser Wiki kopieren“ aus dem Bereich „Für alle Kunden“ (Firma 0, `wk_is_hub()` in `docs.php`, Aufruf mit `hub=1`) | `doc_file` `[docs_files.php:page_doc_file]` | über `doc_action` (`wk_action_stage3()`): `publish`, `unpublish`, `reviewed`, `file_add`, `file_version`, `file_delete`, `copy_to_company` |
| `docs_vault.php` | Dokumente & Anleitungen, Stufe 4: Passwort-Tresor (Zero-Knowledge). Seite `doc_vault` (Oberfläche baut `assets/vault.js`), JSON-Schnittstelle `doc_vault_api` mit Rechteprüfung je Ordner (`wkv_perm()`), Freigaben, Schlüsselwechsel, Notfallzugriff und Protokoll; der Server sieht nie Klartext | `doc_vault`, `doc_vault_api` `[docs_vault.php:page_doc_vault_api]` | `doc_vault_api`: `state`, `account_create`, `account_password`, `company_activate`, `company_key`, `settings`, `folder_save`, `folder_delete`, `share`, `emergency_take`, `rotate`, `entry_get`, `entry_save`, `entry_delete`, `log`, `entry_log`, `account_reset` |
| `ct_inventory.php` | Verträge ↔ Inventar: Abo-/Wartungslizenzen als Ansicht in der Vertragsliste, Verknüpfung mit Geräten und Lizenzen, Karte „Verträge“ im Inventar, Hinweis beim Ausmustern | – (Hilfsfunktionen für `contracts.php` und das Inventar) | – |
| `customers.php` | Kundendatenbank (App Formulare) | `customers`, `customer_edit`, `customer_fields`, `customer_import`, `customer_export` `[customers.php:page_customers]` | `delete`, `add`, `save`; Import-Modus `add`/`upsert` |
| `employees.php` | Mitarbeiterliste | `employees`, `employee_edit` `[employees.php:page_employees]` | `import` (auf `employees`), `delete` (auf `employee_edit`) |
| `form_editor.php` | Formular bearbeiten, ausfüllen, drucken | `form_edit`, `form_use`, `form_print` `[form_editor.php:page_form_edit]` | Speichern |
| `forms.php` | Formulare, Ordner, Briefköpfe, Logo, API-Schlüssel | `forms`, `form_folder`, `form_action`, `form_layouts`, `form_settings` `[forms.php:page_forms]` | Ordner `create`/`rename`/`delete`; Formular `duplicate`/`move`/`delete`; `branding`, `logo`, `logo_delete`, `token_create`, `token_revoke` |
| `links.php` | Linksammlung | `links`, `link_edit`, `link_action`, `link_image`, `link_suggest` `[links.php:page_links]` | `tab_*`, `section_*`, `link_move`, `link_delete`; auf `link_edit` `delete` |
| `mailsettings.php` | E-Mail-Postfächer, OAuth | `mail_settings`, `oauth_start`, `oauth_callback` `[mailsettings.php:page_mail_settings]` | `oauth_app`, `save`, `test`, `delete` |
| `masterdata.php` | Abteilungen, Positionen, Katalog, Vorlagen, Protokoll | `structure`, `catalog`, `templates`, `audit` `[masterdata.php:page_structure]` | `add_dep`, `save_dep`, `add_pos`, `save_pos`, `add`, `save` |
| `requests.php` | Aufträge: Liste, Onboarding, Detail, Workflow | `dashboard`, `request_new`, `request_edit`, `request`, `request_action`, `request_print` `[requests.php:page_request_action]` | `checklist`, `status`, `complete_all`, `comment`, `cancel`, `change_request`, `change_withdraw`, `change_approve`, `change_reject` |
| `selfservice.php` | Registrierung, E-Mail-Bestätigung, Passwort vergessen | `register`, `verify_email`, `forgot_password`, `reset_password` `[selfservice.php:page_register]` | Speichern |
| `updates.php` | Update-Pakete einspielen | `updates` `[updates.php:page_updates]` | `upload`, `apply`, `edit`, `delete` |
| `users.php` | Benutzer und Admin-Rechte | `users`, `user_edit` `[users.php:page_user_edit]` | `save`, `reset_password`, `reset_2fa`, `delete`, `transfer_superadmin`, `verify_email`, `resend_verification`, `unlock` |

**Von anderen Seiten genutzt:** `selfservice.php` liefert `handle_registration_decision`, `request_2fa_reset`, `portal_link` und `send_verification_mail` für `companies.php`, `auth.php` und `users.php`. `form_editor.php` liefert `render_word_editor` für `forms.php`. Wer diese Funktionen umbenennt, muss die Aufrufer mit ändern.

## Invariants

- **Anfragen:**
  - Jeder schreibende POST ruft `require_post_csrf()` auf. Die Funktion beantwortet alles außer POST mit 400. Routen, die sie ohne eigene Methodenprüfung aufrufen (`logout`, `form_folder`, `form_action`, `request_action`, `oauth_start`), gehen daher nur per POST. `link_action` prüft die Methode selbst und antwortet mit 405.
  - Für Dienstleister-Konten verlangt `index.php` bei jedem POST `PERM_EDIT` im Bereich der Route.
  - **Neue Route → Eintrag in `ROUTE_AREAS` ist Pflicht.** Eine Route, die weder dort noch in `ROUTES_WITHOUT_AREA` steht, beantwortet `index.php` für Dienstleister-Konten mit 403.
  - Die Handler wiederholen die Bereichsprüfung nicht. `admin_can()` steuert in den Seiten nur die Anzeige.
- **Mandantentrennung:**
  - Datensätze werden mit `company_id = ?` gelesen und geschrieben, z. B. `[forms.php:load_form]` und `[customers.php:load_customer]`.
  - Wo zuerst über die ID geladen wird (`load_request`, `page_link_image`, Katalog `save`, `page_user_edit`), folgt direkt danach die Zugriffsprüfung (`require_company_access`, `can_access_company` oder `can_edit_catalog_item`).
  - Firmenkonten sind auf die eigene `company_id` festgelegt.
  - Für Dienstleister-Konten ergibt eine ausdrücklich angefragte, aber nicht erlaubte Firma 403 (`context_company_id`).
  - Firmenübergreifende Aktionen brauchen Zugriff auf alle Firmen: neue Firma, eigene Apps, Portal-Sicherung, globale Katalogeinträge.
- **Rechte der Firmenkonten:**

  | Bereich | App-Prüfung | `firma_user` | nur `firma_admin` |
  |---|---|---|---|
  | Aufträge | `require_app('onboarding')` | anlegen, ansehen, kommentieren, stornieren (aus `eingereicht`/`rueckfrage`) | Änderungsantrag entscheiden (auch die einreichende Person) |
  | Mitarbeiterliste | `require_app('onboarding')` | ansehen, Aufträge starten | anlegen, ändern, CSV-Import und -Export |
  | Abteilungen, Katalog, Vorlagen | `require_app('onboarding')` | – | alles (`require_manager`) |
  | Formulare, Ordner, Kunden | `forms_company_id()` | anlegen, ändern, löschen, ausfüllen (Beschluss vom 22.11.2026) | – |
  | Briefköpfe, Logo, eigene Felder, Kunden-CSV, API-Schlüssel | `forms_company_id()` | – | alles (`forms_require_manage`) |
  | Linksammlung | `links_company_id()` | ansehen | bearbeiten (`links_require_manage`) |
  | Firmen-Sicherung | `cbackup_company_id()` | – | alles |
  | Firmenprofil, Benutzer, E-Mail-Versand | – | – | alles (`require_manager`) |
- **Nur der Superadmin darf:**
  - Dienstleister-Konten anlegen, ändern und löschen
  - 2FA zurücksetzen
  - die Superadmin-Rolle übertragen (mit Passwort)
  - Updates einspielen (mit Passwort)

  Quellen: `[users.php:page_user_edit]`, `[updates.php:page_updates]`
- **Eigenes Konto:** Passwort- und 2FA-Reset, Löschen und Rollenwechsel sind für das eigene Konto gesperrt. Firmenkonten werden nur deaktiviert, nie gelöscht.
- **Anmeldung** `[auth.php:page_login]`:
  - Ob ein Konto existiert, ist nicht erkennbar:
    - Für unbekannte Adressen prüft `password_verify` gegen einen gespeicherten Dummy-Hash, damit die Antwortzeit gleich ist.
    - Jeder Fehlschlag wartet 200–500 ms und zeigt `LOGIN_FAIL_MESSAGE`.
    - „Konto gesperrt“ sieht nur, wer das richtige Passwort eingibt.
  - Sperren:
    - Kontosperre nach `max_login_attempts` (5) Fehlversuchen
    - IP-Sperre nach `max_login_attempts_ip` (20) Fehlversuchen über alle Konten
    - Beide gelten für `lockout_minutes` (15).
  - Nach richtigem Passwort bleiben 300 s für den 2FA-Code. Ein TOTP-Code gilt nur einmal (`totp_last_step`).
- **Selbstbedienung** `[selfservice.php:page_register]`:
  - Registrierung: Honeypot, Absenden frühestens 3 s nach Aufruf, Begrenzung je IP (`registration.max_per_hour`)
  - Bestätigungslinks gelten 48 h, Links zum Zurücksetzen 60 min und nur einmal. Gespeichert wird nur der SHA-256-Hash.
  - „Passwort vergessen“ antwortet immer gleich, egal ob die Adresse existiert, und erlaubt höchstens 10 Anfragen je IP und Stunde.
- **Auftrags-Workflow** `[requests.php:page_request_action]`:
  - Nur Dienstleister-Konten setzen den Status, pflegen die Checkliste und schließen ab, und das nur, solange der Auftrag offen ist.
  - Eine `rueckfrage` braucht eine Nachricht. Die Antwort der Firma setzt den Auftrag wieder auf `in_bearbeitung`.
  - **Abgeschlossene Aufträge** (`freigegeben`, `storniert`) sind für Dienstleister-Konten gesperrt (`request_locked`).
    - Sie können nur einen begründeten Änderungsantrag stellen (`change_request`). Pro Auftrag ist höchstens einer offen, und bis zur Entscheidung kann er zurückgezogen werden.
    - Entscheiden dürfen nur die Firmen-Admins der Firma und die einreichende Person (`can_decide_change`).
    - Wird der Antrag freigegeben, steht der Auftrag wieder auf `in_bearbeitung`. Wird er abgelehnt, bleibt der Auftrag abgeschlossen.
  - Offboarding, Stilllegung und Reaktivierung: höchstens ein offener Auftrag je Personalnummer und Typ.
  - Onboarding: höchstens ein nicht stornierter Auftrag je Personalnummer.
- **Ausgehende Verbindungen** (SSRF-Schutz über `public_ip()`):
  - **SMTP:** Nur öffentliche Server, geprüft beim Speichern und bei jeder Verbindung. Provider kommen aus `MAIL_PROVIDERS`, Ports aus `SMTP_ALLOWED_PORTS`. Ohne Verschlüsselung ist keine Anmeldung erlaubt.
  - **OAuth:** PKCE S256. Der `state` gilt einmalig und 900 s.
  - **Linksammlung:**
    - nur http(s) auf den Ports 80, 443, 8080 und 8443
    - DNS-Pinning
    - höchstens 3 Weiterleitungen, jede wird erneut geprüft
    - `link_suggest`: höchstens 30 Aufrufe je Sitzung in 10 min, Laufzeit höchstens 25 s, Google-Favicons nur mit `links.google_favicons`
- **Uploads und CSV:**
  - Logo und Linkbilder: nur MIME-Typen aus einer festen Liste. Linkbilder bis 3 MB.
  - CSV-Import: Kunden bis 10 MB, Mitarbeitende bis 5 MB. Update-Pakete bis 50 MB.
  - Jeder CSV-Export nutzt `csv_cell_safe()` (Schutz gegen Formel-Injection). `csv_read()` macht das beim Import rückgängig.

## Side Effects

- **Schreiben bei GET:**
  - Downloads und Exporte schreiben in `audit_log`.
  - `verify_email` und `oauth_callback` schreiben Daten.
  - Der erste Aufruf legt Vorgaben an: `links_company_id()` den Reiter „Start“, `forms_company_id()` Briefkopf, Fußzeile und ein Beispielformular, genau einmal je Firma.
- **Dateien** (relativ zu `kundenportal/`):
  - Sicherungen in `app/data/backups/`
  - Update-Pakete in `app/data/updates/`. `apply` überschreibt die Programmdateien.
- **E-Mails:**
  - Aufträge: neu, Statuswechsel, Nachrichten, Änderungsantrag gestellt, freigegeben oder abgelehnt
  - Registrierung und Freischaltung
  - Passwort und 2FA
  - Testmail des Postfachs (direkt, ohne Ausweichen auf ein anderes Postfach)
- **Externe HTTP-Aufrufe:**
  - OAuth bei Microsoft und Google
  - öffentliche Webseiten für die Linksammlung
  - Google Apps Script für Drive-Sicherungen
- **Binärdaten in der Datenbank:** Logos und Linkbilder liegen base64-kodiert in der Datenbank. So sind Firmen-Sicherungen (JSON/CSV) vollständig, ohne Dateien. Nicht ins Dateisystem verlagern, ohne die Sicherung anzupassen.
- **Session:** Bei Anmeldung und Passwort-Reset wird `session_regenerate_id` aufgerufen.

## Bekannte Risiken und offene Entscheidungen

Stand 22.11.2026. Es gibt keinen Issue-Tracker, offene Punkte werden hier geführt.

- **Kontosperre über viele IPs:** Wer eine E-Mail-Adresse kennt und viele IPs nutzt, kann das Konto weiter sperren. Das ist bewusst in Kauf genommen.
- **IP-Begrenzungen:** Sie setzen voraus, dass kein Proxy oder CDN vor dem Portal sitzt (`REMOTE_ADDR`).
- **Schreiben beim Lesen:** Auch Konten, die nur ansehen dürfen, lösen beim ersten Aufruf das Anlegen der Vorgaben aus (siehe Side Effects).
- **Bild beim Speichern:** `link_edit` lädt das gewählte Vorschlagsbild beim Speichern erneut. Hat die Website es inzwischen geändert, wird das neue Bild gespeichert.
- **Fehlversuche im Protokoll:** `login_failed`-Einträge machen das Protokoll unter `audit` voller.
- **Keine automatisierten Tests:** siehe Verification.

## Verification

- Run: `<test-command>` (kein automatisierter Test im Repository). Einrichtung wie unter „Lokal testen“ in [../../README.md](../../README.md).
- Manuelle Grenzprüfungen nach Änderungen an Seiten:
  1. `GET ?r=form_action` → 400, `GET ?r=link_action` → 405.
  2. Als `firma_user`: `?r=customer_fields`, `?r=link_edit` und `?r=employees&export=csv` → jeweils 403.
  3. Als Admin mit ausgewählten Firmen: `?r=customers&company=<fremde ID>` → 403. Mit „nur ansehen“ für Formulare: Kunde speichern → 403.
  4. Anmeldung mit unbekannter Adresse und mit falschem Passwort → dieselbe Meldung.
  5. Freigegebener Auftrag: Status ändern per POST → 403. Änderungsantrag stellen, als `firma_admin` freigeben → „In Bearbeitung“.
  6. SMTP-Server `10.0.0.5` speichern → Fehlermeldung. `link_suggest` mit `http://127.0.0.1` → `ok: false`.
