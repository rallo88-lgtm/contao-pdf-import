<?php

namespace Rallo\ContaoPdfImport;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ContaoPdfImportBundle extends Bundle
{
    public const VERSION = '0.2.0';

    public function getPath(): string
    {
        return \dirname(__DIR__) . '/src';
    }
}
