<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronScheduleMode;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use PDO;
use Throwable;

/**
 * Souhrnná provozní diagnostika pro veřejný `/api/health` (H-09).
 *
 * Bez fleet API je health JEDINÝ kanál, kterým se dozvíme, co na flotile běží.
 * Proto sem patří přesně to, co provozovatel monitoruje: údržba, rozpracovaná
 * práce, čerstvost cronu, stáří zálohy, verze a stav migrací.
 *
 * DVĚ tvrdá pravidla:
 *
 * 1. **Endpoint je veřejný, takže ven jde jen SOUHRN.** Čísla, stáří a booleany
 *    ano; jména skriptů, seznamy nedoběhlých migrací, cesty, chybové hlášky ani
 *    hostname NE. Diagnostika s detaily je za autentizací
 *    ({@see EnvironmentCheckService}).
 * 2. **Nic tady nesmí vyhodit výjimku.** Health musí odpovědět i na instalaci
 *    bez migrací a s nedostupnou databází — právě tehdy je nejvíc potřeba.
 *    Každý dílčí sběr proto degraduje na `null`.
 *
 * ⚠️ Čerstvost cronu se čte z DATABÁZE, ne z logu. Hosting náš wrapper
 * `cmd/cron-dispatch.sh` obchází (má víc verzí PHP a `php` z PATH není ta
 * správná), takže `log/cron/dispatch-*.log` na jejich instanci vůbec nevznikne.
 * Zdrojem pravdy je `cron_heartbeat`, který zapisuje sám skript přes
 * {@see \MyInvoice\Service\Cron\CronRun}.
 */
final class InstanceHealthProbe
{
    /** Dispatcher běží každou minutu; pět minut ticha je už výpadek. */
    public const DISPATCHER_MAX_AGE_SEC = 300;

    /** Shodně s `max_age_hours` úlohy `cron-backup` v katalogu (36 h). */
    public const BACKUP_MAX_AGE_SEC = 36 * 3600;

