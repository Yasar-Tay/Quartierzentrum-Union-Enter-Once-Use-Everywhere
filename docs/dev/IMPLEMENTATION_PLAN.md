# Enter once, show everywhere: implementation plan

A sync solution that takes the public events of Quartierzentrum Union (Basel) from its Outlook calendar and shows them automatically on two websites: the umbrella association's site (qtp-basel.ch) and Union's own site (union-basel.ch).

- **Version:** 9, 24 November 2026. Version 9 adds section 12, "As built"; sections 1 to 11 are version 8 unchanged. A copy of this file travels with the source in `enter-once-sync/docs/dev/IMPLEMENTATION_PLAN.md`. Version 8 applied the second independent review: the screening rule, the weekly-offer rules, the setup wizard, the drift report, the image flow, the settings reference and the file table were corrected.
- **Status:** implemented and tested locally on 24 November 2026; see section 12 for what was built, where it deviates from this plan, and what has not been tested. Nothing has been tried against the real websites or calendars.
- **Frame:** the challenge «Einmal erfassen, überall anzeigen» was posted by Quartierzentrum Union at the AI for Access Hackathon (20–21 November 2026). According to the project owner, no team took it on. This solution is built by a volunteer and given to Union as a gift. After handover, Union and the association operate it themselves; the developer supports on request.

## 0. How to read this document

**Two audiences.**

- **Union and association staff:** read sections 1, 2, 4.1, 6 and 10. Section 6 lists every assumption made on your behalf and how to change it. The handover package will contain the same content in German (`docs/de/Entscheidungen.md`).
- **Developers and coding agents:** read everything. Sections 4, 6, 7 and 8 are binding. Where this plan names a setting key, file path or rule, use that name.

**Conventions.**

- IDs are stable and may be cited: `G-nn` is a knowledge gap, `A-nn` an assumption, `R-nn` a rule the software must follow, `Q-nn` an open question.
- "MUST" marks a rule whose violation is a bug. "SHOULD" marks a default that may be deviated from with a stated reason.
- German terms that appear in the user interfaces are kept verbatim in «guillemets».
- Every fact carries an evidence level:

| Level | Meaning |
|---|---|
| **V** verified | Read directly from a file in this folder, or computed and cross-checked. |
| **O** observed | Seen from the outside on a public website on 23 November 2026. Correct then; may change. |
| **R** reported | Stated by a source we did not check ourselves (documentation, a review). |
| **A** assumed | Not known. See section 6. |

**Files in this folder** (layout as of 23 November 2026).

| Path | What it is |
|---|---|
| `enter-once-challenge-de.pdf` | The challenge text (German). |
| `Workflow_Veranstaltungen_erfassen_eigene_Webseite.pdf` | Union's staff guide for today's Outlook → Google Calendar → Elfsight chain. |
| `Workflow_Veranstaltung_erfassen_fuer_Verbandswebseite.pdf` | Four screenshots of the association's WordPress admin. |
| `README.md` | The hackathon organisers' guide to the starter kit. |
| `union-beispielkalender.ics` | Organisers' sample calendar with edge cases. |
| `webflow-cms-csv/` | 13 CSV exports of Union's Webflow CMS and `_schema.json`. |
| `calendar.ics` | Copy of Union's published Outlook test calendar, downloaded 23 November 2026. |
| `webflow-static/` | Complete static copy of union-basel.ch (45 pages, 215 files). Contains `_mirror.py`, the script that made the copy; it is not part of this project and is not to be run. |
| `enter-once-sync/` | The code. `AGENTS.md` in it holds the rules for everyone working there. |

## 1. Summary

**Problem.** Union enters every event in Outlook. To appear on the association's site, each event is typed in a second time by hand. To appear on Union's own site, it travels through a fragile chain of Google Calendar, a custom script and a third-party widget.

**Solution.** One WordPress plugin, installed on the association's site. Every 15 minutes it reads a published calendar that contains only Union's public events and then does two things:

1. It creates, updates and removes Union's events in the association's existing event tool.
2. It offers the same events as a public data feed. A small script on union-basel.ch reads that feed and displays the event calendar, replacing the current widget.

Staff keep working as today: they enter the event in Outlook and invite the public calendar. Nothing is typed a second time.

**What is still open.** We have not seen the code of the association's event tool and have no access to Union's Microsoft 365 or Google account. Section 5 lists what we do not know, section 6 the assumptions that let us build anyway. None of the open points blocks the start of development. The one dependency we cannot remove is the association's consent to install the plugin; section 4.10 describes what is delivered if that consent does not come.

## 2. The problem

### 2.1 Association site (priority)

| | |
|---|---|
| Today | Union staff log in to qtp-basel.ch and type each event into a custom-built event tool. **V** |
| Why | The tool has no import: no ICS import, no reading of shared calendars. **V** (challenge text) |
| Effect | Double work, typos, forgotten changes, cancelled events that stay online. **V** (challenge text) |
| Evidence of drift | On 23 November 2026 the association site showed 2 dated events for Union. **O** Union's own calendar holds far more: the staff guide's calendar screenshot shows several events on most days. **V** |

### 2.2 Union's own site (optional in the challenge, in scope here)

| | |
|---|---|
| Today | Staff invite a Google calendar to each public Outlook event. A Google Apps Script accepts the invitation about every 5 minutes and attaches an image from a Google Drive folder. An Elfsight widget on union-basel.ch displays that Google calendar. **V** (staff guide) |
| Why it is a problem | It works, but depends on a custom script that staff are told not to touch, on one person who maintains it, and on two external services. **V** |
| Challenge wishes | Connect Outlook without the Google detour; fill the site with event data automatically; assign images and categories in the calendar entry. **V** |
| Urgency | The challenge itself says there is no urgent need here. **V** |

### 2.3 Goals

| ID | Goal | Source |
|---|---|---|
| Minimum | Events are no longer typed into the association site by hand. Changes and cancellations arrive automatically. Usable without technical background. | Challenge, «Variante B» |
| Ideal | An event is entered once and appears on both sites. Other neighbourhood centres can use the same solution. | Challenge, «Variante A» |
| Optional | Event texts in easy language («Leichte Sprache»); automatic categories and target groups. | Challenge |

### 2.4 Constraints

- Internal room bookings MUST never be published.
- The association's event tool is not rewritten.
- Entering, changing and cancelling an event needs no step outside Outlook. Adding a new image is the one occasional step elsewhere (A-15).
- Union and the association MUST be able to run the solution without the developer. Nothing in the delivered solution may depend on an account or server that belongs to the developer.

### 2.5 Out of scope

- Union's room booking form.
- The cookie banner on union-basel.ch (a separate Elfsight widget; untouched).
- Generating Union's weekly and monthly programme PDFs (possible later from the same data).

## 3. What we know

### 3.1 Organisations and systems

| System | Owner | Role | How events get in | Evidence |
|---|---|---|---|---|
| Outlook 365 | Union | Room and event planning; the single source | Staff type them | V |
| Google Calendar + Apps Script | Union | Carries public events to Union's site | Outlook invitation; script runs about every 5 minutes | V |
| Webflow + Elfsight (union-basel.ch) | Union | Union's own website | Elfsight reads the Google calendar | V |
| WordPress + custom event tool (qtp-basel.ch) | Umbrella association (Verband Quartiertreffpunkte Basel) | Shared programme of 16 centres | Union staff type them, a second time | V |

"Staff" always means Union's own staff. The association only runs the shared site.

### 3.2 How staff enter an event today (V, staff guide unless noted)

| Outlook field | Rule |
|---|---|
| Title | Official event name. Also the key for the image. |
| Start, end | Complete times. Recurring events are Outlook series with an end date. |
| Location | `Room, Quartierzentrum Union, Klybeckstrasse 95, 4057 Basel`. The live test calendar contains only the room name. |
| Description | Event text first. Below it one `[event-type]…[/event-type]` block with `name=Value` lines, and `[tag]…[/tag]` blocks. The organisers' `README.md` and the live calendar use one tag block per tag; the staff guide says the exact tag syntax was never written down. |
| Public or internal | Public events get the Google calendar as an invitee. Internal bookings do not. |
| Image | A JPG in the Drive folder «Elfsight Bilder», named after the title with underscores (`Offener_Deutschtreff.jpg`). Priority: event image, then category image, then default image. |

Defined categories (`event-type`): Essen & Trinken, Kultur, Musik, Beratung, Bildung, Bewegung, Kreativität, Party, Offener Treffpunkt. The live calendar also uses Austausch.

Defined tags: Babys & Kleinkinder, Kinder, Jugendliche, Erwachsene, Senior:innen, Familie, Deutschlernende, Highlight.

### 3.3 The association's event tool, seen from outside (O)

Source: public pages, sitemaps, the REST index, the theme's public files and the programme page of qtp-basel.ch, 23 November 2026. Confirmed by a second, independent reading the same day. The tool's code was not seen.

