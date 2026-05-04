<?php

namespace Rallo\ContaoPdfImport\Service;

use Contao\BackendUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Schreibt Toolbox-Events in tl_pdf_import_event.
 *
 * BE-User-ID wird aus dem Security-Token gezogen (null bei CLI/Cron).
 * Schreibt synchron — bei Performance-Sorgen spaeter Symfony-Messenger
 * dazwischen schalten, jetzt aber erst mal direkter Insert pro Event.
 */
final class EventLogger
{
    public function __construct(
        private readonly Connection $db,
        private readonly Security $security,
    ) {}

    public function log(
        string $eventType,
        ?string $reference = null,
        int $costMicrosEur = 0,
        bool $cacheHit = false,
        array $metadata = [],
    ): void {
        $userId = null;
        $user   = $this->security->getUser();
        if ($user instanceof BackendUser) {
            $userId = (int) $user->id;
        }

        try {
            $this->db->insert('tl_pdf_import_event', [
                'tstamp'          => time(),
                'event_type'      => $eventType,
                'user_id'         => $userId,
                'reference'       => $reference,
                'cost_micros_eur' => $costMicrosEur,
                'cache_hit'       => $cacheHit ? 1 : 0,
                'metadata'        => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) {
            // Logging darf nie Hauptaktion zerstoeren. Silent-fail wenn Tabelle
            // nicht da (= Frischinstall vor Migration) oder Connection krank.
        }
    }
}
