# Kundenportal

Mandantenfähiges Webportal für Kundenfirmen eines IT-Dienstleisters. Nach der Anmeldung mit Passwort und Zwei-Faktor-Authentifizierung (Authenticator-App) sieht jede Firma eine Startseite mit den **Apps, die für sie freigeschaltet sind**.

Eingebaute Apps sind **On- & Offboarding**, **Formulare**, **Datensicherung** und **Linksammlung**. Über On- & Offboarding beauftragen die Firmen:

- **Onboarding:** neue Mitarbeitende einrichten
- **Offboarding:** Austritte abwickeln (Konto sperren, Geräte zurücknehmen, Lizenzen entziehen, Postfach und Daten regeln)
- **Stilllegung:** Zugänge bei längerer Abwesenheit (z. B. Elternzeit, Pflegezeit, Sabbatical) ruhen lassen
- **Reaktivierung:** bei der Rückkehr alles wieder bereitstellen

## Funktionen

### Portal
- **Startseite mit App-Kacheln**, abhängig von den Freigaben der Firma
- **Apps & Freigaben** (nur Administratoren): eine Übersicht, welche Firma welche App nutzen darf, mit Häkchen pro Firma und App. Die Freigaben lassen sich auch im Firmenprofil setzen.
- **Externe Apps:** Links zu anderen Anwendungen (z. B. Ticketsystem, Wissensdatenbank) mit Name, Symbol, Farbe und Beschreibung. Diese Anwendungen haben ihre eigene Anmeldung.
- **Rollen:**
  - **Superadmin** (genau einer): alles, was Verwaltungsadmins dürfen, und als Einziger die Verwaltung der Administratoren-Konten (anlegen, deaktivieren, löschen, Zugang zurücksetzen). Nur der Superadmin selbst kann seine Rolle an einen Verwaltungsadmin übertragen, bestätigt mit seinem Passwort. Danach ist er selbst Verwaltungsadmin.
  - **Verwaltungsadmin** (Dienstleister): sieht und pflegt Firmen, deren Benutzer, Apps, E-Mail-Versand und Aufträge – im Rahmen seiner **Rechte**: Der Superadmin legt je Verwaltungsadmin fest, ob er uneingeschränkt arbeitet oder je Bereich (Firmen, Benutzer, Apps & Freigaben, E-Mail-Versand, On- & Offboarding, Formulare, Linksammlung, Datensicherung, Protokoll, Updates) *kein Zugriff*, *ansehen* oder *bearbeiten* hat, und ob er **alle Firmen oder nur ausgewählte** betreut. Mit ausgewählten Firmen sieht er nur deren Daten; Firmenübergreifendes (neue Firmen, eigene Apps, zentrales Postfach, Portal-Sicherungen, Katalogeinträge für alle Firmen) bleibt Admins mit Zugriff auf alle Firmen vorbehalten. Bestehende Verwaltungsadmins sind zunächst uneingeschränkt. Änderungen der Rechte stehen im Protokoll.
  - **Firmen-Administrator:** pflegt Firmenprofil und Benutzer der eigenen Firma
  - **Firmen-Benutzer:** erstellt und verfolgt Aufträge
