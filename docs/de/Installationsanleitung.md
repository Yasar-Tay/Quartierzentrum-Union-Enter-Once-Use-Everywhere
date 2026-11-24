# Installationsanleitung

**Für wen:** Für die Administratorin oder den Administrator der Website des Verbands (qtp-basel.ch) oder für die Entwicklerin oder den Entwickler des Veranstaltungstools (Theme `qtp`).

**Wichtig vorab:** Der Schritt «Feldzuordnung lernen und prüfen» (Abschnitt 5) braucht **eine technisch versierte Person und rund eine halbe Stunde**. Alles andere kann eine Administratorin ohne Programmierkenntnisse erledigen.

> **Was nicht getestet ist:** Das Plugin wurde **nie am echten Veranstaltungstool des Verbands** ausprobiert. Alle Tests liefen auf einer lokalen Nachbildung. Prüfen Sie deshalb die Punkte in Abschnitt 1 und führen Sie die Einführung so durch, wie sie hier beschrieben ist: zuerst nur zur Probe. Die Einzelheiten stehen im `Testbericht.md`.

Alle Bezeichnungen in Anführungszeichen « » stehen so im Plugin. Die Bezeichnungen von WordPress selbst (zum Beispiel «Plugins → Installieren») können je nach WordPress-Version leicht abweichen.

---

## 1. Bevor Sie anfangen

1. **Sicherung.** Erstellen Sie eine vollständige Sicherung der Website (Dateien und Datenbank). Das Plugin schreibt in Veranstaltungen. Es löscht nie etwas, aber eine Sicherung gehört dazu.
2. **Testkopie.** Gibt es eine Kopie der Website (Staging)? Dann installieren Sie zuerst dort. **Wurde das Plugin noch nie am echten Theme ausprobiert, ist das der erste Schritt der Einführung.** Gibt es keine Kopie, bleibt das Plugin im Probelauf (voreingestellt), bis die Feldzuordnung geprüft ist. Im Probelauf schreibt es nichts.
3. **PHP.** Der Server braucht **PHP 8.1 oder neuer**. Bei älteren Versionen zeigt das Plugin: «Enter Once Sync braucht PHP 8.1 oder neuer.» Fragen Sie im Zweifel die Hosting-Firma.
4. **Wer darf was.** Nur Administratorinnen und Administratoren sehen die Einstellungen. Konten von Zentren können nichts ändern.
5. **Der Verband weiss Bescheid.** Das Plugin läuft auf der gemeinsamen Website von 16 Zentren. Der Verband entscheidet über die Installation.
6. **Die Kalender-Adresse (ICS)** von Union liegt bereit (siehe `Outlook-Anleitung.md`, Teil A). Sie ist geheim.
7. **Was Sie auf dem Server wissen sollten:** PHP-Version, ob die Hosting-Firma einen Zeitplaner-Eintrag (Cron) einrichten kann, und ob E-Mails von der Website ankommen.

## 2. Das Plugin installieren

1. Melden Sie sich in WordPress als Administratorin an.
2. Gehen Sie zu «Plugins → Installieren» und wählen Sie «Plugin hochladen».
3. Wählen Sie die Datei `enter-once-sync.zip` aus dem Paket und klicken Sie auf «Jetzt installieren».
4. Klicken Sie auf «Plugin aktivieren».

Danach finden Sie die Einstellungen unter **«Einstellungen → Enter Once»**. In der Plugin-Liste gibt es ausserdem den Link «Einstellungen».

Beim Aktivieren legt das Plugin seine Grundeinstellungen an, richtet den WordPress-Zeitplaner ein und sucht die Begriffe (Themen, Zielgruppen) der Website. Es gibt vier Reiter: **«Status»** (Voreinstellung), **«Einstellungen»**, **«Feldzuordnung»** und **«Bestehende Angebote übernehmen»**.

Sehen Sie nach der Aktivierung stattdessen die Meldung «Enter Once Sync ist unvollständig installiert (vendor/autoload.php fehlt).», wurde nicht das fertige Paket hochgeladen. Laden Sie `enter-once-sync.zip` aus dem Paket hoch.

## 3. Ein Zentrum einrichten

Öffnen Sie «Einstellungen → Enter Once», Reiter **«Einstellungen»**, Abschnitt **«Zentren»**. Ist noch keines eingerichtet, sagt der Reiter «Status»: «Noch kein Zentrum eingerichtet.» mit dem Link «Zu den Einstellungen».

Füllen Sie einen Block aus (ein weiteres Zentrum fügen Sie mit «Neues Zentrum hinzufügen» hinzu):

| Feld | Eintrag für Union |
|---|---|
| «Kennung» | `union`. Kurz, ohne Leerzeichen. Wird im Feed verwendet und **kann später nicht geändert werden**. |
| «Name des Zentrums» | Quartierzentrum Union |
| «Kalender-Adresse (ICS)» | Die geheime Adresse. Das Feld ist ein Passwortfeld; «Anzeigen» zeigt sie. Sie muss mit `https://` beginnen. Beim späteren Bearbeiten lassen Sie das Feld leer, um die gespeicherte Adresse zu behalten. «Adresse löschen» entfernt sie. |
| «Zentrum auf der Verbands-Website» | Wählen Sie «Quartierzentrum Union» aus der Liste (nicht «– bitte wählen –»). |
| «Benachrichtigung» | Eine E-Mail-Adresse pro Zeile. Bleibt das Feld leer, gehen die E-Mails an die Adresse der Website-Administration. |
| «Zentrum aktiv» | Häkchen bei «Automatisch abgleichen». |
| «Probelauf» | **Häkchen lassen** («Nur zeigen, was geändert würde; nichts schreiben»). Solange die Feldzuordnung nicht geprüft ist, gilt der Probelauf ohnehin immer. |

