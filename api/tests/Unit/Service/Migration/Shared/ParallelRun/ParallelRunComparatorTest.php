<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared\ParallelRun;

use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunComparator as C;
use MyInvoice\Service\Migration\Shared\ParallelRun\ParallelRunReconciliation;
use PHPUnit\Framework\TestCase;

final class ParallelRunComparatorTest extends TestCase
{
    public function testTrialBalanceReportsAccountWithAllThreeValues(): void
    {
        $ok = C::trialBalance(['311' => [0.0, 100.0, 100.0]], ['311' => [0.0, 100.0, 100.0]]);
        self::assertSame('ok', $ok['status']);

        $diff = C::trialBalance(['311' => [0.0, 100.0, 100.0], '518' => [0.0, 50.0, 50.0]], ['311' => [0.0, 100.0, 100.0], '518' => [0.0, 55.0, 55.0], '602' => [0.0, -10.0, -10.0]]);
        self::assertSame('differences', $diff['status']);
        self::assertSame(['K1:518', 'K1:602'], array_column($diff['differences'], 'id'));
        self::assertSame('missing_in_myucto', $diff['differences'][1]['note']);
        self::assertSame(['opening', 'turnover', 'closing'], array_column($diff['differences'][0]['values'], 'field'));
    }

    public function testDocumentCountsOnlyForBooksTheSourceReports(): void
    {
        $r = C::documentCounts(['issued_invoices' => 3, 'purchase_invoices' => 5, 'bank' => 7], ['issued_invoices' => 3, 'bank' => 8]);
        self::assertSame(['K5:bank'], array_column($r['differences'], 'id'));
        self::assertArrayNotHasKey('purchase_invoices', $r['summary']['books']);
    }

    public function testSaldoSplitsIntoPartnerTotalsAndDocuments(): void
    {
        $mine = [
            ['account' => '311', 'doc_no' => 'FV001', 'alt_doc_no' => null, 'doc_type' => 'invoice', 'doc_id' => 11, 'partner' => 'Beta a.s.', 'remaining' => 1000.0],
            ['account' => '311', 'doc_no' => 'FV002', 'alt_doc_no' => null, 'doc_type' => 'invoice', 'doc_id' => 12, 'partner' => 'Beta a.s.', 'remaining' => 500.0],
            ['account' => '321', 'doc_no' => 'DF-17', 'alt_doc_no' => 'FP001', 'doc_type' => 'purchase_invoice', 'doc_id' => 21, 'partner' => 'Alfa s.r.o.', 'remaining' => 300.0],
            ['account' => '314', 'doc_no' => 'ZF1', 'alt_doc_no' => null, 'doc_type' => 'purchase_invoice', 'doc_id' => 22, 'partner' => 'Alfa s.r.o.', 'remaining' => 99.0],
        ];
        $theirs = [
            ['account' => '311', 'document' => 'FV001', 'partner' => 'BETA a.s.', 'ico' => '', 'amount' => 1000.0],
            ['account' => '311', 'document' => 'FV003', 'partner' => 'Beta a.s.', 'ico' => '', 'amount' => 500.0],
            ['account' => '321', 'document' => 'FP001', 'partner' => 'Alfa s.r.o.', 'ico' => '', 'amount' => 300.0],
        ];
        [$k6, $k7] = C::saldo($mine, $theirs);

        self::assertSame('ok', $k6['status'], 'Součty po účtech i partnerech sedí; 314 zdroj neuvádí, nekontroluje se.');
        self::assertSame('differences', $k7['status']);
        $byId = array_column($k7['differences'], null, 'id');
        self::assertCount(2, $byId, 'Přijatá faktura se spáruje vlastním číslem dokladu.');
        self::assertSame('open_only_in_myucto', $byId['K7:311|FV002']['note']);
        self::assertSame(['type' => 'invoice', 'id' => 12], $byId['K7:311|FV002']['link']);
        self::assertSame('open_only_in_source', $byId['K7:311|FV003']['note']);
    }

    public function testSaldoPartnerDifference(): void
    {
        [$k6] = C::saldo(
            [['account' => '311', 'doc_no' => 'FV001', 'doc_type' => 'invoice', 'doc_id' => 1, 'partner' => 'Beta', 'remaining' => 1000.0]],
            [['account' => '311', 'document' => 'FV001', 'partner' => 'Gama', 'ico' => '', 'amount' => 1000.0]],
        );
        self::assertSame(['K6:partner:311|beta', 'K6:partner:311|gama'], array_column($k6['differences'], 'id'), 'Účet sedí, partneři ne.');
    }

    public function testBankCzkAndForeign(): void
    {
        $mine = [
            ['key' => '1', 'numbers' => ['0001000000005/0100'], 'label' => 'Běžný', 'currency' => 'CZK', 'ledger_code' => '221.001', 'ledger_balance' => 62150.0, 'statement_balance' => 62150.0, 'statement_date' => '2026-10-31'],
            ['key' => '2', 'numbers' => ['2000000003/0300'], 'label' => 'EUR', 'currency' => 'EUR', 'ledger_code' => '221.002', 'ledger_balance' => 25100.0, 'statement_balance' => 1000.0, 'statement_date' => '2026-10-31'],
            ['key' => '3', 'numbers' => ['3000000004/0800'], 'label' => 'Rezerva', 'currency' => 'CZK', 'ledger_code' => '221.003', 'ledger_balance' => 10.0, 'statement_balance' => 20.0, 'statement_date' => '2026-10-31'],
        ];
        [$k8, $k10] = C::bank($mine, [
            ['account' => '1000000005/0100', 'currency' => 'CZK', 'balance' => 62150.0, 'balance_czk' => null],
            ['account' => '221.002', 'currency' => 'EUR', 'balance' => 1000.01, 'balance_czk' => 25100.0],
            ['account' => '9999/0100', 'currency' => 'CZK', 'balance' => 1.0, 'balance_czk' => null],
        ]);

        self::assertSame(['K8:9999/0100', 'K8:3'], array_column($k8['differences'], 'id'), 'Neznámý účet zdroje a výpis ≠ 221 u účtu, který zdroj neuvádí.');
        self::assertSame('account_not_found', $k8['differences'][0]['note']);
        self::assertSame('statement_vs_ledger', $k8['differences'][1]['note']);
        self::assertSame('ok', $k10['status'], 'Rozdíl 0,01 v měně je v toleranci.');

        [, $k10] = C::bank($mine, [['account' => '2000000003/0300', 'currency' => 'EUR', 'balance' => 1000.02, 'balance_czk' => null]]);
        self::assertSame('differences', $k10['status']);
    }

