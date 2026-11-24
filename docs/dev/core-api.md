# Core API (`EnterOnce\Core`)

The WordPress-free core of "Enter once": ICS text in, records and actions out. It implements plan sections 4.4, 4.5, 4.6 (R-01, R-03 integrity part, R-04 to R-08, R-12, R-13, R-20 to R-27, R-31) and the settings of 6.6. It has no clock, no network, no file access and no WordPress call (enforced by `tests/Core/CorePurityTest.php`). Same input → same output.

- Code: `src/Core/`, PHP ≥ 8.1, `declare(strict_types=1)`. Dependency: `sabre/vobject` 4.x.
- Tests: `bin/phpunit` (`tests/Core/`). Fixtures: `tests/fixtures/*.ics`.
- Developer command: `bin/php bin/enteronce-core` (section 9).

All times are `DateTimeImmutable` in **Europe/Zurich**. All ISO strings are `DATE_ATOM` (`2027-01-07T15:00:00+01:00`). Local dates are `YYYY-MM-DD`, times `HH:MM`.

## 1. The run in five calls

```php
use EnterOnce\Core\{Core, Window, SiteRecord, IntegrityError};

$core = new Core($settingsArray, 'union');            // one instance per source and run
$now  = new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')); // the SHELL reads the clock
$win  = Window::monthsAhead($now, $core->settings->windowMonthsAhead);

try {
    $result = $core->read($icsText, $now, $win, $lastRunHadEvents);   // CalendarResult
} catch (IntegrityError $e) {
    // R-03: change nothing, report $e->reason / $e->germanMessage(). Do not touch missing_runs.
}
$targets = $core->targets($result);                    // TargetSet
$plan    = $core->diff($targets, $siteRecords);        // SyncPlan; $siteRecords: list<SiteRecord>
$feed    = $core->feed($result->occurrences);          // list<array>, AGENTS.md feed format
```

Store after every successful run (plan 4.4): `array_map(fn ($o) => $o->toArray(), $result->occurrences)` as `enteronce_occurrences_<source_id>`, and `$result->eventCount > 0` for the next run's `$lastRunHadEvents`. Rebuild with `Occurrence::fromArray()` and pass to `$core->feed($occurrences, $range)` to serve the feed.

## 2. Classes and signatures

| Class | Purpose |
|---|---|
| `Core` | Facade (below). |
| `Settings` | Typed, read-only view of `enteronce_settings` for one source. |
| `Window` | Half-open interval `[start, end)`. |
| `IntegrityError` | Exception for R-03. |
| `CalendarResult` | Occurrences, series, notices, counts. |
| `Occurrence` | One date of one event. |
| `SeriesDefinition`, `Slot` | One recurring event; one planned date of it. |
| `EntryFields` | Text fields of one calendar entry after clean-up and mapping. |
| `Notice` | Warning / info (section 4.5). |
| `TargetSet`, `TargetRecord` | What the site should contain. |
| `SiteRecord` | What the site contains (built by the shell). |
| `SyncPlan`, `Action` | Result of the diff. |
| `Feed` | Feed builder. |
| `MovedDateNote` | R-24 note text and markers. |
| `ContentHash` | `_enteronce_hash` rule. |
| `Mapper`, `DescriptionParser`, `CancelRule`, `TitleNormaliser`, `GermanDate` | Building blocks; usable alone. |
| `IcsReader`, `Screener`, `Expander`, `TargetBuilder`, `Differ` | Internal steps; `Core` calls them. |

### `Core`

```php
final class Core {
    public readonly Settings $settings;
    public function __construct(array $settings, string $sourceId);
    /** @throws IntegrityError */
    public function read(string $icsText, DateTimeImmutable $now, Window $window, bool $lastRunHadEvents = false): CalendarResult;
    public function targets(CalendarResult $result): TargetSet;
    /** @param list<SiteRecord> $siteRecords */
    public function diff(TargetSet $targets, array $siteRecords, bool $releaseHeld = false): SyncPlan;
    /** @param list<Occurrence> $occurrences @return list<array<string,mixed>> */
    public function feed(array $occurrences, ?Window $range = null): array;
}
```

