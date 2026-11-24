<?php

/**
 * Builds dist/enter-once-uebergabe.zip, the handover package (run through bin/build-package,
 * inside Docker, project at /app). Needs dist/enter-once-sync.zip from bin/build-zip.
 *
 *   enter-once-sync.zip   the installable plugin, byte for byte the file bin/build-zip built
 *   anleitungen/          the German guides of docs/de/, each also as a PDF from dist/pdf/ (built by
 *                         bin/build-pdf; left out with the argument --no-pdf)
 *   abgleichsbericht/     the drift report, runnable with nothing but PHP 8.1: bin/enteronce-drift
 *                         (unchanged), a wrapper «enteronce-drift», src/Core, src/Drift, sabre/* and
 *                         an autoloader, LIESMICH.txt
 *   quellcode/            the full source without vendor/, node_modules/, dist/, caches, and without the
 *                         copies of pages of union-basel.ch that the widget demo generates (the package
 *                         may be published; that content belongs to Union)
 *   LIESMICH.txt          what is where
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

$root = '/app';
$stage = sys_get_temp_dir() . '/eo-package';
$zipPath = $root . '/dist/enter-once-uebergabe.zip';

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** @param list<string> $skip fnmatch patterns on the relative path (dirs skip their contents) */
function copyTree(string $from, string $to, array $skip = []): int
{
    $count = 0;
    $dirIt = new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($dirIt, static function (SplFileInfo $f) use ($from, $skip): bool {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($from) + 1));
        foreach ($skip as $pattern) {
            if (fnmatch($pattern, $rel) || fnmatch($pattern, $f->getFilename())) {
                return false;
            }
        }

        return true;
    });
    foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST) as $f) {
        $dest = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
        if ($f->isDir()) {
            @mkdir($dest, 0775, true);
        } else {
            @mkdir(dirname($dest), 0775, true);
            copy($f->getPathname(), $dest);
            ++$count;
        }
    }

    return $count;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

if (!is_file($root . '/dist/enter-once-sync.zip')) {
    fail('dist/enter-once-sync.zip missing: run bin/build-zip first');
}
rrmdir($stage);
mkdir($stage, 0775, true);

// 1. The plugin, unchanged.
copy($root . '/dist/enter-once-sync.zip', $stage . '/enter-once-sync.zip');

// 2. The guides.
$guides = glob($root . '/docs/de/*.md') ?: [];
if (count($guides) !== 8) {
    fail('expected 8 guides in docs/de, found ' . count($guides));
}
mkdir($stage . '/anleitungen');
foreach ($guides as $g) {
    copy($g, $stage . '/anleitungen/' . basename($g));
}

