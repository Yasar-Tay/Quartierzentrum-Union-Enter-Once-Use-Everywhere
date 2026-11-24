import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { openPage, sampleFeed, calendarHtml, highlightsHtml, change, delay, FEED_URL } from './helpers.js';

const MONTH_OF = (iso) => new Intl.DateTimeFormat('de-CH', { timeZone: 'Europe/Zurich', month: 'long', year: 'numeric' }).format(new Date(iso));
const cards = (document) => [...document.querySelectorAll('article.eo-card')];
const titleOf = (card) => card.querySelector('.eo-title').textContent;
const status = (document) => document.querySelector('.eo-status').textContent;

function event(overrides = {}) {
  return {
    id: 'union:T:2027-02-02', title: 'Testanlass', start: '2027-02-02T10:00:00+01:00', end: '2027-02-02T11:00:00+01:00',
    all_day: false, room: 'Quartierraum', location: 'Quartierraum', description_html: '', categories: [], tags: [],
    image: null, cancelled: false, series_id: null, ...overrides
  };
}

describe('calendar: rendering and month grouping', () => {
  test('renders one card per event, grouped under month headings in date order', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    assert.equal(cards(document).length, sampleFeed.length);
    const headings = [...document.querySelectorAll('.eo-month-title')].map((h) => h.textContent);
    assert.deepEqual(headings, ['Januar 2027', 'Februar 2027', 'März 2027']);
    for (const section of document.querySelectorAll('section.eo-month')) {
      const month = section.querySelector('.eo-month-title').textContent;
      const expected = sampleFeed.filter((e) => MONTH_OF(e.start) === month).length;
      assert.equal(section.querySelectorAll('article').length, expected, month);
    }
    const titles = cards(document).map(titleOf);
    assert.equal(titles[0], 'Yoga im Union');
  });

  test('a card shows image, date badge, categories, title, tags, time and room', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const card = cards(document).find((c) => titleOf(c) === 'Offener Deutschtreff');
    assert.ok(card.querySelector('.eo-media img[src^="https://media.example.org/"]'));
    assert.equal(card.querySelector('.eo-badge').getAttribute('aria-hidden'), 'true');
    assert.match(card.querySelector('.eo-badge').textContent, /7/);
    assert.deepEqual([...card.querySelectorAll('.eo-cats li')].map((l) => l.textContent), ['Bildung', 'Offener Treffpunkt']);
    assert.deepEqual([...card.querySelectorAll('.eo-tags li')].map((l) => l.textContent), ['Deutschlernende', 'Erwachsene']);
    assert.match(card.querySelector('.eo-when').textContent, /Donnerstag, 7\. Januar 2027/);
    assert.equal(card.querySelector('.eo-when time').textContent, '15:00–17:00 Uhr');
    assert.equal(card.querySelector('.eo-when time').getAttribute('datetime'), '2027-01-07T15:00:00+01:00');
    assert.match(card.querySelector('.eo-room').textContent, /Quartierraum/);
  });

  test('several events on one day all appear, in time order', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const day = cards(document).filter((c) => c.querySelector('.eo-when').textContent.includes('Mittwoch, 27. Januar 2027'));
    assert.deepEqual(day.map(titleOf), ['Kinderpiazza', 'Sprechstunde Sozialberatung', 'Konzert: Trio Rheinwind']);
  });

  test('dates and months are those of Europe/Zurich, whatever the browser time zone', async () => {
    const feed = [
      event({ id: 'a', title: 'Spät am Monatsende', start: '2027-01-31T23:30:00+00:00', end: '2027-02-01T00:30:00+00:00' }),
      event({ id: 'b', title: 'Sommerzeit', start: '2027-03-31T22:30:00Z', end: '2027-03-31T23:30:00Z' })
    ];
    const { document } = await openPage({ html: calendarHtml(), feed });
    assert.deepEqual([...document.querySelectorAll('.eo-month-title')].map((h) => h.textContent), ['Februar 2027', 'April 2027']);
    assert.equal(cards(document)[0].querySelector('time').textContent, '00:30–01:30 Uhr');
    assert.equal(cards(document)[1].querySelector('time').textContent, '00:30–01:30 Uhr');
  });

  test('an unsorted feed is sorted by start', async () => {
    const feed = [event({ id: '2', title: 'Zweiter', start: '2027-02-03T10:00:00+01:00', end: '2027-02-03T11:00:00+01:00' }), event({ id: '1', title: 'Erster' })];
    const { document } = await openPage({ html: calendarHtml(), feed });
    assert.deepEqual(cards(document).map(titleOf), ['Erster', 'Zweiter']);
  });

  test('all-day and multi-day events are described in words', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const allDay = cards(document).find((c) => titleOf(c) === 'Tag der offenen Tür');
    assert.match(allDay.querySelector('.eo-when').textContent, /ganztägig/);
    const multi = cards(document).find((c) => titleOf(c) === 'Wochenendkurs Siebdruck');
    assert.match(multi.querySelector('.eo-when').textContent, /Sa\., 13\. Feb\., 09:00 Uhr bis So\., 14\. Feb\., 17:00 Uhr/);
  });

  test('the long title is shown in full', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const long = sampleFeed.find((e) => e.title.length > 150).title;
    assert.ok(cards(document).some((c) => titleOf(c) === long));
  });

  test('data-heading-level moves all headings; data-view and data-limit are honoured', async () => {
    const { document } = await openPage({ html: calendarHtml('data-heading-level="2" data-view="grid" data-limit="5"') });
    assert.ok(document.querySelector('h2.eo-month-title'));
    assert.ok(document.querySelector('h3.eo-title'));
    assert.ok(document.querySelector('.eo-calendar.eo-view-grid'));
    assert.equal(cards(document).length, 5);
  });

  test('the description opens in a details element, with the full address', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const card = cards(document).find((c) => titleOf(c) === 'Repair Café');
    const details = card.querySelector('details.eo-more');
    assert.equal(details.querySelector('summary').textContent, 'Mehr Informationen');
    assert.match(details.textContent, /Defekte Geräte/);
    assert.match(details.textContent, /Klybeckstrasse 95, 4057 Basel/);
    assert.equal(details.querySelector('a').getAttribute('href'), 'mailto:repair@example.org');
  });
});

