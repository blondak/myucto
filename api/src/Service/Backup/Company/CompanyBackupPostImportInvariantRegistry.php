<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataRegistry;
use MyInvoice\Service\Backup\Registry\TenantDataRegistrySnapshot;
use PDO;

/** Spouští kontroly jednotlivých agend bez centrálního seznamu modulů. */
final readonly class CompanyBackupPostImportInvariantRegistry
{
    private const MAX_INVARIANTS = 256;

    /** @var array<string,CompanyBackupPostImportInvariant> */
    private array $invariants;

    /** @param array<mixed> $invariants */
    private function __construct(array $invariants)
    {
        if (!array_is_list($invariants)
            || count($invariants) > self::MAX_INVARIANTS
        ) {
            throw new \InvalidArgumentException(
                'Registr post-import invariantů nemá platný tvar.',
            );
        }
        $byId = [];
        foreach ($invariants as $invariant) {
            if (!$invariant instanceof CompanyBackupPostImportInvariant) {
                throw new \InvalidArgumentException(
                    'Registr obsahuje neplatný post-import invariant.',
                );
            }
            $id = $invariant->id();
            if (preg_match('/^[a-z][a-z0-9._-]{0,95}$/D', $id) !== 1) {
                throw new \InvalidArgumentException(
                    'Post-import invariant nemá bezpečný identifikátor.',
                );
            }
            if (isset($byId[$id])) {
                throw new \InvalidArgumentException(
                    'Duplicitní post-import invariant ' . $id . '.',
                );
            }
            $byId[$id] = $invariant;
        }
        ksort($byId, SORT_STRING);
        $this->invariants = $byId;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** @param list<CompanyBackupPostImportInvariant> $invariants */
    public static function fromInvariants(array $invariants): self
    {
        return new self($invariants);
    }

    public function validate(
        PDO $database,
        int $supplierId,
        TenantDataRegistrySnapshot $registry,
    ): CompanyBackupPostImportInvariantReport {
        self::assertTransaction($database);
        if ($supplierId < 1
            || $registry->profile
                !== TenantDataRegistry::COMPANY_BACKUP_PROFILE
        ) {
            throw self::error('post_import_invariant_context_mismatch');
        }

        $results = [];
        foreach ($this->invariants as $id => $invariant) {
            self::assertTransaction($database, $id);
            try {
                $checkCount = $invariant->validate(
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
                throw self::error('post_import_invariant_failed', $id, $e);
            }
            self::assertTransaction($database, $id);
            if ($checkCount < 0) {
                throw self::error(
                    'post_import_invariant_result_invalid',
                    $id,
                );
            }
            $results[] = ['id' => $id, 'check_count' => $checkCount];
        }

        try {
            return new CompanyBackupPostImportInvariantReport(
                $supplierId,
                $registry->fingerprint,
                $results,
            );
        } catch (\InvalidArgumentException $e) {
            throw self::error(
                'post_import_invariant_report_invalid',
                previous: $e,
            );
        }
    }

    /** @phpstan-impure Doménová kontrola nesmí ukončit write transakci. */
    private static function assertTransaction(
        PDO $database,
        ?string $invariantId = null,
    ): void {
        if (!$database->inTransaction()) {
            throw self::error(
                'post_import_transaction_lost',
                $invariantId,
            );
        }
    }

    private static function error(
        string $errorCode,
        ?string $invariantId = null,
        ?\Throwable $previous = null,
    ): CompanyBackupPostImportException {
        return new CompanyBackupPostImportException(
            $errorCode,
            $invariantId === null ? null : 'invariant:' . $invariantId,
            $previous,
        );
    }
}
