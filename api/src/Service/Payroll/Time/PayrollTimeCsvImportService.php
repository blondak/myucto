<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Repository\Payroll\PayrollTimeLockedException;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;

final class PayrollTimeCsvImportService
{
    private const REQUIRED = [
        'employment_code',
        'starts_at',
        'ends_at',
        'timezone',
        'category',
        'external_id',
    ];
    private const CATEGORIES = [
        'regular',
        'overtime',
        'night',
        'weekend',
        'holiday',
        'difficult_environment',
    ];

    public function __construct(
        private readonly PayrollTimeRepository $repository,
        private readonly PayrollTimeService $time,
    ) {}

    /** @return array<string,mixed> */
    public function preview(
        int $supplierId,
        string $period,
        string $format,
        string $originalName,
        string $content,
    ): array {
        [$periodStart] = $this->time->periodBounds($period);
        if ($format === 'xlsx') {
            return [
                'format' => 'xlsx',
                'supported' => false,
                'status' => 'manual_review',
                'period' => $period,
                'original_name' => $originalName,
                'total_rows' => 0,
                'accepted_rows' => 0,
                'rejected_rows' => 0,
                'duplicate_rows' => 0,
                'errors' => [[
                    'row_number' => 0,
                    'error_code' => 'xlsx_manual_review',
                    'field_name' => null,
                    'error_message' => 'XLSX import není automaticky podporován; soubor vyžaduje ruční převod do CSV a kontrolu.',
                ]],
                'rows' => [],
            ];
        }
        if ($format !== 'csv') {
            throw new \InvalidArgumentException('format musí být csv nebo xlsx.');
        }
        if (strlen($content) > 5_000_000) {
            throw new \InvalidArgumentException('CSV je větší než bezpečný limit 5 MB.');
        }

        $parsed = $this->parseCsv($content);
        $errors = $parsed['errors'];
        $accepted = [];
        $duplicates = 0;
        $seenSourceHashes = [];
        foreach ($parsed['rows'] as $row) {
            $validated = $this->validateRow($supplierId, $period, $row);
            if (isset($validated['error'])) {
                $errors[] = $validated['error'];
                continue;
            }
            $entry = $validated['entry'];
            $sourceHash = PayrollTimeValue::string($entry['source_hash'] ?? null, 'source_hash');
            $sourceHashKey = bin2hex($sourceHash);
            if (isset($seenSourceHashes[$sourceHashKey])) {
                ++$duplicates;
                continue;
            }
            $seenSourceHashes[$sourceHashKey] = true;
            if ($this->repository->hasEntrySourceHash(
                $supplierId,
                PayrollTimeValue::int($entry['employment_id'] ?? null, 'employment_id'),
                $sourceHash,
            )) {
                ++$duplicates;
                continue;
            }
            $accepted[] = $entry;
        }

        return [
            'format' => 'csv',
            'supported' => true,
            'status' => 'preview',
            'period' => $period,
            'period_start' => $periodStart,
            'original_name' => $originalName,
            'content_hash' => hash('sha256', $content),
            'total_rows' => count($parsed['rows']) + count($parsed['row_errors']),
            'accepted_rows' => count($accepted),
            'rejected_rows' => count($errors),
            'duplicate_rows' => $duplicates,
            'errors' => array_map(
                static fn (array $error): array => array_diff_key($error, ['row_hash' => true]),
                $errors,
            ),
            'rows' => array_map(
                static fn (array $row): array => array_diff_key(
                    $row,
                    ['source_hash' => true, 'row_hash' => true],
                ),
                $accepted,
            ),
            '_accepted' => $accepted,
            '_errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    public function import(
        int $supplierId,
        string $period,
        string $format,
        string $originalName,
        string $content,
        ?int $userId,
    ): array {
        [$periodStart] = $this->time->periodBounds($period);
        $contentHash = hash('sha256', $content, true);
        $existing = $this->repository->importByHash($supplierId, $periodStart, $contentHash);
        if ($existing !== null) {
            $existing['replayed'] = true;
            return $existing;
        }

        $preview = $this->preview(
            $supplierId,
            $period,
            $format,
            $originalName,
            $content,
        );
        if ($format === 'xlsx') {
            $recorded = $this->repository->recordImport(
                $supplierId,
                $periodStart,
                'xlsx',
                $originalName,
                $contentHash,
                'manual_review',
                0,
                0,
                0,
                0,
                [[
                    'row_number' => 0,
                    'error_code' => 'xlsx_manual_review',
                    'field_name' => null,
                    'error_message' => 'XLSX import vyžaduje ruční převod do CSV a kontrolu.',
                    'row_hash' => hash('sha256', 'xlsx_manual_review', true),
                ]],
                $userId,
            );
            $recorded['supported'] = false;
            return $recorded;
        }

        $acceptedRows = PayrollTimeValue::rows($preview['_accepted'] ?? null, '_accepted');
        $errors = $this->errors($preview['_errors'] ?? null);
        $accepted = 0;
        $duplicates = PayrollTimeValue::int(
            $preview['duplicate_rows'] ?? null,
            'duplicate_rows',
        );
        foreach ($acceptedRows as $row) {
            $employmentId = PayrollTimeValue::int(
                $row['employment_id'] ?? null,
                'employment_id',
            );
            $sourceHash = PayrollTimeValue::string(
                $row['source_hash'] ?? null,
                'source_hash',
            );
            if ($this->repository->hasEntrySourceHash(
                $supplierId,
                $employmentId,
                $sourceHash,
            )) {
                ++$duplicates;
                continue;
            }
            $state = $this->repository->monthState(
                $supplierId,
                $employmentId,
                $periodStart,
            );
            $row['month_row_version'] = $state === null
                ? 0
                : PayrollTimeValue::int($state['row_version'] ?? null, 'row_version');
            $row['row_version'] = 0;
            try {
                $this->time->saveEntry(
                    $supplierId,
                    $row,
                    $userId,
                    'import',
                    PayrollTimeValue::string($row['external_id'] ?? null, 'external_id'),
                    $sourceHash,
                );
                ++$accepted;
            } catch (PayrollTimeLockedException) {
                $errors[] = $this->error(
                    PayrollTimeValue::int($row['row_number'] ?? null, 'row_number'),
                    'month_locked',
                    null,
                    'Měsíc pracovního vztahu je schválený; před importem jej znovu otevřete.',
                    PayrollTimeValue::string($row['row_hash'] ?? null, 'row_hash'),
                );
            } catch (\InvalidArgumentException $e) {
                $errors[] = $this->error(
                    PayrollTimeValue::int($row['row_number'] ?? null, 'row_number'),
                    'row_rejected',
                    null,
                    $e->getMessage(),
                    PayrollTimeValue::string($row['row_hash'] ?? null, 'row_hash'),
                );
            }
        }

        $rejected = count($errors);
        $status = $accepted > 0 && $rejected > 0
            ? 'partial'
            : ($accepted > 0 ? 'imported' : 'failed');
        $recorded = $this->repository->recordImport(
            $supplierId,
            $periodStart,
            'csv',
            $originalName,
            $contentHash,
            $status,
            PayrollTimeValue::int($preview['total_rows'] ?? null, 'total_rows'),
            $accepted,
            $rejected,
            $duplicates,
            $errors,
            $userId,
        );
        $recorded['replayed'] = false;
        return $recorded;
    }

    /**
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   errors:list<array{row_number:int,error_code:string,field_name:?string,error_message:string,row_hash:string}>,
     *   row_errors:list<array{row_number:int,error_code:string,field_name:?string,error_message:string,row_hash:string}>
     * }
     */
    private function parseCsv(string $content): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('CSV se nepodařilo otevřít v paměti.');
        }
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);
        rewind($stream);
        $firstLine = fgets($stream);
        if ($firstLine === false) {
            fclose($stream);
            throw new \InvalidArgumentException('CSV je prázdné.');
        }
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        rewind($stream);
        $header = fgetcsv($stream, 0, $delimiter);
        if ($header === false) {
            fclose($stream);
            throw new \InvalidArgumentException('CSV nemá platnou hlavičku.');
        }
        $header = array_map(
            static fn (?string $value): string => trim($value ?? ''),
            $header,
        );
        foreach (self::REQUIRED as $required) {
            if (!in_array($required, $header, true)) {
                fclose($stream);
                throw new \InvalidArgumentException("CSV hlavička neobsahuje {$required}.");
            }
        }
        if (count($header) !== count(array_unique($header))) {
            fclose($stream);
            throw new \InvalidArgumentException('CSV hlavička obsahuje duplicitní názvy sloupců.');
        }

        $rows = [];
        $rowErrors = [];
        $line = 1;
        while (($values = fgetcsv($stream, 0, $delimiter)) !== false) {
            ++$line;
            if ($values === [null] || $values === ['']) {
                continue;
            }
            $raw = implode(
                $delimiter,
                array_map(static fn (?string $value): string => $value ?? '', $values),
            );
            $rowHash = hash('sha256', $raw, true);
            if (count($values) !== count($header)) {
                $rowErrors[] = $this->error(
                    $line,
                    'column_count',
                    null,
                    'Počet sloupců řádku neodpovídá hlavičce.',
                    $rowHash,
                );
                continue;
            }
            $combined = array_combine($header, $values);
            $row = [];
            foreach ($combined as $key => $value) {
                $row[$key] = trim($value ?? '');
            }
            $row['row_number'] = $line;
            $row['row_hash'] = $rowHash;
            $rows[] = $row;
        }
        fclose($stream);
        return ['rows' => $rows, 'errors' => $rowErrors, 'row_errors' => $rowErrors];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{entry:array<string,mixed>}|array{
     *   error:array{row_number:int,error_code:string,field_name:?string,error_message:string,row_hash:string}
     * }
     */
    private function validateRow(int $supplierId, string $period, array $row): array
    {
        $rowNumber = PayrollTimeValue::int($row['row_number'] ?? null, 'row_number');
        $rowHash = PayrollTimeValue::string($row['row_hash'] ?? null, 'row_hash');
        $employmentCode = $this->nullable(
            PayrollTimeValue::string($row['employment_code'] ?? '', 'employment_code'),
        );
        $employmentIdRaw = $this->nullable(
            PayrollTimeValue::string($row['employment_id'] ?? '', 'employment_id'),
        );
        $employmentId = null;
        if ($employmentIdRaw !== null) {
            if (!ctype_digit($employmentIdRaw) || (int) $employmentIdRaw <= 0) {
                return ['error' => $this->error(
                    $rowNumber,
                    'invalid_employment_id',
                    'employment_id',
                    'employment_id musí být kladné celé číslo.',
                    $rowHash,
                )];
            }
            $employmentId = (int) $employmentIdRaw;
        }
        $resolved = $this->repository->resolveEmployment(
            $supplierId,
            $employmentId,
            $employmentCode,
        );
        if ($resolved === null) {
            return ['error' => $this->error(
                $rowNumber,
                'employment_not_unique',
                'employment_code',
                'Pracovní vztah nebyl v této firmě jednoznačně nalezen; použijte employment_id a odpovídající employment_code.',
                $rowHash,
            )];
        }
        $externalId = trim(
            PayrollTimeValue::string($row['external_id'] ?? '', 'external_id'),
        );
        if ($externalId === '' || mb_strlen($externalId) > 191) {
            return ['error' => $this->error(
                $rowNumber,
                'invalid_external_id',
                'external_id',
                'external_id je povinné a smí mít nejvýše 191 znaků.',
                $rowHash,
            )];
        }
        $break = trim(
            PayrollTimeValue::string($row['break_minutes'] ?? '0', 'break_minutes'),
        );
        if ($break === '') {
            $break = '0';
        }
        if (!ctype_digit($break)) {
            return ['error' => $this->error(
                $rowNumber,
                'invalid_break',
                'break_minutes',
                'break_minutes musí být nezáporné celé číslo.',
                $rowHash,
            )];
        }
        $breakMinutes = (int) $break;
        $category = PayrollTimeValue::string($row['category'] ?? '', 'category');
        if (!in_array($category, self::CATEGORIES, true)) {
            return ['error' => $this->error(
                $rowNumber,
                'invalid_category',
                'category',
                'category není podporovaná kategorie pracovního času.',
                $rowHash,
            )];
        }
        $startsAt = PayrollTimeValue::string($row['starts_at'] ?? '', 'starts_at');
        $timezone = PayrollTimeValue::string($row['timezone'] ?? '', 'timezone');
        try {
            $interval = PayrollTimeInterval::fromIso(
                $startsAt,
                PayrollTimeValue::string($row['ends_at'] ?? '', 'ends_at'),
                $timezone,
            );
            $start = (new \DateTimeImmutable(
                $interval->startsAtUtc,
                new \DateTimeZone('UTC'),
            ))->setTimezone(new \DateTimeZone($timezone));
        } catch (\InvalidArgumentException $e) {
            return ['error' => $this->error(
                $rowNumber,
                'invalid_interval',
                'starts_at',
                $e->getMessage(),
                $rowHash,
            )];
        }
        if ($start->format('Y-m') !== $period) {
            return ['error' => $this->error(
                $rowNumber,
                'period_mismatch',
                'starts_at',
                'Začátek záznamu neleží v importovaném období.',
                $rowHash,
            )];
        }
        if ($breakMinutes >= $interval->durationMinutes) {
            return ['error' => $this->error(
                $rowNumber,
                'invalid_break',
                'break_minutes',
                'Přestávka musí být kratší než časový interval.',
                $rowHash,
            )];
        }

        $sourceHash = hash('sha256', "payroll-time-import\0{$resolved}\0{$externalId}", true);
        return ['entry' => [
            'row_number' => $rowNumber,
            'row_hash' => $rowHash,
            'employment_id' => $resolved,
            'employment_code' => $employmentCode,
            'starts_at' => $startsAt,
            'ends_at' => PayrollTimeValue::string($row['ends_at'] ?? '', 'ends_at'),
            'timezone' => $timezone,
            'category' => $category,
            'break_minutes' => $breakMinutes,
            'external_id' => $externalId,
            'source_hash' => $sourceHash,
        ]];
    }

    /**
     * @return array{
     *   row_number:int,
     *   error_code:string,
     *   field_name:?string,
     *   error_message:string,
     *   row_hash:string
     * }
     */
    private function error(
        int $row,
        string $code,
        ?string $field,
        string $message,
        string $rowHash,
    ): array {
        return [
            'row_number' => $row,
            'error_code' => $code,
            'field_name' => $field,
            'error_message' => $message,
            'row_hash' => $rowHash,
        ];
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * @return list<array{
     *   row_number:int,
     *   error_code:string,
     *   field_name:?string,
     *   error_message:string,
     *   row_hash:string
     * }>
     */
    private function errors(mixed $value): array
    {
        $rows = PayrollTimeValue::rows($value, '_errors');
        $result = [];
        foreach ($rows as $row) {
            $field = $row['field_name'] ?? null;
            if ($field !== null && !is_string($field)) {
                throw new \UnexpectedValueException('field_name musí být text nebo null.');
            }
            $result[] = [
                'row_number' => PayrollTimeValue::int(
                    $row['row_number'] ?? null,
                    'row_number',
                ),
                'error_code' => PayrollTimeValue::string(
                    $row['error_code'] ?? null,
                    'error_code',
                ),
                'field_name' => $field,
                'error_message' => PayrollTimeValue::string(
                    $row['error_message'] ?? null,
                    'error_message',
                ),
                'row_hash' => PayrollTimeValue::string(
                    $row['row_hash'] ?? null,
                    'row_hash',
                ),
            ];
        }
        return $result;
    }
}