describe('calendar: filters, search and the live region', () => {
  test('category filter lists the categories of the feed and narrows the list', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    const select = document.querySelector('select[id$="-cat"]');
    const options = [...select.options].map((o) => o.textContent);
    assert.equal(options[0], 'Alle');
    const expected = [...new Set(sampleFeed.flatMap((e) => e.categories))].sort((a, b) => a.localeCompare(b, 'de-CH'));
    assert.deepEqual(options.slice(1), expected);
    change(window, select, 'Musik');
    const musik = sampleFeed.filter((e) => e.categories.includes('Musik'));
    assert.equal(cards(document).length, musik.length);
    assert.ok(cards(document).every((c) => [...c.querySelectorAll('.eo-cats li')].some((l) => l.textContent === 'Musik')));
    assert.equal(status(document), `${musik.length} Veranstaltungen gefunden.`);
  });

  test('room filter narrows the list', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    const select = document.querySelector('select[id$="-room"]');
    assert.ok([...select.options].some((o) => o.textContent === 'Bühnensaal'));
    assert.ok(![...select.options].some((o) => o.textContent === ''), 'no empty room option');
    change(window, select, 'Bühnensaal');
    const expected = sampleFeed.filter((e) => e.room === 'Bühnensaal');
    assert.equal(cards(document).length, expected.length);
    assert.ok(cards(document).every((c) => c.querySelector('.eo-room').textContent.includes('Bühnensaal')));
  });

  test('date range filter: from, to, and one single day', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    const from = document.querySelector('input[id$="-from"]');
    const to = document.querySelector('input[id$="-to"]');
    change(window, from, '2027-02-01');
    change(window, to, '2027-02-28');
    const feb = sampleFeed.filter((e) => e.start.startsWith('2027-02')).length;
    assert.equal(cards(document).length, feb);
    assert.deepEqual([...document.querySelectorAll('.eo-month-title')].map((h) => h.textContent), ['Februar 2027']);
    // A two-day event is found on its second day.
    change(window, from, '2027-02-14');
    change(window, to, '2027-02-14');
    assert.deepEqual(cards(document).map(titleOf), ['Wochenendkurs Siebdruck']);
  });

  test('a range that ends before it starts is reported, not silently emptied', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    change(window, document.querySelector('input[id$="-from"]'), '2027-03-01');
    change(window, document.querySelector('input[id$="-to"]'), '2027-02-01');
    assert.equal(status(document), 'Das Enddatum liegt vor dem Startdatum.');
    assert.equal(document.querySelector('input[id$="-to"]').getAttribute('aria-invalid'), 'true');
    change(window, document.querySelector('input[id$="-to"]'), '2027-03-31');
    assert.equal(document.querySelector('input[id$="-to"]').hasAttribute('aria-invalid'), false);
  });

  test('text search looks at title, description, room and labels, ignoring case and accents', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    const search = document.querySelector('input[type="search"]');
    change(window, search, 'JAZZ');
    assert.deepEqual(cards(document).map(titleOf), ['Konzert: Trio Rheinwind']);
    change(window, search, 'buhnensaal');
    assert.equal(cards(document).length, sampleFeed.filter((e) => e.room === 'Bühnensaal').length);
    change(window, search, 'yoga oberer');
    assert.ok(cards(document).length > 0 && cards(document).every((c) => titleOf(c) === 'Yoga im Union'));
    change(window, search, 'senior:innen');
    assert.ok(cards(document).length > 0);
  });

  test('filters combine, and the reset button restores everything and returns focus to the search', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    change(window, document.querySelector('select[id$="-cat"]'), 'Musik');
    change(window, document.querySelector('select[id$="-room"]'), 'Bühnensaal');
    const both = sampleFeed.filter((e) => e.categories.includes('Musik') && e.room === 'Bühnensaal').length;
    assert.equal(cards(document).length, both);
    document.querySelector('button.eo-button').click();
    assert.equal(cards(document).length, sampleFeed.length);
    assert.equal(document.activeElement, document.querySelector('input[type="search"]'));
    assert.equal(status(document), `${sampleFeed.length} Veranstaltungen gefunden.`);
  });

  test('no match: a message in the list and in the live region', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    change(window, document.querySelector('input[type="search"]'), 'qqqqqq');
    assert.equal(cards(document).length, 0);
    assert.match(document.querySelector('.eo-results').textContent, /Keine Veranstaltungen gefunden\./);
    await delay(450);
    assert.equal(status(document), 'Keine Veranstaltungen gefunden.');
  });

  test('the count is a polite live region; typing announces after a pause, not per key', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    const region = document.querySelector('.eo-status');
    assert.equal(region.getAttribute('role'), 'status');
    assert.equal(region.getAttribute('aria-live'), 'polite');
    assert.equal(region.getAttribute('aria-atomic'), 'true');
    const search = document.querySelector('input[type="search"]');
    change(window, search, 'y');
    change(window, search, 'yo');
    change(window, search, 'yoga');
    assert.equal(status(document), `${sampleFeed.length} Veranstaltungen gefunden.`, 'not announced yet');
    await delay(450);
    assert.equal(status(document), `${sampleFeed.filter((e) => e.title.includes('Yoga')).length} Veranstaltungen gefunden.`);
  });

  test('a pending announcement from typing never overwrites a newer one (quick reset)', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    change(window, document.querySelector('input[type="search"]'), 'qqqqqq');
    document.querySelector('button.eo-button').click();
    await delay(450);
    assert.equal(status(document), `${sampleFeed.length} Veranstaltungen gefunden.`);
    assert.equal(cards(document).length, sampleFeed.length);
  });

  test('one single result is announced in the singular', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    change(window, document.querySelector('input[type="search"]'), 'siebdruck');
    await delay(450);
    assert.equal(status(document), '1 Veranstaltung gefunden.');
  });
});

