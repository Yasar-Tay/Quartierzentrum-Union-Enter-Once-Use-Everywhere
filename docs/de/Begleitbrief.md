# Begleitbrief

Basel, 24. November 2026

An das Quartierzentrum Union und den Verband Quartiertreffpunkte Basel

**«Einmal erfassen, überall anzeigen»: ein Geschenk**

Guten Tag

Am AI for Access Hackathon vom 20. und 21. November 2026 hat das Quartierzentrum Union die Aufgabe gestellt, Veranstaltungen nur einmal zu erfassen und überall anzuzeigen. Ich habe sie als Freiwillige oder Freiwilliger weiterverfolgt. Was daraus entstanden ist, schenke ich Ihnen.

**Was Sie erhalten**

Das Übergabepaket enthält fünf Dinge:

- `LIESMICH.txt`: eine kurze Übersicht.
- `enter-once-sync.zip`: das Plugin «Enter Once Sync» für die Website des Verbands, als installierbare Datei. Darin enthalten ist auch das kleine Skript für union-basel.ch (Widget), das den Kalender anzeigt und die heutigen Elfsight-Widgets für Veranstaltungen ersetzen kann.
- `anleitungen/`: acht Anleitungen auf Deutsch. Jede liegt zweimal vor: als PDF zum Lesen und Drucken und als `.md`-Textdatei mit demselben Namen und demselben Inhalt.
  - `Begleitbrief.md`: dieser Brief,
  - `Entscheidungen.md` mit den Annahmen, die ich für Sie getroffen habe, und wie Sie sie ändern,
  - `Installationsanleitung.md` für die Administration der Verbands-Website oder die Entwicklerin oder den Entwickler des Veranstaltungstools,
  - `Outlook-Anleitung.md` für die Microsoft-365-Administration von Union,
  - `Website-Anleitung.md` für die Person, die union-basel.ch in Webflow bearbeitet,
  - `Kurzanleitung-Team.md`, eine Seite für alle, die Veranstaltungen erfassen,
  - `Abgleichsbericht.md` für den Vergleich von Kalender und Programmseite,
  - `Testbericht.md`. Er sagt offen, was getestet wurde und was nur in Ihrer Umgebung geprüft werden kann.
- `abgleichsbericht/`: das Werkzeug für diesen Vergleich als eigener Ordner. Es braucht nur PHP 8.1 oder neuer oder Docker.
- `quellcode/`: der vollständige Quellcode.

**Womit Sie anfangen**

Lesen Sie zuerst diesen Brief, dann `Entscheidungen.md`, dann `Installationsanleitung.md`.

**Was es tut**

Ihr Team erfasst Veranstaltungen weiter in Outlook. Das Plugin liest den öffentlichen Kalender und legt die Veranstaltungen auf der Verbands-Website an. Änderungen und Absagen übernimmt es. Dasselbe liefert es als Feed für Unions eigene Website. Ein zweites Erfassen entfällt.

**Wer das Plugin betreibt**

Nach der Übergabe liegen Betrieb und Wartung bei Ihnen, beim Quartierzentrum Union und beim Verband. Nichts darin hängt von einem Konto oder Server von mir ab. Die Anleitungen sind dafür geschrieben, dass Sie es ohne mich betreiben können.

Einiges kann ich nicht für Sie entscheiden: Ob das Plugin auf der Website des Verbands installiert wird, entscheidet der Verband. Sollte er das nicht wollen, lässt sich der Kern der Arbeit auch an die Entwickler des Veranstaltungstools weitergeben. Ein Abgleichsbericht ist in jedem Fall dabei.

**Lizenz und Haftung**

Das Plugin ist freie Software unter der GNU General Public License, Version 2 oder später (GPL v2 oder später). Sie dürfen es frei nutzen, ändern und weitergeben. Geänderte Versionen bleiben offen. **Es gibt keine Garantie.** Prüfen Sie es vor dem Einsatz wie jede Software, besonders dort, wo es nur in Ihrer Umgebung getestet werden kann.

**Unterstützung**

Auf Anfrage helfe ich gerne weiter: bei der Einrichtung, bei Fragen und, wenn etwas nicht stimmt, bei der Fehlersuche. Sie erreichen mich unter yasartay@gmail.com.

Ich wünsche Ihnen, dass es Ihnen Zeit für das schenkt, was zählt: das Quartier.

Freundliche Grüsse

Yasar TAY
