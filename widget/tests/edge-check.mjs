#!/usr/bin/env node
/*
 * Real-browser check with Microsoft Edge (headless), driven over the DevTools protocol.
 * Not part of `npm test` (it needs Edge). Run:  npm run check:edge
 *
 * What it does, against the demo pages on a local server (all outside hosts are blocked):
 *   1. axe-core in a real browser, WITH colour contrast, on calendar, highlights and the fallback;
 *   2. a keyboard-only walkthrough of the calendar: Tab order, typing, select by arrow keys,
 *      opening details with Enter and Space, reset button; checks a visible focus outline on every stop;
 *   3. screenshots (desktop, phone width, grid, fallback) and a JSON report in tests/edge-output/.
 *
 * Set EDGE to the path of msedge.exe if it is somewhere else.
 */
import { spawn } from 'node:child_process';
import { mkdir, writeFile, readFile, mkdtemp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createDemoServer } from '../demo/serve.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.join(here, 'edge-output');
const axePath = path.join(here, '..', 'node_modules', 'axe-core', 'axe.min.js');
const EDGE = [process.env.EDGE, 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].filter(Boolean).find((p) => existsSync(p));
if (!EDGE) { console.error('Edge not found. Set EDGE=path\\to\\msedge.exe'); process.exit(2); }

const CALENDAR = '/quartierzentrum-union/veranstaltungskalender/';
const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok: !!ok, detail }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  – ' + detail : ''}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function listen(options) {
  const server = createDemoServer(options);
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  return { server, base: `http://127.0.0.1:${server.address().port}` };
}

/* ------------------------------------------------------------ DevTools client */

async function launch() {
  const profile = await mkdtemp(path.join(os.tmpdir(), 'eo-edge-'));
  const port = 9300 + Math.floor(Math.random() * 500);
  const proc = spawn(EDGE, [
    '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu',
    '--lang=de-CH', '--window-size=1280,1000', '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1', 'about:blank'
  ], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 60 && !target; i++) {
    await sleep(250);
    try { target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === 'page'); } catch { /* not up yet */ }
  }
  if (!target) throw new Error('Edge did not start');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let id = 0;
  const pending = new Map();
  const events = [];
  ws.onmessage = (m) => {
    const msg = JSON.parse(m.data);
    if (msg.id && pending.has(msg.id)) { const { resolve, reject } = pending.get(msg.id); pending.delete(msg.id); msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result); } else events.push(msg);
  };
  const send = (method, params = {}) => new Promise((resolve, reject) => { const i = ++id; pending.set(i, { resolve, reject }); ws.send(JSON.stringify({ id: i, method, params })); });
  const close = async () => { try { ws.close(); } catch { /* */ } proc.kill(); await sleep(300); await rm(profile, { recursive: true, force: true }).catch(() => {}); };
  return { send, events, close };
}

