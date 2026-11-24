<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use DateTimeZone;

/**
 * Renders the report: one self-contained HTML file in German (Swiss spelling) for non-technical
 * staff. No script, no external resource (fonts, images, styles), no tracking. Only titles, days
 * and times from the calendar and the page: no description, no attendee data (R-12).
 */
final class ReportRenderer
{
    public function render(DriftResult $r, ReportContext $c): string
    {
        $counts = $r->counts();
        $page = $c->page;
        $weeklyShown = count($page->weeklyOffers);
        $zurich = new DateTimeZone('Europe/Zurich');

        $out = [];
        $out[] = '<!DOCTYPE html>';
        $out[] = '<html lang="de-CH">';
        $out[] = '<head>';
        $out[] = '<meta charset="utf-8">';
        $out[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $out[] = '<meta name="robots" content="noindex">';
        $out[] = '<title>Abgleichsbericht ' . self::h($c->centreName) . ' – ' . $c->generatedAt->setTimezone($zurich)->format('d.m.Y') . '</title>';
        $out[] = '<style>' . self::css() . '</style>';
        $out[] = '</head>';
        $out[] = '<body>';
        $out[] = '<a class="skip" href="#inhalt">Zum Inhalt springen</a>';
        $out[] = '<header><h1>Abgleichsbericht: Kalender und Programmseite</h1>';
        $out[] = '<p class="lede">Dieser Bericht vergleicht den Kalender von ' . self::h($c->centreName)
            . ' mit dem, was die Programmseite des Verbands heute zeigt. Er ändert nichts, weder im Kalender noch auf der Website.</p></header>';
        $out[] = '<main id="inhalt" tabindex="-1">';

        // ------------------------------------------------------------------ this run
        $out[] = '<section aria-labelledby="h-lauf"><h2 id="h-lauf">Dieser Lauf</h2><dl class="facts">';
        $out[] = self::fact('Erstellt am', $c->generatedAt->setTimezone($zurich)->format('d.m.Y, H:i') . ' Uhr');
        $out[] = self::fact('Standort', $c->centreName . ' (location_cat ' . $c->categoryId . ')');
        $out[] = self::fact('Kalender', $c->calendarLabel);
        $out[] = self::fact('Stand des Kalenders', $c->calendarNow->setTimezone($zurich)->format('d.m.Y, H:i') . ' Uhr');
        $out[] = self::fact('Verglichener Zeitraum', $c->window->start->format('d.m.Y') . ' bis ' . $c->window->end->modify('-1 day')->format('d.m.Y'));
        $pageLink = $c->pageUrl !== null ? '<a href="' . self::h($c->pageUrl) . '" rel="noopener noreferrer">' . self::h($c->pageLabel) . '</a>' : self::h($c->pageLabel);
        $out[] = '<div><dt>Programmseite</dt><dd>' . $pageLink . '</dd></div>';
        $out[] = self::fact('Im Kalender erwartet', $c->calendarSummary['dated_events'] . ' einzelne Veranstaltungen und ' . $c->calendarSummary['weekly_offers'] . ' wöchentliche Angebote');
        $out[] = self::fact('Auf der Seite gesehen', count($page->datedEvents) . ' einzelne Veranstaltungen und ' . $weeklyShown . ' wöchentliche Angebote');
        $out[] = '</dl></section>';

        // ------------------------------------------------------------------ limits
        $out[] = '<section aria-labelledby="h-grenzen" class="note"><h2 id="h-grenzen">Was dieser Bericht nicht sehen konnte</h2>';
        $weeklyText = 'Die Programmseite zeigte ' . $weeklyShown . ' wöchentliche Angebote.';
        if ($page->loadMoreWeekly) {
            $weeklyText .= ' Auf der Seite gibt es einen Knopf «mehr laden»: Es gibt also weitere wöchentliche Angebote, die dieser Bericht nicht sehen konnte.';
        } elseif ($page->weeklyMayBeIncomplete()) {
            $weeklyText .= ' Die Seite zeigt höchstens ' . $page->perPage . ' Angebote auf einmal. Es kann weitere geben, die dieser Bericht nicht sehen konnte.';
        } else {
            $weeklyText .= ' Es kann trotzdem weitere geben, die dieser Bericht nicht sehen konnte, denn die Seite lädt weitere Angebote nur über eigene Abfragen nach, die hier bewusst nicht verwendet werden.';
        }
        $out[] = '<p>' . self::h($weeklyText) . '</p>';
        if ($page->loadMoreDated) {
            $out[] = '<p>Auch die Liste der einzelnen Veranstaltungen ist länger als gezeigt (Knopf «mehr laden»). Termine nach dem letzten gezeigten Tag sind unter «Nicht beurteilbar» aufgeführt.</p>';
        }
        if ($page->unreadable !== []) {
            $out[] = '<p><strong>' . count($page->unreadable) . ' Einträge der Seite konnten nicht gelesen werden:</strong> '
                . self::h(implode('; ', $page->unreadable)) . '.</p>';
        }
        $ignored = [];
        if (($r->ignored['past_on_site'] ?? 0) > 0) {
            $ignored[] = $r->ignored['past_on_site'] . ' Einträge der Website liegen in der Vergangenheit';
        }
        if (($r->ignored['beyond_window_on_site'] ?? 0) > 0) {
            $ignored[] = $r->ignored['beyond_window_on_site'] . ' Einträge der Website liegen nach dem verglichenen Zeitraum';
        }
        if (($r->ignored['pending_weekly'] ?? 0) > 0) {
            $ignored[] = $r->ignored['pending_weekly'] . ' wöchentliche Angebote des Kalenders beginnen erst später und werden noch nicht auf der Website erwartet';
        }
        if (($r->ignored['cancelled_not_on_site'] ?? 0) > 0) {
            $ignored[] = $r->ignored['cancelled_not_on_site'] . ' abgesagte Veranstaltungen des Kalenders stehen (richtigerweise) nicht auf der Website';
        }
        if ($ignored !== []) {
            $out[] = '<p>Nicht als Abweichung gezählt: ' . self::h(implode('; ', $ignored)) . '.</p>';
        }
        $out[] = '</section>';

        // ------------------------------------------------------------------ overview
        $out[] = '<section aria-labelledby="h-uebersicht"><h2 id="h-uebersicht">Übersicht</h2>';
        $out[] = $r->hasFindings()
            ? '<p>Bitte schauen Sie sich die Listen mit Einträgen an.</p>'
            : '<p><strong>Keine Abweichungen gefunden.</strong> Alles, was der Bericht beurteilen konnte, stimmt überein.</p>';
        $out[] = '<ul class="overview">';
        foreach ([
            ['fehlt', 'Fehlt auf der Website', $counts['missing']],
            ['anders', 'Auf der Website anders', $counts['different']],
            ['nur-website', 'Auf der Website, aber nicht im Kalender', $counts['only_on_site']],
            ['abgesagt', 'Abgesagt, aber noch online', $counts['cancelled_online']],
            ['unklar', 'Nicht eindeutig zuzuordnen', $counts['ambiguous']],
            ['offen', 'Nicht beurteilbar', $counts['unverifiable']],
            ['ok', 'In Ordnung', $counts['agreeing']],
        ] as [$id, $label, $n]) {
            $out[] = '<li><a href="#' . $id . '">' . self::h($label) . '</a>: <strong>' . $n . '</strong></li>';
        }
        $out[] = '</ul></section>';

        // ------------------------------------------------------------------ lists
        $out[] = $this->section('fehlt', '1. Fehlt auf der Website', 'Diese Einträge stehen im Kalender, sind auf der Programmseite aber nicht zu finden.', $this->expectedTable($r->missing, 'Im Kalender'), $counts['missing']);
        $out[] = $this->section('anders', '2. Auf der Website anders', 'Diese Einträge gibt es auf beiden Seiten, aber etwas stimmt nicht überein. Die Titel dürfen verschieden sein; sie sind hier nicht gemeint.', $this->pairTable($r->different, true), $counts['different']);
        $out[] = $this->section('nur-website', '3. Auf der Website, aber nicht im Kalender', 'Diese Einträge zeigt die Programmseite, im Kalender gibt es dazu nichts (mehr). Möglich: ein Termin wurde im Kalender gelöscht, ist dort abgelaufen oder wird dort unter anderem Wochentag oder anderer Zeit geführt.', $this->siteTable($r->onlyOnSite), $counts['only_on_site']);
        $out[] = $this->section('abgesagt', '4. Abgesagt, aber noch online', 'Diese Termine sind im Kalender abgesagt. Die Website zeigt sie ohne den Vermerk «abgesagt».', $this->pairTable($r->cancelledOnline, false), $counts['cancelled_online']);
        $out[] = $this->section('unklar', '5. Nicht eindeutig zuzuordnen', 'Hier lässt sich nicht entscheiden, welcher Eintrag des Kalenders zu welchem Eintrag der Website gehört (gleicher Tag, ähnliche Zeit, ähnlicher oder gleicher Titel). Der Bericht rät nicht. Bitte von Hand prüfen.', $this->ambiguousBlocks($r->ambiguous), $counts['ambiguous']);
        $out[] = $this->section('offen', '6. Nicht beurteilbar', 'Die Programmseite zeigte nur einen Teil der Liste. Ob diese Einträge online sind, lässt sich mit diesem Bericht nicht sagen.', $this->expectedTable($r->unverifiable, 'Im Kalender'), $counts['unverifiable']);

        $okBody = '<details><summary>Liste der übereinstimmenden Einträge anzeigen</summary>' . $this->pairTable($r->agreeing, false, true) . '</details>';
        $out[] = $this->section('ok', '7. In Ordnung', 'Diese Einträge stimmen in Tag beziehungsweise Wochentag, Zeit und Vermerken überein.', $counts['agreeing'] > 0 ? $okBody : '', $counts['agreeing']);

        $out[] = '</main>';
        $out[] = '<footer><p>Dieser Bericht enthält nur Titel, Tage und Zeiten aus dem öffentlichen Kalender und von der öffentlichen Programmseite, keine Beschreibungstexte und keine Angaben zu Personen. '
            . 'Er ist eine Datei ohne Skripte und ohne Verbindung ins Internet. Er wurde mit dem Abgleichswerkzeug von «Einmal erfassen, überall anzeigen» erstellt.</p></footer>';
        $out[] = '</body></html>';

        return implode("\n", $out) . "\n";
    }

    private function section(string $id, string $title, string $intro, string $body, int $count): string
    {
        $html = '<section id="' . $id . '" aria-labelledby="h-' . $id . '"><h2 id="h-' . $id . '">' . self::h($title) . ' <span class="count">(' . $count . ')</span></h2>';
        $html .= '<p>' . self::h($intro) . '</p>';
        $html .= $count === 0 ? '<p class="none">Keine Einträge.</p>' : $body;

        return $html . '</section>';
    }

    /** @param list<Expected> $rows */
    private function expectedTable(array $rows, string $heading): string
    {
        $t = '<table><caption class="vh">' . self::h($heading) . '</caption><thead><tr><th scope="col">Titel</th><th scope="col">Wann</th><th scope="col">Raum</th></tr></thead><tbody>';
        foreach ($rows as $e) {
            $t .= '<tr><th scope="row">' . self::h($e->title) . '</th><td>' . self::h($e->whenText()) . '</td><td>' . self::h($e->room !== '' ? $e->room : '–') . '</td></tr>';
        }

        return $t . '</tbody></table>';
    }

    /** @param list<PageEvent> $rows */
    private function siteTable(array $rows): string
    {
        $t = '<table><caption class="vh">Auf der Website</caption><thead><tr><th scope="col">Titel auf der Website</th><th scope="col">Wann</th><th scope="col">Art</th></tr></thead><tbody>';
        foreach ($rows as $s) {
            $kind = $s->isWeekly() ? 'Wöchentliches Angebot' : 'Einzelne Veranstaltung';
            $note = $s->cancelled ? $kind . ', mit Vermerk «abgesagt»' : ($s->vacation ? $kind . ', mit Vermerk «Ferienpause»' : $kind);
            $t .= '<tr><th scope="row">' . self::siteLink($s) . '</th><td>' . self::h($s->whenText()) . '</td><td>' . self::h($note) . '</td></tr>';
        }

        return $t . '</tbody></table>';
    }

    /** @param list<Pair> $pairs */
    private function pairTable(array $pairs, bool $showDifferences, bool $compact = false): string
    {
        $differenceColumn = $showDifferences ? '<th scope="col">Was nicht stimmt</th>' : (!$compact ? '<th scope="col">Hinweis</th>' : '');
        $t = '<table><caption class="vh">Kalender und Website</caption><thead><tr><th scope="col">Im Kalender</th><th scope="col">Wann im Kalender</th><th scope="col">Auf der Website</th><th scope="col">Wann auf der Website</th>' . $differenceColumn . '</tr></thead><tbody>';
        foreach ($pairs as $p) {
            $titles = implode('; ', array_map(static fn (Expected $e) => $e->title, $p->expected));
            $whens = implode('; ', array_map(static fn (Expected $e) => $e->whenText(), $p->expected));
            $t .= '<tr><th scope="row">' . self::h($titles) . '</th><td>' . self::h($whens) . '</td><td>' . self::siteLink($p->site) . '</td><td>' . self::h($p->site->whenText()) . '</td>';
            if ($showDifferences) {
                $t .= '<td><ul>' . implode('', array_map(static fn (string $d) => '<li>' . self::h($d) . '</li>', $p->differences)) . '</ul></td>';
            } elseif (!$compact) {
                $t .= '<td>' . self::h(implode(' ', $p->differences)) . '</td>';
            }
            $t .= '</tr>';
        }

        return $t . '</tbody></table>';
    }

    /** @param list<AmbiguousGroup> $groups */
    private function ambiguousBlocks(array $groups): string
    {
        $html = '';
        foreach ($groups as $n => $g) {
            $html .= '<article class="group"><h3>Gruppe ' . ($n + 1) . '</h3><div class="cols"><div><h4>Im Kalender</h4><ul>';
            foreach ($g->expected as $e) {
                $html .= '<li>' . self::h($e->title) . ' – ' . self::h($e->whenText()) . '</li>';
            }
            $html .= '</ul></div><div><h4>Auf der Website</h4><ul>';
            foreach ($g->site as $s) {
                $html .= '<li>' . self::siteLink($s) . ' – ' . self::h($s->whenText()) . '</li>';
            }
            $html .= '</ul></div></div></article>';
        }

        return $html;
    }

    private static function siteLink(PageEvent $s): string
    {
        $title = self::h($s->title);
        if ($s->url === null) {
            return $title;
        }

        return '<a href="' . self::h($s->url) . '" rel="noopener noreferrer">' . $title . '</a>';
    }

    private static function fact(string $label, string $value): string
    {
        return '<div><dt>' . self::h($label) . '</dt><dd>' . self::h($value) . '</dd></div>';
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function css(): string
    {
        return <<<'CSS'
:root{color-scheme:light}
body{margin:0 auto;max-width:62rem;padding:1rem 1rem 3rem;font:1rem/1.5 system-ui,-apple-system,"Segoe UI",Arial,sans-serif;color:#1f1b3a;background:#fff}
h1{font-size:1.75rem;line-height:1.2;margin:1rem 0 .5rem}
h2{font-size:1.35rem;margin:2.25rem 0 .5rem;padding-bottom:.25rem;border-bottom:3px solid #1f1b3a}
h3{font-size:1.1rem;margin:1rem 0 .25rem}h4{font-size:1rem;margin:.5rem 0 .25rem}
a{color:#8f2c00;text-decoration:underline}
a:focus-visible,summary:focus-visible,main:focus-visible{outline:3px solid #1f1b3a;outline-offset:2px}
.skip{position:absolute;left:-999px}.skip:focus{left:1rem;top:1rem;background:#fff;padding:.5rem}
.lede{font-size:1.1rem}
.count{font-weight:400}
.facts div{display:flex;flex-wrap:wrap;gap:.25rem 1rem;padding:.25rem 0;border-bottom:1px solid #c9c6dc}
.facts dt{font-weight:700;min-width:13rem}.facts dd{margin:0}
.note{border:3px solid #1f1b3a;border-radius:.75rem;padding:.25rem 1rem 1rem;margin-top:1.5rem}
.note h2{border:0;margin-top:.75rem}
.overview{padding-left:1.25rem}
table{border-collapse:collapse;width:100%;margin:.5rem 0}
th,td{border:1px solid #6d6896;padding:.4rem .6rem;text-align:left;vertical-align:top;overflow-wrap:anywhere}
thead th{background:#eceaf6}
tbody th{font-weight:600}
td ul{margin:0;padding-left:1.1rem}
.group{border:2px dashed #1f1b3a;border-radius:.5rem;padding:.25rem 1rem .75rem;margin:.75rem 0}
.cols{display:flex;flex-wrap:wrap;gap:1rem 2rem}.cols>div{flex:1 1 16rem}
.none{font-style:italic}
details{margin:.5rem 0}summary{cursor:pointer;font-weight:600}
.vh{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
footer{margin-top:3rem;border-top:1px solid #6d6896;font-size:.9rem}
@media print{a{color:#000}.skip{display:none}h2{break-after:avoid}tr{break-inside:avoid}}
CSS;
    }
}
