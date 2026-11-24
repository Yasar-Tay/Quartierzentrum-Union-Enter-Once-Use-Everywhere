#!/usr/bin/env node
/*
 * Demo server for the calendar widget. Node only, no dependencies.
 *
 *   node demo/serve.mjs                 serve the demo on http://127.0.0.1:8080
 *   node demo/serve.mjs --port=9000
 *   node demo/serve.mjs --dead          the feed answers 503 (shows the fallback message)
 *   node demo/serve.mjs --dead=garbage  the feed answers 200 with something that is not JSON
 *   node demo/serve.mjs --dead=hang     the feed never answers (the widget gives up after 10 s)
 *   node demo/serve.mjs --no-shift      serve the fixture dates unchanged (January to March 2027)
 *   node demo/serve.mjs --today=2027-01-10   pretend today is that day
 *
 * What it serves:
 *   /                                    the copied home page (demo/index.html)
 *   /quartierzentrum-union/veranstaltungskalender/   the copied calendar page
 *   /wp-content/plugins/enter-once-sync/widget/...   the widget script and stylesheet
 *   /wp-json/enteronce/v1/events         the fixture feed; honours from, to and source
 *   /media/events/<name>.jpg             a generated picture for every image in the feed
 *   anything else                        read-only from ../../webflow-static (the static copy of
 *                                        union-basel.ch: assets and the other pages)
 *
 * Unless --no-shift is given, the fixture is moved by whole weeks so that its first Monday
 * becomes the Monday of the current week. The demo therefore always shows upcoming events.
 */
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const widgetDir = path.resolve(here, '..');
const FEED_PATH = '/wp-json/enteronce/v1/events';
const PLUGIN_WIDGET = '/wp-content/plugins/enter-once-sync/widget/';
const IMAGE_HOST = 'https://media.example.org/events/';

const MIME = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.woff': 'font/woff', '.woff2': 'font/woff2', '.ico': 'image/x-icon',
  '.pdf': 'application/pdf', '.txt': 'text/plain; charset=utf-8'
};

/* ----------------------------------------------------------- date helpers */

const zurich = new Intl.DateTimeFormat('en-US', {
  timeZone: 'Europe/Zurich', hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit',
  hour: '2-digit', minute: '2-digit', second: '2-digit'
});

/** Offset of Europe/Zurich from UTC at the given instant, in minutes. */
function zurichOffset(ms) {
  const p = Object.fromEntries(zurich.formatToParts(new Date(ms)).map((x) => [x.type, x.value]));
  return (Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second) - ms) / 60000;
}

function todayZurich() {
  const p = Object.fromEntries(zurich.formatToParts(new Date()).map((x) => [x.type, x.value]));
  return `${p.year}-${p.month}-${p.day}`;
}

const dayNumber = (ymd) => Date.parse(ymd + 'T00:00:00Z') / 86400000;

/** Moves an ISO timestamp with offset by whole days, keeping the wall-clock time and fixing the offset. */
function shiftIso(iso, days) {
  const m = /^(\d{4})-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)/.exec(iso);
  if (!m) return iso;
  const wall = Date.UTC(+m[1], +m[2] - 1, +m[3] + days, +m[4], +m[5], +m[6]);
  let offset = zurichOffset(wall - 3600000);
  offset = zurichOffset(wall - offset * 60000);
  const sign = offset < 0 ? '-' : '+';
  const abs = Math.abs(offset);
  const hh = String(Math.floor(abs / 60)).padStart(2, '0');
  const mm = String(abs % 60).padStart(2, '0');
  return new Date(wall).toISOString().slice(0, 19) + `${sign}${hh}:${mm}`;
}

/* ------------------------------------------------------------------- feed */

async function loadFeed(options) {
  const fixture = options.fixture || path.join(widgetDir, 'tests', 'fixtures', 'feed-sample.json');
  let events = JSON.parse(await readFile(fixture, 'utf8'));
  if (options.shift !== false && events.length) {
    const today = options.today || todayZurich();
    const monday = dayNumber(today) - ((new Date(today + 'T00:00:00Z').getUTCDay() + 6) % 7);
    const first = dayNumber(events[0].start.slice(0, 10));
    const days = Math.round((monday - first) / 7) * 7;
    events = events.map((e) => {
      const start = shiftIso(e.start, days);
      return { ...e, id: e.id.replace(/\d{4}-\d\d-\d\d$/, start.slice(0, 10)), start, end: shiftIso(e.end, days) };
    });
  }
  return events;
}

function filterFeed(events, query, today) {
  const source = query.get('source');
  if (source && source !== 'union') return [];
  const last = events.length ? events[events.length - 1].end.slice(0, 10) : today;
  const from = /^\d{4}-\d\d-\d\d$/.test(query.get('from') || '') ? query.get('from') : today;
  const to = /^\d{4}-\d\d-\d\d$/.test(query.get('to') || '') ? query.get('to') : last;
  return events.filter((e) => e.end.slice(0, 10) >= from && e.start.slice(0, 10) <= to);
}

function rewriteImages(events, origin) {
  return events.map((e) => (e.image && e.image.url.startsWith(IMAGE_HOST)
    ? { ...e, image: { ...e.image, url: origin + '/media/events/' + e.image.url.slice(IMAGE_HOST.length) } }
    : e));
}

