# Calendar widget for union-basel.ch

Part of `enter-once-sync` (plan v8, phase 4). A plain script and a stylesheet that read the plugin's public event feed and replace the two Elfsight event widgets (calendar page and «Highlights» on the home page) on union-basel.ch. The cookie-banner widget is not touched.

- No runtime dependencies, no build step, no framework. `enteronce-widget.js` is one file (about 600 lines, ES2017, readable top to bottom); `enteronce-widget.css` is one file.
- Dev dependencies (tests only): `jsdom`, `axe-core`. Node 20 or newer.
- Licence: GPL-2.0-or-later, like the plugin.
- Nobody is paid to maintain this after handover. Rule of thumb for changes: keep it one file, keep it dependency-free, keep the tests green.

## Embed

On the calendar page (replaces the Elfsight block «Union Programm»):

```html
<div data-enteronce="calendar" data-source="union"></div>
<script src="https://www.qtp-basel.ch/wp-content/plugins/enter-once-sync/widget/enteronce-widget.js" defer></script>
```

On the home page (replaces «Highlights»):

```html
<div data-enteronce="highlights" data-source="union" data-tag="Highlight" data-limit="6"></div>
<script src="https://www.qtp-basel.ch/wp-content/plugins/enter-once-sync/widget/enteronce-widget.js" defer></script>
```

The script loads `enteronce-widget.css` from the same folder by itself (a `<link>` is added unless one for `enteronce-widget` is already on the page), so the snippets above are all that is needed. One script tag handles every `data-enteronce` element on the page.

The feed address is derived from the script's own address: same host, `/wp-json/enteronce/v1/events`. If WordPress lives in a sub-folder (`…/verband/wp-content/plugins/…`) the part before `/wp-content/` is kept. The widget makes one request per distinct feed address, however many widgets share it.

## Attributes

All options are HTML attributes on the `<div>`. Nothing is configured in the script.

| Attribute | Applies to | Default | Meaning |
|---|---|---|---|
| `data-enteronce` | both | – | `calendar` or `highlights`. Anything else is ignored. |
| `data-source` | both | – | Sent to the feed as `?source=`. Omit it to let the feed use its default. |
| `data-view` | both | `list` (calendar), `grid` (highlights) | Card layout: `list` (image left, one card per row) or `grid` (image on top, cards side by side). |
| `data-limit` | both | calendar: all; highlights: `6` | Maximum number of cards shown (after filtering, in the calendar). |
| `data-tag` | highlights | `Highlight` | Show only events carrying this tag. Case and accents do not matter. |
| `data-fallback` | both | `https://www.qtp-basel.ch/programm/` | Where the link in the error message goes. Only `http(s)` addresses are accepted. |
| `data-heading-level` | both | `3` | Level of the month headings (calendar) or event titles (highlights). Event titles in the calendar are one level lower. Set it so the page's outline has no gaps: the Union pages have an `h2` above the widget, so the default `3` fits. |
| `data-feed` | both | derived from the script | Full feed address. For testing (see the demo); in production leave it out. |
| `data-now` | highlights | the browser clock | ISO date-time used as «now» when choosing the next events. For tests only. |

The feed format is fixed in `../AGENTS.md`. The widget depends on nothing else. Entries that are not objects, have no title or have an unreadable `start` are skipped; if the list is not empty but nothing in it is usable, that counts as a broken feed.

## What the widget shows

**Calendar.** Search field, date range («Von» / «Bis»), «Veranstaltungsart» (categories found in the feed), «Veranstaltungsort» (rooms found in the feed) and a reset button. Under them a line with the number of results (this line is a polite live region). Then the events, grouped under a heading per month, as cards: image or neutral placeholder, date badge, categories, title, time, room, tags and a «Mehr Informationen» disclosure with the description and the full address. The list is sorted by start time. Dates and times are formatted for `de-CH` in Europe/Zurich, whatever the visitor's time zone.

**Highlights.** The next events (not yet over) with the tag, at most `data-limit`, as cards with the full date shown. No filters.

**Cancelled events** (`cancelled: true`) keep their place, get a dashed border, the title struck through and the word «abgesagt» in front of it. This also applies in the highlights view: a cancelled highlight still occupies a slot, because hiding it would be wrong for people who planned to come.

