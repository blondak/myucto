<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;

/** Spouští odvozené materializace po importu v kanonickém pořadí. */
final readonly class CompanyBackupPostImportMaterializerRegistry
{
    private const MAX_MATERIALIZERS = 256;

    /** @var array<string,CompanyBackupPostImportMaterializer> */
    private array $materializers;

    /** @param array<mixed> $materializers */
    private function __construct(array $materializers)
    {
        if (!array_is_list($materializers)
            || count($materializers) > self::MAX_MATERIALIZERS
        ) {
            throw new \InvalidArgumentException(
                'Registr post-import materializací nemá platný tvar.',
            );
        }
        $byId = [];
        foreach ($materializers as $materializer) {
            if (!$materializer instanceof CompanyBackupPostImportMaterializer) {
                throw new \InvalidArgumentException(
                    'Registr obsahuje neplatnou post-import materializaci.',
                );
            }
            $id = $materializer->id();
            if (preg_match('/^[a-z][a-z0-9._-]{0,95}$/D', $id) !== 1) {
                throw new \InvalidArgumentException(
                    'Post-import materializace nemá bezpečný identifikátor.',
                );
            }
            if (isset($byId[$id])) {
                throw new \InvalidArgumentException(
                    'Duplicitní post-import materializace ' . $id . '.',
                );
            }
            $byId[$id] = $materializer;
        }
        ksort($byId, SORT_STRING);
        $this->materializers = $byId;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function production(): self
    {
        return new self([
            new CompanyBackupStockCategoryTreeMaterializer(),
        ]);
    }

    /** @param list<CompanyBackupPostImportMaterializer> $materializers */
    public static function fromMaterializers(array $materializers): self
    {
        return new self($materializers);
    }

    public function materialize(
        PDO $database,
        int $supplierId,
        TenantDataRegistrySnapshot $registry,
    ): int {
        self::assertTransaction($database);
        if ($supplierId < 1
            || $registry->profile
                !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
        ) {
            throw self::error('post_import_materializer_context_mismatch');
        }

        $materializedRows = 0;
        foreach ($this->materializers as $id => $materializer) {
            self::assertTransaction($database, $id);
            try {
                $count = $materializer->materialize(
                    $database,
                    $supplierId,
                    $registry,
                );
            } catch (\Throwable $e) {
                if (!$database->inTransaction()) {
                    throw self::error(
                        'post_import_transaction_lost',
                        $id,
                        $e,
                    );
                }
                if ($e instanceof CompanyBackupPostImportException) {
                    throw $e;
                }
                throw self::error(
                    'post_import_materializer_failed',
                    $id,
                    $e,
                );
            }
            self::assertTransaction($database, $id);
            if ($count < 0 || $materializedRows > PHP_INT_MAX - $count) {
                throw self::error(
                    'post_import_materializer_result_invalid',
                    $id,
                );
            }
            $materializedRows += $count;
        }
        return $materializedRows;
    }

    /** @phpstan-impure Materializace nesmí ukončit write transakci obnovy. */
    private static function assertTransaction(
        PDO $database,
        ?string $materializerId = null,
    ): void {
        if (!$database->inTransaction()) {
            throw self::error(
                'post_import_transaction_lost',
                $materializerId,
            );
        }
    }

    private static function error(
        string $errorCode,
        ?string $materializerId = null,
        ?\Throwable $previous = null,
    ): CompanyBackupPostImportException {
        return new CompanyBackupPostImportException(
            $errorCode,
            $materializerId === null
                ? null
                : 'materializer:' . $materializerId,
            $previous,
        );
    }
}
