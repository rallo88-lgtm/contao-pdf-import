<?php

namespace Rallo\ContaoPdfImport\Service;

use Doctrine\DBAL\Connection;

/**
 * Checked pro Page ob bereits ein tl_news-Eintrag existiert. Match-Key
 * ist tl_news.headline (= deterministisch "MBJ-{nr} Seite {pageNr}") —
 * robust gegen den noch ungeklaerten date-Modifikator, der zwischen PHP
 * und DB die date/time-Werte normalisiert. Solange Bettina die Headlines
 * nicht haendisch umbenennt, ist Headline der zuverlaessigere Schluessel.
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

    public static function deterministicHeadline(string $issueNumber, int $pageNumber): string
    {
        return sprintf('MBJ-%s Seite %d', $issueNumber, $pageNumber);
    }

    /**
     * @param int[] $pageNumbers
     * @return array<int, array{exists: bool, newsId: int|null, headline: string|null, alias: string|null}>
     */
    public function check(int $archivePid, ?string $issueNumber, array $pageNumbers): array
    {
        $result = [];

        foreach ($pageNumbers as $pageNumber) {
            if ($issueNumber === null || $issueNumber === '') {
                $result[$pageNumber] = [
                    'exists'   => false,
                    'newsId'   => null,
                    'headline' => null,
                    'alias'    => null,
                ];
                continue;
            }

            $expectedHeadline = self::deterministicHeadline($issueNumber, $pageNumber);

            try {
                $row = $this->db->fetchAssociative(
                    'SELECT id, headline, alias FROM tl_news WHERE pid = :pid AND headline = :headline ORDER BY id DESC LIMIT 1',
                    ['pid' => $archivePid, 'headline' => $expectedHeadline],
                );
            } catch (\Throwable) {
                $row = false;
            }

            $result[$pageNumber] = [
                'exists'   => $row !== false,
                'newsId'   => $row !== false ? (int) $row['id'] : null,
                'headline' => $row !== false ? (string) $row['headline'] : $expectedHeadline,
                'alias'    => $row !== false ? (string) $row['alias'] : null,
            ];
        }

        return $result;
    }
}
