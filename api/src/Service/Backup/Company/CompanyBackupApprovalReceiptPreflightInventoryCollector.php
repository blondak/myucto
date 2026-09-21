<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Drží jen nenulové hashe a předává inventáři bezpečné příznaky kolizí. */
final class CompanyBackupApprovalReceiptPreflightInventoryCollector
{
    /** @var array<int,string> */
    private array $hashes = [];

    private bool $finished = false;

    public function __construct(
        private readonly CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {}

    /** @param array<string,mixed> $row */
    public function acceptRow(#[\SensitiveParameter] array $row): void
    {
        $this->assertOpen();
        $id = $row['id'] ?? null;
        if (!is_int($id) || $id < 1 || !array_key_exists(CompanyBackupApprovalReceiptPolicy::COLUMN, $row)) {
            throw self::error('approval_receipt_inventory_invalid');
        }
        $hash = CompanyBackupApprovalReceiptPolicy::restoreHash($row[CompanyBackupApprovalReceiptPolicy::COLUMN], false);
        if ($hash === null) {
            return;
        }
        // Duplicitní ID všech řádků včetně NULL hlídá sdílený source identity index.
        if (array_key_exists($id, $this->hashes)) {
            throw self::error('approval_receipt_source_duplicate');
        }
        if (count($this->hashes) >= $this->limits->maxReferenceRequirements) {
            throw self::error('approval_receipt_inventory_limit_exceeded');
        }
        $this->hashes[$id] = $hash;
    }

    public function finish(PDO $database): CompanyBackupApprovalReceiptInventory
    {
        $this->assertOpen();
        $this->finished = true;
        try {
            return CompanyBackupApprovalReceiptInventory::fromRows($this->records($database), $this->limits);
        } finally {
            $this->hashes = [];
        }
    }

    /** @return \Generator<int,array{id:int,approval_receipt_hash:string,target_collision:bool}> */
    private function records(PDO $database): \Generator
    {
        foreach ($this->hashes as $id => $hash) {
            yield [
                'id' => $id,
                'approval_receipt_hash' => $hash,
                'target_collision' => CompanyBackupApprovalReceiptCollisionLookup::hasCollision($database, $hash),
            ];
        }
    }

    private function assertOpen(): void
    {
        if ($this->finished) {
            throw self::error('approval_receipt_inventory_already_finished');
        }
    }

    private static function error(string $code): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException(
            $code,
            CompanyBackupApprovalReceiptPolicy::REGISTRY_KEY,
            CompanyBackupApprovalReceiptPolicy::COLUMN,
        );
    }
}
