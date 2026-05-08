<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\PromoteService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Phase F — Publish/Promote-Übersicht.
 *
 * Zeigt:
 *   - Promote-Button (Preview → Aktuell, Aktuell → Archiv) inkl. DOS-Box
 *   - Tabelle aller Ausgaben mit Status (Preview/Aktiv/Archiv), Seiten-
 *     Anzahl und Bulk-Publish-Toggle (Auge-Icon)
 *
 * Pre-Flight-Checks laufen LIVE im Stream-Controller — die Index-View
 * zeigt nur eine kurze "Bereit?"-Zusammenfassung mit den IDs der drei
 * Archive (oder Warnung wenn eines fehlt).
 */
#[Route(
    '/contao/pdf-import-publish',
    name: 'pdf_import_publish',
    defaults: ['_scope' => 'backend', '_token_check' => true],
)]
class PdfImportPublishController extends AbstractBackendController
{
    public function __construct(private readonly PromoteService $promote) {}

    public function __invoke(Request $request): Response
    {
        $aktuellId = $this->promote->findArchiveId(PromoteService::TITLE_AKTUELL);
        $previewId = $this->promote->findArchiveId(PromoteService::TITLE_PREVIEW);
        $archivId  = $this->promote->findArchiveId(PromoteService::TITLE_ARCHIV);

        return $this->render('@ContaoPdfImport/backend/pdf_import_publish.html.twig', [
            'aktuellId' => $aktuellId,
            'previewId' => $previewId,
            'archivId'  => $archivId,
            'archivesReady' => $aktuellId && $previewId && $archivId,
            'issues'    => $this->promote->listIssues(),
            'toggled'   => $request->query->getBoolean('toggled'),
            'promoted'  => $request->query->getBoolean('promoted'),
        ]);
    }
}