// 2a. The same guides as PDF. dist/pdf/quellen.sha256 says which text each PDF was made from; a PDF
// of an older text is refused, so the two formats in the package never disagree.
$withPdf = !in_array('--no-pdf', $argv, true);
if ($withPdf) {
    $manifest = [];
    foreach (@file($root . '/dist/pdf/quellen.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$hash, $name] = preg_split('/\s+/', trim($line), 2) + [1 => ''];
        $manifest[$name] = $hash;
    }
    foreach ($guides as $g) {
        $pdf = $root . '/dist/pdf/' . basename($g, '.md') . '.pdf';
        if (!is_file($pdf) || ($manifest[basename($g)] ?? '') !== hash_file('sha256', $g)) {
            fail('dist/pdf/' . basename($pdf) . ' is missing or older than docs/de/' . basename($g) . ': run bin/build-pdf (or build without PDFs: EO_NO_PDF=1 bin/build-package)');
        }
        copy($pdf, $stage . '/anleitungen/' . basename($pdf));
    }
}

// 3. The drift report, self-contained. Its source files are copied, never changed.
$drift = $stage . '/abgleichsbericht';
mkdir($drift . '/bin', 0775, true);
copy($root . '/bin/enteronce-drift', $drift . '/bin/enteronce-drift');
copyTree($root . '/src/Core', $drift . '/src/Core');
copyTree($root . '/src/Drift', $drift . '/src/Drift');
foreach (['vobject', 'xml', 'uri'] as $pkg) {
    copyTree($root . '/vendor/sabre/' . $pkg, $drift . '/vendor/sabre/' . $pkg, ['bin', 'tests', '.github', '*.md', 'phpunit*', '.php-cs-fixer*', 'phpstan*', 'composer.json']);
}
file_put_contents($drift . '/vendor/autoload.php', <<<'PHP'
<?php
/** Autoloader of the drift report package (no Composer needed). Generated by bin/build-package. */
spl_autoload_register(static function (string $class): void {
    static $map = [
        'Sabre\\VObject\\' => '/sabre/vobject/lib/',
        'Sabre\\Xml\\' => '/sabre/xml/lib/',
        'Sabre\\Uri\\' => '/sabre/uri/lib/',
        'EnterOnce\\' => '/../src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
require_once __DIR__ . '/sabre/uri/lib/functions.php';
require_once __DIR__ . '/sabre/xml/lib/Deserializer/functions.php';
require_once __DIR__ . '/sabre/xml/lib/Serializer/functions.php';
PHP);
file_put_contents($drift . '/enteronce-drift', <<<'PHP'
#!/usr/bin/env php
<?php
// Kurzer Aufruf des Abgleichsberichts: php enteronce-drift <kalender> <location_cat> <bericht.html> [optionen]
// Startet bin/enteronce-drift unverändert (dort steht die vollständige Beschreibung, auch mit --help).
require __DIR__ . '/bin/enteronce-drift';
PHP);
copy($root . '/LICENSE', $drift . '/LICENSE');
file_put_contents($drift . '/LIESMICH.txt', <<<'TXT'
Abgleichsbericht (Enter Once)
=============================

Der Abgleichsbericht vergleicht den Kalender des Quartierzentrums mit der Programmseite auf
qtp-basel.ch und schreibt eine HTML-Datei. Er verändert nichts, weder im Kalender noch auf der
Website. Was er tut und wie man den Bericht liest: ../anleitungen/Abgleichsbericht.md

Wichtig: Der erste echte Abruf der Programmseite erst, nachdem der Verband informiert ist (R-42).
Der Befehl ruft die Seite höchstens einmal pro Stunde ab und nach einer Ablehnung (403 oder 429)
an diesem Tag nicht mehr. Die Datei enteronce-drift.state.json neben dem Bericht merkt sich das;
verwenden Sie deshalb immer denselben Ausgabeordner.

Die geheime Kalender-Adresse nie in die Befehlszeile schreiben, sondern in eine Umgebungsvariable,
hier UNION_ICS, und «env:UNION_ICS» angeben. 52 ist die Nummer von Union auf qtp-basel.ch.

Weg 1: PHP ist installiert (PHP 8.1 oder neuer, Befehl «php» im Terminal)
-------------------------------------------------------------------------
Im Terminal in diesen Ordner wechseln, dann:

  php enteronce-drift env:UNION_ICS 52 bericht.html

Hilfe zu allen Optionen:  php enteronce-drift --help

Weg 2: Docker ist installiert (offizielles Abbild php:8.1-cli), eine Zeile in diesem Ordner
------------------------------------------------------------------------------------------
Windows (PowerShell):

  docker run --rm -e UNION_ICS -v "${PWD}:/bericht" -w /bericht php:8.1-cli php enteronce-drift env:UNION_ICS 52 bericht.html

macOS oder Linux (Terminal):

  docker run --rm -e UNION_ICS -v "$PWD:/bericht" -w /bericht php:8.1-cli php enteronce-drift env:UNION_ICS 52 bericht.html

Die Umgebungsvariable vorher setzen: in PowerShell  $env:UNION_ICS = "https://…"  (Adresse einfügen),
unter macOS oder Linux  export UNION_ICS="https://…"

Rückgabewerte: 0 Bericht geschrieben, 1 falscher Aufruf, 2 abgebrochen (die Meldung nennt den Grund).
Lizenz: GPL-2.0-or-later (Datei LICENSE). Mitgeliefert: sabre/vobject, sabre/xml, sabre/uri (BSD-3-Clause).
TXT);

// 4. The source.
$sourceFiles = copyTree($root, $stage . '/quellcode', [
    'vendor', 'node_modules', 'dist', '.phpunit.cache', '.phpunit.result.cache', '*.cache', 'build',
    '.git', '.idea', '.vscode', '.env', '.env.*', '*.log', '.DS_Store', 'Thumbs.db', '*.state.json', '*.stackdump',
    // Generated from a copy of union-basel.ch; not ours to pass on (see .gitignore).
    'widget/demo/index.html', 'widget/demo/quartierzentrum-union', 'widget/tests/edge-output',
]);

// 5. What is where.
file_put_contents($stage . '/LIESMICH.txt', strtr(<<<'TXT'
Enter Once – Übergabe an das Quartierzentrum Union und den Verband Quartiertreffpunkte Basel
Zuerst lesen: anleitungen/Begleitbrief.{ext} (was dieses Paket ist und wer was tut).
enter-once-sync.zip: das WordPress-Plugin für qtp-basel.ch, in WordPress unter «Plugins → Installieren → Plugin hochladen».
anleitungen/: die Anleitungen auf Deutsch{formats}; für die Installation anleitungen/Installationsanleitung.{ext}.
abgleichsbericht/: der Abgleichsbericht als eigenes Werkzeug, läuft mit PHP 8.1 oder Docker; siehe abgleichsbericht/LIESMICH.txt.
quellcode/: der vollständige Quellcode zum Prüfen, Ändern und Neubauen; Einstieg quellcode/docs/dev/wordpress.md.
Die Kalender-Adresse (ICS) ist in keiner Datei dieses Pakets enthalten; sie wird nur im Plugin eingetragen.
Die Zugangsdaten in quellcode/ (z. B. admin / enteronce-dev) gelten nur für die lokale Testumgebung.
Lizenz: GPL-2.0-or-later, ohne Gewährleistung (quellcode/LICENSE).
Ein Geschenk nach dem AI for Access Hackathon (Basel, 20.–21. November 2026).
TXT, [
    '{ext}' => $withPdf ? 'pdf' : 'md',
    '{formats}' => $withPdf ? ', jede als PDF zum Lesen und Drucken und als .md-Textdatei' : '',
]));

// Zip.
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fail("cannot create $zipPath");
}
$count = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS)) as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($stage) + 1));
    $zip->addFile($f->getPathname(), $rel);
    if (in_array($rel, ['abgleichsbericht/enteronce-drift', 'abgleichsbericht/bin/enteronce-drift'], true)) {
        $zip->setExternalAttributesName($rel, ZipArchive::OPSYS_UNIX, 0100755 << 16);
    }
    ++$count;
}
$zip->close();
rrmdir($stage);
printf("Built dist/enter-once-uebergabe.zip (%d files, %d KB; quellcode: %d files)\n", $count, (int) (filesize($zipPath) / 1024), $sourceFiles);
