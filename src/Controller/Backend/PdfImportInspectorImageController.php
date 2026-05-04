<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/contao/pdf-import-inspector/image/{hash}.jpg',
    name: 'pdf_import_inspector_image',
    requirements: ['hash' => '[a-f0-9]{40}'],
    defaults: ['_scope' => 'backend', '_token_check' => false],
    methods: ['GET'],
)]
class PdfImportInspectorImageController extends AbstractBackendController
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {}

    public function __invoke(string $hash): Response
    {
        $path = $this->projectDir . '/var/cache/textract/img/' . $hash . '.jpg';
        if (!is_file($path)) {
            throw new NotFoundHttpException('Image expired or unknown.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }
}
