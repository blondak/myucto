<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\RateLimit;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Config\Config;
use Psr\Log\LoggerInterface;

/**
 * H-16 — brzda odchozí pošty pod mezí hostingu.
 *
 * Hosting spravované instalace má na instanci limity odchozí pošty
 * (200/hod, 1 000/den) a nad limit odpovídá SMTP 451: zpráva se nezahodí,
 * zůstane v jeho frontě a odejde později. Nic se tedy neztratí — ale
 * zákazník s dávkou upomínek se o problému dozví až z nedoručených faktur.
 * Brzdíme proto dřív než oni, s rezervou danou konfigurací.
 *
 * ČTYŘI VĚCI, KTERÉ SE NESMÍ ROZEJÍT S JEJICH POČÍTADLEM:
 *
 *  1. **Jednotka je ZPRÁVA, ne příjemce.** Faktura rozeslaná padesáti
 *     odběratelům v jedné zprávě je jedno odeslání. Kdybychom počítali řádky
 *     `To:`, brzdili bychom pětkrát dřív, než je potřeba.
 *  2. **Okna jsou KLOUZAVÁ.** Půlnoc počítadlo hostingu nenuluje — viz
 *     {@see MailRateLimitWindow}.
 *  3. **Strop 100 příjemců na zprávu je TVRDÝ.** Nad něj je odmítnutí trvalé
 *     a fronta nepomůže; dělí {@see MailRecipientBatcher}.
 *  4. **Nad limit se odkládá, nezahazuje** — `over_limit_action = deferred`.
 *
 * Prahy jsou konfigurovatelné (`smtp.rate_limit.*`), protože limity hostingu
 * jsou smluvní údaj, ne konstanta aplikace. Výchozí hodnoty leží pod jejich
 * mezí, aby zbyla rezerva na souběh procesů: rozhodnutí a zápis do počítadla
 * nejsou jedna transakce, takže dva cronové běhy najednou můžou limit o pár
 * zpráv přestřelit. Rezerva 20 % je levnější než zámek přes celé odeslání.
 */
final class MailRateLimiter
{
    /** Limity hostingu — proti nim se poměřuje, jestli je konfigurace ještě pod jejich mezí. */
    public const HOSTING_LIMIT_HOUR = 200;
    public const HOSTING_LIMIT_DAY  = 1000;

    /** Výchozí prahy: 80 % meze hostingu, tj. rezerva na souběh a na jejich vlastní počítání. */
    public const DEFAULT_LIMIT_HOUR = 160;
    public const DEFAULT_LIMIT_DAY  = 800;

    public const DEFAULT_WARN_PERCENT = 90.0;

    /** Události v tomtéž tvaru, v jakém je posílá hosting na prodejní web. */
    public const EVENT_WARNING = 'instance.mail_limit_warning';
    public const EVENT_REACHED = 'instance.mail_limit_reached';

    public function __construct(
        private readonly Config $config,
        private readonly MailSendCounterInterface $counter,
        private readonly LoggerInterface $logger,
        private readonly ?MailRateLimitEventLog $events = null,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('smtp.rate_limit.enabled', true);
    }

    public function limitHour(): int
    {
        return max(1, (int) $this->config->get('smtp.rate_limit.per_hour', self::DEFAULT_LIMIT_HOUR));
    }

    public function limitDay(): int
    {
        return max(1, (int) $this->config->get('smtp.rate_limit.per_day', self::DEFAULT_LIMIT_DAY));
    }

    public function warnPercent(): float
    {
        $value = (float) $this->config->get('smtp.rate_limit.warn_percent', self::DEFAULT_WARN_PERCENT);

        return $value > 0.0 && $value <= 100.0 ? $value : self::DEFAULT_WARN_PERCENT;
    }

    public function maxRecipientsPerMessage(): int
    {
        return MailRecipientBatcher::clamp(
            (int) $this->config->get(
                'smtp.rate_limit.max_recipients_per_message',
                MailRecipientBatcher::HARD_MAX_RECIPIENTS,
            )
        );
    }

    /**
     * Konfigurace, která si nastaví práh NAD mez hostingu, brzdu fakticky
     * vypíná — 451 pak přijde od nich. Není to důvod odesílání zastavit, ale
     * musí to být vidět v logu, protože jinak se to pozná až podle fronty.
     *
     * @return list<string>
     */
    public function configurationWarnings(): array
    {
        $warnings = [];
        if ($this->limitHour() > self::HOSTING_LIMIT_HOUR) {
            $warnings[] = sprintf(
                'smtp.rate_limit.per_hour = %d je nad mezí hostingu (%d) — brzda nesepne dřív než jejich 451.',
                $this->limitHour(),
                self::HOSTING_LIMIT_HOUR,
            );
        }
        if ($this->limitDay() > self::HOSTING_LIMIT_DAY) {
            $warnings[] = sprintf(
                'smtp.rate_limit.per_day = %d je nad mezí hostingu (%d) — brzda nesepne dřív než jejich 451.',
                $this->limitDay(),
                self::HOSTING_LIMIT_DAY,
            );
        }

        return $warnings;
    }

