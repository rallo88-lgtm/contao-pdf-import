<?php

/**
 * Bundle-Erweiterung von tl_news fuer den PDF-Import-Workflow:
 * - issue_number / pageNumber als Composite-Match-Key fuer NewsConflictChecker
 *   und Sortierung im Archiv-Listing.
 *
 * Felder werden zusaetzlich zur AddNewsIssueColumnsMigration deklariert,
 * damit Contao Schema-Sync sie kennt und nicht als Orphans markiert. Beide
 * Mechanismen sind idempotent.
 *
 * BE-Listing-Default (headline + date + time) reicht — headline ist seit
 * v0.3.1 wieder der Identifier "MBJ-{issue} Seite {page}", also schon
 * scanbar/sortierbar ohne Custom-Format.
 */

$GLOBALS['TL_DCA']['tl_news']['fields']['issue_number'] = [
    'label'     => ['Ausgabe-Nr.', 'MBJ-Ausgaben-Nummer (Composite-Match-Key fuer pdf-import).'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w50', 'maxlength' => 6],
    'sql'       => "int(10) unsigned NOT NULL default 0",
];

$GLOBALS['TL_DCA']['tl_news']['fields']['pageNumber'] = [
    'label'     => ['Seite (App)', 'Sequenzielle App-Seite ab 1 pro Ausgabe (nicht die Print-Seitenzahl).'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w50', 'maxlength' => 4],
    'sql'       => "int(10) unsigned NOT NULL default 0",
];

// Composite-Index fuer NewsConflictChecker-Lookup.
$GLOBALS['TL_DCA']['tl_news']['config']['sql']['keys']['pid,issue_number,pageNumber'] = 'index';
