# Outlook- und Kalender-Anleitung

*Für die Microsoft-365-Administratorin oder den Microsoft-365-Administrator von Quartierzentrum Union. Sie brauchen IT-Kenntnisse, aber keine Programmierkenntnisse.*

Das Plugin liest **eine Kalender-Adresse** (eine «ICS-Adresse»). Es spielt keine Rolle, wer den Kalender veröffentlicht. Diese Anleitung hat drei Teile:

- **Teil A:** die Adresse des bestehenden Google-Kalenders holen (Startquelle).
- **Teil B:** später einen Outlook-Kalender «Programm» einrichten.
- **Teil C:** das Plugin von der Google-Adresse auf die Outlook-Adresse umstellen.

Grundlage ist der Umsetzungsplan (Version 8), Annahme A-01 und die Lücken G-06 bis G-08.

---

## Teil A: Die ICS-Adresse des Google-Kalenders

*Status: Der Weg ist bekannt (so läuft es heute), aber das Plugin wurde noch nicht mit einer Google-Datei getestet (Lücke G-06). Die genauen Bezeichnungen in Google sind unten nicht bestätigt.*

### Was Sie holen

Union lädt heute jede öffentliche Outlook-Veranstaltung zu einem Google-Kalender ein. Dieser Kalender speist Unions Website. Das Plugin soll denselben Kalender lesen. Dafür braucht es seine **geheime Adresse im iCal-Format** (ICS-Adresse).

### Schritte

1. Melden Sie sich bei Google Calendar mit dem Konto an, dem der Kalender gehört, den Elfsight verwendet. Wer dieses Konto verwaltet, wissen Union und die zuständige Person für das Skript. Der Name des Kalenders steht in der bisherigen Anleitung nicht; fragen Sie nach.
2. Öffnen Sie die Einstellungen dieses Kalenders. Suchen Sie den Abschnitt zum Einbinden des Kalenders und die geheime Adresse im iCal-Format. *(Nicht bestätigt: Die Bezeichnungen können in Ihrer Google-Oberfläche anders lauten. Wir haben sie nicht geprüft und nennen sie deshalb nicht wörtlich.)*
3. Kopieren Sie die Adresse. Sie beginnt mit `https://` und endet in der Regel mit `.ics`.
4. Übermitteln Sie die Adresse **nicht** per gewöhnlicher E-Mail oder Chat. Tragen Sie sie selbst im Plugin ein: «Einstellungen → Enter Once» → «Kalender-Adresse (ICS)». Wer das nicht kann, übergibt sie der Administratorin der Verbands-Website persönlich oder über einen Passwortmanager.

### Warum die Adresse vertraulich bleiben muss

- Die «geheime Adresse» gibt Zugriff auf den **ganzen** Kalender. Wer sie kennt, braucht kein Passwort.
- Eine Google-Datei, die aus Outlook-Einladungen entsteht, enthält vermutlich auch E-Mail-Adressen von Mitarbeitenden (Annahme, G-06). Das Plugin gibt solche Angaben nie weiter, aber die Datei selbst wäre für Dritte lesbar.
- Das Plugin speichert die Adresse nur in den Einstellungen, zeigt sie nur Administratorinnen und Administratoren, schreibt sie in kein Protokoll und nutzt keinen Dritt-Dienst dafür.
- Stellen Sie die Adresse nirgends öffentlich ein: nicht in ein Dokument, nicht in ein Ticket, nicht in ein Code-Archiv.
- Ist sie doch bekannt geworden, wenden Sie sich an die Person, die das Google-Konto verwaltet. Google bietet meines Wissens eine Möglichkeit, die geheime Adresse zu erneuern; das ist hier **nicht bestätigt**. Danach tragen Sie die neue Adresse im Plugin ein.

### Der Test vor dem Start (Lücke G-06)

Bevor sich etwas auf diese Quelle verlässt, wird sie mit einer echten Einladung geprüft. Dieser Versuch gehört zur Phase 0 des Plans: Von einem Outlook-Konto aus laden Sie einen Google-Testkalender ein zu (1) einem einzelnen Termin, (2) einer wöchentlichen Serie, (3) einem verschobenen Termin, (4) einem abgesagten Termin einer Serie und (5) einer abgesagten Veranstaltung. Danach laden Sie die ICS-Datei des Google-Kalenders herunter und notieren:

| Frage | Ergebnis |
|---|---|
| Sind eingeladene Veranstaltungen in der Datei? | |
| Gibt es eine Zeile, die den Eintrag als öffentlich oder nicht öffentlich kennzeichnet? | |
| Welche Zeilen nennen Personen? | |
| Wie sieht eine Absage aus? | |
| Sind die Kennungen (UID) dieselben wie in Outlook? | |
| Wie aktuell ist die Datei (wie lange bis zur Änderung)? | |

