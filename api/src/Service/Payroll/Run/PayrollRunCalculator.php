<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Calculation\Money;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefinitionFactory;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class PayrollRunCalculator
{
    private const TOTAL_KEYS = [
        'source_amount_minor',
        'cash_payable_minor',
        'tax_base_minor',
        'social_base_minor',
        'health_base_minor',
        'average_earning_base_minor',
        'enforcement_base_minor',
        'jmhz_amount_minor',
    ];

    public function __construct(
        private readonly PayrollComponentDefinitionFactory $definitionFactory,
    ) {}

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function calculate(array $snapshot): array
    {
        if (!in_array(
            $snapshot['schema_version'] ?? null,
            ['payroll-run-input.v1', 'payroll-run-input.v2'],
            true,
        )) {
            throw new \InvalidArgumentException('Nepodporované schéma vstupního snapshotu.');
        }
        $people = $this->rows($snapshot['people'] ?? null, 'people');
        usort($people, static fn (array $left, array $right): int =>
            self::nestedId($left, 'employee') <=> self::nestedId($right, 'employee')
        );

        $personResults = [];
        $companyTotals = $this->emptyTotals();
        /** @var array<string,array{debit_code:string,credit_code:string,amount_minor:int}> $accounting */
        $accounting = [];
        foreach ($people as $person) {
            $employee = $this->row($person['employee'] ?? null, 'employee');
            $employeeId = $this->positiveInt($employee['id'] ?? null, 'employee.id');
            $employments = $this->rows($person['employments'] ?? null, 'employments');
            usort($employments, static fn (array $left, array $right): int =>
                self::nestedId($left, 'employment')
                <=> self::nestedId($right, 'employment')
            );

            $employmentResults = [];
            $personTotals = $this->emptyTotals();
            foreach ($employments as $employmentSnapshot) {
                $employment = $this->row(
                    $employmentSnapshot['employment'] ?? null,
                    'employment',
                );
                $employmentId = $this->positiveInt(
                    $employment['id'] ?? null,
                    'employment.id',
                );
                $inputs = $this->rows($employmentSnapshot['inputs'] ?? null, 'inputs');
                usort($inputs, static fn (array $left, array $right): int =>
                    self::id($left) <=> self::id($right)
                );

                $employmentTotals = $this->emptyTotals();
                $inputResults = [];
                foreach ($inputs as $input) {
                    $component = $this->row(
                        $input['component'] ?? null,
                        'input.component',
                    );
                    $amountMinor = $this->int(
                        $input['amount_minor'] ?? null,
                        'input.amount_minor',
                    );
                    $definition = $this->definitionFactory->fromArray($component);
                    $impact = $definition->impact(new Money($amountMinor));
                    $inputTotals = [
                        'source_amount_minor' => $impact->sourceAmount->minorUnits,
                        'cash_payable_minor' => $impact->cashPayable->minorUnits,
                        'tax_base_minor' => $impact->taxBase->minorUnits,
                        'social_base_minor' => $impact->socialBase->minorUnits,
                        'health_base_minor' => $impact->healthBase->minorUnits,
                        'average_earning_base_minor' =>
                            $impact->averageEarningBase->minorUnits,
                        'enforcement_base_minor' => $impact->enforcementBase->minorUnits,
                        'jmhz_amount_minor' => $impact->jmhzAmount->minorUnits,
                    ];
                    $employmentTotals = $this->addTotals(
                        $employmentTotals,
                        $inputTotals,
                    );
                    if ($definition->accountingDebitCode !== null
                        && $definition->accountingCreditCode !== null
                    ) {
                        $accountingKey = $definition->accountingDebitCode
                            . "\0"
                            . $definition->accountingCreditCode;
                        $accounting[$accountingKey] ??= [
                            'debit_code' => $definition->accountingDebitCode,
                            'credit_code' => $definition->accountingCreditCode,
                            'amount_minor' => 0,
                        ];
                        $accounting[$accountingKey]['amount_minor'] = (new Money(
                            $accounting[$accountingKey]['amount_minor'],
                        ))->add(new Money($amountMinor))->minorUnits;
                    }
                    $inputResults[] = [
                        'input_id' => $this->positiveInt($input['id'] ?? null, 'input.id'),
                        'component_code' => (string) ($component['code'] ?? ''),
                        'totals' => $inputTotals,
                        'withholding_candidate' => $impact->withholdingCandidate,
                        'statistics_included' => $impact->statisticsIncluded,
                        'accounting' => [
                            'debit_code' => $definition->accountingDebitCode,
                            'credit_code' => $definition->accountingCreditCode,
                            'amount_minor' => $amountMinor,
                        ],
                    ];
                }
                $personTotals = $this->addTotals($personTotals, $employmentTotals);
                $employmentResults[] = [
                    'employment_id' => $employmentId,
                    'inputs' => $inputResults,
                    'totals' => $employmentTotals,
                ];
            }
            $companyTotals = $this->addTotals($companyTotals, $personTotals);
            $personResults[] = [
                'employee_id' => $employeeId,
                'employments' => $employmentResults,
                'totals' => $personTotals,
            ];
        }

        ksort($accounting, SORT_STRING);
        return [
            'schema_version' => 'payroll-run-result.v1',
            'source_snapshot_hash' => hash('sha256', CanonicalJson::encode($snapshot)),
            'people' => $personResults,
            'totals' => $companyTotals,
            'accounting_totals' => array_values($accounting),
        ];
    }

    /** @return array<string,int> */
    private function emptyTotals(): array
    {
        return array_fill_keys(self::TOTAL_KEYS, 0);
    }

    /**
     * @param array<string,int> $left
     * @param array<string,int> $right
     * @return array<string,int>
     */
    private function addTotals(array $left, array $right): array
    {
        foreach (self::TOTAL_KEYS as $key) {
            $left[$key] = (new Money($left[$key]))
                ->add(new Money($right[$key]))
                ->minorUnits;
        }
        return $left;
    }

    /** @return array<string,mixed> */
    private function row(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }
        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        return array_map(
            fn (mixed $row): array => $this->row($row, $field),
            $value,
        );
    }

    private function int(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException("{$field} musí být celé číslo.");
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $result = $this->int($value, $field);
        if ($result <= 0) {
            throw new \UnexpectedValueException("{$field} musí být kladné.");
        }
        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function id(array $row): int
    {
        return (int) ($row['id'] ?? 0);
    }

    /** @param array<string,mixed> $row */
    private static function nestedId(array $row, string $key): int
    {
        $nested = $row[$key] ?? null;
        return is_array($nested) ? (int) ($nested['id'] ?? 0) : 0;
    }
}
