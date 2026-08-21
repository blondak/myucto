<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use DateTimeImmutable;
use MyInvoice\Service\Cron\CronExpression;
use Throwable;

/**
 * H-25 — jak často smí běžet `cron-backup`.
 *
 * ZMĚNA: denní dump (`0 2 * * *`) sráží ztrátu dat až na 24 hodin. Rozvrh
 * `0 * / 6 * * *` (4× denně) ji srazí na 6 hodin, což je u účetnictví rozdíl
 * mezi „dopiš si dnešek" a „dopiš si týden".
 *
 * ⚠️ STROP JE SMLUVNÍ, NE TECHNICKÝ. 4× denně je nově maximum, do kterého
 * hosting následuje frekvenci svých vlastních záloh zdarma. Nad něj už ne —
 * pátý dump denně není „o něco víc dat", je to položka, kterou nikdo
 * neodsouhlasil. Proto se rozvrh nad {@see self::MAX_RUNS_PER_DAY} ODMÍTÁ
 * tvrdě, ne varováním: varování se přehlédne a faktura přijde za měsíc.
 *
 * ── Velikost dumpu a dopad na kvótu ────────────────────────────────────────
 * Změřeno na reálné firemní databázi: SQL dump ~42 MB, po kompresi ~20 MB
 * (ratio jen ~48 %, protože dump nese i binární přílohy — u textovějších dat
 * bude výrazně menší). Z toho vychází zabraný prostor:
 *
 *   dnešní default, 1×/den:  30 denních + 12 měsíčních ≈ 42 souborů ≈ 840 MB
 *   4×/den + retence 7 DNŮ:  28 souborů                            ≈ 560 MB
 *   4×/den + retence 7 KUSŮ: 7 souborů                             ≈ 140 MB  ← profil `managed`
 *
 * Čtyřnásobná frekvence tedy sama o sobě kvótu nezvedá — zvedla by ji jen
 * v kombinaci s retencí počítanou ve DNECH. Proto H-25 a H-05 patří k sobě:
 * bez přepnutí retence na KUSY ({@see BackupRetentionPolicy}) by častější
 * dump snědl rezervu, kterou má ušetřit.
 */
final class BackupScheduleLimit
{
    /** Smluvní strop pro `cron-backup`. Zvýšení = dodatek ke smlouvě, ne změna konstanty. */
    public const MAX_RUNS_PER_DAY = 4;

    /** Rozvrh, na který H-25 přechází: 00:00, 06:00, 12:00, 18:00. */
    public const RECOMMENDED_EXPRESSION = '0 */6 * * *';

    /** Úlohy, na které se smluvní strop vztahuje. */
    public const CONTRACTED_SCRIPTS = ['cron-backup'];

    /**
     * Kolikrát daný výraz sedne během JEDNOHO dne.
     *
     * Počítá se prostým průchodem 1 440 minutami, ne rozborem výrazu:
     * {@see CronExpression} je jediný vyhodnocovač v repu a druhá,
     * „chytřejší" implementace by se s ním dřív nebo později rozešla —
     * a rozešla by se tiše, protože obě by v běžných případech daly totéž.
     */
    public static function runsOnDay(string $expression, DateTimeImmutable $day): int
    {
        // Den se skládá v UTC, ne v lokální zóně: na přechodu letního času
        // některá lokální minuta neexistuje a jiná je dvakrát, takže by průchod
        // „dnem" jednou za rok napočítal o hodinu míň nebo víc. Cron výraz
        // ale čte jen Y-m-d H:i a den v týdnu, a ty jsou v UTC stejné.
        $midnight = new DateTimeImmutable($day->format('Y-m-d') . ' 00:00:00', new \DateTimeZone('UTC'));

        $runs = 0;
        for ($hour = 0; $hour < 24; $hour++) {
            for ($minute = 0; $minute < 60; $minute++) {
                if (CronExpression::matches($expression, $midnight->setTime($hour, $minute))) {
                    $runs++;
                }
            }
        }

        return $runs;
    }

    /**
     * Nejhorší den v roce. Výraz může být na většinu dnů neškodný a přestřelit
     * jen prvního v měsíci nebo v pondělí — kontrolovat „dnešek" by takový
     * rozvrh pustil dovnitř a limit by praskl až za týden.
     *
     * Hledá se PRVNÍ den, kdy výraz vůbec sedne, a tím se počítání končí:
     * kolikrát za den výraz sedne, určují výhradně pole minuta a hodina, která
     * jsou pro každý „platný" den stejná. Pole dne/měsíce jen rozhodují, jestli
     * den sedne, nebo ne — nemůžou tedy počet v rámci dne změnit.
     */
    public static function maxRunsPerDay(string $expression, ?DateTimeImmutable $from = null): int
    {
        // Rok pokryje všechny kombinace dne v týdnu, dne v měsíci i měsíce;
        // 2028 je přestupný, takže projde i `0 0 29 2 *`.
        $start = ($from ?? new DateTimeImmutable('2028-01-01'));
        for ($day = 0; $day < 366; $day++) {
            $runs = self::runsOnDay($expression, $start->modify('+' . $day . ' days'));
            if ($runs > 0) {
                return $runs;
            }
        }

        return 0;
    }

    public static function isWithinContract(string $expression): bool
    {
        try {
            $runs = self::maxRunsPerDay($expression);

            return $runs >= 1 && $runs <= self::MAX_RUNS_PER_DAY;
        } catch (Throwable) {
            // Nesrozumitelný výraz není „v pořádku" — viz assert níž.
            return false;
        }
    }

    /**
     * Tvrdá validace rozvrhu zálohy.
     *
     * Pouští se všude, kde se rozvrh ZAPISUJE (migrace, UI, CLI), ne až při
     * běhu zálohy: odmítnout pátý dump ve chvíli, kdy už proběhl, je pozdě.
     *
     * @throws BackupScheduleContractException
     */
    public static function assertWithinContract(string $expression, string $script = 'cron-backup'): void
    {
        try {
            $runs = self::maxRunsPerDay($expression);
        } catch (Throwable $e) {
            throw new BackupScheduleContractException(sprintf(
                "Rozvrh '%s' pro úlohu %s není platný cron výraz: %s",
                $expression,
                $script,
                $e->getMessage(),
            ), 0, $e);
        }

        if ($runs === 0) {
            throw new BackupScheduleContractException(sprintf(
                "Rozvrh '%s' by úlohu %s nespustil NIKDY. Vypnout zálohu jde přes "
                . 'cron.disabled_jobs, ne rozvrhem, který nikdy nesedne.',
                $expression,
                $script,
            ));
        }

        if ($runs > self::MAX_RUNS_PER_DAY) {
            throw new BackupScheduleContractException(sprintf(
                "Rozvrh '%s' by úlohu %s spustil %d× denně. Smluvní strop je %d×/den — "
                . 'nad něj hosting nenásleduje frekvenci svých záloh zdarma. '
                . 'Zvýšení vyžaduje dodatek ke smlouvě, ne změnu konfigurace. '
                . "Doporučený rozvrh: '%s'.",
                $expression,
                $script,
                $runs,
                self::MAX_RUNS_PER_DAY,
                self::RECOMMENDED_EXPRESSION,
            ));
        }
    }

    public static function isContracted(string $script): bool
    {
        return in_array($script, self::CONTRACTED_SCRIPTS, true);
    }
}
