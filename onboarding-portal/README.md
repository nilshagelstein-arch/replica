# Onboarding-Portal

Mandantenfähiges Webportal, über das Kundenfirmen neue Mitarbeitende als Onboarding-Auftrag an den IT-Dienstleister senden.
Die Anmeldung erfolgt mit Passwort und Zwei-Faktor-Authentifizierung (Authenticator-App).

## Funktionen

- **Rollen:** Administrator (Dienstleister, sieht und pflegt alle Firmen), Firmen-Administrator (pflegt die eigene Firma), Firmen-Benutzer (erstellt und verfolgt Aufträge)
- **Firmenprofil:** Adresse, Ansprechpartner, E-Mail-Domain und IT-Hinweise pflegt jede Firma selbst
- **Onboarding-Auftrag:** Name, Mitarbeiternummer, Abteilung, Position, Eintrittsdatum, Standort, Vorgesetzte/r, Wunsch-E-Mail, Geräte, Lizenzen, Programme, Netzlaufwerke und Freigaben, dazu Bemerkungen und Freitext-Zusatzwünsche
- **Kataloge und Vorlagen:** Einträge gelten für alle Firmen oder nur für eine. Für jede Abteilung und Position lässt sich hinterlegen, was vorausgewählt wird.
- **Workflow:** Eingereicht → In Bearbeitung → Rückfrage → Eingerichtet → Freigegeben (oder Storniert). Dazu gibt es eine Checkliste je Position, Nachrichten, interne Notizen und eine Druckansicht.
- **E-Mail-Benachrichtigungen** bei neuen Aufträgen, Antworten und Statuswechseln
- **Sicherheit:** 2FA (TOTP) mit QR-Code, der auf dem eigenen Server erzeugt wird. Außerdem Sperre nach Fehlversuchen, CSRF-Schutz, strikte Mandantentrennung, Protokoll und erzwungene Passwortänderung beim ersten Login.

## Voraussetzungen

- Webhosting mit **PHP 8.1 oder neuer** (Erweiterungen PDO/pdo_mysql, mbstring, openssl), wie es bei IONOS, Strato, All-Inkl. und ähnlichen Hostern Standard ist
- eine **MySQL- oder MariaDB-Datenbank**
- **HTTPS** (SSL-Zertifikat, bei den meisten Hostern kostenlos)

Composer und ein Kommandozeilenzugang sind nicht nötig.

## Installation

1. Den Inhalt des Ordners `onboarding-portal/` per FTP in ein Verzeichnis der Homepage hochladen, z. B. `/onboarding`.
2. Im Kundenmenü des Hosters eine MySQL-Datenbank anlegen.
3. `config.sample.php` nach `config.php` kopieren. Dort Datenbankzugang, `base_url`, Absender und Empfänger für E-Mails eintragen.
4. `https://ihre-domain.de/onboarding/install.php` aufrufen und den ersten Administrator anlegen.
5. **`install.php` vom Server löschen.**
6. Anmelden und die Authenticator-App einrichten (Microsoft oder Google Authenticator, FreeOTP …).
7. Unter **Firmen** die Kundenfirmen anlegen und je Firma einen Firmen-Administrator. Das Startpasswort wird einmalig angezeigt.

Der Ordner `app/` und die Konfiguration sind per `.htaccess` vor direktem Zugriff geschützt (Apache). Auf nginx-Servern muss der Zugriff auf `app/` und `config.php` in der Serverkonfiguration gesperrt werden.

## Einrichtung pro Firma

1. **Abteilungen & Positionen** anlegen
2. Im **Katalog** firmeneigene Netzlaufwerke (z. B. `S: Gemeinsam` mit Pfad `\\server\freigabe`) und Freigaben ergänzen
3. Unter **Vorlagen** je Abteilung und Position die Standardausstattung festlegen

## Lokal testen

```bash
cp config.sample.php config.php   # dort 'driver' => 'sqlite' und mail 'enabled' => false setzen
php -S 127.0.0.1:8080
# http://127.0.0.1:8080/install.php aufrufen
```

Ist der Mailversand deaktiviert, landen die E-Mails in `app/data/mail.log`.

## Datensicherung

Sichern Sie regelmäßig die Datenbank (Backup-Funktion des Hosters) und die Datei `config.php`.