- `read()`: integrity check, time zones, screening, expansion, normalisation. `$now` is converted to Europe/Zurich.
- `diff(..., releaseHeld: true)`: used after «Trotzdem anwenden»; R-13 does not hold this run. Re-run the diff with fresh data rather than replaying stored actions.
- `feed($occ, $range)`: `$range` = `Window::fromDates($from, $to)` built from the request's `from`/`to`; the shell clamps it to the sync window (plan 4.8). `null` = all given occurrences.

### `Window`

```php
new Window(DateTimeImmutable $start, DateTimeImmutable $end);  // end exclusive, converted to Europe/Zurich
Window::fromDates('2026-11-19', '2027-03-31');  // 19 Nov 00:00 → 1 Apr 00:00 (last day inclusive)
Window::monthsAhead($now, 3);                    // start of today → end of the day 3 months later
$w->overlaps($start, $end): bool;                // start < w.end && end > w.start
$w->toArray(): ['start' => ATOM, 'end' => ATOM];
```

An occurrence is inside the window when it overlaps it.

### `IntegrityError` (extends `RuntimeException`)

`public readonly string $reason` is one of:

| Constant | Value | When |
|---|---|---|
| `REASON_EMPTY_TEXT` | `empty_text` | empty or whitespace only |
| `REASON_NOT_A_CALENDAR` | `not_a_calendar` | does not start with `BEGIN:VCALENDAR` (e.g. an HTML error page) |
| `REASON_TRUNCATED` | `truncated` | does not end with `END:VCALENDAR` |
| `REASON_PARSE_ERROR` | `parse_error` | sabre/vobject cannot parse it |
| `REASON_NO_EVENTS` | `no_events` | no `VEVENT` although `$lastRunHadEvents` |

`getMessage()` is English (logs), `germanMessage()` German (status page, e-mail). Download errors and https checks (R-02, rest of R-03) belong to the shell.

### `Settings`

Built by `Core`; read it as `$core->settings`. Public readonly properties and methods:

| Member | Settings key (section 6.6) | Default |
|---|---|---|
| `string $sourceId` | — | constructor argument |
| `array $source` | the `sources[]` entry whose `id` equals `$sourceId` (plus defaults `label`, `location_term` = null, `enabled` = true, `dry_run` = true) | |
| `int $windowMonthsAhead` | `window_months_ahead` | 3 |
| `int $removalGraceRuns` | `removal_grace_runs` (min 1) | 2 |
| `int $bulkUnpublishLimit` | `bulk_unpublish_limit` | 5 |
| `bool $internalClassNotPublic` | `internal_markers.class_not_public` | true |
| `list<string> $internalCategories` | `internal_markers.categories` | `["Intern"]` |
| `list<string> $cancelPrefixes` | `cancel_prefixes` | `["Abgesagt:", "Canceled:", "Cancelled:"]` |
| `string $cancelMode` | `cancel_mode`: `unpublish` \| `badge` | `unpublish` |
| `string $weeklyMode` | `weekly_mode`: `weekly_offer` \| `per_date` | `weekly_offer` |
| `int $weeklyMinDates` | `weekly_min_dates` | 4 |
| `int $weeklyLeadDays` | `weekly_lead_days` | 14 |
| `bool $movedDateNote` | `moved_date_note` | true |
| `array<string, list<string>\|null> $categoryMap` | `category_map` | section 4.5 |
| `array<string, list<string>\|null> $tagMap` | `tag_map` | section 4.5 |
| `array<string, string> $synonyms` | `synonyms` | `Familien → Familie`, `Kulinarisches → Essen & Trinken` |
| `bool $fieldMapVerified` | `field_map_verified` | false |
| `bool $adoptKeepText` | `adopt_keep_text` | true (read, not used by the diff: the per-record flag decides) |
| `isDryRun(): bool` | `sources[].dry_run` OR NOT `field_map_verified` (R-11, R-31) | true |
| `locationTerm(): int\|string\|null` | `sources[].location_term` | null |
| `Settings::defaults(): array` | all defaults above as a settings array | |
| `Settings::timezone(): DateTimeZone` | — | Europe/Zurich |

