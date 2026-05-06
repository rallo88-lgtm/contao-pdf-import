<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\BlockMappingProvider;
use Rallo\ContaoPdfImport\Service\ProcessingRulesProvider;
use Rallo\ContaoPdfImport\Service\RuleOverridesProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/contao/pdf-import-config', name: 'pdf_import_config', defaults: ['_scope' => 'backend', '_token_check' => true])]
class PdfImportConfigController extends AbstractBackendController
{
    public function __construct(
        private readonly BlockMappingProvider $blockMapping,
        private readonly ProcessingRulesProvider $processingRules,
        private readonly RuleOverridesProvider $ruleOverrides,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $this->render('@ContaoPdfImport/backend/pdf_import_config.html.twig', [
            'blockMapping'    => $this->blockMapping->getAll(),
            'processingRules' => $this->processingRules->getProcessingRules(),
            'editorialHints'  => $this->processingRules->getEditorialHints(),
            'rules'           => $this->ruleOverrides->getAll(),
            'envMultiColForced' => ((string) getenv('PDFIMPORT_DISABLE_MULTI_COL_SPLIT')) === '1',
            'saved'           => $request->query->getBoolean('saved'),
            'phases'          => [
                ['1', 'OCR-Provider',         'Textract-API-Key + Region (oder spaeter Tesseract-Fallback)'],
                ['2', 'News-Archive-Mapping', 'tl_news_archive ID(s) als Default-Ziel(e) fuer Imports'],
                ['3', 'Issue-Detector',       'Regex / Strategy zur Ausgabe-Nummer-Extraktion'],
                ['4', 'Block-CE-Override',    'JSON-Override der Default-Block-CE-Map'],
            ],
        ]);
    }
}
