# Entscheidungen: Was wir für Sie angenommen haben und wie Sie es ändern

*Für Mitarbeitende des Quartierzentrums Union und des Verbands Quartiertreffpunkte Basel. Grundlage: Abschnitt 6 des Umsetzungsplans (Version 8, 23. November 2026).*

## Warum wir Entscheidungen für Sie getroffen haben

Bei der Planung waren viele Fragen offen. Wir hatten keinen Zugriff auf das Microsoft-365-Konto von Union, auf den Google-Kalender oder auf den Programmcode des Veranstaltungstools des Verbands. Wir konnten nicht jede Frage klären und dann erst mit der Arbeit beginnen. Deshalb haben wir bei jeder offenen Frage eine **Annahme** getroffen. Wir haben jeweils die vorsichtigste Variante gewählt, die sich später leicht ändern lässt.

Diese Annahmen betreffen Ihre Arbeit. Sie sollen deshalb nicht im Verborgenen bleiben. Dieses Dokument nennt jede Annahme mit einer Nummer (A-01, A-02 …). So können Sie in einer E-Mail oder an einer Sitzung schreiben: «Wir möchten A-06 ändern.»

Zu jeder Annahme steht hier:

- **Was wir angenommen haben.**
- **Warum.**
- **Wie Sie es ändern.** Die Einstellungen finden Sie in WordPress unter «Einstellungen → Enter Once». Nur Administratorinnen und Administratoren der Verbands-Website können diese Seite öffnen.

Einige Annahmen hängen noch von einer Antwort von Union oder vom Verband ab. Das ist jeweils vermerkt. Nichts hier ist endgültig. Wo Sie anderer Meinung sind, ändern Sie die Einstellung.

Die Bezeichnungen der Einstellungen stammen aus der Dokumentation des fertigen Plugins (`docs/dev/ui-labels.md`, Version 0.1.0). Sie wurden an der echten Website des Verbands nicht gesehen.

---

## 1. Quelle des Kalenders

### A-01 Eine Kalender-Adresse pro Zentrum; wir starten mit dem Google-Kalender

**Angenommen:** Das Plugin liest pro Zentrum eine Kalender-Adresse (eine ICS-Adresse). Es ist ihm gleich, wer den Kalender veröffentlicht. Wir beginnen mit dem bestehenden Google-Kalender von Union. Später kann ein Outlook-Kalender «Programm» an seine Stelle treten.

**Warum:** Der Google-Kalender enthält schon heute nur öffentliche Veranstaltungen, und Ihr Team lädt ihn schon heute ein. Für den Start braucht es keine Administratorrechte. Der Weg über Outlook entfernt Google, braucht aber Administratorrechte in Microsoft 365 und Einstellungen, die noch niemand getestet hat.

**Wichtig:** Der Google-Weg ist genauso ungetestet wie der Outlook-Weg. Vor dem Start wird er mit einer echten Einladung geprüft. Es kann sein, dass die Google-Datei etwas Nötiges nicht enthält. Dann wird der Outlook-Weg zum Startpunkt.

**Ändern:** «Einstellungen → Enter Once», Reiter «Einstellungen», Abschnitt «Zentren», Feld «Kalender-Adresse (ICS)»: Tragen Sie dort eine andere Adresse ein. Für den Wechsel zu Outlook folgen Sie der `Outlook-Anleitung.md`. **Das Plugin hat noch keine Funktion zum Neuverknüpfen:** Verwenden Google und Outlook unterschiedliche Kennungen, werden nach einem Wechsel Einträge neu angelegt und die alten auf «Entwurf» gesetzt. Ein angehaltener Abgleich («Trotzdem anwenden») ist dann der erwartete Sicherheitsstopp.

**Offen:** Wir brauchen die Adresse des Google-Kalenders (Frage Q-03).

### A-02 Ein Eintrag gilt als öffentlich, ausser er ist anders markiert

**Angenommen:** Ein Eintrag wird verworfen und nirgends gespeichert, wenn er als nicht öffentlich markiert ist oder die Kategorie «Intern» trägt. Ein Eintrag ohne Markierung gilt als öffentlich.

**Warum:** So ist es im Standard für Kalenderdateien vorgesehen. Ausserdem gibt es eine zweite Sicherung: Der Kalender enthält ohnehin nur Termine, zu denen der öffentliche Kalender eingeladen wurde.

