<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Mapa převodu pro srážky převzaté z předchozího mzdového systému: reference srážky ve
 * zdroji => případ nebo dohoda v MyÚčtu. Nese idempotenci zápisu srážek
 * ({@see PayrollTakeoverDeductionsWriter}); každý zdroj ji má ve své tabulce mapy převodu.
 */
interface PayrollTakeoverDeductionMap
{
    /** @return array<string,int> reference => id případu nebo dohody */
    public function all(int $supplierId): array;

    public function put(int $supplierId, string $reference, int $targetId, ?int $runId): void;
}