Klicken Sie auf **«Einstellungen speichern»**. Es erscheint «Einstellungen gespeichert.»

Mögliche Fehlermeldungen: «Die Kennung «…» kommt zweimal vor.», «…: Die Kalender-Adresse (ICS) muss mit https:// beginnen. Die bisherige Adresse bleibt.», «…: ungültige E-Mail-Adresse wurde entfernt.»

Meldet das Plugin «Begriff nicht gefunden: …», fehlt auf der Website ein Thema oder eine Zielgruppe, die das Plugin braucht. Legen Sie ihn an oder ändern Sie die Zuordnung (Abschnitt 7). Mit **«Begriffe neu zuordnen»** (unter dem Formular) sucht das Plugin erneut; Sie sehen «Alle Begriffe wurden gefunden.» oder «Nicht gefunden: …».

## 4. Der Probelauf: was er bedeutet

Im Probelauf liest das Plugin den Kalender und rechnet aus, was es tun würde. **Es schreibt nichts** auf die Website. Der Reiter «Status» zeigt es:

- Die Zeile «Probelauf» sagt «Ein – es wird nichts geschrieben».
- Die Tabelle heisst «Geplante Aktionen (Probelauf)». Spalten: «Aktion» («Neu», «Ändern», «Auf Entwurf setzen», «Angehalten», «Unverändert»), «Art» («Wöchentliches Angebot» oder «Veranstaltung»), «Titel», «Wann», «Grund».
- Unter «Warnungen und Hinweise» stehen «Warnung: …» und «Hinweis: …» zum Kalender.

Solange die Feldzuordnung nicht geprüft ist, steht auf dem Reiter: «Die Feldzuordnung ist noch nicht geprüft. Bis dahin läuft jeder Abgleich als Probelauf und schreibt nichts.» mit dem Link «Zur Feldzuordnung».

Mit **«Jetzt abgleichen»** starten Sie einen Abgleich von Hand. Danach steht oben: «Probelauf abgeschlossen: … neu, … geändert, … auf Entwurf, … angehalten (nichts geschrieben).»

Machen Sie jetzt einen ersten Probelauf. Er braucht noch keine Feldzuordnung. Er ist auch die Voraussetzung für Abschnitt 6, weil das Plugin dabei die Serien des Kalenders kennenlernt.

## 5. Die Feldzuordnung lernen und prüfen

*Dieser Schritt braucht eine technisch versierte Person, rund eine halbe Stunde.* Das Plugin kennt die internen Feldnamen des Veranstaltungstools nicht. Es lernt sie durch Vergleichen: Sie ändern im Tool jeweils genau einen Wert und speichern; das Plugin vergleicht vorher und nachher.

Öffnen Sie den Reiter **«Feldzuordnung»**. Oben steht der Stand: «Feldzuordnung nicht geprüft – jeder Abgleich ist ein Probelauf.» Darunter die Liste der Felder, jeweils mit «gespeichert als …» oder «nicht vorhanden» und einem Haken, sobald es gelernt ist: «Datum», «Beginn», «Ende», «Wöchentliches Angebot», «Wochentag», «abgesagt», «Ferienpause», «Organisation und Information», «Raum».

Hinweis: Das Plugin zeigt bei jedem Schritt eine Anweisung in einem Kasten. Sie gilt, auch wenn sie von der Wiedergabe hier abweicht.

**Schritt 0: Lernentwurf anlegen.**
1. Legen Sie im Veranstaltungstool (im Bearbeitungsbildschirm der Veranstaltungen) eine neue Veranstaltung an. Titel: **«Enter Once Lernentwurf»**. Füllen Sie sonst nichts aus, lassen Sie alle Schalter aus und speichern Sie sie **als Entwurf**.
2. Klicken Sie im Assistenten auf **«Entwurf gespeichert – weiter»**. Das Plugin findet den Entwurf und erfasst alle gespeicherten Felder: «Lernentwurf gefunden (ID …). Alle gespeicherten Felder wurden erfasst.»
3. **Zweiter Ausgangszustand.** Speichern Sie den Lernentwurf im Bearbeitungsbildschirm **noch einmal, ohne etwas zu ändern**, und klicken Sie auf **«Gespeichert – vergleichen»**. So erkennt der Assistent Felder, die andere Plugins bei jedem Speichern selbst ändern (zum Beispiel SEO- oder Übersetzungs-Plugins). Diese Felder beachtet er danach nicht mehr. Meldung: «Zweiter Ausgangszustand erfasst. Keine Felder ändern sich von selbst.» oder «Zweiter Ausgangszustand erfasst. Diese Felder ändern sich bei jedem Speichern von selbst und werden nicht beachtet: …»

**Schritte 1 bis 9: ein Feld nach dem anderen.** Bei jedem Schritt ändern Sie im Lernentwurf **nur den einen verlangten Wert**, speichern im Tool und klicken im Assistenten auf **«Gespeichert – vergleichen»**. Mit «Lernentwurf öffnen» kommen Sie zum Entwurf.