| Topic | Finding |
|---|---|
| Where it lives | Inside the site's custom theme `qtp`, not in a plugin. The theme header names «k-webs» as author and GPL v2 or later as licence. |
| Content types | `events`, `locations` (the centres), `news`. Event URLs are `/events/<slug>/`. |
| Taxonomies | `topic` (7 terms), `targetgroup` (5 terms), `location_cat` (16 centres). Full lists in section 4.5. A fourth, `district`, belongs to `locations` and does not concern events. |
| Two kinds of event | **Dated event:** shows «So., 29.11.2026» and «09:00 - 14:00 Uhr». **Weekly offer:** one record. Its own page shows «Jeden Montag» and a time; the list shows «Montag 09:15 - 11:00 Uhr» in a separate column «Wöchentliche Angebote». |
| Status flags | A record can carry «abgesagt» (cancelled) or «Ferienpause» (holiday break); the list shows it as a band across the card. In the list data both flags are one value per record. |
| Fields of one record | Title, body text (classic editor), featured image, date, start time, end time, weekly-offer switch, cancelled, holiday break, a free info text in the sidebar («Organisation und Information»). |
| Series | Dates generated by the tool are separate records: 2053 event URLs, 1146 distinct base slugs, up to 33 copies of one slug (`name-2`, `name-3`, …). |
| Programme page | `/programm/?location_cat[52]=…` is rendered on the server. A plain page request returns the centre's dated events and the first 8 weekly offers. |
| List request | «mehr laden» and the filters fetch more through a request named `filter_events`: a POST to the site's admin endpoint with a token taken from the page. Its response carries title, link, day, time, cancelled and holiday-break values. One such request, made once on 23 November 2026, reported 18 weekly offers for Union; the second reading did not repeat it and saw only the first 8. |
| Union's records | 2 dated events; weekly offers titled differently from the calendar («Deutsch im Alltag A», «Café Bâlance», «Yoga am Montag (Start 09:00 Uhr)»); two separate «Kinderpiazza» offers on the same weekday and time; one offer, «Jugendschach & Schach- und Denkspiele», that covers two calendar series. |
| Write interface | None from outside. The `events` type is not exposed in the WordPress REST API; the site advertises no application-password login; `xmlrpc.php` answers 403. Events can only be written by code running inside the site, or by hand in the admin screens. |
| Agent interface | The REST index lists routes of the WordPress Abilities API and an MCP adapter (`/wp-abilities/v1`, `/mcp/…`). They require login and were not explored. |
| Other plugins | WPML (multilingual), WP Rocket (page cache), Yoast SEO, Wordfence (security). |
| Admin screen | The edit screen has a box «Serien- und Mehrfachtermine» with «Wiederholung» and «Zusätzliche Termine». Saving a series generates independent events once. **V** (screenshot) |

### 3.4 Union's own site (V, static copy and CMS export)

| Topic | Finding |
|---|---|
| Event data | Webflow stores no events. All 13 CMS collections hold other content (offers, rooms, team, tenants, downloads). |
| Elfsight widgets | Three: a cookie banner on all 45 pages (`92219bf9-…`), the event calendar on `quartierzentrum-union/veranstaltungskalender` (`2a073e75-…`), and «Highlights» on the home page (`cda7408f-…`). |
| Calendar layout | Month heading; cards with date badge, image, category labels, title, tags, time and location; filters «Daten», «Veranstaltungsart», «Veranstaltungsort»; a search field. **V** (screenshot in the staff guide) |
| «Highlight» tag | Used for the home page section. Specific to Union's own site. |
| Offers | 24 offer records (`webflow-cms-csv/angebote-und-projekte.csv`), each with an image, subtitle and text. Titles differ from calendar titles (site «Seniorensport TV St. Josef», calendar «Seniorensport»). |
| Rooms | 4 bookable rooms (`webflow-cms-csv/raume.csv`): Quartierraum, Bühnensaal, Grosser Saal, Oberer Saal, each with accessibility details. The live calendar uses other names too (Projektraum Rehovot, Innenhof, Foyer, Bistro Quartierraum). Grosser Saal will be renovated from mid-2027. |
| Easy language | The site already has a «Leichte Sprache» page. |
| Custom code | The site already embeds third-party scripts, so pasting an embed snippet is possible. |

### 3.5 Test data (V)

| File | Content |
|---|---|
| `union-beispielkalender.ics` | Made by the hackathon organisers. 11 events in 12 calendar entries, November 2026 to March 2027. Contains the hard cases on purpose: skipped dates, a moved date, a cancellation, a typo, a missing description, internal bookings. Its `CATEGORIES:Öffentlich/Intern` marker is the organisers' invention, not Union's practice. |
| `calendar.ics` | Union's real published Outlook test calendar. 9 events from January 2027: 5 weekly series, 4 single events. Every entry has `CLASS:PUBLIC`. No `CATEGORIES`, no skipped or moved dates, no cancellations, no attendee data. 5 of 9 events have no category block; one tag block is not closed; three different events share one title. |

Both files are in Outlook's format. There is no file yet in the format of the starting source, a Google calendar fed by Outlook invitations (G-06).

Expected results for both files are in section 8.

## 4. The solution

### 4.1 Overview

```
Union staff ── enter events in Outlook, invite the public calendar (unchanged)
                     │
                     ▼
        Public-only calendar, published as an ICS address
        (start: today's Google calendar · later: Outlook «Programm» calendar)
                     │  read every 15 minutes
                     ▼
┌──────────── WordPress plugin on qtp-basel.ch ─────────────┐
│ SHELL                                                     │
│  1 Fetch     download the ICS text, reject broken or empty│
│                                                           │
│ CORE (no WordPress calls; input is ICS text;              │
│       same input → same output)                           │
│  2 Screen    drop internal and private entries            │
│  3 Expand    turn series into single dates                │
│  4 Normalise extract fields, map categories and tags      │
│  5 Diff      compare with what is on the site             │
│                                                           │
│ SHELL                                                     │
│  6 Write     create / update / unpublish in the event tool│
│  7 Feed      serve the public events as JSON              │
│  8 Notify    e-mail digest and status page                │
└───────────────┬──────────────────────────┬────────────────┘
                ▼                          ▼
   qtp-basel.ch event tool      union-basel.ch calendar widget
   (dated events and            (small script that reads the feed;
    weekly offers)               replaces the Elfsight event widgets)
```

### 4.2 Why this shape

| Decision | Reason |
|---|---|
| Input is an **ICS address**, not the Microsoft Graph API | Reading needs no Microsoft login. Centres that do not use Outlook can connect too. |
| The solution is **a WordPress plugin on the association's site** | It is the one place all 16 centres share. The event tool has no write interface from outside (section 3.3), so the writing part has to live inside the site. No subscription, no extra server. |
| **One plugin feeds both sites** | The plugin already holds the cleaned events. Serving them as a feed makes "enter once, show on both" true without a second system. |
| Union's site gets **a script that reads the feed**, not Webflow CMS items | No Webflow API token, no place to run a second job, no design work inside Webflow. It replaces the Elfsight embed one for one. The price: no search-engine visibility for single events and a run-time dependency on the association's server; both are also true of Elfsight today. The CMS variant stays possible (A-17). |
| Language **PHP**, library **sabre/vobject** | The target is a WordPress plugin. The library handles series, skipped dates, moved dates and Windows time zone names. **R**, to be confirmed in phase 1. |
| **Core separate from WordPress** | The core takes ICS text and returns records and actions. It can be tested without WordPress and reused in the other delivery routes (section 4.10). |
| Licence **GPL v2 or later** | The plugin is a gift. GPL allows unrestricted use, change and sharing, keeps changed versions open, and disclaims warranty. It is the normal licence for WordPress plugins. |
| Interface and documentation **in German** | Union and the association work in German and will run it alone. |
| AI stays **outside the sync** | The sync must be repeatable. AI only suggests; suggestions are published after approval. |

### 4.3 Names that implementations MUST use

| Thing | Name |
|---|---|
| Plugin slug and folder | `enter-once-sync` |
| PHP namespace | `EnterOnce\Core`, `EnterOnce\WordPress` |
| Settings option | `enteronce_settings` (one array; full key list in section 6.6) |
| Post meta prefix | `_enteronce_` |
| REST namespace | `enteronce/v1` |
| Text domain | `enter-once-sync` |
| Widget files | `widget/enteronce-widget.js`, `widget/enteronce-widget.css` |

Repository layout:

```
enter-once-sync/
  enter-once-sync.php      plugin bootstrap
  src/Core/                pure PHP, no WordPress functions, no network
  src/WordPress/           fetcher, adapter, admin pages, scheduler, REST feed, e-mail
  widget/                  script and stylesheet for union-basel.ch
  languages/               German translations
  docs/de/                 guides delivered to Union and the association
  tests/fixtures/          calendars and recorded pages used by tests
  tests/Core/              unit tests for the core
  tests/WordPress/         integration tests against a local WordPress
  bin/enteronce-drift      command line: drift report (section 4.10)
  docker-compose.yml       local WordPress + WP-CLI
```

### 4.4 Data model

The core produces two kinds of record from the calendar.

**Occurrence** (one date of one event):

| Field | From | Rule |
|---|---|---|
| `source_id` | settings | Which centre's calendar this came from. |
| `uid` | `UID` | The calendar's own identifier. |
| `sync_key` | see below | Stable identity used for matching. |
| `title` | `SUMMARY` | Cancel prefixes are removed (A-09). |
| `start`, `end`, `all_day` | `DTSTART`, `DTEND` | In Europe/Zurich. |
| `room` | first comma-separated part of `LOCATION` | Free text. Both `Grosser Saal` and `Grosser Saal, Quartierzentrum Union, …` MUST work. |
| `location` | whole `LOCATION` | Unchanged. |
| `description` | `DESCRIPTION` | Category and tag blocks removed; `<mailto:…>` and `<https://…>` remnants removed; HTML sanitised. |
| `categories` | `[event-type]` block | Calendar names, untranslated. |
| `tags` | `[tag]` blocks | Calendar names, untranslated. |
| `cancelled` | `STATUS` or title prefix | A-09. |
| `series_uid` | `UID` of the series, if any | |

