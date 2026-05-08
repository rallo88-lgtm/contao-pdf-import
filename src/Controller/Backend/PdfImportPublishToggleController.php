<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\PromoteService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Bulk-Publish-Toggle: togglet alle News einer (archive, issue_number)-
 * Kombination atomar. Wenn alle published sind, werden sie alle unpublished
 * — sonst alle published.
 *
 * Verwendung im Listing als kompakter Auge-Klick statt 60-mal einzeln.
 */
#[Route(
    '/contao/pdf-import-publish/toggle',
    name: 'pdf_import_publish_toggle',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['POST'],
)]
class PdfImportPublishToggleController extends AbstractBackendController
{
    public function __construct(
        private readonly PromoteService $promote,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $archiveId   = (int) $request->request->get('archive_id', 0);
        $issueNumber = (int) $request->request->get('issue_number', 0);

        if ($archiveId > 0 && $issueNumber > 0) {
            $this->promote->togglePublished($archiveId, $issueNumber);
        }

        return new RedirectResponse(
            $this->urlGenerator->generate('pdf_import_publish', ['toggled' => 1])
        );
    }
}