describe('highlights view', () => {
  test('shows the next events with the tag, limited, in date order, with the full date visible', async () => {
    const { document } = await openPage({ html: highlightsHtml() });
    const shown = cards(document);
    assert.equal(shown.length, 6);
    const expected = sampleFeed.filter((e) => e.tags.includes('Highlight')).slice(0, 6).map((e) => e.title);
    assert.deepEqual(shown.map(titleOf), expected);
    assert.ok(shown[0].querySelector('.eo-tags li') && [...shown[0].querySelectorAll('.eo-tags li')].some((l) => l.textContent === 'Highlight'));
    assert.match(shown[0].querySelector('.eo-when').textContent, /Fr\., 15\. Jan\., 19:00–22:30 Uhr/);
    assert.equal(document.querySelector('.eo-filters'), null, 'no filters in the highlights view');
    assert.ok(document.querySelector('.eo-highlights.eo-view-grid'));
  });

  test('data-limit and data-tag (case-insensitive) are honoured', async () => {
    const { document } = await openPage({ html: highlightsHtml('data-limit="3"') });
    assert.equal(cards(document).length, 3);
    const other = await openPage({ html: highlightsHtml('data-tag="senior:INNEN" data-limit="50"') });
    const expected = sampleFeed.filter((e) => e.tags.includes('Senior:innen')).length;
    assert.equal(cards(other.document).length, expected);
  });

  test('events that are over are left out; cancelled ones stay, marked', async () => {
    const { document } = await openPage({ html: highlightsHtml('data-now="2027-03-01T00:00:00+01:00"') });
    assert.deepEqual(cards(document).map(titleOf).map((t) => t.replace(/^abgesagt /, '')), ['Tag der offenen Tür', 'Offene Bühne', 'Salsa-Nacht']);
    assert.equal(cards(document)[1].classList.contains('eo-cancelled'), true);
  });

  test('a message instead of an empty box when nothing matches', async () => {
    const { document } = await openPage({ html: highlightsHtml('data-tag="Gibtesnicht"') });
    assert.equal(cards(document).length, 0);
    assert.equal(document.querySelector('.eo-message').textContent, 'Zurzeit sind keine Highlights geplant.');
  });

  test('the home page may carry both views and loads the feed only once', async () => {
    const { document, calls } = await openPage({ html: highlightsHtml() + calendarHtml() });
    assert.equal(calls.length, 1);
    assert.ok(document.querySelector('.eo-highlights article'));
    assert.ok(document.querySelector('.eo-calendar article'));
  });
});

