<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

/**
 * Doložený záměr uplatňovat slevu tak, jak ho vede ČSSZ v evidenci podle
 * § 23f odst. 2 a 3 (systém ZAMERY_SLEV).
 *
 * Tři data, tři různé role — proto to není jedno pole „oznámeno dne":
 *   * `intentFrom` = ZAMER_OD, den, od kterého záměr platí,
 *   * `intentTo`   = ZAMER_DO, den skončení; `null` znamená „záměr trvá",
 *   * `acceptedOn` = DATUM_PRIJETI_FORMULARE, den DORUČENÍ oznámení ČSSZ,
 *     na kterém podle § 7a odst. 5 stojí nárok.
 */
final readonly class OzuspojIntentEvidence
{
    public function __construct(
        public OzuspojIntentStatus $status,
        public string $intentFrom,
        public ?string $intentTo,
        public ?string $acceptedOn,
    ) {}

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): ?self
    {
        $status = is_string($row['status'] ?? null)
            ? OzuspojIntentStatus::tryFrom($row['status'])
            : null;
        $from = $row['intent_from'] ?? null;
        if ($status === null || !is_string($from) || $from === '') {
            return null;
        }
        $to = $row['intent_to'] ?? null;
        $accepted = $row['accepted_on'] ?? null;

        return new self(
            $status,
            $from,
            is_string($to) && $to !== '' ? $to : null,
            is_string($accepted) && $accepted !== '' ? $accepted : null,
        );
    }

    /** @return array{status:string,intent_from:string,intent_to:?string,accepted_on:?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'intent_from' => $this->intentFrom,
            'intent_to' => $this->intentTo,
            'accepted_on' => $this->acceptedOn,
        ];
    }
}