**Series definition** (one recurring event as a whole): `source_id`, `uid`, weekday, start and end time, first and last date, number of dates, skipped dates, moved dates, cancelled dates, plus the same text fields. Produced for every series; used for weekly offers (section 4.6).

**Sync keys.**

| Record | Key | Effect |
|---|---|---|
| Single event | `source_id` + `uid` | Changing time or day updates the same record. |
| One date of a series | `source_id` + `uid` + original local date | A moved date, and a time change of the whole series, keep the key. |
| Weekly offer | `source_id` + `uid` + weekday | One record per weekday. |

Known limits: if a series moves to another weekday, old records are removed and new ones created. A series with two dates on one day is reported as a warning and not synced.

**Stored on every WordPress record the plugin manages** (post meta): `_enteronce_source`, `_enteronce_key`, `_enteronce_hash`, `_enteronce_managed` = 1, `_enteronce_missing_runs`.

`_enteronce_hash` is computed from the values that will be written to WordPress, after mapping. `DTSTAMP`, `LAST-MODIFIED` and `SEQUENCE` MUST NOT enter the hash; the live feed regenerates `DTSTAMP` on every download.

**Stored per source after every run:** the list of occurrences inside the sync window (`enteronce_occurrences_<source_id>`). The public feed (section 4.8) is served from this list, not from WordPress records.

### 4.5 Mapping tables

Term lists on the association site are complete as observed on 23 November 2026 (**O**). The plugin MUST resolve terms by name at setup and store IDs; the IDs below are for orientation.

**Categories → `topic`**

| Calendar category | Association topic | Status |
|---|---|---|
| Essen & Trinken | Essen & Trinken (35) | fixed |
| Kultur | Kulturelles & Musik (37) | fixed |
| Musik | Kulturelles & Musik (37) | fixed |
| Beratung | Beratung & Bildung (33) | fixed |
| Bildung | Beratung & Bildung (33) | fixed |
| Bewegung | Bewegung & Entspannung (34) | fixed |
| Kreativität | Kreativität & Spielen (36) | fixed |
| Offener Treffpunkt | Offener Treffpunkt (205) | fixed |
| Party | Märkte & Feste (204) | A-07 |
| Austausch | Offener Treffpunkt (205) | A-07 |

**Tags → `targetgroup`**

| Calendar tag | Association target group | Status |
|---|---|---|
| Babys & Kleinkinder | Für Babys und Kleinkinder (28) | fixed |
| Kinder | Für Kinder (29) | fixed |
| Jugendliche | Für Jugendliche (30) | fixed |
| Erwachsene | Für Erwachsene (31) | fixed |
| Senior:innen | Für Senioren (32) | fixed |
| Familie | Für Kinder (29) + Für Erwachsene (31) | A-08 |
| Deutschlernende | not transferred | A-08 |
| Highlight | not transferred; used only by Union's own site | A-08 |

**Centre → `location_cat`:** Quartierzentrum Union (52).

**Values outside the lists**, in this order:

1. **Synonym table.** Known variants are mapped by hand: «Familien» → Familie, «Kulinarisches» → Essen & Trinken.
2. **Spelling tolerance.** At most one character different and exactly one candidate: `Kulturr` → `Kultur`. The threshold is narrow on purpose; «Beratung» and «Bewegung» differ by three characters.
3. **Leave empty.** No value is invented.

**Notices.** Raised only for entries that pass screening and are not cancelled. Counted once per event or series, never per date.

- **Warning:** an unknown value was corrected or dropped; a series has two dates on one day.
- **Info:** the event has no category after mapping. A missing tag alone raises nothing. Having no category is normal (5 of 9 events in the live calendar) and MUST NOT be a warning.
- Values that are deliberately not transferred raise nothing.

### 4.6 Sync rules

**Screening**

- **R-01** An entry MUST be dropped, and never written anywhere, if it has a `CLASS` line whose value is not `PUBLIC`, or a `CATEGORIES` line containing «Intern». An entry without a `CLASS` line is public; that is the default of the ICS standard.
- **R-12** `ORGANIZER`, `ATTENDEE` and any other line naming a person MUST NOT reach any output: not WordPress, not the feed, not the report, not a log.

**Fetching** (shell)

- **R-02** The address MUST be https. Requests go through WordPress's safe HTTP function; internal network addresses are refused.
- **R-03** If the download fails, the text lacks `BEGIN:VCALENDAR`/`END:VCALENDAR`, or a feed that had events last time is now empty, the run MUST change nothing and report an error.

**Writing**

- **R-04** The plugin MUST only touch records that carry `_enteronce_managed` and the same `_enteronce_source`.
- **R-05** A record is unpublished only after it has been missing from the feed in two consecutive successful runs (A-10).
- **R-06** "Unpublish" means set to draft, never delete. If the event returns, the same record is published again.
- **R-07** Past records are never changed. A dated event is past when its end time has passed. A weekly offer is past when the last date of its series has passed.
- **R-08** "Missing from the feed → unpublish" applies only inside the sync window (A-11).
- **R-09** After a run that changed something, the plugin MUST purge the page cache for the changed records and the programme page, not for the whole site.
- **R-10** The plugin MUST NOT trigger the event tool's own series generation.
- **R-11** While the field mapping is incomplete or unverified, the plugin runs dry only.
- **R-13** If one run would unpublish more than `bulk_unpublish_limit` records of one source, none of them is unpublished. The plugin sends an e-mail and shows the list on the status page with a button «Trotzdem anwenden».

**Weekly offers** (A-05)

- **R-20** A weekly series is a series that repeats every week and has at least `weekly_min_dates` dates. It becomes one weekly-offer record per weekday. A shorter weekly series becomes dated events.
- **R-21** If the next planned date after now is skipped in the calendar, the record carries «Ferienpause» until that date has passed. "Next planned date" means the next date of the regular weekly pattern, whether or not it takes place.
- **R-22** If the next planned date is cancelled, the record carries «abgesagt» until that date has passed.
- **R-23** After the last date of the series, the record is set to draft.
- **R-24** If the next planned date is moved to another time or room, the weekly record keeps its regular time and gets a one-line note at the top of its body text, for example «Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr im Grossen Saal». The note is removed when the date has passed. The change is also listed in the e-mail digest.
- **R-25** Series that are not weekly (monthly, every two weeks) become dated events, one per date.
- **R-26** A weekly offer is published when its first date is at most `weekly_lead_days` away. Before that it does not exist on the site.
- **R-27** When a new series has the same weekday, start time and normalised title as a managed draft of the same source, the plugin reuses that record instead of creating a new one. This keeps the page address stable when staff start a new series each season.

**Running**

- **R-30** Runs are started by the server's scheduler where one is available, and by WordPress's built-in scheduler otherwise. A lock prevents overlapping runs. The status page warns when the last successful run is older than `stale_after_minutes`.
- **R-31** A dry-run switch per source computes and shows all actions without applying them.
- **R-32** Error e-mails are rate-limited: the same error at most once a day.
- **R-33** The digest of changes and warnings is sent at most once per `digest_frequency`. Errors and held runs (R-13) are mailed at once.

### 4.7 The WordPress side

**Field-mapping wizard.** We know which fields a record has (section 3.3) but not their internal names or formats (G-01). One example record is not enough: a switch that is off is often not stored at all, and a format cannot be read from one value. The wizard therefore learns by comparison:

1. The administrator creates a draft event in the tool's own edit screen and saves it. The wizard records all stored fields.
2. For each field in turn (date, start time, end time, weekday, weekly-offer switch, cancelled, holiday break, sidebar info text, room if the tool has one) the wizard asks the administrator to change exactly that value in the edit screen and save. It compares before and after, and learns the field's name and how its values are written.
3. The wizard then writes one draft itself. The administrator opens it in the tool's own edit screen and on the public page and confirms that everything appears correctly. Only then is the mapping marked verified (R-11).

This is work for the tool's developer or a technically confident administrator, about half an hour. The installation guide says so plainly. If the tool's code arrives before handover, the plugin ships with the mapping filled in and only step 3 remains.

**Adopting existing records.** Union's hand-entered weekly offers are online, and they do not match the calendar one to one (section 3.3): titles differ, two offers share weekday and time, and one offer covers two series. Adoption is therefore a manual pairing with help:

- The plugin lists the centre's weekly offers next to the calendar's weekly series and shows, for each offer, all candidate series with the same weekday and overlapping time. It never pairs by itself.
- The administrator chooses per offer: adopt with one series, or leave alone. One offer can be paired with one series only.
- Before the first write, the plugin stores a copy of the record (a WordPress revision) and shows a field-by-field comparison.
- Title and body text of adopted records are kept as they are on the site by default (`adopt_keep_text`). The sync then maintains time, flags and taxonomy terms. Text differences between calendar and site appear in the digest.
- Offers left alone stay untouched forever (R-04).

**Other parts.**

