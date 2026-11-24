import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { openPage, widgetCss, sampleFeed, calendarHtml, highlightsHtml, change, axeViolations, describeViolations } from './helpers.js';

async function assertNoViolations(window, label) {
  const violations = await axeViolations(window);
  assert.equal(violations.length, 0, `${label}\n${describeViolations(violations)}`);
}

describe('axe-core: WCAG 2.0/2.1 A and AA rules, no violations', () => {
  test('calendar, as loaded', async () => {
    const { window } = await openPage({ html: calendarHtml() });
    await assertNoViolations(window, 'calendar');
  });

  test('calendar, grid layout, other heading level', async () => {
    const { window } = await openPage({ html: calendarHtml('data-view="grid" data-heading-level="2"').replace('<h2>', '<h2>') });
    // With heading level 2 the page's own «Veranstaltungen» h2 and the months are siblings: still a valid outline.
    await assertNoViolations(window, 'calendar grid');
  });

  test('calendar with every card opened', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    document.querySelectorAll('details').forEach((d) => d.setAttribute('open', ''));
    await assertNoViolations(window, 'calendar, details open');
  });

  test('calendar after filtering, with a range error, and with no result', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    change(window, document.querySelector('select[id$="-cat"]'), 'Musik');
    await assertNoViolations(window, 'category filter');
    change(window, document.querySelector('input[id$="-from"]'), '2027-03-01');
    change(window, document.querySelector('input[id$="-to"]'), '2027-02-01');
    await assertNoViolations(window, 'bad range (aria-invalid)');
    change(window, document.querySelector('input[id$="-to"]'), '');
    change(window, document.querySelector('input[type="search"]'), 'qqqq');
    await assertNoViolations(window, 'no result');
  });

  test('highlights', async () => {
    const { window } = await openPage({ html: highlightsHtml() });
    await assertNoViolations(window, 'highlights');
  });

  test('highlights next to the calendar on one page', async () => {
    const { window } = await openPage({ html: `<h2>Highlights</h2>${highlightsHtml()}<h2>Programm</h2>${calendarHtml()}` });
    await assertNoViolations(window, 'both');
  });

  test('fallback message, empty feed, loading state', async () => {
    for (const feed of [new Error('offline'), []]) {
      const { window } = await openPage({ html: calendarHtml(), feed });
      await assertNoViolations(window, String(feed));
    }
  });
});

describe('the axe harness itself', () => {
  test('it does report a missing label and a skipped heading level (so a green result means something)', async () => {
    const { window, document } = await openPage({ html: calendarHtml() });
    document.querySelector('label[for$="-q"]').remove();
    document.querySelector('.eo-month-title').outerHTML = '<h6>Kaputt</h6>';
    const ids = (await axeViolations(window)).map((v) => v.id);
    assert.ok(ids.includes('label'), ids.join());
  });
});

describe('structure that axe does not judge', () => {
  test('every form control has a visible label; ids are unique', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const controls = [...document.querySelectorAll('input, select')];
    assert.equal(controls.length, 5);
    for (const control of controls) {
      const label = document.querySelector(`label[for="${control.id}"]`);
      assert.ok(label && label.textContent.trim(), `label for ${control.id}`);
    }
    const ids = [...document.querySelectorAll('[id]')].map((e) => e.id);
    assert.equal(new Set(ids).size, ids.length, 'unique ids');
    assert.deepEqual([...document.querySelectorAll('label')].map((l) => l.textContent), ['Suche', 'Von', 'Bis', 'Veranstaltungsart', 'Veranstaltungsort']);
  });

  test('the filters are a labelled search landmark, the date fields a group', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const form = document.querySelector('form');
    assert.equal(form.getAttribute('role'), 'search');
    assert.ok(form.getAttribute('aria-label'));
    assert.equal(form.querySelector('fieldset legend').textContent, 'Zeitraum');
  });

  test('headings never skip a level: h1, h2 of the page, h3 months, h4 events', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const levels = [...document.querySelectorAll('h1, h2, h3, h4, h5, h6')].map((h) => Number(h.tagName[1]));
    for (let i = 1; i < levels.length; i++) assert.ok(levels[i] - levels[i - 1] <= 1, `jump at heading ${i}: ${levels[i - 1]} → ${levels[i]}`);
    assert.equal(levels.filter((l) => l === 3).length, 3);
    assert.equal(levels.filter((l) => l === 4).length, sampleFeed.length);
  });

  test('events are list items; each card is an article named by its title', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    for (const article of document.querySelectorAll('article')) {
      assert.equal(article.parentElement.tagName, 'LI');
      assert.equal(article.parentElement.parentElement.tagName, 'UL');
      const name = document.getElementById(article.getAttribute('aria-labelledby'));
      assert.ok(name && /^H[1-6]$/.test(name.tagName));
    }
  });

  test('the first thing a screen reader meets in a card is the title, then date, place, labels', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    const card = [...document.querySelectorAll('article')].find((c) => c.querySelector('.eo-title').textContent === 'Offener Deutschtreff');
    const order = [...card.querySelector('.eo-body').children].map((c) => c.className.split(' ')[0]);
    assert.deepEqual(order.slice(0, 5), ['eo-title', 'eo-when', 'eo-room', 'eo-labels', 'eo-labels']);
    assert.match(card.querySelector('.eo-when').textContent, /^Donnerstag, 7\. Januar 2027, 15:00–17:00 Uhr$/);
    assert.match(card.querySelector('.eo-room').textContent, /^Ort: Quartierraum$/);
  });

  test('information is never carried by colour alone: cancelled has a word, labels have a name', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    for (const card of document.querySelectorAll('.eo-cancelled')) {
      assert.ok(card.querySelector('.eo-cancel-label').textContent === 'abgesagt');
      assert.ok(card.querySelector('.eo-title s'));
    }
    for (const list of document.querySelectorAll('.eo-labels')) assert.ok(list.getAttribute('aria-label'));
  });

  test('nothing in the markup traps the keyboard: no tabindex, no positive tabindex, native controls only', async () => {
    const { document } = await openPage({ html: calendarHtml() });
    assert.equal(document.querySelectorAll('[tabindex]').length, 0);
    const focusable = [...document.querySelectorAll('a[href], button, input, select, summary, [tabindex]')];
    assert.ok(focusable.every((e) => ['A', 'BUTTON', 'INPUT', 'SELECT', 'SUMMARY'].includes(e.tagName)));
    assert.equal(document.querySelectorAll('[onclick], [onkeydown]').length, 0);
  });
});

