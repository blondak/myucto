<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

/**
 * Volby jednoho běhu převodu.
 */
final class ImportOptions
{
    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_IMPORT = 'import';

    /**
     * @param list<string> $relatedPartyIcos IČO partnerů, které se v adresáři označí jako spřízněné osoby
     * @param array<int,string> $moneyReports rok => cesta k obratové předvaze vyexportované z Money (CSV)
     * @param bool $confirmedIco uživatel výslovně potvrdil, že záloha patří firmě, i když to IČO ověřit nejde
     * @param int|null $fromYear první převáděný účetní rok; starší roky zálohy (a jejich doklady) se vynechají
     */
    public function __construct(
        public readonly string $mode = self::MODE_DRY_RUN,
        public readonly bool $closeHistory = true,
        public readonly ?string $firstPeriodStart = null,
        public readonly array $relatedPartyIcos = [],
        public readonly array $moneyReports = [],
        public readonly bool $confirmedIco = false,
        public readonly ?int $fromYear = null,
    ) {
        if (!in_array($mode, [self::MODE_DRY_RUN, self::MODE_IMPORT], true)) {
            throw new MoneyS3Exception('invalid_mode', 'Neznámý režim převodu.');
        }
        if ($firstPeriodStart !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstPeriodStart) !== 1) {
            throw new MoneyS3Exception('invalid_first_period_start', 'Začátek prvního účetního období čeká datum RRRR-MM-DD.');
        }
        if ($fromYear !== null && ($fromYear < 1990 || $fromYear > 2100)) {
            throw new MoneyS3Exception('invalid_from_year', 'První převáděný rok musí být mezi lety 1990 a 2100.');
        }
    }

    public function isDryRun(): bool
    {
        return $this->mode === self::MODE_DRY_RUN;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'close_history' => $this->closeHistory,
            'first_period_start' => $this->firstPeriodStart,
            'related_party_icos' => $this->relatedPartyIcos,
            'money_reports' => array_map('intval', array_keys($this->moneyReports)),
            'confirmed_ico' => $this->confirmedIco,
            'from_year' => $this->fromYear,
        ];
    }
}
