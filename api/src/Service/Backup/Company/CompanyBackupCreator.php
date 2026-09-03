<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Bezpečnostní hranice mezi HTTP transportem a založením exportního jobu. */
interface CompanyBackupCreator
{
    public function create(
        int $supplierId,
        int $userId,
        string $sessionToken,
        string $proofToken,
        #[\SensitiveParameter] string $password,
        ?string $ip,
        string $userAgent,
    ): string;
}