describe('cancelled events and missing fields', () => {
  test('a cancelled event is struck through and carries the word «abgesagt»', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const card = cards(document).find((c) => c.querySelector('.eo-title s') && c.querySelector('.eo-title s').textContent === 'Kinderpiazza');
    assert.ok(card, 'cancelled Kinderpiazza');
    assert.ok(card.classList.contains('eo-cancelled'));
    assert.equal(card.querySelector('.eo-cancel-label').textContent, 'abgesagt');
    assert.match(card.querySelector('.eo-title').textContent, /^abgesagt Kinderpiazza$/);
    const normal = cards(document).filter((c) => titleOf(c) === 'Kinderpiazza');
    assert.equal(normal.length, 12);
  });

  test('without image: a neutral placeholder, no img element', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const card = cards(document).find((c) => titleOf(c) === 'Theater Niemandsland');
    assert.equal(card.querySelector('img'), null);
    assert.equal(card.querySelector('.eo-placeholder').getAttribute('aria-hidden'), 'true');
  });

  test('a picture that fails to load is replaced by the placeholder', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    const img = document.querySelector('.eo-media img');
    img.dispatchEvent(new window.Event('error'));
    assert.equal(document.querySelector('.eo-media img') === img, false);
  });

  test('alt text that only repeats the title is dropped; a useful alt text is kept', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const same = cards(document).find((c) => titleOf(c) === 'Offener Deutschtreff').querySelector('img');
    assert.equal(same.getAttribute('alt'), '');
    const useful = cards(document).find((c) => titleOf(c) === 'Yoga im Union').querySelector('img');
    assert.equal(useful.getAttribute('alt'), 'Menschen auf Yogamatten in einem hellen Saal');
    for (const img of document.querySelectorAll('img')) assert.ok(img.hasAttribute('alt'));
  });

  test('without category, tags, room or description: those parts are simply absent', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const noCategory = cards(document).find((c) => titleOf(c) === 'Spieleabend');
    assert.equal(noCategory.querySelector('.eo-cats'), null);
    const noRoom = cards(document).find((c) => titleOf(c) === 'Café Austausch');
    assert.equal(noRoom.querySelector('.eo-room'), null);
    assert.doesNotMatch(noRoom.textContent, /null|undefined/);
    const emptyRoom = cards(document).find((c) => titleOf(c) === 'Tag der offenen Tür');
    assert.equal(emptyRoom.querySelector('.eo-room'), null);
  });

  test('nothing to open when there is no description and the location is only the room', async () => {
    const { document } = await openPage({ html: calendarHtml(), feed: [event()] });
    assert.equal(document.querySelector('details'), null);
  });

  test('the room filter ignores events without a room, the category filter ignores events without category', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const rooms = [...document.querySelectorAll('select[id$="-room"] option')].map((o) => o.value);
    assert.ok(!rooms.includes('null') && !rooms.includes('undefined'));
  });

  test('entries that are unusable are skipped, the rest is shown', async () => {
    const feed = [event(), { title: 'Ohne Datum' }, { start: '2027-02-02T10:00:00+01:00' }, 'text', null, event({ id: 'x', title: 'Schlechtes Datum', start: 'morgen' })];
    const { document } = await openPage({ html: calendarHtml(), feed });
    assert.deepEqual(cards(document).map(titleOf), ['Testanlass']);
  });

  test('wrong types inside an entry do not break it', async () => {
    const feed = [event({ room: 5, categories: 'Musik', tags: [null, 3, 'Gut'], image: 'x', description_html: 42, cancelled: 'yes', end: 'kaputt' })];
    const { document } = await openPage({ html: calendarHtml(), feed });
    assert.equal(cards(document).length, 1);
    assert.deepEqual([...document.querySelectorAll('.eo-tags li')].map((l) => l.textContent), ['Gut']);
    assert.equal(document.querySelector('.eo-cancelled'), null, 'only the boolean true cancels');
  });

  test('an empty feed says so', async () => {
    const { document } = await openPage({ html: calendarHtml(), feed: [] });
    assert.equal(document.querySelector('.eo-message').textContent, 'Zurzeit sind keine Veranstaltungen geplant.');
    assert.equal(document.querySelector('.eo-error'), null);
  });
});

