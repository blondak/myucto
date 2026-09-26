<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Travel;

/**
 * Vypořádání vyúčtované pracovní cesty proti poskytnuté záloze (§ 183 ZP).
 *
 * Jediné místo, kde se z nároku, jeho daňového rozpadu a zálohy odvozuje, co
 * půjde do mzdy, co do pokladny a co zaměstnanec vrací. Čte ho promítnutí do
 * mzdy, účetní zápis vypořádání pokladnou i náhled ve formuláři cesty, aby se
 * tři místa nemohla rozejít.
 *
 * Záloha se vždy započte nejdřív proti NEZDANĚNÉ části. Zdanitelná část je
 * příjmem ze závislé činnosti v plné výši bez ohledu na to, kolik z ní pokryla
 * záloha — do základu daně i pojistného musí vstoupit celá, a to umí jen mzda.
 */
final readonly class BusinessTripSettlement
{
    public const MODE_PAYROLL = 'payroll';
    public const MODE_CASH = 'cash';
    public const MODES = [self::MODE_PAYROLL, self::MODE_CASH];

    private function __construct(
        public string $mode,
        public int $entitlementMinor,
        public int $exemptMinor,
        public int $taxableMinor,
        public int $advanceMinor,
        /** Nezdaněná část, která jde do mzdy (v režimu pokladny nula). */
        public int $payrollExemptMinor,
        /** Kolik zálohy se odečte ve mzdě (kladné číslo). */
        public int $payrollAdvanceOffsetMinor,
        /** Doplatek zaměstnanci z pokladny. */
        public int $cashPayoutMinor,
        /** Přeplatek zálohy, který zaměstnanec vrací. */
        public int $employeeRefundMinor,
    ) {}

    public static function calculate(
        string $mode,
        int $exemptMinor,
        int $taxableMinor,
        int $advanceMinor,
    ): self {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Způsob vypořádání zálohy není podporovaný.');
        }
        if ($exemptMinor < 0 || $taxableMinor < 0 || $advanceMinor < 0) {
            throw new \InvalidArgumentException('Částky vyúčtování cesty nesmí být záporné.');
        }
        $entitlement = $exemptMinor + $taxableMinor;
        if ($mode === self::MODE_PAYROLL) {
            $offset = min($advanceMinor, $entitlement);

            return new self(
                $mode,
                $entitlement,
                $exemptMinor,
                $taxableMinor,
                $advanceMinor,
                $exemptMinor,
                $offset,
                0,
                $advanceMinor - $offset,
            );
        }

        return new self(
            $mode,
            $entitlement,
            $exemptMinor,
            $taxableMinor,
            $advanceMinor,
            0,
            0,
            max(0, $exemptMinor - $advanceMinor),
            max(0, $advanceMinor - $exemptMinor),
        );
    }

    /** @param array<string,mixed> $trip uložená schválená cesta */
    public static function fromTrip(array $trip): self
    {
        $mode = $trip['advance_settlement'] ?? self::MODE_PAYROLL;

        return self::calculate(
            is_string($mode) ? $mode : self::MODE_PAYROLL,
            (int) ($trip['exempt_total_minor'] ?? 0),
            (int) ($trip['taxable_total_minor'] ?? 0),
            (int) ($trip['advance_minor'] ?? 0),
        );
    }

    /** Čistý dopad vyúčtování do výplaty (před daní ze zdanitelné části). */
    public function payrollNetMinor(): int
    {
        return $this->payrollExemptMinor + $this->taxableMinor - $this->payrollAdvanceOffsetMinor;
    }

    /** @return array<string,int|string> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'entitlement_minor' => $this->entitlementMinor,
            'exempt_minor' => $this->exemptMinor,
            'taxable_minor' => $this->taxableMinor,
            'advance_minor' => $this->advanceMinor,
            'payroll_exempt_minor' => $this->payrollExemptMinor,
            'payroll_advance_offset_minor' => $this->payrollAdvanceOffsetMinor,
            'payroll_net_minor' => $this->payrollNetMinor(),
            'cash_payout_minor' => $this->cashPayoutMinor,
            'employee_refund_minor' => $this->employeeRefundMinor,
        ];
    }
}
