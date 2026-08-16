<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

/**
 * Smazání účtu instituce, který vznikl omylem nebo duplicitně.
 *
 * ── Past: vazba bez cizího klíče ──────────────────────────────────────────────
 * Na `payroll_institution_accounts` nevede ANI JEDEN cizí klíč — z grafu
 * `information_schema` to vypadá jako list bez potomků. Jenže platební závazky
 * a položky platebního příkazu si příjemce nesou jako TEXT
 * (`recipient_reference` = `institution:<typ>:<kód>:account:<id>`) a seznam plateb
 * si podle něj účet dojoinuje. Kdyby se řádek smazal, databáze by nic nenamítla
 * a v přehledu plateb by u existujících závazků zmizelo, komu se platí. Blokujeme
 * proto výslovně — je to důkaz pohybu jako každý jiný, jen ho nedrží FK.
 *
 * ── Co NEblokuje ──────────────────────────────────────────────────────────────
 * Samotná existence účtu, jeho ověření ani historie platnosti. Účet, ze kterého
 * se nikdy neplatilo, je jen konfigurační řádek — když u něj nejsou pohyby, není
 * co chránit. Pro účet, ze kterého se platilo, zůstává ukončení platnosti
 * (`valid_to`), které dělá `PayrollInstitutionAccountRepository::update()`.
 */
final class PayrollInstitutionAccountDeletionRepository extends PayrollRowDeletionRepository
{
    protected static function blockers(): array
    {
        return [
            'liability' => [
                'code' => 'payroll_institution_account_in_liability',
                'message' => 'Na tento účet už míří mzdový platební závazek. Jde o peníze, '
                    . 'takže účet smazat nelze — místo toho mu nastavte konec platnosti '
                    . 'a založte nový.',
                'sql' => "EXISTS (
                    SELECT 1
                      FROM payroll_payment_liabilities liability
                     WHERE liability.supplier_id = account.supplier_id
                       AND liability.recipient_reference
                           LIKE CONCAT('institution:%:account:', account.id)
                )",
            ],
            'payment_item' => [
                'code' => 'payroll_institution_account_in_payment',
                'message' => 'Účet je použitý v platebním příkazu, který už byl vytvořen. '
                    . 'Jde o peníze, takže účet smazat nelze — místo toho mu nastavte '
                    . 'konec platnosti.',
                'sql' => "EXISTS (
                    SELECT 1
                      FROM payroll_payment_items item
                     WHERE item.supplier_id = account.supplier_id
                       AND item.recipient_reference
                           LIKE CONCAT('institution:%:account:', account.id)
                )",
            ],
        ];
    }

    protected static function cascade(): array
    {
        return [];
    }

    protected static function table(): string
    {
        return 'payroll_institution_accounts';
    }

    protected static function rowAlias(): string
    {
        return 'account';
    }

    protected static function notFoundMessage(): string
    {
        return 'Účet instituce nebyl nalezen.';
    }

    protected static function auditAction(): string
    {
        return 'payroll.institution_account.deleted';
    }

    protected static function auditEntity(): string
    {
        return 'payroll_institution_account';
    }

    protected static function lockedColumns(): array
    {
        return [
            'id',
            'institution_id',
            'institution_name',
            'bank_account_masked',
            'currency_code',
            'valid_from',
            'valid_to',
            'row_version',
        ];
    }

    protected static function auditPayload(array $row): array
    {
        return [
            'institution_id' => (int) ($row['institution_id'] ?? 0),
            'institution_name' => (string) ($row['institution_name'] ?? ''),
            // Maskovaný tvar, nikdy ne šifrované číslo účtu.
            'bank_account_masked' => (string) ($row['bank_account_masked'] ?? ''),
            'currency_code' => (string) ($row['currency_code'] ?? ''),
            'valid_from' => $row['valid_from'] ?? null,
            'valid_to' => $row['valid_to'] ?? null,
            'row_version' => (int) ($row['row_version'] ?? 0),
        ];
    }
}
