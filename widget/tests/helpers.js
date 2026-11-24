// Shared helpers: build a jsdom page, run the widget in it with a fake feed.
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
export const widgetSource = readFileSync(path.join(here, '..', 'enteronce-widget.js'), 'utf8');
export const widgetCss = readFileSync(path.join(here, '..', 'enteronce-widget.css'), 'utf8');
export const axeSource = readFileSync(path.join(here, '..', 'node_modules', 'axe-core', 'axe.min.js'), 'utf8');
export const sampleFeed = JSON.parse(readFileSync(path.join(here, 'fixtures', 'feed-sample.json'), 'utf8'));

export const FEED_URL = 'https://feed.test/events';
export const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** A fetch that answers with the given body. body may be a value (sent as JSON), a function, or an Error. */
export function fakeFetch(body, calls = []) {
  return (url) => {
    calls.push(String(url));
    const value = typeof body === 'function' ? body(String(url)) : body;
    if (value instanceof Error) return Promise.reject(value);
    if (value && value.status) {
      return Promise.resolve({ ok: value.status < 400, status: value.status, json: () => (value.raw !== undefined ? Promise.reject(new SyntaxError('Unexpected token')) : Promise.resolve(value.json)) });
    }
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(structuredClone(value)) });
  };
}

/**
 * Opens a page with the given markup inside <body>, installs a fake fetch and runs the widget.
 * Returns { window, document, ready, calls }.
 */
export async function openPage({ html, feed = sampleFeed, url = 'https://union.test/programm/', scriptTag = '' }) {
  const dom = new JSDOM(`<!doctype html><html lang="de-CH"><head><title>Programm</title></head><body><main><h1>Programm</h1><h2>Veranstaltungen</h2>${html}</main>${scriptTag}</body></html>`, {
    url,
    runScripts: 'outside-only',
    pretendToBeVisual: true
  });
  const { window } = dom;
  const calls = [];
  window.fetch = fakeFetch(feed, calls);
  window.eval(widgetSource);
  await window.EnterOnceWidget.ready;
  return { window, document: window.document, calls, dom };
}

export const calendarHtml = (attrs = '') => `<div data-enteronce="calendar" data-feed="${FEED_URL}" ${attrs}></div>`;
export const highlightsHtml = (attrs = '') => `<div data-enteronce="highlights" data-feed="${FEED_URL}" ${attrs.includes('data-now') ? '' : 'data-now="2027-01-01T00:00:00+01:00"'} ${attrs}></div>`;

export function change(window, element, value) {
  element.value = value;
  element.dispatchEvent(new window.Event(element.tagName === 'INPUT' && element.type === 'search' ? 'input' : 'change', { bubbles: true }));
}

/** Runs axe on the page and returns the violations. color-contrast cannot be computed in jsdom. */
export async function axeViolations(window, context) {
  window.eval(axeSource);
  const result = await window.axe.run(context || window.document, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    rules: { 'color-contrast': { enabled: false } }
  });
  return result.violations;
}

export function describeViolations(violations) {
  return violations.map((v) => `${v.id}: ${v.help}\n` + v.nodes.map((n) => '  ' + n.html.slice(0, 160)).join('\n')).join('\n');
}
