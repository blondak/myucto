<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use DateTimeImmutable;

/** Krátká časová retence hotového šifrovaného archivu. */
final readonly class CompanyBackupJobRetentionPolicy
{
    public const DEFAULT_HOURS = 24;
    public const MIN_HOURS = 1;
    public const MAX_HOURS = 7 * 24;

    public function __construct(public int $hours)
    {
        if ($hours < self::MIN_HOURS || $hours > self::MAX_HOURS) {
            throw new \InvalidArgumentException(
                'Retence zálohy firmy musí být mezi 1 a 168 hodinami.',
            );
        }
    }

    public static function defaults(): self
    {
        return new self(self::DEFAULT_HOURS);
    }

    public function expiresAt(DateTimeImmutable $completedAt): DateTimeImmutable
    {
        return $completedAt->setTimestamp(
            $completedAt->getTimestamp() + ($this->hours * 3600),
        );
    }
}
