<?php

namespace Rallo\ContaoPdfImport\Service\Importer;

use Doctrine\DBAL\Connection;
use Rallo\ContaoPdfImport\Service\Job\Job;
use Rallo\ContaoPdfImport\Service\Job\JobFilesystem;
use Rallo\ContaoPdfImport\Service\NewsConflictChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Build-Logik fuer eine einzelne tl_news + ihre tl_content-Children
 * pro Page. Per Default:
 * - headline = "MBJ-{nr} Seite {pageNr}", alias = sanitized + tstamp,
 *   date = mktime+nr*1000+pageNr (deterministic), time = pageNr,
 *   published = 0 (Bettina prueft via FE-Reader-Vorschau).
 * - Block-Mapping: mappingCe='headline' -> tl_content type=headline mit
 *   unit=h1 (LAYOUT_TITLE) oder h2 (LAYOUT_SECTION_HEADER); 'text' ->
 *   type=text mit <p>nl2br($text)</p>; 'image' (FIGURE/TABLE) -> crop +
 *   tl_files + type=image mit singleSRC.
 * - decision='replace': existing tl_news.id behalten, tl_content unter
 *   ihm loeschen, tl_news mit neuen Werten updaten (published=0!).
 * - decision='new': neue tl_news anlegen.
 * - decision='skip': nichts tun.
 *
 * Returns Insert-Stats (created, replaced, skipped, blocks_inserted,
 * images_cropped).
 */
