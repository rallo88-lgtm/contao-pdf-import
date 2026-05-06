<?php

use Contao\DC_Table;

/**
 * Singleton-Config (id=1) fuer globale Rule-Toggles.
 *
 * Aktuell 4 Heuristik-Toggles (default alle aktiv = "smart"-Modus).
 * Wenn deaktiviert, faellt Phase C/E auf rohen Textract-Output zurueck —
 * nuetzlich um Edge-Case-Pages ohne stoerende Heuristiken zu importieren.
 *
 * Schema-Sync legt die Tabelle automatisch an. Singleton-Row entsteht
 * implizit beim ersten Save (INSERT ... ON DUPLICATE KEY UPDATE auf id=1).
 *
 * BE-Edit-View ist die Custom-Twig-Page pdf_import_config (POST-Form),
 * nicht das Standard-DCA-Edit. Daher closed/notEditable/notDeletable.
 */
$GLOBALS['TL_DCA']['tl_pdf_import_config'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'closed'        => true,
        'notEditable'   => true,
        'notCopyable'   => true,
        'notDeletable'  => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
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
        'caption_heuristic' => [
            'sql' => "TINYINT(1) NOT NULL DEFAULT 1",
        ],
        'list_dedup' => [
            'sql' => "TINYINT(1) NOT NULL DEFAULT 1",
        ],
        'reading_order_reorder' => [
            'sql' => "TINYINT(1) NOT NULL DEFAULT 1",
        ],
        'multi_col_split' => [
            'sql' => "TINYINT(1) NOT NULL DEFAULT 1",
        ],
    ],
];