Speichern Sie die Datei **nicht** mit echten Personendaten weiter. Ersetzen Sie die Personendaten vorher.

---

## Teil B: Später: ein Outlook-Kalender «Programm»

> **Ungetestet.** Dieser ganze Teil beschreibt einen Weg, der noch nie ausprobiert wurde (Lücken G-07, G-08). Alle Namen von Microsoft-Einstellungen stammen **laut Microsoft-Dokumentation** und sind in Ihrem Mandanten nicht geprüft. Befehle und genaue Schreibweisen entnehmen Sie bitte der verlinkten Seite. Wir raten sie nicht.

### Zweck

Der Kalender «Programm» soll **nur öffentliche Veranstaltungen** enthalten und als ICS-Adresse veröffentlicht werden. Dann entfällt der Umweg über Google (Kalender, Skript, Drive-Ordner). Die Mitarbeitenden laden statt des Google-Kalenders den Kalender «Programm» ein, sonst bleibt ihre Arbeit gleich.

### Welche Art von Postfach?

Laut Plan ist ein **Ressourcenpostfach** (resource mailbox) vorgesehen, das Einladungen automatisch annimmt. Ob das Veröffentlichen eines solchen Kalenders als ICS-Adresse so geht, ist **nicht geprüft** und gehört zu den Versuchen unten. Microsoft führt eine Fehlerseite für das Veröffentlichen eines geteilten Postfach-Kalenders; sie ist ein Hinweis darauf, dass der Weg nicht automatisch funktioniert: [HTTP 500 beim Veröffentlichen des Kalenders eines geteilten Postfachs](https://learn.microsoft.com/en-us/troubleshoot/exchange/user-and-shared-mailboxes/http-500-error-when-publishing-calendar-of-shared-mailbox). Wenn es mit dem Ressourcenpostfach nicht geht, notieren Sie das; dann entscheiden wir gemeinsam über eine Alternative.

### Die Voreinstellungen, die Probleme machen

Ein Ressourcenpostfach verarbeitet Einladungen nach eigenen Regeln. Der Plan nennt vier Voreinstellungen, die für uns ungünstig sind. Die Namen stammen aus der Seite [Set-CalendarProcessing](https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/set-calendarprocessing?view=exchange-ps) (laut Microsoft-Dokumentation, nicht geprüft):

| Problem | Wirkung | Einstellung (laut Microsoft-Dokumentation) | Voreinstellung (laut Microsoft-Dokumentation) | Was wir brauchen |
|---|---|---|---|---|
| Betreff gelöscht | Der Titel der Veranstaltung fehlt. | `DeleteSubject` | wird gelöscht | Betreff **behalten** |
| Beschreibung gelöscht | Kategorie- und Tag-Blöcke fehlen. | `DeleteComments` | wird gelöscht | Beschreibung **behalten** |
| Überschneidende Termine abgelehnt | Gleichzeitige Veranstaltungen in verschiedenen Räumen werden abgelehnt. | `AllowConflicts` | nicht erlaubt | Überschneidungen **erlauben** |
| Serien über 180 Tage abgelehnt | Lange Serien werden abgelehnt. | `BookingWindowInDays` | 180 Tage | Wert **erhöhen** (die Seite nennt als Höchstwert 1080; Wert klären wir im Versuch) |
| Abgesagte Termine bleiben | Eine Absage entfernt den Termin nicht aus dem Kalender. | `RemoveCanceledMeetings` | nicht löschen (nur Exchange Online) | Absagen müssen in der Datei erkennbar sein (siehe Versuche; G-08). Ob Löschen oder Behalten besser ist, entscheiden wir nach dem Versuch. |

Hinweise zu dieser Tabelle:

- `DeleteSubject` und `DeleteComments` gelten laut Dokumentation nur bei Ressourcenpostfächern, die Einladungen automatisch annehmen.
- Die Dokumentationsseiten sind nicht in allen Sprachversionen gleich. Prüfen Sie die aktuelle englische Seite.
- Notieren Sie für jede Einstellung den Wert **vorher** und **nachher**. So können Sie zurück.

### Den Kalender veröffentlichen

Die Veröffentlichung hängt von den Kalender-Einstellungen des Postfachs ab. Die Seite [Set-MailboxCalendarFolder](https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/set-mailboxcalendarfolder?view=exchange-ps) nennt (laut Microsoft-Dokumentation, nicht geprüft):

- `PublishEnabled`: schaltet das Veröffentlichen ein. Voreinstellung: aus.
- `DetailLevel`: wirkt nur, wenn das Veröffentlichen eingeschaltet ist. **Die Beschreibung muss in der Datei enthalten sein**, sonst fehlen Kategorie und Tags. Welche Stufe das erlaubt, klären Sie im Versuch.
- `PublishDateRangeFrom` (und der Gegenwert für die Zukunft): bestimmt, wie weit zurück und voraus veröffentlicht wird. Voreinstellung laut Dokumentation: `ThreeMonths`. Das entspricht dem Vorschau-Zeitraum von 3 Monaten im Plugin (A-11). Werte reichen laut Dokumentation von `OneDay` bis `OneYear`.

Beachten Sie: Die Dokumentation sagt, dass nur die Besitzerin oder der Besitzer eines Postfachs dessen Kalender extern veröffentlichen kann. Foren-Beiträge nennen andere Standardwerte; vertrauen Sie der Dokumentation und prüfen Sie im Versuch.

Die entstehende ICS-Adresse behandeln Sie **genauso vertraulich wie in Teil A**.

### Versuche (G-06, G-07, G-08)

Führen Sie jeden Versuch aus und tragen Sie das Ergebnis ein. Erst wenn alle Ergebnisse vorliegen, entscheiden Union und der Entwickler über den Wechsel.

| Nr. | Versuch | Was zu notieren ist | Ergebnis |
|---|---|---|---|
| 1 | Ressourcenpostfach «Programm» anlegen. Veröffentlichen einschalten. Lässt sich eine ICS-Adresse erzeugen? | Ja/Nein; Fehlermeldung; Wortlaut der Adresse (nicht aufschreiben, nur «ja»). | |
| 2 | Vom Konto einer Mitarbeiterin einen einzelnen Termin an «Programm» einladen. Erscheint er in der Datei? | Titel und Beschreibung enthalten? Ort? | |
| 3 | Zwei gleichzeitige Termine einladen. | Beide angenommen oder einer abgelehnt? | |
| 4 | Eine Serie über mehr als 180 Tage einladen. | Angenommen oder abgelehnt? Nach Erhöhen von `BookingWindowInDays`? | |
| 5 | Eine Serie mit einem übersprungenen Datum einladen. | Fehlt das Datum in der Datei? | |
| 6 | Bei einer Serie ein einzelnes Datum verschieben. | Wie sieht es in der Datei aus? | |
| 7 | Einen einzelnen Termin absagen. | Verschwindet er, oder bleibt er mit Kennzeichnung? Wie lautet sie? (G-08) | |
| 8 | Ein einzelnes Datum einer Serie absagen. | Dasselbe. | |
| 9 | Prüfen, ob in der Datei Zeilen mit Namen oder E-Mail-Adressen von Personen stehen. | Welche? (Das Plugin gibt sie nie weiter, aber wir müssen es wissen.) | |
| 10 | Stimmen die Kennungen (UID) der Datei mit denen im persönlichen Outlook überein? | Ja/Nein. Wichtig für Teil C. | |
| 11 | Wie weit voraus reicht die veröffentlichte Datei tatsächlich? | Datum des letzten Termins. | |
| 12 | Wie schnell erscheint eine Änderung in der Datei? | Minuten. | |
| 13 | Ein falsch eingeladener interner Termin: wie lange bleibt er, bis er verschwindet? | Minuten. Das schreiben wir später in die Anleitung. | |

Hinweis: Die Datei `calendar.ics` im Projekt zeigt, wie Outlooks veröffentlichte Dateien heute aussehen (alle Einträge als öffentlich gekennzeichnet; keine Absagen; keine Personenangaben). Sie wurde aber **nicht** über ein Ressourcenpostfach erzeugt.

---

## Teil C: Von Google auf Outlook umstellen

> **Ungetestet.** Dieser Teil setzt den Erfolg der Versuche aus Teil B voraus. Das Plugin wurde mit dem Wechsel der Quelle nie getestet. Der Test dafür ist im Entwicklungsprojekt übersprungen, weil die Google-Datei fehlt.

### Wichtig: Das Plugin hat noch keinen Schritt zum Neuverknüpfen

Jeder Eintrag auf der Website ist über eine Kennung (UID) mit dem Kalender verbunden. Verwenden Google und Outlook **verschiedene** Kennungen (Versuch 10 in Teil B), erkennt das Plugin die bestehenden Einträge nach dem Wechsel nicht wieder.

**Das Plugin hat dafür keine Funktion.** Es gibt kein «Neu verknüpfen». Folgendes passiert dann:

- Das Plugin legt die Veranstaltungen aus dem neuen Kalender **neu an**.
- Die alten Einträge fehlen im neuen Kalender und werden nach dem Sicherheitsabstand (zwei Abgleiche) auf **«Entwurf»** gesetzt. Gelöscht wird nichts.
- Weil dabei mehr als die «Obergrenze (Entfernungen pro Abgleich)» (voreingestellt 5) auf einmal entfernt würden, **hält das Plugin den Abgleich an**. Auf dem Reiter «Status» steht «Abgleich angehalten: … Einträge würden auf einmal entfernt. Das ist mehr als die Obergrenze.» Eine E-Mail «Enter Once: Abgleich angehalten (…)» geht an die Adressen unter «Benachrichtigung».
- **Das ist der erwartete Sicherheitsstopp.** Prüfen Sie die Liste. Stimmt sie (die alten Einträge sind die, die ersetzt werden), klicken Sie auf **«Trotzdem anwenden»**.

Bestehende Wöchentliche Angebote, die Sie vorher übernommen haben (Installationsanleitung, Abschnitt 6), gehören zur alten Kennung. Nach dem Wechsel sind sie also betroffen. Planen Sie dafür die Übernahme nach dem Wechsel neu ein.

Ob die beiden Quellen dieselben Kennungen verwenden, ist offen (G-06, G-07). Haben sie dieselben, tritt nichts davon ein.

### Vorher

1. Die Versuche aus Teil B sind abgeschlossen, insbesondere Versuch 10 (Kennungen).
2. Der Kalender «Programm» enthält die kommenden öffentlichen Veranstaltungen. Dafür müssen Ihre Mitarbeitenden eine Zeit lang **beide** Kalender einladen: den Google-Kalender und «Programm». (Das ist eine Annahme von uns; der Plan sagt dazu nichts.)
3. Schalten Sie das Zentrum auf «Probelauf» (Einstellungen → Abschnitt «Zentren» → Häkchen «Probelauf»). Wählen Sie einen Zeitpunkt, der Ihnen passt, und informieren Sie den Verband.
4. Wenn möglich, wechseln Sie zuerst auf einer Testkopie.

### Schritte

1. Tragen Sie in «Einstellungen → Enter Once», Abschnitt «Zentren», im Feld «Kalender-Adresse (ICS)» die Adresse des Kalenders «Programm» ein. Speichern Sie mit «Einstellungen speichern».
2. Klicken Sie auf dem Reiter «Status» auf «Jetzt abgleichen». Im Probelauf steht in der Tabelle «Geplante Aktionen (Probelauf)», was geschehen würde.
3. Prüfen Sie die Liste: Welche Einträge wären «Neu», welche «Auf Entwurf setzen» oder «Angehalten»? Bei verschiedenen Kennungen sind es viele (siehe oben). Bei gleichen Kennungen darf nichts ausser «Unverändert» erscheinen.
4. Wenn die Liste zum erwarteten Wechsel passt: Schalten Sie den Probelauf aus (Häkchen entfernen, speichern). Der nächste Abgleich wird angehalten. Prüfen Sie die Liste auf dem Reiter «Status» und klicken Sie auf «Trotzdem anwenden».
5. Übernehmen Sie bei Bedarf die Wöchentlichen Angebote neu (Reiter «Bestehende Angebote übernehmen»).
6. Beobachten Sie die erste Woche.

### Erst danach (und nur, wenn Unions Website auf das neue Widget umgestellt ist)

Der Google-Kalender, das Google Apps Script, der Drive-Ordner und die beiden Elfsight-Ereignis-Widgets lassen sich abschalten (Plan, Abschnitt 4.8). Machen Sie das erst, wenn alles stabil läuft. Heben Sie den alten Elfsight-Code für den Rückweg auf.

### Quellen

- [Set-CalendarProcessing (Microsoft Learn)](https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/set-calendarprocessing?view=exchange-ps), abgerufen im November 2026 über eine Websuche; Inhalt nicht im eigenen Mandanten geprüft.
- [Set-MailboxCalendarFolder (Microsoft Learn)](https://learn.microsoft.com/en-us/powershell/module/exchangepowershell/set-mailboxcalendarfolder?view=exchange-ps), gleicher Vorbehalt.
- [HTTP 500 beim Veröffentlichen des Kalenders eines geteilten Postfachs](https://learn.microsoft.com/en-us/troubleshoot/exchange/user-and-shared-mailboxes/http-500-error-when-publishing-calendar-of-shared-mailbox).