**Ändern:** In der Regel nichts. Wenn Union andere Markierungen benutzt: Abschnitt «Kennzeichen», Feld «Interne Einträge» (Häkchen «Nicht öffentlich markierte Einträge verwerfen» und eine Liste der Kategorien, eine pro Zeile). Wichtig bleibt: **Interne Raumbelegungen dürfen nie zum öffentlichen Kalender eingeladen werden.**

---

## 2. Verbands-Website

### A-03 Die Felder des Veranstaltungstools werden direkt beschrieben

**Angenommen:** Das Plugin kann die Felder einer Veranstaltung im Veranstaltungstool direkt schreiben, sobald es sie kennt und geprüft hat. Es lernt sie bei der Einrichtung durch Vergleichen (ein Assistent).

**Warum:** Der Code des Tools war uns nicht zugänglich. Von aussen lässt sich nichts hineinschreiben.

**Risiko:** Das Tool könnte beim Speichern selbst Werte berechnen, die ein direktes Schreiben übergeht. Das zeigt sich erst beim Prüfentwurf am Ende der Einrichtung. Ein Probelauf kann es nicht zeigen, weil er nichts schreibt.

**Ändern:** Reiter «Feldzuordnung» → «Neu lernen». Das ist Aufgabe der Entwicklerin oder des Entwicklers des Tools oder einer technisch versierten Administratorin (rund eine halbe Stunde; Anleitung: `Installationsanleitung.md`, Abschnitt 5). Solange die Zuordnung nicht geprüft ist, läuft das Plugin nur im Probelauf.

**Offen:** Ob der Verband den Code des Tools liefert (Frage Q-02).

### A-04 Veranstaltungen entstehen nur in der Standardsprache

**Angenommen:** Das Plugin legt Veranstaltungen nur in der Standardsprache der Website an. Ob für WPML (Mehrsprachigkeit) Übersetzungseinträge nötig sind, wird erst getestet.

**Ändern:** Abschnitt «Website des Verbands», Feld «Sprache (WPML)»: «Nur Standardsprache» (Voreinstellung) oder «In alle Sprachen duplizieren». Ist WPML nicht aktiv, steht dort: «WPML ist auf dieser Website nicht aktiv; die Einstellung hat keine Wirkung.» Übersetzungen können Sie auch von Hand hinzufügen, wie heute. **Ungetestet:** WPML war in der Testumgebung nicht vorhanden.

### A-05 Jede wöchentliche Serie wird zu einem «Wöchentlichen Angebot»

**Angenommen:** Eine Serie, die sich jede Woche wiederholt und mindestens 4 Termine hat, erscheint auf der Verbands-Website als **ein** Wöchentliches Angebot (pro Wochentag). So zeigt die Website wiederkehrende Angebote schon heute. Kürzere Serien und Serien, die nicht wöchentlich sind (zum Beispiel monatlich), werden zu einzelnen Terminen. Ein Wöchentliches Angebot erscheint erst 14 Tage vor dem ersten Termin.

**Warum:** Als einzelne Termine würden wöchentliche Serien die Liste überfluten. Im Beispielkalender stammen 67 von 71 Terminen aus wöchentlichen Serien.

Das Plugin setzt dazu folgende Regeln um:
- Fällt der nächste Termin aus (Ferien), zeigt das Angebot «Ferienpause».
- Ist der nächste Termin abgesagt, zeigt es «abgesagt».
- Ist der nächste Termin verschoben, bleibt die normale Zeit stehen. Oben im Text steht ein Hinweis, zum Beispiel «Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr im Raum Grosser Saal». Er verschwindet nach dem Termin. Die Verschiebung steht auch in der E-Mail-Zusammenfassung.
- Nach dem letzten Termin der Serie wird das Angebot auf «Entwurf» gesetzt.
- Beginnt eine neue Serie mit gleichem Wochentag, gleicher Startzeit und gleichem Titel, wird der alte Entwurf wiederverwendet. Die Adresse der Seite bleibt gleich.

**Ändern:** Abschnitt «Wöchentliche Angebote», Feld «Wöchentliche Termine»: «Als Wöchentliches Angebot» (Voreinstellung) oder «Jeder Termin einzeln». Vor dem Start der Automatik ist das nur eine Einstellung. Danach werden die Einträge neu aufgebaut. Daneben gibt es «Mindestanzahl Termine einer Serie» (4), «Vorlauf für Wöchentliche Angebote (Tage)» (14) und «Hinweis bei verschobenem Termin» (Häkchen «Oben im Text des Angebots anzeigen»).