| Schritt | Was Sie im Lernentwurf tun |
|---|---|
| «Datum» | Datum auf den 23.04.2031 setzen und speichern. |
| «Beginn» | Beginn auf 07:45 Uhr setzen. |
| «Ende» | Ende auf 09:05 Uhr setzen. |
| «Wöchentliches Angebot» | «Wöchentliches Angebot» einschalten. |
| «Wochentag» | Wochentag «Sonntag» wählen. Hat das Tool kein Feld dafür, klicken Sie auf **«Dieses Feld gibt es nicht»**. |
| «abgesagt» | «abgesagt» einschalten. |
| «Ferienpause» | «abgesagt» wieder ausschalten, «Ferienpause» einschalten. |
| «Organisation und Information» | In dieses Feld nur den Text «Enter-Once-Info-4711» schreiben. Gibt es das Feld nicht: «Dieses Feld gibt es nicht». |
| «Raum» | Hat das Tool ein Feld für den Raum, schreiben Sie dort «Enter-Once-Raum-4711». Sonst «Dieses Feld gibt es nicht». |

Nach jedem erfolgreichen Schritt meldet das Plugin: «…» gelernt. Bei einem übersprungenen Feld: «…»: im Tool nicht vorhanden. Das Feld «Datum», «Beginn», «Ende», «Wöchentliches Angebot», «abgesagt», «Ferienpause» ist nötig: «Dieses Feld ist nötig und kann nicht übersprungen werden.»

**Wenn ein Schritt nicht klappt**, meldet das Plugin zum Beispiel:

| Meldung | Was zu tun ist |
|---|---|
| «Kein Entwurf mit dem Titel «Enter Once Lernentwurf» gefunden. Bitte zuerst im Veranstaltungs-Tool anlegen und als Entwurf speichern.» | Entwurf anlegen oder Titel prüfen. |
| «Seit dem letzten Schritt hat sich kein gespeichertes Feld verändert. Haben Sie im Bearbeitungsbildschirm gespeichert?» | Speichern und wiederholen. |
| «Es hat sich mehr als ein Feld verändert. Bitte nur den einen Schalter umstellen.» | Nur den verlangten Schalter ändern. Klicken Sie danach auf **«Ausgangszustand neu erfassen»** und stellen Sie den Entwurf zurück. |
| «Kein geändertes Feld enthält den verlangten Wert in einem bekannten Format.» | Den Wert genau wie verlangt eingeben. Hilft das nicht: Das Feld wird vom Tool anders gespeichert (zum Beispiel nicht als Beitrags-Metafeld). Dann braucht es die Entwicklerin oder den Entwickler des Tools. |
| «Kein geändertes Feld enthält den Text «…».» / «Mehrere Felder enthalten den Text «…».» | Text genau eingeben; bei «mehrere» Hilfe der Entwicklerin oder des Entwicklers. |
| «Das geänderte Feld … ist bereits als «…» gelernt. Vermutlich wurde der falsche Wert geändert. …» | Das Plugin lernt ein Feld nicht auf einen gespeicherten Schlüssel, den schon ein anderer Wert verwendet. Stellen Sie den vorherigen Wert zurück, klicken Sie auf «Ausgangszustand neu erfassen» und ändern Sie nur den verlangten Wert. |

Jede solche Meldung nennt am Schluss «Geänderte Felder: …». Das hilft bei der Fehlersuche. Jede Fehlermeldung eines Lernschritts endet mit dem Hinweis: «Lässt sich dieser Schritt auch nach «Ausgangszustand neu erfassen» nicht lernen, speichert das Tool das Feld vermutlich nicht als gewöhnliches Beitragsfeld (Post-Meta). Dann braucht es die Entwicklerin oder den Entwickler des Tools.» «Ausgangszustand neu erfassen» meldet: «Ausgangszustand neu erfasst. Ändern Sie jetzt nur den verlangten Wert.»

**Letzter Schritt: Prüfentwurf.**
1. Klicken Sie auf **«Prüfentwurf anlegen»**. Das Plugin schreibt selbst einen Entwurf «Enter Once Prüfentwurf» (auf demselben Weg wie später der Abgleich): «Prüfentwurf angelegt. Bitte im Tool öffnen und prüfen.»
2. Öffnen Sie ihn mit **«Im Bearbeitungsbildschirm öffnen»** und mit **«Vorschau öffnen»**.
3. Vergleichen Sie mit der Liste «Im Prüfentwurf muss stehen:»: Datum 14.05.2031, Beginn 18:30, Ende 20:00, Wöchentliches Angebot ein, Wochentag Mittwoch, abgesagt aus, Ferienpause ein, «Organisation und Information»: «Prüfraum, Musterstrasse 1, 4000 Basel», Raum «Prüfraum», Titel «Enter Once Prüfentwurf». Eine Tabelle mit den Spalten «Erwartet», «Gelesen» und «Selbstprüfung» («gelesen wie geschrieben» oder «abweichend») zeigt, was das Plugin selbst zurückliest. **Die Selbstprüfung ersetzt Ihre Prüfung nicht.**
4. Prüfen Sie auch auf der öffentlichen Programmseite (Vorschau) die Darstellung: Wochentag, Zeit, Vermerke «abgesagt» und «Ferienpause», Text in der Seitenleiste.
5. Stimmt alles: **«Alles stimmt – Zuordnung bestätigen»**. Dieser Knopf erscheint **nur, wenn jede Zeile «gelesen wie geschrieben» zeigt und der Prüfentwurf noch existiert.** Meldung danach: «Feldzuordnung bestätigt. Lern- und Prüfentwurf liegen im Papierkorb.» Sonst steht dort: «Die Selbstprüfung zeigt Abweichungen. Die Zuordnung kann so nicht bestätigt werden. Klicken Sie auf «Neu lernen» und gehen Sie die Schritte noch einmal durch.» Wurde der Prüfentwurf gelöscht: «Der Prüfentwurf existiert nicht mehr. Bitte legen Sie ihn neu an.»

