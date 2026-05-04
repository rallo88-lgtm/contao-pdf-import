<?php

namespace Rallo\ContaoPdfImport\Service\Ocr;

final class OcrResult
{
    public function __construct(
        public readonly int $pageNumber,
        public readonly array $blocks,
        public readonly int $imageWidth,
        public readonly int $imageHeight,
    ) {}

    public function getLayoutBlocks(): array
    {
        return array_values(array_filter(
            $this->blocks,
            static fn(array $b): bool => str_starts_with($b['BlockType'] ?? '', 'LAYOUT_'),
        ));
    }

    public function getBlockMap(): array
    {
        $map = [];
        foreach ($this->blocks as $b) {
            if (isset($b['Id'])) {
                $map[$b['Id']] = $b;
            }
        }
        return $map;
    }

    public function toArray(): array
    {
        return [
            'pageNumber'  => $this->pageNumber,
            'blocks'      => $this->blocks,
            'imageWidth'  => $this->imageWidth,
            'imageHeight' => $this->imageHeight,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            pageNumber:  (int) ($data['pageNumber']  ?? 0),
            blocks:      $data['blocks']             ?? [],
            imageWidth:  (int) ($data['imageWidth']  ?? 0),
            imageHeight: (int) ($data['imageHeight'] ?? 0),
        );
    }
}