Map format: calendar name → list of association term **names** (a single string is accepted), or `null` = deliberately not transferred. Default `category_map`: Essen & Trinken → [Essen & Trinken], Kultur → [Kulturelles & Musik], Musik → [Kulturelles & Musik], Beratung → [Beratung & Bildung], Bildung → [Beratung & Bildung], Bewegung → [Bewegung & Entspannung], Kreativität → [Kreativität & Spielen], Offener Treffpunkt → [Offener Treffpunkt], Party → [Märkte & Feste], Austausch → [Offener Treffpunkt]. Default `tag_map`: Babys & Kleinkinder → [Für Babys und Kleinkinder], Kinder → [Für Kinder], Jugendliche → [Für Jugendliche], Erwachsene → [Für Erwachsene], Senior:innen → [Für Senioren], Familie → [Für Kinder, Für Erwachsene], Deutschlernende → null, Highlight → null. The shell resolves names to term IDs (plan 4.5). No other key is read by the core; all other keys stay in `$settings->raw`.

## 3. Records

### `Occurrence` — `toArray()`

```jsonc
{
  "source_id": "union",
  "uid": "union-yoga@example",
  "sync_key": "union:union-yoga@example:2026-12-07", // single event: "union:<uid>"; series date: "union:<uid>:<original local date>"
  "title": "Yoga im Union",                          // cancel prefix removed
  "start": "2026-12-07T10:00:00+01:00",
  "end": "2026-12-07T11:00:00+01:00",                // exclusive; all-day: midnight after the last day
  "all_day": false,
  "room": "Grosser Saal",                            // first comma-separated part of LOCATION; "" if none
  "location": "Grosser Saal, Quartierzentrum Union, Klybeckstrasse 95, 4057 Basel", // whole LOCATION; "" if none
  "description": "Sanftes Yoga …",                   // plain text, blocks and link remnants removed
  "description_html": "<p>Sanftes Yoga …</p>",       // only <p> and <br> around escaped text
  "categories": ["Bewegung"],                        // canonical calendar names (after synonym/spelling)
  "tags": ["Erwachsene"],                            // canonical calendar names, incl. not-transferred ones
  "cancelled": false,
  "series_uid": "union-yoga@example",                // null for a single event
  "series_key": "union:union-yoga@example",          // null for a single event (= feed series_id)
  "original_date": "2026-12-07",                     // null for a single event
  "moved": true                                      // series date differs in day, time or room
}
```

PHP properties: `sourceId, uid, syncKey, title, start, end, allDay, room, location, description, descriptionHtml, categories, tags, cancelled, seriesUid, originalDate, moved`; `seriesKey(): ?string`; `Occurrence::fromArray(array)`.

`CalendarResult::$occurrences` contains **cancelled occurrences too** (`cancelled: true`). "71 occurrences" in plan 8.1 = `activeOccurrences()`.

### `CalendarResult`

Properties: `sourceId`, `now`, `window`, `occurrences` (sorted by start, then key), `series` (list<SeriesDefinition>, every series of the file, also those outside the window), `notices` (list<Notice>), `droppedInternal` (VEVENT components dropped by R-01), `eventCount` (VEVENT components in the file before screening). Methods: `activeOccurrences()`, `cancelledOccurrences()`, `warnings()`, `infos()`, `summary()`:

```json
{"events_in_file":12,"dropped_internal":2,"occurrences_active":71,"occurrences_cancelled":1,"cancelled_events":1,"series":4,"warnings":1,"infos":1}
```

