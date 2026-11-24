import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createDemoServer } from '../demo/serve.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const FEED = '/wp-json/enteronce/v1/events';

// The demo pages are copies of two pages of union-basel.ch, made by demo/build-pages.mjs from a static
// copy of that site (../../webflow-static). Neither is part of the public repository, because the
// content belongs to Union. Without them, the tests that need the copy are skipped.
const demoCalendar = path.join(here, '..', 'demo', 'quartierzentrum-union', 'veranstaltungskalender', 'index.html');
const demoHome = path.join(here, '..', 'demo', 'index.html');
const staticCopy = path.join(here, '..', '..', '..', 'webflow-static');
const haveSite = existsSync(demoCalendar) && existsSync(demoHome) && existsSync(path.join(staticCopy, 'index.html'));
const needsSite = haveSite ? {} : { skip: 'needs the static copy of union-basel.ch, which is not part of the repository' };

async function start(options) {
  const server = createDemoServer(options);
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  return { server, base: `http://127.0.0.1:${server.address().port}` };
}
const get = async (base, url) => {
  const res = await fetch(base + url);
  return { res, text: await res.text() };
};

describe('demo server: the feed', () => {
  let s;
  before(async () => { s = await start({ shift: false, today: '2027-01-01' }); });
  after(() => s.server.close());

  test('serves the fixture as JSON, open to every origin, images rewritten to itself', async () => {
    const { res, text } = await get(s.base, FEED);
    assert.equal(res.status, 200);
    assert.equal(res.headers.get('access-control-allow-origin'), '*');
    const events = JSON.parse(text);
    assert.ok(events.length >= 40);
    const image = events.find((e) => e.image).image.url;
    assert.ok(image.startsWith(s.base + '/media/events/'));
    const picture = await get(s.base, new URL(image).pathname);
    assert.equal(picture.res.headers.get('content-type'), 'image/svg+xml');
  });

  test('honours from, to and source', async () => {
    const all = JSON.parse((await get(s.base, FEED)).text);
    const feb = JSON.parse((await get(s.base, `${FEED}?from=2027-02-01&to=2027-02-28&source=union`)).text);
    assert.ok(feb.length > 0 && feb.length < all.length);
    assert.ok(feb.every((e) => e.start.startsWith('2027-02') || e.end.startsWith('2027-02')));
    const later = JSON.parse((await get(s.base, `${FEED}?from=2027-03-30`)).text);
    assert.ok(later.every((e) => e.end.slice(0, 10) >= '2027-03-30'));
    assert.deepEqual(JSON.parse((await get(s.base, `${FEED}?source=other`)).text), []);
  });

  test('from defaults to today', async () => {
    const t = await start({ shift: false, today: '2027-03-01' });
    const events = JSON.parse((await get(t.base, FEED)).text);
    t.server.close();
    assert.ok(events.length > 0 && events.every((e) => e.end.slice(0, 10) >= '2027-03-01'));
  });
});

describe('demo server: shifting the fixture to the current week', () => {
  test('first Monday becomes this Monday; offsets follow the summer time', async () => {
    const t = await start({ today: '2027-06-16' }); // a Wednesday
    const events = JSON.parse((await get(t.base, `${FEED}?from=2027-01-01`)).text);
    t.server.close();
    assert.equal(events[0].start, '2027-06-14T09:00:00+02:00');
    assert.equal(events[0].id.slice(-10), '2027-06-14');
    assert.ok(events.every((e) => /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:00$/.test(e.start)));
    assert.ok(events.some((e) => e.start.startsWith('2027-09')), 'the whole fixture moved, about 13 weeks');
    // Wall-clock times are kept, so a 15:00 event stays at 15:00 whatever the offset.
    assert.ok(events.filter((e) => e.title === 'Offener Deutschtreff').every((e) => e.start.includes('T15:00:00')));
  });

  test('the clock change inside the shifted range gets the right offset', async () => {
    const t = await start({ today: '2027-10-04' }); // Monday; the fixture then runs from 4 October to 29 December, CEST ends on 31 October
    const events = JSON.parse((await get(t.base, `${FEED}?from=2027-01-01`)).text);
    t.server.close();
    for (const e of events) {
      const expected = e.start.slice(0, 10) < '2027-10-31' ? '+02:00' : '+01:00';
      assert.equal(e.start.slice(-6), expected, e.start);
      assert.equal(e.end.slice(-6), e.end.slice(0, 10) < '2027-10-31' ? '+02:00' : '+01:00', e.end);
    }
    assert.ok(events.some((e) => e.start.endsWith('+02:00')) && events.some((e) => e.start.endsWith('+01:00')));
  });
});

