<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\Job\JobFilesystem;
use Rallo\ContaoPdfImport\Service\Job\JobRepository;
use Rallo\ContaoPdfImport\Service\Job\JobStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Phase B — Page-Auswahl per Thumbnail-Grid.
 *
 * GET-only. Zeigt alle Pages eines Jobs als Klick-Toggle-Thumbnails plus
 * Quick-Actions (Alle/Keine) und Range-Input fuer Power-User. Submit geht
 * an PdfImportPhaseBSubmitController, klassisch POST -> Redirect (kein
 * Stream noetig — Auswahl persistieren ist instant).
 *
 * Voraussetzung: Job muss mind. status=split_done haben (Pages gerastert
 * und im Filesystem). Andere Stati werden mit Hinweis gerendert.
 */
#[Route(
    '/contao/pdf-import/phase-b',
    name: 'pdf_import_phase_b',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['GET'],
)]
class PdfImportPhaseBController extends AbstractBackendController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobFilesystem $jobFs,
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

        $allowedStatus = [
            JobStatus::SplitDone,
            JobStatus::PagesSelected,  // erlaubt Re-Selection
        ];
        $statusOk = \in_array($job->status, $allowedStatus, true);

        $previouslySelected = [];
        if (isset($job->payload['selected_pages']) && \is_array($job->payload['selected_pages'])) {
            $previouslySelected = array_values(array_map('intval', $job->payload['selected_pages']));
        }

        return $this->render('@ContaoPdfImport/backend/pdf_import_phase_b.html.twig', [
            'job'                => $job,
            'statusOk'           => $statusOk,
            'previouslySelected' => $previouslySelected,
        ]);
    }
}
