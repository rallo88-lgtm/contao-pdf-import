<?php

/**
 * Bundle-Erweiterung von tl_news fuer den PDF-Import-Workflow:
 * - issue_number / pageNumber: Composite-Match-Key (NewsConflictChecker)
 *   und Sortierung im Archiv-Listing.
 * - Custom Label-Format: "MBJ-{issue} · S.{page} — {headline}" fuer Pages,
 *   die per Importer-Job angelegt wurden. Bei Standard-News (issue_number
 *   leer) bleibt das Contao-Default-Listing aktiv.
 *
 * Felder werden zusaetzlich zur AddNewsIssueColumnsMigration deklariert,
 * damit Contao Schema-Sync sie kennt und nicht als Orphans markiert. Beide
 * Mechanismen sind idempotent.
 */

use Rallo\ContaoPdfImport\EventListener\Dca\TlNewsLabelListener;

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

// Label-Format-Override nur fuer Importer-News (issue_number gesetzt).
$GLOBALS['TL_DCA']['tl_news']['list']['label']['label_callback'] = [TlNewsLabelListener::class, 'formatLabel'];
