<?php

namespace Rallo\ContaoPdfImport\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Fuegt issue_number + pageNumber an tl_news plus Composite-Index
 * (pid, issue_number, pageNumber). Ersetzt den headline-string-basierten
 * Conflict-Match in NewsConflictChecker — sobald headline auf die echte
 * redaktionelle Hauptzeile umgestellt ist, ist der String nicht mehr
 * deterministisch.
 */
class AddNewsIssueColumnsMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $db) {}

    public function getName(): string
    {
        return 'PDF Import – tl_news.issue_number + pageNumber + Composite-Index';
    }

    public function shouldRun(): bool
    {
        $sm = $this->db->createSchemaManager();
        if (!\in_array('tl_news', $sm->listTableNames(), true)) {
            return false;
        }

        $cols = $this->lowercaseColumnNames();

        return !\in_array('issue_number', $cols, true)
            || !\in_array('pagenumber', $cols, true);
    }

    public function run(): MigrationResult
    {
        $cols = $this->lowercaseColumnNames();

        if (!\in_array('issue_number', $cols, true)) {
            $this->db->executeStatement(
                'ALTER TABLE tl_news ADD COLUMN issue_number INT NULL DEFAULT NULL'
            );
        }
        if (!\in_array('pagenumber', $cols, true)) {
            $this->db->executeStatement(
                'ALTER TABLE tl_news ADD COLUMN pageNumber INT NULL DEFAULT NULL'
            );
        }

        $indexes = $this->db->createSchemaManager()->listTableIndexes('tl_news');
        if (!isset($indexes['idx_news_issue_page'])) {
            $this->db->executeStatement(
                'CREATE INDEX idx_news_issue_page ON tl_news (pid, issue_number, pageNumber)'
            );
        }

        // Backfill fuer Bestand mit deterministischer "MBJ-{nr} Seite {x}"-
        // Headline (manuell angelegte Test-News oder Importe vor v0.3.0).
        // Idempotent: nur Zeilen ohne issue_number werden gefuellt.
        $backfilled = $this->db->executeStatement(
            "UPDATE tl_news\n"
            . "   SET issue_number = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(headline, '-', -1), ' ', 1) AS UNSIGNED),\n"
            . "       pageNumber  = CAST(SUBSTRING_INDEX(headline, ' ', -1) AS UNSIGNED)\n"
            . " WHERE headline LIKE 'MBJ-%% Seite %%'\n"
            . "   AND issue_number IS NULL\n"
            . "   AND pageNumber IS NULL"
        );

        return $this->createResult(
            true,
            sprintf('tl_news.issue_number + pageNumber + Composite-Index angelegt (Backfill: %d Zeilen).', $backfilled)
        );
    }

    /**
     * @return list<string>
     */
    private function lowercaseColumnNames(): array
    {
        $cols = $this->db->createSchemaManager()->listTableColumns('tl_news');

        return array_map(
            static fn(string $name): string => strtolower($name),
            array_keys($cols),
        );
    }
}
