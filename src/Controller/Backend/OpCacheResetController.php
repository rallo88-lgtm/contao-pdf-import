<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Admin-Action: ruft opcache_reset() im FPM-Context.
 *
 * Hintergrund: nach SCP-Bundle-Updates serviert FPM auf Mittwald oft
 * stale Bytecode trotz validate_timestamps=1 + cache:clear. CLI hat einen
 * eigenen OpCache, kann den FPM-Pool nicht resetten — daher braucht es
 * einen Web-Hit auf einen Endpoint der opcache_reset() ausfuehrt.
 *
 * Siehe Memory-File feedback_opcache_after_scp.md fuer Hintergrund.
 *
 * POST-only mit REQUEST_TOKEN-Check. Redirect zurueck zur Referer.
 */
#[Route(
    '/contao/pdf-import/op-reset',
    name: 'pdf_import_op_reset',
    defaults: ['_scope' => 'backend', '_token_check' => true],
    methods: ['POST'],
)]
class OpCacheResetController extends AbstractBackendController
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(Request $request): Response
    {
        $msg = $this->doReset();

        $referer = $request->headers->get('referer');
        $back    = $referer ?: $this->urlGenerator->generate('pdf_import');

        return new RedirectResponse(
            $back . (str_contains($back, '?') ? '&' : '?') . 'opcache=' . urlencode($msg),
            Response::HTTP_SEE_OTHER,
        );
    }

    private function doReset(): string
    {
        if (!\function_exists('opcache_reset')) {
            return 'unavailable';
        }
        return @opcache_reset() ? 'reset_ok' : 'reset_failed';
    }
}
