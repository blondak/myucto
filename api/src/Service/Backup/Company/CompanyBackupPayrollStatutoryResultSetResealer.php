<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Přepočítá kořenovou pečeť ze skutečně přemapovaných potomků. */
final class CompanyBackupPayrollStatutoryResultSetResealer
{
    /**
     * @param array<string,mixed> $sourceHeader
     * @param array<string,mixed> $targetHeader
     * @param list<array<string,mixed>> $sourcePeople
     * @param list<array<string,mixed>> $sourceRelationships
     * @param callable(array<string,mixed>): mixed $personMapper
     * @param callable(array<string,mixed>): mixed $relationshipMapper
     * @return array{
     *   header:array<string,mixed>,
     *   people:list<array<string,mixed>>,
     *   relationships:list<array<string,mixed>>
     * }
     */
    public static function reseal(
        array $sourceHeader,
        array $targetHeader,
        array $sourcePeople,
        array $sourceRelationships,
        callable $personMapper,
        callable $relationshipMapper,
    ): array {
        CompanyBackupPayrollStatutoryResultSetAssembler::assertSource(
            $sourceHeader,
            $sourcePeople,
            $sourceRelationships,
        );

        $people = [];
        foreach ($sourcePeople as $row) {
            $people[] = self::mapChild($row, $targetHeader, $personMapper);
        }
        $relationships = [];
        foreach ($sourceRelationships as $row) {
            $relationships[] = self::mapChild(
                $row,
                $targetHeader,
                $relationshipMapper,
            );
        }

        $targetHeader[CompanyBackupPayrollStatutoryResultSetAssembler::HASH_COLUMN] =
            CompanyBackupPayrollStatutoryResultSetAssembler::calculate(
                $targetHeader,
                $people,
                $relationships,
            );

        return [
            'header' => $targetHeader,
            'people' => $people,
            'relationships' => $relationships,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $targetHeader
     * @param callable(array<string,mixed>): mixed $mapper
     * @return array<string,mixed>
     */
    private static function mapChild(
        array $row,
        array $targetHeader,
        callable $mapper,
    ): array {
        $mapped = $mapper($row);
        if (!is_array($mapped) || array_is_list($mapped)) {
            throw self::error('data_aggregate_hash_graph_invalid');
        }
        foreach (
            [
                'supplier_id' => 'supplier_id',
                'statutory_result_id' => 'id',
                'revision_id' => 'revision_id',
                'calculation_kind' => 'calculation_kind',
            ] as $childColumn => $headerColumn
        ) {
            if (!array_key_exists($headerColumn, $targetHeader)) {
                throw self::error('data_aggregate_hash_graph_invalid');
            }
            $mapped[$childColumn] = $targetHeader[$headerColumn];
        }
        return $mapped;
    }

    private static function error(string $code): CompanyBackupDataSourceException
    {
        return new CompanyBackupDataSourceException(
            $code,
            CompanyBackupPayrollStatutoryResultSetAssembler::ROOT_REGISTRY_KEY,
            CompanyBackupPayrollStatutoryResultSetAssembler::HASH_COLUMN,
        );
    }
}
