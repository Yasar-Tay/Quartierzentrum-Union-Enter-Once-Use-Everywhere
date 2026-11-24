# Interface labels (German) of the WordPress plugin

For the guide writers (`docs/de/`). Every text below is copied from the code (`src/WordPress/`) and appears exactly like this in the interface. Placeholders: `%s`, `%d`. Setting keys refer to `enteronce_settings` (plan section 6.6). The full list of strings is in `languages/enter-once-sync.pot`.

Menu: **«Einstellungen → Enter Once»** (`options-general.php?page=enter-once`). Only administrators (`manage_options`). Plugin list: link «Einstellungen».

## Tabs

| Tab | Purpose |
|---|---|
| «Status» | default tab; last runs, planned or applied actions, warnings, held runs |
| «Einstellungen» | all settings |
| «Feldzuordnung» | the learning wizard (plan 4.7) |
| «Bestehende Angebote übernehmen» | adoption of hand-entered weekly offers (plan 4.7, A-14) |

## Tab «Status»

Per centre a heading with «Name des Zentrums», then a table:

| Row | Content | Key |
|---|---|---|
| «Kalender-Adresse (ICS)» | masked: `https://calendar.google.com/…` | `sources[].ics_url` |
| «Zentrum auf der Verbands-Website» | term name | `sources[].location_term` |
| «Zentrum aktiv» | «Ja» / «Nein» | `sources[].enabled` |
| «Probelauf» | «Ein – es wird nichts geschrieben» / «Aus» | `sources[].dry_run`, `field_map_verified` |
| «Letzter Abgleich» | date and time, or «noch nie» | |
| «Letzter erfolgreicher Abgleich» | date and time, or «noch nie» | |
| «Öffentlicher Feed» | feed address, or «Aus» | `public_feed_enabled` |
| «Termine im Vorschau-Zeitraum» | number | |
| «Abgesagte Termine» | number | |
| «Interne Einträge verworfen» | number | |

Button: **«Jetzt abgleichen»**.

Messages on this tab:

- «Die Feldzuordnung ist noch nicht geprüft. Bis dahin läuft jeder Abgleich als Probelauf und schreibt nichts.» with link «Zur Feldzuordnung».
- «Noch kein Zentrum eingerichtet.» with link «Zu den Einstellungen».
- «Veraltet: seit mehr als %d Minuten kein erfolgreicher Abgleich.» (`stale_after_minutes`, R-30)
- «Letzter Fehler: …» (see «Errors» below)
- Held run (R-13): «Abgleich angehalten: %d Einträge würden auf einmal entfernt. Das ist mehr als die Obergrenze.», the list, button **«Trotzdem anwenden»**.
- «Begriff nicht gefunden: %s»
- «Braucht eine Person:» followed by one of (also in the digest under «Warnungen»):
  - ««%s» liegt im Papierkorb und wird deshalb nicht mehr abgeglichen. Bitte wiederherstellen oder endgültig löschen.»
  - ««%s» ist privat gestellt und wird deshalb nicht verändert.»
  - ««%s» wartet auf Freigabe und wird deshalb nicht verändert.»
  - ««%s» ist zur Veröffentlichung geplant und wird deshalb nicht verändert.»
- «Die Liste der angehaltenen Einträge hat sich seit der Anzeige geändert. Es wurde nichts entfernt. Bitte prüfen Sie die neue Liste und klicken Sie erneut auf «Trotzdem anwenden».» («Trotzdem anwenden» applies only the list that was shown.)
- «%d Änderungen folgen beim nächsten Abgleich (Zeitbudget erreicht).»

Table heading: «Geplante Aktionen (Probelauf)» or «Änderungen des letzten Abgleichs»; empty: «Keine Änderungen.» Columns «Aktion», «Art», «Titel», «Wann», «Grund».

- Aktion: «Neu», «Ändern», «Auf Entwurf setzen», «Angehalten», «Unverändert».
- Art: «Wöchentliches Angebot», «Veranstaltung».
- Wann: «Jeden Montag, 09:00–10:00» or «25.11.2026, 19:00–23:00».
- Grund: «neu im Kalender», «im Kalender geändert», «wieder im Kalender», «neue Serie, bestehender Entwurf wird weiterverwendet», «fehlt im Kalender», «abgesagt», «Serie beendet», «fehlt im Kalender (Sicherheitsabstand)»; for a moved date the note is appended: «… – Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr im Raum Grosser Saal».