- **E-Mail-Versand:** zentrales Absender-Postfach und auf Wunsch ein eigenes Postfach je Firma (SMTP mit Anmeldung, Microsoft 365 oder Gmail/Google Workspace per OAuth2). Benachrichtigungen an eine Firma laufen über deren Postfach, Mails an die Administratoren über das zentrale. Fällt ein Postfach aus, springt automatisch das nächste ein (Firma → zentral → Mailfunktion des Webhosters). Zugangsdaten werden verschlüsselt gespeichert, und für jedes Postfach gibt es einen Knopf für eine Testmail.
- **Mitarbeiterliste (On- & Offboarding → Mitarbeiter):** Alle Mitarbeitenden einer Firma mit Status (Eintritt geplant, Aktiv, Stillgelegt, Ausgeschieden). Wird automatisch aus den Aufträgen gepflegt: Ein offenes Onboarding legt den Eintrag als „geplant“ an, abgeschlossene Aufträge setzen den Status. Bestehende Mitarbeitende lassen sich von Hand anlegen oder per CSV importieren (Personalnummer, Vorname, Nachname; optional Abteilung, Position, E-Mail, Eintritt, Status …), Export als CSV. Aus der Liste startet man Offboarding, Stilllegung oder Reaktivierung mit allen Daten und der Ausstattung aus dem letzten Auftrag. Pflegen, importieren und als CSV exportieren dürfen Firmen-Admins und der Dienstleister, ansehen und Aufträge starten alle mit der App.
- **Selbstregistrierung:** Firmen registrieren sich über „Firma registrieren“ auf der Anmeldeseite (Firmendaten, Ansprechpartner/in, Passwort, gewünschte Module). Die Firma bekommt eine E-Mail mit Bestätigungslink, der Dienstleister (`mail.admin_recipients` und Superadmin) eine Benachrichtigung. Nach Bestätigung der E-Mail-Adresse meldet sich die Firma an und richtet die 2FA ein, sieht aber bis zur Freischaltung nur einen Hinweis. Unter **Firmen** steht „Freischaltung offen“; im Firmenprofil werden die angefragten Module geprüft, freigeschaltet oder die Registrierung abgelehnt. Die Firma erhält jeweils eine E-Mail. Schutz gegen Spam: Honeypot, Mindestdauer und Begrenzung je IP-Adresse (`registration.max_per_hour`).
- **Passwort vergessen:** Link per E-Mail (60 Minuten gültig, nur einmal verwendbar). Die 2FA bleibt bestehen. Ob eine Adresse existiert, wird nicht verraten.
- **2FA verloren:** Auf der Code-Seite lässt sich das Zurücksetzen beantragen. Der Superadmin bekommt eine E-Mail und einen Hinweis auf der Startseite; **nur der Superadmin** kann die 2FA zurücksetzen. Der Benutzer wird per E-Mail informiert.
- **Sicherheit:** 2FA (TOTP) mit QR-Code, der auf dem eigenen Server erzeugt wird. Außerdem Sperre nach Fehlversuchen, CSRF-Schutz, strikte Mandantentrennung, Protokoll und erzwungene Passwortänderung beim ersten Login.

### App „On- & Offboarding“
- **Onboarding:** Name, Mitarbeiternummer, Abteilung, Position, Eintrittsdatum, Standort, Vorgesetzte/r, Wunsch-E-Mail, Geräte, Lizenzen, Programme, Netzlaufwerke und Freigaben, dazu Bemerkungen und Freitext-Zusatzwünsche
- **Offboarding:** Austrittsdatum, Zeitpunkt der Kontosperre, Postfach (sperren, freigeben, weiterleiten, löschen), Nachfolger/in, Abwesenheitsnotiz, persönliche Dateien, Rufnummer sowie eine Rückgabe- und Entzugsliste
- **Stilllegung:** Beginn, Grund (Auswahlliste, ohne Gesundheitsdaten), geplante Rückkehr, Vertretung, Abwesenheitsnotiz; Lizenzen und Zugänge werden pausiert statt gelöscht. Bei Geräten kann vermerkt werden, ob sie bei der Person bleiben.
- **Reaktivierung:** übernimmt Ausstattung und Rückkehrdatum aus der Stilllegung
- **Folgeaufträge mit einem Klick:** Aus einem abgeschlossenen Auftrag lassen sich Offboarding, Stilllegung oder Reaktivierung starten. Person und Ausstattung werden übernommen, die Aufträge sind miteinander verknüpft.
- **Boarding durch den IT-Service:** Superadmin und Verwaltungsadmins legen für jede aktive Firma selbst Aufträge an, auch ohne App-Freigabe der Firma. Diese Aufträge starten direkt „In Bearbeitung“, die Benachrichtigung der Firma ist wählbar, und mit „Alles erledigt und abschließen“ ist ein Auftrag in einem Schritt erledigt.
- **Automatische Aufgaben** in der Checkliste, z. B. Konto sperren zum Zeitpunkt X, Postfach umwandeln, Wiedervorlage vor der Rückkehr
- **Kataloge und Vorlagen:** Einträge gelten für alle Firmen oder nur für eine. Für jede Abteilung und Position lässt sich hinterlegen, was vorausgewählt wird.
- **Workflow:** Eingereicht → In Bearbeitung → Rückfrage → Eingerichtet → Freigegeben (oder Storniert). Dazu gibt es eine Checkliste, Nachrichten, interne Notizen und eine Druckansicht.
- **Änderungsantrag:** Freigegebene und stornierte Aufträge kann der IT-Service nicht mehr direkt ändern. Er stellt stattdessen einen Änderungsantrag mit Begründung. Die Firma bekommt eine E-Mail. Ein Firmen-Administrator oder die Person, die den Auftrag eingereicht hat, gibt den Antrag frei; dann ist der Auftrag wieder „In Bearbeitung“. Oder sie lehnt ihn ab, dann bleibt der Auftrag abgeschlossen. Offene Anträge sind in der Auftragsliste markiert.
- **E-Mail-Benachrichtigungen** bei neuen Aufträgen, Antworten und Statuswechseln