function pageApi(cdp) {
  const { send } = cdp;
  const api = {
    async eval(expression) {
      const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    },
    async goto(url, width = 1280, height = 1000) {
      await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 600 });
      await send('Page.navigate', { url });
      for (let i = 0; i < 80; i++) {
        await sleep(150);
        const ready = await api.eval(`document.readyState === 'complete' && !!document.querySelector('[data-enteronce]') && !document.querySelector('[data-enteronce][aria-busy]') && document.querySelector('[data-enteronce]').children.length > 0`).catch(() => false);
        if (ready) return;
      }
      throw new Error('widget did not finish: ' + url);
    },
    async key(key, { code = key, vk, text, shift = false } = {}) {
      const base = { key, code, windowsVirtualKeyCode: vk, nativeVirtualKeyCode: vk, modifiers: shift ? 8 : 0 };
      await send('Input.dispatchKeyEvent', { type: text ? 'keyDown' : 'rawKeyDown', ...base, text });
      await send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
      await sleep(40);
    },
    tab: (shift = false) => api.key('Tab', { vk: 9, shift }),
    enter: () => api.key('Enter', { vk: 13, text: '\r' }),
    space: () => api.key(' ', { code: 'Space', vk: 32, text: ' ' }),
    arrowDown: () => api.key('ArrowDown', { vk: 40 }),
    async type(text) { for (const ch of text) { await send('Input.dispatchKeyEvent', { type: 'keyDown', key: ch, text: ch }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: ch }); } await sleep(60); },
    async screenshot(name, fullPage = false) {
      const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: fullPage });
      await writeFile(path.join(out, name), Buffer.from(data, 'base64'));
    },
    /** What has keyboard focus: tag, accessible-ish name, and whether a focus outline is visible. */
    focused: () => api.eval(`(() => {
      const e = document.activeElement; if (!e || e === document.body) return null;
      const cs = getComputedStyle(e);
      const label = e.labels && e.labels[0] ? e.labels[0].textContent : (e.getAttribute('aria-label') || e.textContent || '').trim().slice(0, 40);
      const card = e.closest('[data-enteronce] article');
      return { tag: e.tagName.toLowerCase() + (e.type ? '[' + e.type + ']' : ''), label, card: card ? card.querySelector('.eo-title').textContent.slice(0, 40) : null,
        outline: cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 2, inWidget: !!e.closest('[data-enteronce]'),
        open: e.tagName === 'SUMMARY' ? e.parentElement.open : undefined };
    })()`),
    async axe(contrast = true) {
      await api.eval(await readFile(axePath, 'utf8'));
      return api.eval(`axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] }, rules: { 'color-contrast': { enabled: ${contrast} } } })
        .then(r => ({ violations: r.violations.map(v => ({ id: v.id, help: v.help, impact: v.impact, nodes: v.nodes.slice(0, 4).map(n => n.target.join(' ') + ' :: ' + (n.any[0] ? n.any[0].message : '')) })), passes: r.passes.length, incomplete: r.incomplete.map(i => i.id) }))`);
    }
  };
  return api;
}

/* ---------------------------------------------------------------------- run */

await mkdir(out, { recursive: true });
const live = await listen({});
const dead = await listen({ dead: '503' });
const cdp = await launch();
const page = pageApi(cdp);

