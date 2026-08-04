<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

final readonly class AnnualTaxCertificateDocumentData
{
    public const SCHEMA_VERSION = 'annual-tax-certificate-document.v2';

    /**
     * @param array{
     *   tax_year:int,
     *   document_kind:PayrollDocumentKind,
     *   form_number:string,
     *   ministry_form:string,
     *   pattern_number:int,
     *   valid_from:string,
     *   valid_to:string,
     *   official_url:string
     * } $form
     * @param list<string> $previousNames
     * @param list<int> $months
     * @param list<int> $taxDeclarationSignedMonths
     * @param array<string,int> $employerProductContributionsMinorUnits
     * @param list<array<string,mixed>> $childTaxBenefits
     * @param list<array<string,mixed>> $disabilityTaxCredits
     * @param array{performed:bool,result:?array<string,mixed>} $annualSettlement
     */
    public function __construct(
        public string $sourceSnapshotSha256,
        public PayrollDocumentKind $kind,
        public int $taxYear,
        public array $form,
        public PayrollDocumentEmployerSnapshot $employer,
        public string $employeeName,
        public string $employeeFirstName,
        public string $employeeLastName,
        public array $previousNames,
        public string $personalIdentifierLabel,
        public string $personalIdentifierValue,
        public string $employeeAddress,
        public array $months,
        public ?string $taxDeclarationStatus,
        public array $taxDeclarationSignedMonths,
        public string $taxResidenceStatus,
        public string $taxResidenceCountryCode,
        public string $issuedAt,
        public ?string $replacesIssuedAt,
        public ?string $correctionReason,
        public array $employerProductContributionsMinorUnits,
        public array $childTaxBenefits,
        public array $disabilityTaxCredits,
        public array $annualSettlement,
        public ?int $nonresidentInsuranceMinorUnits,
        public int $accruedIncomeMinorUnits,
        public int $paidIncomeMinorUnits,
        public int $advanceTaxMinorUnits,
        public int $withholdingTaxMinorUnits,
        public int $taxBonusMinorUnits,
        public string $paymentEvidenceCutoff,
        public string $lastProvenPaymentDate,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotSha256) !== 1) {
            throw new \InvalidArgumentException(
                'Zdrojový otisk daňového potvrzení není platný.',
            );
        }
        if (!in_array($kind, [
            PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            PayrollDocumentKind::TaxableIncomeWithholdingCertificate,
        ], true)) {
            throw new \InvalidArgumentException(
                'Druh daňového potvrzení není podporovaný.',
            );
        }
        if ($form !== AnnualTaxCertificateFormCatalog::resolve($taxYear, $kind)) {
            throw new \InvalidArgumentException(
                'Daňové potvrzení neodpovídá přesnému katalogu formulářů.',
            );
        }
        foreach ([
            'jméno zaměstnance' => $employeeName,
            'křestní jméno zaměstnance' => $employeeFirstName,
            'příjmení zaměstnance' => $employeeLastName,
            'označení osobního identifikátoru' => $personalIdentifierLabel,
            'osobní identifikátor' => $personalIdentifierValue,
            'adresa zaměstnance' => $employeeAddress,
        ] as $label => $text) {
            if (trim($text) === ''
                || trim($text) !== $text
                || mb_strlen($text) > 500
                || preg_match('/[\x00-\x1F\x7F]/u', $text) === 1
            ) {
                throw new \InvalidArgumentException(
                    "Pole {$label} daňového potvrzení není platné.",
                );
            }
        }
        if (count($previousNames) > 20) {
            throw new \InvalidArgumentException(
                'Dřívější jména daňového potvrzení nemají platnou strukturu.',
            );
        }
        foreach ($previousNames as $name) {
            if (trim($name) === ''
                || trim($name) !== $name
                || mb_strlen($name) > 191
            ) {
                throw new \InvalidArgumentException(
                    'Dřívější jméno daňového potvrzení není platné.',
                );
            }
        }
        if ($months === []) {
            throw new \InvalidArgumentException(
                'Daňové potvrzení nemá žádný měsíc zdanitelného příjmu.',
            );
        }
        $seenMonths = [];
        foreach ($months as $month) {
            if ($month < 1
                || $month > 12
                || isset($seenMonths[$month])
            ) {
                throw new \InvalidArgumentException(
                    'Měsíce daňového potvrzení nejsou platné a jednoznačné.',
                );
            }
            $seenMonths[$month] = true;
        }
        $sortedMonths = $months;
        sort($sortedMonths, SORT_NUMERIC);
        if ($sortedMonths !== $months) {
            throw new \InvalidArgumentException(
                'Měsíce daňového potvrzení nejsou seřazené.',
            );
        }
        if ($taxResidenceStatus !== 'czech-resident'
            || $taxResidenceCountryCode !== 'CZ'
        ) {
            throw new \InvalidArgumentException(
                'Daňové potvrzení nemá doložené rezidentství České republiky.',
            );
        }
        $signedMonths = [];
        foreach ($taxDeclarationSignedMonths as $month) {
            if (!in_array($month, $months, true)
                || isset($signedMonths[$month])
            ) {
                throw new \InvalidArgumentException(
                    'Podepsané měsíce Prohlášení poplatníka nejsou platné.',
                );
            }
            $signedMonths[$month] = true;
        }
        $sortedSignedMonths = $taxDeclarationSignedMonths;
        sort($sortedSignedMonths, SORT_NUMERIC);
        if ($sortedSignedMonths !== $taxDeclarationSignedMonths) {
            throw new \InvalidArgumentException(
                'Podepsané měsíce Prohlášení poplatníka nejsou seřazené.',
            );
        }
        if ($kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate) {
            if (!in_array($taxDeclarationStatus, [
                'signed',
                'not-signed',
                'mixed',
            ], true)) {
                throw new \InvalidArgumentException(
                    'Stav Prohlášení poplatníka není doložen.',
                );
            }
            if (($taxDeclarationStatus === 'signed'
                    && $taxDeclarationSignedMonths !== $months)
                || ($taxDeclarationStatus === 'not-signed'
                    && $taxDeclarationSignedMonths !== [])
                || ($taxDeclarationStatus === 'mixed'
                    && ($taxDeclarationSignedMonths === []
                        || count($taxDeclarationSignedMonths) >= count($months)))
            ) {
                throw new \InvalidArgumentException(
                    'Stav Prohlášení neodpovídá podepsaným měsícům.',
                );
            }
        } elseif ($taxDeclarationStatus !== null
            || $taxDeclarationSignedMonths !== []
        ) {
            throw new \InvalidArgumentException(
                'Srážkové potvrzení nesmí uvádět Prohlášení poplatníka.',
            );
        }
        self::timestamp($issuedAt, 'datum vydání');
        if (($replacesIssuedAt === null) !== ($correctionReason === null)) {
            throw new \InvalidArgumentException(
                'Opravné potvrzení vyžaduje datum předchozího vydání i konkrétní důvod.',
            );
        }
        if ($replacesIssuedAt !== null) {
            self::timestamp(
                $replacesIssuedAt,
                'datum nahrazovaného potvrzení',
            );
            if (trim((string) $correctionReason) !== $correctionReason
                || $correctionReason === ''
                || mb_strlen($correctionReason) > 1000
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $correctionReason) === 1
            ) {
                throw new \InvalidArgumentException(
                    'Důvod opravného potvrzení není platný.',
                );
            }
        }
        $expectedProducts = [
            'supplementary_pension',
            'pension_insurance',
            'private_life_insurance',
            'long_term_investment_product',
        ];
        if (array_keys($employerProductContributionsMinorUnits)
            !== $expectedProducts
        ) {
            throw new \InvalidArgumentException(
                'Příspěvky zaměstnavatele nemají strukturu řádku 10.',
            );
        }
        foreach ($employerProductContributionsMinorUnits as $amount) {
            if ($amount !== 0) {
                throw new \InvalidArgumentException(
                    'Nenulový příspěvek zaměstnavatele vyžaduje doložené mapování řádku 10.',
                );
            }
        }
        if ($childTaxBenefits !== [] || $disabilityTaxCredits !== []) {
            throw new \InvalidArgumentException(
                'Děti a invalidita vyžadují úplná strukturovaná data řádků 11 a 12.',
            );
        }
        if ($annualSettlement !== [
            'performed' => false,
            'result' => null,
        ]) {
            throw new \InvalidArgumentException(
                'Výsledek ročního zúčtování nemá úplnou strukturu řádku 13.',
            );
        }
        if ($nonresidentInsuranceMinorUnits !== null) {
            throw new \InvalidArgumentException(
                'Řádek 14 musí u českého daňového rezidenta zůstat prázdný.',
            );
        }
        foreach ([
            'zúčtovaný příjem' => $accruedIncomeMinorUnits,
            'skutečně vyplacený příjem' => $paidIncomeMinorUnits,
            'záloha na daň' => $advanceTaxMinorUnits,
            'srážková daň' => $withholdingTaxMinorUnits,
            'daňový bonus' => $taxBonusMinorUnits,
        ] as $label => $amount) {
            if ($amount < 0 || $amount > 1_000_000_000_000_000) {
                throw new \InvalidArgumentException(
                    "Částka {$label} daňového potvrzení není platná.",
                );
            }
            if ($amount % 100 !== 0) {
                throw new \InvalidArgumentException(
                    "Částka {$label} není doložitelná v celých Kč.",
                );
            }
        }
        if ($accruedIncomeMinorUnits <= 0
            || $paidIncomeMinorUnits !== $accruedIncomeMinorUnits
        ) {
            throw new \InvalidArgumentException(
                'Skutečně vyplacený příjem není plně doložen.',
            );
        }
        if ($kind === PayrollDocumentKind::TaxableIncomeAdvanceCertificate
            && $withholdingTaxMinorUnits !== 0
        ) {
            throw new \InvalidArgumentException(
                'Zálohové potvrzení nesmí obsahovat srážkovou daň.',
            );
        }
        if ($kind === PayrollDocumentKind::TaxableIncomeWithholdingCertificate
            && ($advanceTaxMinorUnits !== 0 || $taxBonusMinorUnits !== 0)
        ) {
            throw new \InvalidArgumentException(
                'Srážkové potvrzení nesmí obsahovat zálohu ani daňový bonus.',
            );
        }
        if ($advanceTaxMinorUnits > $paidIncomeMinorUnits
            || $withholdingTaxMinorUnits > $paidIncomeMinorUnits
        ) {
            throw new \InvalidArgumentException(
                'Daň nemůže převýšit doložený příjem.',
            );
        }
        $cutoff = self::date($paymentEvidenceCutoff, 'mezní datum úhrady');
        $lastPayment = self::date(
            $lastProvenPaymentDate,
            'datum poslední doložené úhrady',
        );
        $expectedCutoff = new \DateTimeImmutable(($taxYear + 1) . '-01-31');
        if ($cutoff != $expectedCutoff || $lastPayment > $cutoff) {
            throw new \InvalidArgumentException(
                'Platební důkaz daňového potvrzení nemá platné rozhodné datum.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function toTemplateData(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source_snapshot_sha256' => $this->sourceSnapshotSha256,
            'document_kind' => $this->kind->value,
            'is_advance' =>
                $this->kind
                === PayrollDocumentKind::TaxableIncomeAdvanceCertificate,
            'tax_year' => $this->taxYear,
            'form' => [
                'form_number' => (string) $this->form['form_number'],
                'ministry_form' => (string) $this->form['ministry_form'],
                'pattern_number' => (int) $this->form['pattern_number'],
            ],
            'employer' => $this->employer->toArray(),
            'employee' => [
                'name' => $this->employeeName,
                'first_name' => $this->employeeFirstName,
                'last_name' => $this->employeeLastName,
                'previous_names' => $this->previousNames,
                'identifier_label' => $this->personalIdentifierLabel,
                'identifier_value' => $this->personalIdentifierValue,
                'address' => $this->employeeAddress,
            ],
            'months' => $this->months,
            'months_label' => implode(', ', array_map(
                static fn (string $range): string => $range,
                self::monthRanges($this->months),
            )),
            'tax_declaration' => [
                'status' => $this->taxDeclarationStatus,
                'status_label' => match ($this->taxDeclarationStatus) {
                    'signed' => 'učiněno',
                    'not-signed' => 'neučiněno',
                    'mixed' => 'učiněno pouze po část období',
                    null => null,
                    default => throw new \LogicException(
                        'Stav Prohlášení poplatníka není podporovaný.',
                    ),
                },
                'signed_months' => $this->taxDeclarationSignedMonths,
                'signed_months_label' => implode(', ', self::monthRanges(
                    $this->taxDeclarationSignedMonths,
                )),
            ],
            'tax_residence' => [
                'status' => $this->taxResidenceStatus,
                'country_code' => $this->taxResidenceCountryCode,
                'is_czech_resident' =>
                    $this->taxResidenceStatus === 'czech-resident'
                    && $this->taxResidenceCountryCode === 'CZ',
            ],
            'replaces_issued_at' => $this->replacesIssuedAt === null
                ? null
                : self::displayDate($this->replacesIssuedAt),
            'issued_at' => self::displayDate($this->issuedAt),
            'correction_reason' => $this->correctionReason,
            'employer_product_contributions' => array_map(
                self::czk(...),
                $this->employerProductContributionsMinorUnits,
            ),
            'child_tax_benefits' => $this->childTaxBenefits,
            'disability_tax_credits' => $this->disabilityTaxCredits,
            'annual_settlement' => $this->annualSettlement,
            'nonresident_insurance_czk' =>
                $this->nonresidentInsuranceMinorUnits === null
                    ? null
                    : self::czk($this->nonresidentInsuranceMinorUnits),
            'amounts' => [
                'accrued_income_czk' => self::czk($this->accruedIncomeMinorUnits),
                'paid_income_czk' => self::czk($this->paidIncomeMinorUnits),
                'advance_tax_czk' => self::czk($this->advanceTaxMinorUnits),
                'withholding_tax_czk' => self::czk($this->withholdingTaxMinorUnits),
                'tax_bonus_czk' => self::czk($this->taxBonusMinorUnits),
            ],
            'payment_evidence' => [
                'cutoff' => self::displayDate($this->paymentEvidenceCutoff),
                'last_payment_date' =>
                    self::displayDate($this->lastProvenPaymentDate),
            ],
        ];
    }

    private static function czk(int $minorUnits): int
    {
        return intdiv($minorUnits, 100);
    }

    /**
     * @param list<int> $months
     * @return list<string>
     */
    private static function monthRanges(array $months): array
    {
        if ($months === []) {
            return [];
        }
        $ranges = [];
        $start = $months[0];
        $previous = $start;
        foreach (array_slice($months, 1) as $month) {
            if ($month === $previous + 1) {
                $previous = $month;
                continue;
            }
            $ranges[] = $start === $previous
                ? (string) $start
                : "{$start}–{$previous}";
            $start = $month;
            $previous = $month;
        }
        $ranges[] = $start === $previous
            ? (string) $start
            : "{$start}–{$previous}";

        return $ranges;
    }

    private static function date(string $value, string $label): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException(
                "Pole {$label} daňového potvrzení není platné datum.",
            );
        }

        return $date;
    }

    private static function displayDate(string $value): string
    {
        $date = str_contains($value, ' ')
            ? self::timestamp($value, 'datum')
            : self::date($value, 'datum');

        return implode('. ', [
            (string) (int) $date->format('d'),
            (string) (int) $date->format('m'),
            $date->format('Y'),
        ]);
    }

    private static function timestamp(
        string $value,
        string $label,
    ): \DateTimeImmutable {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $value
        ) {
            throw new \InvalidArgumentException(
                "Pole {$label} daňového potvrzení není platné datum a čas.",
            );
        }

        return $date;
    }
}
