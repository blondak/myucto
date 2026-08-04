<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * @phpstan-type TermsInput array{
 *   office_id:?int,
 *   effective_from:string,
 *   contract_signed_on:?string,
 *   planned_start_on:string,
 *   actual_start_on:?string,
 *   fixed_term_end_on:?string,
 *   weekly_hours:?string,
 *   workload_basis_points:int,
 *   work_place:?string,
 *   regular_workplace:?string,
 *   cz_isco_code:?string,
 *   activity_code:?string,
 *   social_insurance_participation:string,
 *   health_insurance_participation:string,
 *   tax_regime:string,
 *   foreign_legislation_country_code:?string,
 *   a1_certificate_until:?string,
 *   risky_work:bool,
 *   tax_declaration_signed:bool,
 *   is_primary:bool,
 *   change_reason:?string
 * }
 * @phpstan-type EmploymentCreateInput array{
 *   code:string,
 *   relation_type:string,
 *   monthly_gross_minor:?int,
 *   terms:TermsInput
 * }
 */
final class PayrollEmploymentValidator
{
    private const RELATION_TYPES = [
        'employment',
        'small_scale_employment',
        'dpp',
        'dpc',
        'partner_dependent',
        'statutory_body',
    ];

    private const INSURANCE_MODES = ['automatic', 'included', 'excluded', 'foreign'];
    private const TAX_REGIMES = ['advance', 'withholding', 'foreign', 'manual_review'];
    private const CHECKLIST_STATUSES = ['pending', 'completed', 'not_applicable'];