try {
  await cdp.send('Page.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Emulation.setLocaleOverride', { locale: 'de-CH' }).catch(() => {});

  /* 1. axe with colour contrast */
  for (const [label, url, width] of [
    ['calendar, desktop', live.base + CALENDAR, 1280],
    ['calendar, phone width 375', live.base + CALENDAR, 375],
    ['home page with highlights', live.base + '/', 1280],
    ['fallback (feed dead)', dead.base + CALENDAR, 1280]
  ]) {
    await page.goto(url, width);
    const slug = label.replace(/[^a-z0-9]+/gi, '-');
    await page.screenshot(`${slug}.png`);
    const result = await page.axe(true);
    // Only the widget counts here: the copied Union page has its own findings, listed separately.
    const inside = result.violations.map((v) => ({ ...v, nodes: v.nodes.filter((n) => /eo-|data-enteronce|\.eo/.test(n)) })).filter((v) => v.nodes.length);
    check(`axe + colour contrast, widget only: ${label}`, inside.length === 0, inside.map((v) => `${v.id}: ${v.nodes.join(' | ')}`).join('; '));
    const outside = result.violations.filter((v) => !inside.some((i) => i.id === v.id));
    if (outside.length) console.log(`      (page outside the widget, not ours: ${outside.map((v) => v.id).join(', ')})`);
    results.push({ name: `axe, page outside the widget: ${label}`, ok: true, detail: outside.map((v) => v.id).join(', ') || 'none', informational: true });
  }

  /* 2. keyboard walkthrough on the calendar */
  await page.goto(live.base + CALENDAR, 1280, 900);
  const count = await page.eval(`document.querySelectorAll('.eo-widget article').length`);
  const summaries = await page.eval(`document.querySelectorAll('.eo-widget article summary').length`);
  // Put the keyboard just in front of the widget: focus the page heading above it.
  await page.eval(`(() => { const h = [...document.querySelectorAll('h2')].find(h => h.textContent === 'Programm'); h.setAttribute('tabindex', '-1'); h.focus(); })()`);
  const stops = [];
  for (let i = 0; i < 40; i++) {
    await page.tab();
    const f = await page.focused();
    if (!f || !f.inWidget) break;
    const last = stops[stops.length - 1];
    if (last && last.tag === f.tag && last.label === f.label && last.card === f.card) last.presses++;
    else stops.push({ ...f, presses: 1 });
    if (stops.length >= 8) break;
  }
  const names = stops.map((s) => `${s.tag} «${s.label}»${s.presses > 1 ? ' ×' + s.presses : ''}`);
  console.log('      tab order:', names.join('  →  '));
  check('Tab reaches search, both date fields, category, room, reset, then the first card’s button – in that order',
    stops.length >= 7 && stops[0].tag === 'input[search]' && stops[1].tag === 'input[date]' && stops[2].tag === 'input[date]' && stops[3].tag === 'select[select-one]' && stops[4].tag === 'select[select-one]' && stops[5].tag === 'button[submit]' || stops[5]?.label === 'Filter zurücksetzen',
    names.join(' → '));
  check('every keyboard stop in the widget has a visible focus outline', stops.every((s) => s.outline), stops.filter((s) => !s.outline).map((s) => s.label).join(', '));
  check('the first card’s «Mehr Informationen» button comes right after the filters', stops.some((s) => s.tag === 'summary' && s.label === 'Mehr Informationen'));

  // Typing in the search field narrows the list; status is announced after a pause.
  await page.eval(`document.querySelector('input[type=search]').focus()`);
  await page.type('jazz');
  await sleep(600);
  const jazz = await page.eval(`({ n: document.querySelectorAll('.eo-widget article').length, status: document.querySelector('.eo-status').textContent, q: document.querySelector('input[type=search]').value })`);
  check('typing «jazz» in the search field filters to one card, announced in the live region', jazz.n === 1 && jazz.status === '1 Veranstaltung gefunden.', JSON.stringify(jazz));
  // Reset with the keyboard.
  await page.eval(`document.querySelector('.eo-button').focus()`);
  await page.space();
  check('reset button works with Space and returns focus to the search field',
    (await page.eval(`document.querySelectorAll('.eo-widget article').length`)) === count && (await page.focused())?.tag === 'input[search]');
  await page.eval(`document.querySelector('.eo-button').focus()`);
  await page.type('x');
  await page.eval(`document.querySelector('input[type=search]').value = 'zzzz'; document.querySelector('input[type=search]').dispatchEvent(new Event('input', { bubbles: true }))`);
  await page.eval(`document.querySelector('.eo-button').focus()`);
  await page.enter();
  await sleep(600); // longer than the typing pause: a stale announcement would show now
  check('reset button works with Enter, and the live-region text matches the list afterwards',
    (await page.eval(`document.querySelectorAll('.eo-widget article').length`)) === count
    && (await page.eval(`document.querySelector('.eo-status').textContent`)) === `${count} Veranstaltungen gefunden.`);

  // Category select by keyboard (arrow down on a focused closed select changes the value).
  await page.eval(`document.querySelector('select[id$="-cat"]').focus()`);
  await page.arrowDown();
  const after = await page.eval(`({ n: document.querySelectorAll('.eo-widget article').length, value: document.querySelector('select[id$="-cat"]').value, status: document.querySelector('.eo-status').textContent })`);
  check('category select changes with the arrow key and the list follows', after.value !== '' && after.n < count && after.n > 0, JSON.stringify(after));
  await page.eval(`document.querySelector('.eo-button').focus()`);
  await page.enter();

  // Date field typed with the keyboard.
  await page.eval(`document.querySelector('input[id$="-from"]').focus()`);
  const today = await page.eval(`(() => { const d = [...document.querySelectorAll('.eo-when time')].pop(); return d.getAttribute('datetime').slice(0, 10); })()`);
  const [y, m, d] = today.split('-');
  await page.type(`${d}${m}${y}`);
  const dateState = await page.eval(`({ value: document.querySelector('input[id$="-from"]').value, n: document.querySelectorAll('.eo-widget article').length })`);
  check('a date typed into «Von» filters the list', dateState.value === today && dateState.n >= 1 && dateState.n < count, JSON.stringify(dateState));
  await page.eval(`document.querySelector('.eo-button').focus()`);
  await page.enter();

  // Open and close a card with Enter and Space.
  await page.eval(`document.querySelector('article summary').focus()`);
  await page.enter();
  const opened = await page.eval(`document.querySelector('article details').open`);
  await page.space();
  const closed = await page.eval(`!document.querySelector('article details').open`);
  check('«Mehr Informationen» opens with Enter and closes with Space', opened && closed);
  await page.enter();
  await page.screenshot('calendar-card-open-focus.png');

  // No keyboard trap: Tab onward leaves the widget, Shift+Tab comes back.
  await page.eval(`document.querySelector('.eo-button').focus()`);
  let left = false;
  for (let i = 0; i < summaries + 5 && !left; i++) { await page.tab(); const f = await page.focused(); left = !f || !f.inWidget; }
  check('Tab leaves the widget after the last card (no keyboard trap)', left);
  await page.tab(true);
  check('Shift+Tab comes back into the widget', (await page.focused())?.inWidget === true);

  /* 3. text spacing (WCAG 1.4.12), 200 % zoom and reflow at 320 px */
  await page.goto(live.base + CALENDAR, 1280, 900);
  await page.eval(`(() => { const s = document.createElement('style'); s.textContent = '*{line-height:1.5 !important;letter-spacing:0.12em !important;word-spacing:0.16em !important} p{margin-bottom:2em !important}'; document.head.appendChild(s); })()`);
  await page.screenshot('calendar-text-spacing.png');
  const clipped = await page.eval(`[...document.querySelectorAll('.eo-widget .eo-body, .eo-widget .eo-card')].filter(e => e.scrollWidth > e.clientWidth + 1).length`);
  check('with the WCAG text-spacing overrides nothing is cut off horizontally', clipped === 0, `${clipped} elements overflow`);
  await page.goto(live.base + CALENDAR, 320, 700);
  const overflow = await page.eval(`document.documentElement.scrollWidth - document.documentElement.clientWidth`);
  const widgetOverflow = await page.eval(`(() => { const w = document.querySelector('.eo-widget'); return [...w.querySelectorAll('*')].filter(e => e.getBoundingClientRect().right > document.documentElement.clientWidth + 1).length; })()`);
  check('at 320 px width no page-level horizontal scroll and nothing of the widget sticks out', overflow <= 0 && widgetOverflow === 0, `page overflow ${overflow}px, ${widgetOverflow} elements`);
  await page.screenshot('calendar-320px.png');

  /* 4. screenshots */
  await page.goto(live.base + '/', 1280, 1000);
  await page.eval(`document.querySelector('.eo-highlights').scrollIntoView()`);
  await page.screenshot('home-highlights.png');
  await page.goto(live.base + CALENDAR, 1280, 1000);
  await page.eval(`document.querySelector('.eo-widget').scrollIntoView()`);
  await page.screenshot('calendar-desktop.png');
  await page.eval(`document.querySelector('select[id$="-cat"]').focus()`);
  await page.screenshot('calendar-focus-select.png');
  await page.goto(dead.base + CALENDAR, 1280, 800);
  check('with the feed dead the fallback message and the programme link are shown',
    (await page.eval(`document.querySelector('.eo-error a').getAttribute('href')`)) === 'https://www.qtp-basel.ch/programm/');
  await page.eval(`document.querySelector('.eo-widget').scrollIntoView()`);
  await page.screenshot('fallback.png');
} finally {
  await cdp.close();
  live.server.close();
  dead.server.close();
}

await writeFile(path.join(out, 'report.json'), JSON.stringify({ date: new Date().toISOString(), browser: 'Microsoft Edge headless', results }, null, 2));
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed. Screenshots and report.json are in ${path.relative(process.cwd(), out)}`);
process.exit(failed.length ? 1 : 0);
