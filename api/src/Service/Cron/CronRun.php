<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use PDO;
use Throwable;

/**
 * Heartbeat + log běhů pro api/bin/cron-*.php.
 *
 * Použití se nemění:
 *
 *   $run = CronRun::start($pdo, 'cron-send-reminders');
 *   // ... práce ...
 *   $run->finish('ok', ['sent' => 5, 'errors' => 0]);
 *
 * Co se změnilo (migrace 1183): zápisy jdou do DVOU tabulek s různou rolí.
 *
 *   cron_heartbeat — jeden řádek na skript, upsert při KAŽDÉM doběhu. Odsud čte
 *                    UI zdraví. Konstantní velikost, žádné mazání.
 *   cron_runs      — jen běhy, které něco udělaly nebo selhaly. Skutečná historie.
 *
 * ⚠️ `start()` schválně NIC nezapisuje. Dřív zapisoval řádek 'running' hned na
 * začátku, takže skript, který za 20 ms zjistil, že nemá co dělat, stihl vyrobit
 * INSERT i UPDATE. Cenou za to je, že tvrdě zabitý proces (SIGKILL, OOM) po sobě
 * nenechá žádnou stopu — pozná se až tím, že heartbeat zestárne přes `max_age_hours`.
 * To je lepší kompromis než původní stav, kdy po sobě nechal řádek 'running'
 * navždy viset a UI ho hlásilo jako běžící.
 *
 * Skončí-li skript výjimkou nebo `exit(1)` před voláním finish(), dopíše stav
 * shutdown handler.
 */
final class CronRun
{
    /**
     * Klíče reportu, které samy o sobě NEZNAMENAJÍ vykonanou práci — nesou jen
     * důvod nebo režim běhu. Platí to ale POUZE pro řetězcové a boolean hodnoty:
     * `['skipped' => 'inbox_dir not configured']` je vysvětlení, kdežto
     * `['skipped' => 5]` je počet přeskočených položek, tedy výsledek práce.
     * Číselné hodnoty se proto ignorují vždy jen když jsou nulové.
     */
    private const NON_WORK_KEYS = [
        'skipped', 'skipped_reason', 'reason', 'gate', 'mode', 'dry_run',
        'environment', 'inbox_dir', 'note', 'host',
    ];

