<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Speichert die Rule-Toggles aus der Config-Page in tl_pdf_import_config
 * (Singleton id=1). INSERT ... ON DUPLICATE KEY UPDATE.
 *
 * POST-only mit REQUEST_TOKEN-Check, redirect zurueck zur Config-Page
 * mit ?saved=1.
 */
#[Route(
    '/contao/pdf-import-config/save',
    name: 'pdf_import_config_save',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['POST'],
)]
class PdfImportConfigSaveController extends AbstractBackendController
{
    public function __construct(
        private readonly Connection $db,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(Request $request): Response
    {
        $captionHeuristic    = $request->request->get('caption_heuristic')     ? 1 : 0;
        $listDedup           = $request->request->get('list_dedup')            ? 1 : 0;
        $readingOrderReorder = $request->request->get('reading_order_reorder') ? 1 : 0;
        $multiColSplit       = $request->request->get('multi_col_split')       ? 1 : 0;

        $this->db->executeStatement(
            'INSERT INTO tl_pdf_import_config (id, tstamp, caption_heuristic, list_dedup, reading_order_reorder, multi_col_split)
             VALUES (1, :ts, :cap, :list, :read, :mcs)
             ON DUPLICATE KEY UPDATE
                tstamp = VALUES(tstamp),
                caption_heuristic = VALUES(caption_heuristic),
                list_dedup = VALUES(list_dedup),
                reading_order_reorder = VALUES(reading_order_reorder),
                multi_col_split = VALUES(multi_col_split)',
            [
                'ts'   => time(),
                'cap'  => $captionHeuristic,
                'list' => $listDedup,
                'read' => $readingOrderReorder,
                'mcs'  => $multiColSplit,
            ],
        );

        return new RedirectResponse(
            $this->urlGenerator->generate('pdf_import_config') . '?saved=1',
            Response::HTTP_SEE_OTHER,
        );
    }
}
