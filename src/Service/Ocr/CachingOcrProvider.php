<?php

namespace Rallo\ContaoPdfImport\Service\Ocr;

final class CachingOcrProvider implements OcrProviderInterface
{
    public function __construct(
        private readonly OcrProviderInterface $inner,
        private readonly string $cacheDir,
    ) {}

    public function analyzePage(
        string $imageBytes,
        int $pageNumber,
        int $imageWidth,
        int $imageHeight,
        ?string $reference = null,
        array $extraMeta = [],
    ): OcrResult {
        $key       = sha1($imageBytes);
        $cachePath = $this->cacheDir . '/' . $key . '.json';

        if (is_file($cachePath)) {
            $raw = file_get_contents($cachePath);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    return OcrResult::fromArray($data)->withCacheHit(true);
                }
            }
        }

        $result = $this->inner->analyzePage(
            $imageBytes,
            $pageNumber,
            $imageWidth,
            $imageHeight,
            $reference,
            $extraMeta,
        );

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
        file_put_contents($cachePath, json_encode($result->toArray(), JSON_UNESCAPED_UNICODE));

        return $result;
    }
}
