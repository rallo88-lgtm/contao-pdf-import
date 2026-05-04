<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\EventLogger;
use Rallo\ContaoPdfImport\Service\Job\JobRepository;
use Rallo\ContaoPdfImport\Service\Job\JobStatus;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(
    '/contao/pdf-import/phase-d/submit',
    name: 'pdf_import_phase_d_submit',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['POST'],
)]
class PdfImportPhaseDSubmitController extends AbstractBackendController
{
    private const VALID_DECISIONS = ['new', 'replace', 'skip'];

    public function __construct(
        private readonly JobRepository $jobs,
        private readonly EventLogger $eventLogger,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(Request $request): Response
    {
        $id = (int) $request->request->get('job_id', 0);
        if ($id < 1) {
            throw new NotFoundHttpException('Job-ID fehlt.');
        }

        $job = $this->jobs->find($id);
        if ($job === null) {
            throw new NotFoundHttpException('Job nicht gefunden.');
        }

        $selectedPages = $job->payload['selected_pages'] ?? [];
        if (!\is_array($selectedPages)) {
            $selectedPages = [];
        }

        $decisions = [];
        foreach ($selectedPages as $pageNumber) {
            $key   = 'decision_' . (int) $pageNumber;
            $value = (string) $request->request->get($key, 'new');
            if (!\in_array($value, self::VALID_DECISIONS, true)) {
                $value = 'new';
            }
            $decisions[(string) (int) $pageNumber] = $value;
        }

        $payload              = $job->payload;
        $payload['decisions'] = $decisions;

        $this->jobs->update($id, [
            'status'     => JobStatus::ConflictsResolved,
            'last_phase' => 'phase_d',
            'payload'    => $payload,
        ]);

        $this->eventLogger->log(
            eventType: 'job.phase_d_done',
            reference: 'job-' . $id,
            metadata: [
                'source'     => 'phase_d',
                'job_id'     => $id,
                'decisions'  => $decisions,
                'replaces'   => \count(array_filter($decisions, static fn(string $d) => $d === 'replace')),
                'skips'      => \count(array_filter($decisions, static fn(string $d) => $d === 'skip')),
                'news'       => \count(array_filter($decisions, static fn(string $d) => $d === 'new')),
            ],
        );

        return new RedirectResponse(
            $this->urlGenerator->generate('pdf_import_job', ['id' => $id]),
            Response::HTTP_SEE_OTHER,
        );
    }
}
