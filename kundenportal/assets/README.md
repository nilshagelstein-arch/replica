# assets – Browser-Skripte, Styles und Bilder

## Overview

Statische Dateien, die der Browser direkt lädt. Dazu gehören fünf JavaScript-Dateien ohne Build-Schritt und ohne Abhängigkeiten, `style.css`, das Logo und die Icons.

Welche Seite welches Skript lädt:

- `../app/views/layout.php`: `app.js` überall; `links.js` auf `link_edit`; `wiki.js` auf `doc`, `doc_space`, `doc_edit` und `doc_template_edit`; `calc.js` und `forms.js` auf `form_layouts`
- `render_app_shell()` in `../app/pages/form_editor.php`:
  - `form_edit`: `calc.js` und `forms.js`
  - `form_use`: `calc.js` und `fill.js`
  - `form_print`: nur `calc.js`
- `page_request_print()` in `../app/pages/requests.php`: `app.js` Nur `links.js` spricht mit dem Server, nämlich mit der Route `link_suggest`. Alle anderen Skripte arbeiten rein im DOM.

## Interface

- **`app.js`**: wird auf jeder Seite geladen. Das Skript läuft sofort beim Laden und meldet sich als Event-Listener am Dokument an, legt aber keine globalen Variablen an `[app.js:IIFE]`.
  - Datenattribute: `[data-confirm]`, `[data-toggle-col]`, `[data-show-if="name=value"]`, `[data-perm-preset]`, `[data-pop]`, `[data-print]`, `[data-close]`, `[data-filter-list]` und `[data-check-all]`
  - Seitenelemente:
    - `#oauth-go`: automatische Weiterleitung
    - `body.print`: druckt beim Laden
  - Logik im Auftragsformular (`#request-form`, `#template-data`, `#department_id`, `#position_id`, `#email_wish`):
    - Positionen nach Abteilung filtern
    - Vorlagen-Häkchen setzen
    - E-Mail-Vorschlag erzeugen
- **`calc.js`**: legt die Rechen-Engine als globales Objekt `window.KPCalc` an `[calc.js:window.KPCalc]`.
  - Funktionen: `grid`, `colName`, `colIndex`, `addr`, `parseNumber`, `format`, `evaluate`, `recalc`, `recalcAll`, `hasFormulas`, `shiftFormula`, `shiftTable`, `copyRowFormula` und `insertRowCopy`
  - Formelfunktionen `[calc.js:FN]`: `SUMME`/`SUM`, `MITTELWERT`/`AVERAGE`, `MIN`, `MAX`, `ANZAHL`/`COUNT`, `RUNDEN`/`ROUND`, `AUFRUNDEN`/`ROUNDUP`, `ABRUNDEN`/`ROUNDDOWN`, `WENN`/`IF`, `UND`/`AND`, `ODER`/`OR`, `ABS`, `PRODUKT`/`PRODUCT`
  - Wahrheitswerte: `WAHR`/`FALSCH`/`TRUE`/`FALSE`
  - Zahlenformate `[data-format]`: `zahl`, `ganz`, `euro`, `prozent`
- **`forms.js`**: Word-ähnlicher Formulareditor. Startet nur, wenn `[data-word]` vorhanden ist. Liest die Konfiguration aus `[data-word-config]` (`page`, `layouts`, `defaults`, `canManage`, `header`, `footer`) und nutzt `window.KPCalc` `[forms.js:IIFE]`.
  - Befehle kommen über delegierte Klicks auf `data-cmd`, `data-insert`, `data-tbl`, `data-ph`, `data-field` usw.
  - Tastenkürzel: Strg+S speichert, Strg+L/E/R/J richtet aus. Tab bzw. Umschalt+Tab wechselt zwischen Tabellenzellen; Tab in der letzten Zelle hängt eine Zeile an.
  - Aus Excel kopierte Bereiche (Tab-/Zeilen-getrennt) übernimmt `pasteGrid` direkt in die Tabelle.
  - Beim Absenden schreibt `sync()` die Formularfelder `body`, `header_html`, `footer_html` und `page`. `<kind>_id`, `<kind>_dirty` und `<kind>_new_name` setzt das Skript schon während der Bearbeitung von Briefkopf und Fußzeile.
- **`fill.js`**: Ausfüllansicht.
  - Startet, sobald `[data-fill]` vorhanden ist. `data-form-id` bildet nur den Speicherschlüssel. Nutzt `window.KPCalc`.
  - Dokumentdatum `[data-doc-date]` füllt `[data-auto="datum"|"datum.lang"]`.
  - Felder `[data-key]`: `.fill`, `.fill-multi`, `.fill-check`, `.fill-select` und `.fill-date`
  - Eingabezellen `td[data-input]` und erweiterbare Tabellen `table[data-grow]`
  - Kundenauswahl `[data-apply-customers]` und Zurücksetzen `[data-fill-reset]`
