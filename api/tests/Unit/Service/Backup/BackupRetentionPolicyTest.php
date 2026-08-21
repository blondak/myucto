<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Backup\BackupRetentionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * H-05 — retence záloh v KUSECH vs. ve DNECH.
 *
 * Dokud běžela záloha jednou denně, byly obě jednotky totéž a nikoho
 * nenapadlo je rozlišovat. Od H-25 běží 4× denně a rozdíl je čtyřnásobný:
 * „7 dní" je 28 souborů (a tedy 4× větší kus zaplacené kvóty), „7 kusů" je
 * necelé dva dny zpět (a tedy podstatně kratší historie, než si provozovatel
 * myslí). Špatně zvolená jednotka se přitom projeví až tehdy, kdy je záloha
 * potřeba — proto ji test kontroluje explicitně na obou stranách.
 */
final class BackupRetentionPolicyTest extends TestCase
{
    private const RUNS_PER_DAY = 4;

    /**
     * Dumpy 4× denně po $days dnů zpátky od $now.
     *
     * @return array<string,DateTimeImmutable>
     */
    private function dumps(DateTimeImmutable $now, int $days): array
    {
        $files = [];
        for ($d = 0; $d < $days; $d++) {
            foreach ([0, 6, 12, 18] as $hour) {
                $at = $now->modify("-{$d} days")->setTime($hour, 0);
                $files['/backup/db-' . $at->format('Y-m-d_H-i') . '.zip'] = $at;
            }
        }

        return $files;
    }

    public function testManagedProfileKeepsSevenFilesNotSevenDays(): void
    {
        $now = new DateTimeImmutable('2026-06-20 18:30:00');
        $files = $this->dumps($now, 10); // 40 souborů

        $policy = BackupRetentionPolicy::named(BackupRetentionPolicy::PROFILE_MANAGED);
        $purge = $policy->purgeList($files, $now);

        self::assertSame(BackupRetentionPolicy::MODE_COPIES, $policy->mode);
        self::assertCount(40 - 7, $purge, 'Profil managed drží 7 SOUBORŮ, ne 7 dnů.');
        self::assertSame(7, $policy->expectedDailyFiles(self::RUNS_PER_DAY));

        // Sedm nejnovějších musí zůstat — tedy necelé dva dny zpět.
        $kept = array_values(array_diff(array_keys($files), $purge));
        self::assertCount(7, $kept);
        self::assertContains('/backup/db-2026-06-20_18-00.zip', $kept);
        self::assertContains('/backup/db-2026-06-19_06-00.zip', $kept);
        // Osmý nejnovější dump je z 06-19 00:00 — sedm kusů při 4×/den nesahá
        // ani dva dny zpět, což je přesně to, co „7" v této jednotce znamená.
        self::assertNotContains('/backup/db-2026-06-19_00-00.zip', $kept);
    }

    public function testSameNumberInDaysModeKeepsFourTimesMoreFiles(): void
    {
        $now = new DateTimeImmutable('2026-06-20 18:30:00');
        $files = $this->dumps($now, 10);

        // Stejná sedmička, jiná jednotka — a rozdíl je čtyřnásobný.
        $daysPolicy = BackupRetentionPolicy::fromConfig(new Config(['cron' => ['backup' => [
            'retention_profile'      => BackupRetentionPolicy::PROFILE_MANAGED,
            'retention_mode'         => BackupRetentionPolicy::MODE_DAYS,
            'daily_retention_days'   => 7,
            'monthly_retention_days' => 0,
        ]]]));

        self::assertSame(28, $daysPolicy->expectedDailyFiles(self::RUNS_PER_DAY));

        $kept = array_values(array_diff(array_keys($files), $daysPolicy->purgeList($files, $now)));
        self::assertCount(28, $kept, '„7 dní" znamená při 4×/den 28 souborů — čtyřnásobek kvóty proti 7 kusům.');
    }

