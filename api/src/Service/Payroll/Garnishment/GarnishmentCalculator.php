<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use DateTimeImmutable;
use OverflowException;

final class GarnishmentCalculator
{
    public function calculate(GarnishmentInput $input): GarnishmentResult
    {
        $issues = $this->validateInput($input);
        if ($issues !== []) {
            return $this->manualReview($input, $issues);
        }

        [$protectedAmount, $protectedTrace] = $this->protectedAmount($input);
        $income = $input->income->garnishableMinorUnits;
        $remainder = max(0, $income - $protectedAmount);
        $thirdsBase = intdiv(
            min($remainder, EnforcementRuleset2026::FULLY_ATTACHABLE_THRESHOLD_MINOR_UNITS),
            300,
        ) * 300;
        $third = intdiv($thirdsBase, 3);
        $excess = max(
            0,
            $remainder - EnforcementRuleset2026::FULLY_ATTACHABLE_THRESHOLD_MINOR_UNITS,
        );
        $roundingTrace = [
            $protectedTrace,
            [
                'step' => 'thirds_base',
                'input_minor_units' =>
                    min($remainder, EnforcementRuleset2026::FULLY_ATTACHABLE_THRESHOLD_MINOR_UNITS),
                'multiple_minor_units' => 300,
                'rounding' => 'floor',
                'output_minor_units' => $thirdsBase,
            ],
            [
                'step' => 'one_third',
                'input_minor_units' => $thirdsBase,
                'divisor' => 3,
                'rounding' => 'exact_after_thirds_base',
                'output_minor_units' => $third,
            ],
            [
                'step' => 'fully_attachable_excess',
                'threshold_minor_units' =>
                    EnforcementRuleset2026::FULLY_ATTACHABLE_THRESHOLD_MINOR_UNITS,
                'output_minor_units' => $excess,
            ],
        ];

        if ($input->insolvency->mode === InsolvencyMode::ApprovedStandard) {
            $withheld = min($income, self::addExactly(self::addExactly($third, $third), $excess));
            $allocations = $withheld === 0
                ? []
                : [new GarnishmentAllocation('insolvency-administrator', 0, $withheld)];

            return new GarnishmentResult(
                $input->period,
                GarnishmentStatus::Supported,
                $income,
                $protectedAmount,
                $third,
                $excess,
                0,
                $withheld,
                $income - $withheld,
                false,
                true,
                $allocations,
                [],
                $roundingTrace,
                EnforcementRuleset2026::ID,
                EnforcementRuleset2026::canonicalHash(),
            );
        }

        $claims = $this->activeClaims($input->claims);
        $fourRule = $this->fourEnforcementRuleApplies($claims, $input->pensionEvidence, $third);
        $allocation = $this->allocateClaims($claims, $third, $excess, $fourRule);
        if ($allocation === null) {
            return $this->manualReview($input, ['employer_fee_iteration_did_not_converge']);
        }

        $allocations = [];
        foreach ($claims as $claim) {
            $first = $allocation['first'][$claim->id] ?? 0;
            $second = $allocation['second'][$claim->id] ?? 0;
            if ($first === 0 && $second === 0) {
                continue;
            }
            $allocations[] = new GarnishmentAllocation($claim->id, $first, $second);
        }
        usort(
            $allocations,
            static fn (GarnishmentAllocation $a, GarnishmentAllocation $b): int =>
                $a->claimId <=> $b->claimId,
        );

        $withheld = self::addExactly($allocation['claim_total'], $allocation['fee']);

        return new GarnishmentResult(
            $input->period,
            GarnishmentStatus::Supported,
            $income,
            $protectedAmount,
            $third,
            $excess,
            $allocation['fee'],
            $withheld,
            $income - $withheld,
            $fourRule,
            false,
            $allocations,
            [],
            $roundingTrace,
            EnforcementRuleset2026::ID,
            EnforcementRuleset2026::canonicalHash(),
        );
    }