### App „Formulare“
- **Editor wie Word:** Vollbild mit Menüband (Start, Einfügen, Felder, Layout, Tabelle), Schriftart und -größe in pt, Fett/Kursiv/Unterstrichen, Hoch-/Tiefstellung, Schrift- und Markierfarbe, Ausrichtung, Einzüge, Zeilen- und Absatzabstand, Formatvorlagen, Listen, Bilder, Links, Symbole, Seitenumbruch und Unterschriftsfeld. A4-Seite mit Lineal, Seitenrändern, Grundschrift, Zoom, Seiten- und Wortzähler, Speichern mit Strg+S. Per Doppelklick auf Briefkopf oder Fußzeile lässt sich direkt auf der Seite die Vorlage wechseln, der Inhalt bearbeiten oder als neue Vorlage speichern.
- **Rechentabellen wie Excel:** Zellbezüge (A1, B2:B9), Formelleiste mit Zellname, Spalten-/Zeilenköpfe, Formeln mit `=SUMME`, `MITTELWERT`, `MIN`, `MAX`, `ANZAHL`, `RUNDEN`, `AUFRUNDEN`, `ABRUNDEN`, `WENN`, `UND`, `ODER`, `ABS`, `PRODUKT`, Prozent und Rechenoperatoren; Zahlenformate (Zahl, Ganzzahl, Euro, Prozent), AutoSumme, Zellen markieren/verbinden/teilen, Spaltenbreite mit der Maus ziehen, Rahmen und Schattierung, Einfügen von Bereichen aus Excel. Beim Einfügen von Zeilen passen sich Formeln an (z. B. `=SUMME(E2:E4)` → `E2:E5`). Vorlagen: Positionen mit MwSt., Stundenzettel, leeres Rechenblatt.
- **Ordner** zum Sortieren, beliebig verschachtelt, mit Suche. Formulare lassen sich duplizieren, verschieben und löschen.
- **Platzhalter** für Daten aus der Kundendatenbank (`{{kunde.nachname}}`, `{{kunde.anschrift}}`, `{{kunde.briefanrede}}` …), der eigenen Firma (`{{firma.name}}`, `{{firma.iban}}` …), Datum, Ersteller und Logo
- **Ausfüllfelder:** Eingabefeld, mehrzeiliges Textfeld, Zahl, Datum (mit Kalender), Ankreuzfeld und Auswahlliste; in Tabellen zusätzlich Eingabezellen und „Zeilen ergänzbar“
- **Kundendatenbank je Firma** mit Standardfeldern und beliebig vielen eigenen Feldern. Jedes Feld ist sofort als Platzhalter verfügbar.
- **CSV-Import und -Export:** Trennzeichen und Zeichensatz (UTF-8 oder Windows/Excel) werden erkannt, gängige Spaltennamen automatisch zugeordnet, vorhandene Kunden über die Kundennummer aktualisiert. Unbekannte Spalten können als eigene Felder angelegt werden.
- **API-Schnittstelle (REST/JSON)** mit Schlüsseln je Firma, nur lesend oder mit Schreibrecht (siehe unten)
- **Logo, Briefkopf und Fußzeile:** Logo hochladen, Bank- und Pflichtangaben pflegen, mehrere Briefköpfe und Fußzeilen anlegen und je Formular auswählen. Vorlagen: Briefkopf klassisch nach DIN 5008 (Logo rechts, Anschriftfeld), modern, zentriert, schlicht; Fußzeile dreispaltig, einzeilig oder mit Pflichtangaben.
- **Ausfüllen direkt im Dokument:** Das Formular erscheint als A4-Seite, Felder und Eingabezellen werden an Ort und Stelle ausgefüllt, Rechentabellen rechnen sofort, Zeilen lassen sich ergänzen. Kunden auswählen (mehrere = Serienbrief, je Kunde eine Seite, Eingaben gelten für alle), Datum wählen, drucken oder als PDF speichern. Leere Felder erscheinen im Ausdruck als Linie zum Ausfüllen von Hand.
- **Rechte:** Alle Benutzer einer Firma mit Freigabe nutzen Formulare und Kundendatenbank. Logo, Briefkopf/Fußzeile, eigene Felder, CSV-Import/-Export und API-Schlüssel verwalten Firmen-Administratoren und Dienstleister.

