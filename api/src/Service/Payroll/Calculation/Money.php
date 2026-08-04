<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

final readonly class Money implements JsonSerializable
{
    public function __construct(
        public int $minorUnits,
        public string $currency = 'CZK',
    ) {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be a canonical three-letter ISO code.');
        }
    }

    public static function fromMinorUnits(int $minorUnits, string $currency = 'CZK'): self
    {
        return new self($minorUnits, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        if (
            ($other->minorUnits > 0 && $this->minorUnits > PHP_INT_MAX - $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits < PHP_INT_MIN - $other->minorUnits)
        ) {
            throw new OverflowException('Money addition exceeds the integer range.');
        }

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if (
            ($other->minorUnits > 0 && $this->minorUnits < PHP_INT_MIN + $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits > PHP_INT_MAX + $other->minorUnits)
        ) {
            throw new OverflowException('Money subtraction exceeds the integer range.');
        }

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function negate(): self
    {
        if ($this->minorUnits === PHP_INT_MIN) {
            throw new OverflowException('Money negation exceeds the integer range.');
        }

        return new self(-$this->minorUnits, $this->currency);
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    /** @return array{currency:string,minor_units:int} */
    public function jsonSerialize(): array
    {
        return [
            'currency' => $this->currency,
            'minor_units' => $this->minorUnits,
        ];
    }

    public function toCanonicalJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Money values must use the same currency.');
        }
    }
}
