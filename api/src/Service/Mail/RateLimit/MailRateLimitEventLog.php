<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\RateLimit;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Config\Config;
use PDO;
use Throwable;

/**
 * Trvalý záznam událostí brzdy + upozornění správci instance (H-16, bod 4 a 5).
 *
 * Hosting posílá `instance.mail_limit_warning` (90 %) a
 * `instance.mail_limit_reached` na NÁŠ PRODEJNÍ WEB — do instance se ty
 * události nedostanou. Aplikace se tedy o svém limitu nikdy nedozví zvenčí
 * a musí si stejný stav odvodit sama. Aby šlo obojí porovnat, ukládá se to
 * ve stejném tvaru a pod stejnými jmény událostí; rozdíl mezi naším řádkem
 * a jejich webhookem je pak přímo vidět, místo aby se hádal z logu.
 *
 * Upozornění jde e-mailem MIMO brzdu (jinak by se první zpráva o zablokovaném
 * odesílání sama zablokovala) a je zastropované odstupem
 * `smtp.rate_limit.alert_cooldown_minutes` — jednou za hodinu je informace,
 * po každé zprávě je to další zátěž na tutéž kvótu.
 */
final class MailRateLimitEventLog
{
    public const DEFAULT_COOLDOWN_MINUTES = 60;

    /** @var null|callable(string,string,string):bool */
    private $notifier;

    /**
     * @param null|callable(string $to, string $subject, string $body): bool $notifier
     *        Odeslání upozornění MIMO brzdu. Null = jen se zaznamená.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        ?callable $notifier = null,
    ) {
        $this->notifier = $notifier;
    }

    public function record(string $event, DateTimeImmutable $now, MailRateLimitDecision $decision): void
    {
        $notifiedAt = null;
        if ($this->shouldNotify($event, $now)) {
            $notifiedAt = $this->notify($event, $now, $decision) ? $now : null;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO mail_rate_limit_events
                    (occurred_at, event, window_name, sent_last_hour, sent_last_day,
                     limit_hour, limit_day, percent, over_limit_action, notified_at)
                 VALUES (:occurred_at, :event, :window_name, :sent_last_hour, :sent_last_day,
                     :limit_hour, :limit_day, :percent, :over_limit_action, :notified_at)'
            );
            $stmt->execute([
                'occurred_at'       => $now->format(MailSendCounter::SQL_FORMAT),
                'event'             => $event,
                'window_name'       => $decision->window ?? MailRateLimitWindow::HOUR,
                'sent_last_hour'    => $decision->sentLastHour,
                'sent_last_day'     => $decision->sentLastDay,
                'limit_hour'        => $decision->limitHour,
                'limit_day'         => $decision->limitDay,
                'percent'           => $decision->percent,
                'over_limit_action' => MailRateLimitDecision::ACTION_DEFERRED,
                'notified_at'       => $notifiedAt?->format(MailSendCounter::SQL_FORMAT),
            ]);
        } catch (Throwable) {
            // Diagnostika nesmí shodit odesílání pošty.
        }
    }

    private function shouldNotify(string $event, DateTimeImmutable $now): bool
    {
        if ($this->notifier === null || $this->alertEmail() === '') {
            return false;
        }

        $cooldown = max(1, (int) $this->config->get(
            'smtp.rate_limit.alert_cooldown_minutes',
            self::DEFAULT_COOLDOWN_MINUTES,
        ));

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM mail_rate_limit_events
                  WHERE event = :event AND notified_at IS NOT NULL AND notified_at > :since'
            );
            $stmt->execute([
                'event' => $event,
                'since' => $now->modify('-' . $cooldown . ' minutes')->format(MailSendCounter::SQL_FORMAT),
            ]);

            return ((int) $stmt->fetchColumn()) === 0;
        } catch (Throwable) {
            // Bez tabulky (instalace před migrací) upozornění raději pošli —
            // ztracené varování je horší než jedno navíc.
            return true;
        }
    }

    private function notify(string $event, DateTimeImmutable $now, MailRateLimitDecision $decision): bool
    {
        $notifier = $this->notifier;
        if ($notifier === null) {
            return false;
        }

        $reached = $event === MailRateLimiter::EVENT_REACHED;
        $subject = $reached
            ? 'MyÚčto.cz — odesílání e-mailů je pozastaveno (limit hostingu)'
            : 'MyÚčto.cz — odesílání e-mailů se blíží limitu hostingu';

        $body = implode("\n", array_filter([
            $reached
                ? 'Odesílání e-mailů z této instalace je DOČASNĚ POZASTAVENO, protože se naplnil limit odchozí pošty.'
                : 'Odesílání e-mailů z této instalace se blíží limitu odchozí pošty a brzy se zpomalí.',
            '',
            sprintf('Za poslední hodinu: %d z %d zpráv', $decision->sentLastHour, $decision->limitHour),
            sprintf('Za posledních 24 hodin: %d z %d zpráv', $decision->sentLastDay, $decision->limitDay),
            sprintf('Naplnění rozhodujícího okna (%s): %.1f %%', $decision->window ?? '?', $decision->percent),
            $decision->retryAt !== null
                ? sprintf('Odložené zprávy odejdou nejdřív v %s.', $decision->retryAt->format('d.m.Y H:i'))
                : null,
            '',
            'Limity počítají ZPRÁVY, ne příjemce, a obě okna jsou klouzavá — půlnoc je nenuluje.',
            'Žádná zpráva se neztratila: co se nevešlo, čeká ve frontě a odejde samo.',
            '',
            sprintf('Čas události: %s', $now->format('d.m.Y H:i:s')),
        ], static fn ($line) => $line !== null));

        try {
            return (bool) $notifier($this->alertEmail(), $subject, $body);
        } catch (Throwable) {
            return false;
        }
    }

    private function alertEmail(): string
    {
        $email = trim((string) $this->config->get('smtp.rate_limit.alert_email', ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