### App „Linksammlung“
- **Intranet-Startseite im Stil von SharePoint** je Firma: **Reiter** oben (z. B. Start, Personal, IT), darauf **Überschriften/Kategorien** und darunter die **Links als Kacheln mit Bild, Titel und Beschreibung**
- **Darstellung je Überschrift:** Kacheln mit Bild (16:9), kompakte Kacheln (Bild/Symbol links) oder Liste
- **Links:** Webadressen (`www.…` wird automatisch zu `https://…`), E-Mail-Adressen und Telefonnummern (`tel:`); wahlweise in neuem Fenster. Ohne Bild zeigt die Kachel ein Symbol (Emoji oder Buchstaben) in einer von acht Farben.
- **Bildvorschläge:** Nach Eingabe der Adresse holt das Portal von der Webseite Vorschaubild (Open Graph/Twitter), App-Symbol, Favicon und große Bilder der Seite und zeigt sie zum Anklicken an. Leere Felder für Titel und Beschreibung werden ebenfalls vorgeschlagen. Abgerufen werden nur öffentliche Adressen (keine internen Netze, jede Weiterleitung wird geprüft); nötig ist die PHP-Erweiterung curl.
- **Einheitlicher Bildrahmen:** Alle Bilder erscheinen im gleichen 16:9-Rahmen mit weißem Hintergrund, wahlweise „Einpassen“ (ganzes Bild, ideal für Logos) oder „Ausfüllen“ (für Fotos/Screenshots)
- **Bilder** (PNG, JPG, GIF, WebP bis 3 MB) werden in der Datenbank gespeichert, passend zum Rahmen auf höchstens 800 × 450 px verkleinert (wenn GD vorhanden) und nur an angemeldete Benutzer der Firma ausgeliefert
- **Bearbeiten-Modus** („Seite bearbeiten“): Reiter anlegen, umbenennen, verschieben, löschen; Überschriften anlegen, bearbeiten, auf andere Reiter verschieben; Reihenfolge mit ◀ ▶ / ▲ ▼
- **Suche** über alle Reiter (Titel, Beschreibung, Adresse, Überschrift)
- **Rechte:** Alle Benutzer einer Firma mit Freigabe sehen die Seite. Bearbeiten dürfen Firmen-Administratoren und der Dienstleister (wählt die Firma oben aus).
- Die Daten sind Teil der Firmen-Datensicherung.

### API der Kundendatenbank

Schlüssel werden in der App unter **Logo, Briefpapier & API** erzeugt (nur einmal angezeigt, gespeichert wird nur ein Hash). Aufruf mit `Authorization: Bearer kp_…` oder `X-API-Key: kp_…`.

