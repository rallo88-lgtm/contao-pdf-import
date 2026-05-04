<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\BlockMappingProvider;
use Rallo\ContaoPdfImport\Service\BlockTextExtractor;
use Rallo\ContaoPdfImport\Service\EventLogger;
use Rallo\ContaoPdfImport\Service\Job\JobFilesystem;
use Rallo\ContaoPdfImport\Service\Job\JobRepository;
use Rallo\ContaoPdfImport\Service\Job\JobStatus;
use Rallo\ContaoPdfImport\Service\Ocr\OcrProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Phase C — Textract-Loop fuer alle in Phase B ausgewaehlten Pages.
 *
 * Je Page: Image-Bytes laden, Textract-Call (durch Caching+Logging-Decorator),
 * angereicherte Block-Liste mit Mapping-Vorschau in payload.pages_data
 * persistieren. Pro Page schreibt LoggingOcrProvider sowieso ein
 * ocr.analyze Event in tl_pdf_import_event — Ralph sieht das in Stats.
 *
 * Final: status=ocr_done, redirect zu Job-Detail.
 */
#[Route(
    '/contao/pdf-import/phase-c/stream',
    name: 'pdf_import_phase_c_stream',
    defaults: ['_scope' => 'backend', '_token_check' => false],
    methods: ['POST'],
)]
class PdfImportPhaseCStreamController extends AbstractBackendController
{
    public function __construct(
        private readonly OcrProviderInterface $ocr,
        private readonly BlockTextExtractor $textExtractor,
        private readonly BlockMappingProvider $mapping,
        private readonly JobRepository $jobs,
        private readonly JobFilesystem $jobFs,
        private readonly EventLogger $eventLogger,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
        #[Autowire(env: 'AWS_REGION')]           private readonly string $awsRegion,
        #[Autowire(env: 'AWS_ACCESS_KEY_ID')]    private readonly string $awsKey,
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
                $this->emit('error', 'Job nicht gefunden · id=' . $jobId);
                $this->emitDone();
                return;
            }
            if (!\in_array($job->status, [JobStatus::PagesSelected, JobStatus::OcrDone], true)) {
                $this->emit('error', 'Falscher Status: ' . $job->status->value . ' (erwartet pages_selected oder ocr_done)');
                $this->emitDone();
                return;
            }

            $pages = $job->payload['selected_pages'] ?? [];
            if (!\is_array($pages) || $pages === []) {
                $this->emit('error', 'Keine Pages ausgewählt.');
                $this->emitDone();
                return;
            }
            $pages = array_values(array_map('intval', $pages));

            $this->jobs->update($jobId, ['last_phase' => 'phase_c']);

            $this->emit('info', sprintf(
                'Phase C · Job #%d · MBJ-%s · %d Pages: [%s]',
                $jobId,
                $job->detectedIssueNumber ?? '???',
                \count($pages),
                implode(',', $pages),
            ));

            $masked = \strlen($this->awsKey) >= 8
                ? substr($this->awsKey, 0, 4) . '****' . substr($this->awsKey, -4)
                : '****';
            $this->emit('dim', sprintf(
                'AWS-Textract analyzeDocument(LAYOUT) · region=%s · key=%s',
                $this->awsRegion, $masked,
            ));

            $pagesData    = $job->payload['pages_data'] ?? [];
            $costMicros   = 0;
            $cacheHits    = 0;
            $cacheMisses  = 0;