**Was Sie dabei besonders beobachten sollten** (so empfiehlt es die Entwicklerdokumentation):
- Ein Feld wird nicht erkannt («Kein geändertes Feld …»).
- Das Tool berechnet beim Speichern etwas selbst (zum Beispiel den Wochentag aus dem Datum).
- Für einen Wert werden mehrere Felder gelernt; prüfen Sie sie.
- Das Einschalten von «Wöchentliches Angebot» blendet weitere Felder ein.

**Nicht abgedeckt** vom Assistenten sind (er sagt es auf seiner Seite selbst) Felder, die nicht als Beitrags-Metafelder gespeichert sind (zum Beispiel in einer eigenen Tabelle), Werte, die das Tool aus mehreren Eingaben berechnet, und Felder, die sich nur gemeinsam mit anderen ändern. Diese zeigen sich im Prüfentwurf oder brauchen die Entwicklerin oder den Entwickler des Tools (Lieferweg 2 des Plans).

Nach der Bestätigung zeigt der Reiter «Feldzuordnung»: «Die Feldzuordnung ist geprüft. Der Abgleich darf jetzt schreiben, sobald der Probelauf des Zentrums ausgeschaltet ist.» Der Probelauf des Zentrums bleibt dennoch eingeschaltet, bis Sie ihn selbst ausschalten (Abschnitt 10). Mit **«Neu lernen»** löschen Sie die Zuordnung wieder; der Abgleich ist dann erneut ein Probelauf. Wird die Zuordnung danach wieder bestätigt, schreibt der nächste Abgleich **jeden verwalteten Eintrag einmal neu**.

Prüfen Sie ausserdem (R-10): Nach dem ersten echten Schreiben dürfen **keine Kopien** durch die Box «Serien- und Mehrfachtermine» entstehen.

## 6. Bestehende Wöchentliche Angebote übernehmen

Union hat Wöchentliche Angebote schon von Hand eingetragen. Sie entsprechen dem Kalender nicht eins zu eins (andere Titel, zwei Angebote zur selben Zeit, ein Angebot für zwei Serien). **Übernehmen Sie sie, bevor Sie den Probelauf ausschalten.** Sonst entstehen Doppelte.

Voraussetzungen: Die Feldzuordnung ist gelernt, und ein Abgleich (auch ein Probelauf) wurde ausgeführt. Sonst meldet der Reiter «Bestehende Angebote übernehmen»: «Zuerst die Feldzuordnung lernen; ohne sie lassen sich Wöchentliche Angebote nicht erkennen.» oder «Noch keine Serien bekannt. Führen Sie zuerst einen Abgleich aus (ein Probelauf genügt).»

Das Plugin ordnet **nie von selbst** zu. Unter «Wöchentliche Angebote auf der Website» steht eine Karte pro Angebot, zum Beispiel «Titel (Montag 09:15–11:00)». Je Karte:

1. Wählen Sie «Nicht übernehmen» oder eine der vorgeschlagenen Serien (gleicher Wochentag, überlappende Zeit). Gibt es keine, steht dort «Keine Serie mit gleichem Wochentag und überlappender Zeit.» Ein Angebot kann nur mit **einer** Serie verbunden werden.
2. Das Häkchen **«Text der Website behalten»** ist voreingestellt. Titel und Text der Website bleiben dann, wie sie sind. Entfernen Sie das Häkchen nur, wenn der Text aus dem Kalender den Text der Website ersetzen soll.
3. Klicken Sie auf **«Vergleich anzeigen»**. Das ist der einzige Weg zu «Übernehmen». Die Tabelle «Vergleich: «…» und «…»» zeigt Zeile für Zeile («Titel», «Text», «Wochentag», «Beginn», «Ende», «Themen», «Zielgruppen», «Zentren») die Spalten «Website», «Kalender» und «Nach der Übernahme» («gleich», «bleibt wie auf der Website», «wird aus dem Kalender übernommen»). Bei den «Zentren» steht «(wird ergänzt; andere Zentren bleiben)»: Andere Zentren eines Angebots werden nie entfernt. Gibt der Kalender keine Themen oder Zielgruppen an, bleiben die bestehenden.
   Ist das Angebot auf der Website zurzeit nicht veröffentlicht, steht dort: «Dieses Angebot ist zurzeit nicht veröffentlicht («…»). Es behält diesen Status, ausser Sie wählen:» mit dem Häkchen «Mit dem nächsten Abgleich veröffentlichen».
4. Klicken Sie in der Vergleichsansicht auf **«Übernehmen»** (oder auf «Abbrechen»). Das Plugin speichert vor dem ersten Schreiben eine Kopie des Eintrags (eine Revision) und meldet: ««…» übernommen. Vor dem ersten Schreiben wurde eine Kopie (Revision) gespeichert. Der nächste Abgleich übernimmt Zeit, Status und Themen.»

**Rückgängig machen:** Unter «Übernommene Angebote» steht pro Angebot «übernommen am …» und der Knopf **«Übernahme rückgängig machen»**. Danach sind Titel, Text, Status, Felder und Begriffe wieder wie vorher, und der Abgleich verändert das Angebot nicht mehr. Gibt es keine Kopie von vor der Übernahme, meldet das Plugin: «Für diesen Eintrag gibt es keine Kopie von vor der Übernahme.»