describe('feed errors show the fallback', () => {
  const FALLBACK = 'https://www.qtp-basel.ch/programm/';

  async function assertFallback(feed, html = calendarHtml(), expectedHref = FALLBACK) {
    const { document } = await openPage({ html, feed });
    const box = document.querySelector('.eo-error');
    assert.ok(box, 'error box');
    assert.match(box.textContent, /Die Veranstaltungen können im Moment nicht angezeigt werden\./);
    const link = box.querySelector('a');
    assert.equal(link.getAttribute('href'), expectedHref);
    assert.equal(link.textContent, 'Zum aktuellen Programm');
    assert.equal(document.querySelector('[aria-busy]'), null, 'no longer busy');
    assert.equal(document.querySelectorAll('article').length, 0);
    return document;
  }

  test('network error', () => assertFallback(new TypeError('Failed to fetch')));
  test('HTTP 503', () => assertFallback({ status: 503, json: { code: 'x' } }));
  test('HTTP 404', () => assertFallback({ status: 404, json: {} }));
  test('malformed JSON', () => assertFallback({ status: 200, raw: '<html>Wartung</html>' }));
  test('JSON that is not a list', () => assertFallback({ events: [] }));
  test('a list of nonsense', () => assertFallback([1, 'a', { x: 1 }]));
  test('the highlights view shows the same fallback', () => assertFallback(new TypeError('offline'), highlightsHtml()));
  test('data-fallback sets the link', () => assertFallback(new TypeError('offline'), calendarHtml('data-fallback="https://example.org/programm"'), 'https://example.org/programm'));
  test('a javascript: fallback address is ignored', () => assertFallback(new TypeError('offline'), calendarHtml('data-fallback="javascript:alert(1)"')));

  test('a feed that never answers ends in the fallback (after the timeout)', async () => {
    const dom = await import('jsdom');
    const window = new dom.JSDOM(`<main>${calendarHtml()}</main>`, { url: 'https://union.test/', runScripts: 'outside-only' }).window;
    window.fetch = (url, init) => new Promise((_, reject) => init.signal.addEventListener('abort', () => reject(new Error('aborted'))));
    const realSetTimeout = window.setTimeout;
    window.setTimeout = (fn, ms) => realSetTimeout(fn, ms >= 10000 ? 20 : ms); // do not wait 10 s in a test
    const { widgetSource } = await import('./helpers.js');
    window.eval(widgetSource);
    await window.EnterOnceWidget.ready;
    assert.ok(window.document.querySelector('.eo-error'));
  });
});

