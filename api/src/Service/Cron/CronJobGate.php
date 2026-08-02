<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use Throwable;

/**
 * Rozhoduje, které úlohy z {@see CronCatalog} mají u téhle instalace smysl.
 *
 * Jeden zdroj pravdy pro dva konzumenty, které se dřív rozcházely:
 *   - UI „Systém → Plánované úlohy" (CronJobsAction) — co vůbec zobrazit,
 *   - generátor crontabu pro Docker (DockerCrontabGenerator) — co vůbec plánovat.
 *
 * ⚠️ Ty dvě otázky NEJSOU stejné, proto má třída dvě metody:
 *
 *   isVisibleInUi()  — smí se ptát i na podmínky, které se mění za běhu
 *                      (opt-in AI u dodavatele). UI se čte na každý request,
 *                      takže vidí aktuální stav.
 *
 *   isSchedulable()  — smí se ptát JEN na podmínky stabilní přes restart
 *                      kontejneru, protože crontab se generuje jednou při startu.
 *                      Kdyby sem spadl opt-in AI, admin by ho zapnul v UI a cron
 *                      by se rozjel až po restartu — tiše, bez jakéhokoli signálu.
 *                      Dynamické podmínky patří do {@see CronPreflight}, který
 *                      běží uvnitř skriptu při každém ticku.
 *
 * Brána je záměrně **fail-open**: když se nedá přečíst config nebo databáze,
 * úloha zůstane naplánovaná. Nedostupná DB při startu kontejneru nesmí vést
 * k tomu, že se cron tiše vypne a nikdo si toho měsíc nevšimne.
 */
final class CronJobGate
{
    private ?bool $aiOptInCache = null;

    public function __construct(
        private readonly Config $config,
        private readonly ?PDO $pdo = null,
    ) {}

    /**
     * Má se úloha objevit v UI přehledu? Zahrnuje i dynamické podmínky.
     *
     * Položka dispatcheru se ukazuje jen v režimu DISPATCHER — v default režimu
     * neběží, takže by v přehledu navždy visela jako „nikdy neběželo".
     * Jednotlivé úlohy se naopak ukazují v obou režimech: i pod dispatcherem
     * pořád běží (jen je spouští on) a jejich zdraví je stejně důležité.
     *
     * @param array<string,mixed> $job
     */
    public function isVisibleInUi(array $job, string $mode = CronScheduleMode::INDIVIDUAL): bool
    {
        if (($job['dispatcher_only'] ?? false) === true) {
            return $mode === CronScheduleMode::DISPATCHER;
        }
        if (!$this->isSchedulable($job)) {
            return false;
        }
        if (($job['requires_ai_opt_in'] ?? false) === true) {
            return $this->anySupplierHasAiOptIn();
        }
        return true;
    }

    /**
     * Má se úloha dostat do crontabu? Jen restart-stabilní podmínky.
     *
     * @param array<string,mixed> $job
     */
    public function isSchedulable(array $job): bool
    {
        $key = $job['requires_config'] ?? null;
        if ($key === null) {
            return true;
        }
        try {
            $path = trim((string) $this->config->get((string) $key, ''));
        } catch (Throwable) {
            return true; // fail-open
        }
        return $path !== '' && is_dir($path);
    }

    /**
     * Úlohy, které se mají dostat do crontabu pro daný režim plánování.
     *
     * V režimu DISPATCHER je to JEN položka dispatcheru — jednotlivé úlohy si
     * pak spouští on sám. Kdyby tu zůstaly i ony, běžely by dvakrát.
     *
     * @param string $mode {@see CronScheduleMode}
     * @return list<array<string,mixed>>
     */
    public function schedulableJobs(string $mode = CronScheduleMode::INDIVIDUAL): array
    {
        $dispatcherMode = $mode === CronScheduleMode::DISPATCHER;

        return array_values(array_filter(
            CronCatalog::all(),
            function (array $job) use ($dispatcherMode): bool {
                $isDispatcher = ($job['dispatcher_only'] ?? false) === true;
                if ($isDispatcher !== $dispatcherMode) {
                    return false;
                }
                // Dispatcher sám žádný `requires_config` nemá a mít nesmí —
                // musí se naplánovat vždy, protože gating dělá až on uvnitř.
                return $isDispatcher || $this->isSchedulable($job);
            },
        ));
    }

    private function anySupplierHasAiOptIn(): bool
    {
        if ($this->aiOptInCache !== null) {
            return $this->aiOptInCache;
        }
        if ($this->pdo === null) {
            return $this->aiOptInCache = true; // fail-open
        }
        try {
            $stmt = $this->pdo->query('SELECT 1 FROM supplier WHERE ai_assist_enabled=1 LIMIT 1');
            return $this->aiOptInCache = ($stmt !== false && $stmt->fetchColumn() !== false);
        } catch (Throwable) {
            return $this->aiOptInCache = true; // fail-open
        }
    }
}
