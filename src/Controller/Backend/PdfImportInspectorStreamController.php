<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\BlockMappingProvider;
use Rallo\ContaoPdfImport\Service\BlockTextExtractor;
use Rallo\ContaoPdfImport\Service\Ocr\OcrProviderInterface;
use Rallo\ContaoPdfImport\Service\PdfPageRasterizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SSE-Endpoint des Inspektors. POST mit pdf_upload + page_number, antwortet
 * als text/event-stream mit Phasen-Output (Rasterize, Cache-Check, Textract,
 * Logging, Done). Result wird nach var/cache/textract/runs/{hash}.json
 * persistiert; Final-Event sendet die GET-URL des Inspektor-Controllers
 * mit ?run=hash zum Anzeigen der Ergebnis-Page.
 *
 * _token_check: false weil Multipart+SSE den Standard-Check umgeht.
 * Frontend prueft REQUEST_TOKEN selbst gegen Contaos Csrf-Token-Manager
 * (TODO Phase 3, jetzt offen — ist BE-only und kein Schreib-Risiko).
 */
#[Route(
    '/contao/pdf-import-inspector/stream',
    name: 'pdf_import_inspector_stream',
    defaults: ['_scope' => 'backend', '_token_check' => false],
    methods: ['POST'],
)]
class PdfImportInspectorStreamController extends AbstractBackendController
{
    public function __construct(
        private readonly PdfPageRasterizer $rasterizer,
        private readonly OcrProviderInterface $ocr,
        private readonly BlockTextExtractor $textExtractor,
        private readonly BlockMappingProvider $mapping,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(param: 'kernel.project_dir')]      private readonly string $projectDir,
        #[Autowire(env: 'AWS_REGION')]                private readonly string $awsRegion,
        #[Autowire(env: 'AWS_ACCESS_KEY_ID')]         private readonly string $awsKey,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $tStart     = microtime(true);
        $file       = $request->files->get('pdf_upload');
        $pageNumber = max(1, (int) $request->request->get('page_number', 1));

        return new StreamedResponse(function () use ($file, $pageNumber, $tStart) {
            @set_time_limit(0);
            @ob_implicit_flush(true);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            $this->run($file, $pageNumber, $tStart);
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    private function run(?UploadedFile $file, int $pageNumber, float $tStart): void
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

            $this->emit('info', sprintf(
                'PDF empfangen: %s (%s)',
                $file->getClientOriginalName(),
                $this->formatBytes($file->getSize() ?: 0),
            ));

            $mime = $file->getMimeType();
            if (!\in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
                $this->emit('error', 'Kein PDF · mime=' . ($mime ?? 'unknown'));
                $this->emitDone();
                return;
            }
            $this->emit('ok', 'MIME-Check OK · ' . $mime);

            $pdfPath = $file->getRealPath();
            $this->emit('info', 'Imagick · pingImage() für Page-Count...');
            $pageCount = $this->rasterizer->getPageCount($pdfPath);
            $this->emit('ok', sprintf('PDF hat %d Seiten', $pageCount));

            if ($pageNumber > $pageCount) {
                $this->emit('error', sprintf('Seite %d existiert nicht (PDF hat %d).', $pageNumber, $pageCount));
                $this->emitDone();
                return;
            }

            $this->emit('info', sprintf('Imagick · readImage([%d]) @ 150 dpi...', $pageNumber - 1));
            $rastered = $this->rasterizer->rasterizePage($pdfPath, $pageNumber);
            $this->emit('ok', sprintf(
                'Gerastert: %d × %d px (%s JPEG)',
                $rastered['width'], $rastered['height'], $this->formatBytes(strlen($rastered['bytes'])),
            ));

            $hash = sha1($rastered['bytes']);
            $this->emit('dim', 'Cache-Key sha1: ' . substr($hash, 0, 12) . '…');

            $imgCacheDir = $this->projectDir . '/var/cache/textract/img';
            if (!is_dir($imgCacheDir)) {
                mkdir($imgCacheDir, 0775, true);
            }
            file_put_contents($imgCacheDir . '/' . $hash . '.jpg', $rastered['bytes']);

            $ocrCachePath = $this->projectDir . '/var/cache/textract/' . $hash . '.json';
            $willHit      = is_file($ocrCachePath);

            if ($willHit) {
                $this->emit('ok', 'Cache HIT · kein AWS-Call notwendig');
            } else {
                $masked = strlen($this->awsKey) >= 8
                    ? substr($this->awsKey, 0, 4) . '****' . substr($this->awsKey, -4)
                    : '****';
                $this->emit('info', sprintf(
                    'AWS-Textract analyzeDocument(LAYOUT) · region=%s · key=%s',
                    $this->awsRegion,
                    $masked,
                ));
            }

            $tBefore = microtime(true);
            $result  = $this->ocr->analyzePage(
                $rastered['bytes'],
                $pageNumber,
                $rastered['width'],
                $rastered['height'],
                reference: sprintf('%s/p%d', $file->getClientOriginalName(), $pageNumber),
                extraMeta: [
                    'source'   => 'inspector',
                    'pdf_name' => $file->getClientOriginalName(),
                ],
            );
            $tOcrMs = (int) round((microtime(true) - $tBefore) * 1000);

            $byType = [];
            foreach ($result->blocks as $b) {
                $bt          = $b['BlockType'] ?? 'UNKNOWN';
                $byType[$bt] = ($byType[$bt] ?? 0) + 1;
            }
            $layoutSummary = [];
            foreach ($byType as $bt => $n) {
                if (str_starts_with($bt, 'LAYOUT_')) {
                    $layoutSummary[] = $n . '× ' . substr($bt, 7);
                }
            }

            $this->emit('ok', sprintf('Textract: %d Blocks total in %d ms', count($result->blocks), $tOcrMs));
            if ($layoutSummary !== []) {
                $this->emit('dim', 'LAYOUT: ' . implode(', ', $layoutSummary));
            }

            $this->emit('ok', $result->wasCached
                ? 'Event geloggt: cache_hit=1, cost=0,00 ct'
                : 'Event geloggt: cache_hit=0, cost=≈0,14 ct');

            // Build enriched run-data, persistieren fuer GET ?run=hash
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

            $runDir = $this->projectDir . '/var/cache/textract/runs';
            if (!is_dir($runDir)) {
                mkdir($runDir, 0775, true);
            }
            file_put_contents($runDir . '/' . $hash . '.json', json_encode([
                'imageHash'   => $hash,
                'pdfName'     => $file->getClientOriginalName(),
                'pageNumber'  => $pageNumber,
                'pageCount'   => $pageCount,
                'imageWidth'  => $rastered['width'],
                'imageHeight' => $rastered['height'],
                'blocks'      => $enriched,
                'totalBlocks' => count($result->blocks),
                'wasCached'   => $result->wasCached,
            ], JSON_UNESCAPED_UNICODE));

            $tTotalMs = (int) round((microtime(true) - $tStart) * 1000);
            $this->emit('ok', sprintf('Done in %d ms.', $tTotalMs));

            $resultUrl = $this->urlGenerator->generate(
                'pdf_import_inspector',
                ['run' => $hash],
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
