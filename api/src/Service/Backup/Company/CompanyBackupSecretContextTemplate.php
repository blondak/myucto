<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Striktní AAD template odvozený jen z identity a ownershipu DB řádku. */
final readonly class CompanyBackupSecretContextTemplate
{
    private const MAX_TEMPLATE_BYTES = 128;
    private const MAX_RESOLVED_BYTES = 512;

    /** @var list<string> */
    public array $columns;

    /** @param list<string> $columns */
    private function __construct(
        public string $template,
        array $columns,
    ) {
        $this->columns = $columns;
    }

    public static function fromString(
        string $template,
        string $registryKey,
        string $column,
    ): self {
        if ($template === ''
            || strlen($template) > self::MAX_TEMPLATE_BYTES
            || preg_match('/[\x00-\x20\x7f]/D', $template) === 1
        ) {
            throw self::metadataError($registryKey, $column);
        }
        if (preg_match('/^[a-z][a-z0-9:._-]{0,127}$/D', $template) === 1) {
            return new self($template, []);
        }
        if (preg_match('/^[a-z][a-z0-9:._{}-]{0,127}$/D', $template) !== 1) {
            throw self::metadataError($registryKey, $column);
        }

        preg_match_all(
            '/\{([a-z][a-z0-9_]{0,63})\}/',
            $template,
            $matches,
        );
        $rawColumns = $matches[1];
        if ($rawColumns === []) {
            throw self::metadataError($registryKey, $column);
        }
        $columns = [];
        $seen = [];
        foreach ($rawColumns as $rawColumn) {
            if (isset($seen[$rawColumn])) {
                throw self::metadataError($registryKey, $column);
            }
            $seen[$rawColumn] = true;
            $columns[] = $rawColumn;
        }
        $shape = preg_replace(
            '/\{[a-z][a-z0-9_]{0,63}\}/',
            '1',
            $template,
        );
        if (!is_string($shape)
            || preg_match('/^[a-z][a-z0-9:._-]{0,127}$/D', $shape) !== 1
        ) {
            throw self::metadataError($registryKey, $column);
        }

        return new self($template, $columns);
    }

    /** @param list<string> $allowedColumns */
    public function assertAllowedColumns(
        array $allowedColumns,
        string $registryKey,
        string $column,
    ): void {
        $allowed = array_fill_keys($allowedColumns, true);
        foreach ($this->columns as $contextColumn) {
            if (!isset($allowed[$contextColumn])) {
                throw self::metadataError($registryKey, $column);
            }
        }
    }

    /** @param array<string,mixed> $row */
    public function resolve(
        array $row,
        string $registryKey,
        string $column,
    ): string {
        $context = $this->template;
        foreach ($this->columns as $contextColumn) {
            $value = $row[$contextColumn] ?? null;
            if (is_int($value)) {
                $replacement = (string) $value;
            } elseif (is_string($value)) {
                $replacement = $value;
            } else {
                throw self::runtimeError($registryKey, $column);
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/D', $replacement)
                !== 1
            ) {
                throw self::runtimeError($registryKey, $column);
            }
            $context = str_replace(
                '{' . $contextColumn . '}',
                $replacement,
                $context,
            );
        }
        if (strlen($context) > self::MAX_RESOLVED_BYTES
            || preg_match('/^[A-Za-z][A-Za-z0-9:._-]{0,511}$/D', $context) !== 1
        ) {
            throw self::runtimeError($registryKey, $column);
        }

        return $context;
    }

    private static function metadataError(
        string $registryKey,
        string $column,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'secret_source_storage_invalid',
            $registryKey,
            $column,
        );
    }

    private static function runtimeError(
        string $registryKey,
        string $column,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'secret_source_context_invalid',
            $registryKey,
            $column,
        );
    }
}
