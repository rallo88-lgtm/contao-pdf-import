<?php

namespace Rallo\ContaoPdfImport\Service;

use Doctrine\DBAL\Connection;

/**
 * Pruefen ob bereits ein tl_news-Eintrag fuer (issue, page) existiert.
 * Match-Key ist seit der Headline-Reform der Composite-Index
 * (pid, issue_number, pageNumber) — nicht mehr die deterministische
 * Headline-String. Begruendung: headline traegt jetzt die echte
 * redaktionelle Hauptzeile aus Phase-C, ist also nicht mehr
 * deterministisch ueber Importe hinweg.
 *
 * Wenn die Issue-Number unbekannt ist (Detection failed): kein
 * Conflict-Check moeglich, alle pages als 'neu' behandeln. Phase E
 * warnt dann, dass der Match nicht greift.
 */
final class NewsConflictChecker
{
    /** Base-Timestamp = 1.1.2000 lokal (gleich wie Vorlage) */
    private const BASE_TIME = 946684800;

    public function __construct(
        private readonly Connection $db,
    ) {}

    public static function deterministicDate(int $issueNumber, int $pageNumber): int
    {
        return self::BASE_TIME + ($issueNumber * 1000) + $pageNumber;
    }

    /**
     * @param int[] $pageNumbers
     * @return array<int, array{exists: bool, newsId: int|null, headline: string|null, alias: string|null}>
     */
    public function check(int $archivePid, ?string $issueNumber, array $pageNumbers): array
    {
        $result   = [];
        $issueInt = (int) $issueNumber;

        foreach ($pageNumbers as $pageNumber) {
            if ($issueNumber === null || $issueNumber === '' || $issueInt <= 0) {
                $result[$pageNumber] = [
                    'exists'   => false,
                    'newsId'   => null,
                    'headline' => null,
                    'alias'    => null,
                ];
                continue;
            }

            try {
                $row = $this->db->fetchAssociative(
                    'SELECT id, headline, alias FROM tl_news '
                    . 'WHERE pid = :pid AND issue_number = :issue AND pageNumber = :page '
                    . 'ORDER BY id DESC LIMIT 1',
                    [
                        'pid'   => $archivePid,
                        'issue' => $issueInt,
                        'page'  => $pageNumber,
                    ],
                );
            } catch (\Throwable) {
                $row = false;
            }

            $result[$pageNumber] = [
                'exists'   => $row !== false,
                'newsId'   => $row !== false ? (int) $row['id'] : null,
                'headline' => $row !== false ? (string) $row['headline'] : null,
                'alias'    => $row !== false ? (string) $row['alias'] : null,
            ];
        }

        return $result;
    }
}
