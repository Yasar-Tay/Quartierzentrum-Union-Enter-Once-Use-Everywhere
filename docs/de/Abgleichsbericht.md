# Abgleichsbericht: Kalender und Programmseite vergleichen

*Für die Person bei Union, die den Vergleich ausführt. Grundlage: Umsetzungsplan, Abschnitt 4.10 (Regeln R-40 bis R-42), Annahme A-22, und die Entwicklerdokumentation `docs/dev/drift.md`.*

> **Was nicht getestet ist:** Das Werkzeug wurde nur mit gespeicherten Kopien getestet, **nie gegen die echte Programmseite**. Die Kopien der Seite sind aus Beobachtungen **nachgebaut**, nicht aufgezeichnet. Es kann sein, dass das Werkzeug die echte Seite beim ersten Mal nicht versteht. Das ist dann kein Fehler Ihrerseits (siehe Abschnitt 6).

## 1. Wozu der Bericht dient

Der Abgleichsbericht vergleicht den Kalender von Union mit dem, was die Programmseite des Verbands (qtp-basel.ch) heute zeigt. Er nennt fehlende, abweichende und überzählige Einträge sowie abgesagte, die noch online stehen. Er ist hilfreich, solange das Plugin noch nicht läuft, während des Probelaufs und nach dem Start.

**Was er nicht tut:** Er **ändert nichts**, weder im Kalender noch auf der Website. Er ist auch **nicht die Synchronisation**: Fehler, die er findet, korrigieren Sie selbst (in Outlook beziehungsweise, wo nötig, auf der Verbands-Website). Der Bericht ist eine private Datei auf Ihrem Computer. Nichts wird veröffentlicht und nichts wird für Sie gehostet (A-22).

## 2. Zuerst: Der Verband muss Bescheid wissen

**Führen Sie den Bericht zum ersten Mal erst aus, wenn der Verband informiert wurde** (R-42). Sagen Sie ihm, was der Bericht tut und warum:

- Er ruft die öffentliche Programmseite des Verbands genau **einmal pro Lauf** ab, so wie es jeder Besucher tut.
- Er läuft höchstens einmal pro Stunde. Die Stunde wird **pro Ordner der Ausgabedatei** gezählt: Das Werkzeug legt neben dem Bericht die kleine Statusdatei `enteronce-drift.state.json` ab.
- Er nennt sich in der Anfrage mit einem Absender, der dieses Projekt benennt.
- Er liest nur; er schreibt nichts auf die Website.
- Er benutzt die interne Listenabfrage («mehr laden») der Website **nicht**.

Fragen Sie auch, wer beim Verband sich dafür interessiert (Frage Q-11). Wenn der Verband das nicht will, führen Sie den Bericht nicht aus.

## 3. Was Sie brauchen

Ehrlich gesagt: Dies ist der eine Teil des Pakets, bei dem Sie **einmalig Hilfe von einer technisch versierten Person** brauchen können.

Das Werkzeug liegt im Übergabepaket im Ordner `abgleichsbericht/`. Es ist ein eigenständiger Ordner. Darin steht auch eine Datei `LIESMICH.txt` mit denselben Befehlen wie unten.

- **PHP 8.1 oder neuer** (der Befehl `php` funktioniert im Terminal) *oder* **Docker**.
- Ein **Terminal** (Befehlszeile, unter Windows PowerShell), in dem Sie den Befehl eintippen. Wechseln Sie zuerst in den Ordner `abgleichsbericht/`.
- Die **Adresse des Kalenders** (ICS-Adresse). Sie ist geheim (siehe `Outlook-Anleitung.md`, Teil A).
- Die Nummer von Unions Standort auf der Verbands-Website: **52**.

Nach der ersten Einrichtung brauchen Sie nur noch einen Befehl.

### Die Adresse bereitstellen

Schreiben Sie die geheime Adresse **nie in den Befehl**. Legen Sie sie vorher in einer Umgebungsvariable namens `UNION_ICS` ab. Das gilt nur für das aktuelle Terminal-Fenster und nicht dauerhaft:

- Windows (PowerShell): `$env:UNION_ICS = "https://…"` (Adresse einfügen)
- macOS oder Linux (Terminal): `export UNION_ICS="https://…"`

### Der Befehl

**Weg 1: PHP ist installiert**

```
php enteronce-drift env:UNION_ICS 52 bericht.html
```

**Weg 2: Docker ist installiert** (offizielles Abbild `php:8.1-cli`, eine Zeile im Ordner `abgleichsbericht/`)

Windows (PowerShell):

```
docker run --rm -e UNION_ICS -v "${PWD}:/bericht" -w /bericht php:8.1-cli php enteronce-drift env:UNION_ICS 52 bericht.html
```

macOS oder Linux (Terminal):

```
docker run --rm -e UNION_ICS -v "$PWD:/bericht" -w /bericht php:8.1-cli php enteronce-drift env:UNION_ICS 52 bericht.html
```

Hilfe zu allen Optionen: `php enteronce-drift --help`

| Teil | Bedeutung |
|---|---|
| `php enteronce-drift` | Startet das Abgleichswerkzeug. |
| `env:UNION_ICS` | Die Kalender-Adresse. `env:` heisst: «Lies die Adresse aus der Umgebungsvariable `UNION_ICS`.» So steht die geheime Adresse **nicht** im Befehl und nicht im Befehlsverlauf. |
| `52` | Nummer von Unions Standort auf der Website des Verbands. |
| `bericht.html` | Die Datei, in die der Bericht geschrieben wird. Sie liegt danach im Ordner `abgleichsbericht/`. |
| `docker run --rm -e UNION_ICS -v … -w /bericht php:8.1-cli` | Nur bei Weg 2: startet PHP in einem Docker-Behälter, reicht die Variable `UNION_ICS` hinein und stellt den aktuellen Ordner als `/bericht` bereit. |

Neben dem Bericht legt das Werkzeug eine kleine Datei `enteronce-drift.state.json` ab, **im selben Ordner wie die Ausgabedatei**. Darin merkt es sich, wann es zuletzt lief (siehe Abschnitt 4). Die Stunde zählt deshalb **pro Ordner**: Verschiedene Berichte im selben Ordner teilen sich die Sperre, ganz gleich wie sie heissen; ein anderer Ordner hat eine eigene. Verwenden Sie darum immer denselben Ausgabeordner. Wer neu beginnen will, löscht die Datei bewusst selbst.

**Weitere Angaben (freiwillig):**

- `--contact=TEXT`: Ihre Kontaktadresse oder Webadresse. Sie wird dem Verband im Absender mitgegeben, damit er Sie bei Fragen erreichen kann. Das ist höflich und empfohlen.
- `--centre=NAME`: Name des Standorts, falls er anders geschrieben wird als «Quartierzentrum Union».

**Probelauf ohne Anfrage an die Website:** Mit `--page-file=DATEI` liest das Werkzeug eine gespeicherte Kopie der Seite und ruft die Website **nicht** ab. Das Entwicklerteam benutzt das für Tests. Für Ihre Arbeit brauchen Sie es nicht.

Rückgabewerte des Befehls: 0 = Bericht geschrieben, 1 = falscher Aufruf, 2 = abgebrochen (die Meldung nennt den Grund).

Erfolg: Der Befehl meldet «Bericht geschrieben» und nennt die Anzahl pro Liste. Öffnen Sie die Datei mit einem normalen Browser (Doppelklick). Ein Befund (zum Beispiel «Fehlt auf der Website») ist **kein Fehler** des Befehls.
## 4. Wie oft? Und was passiert, wenn es zu früh ist?

Das Werkzeug schützt die Website des Verbands (R-40):

