=== Enter Once Sync ===
Contributors: Enter Once (Freiwilligenprojekt)
Tags: veranstaltungen, kalender, ics, outlook, feed
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Einmal erfassen, überall anzeigen: Veranstaltungen aus dem Kalender eines Zentrums automatisch auf die Website des Verbands und auf die eigene Website bringen.

== Description ==

Ein Zentrum erfasst seine Veranstaltungen wie bisher in Outlook und lädt den öffentlichen Kalender ein. Das Plugin liest diesen veröffentlichten Kalender (eine ICS-Adresse) alle 15 Minuten und

* legt die Veranstaltungen im Veranstaltungs-Tool der Website an, ändert sie und setzt abgesagte oder entfernte auf «Entwurf». Wöchentliche Serien werden zu «Wöchentlichen Angeboten», mit «Ferienpause» und «abgesagt»;
* bietet dieselben Veranstaltungen als öffentlichen Feed an, den ein kleines Skript (Widget) auf der eigenen Website des Zentrums anzeigt.

Interne Einträge werden verworfen. Namen und Adressen von Personen aus dem Kalender werden nie übernommen. Gelöscht wird nichts: Einträge werden höchstens auf «Entwurf» gesetzt, und eine Obergrenze verhindert, dass ein fehlerhafter Kalender das Programm leert.

Einstellungen: «Einstellungen → Enter Once». Bis die Feldzuordnung mit dem Assistenten gelernt und geprüft ist, läuft jeder Abgleich als Probelauf und schreibt nichts.

= Voraussetzungen =

* WordPress 6.0 oder neuer, PHP 8.1 oder neuer.
* Ein Veranstaltungs-Tool mit dem Inhaltstyp «events» und den Taxonomien «topic», «targetgroup» und «location_cat».
* Pro Zentrum eine veröffentlichte Kalender-Adresse (ICS), die mit https:// beginnt.
* Empfohlen: ein Eintrag im Zeitplaner des Servers (siehe Anleitung). Sonst läuft der Abgleich mit dem Zeitplaner von WordPress.

= Anleitungen =

Die Anleitungen liegen im Ordner «docs/de» dieses Plugins:

* Begleitbrief.md – was dieses Paket ist
* Installationsanleitung.md – Installation, Feldzuordnung, Übernahme bestehender Angebote, Probelauf
* Entscheidungen.md – alle Annahmen und wie man sie ändert
* Kurzanleitung-Team.md – eine Seite für die Mitarbeitenden
* Outlook-Anleitung.md – Kalender-Adresse und späterer Wechsel zu Outlook
* Website-Anleitung.md – das Widget auf der eigenen Website
* Abgleichsbericht.md – der Abgleichsbericht
* Testbericht.md – was getestet wurde und was nicht

= Mitgeliefert =

sabre/vobject, sabre/xml und sabre/uri (BSD-3-Clause) mit eigenem Namensraum (EnterOnce\Vendor\Sabre), damit sie nicht mit anderen Plugins kollidieren. Dazu die Dateien des Widgets im Ordner «widget».

== Installation ==

1. In WordPress unter «Plugins → Installieren → Plugin hochladen» die Datei enter-once-sync.zip hochladen und aktivieren.
2. Unter «Einstellungen → Enter Once» ein Zentrum einrichten: Kalender-Adresse (ICS), Zentrum auf der Verbands-Website, Benachrichtigung.
3. Im Reiter «Feldzuordnung» den Assistenten durchgehen und den Prüfentwurf bestätigen.
4. Bestehende Wöchentliche Angebote im Reiter «Bestehende Angebote übernehmen» verbinden.
5. Zwei Wochen im Probelauf beobachten, dann den Probelauf ausschalten.

== Lizenz und Gewährleistung ==

Dieses Plugin ist freie Software. Sie dürfen es unter den Bedingungen der GNU General Public License, Version 2 oder (nach Ihrer Wahl) einer späteren Version, weitergeben und verändern. Den Text der Lizenz enthält die Datei LICENSE.

Das Plugin wird ohne jede Gewährleistung bereitgestellt, auch ohne die stillschweigende Gewährleistung der Marktreife oder der Eignung für einen bestimmten Zweck. Einzelheiten stehen in der GNU General Public License.

== Widmung ==

Dieses Plugin wurde nach dem AI for Access Hackathon (Basel, 20.–21. November 2026) von einer freiwilligen Person gebaut, als Geschenk an das Quartierzentrum Union und den Verband Quartiertreffpunkte Basel.

Autorin oder Autor: Yasar TAY

Betrieb und Pflege liegen beim Quartierzentrum Union und beim Verband; die Entwicklerin oder der Entwickler hilft auf Anfrage.

== Changelog ==

= 0.1.0 =
* Erste Fassung für den Probelauf.