**Multi-day and all-day events.** An all-day event shows «ganztägig». An event that ends on a later day than it starts shows both ends («Sa., 13. Feb., 09:00 Uhr bis So., 14. Feb., 17:00 Uhr») and appears in every day of a date-range filter that it overlaps. The last second of an event belongs to its last day, so an all-day entry whose `end` is the exclusive next midnight stays on one day.

**Missing data.** No image: a neutral placeholder (a picture that fails to load turns into the placeholder as well). No category, tags, room or description: those parts are left out. An empty feed: «Zurzeit sind keine Veranstaltungen geplant.»

**Feed not reachable, HTTP error, no JSON, wrong shape, no answer within 10 seconds:** a short message («Die Veranstaltungen können im Moment nicht angezeigt werden.») and the link «Zum aktuellen Programm» to `data-fallback`.

**Alt texts.** If the feed's `image.alt` only repeats the title, the image gets `alt=""` (the screen reader hears the title next anyway). A different alt text is kept.

## Untrusted data

Everything from the feed is treated as untrusted, although the plugin sanitises it too.

- Titles, rooms, categories and tags are written with `textContent`.
- `description_html` is parsed with `DOMParser` (inert: no script runs, no image loads), then rebuilt into new elements from an allow-list: `p br strong b em i ul ol li blockquote a`. Headings become bold paragraphs. Scripts, styles, frames, forms, images, SVG and the like are dropped with their content; unknown elements are unwrapped (their text stays). No attribute is copied except `href` on links, and only `http`, `https`, `mailto` and `tel` addresses count; a link without a safe address loses the link and keeps its words. Links get `rel="noopener noreferrer"`.
- Image addresses must be `http(s)`.
- The widget never evaluates anything from the feed, and creates no inline event handler.

## CSS variables

Defined on `.eo-widget` (the `<div>` itself). Defaults come from Union's stylesheet `union-8d3333.webflow.shared.*.css`. To change one, override it in Webflow's custom code, for example `.eo-widget { --eo-radius: 8px; }`.

