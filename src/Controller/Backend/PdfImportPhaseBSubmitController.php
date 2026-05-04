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

/**
 * POST-Endpoint fuer Phase-B-Auswahl.
 *
 * Liest selected_pages (CSV: "2,4-7,12"), normalisiert auf int[] mit
 * Range-Expansion, validiert gegen page_count, persistiert in payload,
 * setzt status=pages_selected. Redirect zurueck zu Job-Detail.
 */
#[Route(
    '/contao/pdf-import/phase-b/submit',
    name: 'pdf_import_phase_b_submit',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['POST'],
)]
class PdfImportPhaseBSubmitController extends AbstractBackendController
{
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

        $rawSelection = (string) $request->request->get('selected_pages', '');
        $pages        = $this->parseRange($rawSelection);
        $pages        = array_values(array_filter($pages, static fn(int $p) => $p >= 1 && $p <= $job->pageCount));

        if ($pages === []) {
            // Zurueck zur Phase-B-Page mit Fehler-Hinweis (via flash?). Pragmatisch: redirect mit Query-Param.
            return new RedirectResponse(
                $this->urlGenerator->generate('pdf_import_phase_b', ['id' => $id, 'err' => 'empty']),
                Response::HTTP_SEE_OTHER,
            );
        }

        $payload                  = $job->payload;
        $payload['selected_pages'] = $pages;

        $this->jobs->update($id, [
            'status'     => JobStatus::PagesSelected,
            'last_phase' => 'phase_b',
            'payload'    => $payload,
        ]);

        $this->eventLogger->log(
            eventType: 'job.phase_b_done',
            reference: 'job-' . $id,
            metadata: [
                'source'         => 'phase_b',
                'job_id'         => $id,
                'selected_pages' => $pages,
                'page_count'     => $job->pageCount,
                'selection_pct'  => round(\count($pages) * 100 / $job->pageCount, 1),
            ],
        );

        return new RedirectResponse(
            $this->urlGenerator->generate('pdf_import_job', ['id' => $id]),
            Response::HTTP_SEE_OTHER,
        );
    }

    /**
     * @return int[] sorted ascending, deduplicated
     */
    private function parseRange(string $input): array
    {
        $out = [];
        foreach (preg_split('/\s*,\s*/', trim($input)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\d+)$/', $part, $m)) {
                $out[(int) $m[1]] = true;
                continue;
            }
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                $a = (int) $m[1];
                $b = (int) $m[2];
                [$lo, $hi] = $a <= $b ? [$a, $b] : [$b, $a];
                for ($i = $lo; $i <= $hi; $i++) {
                    $out[$i] = true;
                }
            }
        }
        $keys = array_keys($out);
        sort($keys);
        return $keys;
    }
}
