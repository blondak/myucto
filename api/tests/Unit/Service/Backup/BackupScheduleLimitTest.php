<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup;

use DateTimeImmutable;
use MyInvoice\Service\Backup\BackupScheduleContractException;
use MyInvoice\Service\Backup\BackupScheduleLimit;
use PHPUnit\Framework\TestCase;

/**
 * H-25 — 4× denně je SMLUVNÍ strop, ne technický.
 *
 * Do téhle frekvence hosting následuje frekvenci svých vlastních záloh
 * zdarma; nad ni už ne. Pátý dump denně tedy není „o něco víc dat", je to
 * položka, kterou nikdo neodsouhlasil — a přijde na faktuře za měsíc, ne
 * v logu. Proto se odmítá tvrdě: varování by se přehlédlo.
 */
final class BackupScheduleLimitTest extends TestCase
{
    public function testRecommendedScheduleRunsExactlyFourTimesADay(): void
    {
        self::assertSame(
            4,
            BackupScheduleLimit::runsOnDay(
                BackupScheduleLimit::RECOMMENDED_EXPRESSION,
                new DateTimeImmutable('2026-06-15'),
            ),
        );
        self::assertTrue(BackupScheduleLimit::isWithinContract(BackupScheduleLimit::RECOMMENDED_EXPRESSION));
        // Ztráta dat klesá z 24 hodin na 6 — to je celý smysl H-25.
        self::assertSame(6, (int) (24 / 4));
    }

    /**
     * @return array<string,array{0:string,1:int}>
     */
    public static function withinContract(): array
    {
        return [
            'denní (dnešní default)' => ['0 2 * * *', 1],
            '2× denně'               => ['0 2,14 * * *', 2],
            '4× denně, doporučeno'   => ['0 */6 * * *', 4],
            '4× denně, výčtem'       => ['30 1,7,13,19 * * *', 4],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('withinContract')]
    public function testSchedulesUpToFourPerDayPass(string $expression, int $expectedRuns): void
    {
        self::assertSame($expectedRuns, BackupScheduleLimit::runsOnDay($expression, new DateTimeImmutable('2026-06-15')));
        BackupScheduleLimit::assertWithinContract($expression);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function overContract(): array
    {
        return [
            '6× denně'      => ['0 */4 * * *'],
            '8× denně'      => ['0 */3 * * *'],
            '12× denně'     => ['0 */2 * * *'],
            'každou hodinu' => ['0 * * * *'],
            'každou minutu' => ['* * * * *'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('overContract')]
    public function testScheduleAboveFourPerDayIsRejected(string $expression): void
    {
        self::assertFalse(BackupScheduleLimit::isWithinContract($expression));

        $this->expectException(BackupScheduleContractException::class);
        $this->expectExceptionMessageMatches('/dodatek ke smlouvě/');
        BackupScheduleLimit::assertWithinContract($expression);
    }

    public function testWorstDayDecidesNotToday(): void
    {
        // Výraz, který je většinu dnů neškodný a přestřelí jen prvního
        // v měsíci. Kontrola „dneška" by ho pustila dovnitř a limit by praskl
        // až za týden — tedy až na faktuře.
        $sneaky = '0 * 1 * *'; // každou hodinu, ale jen 1. v měsíci

        self::assertSame(0, BackupScheduleLimit::runsOnDay($sneaky, new DateTimeImmutable('2026-06-15')));
        self::assertFalse(
            BackupScheduleLimit::isWithinContract($sneaky),
            'Rozhoduje NEJHORŠÍ den v roce, ne ten, kdy se validace náhodou spustila.',
        );
    }

    public function testInvalidExpressionIsRejectedNotIgnored(): void
    {
        // Nesrozumitelný výraz není „v pořádku". Tiše ho propustit znamená
        // spustit zálohu bůhvíkdy — nebo nikdy.
        self::assertFalse(BackupScheduleLimit::isWithinContract('každých šest hodin'));

        $this->expectException(BackupScheduleContractException::class);
        BackupScheduleLimit::assertWithinContract('0 */6 * *');
    }

    public function testContractedScriptsAreExplicit(): void
    {
        self::assertTrue(BackupScheduleLimit::isContracted('cron-backup'));
        self::assertFalse(BackupScheduleLimit::isContracted('cron-cleanup'));
        self::assertSame(4, BackupScheduleLimit::MAX_RUNS_PER_DAY);
    }
}