final class NewsArticleBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobFilesystem $jobFs,
        private readonly FilesIndex $filesIndex,
        private readonly FigureCropper $cropper,
        private readonly NewsConflictChecker $conflicts,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {}

    /**
     * Verarbeitet eine einzelne Page.
     *
     * @return array{action: string, news_id: int, blocks_inserted: int, images: int}
     */
    public function buildPage(Job $job, int $pageNumber, string $decision): array
    {
        if ($decision === 'skip') {
            return ['action' => 'skipped', 'news_id' => 0, 'blocks_inserted' => 0, 'images' => 0];
        }
        if ($job->targetArchivePid === null) {
            throw new \RuntimeException('Job hat kein target_archive_pid.');
        }
        if ($job->detectedIssueNumber === null) {
            throw new \RuntimeException('Job hat keine detected_issue_number — Phase E braucht sie für Headline+Alias+Date.');
        }

        $pageData = $job->payload['pages_data'][(string) $pageNumber] ?? null;
        if ($pageData === null) {
            throw new \RuntimeException(sprintf('Keine pages_data für Page %d in Job-Payload.', $pageNumber));
        }
        $blocks = $pageData['blocks'] ?? [];
        if (!\is_array($blocks)) {
            $blocks = [];
        }

        $issueNum = $job->detectedIssueNumber;
        $headline = sprintf('MBJ-%s Seite %d', $issueNum, $pageNumber);
        $date     = NewsConflictChecker::deterministicDate((int) $issueNum, $pageNumber);
        $time     = $pageNumber;
        $teaser   = sprintf('MBJ Ausgabe %s, Seite %d', $issueNum, $pageNumber);

        // Existing-Check (sollte mit decision konsistent sein)
        $existing = $decision === 'replace'
            ? $this->conflicts->check($job->targetArchivePid, $issueNum, [$pageNumber])[$pageNumber]
            : ['exists' => false, 'newsId' => null, 'alias' => null];

        $newsId = null;
        $action = 'created';

        if ($decision === 'replace' && $existing['exists']) {
            $newsId = (int) $existing['newsId'];
            // tl_content cleanen
            $this->db->executeStatement(
                'DELETE FROM tl_content WHERE pid = :pid AND ptable = :ptable',
                ['pid' => $newsId, 'ptable' => 'tl_news'],
            );
            // tl_news updaten — published=0! Bettina prüft neu
            $this->db->update('tl_news', [
                'tstamp'    => time(),
                'headline'  => $headline,
                'date'      => $date,
                'time'      => $time,
                'teaser'    => $teaser,
                'published' => 0,
            ], ['id' => $newsId]);
            $action = 'replaced';
        } else {
            $alias = $this->buildAlias($issueNum, $pageNumber);
            $this->db->insert('tl_news', [
                'pid'       => $job->targetArchivePid,
                'tstamp'    => time(),
                'headline'  => $headline,
                'alias'     => $alias,
                'author'    => $job->userId ?: 1,
                'date'      => $date,
                'time'      => $time,
                'teaser'    => $teaser,
                'published' => 0,
            ]);
            $newsId = (int) $this->db->lastInsertId();
            $action = 'created';
        }

        // Folder-Hierarchie fuer Images sicherstellen
        $imageRelDir = sprintf('files/mbj-import/MBJ-%s', $issueNum);
        $folderUuid  = $this->filesIndex->ensureFolderPath($imageRelDir);

        $sorting        = 0;
        $blocksInserted = 0;
        $imagesCropped  = 0;
        $imageSeqByType = [];

        foreach ($blocks as $b) {
            $ce = $b['mappingCe'] ?? null;
            if ($ce === null) {
                continue;
            }
            $sorting += 128;

            if ($ce === 'headline') {
                $unit = ($b['type'] ?? '') === 'LAYOUT_TITLE' ? 'h1' : 'h2';
                $text = trim((string) ($b['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $this->db->insert('tl_content', [
                    'pid'      => $newsId,
                    'ptable'   => 'tl_news',
                    'sorting'  => $sorting,
                    'tstamp'   => time(),
                    'type'     => 'headline',
                    'headline' => serialize(['unit' => $unit, 'value' => $text]),
                ]);
                $blocksInserted++;
                continue;
            }

            if ($ce === 'text') {
                $text = trim((string) ($b['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $html = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</p>';
                $this->db->insert('tl_content', [
                    'pid'     => $newsId,
                    'ptable'  => 'tl_news',
                    'sorting' => $sorting,
                    'tstamp'  => time(),
                    'type'    => 'text',
                    'text'    => $html,
                ]);
                $blocksInserted++;
                continue;
            }

            if ($ce === 'image') {
                $box = $b['box'] ?? null;
                if (!\is_array($box) || !isset($box['Width'], $box['Height'])) {
                    continue;
                }
                $typeShort = $b['typeShort'] ?? 'image';
                $imageSeqByType[$typeShort] = ($imageSeqByType[$typeShort] ?? 0) + 1;
                $seq = $imageSeqByType[$typeShort];

                $filename = sprintf('page-%d-%s-%d.jpg', $pageNumber, $typeShort, $seq);
                $relPath  = $imageRelDir . '/' . $filename;
                $absPath  = $this->projectDir . '/' . $relPath;

                $sourcePagePath = $this->jobFs->getPagePath($job->id, $pageNumber);
                $this->cropper->crop($sourcePagePath, $box, $absPath);

                $fileUuid = $this->filesIndex->registerFile($relPath, 'jpg', $folderUuid);

                $this->db->insert('tl_content', [
                    'pid'       => $newsId,
                    'ptable'    => 'tl_news',
                    'sorting'   => $sorting,
                    'tstamp'    => time(),
                    'type'      => 'image',
                    'singleSRC' => $fileUuid,
                ]);
                $blocksInserted++;
                $imagesCropped++;
            }
        }

        return [
            'action'         => $action,
            'news_id'        => $newsId,
            'blocks_inserted' => $blocksInserted,
            'images'         => $imagesCropped,
        ];
    }

    private function buildAlias(string $issueNumber, int $pageNumber): string
    {
        // Vorlage-Pattern: timestamp-suffix garantiert Uniqueness ohne extra check.
        return sprintf('mbj-%s-seite-%d-%d', $issueNumber, $pageNumber, time());
    }
}
