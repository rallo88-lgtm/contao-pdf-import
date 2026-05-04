<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\Job\JobRepository;
use Rallo\ContaoPdfImport\Service\Job\JobStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Phase C — Start-Page mit DOS-Box.
 * GET-only. Submit hijackt JS-seitig (phase-stream.js) und schickt POST
 * an PdfImportPhaseCStreamController. Erlaubt nur status=pages_selected
 * oder ocr_done (= Re-Run nach evtl. neuer Auswahl).
 */
#[Route(
    '/contao/pdf-import/phase-c',
    name: 'pdf_import_phase_c',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['GET'],
)]
class PdfImportPhaseCController extends AbstractBackendController
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

        $allowed = [JobStatus::PagesSelected, JobStatus::OcrDone];
        $statusOk = \in_array($job->status, $allowed, true);

        $selectedPages = [];
        if (isset($job->payload['selected_pages']) && \is_array($job->payload['selected_pages'])) {
            $selectedPages = array_values(array_map('intval', $job->payload['selected_pages']));
        }

        return $this->render('@ContaoPdfImport/backend/pdf_import_phase_c.html.twig', [
            'job'           => $job,
            'statusOk'      => $statusOk,
            'selectedPages' => $selectedPages,
            'maxCostMicros' => 1395 * \count($selectedPages),  // worst-case = alle MISS
        ]);
    }
}
