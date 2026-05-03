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
        return $this->render('@ContaoPdfImport/backend/pdf_import.html.twig');
    }
}