    /**
     * @return array{
     *   first:array<string,int>,
     *   second:array<string,int>,
     *   fee:int,
     *   claim_total:int
     * }|null
     * @param list<DeductionClaim> $claims
     */
    private function allocateClaims(array $claims, int $third, int $excess, bool $fourRule): ?array
    {
        $requestedFee = 0;

        for ($iteration = 0; $iteration < 64; $iteration++) {
            $balances = [];
            foreach ($claims as $claim) {
                $balances[$claim->id] = $claim->outstandingMinorUnits;
            }

            $priorityCapacity = self::addExactly($third, $excess);
            $second = $this->allocatePriorityClaims($claims, $priorityCapacity, $balances);
            $priorityUsed = self::sumExactly($second);
            $excessUsed = max(0, $priorityUsed - $third);
            $unusedExcess = $excess - $excessUsed;
            $unusedSecondThird = $fourRule ? max(0, $third - min($priorityUsed, $third)) : 0;
            $generalBeforeFee = self::addExactly(
                self::addExactly($third, $unusedExcess),
                $unusedSecondThird,
            );
            $actualFee = min($requestedFee, $generalBeforeFee);
            $first = $this->allocateRankedClaims(
                $claims,
                $generalBeforeFee - $actualFee,
                $balances,
            );
            $claimTotal = self::addExactly(self::sumExactly($first), $priorityUsed);
            $grossWithholding = self::addExactly($claimTotal, $actualFee);

            $candidateFee = $this->hasEligibleFeeClaim($claims) && $grossWithholding > 0
                ? min(
                    EnforcementRuleset2026::EMPLOYER_FLAT_FEE_MAX_MINOR_UNITS,
                    $generalBeforeFee,
                    self::ceilOneThirdToWholeCrown($grossWithholding),
                )
                : 0;

            if ($candidateFee === $actualFee) {
                return [
                    'first' => $first,
                    'second' => $second,
                    'fee' => $actualFee,
                    'claim_total' => $claimTotal,
                ];
            }
            $requestedFee = $candidateFee;
        }

        return null;
    }

    /**
     * @param list<DeductionClaim> $claims
     * @param array<string, int> $balances
     * @return array<string, int>
     */
    private function allocatePriorityClaims(array $claims, int $capacity, array &$balances): array
    {
        $allocated = [];

        foreach ([
            ClaimCategory::CurrentMaintenance,
            ClaimCategory::MaintenanceArrears,
            ClaimCategory::SubstituteMaintenance,
        ] as $category) {
            $group = array_values(array_filter(
                $claims,
                static fn (DeductionClaim $claim): bool => $claim->category === $category,
            ));
            $used = $this->allocateProportionally(
                $group,
                $capacity,
                $balances,
                true,
            );
            $allocated = $this->mergeAllocation($allocated, $used);
            $capacity -= self::sumExactly($used);
            if ($capacity === 0) {
                return $allocated;
            }
        }

        $otherPriority = array_values(array_filter(
            $claims,
            static fn (DeductionClaim $claim): bool =>
                $claim->category === ClaimCategory::OtherPriority,
        ));
        $allocated = $this->mergeAllocation(
            $allocated,
            $this->allocateRankedClaims($otherPriority, $capacity, $balances),
        );

        return $allocated;
    }

    /**
     * @param list<DeductionClaim> $claims
     * @param array<string, int> $balances
     * @return array<string, int>
     */
    private function allocateRankedClaims(array $claims, int $capacity, array &$balances): array
    {
        usort($claims, self::claimOrder(...));
        $allocated = [];
        $offset = 0;

        while ($capacity > 0 && isset($claims[$offset])) {
            $priorityDate = $claims[$offset]->priorityDate;
            $group = [];
            while (isset($claims[$offset]) && $claims[$offset]->priorityDate === $priorityDate) {
                if (($balances[$claims[$offset]->id] ?? 0) > 0) {
                    $group[] = $claims[$offset];
                }
                $offset++;
            }
            if ($group === []) {
                continue;
            }

            $used = $this->allocateProportionally($group, $capacity, $balances, false);
            $allocated = $this->mergeAllocation($allocated, $used);
            $capacity -= self::sumExactly($used);
        }

        return $allocated;
    }