/** A plain coloured picture, different for every name, so the cards look like they have photos. */
function pictureSvg(name) {
  let h = 0;
  for (const c of name) h = (h * 31 + c.charCodeAt(0)) % 360;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 200" preserveAspectRatio="xMidYMid slice">
<rect width="320" height="200" fill="hsl(${h} 45% 82%)"/>
<circle cx="${60 + (h % 200)}" cy="${60 + (h % 70)}" r="70" fill="hsl(${(h + 40) % 360} 55% 65%)"/>
<rect x="${(h * 3) % 180}" y="110" width="150" height="90" fill="hsl(${(h + 200) % 360} 40% 45%)"/>
</svg>`;
}

/* ----------------------------------------------------------------- static */

async function sendFile(res, file, options) {
  let info;
  try { info = await stat(file); } catch { return false; }
  if (info.isDirectory()) return sendFile(res, path.join(file, 'index.html'), options);
  const ext = path.extname(file).toLowerCase();
  let body = await readFile(file);
  if (ext === '.html' && options.injectScripts?.length) {
    const tags = options.injectScripts.map((s) => `<script src="${s}"></script>`).join('');
    body = Buffer.from(body.toString('utf8').replace('</body>', tags + '</body>'));
  }
  res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream', 'Cache-Control': 'no-store' });
  res.end(body);
  return true;
}

function inside(root, target) {
  const rel = path.relative(root, target);
  return rel === '' || (!rel.startsWith('..') && !path.isAbsolute(rel));
}

/**
 * Builds the server (does not start it).
 * options: { dead, shift, today, fixture, staticRoot, mounts, injectScripts }
 *   dead: false | '503' | 'garbage' | 'hang'
 *   mounts: { '/url/path': '/file/on/disk' } extra single files (used by the browser check)
 */
export function createDemoServer(options = {}) {
  const staticRoot = options.staticRoot || path.resolve(widgetDir, '..', '..', 'webflow-static');
  return createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      let pathname = decodeURIComponent(url.pathname);
      const origin = `http://${req.headers.host}`;
      const today = options.today || todayZurich();

      if (pathname === FEED_PATH) {
        const headers = { 'Access-Control-Allow-Origin': '*', 'Cache-Control': 'no-store' };
        if (options.dead === 'hang') return; // never answers
        if (options.dead === 'garbage') {
          res.writeHead(200, { ...headers, 'Content-Type': 'text/html; charset=utf-8' });
          return res.end('<html><body>Wartungsarbeiten</body></html>');
        }
        if (options.dead) {
          res.writeHead(503, { ...headers, 'Content-Type': 'application/json; charset=utf-8' });
          return res.end(JSON.stringify({ code: 'enteronce_feed_disabled', message: 'Der Feed ist abgeschaltet.' }));
        }
        const events = filterFeed(await loadFeed(options), url.searchParams, today);
        res.writeHead(200, { ...headers, 'Content-Type': 'application/json; charset=utf-8' });
        return res.end(JSON.stringify(rewriteImages(events, origin)));
      }

      const image = /^\/media\/events\/([\w-]+)\.jpg$/.exec(pathname);
      if (image) {
        res.writeHead(200, { 'Content-Type': 'image/svg+xml', 'Cache-Control': 'no-store' });
        return res.end(pictureSvg(image[1]));
      }

      if (options.mounts && options.mounts[pathname]) {
        if (await sendFile(res, options.mounts[pathname], options)) return;
      }

      if (pathname.startsWith(PLUGIN_WIDGET)) {
        const file = path.join(widgetDir, pathname.slice(PLUGIN_WIDGET.length));
        if (inside(widgetDir, file) && (await sendFile(res, file, options))) return;
      }

      if (pathname === '/') pathname = '/index.html';
      for (const root of [here, staticRoot]) {
        const file = path.join(root, pathname);
        if (inside(root, file) && existsSync(file) && (await sendFile(res, file, options))) return;
      }
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('Nicht gefunden');
    } catch (err) {
      res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('Fehler: ' + err.message);
    }
  });
}

/* -------------------------------------------------------------------- CLI */

function parseArgs(argv) {
  const options = { port: 8080, host: '127.0.0.1' };
  for (const arg of argv) {
    const [key, value] = arg.replace(/^--/, '').split('=');
    if (key === 'port') options.port = Number(value);
    else if (key === 'host') options.host = value;
    else if (key === 'dead') options.dead = value || '503';
    else if (key === 'no-shift') options.shift = false;
    else if (key === 'today') options.today = value;
    else if (key === 'fixture') options.fixture = path.resolve(value);
    else if (key === 'static') options.staticRoot = path.resolve(value);
    else throw new Error(`Unbekannte Option: ${arg}`);
  }
  return options;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const options = parseArgs(process.argv.slice(2));
  const server = createDemoServer(options);
  server.listen(options.port, options.host, () => {
    const base = `http://${options.host}:${options.port}`;
    console.log(`Demo:        ${base}/quartierzentrum-union/veranstaltungskalender/`);
    console.log(`Startseite:  ${base}/`);
    console.log(`Feed:        ${base}${FEED_PATH}${options.dead ? `   (SIMULIERT AUSGEFALLEN: ${options.dead})` : ''}`);
    if (options.shift === false) console.log('Daten:       unveraendert aus der Fixture (Januar bis Maerz 2027)');
  });
}
