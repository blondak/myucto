<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Bezpečný stav řízení jobu, který neprozrazuje cizí tenant ani fyzickou cestu. */
final class CompanyBackupManagementException extends \RuntimeException
{
    /** @var list<string> */
    private const CODES = [
        'not_found',
        'not_cancellable',
        'not_deletable',
        'artifact_delete_deferred',
        'state_conflict',
    ];

    public function __construct(
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        if (!in_array($errorCode, self::CODES, true)) {
            throw new \InvalidArgumentException(
                'Neznámý stav správy zálohového jobu.',
            );
        }
        parent::__construct($errorCode, 0, $previous);
    }
}
