<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClosingRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * K3 („zaplacený doklad s otevřeným saldem") nesmí tvrdit, že úhrada chybí, když ji
 * deník má.
 *
 * Proč to existuje: MariaDB rozkládala CTE úhrad na LATERAL DERIVED a u části firem
 * pak vracela `settled = 0` i dokladům s řádně zaúčtovanou bankovní úhradou. Kontrola
 * hlásila stovky faktur jako „zaplaceno bez úhrady", přestože 321 bylo vyrovnané.
 * Závisí to na plánu, tedy na objemu a statistikách dat, takže syntetický scénář
 * s pár doklady chybu nevyvolá. Test proto bere databázi tak, jak je, a každý nález
 * `marked_paid_unposted` ověří nezávislým bodovým dotazem do deníku.
 *
 * Read-only, nic nezakládá. Nad prázdnou databází skipuje, aby nepředstíral kontrolu.
 */
#[Group('integration')]
final class ClosingPaidSaldoSettlementTest extends TestCase
{
    private ClosingRepository $closing;
    private Connection $db;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->closing = $container->get(ClosingRepository::class);
            $this->db = $container->get(Connection::class);
            $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testPurchaseFindingsWithoutSettlementHaveNoBankSettlementOn321(): void
    {
        $this->assertFindingsHaveNoBankSettlement(
            'purchase_invoice',
            fn (int $supplierId, string $asOf): array => $this->closing->paidPurchasesOpenSaldo($supplierId, $asOf),
        );
    }

    public function testInvoiceFindingsWithoutSettlementHaveNoBankSettlementOn311(): void
    {
        $this->assertFindingsHaveNoBankSettlement(
            'invoice',
            fn (int $supplierId, string $asOf): array => $this->closing->paidInvoicesOpenSaldo($supplierId, $asOf),
        );
    }

    /**
     * @param 'invoice'|'purchase_invoice' $docType
     * @param callable(int,string):list<array<string,mixed>> $findings
     */
    private function assertFindingsHaveNoBankSettlement(string $docType, callable $findings): void
    {
        $asOf = date('Y-m-d');
        $suppliers = $this->db->pdo()
            ->query("SELECT DISTINCT supplier_id FROM journal_entries WHERE source_type = 'bank' ORDER BY supplier_id")
            ->fetchAll(\PDO::FETCH_COLUMN);
        if ($suppliers === []) {
            self::markTestSkipped('V deníku nejsou bankovní zápisy — nález by nebylo s čím porovnat.');
        }

        $checked = 0;
        $contradictions = [];
        foreach ($suppliers as $supplierId) {
            $supplierId = (int) $supplierId;
            $ids = [];
            foreach ($findings($supplierId, $asOf) as $row) {
                if (in_array('marked_paid_unposted', $row['issues'] ?? [], true)) {
                    $ids[] = (int) $row['id'];
                }
            }
            if ($ids === []) {
                continue;
            }
            $checked += count($ids);
            foreach ($this->bankSettledGroups($docType, $supplierId, $asOf, $ids) as $id => $settled) {
                $contradictions[] = sprintf('firma %d, doklad %d: K3 hlásí bez úhrady, deník má %.2f', $supplierId, $id, $settled);
            }
        }

        if ($checked === 0) {
            self::markTestSkipped('K3 nehlásí žádný doklad bez úhrady — není co ověřit.');
        }
        self::assertCount(0, $contradictions, sprintf(
            "K3 hlásí jako „zaplaceno bez úhrady\" %d dokladů, jejichž úhrada v deníku je (prvních 20):\n  %s",
            count($contradictions),
            implode("\n  ", array_slice($contradictions, 0, 20)),
        ));
    }

    /**
     * Nezávislý bodový výpočet bankovního vyrovnání skupiny doklad + dobropisy na
     * saldokontu (311 kredit / 321 debet), stejná pravidla platnosti zápisu jako K3.
     *
     * @param list<int> $ids
     * @return array<int,float> skupina → nenulové vyrovnání
     */
    private function bankSettledGroups(string $docType, int $supplierId, string $asOf, array $ids): array
    {
        if ($docType === 'invoice') {
            $group = "CASE WHEN d.invoice_type = 'credit_note' AND d.parent_invoice_id IS NOT NULL
                           THEN d.parent_invoice_id ELSE d.id END";
            $from = 'invoice_payments m JOIN invoices d ON d.id = m.invoice_id AND d.supplier_id = m.supplier_id';
            $alloc = 'SELECT SUM(a.amount) FROM invoice_payments a
                       WHERE a.supplier_id = m.supplier_id AND a.bank_transaction_id = m.bank_transaction_id';
            $side = 'credit';
            $account = '311';
        } else {
            $group = "CASE WHEN d.document_kind = 'credit_note' AND d.parent_purchase_invoice_id IS NOT NULL
                           THEN d.parent_purchase_invoice_id ELSE d.id END";
            $from = 'payment_matches m JOIN purchase_invoices d ON d.id = m.purchase_invoice_id AND d.supplier_id = m.supplier_id';
            $alloc = 'SELECT SUM(a.amount) FROM payment_matches a
                       WHERE a.supplier_id = m.supplier_id AND a.bank_transaction_id = m.bank_transaction_id
                         AND a.purchase_invoice_id IS NOT NULL';
            $side = 'debit';
            $account = '321';
        }
        $in = implode(',', array_map('intval', $ids));

        $stmt = $this->db->pdo()->prepare(
            "SELECT grp, SUM(net * share) AS settled
               FROM (
                   SELECT {$group} AS grp,
                          (SELECT SUM(CASE WHEN l.side = '{$side}' THEN l.amount ELSE -l.amount END)
                             FROM journal_entries e
                             JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                             JOIN chart_of_accounts ca ON ca.id = l.account_id
                             LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                             LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                            WHERE e.supplier_id = m.supplier_id AND e.source_type = 'bank'
                              AND e.source_id = m.bank_transaction_id
                              AND e.posted_at IS NOT NULL AND e.entry_date <= ?
                              AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                              AND (ca.account_code LIKE '{$account}%' OR COALESCE(pa.account_code, '') LIKE '{$account}%')
                          ) AS net,
                          m.amount / NULLIF(({$alloc}), 0) AS share
                     FROM {$from}
                    WHERE m.supplier_id = ? AND m.bank_transaction_id IS NOT NULL
               ) x
              WHERE grp IN ({$in})
              GROUP BY grp
             HAVING ABS(SUM(net * share)) > 0.005"
        );
        $stmt->execute([$asOf, $asOf, $supplierId]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['grp']] = (float) $r['settled'];
        }
        return $out;
    }
}
