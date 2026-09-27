<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Accounting\TakenOverRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * „Převzatý z jiného účetního programu" má jediný zdroj: {@see TakenOverRecord}.
 *
 * Dřív znala podmínku jen bankovní číselná řada a jen pro tři mapy; převod ze Stereo NX
 * přibyl s vlastní mapou a automatika jeho zápisy přepisovala dál. Test proto hlídá, že
 * SSOT zná každou mapu převodu ve schématu a že účetní kód mapy nečte po svém.
 */
#[Group('architecture')]
final class TakenOverRecordMapsTest extends TestCase
{
    public function testEveryImportMapInSchemaIsKnown(): void
    {
        $snapshot = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/db/schema.snapshot.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $maps = array_values(array_filter(
            array_keys((array) $snapshot['tables']),
            static fn (string $table): bool => str_ends_with($table, '_import_map'),
        ));
        sort($maps);
        $known = array_keys(TakenOverRecord::MAPS);
        sort($known);

        self::assertNotSame([], $maps, 'Otisk schématu nemá žádnou mapu převodu — test hledá špatně.');
        self::assertSame($maps, $known, 'Mapa převodu ve schématu, kterou TakenOverRecord nezná (nebo naopak).');
    }

    public function testEveryImportMapCreatedByMigrationIsKnown(): void
    {
        $created = [];
        foreach (glob(dirname(__DIR__, 3) . '/db/migrations/*.sql') ?: [] as $file) {
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z0-9_]+_import_map)`?/i', (string) file_get_contents($file), $m) > 0) {
                array_push($created, ...array_map('strtolower', $m[1]));
            }
        }
        $created = array_values(array_unique($created));
        sort($created);
        $known = array_keys(TakenOverRecord::MAPS);
        sort($known);

        self::assertSame($known, $created);
    }

    public function testEveryMapCoversEveryRecord(): void
    {
        foreach (TakenOverRecord::MAPS as $map => $kinds) {
            self::assertSame(
                [TakenOverRecord::JOURNAL_ENTRY, TakenOverRecord::INVOICE, TakenOverRecord::PURCHASE_INVOICE],
                array_keys($kinds),
                $map,
            );
        }
        foreach (array_keys(TakenOverRecord::MAPS) as $map) {
            self::assertStringContainsString($map, TakenOverRecord::journalEntrySql('je'));
            self::assertStringContainsString($map, TakenOverRecord::documentSql('invoice', 'i'));
            self::assertStringContainsString($map, TakenOverRecord::documentSql('purchase_invoice', 'pi'));
        }
        self::assertStringContainsString("'accounting_journal'", TakenOverRecord::journalEntrySql('je'));
    }

    public function testAccountingCodeDoesNotReadImportMapsDirectly(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $scanned = 0;
        foreach (['src/Service/Accounting', 'src/Service/Bank', 'src/Action/Accounting', 'src/Action/Bank', 'bin'] as $dir) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if ($path === 'src/Service/Accounting/TakenOverRecord.php') {
                    continue;
                }
                $scanned++;
                if (preg_match('/\b[a-z0-9_]+_import_map\b/', (string) file_get_contents($file->getPathname())) === 1) {
                    $offenders[] = $path;
                }
            }
        }

        self::assertGreaterThan(100, $scanned, 'Guard neprošel účetní kód — hledá špatně.');
        self::assertSame([], $offenders, "Účetní kód čte mapu převodu mimo TakenOverRecord:\n  " . implode("\n  ", $offenders));
    }
}
