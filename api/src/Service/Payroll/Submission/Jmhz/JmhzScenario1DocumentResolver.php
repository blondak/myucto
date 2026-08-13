<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final class JmhzScenario1DocumentResolver
{
    public function resolve(
        JmhzVerifiedPreparationSnapshot $preparation,
        ?JmhzPvpojPreview $pvpoj,
        ?string $pvpojFailureCode = null,
    ): JmhzScenario1Resolution {
        if (!in_array($preparation->builderVersion, [
            JmhzPreparationSnapshotBuilder::PREVIOUS_V4_BUILDER_VERSION,
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
        ], true)) {
            return new JmhzScenario1Resolution(null, [
                $this->blocker(
                    'jmhz_scenario1_source_version_unsupported',
                    'preparation',
                    $preparation->id,
                ),
            ]);
        }

        $blockers = [];
        if (($preparation->readiness['status'] ?? null) !== 'source_ready') {
            $blockers[] = $this->blocker(
                'jmhz_preparation_not_ready',
                'preparation',
                $preparation->id,
            );
            foreach ($this->rows(
                $preparation->payload['readiness_issues'] ?? null,
            ) as $issue) {
                $attributeIds = $issue['attribute_ids'] ?? [];
                $blockers[] = $this->blocker(
                    is_string($issue['code'] ?? null)
                        ? $issue['code']
                        : 'jmhz_preparation_issue_invalid',
                    is_string($issue['entity_type'] ?? null)
                        ? $issue['entity_type']
                        : 'preparation',
                    is_int($issue['entity_id'] ?? null)
                        ? $issue['entity_id']
                        : null,
                    is_array($attributeIds) && array_is_list($attributeIds)
                        ? array_values(array_filter($attributeIds, 'is_string'))
                        : [],
                );
            }
        }

        $scope = $this->object($preparation->payload['scope'] ?? null);
        $sourceRevision = $this->object(
            $preparation->payload['source_revision'] ?? null,
        );
        $ordinaryEvidence = $preparation->builderVersion
            === JmhzPreparationSnapshotBuilder::BUILDER_VERSION
            ? $this->object($preparation->payload['ordinary_evidence'] ?? null)
            : [];
        $people = $this->rows($preparation->payload['people'] ?? null);
        if (count($people) > 1500) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_form_limit_exceeded',
                'revision',
                $preparation->sourceRevisionId,
                ['10015', '10488'],
            );
        }
        $month = (int) substr($preparation->periodStart, 5, 2);
        if ($month <= 3 || $month === 12) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_annual_fields_unsupported',
                'revision',
                $preparation->sourceRevisionId,
            );
        }

        $normalizedPeople = [];
        foreach ($people as $person) {
            $employeeId = is_int($person['employee_id'] ?? null)
                ? $person['employee_id']
                : null;
            $employments = $this->rows($person['employments'] ?? null);
            if (count($employments) !== 1) {
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_multiple_employments_unsupported',
                    'person',
                    $employeeId,
                    ['10286', '10344', '10370', '10371', '10481', '10482', '10495'],
                );
            }
            $personSummary = $this->object($person['person_summary'] ?? null);
            $statutory = $this->object($personSummary['statutory'] ?? null);
            if (($statutory['status'] ?? null) !== 'calculated') {
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_statutory_result_not_calculated',
                    'person',
                    $employeeId,
                );
            }
            $health = $this->calculatedResult(
                $statutory['health_insurance'] ?? null,
                'jmhz_scenario1_health_result_not_calculated',
                $employeeId,
                ['10371', '10482'],
                $blockers,
            );
            $tax = $this->calculatedResult(
                $statutory['income_tax'] ?? null,
                'jmhz_scenario1_income_tax_result_not_calculated',
                $employeeId,
                ['10297', '10298', '10305', '10306', '10535'],
                $blockers,
            );
            $net = $this->netResult(
                $statutory['net_pay'] ?? null,
                $employeeId,
                $blockers,
            );
            $this->inspectUnsupportedTax($tax, $employeeId, $blockers);
            $this->inspectDeductions($net, $employeeId, $blockers);

            $normalizedEmployments = [];
            foreach ($employments as $employment) {
                $employmentId = is_int($employment['employment_id'] ?? null)
                    ? $employment['employment_id']
                    : null;
                $employmentSource = $this->object($employment['employment'] ?? null);
                if (($employmentSource['is_primary'] ?? null) !== true) {
                    $blockers[] = $this->blocker(
                        'jmhz_primary_employment_unresolved',
                        'person',
                        $employeeId,
                        ['10495'],
                    );
                }
                $earnings = $this->earnings(
                    $employment['earnings_by_attribute_minor'] ?? null,
                );
                foreach (['10328', '10329', '10330', '10331'] as $attributeId) {
                    if (!array_key_exists($attributeId, $earnings)) {
                        $blockers[] = $this->blocker(
                            'jmhz_scenario1_earnings_vector_incomplete',
                            'employment',
                            $employmentId,
                            [$attributeId],
                        );
                    }
                }
                $earningsCzk = [];
                foreach ($earnings as $attributeId => $minor) {
                    $attributeId = (string) $attributeId;
                    $whole = $this->wholeCzk(
                        $minor,
                        $attributeId,
                        'employment',
                        $employmentId,
                        $blockers,
                    );
                    if ($whole !== null) {
                        $earningsCzk[$attributeId] = $whole;
                    }
                }
                ksort($earningsCzk, SORT_STRING);
                $identity = $this->object($employment['identity'] ?? null);
                $personIdentifier = $this->object(
                    $identity['person_external_identifier'] ?? null,
                );
                $employmentIdentifier = $this->object(
                    $identity['jmhz_employment_external_identifier'] ?? null,
                );
                $average = $this->object($employment['average_earning'] ?? null);
                $normalizedEmployments[] = [
                    'employment_id' => $employmentId,
                    'primary' => $employmentSource['is_primary'] ?? null,
                    'identity' => [
                        'person_external_identifier' => $personIdentifier['value'] ?? null,
                        'employment_external_identifier' => $employmentIdentifier['value'] ?? null,
                    ],
                    'selector' => $employment['scenario_resolution'] ?? null,
                    'term' => $employment['term'] ?? null,
                    'work_month' => $employment['work_month'] ?? null,
                    'eldp' => $employment['eldp'] ?? null,
                    'average_hourly' => [
                        'minor_units' => $average['average_hourly_minor'] ?? null,
                        'scale' => 2,
                    ],
                    'earnings_by_attribute_czk' => $earningsCzk,
                    'insurance' => $employment['insurance'] ?? null,
                ];
            }
            usort(
                $normalizedEmployments,
                static fn (array $left, array $right): int =>
                    (int) ($left['employment_id'] ?? 0)
                    <=> (int) ($right['employment_id'] ?? 0),
            );
            $normalizedPeople[] = [
                'employee_id' => $employeeId,
                'summary' => [
                    'income_total_czk' => $this->wholeCzk(
                        $this->nestedInt($personSummary, ['totals', 'jmhz_amount_minor']),
                        '10286',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    'net_income_czk' => $this->wholeCzk(
                        is_int($net['net_before_deductions_minor_units'] ?? null)
                            ? $net['net_before_deductions_minor_units']
                            : null,
                        '10344',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    'employee_health_czk' => $this->wholeCzk(
                        is_int($health['employee_contribution_minor_units'] ?? null)
                            ? $health['employee_contribution_minor_units']
                            : null,
                        '10371',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    'employer_health_czk' => $this->wholeCzk(
                        is_int($health['employer_contribution_minor_units'] ?? null)
                            ? $health['employer_contribution_minor_units']
                            : null,
                        '10482',
                        'person',
                        $employeeId,
                        $blockers,
                    ),
                    'deductions_recorded' => $ordinaryEvidence === []
                        ? null
                        : ($ordinaryEvidence['attribute_values']['10116'] ?? null),
                ],
                'employments' => $normalizedEmployments,
            ];
        }
        usort(
            $normalizedPeople,
            static fn (array $left, array $right): int =>
                (int) ($left['employee_id'] ?? 0)
                <=> (int) ($right['employee_id'] ?? 0),
        );

        $pvpojPayload = null;
        if ($pvpoj === null) {
            $blockers[] = $this->blocker(
                $pvpojFailureCode ?? 'jmhz_scenario1_pvpoj_unavailable',
                'revision',
                $preparation->sourceRevisionId,
            );
        } elseif ($pvpoj->supplierId !== $preparation->supplierId
            || $pvpoj->runId !== $preparation->runId
            || $pvpoj->revisionId !== $preparation->sourceRevisionId
            || $pvpoj->revisionNo !== $preparation->revisionNo
            || $pvpoj->period !== substr($preparation->periodStart, 0, 7)
            || ($pvpoj->source['revision_input_hash'] ?? null)
                !== ($sourceRevision['input_snapshot_hash'] ?? null)
        ) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_pvpoj_source_mismatch',
                'revision',
                $preparation->sourceRevisionId,
            );
        } else {
            $pvpojPayload = [
                'sha256' => $pvpoj->sha256(),
                'source' => $pvpoj->source,
                'values' => $pvpoj->pvpoj,
                'reconciliation' => $pvpoj->reconciliation,
            ];
        }

        if ($ordinaryEvidence === []) {
            $blockers[] = $this->blocker(
                'jmhz_attribute_10116_unresolved',
                'revision',
                $preparation->sourceRevisionId,
                ['10116'],
            );
            $blockers[] = $this->blocker(
                'jmhz_attribute_10546_unresolved',
                'revision',
                $preparation->sourceRevisionId,
                ['10546', '10547'],
            );
            $blockers[] = $this->blocker(
                'jmhz_interaction_in13_unresolved',
                'revision',
                $preparation->sourceRevisionId,
                ['10408', '10409', '10410'],
            );
            $blockers[] = $this->blocker(
                'jmhz_interaction_in28_unresolved',
                'revision',
                $preparation->sourceRevisionId,
                ['10347', '10348', '10349'],
            );
            $blockers[] = $this->blocker(
                'jmhz_interaction_in30_unresolved',
                'revision',
                $preparation->sourceRevisionId,
                ['10270', '10271', '10272'],
            );
        }
        $blockers = $this->normalizeBlockers($blockers);

        $candidate = new JmhzScenario1NormalizedDocument([
            'schema_reference' => JmhzScenario1NormalizedDocument::SCHEMA_REFERENCE,
            'scope' => $scope + [
                'submission_kind' => 'regular',
            ],
            'specification' => $preparation->payload['specification'] ?? null,
            'provenance' => [
                'preparation_id' => $preparation->id,
                'builder_version' => $preparation->builderVersion,
                'source_manifest_sha256' => $preparation->sourceManifestSha256,
                'readiness_sha256' => $preparation->readinessSha256,
                'snapshot_fingerprint' => $preparation->snapshotFingerprint,
                'source_revision' => $sourceRevision,
                'pvpoj_preview_sha256' => $pvpoj?->sha256(),
                'ordinary_evidence' => $preparation->payload['source_versions']['ordinary_evidence'] ?? null,
            ],
            'header' => [
                'type' => 'R',
                'variable_symbol' => $preparation->payload['employer_summary']['office']['social_security_variable_symbol'] ?? null,
                'year' => (int) substr($preparation->periodStart, 0, 4),
                'month' => $month,
                'individual_form_count' => count($normalizedPeople),
                'total_form_count' => count($normalizedPeople) + 2,
            ],
            'employer' => [
                'source' => $preparation->payload['employer_summary']['employer'] ?? null,
                'pvpoj' => $pvpojPayload,
            ],
            'people' => $normalizedPeople,
            'interactions' => [
                'IN13' => $ordinaryEvidence === [] ? null : false,
                'IN28' => $ordinaryEvidence === [] ? null : false,
                'IN30' => $ordinaryEvidence === [] ? null : false,
                'IN36' => $ordinaryEvidence === [] ? null : false,
            ],
        ]);

        return new JmhzScenario1Resolution($candidate, $blockers);
    }

    /**
     * @param list<JmhzScenario1Blocker> $blockers
     * @param list<string> $attributeIds
     * @return array<string,mixed>
     */
    private function calculatedResult(
        mixed $value,
        string $code,
        ?int $employeeId,
        array $attributeIds,
        array &$blockers,
    ): array {
        $result = $this->object($value);
        $issues = $result['issues'] ?? null;
        if (($result['status'] ?? null) !== 'calculated'
            || !is_array($issues) || !array_is_list($issues) || $issues !== []
        ) {
            $blockers[] = $this->blocker(
                $code,
                'person',
                $employeeId,
                $attributeIds,
            );
        }
        return $result;
    }

    /**
     * @param list<JmhzScenario1Blocker> $blockers
     * @return array<string,mixed>
     */
    private function netResult(
        mixed $value,
        ?int $employeeId,
        array &$blockers,
    ): array {
        $result = $this->object($value);
        if (!is_int($result['net_before_deductions_minor_units'] ?? null)
            || !is_int($result['deducted_minor_units'] ?? null)
            || !is_int($result['net_payable_minor_units'] ?? null)
            || !is_array($result['relationships'] ?? null)
            || !array_is_list($result['relationships'])
            || !is_array($result['deductions'] ?? null)
            || !array_is_list($result['deductions'])
        ) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_net_result_not_calculated',
                'person',
                $employeeId,
                ['10116', '10344'],
            );
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $tax
     * @param list<JmhzScenario1Blocker> $blockers
     */
    private function inspectUnsupportedTax(array $tax, ?int $employeeId, array &$blockers): void
    {
        $withholdingTax = $tax['withholding_tax_minor_units'] ?? null;
        $withholdingGroups = $tax['withholding_groups'] ?? null;
        if (!is_int($withholdingTax)
            || !is_array($withholdingGroups)
            || !array_is_list($withholdingGroups)
        ) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_income_tax_result_not_calculated',
                'person',
                $employeeId,
                ['10297', '10298', '10305', '10306', '10535'],
            );
            return;
        }
        if ($withholdingTax !== 0 || $withholdingGroups !== []) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_withholding_tax_unsupported',
                'person',
                $employeeId,
                ['10307', '10309'],
            );
        }
        $advance = $this->object($tax['advance_tax'] ?? null);
        foreach (['tax_credits_minor_units', 'tax_bonus_minor_units'] as $field) {
            if (is_int($advance[$field] ?? null) && $advance[$field] > 0) {
                $blockers[] = $this->blocker(
                    'jmhz_scenario1_tax_credit_breakdown_unavailable',
                    'person',
                    $employeeId,
                    ['10299', '10300', '10301', '10302', '10303', '10304'],
                );
                break;
            }
        }
    }

    /**
     * @param array<string,mixed> $net
     * @param list<JmhzScenario1Blocker> $blockers
     */
    private function inspectDeductions(array $net, ?int $employeeId, array &$blockers): void
    {
        $deducted = $net['deducted_minor_units'] ?? null;
        $deductions = $net['deductions'] ?? null;
        if (!is_int($deducted)
            || !is_array($deductions)
            || !array_is_list($deductions)
        ) {
            return;
        }
        if ($deducted !== 0 || $deductions !== []) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_deductions_unsupported',
                'person',
                $employeeId,
                ['10116', '10350', '10351', '10352', '10353'],
            );
        }
    }

    /** @param list<JmhzScenario1Blocker> $blockers */
    private function wholeCzk(
        ?int $minor,
        string $attributeId,
        string $entityType,
        ?int $entityId,
        array &$blockers,
    ): ?int {
        if ($minor === null) {
            return null;
        }
        if ($minor % 100 !== 0) {
            $blockers[] = $this->blocker(
                'jmhz_scenario1_whole_czk_required',
                $entityType,
                $entityId,
                [$attributeId],
            );
            return null;
        }
        return intdiv($minor, 100);
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $path
     */
    private function nestedInt(array $value, array $path): ?int
    {
        $current = $value;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return is_int($current) ? $current : null;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @return array<int|string,int> */
    private function earnings(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $attributeId => $minor) {
            if (!is_int($minor)) {
                continue;
            }
            $result[(string) $attributeId] = $minor;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        return array_values(array_filter(
            $value,
            static fn (mixed $row): bool => is_array($row) && !array_is_list($row),
        ));
    }

    /** @param list<string> $attributeIds */
    private function blocker(
        string $code,
        string $entityType,
        ?int $entityId,
        array $attributeIds = [],
    ): JmhzScenario1Blocker {
        sort($attributeIds, SORT_STRING);
        return new JmhzScenario1Blocker(
            $code,
            $entityType,
            $entityId,
            $attributeIds,
        );
    }

    /**
     * @param list<JmhzScenario1Blocker> $blockers
     * @return list<JmhzScenario1Blocker>
     */
    private function normalizeBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $key = $blocker->code . '|' . $blocker->entityType . '|'
                . ($blocker->entityId ?? '') . '|'
                . implode(',', $blocker->attributeIds);
            $unique[$key] = $blocker;
        }
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }
}
