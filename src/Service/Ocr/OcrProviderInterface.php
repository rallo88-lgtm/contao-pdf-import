<?php

namespace Rallo\ContaoPdfImport\Service\Ocr;

interface OcrProviderInterface
{
    public function analyzePage(
        string $imageBytes,
        int $pageNumber,
        int $imageWidth,
        int $imageHeight,
        ?string $reference = null,
        array $extraMeta = [],
    ): OcrResult;
}
