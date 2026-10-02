<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Setup\AccountingHistoryRuleLearner;
use MyInvoice\Service\Accounting\Setup\AccountingSetupAnalysisService;
use MyInvoice\Service\Accounting\Setup\AccountingSetupApprovalService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AccountingHistoryRuleLearnerTest extends TestCase
{
    private int $nextId = 1;

    public function testConsistentVendorBecomesVendorRule(): void
    {
        $result = AccountingHistoryRuleLearner::learn([
            ...$this->documents(7, 10, ['518.700' => 1000.0], 'Hosting serveru'),
            $this->document(7, ['501.100' => 200.0], 'Kabel'),
        ]);

        self::assertCount(1, $result['rules']);
        $rule = $result['rules'][0];
        self::assertSame(7, $rule['vendor_id']);
        self::assertSame('518.700', $rule['account']);
        self::assertNull($rule['keyword']);
        self::assertSame(11, $rule['documents']);
        self::assertSame(10, $rule['agreeing']);
        self::assertSame(['501.100' => 1], $rule['other_accounts']);
        self::assertSame([], $result['ambiguous']);
    }

    public function testMixedVendorWithoutDiscriminatingWordIsAmbiguousNotGuessed(): void
    {
        $result = AccountingHistoryRuleLearner::learn([
            ...$this->documents(3, 4, ['518.100' => 500.0], 'Dodávka dle smlouvy'),
            ...$this->documents(3, 4, ['501.100' => 500.0], 'Dodávka dle smlouvy'),
        ]);

        self::assertSame([], $result['rules']);
        self::assertCount(1, $result['ambiguous']);
        self::assertSame(['501.100' => 4, '518.100' => 4], $result['ambiguous'][0]['accounts']);
    }

    public function testMixedVendorIsSplitByKeyword(): void
    {
        $result = AccountingHistoryRuleLearner::learn([
            ...$this->documents(5, 4, ['501.100' => 1500.0], 'Nafta'),
            ...$this->documents(5, 2, ['511.100' => 3000.0], 'Servis vozidla'),
            $this->document(5, ['511.100' => 3000.0], 'Servis brzd'),
        ]);

        $byAccount = array_column($result['rules'], null, 'account');
        self::assertSame('nafta', $byAccount['501.100']['keyword']);
        // „servis" je ve všech třech, „vozidla" jen ve dvou: vyhrává pokrytí, ne délka.
        self::assertSame('servis', $byAccount['511.100']['keyword']);
        self::assertSame(1.0, $byAccount['511.100']['share']);
    }

    public function testBelowMinimumDocumentsNoRule(): void
    {
        $result = AccountingHistoryRuleLearner::learn($this->documents(4, 2, ['518.100' => 100.0], 'Licence'));

        self::assertSame([], $result['rules']);
        self::assertSame([], $result['ambiguous']);
    }

    public function testMixedDocumentsCountAgainstTheRule(): void
    {
        $result = AccountingHistoryRuleLearner::learn([
            ...$this->documents(6, 8, ['501.100' => 1000.0], 'Zboží'),
            ...$this->documents(6, 2, ['501.100' => 600.0, '518.100' => 400.0], 'Zboží a doprava'),
        ]);

        self::assertSame([], $result['rules']);
        self::assertCount(1, $result['ambiguous']);
    }

    public function testRoundingLineDoesNotMakeDocumentMixed(): void
    {
        self::assertSame('518.100', AccountingHistoryRuleLearner::dominantAccount(['518.100' => 9990.0, '548.100' => 0.4]));
        self::assertNull(AccountingHistoryRuleLearner::dominantAccount(['518.100' => 600.0, '501.100' => 400.0]));
    }

    public function testRecentHistoryWinsAfterChartChange(): void
    {
        $old = [];
        foreach (range(2015, 2019) as $year) {
            $old[] = $this->document(8, ['518.000' => 100.0], 'Účetní služby', $year . '-03-01');
        }
        $new = [];
        foreach (['2024-06-01', '2024-12-01', '2025-03-01', '2025-06-01'] as $date) {
            $new[] = $this->document(8, ['518.300' => 100.0], 'Účetní služby', $date);
        }

        $result = AccountingHistoryRuleLearner::learn([...$old, ...$new]);

        self::assertCount(1, $result['rules']);
        self::assertSame('518.300', $result['rules'][0]['account']);
        self::assertSame('recent', $result['rules'][0]['window']);
    }

    public function testHistoryKindFollowsAccountAndRefusesAssetsAndSynthetics(): void
    {
        $chart = ['by_code' => [
            '501' => ['account_code' => '501', 'is_active' => true, 'is_synthetic' => true],
            '501.100' => ['account_code' => '501.100', 'name' => 'Spotřeba materiálu', 'is_active' => true, 'is_synthetic' => false],
            '501.200' => ['account_code' => '501.200', 'name' => 'Drobný hmotný majetek', 'is_active' => true, 'is_synthetic' => false],
            '518.200' => ['account_code' => '518.200', 'name' => 'Drobný nehmotný majetek', 'is_active' => true, 'is_synthetic' => false],
            '518.100' => ['account_code' => '518.100', 'name' => 'Ostatní služby', 'is_active' => true, 'is_synthetic' => false],
            '042.100' => ['account_code' => '042.100', 'name' => 'Pořízení DHM', 'is_active' => true, 'is_synthetic' => false],
            '518.900' => ['account_code' => '518.900', 'name' => 'Staré služby', 'is_active' => false, 'is_synthetic' => false],
        ], 'children' => []];

        self::assertSame(ExpenseKind::Material, $this->invokeAnalysis('historyKind', $chart, '501.100', []));
        self::assertSame(ExpenseKind::SmallAsset, $this->invokeAnalysis('historyKind', $chart, '501.200', []));
        self::assertSame(ExpenseKind::SmallIntangible, $this->invokeAnalysis('historyKind', $chart, '518.200', []));
        self::assertSame(ExpenseKind::Service, $this->invokeAnalysis('historyKind', $chart, '518.100', []));
        self::assertSame(ExpenseKind::Material, $this->invokeAnalysis('historyKind', $chart, '518.100', ['518.100' => ExpenseKind::Material]));
        self::assertNull($this->invokeAnalysis('historyKind', $chart, '042.100', []));
        self::assertNull($this->invokeAnalysis('historyKind', $chart, '501', []));
        self::assertNull($this->invokeAnalysis('historyKind', $chart, '518.900', []));
    }

    public function testOnlyHistoryBackedProposalMayStayAutomatic(): void
    {
        self::assertTrue(AccountingSetupApprovalService::historyBackedAuto(['learned_from' => 'history', 'application_mode' => 'auto']));
        self::assertFalse(AccountingSetupApprovalService::historyBackedAuto(['learned_from' => 'history', 'application_mode' => 'suggest']));
        self::assertFalse(AccountingSetupApprovalService::historyBackedAuto(['application_mode' => 'auto']));
    }

    /** @return list<array<string,mixed>> */
    private function documents(int $vendorId, int $count, array $accounts, string $description): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->document($vendorId, $accounts, $description);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function document(int $vendorId, array $accounts, string $description, ?string $date = null): array
    {
        $id = $this->nextId++;
        return [
            'invoice_id' => $id,
            'vendor_id' => $vendorId,
            'vendor_name' => 'Dodavatel ' . $vendorId,
            'date' => $date ?? sprintf('2025-%02d-01', ($id % 12) + 1),
            'accounts' => $accounts,
            'descriptions' => [$description],
        ];
    }

    private function invokeAnalysis(string $methodName, mixed ...$args): mixed
    {
        $service = (new ReflectionClass(AccountingSetupAnalysisService::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod(AccountingSetupAnalysisService::class, $methodName))->invoke($service, ...$args);
    }
}
