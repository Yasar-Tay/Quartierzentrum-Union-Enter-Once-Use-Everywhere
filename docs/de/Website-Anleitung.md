# Website-Anleitung: Kalender auf union-basel.ch

*Für die Person, die union-basel.ch in Webflow bearbeitet. Grundlage: Umsetzungsplan, Abschnitt 4.8 und Annahmen A-16 bis A-18 und A-23.*

> **Nicht getestet:** Das Widget wurde nur auf einer Kopie von union-basel.ch und mit erfundenen Daten geprüft, nicht auf der echten Website unter Webflow und nicht gegen den echten Feed. Prüfen Sie es deshalb wie unten beschrieben auf der Vorschau-Adresse, bevor Sie veröffentlichen. Wo Sie in Webflow genau klicken, hängt von Ihrer Webflow-Version ab; wir beschreiben das nur allgemein.

## Wann dieser Schritt kommt

**Erst, wenn die Verbandsseite zwei Wochen lang im Echtbetrieb gelaufen ist** (A-23). Eine Änderung nach der anderen. Wer diesen Schritt weglässt, ändert nichts an allem anderen: Die Verbands-Website funktioniert auch ohne ihn.

Vorher müssen die Bilder, die Unions Website braucht, einmal in die Medienbibliothek der Verbands-Website hochgeladen sein (A-15). Dafür sorgt die Checkliste der Einführung.

## Was sich ändert

Auf union-basel.ch gibt es drei Elfsight-Widgets:

| Widget | Wo | Was geschieht |
|---|---|---|
| Veranstaltungskalender | Seite `quartierzentrum-union/veranstaltungskalender` | **wird ersetzt** |
| «Highlights» | Startseite | **wird ersetzt** |
| Cookie-Banner | alle Seiten | **bleibt unverändert** |

Der Cookie-Banner ist ein eigenes Elfsight-Widget. Fassen Sie es nicht an.

## Vorher: Sicherung für den Rückweg

1. Öffnen Sie in Webflow die Seite `quartierzentrum-union/veranstaltungskalender` und suchen Sie den Elfsight-Code für den Kalender (Einbettung mit `2a073e75-…`).
2. Kopieren Sie den **ganzen** alten Code in eine Textdatei: `Elfsight-Kalender-alt.txt`.
3. Machen Sie dasselbe für das «Highlights»-Widget auf der Startseite (`cda7408f-…`): `Elfsight-Highlights-alt.txt`.
4. Bewahren Sie beide Dateien sicher auf (zum Beispiel bei den Übergabeunterlagen). Löschen Sie die Elfsight-Widgets in Elfsight selbst **nicht**. Sie sind der Rückweg.
5. Bitten Sie, wenn möglich, um einen Bildschirmfoto der Elfsight-Einstellungen (Filter, Sortierung, Anzahl Highlights), damit wir den Ersatz vergleichen können (Frage Q-10).

## Die zwei neuen Code-Stücke

**Für die Kalenderseite:**

```html
<div data-enteronce="calendar" data-source="union"></div>
<script src="https://www.qtp-basel.ch/wp-content/plugins/enter-once-sync/widget/enteronce-widget.js" defer></script>
```

```html
<div data-enteronce="highlights" data-source="union" data-tag="Highlight" data-limit="6"></div>
<script src="https://www.qtp-basel.ch/wp-content/plugins/enter-once-sync/widget/enteronce-widget.js" defer></script>
```

Die Startseite ist eine andere Seite als der Kalender, deshalb braucht sie die `script`-Zeile **ebenfalls**. (Stünden beide Stücke auf derselben Seite, genügte eine `script`-Zeile: Ein Skript bedient alle Stellen der Seite.) Das Stylesheet lädt das Skript selbst nach; Sie müssen nichts weiter einfügen.

Angaben im Code, die Sie ändern können (A-18):