Heading «Warnungen und Hinweise», entries «Warnung: …» / «Hinweis: …» (texts under «Notices»).

Footer: «Nächster automatischer Abgleich (WordPress-Zeitplaner): %s» and «Kann der Server einen Zeitplaner-Eintrag ausführen, ist diese Zeile zuverlässiger:» followed by the command line, built from «Abgleich-Abstand (Minuten)» (e.g. `*/15 * * * * wp --path=… enteronce sync --quiet`, for 120 minutes `0 */2 * * * …`). The feed address works with plain and pretty permalinks.

Messages after a button (top of the page):

- «Probelauf abgeschlossen: %1$d neu, %2$d geändert, %3$d auf Entwurf, %4$d angehalten (nichts geschrieben).»
- «Abgleich abgeschlossen: %1$d neu, %2$d geändert, %3$d auf Entwurf, %4$d angehalten.»
- «Ein anderer Abgleich läuft gerade.» / «Unbekanntes Zentrum.» / «Abgleich nicht ausgeführt.»

## Tab «Einstellungen»

Button at the end: **«Einstellungen speichern»**; below the form **«Begriffe neu zuordnen»** (resolves term names to IDs again). After saving: «Einstellungen gespeichert.»; «Begriff nicht gefunden: %s. Legen Sie ihn auf der Website an oder ändern Sie die Zuordnung.»; after «Begriffe neu zuordnen»: «Alle Begriffe wurden gefunden.» or «Nicht gefunden: …».

### Section «Zentren» (one block per centre, plus «Neues Zentrum hinzufügen»)

| Label | Key | Notes / help text |
|---|---|---|
| «Kennung» | `sources[].id` | «Kurz, ohne Leerzeichen, z. B. union. Wird im Feed verwendet und kann später nicht geändert werden.» |
| «Name des Zentrums» | `sources[].label` | |
| «Kalender-Adresse (ICS)» | `sources[].ics_url` | password field; «Anzeigen» reveals it; checkbox «Adresse löschen». Help: «Gespeichert: https://…/…. Leer lassen, um die Adresse zu behalten.» or «Geheim: wird nur hier gezeigt und nie protokolliert.» |
| «Zentrum auf der Verbands-Website» | `sources[].location_term` | list of centres; «– bitte wählen –» |
| «Benachrichtigung» | `sources[].notify_emails` | «Eine E-Mail-Adresse pro Zeile. Leer: die Adresse der Website-Administration.» |
| «Zentrum aktiv» | `sources[].enabled` | checkbox «Automatisch abgleichen» |
| «Probelauf» | `sources[].dry_run` | checkbox «Nur zeigen, was geändert würde; nichts schreiben»; help «Solange die Feldzuordnung nicht geprüft ist, gilt der Probelauf immer.» |
| «Entfernen» | — | checkbox «Dieses Zentrum aus den Einstellungen entfernen (Einträge auf der Website bleiben)» |

Errors: «Die Kennung «%s» kommt zweimal vor.»; ««%s»: Die Kalender-Adresse (ICS) muss mit https:// beginnen. Die bisherige Adresse bleibt.»; ««%s»: ungültige E-Mail-Adresse wurde entfernt.»

### Section «Abgleich»

| Label | Key | Default |
|---|---|---|
| «Abgleich-Abstand (Minuten)» | `sync_interval_minutes` | 15 (minimum 5) |
| «Warnung «veraltet» nach (Minuten)» | `stale_after_minutes` | 60 |
| «Vorschau-Zeitraum (Monate)» | `window_months_ahead` | 3 |
| «Sicherheitsabstand (Abgleiche)» | `removal_grace_runs` | 2; help «So oft hintereinander muss eine Veranstaltung im Kalender fehlen, bevor sie auf «Entwurf» gesetzt wird.» |
| «Obergrenze (Entfernungen pro Abgleich)» | `bulk_unpublish_limit` | 5; help «Würden mehr Einträge auf einmal entfernt, wird keiner entfernt und eine E-Mail verschickt.» |

### Section «Kennzeichen»