    public function testDefaultProfileIsUnchangedThirtyDaysAndYearlyMonthlies(): void
    {
        $policy = BackupRetentionPolicy::named(BackupRetentionPolicy::PROFILE_DEFAULT);

        self::assertSame(BackupRetentionPolicy::MODE_DAYS, $policy->mode);
        self::assertSame(30, $policy->daily);
        self::assertSame(365, $policy->monthly);

        $now = new DateTimeImmutable('2026-06-20 12:00:00');
        $files = [
            '/backup/db-2026-06-19.zip' => new DateTimeImmutable('2026-06-19 02:00:00'),
            '/backup/db-2026-04-15.zip' => new DateTimeImmutable('2026-04-15 02:00:00'), // >30 dnů, není 1.
            '/backup/db-2026-01-01.zip' => new DateTimeImmutable('2026-01-01 02:00:00'), // 1. v měsíci, <365 dnů
            '/backup/db-2024-01-01.zip' => new DateTimeImmutable('2024-01-01 02:00:00'), // 1. v měsíci, >365 dnů
        ];

        self::assertSame(
            ['/backup/db-2026-04-15.zip', '/backup/db-2024-01-01.zip'],
            $policy->purgeList($files, $now),
        );
    }

    public function testZeroMonthlyMeansNoneNotUnlimited(): void
    {
        // Obrácený význam nuly („drž všechno") je přesně ta chyba, kterou H-05
        // řeší — u profilu managed by tím v kvótě zůstal každý první v měsíci
        // navždycky.
        $now = new DateTimeImmutable('2026-06-20 12:00:00');
        $files = [
            '/backup/db-2026-06-01_00-00.zip' => new DateTimeImmutable('2026-06-01 00:00:00'),
            '/backup/db-2026-05-01_00-00.zip' => new DateTimeImmutable('2026-05-01 00:00:00'),
            '/backup/db-2026-06-20_18-00.zip' => new DateTimeImmutable('2026-06-20 18:00:00'),
        ];

        $config = static fn (int $monthly): BackupRetentionPolicy => BackupRetentionPolicy::fromConfig(
            new Config(['cron' => ['backup' => [
                'retention_mode'           => BackupRetentionPolicy::MODE_COPIES,
                'daily_retention_copies'   => 1,
                'monthly_retention_copies' => $monthly,
            ]]])
        );

        // monthly = 0 → první v měsíci NEMÁ žádnou zvláštní ochranu a spadne
        // do stejné fronty jako ostatní: zbude jediný nejnovější soubor.
        self::assertSame(
            ['/backup/db-2026-06-01_00-00.zip', '/backup/db-2026-05-01_00-00.zip'],
            $config(0)->purgeList($files, $now),
        );

        // monthly = 1 → jeden „první v měsíci" se drží navíc. Kdyby nula
        // znamenala „drž všechno", byly by oba výsledky stejné.
        self::assertSame(['/backup/db-2026-05-01_00-00.zip'], $config(1)->purgeList($files, $now));
    }

    public function testExplicitConfigKeysOverrideTheProfile(): void
    {
        // Instalace, která si retenci nastavila ručně, se profilem nesmí přepsat.
        $policy = BackupRetentionPolicy::fromConfig(new Config(['cron' => ['backup' => [
            'retention_profile'       => BackupRetentionPolicy::PROFILE_MANAGED,
            'daily_retention_copies'  => 3,
            'monthly_retention_copies' => 2,
        ]]]));

        self::assertSame(BackupRetentionPolicy::MODE_COPIES, $policy->mode);
        self::assertSame(3, $policy->daily);
        self::assertSame(2, $policy->monthly);
        self::assertStringContainsString('ks', $policy->describe());
    }

    public function testDescribeAlwaysNamesTheUnit(): void
    {
        // Jednotka musí být v logu vidět, ne se odvozovat z profilu.
        self::assertStringContainsString(
            'dnů',
            BackupRetentionPolicy::named(BackupRetentionPolicy::PROFILE_DEFAULT)->describe(),
        );
        self::assertStringContainsString(
            'ks',
            BackupRetentionPolicy::named(BackupRetentionPolicy::PROFILE_MANAGED)->describe(),
        );
    }
}
