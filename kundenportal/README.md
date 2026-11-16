# Kundenportal

Mandantenfähiges Webportal für Kundenfirmen eines IT-Dienstleisters. Nach der Anmeldung mit Passwort und Zwei-Faktor-Authentifizierung (Authenticator-App) sieht jede Firma eine Startseite mit den **Apps, die für sie freigeschaltet sind**.

Erste eingebaute App ist **On- & Offboarding**. Darüber beauftragen die Firmen:

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
  - **Verwaltungsadmin** (Dienstleister): sieht und pflegt alle Firmen, deren Benutzer, Apps, E-Mail-Versand und Aufträge
  - **Firmen-Administrator:** pflegt Firmenprofil und Benutzer der eigenen Firma
  - **Firmen-Benutzer:** erstellt und verfolgt Aufträge
- **E-Mail-Versand:** zentrales Absender-Postfach und auf Wunsch ein eigenes Postfach je Firma (SMTP mit Anmeldung, Microsoft 365 oder Gmail/Google Workspace per OAuth2). Benachrichtigungen an eine Firma laufen über deren Postfach, Mails an die Administratoren über das zentrale. Fällt ein Postfach aus, springt automatisch das nächste ein (Firma → zentral → Mailfunktion des Webhosters). Zugangsdaten werden verschlüsselt gespeichert, und für jedes Postfach gibt es einen Knopf für eine Testmail.
- **Sicherheit:** 2FA (TOTP) mit QR-Code, der auf dem eigenen Server erzeugt wird. Außerdem Sperre nach Fehlversuchen, CSRF-Schutz, strikte Mandantentrennung, Protokoll und erzwungene Passwortänderung beim ersten Login.

### App „On- & Offboarding“
- **Onboarding:** Name, Mitarbeiternummer, Abteilung, Position, Eintrittsdatum, Standort, Vorgesetzte/r, Wunsch-E-Mail, Geräte, Lizenzen, Programme, Netzlaufwerke und Freigaben, dazu Bemerkungen und Freitext-Zusatzwünsche
- **Offboarding:** Austrittsdatum, Zeitpunkt der Kontosperre, Postfach (sperren, freigeben, weiterleiten, löschen), Nachfolger/in, Abwesenheitsnotiz, persönliche Dateien, Rufnummer sowie eine Rückgabe- und Entzugsliste
- **Stilllegung:** Beginn, Grund (Auswahlliste, ohne Gesundheitsdaten), geplante Rückkehr, Vertretung, Abwesenheitsnotiz; Lizenzen und Zugänge werden pausiert statt gelöscht. Bei Geräten kann vermerkt werden, ob sie bei der Person bleiben.
- **Reaktivierung:** übernimmt Ausstattung und Rückkehrdatum aus der Stilllegung
- **Folgeaufträge mit einem Klick:** Aus einem abgeschlossenen Auftrag lassen sich Offboarding, Stilllegung oder Reaktivierung starten. Person und Ausstattung werden übernommen, die Aufträge sind miteinander verknüpft.
- **Automatische Aufgaben** in der Checkliste, z. B. Konto sperren zum Zeitpunkt X, Postfach umwandeln, Wiedervorlage vor der Rückkehr
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

## Updates

Am einfachsten mit der **update.php**: in den Portal-Ordner hochladen und im Browser aufrufen. Sie ersetzt die Programmdateien, behält `config.php` und `app/data/` und löscht sich danach selbst. Alternativ die neuen Dateien von Hand über die alten hochladen und dabei `config.php` und `app/data/` behalten. Beim nächsten Aufruf bringt das Portal die Datenbank automatisch auf den neuen Stand. Wer schon eine Version ohne App-Freigaben im Einsatz hatte, bekommt das Onboarding für alle bestehenden Firmen automatisch freigeschaltet.

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
