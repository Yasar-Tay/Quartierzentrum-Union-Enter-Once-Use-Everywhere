# The WordPress shell (`EnterOnce\WordPress`)

Phase 3 of `IMPLEMENTATION_PLAN.md`: the plugin around the core (`docs/dev/core-api.md`). Everything a person sees is German; this document is for developers.

**Nothing in this plugin has been tried against the real event tool of qtp-basel.ch.** All integration tests run against a local stand-in built from the outside observations of plan section 3.3. See section 9 for what a person must check on the first real installation.

## 1. Quick start

```bash
dev/wordpress/start     # local site on http://localhost:18471 (admin / enteronce-dev), seeded
dev/wordpress/test      # ALL tests: core + drift unit tests, WordPress integration tests, zip + package build, clean install, package check
dev/wordpress/stop      # stop (data kept)
dev/wordpress/reset     # delete everything and start fresh
dev/wordpress/seed      # restore the stand-in's demo records
bin/wp <wp-cli args>    # WP-CLI against the local site, e.g. bin/wp enteronce status
bin/build-zip           # dist/enter-once-sync.zip
bin/build-package       # dist/enter-once-uebergabe.zip (plugin zip, guides as Markdown and PDF, drift report, source)
bin/build-pdf           # dist/pdf/*.pdf from docs/de/*.md (host: Node and Edge, Chrome or Chromium; called by bin/build-package)
```

**The local site is single-user:** only one person or session may start, stop, test or seed it at a time; the tests delete its events and options.

Requirements: Docker. The host needs no PHP (see `AGENTS.md`). Shell: Git Bash. The scripts convert the folder path for Docker on Windows themselves.

Only the WordPress integration tests:

```bash
docker compose exec -T -w /var/www/html/wp-content/plugins/enter-once-sync wordpress \
  php vendor/bin/phpunit -c tests/WordPress/phpunit.xml [--filter Name]
```

They run inside the `wordpress` container against its database, delete all events and the plugin's options, and run in about one minute. `dev/wordpress/test` restores the demo state at the end.

## 2. Local site

`docker-compose.yml` (project `enteronce-dev`):

| Service | Image | Role |
|---|---|---|
| `db` | `mariadb:10.11` | database (volume `db_data`) |
| `wordpress` | `wordpress:php8.1-apache` | the site, port 18471; WordPress files in volume `wp_core` |
| `wpcli` | `wordpress:cli-php8.1` (profile `cli`) | WP-CLI, used by `bin/wp` and the scripts |

Throw-away credentials of the local stand-in, valid only inside this Docker setup (the database port is not published): WordPress login `admin` / `enteronce-dev`; database user `wordpress` / `wordpress-dev`, root `root-dev`; the temporary clean site of the zip test `clean` / `clean-dev-only`. These are the only passwords in the source; the package check refuses any other.

