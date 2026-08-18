<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthAssessmentComponent;
use MyInvoice\Service\Payroll\HealthInsurance\HealthComponentTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCorrectionTreatment;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponentTreatment;
use MyInvoice\Service\Payroll\IncomeTax\TaxCorrectionTreatment;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentComponent;
use MyInvoice\Service\Payroll\SocialInsurance\SocialComponentTreatment;

final class PayrollRunStatutoryComponentMapper
{
    /**
     * Přípona reference nadlimitní části benefitu.
     *
     * Nadlimitní část vystupuje jako VLASTNÍ složka výpočtu, ne jako přepsaná
     * částka té původní — jinak by z výsledku nešlo poznat, kolik plnění bylo
     * osvobozené a kolik se zdanilo, a rozklad pojistného by o rozdílu mlčel.
     * Vzorem je dvojice CESTOVNI_NAHRADA_LIMIT / CESTOVNI_NAHRADA_NADLIMIT.
     */
    private const OVER_LIMIT_SUFFIX = '.nadlimit';

    /**
     * Rozpad zákonného koše osvobození zmrazený při schválení vstupu.
     *
     * Vrací null, když vstup do koše nepatří NEBO se do něj celý vešel —
     * v obou případech se nic nedělí a chování zůstává jako před migrací 1480.
     *
     * @param array<string,mixed> $input
     */
    private function overLimitAmount(array $input): ?int
    {
        if (($input['benefit_basket'] ?? null) === null) {
            return null;
        }
        $taxable = $this->integer(
            $input['benefit_taxable_minor'] ?? null,
            'input.benefit_taxable_minor',
        );
        $exempt = $this->integer(
            $input['benefit_exempt_minor'] ?? null,
            'input.benefit_exempt_minor',
        );
        if ($taxable < 0 || $exempt < 0) {
            throw new \UnexpectedValueException(
                'Rozpad koše osvobození nesmí být záporný.',
            );
        }
        if ($exempt + $taxable !== $this->integer(
            $input['amount_minor'] ?? null,
            'input.amount_minor',
        )) {
            throw new \UnexpectedValueException(
                'Rozpad koše osvobození nedává částku mzdového vstupu.',
            );
        }

        return $taxable > 0 ? $taxable : null;
    }
    /**
     * @param array<string,mixed> $input
     * @return list<SocialAssessmentComponent>
     */
    public function social(array $input): array
    {
        $component = $this->component($input);
        $overLimit = $this->overLimitAmount($input);
        $amount = $this->integer($input['amount_minor'] ?? null, 'input.amount_minor');
        $result = [
            new SocialAssessmentComponent(
                $this->reference($input, $component),
                $overLimit === null ? $amount : $amount - $overLimit,
                SocialComponentTreatment::from($this->string(
                    $component['social_participation_treatment'] ?? null,
                    'component.social_participation_treatment',
                )),
                SocialComponentTreatment::from($this->string(
                    $component['social_treatment'] ?? null,
                    'component.social_treatment',
                )),
            ),
        ];
        if ($overLimit !== null) {
            $result[] = new SocialAssessmentComponent(
                $this->reference($input, $component) . self::OVER_LIMIT_SUFFIX,
                $overLimit,
                SocialComponentTreatment::Included,
                SocialComponentTreatment::Included,
            );
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $input
     * @return list<HealthAssessmentComponent>
     */
    public function health(
        array $input,
        string $periodStart,
    ): array {
        $component = $this->component($input);
        $overLimit = $this->overLimitAmount($input);
        $amount = $this->integer($input['amount_minor'] ?? null, 'input.amount_minor');
        $correction = $this->isCurrentPeriod($input, $periodStart)
            ? HealthCorrectionTreatment::CurrentMonth
            : HealthCorrectionTreatment::Unverified;
        $result = [
            new HealthAssessmentComponent(
                $this->reference($input, $component),
                $overLimit === null ? $amount : $amount - $overLimit,
                HealthComponentTreatment::from($this->string(
                    $component['health_participation_treatment'] ?? null,
                    'component.health_participation_treatment',
                )),
                HealthComponentTreatment::from($this->string(
                    $component['health_treatment'] ?? null,
                    'component.health_treatment',
                )),
                $correction,
            ),
        ];
        if ($overLimit !== null) {
            $result[] = new HealthAssessmentComponent(
                $this->reference($input, $component) . self::OVER_LIMIT_SUFFIX,
                $overLimit,
                HealthComponentTreatment::Included,
                HealthComponentTreatment::Included,
                $correction,
            );
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $input
     * @return list<IncomeTaxComponent>
     */
    public function incomeTax(
        array $input,
        string $periodStart,
    ): array {
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
        $overLimit = $this->overLimitAmount($input);
        $amount = $this->integer($input['amount_minor'] ?? null, 'input.amount_minor');
        $correction = $this->isCurrentPeriod($input, $periodStart)
            ? TaxCorrectionTreatment::CurrentMonth
            : TaxCorrectionTreatment::Unverified;
        // Doklad k osvobození nevzniká tady, ale ve sdíleném
        // {@see PayrollExemptionEvidence}. Kdyby si ho mapper odvozoval sám,
        // rozešel by se se sestavovačem zákonných vstupů — a přesně tím
        // rozporem osvobozená složka nikdy neprošla: sestavovač ji pustil,
        // výpočet daně ji shodil do ručního posouzení.
        $evidence = $treatment === IncomeTaxComponentTreatment::Exempt
            ? PayrollExemptionEvidence::resolve($input)
            : null;
        $result = [
            new IncomeTaxComponent(
                $this->reference($input, $component),
                $overLimit === null ? $amount : $amount - $overLimit,
                $treatment,
                $correction,
                $evidence?->status ?? TaxEvidenceStatus::Unverified,
                $evidence?->effectiveFrom,
                $evidence?->effectiveTo,
                $evidence?->reference,
            ),
        ];
        if ($overLimit !== null) {
            $result[] = new IncomeTaxComponent(
                $this->reference($input, $component) . self::OVER_LIMIT_SUFFIX,
                $overLimit,
                IncomeTaxComponentTreatment::Included,
                $correction,
            );
        }

        return $result;
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
