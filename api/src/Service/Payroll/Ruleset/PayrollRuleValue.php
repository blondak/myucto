<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\Money;

final readonly class PayrollRuleValue
{
    private function __construct(
        public string $type,
        public string|int|bool $value,
        public PayrollRulesetCapability $capability,
        public ?string $note,
    ) {}

    public static function rate(string $value): self
    {
        return new self(
            'decimal_rate',
            DecimalRate::fromString($value)->toCanonicalString(),
            PayrollRulesetCapability::Supported,
            null,
        );
    }

    public static function moneyMinor(int $minorUnits): self
    {
        $money = Money::fromMinorUnits($minorUnits);

        return new self('money_minor', $money->minorUnits, PayrollRulesetCapability::Supported, null);
    }

    public static function integer(int $value): self
    {
        return new self('integer', $value, PayrollRulesetCapability::Supported, null);
    }

    public static function text(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Ruleset text values cannot be empty.');
        }

        return new self('text', $value, PayrollRulesetCapability::Supported, null);
    }

    public static function boolean(bool $value): self
    {
        return new self('boolean', $value, PayrollRulesetCapability::Supported, null);
    }

    public static function manualReview(string $reason): self
    {
        if ($reason === '') {
            throw new InvalidArgumentException('Manual-review rules must explain why calculation is blocked.');
        }

        return new self('manual_review', $reason, PayrollRulesetCapability::ManualReview, $reason);
    }

    /** @return array{capability:string,note:?string,type:string,value:bool|int|string} */
    public function toCanonicalArray(): array
    {
        return [
            'capability' => $this->capability->value,
            'note' => $this->note,
            'type' => $this->type,
            'value' => $this->value,
        ];
    }

    public function assertCalculationReady(string $parameter): void
    {
        if ($this->capability !== PayrollRulesetCapability::Supported) {
            throw new PayrollRulesetException(
                "Payroll ruleset parameter {$parameter} requires manual review: {$this->note}",
            );
        }
    }
}
