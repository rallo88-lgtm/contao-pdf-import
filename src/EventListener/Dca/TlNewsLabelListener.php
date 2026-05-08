<?php

namespace Rallo\ContaoPdfImport\EventListener\Dca;

/**
 * tl_news Backend-Listing: Importer-News bekommen ein gut scanbares
 * Format "MBJ-{issue} · S.{page} — {headline}", mit Rubrik (subheadline)
 * als Praefix sofern gesetzt. Standard-News (ohne issue_number) bleiben
 * im Default-Listing-Format.
 *
 * Static-Callback, weil Contao DCA-Callbacks die Klasse ohne Service-
 * Container instanziiert.
 */
final class TlNewsLabelListener
{
    /**
     * @param array<string,mixed> $row
     * @param array<int,string>   $args
     *
     * @return array<int,string>
     */
    public static function formatLabel(array $row, string $label, $dc, array $args): array
    {
        $issue = (int) ($row['issue_number'] ?? 0);
        $page  = (int) ($row['pageNumber'] ?? 0);

        if ($issue <= 0 || $page <= 0) {
            return $args;
        }

        $headline = trim((string) ($row['headline'] ?? ''));
        if ($headline === '') {
            $headline = '(ohne Headline)';
        }

        $rubrik = trim((string) ($row['subheadline'] ?? ''));
        $prefix = $rubrik !== '' ? sprintf('[%s] ', $rubrik) : '';

        // args[0] ist typischerweise der erste fields-Eintrag (headline).
        // Wir ueberschreiben ihn mit dem Identifier-Format. Restliche
        // Spalten (date/time) bleiben unangetastet.
        $args[0] = sprintf('%sMBJ-%d · S.%d — %s', $prefix, $issue, $page, $headline);

        return $args;
    }
}
