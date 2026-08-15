<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Výsledek jednoho volání {@see SubmissionChannel::send()}.
 *
 * Konstruktor je private a jediná cesta k instanci vede přes pojmenované
 * továrny — kanál tak nemůže vrátit „odesláno" bez identifikátoru zprávy ani
 * „odmítnuto" bez kódu chyby. Nevědomost má vlastní továrnu ({@see uncertain()}),
 * aby ji nešlo omylem vyjádřit jako selhání.
 */
final readonly class DispatchResult
{
    private function __construct(
        public DispatchState $state,
        public ?string $externalMessageId,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}

    /** Kanál zprávu přijal a vrátil její identifikátor. */
    public static function sent(string $externalMessageId): self
    {
        if (trim($externalMessageId) === '') {
            throw new \InvalidArgumentException('Odeslání musí vrátit identifikátor zprávy.');
        }
        return new self(DispatchState::Sent, trim($externalMessageId), null, null);
    }

    /**
     * Volání se přerušilo a nevíme, jestli zpráva odešla. Tohle NENÍ chyba —
     * je to stav, ze kterého se musí dohledat pravda ({@see SubmissionChannel::probe()}).
     */
    public static function uncertain(string $errorCode, string $errorMessage): self
    {
        return new self(DispatchState::SendUncertain, null, $errorCode, $errorMessage);
    }

    /** Kanál zprávu prokazatelně nepřijal — je bezpečné poslat ji znovu. */
    public static function failed(string $errorCode, string $errorMessage): self
    {
        return new self(DispatchState::Failed, null, $errorCode, $errorMessage);
    }
}
