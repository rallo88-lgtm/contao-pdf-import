<?php

namespace Rallo\ContaoPdfImport\Controller\Backend;

use Contao\CoreBundle\Controller\AbstractBackendController;
use Rallo\ContaoPdfImport\Service\EventLogger;
use Rallo\ContaoPdfImport\Service\PromoteService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Phase F — Promote-Stream. SSE-Ausgabe in DOS-Box:
 *
 *   [CHECK] ✓ Archive "MBJ-Aktuell" existiert (id=1)
 *   [CHECK] ✓ Issue-Reihenfolge: MBJ-184 > MBJ-183
 *   [CHECK] ✓ Eindeutige Seitennummern: keine Duplikate
 *   [STEP1] Aktuell → Archiv: 8 News verschoben
 *   [STEP2] Preview → Aktuell: 8 News verschoben + published=1
 *   [OK]    Live: MBJ-184. Archiviert: MBJ-183.
 *
 * Bei Pre-Flight-Fail: Stream bricht mit error-Event ab, kein DB-Write.
 */
#[Route(
    '/contao/pdf-import-publish/stream',
    name: 'pdf_import_publish_stream',
    defaults: ['_scope' => 'backend', '_token_check' => false],
    methods: ['POST'],
)]
class PdfImportPublishStreamController extends AbstractBackendController
{
    public function __construct(
        private readonly PromoteService $promote,
        private readonly EventLogger $eventLogger,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $tStart = microtime(true);

        return new StreamedResponse(function () use ($tStart) {
            @set_time_limit(0);
            @ob_implicit_flush(true);
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            $this->run($tStart);
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    private function run(float $tStart): void
    {
        try {
            $aktuellId = $this->promote->findArchiveId(PromoteService::TITLE_AKTUELL) ?? 0;
            $previewId = $this->promote->findArchiveId(PromoteService::TITLE_PREVIEW) ?? 0;
            $archivId  = $this->promote->findArchiveId(PromoteService::TITLE_ARCHIV) ?? 0;

            $this->emit('info', sprintf(
                'Phase F · Promote · Aktuell=id%d · Preview=id%d · Archiv=id%d',
                $aktuellId, $previewId, $archivId,
            ));

            // Pre-Flight live durchspielen — bei erstem Fehler abbrechen.
            $allOk = true;
            foreach ($this->promote->preflightChecks($aktuellId, $previewId, $archivId) as $check) {
                $line = ($check['ok'] ? '✓ ' : '✗ ') . $check['label'];
                if (!empty($check['detail'])) {
                    $line .= ' — ' . $check['detail'];
                }
                $this->emit($check['ok'] ? 'ok' : 'error', $line);
                if (!$check['ok']) {
                    $allOk = false;
                    break;
                }
            }

            if (!$allOk) {
                $this->emit('error', 'Pre-Flight fehlgeschlagen — kein DB-Write. Bitte oben gemeldetes Problem beheben.');
                $this->emitDone();
                return;
            }

            // Move ausfuehren
            $this->emit('info', 'Pre-Flight ok — fuehre Promote in Transaktion aus.');
            $stats = $this->promote->execute($aktuellId, $previewId, $archivId);

            $this->emit('ok', sprintf(
                'Step 1: Aktuell → Archiv: %d News verschoben',
                $stats['moved_to_archive'],
            ));
            $this->emit('ok', sprintf(
                'Step 2: Preview → Aktuell + published=1: %d News verschoben',
                $stats['moved_to_aktuell'],
            ));
            $this->emit('ok', sprintf(
                'Live: MBJ-%d (%d News)',
                $stats['issue_number'], $stats['moved_to_aktuell'],
            ));

            $this->eventLogger->log(
                eventType: 'promote.completed',
                reference: 'mbj-' . $stats['issue_number'],
                metadata: [
                    'source'           => 'phase_f',
                    'issue_number'     => $stats['issue_number'],
                    'moved_to_archive' => $stats['moved_to_archive'],
                    'moved_to_aktuell' => $stats['moved_to_aktuell'],
                    'duration_ms'      => (int) round((microtime(true) - $tStart) * 1000),
                ],
            );

            $resultUrl = $this->urlGenerator->generate(
                'pdf_import_publish',
                ['promoted' => 1],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $this->emitDone($resultUrl);
        } catch (\Throwable $e) {
            $this->emit('error', sprintf(
                '%s: %s',
                $this->shortName($e::class),
                $e->getMessage(),
            ));
            $this->emitDone();
        }
    }

    private function emit(string $type, string $msg): void
    {
        echo 'data: ' . json_encode(['type' => $type, 'msg' => $msg], JSON_UNESCAPED_UNICODE) . "\n\n";
        if (\function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }

    private function emitDone(?string $resultUrl = null): void
    {
        $payload = ['type' => 'done'];
        if ($resultUrl !== null) {
            $payload['resultUrl'] = $resultUrl;
        }
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (\function_exists('ob_flush')) {
            @ob_flush();
        }
        @flush();
    }

    private function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos !== false ? substr($fqn, $pos + 1) : $fqn;
    }
}
