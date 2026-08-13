<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * @phpstan-import-type EmploymentCreateInput from PayrollEmploymentValidator
 * @phpstan-type SharedEmployeeCreateInput array{
 *   full_name:string,
 *   birth_date:?string,
 *   birth_number:?string,
 *   address:null,
 *   taxpayer_type:string,
 *   employment_type:string,
 *   tax_declaration_signed:bool,
 *   tax_credit_taxpayer:bool,
 *   child_count:int,
 *   net_settlement_account_code:null,
 *   monthly_gross:?int,
 *   auto_post:bool,
 *   is_active:bool
 * }
 * @phpstan-type PayrollPersonCreateInput array{
 *   employee:SharedEmployeeCreateInput,
 *   employment:EmploymentCreateInput
 * }
 */
final class PayrollPersonCreateValidator
{
    private const MAX_MONTHLY_GROSS = 10_000_000;

    public function __construct(
        private readonly PayrollEmploymentValidator $employmentValidator,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return PayrollPersonCreateInput
     */
    public function validate(array $input): array
    {
        $fullName = trim($this->string($input['full_name'] ?? null));
        if ($fullName === '') {
            throw new \InvalidArgumentException('Jméno a příjmení je povinné.');
        }
        if (mb_strlen($fullName) > 191) {
            throw new \InvalidArgumentException('Jméno a příjmení může mít nejvýše 191 znaků.');
        }

        $birthDate = $this->optionalDate(
            $input['birth_date'] ?? null,
            'Datum narození musí být ve formátu YYYY-MM-DD.',
        );
        $birthNumber = $this->optionalText(
            $input['birth_number'] ?? null,
            20,
            'Rodné číslo může mít nejvýše 20 znaků.',
        );

        $monthlyGross = $input['monthly_gross'] ?? null;
        if ($monthlyGross !== null
            && (!is_int($monthlyGross)
                || $monthlyGross < 0
                || $monthlyGross > self::MAX_MONTHLY_GROSS)
        ) {
            throw new \InvalidArgumentException(
                'Pravidelná hrubá mzda musí být celé číslo v rozsahu 0 až 10 000 000 Kč.',
            );
        }

        $relationType = $this->string($input['relation_type'] ?? null);
        $plannedStart = $this->string($input['planned_start_on'] ?? null);
        $officeId = $input['office_id'] ?? null;
        $employment = $this->employmentValidator->create([
            'code' => 'ZAM-PENDING',
            'relation_type' => $relationType,
            'monthly_gross_minor' => $monthlyGross === null ? null : $monthlyGross * 100,
            'terms' => [
                'office_id' => $officeId,
                'effective_from' => $plannedStart,
                'contract_signed_on' => null,
                'planned_start_on' => $plannedStart,
                'actual_start_on' => null,
                'fixed_term_end_on' => null,
                'weekly_hours' => '40.00',
                'workload_basis_points' => 10_000,
                'work_place' => null,
                'regular_workplace' => null,
                'jmhz_workplace_municipality_code' => null,
                'jmhz_workplace_country_code' => null,
                'jmhz_apz_contribution_status' => 'unverified',
                'jmhz_apz_instrument_code' => null,
                'jmhz_functional_benefits_status' => 'unverified',
                'jmhz_temporary_assignment_status' => 'unverified',
                'cz_isco_code' => null,
                'activity_code' => null,
                'social_insurance_participation' => 'automatic',
                'health_insurance_participation' => 'automatic',
                'tax_regime' => 'advance',
                'foreign_legislation_country_code' => null,
                'a1_certificate_until' => null,
                'risky_work' => false,
                'tax_declaration_signed' => false,
                'is_primary' => true,
                'change_reason' => 'Počáteční podmínky při založení zaměstnance.',
            ],
        ]);

        return [
            'employee' => [
                'full_name' => $fullName,
                'birth_date' => $birthDate,
                'birth_number' => $birthNumber,
                'address' => null,
                'taxpayer_type' => in_array(
                    $relationType,
                    ['partner_dependent', 'statutory_body'],
                    true,
                ) ? 'managing_partner' : 'employee',
                // Klíče jsou shodné s `payroll_employments.relation_type` všude, kde ta
                // hodnota v účetní větvi existuje — mapování je pak identita, ne překlad.
                // `partner_dependent` v ENUM `payroll_employees.employment_type` protějšek
                // nemá, takže spadá na `hpp`; kontaci 522/366 mu i tak zajistí
                // `taxpayer_type = managing_partner` výš.
                'employment_type' => match ($relationType) {
                    'dpp' => 'dpp',
                    'dpc' => 'dpc',
                    // Migrace 1302 — do té doby dostal člen statutárního orgánu na
                    // legacy kartě „pracovní poměr", což u odměny podle § 6/1/c ZDP
                    // není pravda.
                    'statutory_body' => 'statutory_body',
                    default => 'hpp',
                },
                'tax_declaration_signed' => false,
                'tax_credit_taxpayer' => true,
                'child_count' => 0,
                'net_settlement_account_code' => null,
                'monthly_gross' => $monthlyGross,
                'auto_post' => false,
                'is_active' => true,
            ],
            'employment' => $employment,
        ];
    }

    private function string(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return '';
        }
        throw new \InvalidArgumentException('Textové pole má neplatný typ.');
    }

    private function optionalText(mixed $value, int $maxLength, string $error): ?string
    {
        $text = trim($this->string($value));
        if (mb_strlen($text) > $maxLength) {
            throw new \InvalidArgumentException($error);
        }
        return $text === '' ? null : $text;
    }

    private function optionalDate(mixed $value, string $error): ?string
    {
        $text = trim($this->string($value));
        if ($text === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $text
        ) {
            throw new \InvalidArgumentException($error);
        }
        return $text;
    }
}
