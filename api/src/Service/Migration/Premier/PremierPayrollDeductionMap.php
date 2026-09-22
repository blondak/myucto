<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Repository\PremierImportRepository;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverDeductionMap;

/**
 * Srážky převodu z PREMIER v mapě převodu PREMIER (`premier_import_map`): trvalá srážka
 * `MZ_SRAZ` (`INTER:S_SRAINT`) => exekuční případ nebo dohoda o srážkách.
 */
final class PremierPayrollDeductionMap implements PayrollTakeoverDeductionMap
{
    /**
     * Druh záznamu mapy. Patří mezi konstanty `PremierImportRepository::KIND_*`; tahle
     * větev ten soubor nevlastní, proto zatím tady.
     */
    public const KIND = 'payroll_deduction';

    public function __construct(private readonly PremierImportRepository $map) {}

    public function all(int $supplierId): array
    {
        return $this->map->all($supplierId, self::KIND);
    }

    public function put(int $supplierId, string $reference, int $targetId, ?int $runId): void
    {
        $this->map->put($supplierId, self::KIND, $reference, $targetId, $runId);
    }
}
