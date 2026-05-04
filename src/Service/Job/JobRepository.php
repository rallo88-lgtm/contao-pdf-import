<?php

namespace Rallo\ContaoPdfImport\Service\Job;

use Doctrine\DBAL\Connection;

/**
 * Doctrine-DBAL-basiertes Repository fuer tl_pdf_import_job.
 *
 * Schreibt synchron, kein Caching. payload wird JSON-serialisiert beim
 * Insert/Update, beim Lesen via Job::fromRow() in Array-Form gehoben.
 */
final class JobRepository
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @param array{
     *     user_id?: ?int,
     *     pdf_name: string,
     *     pdf_hash: string,
     *     page_count: int,
     *     target_archive_pid?: ?int,
     *     detected_issue_number?: ?string,
     *     detected_issue_date?: ?string,
     *     payload?: array,
     * } $data
     * @return int Job-ID
     */
    public function create(array $data): int
    {
        $now = time();
        $this->db->insert('tl_pdf_import_job', [
            'tstamp'                => $now,
            'created_at'            => $now,
            'user_id'               => $data['user_id'] ?? null,
            'pdf_name'              => $data['pdf_name'],
            'pdf_hash'              => $data['pdf_hash'],
            'page_count'            => $data['page_count'],
            'status'                => JobStatus::Created->value,
            'last_phase'            => null,
            'target_archive_pid'    => $data['target_archive_pid'] ?? null,
            'detected_issue_number' => $data['detected_issue_number'] ?? null,
            'detected_issue_date'   => $data['detected_issue_date'] ?? null,
            'payload'               => isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?Job
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM tl_pdf_import_job WHERE id = :id',
            ['id' => $id],
        );
        return $row !== false ? Job::fromRow($row) : null;
    }

    /**
     * @return array<int, Job>
     */
    public function findRecent(int $limit = 50): array
    {
        // LIMIT direkt einsetzen — vertrauenswuerdiger int-Param, kein
        // SQL-Injection-Risk. Vermeidet Doctrine-DBAL-4-API-Drift bei
        // typed parameters (ParameterType::INTEGER vs PDO::PARAM_INT).
        $rows = $this->db->fetchAllAssociative(
            sprintf('SELECT * FROM tl_pdf_import_job ORDER BY id DESC LIMIT %d', max(1, $limit)),
        );
        return array_map([Job::class, 'fromRow'], $rows);
    }

    /**
     * Update via DBAL. payload wird automatisch JSON-encoded wenn als Array
     * uebergeben. status akzeptiert string oder JobStatus-Instance.
     */
    public function update(int $id, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        if (isset($changes['payload']) && is_array($changes['payload'])) {
            $changes['payload'] = json_encode($changes['payload'], JSON_UNESCAPED_UNICODE);
        }
        if (isset($changes['status']) && $changes['status'] instanceof JobStatus) {
            $changes['status'] = $changes['status']->value;
        }

        $changes['tstamp'] = time();

        $this->db->update('tl_pdf_import_job', $changes, ['id' => $id]);
    }

    public function tableExists(): bool
    {
        try {
            return $this->db->createSchemaManager()->tablesExist(['tl_pdf_import_job']);
        } catch (\Throwable) {
            return false;
        }
    }
}
