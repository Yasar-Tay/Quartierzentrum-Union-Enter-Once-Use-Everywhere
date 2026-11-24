# Drift report (`EnterOnce\Drift`)

Phase 2a of the plan (section 4.10, rules R-40 to R-42, assumption A-22, tests 8.7). A command that compares Union's calendar with the association's public programme page and writes one HTML file in German. It needs no installation on the association's site, so it is useful under every delivery route: it is the fallback if the plugin is not installed, the comparison tool during the plugin's dry run, and a check after go-live.

- Code: `src/Drift/`. Command: `bin/enteronce-drift`. Tests: `tests/Drift/`. Fixtures: `tests/fixtures/programm/`.
- Dependencies: the core (`EnterOnce\Core`, for everything about the calendar), PHP's built-in DOM extension. Nothing new in `composer.json`.
- It writes nothing except the output file and its small state file (R-41). It changes nothing on either side.

## Read this first: what was never tried

**The page reader has never been run against the real programme page.** No copy of the page exists in this project and none may be fetched. The fixtures are *reconstructed* from `docs/dev/programme-page-notes.md` (the site's own list script and one response of its list request), labelled as such in each file. The server-rendered page very probably uses the same markup, but this was not compared. Also never tried: the HTTP fetcher against a real server (tests inject a transport; no socket is opened anywhere in the test suite).

What the operator must look at on the **first real run** (after R-42 is satisfied, that is, after the association has been told what the report does and why):

1. Does the run stop with «Die Struktur der Programmseite wurde nicht erkannt»? Then the markup differs from the reconstruction; the reader must be adjusted, see «Adjusting the reader».
2. Open the report's header: **«Auf der Seite gesehen»** should be the two dated events and eight weekly offers the notes list for 23 November 2026 (or whatever the page really shows today). A different count, or a line «N Einträge der Seite konnten nicht gelesen werden», means the reader misses something.
3. Does the page really answer a plain request for `/programm/?location_cat[52]=Quartierzentrum%20Union`? (Does the value after `=` matter? Is the `[52]` enough? Not known; G-17 is the related open question.) If the site redirects, the command stops with an HTTP error; correct `--base`.
4. Check three or four entries by hand against the website: times, weekday, the status bands «abgesagt» and «Ferienpause», and that the links in the report open the right record.
5. Compare the number of weekly offers with what the page's button «mehr laden» promises (the notes report 18 once and 8 another time). The report says openly that offers beyond the first page are not seen; it never counts them as missing, it lists the calendar's offers it could not find under «Nicht beurteilbar».
6. Run it a second time within the hour on purpose: it must refuse (R-40).

## How it works

```
ICS (file or https)        programme page (HTTP, or a saved file)
        │                               │
   CalendarSide  (core)            PageReader  (DOM)
   → list<Expected>                → PageSnapshot (dated events, weekly offers, «mehr laden» flags)
        └───────────────┬──────────────┘
                     Matcher  (+ Resolver, TitleSimilarity)
                        → DriftResult
                     ReportRenderer → one HTML file
```

`DriftRunner::run()` does it in this order, which matters for R-40: build the page address; check the output folder; **read the calendar** (so a broken calendar never costs a request); the R-40 guard; the single page request; read the page; match; render; write the report atomically. When a run stops, nothing is written (an existing report stays), except the state file where R-40 requires it.

### Calendar side (`CalendarSide`)

`Core::read()` with the window «from today for `window_months_ahead` months» (3 by default) and `Core::targets()`. Settings are the core's; `--settings` passes a JSON file; **`cancel_mode` is forced to `badge`** so that a cancelled dated event stays a record with `cancelled: true` (with `unpublish` it would vanish from the targets and «cancelled but still online» could not be found). Everything the core publishes counts: dated events (also those with `cancelled: true`), weekly offers per weekday (R-20, R-26: offers that start later than `weekly_lead_days` are *pending*: they may be matched but are never reported missing). Internal entries are dropped by the core (R-01). Only `title`, day or weekday, start and end time, room and the flags are copied; no description and no line naming a person ever reaches the report (R-12; tested with an entry that carries ORGANIZER and ATTENDEE lines).

Weekly flags come from R-21 and R-22: `vacation` = the next planned date is skipped (Ferienpause), `cancelled` = the next planned date, or the whole series, is cancelled (abgesagt).

### Page reader (`PageReader`)

Expects (docs/dev/programme-page-notes.md): `.events-simple-programme` (dated events) and `.events-listing-weekly` (weekly offers), items `.qtp-article-preview` with an `h3` title, `.qtp-article-date` spans (day `So., 29.11.2026` or weekday `Montag`, time `09:00 - 14:00 Uhr`), an optional band `.qtp-article-cancelled` (word «abgesagt», or «Ferienpause» / class `qtp-vacation`), `.qtp-events-load-more[data-events=normal|weekly]`, `form.filter-form[data-perpage]` (default 8).

Tolerant of whitespace and non-breaking spaces, attribute order, quotes and case, entities, extra classes, `09.00` as well as `09:00`, a second day (`Sa., 13.02.2027 - So., 14.02.2027`), `Jeden Donnerstag`, a missing image or time, relative links (resolved against the page address).

It refuses (`PageStructureError`, German message «Die Struktur der Programmseite wurde nicht erkannt …», exit code 2, nothing written) when:

- the answer is empty, or neither container exists (new theme, maintenance page, JSON);
- a container holds links, headings or pictures but no recognised item (item markup renamed);
- items exist but none can be read.

An empty container with no links is accepted as «no events». Individual unreadable entries (no title, no date or weekday) are skipped, counted and named in the report's «what this report could not see» box.

### Page fetcher

`PageFetcher` interface: `fetch(string $url): PageResponse`, `isLive(): bool`.

- `FilePageFetcher` reads a saved file. Used by every test and by `--page-file`. Not live: no R-40 limits, no state file.
- `HttpPageFetcher` makes **one plain GET** of `PageAddress::build($base, $categoryId, $centreName)` = `https://www.qtp-basel.ch/programm/?location_cat[52]=Quartierzentrum%20Union`. User-Agent `EnterOnceSync-DriftReport/1.0 (read-only comparison of one programme page; open source, GPL-2.0-or-later)`, optionally followed by ` contact: <--contact>` (control characters removed). No cookies, no credentials, no redirect following, timeout 20 s, answer capped at 5 MB, https only (plain http only for `localhost`/`127.0.0.1`). Any address containing `admin-ajax` or `filter_events` is refused before a request: **the site's own list request is never used** (plan 4.10). The transport is injectable for tests.

### R-40 and the state file (`RunState`)

The state file is `enteronce-drift.state.json` in the **same folder as the output file** (shared by every report in that folder, whatever it is called):

```json
{ "version": 1, "last_run": "2026-11-24T09:00:00+01:00", "stop_day": "2026-11-24", "stop_status": 429, "stop_at": "…" }
```

- `last_run` is written **before** the request, so a crashed run counts. A live run is refused while `now < last_run + 1 hour` (outcome `too_soon`, says from when it may run again).
- A 403 or 429 answer writes `stop_day` (Europe/Zurich date): any live run that day is refused (`stopped_today`, until midnight). The next day it may try again.
- Other errors (404, 503, no answer) do not set `stop_day` but still count as a run.
- An unreadable state file blocks the request (`state_unreadable`); the message says to check the file or delete it.
- The guard uses the real clock; `--now` only moves the «now» of the calendar. Runs with `--page-file` neither read nor write the state file.
- To start over deliberately, delete the file. That is the operator's decision.
- **Limit of this guard:** the hourly limit and the stop for the day hold **per output folder**, because the state file lives there. Two reports written into two different folders can each make one request in the same hour. Whoever runs the report should always use the same folder; the code does not enforce a single folder per machine.
- **R-41:** the report writes two files, the HTML report and this state file. Both are in the output folder; nothing else is written anywhere.

### Matching (`Matcher`, `Resolver`, `TitleSimilarity`)

The title alone is never the key. Titles differ between the systems on purpose of history, so they only break ties or pair events whose times differ.

**Dated events.** Per day (site records before today and on or after the end of the compared period are left out and counted).

1. Same day **and same start time** → candidate pair; score = title similarity.
2. Remaining events on the same day with **different start times** → candidate if title similarity ≥ `Matcher::TITLE_PAIR` (0.60); they are then reported as «different» (time).
3. Candidates go to the resolver (below).

**Weekly offers.** Candidate = same weekday and **overlapping time** (a missing time means the whole day; an end of 00:00 means midnight). Score = 0.6 × title similarity + 0.4 × share of overlap (overlap ÷ union of the two time spans). Before the resolver, one site offer that spans **several consecutive calendar series** is recognised: ≥ 2 series, each having this offer as its only candidate, each inside the offer's time span, each with title similarity ≥ `Matcher::COVER_TITLE` (0.60), and not overlapping each other. They count as one pair (report: «Jugendschach; Schach- und Denkspiele» ↔ one site offer); the times are compared as one block.

**Resolver (no guessing).** Candidates form connected groups. In each group: a pair whose two members have no other candidate is a match. Otherwise a pair is accepted only if its score is ≥ `Resolver::STRONG` (0.70) **and** exceeds the best competing candidate of *either* member by ≥ `Resolver::MARGIN` (0.15). Matched members leave and the rest is looked at again. When nothing clear remains, candidates weaker than the group's best by more than `Resolver::WEAK_GAP` (0.35) are dropped (an unrelated offer that merely overlaps in time) and the steps repeat. Whatever is left with candidates is **ambiguous**: listed in its own section, never paired. Example: two site offers «Kinderpiazza» on Monday 14:00–17:00 against one calendar series → ambiguous, and neither site offer is called «not in the calendar».

**Title similarity** (0 to 1): titles are folded (case, accents, umlauts, ß), a leading «Test:» is dropped, as are filler words (`am im und für mit …`), weekday names, «Union», «Start», «Uhr» and bare numbers. Result = the best of Dice coefficient of the remaining words, 0.9 × containment (all words of the shorter title appear in the longer), and edit-distance ratio. «Café Balance» / «Café Bâlance» = 1.0; «Deutsch im Alltag» / «Deutsch im Alltag A» = 0.9; «Yoga im Union» / «Yoga am Montag (Start 09:00 Uhr)» = 1.0; «Repair Café» / «Gottesdienst» < 0.6.

**What counts as a difference** (titles never do):

- time: start or end differs by any minute («Zeit: Kalender 09:00–11:00 Uhr, Website 09:15–11:00 Uhr.»); the site shows no time where the calendar has one; all-day events and multi-day events are not compared by time;
- the site shows «abgesagt» but the calendar does not; for weekly offers the same with «Ferienpause» in both directions;
- **cancelled but still online** (own list): the calendar says cancelled (a dated event, or a weekly offer whose next date is cancelled) and the site shows no «abgesagt».

**Lists** (`DriftResult`): `missing` (in the calendar, not on the site), `different`, `onlyOnSite`, `cancelledOnline`, `ambiguous`, `unverifiable`, `agreeing`, plus `ignored` counters (site records in the past or beyond the period, pending weekly offers, cancelled calendar events that are correctly not on the site).

**What the page cannot show.** If the page's weekly list may be incomplete (button «mehr laden», or as many offers as the page size), calendar offers with no counterpart are **not** «missing» but «unverifiable». If the dated list has a «mehr laden» button, calendar events after the last shown day are «unverifiable» too. Site-only entries are always listed: what the page shows exists.

### Report (`ReportRenderer`)

One self-contained HTML file, `lang="de-CH"`, Swiss spelling, inline CSS only: no script, no external resource, no image, no font. Landmarks (`header`, `main`, `footer`), skip link, one `h1`, every section named by its heading, tables with `caption` and `th scope`, a native `details` element for the list of agreeing entries, focus outlines, print styles. Order: this run (time made, centre and `location_cat`, calendar, calendar's «now», compared period, page address or file, counts on both sides) → «Was dieser Bericht nicht sehen konnte» (weekly offers shown, that more may exist, unreadable entries, what was left out) → overview with links → the lists. Each entry that has a site record links to it. A calendar **address** is never printed (it may be secret, plan 4.9); a file is shown by its name.

## Running it

The host has no PHP; use the wrapper (paths relative to the project folder, which the container mounts at `/app`):

```
bin/php bin/enteronce-drift <ics> <location_cat> <output.html> [options]
```

| Argument / option | Meaning |
|---|---|
| `<ics>` | calendar: a file path or an https address. `env:NAME` reads the address from the environment variable `NAME`, so a secret address stays out of the shell history. Addresses must be https and public (R-02: private, loopback and link-local addresses are refused; redirects are not followed). |
| `<location_cat>` | the centre's term id on the association site (Union: 52) |
| `<output.html>` | the report; the state file `enteronce-drift.state.json` goes into the same folder (it must exist and be writable) |
| `--base=URL` | association site base address (default `https://www.qtp-basel.ch`; a sub-folder path is kept) |
| `--page-file=FILE` | read a saved copy of the programme page, make **no request** |
| `--now=DATETIME` | «now» for the calendar, local Europe/Zurich unless it carries an offset, e.g. `2026-11-23T00:00` |
| `--centre=NAME` | the centre's name as the site writes it in the address (default `Quartierzentrum Union`) |
| `--settings=FILE` | JSON file with core settings (`enteronce_settings` shape) |
| `--source=ID` | core source id (default `union`) |
| `--contact=TEXT` | appended to the User-Agent |

Trial run, no request (this is what the tests and the trial runs of the development session used):

```
bin/php bin/enteronce-drift tests/fixtures/programm/drift-calendar.ics 52 build/bericht.html \
    --page-file=tests/fixtures/programm/programm-union-reconstructed.html --now=2026-11-23T00:00
```

Real run (only after R-42: the association has been told what the report does and why):

```
bin/php bin/enteronce-drift env:UNION_ICS 52 /app/bericht.html
```

Exit codes: `0` report written (findings are not an error); `1` wrong usage (message and usage text in German); `2` stopped, with the reason in German and nothing written: calendar not readable or invalid, `too_soon`, `stopped_today`, `state_unreadable`, blocked (403/429), HTTP error, no answer, structure not recognised, output folder not writable.

The operator can put the command on their own scheduler; the hourly limit protects the site either way. Nothing is hosted, nothing is published (A-22).

## Tests

`bin/phpunit` (Core and Drift together; `--testsuite Drift` for this part). No test opens a socket: live runs use `FakeFetcher` (records URLs, scripted answers) or `HttpPageFetcher` with an injected transport; `DriftPurityTest` greps the sources for network functions outside the two fetchers, writes outside the runner and the state file, WordPress calls and external references in the report template. `CliTest` runs the command as a child process, only with `--page-file` or stopping before any request.

| File | Covers |
|---|---|
| `PageReaderTest` | reconstructed page, no dated events, status bands, whitespace/attributes/entities/quotes/case, missing image and time, multi-day, relative links, unreadable entries, load-more buttons, structure refusals (6 variants), no network while parsing |
| `MatcherTest` | every bullet of plan 8.7; the hard cases (two offers on the same weekday and time, differing titles, one offer covering two series, 15-minute difference); the title is never the key; incomplete lists; pending weekly offers; periods |
| `TitleSimilarityTest` | similarity examples, resolver thresholds |
| `DriftRunnerTest` | whole comparison of the fixtures, report content (header, limits, links, German, no script or external resource, no person data, address not printed, escaping), R-40 (one request; second run in the hour refused; 403 and 429 stop for the day; next day allowed; unreadable state file; calendar failures cost no request), R-41 (only two files written; a stopped run does not overwrite the old report) |
| `FetchingTest` | page address, forbidden list request, User-Agent, https only, file fetcher, ICS loader (https, private addresses, no address in messages) |
| `CliTest`, `DriftPurityTest` | the command, usage errors, source rules |

Fixtures in `tests/fixtures/programm/` (all HTML files carry a «RECONSTRUCTED FIXTURE - NOT RECORDED» banner): `programm-union-reconstructed.html` (the notes' data: 2 dated events, 8 weekly offers, «mehr laden»), `programm-no-dated-events.html`, `programm-variants.html`, `programm-changed-structure.html`, `programm-new-item-markup.html`, `programm-maintenance.html`, and the invented calendar `drift-calendar.ics` built to produce every list with the reconstructed page (with `--now=2026-11-23T00:00`: 1 missing, 3 different, 2 only on the site, 1 cancelled but online, 1 ambiguous, 1 unverifiable, 2 in order).

## Limits

- Only the **first 8 weekly offers** are visible to a plain page request (G-17: whether a page parameter returns more is unknown). The report states this at its top. The site's own list request, which would return the rest, is not used without the association's agreement.
- Markup **reconstructed, not recorded** (see the top of this file).
- Different dates are not paired: if an event moved to another day, the report shows it twice (missing, and only on the site). Titles are not compared; a renamed event with the same date and time pairs silently.
- A weekly offer on the site carries a weekday and a time; a calendar series that changed weekday shows up as missing plus «only on the site».
- Weekly flags (Ferienpause, abgesagt) are compared only for the next planned date (R-21, R-22), as the core defines it.
- Titles that differ completely, with several overlapping offers on one weekday, end up «ambiguous» or «only on the site»; that is deliberate.
- Time zones: the page shows local Zurich times; all comparison is local Zurich time.

## Adjusting the reader

When the page changes, the place is `PageReader` (class names in the XPath tests at the top of `read()` and in `parseItem()`). Add a fixture for the new markup to `tests/fixtures/programm/` (label it, and say whether it was recorded or reconstructed), make `PageReaderTest` pass, and keep the refusal fixtures: a page that the reader does not understand must stop the run, never produce an empty report.
