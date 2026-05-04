<?php

use Contao\DC_Table;

/**
 * Tracking-Tabelle fuer Toolbox-Events (OCR-Calls, Imports, Translations, ...).
 *
 * Read-only aus BE-Sicht (closed + notEditable + notDeletable). BE-Listing erfolgt
 * ueber den dedizierten Stats-Controller, nicht ueber den Standard-DC_Table-View.
 * Die DCA-Datei existiert nur damit Doctrine-Schema-Sync die Tabelle anlegt
 * und nicht beim naechsten contao:migrate --with-deletes droppt.
 */
$GLOBALS['TL_DCA']['tl_pdf_import_event'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'closed'           => true,
        'notEditable'      => true,
        'notCopyable'      => true,
        'notDeletable'     => true,
        'sql' => [
            'keys' => [
                'id'         => 'primary',
                'tstamp'     => 'index',
                'event_type' => 'index',
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
        'event_type' => [
            'sql' => "VARCHAR(64) NOT NULL DEFAULT ''",
        ],
        'user_id' => [
            'sql' => "INT UNSIGNED DEFAULT NULL",
        ],
        'reference' => [
            'sql' => "VARCHAR(128) DEFAULT NULL",
        ],
        'cost_micros_eur' => [
            'sql' => "INT UNSIGNED NOT NULL DEFAULT 0",
        ],
        'cache_hit' => [
            'sql' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
        ],
        'metadata' => [
            'sql' => "JSON DEFAULT NULL",
        ],
    ],
];
