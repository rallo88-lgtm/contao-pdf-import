<?php

namespace Rallo\ContaoPdfImport\Service\Pricing;

/**
 * AWS-Textract-Pricing als Schaetzung.
 *
 * AWS-Console ist authoritativ. Diese Konstanten sind Approximationen fuer
 * Live-Anzeige im Stats-BE. Bei groesseren Drift via Constructor-Param oder
 * Config-Override anpassen.
 *
 * Pricing-Stand 2026-04: Textract Layout-Feature ist $1.50 pro 1000 Pages
 * = $0.0015 pro Page. USD->EUR Approx 0.93 (April 2026).
 *
 * Mathe: Mikro-EUR (EUR * 1_000_000) als Integer um Float-Drift bei
 * SUM-Aggregationen zu vermeiden.
 */
final class TextractPricing
{
    /** $0.0015 USD per page = 1500 Mikro-USD */
    public const COST_LAYOUT_PAGE_MICROS_USD = 1500;

    /** Approximation, AWS-Billing ist authoritativ */
    public const USD_TO_EUR_RATE = 0.93;

    public static function layoutPageMicrosEur(int $pages = 1): int
    {
        return (int) round(self::COST_LAYOUT_PAGE_MICROS_USD * self::USD_TO_EUR_RATE * $pages);
    }

    /**
     * Anzeige-Helper: Mikro-EUR -> menschliche Form.
     * <  100_000 (=  10 ct) -> "0,12 ct"
     * < 1_000_000 (= 1 EUR) -> "0,12 EUR"
     * sonst                 -> "12,34 EUR"
     */
    public static function formatMicros(int $micros): string
    {
        if ($micros === 0) {
            return '0,00 ct';
        }
        if ($micros < 100_000) {
            return number_format($micros / 10_000, 2, ',', '.') . ' ct';
        }
        return number_format($micros / 1_000_000, $micros < 1_000_000 ? 4 : 2, ',', '.') . ' €';
    }
}
