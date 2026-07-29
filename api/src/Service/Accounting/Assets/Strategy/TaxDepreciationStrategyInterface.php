<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets\Strategy;

use MyInvoice\Service\Accounting\Assets\DepreciationContext;

/**
 * Strategie daňových odpisů (Epic F3, spec §2.2). Sazby, koeficienty a limity
 * ZDP jsou konstanty implementací (R4) — změna zákona = nová verze konstant,
 * ne datová migrace.
 */
interface TaxDepreciationStrategyInterface
{
    public const LAW_VERSION = 'ZDP 586/1992 Sb., znění k 1. 1. 2026';

    /**
     * Kompletní průběh daňových odpisů: minulost převzatá z confirmedEntries,
     * budoucnost dopočtená do plného odepsání (nebo do roku vyřazení).
     * @return list<array{fiscal_year:int, amount:float, full_amount:float,
     *   residual_start:float, residual_end:float, is_half:bool, is_paused:bool,
     *   months_count:?int, months:?list<array{month:string,amount:float}>,
     *   source:'confirmed'|'computed', note:?string}>
     */
    public function plan(DepreciationContext $ctx): array;

    /** Řádek jednoho roku (pro book/dispose) — stejný tvar, jeden prvek. */
    public function yearRow(DepreciationContext $ctx, int $fiscalYear): ?array;
}