describe('demo server: simulated dead feed', () => {
  test('--dead answers 503', async () => {
    const t = await start({ dead: '503' });
    const { res } = await get(t.base, FEED);
    t.server.close();
    assert.equal(res.status, 503);
  });

  test('--dead=garbage answers 200 with something that is not JSON', async () => {
    const t = await start({ dead: 'garbage' });
    const { res, text } = await get(t.base, FEED);
    t.server.close();
    assert.equal(res.status, 200);
    assert.throws(() => JSON.parse(text));
  });
});

describe('demo server: pages and files', () => {
  let s;
  before(async () => { s = await start({}); });
  after(() => s.server.close());

  test('serves the widget under the plugin path and the copied pages', needsSite, async () => {
    const js = await get(s.base, '/wp-content/plugins/enter-once-sync/widget/enteronce-widget.js');
    assert.equal(js.res.status, 200);
    assert.match(js.text, /data-enteronce/);
    const css = await get(s.base, '/wp-content/plugins/enter-once-sync/widget/enteronce-widget.css');
    assert.equal(css.res.status, 200);
    const home = await get(s.base, '/');
    assert.match(home.text, /data-enteronce="highlights"/);
    const calendar = await get(s.base, '/quartierzentrum-union/veranstaltungskalender/');
    assert.match(calendar.text, /data-enteronce="calendar"/);
  });

  test('falls back to the static copy for assets, and refuses to leave the folders', needsSite, async () => {
    const other = await get(s.base, '/kontakt-und-services/index.html');
    assert.equal(other.res.status, 200);
    const escape = await fetch(s.base + '/%2e%2e/%2e%2e/IMPLEMENTATION_PLAN.md');
    assert.equal(escape.status, 404);
    const escape2 = await fetch(s.base + '/wp-content/plugins/enter-once-sync/widget/..%2fpackage.json');
    assert.notEqual(escape2.status, 200);
  });
});

describe('the two demo pages', needsSite, () => {
  const calendarPage = haveSite ? readFileSync(demoCalendar, 'utf8') : '';
  const homePage = haveSite ? readFileSync(demoHome, 'utf8') : '';
  const original = (rel) => readFileSync(path.join(staticCopy, rel), 'utf8');

  test('the Elfsight event widgets are gone, the cookie banner stays', () => {
    for (const page of [calendarPage, homePage]) {
      assert.doesNotMatch(page, /elfsight-app-(2a073e75|cda7408f)/);
      assert.match(page, /elfsight-app-92219bf9-a181-4263-a9d4-ae959cdf5d33/);
    }
  });

  test('they carry the embed snippets of plan section 4.8', () => {
    assert.match(calendarPage, /<div data-enteronce="calendar" data-source="union"><\/div>\s*<script src="[^"]*\/enter-once-sync\/widget\/enteronce-widget\.js" defer><\/script>/);
    assert.match(homePage, /<div data-enteronce="highlights" data-source="union" data-tag="Highlight" data-limit="6"><\/div>/);
  });

  test('apart from that block they are identical to the static copy', () => {
    const strip = (html) => html
      .replace(/<!-- (Elfsight Event Calendar|Enter once widget)[\s\S]*?<\/div>/, '')
      .replace(/<script src="\/wp-content[^>]*><\/script>/, '')
      .replace(/<script src="https:\/\/elfsightcdn[^>]*><\/script>/g, '')
      .replace(/>\s+</g, '><');
    assert.equal(strip(calendarPage), strip(original('quartierzentrum-union/veranstaltungskalender/index.html')));
    assert.equal(strip(homePage), strip(original('index.html')));
  });
});
