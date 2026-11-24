<?php

/**
 * Checks an unpacked handover package (argument: its folder). Run with plain PHP 8.1.
 *  - no file (also inside the nested plugin zip) contains a calendar address other than the
 *    invented test addresses (example hosts, fixtures.*, private test IPs);
 *  - no environment file, wp-config.php, vendor/ or node_modules/ in quellcode/;
 *  - the only passwords are the documented throw-away logins of the local stand-in site.
 */

declare(strict_types=1);

$dir = rtrim((string) ($argv[1] ?? ''), '/');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: php package-scan.php <unpacked-package>\n");
    exit(1);
}
$problems = [];
$allowedHosts = '/^(?:[a-z0-9-]+\.)*(?:example\.org|example\.com|example|fixtures\.test|fixtures\.enteronce\.invalid|localhost)$|^(?:127\.|10\.|192\.168\.)/i';
$knownTestPasswords = ['enteronce-dev', 'wordpress-dev', 'root-dev', 'clean-dev-only'];

$scanText = static function (string $name, string $text) use (&$problems, $allowedHosts, $knownTestPasswords): void {
    // Real calendar services.
    if (preg_match('#(outlook\.office365\.com|outlook\.live\.com|outlook\.office\.com)/owa/calendar/|calendar\.google\.com/calendar/ical/|p\d+-caldav\.icloud\.com#i', $text, $m)) {
        $problems[] = "$name: calendar service address ($m[0])";
    }
    // Any .ics or webcal address on a host that is not an invented test host.
    // Backslashes removed first, so addresses escaped in regular expressions or strings count too.
    if (preg_match_all('#(?:https?|webcal)://([A-Za-z0-9.\-]+)[^\s"\'<>)]*\.ics\b#i', str_replace('\\', '', $text), $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $host = str_replace('\\', '', $m[1]);
            if (!preg_match($allowedHosts, $host)) {
                $problems[] = "$name: calendar address on $host";
            }
        }
    }
    // Passwords: only the documented throw-away logins of the local stand-in site.
    if (preg_match_all('#(?:admin_password|PASSWORD|dbpass)\s*[=:]\s*["\']?([^\s"\']+)#', $text, $pm, PREG_SET_ORDER)) {
        foreach ($pm as $m) {
            $value = $m[1];
            if (!in_array($value, $knownTestPasswords, true) && !str_starts_with($value, '$') && !str_starts_with($value, '"$')) {
                $problems[] = "$name: password value «{$value}»";
            }
        }
    }
};

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
$files = 0;
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($dir) + 1);
    ++$files;
    if (preg_match('#(^|/)(\.env[^/]*|wp-config\.php|settings\.local\.json)$#', $rel)) {
        $problems[] = "$rel: secret-bearing file name";
    }
    if (preg_match('#^quellcode/(.*/)?(vendor|node_modules|dist|\.phpunit\.cache)/#', $rel)) {
        $problems[] = "$rel: must not be in quellcode/";
    }
    if (str_ends_with($rel, '.zip')) {
        $zip = new ZipArchive();
        if ($zip->open($f->getPathname()) !== true) {
            $problems[] = "$rel: unreadable zip";
            continue;
        }
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('/\.(php|txt|md|js|css|json|ics|html|xml|yml|po|pot)$/i', $name) || !str_contains(basename($name), '.')) {
                $scanText("$rel!$name", (string) $zip->getFromIndex($i));
            }
        }
        $zip->close();
        continue;
    }
    if ($f->getSize() < 5 * 1024 * 1024 && !preg_match('/\.(png|jpe?g|gif|webp|ico|woff2?)$/i', $rel)) {
        $scanText($rel, (string) file_get_contents($f->getPathname()));
    }
}
if ($problems !== []) {
    fwrite(STDERR, "Package scan FAILED:\n- " . implode("\n- ", array_unique($problems)) . "\n");
    exit(1);
}
echo "Package scan: $files files, no calendar address, no secret file, only the documented local test logins: OK\n";
