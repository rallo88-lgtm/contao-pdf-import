<?php

namespace Rallo\ContaoPdfImport\Service\Ocr;

use Aws\Textract\TextractClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wichtig: Credentials muessen EXPLIZIT uebergeben werden. Symfony-Dotenv
 * laedt .env-Vars nur in $_SERVER/$_ENV, nicht via putenv(). Das AWS-SDK
 * default-CredentialProvider ruft aber getenv() — findet sie also nicht
 * und faellt auf EC2-Instance-Metadata zurueck (= scheitert auf Mittwald).
 */
final class TextractClientFactory
{
    public function __construct(
        #[Autowire(env: 'AWS_REGION')]            private readonly string $region,
        #[Autowire(env: 'AWS_ACCESS_KEY_ID')]     private readonly string $accessKeyId,
        #[Autowire(env: 'AWS_SECRET_ACCESS_KEY')] private readonly string $secretAccessKey,
    ) {}

    public function create(): TextractClient
    {
        return new TextractClient([
            'version'     => 'latest',
            'region'      => $this->region,
            'credentials' => [
                'key'    => $this->accessKeyId,
                'secret' => $this->secretAccessKey,
            ],
        ]);
    }
}