| Aufruf | Bedeutung |
|---|---|
| `GET /api.php/kunden?suche=&seite=1&pro_seite=100&geaendert_seit=2026-01-01` | Kunden auflisten |
| `GET /api.php/kunden/{id}` | ein Kunde |
| `GET /api.php/kunden/nummer/{kundennummer}` | Kunde über die Kundennummer |
| `POST /api.php/kunden` | anlegen; mit vorhandener `nummer` aktualisieren. Ein Objekt oder eine Liste (max. 1000) |
| `PATCH /api.php/kunden/{id}` | einzelne Felder ändern (`PUT` ersetzt alle Felder) |
| `DELETE /api.php/kunden/{id}` | löschen |
| `GET /api.php/felder` | alle Felder mit Bezeichnung |

Feldnamen: `nummer, firma, anrede, titel, vorname, nachname, strasse, plz, ort, land, email, telefon, notiz` und die Namen der eigenen Felder. Erlaubt der Server keine Pfade hinter `api.php`, funktioniert auch `api.php?pfad=kunden/12`.

## Voraussetzungen

- Webhosting mit **PHP 8.1 oder neuer** (PDO/pdo_mysql, mbstring, openssl, dom, zip für die update.php), wie bei IONOS, Strato, All-Inkl. und ähnlichen Hostern üblich
- eine **MySQL- oder MariaDB-Datenbank**
- **HTTPS**
- **Kein Reverse Proxy oder CDN davor**, also kein Cloudflare o. Ä. Die Begrenzungen pro IP (Anmeldung, Registrierung, Passwort vergessen) lesen die Client-Adresse aus `REMOTE_ADDR`. Hinter einem Proxy hätten alle Benutzer dieselbe Adresse, und die Begrenzungen würden für alle gemeinsam gelten.

Composer und ein Kommandozeilenzugang sind nicht nötig.

## Installation

1. Den Inhalt des Ordners `kundenportal/` per FTP hochladen, z. B. nach `/portal`.
2. Im Kundenmenü des Hosters eine MySQL-Datenbank anlegen.
3. `config.sample.php` nach `config.php` kopieren. Dort Datenbankzugang, `base_url`, den Portalnamen, Absender und Empfänger für E-Mails eintragen.
4. `https://ihre-domain.de/portal/install.php` aufrufen und den Superadmin anlegen.
5. **`install.php` vom Server löschen.**
6. Anmelden und die Authenticator-App einrichten.
7. Unter **Firmen** die Kundenfirmen anlegen und unter **Apps & Freigaben** festlegen, welche Firma welche App bekommt. Danach je Firma einen Firmen-Administrator anlegen; das Startpasswort wird einmalig angezeigt.

`app/` und die Konfiguration sind per `.htaccess` vor direktem Zugriff geschützt (Apache). Auf nginx-Servern muss der Zugriff auf `app/` und `config.php` in der Serverkonfiguration gesperrt werden.

## E-Mail-Versand per Microsoft 365 / Google einrichten

1. **Microsoft:** Im Microsoft Entra Admin Center unter *App-Registrierungen* eine neue Anwendung anlegen. Kontotyp: *Konten in einem beliebigen Organisationsverzeichnis*. Als Umleitungs-URI (Web) `https://<portal>/index.php?r=oauth_callback` eintragen. Delegierte API-Berechtigungen: `Mail.Send`, `User.Read`, `offline_access`. Dann einen geheimen Clientschlüssel erzeugen.
2. **Google:** In der Google Cloud Console die Gmail API aktivieren, den OAuth-Zustimmungsbildschirm mit dem Bereich `gmail.send` einrichten und einen OAuth-Client vom Typ *Webanwendung* mit derselben Weiterleitungs-URI anlegen.
3. Client-ID und Schlüssel im Portal unter **E-Mail-Versand → Zentral → Anwendungsdaten** eintragen.
4. Danach kann jede Firma unter **E-Mail-Versand** „Mit Microsoft anmelden“ bzw. „Mit Google anmelden“ wählen. Das Portal bekommt nur das Recht zum Senden, nicht zum Lesen.