    /**
     * @param list<DeductionClaim> $claims
     * @param array<string, int> $balances
     * @return array<string, int>
     */
    private function allocateProportionally(
        array $claims,
        int $capacity,
        array &$balances,
        bool $useMaintenanceWeight,
    ): array {
        $available = 0;
        foreach ($claims as $claim) {
            $available = self::addExactly($available, $balances[$claim->id] ?? 0);
        }
        $remainingPool = min($capacity, $available);
        $allocated = [];

        while ($remainingPool > 0) {
            $active = array_values(array_filter(
                $claims,
                static fn (DeductionClaim $claim): bool => ($balances[$claim->id] ?? 0) > 0,
            ));
            if ($active === []) {
                break;
            }

            $weightTotal = 0;
            foreach ($active as $claim) {
                $weight = $useMaintenanceWeight
                    ? (int) $claim->maintenanceWeightMinorUnits
                    : $balances[$claim->id];
                $weightTotal = self::addExactly($weightTotal, $weight);
            }

            $remainders = [];
            $capped = false;
            $roundPool = $remainingPool;
            foreach ($active as $claim) {
                $weight = $useMaintenanceWeight
                    ? (int) $claim->maintenanceWeightMinorUnits
                    : $balances[$claim->id];
                $product = self::multiplyExactly($roundPool, $weight);
                $floorShare = intdiv($product, $weightTotal);
                $grant = min($floorShare, $balances[$claim->id]);
                if ($floorShare > $balances[$claim->id]) {
                    $capped = true;
                }
                if ($grant > 0) {
                    $allocated[$claim->id] = self::addExactly(
                        $allocated[$claim->id] ?? 0,
                        $grant,
                    );
                    $balances[$claim->id] -= $grant;
                    $remainingPool -= $grant;
                }
                $remainders[$claim->id] = $product % $weightTotal;
            }

            if ($remainingPool === 0) {
                break;
            }
            if ($capped) {
                continue;
            }

            usort($active, static function (DeductionClaim $a, DeductionClaim $b) use ($remainders): int {
                $remainderOrder = $remainders[$b->id] <=> $remainders[$a->id];

                return $remainderOrder !== 0 ? $remainderOrder : $a->id <=> $b->id;
            });
            foreach ($active as $claim) {
                if ($remainingPool === 0) {
                    break;
                }
                if ($balances[$claim->id] === 0) {
                    continue;
                }
                $allocated[$claim->id] = self::addExactly($allocated[$claim->id] ?? 0, 1);
                $balances[$claim->id]--;
                $remainingPool--;
            }
        }

        ksort($allocated, SORT_STRING);

        return $allocated;
    }

