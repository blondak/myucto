<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthEvidence;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthRequest;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeItem;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeKind;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResolver;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentCalculation;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentRunIntegration;

final class PayrollRunGarnishmentProcessor
{
    public function __construct(
        private readonly GarnishmentCalculator $calculator,
        private readonly PayrollGarnishmentRunIntegration $integration,
        private readonly GarnishableIncomeResolver $incomeResolver =
            new GarnishableIncomeResolver(),
    ) {}

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $baseResult
     * @return array<string,mixed>
     */
    public function calculate(array $snapshot, array $baseResult): array
    {
        $supplierId = self::positiveInt($snapshot, 'supplier_id');
        $period = substr(self::string($snapshot, 'period_start'), 0, 7);
        $paymentDate = self::string($snapshot, 'payment_date');
        $requiresNetPay = ($snapshot['schema_version'] ?? null) === 'payroll-run-input.v2'
            && isset($baseResult['statutory']);
        $evidenceByEmployee = [];
        foreach (self::rows($snapshot['people'] ?? null, 'snapshot.people') as $person) {
            $employee = self::row($person['employee'] ?? null, 'snapshot.employee');
            $evidence = self::row(
                $person['enforcement_evidence'] ?? null,
                'snapshot.enforcement_evidence',
            );
            $evidenceByEmployee[self::positiveInt($employee, 'id')] =
                EnforcementPersonMonthEvidence::fromCanonicalArray($evidence);
        }

        $people = self::rows($baseResult['people'] ?? null, 'result.people');
        $withheldTotal = 0;
        $payableTotal = 0;
        foreach ($people as &$person) {
            $employeeId = self::positiveInt($person, 'employee_id');
            $totals = self::row($person['totals'] ?? null, 'result.person.totals');
            $grossCashPayable = self::int($totals, 'cash_payable_minor');
            $grossEnforcementBase = self::int(
                $totals,
                'enforcement_base_minor',
            );
            $cashPayable = $grossCashPayable;
            $enforcementBase = $grossEnforcementBase;
            $statutoryUnavailable = false;
            if ($requiresNetPay) {
                $statutory = self::row(
                    $person['statutory'] ?? null,
                    'result.person.statutory',
                );
                if (($statutory['status'] ?? null) === 'calculated'
                    && is_int($statutory['net_payable_minor_units'] ?? null)
                ) {
                    $excluded = $grossCashPayable - $grossEnforcementBase;
                    $cashPayable = (int) $statutory['net_payable_minor_units'];
                    $enforcementBase = $cashPayable - $excluded;
                } else {
                    $statutoryUnavailable = true;
                }
            }
            $income = $statutoryUnavailable
                ? new GarnishableIncomeResult(
                    GarnishmentStatus::ManualReview,
                    0,
                    0,
                    ['net_pay_result_missing_or_unverified'],
                    [],
                )
                : ($cashPayable < 0
                    || $enforcementBase < 0
                    || $enforcementBase > $cashPayable
                ? new GarnishableIncomeResult(
                    GarnishmentStatus::ManualReview,
                    0,
                    0,
                    ['cash_payable_enforcement_base_inconsistent'],
                    [],
                )
                : $this->incomeResolver->resolve(array_values(array_filter([
                    $enforcementBase === 0 ? null : new GarnishableIncomeItem(
                        "revision-person-{$employeeId}-garnishable",
                        GarnishableIncomeKind::Wage,
                        $enforcementBase,
                        "supplier-{$supplierId}",
                    ),
                    $cashPayable === $enforcementBase
                        ? null
                        : new GarnishableIncomeItem(
                            "revision-person-{$employeeId}-excluded",
                            GarnishableIncomeKind::TravelReimbursement,
                            $cashPayable - $enforcementBase,
                            "supplier-{$supplierId}",
                        ),
                ])), true));
            $evidence = $evidenceByEmployee[$employeeId]
                ?? throw new \UnexpectedValueException(
                    'Snapshot neobsahuje exekuční důkazy zaměstnance.',
                );
            $input = new GarnishmentInput(
                $period,
                $paymentDate,
                $income,
                $evidence->claims,
                $evidence->eligibleDependants,
                $evidence->dependantsEvidenceComplete,
                $evidence->eligibleSpouse,
                $evidence->spouseEvidenceComplete,
                $evidence->pensionEvidence,
                $evidence->hasMultiplePayers,
                $evidence->protectedAmountOverrideMinorUnits,
                $evidence->insolvency,
                $evidence->protectedAmountOverrideVerified,
                $evidence->claimRegisterEvidenceComplete,
            );
            $result = $this->calculator->calculate($input);
            $person['enforcement'] = [
                'input' => $input->toCanonicalArray(),
                'result' => $result->jsonSerialize(),
            ];
            $person['payable_after_enforcement_minor'] = self::add(
                $result->employeePaymentMinorUnits,
                $income->excludedMinorUnits,
            );
            $withheldTotal = self::add(
                $withheldTotal,
                $result->totalWithheldMinorUnits,
            );
            $payableTotal = self::add(
                $payableTotal,
                $person['payable_after_enforcement_minor'],
            );
        }
        unset($person);
        $baseResult['people'] = $people;
        $totals = self::row($baseResult['totals'] ?? null, 'result.totals');
        $totals['enforcement_withheld_minor'] = $withheldTotal;
        $totals['payable_after_enforcement_minor'] = $payableTotal;
        $baseResult['totals'] = $totals;

        return $baseResult;
    }

    /** @param array<string,mixed> $result */
    public function storeApproved(
        int $supplierId,
        int $revisionId,
        array $result,
    ): void {
        foreach (self::rows($result['people'] ?? null, 'result.people') as $person) {
            $employeeId = self::positiveInt($person, 'employee_id');
            $enforcement = self::row(
                $person['enforcement'] ?? null,
                'result.person.enforcement',
            );
            $inputData = self::row(
                $enforcement['input'] ?? null,
                'result.person.enforcement.input',
            );
            $resultData = self::row(
                $enforcement['result'] ?? null,
                'result.person.enforcement.result',
            );
            $input = GarnishmentInput::fromCanonicalArray($inputData);
            $calculated = GarnishmentResult::fromCanonicalArray($resultData);
            if ($calculated->status !== GarnishmentStatus::Supported) {
                throw new \DomainException(
                    'Mzdový běh obsahuje srážku vyžadující ruční kontrolu.',
                );
            }
            $request = new EnforcementPersonMonthRequest(
                $supplierId,
                $employeeId,
                $input->period,
                $input->paymentDate,
                [],
                true,
            );
            $this->integration->storeCalculation(
                $request,
                new PayrollGarnishmentCalculation(
                    $supplierId,
                    $employeeId,
                    $input,
                    $calculated,
                ),
                $revisionId,
                "payroll-revision:{$revisionId}:employee:{$employeeId}:enforcement:v1",
            );
        }
    }

    /** @param array<string,mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("{$key} musí být řetězec.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("{$key} musí být celé číslo.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function positiveInt(array $data, string $key): int
    {
        $value = self::int($data, $key);
        if ($value <= 0) {
            throw new \UnexpectedValueException("{$key} musí být kladné.");
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "{$field} musí mít textové klíče.",
                );
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        return array_map(
            static fn (mixed $row): array => self::row($row, $field),
            $value,
        );
    }

    private static function add(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new \OverflowException('Součet srážek přesahuje celočíselný rozsah.');
        }
        return $left + $right;
    }
}