    /**
     * Jak daleko zpět se hledají nedoběhlé úlohy. Musí odpovídat retenci
     * `cron_dispatch_claims` — {@see \MyInvoice\Service\Cron\CronDispatcher}
     * je promazává po dvou hodinách, takže starší claim už neexistuje a delší
     * okno by jen předstíralo přesnost.
     */
    public const RUNNING_JOB_WINDOW_SEC = 2 * 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly MaintenanceLock $lock,
        private readonly AppUrlConfiguration $appUrl,
        private readonly TenantDomainFeature $domains,
        private readonly EnvironmentCheckService $environment,
    ) {}

    /**
     * @return array{
     *     maintenance:bool,
     *     jobs:array{running:int|null},
     *     cron:array{mode:string|null,dispatcher_age_sec:int|null,dispatcher_fresh:bool|null,dispatcher_status:string|null,last_tick_age_sec:int|null},
     *     backup:array{age_sec:int|null,fresh:bool|null},
     *     migrations:array{applied:int|null,pending:int|null,up_to_date:bool|null}
     * }
     */
    public function summary(): array
    {
        return [
            'maintenance' => $this->lock->isActive(),
            'jobs'        => ['running' => $this->runningJobs()],
            'cron'        => $this->cron(),
            'backup'      => $this->backup(),
            'migrations'  => $this->migrations(),
        ];
    }

    /**
     * Tvar, který health vrátí, když se probe nepodařilo sestavit vůbec.
     * Klíče musí existovat vždy — monitoring nesmí rozlišovat „chybí klíč"
     * a „neznámá hodnota".
     *
     * @return array<string,mixed>
     */
    public static function unavailableSummary(): array
    {
        return [
            'maintenance' => false,
            'jobs'        => ['running' => null],
            'cron'        => [
                'mode' => null,
                'dispatcher_age_sec' => null,
                'dispatcher_fresh' => null,
                'dispatcher_status' => null,
                'last_tick_age_sec' => null,
            ],
            'backup'      => ['age_sec' => null, 'fresh' => null],
            'migrations'  => ['applied' => null, 'pending' => null, 'up_to_date' => null],
        ];
    }

    /**
     * Kolik úloh je právě rozpracovaných. Teprve nula znamená „je bezpečné
     * nasazovat" — provozovatel podle toho vyčká na doběhnutí.
     *
     * Skládá se ze dvou nezávislých zdrojů:
     *
     *  - **cron**: nárokovaná minuta ({@see \MyInvoice\Service\Cron\CronDispatcher})
     *    bez novějšího doběhu v `cron_heartbeat`. Dispatcher zapisuje claim při
     *    spuštění procesu, CronRun zapisuje `last_finished_at` při jeho konci —
     *    rozdíl těch dvou razítek je tedy přesně „běží". Platí to jen v režimu
     *    DISPATCHER; v režimu INDIVIDUAL spouští úlohy crontab a claim nevzniká.
     *  - **frontové úlohy** v DB (AI, importy, backfill účetnictví), které si
     *    stav `running` drží samy.
     *
     * `null` = nedostupná databáze, tedy „nevím" — nikdy se nezaměňuje s nulou.
     */
    public function runningJobs(): ?int
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return null;
        }

        $total = 0;
        $known = false;

        $cron = $this->scalarInt(
            $pdo,
            'SELECT COUNT(DISTINCT c.script)
               FROM cron_dispatch_claims c
               LEFT JOIN cron_heartbeat h ON h.script = c.script
              WHERE c.claimed_at >= (NOW() - INTERVAL ? SECOND)
                AND (h.last_finished_at IS NULL OR h.last_finished_at < c.claimed_at)',
            [self::RUNNING_JOB_WINDOW_SEC],
            ['cron_dispatch_claims', 'cron_heartbeat'],
        );
        if ($cron !== null) {
            $total += $cron;
            $known = true;
        }

        foreach (['ai_jobs', 'import_jobs', 'accounting_backfill_jobs'] as $table) {
            $count = $this->scalarInt(
                $pdo,
                "SELECT COUNT(*) FROM {$table} WHERE status = 'running'",
                [],
                [$table],
            );
            if ($count !== null) {
                $total += $count;
                $known = true;
            }
        }

        return $known ? $total : null;
    }

    /**
     * @return array{mode:string|null,dispatcher_age_sec:int|null,dispatcher_fresh:bool|null,dispatcher_status:string|null,last_tick_age_sec:int|null}
     */
    public function cron(): array
    {
        $pdo = $this->pdo();
        if ($pdo === null || !$this->hasTable('cron_heartbeat')) {
            return [
                'mode' => null,
                'dispatcher_age_sec' => null,
                'dispatcher_fresh' => null,
                'dispatcher_status' => null,
                'last_tick_age_sec' => null,
            ];
        }

        $mode = CronScheduleMode::current($pdo);
        $row = $this->heartbeat($pdo, CronScheduleMode::DISPATCHER_SCRIPT);
        $age = $row === null ? null : self::ageSec($row['last_tick_at'] ?? null);

        // V režimu INDIVIDUAL dispatcher záměrně neběží — hlásit ho jako mrtvý
        // by byl falešný poplach. Čerstvost cronu se tam pozná z nejnovějšího
        // ticku jakékoli úlohy.
        $fresh = $mode === CronScheduleMode::DISPATCHER
            ? ($age !== null && $age <= self::DISPATCHER_MAX_AGE_SEC)
            : null;

        return [
            'mode' => $mode,
            'dispatcher_age_sec' => $age,
            'dispatcher_fresh' => $fresh,
            'dispatcher_status' => isset($row['last_status']) ? (string) $row['last_status'] : null,
            'last_tick_age_sec' => $this->scalarInt(
                $pdo,
                'SELECT TIMESTAMPDIFF(SECOND, MAX(last_tick_at), NOW()) FROM cron_heartbeat',
                [],
                ['cron_heartbeat'],
            ),
        ];
    }

    /**
     * Stáří poslední zálohy. `last_work_at` (ne `last_ok_at`), protože záloha,
     * která doběhla bez vytvoření souboru, není záloha.
     *
     * @return array{age_sec:int|null,fresh:bool|null}
     */
    public function backup(): array
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return ['age_sec' => null, 'fresh' => null];
        }

        $row = $this->heartbeat($pdo, 'cron-backup');
        $age = $row === null ? null : self::ageSec($row['last_work_at'] ?? null);

        return [
            'age_sec' => $age,
            'fresh'   => $age === null ? null : $age <= self::BACKUP_MAX_AGE_SEC,
        ];
    }

    /**
     * Stav migrací — jen počty. Seznam nedoběhlých souborů je detail
     * konfigurace a na veřejný endpoint nepatří.
     *
     * @return array{applied:int|null,pending:int|null,up_to_date:bool|null}
     */
    public function migrations(): array
    {
        try {
            $status = $this->environment->migrationStatus();
        } catch (Throwable) {
            return ['applied' => null, 'pending' => null, 'up_to_date' => null];
        }

        if (($status['available'] ?? false) !== true) {
            return ['applied' => null, 'pending' => null, 'up_to_date' => null];
        }

        $pending = $status['pending_count'] ?? null;

        return [
            'applied'    => isset($status['applied']) ? (int) $status['applied'] : null,
            'pending'    => is_int($pending) ? $pending : null,
            'up_to_date' => is_int($pending) ? $pending === 0 : null,
        ];
    }

    /**
     * Vynucuje se host gate reálně?
     *
     * ⚠️ Prázdné `app.url` ho TIŠE VYPNE (canonical origin je prázdný, takže
     * resolver propustí každý hostname), špatné `app.url` ho naopak zamkne.
     * Selhání je v obou směrech tiché, proto to health hlásí explicitně —
     * jinak se na to přijde až tím, že buď neplatí izolace, nebo nejde nic.
     */
    public function hostGateEnforced(): bool
    {
        return $this->domains->isEnabled() && $this->appUrl->isConfigured();
    }

    /** @return array{managed:bool,managed_provider:string|null} */
    public function managed(): array
    {
        $provider = $this->config->get('app.managed_provider', '');
        $provider = is_string($provider) ? trim($provider) : '';

        return [
            'managed' => filter_var(
                $this->config->get('app.managed', false),
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ) === true,
            'managed_provider' => $provider === '' ? null : $provider,
        ];
    }

    /** @return array<string,mixed>|null */
    private function heartbeat(PDO $pdo, string $script): ?array
    {
        if (!$this->hasTable('cron_heartbeat')) {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT last_status,
                        TIMESTAMPDIFF(SECOND, last_tick_at, NOW()) AS last_tick_at,
                        TIMESTAMPDIFF(SECOND, last_work_at, NOW()) AS last_work_at
                   FROM cron_heartbeat
                  WHERE script = ?'
            );
            $stmt->execute([$script]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<mixed>  $params
     * @param list<string> $requiredTables
     */
    private function scalarInt(PDO $pdo, string $sql, array $params, array $requiredTables): ?int
    {
        foreach ($requiredTables as $table) {
            if (!$this->hasTable($table)) {
                return null;
            }
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();

            return $value === false || $value === null ? null : (int) $value;
        } catch (Throwable) {
            return null;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return $this->db->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function pdo(): ?PDO
    {
        try {
            return $this->db->pdo();
        } catch (Throwable) {
            return null;
        }
    }

    /** Záporné stáří (hodiny serveru jdou pozpátku) se ořízne na nulu. */
    private static function ageSec(mixed $value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }
}
