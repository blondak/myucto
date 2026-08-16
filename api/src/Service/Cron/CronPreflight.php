<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

use PDO;
use Throwable;

/**
 * Levná brána „mám vůbec co dělat?" pro často spouštěné cron skripty.
 *
 * Motivace: cron-epo-status běží každou minutu a cron-ai-worker po deseti,
 * ale u typického tenanta nemají 99 % ticků co dělat. Bez brány každý takový
 * tick postaví celý DI kontejner (~200 souborů), otevře DB, zjistí že fronta
 * je prázdná a skončí. Brána to rozhodne jedním indexovaným dotazem nad už
 * otevřeným spojením, ještě než se kontejner vůbec začne stavět.
 *
 * ⚠️ Dotazy tady jsou ZÁMĚRNĚ PERMISIVNĚJŠÍ než ty, které pak frontu opravdu
 * čtou (EpoDirectSubmissionRepository::pollableAttempts, AiJobService::claimBatch).
 * Vynechávají doplňkové podmínky (prostředí, přítomnost credentials, opt-in
 * dodavatele, limit pokusů). Odchylka tak může stát nanejvýš jeden zbytečný
 * bootstrap — nikdy ne zmeškanou práci. Kdyby se brána naopak zpřísnila nad
 * rámec reálného dotazu, tiše by přestala pouštět práci, což je přesně ten
 * druh chyby, který se pozná až po týdnech.
 *
 * Fail-open: jakákoli chyba (chybějící tabulka před migrací, nedostupná DB)
 * znamená „spusť to", ať se diagnostika řeší v samotném skriptu.
 */
final class CronPreflight
{
    /**
     * Je co pollovat u přímých podání EPO?
     *
     * Permisivní protějšek {@see \MyInvoice\Repository\EpoDirectSubmissionRepository::pollableAttempts()}
     * — bez filtru na prostředí, credentials a requested_by.
     */
    public static function hasEpoWork(PDO $pdo): bool
    {
        return self::probe($pdo, "
            SELECT 1 FROM tax_submission_attempts
             WHERE channel = 'epo_direct'
               AND status IN ('processing','confirmed','uncertain')
               AND next_poll_at IS NOT NULL
               AND next_poll_at <= CURRENT_TIMESTAMP
               AND poll_count < 12
             LIMIT 1
        ");
    }

    /**
     * Čeká nějaké mzdové podání na protokol ČSSZ nebo na uzavření transakce?
     *
     * Permisivní protějšek
     * {@see \MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository::listDuePolls()}
     * a `listDueCloses()` — bez podmínky na correlation reference a bez stropu
     * pokusů o uzavření. Odchylka stojí nanejvýš jeden zbytečný bootstrap,
     * nikdy ne zmeškaný protokol.
     */
    public static function hasJmhzTransportWork(PDO $pdo): bool
    {
        return self::probe($pdo, "
            SELECT 1 FROM payroll_submission_transport_attempts
             WHERE (
                     status = 'awaiting_protocol'
                     OR (status = 'completed' AND closed_at IS NULL)
                   )
               AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())
             LIMIT 1
        ");
    }

    /**
     * Je co zpracovat v AI frontě?
     *
     * Permisivní protějšek {@see \MyInvoice\Service\Ai\AiJobService::claimBatch()}.
     * Stav 'running' je ve výběru schválně: claimBatch zároveň recykluje joby,
     * které zůstaly viset déle než 15 minut, a o tu recyklaci se nesmíme připravit.
     */
    public static function hasAiWork(PDO $pdo): bool
    {
        return self::probe($pdo, "
            SELECT 1 FROM ai_jobs
             WHERE status IN ('queued','running')
             LIMIT 1
        ");
    }

    /**
     * Má cron sahat na nějakou datovou schránku?
     *
     * Odpověď je „ano" jen tehdy, když si vybírání schránky někdo VÝSLOVNĚ
     * zapnul. Není to jen úspora — vyzvednutí seznamu je doručení podle
     * § 17 odst. 3 zák. 300/2008 Sb. a rozjíždí lhůty, takže brána tady je
     * poslední místo, kde se dá zabránit tomu, aby aplikace doručovala
     * zprávy někomu, kdo o to nepožádal.
     *
     * `probe()` je fail-open (při chybě vrací true), ale to je tu bezpečné:
     * skutečnou bránu drží
     * {@see \MyInvoice\Service\Submission\SubmissionInboxService::poll()},
     * která bez souhlasu na síť nesáhne. Tohle jen šetří stavbu kontejneru.
     */
    public static function hasDataBoxInboxWork(PDO $pdo): bool
    {
        return self::probe($pdo, "
            SELECT 1 FROM submission_channel_credentials
             WHERE channel = 'isds'
               AND inbox_polling_enabled = 1
             LIMIT 1
        ");
    }

    private static function probe(PDO $pdo, string $sql): bool
    {
        try {
            $stmt = $pdo->query($sql);
            return $stmt === false || $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return true; // fail-open
        }
    }
}
