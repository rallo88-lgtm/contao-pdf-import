<?php

namespace Rallo\ContaoPdfImport\Service\Job;

enum JobStatus: string
{
    case Created            = 'created';
    case SplitDone          = 'split_done';
    case PagesSelected      = 'pages_selected';
    case OcrDone            = 'ocr_done';
    case ConflictsResolved  = 'conflicts_resolved';
    case Completed          = 'completed';
    case Failed             = 'failed';
    case Cancelled          = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Created           => 'Angelegt',
            self::SplitDone         => 'PDF gesplittet',
            self::PagesSelected     => 'Seiten gewählt',
            self::OcrDone           => 'OCR fertig',
            self::ConflictsResolved => 'Konflikte gelöst',
            self::Completed         => 'Fertig',
            self::Failed            => 'Fehlgeschlagen',
            self::Cancelled         => 'Abgebrochen',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Created            => '#71717a',
            self::SplitDone          => '#06b6d4',
            self::PagesSelected      => '#3b82f6',
            self::OcrDone            => '#8b5cf6',
            self::ConflictsResolved  => '#a855f7',
            self::Completed          => '#10b981',
            self::Failed             => '#ef4444',
            self::Cancelled          => '#888888',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed
            || $this === self::Failed
            || $this === self::Cancelled;
    }
}
