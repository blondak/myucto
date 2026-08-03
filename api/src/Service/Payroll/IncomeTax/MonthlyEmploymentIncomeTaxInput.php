<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use InvalidArgumentException;

final readonly class MonthlyEmploymentIncomeTaxInput
{
    public string $payerReference;

    /**
     * @param list<EmploymentRelationshipTaxInput> $relationships
     * @param list<TaxDeclarationEvidence> $declarations
     * @param list<TaxCreditClaim> $creditClaims
     * @param list<TaxChildClaim> $childClaims
     * @param list<ExternalEmployerTaxCertificate> $externalCertificates
     */
    public function __construct(
        public string $calculationDate,
        public string $employeeReference,
        public array $relationships,
        public array $declarations,
        public TaxResidenceEvidence $residence,
        public array $creditClaims = [],
        public array $childClaims = [],
        public ?AnnualTaxAccumulatorInput $annualAccumulator = null,
        public array $externalCertificates = [],
        ?string $payerReference = null,
    ) {
        EvidenceInterval::date($calculationDate);
        if (trim($employeeReference) === '') {
            throw new InvalidArgumentException('Tax employee reference must not be empty.');
        }
        if ($relationships === []) {
            throw new InvalidArgumentException('At least one employment relationship is required.');
        }
        $this->assertList($relationships, EmploymentRelationshipTaxInput::class);
        $resolvedPayerReference = $payerReference
            ?? $relationships[0]->payerReference;
        if (trim($resolvedPayerReference) === '') {
            throw new InvalidArgumentException('Tax payer reference must not be empty.');
        }
        $this->payerReference = $resolvedPayerReference;
        foreach ($relationships as $relationship) {
            if ($relationship->payerReference !== $resolvedPayerReference) {
                throw new InvalidArgumentException(
                    'All employment relationships must belong to the input payer.',
                );
            }
        }
        $this->assertList($declarations, TaxDeclarationEvidence::class);
        $this->assertList($creditClaims, TaxCreditClaim::class);
        $this->assertList($childClaims, TaxChildClaim::class);
        $this->assertList($externalCertificates, ExternalEmployerTaxCertificate::class);

        $year = (int) substr($calculationDate, 0, 4);
        if ($annualAccumulator !== null && $annualAccumulator->year !== $year) {
            throw new InvalidArgumentException(
                'Annual tax accumulator year must match the calculation date.',
            );
        }
    }

    /**
     * @param array<mixed> $values
     * @param class-string $class
     */
    private function assertList(array $values, string $class): void
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException('Income tax input collections must be lists.');
        }
        foreach ($values as $value) {
            if (!$value instanceof $class) {
                throw new InvalidArgumentException("Income tax input item must be {$class}.");
            }
        }
    }
}