Die Zugangsdaten werden mit einem Schlüssel in `app/data/secret.key` verschlüsselt. Diese Datei gehört in die Datensicherung. Geht sie verloren, müssen alle Postfächer neu verbunden werden.

## Datensicherung

Unter **Datensicherung** (Superadmin und Verwaltungsadmins):

- **Jetzt sichern:** komplette Datenbank als `database.sql` plus `secret.key` (Schlüssel für die Postfach-Zugangsdaten) und `config.php` in einem ZIP-Archiv. Liste mit Download und Löschen.
- **Täglich automatisch** über einen Cronjob, der `cron.php` aufruft. Gesichert wird höchstens einmal pro Tag. Ältere automatische Sicherungen werden gelöscht (Anzahl einstellbar, Standard 14), manuelle bleiben.
- **Optional verschlüsselt** (AES-256-ZIP, z. B. mit 7-Zip zu öffnen) und **E-Mail an die Administratoren**, wenn eine automatische Sicherung fehlschlägt.
- **Google Drive (optional):** Jede neue Sicherung wird zusätzlich in einen Drive-Ordner hochgeladen (Standard „Kundenportal-Backups“, ältere automatische Sicherungen dort nach einstellbarer Anzahl gelöscht). Ohne Google Cloud Console: Das Portal zeigt den Code für ein Google Apps Script mit eigenem Schlüssel; als Web-App bereitstellen (Ausführen als „Ich“, Zugriff „Jeder“) und die Web-App-URL im Portal einfügen. Größe je Sicherung bis 35 MB.
- Ablage in `app/data/backups/` (per `.htaccess` gesperrt). Regelmäßig eine Sicherung herunterladen, damit sie auch außerhalb des Webspace liegt.

**App „Datensicherung“ für Firmen:** Unter Apps & Freigaben je Firma freischalten. Firmen-Administratoren sichern dann die Daten ihrer Firma selbst (Firmenprofil, Benutzer ohne Passwörter, Aufträge, Formulare, Briefköpfe, Logo, Kundendatenbank) als ZIP mit `daten.json` und `kunden.csv`, auf Wunsch täglich und automatisch ins eigene Google Drive (eigenes Apps Script mit eigenem Schlüssel je Firma). Die Sicherungen liegen zusätzlich unter `app/data/backups/firma-<ID>/`. Die tägliche Sicherung läuft über denselben Cronjob wie die Portalsicherung.

**Cronjob bei IONOS (1&1):**

- Im IONOS-Konto unter Hosting › Cronjobs einen täglichen Cronjob mit *HTTP GET* auf `https://ihre-domain.de/portal/cron.php?token=…` anlegen. Die URL mit Schlüssel steht im Portal unter „Datensicherung“.
- Oder per SSH (`crontab -e`): `0 3 * * * /usr/bin/php8.3 -f /pfad/zum/portal/cron.php`

**Wiederherstellen:** `config.php` und `app/data/secret.key` aus dem ZIP zurückspielen, `database.sql` per phpMyAdmin importieren (ersetzt vorhandene Tabellen). Eine `LIESMICH.txt` im Archiv beschreibt die Schritte.

## Updates

Am einfachsten mit der **update.php**: in den Portal-Ordner hochladen und im Browser aufrufen. Sie ersetzt die Programmdateien, behält `config.php` und `app/data/` und legt sich danach unter **Administration → Updates** ab. Alternativ die neuen Dateien von Hand über die alten hochladen und dabei `config.php` und `app/data/` behalten. Beim nächsten Aufruf bringt das Portal die Datenbank automatisch auf den neuen Stand. Wer schon eine Version ohne App-Freigaben im Einsatz hatte, bekommt das Onboarding für alle bestehenden Firmen automatisch freigeschaltet.

## Eine weitere interne App ergänzen

