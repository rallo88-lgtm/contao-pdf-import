<?php

namespace Rallo\ContaoPdfImport\Service\Importer;

use Doctrine\DBAL\Connection;
use Rallo\ContaoPdfImport\Service\IssueDateParser;
use Rallo\ContaoPdfImport\Service\Job\Job;
use Rallo\ContaoPdfImport\Service\Job\JobFilesystem;
use Rallo\ContaoPdfImport\Service\NewsConflictChecker;
use Rallo\ContaoPdfImport\Service\RubrikWhitelist;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Build-Logik fuer eine einzelne tl_news + ihre tl_content-Children
 * pro Page.
 *
 * tl_news-Felder pro Page:
 * - headline    = "MBJ-{nr} Seite {pageNr}" (deterministischer Identifier,
 *   sortier-/scanbar im BE und mod_newslist).
 * - subheadline = LAYOUT_TITLE-Text der Rubrik-Box (Whitelist-normalisiert
 *   gegen die 7 MBJ-Rubriken, Forward-Fill aus voriger Page bei Detection-
 *   Fail).
 * - teaser      = ALLE LAYOUT_SECTION_HEADERs kommasepariert
 *   (= Inhaltsangabe der Sektionen, sichtbar in mod_newslist; Template
 *   kuerzt bei Bedarf via |truncate-Filter).
 * - issue_number / pageNumber = Composite-Match-Key fuer NewsConflictChecker.
 * - alias = sanitized + tstamp.
 * - date = IssueDateParser(detectedIssueDate) ?? deterministicDate.
 * - time = date + pageNr*60 (BE-Sortierung sichtbar pro Page).
 * - published = 0 (Bettina prueft via FE-Reader-Vorschau).
 *
 * tl_content-Body:
 * - LAYOUT_TITLE-Blocks werden NICHT als h1 gerendert (sind in subheadline).
 * - LAYOUT_SECTION_HEADERs ALLE als h2 im Body (echte Hauptzeile +
 *   Zwischenueberschriften); im Body bleibt die volle Artikel-Struktur,
 *   das tl_news-headline-Feld ist nur Identifier.
 * - 'text' -> type=text mit <p>nl2br($text)</p>.
 * - 'image' (FIGURE/TABLE) -> crop + tl_files + type=image / type=gallery.
 *
 * Decisions: replace = update + tl_content cleanen, new = insert,
 * skip = nichts tun. Returns Insert-Stats inkl. 'rubrik' fuer Forward-
 * Fill in der naechsten Page.
 */