Nicht übernommene Angebote bleiben für immer unverändert. Unterschiede zwischen Kalender- und Website-Text erscheinen in der E-Mail-Zusammenfassung als Hinweis.

Fehlermeldungen: «Eintrag nicht gefunden.», «Dieses Angebot ist bereits übernommen.», «Diese Serie ist nicht verfügbar oder bereits mit einem anderen Angebot verbunden.», «Wochentag oder Zeit passen nicht zu dieser Serie.»

## 7. Die übrigen Einstellungen

Reiter **«Einstellungen»**. Die Voreinstellungen sind so gewählt, dass Sie in der Regel nichts ändern müssen. Alle Entscheidungen dahinter stehen in `Entscheidungen.md`.

| Abschnitt | Einstellung | Voreinstellung | Wann ändern |
|---|---|---|---|
| «Abgleich» | «Abgleich-Abstand (Minuten)» | 15 (mindestens 5) | Selten. |
| | «Warnung «veraltet» nach (Minuten)» | 60 | Wenn Ihr Zeitplaner seltener läuft. |
| | «Vorschau-Zeitraum (Monate)» | 3 | Wenn die Quelle weiter reicht (Google). |
| | «Sicherheitsabstand (Abgleiche)» | 2 | Wie oft hintereinander ein Eintrag fehlen muss, bevor er auf «Entwurf» geht. |
| | «Obergrenze (Entfernungen pro Abgleich)» | 5 | Wenn legitime Grossänderungen oft angehalten werden. |
| «Kennzeichen» | «Interne Einträge» | Nicht öffentlich markierte verwerfen; Kategorie «Intern» | Nur wenn Union andere Markierungen benutzt. |
| | «Abgesagt-Kennzeichen» | Abgesagt:, Canceled:, Cancelled: | Wortliste anpassen. Ein Wort pro Zeile. |
| | «Abgesagte Veranstaltungen» | «Ausblenden (Entwurf)» | «Mit Vermerk ‹abgesagt› anzeigen», wenn Abgesagte sichtbar bleiben sollen. |
| «Wöchentliche Angebote» | «Wöchentliche Termine» | «Als Wöchentliches Angebot» | «Jeder Termin einzeln». Nach dem Start werden die Einträge neu aufgebaut. |
| | «Mindestanzahl Termine einer Serie» | 4 | |
| | «Vorlauf für Wöchentliche Angebote (Tage)» | 14 | |
| | «Hinweis bei verschobenem Termin» | ein («Oben im Text des Angebots anzeigen») | |
| «Zuordnung» | «Zuordnung Kategorien» | siehe `Entscheidungen.md` | Eine Zeile pro Kategorie: `Kalender = Thema; Thema`. `Kalender = –` heisst: nicht übertragen. |
| | «Zuordnung Zielgruppen» | siehe `Entscheidungen.md` | Dasselbe Muster. Beispiel: `Familie = Für Kinder; Für Erwachsene`. |
| | «Schreibvarianten (Synonyme)» | bekannte Varianten | `Variante = Kalenderwert`, zum Beispiel `Familien = Familie`. |
| «Website des Verbands» | «Sprache (WPML)» | «Nur Standardsprache» | «In alle Sprachen duplizieren», wenn Einträge in anderen Sprachen fehlen (Abschnitt 11). Ohne WPML steht dort: «WPML ist auf dieser Website nicht aktiv; die Einstellung hat keine Wirkung.» |
| | «Text der Website behalten» | ein | Vorgabe für das Übernehmen. |
| | «Feldzuordnung» | «nicht geprüft» | Wird nur vom Assistenten geändert («Zum Assistenten»). |
| «Bilder» | siehe Abschnitt 9 | | |
| «Öffentlicher Feed» | «Öffentlicher Feed» | ein | Ausschalten, wenn Unions Website den Feed nicht nutzen soll. |
| | «Erlaubte Websites für den Feed» | `*` (alle) | Eine Adresse pro Zeile, z. B. `https://www.union-basel.ch`. Beschränken schützt nichts, die Daten sind öffentlich. |
| | «Zwischenspeicher des Feeds (Sekunden)» | 300 | |
| «Benachrichtigung» | «Häufigkeit» | «täglich» | «bei jedem Abgleich» oder «wöchentlich». |
| «KI-Funktionen» | «KI-Funktionen» | aus | «In dieser Version nicht enthalten.» |

Speichern Sie mit **«Einstellungen speichern»**.

## 8. Der Zeitplaner

**Mit Server-Zeitplaner (empfohlen):** Bittet die Hosting-Firma, folgende Zeile einzurichten (der Pfad zu WordPress ist ein Platzhalter):

```
*/15 * * * * wp --path=/pfad/zu/wordpress enteronce sync --quiet
```

Der Reiter «Status» zeigt diese Zeile unten ebenfalls: «Kann der Server einen Zeitplaner-Eintrag ausführen, ist diese Zeile zuverlässiger:». Sie richtet sich nach dem Feld «Abgleich-Abstand (Minuten)» (bei 120 Minuten zum Beispiel `0 */2 * * * …`). Voraussetzung ist, dass WP-CLI (`wp`) auf dem Server vorhanden ist. Ob das beim Verband der Fall ist, ist offen.

