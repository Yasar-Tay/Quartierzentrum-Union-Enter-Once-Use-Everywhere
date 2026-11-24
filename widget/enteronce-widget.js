/*!
 * Enter once, show everywhere: event widget for union-basel.ch
 * Part of the plugin "enter-once-sync". Licence: GPL-2.0-or-later.
 *
 * One plain script, no dependencies, no build step. It finds every
 * <div data-enteronce="calendar|highlights"> on the page, reads the public
 * event feed of the plugin and renders the events into that element.
 * Options are data attributes on the element; see widget/README.md.
 *
 * Everything the feed delivers is treated as untrusted: texts are written with
 * textContent, URLs are checked, and description_html is rebuilt from an
 * allow-list of harmless tags (see cleanHtml).
 */
(function () {
  'use strict';

  var LOCALE = 'de-CH';
  var TIME_ZONE = 'Europe/Zurich';
  var FEED_PATH = '/wp-json/enteronce/v1/events';
  var DEFAULT_FALLBACK = 'https://www.qtp-basel.ch/programm/';
  var TIMEOUT_MS = 10000;

  // The script's own address: the feed and the stylesheet are found relative to it.
  var scriptEl = document.currentScript || document.querySelector('script[src*="enteronce-widget"]');
  var scriptUrl = scriptEl && scriptEl.src ? scriptEl.src : '';

  var feedCache = {};
  var counter = 0;

  /* ---------------------------------------------------------------- texts */

  var T = {
    loading: 'Veranstaltungen werden geladen …',
    error: 'Die Veranstaltungen können im Moment nicht angezeigt werden.',
    errorLink: 'Zum aktuellen Programm',
    none: 'Zurzeit sind keine Veranstaltungen geplant.',
    noHighlights: 'Zurzeit sind keine Highlights geplant.',
    filterLabel: 'Veranstaltungen filtern',
    search: 'Suche',
    period: 'Zeitraum',
    from: 'Von',
    to: 'Bis',
    category: 'Veranstaltungsart',
    room: 'Veranstaltungsort',
    all: 'Alle',
    reset: 'Filter zurücksetzen',
    noMatch: 'Keine Veranstaltungen gefunden.',
    noMatchHelp: 'Ändern Sie die Suche oder setzen Sie die Filter zurück.',
    badRange: 'Das Enddatum liegt vor dem Startdatum.',
    cancelled: 'abgesagt',
    allDay: 'ganztägig',
    clock: ' Uhr',
    until: ' bis ',
    more: 'Mehr Informationen',
    address: 'Adresse: ',
    place: 'Ort: ',
    categories: 'Veranstaltungsart',
    tags: 'Schlagwörter'
  };

  function countText(n) {
    if (n === 0) return T.noMatch;
    return n === 1 ? '1 Veranstaltung gefunden.' : n + ' Veranstaltungen gefunden.';
  }

  /* ---------------------------------------------------------------- dates */

  function dtf(options) {
    options.timeZone = TIME_ZONE;
    return new Intl.DateTimeFormat(LOCALE, options);
  }
  var fParts = dtf({ year: 'numeric', month: '2-digit', day: '2-digit' });
  var fMonth = dtf({ month: 'long', year: 'numeric' });
  var fLong = dtf({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  var fShort = dtf({ weekday: 'short', day: 'numeric', month: 'short' });
  var fWeekday = dtf({ weekday: 'short' });
  var fDay = dtf({ day: 'numeric' });
  var fMonthShort = dtf({ month: 'short' });
  var fTime = dtf({ hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

  /** "2027-01-07": the calendar day in Zurich, used for grouping and for the date filter. */
  function dayKey(date) {
    var key = {};
    fParts.formatToParts(date).forEach(function (p) { key[p.type] = p.value; });
    return key.year + '-' + key.month + '-' + key.day;
  }

  /* ----------------------------------------------------------------- data */

  function str(value) {
    return typeof value === 'string' ? value.trim() : '';
  }

  function strList(value) {
    return Array.isArray(value) ? value.map(str).filter(Boolean) : [];
  }

  /** Returns the URL as text if its protocol is in the list, otherwise ''. */
  function safeUrl(value, protocols) {
    try {
      var url = new URL(str(value));
      return protocols.indexOf(url.protocol) >= 0 ? url.href : '';
    } catch (e) {
      return '';
    }
  }

  function plain(text) {
    return text.toLowerCase().replace(/ß/g, 'ss').normalize('NFD').replace(/[̀-ͯ]/g, '');
  }

  var DROP = /^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|TEMPLATE|NOSCRIPT|SVG|MATH|LINK|META|TITLE|FORM|INPUT|BUTTON|TEXTAREA|SELECT|IMG|VIDEO|AUDIO|CANVAS)$/;
  var KEEP = /^(P|BR|STRONG|B|EM|I|UL|OL|LI|BLOCKQUOTE)$/;

  /**
   * Rebuilds description_html from an allow-list. The input is parsed by
   * DOMParser, which neither runs scripts nor loads images; only freshly
   * created elements with no attributes (except a checked href) are copied.
   */
  function cleanInto(source, target) {
    Array.prototype.forEach.call(source.childNodes, function (node) {
      if (node.nodeType === 3) {
        target.appendChild(document.createTextNode(node.nodeValue));
        return;
      }
      if (node.nodeType !== 1) return;
      var tag = node.nodeName.toUpperCase();
      if (DROP.test(tag)) return;
      if (/^H[1-6]$/.test(tag)) {
        var p = document.createElement('p');
        var strong = document.createElement('strong');
        cleanInto(node, strong);
        p.appendChild(strong);
        target.appendChild(p);
      } else if (tag === 'A') {
        var href = safeUrl(node.getAttribute('href'), ['http:', 'https:', 'mailto:', 'tel:']);
        if (href) {
          var link = document.createElement('a');
          link.setAttribute('href', href);
          link.setAttribute('rel', 'noopener noreferrer');
          cleanInto(node, link);
          target.appendChild(link);
        } else {
          cleanInto(node, target); // no safe address: keep the words, lose the link
        }
      } else if (KEEP.test(tag)) {
        var copy = document.createElement(tag.toLowerCase());
        cleanInto(node, copy);
        target.appendChild(copy);
      } else {
        cleanInto(node, target); // unknown element: keep its text, lose the element
      }
    });
  }

  function cleanHtml(html) {
    var box = document.createElement('div');
    if (!html) return box;
    var parsed = new DOMParser().parseFromString('<!doctype html><body>' + html, 'text/html');
    cleanInto(parsed.body, box);
    return box;
  }

  /** Turns one feed entry into what the renderer needs, or null if it is unusable. */
  function normalise(raw) {
    if (!raw || typeof raw !== 'object') return null;
    var title = str(raw.title);
    var start = new Date(raw.start);
    if (!title || isNaN(start.getTime())) return null;
    var end = raw.end ? new Date(raw.end) : start;
    if (isNaN(end.getTime()) || end < start) end = start;
    // The last second of an event still belongs to its last day: an event that
    // ends at midnight, or an all-day entry with an exclusive end, stays on one day.
    var lastDay = end > start ? new Date(end.getTime() - 1000) : start;
    var image = null;
    if (raw.image && typeof raw.image === 'object' && safeUrl(raw.image.url, ['http:', 'https:'])) {
      image = { url: safeUrl(raw.image.url, ['http:', 'https:']), alt: str(raw.image.alt) };
    }
    var ev = {
      id: str(raw.id),
      title: title,
      start: start,
      end: end,
      startRaw: str(raw.start),
      allDay: raw.all_day === true,
      room: str(raw.room),
      location: str(raw.location),
      desc: cleanHtml(str(raw.description_html)),
      categories: strList(raw.categories),
      tags: strList(raw.tags),
      image: image,
      cancelled: raw.cancelled === true,
      startKey: dayKey(start),
      endKey: dayKey(lastDay),
      lastDay: lastDay
    };
    ev.hay = plain([ev.title, ev.room, ev.location, ev.categories.join(' '), ev.tags.join(' '), ev.desc.textContent].join(' '));
    return ev;
  }

  function loadFeed(url) {
    if (feedCache[url]) return feedCache[url];
    feedCache[url] = new Promise(function (resolve, reject) {
      if (typeof window.fetch !== 'function') return reject(new Error('no fetch'));
      var controller = typeof AbortController === 'function' ? new AbortController() : null;
      var timer = setTimeout(function () {
        if (controller) controller.abort();
        reject(new Error('timeout'));
      }, TIMEOUT_MS);
      window.fetch(url, { credentials: 'omit', signal: controller ? controller.signal : undefined })
        .then(function (res) {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.json();
        })
        .then(function (data) {
          clearTimeout(timer);
          if (!Array.isArray(data)) throw new Error('not a list');
          var events = data.map(normalise).filter(Boolean);
          if (data.length > 0 && events.length === 0) throw new Error('no usable entry');
          events.sort(function (a, b) { return a.start - b.start || (a.title < b.title ? -1 : 1); });
          resolve(events);
        })
        .catch(function (err) {
          clearTimeout(timer);
          reject(err);
        });
    });
    return feedCache[url];
  }

  /** The feed lives on the same site as this script; WordPress may sit in a sub-folder. */
  function defaultFeed() {
    if (!scriptUrl) return location.origin + FEED_PATH;
    var url = new URL(scriptUrl);
    var cut = url.pathname.indexOf('/wp-content/');
    return url.origin + (cut > 0 ? url.pathname.slice(0, cut) : '') + FEED_PATH;
  }

  /* ------------------------------------------------------------- building */

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function list(items, className, label) {
    var ul = el('ul', className);
    ul.setAttribute('aria-label', label);
    items.forEach(function (item) { ul.appendChild(el('li', '', item)); });
    return ul;
  }

  function placeholder() {
    var box = el('div', 'eo-placeholder');
    box.setAttribute('aria-hidden', 'true');
    return box;
  }

  function media(ev) {
    var box = el('div', 'eo-media');
    if (ev.image) {
      var img = document.createElement('img');
      img.addEventListener('error', function () { img.replaceWith(placeholder()); });
      // The alt text is dropped when it only repeats the title the reader hears next.
      var alt = plain(ev.image.alt) === plain(ev.title) ? '' : ev.image.alt;
      img.setAttribute('alt', alt);
      img.setAttribute('loading', 'lazy');
      img.setAttribute('decoding', 'async');
      img.src = ev.image.url;
      box.appendChild(img);
    } else {
      box.appendChild(placeholder());
    }
    var badge = el('div', 'eo-badge');
    badge.setAttribute('aria-hidden', 'true');
    badge.appendChild(el('span', 'eo-badge-weekday', fWeekday.format(ev.start)));
    badge.appendChild(el('span', 'eo-badge-day', fDay.format(ev.start)));
    badge.appendChild(el('span', 'eo-badge-month', fMonthShort.format(ev.start)));
    box.appendChild(badge);
    return box;
  }

  function timePart(ev) {
    if (ev.allDay) return T.allDay;
    var from = fTime.format(ev.start);
    var to = fTime.format(ev.end);
    return ev.end > ev.start && ev.startKey === ev.endKey ? from + '–' + to + T.clock : from + T.clock;
  }

  /** The line with date and time. Screen readers always get the full date. */
  function whenLine(ev, showDate) {
    var p = el('p', 'eo-when');
    var time = document.createElement('time');
    time.setAttribute('datetime', ev.startRaw);
    if (ev.startKey === ev.endKey) {
      if (!showDate) p.appendChild(el('span', 'eo-vh', fLong.format(ev.start) + ', '));
      time.textContent = (showDate ? fShort.format(ev.start) + ', ' : '') + timePart(ev);
    } else {
      var first = fShort.format(ev.start) + (ev.allDay ? '' : ', ' + fTime.format(ev.start) + T.clock);
      var last = fShort.format(ev.lastDay) + (ev.allDay ? '' : ', ' + fTime.format(ev.end) + T.clock);
      time.textContent = (ev.allDay ? T.allDay + ', ' : '') + first + T.until + last;
    }
    p.appendChild(time);
    return p;
  }

  function card(ev, headingLevel, showDate, idPrefix, index) {
    var li = el('li', 'eo-item');
    var article = el('article', 'eo-card' + (ev.cancelled ? ' eo-cancelled' : ''));
    var titleId = idPrefix + '-t' + index;
    article.setAttribute('aria-labelledby', titleId);
    article.appendChild(media(ev));

    var body = el('div', 'eo-body');
    var heading = el('h' + headingLevel, 'eo-title');
    heading.id = titleId;
    if (ev.cancelled) {
      heading.appendChild(el('span', 'eo-cancel-label', T.cancelled));
      heading.appendChild(document.createTextNode(' '));
      heading.appendChild(el('s', '', ev.title));
    } else {
      heading.textContent = ev.title;
    }
    body.appendChild(heading);
    body.appendChild(whenLine(ev, showDate));
    if (ev.room) {
      var room = el('p', 'eo-room');
      room.appendChild(el('span', 'eo-vh', T.place));
      room.appendChild(document.createTextNode(ev.room));
      body.appendChild(room);
    }
    if (ev.categories.length) body.appendChild(list(ev.categories, 'eo-labels eo-cats', T.categories));
    if (ev.tags.length) body.appendChild(list(ev.tags, 'eo-labels eo-tags', T.tags));

    var showLocation = ev.location && ev.location !== ev.room;
    if (ev.desc.textContent.trim() || showLocation) {
      var details = el('details', 'eo-more');
      details.appendChild(el('summary', '', T.more));
      var content = el('div', 'eo-desc');
      content.appendChild(ev.desc.cloneNode(true));
      if (showLocation) {
        var address = el('p', 'eo-address');
        address.appendChild(el('strong', '', T.address));
        address.appendChild(document.createTextNode(ev.location));
        content.appendChild(address);
      }
      details.appendChild(content);
      body.appendChild(details);
    }
    article.appendChild(body);
    li.appendChild(article);
    return li;
  }

  function field(id, labelText, control) {
    var wrap = el('div', 'eo-field');
    var label = el('label', '', labelText);
    label.setAttribute('for', id);
    wrap.appendChild(label);
    wrap.appendChild(control);
    return wrap;
  }

  function select(id, options) {
    var s = el('select', 'eo-control');
    s.id = id;
    var all = el('option', '', T.all);
    all.value = '';
    s.appendChild(all);
    options.forEach(function (o) {
      var opt = el('option', '', o);
      opt.value = o;
      s.appendChild(opt);
    });
    return s;
  }

  function unique(values) {
    var seen = {};
    return values.filter(function (v) { return v && !seen[v] && (seen[v] = true); })
      .sort(function (a, b) { return a.localeCompare(b, LOCALE); });
  }

  function intOption(host, name, fallback) {
    var n = parseInt(host.getAttribute(name), 10);
    return n >= 0 ? n : fallback;
  }

  function headingLevel(host) {
    var n = parseInt(host.getAttribute('data-heading-level'), 10);
    return n >= 1 && n <= 5 ? n : 3;
  }

  function viewClass(host, fallback) {
    return 'eo-view-' + (host.getAttribute('data-view') === 'grid' ? 'grid' : host.getAttribute('data-view') === 'list' ? 'list' : fallback);
  }

  /* ---------------------------------------------------------------- views */

  function renderCalendar(host, events, id) {
    var base = headingLevel(host);
    var limit = intOption(host, 'data-limit', 0);
    host.className += ' eo-calendar ' + viewClass(host, 'list');
    host.textContent = '';

    if (!events.length) {
      host.appendChild(el('p', 'eo-message', T.none));
      return;
    }

    var form = el('form', 'eo-filters');
    form.setAttribute('role', 'search');
    form.setAttribute('aria-label', T.filterLabel);
    var search = el('input', 'eo-control');
    search.type = 'search';
    search.id = id + '-q';
    search.setAttribute('autocomplete', 'off');
    var from = el('input', 'eo-control');
    from.type = 'date';
    from.id = id + '-from';
    var to = el('input', 'eo-control');
    to.type = 'date';
    to.id = id + '-to';
    var category = select(id + '-cat', unique([].concat.apply([], events.map(function (e) { return e.categories; }))));
    var room = select(id + '-room', unique(events.map(function (e) { return e.room; })));
    var reset = el('button', 'eo-button', T.reset);
    reset.type = 'button';

    form.appendChild(field(search.id, T.search, search));
    var period = el('fieldset', 'eo-period');
    period.appendChild(el('legend', '', T.period));
    period.appendChild(field(from.id, T.from, from));
    period.appendChild(field(to.id, T.to, to));
    form.appendChild(period);
    form.appendChild(field(category.id, T.category, category));
    form.appendChild(field(room.id, T.room, room));
    form.appendChild(reset);

    var status = el('p', 'eo-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    var results = el('div', 'eo-results');

    function matches(ev, f) {
      if (f.words.some(function (w) { return ev.hay.indexOf(w) < 0; })) return false;
      if (f.from && ev.endKey < f.from) return false;
      if (f.to && ev.startKey > f.to) return false;
      if (f.category && ev.categories.indexOf(f.category) < 0) return false;
      if (f.room && ev.room !== f.room) return false;
      return true;
    }

    // Draws the results; returns the sentence for the live region.
    function draw() {
      var f = {
        words: plain(search.value).split(/\s+/).filter(Boolean),
        from: from.value,
        to: to.value,
        category: category.value,
        room: room.value
      };
      to.removeAttribute('aria-invalid');
      results.textContent = '';
      if (f.from && f.to && f.to < f.from) {
        to.setAttribute('aria-invalid', 'true');
        return T.badRange;
      }
      var shown = events.filter(function (ev) { return matches(ev, f); });
      var total = shown.length;
      if (limit) shown = shown.slice(0, limit);
      var monthList = null;
      var lastMonth = '';
      shown.forEach(function (ev, i) {
        var month = fMonth.format(ev.start);
        if (month !== lastMonth) {
          lastMonth = month;
          var section = el('section', 'eo-month');
          var h = el('h' + base, 'eo-month-title', month);
          h.id = id + '-m' + i;
          section.setAttribute('aria-labelledby', h.id);
          section.appendChild(h);
          monthList = el('ul', 'eo-list');
          section.appendChild(monthList);
          results.appendChild(section);
        }
        monthList.appendChild(card(ev, Math.min(base + 1, 6), false, id, i));
      });
      if (!shown.length) {
        var empty = el('div', 'eo-message');
        empty.appendChild(el('p', '', T.noMatch));
        empty.appendChild(el('p', '', T.noMatchHelp));
        results.appendChild(empty);
      }
      return countText(total);
    }

    // The sentence is written before the region is attached, so it is not read out on page load.
    status.textContent = draw();
    host.appendChild(form);
    host.appendChild(status);
    host.appendChild(results);

    var timer = 0;
    // Any other change announces at once and cancels a pending announcement from typing.
    function update() {
      clearTimeout(timer);
      status.textContent = draw();
    }
    // While typing, the list changes at once but the announcement waits for a pause.
    search.addEventListener('input', function () {
      var text = draw();
      clearTimeout(timer);
      timer = setTimeout(function () { status.textContent = text; }, 400);
    });
    [from, to, category, room].forEach(function (c) { c.addEventListener('change', update); });
    form.addEventListener('submit', function (e) { e.preventDefault(); });
    reset.addEventListener('click', function () {
      form.reset();
      update();
      search.focus();
    });
  }

  function renderHighlights(host, events, id) {
    var tag = plain(str(host.getAttribute('data-tag')) || 'Highlight');
    var limit = intOption(host, 'data-limit', 6) || 6;
    var now = host.getAttribute('data-now') ? new Date(host.getAttribute('data-now')) : new Date();
    var picked = events.filter(function (ev) {
      return ev.end >= now && ev.tags.some(function (t) { return plain(t) === tag; });
    }).slice(0, limit);
    host.className += ' eo-highlights ' + viewClass(host, 'grid');
    host.textContent = '';
    if (!picked.length) {
      host.appendChild(el('p', 'eo-message', T.noHighlights));
      return;
    }
    var ul = el('ul', 'eo-list');
    picked.forEach(function (ev, i) { ul.appendChild(card(ev, headingLevel(host), true, id, i)); });
    host.appendChild(ul);
  }

  function renderError(host) {
    var link = safeUrl(host.getAttribute('data-fallback'), ['http:', 'https:']) || DEFAULT_FALLBACK;
    host.textContent = '';
    var box = el('div', 'eo-message eo-error');
    box.setAttribute('role', 'status');
    box.appendChild(el('p', '', T.error));
    var p = el('p');
    var a = el('a', '', T.errorLink);
    a.href = link;
    p.appendChild(a);
    box.appendChild(p);
    host.appendChild(box);
  }

  /* ----------------------------------------------------------------- init */

  function start(host) {
    var kind = host.getAttribute('data-enteronce');
    if (kind !== 'calendar' && kind !== 'highlights') return Promise.resolve();
    if (host.hasAttribute('data-eo-started')) return Promise.resolve();
    host.setAttribute('data-eo-started', '');
    host.classList.add('eo-widget');
    host.setAttribute('aria-busy', 'true');
    var loading = el('p', 'eo-message', T.loading);
    loading.setAttribute('role', 'status');
    host.textContent = '';
    host.appendChild(loading);

    var url;
    try {
      url = new URL(host.getAttribute('data-feed') || defaultFeed(), location.href);
      if (host.getAttribute('data-source')) url.searchParams.set('source', host.getAttribute('data-source'));
    } catch (e) {
      renderError(host);
      host.removeAttribute('aria-busy');
      return Promise.resolve();
    }
    return loadFeed(url.href).then(function (events) {
      var id = 'eo' + (++counter);
      if (kind === 'calendar') renderCalendar(host, events, id);
      else renderHighlights(host, events, id);
    }).catch(function () {
      renderError(host);
    }).then(function () {
      host.removeAttribute('aria-busy');
    });
  }

  function addStylesheet() {
    if (!scriptUrl || document.querySelector('link[href*="enteronce-widget"]')) return;
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = scriptUrl.replace(/\.js(\?.*)?$/, '.css$1');
    document.head.appendChild(link);
  }

  function init(root) {
    var hosts = (root || document).querySelectorAll('[data-enteronce]');
    return Promise.all(Array.prototype.map.call(hosts, start));
  }

  addStylesheet();
  var api = { init: init, ready: null };
  window.EnterOnceWidget = api;
  if (document.readyState === 'loading') {
    api.ready = new Promise(function (resolve) {
      document.addEventListener('DOMContentLoaded', function () { init().then(resolve); });
    });
  } else {
    api.ready = init();
  }
})();
