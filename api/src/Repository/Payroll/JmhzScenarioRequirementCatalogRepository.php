<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use PDO;

final class JmhzScenarioRequirementCatalogRepository
{
    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable('payroll_jmhz_scenario_catalogs');
    }

    /**
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $manifest
     * @param array{manifest_sha256:string,payload:array<string, mixed>} $specManifest
     */
    public function install(array $manifest, array $specManifest): int
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Registr scénářů JMHZ není dostupný.');
        }
        JmhzScenarioRequirementSourceCatalog::validateManifest($manifest, $specManifest);
        $payload = $manifest['payload'];
        $packageId = $this->packageId(
            $this->requiredString($payload, 'spec_package_key'),
            $this->requiredString($payload, 'spec_manifest_sha256'),
        );
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $existing = $pdo->prepare(
                'SELECT id, manifest_sha256 FROM payroll_jmhz_scenario_catalogs
                  WHERE package_id = ? AND catalog_key = ? FOR UPDATE',
            );
            $existing->execute([$packageId, $this->requiredString($payload, 'catalog_key')]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if (!hash_equals((string) $row['manifest_sha256'], $manifest['manifest_sha256'])) {
                    throw new \LogicException('Katalog scénářů JMHZ už existuje s jiným hashem.');
                }
                $catalogId = (int) $row['id'];
                $this->verifyStoredRows($catalogId, $payload, $manifest['manifest_sha256']);
                if ($ownsTransaction) {
                    $pdo->commit();
                }

                return $catalogId;
            }
            $source = $this->requiredArray($payload, 'source');
            $counts = $this->requiredArray($payload, 'counts');
            $insert = $pdo->prepare(
                'INSERT INTO payroll_jmhz_scenario_catalogs
                    (package_id, catalog_key, version, source_filename, source_sha256,
                     manifest_json, manifest_sha256, scenario_count, interaction_count,
                     matrix_count, requirement_count, interaction_attribute_ref_count,
                     attribute_axis_count, evidence_axis_count, evidence_member_count)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $insert->execute([
                $packageId,
                $this->requiredString($payload, 'catalog_key'),
                $this->requiredString($payload, 'version'),
                $this->requiredString($source, 'filename'),
                $this->requiredString($source, 'sha256'),
                CanonicalJson::encode($manifest),
                $manifest['manifest_sha256'],
                $this->requiredInt($counts, 'scenarios', true),
                $this->requiredInt($counts, 'interactions', true),
                $this->requiredInt($counts, 'matrices', true),
                $this->requiredInt($counts, 'requirements', true),
                $this->requiredInt($counts, 'interaction_attribute_refs', true),
                $this->requiredInt($counts, 'master_attributes', true),
                $this->requiredInt($counts, 'reconciliation_axes', true)
                    + $this->requiredInt($counts, 'derived_axes', true),
                $this->requiredInt($counts, 'derived_one_cells', true),
            ]);
            $catalogId = (int) $pdo->lastInsertId();
            $matrixIds = $this->insertMatrices(
                $catalogId,
                $packageId,
                $this->rows($payload, 'matrices'),
            );
            $this->insertScenarios(
                $catalogId,
                $packageId,
                $matrixIds,
                $this->rows($payload, 'scenarios'),
            );
            $interactionIds = $this->insertInteractions(
                $catalogId,
                $packageId,
                $matrixIds,
                $this->rows($payload, 'interactions'),
            );
            $this->insertInteractionAttributeRefs(
                $catalogId,
                $packageId,
                $interactionIds,
                $this->rows($payload, 'interaction_attribute_refs'),
            );
            $this->insertRequirements(
                $catalogId,
                $packageId,
                $matrixIds,
                $this->rows($payload, 'matrices'),
            );
            $this->insertMasterAxis(
                $catalogId,
                $packageId,
                $this->rows($payload, 'master_attribute_axis'),
            );
            $this->insertEvidence(
                $catalogId,
                $packageId,
                $this->rows($payload, 'evidence_axes'),
            );
            $this->verifyStoredRows($catalogId, $payload, $manifest['manifest_sha256']);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $catalogId;
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>}|null */
    public function find(string $catalogKey, string $manifestHash): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, manifest_json, manifest_sha256 FROM payroll_jmhz_scenario_catalogs
              WHERE catalog_key = ? AND manifest_sha256 = ?',
        );
        $stmt->execute([$catalogKey, $manifestHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $manifest = $this->decodedManifest($row['manifest_json']);
        if (!hash_equals($manifestHash, $manifest['manifest_sha256'])) {
            throw new \UnexpectedValueException('Uložený katalog scénářů JMHZ má neplatný hash.');
        }
        $spec = $this->storedSpecManifest($manifest['payload']);
        JmhzScenarioRequirementSourceCatalog::validateManifest($manifest, $spec);
        $this->verifyStoredRows((int) $row['id'], $manifest['payload'], $manifest['manifest_sha256']);

        return $manifest;
    }

    /**
     * @param list<array<string, mixed>> $matrices
     * @return array<string, int>
     */
    private function insertMatrices(int $catalogId, int $packageId, array $matrices): array
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_requirement_matrices
                (catalog_id, package_id, matrix_key, matrix_kind, source_sheet,
                 source_header_row, selector_raw, row_count, matrix_hash, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $ids = [];
        foreach ($matrices as $row) {
            $key = $this->requiredString($row, 'matrix_key');
            $stmt->execute([
                $catalogId, $packageId, $key,
                $this->requiredString($row, 'matrix_kind'),
                $this->requiredString($row, 'source_sheet'),
                $this->requiredInt($row, 'source_header_row'),
                $this->nullableString($row, 'selector_raw'),
                $this->requiredInt($row, 'row_count', true),
                $this->requiredString($row, 'matrix_hash'),
                $this->requiredString($row, 'row_hash'),
            ]);
            $ids[$key] = (int) $this->db->pdo()->lastInsertId();
        }

        return $ids;
    }

    /**
     * @param array<string, int> $matrixIds
     * @param list<array<string, mixed>> $rows
     */
    private function insertScenarios(
        int $catalogId,
        int $packageId,
        array $matrixIds,
        array $rows,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_scenario_definitions
                (catalog_id, package_id, scenario_key, source_sheet, source_row, ordinal,
                 matrix_id, selector_raw_type, selector_raw, name_raw, condition_raw,
                 business_description_raw, business_description_cell_kind, xsd_entrypoint,
                 selection_kind, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($rows as $row) {
            $matrixKey = $this->requiredString($row, 'matrix_key');
            $stmt->execute([
                $catalogId, $packageId,
                $this->requiredString($row, 'scenario_key'),
                $this->requiredString($row, 'source_sheet'),
                $this->requiredInt($row, 'source_row'),
                $this->requiredInt($row, 'ordinal'),
                $matrixIds[$matrixKey]
                    ?? throw new \InvalidArgumentException("Neznámá matice scénáře {$matrixKey}."),
                $this->requiredString($row, 'selector_raw_type'),
                $this->requiredString($row, 'selector_raw'),
                $this->requiredString($row, 'name_raw'),
                $this->requiredString($row, 'condition_raw'),
                $this->requiredString($row, 'business_description_raw'),
                $this->requiredString($row, 'business_description_cell_kind'),
                $this->requiredString($row, 'xsd_entrypoint'),
                $this->requiredString($row, 'selection_kind'),
                $this->requiredString($row, 'row_hash'),
            ]);
        }
    }

    /**
     * @param array<string, int> $matrixIds
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function insertInteractions(
        int $catalogId,
        int $packageId,
        array $matrixIds,
        array $rows,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_interaction_definitions
                (catalog_id, package_id, interaction_key, interaction_id_raw, source_sheet,
                 source_row, ordinal, matrix_id, condition_raw, portal_text, note_raw,
                 trigger_kind, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $ids = [];
        foreach ($rows as $row) {
            $key = $this->requiredString($row, 'interaction_key');
            $matrixKey = $this->nullableString($row, 'matrix_key');
            $stmt->execute([
                $catalogId, $packageId, $key,
                $this->requiredString($row, 'interaction_id_raw'),
                $this->requiredString($row, 'source_sheet'),
                $this->requiredInt($row, 'source_row'),
                $this->requiredInt($row, 'ordinal'),
                $matrixKey === null ? null : ($matrixIds[$matrixKey]
                    ?? throw new \InvalidArgumentException("Neznámá matice interakce {$matrixKey}.")),
                $this->requiredString($row, 'condition_raw'),
                $this->nullableString($row, 'portal_text'),
                $this->nullableString($row, 'note_raw'),
                $this->requiredString($row, 'trigger_kind'),
                $this->requiredString($row, 'row_hash'),
            ]);
            $ids[$key] = (int) $this->db->pdo()->lastInsertId();
        }

        return $ids;
    }

    /**
     * @param array<string, int> $interactionIds
     * @param list<array<string, mixed>> $rows
     */
    private function insertInteractionAttributeRefs(
        int $catalogId,
        int $packageId,
        array $interactionIds,
        array $rows,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_interaction_attribute_refs
                (catalog_id, package_id, interaction_id, attribute_id, ordinal,
                 source_cell, source_match_raw, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($rows as $row) {
            $key = $this->requiredString($row, 'interaction_key');
            $stmt->execute([
                $catalogId, $packageId,
                $interactionIds[$key]
                    ?? throw new \InvalidArgumentException("Neznámá interakce {$key}."),
                $this->requiredString($row, 'attribute_id'),
                $this->requiredInt($row, 'ordinal'),
                $this->requiredString($row, 'source_cell'),
                $this->requiredString($row, 'source_match_raw'),
                $this->requiredString($row, 'row_hash'),
            ]);
        }
    }

    /**
     * @param array<string, int> $matrixIds
     * @param list<array<string, mixed>> $matrices
     */
    private function insertRequirements(
        int $catalogId,
        int $packageId,
        array $matrixIds,
        array $matrices,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_field_requirements
                (catalog_id, package_id, matrix_id, attribute_id, source_row, source_cell,
                 requirement_kind, requirement_raw, condition_note_raw, translation_raw,
                 effect_kind, effect_raw, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($matrices as $matrix) {
            $key = $this->requiredString($matrix, 'matrix_key');
            $matrixId = $matrixIds[$key]
                ?? throw new \InvalidArgumentException("Neznámá matice {$key}.");
            foreach ($this->rows($matrix, 'requirements') as $row) {
                $stmt->execute([
                    $catalogId, $packageId, $matrixId,
                    $this->requiredString($row, 'attribute_id'),
                    $this->requiredInt($row, 'source_row'),
                    $this->requiredString($row, 'source_cell'),
                    $this->requiredString($row, 'requirement_kind'),
                    $this->requiredString($row, 'requirement_raw'),
                    $this->nullableString($row, 'condition_note_raw'),
                    $this->nullableString($row, 'translation_raw'),
                    $this->requiredString($row, 'effect_kind'),
                    $this->nullableString($row, 'effect_raw'),
                    $this->requiredString($row, 'row_hash'),
                ]);
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertMasterAxis(int $catalogId, int $packageId, array $rows): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_master_attribute_axis
                (catalog_id, package_id, attribute_id, ordinal, source_row, row_hash)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        foreach ($rows as $row) {
            $stmt->execute([
                $catalogId, $packageId,
                $this->requiredString($row, 'attribute_id'),
                $this->requiredInt($row, 'ordinal'),
                $this->requiredInt($row, 'source_row'),
                $this->requiredString($row, 'row_hash'),
            ]);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertEvidence(int $catalogId, int $packageId, array $rows): void
    {
        $axisStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_matrix_evidence_axes
                (catalog_id, package_id, axis_key, axis_kind, source_column, source_sheet, label_raw,
                 expected_matrix_key, expected_effect, dimension_count, explicit_cell_count,
                 nonempty_count, blank_count, zero_count, one_count, raw_vector_sha256,
                 dictionary_formula_count, dictionary_formula_vector_sha256,
                 dictionary_cached_vector_sha256, master_match_count, master_mismatch_count,
                 reconciliation_status, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $memberStatement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_matrix_evidence_members
                (catalog_id, package_id, axis_id, attribute_id, ordinal, source_cell,
                 raw_type, raw_value, row_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($rows as $row) {
            $axisStatement->execute([
                $catalogId, $packageId,
                $this->requiredString($row, 'axis_key'),
                $this->requiredString($row, 'axis_kind'),
                $this->requiredString($row, 'source_column'),
                $this->requiredString($row, 'source_sheet'),
                $this->requiredString($row, 'label_raw'),
                $this->nullableString($row, 'expected_matrix_key'),
                $this->nullableString($row, 'expected_effect'),
                $this->requiredInt($row, 'dimension_count', true),
                $this->requiredInt($row, 'explicit_cell_count', true),
                $this->requiredInt($row, 'nonempty_count', true),
                $this->requiredInt($row, 'blank_count', true),
                $this->requiredInt($row, 'zero_count', true),
                $this->requiredInt($row, 'one_count', true),
                $this->requiredString($row, 'raw_vector_sha256'),
                $this->requiredInt($row, 'dictionary_formula_count', true),
                $this->nullableString($row, 'dictionary_formula_vector_sha256'),
                $this->nullableString($row, 'dictionary_cached_vector_sha256'),
                $this->requiredInt($row, 'master_match_count', true),
                $this->requiredInt($row, 'master_mismatch_count', true),
                $this->requiredString($row, 'reconciliation_status'),
                $this->requiredString($row, 'row_hash'),
            ]);
            $axisId = (int) $this->db->pdo()->lastInsertId();
            foreach ($this->rows($row, 'members') as $member) {
                $memberStatement->execute([
                    $catalogId, $packageId, $axisId,
                    $this->requiredString($member, 'attribute_id'),
                    $this->requiredInt($member, 'ordinal'),
                    $this->requiredString($member, 'source_cell'),
                    $this->requiredString($member, 'raw_type'),
                    $this->requiredString($member, 'raw_value'),
                    $this->requiredString($member, 'row_hash'),
                ]);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function verifyStoredRows(int $catalogId, array $payload, string $manifestHash): void
    {
        $source = $this->requiredArray($payload, 'source');
        $counts = $this->requiredArray($payload, 'counts');
        $header = $this->db->pdo()->prepare(
            'SELECT catalog_key, version, source_filename, source_sha256, manifest_sha256,
                    scenario_count, interaction_count, matrix_count, requirement_count,
                    interaction_attribute_ref_count, attribute_axis_count,
                    evidence_axis_count, evidence_member_count
               FROM payroll_jmhz_scenario_catalogs WHERE id = ?',
        );
        $header->execute([$catalogId]);
        $actualHeader = $header->fetch(PDO::FETCH_ASSOC);
        $expectedHeader = [
            'catalog_key' => $this->requiredString($payload, 'catalog_key'),
            'version' => $this->requiredString($payload, 'version'),
            'source_filename' => $this->requiredString($source, 'filename'),
            'source_sha256' => $this->requiredString($source, 'sha256'),
            'manifest_sha256' => $manifestHash,
            'scenario_count' => $this->requiredInt($counts, 'scenarios', true),
            'interaction_count' => $this->requiredInt($counts, 'interactions', true),
            'matrix_count' => $this->requiredInt($counts, 'matrices', true),
            'requirement_count' => $this->requiredInt($counts, 'requirements', true),
            'interaction_attribute_ref_count' => $this->requiredInt(
                $counts,
                'interaction_attribute_refs',
                true,
            ),
            'attribute_axis_count' => $this->requiredInt($counts, 'master_attributes', true),
            'evidence_axis_count' => $this->requiredInt($counts, 'reconciliation_axes', true)
                + $this->requiredInt($counts, 'derived_axes', true),
            'evidence_member_count' => $this->requiredInt($counts, 'derived_one_cells', true),
        ];
        if (!is_array($actualHeader)
            || CanonicalJson::encode(['rows' => $this->storageRows([$actualHeader])])
                !== CanonicalJson::encode(['rows' => $this->storageRows([$expectedHeader])])
        ) {
            throw new \UnexpectedValueException('Uložená hlavička katalogu scénářů JMHZ nesouhlasí.');
        }

        $expected = [
            'scenarios' => count($this->rows($payload, 'scenarios')),
            'interactions' => count($this->rows($payload, 'interactions')),
            'interaction_refs' => count($this->rows($payload, 'interaction_attribute_refs')),
            'matrices' => count($this->rows($payload, 'matrices')),
            'requirements' => array_sum(array_map(
                fn (array $matrix): int => count($this->rows($matrix, 'requirements')),
                $this->rows($payload, 'matrices'),
            )),
            'master_axis' => count($this->rows($payload, 'master_attribute_axis')),
            'evidence_axes' => count($this->rows($payload, 'evidence_axes')),
            'evidence_members' => array_sum(array_map(
                fn (array $axis): int => count($this->rows($axis, 'members')),
                $this->rows($payload, 'evidence_axes'),
            )),
        ];
        $tables = [
            'scenarios' => 'payroll_jmhz_scenario_definitions',
            'interactions' => 'payroll_jmhz_interaction_definitions',
            'interaction_refs' => 'payroll_jmhz_interaction_attribute_refs',
            'matrices' => 'payroll_jmhz_requirement_matrices',
            'requirements' => 'payroll_jmhz_field_requirements',
            'master_axis' => 'payroll_jmhz_master_attribute_axis',
            'evidence_axes' => 'payroll_jmhz_matrix_evidence_axes',
            'evidence_members' => 'payroll_jmhz_matrix_evidence_members',
        ];
        foreach ($tables as $key => $table) {
            $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE catalog_id = ?");
            $stmt->execute([$catalogId]);
            if ((int) $stmt->fetchColumn() !== $expected[$key]) {
                throw new \UnexpectedValueException('Uložený katalog scénářů JMHZ není úplný.');
            }
        }
        $this->verifyStoredSemanticFields($catalogId, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function verifyStoredSemanticFields(int $catalogId, array $payload): void
    {
        $matrices = $this->rows($payload, 'matrices');
        $requirements = [];
        foreach ($matrices as $matrix) {
            foreach ($this->rows($matrix, 'requirements') as $row) {
                $requirements[] = [
                    'matrix_key' => $matrix['matrix_key'],
                    'attribute_id' => $row['attribute_id'],
                    'source_row' => $row['source_row'],
                    'source_cell' => $row['source_cell'],
                    'requirement_kind' => $row['requirement_kind'],
                    'requirement_raw' => $row['requirement_raw'],
                    'condition_note_raw' => $row['condition_note_raw'],
                    'translation_raw' => $row['translation_raw'],
                    'effect_kind' => $row['effect_kind'],
                    'effect_raw' => $row['effect_raw'],
                    'row_hash' => $row['row_hash'],
                ];
            }
        }
        $evidenceMembers = [];
        foreach ($this->rows($payload, 'evidence_axes') as $axis) {
            foreach ($this->rows($axis, 'members') as $member) {
                $evidenceMembers[] = [
                    'axis_key' => $axis['axis_key'],
                    'attribute_id' => $member['attribute_id'],
                    'ordinal' => $member['ordinal'],
                    'source_cell' => $member['source_cell'],
                    'raw_type' => $member['raw_type'],
                    'raw_value' => $member['raw_value'],
                    'row_hash' => $member['row_hash'],
                ];
            }
        }
        $checks = [
            [
                'sql' => 'SELECT s.scenario_key, s.source_sheet, s.source_row, s.ordinal,
                                 m.matrix_key, s.selector_raw_type, s.selector_raw, s.name_raw,
                                 s.condition_raw, s.business_description_raw,
                                 s.business_description_cell_kind, s.xsd_entrypoint,
                                 s.selection_kind, s.row_hash
                            FROM payroll_jmhz_scenario_definitions s
                            JOIN payroll_jmhz_requirement_matrices m ON m.id = s.matrix_id
                           WHERE s.catalog_id = ? ORDER BY s.ordinal',
                'expected' => array_map(static fn (array $row): array => [
                    'scenario_key' => $row['scenario_key'],
                    'source_sheet' => $row['source_sheet'],
                    'source_row' => $row['source_row'],
                    'ordinal' => $row['ordinal'],
                    'matrix_key' => $row['matrix_key'],
                    'selector_raw_type' => $row['selector_raw_type'],
                    'selector_raw' => $row['selector_raw'],
                    'name_raw' => $row['name_raw'],
                    'condition_raw' => $row['condition_raw'],
                    'business_description_raw' => $row['business_description_raw'],
                    'business_description_cell_kind' => $row['business_description_cell_kind'],
                    'xsd_entrypoint' => $row['xsd_entrypoint'],
                    'selection_kind' => $row['selection_kind'],
                    'row_hash' => $row['row_hash'],
                ], $this->rows($payload, 'scenarios')),
            ],
            [
                'sql' => 'SELECT i.interaction_key, i.interaction_id_raw, i.source_sheet,
                                 i.source_row, i.ordinal, m.matrix_key, i.condition_raw,
                                 i.portal_text, i.note_raw, i.trigger_kind, i.row_hash
                            FROM payroll_jmhz_interaction_definitions i
                       LEFT JOIN payroll_jmhz_requirement_matrices m ON m.id = i.matrix_id
                           WHERE i.catalog_id = ? ORDER BY i.ordinal',
                'expected' => array_map(static fn (array $row): array => [
                    'interaction_key' => $row['interaction_key'],
                    'interaction_id_raw' => $row['interaction_id_raw'],
                    'source_sheet' => $row['source_sheet'],
                    'source_row' => $row['source_row'],
                    'ordinal' => $row['ordinal'],
                    'matrix_key' => $row['matrix_key'],
                    'condition_raw' => $row['condition_raw'],
                    'portal_text' => $row['portal_text'],
                    'note_raw' => $row['note_raw'],
                    'trigger_kind' => $row['trigger_kind'],
                    'row_hash' => $row['row_hash'],
                ], $this->rows($payload, 'interactions')),
            ],
            [
                'sql' => 'SELECT matrix_key, matrix_kind, source_sheet, source_header_row,
                                 selector_raw, row_count, matrix_hash, row_hash
                            FROM payroll_jmhz_requirement_matrices
                           WHERE catalog_id = ? ORDER BY id',
                'expected' => array_map(static fn (array $row): array => [
                    'matrix_key' => $row['matrix_key'],
                    'matrix_kind' => $row['matrix_kind'],
                    'source_sheet' => $row['source_sheet'],
                    'source_header_row' => $row['source_header_row'],
                    'selector_raw' => $row['selector_raw'],
                    'row_count' => $row['row_count'],
                    'matrix_hash' => $row['matrix_hash'],
                    'row_hash' => $row['row_hash'],
                ], $matrices),
            ],
            [
                'sql' => 'SELECT m.matrix_key, r.attribute_id, r.source_row, r.source_cell,
                                 r.requirement_kind, r.requirement_raw, r.condition_note_raw,
                                 r.translation_raw, r.effect_kind, r.effect_raw, r.row_hash
                            FROM payroll_jmhz_field_requirements r
                            JOIN payroll_jmhz_requirement_matrices m ON m.id = r.matrix_id
                           WHERE r.catalog_id = ? ORDER BY m.id, r.source_row',
                'expected' => $requirements,
            ],
            [
                'sql' => 'SELECT i.interaction_key, r.attribute_id, r.ordinal, r.source_cell,
                                 r.source_match_raw, r.row_hash
                            FROM payroll_jmhz_interaction_attribute_refs r
                            JOIN payroll_jmhz_interaction_definitions i ON i.id = r.interaction_id
                           WHERE r.catalog_id = ? ORDER BY i.ordinal, r.ordinal',
                'expected' => array_map(static fn (array $row): array => [
                    'interaction_key' => $row['interaction_key'],
                    'attribute_id' => $row['attribute_id'],
                    'ordinal' => $row['ordinal'],
                    'source_cell' => $row['source_cell'],
                    'source_match_raw' => $row['source_match_raw'],
                    'row_hash' => $row['row_hash'],
                ], $this->rows($payload, 'interaction_attribute_refs')),
            ],
            [
                'sql' => 'SELECT attribute_id, ordinal, source_row, row_hash
                            FROM payroll_jmhz_master_attribute_axis
                           WHERE catalog_id = ? ORDER BY ordinal',
                'expected' => $this->rows($payload, 'master_attribute_axis'),
            ],
            [
                'sql' => 'SELECT axis_key, axis_kind, source_column, source_sheet, label_raw,
                                 expected_matrix_key, expected_effect, dimension_count,
                                 explicit_cell_count, nonempty_count, blank_count, zero_count,
                                 one_count, raw_vector_sha256, dictionary_formula_count,
                                 dictionary_formula_vector_sha256,
                                 dictionary_cached_vector_sha256, master_match_count,
                                 master_mismatch_count, reconciliation_status, row_hash
                            FROM payroll_jmhz_matrix_evidence_axes
                           WHERE catalog_id = ? ORDER BY id',
                'expected' => array_map(static fn (array $row): array => [
                    'axis_key' => $row['axis_key'],
                    'axis_kind' => $row['axis_kind'],
                    'source_column' => $row['source_column'],
                    'source_sheet' => $row['source_sheet'],
                    'label_raw' => $row['label_raw'],
                    'expected_matrix_key' => $row['expected_matrix_key'],
                    'expected_effect' => $row['expected_effect'],
                    'dimension_count' => $row['dimension_count'],
                    'explicit_cell_count' => $row['explicit_cell_count'],
                    'nonempty_count' => $row['nonempty_count'],
                    'blank_count' => $row['blank_count'],
                    'zero_count' => $row['zero_count'],
                    'one_count' => $row['one_count'],
                    'raw_vector_sha256' => $row['raw_vector_sha256'],
                    'dictionary_formula_count' => $row['dictionary_formula_count'],
                    'dictionary_formula_vector_sha256' => $row['dictionary_formula_vector_sha256'],
                    'dictionary_cached_vector_sha256' => $row['dictionary_cached_vector_sha256'],
                    'master_match_count' => $row['master_match_count'],
                    'master_mismatch_count' => $row['master_mismatch_count'],
                    'reconciliation_status' => $row['reconciliation_status'],
                    'row_hash' => $row['row_hash'],
                ], $this->rows($payload, 'evidence_axes')),
            ],
            [
                'sql' => 'SELECT a.axis_key, m.attribute_id, m.ordinal, m.source_cell,
                                 m.raw_type, m.raw_value, m.row_hash
                            FROM payroll_jmhz_matrix_evidence_members m
                            JOIN payroll_jmhz_matrix_evidence_axes a ON a.id = m.axis_id
                           WHERE m.catalog_id = ? ORDER BY a.id, m.ordinal',
                'expected' => $evidenceMembers,
            ],
        ];
        foreach ($checks as $index => $check) {
            $stmt = $this->db->pdo()->prepare($check['sql']);
            $stmt->execute([$catalogId]);
            $actual = $this->storageRows($stmt->fetchAll(PDO::FETCH_ASSOC));
            $expected = $this->storageRows($check['expected']);
            if (CanonicalJson::encode(['rows' => $actual])
                !== CanonicalJson::encode(['rows' => $expected])
            ) {
                throw new \UnexpectedValueException(
                    "Uložený obsah katalogu scénářů JMHZ nesouhlasí (sada {$index}).",
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, string|null>>
     */
    private function storageRows(array $rows): array
    {
        return array_values(array_map(
            static fn (array $row): array => array_map(
                static fn (mixed $value): ?string => $value === null ? null : (string) $value,
                $row,
            ),
            $rows,
        ));
    }

    private function packageId(string $packageKey, string $manifestHash): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_jmhz_spec_packages WHERE package_key = ? AND manifest_sha256 = ?',
        );
        $stmt->execute([$packageKey, $manifestHash]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('Rodičovský balík specifikace JMHZ není nainstalovaný.');
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{manifest_sha256:string,payload:array<string, mixed>}
     */
    private function storedSpecManifest(array $payload): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT manifest_json FROM payroll_jmhz_spec_packages
              WHERE package_key = ? AND manifest_sha256 = ?',
        );
        $stmt->execute([
            $this->requiredString($payload, 'spec_package_key'),
            $this->requiredString($payload, 'spec_manifest_sha256'),
        ]);
        $json = $stmt->fetchColumn();
        if (!is_string($json)) {
            throw new \UnexpectedValueException('Rodičovský balík katalogu scénářů JMHZ chybí.');
        }

        return $this->decodedManifest($json);
    }

    /** @return array{manifest_sha256:string,payload:array<string, mixed>} */
    private function decodedManifest(mixed $value): array
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Manifest JMHZ není text.');
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_string($decoded['manifest_sha256'] ?? null)
            || !is_array($decoded['payload'] ?? null)
        ) {
            throw new \UnexpectedValueException('Manifest JMHZ má neplatnou strukturu.');
        }

        return ['manifest_sha256' => $decoded['manifest_sha256'], 'payload' => $decoded['payload']];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function rows(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException("Pole {$field} katalogu scénářů JMHZ není seznam.");
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("Pole {$field} katalogu scénářů JMHZ má neplatný řádek.");
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function requiredArray(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_array($value)) {
            throw new \InvalidArgumentException("Pole {$field} katalogu scénářů JMHZ není objekt.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Pole {$field} katalogu scénářů JMHZ není text.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} katalogu scénářů JMHZ není text.");
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function requiredInt(array $row, string $field, bool $allowZero = false): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) || ($allowZero ? $value < 0 : $value <= 0)) {
            throw new \InvalidArgumentException("Pole {$field} katalogu scénářů JMHZ není platné číslo.");
        }

        return $value;
    }
}
