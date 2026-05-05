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

                // Pre-process: LAYOUT_LIST referenziert pro Item ein nested
                // LAYOUT_TEXT (Textract liefert die Items doppelt — einmal als
                // CHILDren der LIST, einmal parallel auf Top-Level). Wir
                // markieren die Items mit listParentId und sammeln pro LIST
                // die Items als String-Array, damit Phase E daraus ein
                // tl_content type=list bauen kann statt doppelter <p>s.
                $layoutBlocks = $result->getLayoutBlocks();

                // Reading-Order-Reorder: Multi-Column-Layouts (Magazin)
                // erkennen via Cluster-Count auf left-Position und Blocks
                // spalten-weise neu sortieren. Vollbreite Blocks (Width >0.5
                // oder ausserhalb der Cluster) sind Section-Anker, die
                // bleiben in ihrer Top-Position und unterbrechen die Span.
                $layoutBlocks = $this->reorderForReadingOrder($layoutBlocks);

                // Caption-Heuristik: pro LAYOUT_FIGURE finde den ersten
                // LAYOUT_TEXT direkt darunter (vertikal <5% Diff), klein
                // (Hoehe <6%, max ~3 Zeilen) und horizontal innerhalb der
                // Figure-Bounds (mit 2% Tolerance). Eindeutige 1:1-Zuordnung,
                // ein Text wird hoechstens einer Figure als Caption zugewiesen.
                $captionMembership = [];     // text-block-id => figure-block-id
                $captionByFigureId = [];     // figure-block-id => string
                foreach ($layoutBlocks as $f) {
                    if (($f['BlockType'] ?? '') !== 'LAYOUT_FIGURE') {
                        continue;
                    }
                    $fbb = $f['Geometry']['BoundingBox'] ?? null;
                    if ($fbb === null) {
                        continue;
                    }
                    $figureId = $f['Id'] ?? '';
                    $fBottom  = (float) $fbb['Top'] + (float) $fbb['Height'];
                    $fLeft    = (float) $fbb['Left'];
                    $fRight   = $fLeft + (float) $fbb['Width'];

                    $best        = null;
                    $bestTextTop = null;
                    foreach ($layoutBlocks as $t) {
                        if (($t['BlockType'] ?? '') !== 'LAYOUT_TEXT') {
                            continue;
                        }
                        $tId = $t['Id'] ?? '';
                        if (isset($captionMembership[$tId])) {
                            continue; // bereits als Caption einer anderen Figure verbraucht
                        }
                        $tbb = $t['Geometry']['BoundingBox'] ?? null;
                        if ($tbb === null) {
                            continue;
                        }
                        $tTop    = (float) $tbb['Top'];
                        $tHeight = (float) $tbb['Height'];
                        $tLeft   = (float) $tbb['Left'];
                        $tRight  = $tLeft + (float) $tbb['Width'];

                        if ($tTop < $fBottom || $tTop - $fBottom > 0.05) {
                            continue;
                        }
                        if ($tHeight > 0.06) {
                            continue;
                        }
                        if ($tLeft < $fLeft - 0.02 || $tRight > $fRight + 0.02) {
                            continue;
                        }
                        if ($bestTextTop === null || $tTop < $bestTextTop) {
                            $best        = $t;
                            $bestTextTop = $tTop;
                        }
                    }
                    if ($best !== null) {
                        $bestId                       = $best['Id'] ?? '';
                        $captionMembership[$bestId]   = $figureId;
                        $captionByFigureId[$figureId] = $this->textExtractor->extract($best, $blockMap);
                    }
                }

                $listMembership = [];          // text-block-id => list-block-id
                $listItemsByListId = [];       // list-block-id => string[]
                foreach ($layoutBlocks as $b) {
                    if (($b['BlockType'] ?? '') !== 'LAYOUT_LIST') {
                        continue;
                    }
                    $listId = $b['Id'] ?? '';
                    $items  = [];
                    foreach ($b['Relationships'] ?? [] as $rel) {
                        if (($rel['Type'] ?? '') !== 'CHILD') {
                            continue;
                        }
                        foreach ($rel['Ids'] ?? [] as $cid) {
                            $cb = $blockMap[$cid] ?? null;
                            if ($cb === null) {
                                continue;
                            }
                            // Kind-Texts können selbst LAYOUT_TEXTs sein (Magazin-Listen)
                            // oder direkt LINE-Blocks. extract() handhabt beides.
                            $itemText = $this->textExtractor->extract($cb, $blockMap);
                            if ($itemText !== '') {
                                $items[] = $itemText;
                            }
                            if (($cb['BlockType'] ?? '') === 'LAYOUT_TEXT') {
                                $listMembership[$cid] = $listId;
                            }
                        }
                    }
                    $listItemsByListId[$listId] = $items;
                }

                $enriched = [];
                foreach ($layoutBlocks as $b) {
                    $blockId = $b['Id'] ?? '';
                    $mapping = $this->mapping->mapType($b['BlockType']);
                    $entry   = [
                        'id'           => $blockId,
                        'type'         => $b['BlockType'],
                        'typeShort'    => strtolower(str_replace('LAYOUT_', '', $b['BlockType'])),
                        'confidence'   => round((float) ($b['Confidence'] ?? 0), 1),
                        'box'          => $b['Geometry']['BoundingBox'] ?? ['Left' => 0, 'Top' => 0, 'Width' => 0, 'Height' => 0],
                        'text'         => $this->textExtractor->extract($b, $blockMap),
                        'mappingCe'    => $mapping['ce'],
                        'mappingLabel' => $mapping['label'],
                        'color'        => $mapping['color'],
                    ];
                    if (($b['BlockType'] ?? '') === 'LAYOUT_LIST') {
                        $entry['listItems'] = $listItemsByListId[$blockId] ?? [];
                    }
                    if (($b['BlockType'] ?? '') === 'LAYOUT_FIGURE' && isset($captionByFigureId[$blockId])) {
                        $entry['caption'] = $captionByFigureId[$blockId];
                    }
                    if (isset($listMembership[$blockId])) {
                        $entry['listParentId'] = $listMembership[$blockId];
                    }
                    if (isset($captionMembership[$blockId])) {
                        $entry['captionParentId'] = $captionMembership[$blockId];
                    }
                    $enriched[] = $entry;
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

    /**
     * Sortiert Layout-Blocks nach Reading-Order fuer Magazin-Multi-Column-
     * Pages. Trigger: 3 aufeinander folgende LAYOUT_FIGUREs mit distinkten
     * left-Positionen (>=10% auseinander) — typisch fuer 3-Spalten-Sektionen
     * mit Bild oben pro Spalte. Span beginnt bei der Triple und endet beim
     * ersten Section-Anker (vollbreit-Block, naechste Triple, oder Block
     * dessen left ausserhalb der Triple-Cluster liegt). Innerhalb des Spans:
     * sortiere nach (column-index, top) damit Spalte fuer Spalte komplett
     * gelesen wird (Bild + Headline + Liste + Text + Liste).
     *
     * Bewusste Einschraenkung: nur 3-FIGURE-Triple triggert. 2-column-Layouts
     * ohne klares Bild-Grid bleiben unsortiert (kein false-positive). Erweiterung
     * folgt wenn mehr Magazin-Beispielseiten getestet sind.
     *
     * @param array<int, array> $blocks
     * @return array<int, array>
     */
    private function reorderForReadingOrder(array $blocks): array
    {
        $count = \count($blocks);
        if ($count < 3) {
            return $blocks;
        }

        $isFigure = static fn(array $b): bool => ($b['BlockType'] ?? '') === 'LAYOUT_FIGURE';
        $leftOf   = static function (array $b): ?float {
            $bb = $b['Geometry']['BoundingBox'] ?? null;
            return $bb !== null ? (float) ($bb['Left'] ?? 0) : null;
        };
        $widthOf = static function (array $b): float {
            $bb = $b['Geometry']['BoundingBox'] ?? null;
            return $bb !== null ? (float) ($bb['Width'] ?? 0) : 0.0;
        };

        // Phase 1: finde Triples = drei aufeinander folgende LAYOUT_FIGUREs
        // mit distinkten left-Werten (paarweise >=10% auseinander).
        $triples = [];
        for ($i = 0; $i <= $count - 3; $i++) {
            if (!$isFigure($blocks[$i]) || !$isFigure($blocks[$i + 1]) || !$isFigure($blocks[$i + 2])) {
                continue;
            }
            $l0 = $leftOf($blocks[$i]);
            $l1 = $leftOf($blocks[$i + 1]);
            $l2 = $leftOf($blocks[$i + 2]);
            if ($l0 === null || $l1 === null || $l2 === null) {
                continue;
            }
            $sortedLefts = [$l0, $l1, $l2];
            sort($sortedLefts);
            if (($sortedLefts[1] - $sortedLefts[0]) < 0.1 || ($sortedLefts[2] - $sortedLefts[1]) < 0.1) {
                continue;
            }
            $triples[$i] = $sortedLefts; // sortierte cluster-centers
        }

        if ($triples === []) {
            return $blocks;
        }

        // Phase 2: pro Triple einen Span aufbauen (start..end exklusiv),
        // der beim Section-Anker oder bei der naechsten Triple endet.
        $spans = [];
        foreach ($triples as $startIdx => $cols) {
            $endIdx = $count;
            for ($i = $startIdx + 3; $i < $count; $i++) {
                if (isset($triples[$i])) {
                    $endIdx = $i;
                    break;
                }
                $b = $blocks[$i];
                if ($widthOf($b) > 0.5) {
                    $endIdx = $i;
                    break;
                }
                $l = $leftOf($b);
                if ($l !== null) {
                    $matches = false;
                    foreach ($cols as $c) {
                        if (abs($c - $l) < 0.05) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        $endIdx = $i;
                        break;
                    }
                }
            }
            $spans[] = ['start' => $startIdx, 'end' => $endIdx, 'cols' => $cols];
        }

        // Phase 3: rebuild blocks. Ausserhalb von Spans bleibt Original-
        // Reihenfolge, innerhalb wird nach (col-index, top) sortiert.
        $colIndex = static function (float $left, array $cols): int {
            $bestI    = 0;
            $bestDiff = 1.0;
            foreach ($cols as $i => $c) {
                $d = abs($c - $left);
                if ($d < $bestDiff) {
                    $bestDiff = $d;
                    $bestI    = $i;
                }
            }
            return $bestI;
        };

        $reordered = [];
        $cursor    = 0;
        foreach ($spans as $span) {
            while ($cursor < $span['start']) {
                $reordered[] = $blocks[$cursor];
                $cursor++;
            }
            $spanBlocks = \array_slice($blocks, $span['start'], $span['end'] - $span['start']);
            usort($spanBlocks, static function (array $a, array $b) use ($colIndex, $span, $leftOf): int {
                $aLeft = $leftOf($a) ?? 0.0;
                $bLeft = $leftOf($b) ?? 0.0;
                $cmp   = $colIndex($aLeft, $span['cols']) <=> $colIndex($bLeft, $span['cols']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                $aTop = (float) ($a['Geometry']['BoundingBox']['Top'] ?? 0);
                $bTop = (float) ($b['Geometry']['BoundingBox']['Top'] ?? 0);
                return $aTop <=> $bTop;
            });
            foreach ($spanBlocks as $b) {
                $reordered[] = $b;
            }
            $cursor = $span['end'];
        }
        while ($cursor < $count) {
            $reordered[] = $blocks[$cursor];
            $cursor++;
        }
        return $reordered;
    }
}
