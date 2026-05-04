<?php

namespace Rallo\ContaoPdfImport\Service;

use Doctrine\DBAL\Connection;

/**
 * Checked pro Page ob bereits ein tl_news-Eintrag existiert. Match-Key
 * ist tl_news.date (= deterministisch via mktime+nr*1000+pageNr) — robust
 * auch wenn Bettina die Headline manuell editiert.
 *
 * Wenn die Issue-Number unbekannt ist (Detection failed): kein
 * Conflict-Check moeglich, alle pages als 'neu' behandeln. Phase E warnt
 * dann, dass die deterministische Sortierung nicht greift.
 */
final class NewsConflictChecker
{
    /** Base-Timestamp = 1.1.2000 lokal (gleich wie Vorlage) */
    private const BASE_TIME = 946684800; // mktime(0,0,0,1,1,2000)

    public function __construct(
        private readonly Connection $db,
    ) {}

    public static function deterministicDate(int $issueNumber, int $pageNumber): int
    {
        return self::BASE_TIME + ($issueNumber * 1000) + $pageNumber;
    }

    /**
     * @param int[] $pageNumbers
     * @return array<int, array{exists: bool, newsId: int|null, headline: string|null, alias: string|null, date: int}>
     */
    public function check(int $archivePid, ?string $issueNumber, array $pageNumbers): array
    {
        $result      = [];
        $issueNumInt = $issueNumber !== null ? (int) $issueNumber : 0;

        foreach ($pageNumbers as $pageNumber) {
            $date = $issueNumInt > 0
                ? self::deterministicDate($issueNumInt, $pageNumber)
                : 0;

            if ($date === 0) {
                $result[$pageNumber] = [
                    'exists'   => false,
                    'newsId'   => null,
                    'headline' => null,
                    'alias'    => null,
                    'date'     => 0,
                ];
                continue;
            }

            try {
                $row = $this->db->fetchAssociative(
                    'SELECT id, headline, alias FROM tl_news WHERE pid = :pid AND date = :date ORDER BY id DESC LIMIT 1',
                    ['pid' => $archivePid, 'date' => $date],
                );
            } catch (\Throwable) {
                $row = false;
            }

            $result[$pageNumber] = [
                'exists'   => $row !== false,
                'newsId'   => $row !== false ? (int) $row['id'] : null,
                'headline' => $row !== false ? (string) $row['headline'] : null,
                'alias'    => $row !== false ? (string) $row['alias'] : null,
                'date'     => $date,
            ];
        }

        return $result;
    }
}
