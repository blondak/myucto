<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use PHPUnit\Framework\Attributes\Group;

/**
 * R4 (POVINNÉ): nové výpisy mají trvalého vlastníka v bank_statements.supplier_id;
 * starší výpisy bez vlastníka se scopují jen jednoznačnou kanonickou shodou účtu a banky.
 * Cizí bankovní výpis/transakce se NIKDY nesmí objevit v deníku jiného supplieru.
 */
#[Group('integration')]
final class CashJournalTenantLeakTest extends CashJournalTestCase
{
    public function testForeignBankStatementNeverLeaksIntoOwnJournal(): void
    {
        // Supplier B (jiný tenant) s vlastním účtem a bankovním pohybem.
        $supplierB = $this->cloneSupplier('tax_evidence', true);
        $accountB  = '880000222';
        // currencyRow používá $this->currencyId pro clients default; pro B jen bankovní účet.
        $this->db->pdo()->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, "CZK", "CZK", "CZK", "CZK", "CZK", 2, 1, 1, ?, "0300")'
        )->execute([$supplierB, $accountB]);
        $stB = $this->statement($supplierB, $accountB, '0300');
        $this->bankTx($stB, 99000.0, ['description' => 'Cizí příjem tenanta B']);

        // Supplier A má svůj vlastní bankovní pohyb (account A).
        $stA = $this->statement($this->supplierId, $this->accountA);
        $txA = $this->bankTx($stA, 5000.0, ['description' => 'Vlastní příjem A']);
        $this->classifyOverride($this->supplierId, 'bank', $txA, 'income_taxable');

        $res = $this->fullYear($this->supplierId);

        // Deník A vidí PRÁVĚ svůj jeden bankovní pohyb, nikoli pohyb B.
        self::assertSame(1, $this->countRows($res, 'bank'), 'Deník A má vidět jen vlastní bankovní pohyb.');
        foreach ($res['rows'] as $row) {
            self::assertNotSame('Cizí příjem tenanta B', $row['description'] ?? '', 'Cizí bankovní pohyb NESMÍ prosáknout (R4).');
            self::assertNotEqualsWithDelta(99000.0, (float) ($row['income'] ?? 0.0), 0.01);
        }
        self::assertEqualsWithDelta(5000.0, $res['totals']['prijem_danovy'], 0.01);
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.01);
    }

    public function testSupplierWithoutBankAccountSeesNoBankRows(): void
    {
        // A nemá žádný account_number → matchingStatementIds prázdné → noha B odpadá.
        $this->db->pdo()->prepare('UPDATE currencies SET account_number = NULL, iban = NULL WHERE supplier_id = ?')
            ->execute([$this->supplierId]);

        // I kdyby existoval výpis se stejným účtem, bez currencies účtu se nespáruje.
        $st = $this->statement($this->supplierId, $this->accountA);
        $this->bankTx($st, 12000.0);

        $res = $this->fullYear($this->supplierId);
        self::assertSame(0, $this->countRows($res, 'bank'), 'Bez currencies účtu se žádný bankovní pohyb nescopuje (R4).');
    }

    public function testLegacyStatementRequiresBankCodeAndUnambiguousOwner(): void
    {
        $supplierB = $this->cloneSupplier('tax_evidence', true);
        $this->db->pdo()->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, "CZK", "CZK", "CZK", "CZK", "CZK", 2, 1, 1, ?, "0300")'
        )->execute([$supplierB, $this->accountA]);

        $statement = $this->statement($this->supplierId, $this->accountA, '2010');
        $transaction = $this->bankTx($statement, 5000.0);
        $this->classifyOverride($this->supplierId, 'bank', $transaction, 'income_taxable');
        self::assertSame(1, $this->countRows($this->fullYear($this->supplierId), 'bank'));

        $this->db->pdo()->prepare('UPDATE bank_statements SET bank_code = NULL WHERE id = ?')->execute([$statement]);
        self::assertSame(
            0,
            $this->countRows($this->fullYear($this->supplierId), 'bank'),
            'Výpis bez kódu banky se stejným číslem u dvou tenantů je víceznačný a nesmí se přiřadit.',
        );
    }
}