| Label | Key | Notes |
|---|---|---|
| «Interne Einträge» | `internal_markers` | checkbox «Nicht öffentlich markierte Einträge verwerfen» (`class_not_public`); text field for categories, help «Kategorien, die einen Eintrag als intern kennzeichnen, eine pro Zeile.» |
| «Abgesagt-Kennzeichen» | `cancel_prefixes` | «Beginnt ein Titel mit einem dieser Wörter, gilt die Veranstaltung als abgesagt. Ein Wort pro Zeile.» |
| «Abgesagte Veranstaltungen» | `cancel_mode` | «Ausblenden (Entwurf)» = `unpublish`; «Mit Vermerk ‹abgesagt› anzeigen» = `badge` |

### Section «Wöchentliche Angebote»

| Label | Key | Values |
|---|---|---|
| «Wöchentliche Termine» | `weekly_mode` | «Als Wöchentliches Angebot» = `weekly_offer`; «Jeder Termin einzeln» = `per_date` |
| «Mindestanzahl Termine einer Serie» | `weekly_min_dates` | 4 |
| «Vorlauf für Wöchentliche Angebote (Tage)» | `weekly_lead_days` | 14 |
| «Hinweis bei verschobenem Termin» | `moved_date_note` | checkbox «Oben im Text des Angebots anzeigen» |

### Section «Zuordnung»

| Label | Key | Help |
|---|---|---|
| «Zuordnung Kategorien» | `category_map` | «Eine Zeile pro Kategorie: «Kalender = Thema; Thema». «Kalender = –» heisst: nicht übertragen.» |
| «Zuordnung Zielgruppen» | `tag_map` | «Eine Zeile pro Tag: «Kalender = Zielgruppe; Zielgruppe». «Kalender = –» heisst: nicht übertragen.» |
| «Schreibvarianten (Synonyme)» | `synonyms` | ««Variante = Kalenderwert», z. B. «Familien = Familie».» |

Example lines as shown: `Kultur = Kulturelles & Musik`, `Familie = Für Kinder; Für Erwachsene`, `Highlight = –`.

### Section «Website des Verbands»

| Label | Key | Values / notes |
|---|---|---|
| «Sprache (WPML)» | `wpml_mode` | «Nur Standardsprache» = `default_language`; «In alle Sprachen duplizieren» = `duplicate_all`; without WPML: «WPML ist auf dieser Website nicht aktiv; die Einstellung hat keine Wirkung.» |
| «Text der Website behalten» | `adopt_keep_text` | checkbox «Voreinstellung beim Übernehmen bestehender Angebote» |
| «Feldzuordnung» | `field_map_verified` | «geprüft» / «nicht geprüft» – link «Zum Assistenten» (the map itself is changed only by the wizard) |

### Section «Bilder»

| Label | Key | Notes |
|---|---|---|
| «Bilder» | `image_mode` | «Aus der Medienbibliothek» (the only mode). Help: «Ein Bild gilt für ein Zentrum, wenn bei «Medien» im Feld «Zentrum (Enter Once)» das Zentrum gewählt ist. Der Dateiname entspricht dem Titel, z. B. Offener_Deutschtreff.jpg.» |
| «Kategoriebilder» | `category_images` | ««Kategorie = Bild-ID» pro Zeile. Die Bild-ID steht in der Medienbibliothek in der Adresse des Bildes.» |
| «Standardbild (Bild-ID)» | `default_image_id` | |

Media library, edit screen of an image: field **«Zentrum (Enter Once)»** with «– keinem Zentrum –» and the centres; help «Bild für die Veranstaltungen dieses Zentrums. Der Dateiname entspricht dem Titel, z. B. Offener_Deutschtreff.jpg.» (meta `_enteronce_image_source`; not a settings key). Only administrators can change it; other users see the value as text with the help «Nur Administratorinnen und Administratoren können das Zentrum eines Bildes ändern.»

### Section «Öffentlicher Feed»

| Label | Key | Notes |
|---|---|---|
| «Öffentlicher Feed» | `public_feed_enabled` | checkbox «Eingeschaltet» |
| «Erlaubte Websites für den Feed» | `feed_allowed_origins` | ««*» heisst: alle. Sonst eine Adresse pro Zeile, z. B. https://www.union-basel.ch» |
| «Zwischenspeicher des Feeds (Sekunden)» | `feed_cache_seconds` | 300 |

### Section «Benachrichtigung»