- **Höchstens einmal pro Stunde.** Läuft es früher, meldet es: *«Der letzte Lauf liegt weniger als eine Stunde zurück. Um die Website zu schonen, läuft der Bericht höchstens einmal pro Stunde. Nächster Lauf möglich ab …»* Es wird dann nichts abgerufen und nichts überschrieben.
- **Lehnt die Website ab** (Antwort 403 oder 429), hört das Werkzeug **für den ganzen Tag** auf. Meldung: *«Die Website hat die Anfrage abgelehnt (Antwort …). Der Bericht wird heute nicht mehr versucht, um die Website nicht zu belasten. Bitte teilen Sie dem Verband mit, was der Bericht tut, und versuchen Sie es morgen wieder.»* Folgt am selben Tag ein weiterer Versuch, lautet sie: *«Die Website hat heute eine Anfrage abgelehnt (Antwort 403 oder 429). Der Bericht ruft die Website heute nicht mehr ab. Nächster Versuch möglich ab …»*
- Ist die Statusdatei unlesbar: *«Die Statusdatei … neben der Ausgabedatei ist unlesbar. Aus Vorsicht wird die Website nicht abgerufen. Prüfen Sie die Datei; wenn Sie sicher sind, dass in der letzten Stunde kein Lauf stattfand, löschen Sie sie.»*
- Antwortet die Website mit einem anderen Fehler: *«Die Website antwortet mit einem Fehler (Antwort …). Es wurde nichts verändert.»* Auch dieser Versuch zählt als Lauf.
- Ist der Kalender nicht lesbar: *«Der Kalender ist unbrauchbar: … Es wurde nichts verändert.»* oder *«Der Kalender konnte nicht heruntergeladen werden.»* In diesem Fall wird die Website gar nicht erst abgerufen.

Bei jedem Abbruch bleibt ein früherer Bericht unverändert. Bei einem 403 oder 429: **Nicht umgehen.** Rufen Sie den Verband an.

## 5. Den Bericht lesen

Oben steht **«Dieser Lauf»**: wann er erstellt wurde, welcher Kalender, welcher Zeitraum (hier: die nächsten drei Monate), und wie viele Einträge im Kalender erwartet und auf der Seite gesehen wurden.

Dann folgt der Kasten **«Was dieser Bericht nicht sehen konnte»** (siehe Abschnitt 6). Danach die **«Übersicht»** und sieben Listen. Jeder Eintrag, zu dem es einen Datensatz auf der Website gibt, ist dort verlinkt.

| Liste (genau wie im Bericht) | Was sie heisst | Was Sie tun |
|---|---|---|
| **1. Fehlt auf der Website** | Im Kalender, aber auf der Programmseite nicht zu finden. | Prüfen, ob der Eintrag online sein sollte. Wenn ja und das Plugin läuft noch nicht: eintragen, wie bisher. Läuft es schon: Kalender prüfen (wurde der öffentliche Kalender eingeladen?) und die Statusseite ansehen. |
| **2. Auf der Website anders** | Es gibt den Eintrag auf beiden Seiten, aber Zeit oder Vermerk («abgesagt», «Ferienpause») stimmen nicht überein. Titel zählen nie als Unterschied. | Die Spalte «Was nicht stimmt» lesen. In Outlook korrigieren (die Quelle). Bis das Plugin läuft, auf der Website von Hand nachführen. |
| **3. Auf der Website, aber nicht im Kalender** | Die Seite zeigt etwas, wozu der Kalender nichts (mehr) hat. Möglich: gelöscht, abgelaufen, anderer Wochentag oder andere Zeit. | Prüfen, ob es noch gelten soll. Wenn nicht: den Verband bitten, es zu entfernen. Wenn doch: in Outlook ergänzen. |
| **4. Abgesagt, aber noch online** | Im Kalender abgesagt, auf der Seite ohne Vermerk «abgesagt». | Dringend: Verband bitten, den Eintrag als abgesagt zu markieren oder zu entfernen. |
| **5. Nicht eindeutig zuzuordnen** | Es lässt sich nicht entscheiden, welcher Kalendereintrag zu welchem Eintrag der Website gehört. **Der Bericht rät nie.** | Von Hand prüfen und entscheiden. Das kommt zum Beispiel vor, wenn zwei Angebote am selben Wochentag zur selben Zeit stehen. |
| **6. Nicht beurteilbar** | Die Seite zeigte nur einen Teil der Liste. Ob diese Einträge online sind, kann der Bericht nicht sagen. | Auf der Website selbst nachschauen. Das ist **keine** Fehlermeldung. |
| **7. In Ordnung** | Tag oder Wochentag, Zeit und Vermerke stimmen überein. | Nichts. |