- **`wiki.js`**: Seitenbaum ein-/ausklappen (`.wk-twisty`) und Seiten-Editor (`#wk-editor`, Werkzeugleiste `#wk-toolbar`; beim Absenden wird der Inhalt in `#wk-body` geschrieben, ohne JavaScript bleibt das Textfeld). Bilder werden im Browser verkleinert und als `data:`-Bild eingefügt; kein Serveraufruf. Dazu die @-Vorschlagsliste im Kommentarfeld (`#wk-comment-text`, Namen aus `data-names`).
- **`links.js`**: Bildvorschläge im Linkeditor. Startet, sobald `#link-url` vorhanden ist. `data-suggest` liest es erst beim Abruf.
  - Ruft `GET <data-suggest>&url=…` auf (Route `link_suggest`).
  - Die JSON-Antwort hat die Form `{ok, error, title, description, images[]}` `[links.js:load]`.
- `style.css`, `logo.svg`, `favicon-32.png`, `apple-touch-icon.png`, `icon-512.png`: Styles und Bilder ohne Code-Vertrag

## Invariants

- `forms.js` bereinigt eingefügtes HTML über `cleanHtml` `[forms.js:cleanHtml]`:
  - Entfernt werden `SCRIPT`, `STYLE`, `IFRAME`, `OBJECT` und weitere Tags. Tags außerhalb der Whitelist werden ausgepackt.
  - `tagName` wird in Großbuchstaben verglichen, sodass auch `svg` und `math` entfernt werden. Zusätzlich entfernt werden `EMBED`, `TEMPLATE` und `NOSCRIPT`.
  - `IMG@src` bleibt nur als `data:image/…` erhalten.
  - `A@href` bleibt nur mit `http(s)://`, `mailto:` oder `tel:` erhalten. Die verbindliche Bereinigung erfolgt trotzdem serverseitig mit `sanitize_html()`.
- Links, die `forms.js` einfügt, müssen mit `http(s)://`, `mailto:` oder `tel:` beginnen. Bilder dürfen höchstens 1 MB groß sein und werden als Data-URL eingebettet `[forms.js:insert link]`.
- Grenzwerte in `forms.js` `[forms.js:clamps]`:
  - Schriftgröße 5–96 pt, Grundschrift der Seite 6–30 pt
  - Seitenränder 0–60 mm
  - Zoom 50–200 %
  - Spaltenbreite mindestens 12 px
  - Feldbezeichnung höchstens 60 Zeichen ohne `[{}<>|]`, Vorlagenname höchstens 190 Zeichen
- Formeln:
  - Eine Formel ist nur ein Wert, der `/^=./` entspricht: `=` und mindestens ein weiteres Zeichen `[forms.js:formula rule]`.
  - Zirkelbezüge ergeben `#ZIRKEL`. Weitere Fehlerwerte sind `#DIV/0!`, `#WERT!`, `#NAME?` und `#FEHLER` `[calc.js:recalc]`, `[calc.js:evaluate]`.
  - Bezüge haben höchstens zwei Spaltenbuchstaben und vierstellige Zeilennummern `[calc.js:tokenize]`.
- Zahlen werden deutsch formatiert (`de-DE`). `parseNumber` versteht `1.234,56`, `€` und `%` `[calc.js:parseNumber]`, `[calc.js:format]`.
- `links.js` füllt Titel und Beschreibung nur, wenn die Felder leer sind. Ältere Anfragen werden per `AbortController` abgebrochen. Vorschläge werden mit `textContent` statt `innerHTML` dargestellt `[links.js:load]`.
- Bei manuell geänderten Ausstattungs-Häkchen setzt `app.js` keine Vorlagenwerte mehr `[app.js:applyTemplates]`.

## Side Effects

- Netzwerk:
  - Nur `links.js` sendet Anfragen: `fetch` an `link_suggest` mit `credentials: 'same-origin'`.
  - `app.js` leitet über `#oauth-go` zum OAuth-Anbieter weiter.
- Speicher:
  - `fill.js` speichert Eingaben in `sessionStorage` unter `kp-fill-<form-id>` `[fill.js:persist]`.
  - `localStorage` wird nirgends verwendet.
- Formular und Navigation:
  - `forms.js` schreibt vor dem Absenden die versteckten Formularfelder `[forms.js:sync]` und warnt per `beforeunload` vor ungespeicherten Änderungen.
  - `fill.js` navigiert zur Kundenauswahl (`&kunden=<ids>`). Beim Zurücksetzen lädt es die Seite neu.
- Druck und Dialoge:
  - `app.js`: `window.print()`, `confirm`; `[data-close]` ruft `window.close()` auf, ersatzweise `history.back()`
  - `fill.js`: nur `confirm`
  - `forms.js`: `prompt`, `confirm`, `alert`

## Verification

- Run: `<test-command>` (kein automatisierter Test im Repository; im Browser prüfen: Formular bearbeiten (`?r=form_edit`), Formular ausfüllen (`?r=form_use`), Link bearbeiten (`?r=link_edit`))
