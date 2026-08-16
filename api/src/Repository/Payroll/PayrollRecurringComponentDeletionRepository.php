<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Smazání čerstvého předpisu pravidelné mzdové složky.
 *
 * ── Co smí blokovat ───────────────────────────────────────────────────────────
 * Peníze, a to v jediné podobě: materializace. Předpis sám o sobě je jen stálá
 * instrukce „každý měsíc přidej tuhle částku" — dokud ji nikdo nepřevedl na mzdový
 * vstup, nestalo se nic a není co chránit. Jakmile z něj vznikne řádek
 * v `payroll_inputs` (`recurring_component_id`), je to peněžní zápis a předpis
 * zůstává v evidenci; pro ten případ dál platí deaktivace (`is_active`) a ukončení
 * platnosti (`valid_to`), které umí `update()`.
 *
 * ── Sdílení se starší agendou ─────────────────────────────────────────────────
 * Předpis nepoužívá nic mimo nový mzdový modul: v celé databázi na něj vede jediný
 * cizí klíč, a to právě z `payroll_inputs`. Mazání pracovního vztahu ho už dnes
 * uklízí jako lešení (`PayrollEmploymentDeletionRepository::RESTRICT_SCAFFOLD`),
 * takže obě cesty říkají totéž.
 */
final class PayrollRecurringComponentDeletionRepository extends PayrollRowDeletionRepository
{
    protected static function blockers(): array
    {
        return [
            'materialized' => [
                'code' => 'payroll_recurring_materialized',
                'message' => 'Z předpisu už vznikl mzdový vstup za některý měsíc. Jde '
                    . 'o peníze, takže předpis smazat nelze — místo toho mu nastavte konec '
                    . 'platnosti nebo ho deaktivujte.',
                'sql' => 'EXISTS (
                    SELECT 1
                      FROM payroll_inputs input
                     WHERE input.supplier_id = recurring.supplier_id
                       AND input.recurring_component_id = recurring.id
                )',
            ],
        ];
    }

    protected static function cascade(): array
    {
        return [];
    }

    protected static function table(): string
    {
        return 'payroll_recurring_components';
    }

    protected static function rowAlias(): string
    {
        return 'recurring';
    }

    protected static function notFoundMessage(): string
    {
        return 'Předpis pravidelné složky nebyl nalezen.';
    }

    protected static function auditAction(): string
    {
        return 'payroll.recurring_component.deleted';
    }

    protected static function auditEntity(): string
    {
        return 'payroll_recurring_component';
    }

    protected static function lockedColumns(): array
    {
        return [
            'id',
            'employment_id',
            'component_id',
            'calculation_kind',
            'amount_minor',
            'valid_from',
            'valid_to',
            'is_active',
            'row_version',
        ];
    }

    protected static function auditPayload(array $row): array
    {
        return [
            'employment_id' => (int) ($row['employment_id'] ?? 0),
            'component_id' => (int) ($row['component_id'] ?? 0),
            'calculation_kind' => (string) ($row['calculation_kind'] ?? ''),
            'amount_minor' => $row['amount_minor'] === null ? null : (int) $row['amount_minor'],
            'valid_from' => $row['valid_from'] ?? null,
            'valid_to' => $row['valid_to'] ?? null,
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'row_version' => (int) ($row['row_version'] ?? 0),
        ];
    }
}
