<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Bezpečný stav stažení, který lze zveřejnit bez informace o cizím jobu. */
final class CompanyBackupDownloadException extends \RuntimeException
{
    /** @var list<string> */
    private const CODES = [
        'not_found',
        'not_ready',
        'artifact_expired',
        'artifact_unavailable',
    ];

    public function __construct(
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        if (!in_array($errorCode, self::CODES, true)) {
            throw new \InvalidArgumentException(
                'Neznámý stav stažení zálohy firmy.',
            );
        }
        parent::__construct($errorCode, 0, $previous);
    }
}
