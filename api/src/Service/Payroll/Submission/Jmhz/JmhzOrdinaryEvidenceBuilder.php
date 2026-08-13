<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final class JmhzOrdinaryEvidenceBuilder
{
    public const BUILDER_VERSION = 'jmhz-ordinary-evidence.v1';

    private const FACT_KEYS = [
        'reportable_wage_deductions_recorded',
        'employee_social_discount_claimed',
        'specific_legal_fact_occurred',
        'ozp_employment_support_claimed',
        'deep_mining_work_occurred',
    ];

    /**
     * @param array<string,mixed> $facts
     * @return array<string,bool>
     */
    public static function normalizeFacts(array $facts): array
    {
        $keys = array_keys($facts);
        sort($keys, SORT_STRING);
        $expected = self::FACT_KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new JmhzOrdinaryEvidenceException(
                'jmhz_ordinary_evidence_incomplete',
                'Potvrzení musí obsahovat přesně všech pět právních skutečností.',
            );
        }
        $normalized = [];
        foreach (self::FACT_KEYS as $key) {
            if (!is_bool($facts[$key])) {
                throw new JmhzOrdinaryEvidenceException(
                    'jmhz_ordinary_evidence_invalid',
                    'Každá právní skutečnost musí být výslovné Ano nebo Ne.',
                );
            }
            if ($facts[$key] !== false) {
                throw new JmhzOrdinaryEvidenceException(
                    'jmhz_ordinary_evidence_positive_unsupported',
                    'První ordinary profil podporuje jen výslovné Ne u všech pěti skutečností.',
                );
            }
            $normalized[$key] = false;
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,bool> $facts
     */
    public function build(
        int $supplierId,
        array $source,
        array $facts,
        int $confirmedBy,
        string $confirmedAt,
    ): JmhzOrdinaryEvidenceSnapshot {
        $facts = self::normalizeFacts($facts);
        $revision = $this->object($source['revision'] ?? null, 'revision');
        $revisionId = $this->positiveInt($revision['id'] ?? null, 'revision.id');
        $runId = $this->positiveInt($revision['run_id'] ?? null, 'revision.run_id');
        $revisionNo = $this->positiveInt($revision['revision_no'] ?? null, 'revision.revision_no');
        if (($revision['status'] ?? null) !== 'approved'
            || ($revision['revision_kind'] ?? null) !== 'regular'
            || ($revision['current_revision_no'] ?? null) !== $revisionNo
        ) {
            $this->invalid(
                'jmhz_ordinary_evidence_revision_not_current_approved',
                'Potvrzení vyžaduje aktuální schválenou řádnou revizi.',
            );
        }
        $periodStart = $this->date($revision['period_start'] ?? null, 'period_start');
        if (!str_ends_with($periodStart, '-01')) {
            $this->invalid('jmhz_ordinary_evidence_period_invalid', 'Období nezačíná prvním dnem měsíce.');
        }
        $input = $this->canonicalSnapshot(
            $revision['input_snapshot_json'] ?? null,
            $revision['input_snapshot_hash'] ?? null,
            'input',
        );
        $result = $this->canonicalSnapshot(
            $revision['result_snapshot_json'] ?? null,
            $revision['result_snapshot_hash'] ?? null,
            'result',
        );
        if (($input['schema_version'] ?? null) !== 'payroll-run-input.v2'
            || ($input['supplier_id'] ?? null) !== $supplierId
            || ($input['period_start'] ?? null) !== $periodStart
            || ($result['schema_version'] ?? null) !== 'payroll-run-result.v2'
            || ($result['source_snapshot_hash'] ?? null) !== ($revision['input_snapshot_hash'] ?? null)
        ) {
            $this->invalid('jmhz_ordinary_evidence_source_mismatch', 'Zmrazený vstup neodpovídá firmě nebo období.');
        }
        [$employeeId, $employmentId, $person, $employment] = $this->singleEmployment($input);
        $this->assertNoKnownDeductionConflict($person, $result, $employeeId);
        $term = $this->object($employment['term'] ?? null, 'term');
        $selection = JmhzScenario1SelectorResolver::load()->resolve(
            $term['activity_code'] ?? null,
            $term['jmhz_relationship_detail_code'] ?? null,
        );
        if ($selection['supported'] !== true) {
            $this->invalid('jmhz_ordinary_evidence_scenario_unsupported', 'Revize nepatří do podporovaného scenario_1.');
        }

        $catalog = JmhzScenarioRequirementSourceCatalog::load();
        $interactions = [];
        foreach (['IN13', 'IN28', 'IN30'] as $interactionId) {
            $definition = $catalog->interaction($interactionId);
            $interactions[] = [
                'interaction_id' => $interactionId,
                'triggered' => false,
                'row_sha256' => $definition->rowHash,
            ];
        }
        $in36 = $catalog->interaction('IN36');
        $requirements = [];
        foreach ($catalog->requirementsForMatrix('scenario_1') as $requirement) {
            if (in_array($requirement->attributeId, ['10116', '10546'], true)) {
                $requirements[$requirement->attributeId] = $requirement->rowHash;
            }
        }
        if (count($requirements) !== 2
            || !isset($requirements[10116], $requirements[10546])
        ) {
            $this->invalid('jmhz_ordinary_evidence_catalog_mismatch', 'Katalog ordinary evidence není úplný.');
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('last day of this month')->format('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{6})?Z$/D', $confirmedAt) !== 1) {
            $this->invalid('jmhz_ordinary_evidence_confirmation_invalid', 'Čas potvrzení není kanonický UTC timestamp.');
        }
        $payload = [
            'schema_reference' => JmhzOrdinaryEvidenceSnapshot::SCHEMA_REFERENCE,
            'builder_version' => self::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => $supplierId,
                'run_id' => $runId,
                'source_revision_id' => $revisionId,
                'revision_no' => $revisionNo,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
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
                'attribute_requirement_row_sha256' => $requirements,
            ],
            'source_revision' => [
                'input_snapshot_hash' => $revision['input_snapshot_hash'],
                'result_snapshot_hash' => $revision['result_snapshot_hash'],
                'ruleset_manifest_hash' => $revision['ruleset_manifest_hash'],
            ],
            'attribute_values' => [
                '10116' => $facts['reportable_wage_deductions_recorded'],
                '10546' => $facts['employee_social_discount_claimed'],
            ],
            'interaction_decisions' => $interactions,
            'derived_interactions' => [[
                'interaction_id' => 'IN36',
                'triggered' => false,
                'source_attribute_id' => '10546',
                'row_sha256' => $in36->rowHash,
            ]],
            'confirmation' => [
                'source_kind' => 'explicit_confirmation',
                'confirmed_by_user_id' => $confirmedBy,
                'confirmed_at' => $confirmedAt,
            ],
        ];
        return new JmhzOrdinaryEvidenceSnapshot($payload);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{int,int,array<string,mixed>,array<string,mixed>}
     */
    private function singleEmployment(array $input): array
    {
        $people = $input['people'] ?? null;
        if (!is_array($people) || !array_is_list($people) || count($people) !== 1) {
            $this->invalid('jmhz_ordinary_evidence_scope_unsupported', 'První ordinary profil vyžaduje právě jednu osobu.');
        }
        $person = $this->object($people[0], 'person');
        $employee = $this->object($person['employee'] ?? null, 'employee');
        $employeeId = $this->positiveInt($employee['id'] ?? null, 'employee.id');
        $employments = $person['employments'] ?? null;
        if (!is_array($employments) || !array_is_list($employments) || count($employments) !== 1) {
            $this->invalid('jmhz_ordinary_evidence_scope_unsupported', 'První ordinary profil vyžaduje právě jeden pracovní vztah.');
        }
        $entry = $this->object($employments[0], 'employment entry');
        $employment = $this->object($entry['employment'] ?? null, 'employment');
        $employmentId = $this->positiveInt($employment['id'] ?? null, 'employment.id');
        if (($employment['employee_id'] ?? null) !== $employeeId) {
            $this->invalid('jmhz_ordinary_evidence_scope_mismatch', 'Pracovní vztah nepatří zmrazené osobě.');
        }
        return [$employeeId, $employmentId, $person, $entry];
    }

    /**
     * @param array<string,mixed> $person
     * @param array<string,mixed> $result
     */
    private function assertNoKnownDeductionConflict(
        array $person,
        array $result,
        int $employeeId,
    ): void
    {
        $agreements = $person['deduction_agreements'] ?? null;
        if (!is_array($agreements) || !array_is_list($agreements)) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Zmrazená evidence dohod o srážkách není úplná.');
        }
        $enforcement = $person['enforcement_evidence'] ?? null;
        if ($agreements !== []) {
            $this->invalid('jmhz_ordinary_evidence_deduction_conflict', 'Revize obsahuje evidovanou dohodu o srážkách.');
        }
        $enforcement = $this->object($enforcement, 'enforcement_evidence');
        $claims = $enforcement['claims'] ?? null;
        $insolvency = $this->object($enforcement['insolvency'] ?? null, 'insolvency');
        if (!is_array($claims) || !array_is_list($claims)
            || ($enforcement['claim_register_evidence_complete'] ?? null) !== true
            || $claims !== [] || ($insolvency['mode'] ?? null) !== 'none'
        ) {
            $this->invalid('jmhz_ordinary_evidence_deduction_conflict', 'Revize obsahuje exekuční nebo insolvenční evidenci.');
        }
        $resultPeople = $result['people'] ?? null;
        if (!is_array($resultPeople) || !array_is_list($resultPeople) || count($resultPeople) !== 1) {
            $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Výsledek musí obsahovat právě jednu osobu.');
        }
        $resultPerson = null;
        foreach ($resultPeople as $candidate) {
            if (is_array($candidate) && !array_is_list($candidate)
                && ($candidate['employee_id'] ?? null) === $employeeId
            ) {
                if ($resultPerson !== null) {
                    $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Výsledek obsahuje osobu vícekrát.');
                }
                $resultPerson = $candidate;
            }
        }
        if (!is_array($resultPerson)) {
            $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Výsledek neobsahuje zmrazenou osobu.');
        }
        $statutory = $this->object($resultPerson['statutory'] ?? null, 'statutory');
        if (($statutory['status'] ?? null) !== 'calculated') {
            $this->invalid('jmhz_ordinary_evidence_result_mismatch', 'Zákonný výsledek osoby není vypočtený.');
        }
        $net = $this->object($statutory['net_pay'] ?? null, 'net_pay');
        $deductions = $net['deductions'] ?? null;
        if (!is_array($deductions) || !array_is_list($deductions)
            || !is_int($net['deducted_minor_units'] ?? null)
            || $deductions !== [] || $net['deducted_minor_units'] !== 0
        ) {
            $this->invalid('jmhz_ordinary_evidence_deduction_conflict', 'Výsledek obsahuje evidovanou srážku ze mzdy.');
        }
        $resultEnforcement = $this->object($resultPerson['enforcement'] ?? null, 'result.enforcement');
        $enforcementInput = $this->object($resultEnforcement['input'] ?? null, 'result.enforcement.input');
        $enforcementResult = $this->object($resultEnforcement['result'] ?? null, 'result.enforcement.result');
        if (($enforcementResult['status'] ?? null) !== 'supported'
            || ($enforcementResult['issues'] ?? null) !== []
            || CanonicalJson::encode($enforcementInput) !== CanonicalJson::encode($enforcement)
            || ($enforcementResult['allocations'] ?? null) !== []
            || ($enforcementResult['total_withheld_minor_units'] ?? null) !== 0
            || ($enforcementResult['insolvency_applied'] ?? null) !== false
        ) {
            $this->invalid('jmhz_ordinary_evidence_deduction_conflict', 'Výsledek srážek neodpovídá potvrzení ordinary profilu.');
        }
    }

    /** @return array<string,mixed> */
    private function canonicalSnapshot(mixed $json, mixed $hash, string $field): array
    {
        if (!is_string($json) || !is_string($hash)
            || !hash_equals($hash, hash('sha256', $json))
        ) {
            $this->invalid('jmhz_ordinary_evidence_source_hash_mismatch', "Otisk {$field} snapshotu nesouhlasí.");
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded) || CanonicalJson::encode($decoded) !== $json) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', 'Vstupní snapshot není kanonický objekt.');
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} musí být objekt.");
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} musí být kladné celé číslo.");
        }
        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} není platné datum.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->invalid('jmhz_ordinary_evidence_source_invalid', "{$field} není platné datum.");
        }
        return $value;
    }

    private function invalid(string $code, string $message): never
    {
        throw new JmhzOrdinaryEvidenceException($code, $message);
    }
}
