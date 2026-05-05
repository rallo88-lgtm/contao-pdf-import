<?php

namespace Rallo\ContaoPdfImport\Service;

/**
 * Parst den Klammer-Datum-String aus FooterIssueNumberDetector
 * (z.B. "Maerz 2026", "Mai/Juni 2026", "01/2026") zu einem Unix-
 * Timestamp = Monatsanfang lokal. Fallback null wenn nicht parsebar.
 *
 * Monatsanfang ist bewusst gewaehlt: deterministicDate's pageNumber-
 * Sub-Sekunden landen so im selben Monat, Sortierung passt.
 */
final class IssueDateParser
{
    /** @var array<string, int> */
    private const MONTHS = [
        'januar'    => 1,
        'jan'       => 1,
        'februar'   => 2,
        'feb'       => 2,
        'maerz'     => 3,
        'märz'      => 3,
        'mrz'       => 3,
        'april'     => 4,
        'apr'       => 4,
        'mai'       => 5,
        'juni'      => 6,
        'jun'       => 6,
        'juli'      => 7,
        'jul'       => 7,
        'august'    => 8,
        'aug'       => 8,
        'september' => 9,
        'sep'       => 9,
        'sept'      => 9,
        'oktober'   => 10,
        'okt'       => 10,
        'november'  => 11,
        'nov'       => 11,
        'dezember'  => 12,
        'dez'       => 12,
    ];

    public function parse(?string $text): ?int
    {
        if ($text === null) {
            return null;
        }
        $t = trim($text);
        if ($t === '') {
            return null;
        }

        // "Maerz 2026", "Mai 2026", "Mai/Juni 2026" -> erstes Monat + Jahr
        if (preg_match('/([A-Za-zäöüÄÖÜ]+)[^\d]+(\d{4})/u', $t, $m)) {
            $monthKey = mb_strtolower($m[1]);
            $month    = self::MONTHS[$monthKey] ?? null;
            if ($month !== null) {
                return mktime(0, 0, 0, $month, 1, (int) $m[2]) ?: null;
            }
        }

        // "01/2026", "1.2026", "01-2026" -> numerischer Monat + Jahr
        if (preg_match('/^(\d{1,2})[\/.\-](\d{4})$/', $t, $m)) {
            $month = (int) $m[1];
            if ($month >= 1 && $month <= 12) {
                return mktime(0, 0, 0, $month, 1, (int) $m[2]) ?: null;
            }
        }

        return null;
    }
}
