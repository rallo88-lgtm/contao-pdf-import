<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Doctrine\DBAL\Connection;
use Rallo\ContaoPdfImport\Service\Pricing\TextractPricing;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/contao/pdf-import-stats',
    name: 'pdf_import_stats',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['GET'],
)]
class PdfImportStatsController extends AbstractBackendController
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function __invoke(): Response
    {
        if (!$this->tableExists()) {
            return $this->render('@ContaoPdfImport/backend/pdf_import_stats.html.twig', [
                'tableMissing' => true,
            ]);
        }

        $totals = $this->db->fetchAssociative("
            SELECT
                COUNT(*) AS total_events,
                SUM(CASE WHEN event_type = 'ocr.analyze' THEN 1 ELSE 0 END) AS ocr_total,
                SUM(CASE WHEN event_type = 'ocr.analyze' AND cache_hit = 1 THEN 1 ELSE 0 END) AS ocr_cache_hits,
                COALESCE(SUM(cost_micros_eur), 0) AS total_cost_micros
            FROM tl_pdf_import_event
        ") ?: [];

        $byType = $this->db->fetchAllAssociative("
            SELECT event_type, COUNT(*) AS cnt, COALESCE(SUM(cost_micros_eur), 0) AS cost
            FROM tl_pdf_import_event
            GROUP BY event_type
            ORDER BY cnt DESC
        ");

        $recent = $this->db->fetchAllAssociative("
            SELECT id, tstamp, event_type, user_id, reference, cost_micros_eur, cache_hit, metadata
            FROM tl_pdf_import_event
            ORDER BY id DESC
            LIMIT 50
        ");

        $ocrTotal     = (int) ($totals['ocr_total'] ?? 0);
        $ocrCacheHits = (int) ($totals['ocr_cache_hits'] ?? 0);
        $totalCost    = (int) ($totals['total_cost_micros'] ?? 0);

        return $this->render('@ContaoPdfImport/backend/pdf_import_stats.html.twig', [
            'tableMissing' => false,
            'kpi' => [
                'total_events'   => (int) ($totals['total_events'] ?? 0),
                'ocr_total'      => $ocrTotal,
                'ocr_cache_hits' => $ocrCacheHits,
                'cache_rate'     => $ocrTotal > 0 ? round($ocrCacheHits * 100 / $ocrTotal, 1) : null,
                'total_cost'     => TextractPricing::formatMicros($totalCost),
                'estimated_calls' => $ocrTotal - $ocrCacheHits,
            ],
            'byType' => array_map(static fn(array $r) => [
                'type'  => $r['event_type'],
                'count' => (int) $r['cnt'],
                'cost'  => TextractPricing::formatMicros((int) $r['cost']),
            ], $byType),
            'recent' => array_map(static function (array $r): array {
                $meta = null;
                if (!empty($r['metadata'])) {
                    $meta = json_decode((string) $r['metadata'], true);
                }
                return [
                    'id'         => (int) $r['id'],
                    'tstamp'     => (int) $r['tstamp'],
                    'event_type' => (string) $r['event_type'],
                    'user_id'    => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                    'reference'  => $r['reference'],
                    'cost'       => TextractPricing::formatMicros((int) $r['cost_micros_eur']),
                    'cache_hit'  => (int) $r['cache_hit'] === 1,
                    'meta'       => is_array($meta) ? $meta : [],
                ];
            }, $recent),
        ]);
    }

    private function tableExists(): bool
    {
        try {
            return $this->db->createSchemaManager()->tablesExist(['tl_pdf_import_event']);
        } catch (\Throwable) {
            return false;
        }
    }
}