### `SeriesDefinition` — `toArray()`

```jsonc
{
  "source_id": "union", "uid": "union-deutschtreff@example", "sync_key": "union:union-deutschtreff@example",
  "title": "Offener Deutschtreff", "cancelled": false,
  "rrule": "FREQ=WEEKLY;UNTIL=20270325T230000Z;BYDAY=TH", "freq": "WEEKLY", "interval": 1,
  "weekly": true,                         // FREQ=WEEKLY, INTERVAL=1, no RDATE → weekly-offer candidate
  "weekdays": [{"iso":4,"code":"TH","name":"Donnerstag","first_date":"2026-11-19","last_date":"2027-03-25","date_count":17}],
  "all_day": false, "time_start": "15:00", "time_end": "17:00",
  "first_date": "2026-11-19", "last_date": "2027-03-25",   // dates that take place (skipped ones excluded)
  "date_count": 17,                       // whole series, not only the window; null if open-ended
  "open_ended": false,
  "skipped_dates": ["2026-12-24","2026-12-31"],            // EXDATE, and dates marked internal
  "moved_dates": [{"original_date":"…","start":"ATOM","end":"ATOM","room":"…"}],
  "cancelled_dates": [],
  "synced": true,                         // false: two dates on one day (warning), nothing of it is synced
  "room": "…", "location": "…", "description": "…", "description_html": "…", "categories": [], "tags": []
}
```

PHP: `slots` (list<Slot>, the regular pattern plus changed dates outside it, sorted), `fields` (EntryFields of the master), `weekdays` (ISO ints), `patternStart`/`patternEnd` (first planned date), `conflict`, `datedSlots(?int $weekday)`, `weekdayStats(int $weekday)`.

`Slot`: `originalDate`, `originalStart`, `originalEnd`, `status` (`regular` | `skipped` | `moved` | `cancelled` | `changed` — changed = own entry but same day, time and room), `actualStart`, `actualEnd`, `fields` (?EntryFields), `extra`, `weekday()`, `takesPlace()`, `passedAt()` = max(original end, actual end).

### `Notice` — `toArray()`

```json
{"level":"warning","code":"value_corrected","source_id":"union","uid":"union-repaircafe-1205@example","title":"Repair Café","field":"category","value":"Kulturr","corrected_to":"Kultur","message":"«Repair Café»: unbekannte Kategorie (event-type) «Kulturr» wurde zu «Kultur» korrigiert."}
```

| level | code | when |
|---|---|---|
| `warning` | `value_corrected` | unknown category/tag corrected by spelling tolerance |
| `warning` | `value_dropped` | unknown category/tag dropped |
| `warning` | `two_dates_one_day` | a series has two dates on one original local day; `value` lists the days |
| `info` | `no_category` | no category left after resolution |

Once per event or series (deduplicated by level, code, uid, field, value). Never for cancelled entries, never for internal ones, never for values mapped to `null`, never for synonyms, never for a missing tag. Only for events/series with at least one non-cancelled occurrence in the window. `message` is German and contains the title only — no person data. Group by `uid` for the digest.

### `TargetRecord` — `toArray()`

Common keys:

```jsonc
{
  "kind": "weekly_offer",          // or "dated_event"
  "key": "union:union-yoga@example:MO",
  "source_id": "union", "uid": "union-yoga@example", "series_uid": "union-yoga@example",
  "status": "publish",             // or "draft"
  "status_reason": null,           // "cancelled" | "series_ended" when draft
  "past": false,                   // R-07
  "start": "ATOM",                 // event start / first date of the weekly offer
  "end": "ATOM",                   // event end / end of the last date; null when open-ended
  "next_date": "2026-11-23",       // weekly only: next planned date (R-21); not hashed
  "fields": { … },                 // exactly what the adapter writes; the hash is computed from it
  "hash": "v1:…",                  // _enteronce_hash for a normal record
  "hash_without_text": "v1:…"      // _enteronce_hash for a record with _enteronce_keep_text
}
```

