<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataDefinition;

/** Streamovaná validace kanonického JSONL před sestavením plánu obnovy. */
final readonly class CompanyBackupJsonlReader
{
    private const READ_CHUNK_BYTES = 65_536;

    public function __construct(
        private CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {}

    /**
     * Stream vlastní volající. Pro dokončení kontrol manifestu musí iterátor vyčerpat.
     *
     * @param resource $stream
     * @return \Generator<int,array<string,mixed>>
     */
    public function rows(
        mixed $stream,
        TenantDataDefinition $definition,
        CompanyBackupDataObject $object,
    ): \Generator {
        $registryKey = $definition->key;
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new CompanyBackupJsonlReadException(
                'data_stream_invalid',
                $registryKey,
            );
        }
        if ($object->registryKey !== $registryKey
            || $object->path !== CompanyBackupDataObject::pathForRegistryKey($registryKey)
        ) {
            throw new CompanyBackupJsonlReadException(
                'data_object_mismatch',
                $registryKey,
            );
        }
        if ($object->bytes > $this->limits->maxEntryBytes) {
            throw new CompanyBackupJsonlReadException(
                'data_entry_size_exceeded',
                $registryKey,
            );
        }

        try {
            $projection = CompanyBackupTableProjection::fromDefinition($definition);
        } catch (CompanyBackupDataSourceException $e) {
            throw self::fromDataSourceException($e);
        }

        $expectedColumns = $projection->dataColumns;
        sort($expectedColumns, SORT_STRING);
        $hash = hash_init('sha256');
        $bytes = 0;
        $rowNumber = 0;
        $line = '';
        $readLength = self::READ_CHUNK_BYTES + 1;
        if ($this->limits->maxDataRowBytes < self::READ_CHUNK_BYTES) {
            $readLength = max(3, $this->limits->maxDataRowBytes + 2);
        }

        while (true) {
            $chunk = @fgets($stream, $readLength);
            if ($chunk === false) {
                if (!feof($stream)) {
                    throw new CompanyBackupJsonlReadException(
                        'data_stream_read_failed',
                        $registryKey,
                        $rowNumber + 1,
                    );
                }
                break;
            }

            $chunkBytes = strlen($chunk);
            if ($chunkBytes > $object->bytes - $bytes) {
                throw new CompanyBackupJsonlReadException(
                    'data_entry_size_mismatch',
                    $registryKey,
                );
            }
            hash_update($hash, $chunk);
            $bytes += $chunkBytes;

            if ($chunkBytes > $this->limits->maxDataRowBytes - strlen($line)) {
                throw new CompanyBackupJsonlReadException(
                    'data_row_size_exceeded',
                    $registryKey,
                    $rowNumber + 1,
                );
            }
            $line .= $chunk;
            if (!str_ends_with($chunk, "\n")) {
                continue;
            }

            $rowNumber++;
            $row = $this->decodeRow(
                substr($line, 0, -1),
                $projection,
                $expectedColumns,
                $rowNumber,
            );
            if ($rowNumber > $object->rows) {
                throw new CompanyBackupJsonlReadException(
                    'data_row_count_exceeded',
                    $registryKey,
                    $rowNumber,
                );
            }
            $line = '';
            yield $rowNumber => $row;
        }

        if ($line !== '') {
            throw new CompanyBackupJsonlReadException(
                'data_row_terminator_missing',
                $registryKey,
                $rowNumber + 1,
            );
        }
        if ($rowNumber !== $object->rows) {
            throw new CompanyBackupJsonlReadException(
                'data_row_count_mismatch',
                $registryKey,
            );
        }
        if ($bytes !== $object->bytes) {
            throw new CompanyBackupJsonlReadException(
                'data_entry_size_mismatch',
                $registryKey,
            );
        }
        if (!hash_equals($object->sha256, hash_final($hash))) {
            throw new CompanyBackupJsonlReadException(
                'data_entry_checksum_mismatch',
                $registryKey,
            );
        }
    }

    /**
     * @param list<string> $expectedColumns
     * @return array<string,mixed>
     */
    private function decodeRow(
        string $payload,
        CompanyBackupTableProjection $projection,
        array $expectedColumns,
        int $rowNumber,
    ): array {
        try {
            $decoded = json_decode($payload, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CompanyBackupJsonlReadException(
                'data_row_json_invalid',
                $projection->registryKey,
                $rowNumber,
                previous: $e,
            );
        }
        if (!is_array($decoded)
            || !str_starts_with(ltrim($payload), '{')
        ) {
            throw new CompanyBackupJsonlReadException(
                'data_row_not_object',
                $projection->registryKey,
                $rowNumber,
            );
        }

        /** @var array<string,mixed> $row */
        $row = $decoded;
        $actualColumns = array_keys($row);
        sort($actualColumns, SORT_STRING);
        if ($actualColumns !== $expectedColumns) {
            throw new CompanyBackupJsonlReadException(
                'data_row_shape_invalid',
                $projection->registryKey,
                $rowNumber,
            );
        }
        try {
            $canonical = CanonicalJson::encode($row);
        } catch (\Throwable $e) {
            throw new CompanyBackupJsonlReadException(
                'data_row_not_canonical',
                $projection->registryKey,
                $rowNumber,
                previous: $e,
            );
        }
        if (!hash_equals($canonical, $payload)) {
            throw new CompanyBackupJsonlReadException(
                'data_row_not_canonical',
                $projection->registryKey,
                $rowNumber,
            );
        }

        foreach ($projection->primaryKey as $column) {
            $value = $row[$column];
            if ((!is_int($value) && !is_string($value)) || $value === '') {
                throw new CompanyBackupJsonlReadException(
                    'data_primary_key_invalid',
                    $projection->registryKey,
                    $rowNumber,
                    $column,
                );
            }
        }

        try {
            foreach ($projection->columnCodecs as $column => $codec) {
                $codec->decode(
                    $row[$column],
                    $projection->registryKey,
                    $column,
                );
            }
            $projection->assertExportRow($row);
        } catch (CompanyBackupDataSourceException $e) {
            throw self::fromDataSourceException($e, $rowNumber);
        }

        $orderedRow = [];
        foreach ($projection->dataColumns as $column) {
            $orderedRow[$column] = $row[$column];
        }
        return $orderedRow;
    }

    private static function fromDataSourceException(
        CompanyBackupDataSourceException $exception,
        ?int $rowNumber = null,
    ): CompanyBackupJsonlReadException {
        return new CompanyBackupJsonlReadException(
            $exception->errorCode,
            $exception->registryKey,
            $rowNumber,
            $exception->column,
            $exception,
        );
    }
}
