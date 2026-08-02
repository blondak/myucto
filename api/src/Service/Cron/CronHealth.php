<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

/**
 * Vyhodnocení zdraví jedné plánované úlohy z jejího heartbeatu.
 *
 * Vytaženo z {@see \MyInvoice\Action\Admin\CronJobsAction} hlavně kvůli režimu
 * DISPATCHER, kde „poslední běh" už není spolehlivý sám o sobě:
 *
 * Dispatcher úlohu s pracovní bránou ({@see CronDispatcher::gatedScripts()})
 * vůbec nespustí, když pro ni není práce. Nevznikne tedy žádný tick, heartbeat
 * stárne a úloha se rozsvítí jako `overdue`, přestože je všechno v pořádku —
 * `cron-epo-status` (max_age 1 h) a `cron-ai-worker` (2 h) tak v klidné instalaci
 * svítily varovně prakticky pořád. V režimu INDIVIDUAL se tytéž úlohy spustily
 * naprázdno a zapsaly `noop` tick, takže byly OK.
 *
 * Řešení: u gatovaných úloh se v režimu dispatcheru za důkaz života bere
 * heartbeat SAMOTNÉHO dispatcheru — běží-li on, běží i plánování těch úloh a
 * jejich ticho je záměr, ne výpadek. Výsledkem je stav {@see self::IDLE}.
 *
 * Relaxace se schválně týká JEN gatovaných úloh: ostatní dispatcher spouští vždy,
 * když jsou na řadě, takže jejich heartbeat se pohybovat MUSÍ a jeho stárnutí je
 * pořád platný poplach (například proces, který zemře dřív, než stihne cokoli
 * zapsat). A chybu ({@see self::FAILING}) nepřebíjí nikdy nic — ta se hlásí
 * v obou režimech stejně.
 */
final class CronHealth
{
    /** Poslední úspěšný běh je v limitu `max_age_hours`. */
    public const OK = 'ok';
    /** Poslední úspěšný běh je starší, než by měl být. */
    public const OVERDUE = 'overdue';
    /** Poslední běh skončil chybou. */
    public const FAILING = 'failing';
    public const OVERDUE_AND_FAILING = 'overdue_and_failing';
    /** Žádný běh v historii — nejspíš není naplánováno. */
    public const NEVER_RAN = 'never_ran';
    /** Dispatcher žije, ale úloha nemá co dělat, tak ji nespouští. */
    public const IDLE = 'idle';

    /** Zdroj, ze kterého stav vychází — kvůli srozumitelnému tooltipu v UI. */
    public const SOURCE_SELF = 'self';
    public const SOURCE_DISPATCHER = 'dispatcher';

    /**
     * @param int|null $ageSecSinceOk Stáří posledního úspěšného (i prázdného) běhu; null = nikdy neběželo.
     * @param string|null $lastStatus Stav posledního doběhu: ok | noop | error | null.
     * @param int $maxAgeSec Limit z katalogu (`max_age_hours` × 3600).
     * @param bool $dispatcherGated Spouští tuhle úlohu dispatcher jen když má práci?
     * @param bool $dispatcherAlive Žije sám dispatcher (jeho vlastní heartbeat je čerstvý)?
     *
     * @return array{0:string,1:string} [health, source]
     */
    public static function evaluate(
        ?int $ageSecSinceOk,
        ?string $lastStatus,
        int $maxAgeSec,
        bool $dispatcherGated = false,
        bool $dispatcherAlive = false,
    ): array {
        $health = self::NEVER_RAN;
        if ($ageSecSinceOk !== null) {
            $health = $ageSecSinceOk > $maxAgeSec ? self::OVERDUE : self::OK;
        }

        // Chyba posledního běhu má přednost před vším ostatním — i před relaxací
        // níž. Když úloha spadla, není „nečinná", ale rozbitá.
        if ($lastStatus === 'error') {
            return [
                $health === self::OK || $health === self::NEVER_RAN
                    ? self::FAILING
                    : self::OVERDUE_AND_FAILING,
                self::SOURCE_SELF,
            ];
        }

        if ($dispatcherGated && $dispatcherAlive && ($health === self::OVERDUE || $health === self::NEVER_RAN)) {
            return [self::IDLE, self::SOURCE_DISPATCHER];
        }

        return [$health, self::SOURCE_SELF];
    }

    /**
     * Je dispatcher naživu? Bere se jeho vlastní heartbeat — `last_ok_at` posouvá
     * i prázdný tick, takže čerstvá hodnota znamená „plánovací smyčka běží".
     *
     * Tick, který skončil chybou (nepodařilo se spustit proces), se za důkaz
     * života NEPOVAŽUJE: právě tehdy může být ticho podřízené úlohy způsobené
     * selhaným spuštěním, a to se maskovat nesmí.
     *
     * @param array<string,mixed>|null $heartbeat řádek z `cron_heartbeat` pro `cron-dispatch`
     */
    public static function isDispatcherAlive(?array $heartbeat, int $maxAgeSec, int $now): bool
    {
        if ($heartbeat === null || ($heartbeat['last_status'] ?? null) === 'error') {
            return false;
        }
        $lastOkAt = $heartbeat['last_ok_at'] ?? null;
        if ($lastOkAt === null) {
            return false;
        }
        $ts = strtotime((string) $lastOkAt);
        if ($ts === false) {
            return false;
        }
        return ($now - $ts) <= $maxAgeSec;
    }
}
