<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Referenční validace výplatního pravidla — část, která sahá do databáze.
 *
 * Tvar pravidla (fixed/percentage/remainder, cíl vs. reference) hlídá už
 * PayrollPayoutRuleInput. Tady se ověřuje, že zadané odkazy někam vedou a že
 * celá sada pravidel osoby dává dohromady rozdělení, které
 * PayoutAllocationService dokáže spočítat.
 *
 * Proč to nestačí nechat na databázi a na materializeru: DB umí přes unikátní
 * index vynutit „nejvýš jeden aktivní zbytek", ale výsledkem je chyba 1062;
 * materializer chybu odhalí, ale až nad ZMRAZENOU revizí, kde už uživatel nemá
 * co opravovat. Cílem téhle třídy je říct to česky a v okamžiku zadání.
 */
final class PayrollPayoutRuleValidator
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<array<string,mixed>> $currentRules
     *        Zamčená sada pravidel osoby (PayrollPayoutRuleRepository::lockForEmployee).
     * @param int|null $ruleId Id upravovaného pravidla; NULL při zakládání.
     */
    public function assertWritable(
        int $supplierId,
        int $employeeId,
        PayrollPayoutRuleInput $input,
        array $currentRules,
        ?int $ruleId = null,
    ): void {
        $this->assertDestination($supplierId, $employeeId, $input);
        $this->assertSet($input, $currentRules, $ruleId);
    }

    /**
     * Ověří, že cíl výplaty existuje, patří téhle osobě a téhle firmě.
     */
    private function assertDestination(
        int $supplierId,
        int $employeeId,
        PayrollPayoutRuleInput $input,
    ): void {
        if ($input->destinationKind === 'cash') {
            return;
        }
        if ($input->destinationKind === PayrollPartnerSettlement::KIND) {
            PayrollPartnerSettlement::assertEligible(
                $this->relationTypes($supplierId, $employeeId),
                $employeeId,
            );

            return;
        }

        $accountId = $input->bankAccountId();
        if ($accountId === null) {
            throw new \InvalidArgumentException(
                'Bankovní cíl musí odkazovat na výplatní účet zaměstnance '
                . 've tvaru account:<id>.',
            );
        }
        // Scope na (supplier_id, employee_id, id) je tu podstatný: bez employee_id
        // by šlo pravidlo namířit na účet KOLEGY ze stejné firmy a mzda by odešla
        // jinam. Materializer by to nezachytil — účet by ve zmrazených účtech
        // osoby nebyl a spadl by až s obecnou hláškou o chybějícím cíli.
        //
        // Stav OVĚŘENÍ se tu záměrně nečte: neověřený účet zápis pravidla
        // NEBLOKUJE (pravidlo musí jít připravit dřív, než ověření proběhne).
        // Uživateli se to říká varováním, které skládá PayrollPayoutRuleWarnings
        // nad příznakem `destination_verified` z repozitáře.
        $stmt = $this->db->pdo()->prepare(
            'SELECT is_active
               FROM payroll_person_accounts
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employeeId, $accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new \OutOfBoundsException(
                "Výplatní účet {$accountId} k tomuto zaměstnanci neexistuje.",
            );
        }
        if ((int) $account['is_active'] !== 1) {
            throw new \DomainException(
                "Výplatní účet {$accountId} je vyřazený, mzdu na něj poslat nelze.",
            );
        }
    }

    /**
     * Kontroly nad celou sadou pravidel osoby.
     *
     * @param list<array<string,mixed>> $currentRules
     */
    private function assertSet(
        PayrollPayoutRuleInput $input,
        array $currentRules,
        ?int $ruleId,
    ): void {
        $others = array_values(array_filter(
            $currentRules,
            static fn (array $rule): bool => $rule['id'] !== $ruleId
                && $rule['is_active'] === true,
        ));

        if ($input->isActive && $input->allocationKind === 'remainder') {
            foreach ($others as $rule) {
                if ($rule['allocation_kind'] === 'remainder') {
                    throw new \DomainException(
                        'Zaměstnanec už má aktivní pravidlo pro zbytek výplaty. '
                        . 'Zbytek může jít jen na jeden cíl — nejdřív upravte '
                        . 'nebo deaktivujte to stávající.',
                    );
                }
            }
        }

        // Procenta se počítají z čisté mzdy PŘED odečtením pevných částek
        // (PayoutAllocationService::percentageAmount), takže součet nad 100 %
        // znamená, že alokace vždy přeteče výplatu a mzda nepůjde vyplatit.
        $basisPoints = $input->isActive ? ($input->basisPoints ?? 0) : 0;
        $fixedMinor = $input->isActive ? ($input->amountMinor ?? 0) : 0;
        foreach ($others as $rule) {
            $basisPoints += (int) ($rule['basis_points'] ?? 0);
            $fixedMinor += (int) ($rule['amount_minor'] ?? 0);
        }
        if ($basisPoints > 10000) {
            throw new \DomainException(
                'Procentní alokace dohromady přesahují 100 % čisté mzdy.',
            );
        }
        if ($fixedMinor < 0) {
            throw new \DomainException(
                'Součet pevných alokací nesmí být záporný.',
            );
        }
    }

    /** @return list<string> */
    private function relationTypes(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT relation_type
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);

        return array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        ));
    }
}
