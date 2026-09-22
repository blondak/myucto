<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration\Shared;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\ChartAccountCreator;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class MigrationPeriodsAndChartTest extends SharedMigrationDbTestCase
{
    public function testCreatesPeriodOnceAndRemembersOnlyNew(): void
    {
        $supplier = $this->supplier();
        $periods = new MigrationPeriods(new AccountingPeriodRepository($this->db));
        $p = new ImportProtocol('import');
        $remembered = [];
        $adopted = [];

        $created = $periods->ensure($supplier, 2024, '2024-03-15', '2024-12-31', $p, 'journal',
            static function (int $id) use (&$remembered): void { $remembered[] = $id; },
            static function (int $id) use (&$adopted): void { $adopted[] = $id; }, true);
        self::assertSame(['starts_on' => '2024-03-15', 'ends_on' => '2024-12-31', 'status' => 'open', 'locked' => false], array_diff_key($created, ['id' => 0]));
        self::assertSame([$created['id']], $remembered);
        self::assertSame([], $adopted);

        $again = $periods->ensure($supplier, 2024, '2024-01-01', '2024-12-31', $p, 'journal',
            static function (int $id) use (&$remembered): void { $remembered[] = $id; },
            static function (int $id) use (&$adopted): void { $adopted[] = $id; }, true);
        self::assertSame($created, $again, 'existující období se nemění, hranice zůstávají');
        self::assertSame([$created['id']], $remembered);
        self::assertSame([$created['id']], $adopted);

        $step = self::step($p, 'journal');
        self::assertSame(1, $step['counts']['periods_created'] ?? null);
        self::assertSame(['period_bounds_differ'], array_column($step['messages'], 'code'));

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$created['id']]);
        self::assertTrue($periods->ensure($supplier, 2024, '2024-01-01', '2024-12-31', $p, 'journal', static fn (int $id) => null)['locked']);
        self::assertSame(['2025-01-01', '2025-12-31'], MigrationPeriods::calendarYear(2025));
    }

    public function testSyntheticTakesTypeFromSiblingAndAnalyticFromParent(): void
    {
        $supplier = $this->supplier();
        $accounts = new ChartOfAccountsRepository($this->db);
        $accounts->insert($supplier, ['account_code' => '601', 'name' => 'Tržby za výrobky', 'account_type' => 'revenue', 'normal_side' => 'credit', 'is_synthetic' => true, 'parent_id' => null, 'is_active' => true]);
        $creator = new ChartAccountCreator($accounts);
        $p = new ImportProtocol('import');

        $created = $creator->createSynthetic($supplier, '604', 'Tržby za zboží', $p, 'chart', true);
        self::assertNotNull($created);
        self::assertSame('601', $created['sibling']);
        $row = $accounts->findById($supplier, $created['id']);
        self::assertSame(['604', 'revenue', 'credit'], [(string) $row['account_code'], (string) $row['account_type'], (string) $row['normal_side']]);

        $analytic = $creator->createAnalytic($supplier, '604.100', str_repeat('x', 200), $row);
        $child = $accounts->findById($supplier, $analytic);
        self::assertSame([(int) $created['id'], 190], [(int) $child['parent_id'], mb_strlen((string) $child['name'])]);

        self::assertNull($creator->createSynthetic($supplier, '901', 'Podrozvaha', $p, 'chart'), 'bez sourozence typ nejde odvodit');
        $messages = self::step($p, 'chart')['messages'];
        self::assertSame(['synthetic_created', 'unknown_synthetic'], array_column($messages, 'code'));
        self::assertSame(['account' => '604', 'type_from' => '601'], $messages[0]['context']);
    }

    /** @return array<string,mixed> */
    private static function step(ImportProtocol $p, string $key): array
    {
        foreach ($p->toArray()['steps'] as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }
        self::fail("Krok {$key} v protokolu chybí.");
    }
}
