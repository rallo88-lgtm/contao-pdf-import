<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\Job\JobRepository;
use Rallo\ContaoPdfImport\Service\Job\JobStatus;
use Rallo\ContaoPdfImport\Service\NewsConflictChecker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Phase D — Conflict-Check vs. tl_news.
 *
 * GET-only: pruefen welche Pages bereits in tl_news existieren (per
 * deterministischem date-match), Decision-Form rendern. Submit geht an
 * PdfImportPhaseDSubmitController, Default-Decision pro existierendem
 * Treffer ist 'replace' (Bettina kann auf 'skip' aendern).
 */
#[Route(
    '/contao/pdf-import/phase-d',
    name: 'pdf_import_phase_d',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['GET'],
)]
class PdfImportPhaseDController extends AbstractBackendController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly NewsConflictChecker $conflicts,
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

        $allowed  = [JobStatus::OcrDone, JobStatus::ConflictsResolved];
        $statusOk = \in_array($job->status, $allowed, true);

        $selectedPages = [];
        if (isset($job->payload['selected_pages']) && \is_array($job->payload['selected_pages'])) {
            $selectedPages = array_values(array_map('intval', $job->payload['selected_pages']));
        }

        $checks = [];
        if ($statusOk && $selectedPages !== [] && $job->targetArchivePid !== null) {
            $checks = $this->conflicts->check(
                $job->targetArchivePid,
                $job->detectedIssueNumber,
                $selectedPages,
            );
        }

        $existingDecisions = $job->payload['decisions'] ?? [];

        return $this->render('@ContaoPdfImport/backend/pdf_import_phase_d.html.twig', [
            'job'               => $job,
            'statusOk'          => $statusOk,
            'selectedPages'     => $selectedPages,
            'checks'            => $checks,
            'conflictCount'     => \count(array_filter($checks, static fn(array $c) => $c['exists'])),
            'existingDecisions' => $existingDecisions,
        ]);
    }
}
