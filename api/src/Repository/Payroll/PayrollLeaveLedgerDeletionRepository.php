<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Smazání ručně zadaného zápisu v knize dovolené.
 *
 * ── Proč tvrdé smazání, ne zrušení stavem ─────────────────────────────────────
 * Kniha dovolené je saldo: zůstatek je SOUČET `minutes_delta`. Stav „zrušeno" by
 * musel umět odečíst každý dotaz na zůstatek, a přesně od toho tu už je typ
 * `reversal` — jenže ten je pro zápis, který VZNIKL SPRÁVNĚ a byl potom vrácen
 * (zrušené čerpání, nahrazený nárok). Pro překlep v ručním zápisu je reverze
 * zavádějící: ve výpisu by zůstaly dva řádky, které se ruší, a nikdo by nepoznal,
 * že se prostě někdo upsal. Ruční zápis, na kterém nic nestojí, se proto maže.
 *
 * ── Co smí blokovat ───────────────────────────────────────────────────────────
 * • Zápis, který nevznikl ručně. Nárok, čerpání a reverze jsou důsledky jiných
 *   úkonů (výpočet nároku, rozhodnutí o absenci) — ruší se tam, kde vznikly.
 * • Zápis, na který už ukazuje reverze nebo snapshot nároku (FK RESTRICT).
 * • Schválený výpočet: jakmile je za ten rok schválená revize mzdového běhu,
 *   vycházela ze zůstatku dovolené včetně tohoto zápisu.
 */
final class PayrollLeaveLedgerDeletionRepository extends PayrollRowDeletionRepository
{
    protected static function rowVersionColumn(): ?string
    {
        // Kniha dovolené je append-only saldo bez optimistického zámku; souběh
        // hlídá zámek řádku plus atomické přeověření v podmínce DELETE.
        return null;
    }

    protected static function blockers(): array
    {
        return [
            'not_manual' => [
                'code' => 'payroll_leave_entry_not_manual',
                'message' => 'Smazat lze jen ručně zadaný zápis dovolené. Nárok, čerpání '
                    . 'i reverze vznikají z jiných úkonů — zrušte je tam, kde vznikly '
                    . '(výpočet nároku, rozhodnutí o absenci).',
                'sql' => "entry.entry_type NOT IN
                              ('carryover', 'adjustment', 'shortening', 'overdrawn', 'payout')
                          OR entry.source_absence_id IS NOT NULL",
            ],
            'reversed' => [
                'code' => 'payroll_leave_entry_reversed',
                'message' => 'Zápis už vrátila reverze, takže je součástí historie salda. '
                    . 'Smazat ho nelze.',
                'sql' => 'EXISTS (
                    SELECT 1
                      FROM payroll_leave_ledger reversal
                     WHERE reversal.supplier_id = entry.supplier_id
                       AND reversal.reversal_of_id = entry.id
                )',
            ],
            'entitlement' => [
                'code' => 'payroll_leave_entry_in_entitlement',
                'message' => 'Na zápis se odkazuje snapshot nároku na dovolenou. '
                    . 'Nejdřív smažte ten nárok, teprve pak půjde zápis smazat.',
                'sql' => 'EXISTS (
                    SELECT 1
                      FROM payroll_leave_entitlement_snapshots snapshot
                     WHERE snapshot.supplier_id = entry.supplier_id
                       AND snapshot.leave_ledger_entry_id = entry.id
                )',
            ],
            'approved_run' => [
                'code' => 'payroll_leave_entry_in_approved_run',
                'message' => 'Za tento rok už je schválená revize mzdového běhu, která '
                    . 'ze zůstatku dovolené vycházela. Zápis smazat nelze — opravu udělejte '
                    . 'novým zápisem do knihy dovolené.',
                'sql' => self::approvedRunForLeaveYearSql('entry'),
            ],
        ];
    }

    protected static function cascade(): array
    {
        return [];
    }

    protected static function table(): string
    {
        return 'payroll_leave_ledger';
    }

    protected static function rowAlias(): string
    {
        return 'entry';
    }

    protected static function notFoundMessage(): string
    {
        return 'Zápis v knize dovolené nebyl nalezen.';
    }

    protected static function auditAction(): string
    {
        return 'payroll.leave_ledger.deleted';
    }

    protected static function auditEntity(): string
    {
        return 'payroll_leave_ledger';
    }

    protected static function lockedColumns(): array
    {
        return [
            'id',
            'employment_id',
            'leave_year',
            'effective_date',
            'entry_type',
            'minutes_delta',
            'reason',
        ];
    }

    protected static function auditPayload(array $row): array
    {
        return [
            'employment_id' => (int) ($row['employment_id'] ?? 0),
            'leave_year' => (int) ($row['leave_year'] ?? 0),
            'effective_date' => $row['effective_date'] ?? null,
            'entry_type' => (string) ($row['entry_type'] ?? ''),
            'minutes_delta' => (int) ($row['minutes_delta'] ?? 0),
            'reason' => (string) ($row['reason'] ?? ''),
        ];
    }
}
