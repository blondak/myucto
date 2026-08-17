<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Podklad tisku výsledku ročního zúčtování (§ 38ch ZDP).
 *
 * Nese jen to, co na dokladu skutečně je. Přepočet tu nežije ani jako pomocná
 * metoda: čísla přicházejí hotová z `AnnualTaxSettlementCalculator` a šablona
 * je jen vypisuje. Kdyby se tu cokoli dopočítávalo, měl by doklad vlastní
 * pravdu vedle uloženého snapshotu.
 */
final readonly class AnnualSettlementDocumentData
{
    /**
     * @param list<array{label:string,amount_minor_units:int}> $creditRows
     * @param list<array{label:string,months:int,amount_minor_units:int}> $childRows
     */
    public function __construct(
        public string $sourceSnapshotSha256,
        public int $taxYear,
        public string $employerName,
        public string $employerIdentificationNumber,
        public string $employerAddress,
        public string $employeeName,
        public string $personalIdentifierLabel,
        public string $personalIdentifierValue,
        public string $settledOn,
        public int $completedMonths,
        public int $advanceBaseMinorUnits,
        public int $roundedTaxBaseMinorUnits,
        public int $taxBeforeCreditsMinorUnits,
        public array $creditRows,
        public int $appliedCreditsMinorUnits,
        public array $childRows,
        public int $childCreditMinorUnits,
        public int $annualTaxBonusMinorUnits,
        public int $taxAfterAllCreditsMinorUnits,
        public int $advanceTaxMinorUnits,
        public int $monthlyTaxBonusMinorUnits,
        public int $taxDifferenceMinorUnits,
        public int $bonusDifferenceMinorUnits,
        public int $settlementDifferenceMinorUnits,
        public int $payableMinorUnits,
        public string $outcome,
        /**
         * Příspěvek potvrzení od předchozích plátců (§ 38ch odst. 3 a 4).
         *
         * Nese se odděleně, protože doklad musí ukázat, z čeho se ÚHRN skládá.
         * Kdyby se tiskl jen součet, nešlo by z dokladu poznat, že část základu
         * a část záloh je od jiného zaměstnavatele — a přitom právě to je
         * podmínka, za které se zúčtování vůbec smělo provést.
         */
        public int $externalCertificateCount = 0,
        public int $externalAdvanceBaseMinorUnits = 0,
        public int $externalAdvanceTaxMinorUnits = 0,
        public int $externalTaxBonusMinorUnits = 0,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotSha256) !== 1) {
            throw new \InvalidArgumentException(
                'Zdrojový otisk ročního zúčtování není platný.',
            );
        }
        if ($taxYear < 2000 || $taxYear > 2199) {
            throw new \InvalidArgumentException('Rok ročního zúčtování není platný.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $settledOn) !== 1) {
            throw new \InvalidArgumentException('Den zúčtování není platný.');
        }
        if ($completedMonths < 1 || $completedMonths > 12) {
            throw new \InvalidArgumentException(
                'Doklad ročního zúčtování musí stát alespoň na jednom uzavřeném měsíci.',
            );
        }
        foreach ([
            $employerName,
            $employerIdentificationNumber,
            $employerAddress,
            $employeeName,
            $personalIdentifierLabel,
            $personalIdentifierValue,
        ] as $text) {
            if (trim($text) === '' || mb_strlen($text) > 500) {
                throw new \InvalidArgumentException(
                    'Identifikace ročního zúčtování není úplná.',
                );
            }
        }
        // Nedoplatek se poplatníkovi nesráží (§ 38ch odst. 5 věta poslední),
        // takže vyplácená částka nikdy záporná není. Kdyby doklad takové číslo
        // ukázal, tvrdil by, že se něco strhne.
        if ($payableMinorUnits < 0) {
            throw new \InvalidArgumentException(
                'Vyplácená částka ročního zúčtování nesmí být záporná.',
            );
        }
        if ($settlementDifferenceMinorUnits
            !== $taxDifferenceMinorUnits + $bonusDifferenceMinorUnits
        ) {
            throw new \InvalidArgumentException(
                'Doplatek ze zúčtování neodpovídá součtu rozdílů.',
            );
        }
        foreach ([
            $externalCertificateCount,
            $externalAdvanceBaseMinorUnits,
            $externalAdvanceTaxMinorUnits,
            $externalTaxBonusMinorUnits,
        ] as $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(
                    'Příspěvek potvrzení od jiného plátce nesmí být záporný.',
                );
            }
        }
        // Bez potvrzení nesmí být nenulový příspěvek a naopak — jinak by doklad
        // tvrdil, že část úhrnu pochází od plátce, který na něm není uvedený.
        if ($externalCertificateCount === 0
            && ($externalAdvanceBaseMinorUnits !== 0
                || $externalAdvanceTaxMinorUnits !== 0
                || $externalTaxBonusMinorUnits !== 0)
        ) {
            throw new \InvalidArgumentException(
                'Doklad uvádí částky od předchozích plátců, ale žádné potvrzení.',
            );
        }
        // § 35d odst. 7 porovnává daň s ÚHRNEM zálohově sražené daně. Rozdíl na
        // dani na dokladu proto musí sedět na součet vlastních záloh a záloh
        // z potvrzení — kdyby ne, doklad by ukazoval čísla, ze kterých ten
        // rozdíl nevychází.
        if ($taxDifferenceMinorUnits
            !== $advanceTaxMinorUnits + $externalAdvanceTaxMinorUnits
                - $taxAfterAllCreditsMinorUnits
        ) {
            throw new \InvalidArgumentException(
                'Rozdíl na dani neodpovídá úhrnu zálohově sražené daně.',
            );
        }
    }

    /** § 38ch odst. 4 — úhrn za všechny plátce, ne jen za tohoto. */
    public function totalAdvanceBaseMinorUnits(): int
    {
        return $this->advanceBaseMinorUnits + $this->externalAdvanceBaseMinorUnits;
    }

    public function totalAdvanceTaxMinorUnits(): int
    {
        return $this->advanceTaxMinorUnits + $this->externalAdvanceTaxMinorUnits;
    }

    public function totalMonthlyTaxBonusMinorUnits(): int
    {
        return $this->monthlyTaxBonusMinorUnits + $this->externalTaxBonusMinorUnits;
    }

    /** @return array<string,mixed> */
    public function toTemplateData(): array
    {
        return [
            'tax_year' => $this->taxYear,
            'source_snapshot_sha256' => $this->sourceSnapshotSha256,
            'settled_on' => $this->settledOn,
            'completed_months' => $this->completedMonths,
            'employer' => [
                'name' => $this->employerName,
                'identification_number' => $this->employerIdentificationNumber,
                'address' => $this->employerAddress,
            ],
            'employee' => [
                'name' => $this->employeeName,
                'identifier_label' => $this->personalIdentifierLabel,
                'identifier_value' => $this->personalIdentifierValue,
            ],
            'advance_base_minor_units' => $this->advanceBaseMinorUnits,
            'external_certificate_count' => $this->externalCertificateCount,
            'external_advance_base_minor_units' => $this->externalAdvanceBaseMinorUnits,
            'external_advance_tax_minor_units' => $this->externalAdvanceTaxMinorUnits,
            'external_tax_bonus_minor_units' => $this->externalTaxBonusMinorUnits,
            'total_advance_base_minor_units' => $this->totalAdvanceBaseMinorUnits(),
            'total_advance_tax_minor_units' => $this->totalAdvanceTaxMinorUnits(),
            'total_monthly_tax_bonus_minor_units' => $this->totalMonthlyTaxBonusMinorUnits(),
            'rounded_tax_base_minor_units' => $this->roundedTaxBaseMinorUnits,
            'tax_before_credits_minor_units' => $this->taxBeforeCreditsMinorUnits,
            'credit_rows' => $this->creditRows,
            'applied_credits_minor_units' => $this->appliedCreditsMinorUnits,
            'child_rows' => $this->childRows,
            'child_credit_minor_units' => $this->childCreditMinorUnits,
            'annual_tax_bonus_minor_units' => $this->annualTaxBonusMinorUnits,
            'tax_after_all_credits_minor_units' => $this->taxAfterAllCreditsMinorUnits,
            'advance_tax_minor_units' => $this->advanceTaxMinorUnits,
            'monthly_tax_bonus_minor_units' => $this->monthlyTaxBonusMinorUnits,
            'tax_difference_minor_units' => $this->taxDifferenceMinorUnits,
            'bonus_difference_minor_units' => $this->bonusDifferenceMinorUnits,
            'settlement_difference_minor_units' => $this->settlementDifferenceMinorUnits,
            'payable_minor_units' => $this->payableMinorUnits,
            'outcome' => $this->outcome,
        ];
    }
}