    /**
     * @param list<DeductionClaim> $claims
     */
    private function hasEligibleFeeClaim(array $claims): bool
    {
        foreach ($claims as $claim) {
            if (
                $claim->legalBasis === DeductionLegalBasis::Statutory
                && $claim->orderIssuedOn >= EnforcementRuleset2026::EMPLOYER_FLAT_FEE_ORDER_EFFECTIVE_FROM
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<DeductionClaim> $claims
     */
    private function fourEnforcementRuleApplies(
        array $claims,
        PensionEvidence $pensionEvidence,
        int $third,
    ): bool {
        $orders = [];
        foreach ($claims as $claim) {
            if (
                $claim->legalBasis === DeductionLegalBasis::Statutory
                && $claim->enforcementOrderId !== null
            ) {
                $orders[$claim->enforcementOrderId] = true;
            }
        }
        $statutoryCount = count($orders);
        if ($statutoryCount < 4) {
            return false;
        }

        return !(
            $pensionEvidence === PensionEvidence::Verified
            && $third < EnforcementRuleset2026::FOUR_ENFORCEMENT_PENSION_EXCEPTION_LIMIT_MINOR_UNITS
        );
    }

    /** @return array{int, array<string, int|string|bool>} */
    private function protectedAmount(GarnishmentInput $input): array
    {
        if ($input->hasMultiplePayers) {
            $amount = (int) $input->protectedAmountOverrideMinorUnits;

            return [
                $amount,
                [
                    'step' => 'protected_amount',
                    'court_decision_override' => true,
                    'rounding' => 'court_determined',
                    'output_minor_units' => $amount,
                ],
            ];
        }

        $allowanceCount = $input->eligibleDependants + ($input->eligibleSpouse ? 1 : 0);
        $factorNumerator =
            EnforcementRuleset2026::DEPENDANT_SHARE_DENOMINATOR
            + ($allowanceCount * EnforcementRuleset2026::DEPENDANT_SHARE_NUMERATOR);
        $numerator = self::multiplyExactly(
            EnforcementRuleset2026::PROTECTED_DEBTOR_BASE_MINOR_UNITS,
            $factorNumerator,
        );
        $denominator = EnforcementRuleset2026::DEPENDANT_SHARE_DENOMINATOR;
        $amount = self::ceilFractionToMultiple($numerator, $denominator, 100);

        return [
            $amount,
            [
                'step' => 'protected_amount',
                'court_decision_override' => false,
                'eligible_allowance_count' => $allowanceCount,
                'unrounded_numerator' => $numerator,
                'unrounded_denominator' => $denominator,
                'rounding_multiple_minor_units' => 100,
                'rounding' => 'ceil_after_sum',
                'output_minor_units' => $amount,
            ],
        ];
    }

    /** @return list<string> */
    private function validateInput(GarnishmentInput $input): array
    {
        $issues = [];
        if (!$this->isPeriod($input->period)) {
            $issues[] = 'invalid_payroll_period';
        }
        if (
            !$this->isDate($input->paymentDate)
            || $input->paymentDate < EnforcementRuleset2026::EFFECTIVE_FROM
            || $input->paymentDate > EnforcementRuleset2026::EFFECTIVE_TO
        ) {
            $issues[] = 'payment_date_outside_ruleset_2026';
        }
        if ($input->income->status !== GarnishmentStatus::Supported) {
            foreach ($input->income->issues as $incomeIssue) {
                $issues[] = "income:{$incomeIssue}";
            }
        }

        if ($input->hasMultiplePayers) {
            if ($input->protectedAmountOverrideMinorUnits === null) {
                $issues[] = 'multiple_payers_protected_amount_decision_missing';
            }
            if (!$input->protectedAmountOverrideVerified) {
                $issues[] = 'multiple_payers_protected_amount_decision_not_verified';
            }
        } else {
            if ($input->protectedAmountOverrideMinorUnits !== null) {
                $issues[] = 'protected_amount_override_without_multiple_payers';
            }
            if ($input->protectedAmountOverrideVerified) {
                $issues[] = 'protected_amount_decision_verified_without_multiple_payers';
            }
            if (!$input->dependantsEvidenceComplete) {
                $issues[] = 'dependants_evidence_incomplete';
            }
            if (!$input->spouseEvidenceComplete) {
                $issues[] = 'spouse_allowance_evidence_incomplete';
            }
        }

        $activeClaims = $this->activeClaims($input->claims);
        if (!$input->claimRegisterEvidenceComplete) {
            $issues[] = 'claim_register_evidence_incomplete';
        }
        $seen = [];
        foreach ($activeClaims as $claim) {
            if (isset($seen[$claim->id])) {
                $issues[] = "claim:{$claim->id}:duplicate_id";
                continue;
            }
            $seen[$claim->id] = true;
            if ($claim->priorityDate === null || !$this->isDate($claim->priorityDate)) {
                $issues[] = "claim:{$claim->id}:delivery_date_missing";
            }
            if (!$claim->priorityClassificationVerified) {
                $issues[] = "claim:{$claim->id}:priority_classification_not_verified";
            }

            if ($claim->legalBasis === DeductionLegalBasis::Statutory) {
                if (!$claim->legalTitleVerified) {
                    $issues[] = "claim:{$claim->id}:legal_title_not_verified";
                }
                if (!$claim->orderOrNoticeDelivered) {
                    $issues[] = "claim:{$claim->id}:order_or_notice_not_delivered";
                }
                if ($claim->orderIssuedOn === null || !$this->isDate($claim->orderIssuedOn)) {
                    $issues[] = "claim:{$claim->id}:order_issue_date_missing";
                }
                if (!$claim->dueMonetaryClaimVerified) {
                    $issues[] = "claim:{$claim->id}:due_monetary_claim_not_verified";
                }
                if ($claim->enforcementOrderId === null || trim($claim->enforcementOrderId) === '') {
                    $issues[] = "claim:{$claim->id}:enforcement_order_id_missing";
                }
            } else {
                if (!$claim->agreementVerified) {
                    $issues[] = "claim:{$claim->id}:deduction_agreement_not_verified";
                }
                if ($claim->category->isPriority()) {
                    $issues[] = "claim:{$claim->id}:voluntary_agreement_cannot_be_priority";
                }
            }

            if (
                in_array(
                    $claim->category,
                    [
                        ClaimCategory::CurrentMaintenance,
                        ClaimCategory::MaintenanceArrears,
                        ClaimCategory::SubstituteMaintenance,
                    ],
                    true,
                )
                && ($claim->maintenanceWeightMinorUnits ?? 0) <= 0
            ) {
                $issues[] = "claim:{$claim->id}:maintenance_weight_missing";
            }
        }

        $statutoryOrders = [];
        foreach ($activeClaims as $claim) {
            if (
                $claim->legalBasis === DeductionLegalBasis::Statutory
                && $claim->enforcementOrderId !== null
            ) {
                $statutoryOrders[$claim->enforcementOrderId] = true;
            }
        }
        $statutoryCount = count($statutoryOrders);
        if ($statutoryCount >= 4 && $input->pensionEvidence === PensionEvidence::Unknown) {
            $issues[] = 'four_enforcement_pension_exception_evidence_unknown';
        }

        if ($input->insolvency->mode !== InsolvencyMode::None) {
            if (!$input->insolvency->decisionVerified) {
                $issues[] = 'insolvency_decision_not_verified';
            }
            if (!$input->insolvency->recipientVerified) {
                $issues[] = 'insolvency_recipient_not_verified';
            }
            if ($activeClaims !== []) {
                $issues[] = 'concurrent_enforcement_with_insolvency_requires_manual_review';
            }
            if ($input->insolvency->mode === InsolvencyMode::AlertOnly) {
                $issues[] = 'insolvency_alert_cannot_redirect_payment';
            }
            if ($input->insolvency->mode === InsolvencyMode::CourtDeterminedAmount) {
                $issues[] = 'court_determined_insolvency_amount_requires_manual_review';
            }
        } elseif ($input->insolvency->courtDeterminedAmountMinorUnits !== null) {
            $issues[] = 'court_determined_amount_without_insolvency';
        }

        sort($issues, SORT_STRING);

        return array_values(array_unique($issues));
    }

    /**
     * @param list<DeductionClaim> $claims
     * @return list<DeductionClaim>
     */
    private function activeClaims(array $claims): array
    {
        $active = array_values(array_filter(
            $claims,
            static fn (DeductionClaim $claim): bool =>
                $claim->active && $claim->outstandingMinorUnits > 0,
        ));
        usort($active, self::claimOrder(...));

        return $active;
    }

    private static function claimOrder(DeductionClaim $left, DeductionClaim $right): int
    {
        $dateOrder = ($left->priorityDate ?? '') <=> ($right->priorityDate ?? '');

        return $dateOrder !== 0 ? $dateOrder : $left->id <=> $right->id;
    }

    /** @param list<string> $issues */
    private function manualReview(GarnishmentInput $input, array $issues): GarnishmentResult
    {
        sort($issues, SORT_STRING);
        $income = $input->income->garnishableMinorUnits;

        return new GarnishmentResult(
            $input->period,
            GarnishmentStatus::ManualReview,
            $income,
            0,
            0,
            0,
            0,
            0,
            $income,
            false,
            false,
            [],
            array_values(array_unique($issues)),
            [],
            EnforcementRuleset2026::ID,
            EnforcementRuleset2026::canonicalHash(),
        );
    }

    /**
     * @param array<string, int> $target
     * @param array<string, int> $source
     * @return array<string, int>
     */
    private function mergeAllocation(array $target, array $source): array
    {
        foreach ($source as $claimId => $amount) {
            $target[$claimId] = self::addExactly($target[$claimId] ?? 0, $amount);
        }
        ksort($target, SORT_STRING);

        return $target;
    }

    /** @param array<string, int> $amounts */
    private static function sumExactly(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total = self::addExactly($total, $amount);
        }

        return $total;
    }

    private static function ceilOneThirdToWholeCrown(int $minorUnits): int
    {
        $crowns = intdiv($minorUnits, 300);
        if ($minorUnits % 300 !== 0) {
            $crowns++;
        }

        return self::multiplyExactly($crowns, 100);
    }

    private static function ceilFractionToMultiple(
        int $numerator,
        int $denominator,
        int $multiple,
    ): int {
        $combinedDenominator = self::multiplyExactly($denominator, $multiple);
        $units = intdiv($numerator, $combinedDenominator);
        if ($numerator % $combinedDenominator !== 0) {
            $units++;
        }

        return self::multiplyExactly($units, $multiple);
    }

    private static function addExactly(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new OverflowException('Garnishment amount exceeds the integer range.');
        }

        return $left + $right;
    }

    private static function multiplyExactly(int $left, int $right): int
    {
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new OverflowException('Garnishment multiplication exceeds the integer range.');
        }

        return $left * $right;
    }

    private function isPeriod(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m', $value);

        return $parsed !== false && $parsed->format('Y-m') === $value;
    }

    private function isDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
