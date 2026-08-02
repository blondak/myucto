<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use DateTimeImmutable;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Dispatcher spouští úlohy podle tohohle vyhodnocovače. Chyba v něm znamená,
 * že se úloha buď nespustí vůbec (mzdy se nezaúčtují, záloha neproběhne),
 * nebo se spustí mnohonásobně. Ani jedno se hned nepozná, proto sem patří
 * i nudné případy.
 */
final class CronExpressionTest extends TestCase
{
    /** @return iterable<string,array{string,string,bool}> */
    public static function cases(): iterable
    {
        // --- každou minutu ---
        yield 'hvězdičky sedí vždy' => ['* * * * *', '2026-08-02 13:37:00', true];

        // --- denní v pevný čas (cron-backup) ---
        yield 'daily 02:00 v tu minutu' => ['0 2 * * *', '2026-08-02 02:00:00', true];
        yield 'daily 02:00 o minutu vedle' => ['0 2 * * *', '2026-08-02 02:01:00', false];
        yield 'daily 02:00 o hodinu vedle' => ['0 2 * * *', '2026-08-02 03:00:00', false];
        yield 'daily 02:30 (cron-backup-pdf)' => ['30 2 * * *', '2026-08-02 02:30:00', true];

        // --- krok (cron-bank-scan */30, scan-inbox */10) ---
        yield '*/30 v :00' => ['*/30 * * * *', '2026-08-02 09:00:00', true];
        yield '*/30 v :30' => ['*/30 * * * *', '2026-08-02 09:30:00', true];
        yield '*/30 v :15 nesedí' => ['*/30 * * * *', '2026-08-02 09:15:00', false];
        yield '*/10 v :20' => ['*/10 * * * *', '2026-08-02 09:20:00', true];
        yield '*/10 v :25 nesedí' => ['*/10 * * * *', '2026-08-02 09:25:00', false];

        // --- pracovní dny (cron-send-reminders: 0 9 * * 1-5) ---
        // 2026-08-03 je pondělí, 2026-08-08 sobota, 2026-08-09 neděle.
        yield 'pracovní den pondělí 9:00' => ['0 9 * * 1-5', '2026-08-03 09:00:00', true];
        yield 'pracovní den pátek 9:00' => ['0 9 * * 1-5', '2026-08-07 09:00:00', true];
        yield 'sobota nesedí' => ['0 9 * * 1-5', '2026-08-08 09:00:00', false];
        yield 'neděle nesedí' => ['0 9 * * 1-5', '2026-08-09 09:00:00', false];

        // --- měsíční (cron-payroll-post: 0 4 1 * *) ---
        yield 'prvního v měsíci 4:00' => ['0 4 1 * *', '2026-09-01 04:00:00', true];
        yield 'druhého v měsíci nesedí' => ['0 4 1 * *', '2026-09-02 04:00:00', false];

        // --- rozsah hodin (cron-automation-digest: 0 6-8 * * *) ---
        yield 'digest v 6:00' => ['0 6-8 * * *', '2026-08-02 06:00:00', true];
        yield 'digest v 8:00' => ['0 6-8 * * *', '2026-08-02 08:00:00', true];
        yield 'digest v 9:00 nesedí' => ['0 6-8 * * *', '2026-08-02 09:00:00', false];
        yield 'digest v 7:30 nesedí (minuta)' => ['0 6-8 * * *', '2026-08-02 07:30:00', false];

        // --- neděle jako 0 i 7 ---
        yield 'neděle zapsaná jako 0' => ['0 3 * * 0', '2026-08-09 03:00:00', true];
        yield 'neděle zapsaná jako 7' => ['0 3 * * 7', '2026-08-09 03:00:00', true];

        // --- výčet ---
        yield 'výčet minut 0,15,45' => ['0,15,45 * * * *', '2026-08-02 10:15:00', true];
        yield 'výčet minut mimo' => ['0,15,45 * * * *', '2026-08-02 10:30:00', false];

        // --- OR sémantika dom/dow, když jsou omezená obě pole ---
        yield 'dom i dow: sedí přes den v měsíci' => ['0 0 1 * 1', '2026-08-01 00:00:00', true];
        yield 'dom i dow: sedí přes den v týdnu' => ['0 0 1 * 1', '2026-08-03 00:00:00', true];
        yield 'dom i dow: nesedí ani jedno' => ['0 0 1 * 1', '2026-08-04 00:00:00', false];
    }

    #[DataProvider('cases')]
    public function testMatches(string $expression, string $when, bool $expected): void
    {
        self::assertSame(
            $expected,
            CronExpression::matches($expression, new DateTimeImmutable($when)),
            "{$expression} @ {$when}",
        );
    }

    /**
     * Dispatcher umí spustit jen to, čemu rozumí. Kdyby někdo do katalogu napsal
     * výraz mimo podporovanou syntax, úloha by se v režimu dispatcheru tiše
     * nikdy nespustila — tenhle test to zachytí při buildu, ne v produkci.
     */
    public function testEveryCatalogExpressionIsSupported(): void
    {
        foreach (CronCatalog::all() as $job) {
            CronExpression::assertValid((string) $job['linux_cron']);
        }
        self::assertTrue(true);
    }

    /**
     * Každá úloha z katalogu musí během 366 dní alespoň jednou padnout na svou
     * minutu. Chytá překlepy typu `0 4 31 2 *` (31. února), které syntakticky
     * projdou, ale nikdy nenastanou.
     */
    public function testEveryCatalogJobFiresAtLeastOnceInAYear(): void
    {
        $start = new DateTimeImmutable('2026-01-01 00:00:00');

        foreach (CronCatalog::all() as $job) {
            $expr = (string) $job['linux_cron'];
            $fired = false;
            // Krok po minutách by byl 527 040 iterací na úlohu. Katalog má
            // všechny úlohy zarovnané na celé minuty v rámci hodiny, takže
            // stačí projít každou minutu prvních 2 dnů + každou hodinu roku.
            for ($m = 0; $m < 2 * 24 * 60 && !$fired; $m++) {
                $fired = CronExpression::matches($expr, $start->modify("+{$m} minutes"));
            }
            for ($h = 0; $h < 366 * 24 && !$fired; $h++) {
                $probe = $start->modify("+{$h} hours");
                for ($m = 0; $m < 60 && !$fired; $m++) {
                    $fired = CronExpression::matches($expr, $probe->modify("+{$m} minutes"));
                }
            }
            self::assertTrue($fired, "Úloha {$job['script']} ({$expr}) by se za rok nikdy nespustila.");
        }
    }

    public function testRejectsUnsupportedSyntaxLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CronExpression::matches('@daily', new DateTimeImmutable('2026-08-02 00:00:00'));
    }

    public function testRejectsOutOfRangeField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CronExpression::matches('0 25 * * *', new DateTimeImmutable('2026-08-02 00:00:00'));
    }
}
