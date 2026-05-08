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

        $columns = $sm->listTableColumns('tl_news');
        $byName  = [];
        foreach ($columns as $name => $col) {
            $byName[strtolower($name)] = $col;
        }

        // Spalten fehlen ganz?
        if (!isset($byName['issue_number']) || !isset($byName['pagenumber'])) {
            return true;
        }

        // Spalten existieren als NULL-able (v0.3.0-Initial-Pattern) — auf
        // NOT NULL DEFAULT 0 angleichen, damit Schema-Sync stabil bleibt.
        if (!$byName['issue_number']->getNotnull() || !$byName['pagenumber']->getNotnull()) {
            return true;
        }

        // Composite-Index fehlt?
        $indexes = $sm->listTableIndexes('tl_news');

        return !isset($indexes['pid_issue_number_pagenumber']);
    }

    public function run(): MigrationResult
    {
        $cols = $this->lowercaseColumnNames();

        if (!\in_array('issue_number', $cols, true)) {
            $this->db->executeStatement(
                'ALTER TABLE tl_news ADD COLUMN issue_number INT(10) UNSIGNED NOT NULL DEFAULT 0'
            );
        } else {
            // Falls die Spalte bereits NULL-able existiert (v0.3.0 Initial-
            // Deploy mit 'INT NULL DEFAULT NULL'), auf NOT NULL DEFAULT 0
            // angleichen — sonst meldet Contao Schema-Diff dauerhaft pending.
            $this->db->executeStatement(
                "UPDATE tl_news SET issue_number = 0 WHERE issue_number IS NULL"
            );
            $this->db->executeStatement(
                'ALTER TABLE tl_news MODIFY COLUMN issue_number INT(10) UNSIGNED NOT NULL DEFAULT 0'
            );
        }
        if (!\in_array('pagenumber', $cols, true)) {
            $this->db->executeStatement(
                'ALTER TABLE tl_news ADD COLUMN pageNumber INT(10) UNSIGNED NOT NULL DEFAULT 0'
            );
        } else {
            $this->db->executeStatement(
                "UPDATE tl_news SET pageNumber = 0 WHERE pageNumber IS NULL"
            );
            $this->db->executeStatement(
                'ALTER TABLE tl_news MODIFY COLUMN pageNumber INT(10) UNSIGNED NOT NULL DEFAULT 0'
            );
        }

        $indexes = $this->db->createSchemaManager()->listTableIndexes('tl_news');
        if (!isset($indexes['pid_issue_number_pagenumber'])) {
            $this->db->executeStatement(
                'CREATE INDEX pid_issue_number_pagenumber ON tl_news (pid, issue_number, pageNumber)'
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
