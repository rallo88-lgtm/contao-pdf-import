<?php

namespace Rallo\ContaoPdfImport\Service\Ocr;

use Rallo\ContaoPdfImport\Service\EventLogger;
use Rallo\ContaoPdfImport\Service\Pricing\TextractPricing;

/**
 * Decorator außen rum: schreibt pro analyzePage()-Aufruf ein Event mit
 * Cache-Hit-Flag und geschätzten Kosten.
 *
 * Cache-Hit -> 0 Kosten. Cache-Miss -> 1395 Mikro-EUR (Textract Layout per page).
 * Reference + extraMeta werden 1:1 durchgereicht und im Event gespeichert.
 */
final class LoggingOcrProvider implements OcrProviderInterface
{
    public function __construct(
        private readonly OcrProviderInterface $inner,
        private readonly EventLogger $eventLogger,
    ) {}

    public function analyzePage(
        string $imageBytes,
        int $pageNumber,
        int $imageWidth,
        int $imageHeight,
        ?string $reference = null,
        array $extraMeta = [],
    ): OcrResult {
        $result = $this->inner->analyzePage(
            $imageBytes,
            $pageNumber,
            $imageWidth,
            $imageHeight,
            $reference,
            $extraMeta,
        );

        $costMicros = $result->wasCached ? 0 : TextractPricing::layoutPageMicrosEur();

        $this->eventLogger->log(
            eventType: 'ocr.analyze',
            reference: $reference,
            costMicrosEur: $costMicros,
            cacheHit: $result->wasCached,
            metadata: array_merge($extraMeta, [
                'page_number'  => $pageNumber,
                'image_width'  => $imageWidth,
                'image_height' => $imageHeight,
                'block_count'  => count($result->blocks),
                'image_bytes'  => strlen($imageBytes),
            ]),
        );

        return $result;
    }
}
