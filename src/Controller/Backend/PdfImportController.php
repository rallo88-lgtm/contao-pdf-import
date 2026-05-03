<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/contao/pdf-import', name: 'pdf_import', defaults: ['_scope' => 'backend', '_token_check' => true])]
class PdfImportController extends AbstractBackendController
{
    public function __invoke(): Response
    {
        return $this->render('@ContaoPdfImport/backend/coming_soon.html.twig', [
            'headline' => 'PDF-Import',
            'subline'  => 'Phase 3 — Real Import-Workflow mit DOS-Box',
            'phases'   => [
                ['A', 'Local Split',     'Imagick + Smalot extrahieren Seiten und Issue-Nummer'],
                ['B', 'Page-Auswahl',    'User waehlt zu scannende Seiten via DOS-Box-Eingabe'],
                ['C', 'AWS Round',       'Textract verarbeitet ausgewaehlte Seiten'],
                ['D', 'Conflict-Check',  'Pro Seite: Ersetzen / Ueberspringen / Neue Version'],
                ['E', 'Insert',          'tl_news + tl_content + tl_files Inserts'],
            ],
        ]);
    }
}
