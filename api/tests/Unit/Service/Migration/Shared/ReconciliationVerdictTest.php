<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\ReconciliationCriteria;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;
use MyInvoice\Service\Migration\Shared\ReconciliationVerdict;
use PHPUnit\Framework\TestCase;

/**
 * Co nesedící rekonciliace roku znamená pro protokol: K2 chyba, K1 do koruny zaokrouhlení,
 * K1 nad korunu, K3 a K4 rozdíl k přijetí.
 */
final class ReconciliationVerdictTest extends TestCase
{
    /**
     * @param list<array{account:string,myucto:array{0:float,1:float,2:float},money:array{0:float,1:float,2:float}}> $journalDiffs
     * @param array<string,bool> $failing klíč kontroly => ok
     * @return array<string,mixed>
     */
    private static function year(array $journalDiffs, array $failing = [], array $documents = []): array
    {
        $checks = [];
        foreach (['turnover_balanced', 'matches_journal', 'opening_balanced', 'no_drafts'] as $key) {
            $checks[] = ['key' => $key, 'ok' => $failing[$key] ?? true];
        }
        $checks[] = ['key' => 'money_journal', 'ok' => $journalDiffs === [], 'accounts' => 12];
        foreach ($documents as $d) {
            $checks[] = ['key' => 'documents_' . $d['key'], 'ok' => $d['ok']];
        }
        $checks[] = ['key' => 'balance_sheet_balanced', 'ok' => $failing['balance_sheet_balanced'] ?? true];
        $ok = true;
        foreach ($checks as $c) {
            $ok = $ok && $c['ok'];
        }
        return ['year' => 2091, 'ok' => $ok, 'checks' => $checks, 'journal_diffs' => $journalDiffs, 'documents' => $documents, 'unmapped_accounts' => []];
    }

    /** @return list<array{level:string,code:string,context:array<string,mixed>,acceptable?:bool}> */
    private static function messages(ImportProtocol $p): array
    {
        $out = [];
        foreach ($p->toArray()['steps'] as $s) {
            foreach ($s['messages'] as $m) {
                $out[] = $m;
            }
        }
        return $out;
    }

    public function testRoundingUpToOneCrownIsAWarning(): void
    {
        $p = new ImportProtocol('dry_run');
        $year = ReconciliationVerdict::apply($p, 'reconciliation', self::year([
            ['account' => '343', 'myucto' => [0.0, 100.2, 100.2], 'money' => [0.0, 100.0, 100.0]],
            ['account' => '518', 'myucto' => [0.0, 50.0, 50.0], 'money' => [0.0, 51.0, 51.0]],
        ]));

        self::assertSame('completed_with_warnings', $p->status());
        $messages = self::messages($p);
        self::assertSame(['warning', 'rounding_difference'], [$messages[0]['level'], $messages[0]['code']]);
        self::assertSame([['account' => '343', 'difference' => 0.2], ['account' => '518', 'difference' => -1.0]], $messages[0]['context']['accounts']);
        self::assertTrue($year['ok']);
        self::assertTrue(ReconciliationCriteria::forYear($year)['K1']);
        self::assertTrue($year['checks'][4]['rounding']);
    }

    public function testOverOneCrownIsADifference(): void
    {
        $p = new ImportProtocol('dry_run');
        $year = ReconciliationVerdict::apply($p, 'reconciliation', self::year([
            ['account' => '343', 'myucto' => [0.0, 100.2, 100.2], 'money' => [0.0, 100.0, 100.0]],
            ['account' => '518', 'myucto' => [0.0, 50.0, 50.0], 'money' => [0.0, 51.01, 51.01]],
        ]));

        self::assertSame('failed', $p->status());
        self::assertTrue($p->acceptableOnly());
        $messages = self::messages($p);
        self::assertCount(1, $messages);
        self::assertSame(['error', 'reconciliation_failed', true], [$messages[0]['level'], $messages[0]['code'], $messages[0]['acceptable'] ?? false]);
        self::assertSame(['K1'], $messages[0]['context']['criteria']);
        self::assertSame([['account' => '343', 'difference' => 0.2], ['account' => '518', 'difference' => -1.01]], $messages[0]['context']['accounts']);
        self::assertFalse($year['ok']);
    }

    public function testRoundingBoundaryIsInclusive(): void
    {
        self::assertTrue(ReconciliationTolerance::isRounding(1.0));
        self::assertTrue(ReconciliationTolerance::isRounding(-1.004));
        self::assertFalse(ReconciliationTolerance::isRounding(1.01));
    }

    public function testK2IsAlwaysAHardError(): void
    {
        $p = new ImportProtocol('import', true);
        ReconciliationVerdict::apply($p, 'reconciliation', self::year([], ['opening_balanced' => false]));

        self::assertSame('failed', $p->status());
        self::assertFalse($p->acceptableOnly());
        self::assertArrayNotHasKey('acceptable', self::messages($p)[0]);
    }

    public function testDocumentsAndBalanceSheetAreAcceptedDifferences(): void
    {
        $p = new ImportProtocol('import', true);
        ReconciliationVerdict::apply($p, 'reconciliation', self::year(
            [['account' => '311', 'myucto' => [0.0, 0.3, 0.3], 'money' => [0.0, 0.0, 0.0]]],
            ['balance_sheet_balanced' => false],
            [['key' => 'issued_invoices', 'documents' => 1210.0, 'journal' => 1000.0, 'ok' => false, 'other_accounts' => 0]],
        ));

        self::assertSame('completed_with_warnings', $p->status());
        $accepted = $p->toArray()['accepted_differences'];
        self::assertCount(1, $accepted);
        self::assertSame(['K3', 'K4'], $accepted[0]['context']['criteria']);
        self::assertSame([['check' => 'issued_invoices', 'documents' => 1210.0, 'journal' => 1000.0, 'difference' => 210.0]], $accepted[0]['context']['documents']);
        self::assertSame(['rounding_difference', 'reconciliation_failed'], array_column(self::messages($p), 'code'));
    }

    public function testCleanYearWritesNothing(): void
    {
        $p = new ImportProtocol('dry_run');
        $year = self::year([]);
        self::assertSame($year, ReconciliationVerdict::apply($p, 'reconciliation', $year));
        self::assertSame([], self::messages($p));
    }
}
