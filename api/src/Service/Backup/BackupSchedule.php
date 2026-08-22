<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup;

use PDO;
use Throwable;

/**
 * Rozvrh úlohy `cron-backup` uložený v databázi (migrace 1521).
 *
 * Rozvrh je v DB, ne v kódu, protože je to provozní údaj konkrétní instalace —
 * self-host si klidně nechá jeden dump denně, spravovaná instalace jede 4×.
 * Kód drží jen STROP ({@see BackupScheduleLimit}), který je smluvní.
 *
 * ⚠️ Zápis vede VÝHRADNĚ přes {@see self::set()}. `runs_per_day` se dopočítá
 * z výrazu, nikdy se nepřebírá od volajícího — jinak by šlo do sloupce s CHECK
 * napsat čtyřku k výrazu `* * * * *` a strop by neplatil.
 */
final class BackupSchedule
{
    public const SCRIPT = 'cron-backup';

    /**
     * @return array{script:string,cron_expr:string,runs_per_day:int,contract_max:int}
     */
    public static function current(?PDO $pdo): array
    {
        $fallback = [
            'script'       => self::SCRIPT,
            'cron_expr'    => BackupScheduleLimit::RECOMMENDED_EXPRESSION,
            'runs_per_day' => BackupScheduleLimit::MAX_RUNS_PER_DAY,
            'contract_max' => BackupScheduleLimit::MAX_RUNS_PER_DAY,
        ];

        if ($pdo === null) {
            return $fallback;
        }

        try {
            $stmt = $pdo->query(
                'SELECT script, cron_expr, runs_per_day, contract_max
                   FROM backup_schedule_contract WHERE id = 1'
            );
            $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return $fallback;
        }

        if (!is_array($row)) {
            return $fallback;
        }

        return [
            'script'       => (string) $row['script'],
            'cron_expr'    => (string) $row['cron_expr'],
            'runs_per_day' => (int) $row['runs_per_day'],
            'contract_max' => (int) $row['contract_max'],
        ];
    }

    /**
     * Ulož rozvrh. Nad smluvní strop se ZAPSAT NEDÁ.
     *
     * @throws BackupScheduleContractException
     */
    public static function set(PDO $pdo, string $cronExpr, ?int $userId = null, string $script = self::SCRIPT): void
    {
        BackupScheduleLimit::assertWithinContract($cronExpr, $script);
        $runsPerDay = BackupScheduleLimit::maxRunsPerDay($cronExpr);

        $stmt = $pdo->prepare(
            'INSERT INTO backup_schedule_contract (id, script, cron_expr, runs_per_day, contract_max, updated_at, updated_by)
             VALUES (1, :script, :cron_expr, :runs_per_day, :contract_max, NOW(), :user_id)
             ON DUPLICATE KEY UPDATE script       = VALUES(script),
                                     cron_expr    = VALUES(cron_expr),
                                     runs_per_day = VALUES(runs_per_day),
                                     updated_at   = NOW(),
                                     updated_by   = VALUES(updated_by)'
        );
        $stmt->execute([
            'script'       => $script,
            'cron_expr'    => $cronExpr,
            'runs_per_day' => $runsPerDay,
            'contract_max' => BackupScheduleLimit::MAX_RUNS_PER_DAY,
            'user_id'      => $userId,
        ]);
    }

    /**
     * Sedí uložený `runs_per_day` na uložený výraz? SQL CHECK to ověřit neumí
     * (cron výraz nerozebere), takže ruční UPDATE může sloupce rozejít.
     * Vrací null když je vše v pořádku, jinak popis nesouladu.
     */
    public static function inconsistency(?PDO $pdo): ?string
    {
        $row = self::current($pdo);
        try {
            $actual = BackupScheduleLimit::maxRunsPerDay($row['cron_expr']);
        } catch (Throwable $e) {
            return sprintf("Uložený rozvrh '%s' není platný cron výraz: %s", $row['cron_expr'], $e->getMessage());
        }

        if ($actual > BackupScheduleLimit::MAX_RUNS_PER_DAY) {
            return sprintf(
                "Uložený rozvrh '%s' spouští zálohu %d× denně, smluvní strop je %d×/den.",
                $row['cron_expr'],
                $actual,
                BackupScheduleLimit::MAX_RUNS_PER_DAY,
            );
        }

        if ($actual !== $row['runs_per_day']) {
            return sprintf(
                "Sloupec runs_per_day (%d) neodpovídá rozvrhu '%s' (%d× denně) — někdo obešel BackupSchedule::set().",
                $row['runs_per_day'],
                $row['cron_expr'],
                $actual,
            );
        }

        return null;
    }
}
