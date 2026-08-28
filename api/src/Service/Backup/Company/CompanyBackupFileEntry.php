<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Jeden existující nebo historicky chybějící zdroj registrované oblasti. */
final readonly class CompanyBackupFileEntry
{
    private const MAX_SOURCE_PATH_BYTES = 1_024;
    public const MAX_OWNERS = 1_024;

    /**
     * @var list<array{
     *   registry_key:string,
     *   primary_key:array<string,int|string>,
     *   column:string,
     *   path:list<string>
     * }>
     */
    public array $owners;

    /**
     * @param list<array{
     *   registry_key:string,
     *   primary_key:array<string,int|string>,
     *   column:string,
     *   path:list<string>
     * }> $owners
     */
    private function __construct(
        public string $sourcePath,
        public ?string $archivePath,
        public CompanyBackupFileState $state,
        public ?int $bytes,
        public ?string $sha256,
        array $owners,
    ) {
        $this->owners = $owners;
    }

    public static function fromArray(
        mixed $value,
        string $areaName,
        CompanyBackupFilePolicy $policy,
        TenantDataRegistry $registry,
    ): self {
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid('entry');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'archive_path',
            'bytes',
            'owners',
            'sha256',
            'source_path',
            'state',
        ]) {
            throw self::invalid('entry');
        }

        $sourcePath = self::normalizeSourcePath($value['source_path']);
        $stateValue = $value['state'];
        $state = is_string($stateValue)
            ? CompanyBackupFileState::tryFrom($stateValue)
            : null;
        $archivePath = $value['archive_path'];
        $bytes = $value['bytes'];
        $sha256 = $value['sha256'];
        if ($state === null) {
            throw self::invalid('state');
        }
        if ($state === CompanyBackupFileState::Present) {
            if (!is_string($archivePath)
                || !is_int($bytes)
                || $bytes < 0
                || !is_string($sha256)
                || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
                || !self::validArchivePath($archivePath, $areaName, $sha256)
            ) {
                throw self::invalid('archive_path');
            }
        } elseif ($policy !== CompanyBackupFilePolicy::HistoricalOptional
            || $archivePath !== null
            || $bytes !== null
            || $sha256 !== null
        ) {
            throw self::invalid($policy->value);
        }

        $owners = self::owners($value['owners'], $registry);
        return new self(
            $sourcePath,
            $archivePath,
            $state,
            $bytes,
            $sha256,
            $owners,
        );
    }

    /**
     * @return array{
     *   source_path:string,
     *   archive_path:?string,
     *   state:string,
     *   bytes:?int,
     *   sha256:?string,
     *   owners:list<array{
     *     registry_key:string,
     *     primary_key:array<string,int|string>,
     *     column:string,
     *     path:list<string>
     *   }>
     * }
     */
    public function toArray(): array
    {
        return [
            'source_path' => $this->sourcePath,
            'archive_path' => $this->archivePath,
            'state' => $this->state->value,
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
            'owners' => $this->owners,
        ];
    }

    public static function normalizeSourcePath(mixed $value): string
    {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > self::MAX_SOURCE_PATH_BYTES
            || preg_match('//u', $value) !== 1
            || str_starts_with($value, '/')
            || str_contains($value, '\\')
            || preg_match('/\A[A-Za-z]:/', $value) === 1
            || str_contains($value, "\0")
        ) {
            throw self::invalid('source_path');
        }
        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw self::invalid('source_path');
            }
        }
        return $value;
    }

    private static function validArchivePath(
        string $path,
        string $areaName,
        string $sha256,
    ): bool {
        $prefix = 'files/' . $areaName . '/';
        if (!str_starts_with($path, $prefix)) {
            return false;
        }
        $name = substr($path, strlen($prefix));
        return preg_match(
            '/^' . preg_quote($sha256, '/') . '(?:\.[a-z0-9]{1,10})?$/D',
            $name,
        ) === 1;
    }

    /**
     * @return list<array{
     *   registry_key:string,
     *   primary_key:array<string,int|string>,
     *   column:string,
     *   path:list<string>
     * }>
     */
    private static function owners(mixed $value, TenantDataRegistry $registry): array
    {
        if (!is_array($value)
            || !array_is_list($value)
            || $value === []
            || count($value) > self::MAX_OWNERS
        ) {
            throw self::invalid('owners');
        }
        $owners = [];
        $signatures = [];
        $orderedSignatures = [];
        foreach ($value as $owner) {
            if (!is_array($owner) || array_is_list($owner)) {
                throw self::invalid('owners');
            }
            $keys = array_keys($owner);
            sort($keys, SORT_STRING);
            if ($keys !== ['column', 'path', 'primary_key', 'registry_key']) {
                throw self::invalid('owners');
            }
            $registryKey = $owner['registry_key'];
            $column = $owner['column'];
            $primaryKey = $owner['primary_key'];
            $path = self::ownerPath($owner['path']);
            $definition = is_string($registryKey)
                ? $registry->definition($registryKey)
                : null;
            if ($definition === null
                || !in_array(
                    $definition->kind,
                    [TenantDataObjectKind::Table, TenantDataObjectKind::LogicalObject],
                    true,
                )
                || !$definition->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
                || $definition->policy === TenantDataPolicy::Unsupported
                || !is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
                || !is_array($primaryKey)
                || array_is_list($primaryKey)
            ) {
                throw self::invalid('owners');
            }
            $expectedKey = $definition->details['primary_key'] ?? null;
            if (!is_array($expectedKey) || !array_is_list($expectedKey) || $expectedKey === []) {
                throw self::invalid('owners');
            }
            $actualColumns = array_keys($primaryKey);
            $expectedColumns = $expectedKey;
            sort($actualColumns, SORT_STRING);
            sort($expectedColumns, SORT_STRING);
            if ($actualColumns !== $expectedColumns) {
                throw self::invalid('owners');
            }
            $normalizedKey = [];
            foreach ($primaryKey as $keyColumn => $keyValue) {
                if (!is_string($keyColumn)
                    || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $keyColumn) !== 1
                    || !self::validKeyValue($keyValue)
                ) {
                    throw self::invalid('owners');
                }
                $normalizedKey[$keyColumn] = $keyValue;
            }
            ksort($normalizedKey, SORT_STRING);
            $normalized = [
                'registry_key' => $definition->key,
                'primary_key' => $normalizedKey,
                'column' => $column,
                'path' => $path,
            ];
            $signature = $definition->key . ':'
                . CanonicalJson::encode($normalizedKey) . ':' . $column . ':'
                . CanonicalJson::encode($path);
            if (isset($signatures[$signature])) {
                throw self::invalid('owners');
            }
            $signatures[$signature] = true;
            $orderedSignatures[] = $signature;
            $owners[$signature] = $normalized;
        }
        ksort($owners, SORT_STRING);
        if (array_keys($owners) !== $orderedSignatures) {
            throw self::invalid('owners');
        }
        return array_values($owners);
    }

    /** @return list<string> */
    private static function ownerPath(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 32) {
            throw self::invalid('owners');
        }
        $path = [];
        foreach ($value as $segment) {
            if (!is_string($segment)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $segment) !== 1
            ) {
                throw self::invalid('owners');
            }
            $path[] = $segment;
        }
        return $path;
    }

    private static function validKeyValue(mixed $value): bool
    {
        return is_int($value) && $value >= 0
            || is_string($value)
                && $value !== ''
                && strlen($value) <= 255
                && preg_match('//u', $value) === 1
                && !str_contains($value, "\0");
    }

    private static function invalid(string $field): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            'Souborový inventář má neplatné pole ' . $field . '.',
        );
    }
}
