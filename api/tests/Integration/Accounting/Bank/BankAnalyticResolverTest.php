<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;
use MyInvoice\Service\Accounting\Bank\BankAnalyticResolver;
use PHPUnit\Framework\Attributes\Group;

/**
 * Bankovní noha na dedikované analytice vlastního účtu (BankAnalyticResolver +
 * BankAnalyticAssigner). Každý bankovní účet firmy má vlastní 221xxx — chybějící
 * analytika se přidělí automaticky, ručně přiřazená se nikdy nepřepíše.
 * Sdílí izolovanou DB transakci s BankPostingTestCase (rollback v tearDown).
 */
#[Group('integration')]
final class BankAnalyticResolverTest extends BankPostingTestCase
{
    private BankAnalyticResolver $resolver;
    private BankAnalyticAssigner $assigner;
    private SupplierBankAccountRepository $bankAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver     = $this->container->get(BankAnalyticResolver::class);
        $this->assigner     = $this->container->get(BankAnalyticAssigner::class);
        $this->bankAccounts = $this->container->get(SupplierBankAccountRepository::class);
    }

    /** Vloží vlastní bankovní účet se zadaným suffixem a vrátí jeho id. */
    private function ownAccount(string $canonical, string $bank, ?string $suffix, string $currency = 'EUR'): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, analytic_suffix, source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, "current", ?, "manual", 1)'
        )->execute([
            $this->supplierId, 'Test ' . $currency, $canonical, $bank, $bank, $currency,
            $canonical, $suffix,
        ]);
        return (int) $pdo->lastInsertId();
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

    /**
     * Účet bez analytiky ji dostane při prvním zaúčtování — bankovní noha NIKDY
     * nesmí skončit na ploché syntetice 221 (zůstatek by přestal sedět na výpis).
     */
    public function testMissingAnalyticIsAssignedOnFirstPosting(): void
    {
        $id = $this->ownAccount('2000000057', '0300', null, 'CZK');
        $lines = [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 1000.0],
            ['account_code' => '311', 'side' => 'credit', 'amount' => 1000.0],
        ];

        $out = $this->resolver->apply($this->supplierId, $this->tx('2000000057', '0300'), $lines);

        $code = (string) $out[0]['account_code'];
        self::assertNotSame('221', $code, 'Bankovní noha se nesmí nechat na plochém 221.');
        self::assertMatchesRegularExpression('/^221[0-9]{1,6}$/', $code);
        self::assertSame('311', $out[1]['account_code'], 'Protiúčet zůstává beze změny.');

        $stored = $this->bankAccounts->find($this->supplierId, $id);
        self::assertSame($code, '221' . (string) $stored['analytic_suffix'], 'Suffix se uložil k účtu.');
        self::assertNotNull($this->accounts->findByCode($this->supplierId, $code), 'Analytika je v osnově.');
    }

    /** Opakované volání nesmí účtu přidělit druhé číslo (cache i DB drží jedno). */
    public function testAssignmentIsStableAcrossCalls(): void
    {
        $id = $this->ownAccount('2000000058', '0300', null, 'CZK');
        $lines = [['account_code' => '221', 'side' => 'debit', 'amount' => 10.0]];

        $first  = $this->resolver->apply($this->supplierId, $this->tx('2000000058', '0300'), $lines);
        $second = $this->resolver->apply($this->supplierId, $this->tx('2000000058', '0300'), $lines);

        self::assertSame($first[0]['account_code'], $second[0]['account_code']);
        $stored = $this->bankAccounts->find($this->supplierId, $id);
        self::assertSame($first[0]['account_code'], '221' . (string) $stored['analytic_suffix']);
    }

    /** Dva účty firmy nesmí dostat totéž číslo — jinak by se jejich zůstatky promíchaly. */
    public function testTwoAccountsGetDistinctAnalytics(): void
    {
        $this->ownAccount('2000000059', '0300', null, 'CZK');
        $this->ownAccount('2000000060', '0300', null, 'CZK');
        $lines = [['account_code' => '221', 'side' => 'debit', 'amount' => 10.0]];

        $a = $this->resolver->apply($this->supplierId, $this->tx('2000000059', '0300'), $lines)[0]['account_code'];
        $b = $this->resolver->apply($this->supplierId, $this->tx('2000000060', '0300'), $lines)[0]['account_code'];

        self::assertNotSame($a, $b, 'Každý bankovní účet má vlastní analytiku.');
    }

    /** Ručně přiřazená analytika je autoritativní — automat ji nikdy nepřepíše. */
    public function testManualSuffixIsNeverOverwritten(): void
    {
        $id = $this->ownAccount('2000000061', '0300', '742', 'CZK');

        $out = $this->resolver->apply(
            $this->supplierId,
            $this->tx('2000000061', '0300'),
            [['account_code' => '221', 'side' => 'debit', 'amount' => 10.0]],
        );

        self::assertSame('221742', $out[0]['account_code']);
        $stored = $this->bankAccounts->find($this->supplierId, $id);
        self::assertSame('742', (string) $stored['analytic_suffix']);
    }

    /**
     * Analytika, na které UŽ NĚCO LEŽÍ, se automaticky nepřidělí — bankovní účet by
     * zdědil cizí zůstatek (typicky ručně vedený termínovaný vklad na 221100).
     */
    public function testAssignerSkipsAnalyticWithLedgerHistory(): void
    {
        $free = $this->assigner->nextFreeSuffix($this->supplierId);
        self::assertNotNull($free);
        $code = '221' . $free;

        // Analytika s jedním řádkem v deníku → další volání ji musí přeskočit.
        $accountId = $this->assigner->ensureChartAccount($this->supplierId, $free, 'Obsazená analytika');
        self::assertSame($code, $accountId);
        $row = $this->accounts->findByCode($this->supplierId, $code);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, posted_at)
             VALUES (?, ?, ?, 'manual', NOW())"
        )->execute([$this->supplierId, $this->periodId, self::YEAR . '-06-15']);
        $entryId = (int) $pdo->lastInsertId();
        $inserted = $pdo->prepare(
            "INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, side, amount)
             VALUES (?, ?, ?, 'debit', 1.00)"
        );
        $inserted->execute([$this->supplierId, $entryId, (int) $row['id']]);
        self::assertSame(1, $inserted->rowCount(), 'Ledgerová historie analytiky se opravdu založila.');

        $next = $this->assigner->nextFreeSuffix($this->supplierId);
        self::assertNotNull($next);
        self::assertNotSame($free, $next, 'Analytika s historií se automaticky nepřiděluje.');
    }

    public function testUnknownOwnAccountIsNoOp(): void
    {
        $lines = [['account_code' => '221', 'side' => 'debit', 'amount' => 10.0]];
        $out = $this->resolver->apply($this->supplierId, $this->tx('9999999999', '9999'), $lines);
        self::assertSame('221', $out[0]['account_code'], 'Bez shody vlastního účtu = no-op.');
    }

    /** Pořadí kandidátů je smluvní — SQL backfill v migraci 1318 generuje totéž. */
    public function testCandidateOrderStartsWithHundreds(): void
    {
        $candidates = BankAnalyticAssigner::candidateSuffixes();
        self::assertSame(['100', '200', '300', '400', '500', '600', '700', '800', '900'],
            array_slice($candidates, 0, 9));
        self::assertSame('010', $candidates[9], 'Po násobcích sta následují násobky deseti.');
        self::assertSame(count($candidates), count(array_unique($candidates)), 'Bez duplicit.');
    }
}
