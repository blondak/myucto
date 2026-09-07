<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupDataSourceException;
use MyInvoice\Service\Backup\Company\CompanyBackupHashReference;
use MyInvoice\Service\Backup\Company\CompanyBackupHashReferenceSet;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceRemapDirective;
use MyInvoice\Service\Backup\Company\CompanyBackupTableReferenceSchema;
use PHPUnit\Framework\TestCase;

final class CompanyBackupHashReferenceSetTest extends TestCase
{
    public function testValidatesAndRemapsPhysicalHashColumn(): void
    {
        $references = CompanyBackupHashReferenceSet::fromArray(
            [$this->metadata(nullable: false)],
            'table:accumulator_entries',
            ['id', 'source_result_hash'],
        );
        $sourceHash = str_repeat('a', 64);
        $targetHash = str_repeat('b', 64);

        $references->assertSourceRow([
            'id' => 7,
            'source_result_hash' => $sourceHash,
        ]);
        $restored = $references->remap(
            ['id' => 7, 'source_result_hash' => $sourceHash],
            static function (
                CompanyBackupHashReference $reference,
                string $hash,
            ) use ($sourceHash, $targetHash): string {
                self::assertSame(
                    'source_result_hash->statutory_results:result_snapshot_hash!',
                    $reference->signature(),
                );
                self::assertSame($sourceHash, $hash);
                return $targetHash;
            },
        );

        self::assertSame($targetHash, $restored['source_result_hash']);
    }

    public function testNullableReferenceCanBeDeferredToNull(): void
    {
        $references = CompanyBackupHashReferenceSet::fromArray(
            [$this->metadata(nullable: true)],
            'table:accumulator_entries',
            ['source_result_hash'],
        );

        self::assertSame(
            ['source_result_hash' => null],
            $references->remap(
                ['source_result_hash' => str_repeat('a', 64)],
                static fn (): CompanyBackupReferenceRemapDirective =>
                    CompanyBackupReferenceRemapDirective::Defer,
            ),
        );
        $references->assertSourceRow(['source_result_hash' => null]);
    }

    public function testRejectsInvalidValueAndNonNullableDeferral(): void
    {
        $required = CompanyBackupHashReferenceSet::fromArray(
            [$this->metadata(nullable: false)],
            'table:accumulator_entries',
            ['source_result_hash'],
        );

        $this->assertDataError(
            'data_hash_reference_value_invalid',
            fn () => $required->assertSourceRow([
                'source_result_hash' => str_repeat('A', 64),
            ]),
        );
        $this->assertDataError(
            'data_hash_reference_value_invalid',
            fn () => $required->remap(
                ['source_result_hash' => str_repeat('a', 64)],
                static fn (): CompanyBackupReferenceRemapDirective =>
                    CompanyBackupReferenceRemapDirective::Defer,
            ),
        );
    }

    public function testRejectsUnexportedDuplicateAndUnsortedMetadata(): void
    {
        $first = $this->metadata(nullable: false);
        $second = [
            ...$first,
            'column' => 'earlier_hash',
        ];

        $this->assertDataError(
            'data_hash_reference_source_not_exported',
            fn () => CompanyBackupHashReferenceSet::fromArray(
                [$first],
                'table:accumulator_entries',
                ['id'],
            ),
        );
        $this->assertDataError(
            'data_hash_reference_duplicate',
            fn () => CompanyBackupHashReferenceSet::fromArray(
                [$first, $first],
                'table:accumulator_entries',
                ['source_result_hash'],
            ),
        );
        $this->assertDataError(
            'data_hash_reference_metadata_invalid',
            fn () => CompanyBackupHashReferenceSet::fromArray(
                [$first, $second],
                'table:accumulator_entries',
                ['source_result_hash', 'earlier_hash'],
            ),
        );
    }

    public function testChecksDeclaredNullabilityAgainstRuntimeSchema(): void
    {
        $references = CompanyBackupHashReferenceSet::fromArray(
            [$this->metadata(nullable: false)],
            'table:accumulator_entries',
            ['source_result_hash'],
        );

        $references->assertRuntimeSchema(
            new CompanyBackupTableReferenceSchema([], []),
        );
        $this->assertDataError(
            'data_hash_reference_nullability_mismatch',
            fn () => $references->assertRuntimeSchema(
                new CompanyBackupTableReferenceSchema(
                    ['source_result_hash'],
                    [],
                ),
            ),
        );
    }

    /** @return array<string,mixed> */
    private function metadata(bool $nullable): array
    {
        return [
            'column' => 'source_result_hash',
            'nullable' => $nullable,
            'target' => 'table:statutory_results',
            'target_hash_column' => 'result_snapshot_hash',
        ];
    }

    /** @param callable():mixed $operation */
    private function assertDataError(string $errorCode, callable $operation): void
    {
        try {
            $operation();
            self::fail('Neplatná přímá hashová reference musí být odmítnuta.');
        } catch (CompanyBackupDataSourceException $e) {
            self::assertSame($errorCode, $e->errorCode);
            self::assertSame('table:accumulator_entries', $e->registryKey);
        }
    }
}
