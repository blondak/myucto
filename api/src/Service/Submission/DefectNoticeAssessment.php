<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission;

/** Vyhodnocení jedné výzvy — co z ní plyne pro stav podání. */
final readonly class DefectNoticeAssessment
{
    public function __construct(
        public DefectNoticeStatus $status,
        public DefectConsequence $consequence,
        public DefectNoticeOutcome $outcome,
        /** Konec náhradní lhůty po případném posunu podle § 33 odst. 4 DŘ. */
        public ?\DateTimeImmutable $respondBy,
        /** `stated_in_notice`, `derived_from_days` nebo `unknown`. */
        public string $respondBySource,
        public bool $respondByShifted,
        /** Kolik dnů zbývá; NULL, když termín neznáme. */
        public ?int $daysLeft,
        /** Věta pro člověka — co přesně platí a co s tím. */
        public string $sentence,
        /** Lhůta ve výzvě je kratší, než § 32 odst. 2 DŘ považuje za běžné. */
        public bool $suspiciouslyShortPeriod = false,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'consequence' => $this->consequence->value,
            'outcome' => $this->outcome->value,
            'respond_by_on' => $this->respondBy?->format('Y-m-d'),
            'respond_by_source' => $this->respondBySource,
            'respond_by_shifted' => $this->respondByShifted,
            'days_left' => $this->daysLeft,
            'sentence' => $this->sentence,
            'suspiciously_short_period' => $this->suspiciouslyShortPeriod,
            'needs_attention' => $this->status->needsAttention(),
        ];
    }
}