            foreach ($pages as $pageNumber) {
                $pagePath = $this->jobFs->getPagePath($jobId, $pageNumber);
                if (!is_file($pagePath)) {
                    $this->emit('warn', sprintf('Page %d: Datei fehlt (%s) — übersprungen', $pageNumber, $pagePath));
                    continue;
                }

                $bytes = file_get_contents($pagePath);
                $dims  = @getimagesize($pagePath);
                $w     = $dims !== false ? (int) $dims[0] : 0;
                $h     = $dims !== false ? (int) $dims[1] : 0;

                $this->emit('info', sprintf('Page %d · %d × %d px · %s', $pageNumber, $w, $h, $this->formatBytes(\strlen($bytes))));

                $tBefore = microtime(true);
                $result  = $this->ocr->analyzePage(
                    $bytes,
                    $pageNumber,
                    $w,
                    $h,
                    reference: sprintf('job-%d/p%d', $jobId, $pageNumber),
                    extraMeta: [
                        'source'  => 'phase_c',
                        'job_id'  => $jobId,
                        'pdf_name' => $job->pdfName,
                    ],
                );
                $tOcrMs = (int) round((microtime(true) - $tBefore) * 1000);

                if ($result->wasCached) {
                    $cacheHits++;
                    $this->emit('ok', sprintf('Page %d · Cache HIT · %d Blocks in %d ms', $pageNumber, \count($result->blocks), $tOcrMs));
                } else {
                    $cacheMisses++;
                    $costMicros += 1395;
                    $this->emit('ok', sprintf('Page %d · Cache MISS · %d Blocks in %d ms · 0,14 ct', $pageNumber, \count($result->blocks), $tOcrMs));
                }

                // Layout-Breakdown
                $byType = [];
                foreach ($result->blocks as $b) {
                    $bt = $b['BlockType'] ?? 'UNKNOWN';
                    if (str_starts_with($bt, 'LAYOUT_')) {
                        $byType[$bt] = ($byType[$bt] ?? 0) + 1;
                    }
                }
                $summary = [];
                foreach ($byType as $bt => $n) {
                    $summary[] = $n . '× ' . substr($bt, 7);
                }
                if ($summary !== []) {
                    $this->emit('dim', '  LAYOUT: ' . implode(', ', $summary));
                }

                // Build enriched blocks (gleiches Format wie Inspector run-data)
                $blockMap = $result->getBlockMap();
                $enriched = [];
                foreach ($result->getLayoutBlocks() as $b) {
                    $mapping    = $this->mapping->mapType($b['BlockType']);
                    $enriched[] = [
                        'id'           => $b['Id'] ?? '',
                        'type'         => $b['BlockType'],
                        'typeShort'    => strtolower(str_replace('LAYOUT_', '', $b['BlockType'])),
                        'confidence'   => round((float) ($b['Confidence'] ?? 0), 1),
                        'box'          => $b['Geometry']['BoundingBox'] ?? ['Left' => 0, 'Top' => 0, 'Width' => 0, 'Height' => 0],
                        'text'         => $this->textExtractor->extract($b, $blockMap),
                        'mappingCe'    => $mapping['ce'],
                        'mappingLabel' => $mapping['label'],
                        'color'        => $mapping['color'],
                    ];
                }

                $pagesData[(string) $pageNumber] = [
                    'imageWidth'  => $w,
                    'imageHeight' => $h,
                    'blocks'      => $enriched,
                    'totalBlocks' => \count($result->blocks),
                    'wasCached'   => $result->wasCached,
                    'imageHash'   => sha1($bytes),
                ];
            }

            // Persist
            $payload                = $job->payload;
            $payload['pages_data']  = $pagesData;
            $payload['phase_c_summary'] = [
                'cache_hits'   => $cacheHits,
                'cache_misses' => $cacheMisses,
                'cost_micros'  => $costMicros,
                'pages_count'  => \count($pages),
            ];

            $this->jobs->update($jobId, [
                'status'  => JobStatus::OcrDone,
                'payload' => $payload,
            ]);
            $this->emit('ok', sprintf('Job aktualisiert auf status=%s', JobStatus::OcrDone->value));

            $tTotalMs = (int) round((microtime(true) - $tStart) * 1000);
            $this->emit('ok', sprintf(
                'Phase C done in %d ms · %d Cache-Hits · %d MISS · Total ≈ %s',
                $tTotalMs,
                $cacheHits,
                $cacheMisses,
                $this->formatMicros($costMicros),
            ));

            $this->eventLogger->log(
                eventType: 'job.phase_c_done',
                reference: 'job-' . $jobId,
                metadata: [
                    'source'       => 'phase_c',
                    'job_id'       => $jobId,
                    'pages'        => $pages,
                    'cache_hits'   => $cacheHits,
                    'cache_misses' => $cacheMisses,
                    'cost_micros'  => $costMicros,
                    'duration_ms'  => $tTotalMs,
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

    private function formatMicros(int $micros): string
    {
        if ($micros === 0) {
            return '0,00 ct';
        }
        if ($micros < 100_000) {
            return number_format($micros / 10_000, 2, ',', '.') . ' ct';
        }
        return number_format($micros / 1_000_000, 4, ',', '.') . ' €';
    }

    private function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos !== false ? substr($fqn, $pos + 1) : $fqn;
    }
}
