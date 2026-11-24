# Rules for everyone working in this folder

This is the code for "Enter once, show everywhere": a WordPress plugin that syncs a neighbourhood centre's public calendar to an association website and feeds a calendar widget. Several sessions work here at the same time.

## The plan is binding

Read `../IMPLEMENTATION_PLAN.md` before you start (a copy travels with the source as `docs/dev/IMPLEMENTATION_PLAN.md`; if only that copy is present, it is the plan). Its rules (`R-nn`), assumptions (`A-nn`), names (section 4.3), settings keys (section 6.6) and test expectations (section 8) are requirements. If the plan and your task brief disagree, the plan wins; say so in your report. If the plan is wrong or silent, choose the simplest behaviour consistent with it and say so in your report. Do not edit the plan.

## Who owns what

Write only inside your own area. Read anything.

| Area | Owner |
|---|---|
| `src/Core/`, `tests/Core/`, `tests/fixtures/*.ics`, `bin/enteronce-core`, `docs/dev/core-api.md` | core session (finished and checked; treat as read-only and report bugs instead of patching) |
| `widget/` (everything, including its tests, demo and `package.json`) | widget session (finished and checked; read-only) |
| `docs/de/` | documentation sessions |
| `enter-once-sync.php`, `src/WordPress/`, `tests/WordPress/`, `composer.json`, `composer.lock`, `docker-compose.yml`, `dev/wordpress/`, `bin/build-zip`, `bin/wp`, `dist/`, `languages/`, `docs/dev/ui-labels.md`, `docs/dev/wordpress.md` | WordPress session |
| `src/Drift/`, `tests/Drift/`, `tests/fixtures/programm/`, `bin/enteronce-drift`, `docs/dev/drift.md` | drift-report session. It must not edit `composer.json`; PSR-4 already maps `EnterOnce\Drift` to `src/Drift/`. |
| `AGENTS.md`, `phpunit.xml.dist`, `docs/dev/programme-page-notes.md`, `bin/dk`, `bin/php`, `bin/composer`, `bin/phpunit` | the coordinating session; do not change |

If you need a change outside your area, do not make it. Describe it in your final report.

## Tools

- The host has no PHP. Use the wrappers, which run PHP 8.1 in Docker with this folder mounted at `/app`:
  - `bin/php script.php`
  - `bin/composer install`
  - `bin/phpunit` (after `bin/composer install`)
  - `bin/dk <any command>`; set `EO_IMAGE` to use another image, for example `EO_IMAGE=php:8.3-cli bin/dk php -v`.
- The shell is Git Bash on Windows. Paths contain a space (`Quartierzentrum Union`): always quote them.
- Node 24 is installed on the host for JavaScript work.

## Hard limits

- **Git: commit only when the project owner asks.** The folder is a git repository since 24 November 2026 and is meant for a public GitHub repository. Do not commit, push, rewrite history or add a remote on your own. Never commit a calendar address (it is a secret), a file from the parent folder, or the copied pages of union-basel.ch (`.gitignore` lists them and says why).
- **No requests to the real sites or calendars.** Never fetch `qtp-basel.ch`, `union-basel.ch`, an Outlook or a Google calendar address. Tests must run offline. Network is allowed only to install dependencies (Packagist, npm, Docker images).
- **Do not run `../webflow-static/_mirror.py`.** Do not modify anything in the parent folder.
- **No personal data in new fixtures.** Use invented names and addresses. The two provided calendar files may be copied unchanged.
- **Do not start other sessions.**

## Code conventions

- PHP 8.1 compatible, `declare(strict_types=1)`, PSR-12, PSR-4 with `EnterOnce\` mapped to `src/`.
- `src/Core/` contains no WordPress function and makes no network or file-system call except reading a path it is given. Its input is ICS text; "now" and the window are parameters, never the system clock.
- Code, comments and developer docs in English. Every text a user sees is German, in Swiss spelling (ss, never ß), through the text domain `enter-once-sync`.
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

`image` may be `null`. `series_id` is `null` for single events. The list is sorted by `start`.

## Your final report

Write it in Turkish, because the project owner reads Turkish. Keep it short and factual:

1. What you built, with the main file paths.
2. Which tests you ran and their result, with the command.
3. What is not done or not verified.
4. Where you deviated from the plan or had to interpret it, and what you chose.
5. Changes you need outside your area.
