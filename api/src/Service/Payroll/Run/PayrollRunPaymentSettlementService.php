<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Kontrola platebního ledgeru pro příkaz `mark_paid`.
 *
 * Existence platební dávky sama o sobě nic neznamená — dávka se dá vystavit
 * i na část závazků. Akceptační kritérium MZ-17 zní, že nevysvětlený rozdíl
 * blokuje uzavření běhu, takže se ptáme na pokrytí: každý platební závazek
 * revize musí být beze zbytku rozalokovaný do položek platební dávky
 * (`payroll_payment_allocations`). Nepokrytý zbytek přechod zablokuje
 * a vyčíslí se.
 *
 * Částečně uhrazený běh do `paid` nesmí. `paid` je poslední brána před
 * `close` a jediné místo, kde se rozdíl dá ještě odhalit; kdyby se do něj
 * pustil běh s nepokrytým zbytkem, rozdíl by se ztratil v uzavřeném období.
 * Legitimní cesta pro doplatky a opravy vede přes `request_correction`
 * a novou revizi, ne přes „skoro uhrazeno".
 */
final class PayrollRunPaymentSettlementService
{
    private const KIND_LABELS = [
        'net_wage' => 'čistá mzda',
        'social_insurance' => 'sociální pojištění',
        'health_insurance' => 'zdravotní pojištění',
        'advance_tax' => 'záloha na daň',
        'withholding_tax' => 'srážková daň',
        'deduction' => 'srážka',
        'enforcement' => 'exekuční srážka',
        'insolvency' => 'insolvenční srážka',
        'benefit' => 'benefit',
        'statutory_insurance' => 'zákonné pojištění',
        'other' => 'jiný závazek',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   liability_count:int,
     *   batch_count:int,
     *   required_minor:int,
     *   allocated_minor:int,
     *   uncovered:list<array{
     *     liability_id:int,
     *     liability_kind:string,
     *     employee_name:?string,
     *     currency_code:string,
     *     uncovered_minor:int
     *   }>
     * }
     */
    public function inspect(int $supplierId, int $revisionId): array
    {
        if ($supplierId <= 0 || $revisionId <= 0) {
            throw new \InvalidArgumentException(
                'Firma a revize kontroly úhrad musí být kladná čísla.',
            );
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id,
                    liability.liability_kind,
                    liability.currency_code,
                    liability.amount_minor,
                    employee.full_name AS employee_name,
                    COALESCE(SUM(allocation.amount_minor), 0) AS allocated_minor,
                    COUNT(DISTINCT payment_item.batch_id) AS batch_count
               FROM payroll_payment_liabilities liability
          LEFT JOIN payroll_payment_allocations allocation
                 ON allocation.supplier_id = liability.supplier_id
                AND allocation.liability_id = liability.id
          LEFT JOIN payroll_payment_items payment_item
                 ON payment_item.supplier_id = allocation.supplier_id
                AND payment_item.id = allocation.item_id
          LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = liability.supplier_id
                AND employee.id = liability.employee_id
              WHERE liability.supplier_id = ?
                AND liability.revision_id = ?
           GROUP BY liability.id, liability.liability_kind,
                    liability.currency_code, liability.amount_minor,
                    employee.full_name
           ORDER BY liability.liability_kind, liability.id'
        );
        $statement->execute([$supplierId, $revisionId]);

        $liabilityCount = 0;
        $batchIds = 0;
        $required = 0;
        $allocated = 0;
        $uncovered = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila neplatný platební závazek.',
                );
            }
            $amount = (int) $row['amount_minor'];
            $covered = (int) $row['allocated_minor'];
            if ($amount <= 0 || $covered < 0) {
                throw new \UnexpectedValueException(
                    'Platební závazek revize má nepřípustnou částku.',
                );
            }
            ++$liabilityCount;
            $batchIds += (int) $row['batch_count'];
            $required += $amount;
            $allocated += $covered;
            if ($covered >= $amount) {
                continue;
            }
            $name = $row['employee_name'] ?? null;
            $uncovered[] = [
                'liability_id' => (int) $row['id'],
                'liability_kind' => (string) $row['liability_kind'],
                'employee_name' => is_string($name) && trim($name) !== ''
                    ? trim($name)
                    : null,
                'currency_code' => (string) $row['currency_code'],
                'uncovered_minor' => $amount - $covered,
            ];
        }

        return [
            'liability_count' => $liabilityCount,
            'batch_count' => $batchIds,
            'required_minor' => $required,
            'allocated_minor' => $allocated,
            'uncovered' => $uncovered,
        ];
    }

    /**
     * Lidsky čitelné vyčíslení nepokrytého zbytku. Účetní z něj musí poznat,
     * kolik chybí a u čeho — ne že „chybí platební dávka".
     *
     * @param array{
     *   liability_count:int,
     *   batch_count:int,
     *   required_minor:int,
     *   allocated_minor:int,
     *   uncovered:list<array{
     *     liability_id:int,
     *     liability_kind:string,
     *     employee_name:?string,
     *     currency_code:string,
     *     uncovered_minor:int
     *   }>
     * } $coverage
     */
    public function blockingReason(array $coverage): string
    {
        $missing = $coverage['required_minor'] - $coverage['allocated_minor'];
        $details = [];
        foreach (array_slice($coverage['uncovered'], 0, 5) as $item) {
            $label = self::KIND_LABELS[$item['liability_kind']]
                ?? $item['liability_kind'];
            if ($item['employee_name'] !== null) {
                $label .= ' — ' . $item['employee_name'];
            }
            $details[] = sprintf(
                '%s %s %s',
                $label,
                self::amount($item['uncovered_minor']),
                $item['currency_code'],
            );
        }
        $rest = count($coverage['uncovered']) - count($details);
        if ($rest > 0) {
            $details[] = "a další ({$rest})";
        }

        return sprintf(
            'Mzdový běh nelze označit za uhrazený: platební dávky nepokrývají '
            . '%s z celkových %s (chybí %d z %d závazků). Nepokryto: %s. '
            . 'Doplňte platební dávku nad chybějícími závazky, nebo u běhu '
            . 'vyžádejte opravu, pokud se částky změnily.',
            self::amount($missing),
            self::amount($coverage['required_minor']),
            count($coverage['uncovered']),
            $coverage['liability_count'],
            implode('; ', $details),
        );
    }

    private static function amount(int $minor): string
    {
        return number_format($minor / 100, 2, ',', "\u{00a0}");
    }
}