`fields` of a **weekly offer** (`key` = `source:uid:` + `MO`…`SU`):

| Field | Type | Meaning |
|---|---|---|
| `title` | string | |
| `body` | string (HTML) | moved-date note (if any) + `description_html` |
| `weekday` | int 1–7 | ISO, Monday = 1 (`GermanDate::weekdayName()` gives «Montag») |
| `time_start`, `time_end` | `HH:MM` \| null | regular time; null if all-day |
| `all_day` | bool | |
| `room`, `location` | string | regular room / LOCATION |
| `topics` | list<string> | association `topic` names |
| `target_groups` | list<string> | association `targetgroup` names |
| `ferienpause` | bool | R-21 |
| `abgesagt` | bool | R-22 (or whole series cancelled with `cancel_mode` badge) |
| `note` | string \| null | R-24 note, plain text |
| `first_date`, `last_date` | `YYYY-MM-DD`, last null if open-ended | per weekday |
| `location_term` | int\|string\|null | `sources[].location_term` |

`fields` of a **dated event** (`key` = occurrence `sync_key`):

| Field | Type | Meaning |
|---|---|---|
| `title`, `body` | string | `body` = `description_html` |
| `date`, `end_date` | `YYYY-MM-DD` | first and last day (all-day end made inclusive) |
| `time_start`, `time_end` | `HH:MM` \| null | null if all-day; on a multi-day event: start time on `date`, end time on `end_date` |
| `all_day` | bool | |
| `room`, `location`, `topics`, `target_groups`, `location_term` | as above | |
| `cancelled` | bool | true only with `cancel_mode` badge (else the target is a draft) |

`ferienpause` and `abgesagt` are never both true (one planned date is next). `TargetRecord::hashFor(bool $keepText)`.

### `TargetSet`

`sourceId`, `now`, `window`, `targets` (list<TargetRecord>: everything that should exist on the site, publish or draft), `pending` (weekly offers whose first date is more than `weekly_lead_days` away, R-26: not on the site yet; useful for the status page). Methods: `ofKind(string $kind, ?string $status = null)`, `byKey(string)`, `summary()`:

```json
{"weekly_offers":4,"dated_events":4,"weekly_offers_draft":0,"dated_events_draft":1,"past":0,"pending_weekly_offers":0}
```

(`weekly_offers`/`dated_events` count status publish, including past ones.)

### `SiteRecord` (input, built by the shell)

```php
new SiteRecord(
    string $key,               // _enteronce_key
    string $kind,              // 'dated_event' | 'weekly_offer'
    ?string $hash,             // _enteronce_hash, null if never written by the sync (just adopted)
    string $status,            // 'publish' | anything else counts as draft
    int $missingRuns,          // _enteronce_missing_runs
    string $source,            // _enteronce_source
    bool $managed = true,      // _enteronce_managed == 1
    bool $keepText = false,    // _enteronce_keep_text
    array $flags = [],         // weekly: ['ferienpause'=>bool,'abgesagt'=>bool]; dated: ['cancelled'=>bool]; informational
    ?DateTimeImmutable $start = null, // span start: event start / first date of the weekly offer
    ?DateTimeImmutable $end = null,   // span end: event end / end of the last date; null if unknown or open-ended
    ?string $title = null,     // weekly only, for R-27
    ?int $weekday = null,      // weekly only, ISO 1–7, for R-27
    ?string $timeStart = null, // weekly only, "HH:MM", for R-27
    int|string|null $id = null // post ID, passed through to actions
);
SiteRecord::fromArray(array $a);   // keys as in toArray(): key, kind, hash, status, missing_runs, source, managed, keep_text, flags, start, end, title, weekday, time_start, id
SiteRecord::fromTarget(TargetRecord $t, bool $keepText = false, $id = null); // the record as stored after writing $t
```

