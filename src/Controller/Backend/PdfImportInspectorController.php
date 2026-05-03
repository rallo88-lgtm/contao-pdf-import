<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/contao/pdf-import-inspector', name: 'pdf_import_inspector', defaults: ['_scope' => 'backend', '_token_check' => true])]
class PdfImportInspectorController extends AbstractBackendController
{
    public function __invoke(): Response
    {
        return $this->render('@ContaoPdfImport/backend/coming_soon.html.twig', [
            'headline' => 'Textract-Inspektor',
            'subline'  => 'Phase 2 — read-only Demo-Tool fuer Layout-Analyse',
            'phases'   => [
                ['1', 'PDF-Render',       'Linke Haelfte: Seite gerastert via Imagick'],
                ['2', 'Block-Listing',    'Rechte Haelfte: Textract-Bloecke mit Confidence + BoundingBox'],
                ['3', 'Mapping-Vorschau', 'Pro Block die aktuelle Block-CE-Mapping-Entscheidung'],
                ['4', 'BBox-Overlay',     'Toggle: Bounding-Boxen ueber die Seite, color-coded nach BlockType'],
            ],
        ]);
    }
}