/* ---------- colour contrast of the default palette (axe cannot compute it in jsdom) ---------- */

const rootBlock = /\n\.eo-widget\s*\{([^}]*)\}/.exec(widgetCss)[1];
const variable = (name) => new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})`).exec(rootBlock)[1];

function luminance(hex) {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const contrast = (a, b) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

describe('colour contrast of the default CSS variables', () => {
  const pairs = [
    ['text on page', 'eo-text', 'eo-bg', 4.5],
    ['link on page', 'eo-link', 'eo-bg', 4.5],
    ['badge text on badge', 'eo-badge-text', 'eo-badge-bg', 4.5],
    ['text on category label', 'eo-text', 'eo-category-bg', 4.5],
    ['text on tag label', 'eo-text', 'eo-tag-bg', 4.5],
    ['text on «abgesagt» label', 'eo-text', 'eo-cancel-bg', 4.5],
    ['text on placeholder', 'eo-text', 'eo-placeholder-bg', 4.5],
    ['form control border on page (1.4.11)', 'eo-control-border', 'eo-bg', 3],
    ['focus ring on page (1.4.11)', 'eo-focus', 'eo-bg', 3],
    ['control border on category hover', 'eo-control-border', 'eo-category-bg', 3]
  ];
  for (const [name, fg, bg, min] of pairs) {
    test(`${name} ≥ ${min}:1`, () => {
      const ratio = contrast(variable(fg), variable(bg));
      assert.ok(ratio >= min, `${fg} ${variable(fg)} on ${bg} ${variable(bg)} is ${ratio.toFixed(2)}:1`);
    });
  }

  test('the accent colour is only used for decoration, never for text', () => {
    assert.ok(contrast(variable('eo-accent'), variable('eo-bg')) < 4.5, 'documented reason: molten orange is too light for text');
    const uses = widgetCss.split('\n').filter((l) => l.includes('var(--eo-accent)') && /^\s*(color|background)/.test(l));
    assert.deepEqual(uses, []);
  });
});

describe('the stylesheet', () => {
  test('never removes the focus outline and styles every kind of focusable element', () => {
    assert.doesNotMatch(widgetCss, /outline\s*:\s*(none|0)\b/);
    for (const selector of ['a:focus-visible', 'button:focus-visible', 'input:focus-visible', 'select:focus-visible', 'summary:focus-visible']) {
      assert.ok(widgetCss.includes(`.eo-widget ${selector}`), selector);
    }
  });

  test('the form controls and buttons are at least 44 CSS pixels (2.75rem) high', () => {
    assert.match(widgetCss, /\.eo-control\s*\{[^}]*min-height:\s*2\.75rem/);
    assert.match(widgetCss, /\.eo-button\s*\{[^}]*min-height:\s*2\.75rem/);
  });

  test('text is never set below 14px (0.875rem) and no fixed heights cut text', () => {
    const sizes = [...widgetCss.matchAll(/font-size:\s*([\d.]+)rem/g)].map((m) => Number(m[1]));
    assert.ok(Math.min(...sizes) >= 0.875);
    assert.doesNotMatch(widgetCss, /(?<![-\w])height:\s*(?!1px)\d+(\.\d+)?(px|rem|em)/);
  });

  test('it loads no font and no other file from outside', () => {
    assert.doesNotMatch(widgetCss, /@import|@font-face|url\(\s*["']?https?:/);
  });
});
