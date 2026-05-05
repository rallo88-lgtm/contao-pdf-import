<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\EventLogger;
use Rallo\ContaoPdfImport\Service\Job\JobRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Soft-Delete eines Jobs. POST mit REQUEST_TOKEN, redirect zur Master-
 * Page. Stats-Endpoints nutzen weiter alle Rows, Listing filtert via
 * deleted_at IS NULL.
 *
 * Bewusst KEIN Hard-Delete: Job-Row + payload bleiben fuer die spaeter
 * lesende Statistik (Phase A duration, Textract Token, Insert-Counts).
 */
#[Route(
    '/contao/pdf-import/job-delete',
    name: 'pdf_import_job_delete',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['POST'],
)]
class PdfImportJobDeleteController extends AbstractBackendController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly EventLogger $eventLogger,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(Request $request): Response
    {
        $id = (int) $request->request->get('id', 0);
        if ($id < 1) {
            throw new NotFoundHttpException('Job-ID fehlt.');
        }

        $job = $this->jobs->find($id);
        if ($job === null) {
            throw new NotFoundHttpException('Job nicht gefunden.');
        }

        if ($job->deletedAt === null) {
            $this->jobs->softDelete($id);
            $this->eventLogger->log(
                eventType: 'job.soft_deleted',
                reference: 'job-' . $id,
                metadata: [
                    'job_id'       => $id,
                    'previous_status' => $job->status->value,
                ],
            );
        }

        return new RedirectResponse(
            $this->urlGenerator->generate('pdf_import'),
            Response::HTTP_SEE_OTHER,
        );
    }
}
