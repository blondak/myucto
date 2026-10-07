<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

/**
 * Snížení náhrady mzdy při DPN.
 *
 * - `half_192_4`: § 192 odst. 4 ZP. Zaměstnavatel náhradu sníží na polovinu, jde-li
 *   o případy § 31 zák. č. 187/2006 Sb. (rvačka, opilost, návykové látky, úmyslný
 *   trestný čin nebo přestupek). Povinné, podíl je pevný.
 * - `reduced_192_5`: § 192 odst. 5 ZP. Porušení režimu dočasně práce neschopného
 *   v prvních 14 dnech; zaměstnavatel smí náhradu snížit nebo neposkytnout. Výši
 *   určuje on, proto podílem (bazické body, 10 000 = neposkytnout) nebo částkou.
 *
 * Polovina i podíl se počítají z přesného čitatele náhrady PŘED zaokrouhlením na celé
 * koruny ({@see SicknessCompensationCalculator}); zaokrouhlit nejdřív a pak dělit by
 * zaměstnanci ukrojilo až korunu.
 *
 * Pole „příčina DPN" v NEMPRI neexistuje, snížení proto zůstává jen v mzdové evidenci
 * a ve výši náhrady (JMHZ 10342).
 */
final readonly class SicknessCompensationReduction
{
    public const NONE = 'none';
    public const HALF = 'half_192_4';
    public const DISCRETIONARY = 'reduced_192_5';

    public const HALF_BASIS_POINTS = 5_000;

    private function __construct(
        public string $kind,
        public ?int $basisPoints,
        public ?int $amountMinor,
        public ?string $reason,
    ) {}

    public static function none(): self
    {
        return new self(self::NONE, null, null, null);
    }

    public static function half(string $reason): self
    {
        return new self(self::HALF, self::HALF_BASIS_POINTS, null, self::reason($reason));
    }

    public static function byShare(int $basisPoints, string $reason): self
    {
        if ($basisPoints < 1 || $basisPoints > 10_000) {
            throw new \InvalidArgumentException('Snížení náhrady musí být od 0,01 do 100 %.');
        }

        return new self(self::DISCRETIONARY, $basisPoints, null, self::reason($reason));
    }

    public static function byAmount(int $amountMinor, string $reason): self
    {
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('Snížení náhrady částkou musí být kladné.');
        }

        return new self(self::DISCRETIONARY, null, $amountMinor, self::reason($reason));
    }

    public function isNone(): bool
    {
        return $this->kind === self::NONE;
    }

    /** Podíl náhrady, který zůstává, v bazických bodech; `null` u snížení částkou. */
    public function keptBasisPoints(): ?int
    {
        if ($this->isNone()) {
            return 10_000;
        }

        return $this->basisPoints === null ? null : 10_000 - $this->basisPoints;
    }

    /** @return array<string,int|string> */
    public function trace(): array
    {
        return array_filter([
            'reduction' => $this->kind,
            'reduction_basis_points' => $this->basisPoints,
            'reduction_minor' => $this->amountMinor,
            'reduction_reason' => $this->reason,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('U snížení náhrady mzdy uveďte důvod.');
        }
        if (mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('Důvod snížení náhrady může mít nejvýš 500 znaků.');
        }

        return $reason;
    }
}
