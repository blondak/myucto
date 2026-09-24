<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\JournalImporter;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchImporter;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchOptions;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3Exception;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla dávky Money S3 bez databáze: výběr nejnovější zálohy firmy, identita
 * agendy z hlavičky zálohy, rok „od" a volby dávky.
 */
final class MoneyS3BatchSelectionTest extends TestCase
{
    public function testLatestBackupPerCompany(): void
    {
        $backups = [
            ['ico' => '12345679', 'backup_at' => '10.01.2026 08:15', 'mtime' => 300],
            ['ico' => '1234 5679', 'backup_at' => '2.3.2026 7:05', 'mtime' => 100],
            ['ico' => '87654326', 'backup_at' => '', 'mtime' => 100],
            ['ico' => '87654326', 'backup_at' => '', 'mtime' => 200],
            ['ico' => '', 'backup_at' => '', 'mtime' => 1],
        ];
        $selection = MoneyS3BatchImporter::latestPerIco($backups);
        self::assertSame([1, 3, 4], $selection['keep'], 'Datum zálohy z Money přebíjí čas souboru; bez něj rozhoduje čas souboru.');
        self::assertSame([0, 2], $selection['superseded']);
        self::assertSame('2026-03-02 07:05', MoneyS3BatchImporter::backupStamp('2.3.2026 7:05'));
        self::assertSame('2026-01-10 00:00', MoneyS3BatchImporter::backupStamp('10.01.2026'));
        self::assertSame('', MoneyS3BatchImporter::backupStamp('včera'));
    }

    public function testPeekIdentityReadsOnlyTheHeader(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3peek_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            SyntheticAgenda::writeLzFiles($dir . '/a.lz', SyntheticAgenda::forCompany(SyntheticAgenda::files(), '24681351', 'Náhled s.r.o.'));
            $id = Ms3Backup::peekIdentity($dir . '/a.lz', $dir . '/peek');
            self::assertSame(['name' => 'Náhled s.r.o.', 'ico' => '24681351', 'version' => SyntheticAgenda::VERSION, 'backup_at' => '10.01.2026 08:15'], $id);
            self::assertDirectoryDoesNotExist($dir . '/peek', 'Dočasné soubory náhledu se smažou.');

            file_put_contents($dir . '/b.lz', 'není zip');
            $this->expectException(MoneyS3Exception::class);
            Ms3Backup::peekIdentity($dir . '/b.lz', $dir . '/peek2');
        } finally {
            MoneyS3BatchImporter::removeTree($dir);
        }
    }

    public function testSuggestedFromYearIsYearAfterLastChainBreak(): void
    {
        self::assertNull(JournalImporter::suggestedFromYear([]));
        self::assertSame(2025, JournalImporter::suggestedFromYear([
            ['year' => 2022, 'next' => 2023], ['year' => 2024, 'next' => 2025],
        ]));
    }

    public function testOptions(): void
    {
        $o = MoneyS3BatchOptions::fromArray(['mode' => 'import', 'from_year' => '2024', 'existing' => 'update', 'group_name' => ' Skupina ']);
        self::assertSame(2024, $o->fromYear);
        self::assertSame('Skupina', $o->groupName);
        self::assertFalse($o->isDryRun());
        self::assertSame(MoneyS3BatchOptions::FROM_YEAR_AUTO, MoneyS3BatchOptions::fromArray([])->fromYear);
        self::assertTrue(MoneyS3BatchOptions::fromArray([])->isDryRun(), 'Bez volby je dávka zkouškou nanečisto.');
        self::assertNull(MoneyS3BatchOptions::fromArray(['from_year' => 'all'])->fromYear);

        $this->expectException(MoneyS3Exception::class);
        MoneyS3BatchOptions::fromArray(['existing' => 'reset']);
    }

    public function testOnlyReconciliationErrorsLetsFollowUpStepsRun(): void
    {
        $step = static fn (string ...$codes): array => ['key' => 'x', 'messages' => array_map(static fn (string $c): array => ['level' => 'error', 'code' => $c], $codes)];
        self::assertTrue(MoneyS3BatchImporter::onlyReconciliationErrors(['steps' => [$step('reconciliation_failed')]]));
        self::assertFalse(MoneyS3BatchImporter::onlyReconciliationErrors(['steps' => [$step('reconciliation_failed', 'unexpected')]]));
        self::assertFalse(MoneyS3BatchImporter::onlyReconciliationErrors(['steps' => []]));
        self::assertFalse(MoneyS3BatchImporter::onlyReconciliationErrors(['steps' => [$step('reconciliation_failed')], 'failure' => 'cancelled']));
    }
}
