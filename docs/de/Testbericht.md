# Testbericht

*Stand: 24. November 2026. Version des Plugins: 0.1.0. Für Union, den Verband und alle, die entscheiden, ob und wann das Plugin eingesetzt wird.*

Dieser Bericht nennt, was geprüft wurde, womit und mit welchem Ergebnis, und, gleichberechtigt, **was nicht geprüft wurde**. Er sagt nicht mehr, als tatsächlich ausgeführt wurde.

## 1. Kurzfassung

- Das Plugin, der Kern, der Abgleichsbericht und das Widget wurden **nur gegen Nachbildungen** getestet: Testkalender, eine lokale WordPress-Installation mit einem nachgebauten Veranstaltungstool und gespeicherte (nachgebaute) Kopien der Programmseite.
- Die automatischen Tests laufen durch (Abschnitt 3): 208 Einheitentests (1 übersprungen), 64 Integrationstests, Bau und saubere Installation des Zips, 93 Widget-Tests.
- Eine unabhängige Prüfung fand neun Fehler. Alle sind behoben und haben je einen Test. **Diese Tests wurden aber erst nach der Korrektur geschrieben und nicht gegen den alten Code ausgeführt**, weil es keine Versionsgeschichte gibt (Abschnitt 3.4).
- **Nie getestet** wurden: das echte Veranstaltungstool und Theme des Verbands, die Google-Kalender-Quelle, der Outlook-Kalender «Programm», WPML und WP Rocket, ein Screenreader am Widget, andere Browser als Edge und das Lesen der echten Programmseite durch den Abgleichsbericht. **Und niemand hat die Anleitungen bisher von Anfang bis Ende auf einer sauberen Website befolgt.** Siehe Abschnitt 4.

## 2. Womit getestet wurde

| Testdaten | Inhalt |
|---|---|
| `union-beispielkalender.ics` | Von den Veranstaltern des Hackathons gemacht. 11 Veranstaltungen in 12 Einträgen, November 2026 bis März 2027. Enthält absichtlich schwierige Fälle: übersprungene und verschobene Termine, eine Absage, einen Tippfehler, eine fehlende Beschreibung, interne Buchungen. |
| `calendar.ics` | Unions echter veröffentlichter Outlook-Testkalender. 9 Veranstaltungen ab Januar 2027: 5 wöchentliche Serien, 4 einzelne Termine. |
| nachgebautes Tool | Eine lokale WordPress-Installation mit einem nachgebauten Veranstaltungstool (Typ `events`, Begriffe `topic`, `targetgroup`, `location_cat`, Programmseite `/programm/`). Dessen Feldnamen sind **erfunden**; das Plugin kennt sie nicht, das macht den Test des Assistenten aussagekräftig. Der Aufbau folgt den Beobachtungen von aussen vom 23. November 2026. |
| nachgebaute Programmseite | Gespeicherte Kopien, aus Beobachtungen **nachgebaut**, nicht aufgezeichnet. |
| erfundener Feed | Für das Widget: 62 erfundene Einträge, 4. Januar bis 31. März 2027. |

Erwartete Ergebnisse, die die Tests prüfen sollen (Plan, Abschnitt 8): Beispielkalender 71 Termine (17 + 19 + 12 + 19 + 4), 2 interne Einträge verworfen, 1 Absage, 1 Warnung, 1 Hinweis; geschrieben werden 4 Wöchentliche Angebote und 4 einzelne Veranstaltungen. Live-Testkalender: 5 Wöchentliche Angebote und 4 einzelne Veranstaltungen, 5 Hinweise, 0 Warnungen. Ein zweiter Lauf mit gleichem Kalender ändert nichts.

## 3. Was getestet wurde, und was dabei herauskam

### 3.1 Befehle und Ergebnisse

