<?php

namespace Rallo\ContaoPdfImport\Service;

use Doctrine\DBAL\Connection;

/**
 * Phase F — Promote: Two-Archive-Move + Live-Schaltung.
 *
 * Workflow:
 *   1. Aktuell → Archiv  (alte Ausgabe wandert in den Archiv-Topf, bleibt
 *      published, sichtbar im /mbj-archiv)
 *   2. Preview → Aktuell + published=1 (neue Ausgabe wird live)
 *
 * Die drei Archive werden ueber ihren Titel aufgeloest (Konvention im
 * mbj-Setup: "MBJ-Aktuell", "MBJ-Preview", "MBJ-Archiv"). Wenn die Titel
 * spaeter konfigurierbar werden sollen, kommt das in tl_pdf_import_config.
 *
 * Pre-Flight als Generator: yieldet pro Check ein Result-Array, der
 * Stream-Controller emittiert sie einzeln in die DOS-Box. Wenn ein
 * Check 'ok' => false hat, bricht execute() ab.
 */
final class PromoteService
{
    public const TITLE_AKTUELL = 'MBJ-Aktuell';
    public const TITLE_PREVIEW = 'MBJ-Preview';
    public const TITLE_ARCHIV  = 'MBJ-Archiv';

    public function __construct(private readonly Connection $db) {}

    public function findArchiveId(string $title): ?int
    {
        $row = $this->db->fetchAssociative(
            'SELECT id FROM tl_news_archive WHERE title = :title LIMIT 1',
            ['title' => $title],
        );

        return $row !== false ? (int) $row['id'] : null;
    }

    /**
     * @return iterable<array{ok: bool, label: string, detail?: string}>
     */
    public function preflightChecks(int $aktuellId, int $previewId, int $archivId): iterable
    {
        // 1. Alle drei Archive existieren
        if ($aktuellId <= 0) {
            yield ['ok' => false, 'label' => 'Archive "MBJ-Aktuell" existiert', 'detail' => 'nicht gefunden'];
            return;
        }
        yield ['ok' => true, 'label' => 'Archive "MBJ-Aktuell" existiert', 'detail' => 'id=' . $aktuellId];

        if ($previewId <= 0) {
            yield ['ok' => false, 'label' => 'Archive "MBJ-Preview" existiert', 'detail' => 'nicht gefunden'];
            return;
        }
        yield ['ok' => true, 'label' => 'Archive "MBJ-Preview" existiert', 'detail' => 'id=' . $previewId];

        if ($archivId <= 0) {
            yield ['ok' => false, 'label' => 'Archive "MBJ-Archiv" existiert', 'detail' => 'nicht gefunden'];
            return;
        }
        yield ['ok' => true, 'label' => 'Archive "MBJ-Archiv" existiert', 'detail' => 'id=' . $archivId];

        // 2. Preview hat News
        $previewCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM tl_news WHERE pid = :pid',
            ['pid' => $previewId],
        );
        if ($previewCount === 0) {
            yield ['ok' => false, 'label' => 'Preview hat News', 'detail' => '0 News in MBJ-Preview — nichts zu promoten'];
            return;
        }
        yield ['ok' => true, 'label' => 'Preview hat News', 'detail' => $previewCount . ' News'];

        // 3. Issue-Numbers — Preview > Aktuell
        $previewIssue = (int) $this->db->fetchOne(
            'SELECT MAX(issue_number) FROM tl_news WHERE pid = :pid',
            ['pid' => $previewId],
        );
        $aktuellIssue = (int) $this->db->fetchOne(
            'SELECT MAX(issue_number) FROM tl_news WHERE pid = :pid',
            ['pid' => $aktuellId],
        );

        if ($previewIssue <= 0) {
            yield ['ok' => false, 'label' => 'Preview-Issue-Number gesetzt', 'detail' => 'issue_number = 0/NULL'];
            return;
        }
        yield ['ok' => true, 'label' => 'Preview-Issue-Number', 'detail' => 'MBJ-' . $previewIssue];

        if ($aktuellIssue > 0 && $previewIssue <= $aktuellIssue) {
            yield [
                'ok'     => false,
                'label'  => 'Issue-Reihenfolge',
                'detail' => sprintf('Preview MBJ-%d ist nicht neuer als Aktuell MBJ-%d', $previewIssue, $aktuellIssue),
            ];
            return;
        }
        yield [
            'ok'     => true,
            'label'  => 'Issue-Reihenfolge',
            'detail' => $aktuellIssue > 0
                ? sprintf('MBJ-%d > MBJ-%d', $previewIssue, $aktuellIssue)
                : sprintf('MBJ-%d (Aktuell ist leer, erste Promotion)', $previewIssue),
        ];

        // 4. Keine doppelten pageNumbers in Preview
        $duplicates = $this->db->fetchAllAssociative(
            'SELECT pageNumber, COUNT(*) AS c FROM tl_news WHERE pid = :pid GROUP BY pageNumber HAVING c > 1',
            ['pid' => $previewId],
        );
        if (\count($duplicates) > 0) {
            $pages = array_map(static fn(array $r): string => 'S.' . $r['pageNumber'] . ' (' . $r['c'] . 'x)', $duplicates);
            yield [
                'ok'     => false,
                'label'  => 'Eindeutige Seitennummern',
                'detail' => 'Doppelt: ' . implode(', ', $pages),
            ];
            return;
        }
        yield ['ok' => true, 'label' => 'Eindeutige Seitennummern', 'detail' => 'keine Duplikate'];

