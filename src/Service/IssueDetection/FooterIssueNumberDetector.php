<?php

namespace Rallo\ContaoPdfImport\Service\IssueDetection;

use Rallo\ContaoPdfImport\Service\BlockTextExtractor;
use Rallo\ContaoPdfImport\Service\Ocr\OcrResult;

/**
 * Default-Detector: zieht die Issue-Number aus LAYOUT_FOOTER-Bloecken.
 *
 * Robuster als smalot/pdfparser auf Page-1-Text weil:
 * - Funktioniert auf JEDER Seite (Footer ist immer da, Cover-Seite oft nicht)
 * - Strukturierte Erkennung via Textract — kein Cover-Design-Bias
 * - Confidence-Wert vom Textract gibt uns einen Sicherheits-Score
 *
 * Standard-Pattern aus DVMB-Vorlage erweitert um "Ausgabe" und um
 * Klammer-Datum-Extraktion ("(März 2026)" -> dateText).
 */
final class FooterIssueNumberDetector implements IssueNumberDetectorInterface
{
    private const NUMBER_PATTERN = '/(?:Nr\.?|Nummer|Ausgabe)\s*\.?\s*(\d{2,5})/iu';
    private const DATE_PATTERN   = '/\(([^)]{3,40})\)/u';

    public function __construct(
        private readonly BlockTextExtractor $textExtractor,
    ) {}

    public function detectFromResult(OcrResult $result): ?DetectedIssue
    {
        $blockMap   = $result->getBlockMap();
        $candidates = [];

        foreach ($result->blocks as $b) {
            if (($b['BlockType'] ?? '') !== 'LAYOUT_FOOTER') {
                continue;
            }
            $text = $this->textExtractor->extract($b, $blockMap);
            if ($text === '') {
                continue;
            }
            if (!preg_match(self::NUMBER_PATTERN, $text, $m)) {
                continue;
            }

            $dateText = null;
            if (preg_match(self::DATE_PATTERN, $text, $dm)) {
                $dateText = trim($dm[1]);
            }

            $candidates[] = new DetectedIssue(
                number:     $m[1],
                dateText:   $dateText,
                confidence: (float) ($b['Confidence'] ?? 0),
                source:     'footer',
                rawText:    $text,
            );
        }

        if ($candidates === []) {
            return null;
        }

        // Hoechste Confidence gewinnt
        usort($candidates, static fn(DetectedIssue $a, DetectedIssue $b) => $b->confidence <=> $a->confidence);
        return $candidates[0];
    }
}