| Teil | Befehl | Ergebnis |
|---|---|---|
| Alles auf der lokalen Testumgebung | `dev/wordpress/test` | **208 Einheitentests (Kern und Abgleichsbericht), 1 übersprungen. 64 Integrationstests (Plugin gegen das nachgebaute Tool). Bau des Zips und saubere Installation: bestanden.** Ergebnis laut dem Entwicklungsteam nach der Korrekturrunde, bei Ausführung ohne gleichzeitige Nutzung der Testwebsite. Ich habe diesen Lauf nicht selbst wiederholt. |
| übersprungen | | Der Test für den Quellenwechsel Google → Outlook, weil die Datei `tests/fixtures/google-invited.ics` fehlt (sie entsteht erst beim Google-Versuch, Lücke G-06). |
| Widget | `npm test` im Ordner `widget` (von mir am 24. November 2026 ausgeführt) | **93 Tests, alle bestanden.** Enthält die automatische Zugänglichkeitsprüfung mit axe-core (keine Verstösse) und die Prüfung der Farbkontraste der Standardfarben. |
| Widget im echten Browser (Edge) | `npm run check:edge` | **Nicht von mir ausgeführt.** Die Entwickler-Dokumentation nennt «22 von 22 bestanden». |

Das Plugin-Zip enthält das Plugin, das Widget (Skript und Stylesheet), `uninstall.php` und diese Anleitungen. Beim Bau liest der Rauchtest den Beispielkalender mit der eingebauten Bibliothek zu 71 Terminen.

**Das Übergabepaket** (`enter-once-uebergabe.zip`) wird automatisch gebaut und geprüft: Das darin liegende Plugin-Zip ist mit dem einzeln gebauten Zip identisch; die acht Anleitungen sind vorhanden; das Werkzeug für den Abgleichsbericht läuft in einem einfachen PHP-8.1-Behälter mit nur seinem eigenen Ordner; in keiner Datei steht eine Kalender-Adresse.

**Hinweis zu einem früheren Lauf:** Bei einem früheren Lauf am selben Tag, mit anderen Beteiligten gleichzeitig auf derselben lokalen Testwebsite, schlugen 2 beziehungsweise 13 Tests fehl. Laut dem Entwicklungsteam lag das an der gleichzeitigen Nutzung, nicht am Code. Das ist hier nur der Vollständigkeit halber genannt: Die lokale Testwebsite darf nur von einer Person zugleich benutzt werden.

### 3.2 Was die Integrationstests prüfen

Laut Entwickler-Dokumentation: den Abgleich beider Testkalender, den zweiten Lauf ohne Änderungen, den Feed, den Schutz von Personendaten (R-12), den Probelauf, den angehaltenen Abgleich, die Trennung der Zentren untereinander (R-04), den Assistenten, das Übernehmen bestehender Angebote und die Administrationsseiten (Rechte und Sicherheitsabfragen).

### 3.3 Was sonst geprüft wurde

- **Abgleichsbericht:** Die Tests prüfen alle Punkte des Plans (Abschnitt 8.7) gegen gespeicherte Kopien: fehlende, abweichende, überzählige und abgesagte Einträge, mehrdeutige Zuordnungen, die Angabe der gesehenen Wöchentlichen Angebote und den Abbruch bei 403 oder 429. Kein Test öffnet eine Netzwerkverbindung. Ein Beispiellauf mit dem Live-Testkalender und der nachgebauten Seite schrieb einen Bericht (4 fehlen, 2 anders, 6 nur auf der Website, 3 nicht beurteilbar).
- **Widget:** Darstellung, Filter, Suche, Highlights, abgesagte Veranstaltungen, fehlende Daten, Ausfall des Feeds (Fallback-Meldung und Link) und die Abwehr von Skripten in Beschreibungen. Das Rendern geschah in einer simulierten Umgebung (jsdom), die keine Seitenaufteilung kennt.
- **Zip:** Bau und saubere Installation auf einer frischen WordPress-Installation (Klassen, Bibliothek, 28 Einstellungen, Zeitplaner-Eintrag, Feed-Adresse, 71 Termine, benötigte Dateien).

### 3.4 Unabhängige Prüfung

Eine unabhängige Prüfung hat die Testsuiten selbst ausgeführt und **neun Fehler** gefunden. **Fünf davon stellte sie auf einer eigenen Kopie der lokalen Testwebsite nach.** Alle neun wurden behoben, und zu jeder Korrektur gibt es einen Test.