- `data-limit`: Höchstzahl der gezeigten Veranstaltungen (Startseite: voreingestellt 6; Kalender: alle).
- `data-tag`: nur Veranstaltungen mit diesem Tag (Startseite, voreingestellt `Highlight`).
- `data-view`: Darstellung der Karten: `list` (Bild links, eine Karte pro Zeile; voreingestellt im Kalender) oder `grid` (Bild oben, Karten nebeneinander; voreingestellt bei den Highlights).
- `data-fallback`: Adresse des Links in der Fehlermeldung (voreingestellt `https://www.qtp-basel.ch/programm/`).
- `data-heading-level`: Überschriftenebene der Monate beziehungsweise Titel (voreingestellt 3; passend, weil auf Unions Seiten darüber eine `h2` steht).

## Vor dem Veröffentlichen testen

Der Feed ist öffentlich und beantwortet Anfragen von jeder Website. Darum funktioniert das Widget auch auf der Vorschau-Adresse von Webflow.

1. Ersetzen Sie in Webflow den Elfsight-Code auf der Kalenderseite durch den neuen Code (fügen Sie ihn in dasselbe Einbettungs-Element ein).
2. **Veröffentlichen Sie noch nicht.** Öffnen Sie die Vorschau-Adresse von Webflow (die Adresse, die Webflow für Test-Ansichten verwendet).
3. Prüfen Sie:
   - Die Veranstaltungen erscheinen, nach Monaten gegliedert.
   - Jede Karte zeigt Datum, Bild, Kategorie, Titel, Tags, Zeit und Ort.
   - Die Filter (Datum, Kategorie, Raum) und die Suche funktionieren.
   - Abgesagte Veranstaltungen bleiben im Widget sichtbar: mit dem Wort «abgesagt» vor dem Titel, durchgestrichenem Titel und gestricheltem Rand. Das gilt auch bei den Highlights. (Auf der Verbands-Website werden sie dagegen standardmässig ausgeblendet, A-06.)
   - Die Startseite zeigt die Highlights.
4. Vergleichen Sie einige Veranstaltungen mit dem Outlook-Kalender.
5. Erst wenn alles stimmt, veröffentlichen Sie.

## Was Besucherinnen und Besucher sehen, wenn der Feed nicht erreichbar ist

Dann zeigt das Widget die Meldung «Die Veranstaltungen können im Moment nicht angezeigt werden.» und den Link «Zum aktuellen Programm» auf das Programm auf der Website des Verbands. Das gilt auch bei einem Fehler des Servers oder wenn nach 10 Sekunden keine Antwort kommt. Ist der Feed leer, steht stattdessen «Zurzeit sind keine Veranstaltungen geplant.» Der Feed ist ausserdem zwischengespeichert (5 Minuten), sodass kurze Ausfälle meist nicht auffallen. Wird der Feed im Plugin ausgeschaltet («Öffentlicher Feed»), oder das Plugin deaktiviert, erscheint dieselbe Meldung.

## Rückweg

Wenn etwas nicht stimmt:

1. Öffnen Sie in Webflow das Einbettungs-Element.
2. Löschen Sie den neuen Code und fügen Sie den alten aus `Elfsight-Kalender-alt.txt` (bzw. `Elfsight-Highlights-alt.txt`) ein.
3. Veröffentlichen Sie erneut.

Damit ist der heutige Zustand wieder hergestellt. Das gilt, solange das Elfsight-Widget und der Google-Kalender noch bestehen. Schalten Sie diese erst ab, wenn die Quelle Outlook «Programm» ist und alles stabil läuft (siehe `Outlook-Anleitung.md`).

## Was dieser Schritt nicht ist

- Er entfernt den Google-Kalender nicht. Solange die Quelle der Google-Kalender ist, bleibt er samt Skript bestehen. Nur Elfsight wird ersetzt (Plan 4.8).
- Er ändert nichts an den 13 Webflow-CMS-Sammlungen.
- Einzelne Veranstaltungen haben danach keine eigene, von Suchmaschinen gefundene Seite. Das war mit Elfsight auch nicht so.
- Unions Kalender hängt künftig vom Server des Verbands ab (A-16).
