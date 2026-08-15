<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/**
 * Závěr o doručení jedné zprávy — rozhodný den a čím je podložený.
 *
 * Ukládá se celý, ne jen výsledné datum: bez délky lhůty a jejího zdroje by po
 * změně pravidla nešlo přečíst, jak starý závěr vznikl, a bez
 * {@see $fictionStatutoryOn} vedle {@see $fictionDueOn} by zmizela informace,
 * že se konec lhůty posunul přes víkend nebo svátek.
 */
final readonly class ResolvedDelivery
{
    public function __construct(
        public DeliveryBasis $basis,
        /** Rozhodný den doručení. NULL vždy, když {@see DeliveryBasis::isDelivered()} je false. */
        public ?\DateTimeImmutable $deliveredOn,
        /** Poslední den lhůty podle § 17 odst. 4 — bez posunu na pracovní den. */
        public ?\DateTimeImmutable $fictionStatutoryOn,
        /** Týž den po posunu podle § 33 odst. 4 DŘ. */
        public ?\DateTimeImmutable $fictionDueOn,
        public ?int $fictionDays,
        /** `ruleset` nebo `statute` — odkud délka lhůty přišla. */
        public ?string $fictionDaysSource,
        public ?bool $senderIsPublicAuthority,
        /** Věta pro člověka, proč to takhle vyšlo. */
        public string $note,
    ) {}

    /** Posunul se konec lhůty přes sobotu, neděli nebo svátek? */
    public function fictionShifted(): bool
    {
        return $this->fictionStatutoryOn !== null
            && $this->fictionDueOn !== null
            && $this->fictionStatutoryOn->format('Y-m-d') !== $this->fictionDueOn->format('Y-m-d');
    }

    /**
     * Řádek pro `submission_inbox_messages`. `delivery_resolved_at` doplní
     * volající, protože jen on ví, kdy se závěr zapisuje.
     *
     * @return array{
     *   delivery_basis:string,
     *   delivered_on:?string,
     *   fiction_statutory_on:?string,
     *   fiction_due_on:?string,
     *   fiction_days:?int,
     *   fiction_days_source:?string,
     *   sender_is_public_authority:?int,
     *   delivery_note:string
     * }
     */
    public function toRow(): array
    {
        return [
            'delivery_basis' => $this->basis->value,
            'delivered_on' => $this->deliveredOn?->format('Y-m-d'),
            'fiction_statutory_on' => $this->fictionStatutoryOn?->format('Y-m-d'),
            'fiction_due_on' => $this->fictionDueOn?->format('Y-m-d'),
            'fiction_days' => $this->fictionDays,
            'fiction_days_source' => $this->fictionDaysSource,
            'sender_is_public_authority' => $this->senderIsPublicAuthority === null
                ? null
                : (int) $this->senderIsPublicAuthority,
            'delivery_note' => mb_substr($this->note, 0, 300),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->toRow() + [
            'is_delivered' => $this->basis->isDelivered(),
            'fiction_shifted' => $this->fictionShifted(),
        ];
    }
}
