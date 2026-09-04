<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Stabilní chyba mapovacího rozhodnutí bez business hodnot ve zprávě. */
final class CompanyBackupReferenceDecisionException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly ?string $requirementId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $errorCode
            . ($requirementId === null ? '' : ': ' . $requirementId),
            0,
            $previous,
        );
    }
}
