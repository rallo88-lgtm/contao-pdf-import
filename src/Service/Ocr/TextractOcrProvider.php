<?php

namespace Rallo\ContaoPdfImport\Service\Ocr;

use Aws\Textract\TextractClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class TextractOcrProvider implements OcrProviderInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly TextractClient $client,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function analyzePage(
        string $imageBytes,
        int $pageNumber,
        int $imageWidth,
        int $imageHeight,
        ?string $reference = null,
        array $extraMeta = [],
    ): OcrResult {
        $this->logger->info('Textract analyzeDocument', [
            'page'   => $pageNumber,
            'bytes'  => strlen($imageBytes),
            'width'  => $imageWidth,
            'height' => $imageHeight,
        ]);

        $response = $this->client->analyzeDocument([
            'Document'     => ['Bytes' => $imageBytes],
            'FeatureTypes' => ['LAYOUT'],
        ]);

        return new OcrResult(
            pageNumber:  $pageNumber,
            blocks:      $response->toArray()['Blocks'] ?? [],
            imageWidth:  $imageWidth,
            imageHeight: $imageHeight,
        );
    }
}
