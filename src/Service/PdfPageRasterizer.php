<?php

namespace Rallo\ContaoPdfImport\Service;

use Imagick;

final class PdfPageRasterizer
{
    public function __construct(
        private readonly int $defaultDpi = 150,
    ) {}

    public function getPageCount(string $pdfPath): int
    {
        $im = new Imagick();
        try {
            $im->pingImage($pdfPath);
            return $im->getNumberImages();
        } finally {
            $im->clear();
        }
    }

    /**
     * @return array{bytes: string, width: int, height: int}
     */
    public function rasterizePage(string $pdfPath, int $pageNumber, ?int $dpi = null): array
    {
        $img = new Imagick();
        try {
            $img->setResolution($dpi ?? $this->defaultDpi, $dpi ?? $this->defaultDpi);
            $img->readImage($pdfPath . '[' . ($pageNumber - 1) . ']');
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(85);

            return [
                'bytes'  => $img->getImageBlob(),
                'width'  => $img->getImageWidth(),
                'height' => $img->getImageHeight(),
            ];
        } finally {
            $img->clear();
        }
    }
}