| Label | Key | Values |
|---|---|---|
| «Häufigkeit» | `digest_frequency` | «täglich» = `daily`, «bei jedem Abgleich» = `each_run`, «wöchentlich» = `weekly`. Help: «Gilt für die Zusammenfassung. Fehler und angehaltene Abgleiche werden immer sofort gemeldet, derselbe Fehler höchstens einmal pro Tag.» |

### Section «KI-Funktionen»

| Label | Key | Notes |
|---|---|---|
| «KI-Funktionen» | `ai_enabled` | checkbox «Eingeschaltet»; help «In dieser Version nicht enthalten. Ohne KI arbeitet der Abgleich genau gleich.» |

## Tab «Feldzuordnung» (wizard)

Intro: «Der Assistent lernt, wie das Veranstaltungs-Tool seine Felder speichert. Sie ändern im Bearbeitungsbildschirm des Tools jeweils genau einen Wert und speichern; der Assistent vergleicht vorher und nachher. …» and «Der Assistent kann nur Felder lernen, die das Tool als gewöhnliche Beitragsfelder (Post-Meta) speichert. Speichert das Tool ein Feld anders, etwa in einer eigenen Tabelle, braucht es die Entwicklerin oder den Entwickler des Tools.»

«Stand:» «Feldzuordnung geprüft.» / «Feldzuordnung nicht geprüft – jeder Abgleich ist ein Probelauf.»

Numbered list of fields, each with «gespeichert als …» or «nicht vorhanden» and ✓: «Datum», «Beginn», «Ende», «Wöchentliches Angebot», «Wochentag», «abgesagt», «Ferienpause», «Organisation und Information», «Raum».

Instructions per step (shown in a box):

| Step | Text | Buttons |
|---|---|---|
| start | «Legen Sie im Veranstaltungs-Tool eine neue Veranstaltung an. Titel: «Enter Once Lernentwurf». Füllen Sie sonst nichts aus, lassen Sie alle Schalter aus, und speichern Sie sie als Entwurf. Klicken Sie dann auf «Entwurf gespeichert – weiter».» | **«Entwurf gespeichert – weiter»** |
| second baseline | «Speichern Sie den Lernentwurf im Bearbeitungsbildschirm noch einmal, ohne etwas zu ändern. So erkennt der Assistent Felder, die andere Plugins bei jedem Speichern selbst ändern; diese Felder werden danach nicht beachtet.» | «Lernentwurf öffnen», **«Gespeichert – vergleichen»** |
| Datum | «Setzen Sie im Lernentwurf das Datum auf den 23.04.2031 und speichern Sie. Ändern Sie nichts anderes.» | «Lernentwurf öffnen», **«Gespeichert – vergleichen»** |
| Beginn | «Setzen Sie den Beginn auf 07:45 Uhr und speichern Sie.» | same |
| Ende | «Setzen Sie das Ende auf 09:05 Uhr und speichern Sie.» | same |
| Wöchentliches Angebot | «Schalten Sie «Wöchentliches Angebot» ein und speichern Sie.» | same |
| Wochentag | «Wählen Sie als Wochentag «Sonntag» und speichern Sie. Hat das Tool kein Feld für den Wochentag, klicken Sie auf «Dieses Feld gibt es nicht».» | + **«Dieses Feld gibt es nicht»** |
| abgesagt | «Schalten Sie «abgesagt» ein und speichern Sie.» | same as Datum |
| Ferienpause | «Schalten Sie «abgesagt» wieder aus, schalten Sie «Ferienpause» ein und speichern Sie.» | same |
| Organisation und Information | «Schreiben Sie in das Feld «Organisation und Information» nur den Text «Enter-Once-Info-4711» und speichern Sie. Gibt es dieses Feld nicht, klicken Sie auf «Dieses Feld gibt es nicht».» | + «Dieses Feld gibt es nicht» |
| Raum | «Hat das Tool ein Feld für den Raum? Dann schreiben Sie dort «Enter-Once-Raum-4711» und speichern Sie. Sonst klicken Sie auf «Dieses Feld gibt es nicht».» | + «Dieses Feld gibt es nicht» |
| verify | «Alle Felder sind gelernt. Klicken Sie auf «Prüfentwurf anlegen». Öffnen Sie den Prüfentwurf danach im Bearbeitungsbildschirm des Tools und in der Vorschau, und vergleichen Sie mit der Liste unten.» | **«Prüfentwurf anlegen»**, «Im Bearbeitungsbildschirm öffnen», «Vorschau öffnen», **«Alles stimmt – Zuordnung bestätigen»** |
| done | «Die Feldzuordnung ist geprüft. Der Abgleich darf jetzt schreiben, sobald der Probelauf des Zentrums ausgeschaltet ist.» | |

