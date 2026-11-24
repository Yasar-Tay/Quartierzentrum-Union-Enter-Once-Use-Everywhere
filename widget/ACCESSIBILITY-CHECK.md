# Accessibility check: manual steps

For a person to do, with the demo running. Plan section 8.5 asks for the result of three checks in the test report: automated checker, keyboard-only walkthrough, screen-reader walkthrough. The first is automated (`npm test`, `npm run check:edge`). This list is for the other two, and for a few things no tool can judge.

What the developer already did is in `README.md` (section «Accessibility»). The Edge run drove the keyboard with synthetic key events; it found nothing wrong, but it is not a person. **Please do these steps yourself and fill in the result tables.**

## Before you start

```powershell
cd enter-once-sync\widget
npm install          # once
npm run demo         # leave it running
```

Open `http://127.0.0.1:8080/quartierzentrum-union/veranstaltungskalender/` (calendar) and `http://127.0.0.1:8080/` (home page with «Highlights»). For the error case, stop the server and start `npm run demo:dead`.

Write down: date, browser and version, screen reader and version, who tested.

Part A needs only a keyboard (put the mouse aside). Part B needs a screen reader. Part C needs nothing special.

## A. Keyboard only (do it in Edge or Chrome, then again in Firefox; Safari if you can)

Do not use the mouse for anything below. The page starts after a reload; press Tab until you reach the widget (the first stops are Union's menu).

| # | Do | Expected | Result |
|---|---|---|---|
| A1 | Tab into the widget | The first stop is the field «Suche». A clear outline (dark blue, 3 px) surrounds it. | |
| A2 | Tab on | Order: Suche → Von → Bis → Veranstaltungsart → Veranstaltungsort → «Filter zurücksetzen» → «Mehr Informationen» of the first card → the next card's … The date fields take several Tab presses each (day, month, year, calendar button) in Chrome/Edge; that is the browser's doing. Every stop shows an outline; the outline is never hidden behind another element. | |
| A3 | Shift+Tab back to «Suche» and type `jazz` | The list shrinks to one card at once. The text «1 Veranstaltung gefunden.» appears under the filters. | |
| A4 | Tab to «Filter zurücksetzen», press Space | All events are back; focus goes to «Suche»; the text under the filters shows the full count again. | |
| A5 | Tab to «Veranstaltungsart» and press the Down arrow (closed select) or Alt+Down to open it, choose a category with the arrows and Enter | The list shows only that category; the count text changes. | |
| A6 | Do the same with «Veranstaltungsort» | Same, for rooms. | |
| A7 | In «Von», type a date with the keyboard (for example `17022027` in a German browser) | Events before that date disappear. Typing a date in «Bis» that is earlier than «Von» shows «Das Enddatum liegt vor dem Startdatum.» and an empty list. | |
| A8 | Press «Filter zurücksetzen» with Enter | Everything is reset. | |
| A9 | Tab to a card's «Mehr Informationen». Press Enter, then Space, then Enter again | Opens, closes, opens. The description and «Adresse:» appear below. Links inside (if any) are reachable with Tab, in order. | |
| A10 | Keep pressing Tab | After the last card, focus leaves the widget (no keyboard trap). Shift+Tab returns into it. | |
| A11 | Search for `qqqq` | The list shows «Keine Veranstaltungen gefunden.» and a hint; the text under the filters says the same. | |
| A12 | Home page: Tab to the «Highlights» section | Each card shows a «Mehr Informationen» stop; no stop is skipped or duplicated; «Ganzes Programm» (Union's own button) follows. | |
| A13 | Dead-feed demo: Tab to the message | The link «Zum aktuellen Programm» is reachable and Enter follows it. | |

Also look for: a stop where you cannot tell where the focus is; a stop that scrolls the page so the focused element is hidden; anything you can reach with the mouse but not with the keyboard.

## B. Screen reader

Suggested pairs: NVDA with Firefox, NVDA with Edge or Chrome, VoiceOver with Safari (macOS and iOS). TalkBack with Chrome if available. For each pair, go through B1 to B9. Say what you hear in the «Heard» column, in your own words.

| # | Do | Expected | Heard / result |
|---|---|---|---|
| B1 | Open the heading list of the page (NVDA: Insert+F7; VoiceOver: rotor «Headings») | «Programm» (page, level 2), then the month headings (level 3: «Januar 2027» …), then one level-4 heading per event. No heading is called «abgesagt» alone: a cancelled event reads «abgesagt Titel». | |
| B2 | Jump to the form (NVDA: `D` for landmarks; VoiceOver: rotor «Landmarks» / «Form controls») | A search region named «Veranstaltungen filtern». | |
| B3 | Move through the fields with Tab | «Suche, Suchfeld»; «Von, Datum»; «Bis, Datum»; «Veranstaltungsart, Auswahlliste, Alle»; «Veranstaltungsort, Auswahlliste, Alle»; «Filter zurücksetzen, Schaltfläche». Each field is announced with its name; none is announced as «unlabelled» or «edit». | |
| B4 | Choose a category | After the change the result count is announced, without moving focus: for example «12 Veranstaltungen gefunden.» | |
| B5 | Type a search word, then stop | One announcement of the count after a short pause, not one per letter. | |
| B6 | Page load with the screen reader running | The result count is not read out on its own at load. | |
| B7 | Read one card with the browse cursor (NVDA: Down arrow; VoiceOver: VO+Right) | In this order: the title (heading); the date with the full weekday («Donnerstag, 7. Januar 2027, 15:00–17:00 Uhr»); «Ort: Quartierraum»; the categories (list «Veranstaltungsart»); the tags (list «Schlagwörter»); «Mehr Informationen». The date badge (a big «7») is not read a second time. The image is skipped or has a useful description; it does not repeat the title. | |
| B8 | Open «Mehr Informationen» | The state is announced («erweitert» / «expanded»); the description is read next. | |
| B9 | Find a cancelled event. Start the demo with `npm run demo -- --no-shift` (fixed dates), search `Offene Bühne` (Friday 12 March 2027) or `Kinderpiazza` (the one on Wednesday 20 January 2027) | «abgesagt» is heard together with the title. | |
| B10 | List navigation (NVDA: `L`) | The event list is a list with the right number of items; each item is one event. | |
| B11 | Dead-feed demo | «Die Veranstaltungen können im Moment nicht angezeigt werden.» is read when it appears or when browsing; the link is announced as a link. | |

Note anything confusing, repeated, or missing, even if it is not on the list.

## C. Other checks

| # | Do | Expected | Result |
|---|---|---|---|
| C1 | Zoom the browser to 200 % (Ctrl +) and then 400 % | Nothing is cut off, no horizontal scrolling of the page at 400 %, all functions still work. | |
| C2 | Windows: switch on a high-contrast theme (Settings → Accessibility → Contrast themes) | Cards, fields, buttons and labels still have visible borders; the focus outline is visible; the «abgesagt» word is readable. | |
| C3 | Browser text-only enlarging (Firefox: View → Zoom → Zoom Text Only, largest) | Text stays readable, nothing overlaps. | |
| C4 | Phone: open the demo (the PC's address in the same network, e.g. `npm run demo -- --host=0.0.0.0`; allow it through the firewall only for this test) | Filters stack; buttons are easy to hit with a thumb; cards are readable. | |
| C5 | Read the texts aloud | German, Swiss spelling (ss, no ß), short, clear. | |
| C6 | Compare with the real Elfsight widget in Union's current page | Same information: month headings, date badge, image, categories, title, tags, time, room, filters for date / «Veranstaltungsart» / «Veranstaltungsort», search. Note what is missing. | |

## Result table for the test report

| Check | Done by | Date | Browser / reader | Passed? | Findings |
|---|---|---|---|---|---|
| Automated: axe (jsdom) | `npm test` | | | | |
| Automated: axe + contrast in Edge | `npm run check:edge` | | | | |
| A Keyboard only | | | | | |
| B Screen reader 1 | | | | | |
| B Screen reader 2 | | | | | |
| C Other | | | | | |