### A-06 Eine abgesagte Veranstaltung verschwindet von der Website

**Angenommen:** Eine abgesagte einzelne Veranstaltung wird sofort auf «Entwurf» gesetzt, also nicht mehr angezeigt. Gelöscht wird nichts.

**Ändern:** Abschnitt «Kennzeichen», Feld «Abgesagte Veranstaltungen»: «Ausblenden (Entwurf)» (Voreinstellung) oder «Mit Vermerk ‹abgesagt› anzeigen». Dann bleibt die Veranstaltung sichtbar, mit dem Band «abgesagt».

### A-07 Party → «Märkte & Feste»; Austausch → «Offener Treffpunkt»

**Warum:** Die Verbands-Website hat kein gleichnamiges Thema. Dies sind die nächstliegenden. **Das ist noch nicht von Union bestätigt** (Frage Q-05).

**Ändern:** Abschnitt «Zuordnung», Feld «Zuordnung Kategorien». Eine Zeile pro Kategorie, in der Form «Kalender = Thema; Thema». «Kalender = –» heisst: nicht übertragen. Beispiel: `Kultur = Kulturelles & Musik`. Danach auf «Einstellungen speichern». Fehlt ein Thema auf der Website, meldet das Plugin «Begriff nicht gefunden: …».

*Zur Übersicht die ganze Zuordnung der Kategorien:*

| Kategorie im Kalender | Thema auf der Verbands-Website |
|---|---|
| Essen & Trinken | Essen & Trinken |
| Kultur, Musik | Kulturelles & Musik |
| Beratung, Bildung | Beratung & Bildung |
| Bewegung | Bewegung & Entspannung |
| Kreativität | Kreativität & Spielen |
| Offener Treffpunkt | Offener Treffpunkt |
| Party | Märkte & Feste *(Annahme)* |
| Austausch | Offener Treffpunkt *(Annahme)* |

### A-08 Familie → «Für Kinder» und «Für Erwachsene»; Deutschlernende und Highlight werden nicht übertragen

**Warum:** Für «Familie» gibt es auf der Verbands-Website keine eigene Zielgruppe. «Deutschlernende» gibt es dort nicht. «Highlight» wird nur auf Unions eigener Website gebraucht. **Nicht von Union bestätigt** (Frage Q-05).

**Ändern:** Abschnitt «Zuordnung», Feld «Zuordnung Zielgruppen», gleiche Schreibweise, zum Beispiel `Familie = Für Kinder; Für Erwachsene` oder `Highlight = –`. Schreibvarianten tragen Sie im Feld «Schreibvarianten (Synonyme)» ein: `Familien = Familie`. Soll ein Tag ohne Gegenstück übertragen werden, muss der Verband zuerst die passende Zielgruppe anlegen.

*Zuordnung der Tags:*

| Tag im Kalender | Zielgruppe auf der Verbands-Website |
|---|---|
| Babys & Kleinkinder | Für Babys und Kleinkinder |
| Kinder | Für Kinder |
| Jugendliche | Für Jugendliche |
| Erwachsene | Für Erwachsene |
| Senior:innen | Für Senioren |
| Familie | Für Kinder und Für Erwachsene *(Annahme)* |
| Deutschlernende | wird nicht übertragen *(Annahme)* |
| Highlight | wird nicht übertragen; nur für Unions Website *(Annahme)* |

**Wenn ein Wert nicht in der Liste steht:** Das Plugin geht in dieser Reihenfolge vor. (1) Eine Tabelle bekannter Schreibvarianten, zum Beispiel «Familien» → Familie, «Kulinarisches» → Essen & Trinken. (2) Bei genau einem Zeichen Unterschied und genau einem passenden Wert wird korrigiert: «Kulturr» → «Kultur». (3) Sonst bleibt das Feld leer. Es wird nichts erfunden.

### A-09 Wann gilt eine Veranstaltung als abgesagt?

**Angenommen:** Wenn der Kalender sie als abgesagt kennzeichnet oder wenn der Titel mit «Abgesagt:», «Canceled:» oder «Cancelled:» beginnt (Gross- und Kleinschreibung egal). Das Wort wird aus dem Titel entfernt.

**Risiko:** Wie Exchange eine Absage in einer veröffentlichten Datei kennzeichnet, ist noch nicht geprüft.

