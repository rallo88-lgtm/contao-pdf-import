<?php

namespace Rallo\ContaoPdfImport\Service;

use Doctrine\DBAL\Connection;

/**
 * Liefert tl_news_archive-Eintraege fuer das Target-Archive-Dropdown
 * im Phase-A-Upload-Form. Liest nur id + title — alles andere kommt
 * spaeter wenn nötig.
 */
final class NewsArchiveProvider
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @return array<int, array{id: int, title: string}>
     */
    public function listAll(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, title FROM tl_news_archive ORDER BY title ASC',
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn(array $r) => [
            'id'    => (int) $r['id'],
            'title' => (string) $r['title'],
        ], $rows);
    }
}