Verification table columns: «Erwartet», «Gelesen», «Selbstprüfung» («gelesen wie geschrieben» / «abweichend»). **«Alles stimmt – Zuordnung bestätigen»** appears only when every row reads «gelesen wie geschrieben»; otherwise: «Die Selbstprüfung zeigt Abweichungen. Die Zuordnung kann so nicht bestätigt werden. Klicken Sie auf «Neu lernen» und gehen Sie die Schritte noch einmal durch.»

New wizard messages: «Zweiter Ausgangszustand erfasst. Keine Felder ändern sich von selbst.» / «Zweiter Ausgangszustand erfasst. Diese Felder ändern sich bei jedem Speichern von selbst und werden nicht beachtet: %s»; «Das geänderte Feld %1$s ist bereits als «%2$s» gelernt. Vermutlich wurde der falsche Wert geändert. Stellen Sie «%2$s» zurück, klicken Sie auf «Ausgangszustand neu erfassen» und ändern Sie nur «%3$s».»; «Der Prüfentwurf existiert nicht mehr. Bitte legen Sie ihn neu an.»; «Die Selbstprüfung zeigt Abweichungen. Die Zuordnung kann so nicht bestätigt werden. Klicken Sie auf «Neu lernen».»; every error of a learning step ends with «Lässt sich dieser Schritt auch nach «Ausgangszustand neu erfassen» nicht lernen, speichert das Tool das Feld vermutlich nicht als gewöhnliches Beitragsfeld (Post-Meta). Dann braucht es die Entwicklerin oder den Entwickler des Tools.»

Always: **«Neu lernen»** (clears `field_map`, sets `field_map_verified` to false). After a failed comparison: **«Ausgangszustand neu erfassen»**.

Verification list «Im Prüfentwurf muss stehen:»: Datum 14.05.2031, Beginn 18:30, Ende 20:00, Wöchentliches Angebot ein, Wochentag Mittwoch, abgesagt aus, Ferienpause ein, Organisation und Information «Prüfraum, Musterstrasse 1, 4000 Basel», Raum «Prüfraum», Titel «Enter Once Prüfentwurf»; per row «Selbstprüfung: gelesen wie geschrieben» / «Selbstprüfung: abweichend». Body of the draft: «Dieser Entwurf wurde vom Enter-Once-Assistenten geschrieben. Er dient nur der Prüfung der Feldzuordnung und wird nach der Bestätigung in den Papierkorb gelegt.»

Wizard messages: «Lernentwurf gefunden (ID %d). Alle gespeicherten Felder wurden erfasst.»; ««%s» gelernt.»; ««%s»: im Tool nicht vorhanden.»; «Kein Entwurf mit dem Titel «Enter Once Lernentwurf» gefunden. Bitte zuerst im Veranstaltungs-Tool anlegen und als Entwurf speichern.»; «Seit dem letzten Schritt hat sich kein gespeichertes Feld verändert. Haben Sie im Bearbeitungsbildschirm gespeichert?»; «Kein geändertes Feld enthält den verlangten Wert in einem bekannten Format.»; «Es hat sich mehr als ein Feld verändert. Bitte nur den einen Schalter umstellen.»; «Kein geändertes Feld enthält den Text «%s».»; «Mehrere Felder enthalten den Text «%s».»; each error ends with «Geänderte Felder: …»; «Dieses Feld ist nötig und kann nicht übersprungen werden.»; «Ausgangszustand neu erfasst. Ändern Sie jetzt nur den verlangten Wert.»; «Prüfentwurf angelegt. Bitte im Tool öffnen und prüfen.»; «Bitte zuerst den Prüfentwurf anlegen und prüfen.»; «Die Feldzuordnung ist unvollständig.»; «Feldzuordnung bestätigt. Lern- und Prüfentwurf liegen im Papierkorb.»

## Tab «Bestehende Angebote übernehmen»

Intro: «Hier verbinden Sie von Hand erfasste Wöchentliche Angebote mit einer Serie aus dem Kalender. Das Plugin schlägt Serien mit gleichem Wochentag und überlappender Zeit vor, verbindet aber nie von selbst. Nicht verbundene Angebote bleiben für immer unverändert.»

