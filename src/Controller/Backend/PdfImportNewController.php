<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\NewsArchiveProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Upload-Form-Page fuer einen neuen Import-Job.
 * GET-only — Submit hijackt JS-seitig (phase-a-stream.js) und schickt
 * Multipart an PdfImportPhaseAStreamController.
 */
#[Route(
    '/contao/pdf-import/new',
    name: 'pdf_import_new',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['GET'],
)]
class PdfImportNewController extends AbstractBackendController
{
    public function __construct(
        private readonly NewsArchiveProvider $archives,
    ) {}

    public function __invoke(): Response
    {
        return $this->render('@ContaoPdfImport/backend/pdf_import_new.html.twig', [
            'archives' => $this->archives->listAll(),
        ]);
    }
}