**Ändern:** Abschnitt «Kennzeichen», Feld «Abgesagt-Kennzeichen»: Bearbeiten Sie die Wortliste (ein Wort pro Zeile).

### A-10 Sicherheitsabstand und Obergrenze beim Entfernen

**Angenommen:** Verschwindet eine Veranstaltung aus dem Kalender, wird sie erst auf «Entwurf» gesetzt, wenn sie in zwei Abgleichen hintereinander gefehlt hat (rund 30 Minuten). Würde ein Abgleich auf einmal mehr als 5 Veranstaltungen eines Zentrums entfernen, geschieht gar nichts. Das Plugin sendet eine E-Mail und zeigt die Liste auf der Statusseite.

**Warum:** Eine Panne im Kalender soll nicht das ganze Programm leeren. Nichts wird gelöscht, nur auf «Entwurf» gesetzt. Kehrt die Veranstaltung zurück, wird derselbe Eintrag wieder veröffentlicht.

**Ändern:** Abschnitt «Abgleich», Felder «Sicherheitsabstand (Abgleiche)» und «Obergrenze (Entfernungen pro Abgleich)». Einen angehaltenen Abgleich geben Sie auf dem Reiter «Status» mit «Trotzdem anwenden» frei.

### A-14 Bestehende Wöchentliche Angebote werden einzeln und mit Bestätigung übernommen

**Angenommen:** Die bereits von Hand erfassten Wöchentlichen Angebote von Union passen nicht eins zu eins zum Kalender (andere Titel; zwei Angebote zur gleichen Zeit; ein Angebot für zwei Serien). Das Plugin ordnet deshalb nie von selbst zu. Es zeigt zu jedem Angebot die möglichen Serien. Eine Person entscheidet pro Angebot: mit einer Serie übernehmen oder in Ruhe lassen. Ein Angebot kann mit nur einer Serie verbunden werden. Vor dem ersten Schreiben speichert das Plugin eine Kopie (Revision) und zeigt den Vergleich Feld für Feld. Titel und Text der Website bleiben standardmässig erhalten. Nicht bestätigte Angebote bleiben für immer unberührt.

**Ändern:** Reiter «Bestehende Angebote übernehmen» (Anleitung: `Installationsanleitung.md`, Abschnitt 6). Soll der Text aus dem Kalender den Text der Website ersetzen, entfernen Sie bei diesem Angebot das Häkchen bei «Text der Website behalten». Die Voreinstellung für alle steht unter «Einstellungen» → «Website des Verbands» → «Text der Website behalten».

**Offen:** Ob das Plugin die bestehenden Angebote übernehmen darf (Frage Q-04).

---

## 3. Bilder

### A-15 Bilder liegen in der Medienbibliothek der Verbands-Website, benannt nach dem Titel

**Angenommen:** Beim Erfassen von Veranstaltungen ist nie ein Hochladen nötig. Ein eigenes Bild ist freiwillig. Das Plugin sucht in dieser Reihenfolge: Bild der Veranstaltung (Dateiname = Titel), Bild der Kategorie, Standardbild. Gross- und Kleinschreibung, Leerzeichen, Unterstriche und Bindestriche spielen keine Rolle. Ein von Hand gesetztes Bild wird nie ersetzt.

**Zu wissen:** Unions Website nimmt ihre Bilder heute aus dem Drive-Ordner «Elfsight Bilder». Für den neuen Kalender müssen die dort verwendeten Bilder einmal in die Medienbibliothek hochgeladen werden. Das ist ein Schritt bei der Umstellung von Unions Website.

**Ändern:** Laden Sie z. B. `Offener_Deutschtreff.jpg` in «Medien» hoch und wählen Sie im Bild das Feld **«Zentrum (Enter Once)»** («Quartierzentrum Union»). Nur Administratorinnen und Administratoren können es setzen. Nur dann gilt das Bild für Unions Veranstaltungen. «Kategoriebilder» (Zeilen «Kategorie = Bild-ID») und «Standardbild (Bild-ID)» stehen in den Einstellungen im Abschnitt «Bilder».

**Offen:** Woher die Bilder kommen sollen und wer die Rechte hat (Frage Q-06).

---

## 4. Unions eigene Website

### A-16 Unions Website liest den Feed vom Server des Verbands

**Risiko:** Der Kalender von Union hängt davon ab, dass qtp-basel.ch erreichbar ist. Dagegen helfen ein Zwischenspeicher, ein Ersatz-Link und der Rückweg (alter Code).