Messages: «Zuerst die Feldzuordnung lernen; ohne sie lassen sich Wöchentliche Angebote nicht erkennen.»; «Noch keine Serien bekannt. Führen Sie zuerst einen Abgleich aus (ein Probelauf genügt).»; «Keine nicht übernommenen Wöchentlichen Angebote gefunden.»

Heading «Wöchentliche Angebote auf der Website»; per offer a card «Titel (Montag 09:15–11:00)» with radio buttons «Nicht übernehmen» and one line per candidate «Titel (Montag 09:00–11:00, 2027-01-04 – 2027-06-21)», or «Keine Serie mit gleichem Wochentag und überlappender Zeit.»; checkbox **«Text der Website behalten»** (default `adopt_keep_text`; per record `_enteronce_keep_text`); button **«Vergleich anzeigen»** only. «Übernehmen» exists only in the comparison view.

Comparison: heading «Vergleich: «%1$s» und «%2$s»», columns «Website», «Kalender», «Nach der Übernahme»; rows «Titel», «Text», «Wochentag», «Beginn», «Ende», «Themen», «Zielgruppen», «Zentren» (calendar column: centre name + «(wird ergänzt; andere Zentren bleiben)»); last column «gleich», «bleibt wie auf der Website», «wird aus dem Kalender übernommen» (topics and target groups stay when the calendar gives none). For an offer that is not published: «Dieses Angebot ist zurzeit nicht veröffentlicht («%s»). Es behält diesen Status, ausser Sie wählen:» and checkbox «Mit dem nächsten Abgleich veröffentlichen». Buttons **«Übernehmen»** and «Abbrechen».

Below the offers: heading «Übernommene Angebote», per row the title, «übernommen am %s» and button **«Übernahme rückgängig machen»**. Result: «Übernahme von «%s» rückgängig gemacht: Titel, Text, Status, Felder und Begriffe sind wieder wie vorher. Der Abgleich verändert dieses Angebot nicht mehr.»; error «Für diesen Eintrag gibt es keine Kopie von vor der Übernahme.»

Results: ««%s» übernommen. Vor dem ersten Schreiben wurde eine Kopie (Revision) gespeichert. Der nächste Abgleich übernimmt Zeit, Status und Themen.»; «Nicht übernommen. Dieses Angebot bleibt unverändert.»; errors «Eintrag nicht gefunden.», «Dieses Angebot ist bereits übernommen.», «Diese Serie ist nicht verfügbar oder bereits mit einem anderen Angebot verbunden.», «Wochentag oder Zeit passen nicht zu dieser Serie.»

## Edit screen of a managed record (event tool)

- Normal: «Dieser Eintrag kommt aus dem Kalender (Enter Once). Änderungen hier werden beim nächsten Abgleich überschrieben. Ändern Sie die Veranstaltung im Kalender. Ein hier gesetztes Beitragsbild bleibt erhalten.»
- Adopted with kept text: «Dieser Eintrag kommt aus dem Kalender (Enter Once). Datum, Zeiten und Status werden beim nächsten Abgleich überschrieben, Themen und Zielgruppen nur, wenn der Kalender welche angibt. Titel und Text der Website bleiben erhalten.»

## Texts written into records

- Moved-date note (R-24), top of the body: «Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr im Raum Grosser Saal»; only time: «Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr»; only room: «Am 7. Dezember ausnahmsweise im Raum Grosser Saal»; other day: «Statt am 7. Dezember ausnahmsweise am Dienstag, 8. Dezember, 10:00–11:00 Uhr». (Wording from the core, `src/Core/MovedDateNote.php`.)
- «Organisation und Information» of new records: the calendar's location line.

## Notices (from the core and the shell)

- Warning: ««%1$s»: unbekannte Kategorie (event-type) «%2$s» wurde zu «%3$s» korrigiert.» (also «Zielgruppe (tag)»)
- Warning: ««%1$s»: unbekannte Kategorie (event-type) «%2$s» wurde nicht übernommen.»
- Warning: ««%1$s»: die Serie hat zwei Termine am selben Tag (%2$s) und wird nicht abgeglichen.»
- Info: ««%s» hat keine Kategorie.»
- Info (adopted records): ««%s»: Der Text auf der Website weicht vom Kalender ab (Text der Website wird behalten).» / ««%1$s»: Titel im Kalender lautet «%2$s» (Titel und Text der Website werden behalten).»

