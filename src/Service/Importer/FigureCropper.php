<?php

namespace Rallo\ContaoPdfImport\Service\Importer;

use Imagick;

/**
 * Schneidet aus dem Source-Page-JPG ein Sub-Image entsprechend der
 * Textract-BoundingBox aus und speichert es als JPEG am Zielpfad.
 *
 * BoundingBox-Werte sind 0..1 relativ zum Page-Image.
 */
final class FigureCropper
{
    /**
     * @param array{Left: float, Top: float, Width: float, Height: float} $box
     */
    public function crop(string $sourcePagePath, array $box, string $targetPath, int $quality = 88): void
    {
        if (!is_file($sourcePagePath)) {
            throw new \RuntimeException('Source page image not found: ' . $sourcePagePath);
        }

        $im = new Imagick();
        try {
            $im->readImage($sourcePagePath);
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();

            $cropW = max(1, (int) round($w * (float) $box['Width']));
            $cropH = max(1, (int) round($h * (float) $box['Height']));
            $cropX = max(0, (int) round($w * (float) $box['Left']));
            $cropY = max(0, (int) round($h * (float) $box['Top']));

            $im->cropImage($cropW, $cropH, $cropX, $cropY);
            $im->setImagePage(0, 0, 0, 0); // strip cropping-page-offset
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality($quality);

            $dir = \dirname($targetPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $im->writeImage($targetPath);
        } finally {
            $im->clear();
        }
    }
}
