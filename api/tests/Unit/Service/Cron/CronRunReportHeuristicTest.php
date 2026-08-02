<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Service\Cron\CronRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Heuristika, která rozhoduje, jestli běh skončí v `cron_runs` nebo jen
 * v heartbeatu. Chyba tady je tichá v obou směrech:
 *   - falešné „nic se nestalo" → běh, který něco udělal, zmizí z historie,
 *   - falešné „něco se stalo"  → vrátí se nafouklá tabulka, kvůli které to celé vzniklo.
 *
 * Proto jsou tu jako vstupy SKUTEČNÉ tvary reportů z api/bin/cron-*.php.
 */
final class CronRunReportHeuristicTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>|null,bool}> */
    public static function reports(): iterable
    {
        // --- prázdné ticky (nesmí se logovat) --------------------------------
        yield 'epo: nic k pollování' => [
            ['polled' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 'no_pollable_attempts'],
            false,
        ];
        yield 'epo: bez komerční licence' => [
            ['polled' => 0, 'updated' => 0, 'errors' => 0, 'skipped' => 'commercial_license_required'],
            false,
        ];
        yield 'ai-worker: prázdná fronta' => [
            ['processed' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0, 'gate' => 'empty_queue'],
            false,
        ];
        yield 'scan-inbox: nenastavený adresář' => [
            ['skipped' => 'inbox_dir not configured', 'inbox_dir' => ''],
            false,
        ];
        yield 'scan-inbox: žádní dodavatelé' => [['skipped' => 'no suppliers'], false];
        yield 'digest: nic k odeslání' => [['sent' => 0, 'skipped' => 0, 'dry_run' => false], false];
        yield 'reminders: nic k připomenutí' => [['sent' => 0, 'errors' => 0], false];
        yield 'prázdný report' => [[], false];

        // --- reálná práce (musí se zalogovat) --------------------------------
        yield 'epo: doopravdy pollovalo' => [
            ['processed' => 3, 'confirmed' => 1, 'rejected' => 0, 'pending' => 2, 'errors' => 0],
            true,
        ];
        yield 'ai-worker: zpracovalo joby' => [
            ['processed' => 4, 'done' => 4, 'skipped' => 0, 'failed' => 0],
            true,
        ];
        yield 'reminders: odeslalo' => [['sent' => 5, 'errors' => 0], true];

        // `skipped` jako POČET je výsledek práce, ne vysvětlení. Kdyby heuristika
        // ignorovala klíč bez ohledu na typ hodnoty, tenhle běh by zmizel z historie.
        yield 'digest: nic neodeslal, ale 5 přeskočil' => [
            ['sent' => 0, 'skipped' => 5, 'dry_run' => false],
            true,
        ];

        yield 'cleanup: smazané řádky' => [['cron_runs_purged' => 940], true];
        yield 'integrita: nalezené nesrovnalosti' => [['checked' => 0, 'issues' => ['chybí protiúčet']], true];
        yield 'vnořená nula se neplete s prací' => [['a' => 0, 'nested' => ['b' => 0, 'c' => 0]], false];
        yield 'vnořená nenula je práce' => [['a' => 0, 'nested' => ['b' => 0, 'c' => 2]], true];

        // Chybějící report = neznámo → raději zalogovat, ať se stopa neztratí.
        yield 'report chybí' => [null, true];
    }

    /** @param array<string,mixed>|null $report */
    #[DataProvider('reports')]
    public function testReportIndicatesWork(?array $report, bool $expected): void
    {
        self::assertSame($expected, CronRun::reportIndicatesWork($report));
    }

    public function testExplicitFlagIsAvailableWhenHeuristicWouldGuessWrong(): void
    {
        // Pojistka pro skripty, kterým tvar reportu heuristice nesedí — smysl je,
        // aby existovala cesta, jak ji obejít, bez ohýbání reportu.
        $method = new \ReflectionMethod(CronRun::class, 'finish');
        $params = $method->getParameters();
        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $params);

        self::assertContains('didWork', $names);
        $didWork = $params[array_search('didWork', $names, true)];
        self::assertTrue($didWork->allowsNull(), 'didWork musí být nullable — null = použij heuristiku.');
        self::assertTrue($didWork->isDefaultValueAvailable());
        self::assertNull($didWork->getDefaultValue());
    }
}
