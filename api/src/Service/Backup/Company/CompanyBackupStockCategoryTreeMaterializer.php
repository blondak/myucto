<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;
use PDOStatement;

/** Z parent_id znovu sestaví materializované cesty cílového stromu kategorií. */
final readonly class CompanyBackupStockCategoryTreeMaterializer implements
    CompanyBackupPostImportMaterializer
{
    public const REGISTRY_KEY = 'table:stock_categories';

    private const MAX_PATH_LENGTH = 255;

    private const MAX_DEPTH = 65535;

    public function id(): string
    {
        return 'stock.category-tree';
    }

    public function materialize(
        PDO $database,
        int $supplierId,
        TenantDataRegistrySnapshot $registry,
    ): int {
        self::assertTransaction($database);
        if ($supplierId < 1
            || $registry->profile !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
        ) {
            throw self::error('post_import_stock_category_context_invalid');
        }
        if (!$this->hasValidDefinition($registry)) {
            return 0;
        }

        $parents = $this->readParents($database, $supplierId);
        $coordinates = $this->coordinates($parents);
        $this->writeCoordinates($database, $supplierId, $coordinates);
        $this->verifyCoordinates($database, $supplierId, $coordinates);
        self::assertTransaction($database);
        return count($coordinates);
    }

    private function hasValidDefinition(
        TenantDataRegistrySnapshot $registry,
    ): bool {
        $definition = $registry->registry->definition(self::REGISTRY_KEY);
        if ($definition === null) {
            return false;
        }
        if ($definition->kind !== TenantDataObjectKind::Table
            || $definition->policy !== TenantDataPolicy::TenantOwned
            || !$definition->hasProfile(
                TenantDataRegistry::COMPANY_BACKUP_PROFILE,
            )
        ) {
            throw self::error('post_import_stock_category_registry_invalid');
        }
        try {
            $projection = CompanyBackupTableProjection::fromDefinition(
                $definition,
            );
        } catch (CompanyBackupDataSourceException $e) {
            throw self::error(
                'post_import_stock_category_registry_invalid',
                $e,
            );
        }
        if ($projection->name !== 'stock_categories'
            || $projection->omitColumns
                !== CompanyBackupStockCategoriesProjection::omitColumns()
            || in_array('path', $projection->dataColumns, true)
            || in_array('depth', $projection->dataColumns, true)
        ) {
            throw self::error('post_import_stock_category_registry_invalid');
        }
        return true;
    }

    /** @return array<int,?int> */
    private function readParents(PDO $database, int $supplierId): array
    {
        try {
            $statement = $database->prepare(
                'SELECT id, parent_id FROM stock_categories'
                    . ' WHERE supplier_id = ? ORDER BY id',
            );
            if (!$statement instanceof PDOStatement
                || !$statement->execute([$supplierId])
            ) {
                throw new \RuntimeException('Strom kategorií nelze přečíst.');
            }
            $parents = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $id = self::positiveInt($row['id'] ?? null);
                $parent = ($row['parent_id'] ?? null) === null
                    ? null
                    : self::positiveInt($row['parent_id']);
                if ($id === null
                    || (($row['parent_id'] ?? null) !== null
                        && $parent === null)
                    || array_key_exists($id, $parents)
                ) {
                    throw self::error(
                        'post_import_stock_category_tree_invalid',
                    );
                }
                $parents[$id] = $parent;
            }
            if (!$statement->closeCursor()) {
                throw new \RuntimeException(
                    'Kurzor stromu kategorií nelze uzavřít.',
                );
            }
        } catch (CompanyBackupPostImportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw self::error(
                'post_import_stock_category_tree_read_failed',
                $e,
            );
        }
        self::assertTransaction($database);
        return $parents;
    }

    /**
     * @param array<int,?int> $parents
     * @return array<int,array{path:string,depth:int}>
     */
    private function coordinates(array $parents): array
    {
        $coordinates = [];
        foreach (array_keys($parents) as $startId) {
            if (isset($coordinates[$startId])) {
                continue;
            }
            $trail = [];
            $visited = [];
            $currentId = $startId;
            while (!isset($coordinates[$currentId])) {
                if (isset($visited[$currentId])) {
                    throw self::error('post_import_stock_category_cycle');
                }
                if (!array_key_exists($currentId, $parents)) {
                    throw self::error(
                        'post_import_stock_category_parent_invalid',
                    );
                }
                $visited[$currentId] = true;
                $trail[] = $currentId;
                $parentId = $parents[$currentId];
                if ($parentId === null) {
                    $basePath = '/';
                    $baseDepth = -1;
                    break;
                }
                if (!array_key_exists($parentId, $parents)) {
                    throw self::error(
                        'post_import_stock_category_parent_invalid',
                    );
                }
                $currentId = $parentId;
            }
            if (isset($coordinates[$currentId])) {
                $basePath = $coordinates[$currentId]['path'];
                $baseDepth = $coordinates[$currentId]['depth'];
            }
            for ($index = count($trail) - 1; $index >= 0; $index--) {
                $id = $trail[$index];
                if (isset($coordinates[$id])) {
                    $basePath = $coordinates[$id]['path'];
                    $baseDepth = $coordinates[$id]['depth'];
                    continue;
                }
                $path = $basePath . $id . '/';
                $depth = $baseDepth + 1;
                if (strlen($path) > self::MAX_PATH_LENGTH
                    || $depth > self::MAX_DEPTH
                ) {
                    throw self::error(
                        'post_import_stock_category_path_limit_exceeded',
                    );
                }
                $coordinates[$id] = ['path' => $path, 'depth' => $depth];
                $basePath = $path;
                $baseDepth = $depth;
            }
        }
        ksort($coordinates, SORT_NUMERIC);
        return $coordinates;
    }

    /** @param array<int,array{path:string,depth:int}> $coordinates */
    private function writeCoordinates(
        PDO $database,
        int $supplierId,
        array $coordinates,
    ): void {
        try {
            $statement = $database->prepare(
                'UPDATE stock_categories SET path = ?, depth = ?,'
                    . ' updated_at = updated_at'
                    . ' WHERE supplier_id = ? AND id = ?',
            );
            if (!$statement instanceof PDOStatement) {
                throw new \RuntimeException(
                    'Zápis stromu kategorií nelze připravit.',
                );
            }
            foreach ($coordinates as $id => $coordinate) {
                if (!$statement->execute([
                    $coordinate['path'],
                    $coordinate['depth'],
                    $supplierId,
                    $id,
                ])) {
                    throw new \RuntimeException(
                        'Souřadnice kategorie nelze zapsat.',
                    );
                }
                if (!$statement->closeCursor()) {
                    throw new \RuntimeException(
                        'Kurzor zápisu kategorie nelze uzavřít.',
                    );
                }
            }
        } catch (\Throwable $e) {
            throw self::error(
                'post_import_stock_category_tree_write_failed',
                $e,
            );
        }
        self::assertTransaction($database);
    }

    /** @param array<int,array{path:string,depth:int}> $coordinates */
    private function verifyCoordinates(
        PDO $database,
        int $supplierId,
        array $coordinates,
    ): void {
        try {
            $statement = $database->prepare(
                'SELECT id, path, depth FROM stock_categories'
                    . ' WHERE supplier_id = ? ORDER BY id',
            );
            if (!$statement instanceof PDOStatement
                || !$statement->execute([$supplierId])
            ) {
                throw new \RuntimeException(
                    'Ověření stromu kategorií nelze spustit.',
                );
            }
            $seen = 0;
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $id = self::positiveInt($row['id'] ?? null);
                $depth = self::nonNegativeInt($row['depth'] ?? null);
                $expected = $id === null ? null : $coordinates[$id] ?? null;
                if ($expected === null
                    || !is_string($row['path'] ?? null)
                    || $row['path'] !== $expected['path']
                    || $depth !== $expected['depth']
                ) {
                    throw new \RuntimeException(
                        'Materializovaný strom kategorií se neshoduje.',
                    );
                }
                $seen++;
            }
            if (!$statement->closeCursor()
                || $seen !== count($coordinates)
            ) {
                throw new \RuntimeException(
                    'Materializovaný strom kategorií není úplný.',
                );
            }
        } catch (\Throwable $e) {
            throw self::error(
                'post_import_stock_category_tree_verification_failed',
                $e,
            );
        }
        self::assertTransaction($database);
    }

    private static function positiveInt(mixed $value): ?int
    {
        $integer = self::nonNegativeInt($value);
        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
        ) {
            return null;
        }
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        return is_int($validated) ? $validated : null;
    }

    /** @phpstan-impure Materializace nesmí ukončit write transakci obnovy. */
    private static function assertTransaction(PDO $database): void
    {
        if (!$database->inTransaction()) {
            throw self::error('post_import_transaction_lost');
        }
    }

    private static function error(
        string $errorCode,
        ?\Throwable $previous = null,
    ): CompanyBackupPostImportException {
        return new CompanyBackupPostImportException(
            $errorCode,
            self::REGISTRY_KEY,
            $previous,
        );
    }
}
