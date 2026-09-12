<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

interface IntrastatDataSource
{
    /** @return array{vat_id:?string,country_iso2:string}|null */
    public function declarant(int $supplierId): ?array;

    /** @return list<string> */
    public function euCountryCodes(): array;

    /** @return list<array<string,mixed>> */
    public function movementRows(int $supplierId, string $from, string $toExclusive, string $direction): array;
}
