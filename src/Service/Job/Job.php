<?php

namespace Rallo\ContaoPdfImport\Service\Job;

final class Job
{
    public function __construct(
        public readonly int $id,
        public readonly int $tstamp,
        public readonly int $createdAt,
        public readonly ?int $userId,
        public readonly string $pdfName,
        public readonly string $pdfHash,
        public readonly int $pageCount,
        public readonly JobStatus $status,
        public readonly ?string $lastPhase,
        public readonly ?int $targetArchivePid,
        public readonly ?string $detectedIssueNumber,
        public readonly ?string $detectedIssueDate,
        public readonly array $payload,
    ) {}

    public static function fromRow(array $row): self
    {
        $payload = null;
        if (!empty($row['payload'])) {
            $decoded = json_decode((string) $row['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return new self(
            id:                  (int) $row['id'],
            tstamp:              (int) ($row['tstamp'] ?? 0),
            createdAt:           (int) ($row['created_at'] ?? 0),
            userId:              isset($row['user_id']) ? (int) $row['user_id'] : null,
            pdfName:             (string) ($row['pdf_name'] ?? ''),
            pdfHash:             (string) ($row['pdf_hash'] ?? ''),
            pageCount:           (int) ($row['page_count'] ?? 0),
            status:              JobStatus::tryFrom((string) ($row['status'] ?? '')) ?? JobStatus::Created,
            lastPhase:           $row['last_phase'] ?? null,
            targetArchivePid:    isset($row['target_archive_pid']) ? (int) $row['target_archive_pid'] : null,
            detectedIssueNumber: $row['detected_issue_number'] ?? null,
            detectedIssueDate:   $row['detected_issue_date'] ?? null,
            payload:             $payload ?? [],
        );
    }

    public function issueLabel(): string
    {
        if ($this->detectedIssueNumber === null) {
            return '–';
        }
        $label = 'MBJ-' . $this->detectedIssueNumber;
        if ($this->detectedIssueDate !== null && $this->detectedIssueDate !== '') {
            $label .= ' (' . $this->detectedIssueDate . ')';
        }
        return $label;
    }
}
