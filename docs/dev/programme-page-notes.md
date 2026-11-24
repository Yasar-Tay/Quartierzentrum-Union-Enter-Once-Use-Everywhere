# The association's programme page: what is known about its markup

For the drift report (`src/Drift/`). Written by the coordinating session on 24 November 2026.

**Status of this information.** No copy of the real page is stored in this project, and none may be fetched (see `AGENTS.md` and plan rules R-40 to R-42). What follows is reconstructed from two sources seen on 23 November 2026: the site's own public list script (`/wp-content/themes/qtp/js/filters.js`), which builds list items in the browser, and one response of the list request. The page that the server renders for a plain page request very probably uses the same markup, but this was not compared. The parser must therefore be tolerant, and when it cannot recognise the structure it must say so loudly ("page structure not recognised") instead of reporting an empty site.

## Address

`https://www.qtp-basel.ch/programm/?location_cat[52]=Quartierzentrum%20Union`

`52` is the `location_cat` term of Quartierzentrum Union. The page is rendered on the server. A plain request returns the centre's dated events and the first 8 weekly offers; more weekly offers load only through the page's own list request, which this project must not use without the association's agreement.

## Containers

- `main#main`, inside it `form.filter-form` with `data-perpage="8"`.
- Dated events: a block with class `events-simple-programme`, items appended into `.event-listing`.
- Weekly offers: a block with class `events-listing-weekly`, items inside `.weekly-section`. Its heading reads «Wöchentliche Angebote».
- "Load more" buttons: `.qtp-events-load-more` with `data-events="normal"` or `data-events="weekly"`. Their presence means the list is longer than what the page shows.

## One dated event

```html
<div class="col-xs-12 col-sm-6">
  <div class="qtp-article-preview">
    <a href="https://www.qtp-basel.ch/events/atelier-cuisine-globale/"><img …></a>
    <div class="qtp-article-preview-content">
      <div class="qtp-article-meta">
        <span class="qtp-article-date">So., 29.11.2026</span><span class="qtp-article-date">09:00 - 14:00</span>
      </div>
      <a href="https://www.qtp-basel.ch/events/atelier-cuisine-globale/">
        <h3>Atelier Cuisine Globale</h3>
        <p>Quartierzentrum Union </p>
      </a>
    </div>
    <a href="https://www.qtp-basel.ch/events/atelier-cuisine-globale/" class="qtp-read-more">mehr lesen</a>
  </div>
</div>
```

- Day format: `So., 29.11.2026` (German short weekday, day.month.year).
- Time format: `09:00 - 14:00`. The event's own page writes `09:00 - 14:00 Uhr`.
- The image link is missing when the event has no image.

## One weekly offer

```html
<div class="qtp-article-preview">
  <div class="qtp-article-preview-content">
    <a href="https://www.qtp-basel.ch/events/deutsch-fuer-den-alltag-2/">
      <h3>Deutsch im Alltag A</h3>
      <p>Quartierzentrum Union </p>
    </a>
    <div class="qtp-article-meta">
      <span class="qtp-article-date">Montag</span> <span class="qtp-article-date">09:15 - 11:00 Uhr</span>
    </div>
  </div>
  <a href="https://www.qtp-basel.ch/events/deutsch-fuer-den-alltag-2/" class="qtp-read-more">mehr lesen</a>
</div>
```

- Weekday as a full German word; time with «Uhr».
- Titles may contain HTML entities (`Jugendschach &amp; Schach- und Denkspiele`).

## Status band

A cancelled record, or one on holiday break, carries an extra first child inside `.qtp-article-preview`:

```html
<div class="qtp-article-cancelled"><div class="qtp-article-cancelled-wrap"><div class="qtp-article-cancelled-overlay"></div><span>abgesagt</span></div></div>
<div class="qtp-article-cancelled qtp-vacation"><div class="qtp-article-cancelled-wrap"><div class="qtp-article-cancelled-overlay"></div><span>Ferienpause</span></div></div>
```

Weekly offers add the class `weekly` to that element.

## What the site showed for Union on 23 November 2026

Dated events (all of them):

| Title | Day | Time | Slug |
|---|---|---|---|
| Atelier Cuisine Globale | So., 29.11.2026 | 09:00 - 14:00 | `atelier-cuisine-globale` |
| Treffpunkt für ältere Quartierbewohner:innen | Mi., 16.12.2026 | 14:00 - 16:00 | `treffpunkt-fuer-aeltere-quartierbewohnerinnen-5` |

Weekly offers (the first 8; a count of 18 in total was reported once and not confirmed):

| Title | Weekday | Time | Slug |
|---|---|---|---|
| Deutsch im Alltag A | Montag | 09:15 - 11:00 Uhr | `deutsch-fuer-den-alltag-2` |
| Café Bâlance | Montag | 14:15 - 15:15 Uhr | `cafe-balance-8` |
| Kinderpiazza | Montag | 14:00 - 17:00 Uhr | `kinderpiazza-17` |
| Jugendschach & Schach- und Denkspiele | Montag | 18:00 - 00:00 Uhr | `jugendschach-schach-und-denkspiele-33` |
| Deutsch im Alltag B | Montag | 16:00 - 17:45 Uhr | `deutsch-fuer-den-alltag` |
| Yoga am Montag (Start 09:00 Uhr) | Montag | 09:00 - 10:00 Uhr | `yoga-am-montag-start-0900-uhr-5` |
| Kinderpiazza | Montag | 14:00 - 17:00 Uhr | `kinderpiazza-saisonbeginn` |
| Wir reden Deutsch | Dienstag | 09:30 - 11:00 Uhr | `spielend-deutsch-lernen-6` |

Note the two «Kinderpiazza» offers with the same weekday and time, the titles that differ from the calendar («Deutsch im Alltag A», «Café Bâlance»), and the one offer that covers two calendar series («Jugendschach & Schach- und Denkspiele»). These are the hard cases for matching.

## Fixtures

Build the test fixtures in `tests/fixtures/programm/` from the markup and data above. Label them as reconstructed, not recorded. Add variants that the real page will sooner or later produce: no dated events at all, a status band, a missing image, extra whitespace and attributes, and a page whose structure has changed.
