<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\RateLimit;

use DateTimeImmutable;

/**
 * Výsledek dotazu brzdy „smí tahle zpráva teď odejít?".
 *
 * Tvar polí kopíruje payload webhooků hostingu (`sent_last_hour`,
 * `sent_last_day`, `limit_hour`, `limit_day`, `percent`, `window`,
 * `over_limit_action`), aby šlo naše rozhodnutí položit vedle jejich události
 * a hned vidět, jestli se stavy nerozešly.
 */
final class MailRateLimitDecision
{
    public const ACTION_DEFERRED = 'deferred';

    private function __construct(
        public readonly bool $allowed,
        public readonly bool $warning,
        public readonly int $sentLastHour,
        public readonly int $sentLastDay,
        public readonly int $limitHour,
        public readonly int $limitDay,
        public readonly float $percent,
        public readonly ?string $window,
        public readonly ?DateTimeImmutable $retryAt,
    ) {}

    public static function allow(
        int $sentLastHour,
        int $sentLastDay,
        int $limitHour,
        int $limitDay,
        float $percent,
        bool $warning,
        ?string $window,
    ): self {
        return new self(true, $warning, $sentLastHour, $sentLastDay, $limitHour, $limitDay, $percent, $window, null);
    }

    public static function defer(
        int $sentLastHour,
        int $sentLastDay,
        int $limitHour,
        int $limitDay,
        float $percent,
        string $window,
        DateTimeImmutable $retryAt,
    ): self {
        return new self(false, true, $sentLastHour, $sentLastDay, $limitHour, $limitDay, $percent, $window, $retryAt);
    }

    public function isDeferred(): bool
    {
        return !$this->allowed;
    }

    /**
     * Odpověď, kterou by na naši zprávu dal hosting. Vracíme ji volajícímu
     * místo výjimky, protože sémanticky je to totéž, co by dostal od jejich
     * MTA — dočasné odmítnutí, zpráva ve frontě, doručení později.
     */
    public function smtpResponse(): string
    {
        return sprintf(
            '451 4.7.1 Odloženo lokální brzdou (%s: %d/%d) — ve frontě, odejde v %s',
            $this->window ?? MailRateLimitWindow::HOUR,
            $this->window === MailRateLimitWindow::DAY ? $this->sentLastDay : $this->sentLastHour,
            $this->window === MailRateLimitWindow::DAY ? $this->limitDay : $this->limitHour,
            $this->retryAt?->format('Y-m-d H:i:s') ?? '?',
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'sent_last_hour'    => $this->sentLastHour,
            'sent_last_day'     => $this->sentLastDay,
            'limit_hour'        => $this->limitHour,
            'limit_day'         => $this->limitDay,
            'percent'           => $this->percent,
            'window'            => $this->window,
            'over_limit_action' => self::ACTION_DEFERRED,
        ];
    }
}
