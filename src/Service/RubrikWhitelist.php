<?php

namespace Rallo\ContaoPdfImport\Service;

/**
 * 7 redaktionelle Rubriken des MBJ. OCR-/Layout-Glitches via Levenshtein-
 * Fuzzy auf den naechstliegenden Whitelist-Wert mappen. Liefert null
 * wenn kein Match in tolerabler Distanz — der Builder nutzt dann den
 * Wert der vorigen Page per Forward-Fill.
 */
final class RubrikWhitelist
{
    public const RUBRIKEN = [
        'Vorwort',
        'Wissenschaft und Forschung',
        'Literatur',
        'Therapie',
        'Ratgeber',
        'Meine Geschichte',
        'DVMB Aktiv',
    ];

    /**
     * Threshold = 20% der Needle-Laenge, gedeckelt auf [1, 4]. Damit wird
     * "DVMB-aktiv" -> "DVMB Aktiv" gemacht, aber "Wissenschaft" alleine
     * nicht auf "Wissenschaft und Forschung".
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $needle = self::canonicalize($raw);
        if ($needle === '') {
            return null;
        }

        $best     = null;
        $bestDist = \PHP_INT_MAX;

        foreach (self::RUBRIKEN as $rubrik) {
            $cand = self::canonicalize($rubrik);
            if ($cand === $needle) {
                return $rubrik;
            }
            $dist = levenshtein($needle, $cand);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best     = $rubrik;
            }
        }

        $threshold = max(1, min(4, (int) floor(\strlen($needle) * 0.2)));

        return $bestDist <= $threshold ? $best : null;
    }

    private static function canonicalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9 ]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return trim($s);
    }
}
