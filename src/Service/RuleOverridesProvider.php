<?php

namespace Rallo\ContaoPdfImport\Service;

use Doctrine\DBAL\Connection;

/**
 * Liest die globalen Rule-Toggles aus tl_pdf_import_config (Singleton id=1).
 * Defaults = alle aktiv. Ergebnis wird pro Request einmalig gecached.
 *
 * Multi-Column-Split kennt zusaetzlich einen ENV-Force-Override
 * PDFIMPORT_DISABLE_MULTI_COL_SPLIT=1 fuer CLI-/Server-Notnaegel ohne
 * BE-Login. Wenn ENV gesetzt ist, ueberstimmt sie den DCA-Wert.
 */
final class RuleOverridesProvider
{
    /** @var array<string, bool>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function isCaptionHeuristicEnabled(): bool
    {
        return $this->load()['caption_heuristic'];
    }

    public function isListDedupEnabled(): bool
    {
        return $this->load()['list_dedup'];
    }

    public function isReadingOrderEnabled(): bool
    {
        return $this->load()['reading_order_reorder'];
    }

    public function isMultiColSplitEnabled(): bool
    {
        if (((string) getenv('PDFIMPORT_DISABLE_MULTI_COL_SPLIT')) === '1') {
            return false;
        }
        return $this->load()['multi_col_split'];
    }

    /**
     * Liefert alle Werte als bool-Map (fuer UI-Rendering).
     *
     * @return array{caption_heuristic: bool, list_dedup: bool, reading_order_reorder: bool, multi_col_split: bool}
     */
    public function getAll(): array
    {
        return $this->load();
    }

    /**
     * @return array{caption_heuristic: bool, list_dedup: bool, reading_order_reorder: bool, multi_col_split: bool}
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $defaults = [
            'caption_heuristic'     => true,
            'list_dedup'            => true,
            'reading_order_reorder' => true,
            'multi_col_split'       => true,
        ];

        try {
            $row = $this->db->fetchAssociative(
                'SELECT caption_heuristic, list_dedup, reading_order_reorder, multi_col_split FROM tl_pdf_import_config WHERE id = 1',
            );
        } catch (\Throwable) {
            // Tabelle existiert noch nicht (vor erstem Schema-Sync) — Defaults.
            return $this->cache = $defaults;
        }

        if ($row === false) {
            return $this->cache = $defaults;
        }

        return $this->cache = [
            'caption_heuristic'     => (bool) $row['caption_heuristic'],
            'list_dedup'            => (bool) $row['list_dedup'],
            'reading_order_reorder' => (bool) $row['reading_order_reorder'],
            'multi_col_split'       => (bool) $row['multi_col_split'],
        ];
    }
}