Mounted into WordPress: the project folder as `wp-content/plugins/enter-once-sync` (development copy with Composer's autoloader), `dev/wordpress/qtp-standin` as the theme, `dev/wordpress/mu-plugins` as must-use plugins. Credentials are throw-away test values for this local site only. `WP_ENVIRONMENT_TYPE` is `local`, and `ENTERONCE_DEV` is `true` (enables `wp enteronce sync --now=…`; never set it on a real site).

`dev/wordpress/mu-plugins/enteronce-dev.php` (local only, never shipped):

- stores every outgoing e-mail in the option `enteronce_dev_mails` instead of sending it;
- lets the source address `https://fixtures.enteronce.invalid/<file>.ics` read `tests/fixtures/<file>.ics`, so «Jetzt abgleichen» and `bin/wp enteronce sync` work offline. Example: set «Kalender-Adresse (ICS)» to `https://fixtures.enteronce.invalid/union-beispielkalender.ics` and run `bin/wp enteronce sync --now="2026-11-19 00:00"`.

### The stand-in theme (`dev/wordpress/qtp-standin`)

Imitates the association's tool as plan 3.3 describes it:

- post types `events` (title, editor, featured image, revisions), `locations`, `news`;
- taxonomies `topic` (7 terms), `targetgroup` (5 terms), `location_cat` (16 centres), `district`;
- edit screen with «Veranstaltungsdaten» (Datum, Beginn, Ende, Wöchentliches Angebot, Wochentag, abgesagt, Ferienpause), «Serien- und Mehrfachtermine» (generates independent copies on save, like the real box) and the sidebar box «Organisation und Information»;
- programme page `/programm/?location_cat[<id>]=<name>` in the markup of `docs/dev/programme-page-notes.md`, with status bands «abgesagt» and «Ferienpause».

Its storage is deliberately odd (documented in `functions.php`): date `Ymd`, times `Hi`, weekday as German two letters, «abgesagt» as `ja`/`nein`, and two switches («Wöchentliches Angebot», «Ferienpause») that are **not stored at all when off**. There is no room field. The save handler acts only when its own form nonce is posted, as hand-written tools usually do. The plugin's code never mentions any of these names (`WizardTest::testPluginCodeNeverNamesTheStandInFields`).

Seed (`qtp_standin_seed()`): the terms, 16 centres («Quartierzentrum Union» and 15 invented names), Union's 2 dated events and 8 weekly offers of `docs/dev/programme-page-notes.md` (texts invented), and two records of another centre.

## 3. Architecture

```
enter-once-sync.php           bootstrap: PHP check, autoloader, activation hooks, Plugin::boot()
src/WordPress/
  Plugin        wiring; clock() and fetcher() (filters enteronce_clock, enteronce_fetcher); runner()
  Options       the one option enteronce_settings: exactly the keys of plan 6.6, with defaults
  State         per-source state and the stored occurrence list (separate options)
  Clock / SystemClock    "now" is injected everywhere (test clock: tests/WordPress/Support/FixedClock)
  Fetcher / HttpFetcher / FetchError    R-02, R-03 download part (test fetcher: tests/WordPress/Support/StaticFetcher)
  Runner        one run of one source (below)
  EventAdapter  the ONLY class that writes events (A-03): field map, terms, own meta, image
  FieldMap / FieldFormats               learned field names and value formats; encode/decode
  FieldWizard   learning by comparison + verification draft (plan 4.7)
  Adoption      pairing hand-entered weekly offers with calendar series (plan 4.7, A-14)
  TermResolver  term names → IDs, stored in enteronce_term_ids (plan 4.5)
  Images        A-15 lookup; media field «Zentrum (Enter Once)»
  FeedController  REST route enteronce/v1/events (plan 4.8)
  Notifier      digest, error and held-run e-mails (R-32, R-33)
  Scheduler     WordPress cron fallback, stale check (R-30)
  Cli           wp enteronce sync|held|status|digest (server scheduler entry point)
  Admin         «Einstellungen → Enter Once»: Status, Einstellungen, Feldzuordnung, Bestehende Angebote übernehmen
  EditNotice    notice on managed records' edit screen
  Integrations  guarded WP Rocket purge (R-09) and WPML (A-04)
  Secret        masking of the ICS address
```

### One run (`Runner::run($sourceId, $trigger, $releaseHeld)`)

1. Lock: option `enteronce_lock_<id>` (added atomically). It expires after the time budget plus 120 s, so it always outlives a running run. A second run returns `locked`.
2. Fetch the ICS text (`Fetcher`). Any failure → `error`, nothing changes (R-03).
3. Core: `read()` with `now` from the clock and `Window::monthsAhead(now, window_months_ahead)`, `lastRunHadEvents` from the state; `targets()`; `diff()` against `EventAdapter::siteRecords()` (managed records of this source, any status including trash). A held run is released only by «Trotzdem anwenden» with the hash of the list that was shown (`held_hash`); if the list changed meanwhile, nothing is removed and the status page says so. `wp enteronce held` prints the held list with its fingerprint (the same hash), and `wp enteronce sync --source=<id> --release-held=<fingerprint>` releases only while the list still has that fingerprint; without one, or with a stale one, it refuses and removes nothing (`Cli::releaseHeld()`, tested in `CliReleaseTest`).
4. Dry run if the source says so, or the mapping is unverified (core) or incomplete (shell) — R-11, R-31. In a dry run **nothing** is written to any event, not even `_enteronce_missing_runs`.
5. Otherwise every action goes through `EventAdapter::apply()`. **Time budget** (`enteronce_time_budget` filter, default 45 s): after the first change, a change that would start after the budget is skipped; the run ends cleanly, the state shows «%d Änderungen folgen beim nächsten Abgleich», and the next run recomputes and finishes. Changed post IDs → `Integrations::purgeCache()` (R-09).
6. The occurrence list for the feed is stored also in dry run, **except when the run is held** (R-13: the previous list stays). Occurrences missing from the calendar stay in the list for `removal_grace_runs` − 1 further runs (R-05), unless they ended before the window. The state for the status page is always stored.
7. Notifier: changes and notices are queued for the digest (in dry run only notices), including «needs a person» notes (records in the bin, private, pending or scheduled); a held run is mailed at once; the digest is sent if due. Errors are mailed at once, rate-limited per error **category** (`fetch`, `integrity:<reason>`, `internal`), not per wording.

Triggers: `cron` (WordPress scheduler), `cli` (`wp enteronce sync`), `manual` («Jetzt abgleichen», «Trotzdem anwenden»).

### Writing (`EventAdapter`)

- Create (review D2): `wp_insert_post` as **draft** with `_enteronce_managed`, `_enteronce_source`, `_enteronce_key`, an empty hash and `_enteronce_kind` in the same call (`meta_input`); then fields, terms, own meta, image; **publishing is the last step**. A run that stops in between leaves a managed draft, which the next run completes (no second record). Update: `wp_update_post`; for records with `_enteronce_keep_text` title, body and «Organisation und Information» are left alone, only the moved-date note on top of the body is maintained (`MovedDateNote::apply`). Unpublish: status `draft` (R-06; never delete). Keep/hold: only `_enteronce_missing_runs`, and a featured image that appeared in the media library since.
- Values written through the field map: weekly offer → date = first date, start/end time, weekly switch on, weekday, «abgesagt», «Ferienpause», room, info text = calendar location; dated event → date, times, weekly switch off, «abgesagt» (only with `cancel_mode` badge), «Ferienpause» off, room, info text. Two switches that share one stored field are written as one value.
- Terms (review D5): `topic` and `targetgroup` by the IDs of `enteronce_term_ids` (names missing on the site are reported on the status page), set **only when the calendar provides some**; otherwise the record keeps its own. `location_cat`: the source's term is **added**; other centres are never removed.
- Titles, room and location are plain text (core `PlainText`, then `sanitize_text_field`), whoever runs the sync (review D3).
- Statuses a person chose are respected: records that are private, pending or scheduled, or in the bin, are never changed or republished; they appear on the status page and in the digest as «Braucht eine Person». An adopted offer that was not published keeps its status (`_enteronce_keep_status`) unless publishing was chosen at adoption.
- Every record stores the fingerprint of the field map it was written with (`_enteronce_map`). After the map is relearned and verified, the fingerprint differs, the record's hash is ignored, and every record is rewritten once (review D1).
- R-10: every write runs with empty `$_POST`/`$_REQUEST` (restored afterwards) and `$GLOBALS['enteronce_writing'] = true`, so a save handler reading the edit form — including the tool's series generation — finds nothing to act on. Tested with the stand-in's generator and a posted series form.
- Featured image (A-15): set when the record has none or has the one the plugin set before (`_enteronce_image_auto`); an image set by hand is never replaced.

## 4. What is stored where

| Where | Name | Content |
|---|---|---|
| option | `enteronce_settings` | the 28 keys of plan 6.6, nothing else (tested) |
| option | `enteronce_term_ids` | `{topic: {name: id}, targetgroup: {…}}` |
| option | `enteronce_state_<id>` | last_run, last_success, trigger, dry_run, error, last_event_count, summary, counts, notices, actions (compact list of the last plan's changes), held, held_actions, held_hash, release_refused, incomplete, weekly_series (for adoption), window, missing_terms, skipped («needs a person»), last_cli_run |
| option | `enteronce_occurrences_<id>` | `{window, occurrences: [Occurrence::toArray()…]}` — the feed's source (plan 4.4); occurrences kept by the grace rule carry `_missing` |
| option | `enteronce_feed_version_<id>` | changes with every run; part of the feed cache key |
| option | `enteronce_digest_<id>` | queued changes and notices, since, last_sent |
| option | `enteronce_mail_log` | hashes of errors/held lists mailed in the last 24 h (R-32) |
| option | `enteronce_wizard` | wizard step, learning draft ID, snapshot, test draft ID, message |
| option | `enteronce_lock_<id>` | lock expiry |
| transient | `enteronce_feed_<source>` | the whole feed of a source with its data version (`feed_cache_seconds`); one entry per source |
| transient | `enteronce_flash_<user>` | admin messages after a button |
| post meta | `_enteronce_source`, `_enteronce_key`, `_enteronce_hash`, `_enteronce_managed` (= 1), `_enteronce_missing_runs` | plan 4.4 |
| post meta | `_enteronce_kind`, `_enteronce_start`, `_enteronce_end` | for R-07, R-08 (core `SiteRecord::$start/$end`) |
| post meta | `_enteronce_map` | fingerprint of the field map the record was written with |
| post meta | `_enteronce_keep_status` | adopted, unpublished offer: the status to keep |
| post meta | `_enteronce_weekday`, `_enteronce_time_start` | for R-27 draft reuse |
| post meta | `_enteronce_flags` | last written flags (JSON), for the digest's flag changes |
| post meta | `_enteronce_keep_text` | A-14 per record |
| post meta | `_enteronce_image_auto` | the image the plugin set |
| post meta | `_enteronce_backup`, `_enteronce_adopted` | adoption: full copy (title, content, status, all meta, terms) and time |
| attachment meta | `_enteronce_image_source` | «Zentrum (Enter Once)» of an image |
| cron | `enteronce_sync` (every `sync_interval_minutes`), `enteronce_digest` (hourly) | |

The tool's own fields are written only under the names the wizard learned (`field_map`).

**Uninstall** (`uninstall.php`, when the plugin is deleted in «Plugins»): removes every `enteronce_*` option and transient (including the secret calendar addresses) and the cron events, on every site of a multisite. Event records and their `_enteronce_*` meta stay: they are the association's programme; a reinstallation recognises them again.

## 5. The wizard (`FieldWizard`)

State in `enteronce_wizard`; learned entries are saved into `field_map` after every step (still unverified, so runs stay dry).

1. **Start**: the administrator creates a draft titled «Enter Once Lernentwurf» in the tool's edit screen. `begin()` finds the newest such draft and takes a **snapshot**: every post meta key of the draft (first value), except WordPress's own bookkeeping (`_edit_*`, `_wp_*`, `_thumbnail_id`, `_encloseme`, `_pingme`) and `_enteronce_*`.
   **Second baseline**: the administrator saves once more without an edit. Keys that change anyway (other plugins that rewrite their own meta on every save, such as SEO or translation plugins) are recorded in `ignore` and left out of every later comparison.
2. **One field per step**, in this order: date, start, end, weekly switch, weekday, «abgesagt», «Ferienpause», «Organisation und Information», room. The administrator sets a fixed sample value and saves; `compare()` diffs the snapshot with the current meta:
   - a change is a key that appeared, disappeared or changed value; a learned switch that went back to its «off» value is ignored (the «Ferienpause» step asks to switch «abgesagt» off again);
   - **values** (date 23.04.2031, start 07:45, end 09:05, weekday Sunday): every changed key is tried against the candidate formats of `FieldFormats` (`Ymd`, `Y-m-d`, `d.m.Y`, … `U`; `H:i`, `Hi`, `H.i`, `G:i`, minutes, seconds …; ISO number, Sunday-0 number, German/English names, iCalendar codes). The samples are chosen so that exactly one format matches. If several keys hold the value, the first is the main key and the others are written too (`also`);
   - **switches**: exactly one key must have changed; «on» = the new value, «off» = the old value, `null` = not stored. If two switches share one key (one status field), «off» is taken from the first switch;
   - **texts**: the key whose new value contains the marker text; `html` if it contains tags;
   - a companion key `_<key>` whose value starts with `field_` (a field-framework reference) is remembered and written along;
   - optional fields (weekday, info text, room) can be marked «Dieses Feld gibt es nicht» (`['none' => true]`);
   - a key already learned for another value field is refused (only switches may share a key): «Das geänderte Feld … ist bereits als «Beginn» gelernt …» (review D1);
   - errors leave the snapshot in place; «Ausgangszustand neu erfassen» takes a new one; every error says that a field outside post meta needs the tool's developer.
3. **Verification**: `writeTestDraft()` writes «Enter Once Prüfentwurf» through `EventAdapter::write()` (the same code the sync uses) as a weekly offer with known values; the managed meta is removed from it. The page shows the expected values, a self-check (values read back through the learned map), and links to the edit screen and the preview. The table shows expected and read values. «Alles stimmt – Zuordnung bestätigen» is shown, and `confirm()` succeeds, **only when every learned field reads back exactly as written and the test draft still exists** (review D1); it sets `field_map_verified` and puts both drafts in the bin. «Neu lernen» clears the map.

Not covered by the wizard: fields stored outside post meta (post fields, custom tables, options), values the tool computes on save from several inputs, and fields that change only together with others. These would show up in step 3 or need the tool's developer (route 2 of plan 4.10).

## 6. Adoption (`Adoption`)

- Offers: `events` of the centre's `location_cat` term, not managed, whose weekly switch reads «on» through the field map. Weekday from the weekday field, or from the date if the tool has no weekday field.
- Series: `weekly_series` of the last run's state (weekly targets and pending ones), minus keys any managed record of the source already uses. A run is needed first; a dry run is enough.
- Candidates: same weekday and overlapping time (`[start, end)`, an end at or before the start counts as past midnight). Only listed; the administrator chooses or leaves the offer alone.
- The offer's card has only «Vergleich anzeigen»; «Übernehmen» exists only in the comparison view (review D6), which also shows what happens to topics, target groups and centres, and, for an offer that is not published, keeps its status unless «Mit dem nächsten Abgleich veröffentlichen» is ticked.
- `adopt()` checks: offer not managed, series free, weekday/time fit. Then `wp_save_post_revision()` (if the post type keeps revisions) and a full copy in `_enteronce_backup`, then only `_enteronce_*` meta: managed, source, key, kind, empty hash (forces the next run to write), missing 0, `keep_text`, weekday, start time.
- The next run updates the record: time, flags, image, and terms where the calendar provides them; the centre is added, other centres stay; title, body and «Organisation und Information» stay when «Text der Website behalten» is ticked.
- «Übernahme rückgängig machen» (`Adoption::undo()`): restores title, text, status, every field and the terms from `_enteronce_backup` and removes all `_enteronce_*` meta; the record is free again. Differences between calendar and site text appear in the digest as info notices (`text_differs`).

## 7. Feed

`GET /wp-json/enteronce/v1/events?source=<id>&from=YYYY-MM-DD&to=YYYY-MM-DD` — format in `AGENTS.md`. Built by the core's `Feed::build()` from `enteronce_occurrences_<id>`; `from` and `to` must be real dates (`2027-13-45` → 400); `from` defaults to today (clock), `to` to the last day of the stored window; both are clamped to the stored window. Images per A-15 (`{url, alt}` or `null`). Cancelled dates only with `cancel_mode` badge. `Cache-Control: public, max-age=<feed_cache_seconds>`; the whole list of a source is cached once per data version and image settings (one transient per source) and each range is filtered in memory (review D4). CORS: `Access-Control-Allow-Origin: *` when `feed_allowed_origins` contains `*`, otherwise the request's origin if listed; WordPress's default «credentials» header is removed for this route. `public_feed_enabled` off → 404.

## 8. Scheduling and mail

- WordPress cron: `enteronce_sync` every `sync_interval_minutes` (custom schedule `enteronce_interval`, rescheduled when the interval changes); `enteronce_digest` hourly.
- Server scheduler (preferred where the host allows): `*/15 * * * * wp --path=/path/to/wordpress enteronce sync --quiet`. When a CLI run happened within the last two intervals, the WordPress event skips.
- Stale warning on the status page when the last success is older than `stale_after_minutes`.
- Digest per `digest_frequency` (daily / each_run / weekly); errors and held runs at once; the same error or the same held list at most once per 24 h.

## 9. Security (plan 4.9)

- All admin pages and actions require `manage_options`; every action is a POST to `admin-post.php` with its own nonce (`enteronce_<action>`). Tested: an editor gets `wp_die`, a wrong nonce gets `wp_die`.
- Input sanitised in `Admin::sanitize()`; output escaped. The settings form never changes `field_map`.
- ICS address: https only (R-02; also checked with `wp_http_validate_url` and `wp_safe_remote_get` with `reject_unsafe_urls`). WordPress follows no redirect; `HttpFetcher` follows at most 3 and checks every target again (https, public address). A body over 10 MB is refused (review D8); shown in full only on the settings page (administrators), as a password field with «Anzeigen»; masked (`https://host/…`) on the status page, in WP-CLI output, mails and errors; never logged.
- R-12: the core removes person lines before anything else; `PrivacyTest` searches posts, meta, the plugin's options, the feed, mails and the debug log for the invented attendee names of `tests/fixtures/attendees.ics`.
- The feed is read-only and contains only screened data.
- The image field «Zentrum (Enter Once)» can only be changed by administrators.
- `uninstall.php` removes the stored addresses when the plugin is deleted.

## 10. The zip (`bin/build-zip`)

`dev/wordpress/build-zip.php`, run in the `composer:2` image: copies `enter-once-sync.php`, `uninstall.php`, `src/Core`, `src/WordPress`, `languages`, `readme.txt` and `LICENSE` from the plugin root, the German guides `docs/de/*.md` (into `docs/de/` of the plugin), `widget/enteronce-widget.js` and `.css`, and `vendor/sabre/{vobject,xml,uri}`; fails if `readme.txt`, `LICENSE` or the guides are missing, or if the readme's «Stable tag» differs from the plugin version; rewrites every `Sabre\` namespace reference (code and class-name strings) to `EnterOnce\Vendor\Sabre\` (A-13); writes its own PSR-4 autoloader (no Composer at runtime, no dev dependencies); lints every file and checks that the prefixed core reads the sample calendar to 71 occurrences; zips to `dist/enter-once-sync.zip`. `dev/wordpress/test-zip-install` installs and activates the zip on a fresh WordPress (copy of the local core, table prefix `clean_`), checks classes, the prefixed library, the 28 settings keys, the cron event, the feed route, the 71 occurrences, and that `readme.txt`, `LICENSE` (GPL v2 text), the widget files and every guide of `docs/de/` are in the installed plugin, and that no test double ships and `wp enteronce sync --now` is refused there; then removes the copy. The test doubles live in `tests/WordPress/Support/` and never enter the zip.

`readme.txt` (WordPress readme format, German) and `LICENSE` live in the plugin root. `LICENSE` is the GPL version 2 text taken unchanged from the `license.txt` that ships with WordPress (lines 39–377): WordPress's own preamble (copyright of WordPress and b2) and its closing «WRITTEN OFFER» about WordPress's source are left out, because they speak about WordPress, not about this plugin. The readme's author line names the author (filled in on 24 November 2026).

The widget files are served from `wp-content/plugins/enter-once-sync/widget/` as plan 4.8's embed snippet expects.

## 10a. The handover package (`bin/build-package`)

`bin/build-package` runs `bin/build-zip`, then `bin/build-pdf`, then `dev/wordpress/build-package.php` (in the `composer:2` image). It writes `dist/enter-once-uebergabe.zip`:

| Path | Content |
|---|---|
| `LIESMICH.txt` | ten German lines: what each part is; read `anleitungen/Begleitbrief.pdf` first (`.md` in a package built without PDFs) |
| `enter-once-sync.zip` | the plugin, byte for byte `dist/enter-once-sync.zip` |
| `anleitungen/` | the eight guides of `docs/de/`, each as `.md` and as `.pdf` with the same name |
| `abgleichsbericht/` | the drift report as a standalone tool for PHP 8.1+: `bin/enteronce-drift` (copied unchanged), the wrapper `enteronce-drift` (`php enteronce-drift …`; it only requires `bin/enteronce-drift`), `src/Core/`, `src/Drift/`, `vendor/sabre/{vobject,xml,uri}` with a generated autoloader (`vendor/autoload.php`, so the unchanged command finds it), `LICENSE`, and a German `LIESMICH.txt` with the two ways to run it (installed PHP; Docker `php:8.1-cli`, one line for PowerShell and one for macOS/Linux). No tests, fixtures or development files. |
| `quellcode/` | the project without `vendor/`, `node_modules/`, `dist/`, caches, logs, `.env` files, drift state files, and the generated copies of pages of union-basel.ch (`widget/demo/index.html`, `widget/demo/quartierzentrum-union/`, `widget/tests/edge-output/`) |

`dev/wordpress/test-package` (part of `dev/wordpress/test`) unpacks the package into `dist/.package-check/` and checks: the plugin zip is identical to `dist/enter-once-sync.zip`; the eight guides are there and identical; the eight PDFs are there, identical to `dist/pdf/` and made from the current text; the layout and the unchanged drift command; the drift command in `abgleichsbericht/` writes a report in a plain `php:8.1-cli` container with only that folder mounted (a fixture calendar and page are copied in for the test and removed again); `dev/wordpress/package-scan.php` finds no calendar address other than invented test hosts (also inside the nested zip; it recognises Outlook, Google and iCloud calendar addresses and any `.ics` address on a real host), no `.env`/`wp-config.php`/`settings.local.json`, and no password other than the throw-away logins listed in section 2.

**The PDFs.** `bin/build-pdf` runs on the host, not in Docker: `dev/pdf/build-pdf.mjs` turns each guide into HTML with `marked` (pinned in `dev/pdf/package.json`) and prints it to `dist/pdf/<name>.pdf` with a Chromium browser in headless mode (Edge, Chrome or Chromium; `EO_BROWSER` names the executable if none is found). Raw HTML in a guide is printed as text, and the page's content security policy forbids every outside resource, so nothing is fetched. `dist/pdf/quellen.sha256` records the hash of each source text; `build-package.php` refuses a PDF whose text has changed since. Without Node or a browser, `EO_NO_PDF=1 bin/build-package` builds the package with the Markdown guides only, and `EO_NO_PDF=1 dev/wordpress/test-package` checks it.

## 11. Tests (`tests/WordPress/`)

| Class | Covers |
|---|---|
| `SyncTest` | sample and live calendars end to end with the "now" values of plan 8; programme page shows dated events and weekly offers; second run changes nothing; Ferienpause, moved-date note, draft after the last date on the page; deletion after two runs and return; failed downloads change nothing; R-10 |
| `SafetyTest` | dry run writes nothing; unverified/incomplete mapping forces dry run; counters untouched in dry run; held run, mail, «Trotzdem anwenden»; one centre never touches another (also hand-entered records); settings keys |
| `FeedTest` | 71 occurrences of the sample; format, order, defaults, range cut; images A-15; feed off; CORS; cache invalidation; a real HTTP request to the local server |
| `PrivacyTest` | no person data anywhere (R-12); ICS address never in state, mails or report; error mail once a day |
| `WizardTest` | the wizard learns the stand-in through its form handler; refuses ambiguous steps; verification draft; confirm; «Neu lernen»; plugin code never names the stand-in's fields |
| `AdoptionTest` | candidates listed, never paired; adopt with revision and backup; one offer ↔ one series; dry run plans an update; live run keeps text and updates times and terms; without keep-text |
| `AdminTest` | every tab renders; capability and nonce checks; settings sanitising and secret handling; buttons; edit-screen notice |
| `ReviewFixesTest` | the independent review: D1 wizard key conflict, confirm only with a passing self-check, relearned map rewrites; D2 interrupted create, time budget, lock; D3 plain titles; D4 one feed cache entry, strict and clamped dates; D5 centres and hand-set terms kept, undo; D6 status kept, adoption only from the comparison; D7 feed grace and held runs; D8 redirects, internal addresses, size; D9 uninstall; release bound to the shown list; error mails per category; noisy meta in the wizard; image field rights; private/pending/scheduled/trashed records; feed address and cron line; no test doubles in `src/` |
| `CliReleaseTest` | `wp enteronce held` output with fingerprint; `--release-held` refused without fingerprint, with a wrong one, without `--source`, and after the list changed; applied with the current one |
| `OperationTest` | daily and each-run digest, moved date in the digest; stale check; server scheduler precedence; cron events; WPML/WP Rocket guards; targeted purge; mail fallback |

"Now" is always a `FixedClock`; the calendar comes from `StaticFetcher`; a filter makes any request to a non-local host fail the test.

## 12. Not tested against the real tool — check on the first real installation

1. Install on a copy of the real site (theme `qtp`) first, if one exists; otherwise with dry run on (the default).
2. Run the wizard there. Watch for: a field that is not post meta (the wizard says «Kein geändertes Feld …»); a value the tool computes on save; a weekday that the tool derives from the date; several keys for one value (they are learned as `also`, check them); the weekly switch revealing other fields.
3. Open the verification draft in the tool's own edit screen and on the public page, including the list «Wöchentliche Angebote» of `/programm/`: weekday, time, bands «abgesagt»/«Ferienpause», sidebar text.
4. Check that saving a record through the plugin does not trigger «Serien- und Mehrfachtermine» (R-10): after the first live run, no copies may appear.
5. Check the terms: «Begriffe neu zuordnen» must find all topic and target-group names; check that `location_cat` of Union is selected.
6. WPML (G-03): are records created by the plugin visible in every language the site shows? If not, try «In alle Sprachen duplizieren».
7. WP Rocket: after a run, are the changed records and `/programm/` fresh?
8. Two weeks of dry run with the drift report; compare «Geplante Aktionen (Probelauf)» with the site.
9. Adopt the existing weekly offers before switching dry run off; otherwise duplicates appear.
10. Server scheduler: ask the host for the cron line; otherwise WordPress's scheduler depends on visits.
11. Mail delivery from the association's server (the digest and error mails).
12. The Google source (G-06) and the Outlook re-link step («Neu verknüpfen», `docs/de/Outlook-Anleitung.md` Teil C) are not implemented: there is no tool to move records from one calendar's identifiers to another's.
