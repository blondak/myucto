<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Podmínka vybírající cíl polymorfní embedded reference podle souběžné hodnoty. */
final readonly class CompanyBackupEmbeddedReferenceCondition
{
    /** @var list<string> */
    public array $path;

    /** @param list<string> $path */
    private function __construct(array $path, public string $equals)
    {
        $this->path = $path;
    }

    /** @param list<string> $referencePath */
    public static function fromArray(
        mixed $value,
        string $registryKey,
        string $column,
        array $referencePath,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid($registryKey, $column);
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['equals', 'path']) {
            throw self::invalid($registryKey, $column);
        }

        $path = $value['path'];
        $equals = $value['equals'];
        if (!is_array($path)
            || !array_is_list($path)
            || $path === []
            || !is_string($equals)
            || preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $equals) !== 1
        ) {
            throw self::invalid($registryKey, $column);
        }
        $segments = [];
        $referenceWildcardPositions = [];
        foreach ($referencePath as $position => $segment) {
            if ($segment === '*') {
                $referenceWildcardPositions[] = $position;
            }
        }
        $wildcards = 0;
        foreach ($path as $segment) {
            if (!is_string($segment)
                || ($segment !== '*'
                    && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $segment) !== 1)
            ) {
                throw self::invalid($registryKey, $column);
            }
            if ($segment === '*') {
                $referencePosition = $referenceWildcardPositions[$wildcards] ?? null;
                if ($referencePosition === null
                    || $segments !== array_slice($referencePath, 0, $referencePosition)
                ) {
                    throw self::invalid($registryKey, $column);
                }
                $wildcards++;
            }
            $segments[] = $segment;
        }
        if ($wildcards > count($referenceWildcardPositions)) {
            throw self::invalid($registryKey, $column);
        }

        return new self($segments, $equals);
    }

    public function signature(): string
    {
        return implode('.', $this->path) . '=' . $this->equals;
    }

    private static function invalid(
        string $registryKey,
        string $column,
    ): CompanyBackupDataSourceException {
        return new CompanyBackupDataSourceException(
            'data_embedded_reference_metadata_invalid',
            $registryKey,
            $column,
        );
    }
}
