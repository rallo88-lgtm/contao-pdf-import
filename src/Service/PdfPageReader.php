<?php

namespace Rallo\ContaoPdfImport\Service;

use Smalot\PdfParser\Parser;

final class PdfPageReader
{
    public function getPageText(string $pdfPath, int $pageNumber): string
    {
        $parser = new Parser();
        $pdf    = $parser->parseFile($pdfPath);
        $pages  = $pdf->getPages();
        $idx    = $pageNumber - 1;

        return $pages[$idx]?->getText() ?? '';
    }
}