**Ohne Server-Zeitplaner:** Das Plugin läuft über den WordPress-eigenen Zeitplaner. Der Status zeigt «Nächster automatischer Abgleich (WordPress-Zeitplaner): …». Dieser Zeitplaner startet nur, wenn jemand die Website besucht. Bei wenig Besuch läuft der Abgleich daher unregelmässiger. Hat in den letzten zwei Abständen ein Abgleich über die Befehlszeile stattgefunden, lässt der WordPress-Zeitplaner aus.

Nützliche Befehle (für technisch versierte Personen):

- `wp enteronce sync [--source=union]`: sofort abgleichen.
- `wp enteronce held [--source=union]`: zeigt die angehaltene Liste und ihren **Fingerabdruck**. Ausgabe zum Beispiel: «union: Abgleich angehalten, 6 Einträge würden entfernt:», dann eine Zeile «  - Titel» je Eintrag, «Fingerabdruck: …» und «Anwenden: wp enteronce sync --source=union --release-held=<Fingerabdruck>». Ohne Anhaltung: «union: kein angehaltener Abgleich.»
- `wp enteronce sync --source=union --release-held=<Fingerabdruck>`: «Trotzdem anwenden» in der Befehlszeile. Der Befehl wendet die Liste **nur an, wenn sie noch denselben Fingerabdruck hat**, genau wie der Knopf. Meldung bei Erfolg: «Angehaltene Liste angewendet: … Einträge auf Entwurf gesetzt.»
- `wp enteronce status`: Stand jedes Zentrums (Adresse verdeckt).
- `wp enteronce digest [--force]`: fällige Zusammenfassungen senden.

## 9. Bilder

Entscheidend ist **«Medien»** (Medienbibliothek):

1. Laden Sie das Bild hoch. Der Dateiname entspricht dem Titel, zum Beispiel `Offener_Deutschtreff.jpg`. Gross- und Kleinschreibung, Leerzeichen, Unterstriche und Bindestriche spielen keine Rolle.
2. Öffnen Sie das Bild in der Medienbibliothek. Im Bearbeitungsbildschirm gibt es das Feld **«Zentrum (Enter Once)»**. Wählen Sie «Quartierzentrum Union» (Voreinstellung: «– keinem Zentrum –»). **Nur dann gilt das Bild für Unions Veranstaltungen.**
3. Speichern Sie. Das Feld «Zentrum (Enter Once)» können nur Administratorinnen und Administratoren setzen.

Reihenfolge: Bild der Veranstaltung, dann Kategoriebild, dann Standardbild. Ein von Hand gesetztes Beitragsbild ersetzt das Plugin nie.

Im Reiter «Einstellungen» → Abschnitt **«Bilder»**:

- «Bilder»: «Aus der Medienbibliothek» (der einzige Modus).
- «Kategoriebilder»: eine Zeile pro Kategorie, `Kategorie = Bild-ID`. Die Bild-ID steht in der Medienbibliothek in der Adresse des Bildes.
- «Standardbild (Bild-ID)».

Bereits übernommene Angebote behalten das Bild, das sie haben. Für Unions eigene Website müssen die dort verwendeten Bilder **einmal** hochgeladen werden (Teil der Umstellung, siehe `Website-Anleitung.md`).

## 10. Zwei Wochen Probelauf, dann live

1. **Zwei Wochen Probelauf.** Das Plugin zeigt, was es tun würde. Die Mitarbeitenden erfassen weiter von Hand auf der Verbands-Website. Vergleichen Sie täglich oder wöchentlich den Reiter «Status» («Geplante Aktionen (Probelauf)») mit der Website. Der Abgleichsbericht (`Abgleichsbericht.md`) hilft dabei.
2. **Vereinbaren Sie ein Datum**, ab dem live geschaltet wird. Informieren Sie das Team.
3. **Vorher:** Wurden die bestehenden Wöchentlichen Angebote übernommen (Abschnitt 6)? Wurden Bilder und Begriffe geprüft?
4. **Umschalten:** Reiter «Einstellungen» → «Zentren» → Häkchen bei «Probelauf» entfernen → «Einstellungen speichern». Der nächste Abgleich schreibt. Die Zeile «Probelauf» im Status sagt jetzt «Aus».
5. **Von Hand eingetragene Veranstaltungen** (dated events) ab dem Stichtag werden **einmal von Hand entfernt**. Es gibt keinen automatischen Abgleich nach Titel.
6. Beobachten Sie die erste Woche. Kontrollieren Sie, dass die Seite `/programm/` aktuell ist (WP Rocket, Abschnitt 11).

Erst zwei Wochen danach folgt, wenn gewünscht, Unions Website (`Website-Anleitung.md`).

## 11. Was zu prüfen ist, weil es nie am echten System lief

Gehen Sie diese Liste bei der ersten echten Installation durch (aus der Entwicklerdokumentation):

1. Auf einer Kopie der echten Website beginnen, wenn es eine gibt.
2. Den Assistenten durchlaufen und beobachten (Abschnitt 5).
3. Prüfentwurf im Tool und auf der öffentlichen Seite prüfen, auch die Liste «Wöchentliche Angebote» unter `/programm/`.
4. Prüfen, dass das Schreiben keine «Serien- und Mehrfachtermine» auslöst.
5. «Begriffe neu zuordnen»: findet das Plugin alle Themen und Zielgruppen? Ist bei Union das richtige Zentrum gewählt?
6. **WPML:** Sind die vom Plugin angelegten Einträge in jeder Sprache sichtbar, die die Website zeigt? Wenn nicht: «Sprache (WPML)» → «In alle Sprachen duplizieren». Das ist ungetestet.
7. **WP Rocket:** Sind nach einem Lauf die geänderten Einträge und `/programm/` frisch? Ungetestet.
8. Zwei Wochen Probelauf mit dem Abgleichsbericht.
9. Bestehende Angebote übernehmen, bevor der Probelauf ausgeschaltet wird.
10. Zeitplaner: Wie lautet die Zeile der Hosting-Firma?
11. **E-Mail-Zustellung** vom Server des Verbands (Zusammenfassung und Fehlermails).