## Errors (status page, error e-mail)

- «Für dieses Zentrum ist keine Kalender-Adresse (ICS) eingetragen.»
- «Die Kalender-Adresse (ICS) muss mit https:// beginnen und darf nicht auf ein internes Netz zeigen.»
- «Der Kalender konnte nicht heruntergeladen werden (%s).»
- «Der Kalender-Server hat mit Status %d geantwortet.»
- «Die Kalenderdatei ist grösser als %d MB und wird nicht gelesen.»
- «Der Kalender-Server leitet zu oft oder ohne Ziel weiter.»
- «Die Kalenderdatei ist leer.» / «Die heruntergeladene Datei ist kein Kalender (BEGIN:VCALENDAR fehlt).» / «Die Kalenderdatei ist unvollständig (END:VCALENDAR fehlt).» / «Die Kalenderdatei kann nicht gelesen werden.» / «Die Kalenderdatei enthält keine Termine mehr, beim letzten Abgleich aber schon.»
- «Interner Fehler beim Abgleich.» + technical detail
- Feed: «Der öffentliche Feed ist ausgeschaltet.», «Unbekanntes Zentrum.», «Ungültiger Zeitraum.»

## E-mails

| Subject | When | Body |
|---|---|---|
| «Enter Once: Zusammenfassung für %s» | at most once per «Häufigkeit» (`digest_frequency`) | «Enter Once – Zusammenfassung für %s», then sections «Neu auf der Website (n)», «Geändert (n)», «Auf Entwurf gesetzt (n)», «Verschobene Termine (n)», «Warnungen (n)», «Hinweise (n)», «Status: <link>» |
| «Enter Once: Fehler beim Abgleich (%s)» | at once; the same error at most once a day | «Der Abgleich für %s ist fehlgeschlagen. Auf der Website wurde nichts verändert. Grund: … Status: <link>» |
| «Enter Once: Abgleich angehalten (%s)» | at once (R-13); the same held list at most once a day | «Der Abgleich für %1$s würde %2$d Einträge auf einmal entfernen. Das ist mehr als die Obergrenze; deshalb wurde keiner entfernt.» + list + «Prüfen Sie den Kalender. Wenn alles stimmt, klicken Sie auf der Statusseite auf «Trotzdem anwenden»: <link>» |

Recipients: «Benachrichtigung» of the centre; if empty, the site's administration address.

## Command line (for the guides)

- `wp enteronce held [--source=union]` — shows the held list (R-13) and its fingerprint, in German: «union: Abgleich angehalten, 6 Einträge würden entfernt:», one line «  - Titel» per entry, «Fingerabdruck: <32 Zeichen>», «Anwenden: wp enteronce sync --source=union --release-held=<Fingerabdruck>»; or «union: kein angehaltener Abgleich.» / «Noch kein Zentrum eingerichtet.»
- `wp enteronce sync [--source=union]` — run now.
- `wp enteronce sync --source=union --release-held=<Fingerabdruck>` — «Trotzdem anwenden» on the command line: applies the held list only if it still has that fingerprint, like the button. Messages: «Angehaltene Liste angewendet: %d Einträge auf Entwurf gesetzt.»; «Kein Eintrag entfernt: der Abgleich ist weiterhin angehalten.»; refusals «Bitte das Zentrum angeben: --source=<Kennung>.», «Bitte den Fingerabdruck der angehaltenen Liste angeben: --release-held=<Fingerabdruck>. «wp enteronce held» zeigt die Liste und ihren Fingerabdruck. Es wurde nichts entfernt.», and when the list changed: «Die Liste der angehaltenen Einträge hat sich seit der Anzeige geändert. Es wurde nichts entfernt. … Neuer Fingerabdruck: «wp enteronce held».» `--now` exists only on a development site (constant `ENTERONCE_DEV`); elsewhere: «--now ist nur auf einer Entwicklungs-Website verfügbar (Konstante ENTERONCE_DEV).»
- `wp enteronce status` — state of every centre (address masked).
- `wp enteronce digest [--force]` — send due summaries.
- Server scheduler line: `*/15 * * * * wp --path=/pfad/zu/wordpress enteronce sync --quiet`

Cron schedule name in WordPress: «Enter Once: alle %d Minuten».
