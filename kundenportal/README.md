# Kundenportal

Mandantenfähiges Webportal für Kundenfirmen eines IT-Dienstleisters. Nach der Anmeldung mit Passwort und Zwei-Faktor-Authentifizierung (Authenticator-App) sieht jede Firma eine Startseite mit den **Apps, die für sie freigeschaltet sind**.

Erste eingebaute App ist **Onboarding**. Darüber melden die Firmen neue Mitarbeitende zur Einrichtung von Arbeitsplatz, Zugängen und Freigaben.

## Funktionen

### Portal
- **Startseite mit App-Kacheln**, abhängig von den Freigaben der Firma
- **Apps & Freigaben** (nur Administratoren): eine Übersicht, welche Firma welche App nutzen darf, mit Häkchen pro Firma und App. Die Freigaben lassen sich auch im Firmenprofil setzen.
- **Externe Apps:** Links zu anderen Anwendungen (z. B. Ticketsystem, Wissensdatenbank) mit Name, Symbol, Farbe und Beschreibung. Diese Anwendungen haben ihre eigene Anmeldung.
- **Rollen:**
  - Administrator (Dienstleister, sieht und pflegt alle Firmen)
  - Firmen-Administrator (pflegt Firmenprofil und Benutzer der eigenen Firma)
  - Firmen-Benutzer
- **Sicherheit:** 2FA (TOTP) mit QR-Code, der auf dem eigenen Server erzeugt wird. Außerdem Sperre nach Fehlversuchen, CSRF-Schutz, strikte Mandantentrennung, Protokoll und erzwungene Passwortänderung beim ersten Login.

### App „Onboarding“
- **Auftrag:** Name, Mitarbeiternummer, Abteilung, Position, Eintrittsdatum, Standort, Vorgesetzte/r, Wunsch-E-Mail, Geräte, Lizenzen, Programme, Netzlaufwerke und Freigaben, dazu Bemerkungen und Freitext-Zusatzwünsche
- **Kataloge und Vorlagen:** Einträge gelten für alle Firmen oder nur für eine. Für jede Abteilung und Position lässt sich hinterlegen, was vorausgewählt wird.
- **Workflow:** Eingereicht → In Bearbeitung → Rückfrage → Eingerichtet → Freigegeben (oder Storniert). Dazu gibt es eine Checkliste, Nachrichten, interne Notizen und eine Druckansicht.
- **E-Mail-Benachrichtigungen** bei neuen Aufträgen, Antworten und Statuswechseln

## Voraussetzungen

- Webhosting mit **PHP 8.1 oder neuer** (PDO/pdo_mysql, mbstring, openssl), wie bei IONOS, Strato, All-Inkl. und ähnlichen Hostern üblich
- eine **MySQL- oder MariaDB-Datenbank**
- **HTTPS**

Composer und ein Kommandozeilenzugang sind nicht nötig.

## Installation

1. Den Inhalt des Ordners `kundenportal/` per FTP hochladen, z. B. nach `/portal`.
2. Im Kundenmenü des Hosters eine MySQL-Datenbank anlegen.
3. `config.sample.php` nach `config.php` kopieren. Dort Datenbankzugang, `base_url`, den Portalnamen, Absender und Empfänger für E-Mails eintragen.
4. `https://ihre-domain.de/portal/install.php` aufrufen und den ersten Administrator anlegen.
5. **`install.php` vom Server löschen.**
6. Anmelden und die Authenticator-App einrichten.
7. Unter **Firmen** die Kundenfirmen anlegen und unter **Apps & Freigaben** festlegen, welche Firma welche App bekommt. Danach je Firma einen Firmen-Administrator anlegen; das Startpasswort wird einmalig angezeigt.

`app/` und die Konfiguration sind per `.htaccess` vor direktem Zugriff geschützt (Apache). Auf nginx-Servern muss der Zugriff auf `app/` und `config.php` in der Serverkonfiguration gesperrt werden.

## Updates

Neue Dateien einfach über die alten hochladen und dabei `config.php` und `app/data/` behalten. Beim nächsten Aufruf bringt das Portal die Datenbank automatisch auf den neuen Stand. Wer schon eine Version ohne App-Freigaben im Einsatz hatte, bekommt das Onboarding für alle bestehenden Firmen automatisch freigeschaltet.

## Eine weitere interne App ergänzen

1. Seiten der App in `app/pages/<app>.php` anlegen und am Anfang jeder Seite `require_app('<slug>')` aufrufen
2. Die Routen in `index.php` eintragen
3. Die App in `BUILTIN_APPS` (in `app/schema.php`) ergänzen und `SCHEMA_VERSION` erhöhen; sie wird dann beim nächsten Aufruf registriert
4. Optional die Unternavigation der App in `app/views/layout.php` ergänzen

## Lokal testen

```bash
cp config.sample.php config.php   # dort 'driver' => 'sqlite' und mail 'enabled' => false setzen
php -S 127.0.0.1:8080
# http://127.0.0.1:8080/install.php aufrufen
```

Ist der Mailversand deaktiviert, landen die E-Mails in `app/data/mail.log`.

## Datensicherung

Sichern Sie regelmäßig die Datenbank (Backup-Funktion des Hosters) und die Datei `config.php`.
