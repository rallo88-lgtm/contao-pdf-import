<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\Job\JobRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Master-Page des PDF-Imports — zeigt Job-Listing der letzten Imports.
 *
 * Phase 3 in 6 Iterationen aufgeteilt; aktuell ist C1 fertig (Foundation).
 * "Neuer Import"-Button ist disabled bis C2 (Phase A) das Upload-Form
 * + Phase-A-Stream-Endpoint hat.
 */
#[Route(
    '/contao/pdf-import',
    name: 'pdf_import',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['GET'],
)]
class PdfImportController extends AbstractBackendController
{
    public function __construct(
        private readonly JobRepository $jobs,
    ) {}

    public function __invoke(): Response
    {
        if (!$this->jobs->tableExists()) {
            return $this->render('@ContaoPdfImport/backend/pdf_import.html.twig', [
                'tableMissing' => true,
                'jobs'         => [],
            ]);
        }

        return $this->render('@ContaoPdfImport/backend/pdf_import.html.twig', [
            'tableMissing' => false,
            'jobs'         => $this->jobs->findRecent(50),
        ]);
    }
}