**Ändern:** Abschnitt «Öffentlicher Feed», Feld «Öffentlicher Feed» (Häkchen «Eingeschaltet») schaltet den Feed ein oder aus.

### A-17 Unions Website zeigt Veranstaltungen über ein eingebettetes Skript, nicht über Webflow-CMS-Einträge

**Zurück zum alten Stand:** Fügen Sie den alten Elfsight-Code wieder ein. Für Webflow-CMS wären ein Webflow-Zugangsschlüssel, eine neue Sammlung «Veranstaltungen», eine Seitengestaltung und ein Ort für einen zweiten Hintergrunddienst nötig. Das wäre ein eigenes Projekt.

### A-18 Der neue Kalender bietet dieselben Filter und dasselbe Layout wie das heutige Elfsight-Widget

**Ändern:** Optionen des Widgets sind Angaben im eingefügten Code (`data-limit`, `data-tag`, `data-view`, `data-fallback`, `data-heading-level`). Die `Website-Anleitung.md` beschreibt sie.

**Offen:** Wie die beiden Elfsight-Widgets heute eingestellt sind (Frage Q-10).

### A-23 Unions Website wechselt erst, wenn die Verbandsseite zwei Wochen lief

**Warum:** Eine Änderung nach der anderen. Der kleinere Nutzen kommt nach dem grösseren.

**Ändern:** Es gibt nichts einzustellen; es ist ein Schritt der Checkliste. Wer diesen Teil ganz weglässt, ändert nichts an allem anderen.

---

## 5. Betrieb und Übergabe

### A-11 Der Abgleich schaut 3 Monate voraus

**Warum:** Veröffentlichte Outlook-Kalender reichen standardmässig rund 3 Monate (laut Microsoft-Dokumentation). Würde das Plugin weiter vorausschauen, würden Ausnahmen von Serien fehlen. Ein Google-Kalender liefert mehr; der Wert kann erhöht werden, sobald die Quelle bekannt ist.

**Ändern:** Abschnitt «Abgleich», Feld «Vorschau-Zeitraum (Monate)».

### A-12 Der Abgleich läuft alle 15 Minuten

**Ändern:** Abschnitt «Abgleich», Felder «Abgleich-Abstand (Minuten)» (15, mindestens 5) und «Warnung «veraltet» nach (Minuten)» (60). Kann die Hosting-Firma des Verbands einen Eintrag im Server-Zeitplaner anlegen, nennt die Installationsanleitung (Abschnitt 8) die Zeile dafür; sie steht auch unten auf dem Reiter «Status». Sonst läuft das Plugin trotzdem, aber unregelmässiger. Der Reiter «Status» warnt, wenn der letzte erfolgreiche Abgleich zu alt ist.

**Offen:** Welche PHP-Version und welchen Zeitplaner der Server hat (Frage Q-09).

### A-13 Der Server läuft mit PHP 8.1 oder neuer

Technische Annahme; keine Einstellung. Ihre Hosting-Firma kann die Version bestätigen (Frage Q-09).

### A-19 Nach der Übergabe betreiben Union und der Verband das Plugin selbst

Fehler-E-Mails gehen an die Adressen im Feld «Benachrichtigung» (Abschnitt «Zentren»; leer: die Adresse der Website-Administration). Die Installationsanleitung enthält einen Abschnitt zur Fehlersuche. Die Entwicklerin oder der Entwickler hilft auf Anfrage.

### A-20 KI-Funktionen sind ausgeschaltet

**Ändern:** Es gibt das Feld «KI-Funktionen» (Voreinstellung aus). Es trägt den Hinweis «In dieser Version nicht enthalten.» Eine Einschaltung bewirkt also nichts. Ohne KI arbeitet die Synchronisation genau gleich.

**Offen:** Ob Union die KI-Zusätze möchte (Frage Q-08).

### A-21 Das Plugin ist der Hauptweg; sonst geht der Kern an die Entwickler des Tools

Lehnt der Verband das Plugin ab, geht der Kern an die Entwicklerin oder den Entwickler des Tools. Der Abgleichsbericht wird in jedem Fall geliefert. Es gibt nichts einzustellen. Sagen Sie uns, welchen Weg der Verband bevorzugt.

### A-22 Der Abgleichsbericht ist ein Befehl, den die Betreiber von Hand starten

Der Bericht wird in eine private Datei geschrieben. Er ist nicht öffentlich und wird nicht von uns gehostet. Wie Sie ihn starten, steht in `Abgleichsbericht.md`.