        // 5. Issue noch nicht im Archiv (sonst Re-Promote ohne Vorwarnung)
        $alreadyArchived = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM tl_news WHERE pid = :pid AND issue_number = :issue',
            ['pid' => $archivId, 'issue' => $previewIssue],
        );
        if ($alreadyArchived > 0) {
            yield [
                'ok'     => false,
                'label'  => 'Issue nicht im Archiv',
                'detail' => sprintf('MBJ-%d existiert bereits im Archiv (%d News) — Re-Promote nicht unterstuetzt', $previewIssue, $alreadyArchived),
            ];
            return;
        }
        yield ['ok' => true, 'label' => 'Issue nicht im Archiv', 'detail' => 'MBJ-' . $previewIssue . ' frei'];
    }

    /**
     * Wirft RuntimeException bei fehlgeschlagenem Pre-Flight (Defense-in-
     * Depth — der Caller sollte Pre-Flights schon vor dem Aufruf gepruefen
     * haben, aber doppelt haelt besser).
     *
     * @return array{moved_to_archive: int, moved_to_aktuell: int, issue_number: int}
     */
    public function execute(int $aktuellId, int $previewId, int $archivId): array
    {
        foreach ($this->preflightChecks($aktuellId, $previewId, $archivId) as $check) {
            if (!$check['ok']) {
                throw new \RuntimeException(sprintf(
                    'Pre-Flight fehlgeschlagen: %s — %s',
                    $check['label'],
                    $check['detail'] ?? '',
                ));
            }
        }

        $previewIssue = (int) $this->db->fetchOne(
            'SELECT MAX(issue_number) FROM tl_news WHERE pid = :pid',
            ['pid' => $previewId],
        );

        $this->db->beginTransaction();
        try {
            // Step 1: Aktuell -> Archiv
            $movedToArchive = (int) $this->db->executeStatement(
                'UPDATE tl_news SET pid = :archiv, tstamp = :ts WHERE pid = :aktuell',
                ['archiv' => $archivId, 'aktuell' => $aktuellId, 'ts' => time()],
            );

            // Step 2: Preview -> Aktuell + published=1
            $movedToAktuell = (int) $this->db->executeStatement(
                'UPDATE tl_news SET pid = :aktuell, published = 1, tstamp = :ts WHERE pid = :preview',
                ['aktuell' => $aktuellId, 'preview' => $previewId, 'ts' => time()],
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return [
            'moved_to_archive' => $movedToArchive,
            'moved_to_aktuell' => $movedToAktuell,
            'issue_number'     => $previewIssue,
        ];
    }

    /**
     * Toggle published-State aller News einer Ausgabe atomar. Wenn alle
     * published, werden alle unpublished — sonst werden alle published.
     *
     * @return array{toggled: int, new_state: int}
     */
    public function togglePublished(int $archiveId, int $issueNumber): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS total, SUM(published) AS published FROM tl_news '
            . 'WHERE pid = :pid AND issue_number = :issue',
            ['pid' => $archiveId, 'issue' => $issueNumber],
        );

        if ($row === false || (int) $row['total'] === 0) {
            return ['toggled' => 0, 'new_state' => 0];
        }

        $allPublished = ((int) $row['total']) === ((int) $row['published']);
        $newState     = $allPublished ? 0 : 1;

        $toggled = (int) $this->db->executeStatement(
            'UPDATE tl_news SET published = :state, tstamp = :ts '
            . 'WHERE pid = :pid AND issue_number = :issue',
            [
                'state' => $newState,
                'ts'    => time(),
                'pid'   => $archiveId,
                'issue' => $issueNumber,
            ],
        );

        return ['toggled' => $toggled, 'new_state' => $newState];
    }

    /**
     * @return list<array{
     *   pid: int,
     *   archive_title: string,
     *   issue_number: int,
     *   page_count: int,
     *   published_count: int,
     *   first_date: int,
     *   status: string
     * }>
     */
    public function listIssues(): array
    {
        $aktuellId = $this->findArchiveId(self::TITLE_AKTUELL);
        $previewId = $this->findArchiveId(self::TITLE_PREVIEW);
        $archivId  = $this->findArchiveId(self::TITLE_ARCHIV);

        $rows = $this->db->fetchAllAssociative(
            'SELECT n.pid, a.title AS archive_title, n.issue_number, '
            . 'COUNT(*) AS page_count, '
            . 'CAST(SUM(n.published) AS UNSIGNED) AS published_count, '
            . 'MIN(n.date) AS first_date '
            . 'FROM tl_news n '
            . 'JOIN tl_news_archive a ON a.id = n.pid '
            . 'WHERE n.issue_number > 0 '
            . 'GROUP BY n.pid, a.title, n.issue_number '
            . 'ORDER BY n.pid ASC, n.issue_number DESC',
        );

        $result = [];
        foreach ($rows as $r) {
            $pid = (int) $r['pid'];
            if ($pid === $aktuellId) {
                $status = 'aktiv';
            } elseif ($pid === $previewId) {
                $status = 'preview';
            } elseif ($pid === $archivId) {
                $status = 'archiv';
            } else {
                $status = 'sonstig';
            }

            $result[] = [
                'pid'             => $pid,
                'archive_title'   => (string) $r['archive_title'],
                'issue_number'    => (int) $r['issue_number'],
                'page_count'      => (int) $r['page_count'],
                'published_count' => (int) $r['published_count'],
                'first_date'      => (int) $r['first_date'],
                'status'          => $status,
            ];
        }

        return $result;
    }
}
