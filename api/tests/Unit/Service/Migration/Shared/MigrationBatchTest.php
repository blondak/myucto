<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\Shared\MigrationBatchActor;
use MyInvoice\Service\Migration\Shared\MigrationBatchRunner;
use MyInvoice\Service\Migration\Shared\ReconciliationCriteria;
use PHPUnit\Framework\TestCase;

/**
 * Fronta dávkového převodu (pád položky nezastaví ostatní, zrušení, stav dávky),
 * souhrn rekonciliace K1–K4 a oprávnění dávky.
 */
final class MigrationBatchTest extends TestCase
{
    public function testFailingItemDoesNotStopTheRest(): void
    {
        $done = [];
        $results = (new MigrationBatchRunner())->run(
            ['a', 'b', 'c'],
            static function (string $item): array {
                if ($item === 'b') {
                    throw new \RuntimeException('záloha b je poškozená');
                }
                return ['status' => 'completed', 'item' => $item];
            },
            static fn (\Throwable $e): string => 'chyba: ' . $e->getMessage(),
            null,
            null,
            static function (string $item, int $i, array $r) use (&$done): void {
                $done[] = $item . ':' . $r['status'];
            },
        );

        self::assertSame(['completed', 'failed', 'completed'], array_column($results, 'status'));
        self::assertSame('chyba: záloha b je poškozená', $results[1]['error']);
        self::assertSame(['a:completed', 'b:failed', 'c:completed'], $done, 'Výsledek každé položky se hlásí hned.');
    }

    public function testCancellationSkipsRemainingItems(): void
    {
        $calls = 0;
        $ran = [];
        $results = (new MigrationBatchRunner())->run(
            ['a', 'b', 'c'],
            static function (string $item) use (&$ran): array {
                $ran[] = $item;
                return ['status' => 'completed'];
            },
            static fn (\Throwable $e): string => $e->getMessage(),
            static function () use (&$calls): bool {
                return ++$calls > 1;
            },
        );
        self::assertSame(['a'], $ran);
        self::assertSame(['completed', 'cancelled', 'cancelled'], array_column($results, 'status'));

        $ran = [];
        $results = (new MigrationBatchRunner())->run(
            ['a', 'b'],
            static function (string $item) use (&$ran): array {
                $ran[] = $item;
                return ['status' => 'cancelled'];
            },
            static fn (\Throwable $e): string => $e->getMessage(),
        );
        self::assertSame(['a'], $ran, 'Zrušení uprostřed firmy zruší i zbytek dávky.');
        self::assertSame(['cancelled', 'cancelled'], array_column($results, 'status'));
    }

    public function testBatchStatus(): void
    {
        self::assertSame('completed', MigrationBatchRunner::batchStatus([]));
        self::assertSame('completed', MigrationBatchRunner::batchStatus(['completed', 'skipped']));
        self::assertSame('completed', MigrationBatchRunner::batchStatus(['skipped', 'skipped']), 'Přeskočené firmy nejsou upozornění.');
        self::assertSame('completed_with_warnings', MigrationBatchRunner::batchStatus(['completed', 'completed_with_warnings']));
        self::assertSame('completed_with_warnings', MigrationBatchRunner::batchStatus(['completed', 'failed']), 'Část firem převedená = dávka doběhla s upozorněním.');
        self::assertSame('failed', MigrationBatchRunner::batchStatus(['failed', 'skipped', 'failed']));
        self::assertSame('cancelled', MigrationBatchRunner::batchStatus(['completed', 'cancelled']));
    }

    public function testReconciliationCriteria(): void
    {
        $year2024 = ['year' => 2024, 'checks' => [
            ['key' => 'turnover_balanced', 'ok' => true],
            ['key' => 'matches_journal', 'ok' => true],
            ['key' => 'opening_balanced', 'ok' => true],
            ['key' => 'no_drafts', 'ok' => true],
            ['key' => 'money_journal', 'ok' => true],
            ['key' => 'money_report', 'ok' => false],
            ['key' => 'documents_purchase_invoices', 'ok' => true],
            ['key' => 'balance_sheet_balanced', 'ok' => true],
        ]];
        $year2025 = ['year' => 2025, 'checks' => [
            ['key' => 'turnover_balanced', 'ok' => true],
            ['key' => 'pohoda_journal', 'ok' => true],
            ['key' => 'documents_bank', 'ok' => false],
            ['key' => 'balance_sheet_balanced', 'ok' => true],
        ]];

        self::assertSame(['K1' => false, 'K2' => true, 'K3' => true, 'K4' => true], ReconciliationCriteria::forYear($year2024));
        self::assertSame(['K1' => true, 'K2' => true, 'K3' => true, 'K4' => false], ReconciliationCriteria::forYear($year2025));
        self::assertSame(['K1' => null, 'K2' => null, 'K3' => null, 'K4' => null], ReconciliationCriteria::forYear(['year' => 2023, 'checks' => []]),
            'Rok bez kontrol není „sedí".');

        $summary = ReconciliationCriteria::summarize([$year2025, $year2024]);
        self::assertSame([2024, 2025], array_keys($summary['years']));
        self::assertSame(['K1' => false, 'K2' => true, 'K3' => true, 'K4' => false], $summary['total']);
        self::assertSame(['K1' => null, 'K2' => null, 'K3' => null, 'K4' => null], ReconciliationCriteria::summarize([])['total']);
    }

    public function testActorRoundTripAndNewCompanyBecomesReachable(): void
    {
        $actor = MigrationBatchActor::fromArray((new MigrationBatchActor(7, [1, 2], true, true))->toArray());
        self::assertSame([1, 2], $actor->allowedSupplierIds);
        self::assertSame([1, 2, 9], $actor->withSupplier(9)->allowedSupplierIds);
        self::assertSame($actor, $actor->withSupplier(2));
        self::assertNull(MigrationBatchActor::cli(1)->withSupplier(5)->allowedSupplierIds);
        self::assertFalse(MigrationBatchActor::fromArray([])->canCreate, 'Bez uložených práv dávka firmy nezakládá.');
    }
}