describe('untrusted data', () => {
  const evil = `
    <p onclick="window.__pwned=1">Text mit <b onmouseover="window.__pwned=2">fett</b></p>
    <script>window.__pwned = 3</script>
    <img src="x" onerror="window.__pwned=4">
    <svg onload="window.__pwned=5"><script>window.__pwned=6</script></svg>
    <iframe srcdoc="<script>parent.__pwned=7</script>"></iframe>
    <style>body{display:none}</style>
    <a href="javascript:window.__pwned=8" onclick="window.__pwned=9">schlechter Link</a>
    <a href="https://example.org/ok" target="_blank" style="color:red">guter Link</a>
    <h1>Titel</h1>
    <form action="https://evil.example"><input name="x"><button>Los</button></form>
    <object data="x"></object><embed src="x"><video src="x"></video>
    <div class="nope"><span>Text in unbekannten Elementen bleibt</span></div>`;

  test('description_html is rebuilt from an allow-list: no script, handler, style, frame or form survives', async () => {
    const feed = [event({ title: '<img src=x onerror="window.__pwned=10">', description_html: evil, location: 'Ort <b>fett</b>' })];
    const { window, document } = await openPage({ html: calendarHtml(), feed });
    const card = document.querySelector('article');
    card.querySelector('summary').click();
    assert.equal(window.__pwned, undefined);
    assert.equal(card.querySelectorAll('script, style, iframe, svg, img, form, input, button, object, embed, video, h1').length, 0);
    for (const element of card.querySelectorAll('*')) {
      for (const attr of element.attributes) assert.ok(!/^on/i.test(attr.name), `attribute ${attr.name} on ${element.tagName}`);
      assert.equal(element.hasAttribute('style'), false);
    }
    const links = [...card.querySelectorAll('.eo-desc a')];
    assert.equal(links.length, 1, 'the javascript: link lost its href and element');
    assert.equal(links[0].getAttribute('href'), 'https://example.org/ok');
    assert.equal(links[0].getAttribute('rel'), 'noopener noreferrer');
    assert.equal(links[0].hasAttribute('target'), false);
    assert.match(card.querySelector('.eo-desc').textContent, /Text in unbekannten Elementen bleibt/);
    assert.match(card.querySelector('.eo-desc').textContent, /schlechter Link/, 'the text of a removed link stays');
    assert.doesNotMatch(card.querySelector('.eo-desc').textContent, /__pwned|body\{display/);
  });

  test('titles and rooms are text, never markup', async () => {
    const feed = [event({ title: '<b>Fett</b> & <script>x</script>', room: '<i>Raum</i>', categories: ['<u>K</u>'], tags: ['<s>T</s>'] })];
    const { document } = await openPage({ html: calendarHtml(), feed });
    const card = document.querySelector('article');
    assert.equal(card.querySelector('.eo-title').textContent, '<b>Fett</b> & <script>x</script>');
    assert.equal(card.querySelectorAll('b, i, u, script').length, 0);
    assert.equal(card.querySelector('.eo-cats li').textContent, '<u>K</u>');
  });

  test('image addresses other than http(s) are refused', async () => {
    const feed = [event({ image: { url: 'javascript:alert(1)', alt: 'x' } }), event({ id: 'y', title: 'Zwei', image: { url: 'data:image/svg+xml,<svg onload=alert(1)>', alt: '' } })];
    const { document } = await openPage({ html: calendarHtml(), feed });
    assert.equal(document.querySelectorAll('img').length, 0);
    assert.equal(document.querySelectorAll('.eo-placeholder').length, 2);
  });
});

describe('where the widget finds its feed and stylesheet', () => {
  const tag = (src) => `<script src="${src}" defer></script>`;

  test('by default: same host as the script, /wp-json/enteronce/v1/events, plus the source', async () => {
    const { calls, document } = await openPage({
      html: '<div data-enteronce="calendar" data-source="union"></div>',
      scriptTag: tag('https://www.qtp-basel.ch/wp-content/plugins/enter-once-sync/widget/enteronce-widget.js')
    });
    assert.deepEqual(calls, ['https://www.qtp-basel.ch/wp-json/enteronce/v1/events?source=union']);
    const link = document.querySelector('link[rel="stylesheet"]');
    assert.equal(link.getAttribute('href'), 'https://www.qtp-basel.ch/wp-content/plugins/enter-once-sync/widget/enteronce-widget.css');
  });

  test('WordPress in a sub-folder', async () => {
    const { calls } = await openPage({
      html: '<div data-enteronce="highlights"></div>',
      scriptTag: tag('https://example.org/verband/wp-content/plugins/enter-once-sync/widget/enteronce-widget.js?ver=1')
    });
    assert.deepEqual(calls, ['https://example.org/verband/wp-json/enteronce/v1/events']);
  });

  test('data-feed overrides it; a stylesheet that is already linked is not added twice', async () => {
    const { calls, document } = await openPage({
      html: `<link rel="stylesheet" href="/css/enteronce-widget.css"><div data-enteronce="calendar" data-feed="${FEED_URL}" data-source="a b"></div>`,
      scriptTag: tag('https://www.qtp-basel.ch/wp-content/plugins/enter-once-sync/widget/enteronce-widget.js')
    });
    assert.deepEqual(calls, ['https://feed.test/events?source=a+b']);
    assert.equal(document.querySelectorAll('link[rel="stylesheet"]').length, 1);
  });

  test('elements that are not ours are left alone; running twice does nothing twice', async () => {
    const { window, document, calls } = await openPage({ html: `<div data-enteronce="unknown"></div>${calendarHtml()}` });
    await window.EnterOnceWidget.init();
    assert.equal(document.querySelector('[data-enteronce="unknown"]').children.length, 0);
    assert.equal(calls.length, 1);
    assert.equal(document.querySelectorAll('form.eo-filters').length, 1);
  });
});
