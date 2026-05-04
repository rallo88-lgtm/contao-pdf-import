<?php

namespace Rallo\ContaoPdfImport\Service;

final class BlockTextExtractor
{
    public function extract(array $block, array $blockMap): string
    {
        $text = $block['Text'] ?? '';

        if ($text === '' && isset($block['Relationships']) && is_array($block['Relationships'])) {
            foreach ($block['Relationships'] as $rel) {
                if (($rel['Type'] ?? '') !== 'CHILD' || !isset($rel['Ids'])) {
                    continue;
                }
                foreach ($rel['Ids'] as $id) {
                    if (isset($blockMap[$id]['Text'])) {
                        $text .= $blockMap[$id]['Text'] . ' ';
                    }
                }
            }
        }

        return trim($text);
    }
}