- **Settings page.** Per centre: ICS address, `location_cat` term, notification addresses, dry-run switch. Globally: the mappings and options in section 6. Only site administrators can open it.
- **E-mail digest** to the centre's contact: what was added, changed and removed, and warnings grouped by series (R-33).
- **Status page** for the administrator: time of the last run, counts, warnings, held runs, a «Jetzt abgleichen» button.
- **Edit-screen notice** on managed records: this record comes from Outlook; changes made here are overwritten.
- **Images.** A-15.
- **WPML.** Events are created in the default language. Whether translation records are required is tested on the local setup (G-03).

### 4.8 The Union website side

**What changes.** Two Elfsight widgets are replaced: the event calendar and «Highlights». The cookie banner stays.

**What it achieves, honestly.** While the source is the Google calendar (A-01), the Google calendar and the script stay; only Elfsight is replaced. The full benefit, dropping the whole Google chain, comes with the later move to an Outlook «Programm» calendar. In exchange, Union's calendar starts to depend on the association's server and on a script nobody is paid to maintain. This part is in scope by the project owner's decision; a review recommended deferring it. The plan keeps it separable: dropping phase 4 changes nothing in phases 1 to 3, and its go-live comes last (A-23).

**Public feed** (served by the plugin, built in phase 3):

```
GET https://www.qtp-basel.ch/wp-json/enteronce/v1/events
    ?source=union&from=2027-01-01&to=2027-03-31
```

