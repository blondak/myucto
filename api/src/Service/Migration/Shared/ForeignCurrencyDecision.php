<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

/**
 * Výsledek {@see ForeignCurrencyTakeover::decide()} pro jeden doklad: převzít v měně dokladu
 * (`currencyId` + `rate`), nebo v Kč jako dřív (`reason` říká proč; doklad v Kč nemá důvod).
 */
final class ForeignCurrencyDecision
{
    private function __construct(
        public readonly string $currency,
        public readonly ?int $currencyId,
        public readonly ?float $rate,
        public readonly ?string $reason,
    ) {}

    /** Doklad v domácí měně - převod se ho netýká. */
    public static function home(): self
    {
        return new self('CZK', null, null, null);
    }

    public static function foreign(string $currency, int $currencyId, float $rate): self
    {
        return new self($currency, $currencyId, $rate, null);
    }

    /** Doklad v cizí měně, který pojistka nepustila - převezme se v Kč. */
    public static function keptInHome(string $currency, string $reason): self
    {
        return new self($currency, null, null, $reason);
    }

    public function inForeignCurrency(): bool
    {
        return $this->currencyId !== null;
    }

    /** Doklad byl v cizí měně (převzatý v ní, nebo v Kč s důvodem). */
    public function isForeignDocument(): bool
    {
        return $this->currency !== 'CZK';
    }

    /**
     * Věta do poznámky dokladu (bez oddělovače), `null` = doklad v Kč. `$homeWording` je
     * dosavadní text zdroje pro doklad převzatý v Kč („převzat v Kč", „převzat v Kč podle
     * zaúčtování").
     */
    public function note(string $homeWording = 'převzat v Kč'): ?string
    {
        if (!$this->isForeignDocument()) {
            return null;
        }
        if ($this->inForeignCurrency()) {
            return 'doklad v ' . $this->currency . ', převzat v měně dokladu kurzem '
                . rtrim(rtrim(number_format((float) $this->rate, 6, ',', ''), '0'), ',') . ' Kč';
        }
        return 'doklad v ' . $this->currency . ', ' . $homeWording . ' (' . $this->reason . ')';
    }
}
