<?php

namespace Rallo\ContaoPdfImport\Service\Job;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pfad-Helper fuer Job-Working-Files. Lebt unter var/data/pdf_import/jobs/{id}/
 * — bewusst ausserhalb von var/cache (sonst killt cache:clear die laufenden
 * Jobs). Phase A speichert source.pdf + pages/p{N}.jpg, Phase E zieht das
 * von hier in files/mbj-import/ und legt tl_files-Entries an.
 */
final class JobFilesystem
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {}

    public function getJobDir(int $jobId): string
    {
        return $this->projectDir . '/var/data/pdf_import/jobs/' . $jobId;
    }

    public function getSourcePdfPath(int $jobId): string
    {
        return $this->getJobDir($jobId) . '/source.pdf';
    }

    public function getPagesDir(int $jobId): string
    {
        return $this->getJobDir($jobId) . '/pages';
    }

    public function getPagePath(int $jobId, int $pageNumber): string
    {
        return $this->getPagesDir($jobId) . '/p' . $pageNumber . '.jpg';
    }

    public function ensureJobDir(int $jobId): void
    {
        $dirs = [
            $this->getJobDir($jobId),
            $this->getPagesDir($jobId),
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }
}
