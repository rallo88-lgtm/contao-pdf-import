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
 * Phase E — Start-Page mit DOS-Box. GET-only. Submit hijackt JS-seitig
 * via phase-stream.js und triggert PdfImportPhaseEStreamController.
 */
#[Route(
    '/contao/pdf-import/phase-e',
    name: 'pdf_import_phase_e',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['GET'],
)]
class PdfImportPhaseEController extends AbstractBackendController
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

        $allowed  = [JobStatus::ConflictsResolved, JobStatus::Completed];
        $statusOk = \in_array($job->status, $allowed, true);

        $decisions = $job->payload['decisions'] ?? [];
        if (!\is_array($decisions)) {
            $decisions = [];
        }

        return $this->render('@ContaoPdfImport/backend/pdf_import_phase_e.html.twig', [
            'job'       => $job,
            'statusOk'  => $statusOk,
            'decisions' => $decisions,
            'replaces'  => \count(array_filter($decisions, static fn(string $d) => $d === 'replace')),
            'skips'     => \count(array_filter($decisions, static fn(string $d) => $d === 'skip')),
            'news'      => \count(array_filter($decisions, static fn(string $d) => $d === 'new')),
        ]);
    }
}