    /** @param array<string,mixed> $input
     *  @return EmploymentCreateInput
     */
    public function create(array $input): array
    {
        $code = trim($this->inputString($input['code'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/', $code)) {
            throw new \InvalidArgumentException('Kód pracovního vztahu není platný.');
        }
        $relationType = $this->inputString($input['relation_type'] ?? '');
        if (!in_array($relationType, self::RELATION_TYPES, true)) {
            throw new \InvalidArgumentException('Typ pracovního vztahu není podporován.');
        }
        $gross = $input['monthly_gross_minor'] ?? null;
        if ($gross !== null && (!is_int($gross) || $gross < 0)) {
            throw new \InvalidArgumentException('Pravidelná hrubá mzda musí být nezáporná částka v haléřích.');
        }
        if (!is_array($input['terms'] ?? null)) {
            throw new \InvalidArgumentException('Chybí počáteční smluvní podmínky.');
        }

        $terms = $this->terms($this->stringKeyed($input['terms']));
        if ($terms['actual_start_on'] !== null) {
            throw new \InvalidArgumentException(
                'Skutečný nástup se zaznamenává přechodem pracovního vztahu do aktivního stavu.'
            );
        }

        return [
            'code' => $code,
            'relation_type' => $relationType,
            'monthly_gross_minor' => $gross,
            'terms' => $terms,
        ];
    }

    /** @param array<string,mixed> $input
     *  @return TermsInput
     */
    public function terms(array $input): array
    {
        $effectiveFrom = $this->requiredDate($input, 'effective_from');
        $plannedStart = $this->requiredDate($input, 'planned_start_on');
        $fixedEnd = $this->optionalDate($input, 'fixed_term_end_on');
        if ($fixedEnd !== null && $fixedEnd < $plannedStart) {
            throw new \InvalidArgumentException('Konec doby určité nesmí předcházet plánovanému nástupu.');
        }

        $officeId = $input['office_id'] ?? null;
        if ($officeId !== null && (!is_int($officeId) || $officeId <= 0)) {
            throw new \InvalidArgumentException('Mzdová účtárna není platná.');
        }
        $hours = $input['weekly_hours'] ?? null;
        if ($hours !== null) {
            if ((!is_string($hours) && !is_int($hours))
                || preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', (string) $hours, $parts) !== 1) {
                throw new \InvalidArgumentException('Týdenní pracovní doba není platná.');
            }
            $whole = (int) $parts[1];
            $fraction = str_pad($parts[2] ?? '', 2, '0');
            $centiHours = ($whole * 100) + (int) $fraction;
            if ($centiHours <= 0 || $centiHours > 16800) {
                throw new \InvalidArgumentException('Týdenní pracovní doba musí být větší než nula a nejvýše 168 hodin.');
            }
            $hours = sprintf('%d.%02d', intdiv($centiHours, 100), $centiHours % 100);
        }
        $workload = $input['workload_basis_points'] ?? 10000;
        if (!is_int($workload) || $workload < 1 || $workload > 10000) {
            throw new \InvalidArgumentException('Úvazek musí být od 0,01 % do 100 %.');
        }

        $social = $this->inputString($input['social_insurance_participation'] ?? 'automatic');
        $health = $this->inputString($input['health_insurance_participation'] ?? 'automatic');
        $tax = $this->inputString($input['tax_regime'] ?? 'advance');
        if (!in_array($social, self::INSURANCE_MODES, true)
            || !in_array($health, self::INSURANCE_MODES, true)
            || !in_array($tax, self::TAX_REGIMES, true)) {
            throw new \InvalidArgumentException('Pojistný nebo daňový režim není podporován.');
        }
        $country = strtoupper(trim($this->inputString($input['foreign_legislation_country_code'] ?? '')));
        $country = $country === '' ? null : $country;
        if ($country !== null && !preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \InvalidArgumentException('Kód státu cizích právních předpisů není platný.');
        }
        if (($social === 'foreign' || $health === 'foreign' || $tax === 'foreign') && $country === null) {
            throw new \InvalidArgumentException('Cizí režim vyžaduje kód státu právních předpisů.');
        }

        return [
            'office_id' => $officeId,
            'effective_from' => $effectiveFrom,
            'contract_signed_on' => $this->optionalDate($input, 'contract_signed_on'),
            'planned_start_on' => $plannedStart,
            'actual_start_on' => $this->optionalDate($input, 'actual_start_on'),
            'fixed_term_end_on' => $fixedEnd,
            'weekly_hours' => $hours === null ? null : (string) $hours,
            'workload_basis_points' => $workload,
            'work_place' => $this->optionalText($input, 'work_place', 255),
            'regular_workplace' => $this->optionalText($input, 'regular_workplace', 255),
            'cz_isco_code' => $this->optionalCode($input, 'cz_isco_code', 16),
            'activity_code' => $this->optionalCode($input, 'activity_code', 32),
            'social_insurance_participation' => $social,
            'health_insurance_participation' => $health,
            'tax_regime' => $tax,
            'foreign_legislation_country_code' => $country,
            'a1_certificate_until' => $this->optionalDate($input, 'a1_certificate_until'),
            'risky_work' => $this->requiredBool($input, 'risky_work', false),
            'tax_declaration_signed' => $this->requiredBool($input, 'tax_declaration_signed', false),
            'is_primary' => $this->requiredBool($input, 'is_primary', false),
            'change_reason' => $this->optionalText($input, 'change_reason', 500),
        ];
    }

    /** @param array<string,mixed> $input
     *  @return array{row_version:int,status:string,note:?string}
     */
    public function checklist(array $input): array
    {
        $version = $this->rowVersion($input);
        $status = $this->inputString($input['status'] ?? '');
        if (!in_array($status, self::CHECKLIST_STATUSES, true)) {
            throw new \InvalidArgumentException('Stav položky checklistu není platný.');
        }
        return [
            'row_version' => $version,
            'status' => $status,
            'note' => $this->optionalText($input, 'note', 500),
        ];
    }

    /** @param array<string,mixed> $input
     *  @return array{row_version:int,effective_on:string,note:?string}
     */
    public function transition(array $input): array
    {
        return [
            'row_version' => $this->rowVersion($input),
            'effective_on' => $this->requiredDate($input, 'effective_on'),
            'note' => $this->optionalText($input, 'note', 500),
        ];
    }

    /** @param array<string,mixed> $input */
    public function rowVersion(array $input): int
    {
        $value = $input['row_version'] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('row_version musí být kladné celé číslo.');
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredDate(array $input, string $key): string
    {
        return $this->date($this->inputString($input[$key] ?? ''), $key)
            ?? throw new \InvalidArgumentException("Pole {$key} je povinné.");
    }

    /** @param array<string,mixed> $input */
    private function optionalDate(array $input, string $key): ?string
    {
        return $this->date(trim($this->inputString($input[$key] ?? '')), $key);
    }

    private function date(string $value, string $key): ?string
    {
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Pole {$key} musí být datum YYYY-MM-DD.");
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalText(array $input, string $key, int $maxLength): ?string
    {
        $value = trim($this->inputString($input[$key] ?? ''));
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException("Pole {$key} je příliš dlouhé.");
        }
        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalCode(array $input, string $key, int $maxLength): ?string
    {
        $value = $this->optionalText($input, $key, $maxLength);
        if ($value !== null && !preg_match('/^[A-Za-z0-9._\/-]+$/', $value)) {
            throw new \InvalidArgumentException("Pole {$key} není platný kód.");
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredBool(array $input, string $key, bool $default): bool
    {
        $value = $input[$key] ?? $default;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("Pole {$key} musí být boolean.");
        }
        return $value;
    }

    private function inputString(mixed $value): string
    {
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return '';
        }
        throw new \InvalidArgumentException('Textové pole má neplatný typ.');
    }

    /** @param array<mixed> $value
     *  @return array<string,mixed>
     */
    private function stringKeyed(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Objekt obsahuje neplatný klíč.');
            }
            $result[$key] = $item;
        }
        return $result;
    }
}