| Variable | Default | Taken from / meaning |
|---|---|---|
| `--eo-text` | `#312a6f` | Union's `--midnight-blue`: all text |
| `--eo-bg` | `#ffffff` | page background |
| `--eo-link` | `#b53700` | darkened `--molten-orange`; `#e94e0e` itself only reaches 3.8:1 on white and is not allowed for text |
| `--eo-accent` | `#e94e0e` | `--molten-orange`: decoration only (month heading underline) |
| `--eo-border` | `#01b3c4` | `--pacific-blue`: card and button borders (as Union's `.button-cta`) |
| `--eo-control-border` | `#312a6f` | form fields, label outlines, «abgesagt» label; must stay 3:1 against the page |
| `--eo-focus` | `#312a6f` | focus ring; must stay 3:1 against the page |
| `--eo-badge-bg`, `--eo-badge-text` | `#312a6f`, `#ffffff` | date badge |
| `--eo-category-bg` | `#d6fcd5` | `--tea-green`: category labels |
| `--eo-tag-bg` | `#c9f8fb` | `--frozen-water`: tag labels |
| `--eo-cancel-bg` | `#ffd0c6` | `--almond-silk`: «abgesagt» label |
| `--eo-placeholder-bg` | `#eceaf6` | image placeholder |
| `--eo-font-body` | `"Rethink Sans", Arial, sans-serif` | Union's body font (the page loads it; the widget loads nothing) |
| `--eo-font-heading` | `"Haffer XH", "Rethink Sans", Arial, sans-serif` | Union's heading font (loaded by the page) |
| `--eo-radius` | `20px` | card corners |
| `--eo-gap` | `1rem` | space between cards and filters |
| `--eo-max-width` | `1100px` | widest the widget gets |
| `--eo-image-width` | `240px` | image column, list view |
| `--eo-grid-min` | `260px` | narrowest card, grid view |

`npm test` checks the contrast of the default colour pairs (`tests/a11y.test.js`); if you change colours, run it again.

The stylesheet resets what Union's Webflow stylesheet sets on bare elements (`img` has `margin-top: 20px` there, `p`, `li` and `a` carry colours and widths). The scope is `.eo-widget`.

## Run the demo

```powershell
npm install
npm run demo          # http://127.0.0.1:8080/quartierzentrum-union/veranstaltungskalender/
npm run demo:dead     # same, but the feed answers 503: shows the fallback message
```

The demo shows the widget inside two pages of union-basel.ch. Those pages and the static copy of the site they are made from (`../../webflow-static`) belong to Union and are **neither in the git repository nor in the handover package**. Without them the feed and the widget files are still served, and the tests that need the copy are skipped (`npm test` reports them as skipped).

`demo/serve.mjs` is a small Node static server. It serves

- the two copied pages `demo/index.html` (home, with «Highlights») and `demo/quartierzentrum-union/veranstaltungskalender/index.html`;
- the widget under `/wp-content/plugins/enter-once-sync/widget/…`, the same path the real plugin uses, so the derivation of the feed address is exercised;
- a feed at `/wp-json/enteronce/v1/events` built from `tests/fixtures/feed-sample.json`, honouring `from`, `to` and `source`, with `Access-Control-Allow-Origin: *`; images in it are generated pictures served by the server itself;
- everything else read-only from `../../webflow-static` (the other pages, fonts, CSS). That folder is never modified.

Flags: `--port=N`, `--host=…`, `--dead` (503), `--dead=garbage` (200, not JSON), `--dead=hang` (never answers, the widget gives up after 10 s), `--no-shift`, `--today=YYYY-MM-DD`, `--fixture=path.json` (any feed in the documented format, for example one generated by the core).

By default the server moves the fixture by whole weeks so that its first Monday becomes the Monday of the current week (wall-clock times are kept, offsets are recomputed for summer time). The demo therefore always shows upcoming events. `--no-shift` serves the dates as stored (January to March 2027).

The two demo pages are made from the static copy by `node demo/build-pages.mjs` (run it again if `webflow-static` changes). It replaces the Elfsight blocks `2a073e75-…` and `cda7408f-…` with the snippets of plan section 4.8 and leaves the cookie banner `92219bf9-…` alone. The pages still contain Union's other external references (Google font loader, Elfsight cookie banner); they only matter if you open the demo on a machine with internet.

## Run the tests

```powershell
npm install
npm test
```

`node --test` runs everything in `tests/` (about 90 tests, 10 seconds, offline):

| File | What |
|---|---|
| `tests/widget.test.js` | rendering and month grouping, each filter, search, live-region text, highlights, cancelled events, missing fields, fallbacks (network error, 503, 404, malformed JSON, wrong shape, nonsense list, timeout), no script execution from `description_html`, where feed and stylesheet are found |
| `tests/a11y.test.js` | axe-core (WCAG 2.0/2.1 A and AA) with zero violations on calendar (loaded, grid, details open, filtered, bad range, no result), highlights, both together, fallback, empty feed; label/heading/list structure; colour contrast of the default variables; focus and target-size rules in the CSS |
| `tests/server.test.js` | the demo server (feed filters, shifting, dead modes, path safety) and the two demo pages (Elfsight gone, cookie banner kept, otherwise identical to the static copy) |

jsdom does not lay out pages, so axe's `color-contrast` rule cannot run there; that is why the contrast of the default colours is computed separately and why the Edge check below exists.

`tests/fixtures/feed-sample.json` is an invented feed in the exact format of `AGENTS.md`: 62 entries, 4 January to 31 March 2027, three weekly series plus 25 single events. It has days with several entries, two cancelled entries (one of them a Highlight), ten Highlights, entries without image (3), without category (1), with `room` as `null` and as `""`, two all-day entries, a two-day course, a very long title, the clock change on 28 March, and alt texts that repeat the title, differ from it or are empty. To test against a feed made by the core, copy it over the fixture or pass `--fixture`; the tests that name concrete events (for example «Offener Deutschtreff») would then need adjusting, the rest only depends on the format.

## Real-browser check (Edge)

```powershell
npm run check:edge
```

`tests/edge-check.mjs` starts the demo server and Microsoft Edge in headless mode (all outside hosts blocked), drives it over the DevTools protocol, and writes screenshots and `report.json` to `tests/edge-output/`. It runs axe-core including colour contrast, a keyboard-only walk (Tab order, typing, arrow keys on the select, Enter and Space, reset, no keyboard trap), checks for a visible focus outline at every stop, the WCAG text-spacing overrides, reflow at 320 px, and the fallback. It is not part of `npm test` because it needs Edge (`EDGE=path\to\msedge.exe` if it is not in the usual place). Last run: 22 of 22 passed.

## Accessibility: what was and was not verified

Target: WCAG 2.1 level AA. Plan section 8.5 asks for three results: automated checker, keyboard walkthrough, screen-reader walkthrough.

**Verified (automated, repeatable)**

- axe-core 4.14, tags `wcag2a wcag2aa wcag21a wcag21aa`: no violations on the states listed above, in jsdom (`npm test`) and in real Edge including colour contrast on calendar (1280 px and 375 px), home page highlights and fallback (`npm run check:edge`). In Edge, axe also reports findings on the copied Union page *outside* the widget (`link-name` on the logo link, and colour contrast of text elsewhere on the page). Those are Union's page, not the widget; they are listed in `tests/edge-output/report.json`.
- Computed contrast of every default colour pair (text 12:1; link 6.0:1; badge, labels, placeholder above 4.5:1; form borders and focus ring above 3:1).
- Structure in markup tests: every control has a visible `<label>`, the date fields sit in a `<fieldset>` with a legend, filters are a labelled `role="search"` form, headings never skip a level, events are list items holding `<article>`s named by their title, no `tabindex`, no custom widgets (only native `input`, `select`, `button`, `details/summary`, `a`).
- Result count in a polite, atomic `role="status"` region; it is filled before it is attached, so it is not read on page load; typing announces after a 400 ms pause; a pending announcement cannot overwrite a newer one (this was a real bug found in the Edge run and fixed).
- Information is never carried by colour alone: cancelled = word + strikethrough + dashed border; labels are text.
- Keyboard in Edge, with synthetic key events over the DevTools protocol: Tab order is search → Von → Bis → Veranstaltungsart → Veranstaltungsort → reset → each card's «Mehr Informationen»; a focus outline is visible at each stop; typing, arrow keys on the select, Space/Enter on reset and on `summary`, Tab out and Shift+Tab back all work.
- Reflow: no horizontal scroll at 320 px; nothing is cut with the WCAG 1.4.12 text-spacing overrides applied.

**Not verified**

- **No screen-reader run.** Nothing here was heard through NVDA, JAWS, VoiceOver or TalkBack. The reading order (title, date, place, labels), the live-region announcements and the date-field behaviour are designed to be right and checked in markup, but unconfirmed. Steps are in `ACCESSIBILITY-CHECK.md`.
- **No keyboard run on real hardware or in other browsers.** The keyboard checks used synthetic events in Chromium (Edge). Firefox and Safari were not run. The native date field and the select behave differently per browser (in Chromium each date field takes several Tab presses).
- Not run on the real union-basel.ch under Webflow, nor against the real feed; only against the static copy and the fixture. Webflow's published CSS and the live site's scripts may interact in ways the copy does not show.
- Browser zoom to 200 % and Windows high-contrast mode (`forced-colors`) were not tried; the CSS has a `forced-colors` rule that keeps borders, untested. There is no animation, so reduced-motion needed nothing.
- Axe finds only part of the WCAG problems; it cannot judge whether labels are meaningful, whether the German is clear, or whether alt texts help.
- Easy language («Leichte Sprache») is not part of this widget.
- The screenshot of today's Elfsight widget (last page of the staff guide PDF) could not be rendered on this machine (no PDF renderer); the layout was rebuilt from the description in plan section 3.4. A visual comparison with the real Elfsight widget is still to do.

## Notes for the next developer

- Dates: a day is the day in Europe/Zurich of `start` (and of the last second before `end`). Month grouping, the date filter and highlights all use it.
- The description is shown in a `<details>` instead of a pop-up: native, keyboard-friendly, no focus management to get wrong.
- The feed has no event page address, so cards are not links.
- Search matches title, room, address, categories, tags and the description's text, all words must match; case and accents are ignored.
- The month headings and event titles are real headings; the filters are not hidden anywhere.
- Rollback: paste the old Elfsight embed code back (it is kept in the handover notes, plan section 4.8). The widget leaves no other trace on the page.
- The script and stylesheet are served from the plugin folder; if the plugin is deactivated, the widgets show the fallback message.
