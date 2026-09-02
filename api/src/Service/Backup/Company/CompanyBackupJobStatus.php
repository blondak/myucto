<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stav bezpečně obnovitelného exportu jedné firmy. */
enum CompanyBackupJobStatus: string
{
    case Queued = 'queued';
    case Checking = 'checking';
    case Snapshotting = 'snapshotting';
    case Packaging = 'packaging';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isProcessing(): bool
    {
        return match ($this) {
            self::Queued,
            self::Checking,
            self::Snapshotting,
            self::Packaging => true,
            self::Completed,
            self::Failed,
            self::Cancelled,
            self::Expired => false,
        };
    }

    public function isDownloadable(): bool
    {
        return $this === self::Completed;
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this->isProcessing()
            && in_array($next, [self::Failed, self::Cancelled, self::Expired], true)
        ) {
            return true;
        }

        return match ($this) {
            self::Queued => $next === self::Checking,
            self::Checking => $next === self::Snapshotting,
            self::Snapshotting => $next === self::Packaging,
            self::Packaging => $next === self::Completed,
            self::Completed => $next === self::Expired,
            self::Failed,
            self::Cancelled,
            self::Expired => false,
        };
    }
}
