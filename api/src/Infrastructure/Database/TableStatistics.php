<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;

/**
 * Přepočet statistik optimizeru po hromadném zápisu (převody z POHODY, PREMIERu, Money S3).
 *
 * Why: převod naplní tisíce dokladů, zápisů deníku a párování během pár minut a hned poté
 * nad nimi běží rekonciliace; další dotazy přijdou s prvním otevřením dashboardu. Statistiky
 * InnoDB se po takové dávce přepočítávají opožděně a na instalaci s jedinou firmou pak
 * optimizer v závislých poddotazech volí index jen na `supplier_id` (jediná hodnota = celá
 * tabulka na každý řádek). Ověřeno na produkci: rekonciliace 30 s na dotaz, dashboard 5 s;
 * po přepočtu statistik 12 ms a 7 ms.
 *
 * `ANALYZE TABLE` v MariaDB implicitně commituje, proto se uvnitř transakce (zkouška nanečisto,
 * testy) nepouští vůbec. Selhání převod nezastaví, jde jen o výkon.
 */
final class TableStatistics
{
    /** Tabulky, které převody plní a nad kterými běží rekonciliace i přehledy. */
    public const IMPORTED_ACCOUNTING_TABLES = [
        'chart_of_accounts',
        'journal_entries',
        'journal_entry_lines',
        'journal_entry_document_links',
        'clients',
        'invoices',
        'invoice_items',
        'purchase_invoices',
        'purchase_invoice_items',
        'cash_documents',
        'bank_statements',
        'bank_transactions',
        'payment_matches',
        'invoice_settlements',
        'offset_agreements',
        'offset_agreement_items',
    ];

    public function __construct(private readonly Connection $db) {}

    /** @param list<string> $extraTables Např. mapovací tabulka konkrétního převodu. */
    public function refreshAfterImport(array $extraTables = []): void
    {
        self::analyze($this->db->pdo(), [...self::IMPORTED_ACCOUNTING_TABLES, ...$extraTables]);
    }

    /** @param list<string> $tables */
    public static function analyze(PDO $pdo, array $tables): void
    {
        if ($tables === [] || $pdo->inTransaction()) {
            return;
        }
        $quoted = array_map(static fn (string $t): string => '`' . str_replace('`', '', $t) . '`', array_values(array_unique($tables)));
        try {
            $pdo->query('ANALYZE TABLE ' . implode(', ', $quoted))->fetchAll();
        } catch (\Throwable $e) {
            error_log('ANALYZE TABLE po hromadném zápisu selhal: ' . $e->getMessage());
        }
    }
}
