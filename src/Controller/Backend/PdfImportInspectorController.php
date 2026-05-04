<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\BlockMappingProvider;
use Rallo\ContaoPdfImport\Service\BlockTextExtractor;
use Rallo\ContaoPdfImport\Service\Ocr\OcrProviderInterface;
use Rallo\ContaoPdfImport\Service\PdfPageRasterizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/contao/pdf-import-inspector',
    name: 'pdf_import_inspector',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['GET', 'POST'],
)]
class PdfImportInspectorController extends AbstractBackendController
{
    public function __construct(
        private readonly PdfPageRasterizer $rasterizer,
        private readonly OcrProviderInterface $ocr,
        private readonly BlockTextExtractor $textExtractor,
        private readonly BlockMappingProvider $mapping,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {}

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->analyze($request);
        }
        return $this->renderForm();
    }

    private function renderForm(?string $error = null): Response
    {
        return $this->render('@ContaoPdfImport/backend/pdf_import_inspector.html.twig', [
            'mode'  => 'form',
            'error' => $error,
        ]);
    }

    private function analyze(Request $request): Response
    {
        $file       = $request->files->get('pdf_upload');
        $pageNumber = max(1, (int) $request->request->get('page_number', 1));

        if (!$file) {
            return $this->renderForm('Keine Datei hochgeladen.');
        }
        if (!$file->isValid()) {
            return $this->renderForm('Upload-Fehler: ' . $file->getErrorMessage());
        }
        $mime = $file->getMimeType();
        if (!\in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            return $this->renderForm('Nur PDF-Dateien zulaessig (mime: ' . ($mime ?? 'unknown') . ').');
        }

        try {
            $pdfPath   = $file->getRealPath();
            $pageCount = $this->rasterizer->getPageCount($pdfPath);

            if ($pageNumber > $pageCount) {
                return $this->renderForm(sprintf('Seite %d existiert nicht (PDF hat %d Seiten).', $pageNumber, $pageCount));
            }

            $rastered = $this->rasterizer->rasterizePage($pdfPath, $pageNumber);
            $hash     = sha1($rastered['bytes']);

            $imgCacheDir = $this->projectDir . '/var/cache/textract/img';
            if (!is_dir($imgCacheDir)) {
                mkdir($imgCacheDir, 0775, true);
            }
            file_put_contents($imgCacheDir . '/' . $hash . '.jpg', $rastered['bytes']);

            $result = $this->ocr->analyzePage(
                $rastered['bytes'],
                $pageNumber,
                $rastered['width'],
                $rastered['height'],
            );
        } catch (\Throwable $e) {
            return $this->renderForm('Verarbeitung fehlgeschlagen: ' . $e->getMessage());
        }

        $blockMap = $result->getBlockMap();
        $enriched = [];
        foreach ($result->getLayoutBlocks() as $b) {
            $mapping = $this->mapping->mapType($b['BlockType']);
            $enriched[] = [
                'id'           => $b['Id']         ?? '',
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

        return $this->render('@ContaoPdfImport/backend/pdf_import_inspector.html.twig', [
            'mode'        => 'result',
            'pdfName'     => $file->getClientOriginalName(),
            'pageNumber'  => $pageNumber,
            'pageCount'   => $pageCount,
            'imageHash'   => $hash,
            'imageWidth'  => $rastered['width'],
            'imageHeight' => $rastered['height'],
            'blocks'      => $enriched,
            'totalBlocks' => count($result->blocks),
        ]);
    }
}
