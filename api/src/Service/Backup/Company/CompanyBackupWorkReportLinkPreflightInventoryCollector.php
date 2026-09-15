<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use PDO;

/** Páruje běžné řádky s tokeny z chráněné obálky bez veřejného plaintext výstupu. */
final class CompanyBackupWorkReportLinkPreflightInventoryCollector
{
    /** @var array<int,string> */
    private array $tokens = [];

    /** @var array<int,true> */
    private array $rows = [];

    private bool $finished = false;

    public function __construct(
        private readonly CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {}

    public function acceptSecret(#[\SensitiveParameter] CompanyBackupSecretValue $value): void
    {
        $this->assertOpen();
        if ($value->registryKey !== CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY
            || $value->scope !== CompanyBackupSecretScope::Column
            || $value->name !== CompanyBackupWorkReportLinkPolicy::COLUMN
            || array_keys($value->primaryKey) !== ['id']
            || !is_int($value->primaryKey['id'])
            || $value->primaryKey['id'] < 1
        ) {
            throw self::error('work_report_link_secret_invalid');
        }
        if (count($this->tokens) >= $this->limits->maxReferenceRequirements) {
            throw self::error('work_report_link_inventory_limit_exceeded');
        }
        $id = $value->primaryKey['id'];
        if (isset($this->tokens[$id])) {
            throw self::error('work_report_link_secret_duplicate');
        }
        $token = $value->plaintext();
        CompanyBackupWorkReportLinksProjection::validateToken($token);
        $this->tokens[$id] = $token;
    }

    /** @param array<string,mixed> $row */
    public function acceptRow(#[\SensitiveParameter] array $row): void
    {
        $this->assertOpen();
        if (array_key_exists(CompanyBackupWorkReportLinkPolicy::COLUMN, $row)) {
            throw self::error('work_report_link_plaintext_row_forbidden');
        }
        $id = $row['id'] ?? null;
        if (!is_int($id) || $id < 1) {
            throw self::error('work_report_link_source_id_invalid');
        }
        CompanyBackupWorkReportLinksProjection::validateRow($row);
        if (count($this->rows) >= $this->limits->maxReferenceRequirements) {
            throw self::error('work_report_link_inventory_limit_exceeded');
        }
        if (isset($this->rows[$id])) {
            throw self::error('work_report_link_source_duplicate');
        }
        $this->rows[$id] = true;
    }

    public function finish(PDO $database): CompanyBackupWorkReportLinkInventory
    {
        $this->assertOpen();
        $this->finished = true;
        try {
            if (count($this->rows) !== count($this->tokens)) {
                throw self::error('work_report_link_secret_row_mismatch');
            }
            foreach ($this->rows as $id => $_) {
                if (!isset($this->tokens[$id])) {
                    throw self::error('work_report_link_secret_row_mismatch');
                }
            }

            return CompanyBackupWorkReportLinkInventory::fromRows(
                $this->records($database), $this->limits,
            );
        } finally {
            $this->tokens = [];
            $this->rows = [];
        }
    }

    /** @return \Generator<int,array{id:int,token:string,target_collision:bool}> */
    private function records(PDO $database): \Generator
    {
        foreach ($this->rows as $id => $_) {
            $token = $this->tokens[$id];
            yield [
                'id' => $id,
                'token' => $token,
                'target_collision' => CompanyBackupWorkReportLinkCollisionLookup::hasCollision($database, $token),
            ];
        }
    }

    private function assertOpen(): void
    {
        if ($this->finished) {
            throw self::error('work_report_link_inventory_already_finished');
        }
    }

    private static function error(string $code): CompanyBackupPreflightException
    {
        return new CompanyBackupPreflightException(
            $code,
            CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY,
            CompanyBackupWorkReportLinkPolicy::COLUMN,
        );
    }
}
