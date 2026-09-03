<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupDataObject;
use MyInvoice\Service\Backup\Company\CompanyBackupJsonlReadException;
use MyInvoice\Service\Backup\Company\CompanyBackupJsonlReader;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompanyBackupJsonlReaderTest extends TestCase
{
    public function testStreamsCanonicalRowsAndVerifiesManifestMetadata(): void
    {
        $rows = [
            ['id' => 1, 'supplier_id' => 7, 'label' => 'První'],
            ['id' => 2, 'supplier_id' => 7, 'label' => null],
        ];
        $contents = self::jsonl($rows);

        $parsed = iterator_to_array((new CompanyBackupJsonlReader())->rows(
            self::stream($contents),
            $this->definition(),
            $this->object($contents, 2),
        ), false);

        self::assertSame($rows, $parsed);
    }

    #[DataProvider('invalidRows')]
    public function testRejectsInvalidRowBeforeItCanEnterAPlan(
        string $contents,
        string $errorCode,
        ?string $column = null,
    ): void {
        $definition = $this->definition(
            $column === 'label' ? ['label' => 'binary_hex'] : [],
        );

        try {
            iterator_to_array((new CompanyBackupJsonlReader())->rows(
                self::stream($contents),
                $definition,
                $this->object($contents, 1, $definition),
            ));
            self::fail('Neplatný JSONL řádek nesmí projít preflight parserem.');
        } catch (CompanyBackupJsonlReadException $e) {
            self::assertSame($errorCode, $e->errorCode);
            self::assertSame('table:synthetic_records', $e->registryKey);
            self::assertSame(1, $e->rowNumber);
            self::assertSame($column, $e->column);
        }
    }

    /** @return iterable<string,array{string,string,?string}> */
    public static function invalidRows(): iterable
    {
        yield 'non-canonical key order' => [
            "{\"supplier_id\":7,\"label\":\"A\",\"id\":1}\n",
            'data_row_not_canonical',
            null,
        ];
        yield 'blank line' => ["\n", 'data_row_json_invalid', null];
        yield 'root list' => ["[1,2,3]\n", 'data_row_not_object', null];
        yield 'unknown column' => [
            "{\"extra\":true,\"id\":1,\"label\":\"A\",\"supplier_id\":7}\n",
            'data_row_shape_invalid',
            null,
        ];
        yield 'null primary key' => [
            "{\"id\":null,\"label\":\"A\",\"supplier_id\":7}\n",
            'data_primary_key_invalid',
            'id',
        ];
        yield 'null required reference' => [
            "{\"id\":1,\"label\":\"A\",\"supplier_id\":null}\n",
            'data_reference_value_invalid',
            'supplier_id',
        ];
        yield 'invalid binary codec payload' => [
            "{\"id\":1,\"label\":\"zz\",\"supplier_id\":7}\n",
            'data_column_codec_payload_invalid',
            'label',
        ];
        yield 'missing LF terminator' => [
            '{"id":1,"label":"A","supplier_id":7}',
            'data_row_terminator_missing',
            null,
        ];
    }

    public function testRejectsRowCountMismatchEvenWhenBytesAndHashMatch(): void
    {
        $contents = self::jsonl([
            ['id' => 1, 'supplier_id' => 7, 'label' => 'Jediný'],
        ]);

        $this->expectReadError('data_row_count_mismatch', function () use ($contents): void {
            iterator_to_array((new CompanyBackupJsonlReader())->rows(
                self::stream($contents),
                $this->definition(),
                $this->object($contents, 2),
            ));
        }, null);
    }

    public function testStopsAsSoonAsRowsExceedManifestCount(): void
    {
        $contents = self::jsonl([
            ['id' => 1, 'supplier_id' => 7, 'label' => 'První'],
            ['id' => 2, 'supplier_id' => 7, 'label' => 'Druhý'],
        ]);

        $this->expectReadError('data_row_count_exceeded', function () use ($contents): void {
            iterator_to_array((new CompanyBackupJsonlReader())->rows(
                self::stream($contents),
                $this->definition(),
                $this->object($contents, 1),
            ));
        }, 2);
    }

    public function testRejectsChangedCanonicalPayloadAgainstManifestHash(): void
    {
        $expected = self::jsonl([
            ['id' => 1, 'supplier_id' => 7, 'label' => 'Alpha'],
        ]);
        $changed = self::jsonl([
            ['id' => 1, 'supplier_id' => 7, 'label' => 'Omega'],
        ]);
        self::assertSame(strlen($expected), strlen($changed));

        $this->expectReadError('data_entry_checksum_mismatch', function () use (
            $expected,
            $changed,
        ): void {
            iterator_to_array((new CompanyBackupJsonlReader())->rows(
                self::stream($changed),
                $this->definition(),
                $this->object($expected, 1),
            ));
        }, null);
    }

    public function testRejectsRowAboveDedicatedMemoryLimit(): void
    {
        $contents = self::jsonl([
            ['id' => 1, 'supplier_id' => 7, 'label' => str_repeat('x', 80)],
        ]);
        $limits = new CompanyBackupArchiveLimits(
            maxArchiveBytes: 4_096,
            maxEntries: 20,
            maxEntryBytes: 1_024,
            maxExpandedBytes: 4_096,
            maxCompressionRatio: 100,
            maxManifestBytes: 512,
            maxChecksumsBytes: 512,
            maxDataRowBytes: 64,
        );

        $this->expectReadError('data_row_size_exceeded', function () use (
            $contents,
            $limits,
        ): void {
            iterator_to_array((new CompanyBackupJsonlReader($limits))->rows(
                self::stream($contents),
                $this->definition(),
                $this->object($contents, 1),
            ));
        }, 1);
    }

    public function testRejectsInvalidEmbeddedReferenceValue(): void
    {
        $definition = $this->definition(embeddedReference: true);
        $contents = self::jsonl([[
            'id' => 1,
            'supplier_id' => 7,
            'label' => CanonicalJson::encode(['owner_id' => '7']),
        ]]);

        try {
            iterator_to_array((new CompanyBackupJsonlReader())->rows(
                self::stream($contents),
                $definition,
                $this->object($contents, 1, $definition),
            ));
            self::fail('Neplatná vnořená reference nesmí vstoupit do plánu.');
        } catch (CompanyBackupJsonlReadException $e) {
            self::assertSame(
                'data_embedded_reference_value_invalid',
                $e->errorCode,
            );
            self::assertSame(1, $e->rowNumber);
            self::assertSame('label', $e->column);
        }
    }

    /** @param array<string,string> $columnCodecs */
    private function definition(
        array $columnCodecs = [],
        bool $embeddedReference = false,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:synthetic_records',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::COMPANY_BACKUP_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'company_backup' => [
                    ...($columnCodecs === [] ? [] : [
                        'column_codecs' => $columnCodecs,
                    ]),
                    'data_columns' => ['id', 'supplier_id', 'label'],
                    'embedded_references' => $embeddedReference ? [[
                        'column' => 'label',
                        'condition' => null,
                        'fallbacks' => [],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'nullable' => false,
                        'path' => ['owner_id'],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                    ]] : [],
                    'generated_columns' => [],
                    'omit_columns' => [],
                    'references' => [[
                        'columns' => ['supplier_id'],
                        'target' => 'table:supplier',
                        'target_columns' => ['id'],
                        'mapping' => CompanyBackupReferenceMapping::TenantId->value,
                        'constraint' => CompanyBackupReferenceConstraint::Required->value,
                        'nullable_columns' => [],
                        'fallbacks' => [],
                    ]],
                    'restore_overrides' => [],
                ],
            ],
        );
    }

    private function object(
        string $contents,
        int $rows,
        ?TenantDataDefinition $definition = null,
    ): CompanyBackupDataObject {
        return CompanyBackupDataObject::fromWrittenPayload(
            $definition ?? $this->definition(),
            1,
            $rows,
            strlen($contents),
            hash('sha256', $contents),
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private static function jsonl(array $rows): string
    {
        return implode('', array_map(
            static fn (array $row): string => CanonicalJson::encode($row) . "\n",
            $rows,
        ));
    }

    /** @return resource */
    private static function stream(string $contents)
    {
        $stream = fopen('php://temp', 'w+b');
        if (!is_resource($stream)
            || fwrite($stream, $contents) !== strlen($contents)
            || !rewind($stream)
        ) {
            throw new \RuntimeException('Nelze připravit syntetický JSONL stream.');
        }
        return $stream;
    }

    private function expectReadError(
        string $code,
        \Closure $operation,
        ?int $rowNumber,
    ): void {
        try {
            $operation();
            self::fail('Poškozená strojová data musí preflight odmítnout.');
        } catch (CompanyBackupJsonlReadException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame('table:synthetic_records', $e->registryKey);
            self::assertSame($rowNumber, $e->rowNumber);
        }
    }
}
