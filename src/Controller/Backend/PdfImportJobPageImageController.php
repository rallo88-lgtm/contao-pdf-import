<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\Job\JobFilesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serviert gerasterte Job-Pages (var/data/pdf_import/jobs/{id}/pages/p{N}.jpg)
 * fuer das Phase-B-Thumbnail-Grid und spaetere Phasen.
 *
 * BE-scoped, GET-only, kein _token_check (Image-Route, hash-aehnlicher Path).
 * Validiert Path-Existence — verhindert Path-Traversal weil die Route nur
 * \d+ als Param akzeptiert.
 */
#[Route(
    '/contao/pdf-import/job/{jobId}/page/{pageNumber}.jpg',
    name: 'pdf_import_job_page_image',
    requirements: ['jobId' => '\d+', 'pageNumber' => '\d+'],
    defaults: ['_scope' => 'backend', '_token_check' => false],
    methods: ['GET'],
)]
class PdfImportJobPageImageController extends AbstractBackendController
{
    public function __construct(
        private readonly JobFilesystem $jobFs,
    ) {}

    public function __invoke(int $jobId, int $pageNumber): Response
    {
        $path = $this->jobFs->getPagePath($jobId, $pageNumber);
        if (!is_file($path)) {
            throw new NotFoundHttpException('Page image not found.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }
}
