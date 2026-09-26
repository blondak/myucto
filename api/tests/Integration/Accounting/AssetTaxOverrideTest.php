<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ruční přepis daňového odpisu roku na kartě (R8, „převzít čísla účetní"): částka se
 * promítne do přiznání (rozdíl odpisů i tabulka odpisů podle skupin), hromadné potvrzení
 * odpisů ji nepřepíše a přepis jde vrátit.
 */
#[Group('integration')]
final class AssetTaxOverrideTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private AssetService $service;
    private DepreciationPostingService $depPosting;
    private DepreciationEntryRepository $entries;
    private DppoReturnDataProvider $dppo;
    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(AssetService::class);
            $this->depPosting = $container->get(DepreciationPostingService::class);
            $this->entries = $container->get(DepreciationEntryRepository::class);
            $this->dppo = $container->get(DppoReturnDataProvider::class);
            $periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('depreciation_entries', 'override_reason')) {
            $this->markTestSkipped('Chybí migrace depreciation_entries.override_*.');
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testOverriddenTaxDepreciationFlowsToReturnAndSurvivesRebooking(): void
    {
        $assetId = $this->assetInUse();
        $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        $this->depPosting->bookYear($this->supplierId, self::YEAR + 1, ['posted_by' => $this->userId]);
        $computed = $this->entries->findYear($assetId, 'tax', self::YEAR);
        $next = $this->entries->findYear($assetId, 'tax', self::YEAR + 1);
        self::assertNotNull($computed);
        self::assertNotNull($next);
        $before = $this->dppo->gather($this->supplierId, self::YEAR);

        $result = $this->service->overrideTaxYear($this->supplierId, $assetId, self::YEAR, 7000.00,
            'Převzato z přiznání účetní (jiná vstupní cena)', ['user_id' => $this->userId]);

        $delta = 7000.00 - (float) $computed['full_amount'];
        self::assertSame((float) $computed['amount'], $result['previous_amount']);
        $row = $this->entries->findYear($assetId, 'tax', self::YEAR);
        self::assertSame([7000.0, 7000.0, round((float) $computed['residual_value_end'] - $delta, 2)],
            [$row['amount'], $row['full_amount'], $row['residual_value_end']]);
        self::assertSame(['Převzato z přiznání účetní (jiná vstupní cena)', (float) $computed['amount'], $this->userId],
            [$row['override_reason'], $row['override_original_amount'], $row['override_by']]);
        self::assertSame(round((float) $next['residual_value_end'] - $delta, 2),
            $this->entries->findYear($assetId, 'tax', self::YEAR + 1)['residual_value_end'], 'Pozdější rok posune zůstatkovou cenu.');

        $after = $this->dppo->gather($this->supplierId, self::YEAR);
        self::assertSame(round($before['depreciation']['tax'] + $delta, 2), $after['depreciation']['tax'], 'Rozdíl odpisů v přiznání.');
        self::assertSame(round(($before['depreciation_by_group']['tangible'][2] ?? 0.0) + $delta, 2),
            $after['depreciation_by_group']['tangible'][2], 'Tabulka odpisů podle skupin.');

        // Hromadné potvrzení odpisů roku přepsaný odpis nemění.
        $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        self::assertSame(7000.0, $this->entries->findYear($assetId, 'tax', self::YEAR)['amount']);

        $plan = $this->service->plan($this->supplierId, $assetId);
        $planRow = array_column($plan['tax'], null, 'fiscal_year')[self::YEAR];
        self::assertSame([7000.0, 'Převzato z přiznání účetní (jiná vstupní cena)'], [(float) $planRow['amount'], $planRow['override_reason'] ?? null]);

        $this->service->clearTaxOverride($this->supplierId, $assetId, self::YEAR);
        $restored = $this->entries->findYear($assetId, 'tax', self::YEAR);
        self::assertSame([(float) $computed['amount'], (float) $computed['residual_value_end'], null],
            [$restored['amount'], $restored['residual_value_end'], $restored['override_reason']]);
        self::assertSame((float) $next['residual_value_end'], $this->entries->findYear($assetId, 'tax', self::YEAR + 1)['residual_value_end']);
    }

    public function testOverrideRequiresReasonAndConfirmedYearAndCapsAtResidual(): void
    {
        $assetId = $this->assetInUse();
        $this->assertRejected(fn () => $this->service->overrideTaxYear($this->supplierId, $assetId, self::YEAR, 1000.0, '  '), 'validation_failed');
        $this->assertRejected(fn () => $this->service->overrideTaxYear($this->supplierId, $assetId, self::YEAR, 1000.0, 'důvod'), 'not_found');

        $this->depPosting->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        $this->assertRejected(fn () => $this->service->overrideTaxYear($this->supplierId, $assetId, self::YEAR, 100000.01, 'důvod'), 'validation_failed');
        $this->assertRejected(fn () => $this->service->overrideTaxYear($this->supplierId + 99999, $assetId, self::YEAR, 1000.0, 'důvod'), 'not_found');
    }

    private function assertRejected(callable $call, string $code): void
    {
        try {
            $call();
            self::fail('Čekala se chyba ' . $code . '.');
        } catch (AssetException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    private function assetInUse(): int
    {
        $created = $this->service->create($this->supplierId, [
            'inventory_number' => 'M-OVR-001',
            'name' => 'Stroj s daňovým odpisem podle účetní',
            'input_price' => 100000.00,
            'acquisition_date' => self::YEAR . '-01-10',
            'put_into_use_date' => self::YEAR . '-01-10',
            'status' => 'in_use',
            'tax_method' => 'straight',
            'tax_group' => 2,
            'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId]);
        return (int) $created['asset']['id'];
    }
}
