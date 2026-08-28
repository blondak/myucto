<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;

/** Bezpečně materializuje DB odkazy do úplného inventáře souborových oblastí. */
final readonly class CompanyBackupFileCollector
{
    public function __construct(
        private CompanyBackupFileAreaRootResolver $roots =
            new CompanyBackupRuntimeFileAreaRootResolver(),
        private CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {}

    public function collect(
        PDO $snapshot,
        TenantDataRegistrySnapshot $registry,
        int $supplierId,
        CompanyBackupFileReferenceSource $source,
    ): CompanyBackupFileSnapshot {
        if ($supplierId < 1) {
            throw new \InvalidArgumentException('Firma souborového snapshotu musí mít kladné ID.');
        }

        $areas = [];
        $sourceFiles = [];
        $sourcePaths = [];
        $expandedBytes = 0;
        foreach ($this->definitions($registry) as $index => $definition) {
            $projection = CompanyBackupFileAreaProjection::fromDefinition(
                $definition,
                $registry->registry,
            );
            $references = $this->groupReferences(
                $source->references(
                    $snapshot,
                    $supplierId,
                    $definition,
                    $registry->registry,
                ),
                $projection,
                $supplierId,
            );
            $entries = [];
            $root = $references === []
                ? null
                : $this->resolvedRoot($projection);
            foreach ($references as $sourcePath => $owners) {
                $fingerprint = $root === null
                    ? null
                    : $this->fingerprint($root, $sourcePath, $projection);
                if ($fingerprint === null) {
                    if ($projection->policy === CompanyBackupFilePolicy::Required) {
                        throw new CompanyBackupFileSourceException(
                            'file_source_missing',
                            $projection->registryKey,
                            $sourcePath,
                        );
                    }
                    $entries[] = [
                        'source_path' => $sourcePath,
                        'archive_path' => null,
                        'state' => CompanyBackupFileState::Missing->value,
                        'bytes' => null,
                        'sha256' => null,
                        'owners' => array_values($owners),
                    ];
                    continue;
                }

                $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
                $suffix = preg_match('/^[a-z0-9]{1,10}$/D', $extension) === 1
                    ? '.' . $extension
                    : '';
                $archivePath = 'files/' . $projection->name . '/'
                    . $fingerprint['sha256'] . $suffix;
                $entries[] = [
                    'source_path' => $sourcePath,
                    'archive_path' => $archivePath,
                    'state' => CompanyBackupFileState::Present->value,
                    'bytes' => $fingerprint['bytes'],
                    'sha256' => $fingerprint['sha256'],
                    'owners' => array_values($owners),
                ];
                if (!isset($sourceFiles[$archivePath])) {
                    if (count($sourceFiles) >= $this->limits->maxEntries
                        || $fingerprint['bytes']
                            > $this->limits->maxExpandedBytes - $expandedBytes
                    ) {
                        throw $this->sourceError(
                            'file_source_limits_exceeded',
                            $projection,
                            $sourcePath,
                        );
                    }
                    $expandedBytes += $fingerprint['bytes'];
                    $sourceFiles[$archivePath] = $fingerprint['path'];
                    $sourcePaths[$archivePath] = $sourcePath;
                }
            }
            $areas[] = [
                'registry_key' => $projection->registryKey,
                'order' => $index + 1,
                'entries' => $entries,
            ];
        }
        ksort($sourceFiles, SORT_STRING);
        ksort($sourcePaths, SORT_STRING);

        try {
            $inventory = CompanyBackupFileInventory::fromArray([
                'format' => CompanyBackupFileInventory::FORMAT,
                'version' => CompanyBackupFileInventory::VERSION,
                'areas' => $areas,
            ], $registry);
            return new CompanyBackupFileSnapshot($inventory, $sourceFiles, $sourcePaths);
        } catch (CompanyBackupFileSourceException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw new CompanyBackupFileSourceException(
                'file_inventory_build_failed',
                'file-area:inventory',
                previous: $e,
            );
        }
    }

    /**
     * @param iterable<mixed> $references
     * @return array<string,array<string,array{
     *   registry_key:string,
     *   primary_key:array<string,int|string>,
     *   column:string,
     *   path:list<string>
     * }>>
     */
    private function groupReferences(
        iterable $references,
        CompanyBackupFileAreaProjection $projection,
        int $supplierId,
    ): array {
        $grouped = [];
        $collisionPaths = [];
        foreach ($references as $reference) {
            if (!$reference instanceof CompanyBackupFileReference) {
                throw new CompanyBackupFileSourceException(
                    'file_source_reference_invalid',
                    $projection->registryKey,
                );
            }
            if (!$projection->pathPolicy->accepts(
                $reference->sourcePath,
                $supplierId,
            )) {
                throw new CompanyBackupFileSourceException(
                    'file_source_tenant_mismatch',
                    $projection->registryKey,
                    $reference->sourcePath,
                );
            }
            $collisionKey = strtolower($reference->sourcePath);
            $knownPath = $collisionPaths[$collisionKey] ?? null;
            if ($knownPath !== null && $knownPath !== $reference->sourcePath) {
                throw new CompanyBackupFileSourceException(
                    'file_source_path_collision',
                    $projection->registryKey,
                    $reference->sourcePath,
                );
            }
            $collisionPaths[$collisionKey] = $reference->sourcePath;
            $signature = $reference->ownerSignature();
            if (isset($grouped[$reference->sourcePath][$signature])) {
                throw new CompanyBackupFileSourceException(
                    'file_source_reference_duplicate',
                    $projection->registryKey,
                    $reference->sourcePath,
                );
            }
            $grouped[$reference->sourcePath][$signature] = $reference->owner();
            if (count($grouped[$reference->sourcePath])
                > CompanyBackupFileEntry::MAX_OWNERS
            ) {
                throw new CompanyBackupFileSourceException(
                    'file_source_owner_limit_exceeded',
                    $projection->registryKey,
                    $reference->sourcePath,
                );
            }
        }
        ksort($grouped, SORT_STRING);
        foreach ($grouped as &$owners) {
            ksort($owners, SORT_STRING);
        }
        unset($owners);
        return $grouped;
    }

    private function resolvedRoot(CompanyBackupFileAreaProjection $projection): ?string
    {
        $configured = $this->roots->resolve($projection->storageSubdirectory);
        if ($configured === '' || str_contains($configured, "\0")) {
            throw new CompanyBackupFileSourceException(
                'file_area_root_invalid',
                $projection->registryKey,
            );
        }
        clearstatcache(true, $configured);
        if (!file_exists($configured)) {
            if (is_link($configured)) {
                throw new CompanyBackupFileSourceException(
                    'file_area_root_invalid',
                    $projection->registryKey,
                );
            }
            return null;
        }
        $resolved = @realpath($configured);
        if (!is_string($resolved) || !is_dir($resolved) || !is_readable($resolved)) {
            throw new CompanyBackupFileSourceException(
                'file_area_root_invalid',
                $projection->registryKey,
            );
        }
        return rtrim($resolved, '/\\');
    }

    /** @return array{path:string,bytes:int,sha256:string}|null */
    private function fingerprint(
        string $root,
        string $sourcePath,
        CompanyBackupFileAreaProjection $projection,
    ): ?array {
        $candidate = $root;
        $segments = explode('/', $sourcePath);
        foreach ($segments as $index => $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            clearstatcache(true, $candidate);
            if (is_link($candidate)) {
                throw $this->sourceError('file_source_path_unsafe', $projection, $sourcePath);
            }
            if (!file_exists($candidate)) {
                return null;
            }
            if ($index < count($segments) - 1 && !is_dir($candidate)) {
                throw $this->sourceError('file_source_path_unsafe', $projection, $sourcePath);
            }
        }

        $resolved = @realpath($candidate);
        $prefix = strtolower($root . DIRECTORY_SEPARATOR);
        if (!is_string($resolved)
            || !str_starts_with(strtolower($resolved), $prefix)
            || !is_file($resolved)
            || !is_readable($resolved)
        ) {
            throw $this->sourceError('file_source_path_unsafe', $projection, $sourcePath);
        }

        clearstatcache(true, $resolved);
        $before = @stat($resolved);
        if ($before === false || $before['size'] > $this->limits->maxEntryBytes) {
            throw $this->sourceError(
                $before === false ? 'file_source_unreadable' : 'file_source_size_exceeded',
                $projection,
                $sourcePath,
            );
        }
        $sha256 = @hash_file('sha256', $resolved);
        clearstatcache(true, $resolved);
        $after = @stat($resolved);
        if (!is_string($sha256)
            || $after === false
            || is_link($candidate)
            || !self::sameFile($before, $after)
        ) {
            throw $this->sourceError('file_source_changed', $projection, $sourcePath);
        }
        return [
            'path' => $resolved,
            'bytes' => $after['size'],
            'sha256' => $sha256,
        ];
    }

    /** @return list<TenantDataDefinition> */
    private function definitions(TenantDataRegistrySnapshot $snapshot): array
    {
        return array_values(array_filter(
            $snapshot->registry->definitionsFor($snapshot->profile),
            static fn (TenantDataDefinition $definition): bool =>
                $definition->kind === TenantDataObjectKind::FileArea
                && $definition->policy->hasMachineDataPayload(),
        ));
    }

    /**
     * @param array<int|string,int> $before
     * @param array<int|string,int> $after
     */
    private static function sameFile(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }

    private function sourceError(
        string $errorCode,
        CompanyBackupFileAreaProjection $projection,
        string $sourcePath,
    ): CompanyBackupFileSourceException {
        return new CompanyBackupFileSourceException(
            $errorCode,
            $projection->registryKey,
            $sourcePath,
        );
    }
}