    /**
     * Smí teď odejít JEDNA zpráva?
     *
     * Volá se jednou na zprávu, ne na příjemce — po rozdělení dávky
     * {@see MailRecipientBatcher} se tedy zeptá tolikrát, kolik zpráv
     * z dávky vzniklo. Přesně tak to počítá i hosting.
     */
    public function decide(DateTimeImmutable $now): MailRateLimitDecision
    {
        $limitHour = $this->limitHour();
        $limitDay  = $this->limitDay();

        $hourStart = MailRateLimitWindow::start($now, MailRateLimitWindow::HOUR);
        $dayStart  = MailRateLimitWindow::start($now, MailRateLimitWindow::DAY);

        $sentHour = $this->counter->sentSince($hourStart);
        $sentDay  = $this->counter->sentSince($dayStart);

        $pctHour = $limitHour > 0 ? ($sentHour / $limitHour) * 100.0 : 0.0;
        $pctDay  = $limitDay > 0 ? ($sentDay / $limitDay) * 100.0 : 0.0;

        // Rozhoduje TĚSNĚJŠÍ okno. Kdyby se rozhodovalo jen podle hodiny,
        // denní limit by se přetekl v poslední hodině dne; kdyby jen podle
        // dne, hodinový by se přetekl hned na začátku dávky.
        $window  = $pctDay >= $pctHour ? MailRateLimitWindow::DAY : MailRateLimitWindow::HOUR;
        $percent = round(max($pctHour, $pctDay), 1);

        if (!$this->enabled()) {
            return MailRateLimitDecision::allow($sentHour, $sentDay, $limitHour, $limitDay, $percent, false, $window);
        }

        $blocking = null;
        if ($sentDay >= $limitDay) {
            $blocking = MailRateLimitWindow::DAY;
        } elseif ($sentHour >= $limitHour) {
            $blocking = MailRateLimitWindow::HOUR;
        }

        if ($blocking !== null) {
            $retryAt = $this->retryAt($now, $blocking);
            $decision = MailRateLimitDecision::defer(
                $sentHour,
                $sentDay,
                $limitHour,
                $limitDay,
                $percent,
                $blocking,
                $retryAt,
            );
            $this->announce(self::EVENT_REACHED, $now, $decision);

            return $decision;
        }

        $warning = $percent >= $this->warnPercent();
        $decision = MailRateLimitDecision::allow(
            $sentHour,
            $sentDay,
            $limitHour,
            $limitDay,
            $percent,
            $warning,
            $window,
        );
        if ($warning) {
            $this->announce(self::EVENT_WARNING, $now, $decision);
        }

        return $decision;
    }

    /** Zaznamenej JEDNU odeslanou zprávu (ne jednoho příjemce). */
    public function recordSent(
        DateTimeImmutable $now,
        int $recipients,
        string $template,
        ?string $emailProfile = null,
    ): void {
        $this->counter->record($now, $recipients, $template, $emailProfile);
    }

    /**
     * Kdy se okno prokazatelně uvolní. Počítá se z NEJSTARŠÍHO odeslání
     * v okně — klouzavé okno se uvolňuje po jedné zprávě, ne skokem, takže
     * paušální „zkus to za čtvrt hodiny" by frontu buď zdržel, nebo poslal
     * rovnou do dalšího odmítnutí.
     */
    private function retryAt(DateTimeImmutable $now, string $window): DateTimeImmutable
    {
        $oldest = $this->counter->oldestSince(MailRateLimitWindow::start($now, $window));
        if ($oldest !== null) {
            $freesAt = MailRateLimitWindow::freesAt($oldest, $window);
            if ($freesAt > $now) {
                return $freesAt;
            }
        }

        $fallback = max(60, (int) $this->config->get('smtp.rate_limit.defer_retry_seconds', 900));

        return $now->modify('+' . $fallback . ' seconds');
    }

    /**
     * Upozornění správci instance. Zákazník se musí dozvědět, že se odesílání
     * zpomalilo, dřív než mu někdo zavolá, že nedostal fakturu.
     *
     * Do logu jde VŽDY (a s uvedením okna, které rozhodlo — z logu musí být
     * poznat, PROČ se brzdilo). Trvalý záznam a e-mail obstará
     * {@see MailRateLimitEventLog}, který si sám hlídá odstup mezi
     * upozorněními, aby se z brzdy nestal spam.
     */
    private function announce(string $event, DateTimeImmutable $now, MailRateLimitDecision $decision): void
    {
        $context = ['event' => $event] + $decision->toArray();
        if ($decision->retryAt !== null) {
            $context['retry_at'] = $decision->retryAt->format('Y-m-d H:i:s');
        }

        $this->logger->warning('mail.rate_limit', $context);
        $this->events?->record($event, $now, $decision);
    }
}