- Returns a JSON list of occurrences, each with: `id`, `title`, `start`, `end` (ISO 8601 with offset), `all_day`, `room`, `location`, `description_html` (sanitised), `categories`, `tags` (calendar names, so Union's own vocabulary is kept), `image` (`url`, `alt`; or null), `cancelled`, `series_id`.
- Data source: the stored occurrence list (section 4.4). The feed therefore lists single dates also for weekly series: Union's calendar shows every date, as today, while the association site shows one weekly offer.
- `from` defaults to today, `to` to the end of the sync window. A request cannot reach beyond the window.
- `image` is resolved per occurrence by the image rule (A-15).
- Contains only what passed screening (R-01, R-12).
- Cached for `feed_cache_seconds`.
- The data is public, so the feed answers requests from any website (`Access-Control-Allow-Origin: *`). This also lets the widget be tested on Webflow's preview address before publishing. Restricting origins would not protect anything.

**Widget** (`enteronce-widget.js`): plain JavaScript, no dependencies, served from the plugin folder.

Embed for the calendar page:

```html
<div data-enteronce="calendar" data-source="union"></div>
<script src="https://www.qtp-basel.ch/wp-content/plugins/enter-once-sync/widget/enteronce-widget.js" defer></script>
```

Embed for the home page:

```html
<div data-enteronce="highlights" data-source="union" data-tag="Highlight" data-limit="6"></div>
```

Requirements:

- Same content as today: month headings, cards with date, image, category labels, title, tags, time and location; filters for date, category and room; search.
- Colours and fonts are CSS variables whose defaults are taken from Union's stylesheet in `webflow-static/`.
- Accessibility: conforms to WCAG 2.1 level AA. Checked by an automated test, a keyboard-only walkthrough and a screen-reader walkthrough; results go into the test report.
- All texts in German.
- If the feed cannot be reached, the widget shows a short message and a link to Union's programme on the association site.
- Rollback: the old Elfsight embed code is kept in the handover notes; pasting it back restores today's state.

**What can be switched off afterwards.** Once the widget is live and the source is the Outlook «Programm» calendar: the Google calendar, the Apps Script, the Drive image folder and the two Elfsight event widgets.

### 4.9 Security and privacy

| Topic | Measure |
|---|---|
| Server fetching an outside address | R-02. |
| Text from the calendar | Sanitised before storage and before the feed; no scripts, no embedded content. |
| Permissions | Only site administrators configure the plugin and start manual runs. Centre accounts cannot change settings. Data stays separated per centre by R-04. |
| Internal events | Two layers: the source calendar contains only public events, and R-01 drops marked entries. How long a wrongly invited event takes to disappear is measured and written into the guide. |
| Personal data | R-12. Outlook's published file carries no attendee data (**V**). A Google calendar fed by invitations probably carries staff e-mail addresses in its file (**A**, G-06); R-12 covers this. Descriptions contain contact addresses and phone numbers that are already public on Union's site; they pass through unchanged and are never sent to an AI service. |
| The ICS address | A secret setting. Google's «secret address» gives access to the whole calendar. It is stored only in the settings, shown in full only to administrators, never logged, and MUST NOT be committed to the repository or placed on a third-party service. |
| The feed | Read-only, public data only, cached. |
| Reading the association's site (drift report) | Section 4.10, rules R-40 to R-42. |

### 4.10 Alternatives considered and delivery routes

**Alternatives.** Five designs were compared. The outside checks of 23 November 2026 (section 3.3) settled the comparison.

| Design | Verdict | Reason |
|---|---|---|
| WordPress plugin reading an ICS address | **Chosen** | The only design that can write events. No running cost; one installation serves all 16 centres. |
| Automation service (Make, Zapier) | Not possible as is | These services write to WordPress from outside, which this site does not allow. They would need a connector plugin on the site anyway, plus a subscription. **R** |
| Microsoft Graph API | Not needed | It solves reading only, which a published calendar already solves, and needs administrator consent in every organisation. |
| External script writing through the WordPress REST API | Not possible | No outside write path exists. **O** |
| Browser automation of the admin screens with Union's login | Rejected | Fragile, needs a stored password, and collides with the site's login security. |

**What changed.** The technical choice is now forced. The open condition is organisational: the association did not ask for this solution, and installing a volunteer's plugin on a production site shared by 16 centres is its decision (G-05). The Union-website part depends on the same decision.

**Delivery routes.** The core (section 4.1, steps 2 to 5) is identical in all three. Only what surrounds it differs.

| Route | Needs | Delivers |
|---|---|---|
| 1. Plugin (primary) | The association installs the plugin | The full solution for both sites. |
| 2. Core handed to the tool's developer | The association asks its developer (theme `qtp`, by k-webs) to build the import into the tool | The full solution for the association site, maintained by the people who maintain the tool. Our core, its tests and this plan are the input. Both codebases are GPL. Since the wizard needs that developer anyway, this route is more likely than it first seems. |
| 3. Drift report | No installation. The association is told beforehand (R-42). | No automatic entry. A report of what differs between Union's calendar and the association site: missing events, changed times, cancelled events still online. |

Route 3 is built first (phase 2a), because it is useful under every outcome: it is the fallback if routes 1 and 2 fail, the comparison tool during the dry run, and a check after go-live.

**Drift report.**

- Inputs: the centre's ICS address and the association site's programme page for that centre.
- Reading the site: plain page requests to `/programm/?location_cat[…]=…`, the same page any visitor sees. This gives all dated events shown and the first 8 weekly offers. Whether adding the page's weekday filter to the address returns the remaining weekly offers is not yet known (G-17). The page's own list request (`filter_events`) MUST NOT be used unless the association has agreed.
- Matching: dated events by date and start time, then title similarity. Weekly offers by weekday and overlapping time, then title similarity. Titles differ between the systems, so the title alone is never the key. Ambiguous matches are listed as ambiguous, not guessed.
- Output: one HTML file with four lists: missing on the site; different on the site; on the site but not in the calendar; cancelled but still online. Each entry links to the site record. The file states which weekly offers it could not see.
- Running: a command (`bin/enteronce-drift`) that whoever operates the solution runs by hand or puts on their own scheduler. The output is a private file. Nothing is hosted for them and nothing is published (A-22).
- **R-40** Reading MUST be polite: at most one run per hour, a User-Agent that names this project, and an immediate stop for the day on a 403 or 429 answer.
- **R-41** The report MUST NOT write anywhere except its own output file.
- **R-42** The first run against the live site happens only after the association has been told what the report does and why.

## 5. What we do not know

| ID | Gap | Why it matters | How it could be learned |
|---|---|---|---|
| G-01 | Internal names and formats of the event tool's fields, and what the tool computes on save | The plugin must write to them | Tool code or a test copy; otherwise the wizard at setup |
| G-02 | Whether «abgesagt» and «Ferienpause» can also be set per date | The list data shows one value per record; a per-date form would allow more precise display | Same as G-01 |
| G-03 | Whether WPML needs a translation record per event | Events might be invisible in a language | Test locally; confirm on the real site |
| G-04 | PHP version and scheduler on the association's server | Library version; R-30 | Q-09 |
| G-05 | Who may install a plugin on qtp-basel.ch, and who will look after it | Nothing can go live without them | Q-01 |
| G-06 | Address of Union's Google calendar and what its ICS file contains when events arrive as Outlook invitations: `CLASS` lines, attendee lines, how a cancelled single date looks, how fresh the file is, whether `UID` equals Outlook's | It is the starting source; the tests so far use Outlook-format files only | Q-03; phase 0 experiment with a real invitation |
| G-07 | Behaviour of an Outlook «Programm» calendar: invitations to a resource mailbox, the published date range, how cancellations and moved dates appear | Needed for the later source | Experiments by Union's Microsoft 365 administrator |
| G-08 | How Exchange marks a cancellation in a published ICS | The sample file's `STATUS:CANCELLED` was written by hand | Part of G-07 |
| G-09 | Exact configuration of the Elfsight widgets (filters, sorting, how many highlights) | The replacement should match | Q-10 |
| G-10 | Who can edit union-basel.ch in Webflow and paste an embed | Needed for phase 4 go-live | Q-07 |
| G-11 | Where event images should live, and who holds the rights | Featured images on both sites | Q-06 |
| G-12 | Whether Union agrees to the mappings for Party, Austausch, Familie, Deutschlernende, Highlight | Wrong topics on the association site | Q-05 |
| G-13 | Whether Union wants the AI extras | Running cost and privacy review | Q-08 |
| G-14 | Whether the existing weekly offers may be adopted, and how many there are | Otherwise duplicates. 18 were counted once; the second reading saw only the first 8 | Q-04 |
| G-15 | Volume and real titles of Union's production calendar | Sizing; the test calendar prefixes every title with «Test:» | Becomes visible in the first dry run |
| G-16 | Who will run the drift report | It is a command someone has to start | Q-11 |
| G-17 | Whether the programme page returns more than the first 8 weekly offers to plain page requests | Coverage of the drift report | Try once, politely, after R-42 |
| G-18 | Whether the association minds its programme page being read regularly | R-42 | Q-11 |
| G-19 | What roles Union's and other centres' accounts have in WordPress | Who can see the status page | Q-01 |

## 6. Assumptions and how to change them

Each assumption closes one or more gaps so that work can proceed. **For staff** says what to do after installation; all paths are in WordPress under «Einstellungen → Enter Once». **For developers** names the setting key in `enteronce_settings` and the rule it controls. Section 6.6 lists every key.

### 6.1 Source calendar

**A-01 The plugin reads one ICS address per centre and does not care who publishes it. We start with Union's existing Google calendar and may move to an Outlook «Programm» calendar later.** (G-06, G-07)

- Why: the Google calendar already contains only public events, staff already invite it, and no administrator is needed. The Outlook route removes Google but needs Microsoft 365 administrator rights and untested settings: by default a resource mailbox deletes subject and description, refuses overlapping events and refuses series longer than 180 days (**R**, Microsoft documentation).
- Honest status: the Google route is exactly as untested as the Outlook route. Phase 0 tests it with a real invitation before anything depends on it.
- Risk if wrong: the Google file may lack something we need, or change `UID`s. Then the Outlook route becomes the starting point.
- For staff: paste a different address in «Kalender-Adresse (ICS)». To move to Outlook, follow `docs/de/Outlook-Anleitung.md`; it includes the one-time step that re-links existing records if the two calendars use different identifiers.
- For developers: `sources[].ics_url`. No code path may depend on which service produced the file. A source switch MUST be tested (section 8.4).

**A-02 An entry is public unless it is marked otherwise.** (G-06)

- Default: R-01.
- For staff: nothing to do. Internal bookings must simply not be invited to the public calendar.
- For developers: `internal_markers` = `{ "class_not_public": true, "categories": ["Intern"] }`.

### 6.2 Association site

**A-03 The event tool's fields can be written directly as stored values of the `events` record, once the wizard has learned and verified them.** (G-01, G-02)

- Risk if wrong: the tool may compute values on save that a direct write bypasses. The verification draft (section 4.7, step 3) is where this shows; a dry run cannot show it, because it writes nothing.
- For staff: «Feldzuordnung» → «Neu lernen».
- For developers: `field_map` with keys `date`, `time_start`, `time_end`, `weekday`, `weekly_flag`, `cancelled_flag`, `vacation_flag`, `info_text`, `room`; each entry holds the field name and its value format. `field_map_verified` is set only by step 3. All writes go through one adapter class.

**A-04 Events are created in the site's default language only.** (G-03)

- For staff: translations, if wanted, are added by hand as today.
- For developers: `wpml_mode` = `default_language`. Alternative value `duplicate_all` creates WPML duplicates.

**A-05 Every weekly series becomes one weekly offer, as the site shows them today.** (decided 23 November 2026)

- Why: the site and all 16 centres present recurring offers this way. In the sample calendar 67 of 71 dates come from weekly series; as dated events they would flood the list.
- For staff: «Wöchentliche Termine» → «Jeder Termin einzeln». Before go-live this is only a setting. After go-live the records are rebuilt.
- For developers: `weekly_mode` = `weekly_offer` | `per_date`; `weekly_min_dates` = 4; `weekly_lead_days` = 14; `moved_date_note` = true. Rules R-20 to R-27.

**A-06 A cancelled dated event disappears from the site.** (decided)

- For staff: «Abgesagte Veranstaltungen» → «Mit Vermerk ‹abgesagt› anzeigen».
- For developers: `cancel_mode` = `unpublish` | `badge`.

**A-07 Party maps to «Märkte & Feste», Austausch to «Offener Treffpunkt».** (G-12, decided)

- For staff: «Zuordnung Kategorien», change the row.
- For developers: `category_map`.

**A-08 Familie maps to «Für Kinder» and «Für Erwachsene». Deutschlernende and Highlight are not transferred.** (G-12)

- For staff: «Zuordnung Zielgruppen», change the row. To transfer a tag that has no counterpart, the association first creates the target group.
- For developers: `tag_map`; a value of `null` means "deliberately not transferred". `synonyms` holds the variant spellings.

**A-09 An event is cancelled if its status says so or its title starts with a cancel word.** (G-08)

- For staff: «Abgesagt-Kennzeichen», edit the word list.
- For developers: `cancel_prefixes` = `["Abgesagt:", "Canceled:", "Cancelled:"]`, matched case-insensitively.

**A-10 An event that vanishes from the calendar is unpublished after two runs, about 30 minutes. A run that would unpublish more than 5 events at once waits for a person.** (safety)

- For staff: «Sicherheitsabstand» and «Obergrenze». A held run is released with «Trotzdem anwenden».
- For developers: `removal_grace_runs` = 2; `bulk_unpublish_limit` = 5. R-05, R-13.

**A-11 The sync looks 3 months ahead.** (G-06, G-07)

- Why: the sync must not look further than the source reliably reaches. Outlook's published calendars cover 3 months by default (**R**); expanding a series beyond that would miss its exceptions. A Google calendar exports everything, so the limit can be raised once the source is known.
- For staff: «Vorschau-Zeitraum».
- For developers: `window_months_ahead` = 3; R-08.

**A-12 The sync runs every 15 minutes.** (G-04)

- For staff: if the association's host can set up a scheduler entry, the installation guide gives the line. If not, the plugin still runs, a little less regularly.
- For developers: `sync_interval_minutes` = 15; `stale_after_minutes` = 60; R-30.

**A-13 The server runs PHP 8.1 or newer.** (G-04)

- For developers: sabre/vobject 4.x supports this (**R**: 4.6.1 needs PHP 7.1 or newer, 5.0.0 needs 8.2). Bundle the library with a prefixed namespace so it cannot collide with other plugins.

**A-14 Existing weekly offers are adopted one by one, each confirmed by a person, and keep their title and text.** (G-14)

- For staff: «Bestehende Angebote übernehmen». Anything not confirmed is left untouched. To let the calendar's text replace the site's text, untick «Text der Website behalten» for that offer.
- For developers: `adopt_keep_text` = true (per record: `_enteronce_keep_text`). Adoption writes the `_enteronce_*` meta onto the existing record instead of creating one, after storing a revision.

### 6.3 Images

**A-15 Images live in the association site's media library, named after the event title. Entering events never requires an upload; a new image is optional.** (G-11)

- Why: the featured image has to be in WordPress anyway. Adopted offers keep the image they already have. Events without an image get the category image or the default image, so staff are never forced into WordPress. Uploading is needed only when a new event should get its own picture.
- Cost to know: Union's own site today takes images from the Drive folder. For the widget, the images used there have to be uploaded to the media library once (phase 5 go-live step).
- For staff: upload `Offener_Deutschtreff.jpg` to «Medien». Category and default images are chosen in «Bilder».
- For developers: `image_mode` = `media_library`, `category_images`, `default_image_id`. Lookup is limited to images uploaded for the centre. Title matching ignores case, spaces, underscores and hyphens. An image set by hand on a record is never replaced. Union's 24 offer images (URLs in `webflow-cms-csv/angebote-und-projekte.csv`) are a possible seed, only with Union's consent.

### 6.4 Union's own site

**A-16 Union's site reads the feed from the association's server.** (G-10)

- Risk: Union's calendar depends on qtp-basel.ch being reachable. Mitigated by the cache, the fallback link and the rollback snippet.
- For staff: «Öffentlicher Feed» switches the feed on or off.
- For developers: `public_feed_enabled` = true; `feed_allowed_origins` = `["*"]`; `feed_cache_seconds` = 300.

**A-17 Union's site shows events through an embedded script, not through Webflow CMS items.** (G-09, G-10)

- For staff: to go back, paste the old Elfsight code. To use Webflow CMS instead, a Webflow API token, a new «Veranstaltungen» collection, a page design and a place to run a second job are needed; this is a separate piece of work.
- For developers: keep the feed format stable; a CMS writer would be a second consumer of the same feed.

**A-18 The replacement calendar offers the same filters and layout as today's Elfsight widget.** (G-09)

- For staff: widget options are attributes in the embed snippet (`data-limit`, `data-tag`, `data-view`); the guide lists them.
- For developers: no option lives only in code.

**A-23 Union's site switches to the widget only after the association side has run live for two weeks.** (sequencing)

- Why: one change at a time, and the smaller benefit comes after the larger one.
- For staff: nothing to set; it is a step in the go-live checklist. Skipping phase 4 altogether leaves everything else working.
- For developers: phases 1 to 3 MUST NOT depend on the widget.

### 6.5 Operation and delivery

**A-19 After handover, Union and the association run and maintain the plugin. The developer helps on request.** (G-05, decided)

- For staff: error e-mails go to the addresses in «Benachrichtigung». The guide has a troubleshooting section.
- For developers: `sources[].notify_emails`.

**A-20 AI extras are off.** (G-13)

- For staff: «KI-Funktionen». Needs an API key, has running costs, and should pass the organisation's privacy check first.
- For developers: `ai_enabled` = false. With AI off or unreachable the sync MUST behave identically.

**A-21 The plugin is the primary delivery route. If the association declines, the core goes to the tool's developer. The drift report is delivered in every case.** (G-05)

- For staff: nothing to set. Tell the developer which route the association prefers.
- For developers: the core MUST stay free of WordPress calls and network calls, so that routes 2 and 3 reuse it unchanged.

**A-22 The drift report is a command that the operator runs by hand, with a private file as output.** (G-16, G-18)

- Why: a hosted, scheduled, public version would depend on the developer's account, would publish Union's gaps, and would stop silently when the hosting service pauses inactive jobs.
- For staff: run the command in `docs/de/Abgleichsbericht.md` and open the file it writes. Putting it on a scheduler is possible and described there.
- For developers: `bin/enteronce-drift` with three arguments: ICS address, `location_cat` id, output file. Rules R-40 to R-42.

**A-24 Changes are mailed once a day; problems at once.** (operation)

- For staff: «Benachrichtigung» → «Häufigkeit».
- For developers: `digest_frequency` = `daily` | `each_run` | `weekly`; R-33.

### 6.6 Settings reference

All keys of `enteronce_settings`. No behaviour may depend on a value that is not listed here.

| Key | Default | Used by |
|---|---|---|
| `sources[].id` | (set at setup, e.g. `union`) | everything |
| `sources[].label` | centre name | interface |
| `sources[].ics_url` | (secret) | A-01 |
| `sources[].location_term` | term id in `location_cat` | R-04, adapter |
| `sources[].notify_emails` | (set at setup) | A-19 |
| `sources[].enabled` | true | scheduler |
| `sources[].dry_run` | true until mapping is verified | R-11, R-31 |
| `sync_interval_minutes` | 15 | A-12 |
| `stale_after_minutes` | 60 | R-30 |
| `window_months_ahead` | 3 | A-11 |
| `removal_grace_runs` | 2 | R-05 |
| `bulk_unpublish_limit` | 5 | R-13 |
| `internal_markers` | class not public; categories «Intern» | R-01 |
| `cancel_prefixes` | Abgesagt:, Canceled:, Cancelled: | A-09 |
| `cancel_mode` | `unpublish` | A-06 |
| `weekly_mode` | `weekly_offer` | A-05 |
| `weekly_min_dates` | 4 | R-20 |
| `weekly_lead_days` | 14 | R-26 |
| `moved_date_note` | true | R-24 |
| `category_map`, `tag_map`, `synonyms` | section 4.5 | mapping |
| `field_map`, `field_map_verified` | empty, false | A-03, R-11 |
| `wpml_mode` | `default_language` | A-04 |
| `adopt_keep_text` | true | A-14 |
| `image_mode`, `category_images`, `default_image_id` | `media_library`, empty, none | A-15 |
| `public_feed_enabled` | true | A-16 |
| `feed_allowed_origins` | `["*"]` | A-16 |
| `feed_cache_seconds` | 300 | section 4.8 |
| `digest_frequency` | `daily` | R-33 |
| `ai_enabled` | false | A-20 |

## 7. Work plan

Phases 1 to 5, including 2a, are the committed scope. Phase 0 runs in parallel and blocks nothing. Sizes are rough and relative: S about a quarter of L.

| Phase | Size | Depends on |
|---|---|---|
| 0 Clarifications | S | nothing |
| 1 Core: read and expand | M | nothing |
| 2 Core: diff and safety | M | 1 |
| 2a Drift report | S | 1 |
| 3 WordPress plugin | L | 2 |
| 4 Union website widget | M to L | 3 (feed) |
| 5 Handover and go-live | M | 3; 4 if kept |

### Phase 0: clarifications (parallel)

- [x] Inspect the public structure of qtp-basel.ch (section 3.3).
- [x] Check for outside write paths on qtp-basel.ch: none.
- [x] Obtain both Outlook-format test calendars (section 3.5).
- [ ] Send Union the questions in section 10. The same message tells the association about the drift report (R-42).
- [ ] Ask for the event tool's code or a test copy. The theme is GPL-licensed, so the association is free to share it.
- [ ] Google experiment (G-06): from an Outlook account, invite a Google test calendar to a single event, a weekly series, one moved date, one cancelled date and one cancelled event. Download the Google calendar's ICS. Record: are invited events included, is there a `CLASS` line, which lines name people, how does a cancellation look, are the `UID`s Outlook's. Save the file as `tests/fixtures/google-invited.ics` with personal data replaced.

**Done when:** the questions are sent and the Google experiment is written down with its fixture.

### Phase 1: core, read and expand

- [ ] Project skeleton as in section 4.3.
- [ ] Parse ICS text; integrity check.
- [ ] Screening: R-01 including the entry without a `CLASS` line; R-12.
- [ ] Expand series inside a given window; apply skipped, moved and cancelled dates. Window and "now" are parameters, so tests run with a fixed date.
- [ ] Map Windows time zone names to Europe/Zurich.
- [ ] Tolerant block parser: unclosed block, blank lines, space after `name=`, blocks in any order.
- [ ] Description clean-up; room and location.
- [ ] Synonyms, spelling tolerance, notices (section 4.5).
- [ ] Cancellation rule (A-09), also for a single date of a series.
- [ ] Produce occurrences and series definitions.

**Done when:** every expectation in sections 8.1 and 8.2 passes as an automated test, and the Google fixture, once it exists, parses with documented expectations.

### Phase 2: core, diff and safety

- [ ] Compare calendar records with site records; emit create, update, unpublish, keep, hold.
- [ ] Rules R-04 to R-08 and R-13; weekly-offer rules R-20 to R-27.
- [ ] Dry-run mode (R-31).

**Done when:** every scenario in section 8.3 produces the expected actions, and a second run on unchanged input produces none.

### Phase 2a: drift report (first deliverable)

- [ ] Reader for the programme page as plain page requests; rules R-40 to R-42.
- [ ] Matching rules (section 4.10), with ambiguous matches reported as such.
- [ ] HTML report with the four lists and a statement of what it could not see.
- [ ] Command-line entry point `bin/enteronce-drift`.
- [ ] Saved copies of the programme page as test fixtures. Tests make no network calls.

**Done when:** the tests in section 8.7 pass. A live run for Union, checked by hand against the two websites, follows only after R-42 is satisfied.

### Phase 3: WordPress plugin, association site

Developed against a local WordPress with a stand-in `events` type built from section 3.3. The stand-in's field names are invented and unknown to the plugin, which is what makes it a test of the wizard.

- [ ] Fetcher (R-02, R-03).
- [ ] Adapter that writes through `field_map`; never triggers the tool's series generation (R-10).
- [ ] Field-mapping wizard by comparison, with the verification draft (section 4.7); dry run until verified (R-11).
- [ ] Adoption of existing records with revision, comparison and `adopt_keep_text`.
- [ ] Settings page restricted to administrators; scheduler entry points with lock and stale warning (R-30); manual run.
- [ ] E-mail digest and error mails (R-32, R-33); status page with held runs (R-13); edit-screen notice.
- [ ] Image lookup (A-15).
- [ ] Targeted cache purge (R-09); WPML behaviour (A-04).
- [ ] Stored occurrence list and public feed endpoint (section 4.8).
- [ ] Security measures (section 4.9).
- [ ] German interface texts.

**Done when:** on the local site both test calendars sync end to end; dated events and weekly offers appear correctly; the wizard learns the stand-in's fields; the feed returns the expected occurrences. The test report states clearly whether the plugin was also tried on a copy of the real theme. If it was not, that is the first item of the go-live checklist, and the mapping stays unverified until then.

### Phase 4: Union's own website

In scope by the project owner's decision; separable (section 4.8).

- [ ] Widget: calendar view and highlights view.
- [ ] Styling from Union's stylesheet.
- [ ] Accessibility checks to WCAG 2.1 AA (section 8.5).
- [ ] Fallback message and link.
- [ ] Test page: `webflow-static/quartierzentrum-union/veranstaltungskalender/index.html` with the Elfsight placeholder replaced by the embed snippet.

**Done when:** the static copy, served locally, shows the calendar and the highlights from the local feed with working filters; the accessibility checks pass; switching the feed off shows the fallback.

### Phase 5: handover and guided go-live

The developer prepares the package. Union and the association carry out the remaining steps with the guides; the developer joins if asked.

Package contents:

- Plugin as an installable zip, with source code, under GPL v2 or later. The readme carries a short dedication.
- A cover letter: this is a gift; operation is with Union and the association; how to reach the developer.
- `docs/de/Installationsanleitung.md` for the association's site administrator or the tool's developer: install, learn and verify the field mapping, adopt existing offers, settings, dry run, troubleshooting.
- `docs/de/Outlook-Anleitung.md` for Union's Microsoft 365 administrator: «Programm» calendar, required settings, publishing, the list of experiments, and the switch from the Google source.
- `docs/de/Website-Anleitung.md` for whoever edits union-basel.ch: where to paste the two snippets, how to roll back.
- `docs/de/Kurzanleitung-Team.md`: one page for staff.
- `docs/de/Abgleichsbericht.md`: how to run and read the drift report.
- `docs/de/Entscheidungen.md`: section 6 of this plan in German.
- A test report: what was tested with which calendar, what was not tested against the real tool, and what can only be tested in Union's environment.

Go-live steps, in the guides:

- [ ] If the plugin was never tried on the real theme: do that first, on a copy if one exists.
- [ ] Install with dry run on. Learn and verify the field mapping. Adopt existing weekly offers.
- [ ] Two weeks of dry run: the plugin reports, staff keep entering by hand, results are compared (the drift report helps).
- [ ] Switch dry run off from an agreed date. Hand-entered dated events after that date are removed once by hand; there is no automatic matching by title.
- [ ] Two weeks later, if phase 4 is kept: upload the images Union's site needs, paste the widget snippets into union-basel.ch, keep the Elfsight code for rollback (A-23).

**Done when:** the guides have been followed start to finish on a clean WordPress by someone who did not write them, and what is written matches what happens.

### Phase 6: optional extras

Each is independent and off by default.

- Easy-language text and category suggestions (AI). Generated once per distinct text, approved by a person, labelled as generated.
- Accessibility line per room, taken from `webflow-cms-csv/raume.csv` (floor, wheelchair access, toilets). No AI involved.
- Outlook «Programm» calendar as source, after which the Google chain can be retired.
- Webflow CMS variant of the Union site integration (A-17).

### Phase 7: other centres

Works by design: each centre is one more entry in `sources`, mapped to its own `location_cat` term.

- [ ] Try with a second centre, preferably one that does not use Outlook.
- [ ] Confirm with two feeds that one centre's sync never touches the other's records (R-04).

## 8. Test plan

### 8.1 Sample calendar (`union-beispielkalender.ics`)

Window 19 November 2026 to 31 March 2027; "now" fixed at 19 November 2026, 00:00. Counts were computed by hand and confirmed twice by independent scripts.

| Event | Expected |
|---|---|
| Offener Deutschtreff | 17 dates (19 Thursdays, 24 and 31 December skipped). Categories Bildung, Offener Treffpunkt. |
| Yoga im Union | 19 dates. 7 December is 10:00–11:00 in Grosser Saal; all others 09:00–10:00 in Oberer Saal. 29 March 2027, after the clock change, is still 09:00 local time. |
| Kinderpiazza | 12 dates, last on 8 February 2027. 3 categories, 3 tags. |
| Seniorensport | 19 dates. |
| MittwochsBAR im Union | 1 date. Categories Musik, Party. Tags Erwachsene, Highlight. |
| Brunch Atelier Cuisine Globale | 1 date. |
| Listening-Session | Cancelled: not published; unpublished if it was online. The «Abgesagt:» prefix is removed from the title. No notice, because it is cancelled. |
| Theater Niemandsland | 1 date, no description, no category: 1 info notice. |
| Repair Café | 1 date, `Kulturr` corrected to `Kultur`: 1 warning. |
| Teamsitzung Union | Dropped (internal). |
| Raummiete: Private Geburtstagsfeier | Dropped (internal). |

**Occurrences:** 71 (17 + 19 + 12 + 19 + 4). 2 internal entries dropped, 1 cancellation, 1 warning, 1 info notice. Without R-01 the count would be 73; this is the test of the rule.

**Written to the association site** (A-05): 4 weekly offers (Offener Deutschtreff, Yoga im Union, Kinderpiazza, Seniorensport) and 4 dated events. All four series have at least 4 dates and start within 14 days of "now".

With "now" moved forward, also assert:

- Deutschtreff carries «Ferienpause» from the end of the 17 December date until 31 December has passed (R-21 twice in a row).
- Yoga carries the moved-date note from the end of the 30 November date until 7 December has passed (R-24).
- Kinderpiazza is set to draft after 8 February 2027 (R-23).

**In the feed for Union's site:** all 71 occurrences as single dates.

### 8.2 Live test calendar (`calendar.ics`)

Window 4 January 2027 to 31 March 2027; "now" fixed at 4 January 2027, 00:00.

| Entry | Kind | Blocks | Expected |
|---|---|---|---|
| Test: Deutsch im Alltag (Monday 09:00) | Weekly until 21 June 2027 | Bildung; Deutschlernende, Erwachsene | Weekly offer. The space in `name= Bildung` is tolerated. |
| Test: Deutsch im Alltag (Wednesday 09:00) | Weekly until 23 June 2027 | same | A separate weekly offer. |
| Test: Deutsch im Alltag (Tuesday 09:15) | Single, 5 January 2027 | none | Dated event, info notice. Its description is Kinderpiazza's (a copy error in the calendar); passed through as is. |
| Test: Café Balance | Weekly, Monday 14:00 | Bewegung, Austausch; Senior:innen in an unclosed block | Weekly offer. Topics Bewegung & Entspannung and Offener Treffpunkt; target group Für Senioren. |
| Test: Theater Niemandsland | Weekly, Friday 18:00 | Kultur; Erwachsene, tag block first | Weekly offer. Block order does not matter. |
| Test: Offener Deutschtreff | Weekly, Thursday 15:00, until 25 March 2027 | none | Weekly offer, info notice. |
| Test: Kinderpiazza | Single, 4 January 2027 | none | Dated event, info notice. |
| Test: Konzert Terrible two | Single, 5 January 2027 | none | Dated event, info notice. |
| Test: Café Dialogue | Single, 6 January 2027 | none | Dated event, info notice. |

**Written to the association site:** 5 weekly offers and 4 dated events; 5 info notices, 0 warnings.

Also to be asserted:

- With "now" at 23 November 2026 and the window ending 23 February 2027: the 4 dated events are created, and no weekly offer exists yet, because every series starts more than 14 days later (R-26).
- `<mailto:…>` and `<https://…>` remnants are removed from descriptions.
- The three entries titled «Test: Deutsch im Alltag» stay three separate records.
- A location holding only a room name is read correctly.
- A second run produces no actions although `DTSTAMP` changed.

### 8.3 Change scenarios

| Change | Expected actions |
|---|---|
| Same file again | none |
| A single event changes its time | 1 update, 0 creates |
| A whole series changes its time | updates only; 0 creates, 0 unpublishes |
| One date of a series is moved (`per_date` mode) | 1 update |
| One date of a weekly offer is moved | the record gets the moved-date note while that date is next (R-24) |
| A series moves to another weekday | old records unpublished, new ones created (known limit) |
| An event is deleted from the file | first run: none; second run: 1 unpublish |
| Six events are deleted from the file at once | second run: no unpublish; run held; e-mail sent (R-13) |
| An event is cancelled | 1 unpublish at once (A-06) |
| An unpublished event returns | the same record is published again |
| A weekly series ends and a new one with the same weekday, time and title appears | the draft is reused; no new record (R-27) |
| A weekly series starts in 6 weeks | no record yet; created 14 days before the first date (R-26) |
| A weekly series has 3 dates | 3 dated events, no weekly offer (R-20) |
| Empty, broken or truncated text | none, plus an error report |
| A mapping table changes | the affected records update |
| Two centres' feeds | one centre's run never touches the other's records |

### 8.4 Cases not covered by either file

To be added with hand-made or recorded fixtures:

- An entry without a `CLASS` line: kept (R-01).
- An entry with attendee lines: no person's name or address in any output (R-12).
- A single date of a series cancelled; an event cancelled only by its title prefix.
- A series split by "this and all following events".
- All-day event; multi-day event; "first Saturday of the month"; every two weeks; series without an end date; series with two weekdays.
- Source switch: the same events once from the Google fixture and once from an Outlook file; assert whether keys match, and that the re-link step in the guide works.

### 8.5 Widget

- Renders the sample feed: 71 cards across the window, grouped by month.
- Filters by category and room; text search; highlights view shows only entries tagged Highlight.
- Accessibility to WCAG 2.1 AA: an automated checker reports no violations; every function is reachable and operable with the keyboard alone; a screen reader announces headings, filters, and each card's date, title, time and place in a sensible order. The three results are recorded in the test report.
- Feed unreachable: fallback message and link.
- The page works when opened from a different web address than the configured one (feed open to all origins).

### 8.6 Testing without Outlook

The developer's machine needs no Outlook. Files cover parsing and diffing. A browser-based Outlook.com account and a Google calendar cover the phase 0 experiment and "create an event, see it appear, change it, cancel it". What only Union's environment can show (the «Programm» mailbox, the real publishing range, the real event tool) is listed in the test report and in the guides.

### 8.7 Drift report

Fixtures: `calendar.ics` and saved copies of the programme page for Union.

- A calendar event with no counterpart on the site appears under "missing on the site".
- A weekly offer whose time differs by 15 minutes is paired by weekday and listed under "different", not under "missing".
- Two site offers on the same weekday and time are listed as an ambiguous match, not paired by guess.
- A site record with no counterpart in the calendar appears under "on the site but not in the calendar".
- A cancelled calendar event that is still listed appears under "cancelled but still online".
- The report says how many weekly offers it could see.
- A 403 answer stops the run and is reported (R-40).
- No request leaves the machine during tests.

## 9. Risks

| # | Risk | Effect | Measure |
|---|---|---|---|
| 1 | The tool's code never arrives | Field names unknown until installation; the plugin is handed over untested against the real tool | Wizard by comparison with a verification draft; dry run until verified (R-11); stated in the test report |
| 2 | The association does not install the plugin | No automatic entry on either site | Delivery routes 2 and 3 (section 4.10) |
| 3 | The setup wizard is too technical for the association's administrator | Installation stalls | The guide addresses the tool's developer; route 2 is the natural fallback |
| 4 | The Google file lacks needed data or the Outlook route does not work | No usable source | Two independent routes (A-01); phase 0 experiment before anything depends on it |
| 5 | The published calendar covers only near dates | Far-off events appear late | A-11; R-08 |
| 6 | A faulty run unpublishes many events | Empty programme | R-03, R-05, R-06, R-13, dry run |
| 7 | Adoption overwrites hand-edited titles or texts | Lost wording | Revision before first write; `adopt_keep_text` on by default |
| 8 | Staff edit managed records in WordPress | Edits are overwritten | Edit-screen notice; images and kept texts stay untouched |
| 9 | Union's site depends on the association's server | Calendar unavailable when that server is down | Cache, fallback link, rollback snippet; go-live last (A-23) |
| 10 | Microsoft or Google change their export format | Sync stops | Runs that fail change nothing; stale warning; rate-limited error e-mails |
| 11 | Nobody maintains the plugin or the widget after handover | A later breakage goes unfixed | Source code, guides and test report in the package; support on request; rollback for the widget |
| 12 | Reading the association's site for the report is seen as unwelcome | Strained relationship before the plugin is even proposed | R-42 first; plain page requests only; R-40 |
| 13 | AI produces a wrong category or misleading easy-language text | Wrong information published | Off by default; fixed value list; approval step; label |

## 10. Open questions for Union

A German e-mail with these questions has been drafted. The first two matter most.

| ID | Question | Closes |
|---|---|---|
| Q-01 | Who at the association is responsible for the website and can decide on installing a plugin? What role does Union's account have there? The answer decides the delivery route (section 4.10). | G-05, G-19 |
| Q-02 | Can we get the event tool's code (theme `qtp`, by k-webs) or a test copy of the site? | G-01, G-02 |
| Q-03 | What is the ICS address of the Google calendar that feeds Elfsight? Who administers Microsoft 365, should Union later prefer the Outlook route? | G-06, G-07 |
| Q-04 | May the plugin adopt the existing weekly offers? | G-14 |
| Q-05 | Are the mappings for Party, Austausch, Familie, Deutschlernende and Highlight acceptable? | G-12 |
| Q-06 | Where should images come from: the WordPress media library, the Drive folder, or the offer images on union-basel.ch? | G-11 |
| Q-07 | Who edits union-basel.ch in Webflow and could paste two snippets? | G-10 |
| Q-08 | Are the AI extras wanted? | G-13 |
| Q-09 | Which PHP version does the association's server run, and can its host set up a scheduler entry? | G-04 |
| Q-10 | How are the two Elfsight widgets configured (filters, sorting, number of highlights)? A screenshot of the settings is enough. | G-09 |
| Q-11 | We would like to compare Union's calendar with the programme page of qtp-basel.ch from time to time, by reading the public page. Is that acceptable to the association, and who at Union would run the comparison? | G-16, G-18 |

## 11. Glossary

| Term | Meaning |
|---|---|
| ICS | A text file format for calendars. A published calendar has an ICS address that returns the current file. |
| Series | A recurring calendar event (`RRULE`). |
| Skipped date, moved date | A date removed from a series (`EXDATE`); a date of a series changed on its own (`RECURRENCE-ID`). |
| «Wöchentliches Angebot» | Weekly offer: the association site's record type for something that happens every week. |
| «Ferienpause», «abgesagt» | Holiday break; cancelled. Status bands on the association site. |
| «Leichte Sprache» | Easy language: a regulated, simplified form of German. |
| Verband | The umbrella association, Verband Quartiertreffpunkte Basel. |
| Dry run | The plugin reports what it would do and changes nothing. |
| Managed record | A WordPress record the plugin created or adopted; the only kind it ever changes. |
| Held run | A run whose unpublish actions wait for a person because there were too many at once. |

## 12. As built (24 November 2026)

Sections 1 to 11 are the plan as it stood before implementation and are left unchanged. This section records the result.

### 12.1 What exists

Everything is in `enter-once-sync/`. `AGENTS.md` there holds the working rules; `docs/dev/` holds the developer documentation.

| Part | Where | Phase |
|---|---|---|
| Core: parse, screen, expand, map, weekly-offer rules, diff | `src/Core/`, `docs/dev/core-api.md` | 1, 2 |
| Drift report | `src/Drift/`, `bin/enteronce-drift`, `docs/dev/drift.md` | 2a |
| WordPress plugin: adapter, wizard, adoption, settings, scheduler, mails, feed | `enter-once-sync.php`, `src/WordPress/`, `docs/dev/wordpress.md`, `docs/dev/ui-labels.md` | 3 |
| Calendar widget for union-basel.ch | `widget/` | 4 |
| Eight German guides, each also rendered to PDF for the package | `docs/de/`, `bin/build-pdf`, `dev/pdf/` | 5 |
| Local test site with a stand-in for the association's event tool | `docker-compose.yml`, `dev/wordpress/` | 3 |
| Installable plugin | `dist/enter-once-sync.zip` | 5 |
| Handover package: plugin zip, guides as Markdown and PDF, self-contained drift tool, full source | `dist/enter-once-uebergabe.zip` | 5 |

### 12.2 Test results

One command runs everything: `dev/wordpress/test`. Last run, alone, on 24 November 2026:

| Suite | Result |
|---|---|
| Unit tests, core and drift | 208 passed, 1 skipped (needs the Google fixture, G-06) |
| WordPress integration tests on the local stand-in | 64 passed |
| Clean install of the plugin zip on an empty WordPress | passed |
| Handover package check | passed |
| Widget (`npm test` in `widget/`) | 93 passed, no automated accessibility violation |

The numbers of sections 8.1 and 8.2 reproduce exactly. An independent review found nine defects in the WordPress shell, five of them reproduced; all were fixed and each fix has a test. Those tests were written after the fixes and were never run against the old code, because the project has no version history.

### 12.3 Where the result deviates from the plan

| Topic | Plan | As built |
|---|---|---|
| Re-linking after a source switch (A-01) | The Outlook guide contains a re-link step | Not implemented. After a switch, records may be created anew and the old ones set to draft; a held run is the expected safety stop. The guides say so. |
| Images (A-15) | Staff upload images named after the title | An image belongs to a centre only when the field «Zentrum (Enter Once)» is set in the media library, and only administrators can set it. Union staff cannot add a new event image on their own. |
| Field-mapping wizard (4.7) | Learns the tool's fields by comparison | Learns only fields stored as post meta. If the tool stores a field elsewhere, the tool's developer is needed. |
| Sidebar info text | Not specified | The calendar's location text is written there. |
| AI extras (A-20) | Off by default | A switch only; no function behind it. |
| Drift report output (R-41) | Writes only its output file | Also writes a small state file next to it, needed for R-40. The hourly limit counts per output folder. |
| Widget `data-view` | Named, not defined | `list` or `grid`. |
| Home-page snippet (4.8) | The `div` line only | The script line is needed there too, because the home page is a separate page. |
| Moved-date note (R-24) | «… im Grossen Saal» | «… im Raum Grosser Saal», to avoid declining room names. |
| Sync window in tests | 3 months by default | The sample calendar yields 71 dates only with a 5-month window; with the default 3 months it yields 55, which is correct. |
| Delivery | Plugin zip and guides | One handover package (12.1). |

### 12.4 Not tested

Each of these can only be checked by, or together with, Union or the association. The German test report (`docs/de/Testbericht.md`) lists them for the recipient.

- The real event tool and theme. The plugin has only ever written to a stand-in built from section 3.3. The first real installation starts in dry run and must pass the wizard's verification draft; `docs/dev/wordpress.md` section 12 lists what to check.
- The Google calendar as source, and the Outlook «Programm» calendar. The phase 0 experiment was not done: it needs real accounts.
- WPML and WP Rocket being present. Only "does nothing when absent" was tested.
- The drift report's reader on the real programme page. Its fixtures are reconstructed, not recorded. No run against the live site has happened, as R-42 requires.
- The widget with a screen reader, in browsers other than Edge, and on the real Webflow site.
- The guides. Nobody has yet followed them from start to finish on a clean site, which section 7 names as the completion criterion of phase 5.
- Mail delivery, the server scheduler, and behaviour under the real server's time and memory limits.

### 12.5 What remains for the project owner

1. Send Union the e-mail with the questions of section 10 (draft: `E-Mail-an-Union.md` next to this file). Q-01 and Q-02 decide the delivery route.
2. Do the phase 0 Google experiment, or ask Union to: it produces the missing test fixture and tells whether the starting source works.
3. Decide with the association how new event images get their centre (12.3).
4. Fill in the placeholders for name and contact in `docs/de/Begleitbrief.md` and `readme.txt`, then rebuild the package with `bin/build-package` (it also renders the PDFs; this needs Node and Edge, Chrome or Chromium on the machine, besides Docker).
5. Have someone who did not write the guides follow them once on a clean WordPress.