1. Seiten der App in `app/pages/<app>.php` anlegen und am Anfang jeder Seite `require_app('<slug>')` aufrufen
2. Die Routen in `index.php` eintragen
3. Die App in `BUILTIN_APPS` (in `app/schema.php`) ergänzen und `SCHEMA_VERSION` erhöhen; sie wird dann beim nächsten Aufruf registriert
4. Optional die Unternavigation der App in `app/views/layout.php` ergänzen

### Seite „Updates“ (Administration)

Archiv aller Update-Pakete mit Datum, Autor und Zusammenfassung. Pakete liegen in `app/data/updates/` (gesperrt per `.htaccess`). Superadmin und Verwaltungsadmins sehen die Liste und laden Pakete herunter; nur der Superadmin legt Pakete ab, bearbeitet die Angaben, löscht sie oder spielt sie direkt im Portal ein (Bestätigung mit Passwort). Ältere Pakete ohne eingebettete Angaben lassen sich hochladen und von Hand beschreiben.

## Update-Paket bauen

```bash
php tools/build-update.php --author="Name" --summary="Was ist neu"   # erzeugt build/update.php aus dem Ordner kundenportal/
```

## Lokal testen

```bash
cp config.sample.php config.php   # dort 'driver' => 'sqlite' und mail 'enabled' => false setzen
php -S 127.0.0.1:8080
# http://127.0.0.1:8080/install.php aufrufen
```

Ist der Mailversand deaktiviert, landen die E-Mails in `app/data/mail.log`.

## Datensicherung

Sichern Sie regelmäßig die Datenbank (Backup-Funktion des Hosters) und die Datei `config.php`.

---

<!-- EGM-Vertrag: die folgenden Abschnitte beschreiben die Code-Grenzen dieses Pakets. Bitte bei Änderungen an Routen, Rechten oder Tabellen mitpflegen. -->

## Interface

- **Unterverzeichnisse:**
  - [app/README.md](./app/README.md): Kernbibliothek (Rechte, Schema, Mail, Sicherung, Formulare, Updates)
  - [app/pages/README.md](./app/pages/README.md): Seiten-Handler
  - [app/views/README.md](./app/views/README.md): Layout
  - [assets/README.md](./assets/README.md): Browser-Skripte
- **`index.php`**: Front-Controller.
  - `?r=<route>` wird über `$routes` an `page_*`-Funktionen weitergegeben. Ohne `r` geht es zu `home` bzw. `login`, eine unbekannte Route ergibt 404 `[index.php:$routes]`.
  - `ROUTE_AREAS` ordnet jeder Verwaltungsroute einen Bereich (`companies`, `users`, `apps`, `mail`, `audit`, `updates`, `backups`, `onboarding`, `forms`, `links`) und eine Mindeststufe (`PERM_VIEW`/`PERM_EDIT`) zu `[index.php:ROUTE_AREAS]`. Routen ohne Bereich stehen in `ROUTES_WITHOUT_AREA`. Jede andere Route ergibt für Verwaltungsadmins 403.
- **`api.php`**: REST/JSON-API der Kundendatenbank `[api.php]`. Der Pfad kommt aus `PATH_INFO` oder `?pfad=`/`?path=`.
  - `GET /`
  - `GET /felder`
  - `GET|POST /kunden`
  - `GET|PUT|PATCH|DELETE /kunden/{id}`
  - `…/kunden/nummer/{nr}`
- **`cron.php`**: tägliche Sicherung `backup_daily($force)` `[cron.php]`. Aufruf per HTTP mit `?token=<backup_cron_token>[&force=1]` oder per CLI mit `php -f cron.php [--force]`.
- **`install.php`**: einmalige Einrichtung `[install.php]`. Legt das Schema an (`run_migrations`), erzeugt den Superadmin, füllt den Katalog vor und schreibt `app/data/installed.lock`.
- **`config.sample.php`**: Vorlage für `config.php`. Schlüssel:
  - `app_name`, `base_url`
  - `db.{driver, host, port, name, user, password, sqlite_path}`
  - `mail.{enabled, from, from_name, admin_recipients}`
  - `registration.{enabled, privacy_url, max_per_hour}`
  - `totp_issuer`, `session_lifetime_minutes`, `max_login_attempts`, `max_login_attempts_ip`, `lockout_minutes`, `timezone`
  - optional: `mail.smtp_allow_private` (nur zum Testen) und `links.google_favicons` (Standard aus)

