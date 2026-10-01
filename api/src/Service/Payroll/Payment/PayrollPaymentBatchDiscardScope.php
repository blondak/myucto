<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

/**
 * Jediné místo, které ví, jak se v SQL pozná alokace ZAHOZENÉ dávky.
 *
 * Zahozená dávka (`payroll_payment_batch_discards`, migrace 1952) zůstává
 * v ledgeru celá, jen přestane držet závazky. Každý dotaz, který z alokací
 * počítá „zařazeno do dávky", ji proto musí vynechat - jinak by se závazek po
 * zahození nevrátil do „Co zaplatit" a nová dávka by ho nemohla převzít.
 * Stejné pravidlo drží trigger `trg_payroll_payment_allocation_validate_insert`.
 *
 * Dotazy nad úhradami (`payroll_payment_matches`) predikát nepotřebují:
 * dávku s doloženou úhradou zahodit nejde a k zahozené se úhrada spárovat
 * nedá (trigger `trg_payroll_payment_match_discard_guard`).
 */
final class PayrollPaymentBatchDiscardScope
{
    /**
     * Predikát „alokace patří živé (nezahozené) dávce".
     *
     * @param string $allocationAlias alias tabulky `payroll_payment_allocations`
     * @param string $suffix rozliší aliasy, když se predikát v dotazu opakuje
     */
    public static function activeAllocation(
        string $allocationAlias,
        string $suffix = '',
    ): string {
        self::assertIdentifier($allocationAlias);
        self::assertIdentifier('x' . $suffix);
        $item = 'discard_item' . $suffix;
        $discard = 'batch_discard' . $suffix;

        return "NOT EXISTS (
            SELECT 1
              FROM payroll_payment_items {$item}
              JOIN payroll_payment_batch_discards {$discard}
                ON {$discard}.supplier_id = {$item}.supplier_id
               AND {$discard}.batch_id = {$item}.batch_id
             WHERE {$item}.supplier_id = {$allocationAlias}.supplier_id
               AND {$item}.id = {$allocationAlias}.item_id
        )";
    }

    /**
     * Predikát „dávka je živá (nezahozená)".
     *
     * @param string $batchAlias alias tabulky `payroll_payment_batches`
     */
    public static function activeBatch(
        string $batchAlias,
        string $suffix = '',
    ): string {
        self::assertIdentifier($batchAlias);
        self::assertIdentifier('x' . $suffix);
        $discard = 'batch_discard' . $suffix;

        return "NOT EXISTS (
            SELECT 1
              FROM payroll_payment_batch_discards {$discard}
             WHERE {$discard}.supplier_id = {$batchAlias}.supplier_id
               AND {$discard}.batch_id = {$batchAlias}.id
        )";
    }

    private static function assertIdentifier(string $value): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                'Alias tabulky v predikátu zahozené dávky není platný.',
            );
        }
    }
}
