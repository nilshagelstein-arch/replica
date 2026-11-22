# tools – Build-Werkzeuge

## Overview

`build-update.php` ist ein Kommandozeilenskript für Entwickler. Es packt den Ordner `kundenportal/` in eine einzelne, selbstinstallierende Datei `build/update.php`. Diese Datei lädt man auf den Server hoch und ruft sie im Browser auf, oder man legt sie im Portal unter **Administration → Updates** ab. Format und Prüfung des Pakets müssen zu `kundenportal/app/updates.php` passen (siehe [../kundenportal/app/README.md](../kundenportal/app/README.md)).

```bash
php tools/build-update.php --author="Name" --summary="Was ist neu" [--summary-file=notes.txt] [--date=2026-11-21]
```

## Interface

- CLI-Optionen, nur in Langform: `--summary=<text>`, `--summary-file=<pfad>`, `--author=<name>` und `--date=<Y-m-d>` `[build-update.php:getopt]`. `--summary-file` hat Vorrang vor `--summary` `[build-update.php:$summary]`.
- Standardwerte `[build-update.php:$updateInfo]`:
  - `date`: heutiges Datum
  - `author`: `git config user.name`
  - `build`: `Y-m-d H:i`, dazu ` · <kurzer Commit-Hash>`, wenn git einen liefert
  - `summary`: leer

  Die git-Aufrufe verwerfen die Fehlerausgabe, unter Windows nach `NUL`, sonst nach `/dev/null` (`$nullDev`).
- Pfade `[build-update.php:$root]`, `[build-update.php:$src]`, `[build-update.php:$out]`:
  - Quelle: `kundenportal/`
  - Ausgabe: `build/update.php`
- Paketformat `[build-update.php:$head]`:
  1. PHP-Kopf mit der Zeile `// UPDATE-INFO: <base64(JSON)>`. Die JSON-Schlüssel sind `date`, `author`, `summary`, `build` und `files`.
  2. Danach `__HALT_COMPILER();`.
  3. Danach das ZIP, base64-kodiert in Zeilen zu je 76 Zeichen.
  4. Der Kopf definiert `upd_page()`.
- Gegenstück im Portal (`kundenportal/app/updates.php`):
  - `update_embedded_info()`: liest die ersten 8192 Bytes
  - `update_extract_zip()`: die Daten müssen mit `PK` beginnen
  - `update_check_package()`: das ZIP muss `index.php` und `app/bootstrap.php` enthalten
  - `update_apply()`: wendet dieselben Überspring-Regeln an wie der Kopf des Pakets

  Beide Seiten müssen synchron bleiben.

## Invariants

- Nicht ins Paket kommen:
  - `config.php` und `.gitignore` im Wurzelordner der Quelle
  - alles unter `app/data/` außer `app/data/.htaccess`

  Quelle: `[build-update.php:$rel]`
- Der Builder braucht PHP 8.0 und die Erweiterung `ZipArchive`. Das erzeugte Paket braucht auf dem Server PHP 8.1, weil `upd_page()` den Rückgabetyp `never` hat.
- Der Builder prüft weder, ob er auf der Kommandozeile läuft, noch die Werte der Optionen. Ein leerer `--summary` ist erlaubt `[build-update.php:$summary]`.
- Das erzeugte Paket prüft zur Laufzeit `[build-update.php:$head]`:
  - Es bricht mit einer Fehlerseite ab, wenn `ZipArchive` fehlt, wenn `config.php` bzw. `app/bootstrap.php` im Zielordner fehlen („Falscher Ordner“), wenn das CSRF-Token abgelaufen ist oder wenn das ZIP beschädigt ist.
  - Nur die Rollen `superadmin` und `admin` dürfen installieren.
  - Installiert wird nur per POST mit CSRF-Prüfung (`hash_equals` mit `csrf_token()`).
  - Einträge mit `..` oder führendem `/` werden übersprungen.
  - `config.php` und `app/data/*` werden nie überschrieben. Ausnahme: `app/data/.htaccess` wird überschrieben.
  - `install.php` wird übersprungen, wenn `app/data/installed.lock` existiert.

## Side Effects

- Ruft über `shell_exec` die Befehle `git rev-parse --short HEAD` und, ohne `--author`, `git config user.name` auf `[build-update.php:$commit]`.
- Legt bei Bedarf `build/` an und schreibt eine temporäre ZIP-Datei (`tempnam`), die am Ende gelöscht wird.
- Überschreibt `build/update.php` und gibt Pfad, Dateianzahl und Größe aus `[build-update.php:$out]`.
- Fehler beim Schreiben werden nicht geprüft und führen zu Exit-Code 0. Fatale Fehler enden dagegen mit 255, z. B. ein fehlendes `ZipArchive` oder ein `json_encode`-Fehler bei ungültigem UTF-8 in `--summary` (TypeError unter `strict_types`).
- Das erzeugte Paket zur Laufzeit `[build-update.php:$head]`:
  - setzt die Header `Content-Type` und `X-Robots-Tag: noindex` und startet über `bootstrap.php` die Session
  - schreibt `app/data/update-<hex>.zip` (wird danach gelöscht) und die Programmdateien; fehlende Ordner legt es mit 0755 an
  - ruft danach `opcache_reset` auf
  - bricht mit „Update unvollständig“ ab, wenn eine Datei nicht geschrieben werden konnte; `update.php` bleibt dann liegen
  - archiviert sich sonst über `update_store(…, true)` als `app/data/updates/<id>.php` plus `.json` mit Metadaten
  - löscht sich, wenn das nicht gelingt oder `app/updates.php` fehlt

## Verification

- Run: `<test-command>` (kein automatisierter Test im Repository; Probelauf: `php tools/build-update.php --summary="Test"` und prüfen, dass `build/update.php` erzeugt wird)
