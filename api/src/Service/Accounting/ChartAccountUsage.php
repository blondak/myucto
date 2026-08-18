<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Kde všude visí kód účtu z osnovy — podklad pro smazání analytiky.
 *
 * Kód účtu je napříč aplikací TEXTOVÝ klíč (ne FK): kontace, bankovní pravidla,
 * pokladny, karty majetku, mzdové nastavení i pravidla klasifikace nákladů drží
 * `account_code` jako řetězec. Tenhle seznam je proto jediná pojistka proti tomu,
 * aby po smazání účtu zůstal někde odkaz na neexistující kód — a musí odpovídat
 * migraci 1322, která tytéž sloupce přepisovala na tečkovaný tvar.
 *
 * Kontroluje se jak vazba přes id (`journal_entry_lines.account_id`), tak přes
 * text (`account_code`) — mazat se smí jen účet, na kterém nevisí ani jedno.
 */
final class ChartAccountUsage
{
    /**
     * Sloupce s kódem účtu jako textem: tabulka => [sloupce].
     * Zdroj pravdy je migrace 1322 (tytéž sloupce), plus počáteční stavy aktivace.
     */
    private const CODE_COLUMNS = [
        'posting_rules'                => ['debit_account_code', 'credit_account_code'],
        'bank_posting_rules'           => ['debit_account_code', 'credit_account_code'],
        'cash_registers'               => ['account_code'],
        'assets'                       => ['acquisition_account_code', 'accumulated_account_code', 'asset_account_code'],
        'expense_classification_rules' => ['target_account_code'],
        'accounting_opening_balances'  => ['account_code'],
        'payroll_employer_settings'    => [
            'employment_gross_debit_account', 'employment_gross_credit_account',
            'statutory_gross_debit_account', 'statutory_gross_credit_account',
            'partner_gross_debit_account', 'partner_gross_credit_account',
            'social_insurance_credit_account', 'health_insurance_credit_account',
            'income_tax_credit_account', 'other_deductions_credit_account',
            'employer_insurance_debit_account', 'partner_settlement_credit_account',
        ],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * Vrátí seznam míst, která účet drží. Prázdné pole = účet jde smazat.
     *
     * @return list<string> lidsky čitelné názvy míst (bez duplicit)
     */
    public function usages(int $supplierId, int $accountId, string $accountCode): array
    {
        $pdo = $this->db->pdo();
        $found = [];

        $stmt = $pdo->prepare(
            'SELECT EXISTS (SELECT 1 FROM journal_entry_lines l
                              JOIN journal_entries e ON e.id = l.entry_id
                             WHERE e.supplier_id = ? AND l.account_id = ?)'
        );
        $stmt->execute([$supplierId, $accountId]);
        if ((bool) $stmt->fetchColumn()) {
            $found[] = 'účetní deník';
        }

        // Dítě v osnově drží účet přes parent_id — smazáním by osiřelo.
        $stmt = $pdo->prepare('SELECT EXISTS (SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND parent_id = ?)');
        $stmt->execute([$supplierId, $accountId]);
        if ((bool) $stmt->fetchColumn()) {
            $found[] = 'podřízené analytiky';
        }

        foreach (self::CODE_COLUMNS as $table => $columns) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $conditions = implode(' OR ', array_map(static fn (string $c): string => $c . ' = ?', $columns));
            $stmt = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM {$table} WHERE supplier_id = ? AND ({$conditions}))");
            $stmt->execute(array_merge([$supplierId], array_fill(0, count($columns), $accountCode)));
            if ((bool) $stmt->fetchColumn()) {
                $found[] = self::label($table);
            }
        }

        return array_values(array_unique($found));
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = ?)'
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }

    private static function label(string $table): string
    {
        return match ($table) {
            'posting_rules'                => 'kontace',
            'bank_posting_rules'           => 'kontace bankovních pohybů',
            'cash_registers'               => 'pokladna',
            'assets'                       => 'karty majetku',
            'expense_classification_rules' => 'pravidla klasifikace nákladů',
            'accounting_opening_balances'  => 'počáteční stavy',
            'payroll_employer_settings'    => 'mzdové nastavení',
            default                        => $table,
        };
    }
}