final class NewsArticleBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobFilesystem $jobFs,
        private readonly FilesIndex $filesIndex,
        private readonly FigureCropper $cropper,
        private readonly NewsConflictChecker $conflicts,
        private readonly IssueDateParser $issueDateParser,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {}

    /**
     * Verarbeitet eine einzelne Page.
     *
     * @param ?string $previousRubrik Forward-Fill-Wert aus der vorigen
     *                                Page; wird genutzt wenn auf der
     *                                aktuellen Page keine Rubrik in der
     *                                Whitelist matcht.
     *
     * @return array{action: string, news_id: int, blocks_inserted: int, images: int, rubrik: ?string}
     */
    public function buildPage(Job $job, int $pageNumber, string $decision, ?string $previousRubrik = null): array
    {
        if ($decision === 'skip') {
            return ['action' => 'skipped', 'news_id' => 0, 'blocks_inserted' => 0, 'images' => 0, 'rubrik' => $previousRubrik];
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
        $issueInt = (int) $issueNum;

        // Headline-Sammlung VOR Insert/Update: Rubrik (LAYOUT_TITLE) +
        // Sections (LAYOUT_SECTION_HEADER, in Reading-Order).
        $titleTexts   = [];
        $sectionTexts = [];
        foreach ($blocks as $b) {
            if (($b['mappingCe'] ?? null) !== 'headline') {
                continue;
            }
            if (!empty($b['listParentId']) || !empty($b['captionParentId'])) {
                continue;
            }
            $type = (string) ($b['type'] ?? '');
            $text = trim((string) ($b['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if ($type === 'LAYOUT_TITLE') {
                $titleTexts[] = $text;
            } elseif ($type === 'LAYOUT_SECTION_HEADER') {
                $sectionTexts[] = $text;
            }
        }

        // Multi-Line-Rubrik-Boxen ("WISSENSCHAFT" \n "UND FORSCHUNG")
        // joinen und gegen Whitelist normalisieren; bei No-Match Forward-
        // Fill aus voriger Page.
        $rubrikRaw = trim(implode(' ', $titleTexts));
        $rubrik    = RubrikWhitelist::normalize($rubrikRaw) ?? $previousRubrik;

        // headline ist deterministischer Identifier — kein Inhalt aus
        // Phase-C, weil dessen Heuristik (erste LAYOUT_SECTION_HEADER)
        // nicht zuverlaessig die Artikel-Hauptzeile liefert (kann Bild-
        // Caption oder Zwischenueberschrift sein). Inhalt landet als h2
        // im Body, kommasepariert auch im teaser.
        $headline = NewsConflictChecker::deterministicHeadline($issueNum, $pageNumber);
        $teaser   = implode(', ', $sectionTexts);

        // Echtes Datum aus Klammer-Erkennung (z.B. "Maerz 2026" -> Monatsanfang),
        // sonst deterministicDate als Sortier-Fallback.
        $realDate = $this->issueDateParser->parse($job->detectedIssueDate);
        $date     = $realDate ?? NewsConflictChecker::deterministicDate($issueInt, $pageNumber);
        // Page-Index als Minuten-Offset, damit BE-Anzeige Page-Reihenfolge zeigt:
        // Page 1 -> 00:01, Page 2 -> 00:02, ... (statt allen "00:00" mit Sub-Minuten-Diff)
        $time     = $date + ($pageNumber * 60);

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
                'tstamp'       => time(),
                'headline'     => $headline,
                'subheadline'  => $rubrik ?? '',
                'date'         => $date,
                'time'         => $time,
                'teaser'       => $teaser,
                'issue_number' => $issueInt,
                'pageNumber'   => $pageNumber,
                'published'    => 0,
            ], ['id' => $newsId]);
            $action = 'replaced';
        } else {
            $alias = $this->buildAlias($issueNum, $pageNumber);
            $this->db->insert('tl_news', [
                'pid'          => $job->targetArchivePid,
                'tstamp'       => time(),
                'headline'     => $headline,
                'subheadline'  => $rubrik ?? '',
                'alias'        => $alias,
                'author'       => $job->userId ?: 1,
                'date'         => $date,
                'time'         => $time,
                'teaser'       => $teaser,
                'issue_number' => $issueInt,
                'pageNumber'   => $pageNumber,
                'published'    => 0,
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
            // List-Items wurden in Phase C als CHILDren einer LAYOUT_LIST
            // identifiziert — ihre Texte landen ueber das LIST-Block selbst
            // im listitems-Array, hier also skippen damit kein doppelter <p>.
            if (!empty($b['listParentId'])) {
                continue;
            }
            // Caption-Texte wurden ihrer LAYOUT_FIGURE als 'caption'
            // zugeordnet (Phase-C-Heuristik) und landen im Image-CE
            // caption-Feld — hier skippen damit kein eigener <p>.
            if (!empty($b['captionParentId'])) {
                continue;
            }
            // Header-Skip: LAYOUT_TITLE landet komplett in tl_news.subheadline
            // (Rubrik) — nicht doppelt als h1 im Body. LAYOUT_SECTION_HEADER
            // bleiben ALLE als h2 im Body (echte Artikel-Hauptzeile +
            // Zwischenueberschriften); zusaetzlich kommasepariert im teaser.
            if ($ce === 'headline' && (string) ($b['type'] ?? '') === 'LAYOUT_TITLE') {
                continue;
            }
            $sorting += 128;

            if ($ce === 'list') {
                $items = $b['listItems'] ?? [];
                if (!\is_array($items)) {
                    $items = [];
                }
                $items = array_values(array_filter(array_map(
                    static fn($s) => trim((string) $s),
                    $items,
                ), static fn(string $s) => $s !== ''));
                if ($items === []) {
                    continue;
                }
                $this->db->insert('tl_content', [
                    'pid'       => $newsId,
                    'ptable'    => 'tl_news',
                    'sorting'   => $sorting,
                    'tstamp'    => time(),
                    'type'      => 'list',
                    'listtype'  => 'unordered',
                    'listitems' => serialize($items),
                ]);
                $blocksInserted++;
                continue;
            }

            if ($ce === 'headline') {
                // Hier landen nur noch Zwischenueberschriften (=
                // LAYOUT_SECTION_HEADER ab dem 2. Vorkommen). Die Hauptzeile
                // ist in tl_news.headline, die Rubrik in subheadline.
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
                    'headline' => serialize(['unit' => 'h2', 'value' => $text]),
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
                $sourcePagePath = $this->jobFs->getPagePath($job->id, $pageNumber);
                $caption        = trim((string) ($b['caption'] ?? ''));
                $subColumns     = $b['subColumns'] ?? null;

                if (\is_array($subColumns) && \count($subColumns) >= 2) {
                    // Multi-Column-Infografik -> N Sub-Crops + tl_content
                    // type=gallery. CSS-Klasse rct-infografik triggert im
                    // rct-bundle das responsive Grid (Desktop nahtlos
                    // nebeneinander, Mobile <768px gestackt).
                    //
                    // Caption: Contao rendert tl_content.caption beim
                    // gallery-CE NICHT — daher separates text-CE nach der
                    // Gallery mit cssClass rct-infografik-caption (rct-bundle
                    // styled das wie figcaption).
                    $imageSeqByType[$typeShort] = ($imageSeqByType[$typeShort] ?? 0) + 1;
                    $seq                        = $imageSeqByType[$typeShort];
                    $uuids                      = [];
                    foreach ($subColumns as $idx => $subBox) {
                        $filename = sprintf('page-%d-%s-%d-col-%d.jpg', $pageNumber, $typeShort, $seq, $idx + 1);
                        $relPath  = $imageRelDir . '/' . $filename;
                        $absPath  = $this->projectDir . '/' . $relPath;
                        $this->cropper->crop($sourcePagePath, $subBox, $absPath);
                        $uuids[]  = $this->filesIndex->registerFile($relPath, 'jpg', $folderUuid);
                        $imagesCropped++;
                    }
                    $this->db->insert('tl_content', [
                        'pid'      => $newsId,
                        'ptable'   => 'tl_news',
                        'sorting'  => $sorting,
                        'tstamp'   => time(),
                        'type'     => 'gallery',
                        'multiSRC' => serialize($uuids),
                        'sortBy'   => 'custom',
                        'perRow'   => \count($uuids),
                        // Contao speichert CSS-Klasse als serialisiertes [id, class]-Tupel
                        'cssID'    => serialize(['', 'rct-infografik']),
                    ]);
                    $blocksInserted++;

                    if ($caption !== '') {
                        $sorting += 64;
                        $this->db->insert('tl_content', [
                            'pid'     => $newsId,
                            'ptable'  => 'tl_news',
                            'sorting' => $sorting,
                            'tstamp'  => time(),
                            'type'    => 'text',
                            'text'    => '<p>' . htmlspecialchars($caption, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>',
                            'cssID'   => serialize(['', 'rct-infografik-caption']),
                        ]);
                        $blocksInserted++;
                    }
                    continue;
                }

                $imageSeqByType[$typeShort] = ($imageSeqByType[$typeShort] ?? 0) + 1;
                $seq = $imageSeqByType[$typeShort];

                $filename = sprintf('page-%d-%s-%d.jpg', $pageNumber, $typeShort, $seq);
                $relPath  = $imageRelDir . '/' . $filename;
                $absPath  = $this->projectDir . '/' . $relPath;

                $this->cropper->crop($sourcePagePath, $box, $absPath);

                $fileUuid = $this->filesIndex->registerFile($relPath, 'jpg', $folderUuid);

                $this->db->insert('tl_content', [
                    'pid'       => $newsId,
                    'ptable'    => 'tl_news',
                    'sorting'   => $sorting,
                    'tstamp'    => time(),
                    'type'      => 'image',
                    'singleSRC' => $fileUuid,
                    'caption'   => $caption,
                ]);
                $blocksInserted++;
                $imagesCropped++;
            }
        }

        return [
            'action'          => $action,
            'news_id'         => $newsId,
            'blocks_inserted' => $blocksInserted,
            'images'          => $imagesCropped,
            'rubrik'          => $rubrik,
        ];
    }

    private function buildAlias(string $issueNumber, int $pageNumber): string
    {
        // Vorlage-Pattern: timestamp-suffix garantiert Uniqueness ohne extra check.
        return sprintf('mbj-%s-seite-%d-%d', $issueNumber, $pageNumber, time());
    }
}