Pass every record the shell finds; unmanaged records and other sources are ignored (R-04). `start`/`end` are needed for R-07 and R-08 on records missing from the calendar: the shell can read them from the record's date fields, or store them in its own meta when writing (they are `TargetRecord::$start/$end`). Without them a missing record is treated as current and inside the window. `title`/`weekday`/`timeStart` are needed only for R-27 draft reuse.

### `Action` — `toArray()`

```jsonc
{
  "type": "update",            // create | update | unpublish | keep | hold
  "kind": "weekly_offer",
  "key": "union:…:MO",         // key the record has after the action
  "reason": "changed",         // see table
  "status": "publish",         // post status after the action
  "missing_runs": 0,           // value of _enteronce_missing_runs to store
  "site_key": "union:…:MO",    // existing record's key (null for create); differs from key on R-27 reuse
  "site_id": 123,              // SiteRecord::$id passed through
  "hash": "v1:…",              // _enteronce_hash to store (null = leave)
  "flag_changes": {"ferienpause": [false, true]}, // when the site record's flags were given
  "target": { …TargetRecord… } // null for missing-record actions
}
```

| type | reason | Shell does |
|---|---|---|
| create | `new` | create post from `target.fields`, publish, store meta (`_enteronce_key` = key, hash, managed, source, missing_runs 0) |
| update | `changed` | write `target.fields` (for keep-text records skip `title`/`body`, but put `fields.note` on top of the site body with `MovedDateNote::apply($siteBody, $note)`), store `hash` |
| update | `republish` | as `changed`, and publish again (R-06) |
| update | `reuse_draft` | as `republish`, and rewrite `_enteronce_key` from `site_key` to `key` (R-27) |
| unpublish | `missing` | set to draft (R-05; counter reached `removal_grace_runs`) |
| unpublish | `cancelled` | set to draft at once (A-06) |
| unpublish | `series_ended` | set to draft (R-23) |
| keep | `unchanged`, `past`, `already_draft`, `outside_window`, `not_yet_due` | nothing |
| keep | `missing_grace` | store `missing_runs` only |
| hold | the unpublish reason | nothing; list on the status page, mail at once (R-13) |

Rule: for every action store `missing_runs` if it differs from the stored value. Present records always get 0.

### `SyncPlan`

`sourceId`, `actions` (list<Action>: one per target that needs a record and one per managed site record of the source), `dryRun` (bool), `held` (bool). Methods: `ofType(string)`, `changes()` (all but keep), `hasChanges()`, `counts()` (`{"create":…, "update":…, "unpublish":…, "keep":…, "hold":…}`), `toArray()`.

- **Dry run** (`dryRun = true`, R-11/R-31): show the plan, apply **nothing**, not even `missing_runs`.
- **Held** (`held = true`, R-13): more than `bulk_unpublish_limit` unpublish actions (any reason) → all of them became `hold`. Everything else in the plan may be applied. «Trotzdem anwenden» = run the diff again with `releaseHeld: true`.
- A second run on unchanged input gives `changes() === []`.

## 4. Rules the diff applies

1. R-04: only `managed` records whose `source` equals the source id.
2. Target with a record: past → keep (except weekly offer still published → unpublish `series_ended`); target draft → unpublish if published, else keep; record draft → update `republish`; hash differs (`hashFor(record.keepText)`) → update `changed`; else keep.
3. Target without a record: draft or past → nothing; weekly offer → R-27 reuse of a draft weekly record of the source with the same weekday, start time and normalised title, if that draft's own series is gone or over; else create.
4. Record without a target: pending weekly offer → keep; `end ≤ now` → keep `past` (published weekly offer → unpublish `series_ended`); outside the window (`start ≥ window end` or `end ≤ window start`) → keep; draft → keep; else `missing_runs + 1`, unpublish when it reaches `removal_grace_runs`.
5. R-13 over all unpublish actions.