## Invariants

- Ohne `app/data/installed.lock` leitet `index.php` zu `install.php` weiter. `api.php` antwortet dann mit 503. `cron.php` antwortet per HTTP mit 503, per CLI gibt es nur eine Textmeldung aus. `install.php` ist nach dem Anlegen der Datei gesperrt.
- **Sicherheitsheader** `[index.php]`, `[index.php:$styleSrc]`:
  - `X-Frame-Options DENY`, `X-Content-Type-Options nosniff`, `Referrer-Policy same-origin`
  - CSP mit `default-src 'self'; img-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'`
  - `style-src 'unsafe-inline'` nur für `form_edit`, `form_layouts`, `form_use` und `form_print`
- Für Verwaltungsadmins verlangt `index.php` vor dem Handler `require_area(<bereich>, is_post() || PERM_EDIT)`. Jeder POST braucht also das Schreibrecht. Rechte für Firmenrollen prüfen die Seiten selbst.
- **API** `[api.php:auth]`, `[api.php:$canWrite]`:
  - Schlüssel kommt per `Authorization: Bearer …` oder `X-API-Key`. Ein ungültiger Schlüssel ergibt 401 nach 300 ms Verzögerung.
  - Die Firma muss aktiv sein und die App `formulare` haben.
  - Schreibende Methoden brauchen `can_write=1`.
  - Grenzen: Anfragekörper bis 5 MiB, `pro_seite` 1–500, höchstens 1000 Kunden je POST.
  - Unbekannte Felder ergeben 422, eine doppelte Kundennummer 409.
- **Cron:** Per HTTP muss der Token per `hash_equals` gegen `backup_cron_token` passen. Per CLI ist kein Token nötig `[cron.php]`.
- **Webserver:** `.htaccess` sperrt `config.php`, `config.sample.php`, `README.md`, `*.sqlite`, `*.log` und `*.lock`, erzwingt HTTPS und reicht `Authorization` an PHP weiter. `app/.htaccess` und `app/data/.htaccess` enthalten `Require all denied`.
- Ein Datenbankfehler (`PDOException`) im Handler wird protokolliert und mit einer allgemeinen 500-Seite beantwortet `[index.php]`.
- Weitere Regeln zu Rechten, Mandantentrennung und Workflow: siehe [app/README.md](./app/README.md) und [app/pages/README.md](./app/pages/README.md).

## Side Effects

- Bei jeder Anfrage laufen `ensure_schema()` (ggf. Migration) und `start_session()` `[index.php]`.
- **API** `[api.php]`:
  - `api_tokens.last_used_at` wird höchstens einmal pro Minute aktualisiert.
  - Schreibzugriffe auf `customers` mit `audit()`: `api_customers_saved`, `api_customer_updated`, `api_customer_deleted`.
- **Cron** `[cron.php]`:
  - Per HTTP mit `set_time_limit(900)`. Gibt es `fastcgi_finish_request` (PHP-FPM), antwortet das Skript sofort mit `OK` und sichert im Hintergrund weiter. Sonst läuft die Sicherung synchron und die Antwort ist das Ergebnis von `backup_daily()`.
  - Schreibt Sicherungen nach `app/data/backups/` und lädt sie optional zu Google Drive hoch.
- **Installation** `[install.php]`: schreibt `users`, `catalog_items` und `app/data/installed.lock`.
- Weitere Effekte (Mails, OAuth, Drive, Dateien unter `app/data/`): siehe [app/README.md](./app/README.md).

## Verification

- Run: `<test-command>` (kein automatisierter Test im Repository; Rauchtest wie unter „Lokal testen“: `php -S 127.0.0.1:8080`, dann `install.php` und Anmeldung durchspielen)
