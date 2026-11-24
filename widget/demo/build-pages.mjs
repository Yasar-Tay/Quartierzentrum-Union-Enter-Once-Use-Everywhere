#!/usr/bin/env node
/*
 * Makes the two demo pages from the static copy of union-basel.ch.
 * Reads ../../webflow-static (never writes there), replaces the two Elfsight event
 * widgets with the embed snippets from plan section 4.8, writes into demo/.
 * The cookie-banner widget (92219bf9-…) is left alone.
 *
 *   node demo/build-pages.mjs
 */
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const staticRoot = path.resolve(here, '..', '..', '..', 'webflow-static');

// The plan's snippets, with the script served from the same path the plugin uses.
const SCRIPT = '<script src="/wp-content/plugins/enter-once-sync/widget/enteronce-widget.js" defer></script>';
const CALENDAR = `<!-- Enter once widget | Veranstaltungskalender -->
<div data-enteronce="calendar" data-source="union"></div>
${SCRIPT}`;
const HIGHLIGHTS = `<!-- Enter once widget | Highlights -->
<div data-enteronce="highlights" data-source="union" data-tag="Highlight" data-limit="6"></div>
${SCRIPT}`;

const jobs = [
  { page: 'quartierzentrum-union/veranstaltungskalender/index.html', id: '2a073e75-23d2-483b-bda1-e1b586f02e87', snippet: CALENDAR },
  { page: 'index.html', id: 'cda7408f-ee59-4057-a193-684d3baa2788', snippet: HIGHLIGHTS }
];

for (const job of jobs) {
  const html = await readFile(path.join(staticRoot, job.page), 'utf8');
  // From the comment above the Elfsight script to the end of the app's <div>.
  const pattern = new RegExp(
    `<!-- Elfsight Event Calendar[^>]*-->\\s*<script src="https://elfsightcdn\\.com/platform\\.js" async></script>\\s*` +
    `<div class="elfsight-app-${job.id}" data-elfsight-app-lazy></div>`
  );
  if (!pattern.test(html)) throw new Error(`Elfsight block ${job.id} not found in ${job.page}`);
  const out = html.replace(pattern, () => job.snippet);
  if (out.includes(job.id)) throw new Error(`${job.id} is still present in ${job.page}`);
  if (!out.includes('92219bf9-a181-4263-a9d4-ae959cdf5d33')) throw new Error('cookie banner widget was lost');
  const target = path.join(here, job.page);
  await mkdir(path.dirname(target), { recursive: true });
  await writeFile(target, out);
  console.log('written', path.relative(process.cwd(), target));
}