    public function testVatReturnToleratesCrownRounding(): void
    {
        $r = C::vatReturn(['Veta1.obrat23' => 100000.0, 'Veta1.dan23' => 21000.0], ['Veta1.obrat23' => 100000.4, 'Veta1.dan23' => 21001.0, 'Veta4.pln23' => 5.0]);
        self::assertSame(['K9:dphdp3:Veta1.dan23', 'K9:dphdp3:Veta4.pln23'], array_column($r['differences'], 'id'));
        self::assertSame('1', $r['differences'][0]['label']);
    }

    public function testControlStatementLinksDocument(): void
    {
        $row = static fn (string $section, string $doc, float $amount): array => ['section' => $section, 'partner' => 'CZ1', 'document' => $doc, 'amount' => $amount];
        $diffs = C::controlStatement(
            ['values' => ['VetaC.obrat23' => 100.0], 'rows' => ['A4|CZ1|FV1' => $row('A4', 'FV1', 121.0), 'B2|CZ1|DF1' => $row('B2', 'DF1', 50.0)]],
            ['values' => ['VetaC.obrat23' => 100.0], 'rows' => ['A4|CZ1|FV1' => $row('A4', 'FV1', 121.0), 'A4|CZ1|FV2' => $row('A4', 'FV2', 10.0)]],
            static fn (string $section, string $doc): ?array => $doc === 'DF1' ? ['type' => 'purchase_invoice', 'id' => 7] : null,
        );
        self::assertSame(['K9:dphkh1:A4|CZ1|FV2', 'K9:dphkh1:B2|CZ1|DF1'], array_column($diffs, 'id'));
        self::assertSame('missing_in_myucto', $diffs[0]['note']);
        self::assertSame(['type' => 'purchase_invoice', 'id' => 7], $diffs[1]['link']);
    }

    public function testAssetsCompareOnlyProvidedValues(): void
    {
        $mine = [
            ['inventory_number' => 'M001', 'name' => 'Stroj', 'input_price' => 100000.0, 'acc_amount' => 40000.0, 'net_book_value' => 60000.0],
            ['inventory_number' => 'M002', 'name' => 'Auto', 'input_price' => 500000.0, 'acc_amount' => 0.0, 'net_book_value' => 500000.0],
        ];
        $r = C::assets($mine, [
            ['inventory_number' => 'm001', 'name' => '', 'input_price' => 100000.0, 'acc_amount' => null, 'net_book_value' => 60000.0],
            ['inventory_number' => 'M003', 'name' => 'Pila', 'input_price' => 1.0, 'acc_amount' => null, 'net_book_value' => null],
        ]);
        self::assertSame(['K11:M003', 'K11:M002'], array_column($r['differences'], 'id'));
        self::assertSame(['missing_in_myucto', 'missing_in_source'], array_column($r['differences'], 'note'));
    }

    public function testCostCentersAndStatement(): void
    {
        $r = C::costCenters(['REZIE' => ['name' => 'Režie', 'revenue' => 0.0, 'cost' => 11800.0]], ['rezie' => ['revenue' => 0.0, 'cost' => 11800.0], 'VYROBA' => ['revenue' => 5.0, 'cost' => 0.0]]);
        self::assertSame(['K12:VYROBA'], array_column($r['differences'], 'id'));

        $mine = ['A:B.II.' => ['label' => 'Oběžná aktiva', 'amount' => 120400.0]];
        self::assertSame([], C::statement('balance_sheet', $mine, ['A:B.II.' => 120000.0], 1000.0), 'V tisících je tolerance tisíc.');
        self::assertCount(1, C::statement('balance_sheet', $mine, ['A:B.II.' => 120000.0], 1.0));
        self::assertSame('row_unknown', C::statement('balance_sheet', $mine, ['A:X.' => 1.0], 1.0)[0]['note']);
    }

    public function testOverallStatus(): void
    {
        self::assertSame('incomplete', ParallelRunReconciliation::status([]));
        self::assertSame('ok', ParallelRunReconciliation::status([['status' => 'ok']]));
        self::assertSame('incomplete', ParallelRunReconciliation::status([['status' => 'ok'], ['status' => 'error']]));
        self::assertSame('differences', ParallelRunReconciliation::status([['status' => 'error'], ['status' => 'differences']]));
    }

    public function testDifferencesAreCappedButCounted(): void
    {
        $diffs = array_map(static fn (int $i): array => C::diff('K1:' . $i, (string) $i, null, []), range(1, C::MAX_DIFFERENCES + 3));
        $r = C::result('K1', C::TOLERANCE_CENT, $diffs);
        self::assertSame(C::MAX_DIFFERENCES + 3, $r['difference_count']);
        self::assertCount(C::MAX_DIFFERENCES, $r['differences']);
    }
}
