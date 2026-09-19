<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Čtečka převzatého zaúčtování z jednoho konkrétního programu.
 *
 * Implementace smí být jakkoliv ukecaná - celý svůj program zná jen ona. Ven
 * ale dává výhradně {@see PayrollLegacyPostingRow}, takže o ní vyhodnocení
 * ({@see PayrollPostingMapProposalBuilder}) neví nic.
 *
 * Druhý zdroj se přidává implementací tohohle rozhraní a rozšířením ENUM
 * `source` v tabulce návrhů; jádro zůstává beze změny.
 */
interface PayrollLegacyPostingSource
{
    /** Označení zdroje; musí sedět na ENUM `source` tabulky návrhů. */
    public function sourceKey(): string;

    /**
     * Sečtené řádky převzatého zaúčtování.
     *
     * @return list<PayrollLegacyPostingRow>
     */
    public function postingRows(): array;
}
