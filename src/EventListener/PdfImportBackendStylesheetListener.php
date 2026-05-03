<?php

namespace Rallo\ContaoPdfImport\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;

/**
 * Bindet pdf-import-be.css im Contao-Backend ein.
 * Contao laedt alles aus diesem Hook auf jeder BE-Seite — damit ist die
 * Hauptmenue-Top-Level-Icon-CSS-Regel global verfuegbar.
 */
#[AsHook('getBackendStylesheets')]
class PdfImportBackendStylesheetListener
{
    public function __invoke(array $stylesheets): array
    {
        $stylesheets[] = 'bundles/contaopdfimport/css/pdf-import-be.css';
        return $stylesheets;
    }
}
