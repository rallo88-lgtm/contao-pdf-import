<?php

namespace Rallo\ContaoPdfImport\Service\IssueDetection;

use Rallo\ContaoPdfImport\Service\Ocr\OcrResult;

interface IssueNumberDetectorInterface
{
    public function detectFromResult(OcrResult $result): ?DetectedIssue;
}