## 5. Target rules (`TargetBuilder`)

- `weekly_mode = weekly_offer`: every series with `weekly = true` gives one weekly offer per weekday that has at least `weekly_min_dates` dates (an open-ended series always qualifies). Shorter weekdays, non-weekly series (monthly, every two weeks: R-25) and `per_date` mode give one dated event per occurrence in the window.
- R-26: a weekly offer whose first date is later than `now + weekly_lead_days` goes to `pending`; its dates do not become dated events either.
- Next planned date: the first slot of that weekday in the regular pattern with `passedAt() > now`. `skipped` → `ferienpause`; `cancelled` → `abgesagt`; `moved` → `note` (if `moved_date_note`).
- R-23: last date passed (`passedAt() ≤ now`) → status draft, `series_ended`, `past`.
- Cancelled: dated event → draft with `cancel_mode = unpublish`, publish with `cancelled: true` with `badge`. A whole cancelled series as weekly offer → draft (`unpublish`) or `abgesagt` (`badge`).
- A series with two dates on one original local day (`conflict`) gives nothing.

## 6. Text rules

- **Screening** (R-01): dropped when `CLASS` exists and is not `PUBLIC` (case-insensitive), or a `CATEGORIES` value equals an `internal_markers.categories` entry (case-insensitive). No `CLASS` = public. An internal master drops its whole series; an internal changed date counts as a skipped date.
- **R-12**: after screening every property except `UID SUMMARY DTSTART DTEND DURATION RRULE RDATE EXDATE RECURRENCE-ID LOCATION DESCRIPTION STATUS CLASS CATEGORIES` and every sub-component (VALARM) is removed. `DESCRIPTION` passes as is (contact data in it is public, plan 4.9).
- **Time zones**: Windows names with Central European rules (`W. Europe Standard Time`, `Central Europe Standard Time`, `Central European Standard Time`, `Romance Standard Time`) → Europe/Zurich; IANA names as given; other names via sabre's lookup; unknown names and floating times → Europe/Zurich (never the server zone). All output in Europe/Zurich.
- **Cancellation** (A-09): `STATUS:CANCELLED`, or the title starts with a `cancel_prefixes` entry (case-insensitive, repeated prefixes removed).
- **Blocks**: `[event-type]` and `[tag]` (case-insensitive), `name = Value` lines, blank lines, any order, several blocks; a block ends at its closing marker, the next opening marker, the first non-empty line that is not `name=`, or the end of the text.
- **Clean-up**: `<mailto:…>`, `<http(s)://…>`, `<tel:…>` removed; HTML reduced to text (script/style dropped); trailing spaces trimmed; more than one blank line collapsed; HTML output built from text with `<p>` / `<br>` only.
- **Mapping**: exact (case and space insensitive) → silent; synonym → silent; Levenshtein ≤ 1 in characters to exactly one key → corrected + warning; else dropped + warning.
- **Moved-date note** (`MovedDateNote`): `«Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr im Raum Grosser Saal»`; only time → `«Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr»`; only room → `«Am 7. Dezember ausnahmsweise im Raum Grosser Saal»`; other day → `«Statt am 7. Dezember ausnahmsweise am Dienstag, 8. Dezember, 10:00–11:00 Uhr»` (+ ` im Raum …`). Markup `<!-- enteronce:note --><p class="enteronce-note">…</p><!-- /enteronce:note -->`. `MovedDateNote::apply(string $body, ?string $note): string` replaces any old note; `strip(string $body): string` removes it (by comments, fallback by class).
- **Title normalisation** (R-27, `TitleNormaliser::normalise($title, $prefixes)`): cancel prefixes removed, lower case, runs of non-letters/digits → one space, trimmed.
- **Hash** (`ContentHash::of(array $fields, bool $withoutText = false)`): `"v1:" . sha256(json)`; keys sorted, `topics`/`target_groups` sorted, JSON with unescaped Unicode and slashes; `$withoutText` drops `title` and `body`. Status is not hashed; DTSTAMP, LAST-MODIFIED, SEQUENCE never reach `fields`.

