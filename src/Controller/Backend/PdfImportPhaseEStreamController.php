<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Doctrine\DBAL\Connection;
use Rallo\ContaoPdfImport\Service\EventLogger;
use Rallo\ContaoPdfImport\Service\Importer\NewsArticleBuilder;
use Rallo\ContaoPdfImport\Service\Job\JobRepository;
use Rallo\ContaoPdfImport\Service\Job\JobStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Phase E — Real Insert. SSE-Stream.
 *
 * Pro selected_page mit decision != 'skip' baut NewsArticleBuilder die
 * tl_news + tl_content + tl_files. Fortschritt wird live in DOS-Box
 * gestreamt. Final: status -> completed, redirect zu Job-Detail.
 *
 * Transaction: Wir machen pro Page eine eigene Transaktion. Wenn eine
 * fehlschlaegt, sind die anderen safe. Job-Payload trackt errors.
 */
#[Route(
    '/contao/pdf-import/phase-e/stream',
    name: 'pdf_import_phase_e_stream',
    defaults: ['_scope' => 'backend', '_token_check' => false],
    methods: ['POST'],
)]
class PdfImportPhaseEStreamController extends AbstractBackendController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly NewsArticleBuilder $builder,
        private readonly EventLogger $eventLogger,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $tStart = microtime(true);
        $jobId  = (int) $request->request->get('job_id', 0);

        return new StreamedResponse(function () use ($jobId, $tStart) {
            @set_time_limit(0);
            @ob_implicit_flush(true);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            $this->run($jobId, $tStart);
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    private function run(int $jobId, float $tStart): void
    {
        try {
            $job = $this->jobs->find($jobId);
            if ($job === null) {
                $this->emit('error', 'Job nicht gefunden.');
                $this->emitDone();
                return;
            }
            if (!\in_array($job->status, [JobStatus::ConflictsResolved, JobStatus::Completed], true)) {
                $this->emit('error', 'Falscher Status: ' . $job->status->value);
                $this->emitDone();
                return;
            }

            $selectedPages = $job->payload['selected_pages'] ?? [];
            $decisions     = $job->payload['decisions'] ?? [];
            if (!\is_array($selectedPages) || !\is_array($decisions)) {
                $this->emit('error', 'Job-Payload korrupt (selected_pages oder decisions fehlen).');
                $this->emitDone();
                return;
            }

            $this->jobs->update($jobId, ['last_phase' => 'phase_e']);

            $this->emit('info', sprintf(
                'Phase E · Job #%d · MBJ-%s · %d Pages · Target tl_news_archive id=%d',
                $jobId,
                $job->detectedIssueNumber ?? '???',
                \count($selectedPages),
                $job->targetArchivePid ?? 0,
            ));

            $totals = ['created' => 0, 'replaced' => 0, 'skipped' => 0, 'failed' => 0, 'blocks' => 0, 'images' => 0];
            $errors = [];

            foreach ($selectedPages as $pageNumber) {
                $pageNumber = (int) $pageNumber;
                $decision   = (string) ($decisions[(string) $pageNumber] ?? $decisions[$pageNumber] ?? 'new');

                if ($decision === 'skip') {
                    $this->emit('dim', sprintf('Page %d · skip', $pageNumber));
                    $totals['skipped']++;
                    continue;
                }

                $this->emit('info', sprintf('Page %d · decision=%s · build...', $pageNumber, $decision));

                try {
                    $this->db->beginTransaction();
                    $r = $this->builder->buildPage($job, $pageNumber, $decision);
                    $this->db->commit();

                    if ($r['action'] === 'replaced') {
                        $totals['replaced']++;
                        $this->emit('ok', sprintf(
                            'Page %d · REPLACED · tl_news#%d · %d Blocks · %d Bilder',
                            $pageNumber, $r['news_id'], $r['blocks_inserted'], $r['images'],
                        ));
                    } else {
                        $totals['created']++;
                        $this->emit('ok', sprintf(
                            'Page %d · CREATED · tl_news#%d · %d Blocks · %d Bilder',
                            $pageNumber, $r['news_id'], $r['blocks_inserted'], $r['images'],
                        ));
                    }
                    $totals['blocks'] += $r['blocks_inserted'];
                    $totals['images'] += $r['images'];
                } catch (\Throwable $e) {
                    if ($this->db->isTransactionActive()) {
                        $this->db->rollBack();
                    }
                    $totals['failed']++;
                    $msg = sprintf('%s: %s', $this->shortName($e::class), $e->getMessage());
                    $errors[$pageNumber] = $msg;
                    $this->emit('error', sprintf('Page %d · %s', $pageNumber, $msg));
                }
            }

            // Persist results
            $payload                 = $job->payload;
            $payload['phase_e_summary'] = $totals;
            if ($errors !== []) {
                $payload['phase_e_errors'] = $errors;
            }

            $newStatus = $totals['failed'] === 0 ? JobStatus::Completed : JobStatus::Failed;
            $this->jobs->update($jobId, [
                'status'  => $newStatus,
                'payload' => $payload,
            ]);

            $this->emit('ok', sprintf(
                'Phase E done · created=%d, replaced=%d, skipped=%d, failed=%d · %d Blocks · %d Bilder · status=%s',
                $totals['created'], $totals['replaced'], $totals['skipped'], $totals['failed'],
                $totals['blocks'], $totals['images'], $newStatus->value,
            ));

            $this->eventLogger->log(
                eventType: $newStatus === JobStatus::Completed ? 'job.completed' : 'job.failed',
                reference: 'job-' . $jobId,
                metadata: [
                    'source'       => 'phase_e',
                    'job_id'       => $jobId,
                    'totals'       => $totals,
                    'errors'       => $errors,
                    'duration_ms'  => (int) round((microtime(true) - $tStart) * 1000),
                ],
            );

            $resultUrl = $this->urlGenerator->generate(
                'pdf_import_job',
                ['id' => $jobId],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $this->emitDone($resultUrl);
        } catch (\Throwable $e) {
            $this->emit('error', sprintf('%s: %s', $this->shortName($e::class), $e->getMessage()));
            $this->emitDone();
        }
    }

    private function emit(string $type, string $msg): void
    {
        echo 'data: ' . json_encode(['type' => $type, 'msg' => $msg], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (\function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }

    private function emitDone(?string $resultUrl = null): void
    {
        $payload = ['type' => 'done'];
        if ($resultUrl !== null) {
            $payload['resultUrl'] = $resultUrl;
        }
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (\function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }

    private function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos !== false ? substr($fqn, $pos + 1) : $fqn;
    }
}
