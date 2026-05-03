<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/contao/pdf-import-config', name: 'pdf_import_config', defaults: ['_scope' => 'backend', '_token_check' => true])]
class PdfImportConfigController extends AbstractBackendController
{
    public function __invoke(): Response
    {
        return $this->render('@ContaoPdfImport/backend/coming_soon.html.twig', [
            'headline' => 'Konfiguration',
            'subline'  => 'Phase 4 — Mapping-Editor + OCR-Provider-Settings',
            'phases'   => [
                ['1', 'OCR-Provider',         'Textract-API-Key + Region (oder spaeter Tesseract-Fallback)'],
                ['2', 'News-Archive-Mapping', 'tl_news_archive ID(s) als Default-Ziel(e) fuer Imports'],
                ['3', 'Issue-Detector',       'Regex / Strategy zur Ausgabe-Nummer-Extraktion'],
                ['4', 'Block-CE-Override',    'JSON-Override der Default-Block-CE-Map'],
            ],
        ]);
    }
}