## 7. Feed (`Feed::build(array $occurrences, Settings $settings, ?Window $range = null)`)

Returns the AGENTS.md format: `id` (= sync key), `title`, `start`, `end`, `all_day`, `room`, `location`, `description_html`, `categories`, `tags`, `image` (always `null` from the core; the shell sets `{url, alt}` per A-15), `cancelled`, `series_id` (null for single events). Sorted by start, then id. Cancelled occurrences are left out with `cancel_mode = unpublish` and included with `cancelled: true` with `badge`. All-day items start at local midnight and end at the following midnight (exclusive).

## 8. Worked example

Sample calendar, "now" 19 November 2026 00:00, window 19 November 2026 – 31 March 2027, no site records yet:

```php
$core = new Core([], 'union');
$r = $core->read(file_get_contents('tests/fixtures/union-beispielkalender.ics'),
                 new DateTimeImmutable('2026-11-19 00:00', new DateTimeZone('Europe/Zurich')),
                 Window::fromDates('2026-11-19', '2027-03-31'));
count($r->activeOccurrences());   // 71
$r->droppedInternal;              // 2
count($r->cancelledOccurrences()); // 1  (Listening-Session, title without «Abgesagt:»)
$r->warnings()[0]->code;          // "value_corrected" (Repair Café, Kulturr → Kultur)
$r->infos()[0]->title;            // "Theater Niemandsland"

$t = $core->targets($r);
$t->summary();                    // weekly_offers 4, dated_events 4, dated_events_draft 1 (the cancelled one)
$yoga = $t->byKey('union:union-yoga@example:MO');
$yoga->fields['time_start'];      // "09:00"; fields['note'] null now, set from 30 Nov 10:00 to 7 Dec 11:00

$plan = $core->diff($t, []);
$plan->counts();                  // create 8, others 0; $plan->dryRun === true (mapping not verified)
```

Apply the plan (store `SiteRecord::fromTarget($a->target)` per create), run again with the same file: `$plan->changes() === []`. Remove the Brunch from the file: first run keep `missing_grace` (`missing_runs` 1), second run unpublish `missing`.

## 9. Developer command

```
bin/php bin/enteronce-core <mode> --ics=FILE --now=DATETIME --from=YYYY-MM-DD --to=YYYY-MM-DD
                           [--source=union] [--settings=FILE.json] [--site=FILE.json] [--release-held]
```

`--now` is local Europe/Zurich unless it has an offset. Window = `--from` 00:00 to end of `--to`. Paths are relative to the project folder (the container mounts it at `/app`).

| mode | Output (JSON) |
|---|---|
| `occurrences` | `{source_id, now, window, summary, notices, occurrences}` |
| `series` | `{…, series}` |
| `targets` | `{…, targets_summary, weekly_offers, dated_events, pending_weekly_offers}` |
| `feed` | the feed array for the window |
| `plan` | `{…, plan}`; `--site` = JSON list of `SiteRecord::toArray()` objects (default empty) |

Exit code 2 with `{error, message, message_de}` on an integrity error.

Reference numbers:

| Calendar | Arguments | Result |
|---|---|---|
| `union-beispielkalender.ics` | `--now=2026-11-19T00:00 --from=2026-11-19 --to=2027-03-31` | 71 active + 1 cancelled occurrences, 2 dropped, 1 warning, 1 info; 4 weekly offers, 4 dated events (+1 draft) |
| `calendar.ics` | `--now=2027-01-04T00:00 --from=2027-01-04 --to=2027-03-31` | 67 occurrences; 5 weekly offers, 4 dated events; 5 info, 0 warnings |
| `calendar.ics` | `--now=2026-11-23T00:00 --from=2026-11-23 --to=2027-02-23` | 4 dated events, 0 weekly offers, 5 pending |
