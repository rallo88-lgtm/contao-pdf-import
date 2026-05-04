<?php

namespace Rallo\ContaoPdfImport\Service\Ocr;

use Aws\Textract\TextractClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TextractClientFactory
{
    public function __construct(
        #[Autowire(env: 'AWS_REGION')] private readonly string $region,
    ) {}

    public function create(): TextractClient
    {
        return new TextractClient([
            'version' => 'latest',
            'region'  => $this->region,
        ]);
    }
}
