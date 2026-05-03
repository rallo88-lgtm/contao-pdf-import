<?php

namespace Rallo\ContaoPdfImport\EventListener;

use Contao\CoreBundle\Routing\ScopeMatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Bindet pdf-import-be.css im Contao-Backend ein.
 *
 * Hintergrund: Contao 5.7 hat den klassischen `getBackendStylesheets`-Hook
 * NICHT MEHR — und `$GLOBALS['TL_USER_CSS']` ist Frontend-only. Sauberster
 * Weg fuer BE-CSS-Injection ist ein `kernel.response`-Listener der im
 * Backend-Scope das <link>-Tag direkt vor </head> einfuegt.
 *
 * Damit ist die Hauptmenue-Top-Level-Icon-CSS-Regel auf jeder BE-Seite
 * verfuegbar (egal welche Route).
 */
#[AsEventListener(KernelEvents::RESPONSE, priority: 0)]
class PdfImportBackendStylesheetListener
{
    private const CSS_PATH = '/bundles/contaopdfimport/css/pdf-import-be.css';

    public function __construct(private readonly ScopeMatcher $scopeMatcher) {}

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        if (!$this->scopeMatcher->isBackendRequest($event->getRequest())) {
            return;
        }

        $response = $event->getResponse();
        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html')) {
            return;
        }

        $html = $response->getContent();
        if (!is_string($html) || !str_contains($html, '</head>')) {
            return;
        }

        // Cache-Buster ueber Bundle-CSS-mtime — bei jedem Asset-Update
        // bekommt der Browser garantiert die neue Version. data-turbo-track
        // damit Turbo bei Hash-Drift einen Full-Reload macht.
        $cssFile = \dirname(__DIR__) . '/Resources/public/css/pdf-import-be.css';
        $version = is_file($cssFile) ? @filemtime($cssFile) : time();
        $link = sprintf(
            '<link rel="stylesheet" href="%s?v=%d" data-turbo-track="reload">',
            self::CSS_PATH,
            $version
        );
        $response->setContent(str_replace('</head>', $link . '</head>', $html));
    }
}