## 12. Betrieb: Statusseite, angehaltene Abgleiche, E-Mails

### Statusseite

Reiter **«Status»**. Pro Zentrum eine Tabelle: «Kalender-Adresse (ICS)» (verdeckt), «Zentrum auf der Verbands-Website», «Zentrum aktiv», «Probelauf», «Letzter Abgleich», «Letzter erfolgreicher Abgleich», «Öffentlicher Feed», «Termine im Vorschau-Zeitraum», «Abgesagte Termine», «Interne Einträge verworfen». Dazu die Tabelle «Änderungen des letzten Abgleichs» (oder «Keine Änderungen.») und «Warnungen und Hinweise». Der Knopf **«Jetzt abgleichen»** startet einen Abgleich. Danach: «Abgleich abgeschlossen: … neu, … geändert, … auf Entwurf, … angehalten.»

**«Braucht eine Person:»** Einträge des Plugins, die im Papierkorb liegen, privat gestellt sind, auf Freigabe warten oder zur Veröffentlichung geplant sind, **fasst das Plugin nicht an**. Es listet sie auf dem Reiter «Status» und in der Zusammenfassung unter «Warnungen», zum Beispiel: ««…» liegt im Papierkorb und wird deshalb nicht mehr abgeglichen. Bitte wiederherstellen oder endgültig löschen.» Sehen Sie sich den Eintrag an und stellen Sie ihn wieder her oder löschen Sie ihn endgültig.

Neue Einträge legt das Plugin zuerst als **Entwurf** an und veröffentlicht sie als letzten Schritt. Ein Abgleich hat ein Zeitbudget. Reicht es nicht, steht auf dem Reiter «Status»: «… Änderungen folgen beim nächsten Abgleich (Zeitbudget erreicht).» Die übrigen Änderungen kommen dann im nächsten Lauf.

Weitere Meldung: «Veraltet: seit mehr als … Minuten kein erfolgreicher Abgleich.» (siehe Fehlersuche).

### Angehaltener Abgleich und «Trotzdem anwenden»

Würde ein Abgleich auf einmal mehr Einträge entfernen als die «Obergrenze» (voreingestellt 5), entfernt er **keinen**. Auf dem Reiter «Status» steht: «Abgleich angehalten: … Einträge würden auf einmal entfernt. Das ist mehr als die Obergrenze.» mit der Liste. Zugleich geht eine E-Mail «Enter Once: Abgleich angehalten (…)» raus. Solange der Abgleich angehalten ist, behält der Feed für Unions Website seinen bisherigen Inhalt; Termine, die aus dem Kalender verschwinden, bleiben im Feed ebenfalls zwei Abgleiche lang.

1. Prüfen Sie den Kalender. Wurde wirklich so viel gelöscht (Panne, falscher Kalender, Quellenwechsel)?
2. Stimmt es, klicken Sie auf **«Trotzdem anwenden»**. Das gilt **nur für die Liste, die Sie gesehen haben.** Hat sich die Liste inzwischen geändert, wird nichts entfernt und das Plugin meldet: «Die Liste der angehaltenen Einträge hat sich seit der Anzeige geändert. Es wurde nichts entfernt. Bitte prüfen Sie die neue Liste und klicken Sie erneut auf «Trotzdem anwenden».» Stimmt es nicht, beheben Sie zuerst den Kalender; der nächste Abgleich erledigt es.
3. In der Befehlszeile zeigt `wp enteronce held` die Liste und ihren Fingerabdruck. Mit `wp enteronce sync --source=<Kennung> --release-held=<Fingerabdruck>` wenden Sie genau diese Liste an. Hat sich die Liste geändert, wird nichts entfernt: «Die Liste der angehaltenen Einträge hat sich seit der Anzeige geändert. Es wurde nichts entfernt. …» Dann lassen Sie sich mit `wp enteronce held` den neuen Fingerabdruck zeigen. Ohne Zentrum oder Fingerabdruck verweigert der Befehl und entfernt nichts: «Bitte das Zentrum angeben: --source=<Kennung>.» beziehungsweise «Bitte den Fingerabdruck der angehaltenen Liste angeben: --release-held=<Fingerabdruck>. …». Bleibt die Anhaltung bestehen: «Kein Eintrag entfernt: der Abgleich ist weiterhin angehalten.»

### E-Mails

| Betreff | Wann | Inhalt |
|---|---|---|
| «Enter Once: Zusammenfassung für …» | höchstens so oft wie «Häufigkeit» (voreingestellt täglich) | «Neu auf der Website», «Geändert», «Auf Entwurf gesetzt», «Verschobene Termine», «Warnungen», «Hinweise» und der Link «Status». |
| «Enter Once: Fehler beim Abgleich (…)» | sofort; derselbe Fehler höchstens einmal pro Tag | «Der Abgleich für … ist fehlgeschlagen. Auf der Website wurde nichts verändert. Grund: …» |
| «Enter Once: Abgleich angehalten (…)» | sofort; dieselbe Liste höchstens einmal pro Tag | Siehe oben. |