**Offen:** Wer den Bericht ausführt, und ob der Verband einverstanden ist, dass seine öffentliche Programmseite gelesen wird (Frage Q-11).

### A-24 Änderungen kommen einmal täglich per E-Mail; Probleme sofort

**Ändern:** Abschnitt «Benachrichtigung», Feld «Häufigkeit» («täglich», «bei jedem Abgleich» oder «wöchentlich»). Fehler und angehaltene Abgleiche werden immer sofort gemeldet. Derselbe Fehler wird höchstens einmal pro Tag gemeldet.

---

## Alle Einstellungen mit Standardwert

Alle Werte sind Voreinstellungen. Die Bezeichnungen in der ersten Spalte stehen so im Plugin («Einstellungen → Enter Once», Reiter «Einstellungen»); der Abschnitt steht in der zweiten Spalte.

| Einstellung | Abschnitt | Standardwert in einfachen Worten |
|---|---|---|
| «Kennung» | «Zentren» | Wird bei der Einrichtung gesetzt (zum Beispiel `union`); später nicht änderbar. |
| «Name des Zentrums» | «Zentren» | Der Name des Zentrums. |
| «Kalender-Adresse (ICS)» | «Zentren» | Wird bei der Einrichtung eingetragen. Geheim. |
| «Zentrum auf der Verbands-Website» | «Zentren» | Wird bei der Einrichtung gewählt (für Union: «Quartierzentrum Union»). |
| «Benachrichtigung» | «Zentren» | Wird bei der Einrichtung eingetragen. Leer: die Adresse der Website-Administration. |
| «Zentrum aktiv» | «Zentren» | Ja («Automatisch abgleichen»). |
| «Probelauf» | «Zentren» | Ein, bis die Feldzuordnung geprüft ist und Sie ihn selbst ausschalten. |
| «Abgleich-Abstand (Minuten)» | «Abgleich» | 15 (mindestens 5). |
| «Warnung «veraltet» nach (Minuten)» | «Abgleich» | 60. |
| «Vorschau-Zeitraum (Monate)» | «Abgleich» | 3. |
| «Sicherheitsabstand (Abgleiche)» | «Abgleich» | 2 (rund 30 Minuten). |
| «Obergrenze (Entfernungen pro Abgleich)» | «Abgleich» | 5. |
| «Interne Einträge» | «Kennzeichen» | Nicht öffentlich markierte Einträge und die Kategorie «Intern» werden verworfen. |
| «Abgesagt-Kennzeichen» | «Kennzeichen» | Abgesagt:, Canceled:, Cancelled:. |
| «Abgesagte Veranstaltungen» | «Kennzeichen» | «Ausblenden (Entwurf)». |
| «Wöchentliche Termine» | «Wöchentliche Angebote» | «Als Wöchentliches Angebot». |
| «Mindestanzahl Termine einer Serie» | «Wöchentliche Angebote» | 4. |
| «Vorlauf für Wöchentliche Angebote (Tage)» | «Wöchentliche Angebote» | 14 Tage vor dem ersten Termin. |
| «Hinweis bei verschobenem Termin» | «Wöchentliche Angebote» | Ein. |
| «Zuordnung Kategorien», «Zuordnung Zielgruppen», «Schreibvarianten (Synonyme)» | «Zuordnung» | Wie in den Tabellen oben. |
| «Sprache (WPML)» | «Website des Verbands» | «Nur Standardsprache». |
| «Text der Website behalten» | «Website des Verbands» | Ein. |
| «Feldzuordnung» | «Website des Verbands» | «nicht geprüft». Wird nur im Reiter «Feldzuordnung» geändert. |
| «Bilder» | «Bilder» | «Aus der Medienbibliothek». |
| «Kategoriebilder» | «Bilder» | Leer. |
| «Standardbild (Bild-ID)» | «Bilder» | Keines. |
| «Öffentlicher Feed» | «Öffentlicher Feed» | Ein. |
| «Erlaubte Websites für den Feed» | «Öffentlicher Feed» | `*` (alle). |
| «Zwischenspeicher des Feeds (Sekunden)» | «Öffentlicher Feed» | 300 (5 Minuten). |
| «Häufigkeit» | «Benachrichtigung» | «täglich». |
| «KI-Funktionen» | «KI-Funktionen» | Aus. «In dieser Version nicht enthalten.» |