Gut zu wissen:

- Der Bericht enthält nur Titel, Tage und Zeiten, keine Beschreibungen und keine Angaben zu Personen. Die Kalender-Adresse druckt er nie.
- Hat sich ein Termin auf einen anderen Tag verschoben, erscheint er **zweimal**: unter «1. Fehlt auf der Website» und unter «3. Auf der Website, aber nicht im Kalender».
- Wurde ein Eintrag nur umbenannt, aber Datum und Zeit blieben gleich, gilt er als in Ordnung. Titel werden nicht verglichen.

## 6. Was der Bericht nicht sehen kann, und die erste echte Ausführung

- **Nur die ersten 8 Wöchentlichen Angebote.** Eine einfache Seitenanfrage liefert nicht mehr. Der Bericht nennt das im Kasten oben. Was darüber hinaus liegt, zählt er nie als fehlend, sondern führt es unter «6. Nicht beurteilbar» auf. Ob sich mehr Angebote erreichen lassen, ist offen (Lücke G-17).
- **Die Seitenstruktur ist nachgebaut, nicht aufgezeichnet.**

Prüfen Sie bei der **allerersten echten Ausführung** (nach Abschnitt 2):

1. **Bricht der Lauf ab mit «Die Struktur der Programmseite wurde nicht erkannt …»?** Dann hat die Seite einen anderen Aufbau als angenommen oder wurde umgebaut. Es wird bewusst kein Bericht erstellt, damit eine leere Liste nicht mit einer leeren Website verwechselt wird. Wenden Sie sich an die Entwicklerin oder den Entwickler: Das Werkzeug muss angepasst werden.
2. **Kasten oben:** «Auf der Seite gesehen» sollte zu dem passen, was die Seite wirklich zeigt (am 23. November 2026 waren es 2 einzelne Veranstaltungen und 8 Wöchentliche Angebote). Weicht die Zahl ab, oder steht dort «Einträge der Seite konnten nicht gelesen werden», liest das Werkzeug etwas nicht.
3. **Antwortet die Seite überhaupt?** Bei einer Weiterleitung oder einem Fehler bricht das Werkzeug mit einer Meldung ab. Das lässt sich über die Basisadresse (`--base`) korrigieren; fragen Sie die technische Person.
4. **Drei, vier Einträge von Hand mit der Website vergleichen:** Zeiten, Wochentag, Vermerke «abgesagt» und «Ferienpause», und ob die Links im Bericht zum richtigen Eintrag führen.
5. **Anzahl der Wöchentlichen Angebote** mit dem vergleichen, was die Website bei «mehr laden» verspricht. Bei der Beobachtung im November 2026 waren es einmal 18 und einmal 8.
6. **Absichtlich ein zweites Mal innerhalb einer Stunde starten.** Es muss die Meldung aus Abschnitt 4 kommen («Der letzte Lauf liegt weniger als eine Stunde zurück …»).

Notieren Sie das Ergebnis jeder Prüfung. Melden Sie Auffälligkeiten der Entwicklerin oder dem Entwickler.

## 7. Automatisch ausführen (freiwillig)

Sie können den Befehl auf einem **eigenen Zeitplaner** einrichten (zum Beispiel einmal täglich). Der Plan beschreibt das als möglich (A-22). Die Stundenbremse schützt die Website auch dann.

Bedenken Sie:

- Der Zeitplaner läuft **auf dem Computer, auf dem Sie ihn einrichten**. Ist dieser Computer aus, läuft nichts, und niemand merkt es.
- Die Adresse des Kalenders muss dort verfügbar sein, ohne dass sie in einer Datei steht, die andere lesen können.
- Der Bericht bleibt eine private Datei auf diesem Computer.
- Die genaue Einrichtung hängt von Ihrem System ab und ist hier **nicht beschrieben und nicht geprüft**.
- Richten Sie die Automatik erst ein, wenn die erste echte Ausführung (Abschnitt 6) gut verlaufen ist.
