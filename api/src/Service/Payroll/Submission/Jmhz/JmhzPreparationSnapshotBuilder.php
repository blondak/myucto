<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzPreparationSnapshotBuilder
{
    public const LEGACY_BUILDER_VERSION = 'jmhz-preparation-source.v1';
    public const BUILDER_VERSION = 'jmhz-preparation-source.v2';

    private ?JmhzScenario1SelectorResolver $scenarioSelector = null;

    /**
     * @param array<string,mixed> $source
     * @param array<int,array<string,mixed>> $identitySources
     * @param array<int,array<string,mixed>> $mappingSources
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $sourceIssues
     */
    public function build(
        int $supplierId,
        string $environment,
        array $source,
        array $identitySources,
        array $mappingSources,
        array $sourceIssues = [],
    ): JmhzPreparationSnapshot {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musi byt kladne cislo.');
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            $this->invalid('jmhz_preparation_environment_invalid', 'Prostredi pripravy JMHZ neni platne.');
        }
        $revision = $this->object($source['revision'] ?? null, 'revision');
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        $runId = $this->positiveInt($revision['run_id'] ?? null, 'revision.run_id');
        $revisionNo = $this->positiveInt($revision['revision_no'] ?? null, 'revision.revision_no');
        if (($revision['status'] ?? null) !== 'approved'
            || ($revision['current_revision_no'] ?? null) !== $revisionNo
        ) {
            $this->invalid(
                'jmhz_revision_not_current_approved',
                'Priprava JMHZ vyzaduje aktualni schvalenou mzdovou revizi.',
            );
        }
        $periodStart = $this->date($revision['period_start'] ?? null, 'revision.period_start');
        if (!str_ends_with($periodStart, '-01')) {
            $this->invalid('jmhz_period_invalid', 'Obdobi JMHZ nezacina prvnim dnem mesice.');
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')
            ->format('Y-m-d');
        $input = $this->canonicalSnapshot(
            $revision['input_snapshot_json'] ?? null,
            $revision['input_snapshot_hash'] ?? null,
            'revision.input_snapshot',
        );
        $result = $this->canonicalSnapshot(
            $revision['result_snapshot_json'] ?? null,
            $revision['result_snapshot_hash'] ?? null,
            'revision.result_snapshot',
        );
        if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2'
            || ($input['supplier_id'] ?? null) !== $supplierId
            || ($input['period_start'] ?? null) !== $periodStart
        ) {
            $this->invalid(
                'jmhz_revision_input_mismatch',
                'Zmrazeny vstup neodpovida firme nebo obdobi JMHZ.',
            );
        }
        if (($result['schema_version'] ?? null) !== 'payroll-run-result.v2'
            || ($result['source_snapshot_hash'] ?? null)
                !== ($revision['input_snapshot_hash'] ?? null)
        ) {
            $this->invalid(
                'jmhz_revision_result_mismatch',
                'Vysledek revize nevychazi ze stejneho zmrazeneho vstupu.',
            );
        }
        $resultPeople = $this->indexResultPeople($result);

        $issues = $sourceIssues;
        if (($revision['revision_kind'] ?? null) !== 'regular') {
            $issues[] = $this->issue(
                'jmhz_correction_revision_unsupported',
                'revision',
                $revisionId,
            );
        }

        $normalizedPeople = [];
        $sourceVersions = [];
        $seenEmployments = [];
        foreach ($this->rows($input['people'] ?? null, 'input.people') as $personIndex => $person) {
            $employee = $this->object($person['employee'] ?? null, "input.people.{$personIndex}.employee");
            $employeeId = $this->positiveInt($employee['id'] ?? null, 'employee.id');
            $personResult = $resultPeople[$employeeId] ?? null;
            if (!is_array($personResult)) {
                $this->invalid(
                    'jmhz_result_person_set_mismatch',
                    'Vysledek revize nepokryva presne zmrazene osoby.',
                );
            }
            $employmentResults = $this->indexResultEmployments($personResult);
            $normalizedEmployments = [];
            foreach ($this->rows($person['employments'] ?? null, "input.people.{$personIndex}.employments") as $employmentIndex => $entry) {
                $employment = $this->object(
                    $entry['employment'] ?? null,
                    "input.people.{$personIndex}.employments.{$employmentIndex}.employment",
                );
                $employmentId = $this->positiveInt($employment['id'] ?? null, 'employment.id');
                if (($employment['employee_id'] ?? null) !== $employeeId || isset($seenEmployments[$employmentId])) {
                    $this->invalid('jmhz_employment_scope_mismatch', 'Zmrazeny vztah nema jednoznacneho vlastnika.');
                }
                $seenEmployments[$employmentId] = true;
                $term = $entry['term'] ?? null;
                $scenarioResolution = null;
                if (!is_array($term) || array_is_list($term)) {
                    $issues[] = $this->issue('effective_term_missing', 'employment', $employmentId);
                } else {
                    $this->inspectTerm($term, $employmentId, $issues);
                    $selection = $this->scenarioSelector()->resolve(
                        is_string($term['activity_code'] ?? null)
                            ? $term['activity_code']
                            : null,
                        is_string($term['jmhz_relationship_detail_code'] ?? null)
                            ? $term['jmhz_relationship_detail_code']
                            : null,
                    );
                    if (!$selection['supported']) {
                        $issueCode = $selection['issue_code'];
                        if (!is_string($issueCode)) {
                            throw new \UnexpectedValueException('Resolver scénáře JMHZ nevrátil blocker.');
                        }
                        $issues[] = $this->issue(
                            $issueCode,
                            'employment',
                            $employmentId,
                            $selection['attribute_ids'],
                        );
                    } else {
                        $scenarioResolution = $selection['evidence'];
                    }
                }
                $this->inspectWorkMonth($entry['time_month'] ?? null, $employmentId, $issues);
                $issues[] = $this->issue('eldp_block_missing', 'employment', $employmentId, [
                    '10240', '10241', '10242', '10245', '10356', '10357',
                    '10358', '10359', '10360', '10362', '10366', '10375',
                    '10462', '10463', '10464', '10465', '10466', '10468',
                    '10469', '10473', '10474', '10475',
                ]);
                $componentMappings = [];
                $earnings = [];
                foreach ($this->rows($entry['inputs'] ?? null, 'employment.inputs') as $inputRow) {
                    $component = $this->object($inputRow['component'] ?? null, 'input.component');
                    $componentId = $this->positiveInt($component['component_id'] ?? null, 'component.component_id');
                    if (!hash_equals(
                        $this->hash(
                            $inputRow['component_snapshot_hash'] ?? null,
                            'input.component_snapshot_hash',
                        ),
                        hash('sha256', CanonicalJson::encode($component)),
                    )) {
                        $this->invalid(
                            'jmhz_component_snapshot_hash_mismatch',
                            'Otisk snapshotu mzdove slozky nesouhlasi.',
                        );
                    }
                    $treatment = $component['jmhz_treatment'] ?? null;
                    if ($treatment === 'manual_review') {
                        $issues[] = $this->issue('component_jmhz_manual_review', 'component', $componentId);
                    } elseif ($treatment === 'included') {
                        $mapping = $mappingSources[$componentId] ?? null;
                        if (!is_array($mapping)) {
                            $issues[] = $this->issue('component_jmhz_mapping_missing', 'component', $componentId);
                        } else {
                            $this->assertMapping($mapping, $componentId);
                            $componentMappings[] = $mapping;
                            $amount = $inputRow['amount_minor'] ?? null;
                            if (!is_int($amount) || $amount < 0) {
                                $issues[] = $this->issue(
                                    'jmhz_negative_or_deferred_income_unsupported',
                                    'component',
                                    $componentId,
                                );
                            } else {
                                $targets = [
                                    (string) $mapping['target_attribute_id'],
                                    ...$this->stringList(
                                        $mapping['ancestor_attribute_ids'] ?? null,
                                        'mapping.ancestor_attribute_ids',
                                    ),
                                ];
                                foreach (array_values(array_unique($targets)) as $target) {
                                    $earnings[$target] = $this->checkedAdd(
                                        $earnings[$target] ?? 0,
                                        $amount,
                                    );
                                }
                            }
                        }
                    } elseif ($treatment !== 'excluded') {
                        $issues[] = $this->issue('component_jmhz_treatment_invalid', 'component', $componentId);
                    }
                }
                usort(
                    $componentMappings,
                    static fn (array $left, array $right): int =>
                        (int) ($left['component_definition_id'] ?? 0)
                        <=> (int) ($right['component_definition_id'] ?? 0),
                );
                $identity = $identitySources[$employmentId] ?? null;
                if (!is_array($identity)) {
                    $issues[] = $this->issue('jmhz_identity_incomplete', 'employment', $employmentId, ['10051', '10228']);
                    $identity = null;
                } else {
                    $this->assertIdentity(
                        $identity,
                        $environment,
                        $employeeId,
                        $employmentId,
                    );
                }
                $employmentResult = $employmentResults[$employmentId] ?? null;
                if (!is_array($employmentResult)) {
                    $this->invalid(
                        'jmhz_result_employment_set_mismatch',
                        'Vysledek revize nepokryva presne zmrazene pracovni vztahy.',
                    );
                }
                $insurance = $this->inspectDiscounts(
                    $personResult,
                    $employmentId,
                    $issues,
                );
                ksort($earnings, SORT_STRING);
                $normalizedEmployments[] = [
                    'employment_id' => $employmentId,
                    'identity' => $identity,
                    'employment' => $employment,
                    'term' => $term,
                    'scenario_resolution' => $scenarioResolution,
                    'work_month' => $entry['time_month'] ?? null,
                    'earnings_by_attribute_minor' => $earnings,
                    'insurance' => $insurance,
                    'calculation' => $employmentResult,
                    'component_mappings' => $componentMappings,
                ];
                $identityVersions = null;
                if (is_array($identity)) {
                    $identityVersions = [
                        'identity_id' => $identity['identity']['id'] ?? null,
                        'identity_row_version' =>
                            $identity['identity']['row_version'] ?? null,
                        'person_external_id' =>
                            $identity['person_external_identifier']['id'] ?? null,
                        'person_external_row_version' =>
                            $identity['person_external_identifier']['row_version'] ?? null,
                        'employment_external_id' =>
                            $identity['employment_external_identifier']['id'] ?? null,
                        'employment_external_row_version' =>
                            $identity['employment_external_identifier']['row_version'] ?? null,
                        'employment_external_source_reference_hash' =>
                            $identity['employment_external_identifier']['source_reference_hash'] ?? null,
                    ];
                }
                $mappingVersions = array_map(
                    static fn (array $mapping): array => [
                        'mapping_id' => $mapping['mapping_id'] ?? null,
                        'mapping_row_version' => $mapping['mapping_row_version'] ?? null,
                        'mapping_hash' => $mapping['mapping_hash'] ?? null,
                    ],
                    $componentMappings,
                );
                $workSummary = is_array($entry['time_month'] ?? null)
                    ? ($entry['time_month']['jmhz_work_summary'] ?? null)
                    : null;
                $sourceVersions[] = [
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'term_id' => is_array($term) ? ($term['id'] ?? null) : null,
                    'term_row_version' => is_array($term)
                        ? ($term['row_version'] ?? null)
                        : null,
                    'scenario_resolution' => is_array($scenarioResolution)
                        ? [
                            'scenario_row_sha256' =>
                                $scenarioResolution['scenario_row_sha256'] ?? null,
                            'matrix_sha256' =>
                                $scenarioResolution['matrix_sha256'] ?? null,
                            'matrix_row_sha256' =>
                                $scenarioResolution['matrix_row_sha256'] ?? null,
                        ]
                        : null,
                    'work_summary_id' => is_array($workSummary)
                        ? ($workSummary['id'] ?? null)
                        : null,
                    'work_summary_sha256' => is_array($workSummary)
                        ? ($workSummary['summary_sha256'] ?? null)
                        : null,
                    'identity' => $identityVersions,
                    'mappings' => $mappingVersions,
                ];
            }
            if (count($employmentResults) !== count($normalizedEmployments)) {
                $this->invalid(
                    'jmhz_result_employment_set_mismatch',
                    'Vysledek revize obsahuje jinou mnozinu pracovnich vztahu.',
                );
            }
            $this->assertSocialRelationshipSet(
                $personResult,
                array_column($normalizedEmployments, 'employment_id'),
            );
            $normalizedPeople[] = [
                'employee_id' => $employeeId,
                'person_summary' => $personResult,
                'employments' => $normalizedEmployments,
            ];
        }
        if ($normalizedPeople === []) {
            $issues[] = $this->issue('jmhz_employment_set_empty', 'revision', $revisionId);
        }
        if (count($resultPeople) !== count($normalizedPeople)) {
            $this->invalid(
                'jmhz_result_person_set_mismatch',
                'Vysledek revize obsahuje jinou mnozinu osob.',
            );
        }
        $office = $source['office'] ?? null;
        if (!is_array($office)
            || !is_string($office['social_security_variable_symbol'] ?? null)
            || preg_match('/^[0-9]{1,10}$/D', $office['social_security_variable_symbol']) !== 1
        ) {
            $issues[] = $this->issue('social_security_variable_symbol_missing', 'run', $runId, ['10221']);
            $office = null;
        }

        $issues = $this->normalizeIssues($issues);
        $payload = [
            'schema_reference' => JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'run_id' => $runId,
                'source_revision_id' => $revisionId,
                'revision_no' => $revisionNo,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'scenario_key' => 'scenario_1',
            ],
            'specification' => [
                'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
                'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
                'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
                'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
                'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
                'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
            ],
            'source_revision' => [
                'input_snapshot_hash' => $this->hash($revision['input_snapshot_hash'] ?? null, 'input_snapshot_hash'),
                'result_snapshot_hash' => $this->hash($revision['result_snapshot_hash'] ?? null, 'result_snapshot_hash'),
                'ruleset_manifest_hash' => $this->hash($revision['ruleset_manifest_hash'] ?? null, 'ruleset_manifest_hash'),
            ],
            'header' => [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'environment' => $environment,
            ],
            'employer_summary' => [
                'employer' => $input['employer'] ?? null,
                'office' => $office,
            ],
            'people' => $normalizedPeople,
            'source_versions' => [
                'office_id' => is_array($office) ? ($office['id'] ?? null) : null,
                'employments' => $sourceVersions,
            ],
            'readiness_issue_codes' => array_column($issues, 'code'),
            'readiness_issues' => $issues,
        ];

        return new JmhzPreparationSnapshot($payload, $issues);
    }

    private function scenarioSelector(): JmhzScenario1SelectorResolver
    {
        return $this->scenarioSelector ??= JmhzScenario1SelectorResolver::load();
    }

    /**
     * @param array<string,mixed> $result
     * @return array<int,array<string,mixed>>
     */
    private function indexResultPeople(array $result): array
    {
        $indexed = [];
        foreach ($this->rows($result['people'] ?? null, 'result.people') as $person) {
            $employeeId = $this->positiveInt(
                $person['employee_id'] ?? null,
                'result.employee_id',
            );
            if (isset($indexed[$employeeId])) {
                $this->invalid(
                    'jmhz_result_person_set_mismatch',
                    'Vysledek revize obsahuje osobu vicekrat.',
                );
            }
            $indexed[$employeeId] = $person;
        }
        ksort($indexed, SORT_NUMERIC);
        return $indexed;
    }

    /**
     * @param array<string,mixed> $person
     * @return array<int,array<string,mixed>>
     */
    private function indexResultEmployments(array $person): array
    {
        $indexed = [];
        foreach ($this->rows(
            $person['employments'] ?? null,
            'result.person.employments',
        ) as $employment) {
            $employmentId = $this->positiveInt(
                $employment['employment_id'] ?? null,
                'result.employment_id',
            );
            if (isset($indexed[$employmentId])) {
                $this->invalid(
                    'jmhz_result_employment_set_mismatch',
                    'Vysledek revize obsahuje pracovni vztah vicekrat.',
                );
            }
            $indexed[$employmentId] = $employment;
        }
        ksort($indexed, SORT_NUMERIC);
        return $indexed;
    }

    /**
     * @param array<string,mixed> $personResult
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @param-out list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @return array<string,mixed>|null
     */
    private function inspectDiscounts(
        array $personResult,
        int $employmentId,
        array &$issues,
    ): ?array {
        $statutory = $personResult['statutory'] ?? null;
        $social = is_array($statutory)
            ? ($statutory['social_insurance'] ?? null)
            : null;
        if (!is_array($social) || array_is_list($social)
            || ($social['status'] ?? null) !== 'calculated'
        ) {
            $issues[] = $this->issue(
                'jmhz_social_result_not_calculated',
                'employment',
                $employmentId,
                ['10370', '10371', '10481', '10482'],
            );
            return null;
        }
        $employeeDiscount = $social['working_pensioner_discount_minor_units'] ?? null;
        if (!is_int($employeeDiscount) || $employeeDiscount < 0) {
            $issues[] = $this->issue(
                'jmhz_social_result_not_calculated',
                'employment',
                $employmentId,
            );
        } elseif ($employeeDiscount > 0) {
            $issues[] = $this->issue(
                'jmhz_employee_social_discount_unsupported',
                'employment',
                $employmentId,
            );
        }
        $matched = null;
        foreach ($this->rows(
            $social['relationships'] ?? null,
            'social_insurance.relationships',
        ) as $relationship) {
            if (($relationship['relationship_id'] ?? null)
                === "employment:{$employmentId}"
            ) {
                if ($matched !== null) {
                    $this->invalid(
                        'jmhz_social_relationship_mismatch',
                        'Socialni vysledek obsahuje vztah vicekrat.',
                    );
                }
                $matched = $relationship;
            }
        }
        if ($matched === null) {
            $this->invalid(
                'jmhz_social_relationship_mismatch',
                'Socialni vysledek nepokryva pracovni vztah.',
            );
        }
        if (($matched['part_time_employer_discount'] ?? null) !== 'not_claimed') {
            $issues[] = $this->issue(
                'jmhz_employer_part_time_discount_unsupported',
                'employment',
                $employmentId,
                ['10372', '10373', '10374'],
            );
        }
        return $matched;
    }

    /**
     * @param array<string,mixed> $personResult
     * @param list<int> $employmentIds
     */
    private function assertSocialRelationshipSet(
        array $personResult,
        array $employmentIds,
    ): void {
        $statutory = $this->object(
            $personResult['statutory'] ?? null,
            'person_result.statutory',
        );
        $social = $this->object(
            $statutory['social_insurance'] ?? null,
            'person_result.statutory.social_insurance',
        );
        $actual = [];
        foreach ($this->rows(
            $social['relationships'] ?? null,
            'social_insurance.relationships',
        ) as $relationship) {
            $relationshipId = $relationship['relationship_id'] ?? null;
            if (!is_string($relationshipId)
                || preg_match('/^employment:([1-9][0-9]*)$/D', $relationshipId, $matches) !== 1
            ) {
                $this->invalid(
                    'jmhz_social_relationship_mismatch',
                    'Socialni vysledek obsahuje neplatny vztah.',
                );
            }
            $actual[] = (int) $matches[1];
        }
        sort($actual, SORT_NUMERIC);
        sort($employmentIds, SORT_NUMERIC);
        if ($actual !== $employmentIds) {
            $this->invalid(
                'jmhz_social_relationship_mismatch',
                'Socialni vysledek neobsahuje presne zmrazene pracovni vztahy.',
            );
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid('jmhz_source_invalid', "{$field} musi byt seznam.");
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                $this->invalid('jmhz_source_invalid', "{$field} obsahuje neplatnou hodnotu.");
            }
            $result[] = $item;
        }
        return $result;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            $this->invalid('jmhz_amount_overflow', 'Agregace castky JMHZ pretekla.');
        }
        return $left + $right;
    }

    /** @param array<string,mixed> $mapping */
    private function assertMapping(array $mapping, int $componentId): void
    {
        if ($mapping['component_definition_id'] !== $componentId
            || $mapping['package_key']
                !== JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY
            || $mapping['spec_manifest_sha256']
                !== JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256
        ) {
            $this->invalid(
                'jmhz_component_mapping_mismatch',
                'Mapovani mzdove slozky neodpovida pripnutemu baliku JMHZ.',
            );
        }
        $mappingHash = $this->hash(
            $mapping['mapping_hash'] ?? null,
            'mapping.mapping_hash',
        );
        $expected = [
            'supplier_id' => $mapping['supplier_id'] ?? null,
            'component_definition_id' => $mapping['component_definition_id'],
            'mapping_id' => $mapping['mapping_id'] ?? null,
            'mapping_row_version' => $mapping['mapping_row_version'] ?? null,
            'package_key' => $mapping['package_key'],
            'spec_manifest_sha256' => $mapping['spec_manifest_sha256'],
            'target_attribute_id' => $mapping['target_attribute_id'] ?? null,
            'target_xsd_mapping' => $mapping['target_xsd_mapping'] ?? null,
            'parent_attribute_id' => $mapping['parent_attribute_id'] ?? null,
            'ancestor_attribute_ids' => $mapping['ancestor_attribute_ids'] ?? null,
            'aggregation_role' => $mapping['aggregation_role'] ?? null,
            'aggregation_scope' => $mapping['aggregation_scope'] ?? null,
            'topology_hash' => $mapping['topology_hash'] ?? null,
        ];
        if (!hash_equals(
            $mappingHash,
            hash('sha256', CanonicalJson::encode($expected)),
        )) {
            $this->invalid(
                'jmhz_component_mapping_hash_mismatch',
                'Otisk mapovani mzdove slozky nesouhlasi.',
            );
        }
    }

    /** @param array<string,mixed> $identity */
    private function assertIdentity(
        array $identity,
        string $environment,
        int $employeeId,
        int $employmentId,
    ): void {
        $person = $identity['person_external_identifier'] ?? null;
        $employment = $identity['employment_external_identifier'] ?? null;
        $jmhzEmployment = $identity['jmhz_employment_external_identifier'] ?? null;
        $history = $identity['identity'] ?? null;
        if (!is_array($person) || !is_array($employment)
            || !is_array($jmhzEmployment) || !is_array($history)
            || ($person['identifier_type'] ?? null) !== 'ik_mpsv'
            || ($identity['jmhz_environment'] ?? null) !== $environment
            || ($employment['identifier_type'] ?? null) !== 'id_ppv'
            || ($history['employee_id'] ?? null) !== $employeeId
            || ($employment['environment'] ?? null) !== $environment
            || ($employment['employment_id'] ?? null) !== $employmentId
            || ($employment['employee_id'] ?? null) !== $employeeId
            || ($jmhzEmployment['id'] ?? null) !== ($employment['id'] ?? null)
            || ($jmhzEmployment['value'] ?? null) !== ($employment['value'] ?? null)
        ) {
            $this->invalid(
                'jmhz_identity_scope_mismatch',
                'Citliva identita JMHZ neodpovida osobe, vztahu nebo prostredi.',
            );
        }
    }

    /**
     * @param array<string,mixed> $term
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @param-out list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     */
    private function inspectTerm(array $term, int $employmentId, array &$issues): void
    {
        if (($term['jmhz_external_codebooks_verified_for_period'] ?? null) !== true) {
            $issues[] = $this->issue('jmhz_workplace_codebooks_unverified', 'employment', $employmentId, ['10229', '10230', '10231']);
        }
        foreach ([
            'jmhz_apz_contribution_status' => '10232',
            'jmhz_functional_benefits_status' => '10247',
            'jmhz_temporary_assignment_status' => '10251',
        ] as $field => $attributeId) {
            if (!in_array($term[$field] ?? null, ['yes', 'no'], true)) {
                $issues[] = $this->issue('jmhz_verified_boolean_missing', 'employment', $employmentId, [$attributeId]);
            }
        }
        if (($term['jmhz_apz_contribution_status'] ?? null) === 'yes'
            && !is_string($term['jmhz_apz_instrument_code'] ?? null)
        ) {
            $issues[] = $this->issue('jmhz_apz_instrument_missing', 'employment', $employmentId, ['10233']);
        }
        if (($term['jmhz_temporary_assignment_status'] ?? null) === 'yes') {
            $issues[] = $this->issue('jmhz_temporary_assignment_unsupported', 'employment', $employmentId, ['10252', '10457', '10492', '10493', '10494']);
        }
        if (($term['risky_work'] ?? null) === true) {
            $issues[] = $this->issue('jmhz_risky_work_unsupported', 'employment', $employmentId, ['10273', '10274']);
        }
    }

    /**
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @param-out list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     */
    private function inspectWorkMonth(mixed $timeMonth, int $employmentId, array &$issues): void
    {
        if (!is_array($timeMonth) || array_is_list($timeMonth)
            || ($timeMonth['status'] ?? null) !== 'approved'
        ) {
            $issues[] = $this->issue('jmhz_work_month_not_approved', 'employment', $employmentId, ['10259', '10260', '10261', '10265', '10268']);
            return;
        }
        $summary = $timeMonth['jmhz_work_summary'] ?? null;
        if (!is_array($summary) || array_is_list($summary)
            || ($timeMonth['jmhz_work_summary_status'] ?? null) !== 'frozen_work_summary'
            || ($summary['derivation_version'] ?? null) !== 'jmhz-work-month.v2'
        ) {
            $issues[] = $this->issue('jmhz_work_summary_v2_missing', 'employment', $employmentId, ['10259', '10260', '10261', '10265', '10268']);
        }
    }

    /**
     * @param list<string> $attributeIds
     * @return array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}
     */
    private function issue(
        string $code,
        string $entityType,
        ?int $entityId,
        array $attributeIds = [],
    ): array
    {
        sort($attributeIds, SORT_STRING);
        return ['code' => $code, 'entity_type' => $entityType, 'entity_id' => $entityId, 'attribute_ids' => $attributeIds];
    }

    /**
     * @param list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}> $issues
     * @return list<array{code:string,entity_type:string,entity_id:?int,attribute_ids:list<string>}>
     */
    private function normalizeIssues(array $issues): array
    {
        usort($issues, static fn (array $left, array $right): int => [
            $left['code'], $left['entity_type'], $left['entity_id'] ?? 0, implode(',', $left['attribute_ids']),
        ] <=> [
            $right['code'], $right['entity_type'], $right['entity_id'] ?? 0, implode(',', $right['attribute_ids']),
        ]);
        $unique = [];
        foreach ($issues as $issue) {
            $unique[CanonicalJson::encode($issue)] = $issue;
        }
        return array_values($unique);
    }

    /** @return array<string,mixed> */
    private function canonicalSnapshot(mixed $json, mixed $expectedHash, string $field): array
    {
        if (!is_string($json) || $json === '') {
            $this->invalid('jmhz_snapshot_missing', "{$field} chybi.");
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new JmhzPreparationSnapshotException('jmhz_snapshot_invalid', "{$field} neni platny JSON.", $exception);
        }
        $object = $this->object($decoded, $field);
        $canonical = CanonicalJson::encode($object);
        if ($canonical !== $json || !hash_equals($this->hash($expectedHash, $field), hash('sha256', $canonical))) {
            $this->invalid('jmhz_snapshot_hash_mismatch', "Otisk {$field} nesouhlasi.");
        }
        return $object;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid('jmhz_source_invalid', "{$field} musi byt objekt.");
        }
        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            $this->invalid('jmhz_source_invalid', "{$field} musi byt seznam.");
        }
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                $this->invalid('jmhz_source_invalid', "{$field} obsahuje neplatny radek.");
            }
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            $this->invalid('jmhz_source_invalid', "{$field} musi byt kladne cele cislo.");
        }
        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $this->invalid('jmhz_source_invalid', "{$field} neni platne datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->invalid('jmhz_source_invalid', "{$field} neni platne datum.");
        }
        return $value;
    }

    private function hash(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            $this->invalid('jmhz_source_invalid', "{$field} neni platny SHA-256.");
        }
        return $value;
    }

    private function invalid(string $code, string $message): never
    {
        throw new JmhzPreparationSnapshotException($code, $message);
    }
}
