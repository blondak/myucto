<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Co kanál ví o už odeslaném podání.
 *
 * Obě osy zvlášť — a `acceptance` smí být jiné než `Unknown` jen s uvedeným
 * druhem důkazu. Vynucuje to konstruktor, takže kanál nemůže „přijato" vrátit
 * jen tak; a {@see \MyInvoice\Service\Submission\SubmissionOutboxService} navíc
 * ověřuje, že kanál na takový důkaz vůbec má ({@see ChannelEvidenceStrength}).
 * Dvojitá pojistka je tu schválně: první hlídá tvar, druhá oprávnění.
 */
final readonly class ChannelStatus
{
    public function __construct(
        public DispatchState $dispatch,
        public AcceptanceState $acceptance = AcceptanceState::Unknown,
        public ?AcceptanceEvidence $evidence = null,
        public ?\DateTimeImmutable $deliveredAt = null,
        public ?\DateTimeImmutable $decidedAt = null,
        public ?string $note = null,
    ) {
        if ($this->acceptance === AcceptanceState::Unknown && $this->evidence !== null) {
            throw new \InvalidArgumentException('Neznámé vyřízení nemá co dokládat.');
        }
        if ($this->acceptance !== AcceptanceState::Unknown && $this->evidence === null) {
            throw new \InvalidArgumentException('Vyřízení bez druhu důkazu je tvrzení bez podkladu.');
        }
        if ($this->dispatch === DispatchState::Delivered && $this->deliveredAt === null) {
            throw new \InvalidArgumentException('Doručení musí nést čas doručení.');
        }
    }

    /**
     * Doručeno — a nic víc. Pojmenovaná továrna existuje proto, aby nejčastější
     * odpověď datové schránky měla jedno místo, kde je vidět, že osu vyřízení
     * nechává na `Unknown`.
     */
    public static function deliveredOnly(\DateTimeImmutable $deliveredAt, ?string $note = null): self
    {
        return new self(DispatchState::Delivered, AcceptanceState::Unknown, null, $deliveredAt, null, $note);
    }

    /** Odesláno, zatím nic dalšího nevíme. */
    public static function sentOnly(?string $note = null): self
    {
        return new self(DispatchState::Sent, AcceptanceState::Unknown, null, null, null, $note);
    }
}