Fehler-E-Mails sind pro Art des Fehlers auf eine pro Tag begrenzt. Empfänger sind die Adressen unter «Benachrichtigung», sonst die Adresse der Website-Administration. Ob E-Mails vom Server des Verbands ankommen, ist ungetestet (Abschnitt 11).

## 13. Fehlersuche

Fehler stehen auf dem Reiter «Status» unter «Letzter Fehler: …» und in der Fehler-E-Mail. **Bei jedem Fehler bleibt die Website unverändert.**

| Meldung | Bedeutung und Massnahme |
|---|---|
| «Für dieses Zentrum ist keine Kalender-Adresse (ICS) eingetragen.» | Adresse unter «Einstellungen» eintragen. |
| «Die Kalender-Adresse (ICS) muss mit https:// beginnen und darf nicht auf ein internes Netz zeigen.» | Adresse prüfen. |
| «Der Kalender konnte nicht heruntergeladen werden (…).» | Kein Zugriff auf die Adresse. Adresse im Browser prüfen; ist sie noch gültig (Google: wurde sie erneuert)? Wenn die Seite des Anbieters nicht erreichbar ist: warten. |
| «Der Kalender-Server hat mit Status … geantwortet.» | Der Anbieter meldet einen Fehler. Warten oder Adresse prüfen. |
| «Die Kalenderdatei ist grösser als … MB und wird nicht gelesen.» | Die Datei ist zu gross (Grenze 10 MB). Kalender prüfen. |
| «Der Kalender-Server leitet zu oft oder ohne Ziel weiter.» | Das Plugin folgt Weiterleitungen nur zu https-Adressen auf öffentlichen Servern, höchstens dreimal. Adresse prüfen. |
| «Die Kalenderdatei ist leer.» / «Die heruntergeladene Datei ist kein Kalender (BEGIN:VCALENDAR fehlt).» / «Die Kalenderdatei ist unvollständig (END:VCALENDAR fehlt).» / «Die Kalenderdatei kann nicht gelesen werden.» | Die Quelle liefert etwas Unbrauchbares. Adresse und Kalender prüfen. |
| «Die Kalenderdatei enthält keine Termine mehr, beim letzten Abgleich aber schon.» | Schutz gegen einen leeren Kalender. Prüfen Sie, ob der Kalender wirklich leer ist; sonst warten. |
| «Interner Fehler beim Abgleich.» | Technisches Detail steht dabei. An die technische Betreuung weiterleiten. |
| «Ein anderer Abgleich läuft gerade.» | Kurz warten. Die Sperre läuft nach dem Zeitbudget des Abgleichs (plus 120 Sekunden) von selbst ab. |
| «Veraltet: seit mehr als … Minuten kein erfolgreicher Abgleich.» | Der Zeitplaner läuft nicht oder die Abgleiche scheitern. «Jetzt abgleichen» klicken; Fehler lesen; Zeitplaner (Abschnitt 8) prüfen. |
| «Begriff nicht gefunden: …» | Thema oder Zielgruppe fehlt auf der Website. Anlegen oder Zuordnung ändern; «Begriffe neu zuordnen». |
| Der Feed antwortet «Der öffentliche Feed ist ausgeschaltet.», «Unbekanntes Zentrum.» oder «Ungültiger Zeitraum.» | «Öffentlicher Feed» einschalten; Kennung prüfen; Datumsangaben prüfen. |

## 14. Deinstallieren und was bleibt

- **Deaktivieren** («Plugins»): Der Zeitplaner-Eintrag wird entfernt. Alles andere bleibt. Es gibt keinen Abgleich mehr.
- **Löschen** («Plugins» → «Löschen»): Das Plugin entfernt seine **Einstellungen einschliesslich der Kalender-Adresse**, seine Zwischenspeicher und seine Zeitplaner-Einträge. Die **Veranstaltungs-Einträge und ihre Markierungen (`_enteronce_…`) bleiben** in der Datenbank. Das ist aus der Entwicklerdokumentation übernommen und nicht an der echten Website ausprobiert. Die Kalender-Adresse bleibt beim Anbieter gültig; erneuern Sie sie dort, wenn sie nicht mehr gebraucht wird.
- Die Veranstaltungen selbst sind normale Einträge des Tools und bleiben sichtbar. Sie werden aber nicht mehr nachgeführt. Wer sie wieder von Hand bearbeitet, kann das tun; der Hinweis «Dieser Eintrag kommt aus dem Kalender (Enter Once). …» auf der Bearbeitungsseite verschwindet mit dem Plugin.
- Der Rückweg für Unions Website ist in `Website-Anleitung.md` beschrieben.
- Ein Zentrum entfernen Sie in den Einstellungen mit dem Häkchen «Dieses Zentrum aus den Einstellungen entfernen (Einträge auf der Website bleiben)».

## 15. Auf einen Blick: Reihenfolge

1. Sicherung, Testkopie, PHP prüfen.
2. Plugin installieren und aktivieren.
3. Zentrum einrichten (mit Probelauf).
4. Ersten Probelauf machen.
5. Feldzuordnung lernen und prüfen.
6. Bestehende Angebote übernehmen.
7. Zeitplaner einrichten.
8. Bilder: «Zentrum (Enter Once)» setzen.
9. Zwei Wochen Probelauf.
10. Ab vereinbartem Datum: Probelauf ausschalten.
11. Zwei Wochen später (freiwillig): Unions Website.