    private bool $finished = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $script,
        private readonly float $startedAt,
        private readonly string $startedAtSql,
    ) {}

    public static function start(PDO $pdo, string $script): self
    {
        $run = new self($pdo, $script, microtime(true), date('Y-m-d H:i:s'));

        // Pokud skript skončí výjimkou / exit() / fatal error bez explicitního finish(),
        // tenhle handler dopíše error stav, aby UI vidělo selhání a ne jen ticho.
        register_shutdown_function(function () use ($run) {
            if ($run->isFinished()) {
                return;
            }
            $err = error_get_last();
            $msg = null;
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                $msg = sprintf('%s in %s:%d', (string) $err['message'], (string) $err['file'], (int) $err['line']);
            }
            $run->finish('error', null, $msg, 1);
        });

        return $run;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * @param 'ok'|'error' $status
     * @param array<string,mixed>|null $report
     * @param bool|null $didWork Explicitní přebití heuristiky {@see reportIndicatesWork()}.
     *                           `false` = „doběhlo v pořádku, ale nebylo co dělat"
     *                           (do cron_runs se nezapíše), `true` = vždy zalogovat.
     */
    public function finish(
        string $status,
        ?array $report = null,
        ?string $message = null,
        ?int $exitCode = null,
        ?bool $didWork = null,
    ): void {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        $durationMs = (int) ((microtime(true) - $this->startedAt) * 1000);
        if ($exitCode === null) {
            $exitCode = $status === 'ok' ? 0 : 1;
        }

        $effective = $status === 'error'
            ? 'error'
            : (($didWork ?? self::reportIndicatesWork($report)) ? 'ok' : 'noop');

        $host = (string) (gethostname() ?: '');
        $host = $host !== '' ? substr($host, 0, 100) : null;
        $message = $message !== null ? mb_substr($message, 0, 2000) : null;
        $reportJson = $report !== null
            ? json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        // Diagnostika je best-effort — selhání zápisu nesmí shodit samotnou úlohu,
        // která už svou práci odvedla.
        try {
            $this->writeHeartbeat($effective, $durationMs, $exitCode, $host, $message, $reportJson);
            if ($effective !== 'noop') {
                $this->writeRunLog($effective, $durationMs, $exitCode, $host, $message, $reportJson);
            }
        } catch (Throwable $e) {
            // STDERR nemusí existovat mimo CLI SAPI (spawn z UI pod php-cgi/FastCGI).
            $stderr = defined('STDERR') ? STDERR : fopen('php://stderr', 'wb');
            if ($stderr !== false) {
                fwrite($stderr, 'CronRun::finish failed: ' . $e->getMessage() . "\n");
            }
        }
    }

    private function writeHeartbeat(
        string $status,
        int $durationMs,
        int $exitCode,
        ?string $host,
        ?string $message,
        ?string $reportJson,
    ): void {
        // last_ok_at posouvá i 'noop' — prázdný tick je důkaz, že cron žije.
        // last_work_at posouvá jen 'ok', ať jde v UI odlišit „běží naprázdno".
        $stmt = $this->pdo->prepare(
            "INSERT INTO cron_heartbeat
                    (script, last_tick_at, last_status, last_started_at, last_finished_at,
                     last_duration_ms, last_exit_code, last_host, last_message, last_report,
                     last_ok_at, last_work_at, noop_ticks)
             VALUES (?, NOW(), ?, ?, NOW(), ?, ?, ?, ?, ?,
                     IF(? IN ('ok','noop'), NOW(), NULL),
                     IF(? = 'ok', NOW(), NULL),
                     IF(? = 'noop', 1, 0))
             ON DUPLICATE KEY UPDATE
                    last_tick_at     = NOW(),
                    last_status      = VALUES(last_status),
                    last_started_at  = VALUES(last_started_at),
                    last_finished_at = NOW(),
                    last_duration_ms = VALUES(last_duration_ms),
                    last_exit_code   = VALUES(last_exit_code),
                    last_host        = VALUES(last_host),
                    last_message     = VALUES(last_message),
                    last_report      = VALUES(last_report),
                    last_ok_at       = COALESCE(VALUES(last_ok_at), cron_heartbeat.last_ok_at),
                    last_work_at     = COALESCE(VALUES(last_work_at), cron_heartbeat.last_work_at),
                    noop_ticks       = cron_heartbeat.noop_ticks + VALUES(noop_ticks)"
        );
        $stmt->execute([
            $this->script,
            $status,
            $this->startedAtSql,
            $durationMs,
            $exitCode,
            $host,
            $message,
            $reportJson,
            $status,
            $status,
            $status,
        ]);
    }

    private function writeRunLog(
        string $status,
        int $durationMs,
        int $exitCode,
        ?string $host,
        ?string $message,
        ?string $reportJson,
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO cron_runs
                    (script, started_at, finished_at, status, duration_ms, exit_code, host, message, report)
             VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $this->script,
            $this->startedAtSql,
            $status,
            $durationMs,
            $exitCode,
            $host,
            $message,
            $reportJson,
        ]);
    }

    /**
     * Odhadne z reportu, jestli běh něco udělal, nebo jen zjistil, že nemá co dělat.
     *
     * Pravidla (v tomhle pořadí):
     *   - chybějící report → považujeme za práci (raději zalogovat víc než ztratit stopu),
     *   - jakékoli nenulové číslo kdekoli v reportu → práce,
     *   - neprázdné pole → rekurze,
     *   - neprázdný řetězec / true pod klíčem MIMO {@see NON_WORK_KEYS} → práce.
     *
     * Skript, kterému heuristika nevyhovuje, si výsledek přebije parametrem
     * `$didWork` u finish().
     *
     * @param array<string,mixed>|null $report
     */
    public static function reportIndicatesWork(?array $report): bool
    {
        if ($report === null) {
            return true;
        }
        foreach ($report as $key => $value) {
            if (is_int($value) || is_float($value)) {
                if ($value != 0) {
                    return true;
                }
                continue;
            }
            if (is_array($value)) {
                if ($value !== [] && self::reportIndicatesWork($value)) {
                    return true;
                }
                continue;
            }
            if (in_array((string) $key, self::NON_WORK_KEYS, true)) {
                continue;
            }
            if (is_string($value)) {
                if (trim($value) !== '') {
                    return true;
                }
                continue;
            }
            if ($value === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Smaže staré záznamy: drží max `$keep` posledních běhů na skript.
     * Volá se z cron-cleanup.
     *
     * Od migrace 1183 je v cron_runs jen skutečná práce, takže tenhle purge
     * má výrazně méně co dělat — dřív mazal hlavně prázdné ticky.
     */
    public static function purgeOld(PDO $pdo, int $keep = 500): int
    {
        // MariaDB neumí LIMIT v DELETE … IN, takže přes virtuální subquery.
        $stmt = $pdo->prepare(
            "DELETE r FROM cron_runs r
              JOIN (
                SELECT id FROM (
                  SELECT id, script,
                         ROW_NUMBER() OVER (PARTITION BY script ORDER BY id DESC) AS rn
                    FROM cron_runs
                ) ranked
                WHERE rn > ?
              ) old ON old.id = r.id"
        );
        $stmt->execute([$keep]);
        return $stmt->rowCount();
    }
}
