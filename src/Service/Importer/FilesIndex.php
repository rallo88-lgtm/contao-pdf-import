<?php

namespace Rallo\ContaoPdfImport\Service\Importer;

use Contao\Database;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Verwaltet tl_files-Folder-Hierarchie + File-Inserts.
 *
 * ensureFolderPath('files/mbj-import/MBJ-184') legt — falls noch nicht da —
 * jedes Segment als folder-Row in tl_files an, plus die physische Directory.
 * registerFile($relPath, $ext) legt eine file-Row mit binary UUID an.
 *
 * Binary-UUID-Generation via Contao\Database::getUuid() — das ist die
 * gleiche Quelle die FigureBuilder::getUuid() im FE auf Validitaet prueft.
 * Falls Contao 5.7 das API mal entfernen sollte: Fallback waere
 * Symfony\Uid\UuidV1, aber bis dahin bleibt's einfach.
 */
final class FilesIndex
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {}

    /**
     * @return string Binary-UUID des letzten Folder-Segments
     */
    public function ensureFolderPath(string $relativePath): string
    {
        $segments = array_values(array_filter(explode('/', trim($relativePath, '/'))));
        if ($segments === []) {
            throw new \InvalidArgumentException('Empty path.');
        }

        $parentUuid = null;
        $accumPath  = '';

        foreach ($segments as $segment) {
            $accumPath = $accumPath === '' ? $segment : $accumPath . '/' . $segment;

            $existing = $this->findByPath($accumPath);
            if ($existing !== null) {
                $parentUuid = $existing;
                continue;
            }

            // Physisches Folder anlegen
            $physical = $this->projectDir . '/' . $accumPath;
            if (!is_dir($physical)) {
                mkdir($physical, 0775, true);
            }

            $uuid = Database::getInstance()->getUuid();
            $this->db->insert('tl_files', [
                'pid'       => $parentUuid,
                'tstamp'    => time(),
                'uuid'      => $uuid,
                'name'      => $segment,
                'type'      => 'folder',
                'path'      => $accumPath,
                'extension' => '',
                'hash'      => '',
                'found'     => 1,
            ]);
            $parentUuid = $uuid;
        }

        return (string) $parentUuid;
    }

    /**
     * Legt einen tl_files-File-Eintrag an, falls die Datei am gegebenen Pfad
     * noch nicht registriert ist. Returns binary UUID.
     */
    public function registerFile(string $relativePath, string $extension, ?string $folderUuid = null): string
    {
        $existing = $this->findByPath($relativePath);
        if ($existing !== null) {
            return $existing;
        }

        $name = basename($relativePath);
        $uuid = Database::getInstance()->getUuid();

        $this->db->insert('tl_files', [
            'pid'       => $folderUuid,
            'tstamp'    => time(),
            'uuid'      => $uuid,
            'name'      => $name,
            'type'      => 'file',
            'path'      => $relativePath,
            'extension' => strtolower($extension),
            'hash'      => '',
            'found'     => 1,
        ]);

        return $uuid;
    }

    /**
     * Liest ggf. existierenden tl_files-Eintrag fuer den Pfad und gibt
     * dessen binary UUID zurueck. null wenn keiner existiert.
     */
    private function findByPath(string $relativePath): ?string
    {
        try {
            $row = $this->db->fetchAssociative(
                'SELECT uuid FROM tl_files WHERE path = :path LIMIT 1',
                ['path' => $relativePath],
            );
        } catch (\Throwable) {
            return null;
        }
        return $row !== false ? (string) $row['uuid'] : null;
    }
}
