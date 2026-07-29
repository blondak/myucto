<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\Bank\BankAnalyticResolver;
use PHPUnit\Framework\Attributes\Group;

/**
 * #35 — bankovní noha na dedikované analytice vlastního účtu (BankAnalyticResolver).
 * Sdílí izolovanou DB transakci s BankPostingTestCase (rollback v tearDown).
 */
#[Group('integration')]
final class BankAnalyticResolverTest extends BankPostingTestCase
{
    private BankAnalyticResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = $this->container->get(BankAnalyticResolver::class);
    }

    /** Vloží vlastní bankovní účet se zadaným suffixem a vrátí jeho canonical/bank. */
    private function ownAccount(string $canonical, string $bank, ?string $suffix, string $currency = 'EUR'): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, analytic_suffix, source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, "current", ?, "manual", 1)'
        )->execute([
            $this->supplierId, 'Test ' . $currency, $canonical, $bank, $bank, $currency,
            $canonical, $suffix,
        ]);
    }

    /** @return array<string,mixed> */
    private function tx(string $account, string $bank): array
    {
        return ['recipient_account' => $account, 'recipient_bank' => $bank];
    }

    public function testRewritesFlatBankLegToOwnAnalytic(): void
    {
        $this->ownAccount('2000000055', '0300', '500');
        $lines = [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 1000.0,
             'currency_code' => 'EUR', 'fx_rate' => 25.0, 'amount_foreign' => 40.0],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 1000.0],
        ];

        $out = $this->resolver->apply($this->supplierId, $this->tx('2000000055', '0300'), $lines);

        self::assertSame('221500', $out[0]['account_code'], 'Bankovní noha na dedikované analytice 221500.');
        self::assertSame('EUR', $out[0]['currency_code'], 'FX stopa řádku se zachová.');
        self::assertSame('311', $out[1]['account_code'], 'Protiúčet (saldokonto) beze změny.');

        $created = $this->accounts->findByCode($this->supplierId, '221500');
        self::assertNotNull($created, 'Analytika 221500 se dohrála do osnovy.');
        self::assertFalse((bool) $created['is_synthetic']);
        $parent = $this->accounts->findByCode($this->supplierId, '221');
        self::assertSame((int) $parent['id'], (int) $created['parent_id'], 'Analytika visí pod syntetickým 221.');
        self::assertSame('asset', (string) $created['account_type']);
        self::assertSame('debit', (string) $created['normal_side']);
    }

    public function testLeavesSpecificAnalyticsAndCounterUntouched(): void
    {
        $this->ownAccount('2000000056', '0300', '500');
        // Termínovaný vklad (221100) i protiúčet 261 se NEsmí přepsat — přepisuje se jen holé '221'.
        $lines = [
            ['account_code' => '221100', 'side' => 'debit', 'amount' => 500.0],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 500.0],
        ];

        $out = $this->resolver->apply($this->supplierId, $this->tx('2000000056', '0300'), $lines);

        self::assertSame('221100', $out[0]['account_code'], 'Konkrétní analytika 221100 zůstává.');
        self::assertSame('221500', $out[1]['account_code'], 'Jen holé 221 se přesměruje.');
    }

    public function testNoSuffixIsNoOp(): void
    {
        $this->ownAccount('2000000057', '0300', null);
        $lines = [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 1000.0],
        ];

        $out = $this->resolver->apply($this->supplierId, $this->tx('2000000057', '0300'), $lines);

        self::assertSame('221', $out[0]['account_code'], 'Bez suffixu se noha nechává na plochém 221.');
        // Protiúčet i celý zápis beze změny — účet bez suffixu je no-op (nezávisle na tom,
        // zda 221500 v osnově existuje z jiného účtu s nastaveným suffixem).
        self::assertSame('311', $out[1]['account_code'], 'Protiúčet zůstává beze změny.');
    }

    public function testUnknownOwnAccountIsNoOp(): void
    {
        $lines = [['account_code' => '221', 'side' => 'debit', 'amount' => 10.0]];
        $out = $this->resolver->apply($this->supplierId, $this->tx('9999999999', '9999'), $lines);
        self::assertSame('221', $out[0]['account_code'], 'Bez shody vlastního účtu = no-op.');
    }
}
