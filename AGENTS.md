# Rules for coding agents and their operators

This repository holds "Enter once, show everywhere": a WordPress plugin that syncs a neighbourhood centre's public calendar (ICS) to an association website, a public feed with a calendar widget for the centre's own website, and a drift report. `README.md` gives the overview. This file holds the rules that apply to every change, whether a person or an agent makes it.

## Read first

- `docs/dev/IMPLEMENTATION_PLAN.md` is the design. Its rules (`R-nn`), assumptions (`A-nn`), names (section 4.3), settings keys (section 6.6) and test expectations (section 8) are requirements. Section 12 says what was built and where it deviates from the plan.
- If a change contradicts the plan, say so and change the plan in the same commit. If the plan is silent, choose the simplest behaviour consistent with it.
- `docs/de/Testbericht.md` lists what has never been tested. Do not describe something as tested unless you ran the test, and keep that report true when you change behaviour.

## Where things live

| Path | Content | Developer notes |
|---|---|---|
| `src/Core/`, `tests/Core/`, `tests/fixtures/*.ics`, `bin/enteronce-core` | Calendar logic without WordPress | `docs/dev/core-api.md` |
| `enter-once-sync.php`, `src/WordPress/`, `tests/WordPress/`, `languages/`, `uninstall.php`, `readme.txt` | The plugin | `docs/dev/wordpress.md`, `docs/dev/ui-labels.md` |
| `src/Drift/`, `tests/Drift/`, `tests/fixtures/programm/`, `bin/enteronce-drift` | The drift report | `docs/dev/drift.md`, `docs/dev/programme-page-notes.md` |
| `widget/` | The calendar widget, its tests and demo | `widget/README.md` |
| `docs/de/` | The German guides for the people who install, operate and use it | |
| `dev/wordpress/`, `docker-compose.yml`, `bin/build-zip`, `bin/build-package` | Local test site with a stand-in for the association's event tool; builds and checks | `docs/dev/wordpress.md` |
| `dev/pdf/`, `bin/build-pdf` | Renders the German guides to PDF | `docs/dev/wordpress.md`, section 10a |

## Tools

- PHP is not needed on the host. The wrappers run PHP 8.1 in Docker with this folder mounted at `/app`:
  - `bin/php script.php`
  - `bin/composer install`
  - `bin/phpunit` (unit tests of the core and the drift report, after `bin/composer install`)
  - `bin/dk <any command>`; set `EO_IMAGE` to use another image, for example `EO_IMAGE=php:8.3-cli bin/dk php -v`.
- `dev/wordpress/test` runs everything: unit tests, WordPress integration tests against the local site, the builds, a clean install of the zip and the package check. `cd widget && npm ci && npm test` runs the widget tests.
- The local test site is for one user at a time. The integration tests delete its events and the plugin's options, so two runs at once produce false failures.
- The scripts are Bash. On Windows use Git Bash, and quote paths that contain spaces.
- Node 20 or newer for the widget tests and the PDF guides; Edge, Chrome or Chromium for the PDFs (`EO_NO_PDF=1` builds and checks the package without them).

## Hard limits

- **No requests to the real sites or calendars.** Never fetch `qtp-basel.ch`, `union-basel.ch`, or an Outlook or Google calendar address while developing or testing. Tests run offline against fixtures. Network is allowed only to install dependencies (Packagist, npm, Docker images).
- **The drift report does not run against the live programme page before the association has been told what it does** (plan rules R-40 to R-42). The site's own list request (`filter_events`) is not used without the association's agreement.
- **Never commit a calendar address.** A published ICS address lets anyone who knows it read the whole calendar. It belongs in the plugin's settings and nowhere else.
- **No content of union-basel.ch in the repository.** The widget demo uses copies of two of its pages; `.gitignore` keeps them out and says why. The tests that need them are skipped when they are missing.
- **No personal data in new fixtures.** Use invented names and addresses.
- **Git: commit, push or rewrite history only when the repository owner asks.**

## Code conventions

- PHP 8.1 compatible, `declare(strict_types=1)`, PSR-12, PSR-4 with `EnterOnce\` mapped to `src/`.
- `src/Core/` contains no WordPress function and makes no network or file-system call except reading a path it is given. Its input is ICS text; "now" and the window are parameters, never the system clock. `tests/Core/CorePurityTest.php` enforces this.
- The widget stays one script and one stylesheet without runtime dependencies or a build step.
- Code, comments and developer notes are English. Every text a user sees is German in Swiss spelling (ss, never ß), through the text domain `enter-once-sync`. New or changed labels go into `docs/dev/ui-labels.md`, and the German guides are updated with them.
- Licence: GPL-2.0-or-later.

## Feed format (shared contract between the plugin and the widget)

`GET <site>/wp-json/enteronce/v1/events?source=<id>&from=YYYY-MM-DD&to=YYYY-MM-DD` returns a JSON array. Each element:

```json
{
  "id": "union:040000008200E000…:2027-01-04",
  "title": "Offener Deutschtreff",
  "start": "2027-01-07T15:00:00+01:00",
  "end": "2027-01-07T17:00:00+01:00",
  "all_day": false,
  "room": "Quartierraum",
  "location": "Quartierraum, Quartierzentrum Union, Klybeckstrasse 95, 4057 Basel",
  "description_html": "<p>…</p>",
  "categories": ["Bildung", "Offener Treffpunkt"],
  "tags": ["Deutschlernende", "Erwachsene"],
  "image": { "url": "https://…/Offener_Deutschtreff.jpg", "alt": "Offener Deutschtreff" },
  "cancelled": false,
  "series_id": "union:040000008200E000…"
}
```

`image` may be `null`. `series_id` is `null` for single events. The list is sorted by `start`. Changing this format means changing the plugin, the widget and their tests together.

## Before you call a change done

1. `dev/wordpress/test` passes, and `npm test` in `widget/` if the widget or the feed changed.
2. The German guides, `docs/dev/` and the plan say what the code now does.
3. After a change to a guide, `bin/build-package` has been run, so that the PDFs and the handover package match the text.
4. Your report says what changed, which tests you ran and with what result, what is not verified, and where you deviated from the plan.
