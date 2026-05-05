<?php

namespace Rallo\ContaoPdfImport\Service;

final class BlockMappingProvider
{
    private const MAP = [
        'LAYOUT_TITLE'          => ['ce' => 'headline', 'label' => 'Headline (h1)',    'color' => '#ef4444'],
        'LAYOUT_SECTION_HEADER' => ['ce' => 'headline', 'label' => 'Headline (h2)',    'color' => '#f97316'],
        'LAYOUT_TEXT'           => ['ce' => 'text',     'label' => 'Text',             'color' => '#3b82f6'],
        'LAYOUT_LIST'           => ['ce' => 'list',     'label' => 'Liste (ul/li)',    'color' => '#8b5cf6'],
        'LAYOUT_TABLE'          => ['ce' => 'image',    'label' => 'Tabelle (als Bild)','color' => '#06b6d4'],
        'LAYOUT_FIGURE'         => ['ce' => 'image',    'label' => 'Bild',             'color' => '#10b981'],
        'LAYOUT_KEY_VALUE'      => ['ce' => 'text',     'label' => 'Text (Key-Value)', 'color' => '#a855f7'],
        'LAYOUT_PAGE_NUMBER'    => ['ce' => null,       'label' => 'Skip',             'color' => '#71717a'],
        'LAYOUT_HEADER'         => ['ce' => null,       'label' => 'Skip',             'color' => '#71717a'],
        'LAYOUT_FOOTER'         => ['ce' => null,       'label' => 'Skip',             'color' => '#71717a'],
    ];

    /**
     * @return array{ce: string|null, label: string, color: string}
     */
    public function mapType(string $blockType): array
    {
        return self::MAP[$blockType] ?? ['ce' => null, 'label' => 'Unknown', 'color' => '#888888'];
    }

    /**
     * Komplette Mapping-Liste fuer die Config-UI. Reihenfolge entspricht
     * Wichtigkeit / Vorkommen-Frequenz im typischen Magazin-Layout.
     *
     * @return array<int, array{type: string, ce: string|null, label: string, color: string}>
     */
    public function getAll(): array
    {
        $out = [];
        foreach (self::MAP as $type => $spec) {
            $out[] = [
                'type'  => $type,
                'ce'    => $spec['ce'],
                'label' => $spec['label'],
                'color' => $spec['color'],
            ];
        }
        return $out;
    }
}
