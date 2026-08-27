<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Bezpečný WHERE fragment a jeho poziční parametry pro jednu firmu. */
final readonly class CompanyBackupSqlSelection
{
    /** @param list<mixed> $params */
    public function __construct(
        public string $where,
        public array $params,
    ) {
        if ($where === '' || $where === '1 = 1') {
            throw new \InvalidArgumentException('Tenantový SQL selektor nesmí být neomezený.');
        }
    }
}
