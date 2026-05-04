<?php

namespace Rallo\ContaoPdfImport\Service\IssueDetection;

final class DetectedIssue
{
    public function __construct(
        public readonly string $number,
        public readonly ?string $dateText = null,
        public readonly float $confidence = 0.0,
        public readonly string $source = 'unknown',
        public readonly string $rawText = '',
    ) {}

    public function label(): string
    {
        $label = 'Nr. ' . $this->number;
        if ($this->dateText !== null && $this->dateText !== '') {
            $label .= ' (' . $this->dateText . ')';
        }
        return $label;
    }

    public function toArray(): array
    {
        return [
            'number'     => $this->number,
            'dateText'   => $this->dateText,
            'confidence' => $this->confidence,
            'source'     => $this->source,
            'rawText'    => $this->rawText,
        ];
    }

    public static function fromArray(?array $data): ?self
    {
        if (!\is_array($data) || !isset($data['number'])) {
            return null;
        }
        return new self(
            number:     (string) $data['number'],
            dateText:   $data['dateText']   ?? null,
            confidence: (float) ($data['confidence'] ?? 0),
            source:     (string) ($data['source'] ?? 'unknown'),
            rawText:    (string) ($data['rawText'] ?? ''),
        );
    }
}
