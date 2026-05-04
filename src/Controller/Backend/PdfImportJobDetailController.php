<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\Job\JobRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Job-Detail-Page. Zeigt Job-Status + payload + Aktionen (Resume/Cancel).
 * Aktuell zeigt sie nur die persistierte Information; Resume- und
 * Phase-B-Trigger kommt mit C3.
 */
#[Route(
    '/contao/pdf-import/job',
    name: 'pdf_import_job',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['GET'],
    requirements: ['id' => '\d+'],
)]
class PdfImportJobDetailController extends AbstractBackendController
{
    public function __construct(
        private readonly JobRepository $jobs,
    ) {}

    public function __invoke(Request $request): Response
    {
        $id = (int) $request->query->get('id', 0);
        if ($id < 1) {
            throw new NotFoundHttpException('Job-ID fehlt.');
        }

        $job = $this->jobs->find($id);
        if ($job === null) {
            throw new NotFoundHttpException('Job nicht gefunden.');
        }

        return $this->render('@ContaoPdfImport/backend/pdf_import_job.html.twig', [
            'job'         => $job,
            'payloadJson' => $job->payload !== []
                ? json_encode($job->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ]);
    }
}