**Einschränkung:** Diese Tests wurden **nach der Korrektur geschrieben** und **nicht gegen den alten, fehlerhaften Code ausgeführt**, weil es keine Versionsgeschichte gibt. Dass sie bestehen, zeigt, dass der heutige Code das Verhalten zeigt, das die Tests verlangen. Es zeigt nicht, dass die Tests den ursprünglichen Fehler tatsächlich aufgedeckt hätten.

Die Korrekturen sind im Plugin enthalten und in der Installationsanleitung beschrieben (Assistent, Übernehmen und Rückgängigmachen, angehaltene Abgleiche, Zeitbudget, Kalender-Adresse, Löschen des Plugins).

## 4. WAS NICHT GETESTET WURDE

Jeder Punkt unten ist ein offenes Risiko. Für jeden: warum nicht, und wie Sie ihn bei sich prüfen.

| Nicht getestet | Warum nicht | So prüfen Sie es bei sich |
|---|---|---|
| **Das echte Veranstaltungstool und das Theme `qtp`** (Feldnamen, Speicherformate, Berechnungen beim Speichern, Serien-Box) | Der Code des Tools lag nicht vor. Es gab keine Testkopie der Website. Das Plugin wurde nur gegen eine Nachbildung getestet. | Installationsanleitung, Abschnitte 1, 5 und 11: zuerst auf einer Kopie, dann Feldzuordnung lernen, Prüfentwurf im Tool und auf der öffentlichen Seite prüfen, Ausbleiben von Serien-Kopien kontrollieren, zwei Wochen Probelauf. |
| **Die Google-Kalender-Quelle** (Einladungen, Kennungen, Absagen, Personenangaben in der Datei) | Es gab keinen Zugriff auf Unions Google-Kalender und es wurde noch keine echte Einladung durchgespielt. Der Test mit einer Google-Datei ist vorgesehen, aber noch nicht möglich: die Datei fehlt (siehe oben, ein Test ist übersprungen). | `Outlook-Anleitung.md`, Teil A: Versuch mit einer echten Einladung und Ergebnistabelle. Eine Datei ohne Personenangaben danach an den Entwickler. |
| **Die Anleitungen selbst, von Anfang bis Ende befolgt** | Niemand hat sie bisher auf einer sauberen Website durchgespielt; der Plan verlangt das als Abschluss der Übergabe. | Eine Person, die sie nicht geschrieben hat, folgt der `Installationsanleitung.md` auf einer Testkopie und meldet Abweichungen. |
| **Der Outlook-Kalender «Programm»** (Ressourcenpostfach, Einstellungen, Veröffentlichen, Absagen, Reichweite) | Kein Zugriff auf Microsoft 365. Die Einstellungsnamen stammen nur aus der Microsoft-Dokumentation. | `Outlook-Anleitung.md`, Teil B: die 13 Versuche mit Ergebnisspalte. |
| **Wechsel der Quelle von Google zu Outlook** | Das Plugin hat dafür keine Funktion zum Neuverknüpfen (siehe `Outlook-Anleitung.md`, Teil C). Der Test ist übersprungen. | Zuerst mit Probelauf wechseln. Ein angehaltener Abgleich ist dabei erwartet. |
| **WPML** (mehrsprachige Website) | In der lokalen Umgebung ist WPML nicht installiert. Nur die Abwesenheit wurde geprüft («WPML ist auf dieser Website nicht aktiv; die Einstellung hat keine Wirkung.»). | Prüfen, ob angelegte Einträge in jeder Sprache sichtbar sind; sonst «Sprache (WPML)» → «In alle Sprachen duplizieren». |
| **WP Rocket** (Seiten-Zwischenspeicher) | Nicht installiert. Nur die geschützte Anbindung ist getestet, dass nichts passiert, wenn es fehlt. | Nach einem Lauf prüfen, ob geänderte Einträge und `/programm/` aktuell sind. |
| **Ein Screenreader am Widget** | Es gab keinen Lauf mit NVDA, JAWS, VoiceOver oder TalkBack. Die Lesereihenfolge und die Ansagen sind nur im Markup geprüft. | `widget/ACCESSIBILITY-CHECK.md`, Teil B: Schritt für Schritt mit Ergebnistabelle. |
| **Browser ausser Edge** (Firefox, Safari, Chrome auf dem Handy usw.) | Der Test im echten Browser lief nur in Edge, mit simulierten Tastendrücken. | `widget/ACCESSIBILITY-CHECK.md`, Teil A: Tastatur in jedem Browser. Ausserdem Zoom 200 %, Kontrastmodus von Windows, Handy (Teil C). |
| **Das Widget auf der echten Website union-basel.ch unter Webflow** | Nur gegen die statische Kopie und den erfundenen Feed. | `Website-Anleitung.md`: auf der Vorschau-Adresse prüfen, bevor Sie veröffentlichen. |
| **Visueller Vergleich mit dem heutigen Elfsight-Widget** | Der Screenshot im PDF der Mitarbeiter-Anleitung konnte nicht dargestellt werden; das Layout wurde nach der Beschreibung im Plan nachgebaut. | Beide nebeneinander öffnen. `ACCESSIBILITY-CHECK.md`, Punkt C6. |
| **Der Abgleichsbericht, wie er die echte Programmseite liest** | Das Lesen der Seite wurde nie an der echten Seite ausprobiert; die Seite ist nachgebaut. Der Verband muss zuerst informiert sein (R-42). Ob die Seite mehr als 8 Wöchentliche Angebote liefert, ist offen (Lücke G-17). | `Abgleichsbericht.md`, Abschnitt 6: die Prüfungen der ersten echten Ausführung. |
| **Zustellung von E-Mails** vom Server des Verbands | In der lokalen Umgebung werden E-Mails nur gespeichert, nicht versendet. | Nach der Einrichtung einen Fehler- oder Zusammenfassungsmail abwarten. |
| **Server-Zeitplaner und PHP-Version des Verbands** | Unbekannt (Lücken G-04). | Hosting-Firma fragen; `Installationsanleitung.md`, Abschnitt 8. |
| **Lauf mit echten Titeln, Mengen und Zeiten von Unions Produktivkalender** | Der Testkalender trägt vor jedem Titel «Test:». Menge und Titel des Produktivkalenders sind unbekannt (G-15). | Zwei Wochen Probelauf. |
| **Wie lange es dauert, bis eine Änderung online ist, und wie lange ein falsch eingeladener interner Termin bleibt** | Hängt von der echten Quelle ab. | Messen und in die Kurzanleitung eintragen (Versuch 12 und 13 in der Outlook-Anleitung). |
| **Lauf im Stundentakt über Tage, Lastverhalten auf dem echten Server** | Nur kurze Testläufe. | Statusseite beobachten («Veraltet: …»). |

## 5. Was noch fehlt

- Die Datei `google-invited.ics` und die dazugehörigen Erwartungen (Phase 0 des Plans).
- Ein Durchlauf der Anleitungen von Anfang bis Ende auf einer sauberen WordPress-Installation durch jemanden, der sie nicht geschrieben hat. Das verlangt der Plan als Abschluss der Übergabe. **Das ist noch nicht geschehen.**

## 6. Zu den Zugänglichkeitsprüfungen des Widgets (Plan, Abschnitt 8.5)

| Prüfung | Stand |
|---|---|
| Automatischer Test (axe-core) in simulierter Umgebung | bestanden, keine Verstösse, in 93 Tests enthalten (Lauf 24. November 2026) |
| Automatischer Test in Edge, mit Farbkontrasten | laut Entwickler-Dokumentation bestanden; von mir nicht erneut ausgeführt. In Edge meldet axe ausserhalb des Widgets Befunde auf der kopierten Union-Seite (Logo-Link ohne Namen, Farbkontrast anderer Texte). |
| Tastaturdurchgang | mit simulierten Tastendrücken in Edge; **nicht von einer Person** |
| Screenreader-Durchgang | **nicht durchgeführt** |
