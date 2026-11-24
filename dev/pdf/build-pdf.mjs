// Renders every guide in docs/de/*.md to dist/pdf/<name>.pdf (run through bin/build-pdf).
//
// Markdown -> HTML with «marked», HTML -> PDF with a Chromium browser in headless mode (Edge, Chrome
// or Chromium; set EO_BROWSER to the executable if none is found). Nothing is fetched from the
// network: raw HTML in a guide is printed as text, never interpreted, and the page forbids every
// outside resource. dist/pdf/quellen.sha256 records the hash of each source file, so that
// dev/wordpress/test-package can tell whether a PDF belongs to the current text.
//
// SPDX-License-Identifier: GPL-2.0-or-later

import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { marked } from 'marked';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const sourceDir = join(root, 'docs', 'de');
const outDir = join(root, 'dist', 'pdf');

function findBrowser() {
  const candidates = [
    process.env.EO_BROWSER,
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/usr/bin/microsoft-edge',
  ];
  return candidates.find((p) => p && existsSync(p));
}

const escapeHtml = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// Raw HTML in a guide is shown, not executed (a guide may quote a <script> line).
marked.use({ gfm: true, renderer: { html: (token) => escapeHtml(token.text ?? token.raw ?? '') } });

// A CSS string literal: backslash and double quote escaped, line breaks flattened.
const cssString = (s) => '"' + s.replace(/[\\"]/g, (c) => '\\' + c).replace(/\s+/g, ' ') + '"';

const css = (title) => `
@page { size: A4; margin: 20mm 18mm 22mm 18mm;
  @bottom-left { content: ${cssString(title)}; font: 8pt "Segoe UI", Arial, sans-serif; color: #555; }
  @bottom-right { content: counter(page) " / " counter(pages); font: 8pt "Segoe UI", Arial, sans-serif; color: #555; } }
html { font: 10.5pt/1.5 "Segoe UI", "Helvetica Neue", Arial, sans-serif; color: #111; }
body { margin: 0; overflow-wrap: break-word; }
h1 { font-size: 20pt; line-height: 1.2; margin: 0 0 10pt; }
h2 { font-size: 14pt; line-height: 1.25; margin: 20pt 0 6pt; padding-bottom: 3pt; border-bottom: 0.6pt solid #999; }
h3 { font-size: 11.5pt; line-height: 1.3; margin: 14pt 0 4pt; }
h4 { font-size: 10.5pt; margin: 12pt 0 4pt; }
h1, h2, h3, h4 { break-after: avoid; }
p, ul, ol, table, pre, blockquote { margin: 0 0 8pt; }
ul, ol { padding-left: 18pt; }
li { margin: 0 0 3pt; }
li > ul, li > ol { margin-top: 3pt; margin-bottom: 0; }
a { color: #0b4f8a; text-decoration: underline; overflow-wrap: anywhere; }
code { font: 9pt/1.4 Consolas, "Cascadia Mono", Menlo, monospace; background: #f1f1f1; padding: 0.5pt 2.5pt; border-radius: 2pt; overflow-wrap: break-word; }
pre { background: #f1f1f1; border: 0.6pt solid #c8c8c8; border-radius: 3pt; padding: 6pt 8pt; white-space: pre-wrap; overflow-wrap: anywhere; break-inside: avoid; }
pre code { background: none; padding: 0; }
table { border-collapse: collapse; width: 100%; font-size: 9.5pt; line-height: 1.4; }
th, td { border: 0.6pt solid #888; padding: 3pt 5pt; text-align: left; vertical-align: top; }
th { background: #e9e9e9; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
blockquote { border-left: 2.5pt solid #999; margin-left: 0; padding-left: 9pt; color: #333; }
hr { border: 0; border-top: 0.6pt solid #999; margin: 14pt 0; }
`;

function toHtml(markdown, title) {
  return `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'">
<title>${escapeHtml(title)}</title>
<style>${css(title)}</style>
</head>
<body>
${marked.parse(markdown)}
</body>
</html>
`;
}

const browser = findBrowser();
if (!browser) {
  console.error('No Chromium browser found (Edge, Chrome or Chromium). Set EO_BROWSER to its executable, or build the package without PDFs: EO_NO_PDF=1 bin/build-package');
  process.exit(1);
}

const guides = readdirSync(sourceDir).filter((f) => f.endsWith('.md')).sort();
if (guides.length === 0) {
  console.error(`no guides in ${sourceDir}`);
  process.exit(1);
}

rmSync(outDir, { recursive: true, force: true });
mkdirSync(outDir, { recursive: true });
const work = mkdtempSync(join(tmpdir(), 'eo-pdf-'));
const manifest = [];
let failed = false;

try {
  for (const guide of guides) {
    const name = guide.replace(/\.md$/, '');
    const bytes = readFileSync(join(sourceDir, guide));
    const markdown = bytes.toString('utf8');
    const title = (markdown.match(/^#\s+(.+)$/m)?.[1] ?? name).trim();
    const htmlPath = join(work, `${name}.html`);
    const pdfPath = join(outDir, `${name}.pdf`);
    writeFileSync(htmlPath, toHtml(markdown, title), 'utf8');

    const run = spawnSync(browser, [
      '--headless',
      '--disable-gpu',
      '--no-first-run',
      '--disable-extensions',
      '--disable-background-networking',
      '--disable-component-update',
      '--disable-sync',
      `--user-data-dir=${join(work, 'profile')}`,
      '--no-pdf-header-footer',
      '--generate-pdf-document-outline',
      `--print-to-pdf=${pdfPath}`,
      pathToFileURL(htmlPath).href,
    ], { timeout: 120000, encoding: 'utf8' });

    const ok = existsSync(pdfPath) && statSync(pdfPath).size > 2000 && readFileSync(pdfPath).subarray(0, 5).toString('latin1') === '%PDF-';
    if (!ok) {
      failed = true;
      console.error(`FAIL: ${name}.pdf was not written (exit ${run.status}${run.error ? ', ' + run.error.message : ''})`);
      if (run.stderr) console.error(run.stderr.trim().split('\n').slice(-5).join('\n'));
      continue;
    }
    manifest.push(`${createHash('sha256').update(bytes).digest('hex')}  ${guide}`);
    console.log(`${name}.pdf  ${Math.round(statSync(pdfPath).size / 1024)} KB`);
  }
} finally {
  rmSync(work, { recursive: true, force: true, maxRetries: 5, retryDelay: 300 });
}

if (failed) {
  process.exit(1);
}
writeFileSync(join(outDir, 'quellen.sha256'), manifest.join('\n') + '\n', 'utf8');
console.log(`Built ${manifest.length} PDFs in dist/pdf/`);
