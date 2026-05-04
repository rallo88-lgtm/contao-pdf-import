<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inspektor — GET-only.
 *
 * Ohne Param: Form-Page mit DOS-Box-Live-Log. Submit hijackt JS-seitig und
 * schickt Multipart an PdfImportInspectorStreamController; das Result wird
 * in var/cache/textract/runs/{hash}.json persistiert.
 *
 * Mit ?run={sha1}: rendert das gespeicherte Result aus der run-Datei
 * (Two-Pane-Layout mit Image + Block-Liste + BBox-Overlay).
 */
#[Route(
    '/contao/pdf-import-inspector',
    name: 'pdf_import_inspector',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['GET'],
)]
class PdfImportInspectorController extends AbstractBackendController
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {}

    public function __invoke(Request $request): Response
    {
        $runId = $request->query->get('run');
        if (\is_string($runId) && preg_match('/^[a-f0-9]{40}$/', $runId)) {
            return $this->renderResult($runId);
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

    private function renderResult(string $runId): Response
    {
        $runFile = $this->projectDir . '/var/cache/textract/runs/' . $runId . '.json';
        if (!is_file($runFile)) {
            return $this->renderForm('Run nicht gefunden (Cache aufgeraeumt?). Bitte neu hochladen.');
        }

        $raw  = file_get_contents($runFile);
        $data = json_decode((string) $raw, true);
        if (!\is_array($data)) {
            return $this->renderForm('Run-Datei korrupt.');
        }

        return $this->render(
            '@ContaoPdfImport/backend/pdf_import_inspector.html.twig',
            array_merge(['mode' => 'result'], $data),
        );
    }
}
