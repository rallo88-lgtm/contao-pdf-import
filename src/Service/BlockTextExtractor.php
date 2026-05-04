<?php

namespace Rallo\ContaoPdfImport\Service;

final class BlockTextExtractor
{
    /**
     * @param bool $dehyphenate "Maßnah- men" -> "Maßnahmen" (typischer PDF-Zeilenumbruch
     *                          in deutschen Magazinen). Match nur wenn naechstes Wort mit
     *                          Kleinbuchstabe startet — vermeidet False-Positives bei
     *                          "Anti- Müll" (Großbuchstabe) oder "deutsch-amerikanisch"
     *                          (kein Space).
     */
    public function __construct(
        private readonly bool $dehyphenate = true,
    ) {}

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

        if ($this->dehyphenate) {
            $text = preg_replace('/(\p{L})-\s+(\p{Ll})/u', '$1$2', $text) ?? $text;
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
