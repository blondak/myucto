<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthAssessmentComponent;
use MyInvoice\Service\Payroll\HealthInsurance\HealthComponentTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCorrectionTreatment;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponentTreatment;
use MyInvoice\Service\Payroll\IncomeTax\TaxCorrectionTreatment;
use MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentComponent;
use MyInvoice\Service\Payroll\SocialInsurance\SocialComponentTreatment;

final class PayrollRunStatutoryComponentMapper
{
    /** @param array<string,mixed> $input */
    public function social(array $input): SocialAssessmentComponent
    {
        $component = $this->component($input);

        return new SocialAssessmentComponent(
            $this->reference($input, $component),
            $this->integer($input['amount_minor'] ?? null, 'input.amount_minor'),
            SocialComponentTreatment::from($this->string(
                $component['social_participation_treatment'] ?? null,
                'component.social_participation_treatment',
            )),
            SocialComponentTreatment::from($this->string(
                $component['social_treatment'] ?? null,
                'component.social_treatment',
            )),
        );
    }

    /** @param array<string,mixed> $input */
    public function health(
        array $input,
        string $periodStart,
    ): HealthAssessmentComponent {
        $component = $this->component($input);

        return new HealthAssessmentComponent(
            $this->reference($input, $component),
            $this->integer($input['amount_minor'] ?? null, 'input.amount_minor'),
            HealthComponentTreatment::from($this->string(
                $component['health_participation_treatment'] ?? null,
                'component.health_participation_treatment',
            )),
            HealthComponentTreatment::from($this->string(
                $component['health_treatment'] ?? null,
                'component.health_treatment',
            )),
            $this->isCurrentPeriod($input, $periodStart)
                ? HealthCorrectionTreatment::CurrentMonth
                : HealthCorrectionTreatment::Unverified,
        );
    }

    /** @param array<string,mixed> $input */
    public function incomeTax(
        array $input,
        string $periodStart,
    ): IncomeTaxComponent {
        $component = $this->component($input);
        $treatment = match ($this->string(
            $component['tax_treatment'] ?? null,
            'component.tax_treatment',
        )) {
            'included', 'withholding_candidate' =>
                IncomeTaxComponentTreatment::Included,
            'exempt' => IncomeTaxComponentTreatment::Exempt,
            'manual_review' => IncomeTaxComponentTreatment::ManualReview,
            default => throw new \UnexpectedValueException(
                'Mzdová složka má nepodporované daňové zacházení.',
            ),
        };

        return new IncomeTaxComponent(
            $this->reference($input, $component),
            $this->integer($input['amount_minor'] ?? null, 'input.amount_minor'),
            $treatment,
            $this->isCurrentPeriod($input, $periodStart)
                ? TaxCorrectionTreatment::CurrentMonth
                : TaxCorrectionTreatment::Unverified,
        );
    }

    /** @param array<string,mixed> $input */
    private function isCurrentPeriod(array $input, string $periodStart): bool
    {
        $this->date($periodStart, 'period_start');
        $source = $input['source_period_start'] ?? null;
        if ($source === null) {
            return true;
        }

        return $this->date($source, 'input.source_period_start') === $periodStart;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function component(array $input): array
    {
        $component = $input['component'] ?? null;
        if (!is_array($component) || array_is_list($component)) {
            throw new \UnexpectedValueException('input.component musí být objekt.');
        }

        return $component;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $component
     */
    private function reference(array $input, array $component): string
    {
        $id = $this->integer($input['id'] ?? null, 'input.id');
        if ($id <= 0) {
            throw new \UnexpectedValueException('input.id musí být kladné.');
        }
        $code = strtolower($this->string($component['code'] ?? null, 'component.code'));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $code) !== 1) {
            throw new \UnexpectedValueException('Mzdová složka nemá kanonický kód.');
        }

        return "input.{$id}.{$code}";
    }

    private function string(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException("{$field} musí být text.");
        }

        return $value;
    }

    private function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new \UnexpectedValueException("{$field} musí být celé číslo.");
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException("{$field} musí být datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \UnexpectedValueException(
                "{$field} musí být datum YYYY-MM-DD.",
            );
        }

        return $value;
    }
}
