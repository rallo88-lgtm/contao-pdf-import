<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\BlockTextExtractor;
use Rallo\ContaoPdfImport\Service\EventLogger;
use Rallo\ContaoPdfImport\Service\IssueDetection\IssueNumberDetectorInterface;
use Rallo\ContaoPdfImport\Service\Job\JobFilesystem;
use Rallo\ContaoPdfImport\Service\Job\JobRepository;
use Rallo\ContaoPdfImport\Service\Job\JobStatus;
use Rallo\ContaoPdfImport\Service\Ocr\OcrProviderInterface;
use Rallo\ContaoPdfImport\Service\PdfPageRasterizer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Phase A — empfaengt PDF-Upload + target_archive, legt Job an, rastert
 * alle Seiten in var/data/pdf_import/jobs/{id}/pages/, Issue-Detection
 * via Footer-Block der ersten Seite (1 Textract-Call), persistiert Job
 * mit status=split_done.
 *
 * Final-Event sendet resultUrl auf die Job-Detail-Page.
 */
#[Route(
    '/contao/pdf-import/phase-a/stream',
    name: 'pdf_import_phase_a_stream',
    defaults: ['_scope' => 'backend', '_token_check' => false],
    methods: ['POST'],
)]
class PdfImportPhaseAStreamController extends AbstractBackendController
{
    public function __construct(
        private readonly PdfPageRasterizer $rasterizer,
        private readonly OcrProviderInterface $ocr,
        private readonly BlockTextExtractor $textExtractor,
        private readonly IssueNumberDetectorInterface $issueDetector,
        private readonly JobRepository $jobs,
        private readonly JobFilesystem $jobFs,
        private readonly EventLogger $eventLogger,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
        #[Autowire(env: 'AWS_REGION')]        private readonly string $awsRegion,
        #[Autowire(env: 'AWS_ACCESS_KEY_ID')] private readonly string $awsKey,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $tStart      = microtime(true);
        $file        = $request->files->get('pdf_upload');
        $archivePid  = (int) $request->request->get('archive_pid', 0);

        return new StreamedResponse(function () use ($file, $archivePid, $tStart) {
            @set_time_limit(0);
            @ob_implicit_flush(true);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            $this->run($file, $archivePid, $tStart);
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    private function run(?UploadedFile $file, int $archivePid, float $tStart): void
    {
        try {
            if (!$file) {
                $this->emit('error', 'Keine Datei empfangen.');
                $this->emitDone();
                return;
            }
            if (!$file->isValid()) {
                $this->emit('error', 'Upload-Fehler: ' . $file->getErrorMessage());
                $this->emitDone();
                return;
            }
            if ($archivePid < 1) {
                $this->emit('error', 'Kein Target-Archive gewählt.');
                $this->emitDone();
                return;
            }
            $mime = $file->getMimeType();
            if (!\in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
                $this->emit('error', 'Kein PDF · mime=' . ($mime ?? 'unknown'));
                $this->emitDone();
                return;
            }

            $pdfPath  = $file->getRealPath();
            $pdfBytes = file_get_contents($pdfPath);
            $pdfHash  = sha1($pdfBytes);

            $this->emit('info', sprintf(
                'PDF empfangen: %s (%s) · sha1: %s…',
                $file->getClientOriginalName(),
                $this->formatBytes(\strlen($pdfBytes)),
                substr($pdfHash, 0, 12),
            ));

            $pageCount = $this->rasterizer->getPageCount($pdfPath);
            $this->emit('ok', sprintf('PDF hat %d Seiten', $pageCount));

            // Job anlegen
            $userId = null;
            $user   = $this->security->getUser();
            if ($user instanceof BackendUser) {
                $userId = (int) $user->id;
            }

            $jobId = $this->jobs->create([
                'user_id'             => $userId,
                'pdf_name'            => $file->getClientOriginalName(),
                'pdf_hash'            => $pdfHash,
                'page_count'          => $pageCount,
                'target_archive_pid'  => $archivePid,
                'payload'             => ['mime' => $mime, 'size' => \strlen($pdfBytes)],
            ]);
            $this->jobs->update($jobId, ['last_phase' => 'phase_a']);
            $this->emit('ok', sprintf('Job angelegt · id=%d · Target-Archive pid=%d', $jobId, $archivePid));

            $this->eventLogger->log(
                eventType: 'job.created',
                reference: 'job-' . $jobId,
                metadata: [
                    'source'             => 'phase_a',
                    'pdf_name'           => $file->getClientOriginalName(),
                    'page_count'         => $pageCount,
                    'target_archive_pid' => $archivePid,
                ],
            );

            // Save PDF
            $this->jobFs->ensureJobDir($jobId);
            $jobPdfPath = $this->jobFs->getSourcePdfPath($jobId);
            file_put_contents($jobPdfPath, $pdfBytes);
            $this->emit('dim', 'PDF gespeichert: ' . str_replace($_SERVER['DOCUMENT_ROOT'] ?? '', '', $jobPdfPath));

            // Rasterize all pages
            $this->emit('info', sprintf('Rastere alle %d Seiten via Imagick...', $pageCount));
            $totalRasteredBytes = 0;
            for ($p = 1; $p <= $pageCount; $p++) {
                $rastered = $this->rasterizer->rasterizePage($jobPdfPath, $p);
                file_put_contents($this->jobFs->getPagePath($jobId, $p), $rastered['bytes']);
                $totalRasteredBytes += \strlen($rastered['bytes']);
                $this->emit('dim', sprintf(
                    'Page %d/%d · %d × %d px · %s',
                    $p, $pageCount, $rastered['width'], $rastered['height'], $this->formatBytes(\strlen($rastered['bytes'])),
                ));
            }
            $this->emit('ok', sprintf('Alle %d Seiten gerastert · gesamt %s', $pageCount, $this->formatBytes($totalRasteredBytes)));

            // Issue-Detection: Page 1 via Textract -> Footer-Detector
            $this->emit('info', 'Issue-Detection · Page 1 an Textract...');
            $page1Bytes  = file_get_contents($this->jobFs->getPagePath($jobId, 1));
            $page1Width  = 0;
            $page1Height = 0;
            // re-detect dims from saved file using Imagick? Pragmatic: use rasterize info from last loop iteration if it was page 1
            // Cleaner: re-rasterize page 1 to get dims (Textract braucht width/height anyway)
            // Actually wir koennen analyzePage ohne genaue dims aufrufen — Detector nutzt nur Geometry-relativ
            // OK: dummy 0/0 ist OK weil wir die nicht im Detector brauchen.
            $masked = \strlen($this->awsKey) >= 8
                ? substr($this->awsKey, 0, 4) . '****' . substr($this->awsKey, -4)
                : '****';
            $this->emit('dim', sprintf('AWS-Textract analyzeDocument(LAYOUT) · region=%s · key=%s', $this->awsRegion, $masked));

            $tBeforeOcr = microtime(true);
            $result     = $this->ocr->analyzePage(
                $page1Bytes,
                1,
                0,
                0,
                reference: sprintf('job-%d/p1', $jobId),
                extraMeta: [
                    'source'  => 'phase_a',
                    'job_id'  => $jobId,
                    'pdf_name' => $file->getClientOriginalName(),
                ],
            );
            $tOcrMs = (int) round((microtime(true) - $tBeforeOcr) * 1000);
            $this->emit('ok', sprintf(
                'Textract: %d Blocks in %d ms (%s)',
                \count($result->blocks),
                $tOcrMs,
                $result->wasCached ? 'CACHE-HIT, 0,00 ct' : 'CACHE-MISS, ≈0,14 ct',
            ));

            $issue = $this->issueDetector->detectFromResult($result);
            if ($issue !== null) {
                $this->emit('ok', sprintf(
                    'Issue erkannt: %s · Confidence %s%%',
                    $issue->label(),
                    round($issue->confidence, 1),
                ));
                $this->jobs->update($jobId, [
                    'detected_issue_number' => $issue->number,
                    'detected_issue_date'   => $issue->dateText,
                ]);
                $this->eventLogger->log(
                    eventType: 'issue.detected',
                    reference: 'MBJ-' . $issue->number,
                    metadata: [
                        'source'  => 'phase_a',
                        'job_id'  => $jobId,
                        'issue'   => $issue->toArray(),
                    ],
                );
            } else {
                $this->emit('warn', 'Issue-Number nicht erkennbar — Footer-Block ohne "Nr. XXX" Pattern.');
            }

            // Status update
            $this->jobs->update($jobId, ['status' => JobStatus::SplitDone]);
            $this->emit('ok', sprintf('Job aktualisiert auf status=%s', JobStatus::SplitDone->value));

            $this->eventLogger->log(
                eventType: 'job.phase_a_done',
                reference: 'job-' . $jobId,
                metadata: [
                    'source'     => 'phase_a',
                    'job_id'     => $jobId,
                    'duration_ms' => (int) round((microtime(true) - $tStart) * 1000),
                ],
            );

            $tTotalMs = (int) round((microtime(true) - $tStart) * 1000);
            $this->emit('ok', sprintf('Phase A done in %d ms.', $tTotalMs));

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

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }
        return number_format($bytes / (1024 * 1024), 2, ',', '.') . ' MB';
    }

    private function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos !== false ? substr($fqn, $pos + 1) : $fqn;
    }
}
