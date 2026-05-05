<?php

use Contao\DC_Table;

/**
 * Import-Job-State pro PDF-Run.
 *
 * Phasen-Workflow:  created -> split_done -> pages_selected -> ocr_done
 *                  -> conflicts_resolved -> completed (oder failed/cancelled).
 *
 * Read-only aus BE-Sicht (closed/notEditable). Manipulation passiert ueber
 * dedizierte Phase-Endpoints + Master-BE-Page mit Custom-Listing.
 *
 * Hybrid-Schema: Standard-Felder als eigene Spalten fuer WHERE/ORDER BY,
 * variable Phase-Daten in payload-JSON-Spalte (selected_pages, pages_data,
 * decisions, temp_dir, last_error).
 */
$GLOBALS['TL_DCA']['tl_pdf_import_job'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed'        => true,
        'notEditable'   => true,
        'notCopyable'   => true,
        'notDeletable'  => true,
        'sql' => [
            'keys' => [
                'id'         => 'primary',
                'tstamp'     => 'index',
                'status'     => 'index',
                'created_at' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => "INT UNSIGNED NOT NULL AUTO_INCREMENT",
        ],
        'tstamp' => [
            'sql' => "INT UNSIGNED NOT NULL DEFAULT 0",
        ],
        'created_at' => [
            'sql' => "INT UNSIGNED NOT NULL DEFAULT 0",
        ],
        'user_id' => [
            'sql' => "INT UNSIGNED DEFAULT NULL",
        ],
        'pdf_name' => [
            'sql' => "VARCHAR(255) NOT NULL DEFAULT ''",
        ],
        'pdf_hash' => [
            'sql' => "CHAR(40) NOT NULL DEFAULT ''",
        ],
        'page_count' => [
            'sql' => "INT UNSIGNED NOT NULL DEFAULT 0",
        ],
        'status' => [
            'sql' => "VARCHAR(32) NOT NULL DEFAULT 'created'",
        ],
        'last_phase' => [
            'sql' => "VARCHAR(32) DEFAULT NULL",
        ],
        'target_archive_pid' => [
            'sql' => "INT UNSIGNED DEFAULT NULL",
        ],
        'detected_issue_number' => [
            'sql' => "VARCHAR(16) DEFAULT NULL",
        ],
        'detected_issue_date' => [
            'sql' => "VARCHAR(64) DEFAULT NULL",
        ],
        'payload' => [
            'sql' => "JSON DEFAULT NULL",
        ],
        'deleted_at' => [
            'sql' => "INT UNSIGNED DEFAULT NULL",
        ],
    ],
];
