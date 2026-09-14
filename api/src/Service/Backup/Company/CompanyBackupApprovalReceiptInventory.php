<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Bezpečný inventář hashů schvalovacích potvrzení bez samotných hodnot. */
final readonly class CompanyBackupApprovalReceiptInventory
{
    public const FORMAT = 'myucto-company-approval-receipt-inventory';
    public const VERSION = 1;

    /** @var list<array{source_invoice_id:int,receipt_fingerprint:string,collision:bool}> */
    private array $entries;

    /** @var array<int,array{source_invoice_id:int,receipt_fingerprint:string,collision:bool}> */
    private array $entriesById;

    /**
     * @param iterable<mixed> $rows
     */
    public function __construct(
        iterable $rows = [],
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {
        $seenIds = [];
        $hashCounts = [];
        $pending = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || !isset($row['id'])
                || !is_int($row['id'])
                || $row['id'] < 1
                || !array_key_exists('approval_receipt_hash', $row)
                || !isset($row['target_collision'])
                || !is_bool($row['target_collision'])
            ) {
                throw self::error('approval_receipt_inventory_invalid');
            }
            $id = $row['id'];
            if (isset($seenIds[$id])) {
                throw self::error('approval_receipt_source_duplicate');
            }
            $seenIds[$id] = true;
            $hash = CompanyBackupApprovalReceiptPolicy::restoreHash(
                $row['approval_receipt_hash'],
                false,
            );
            if ($hash === null) {
                if ($row['target_collision']) {
                    throw self::error('approval_receipt_collision_context_invalid');
                }
                continue;
            }
            if (count($pending) >= $limits->maxReferenceRequirements) {
                throw self::error('approval_receipt_inventory_limit_exceeded');
            }
            $pending[$id] = [
                'hash' => $hash,
                'target_collision' => $row['target_collision'],
            ];
            $hashCounts[$hash] = ($hashCounts[$hash] ?? 0) + 1;
        }

        ksort($pending, SORT_NUMERIC);
        $entries = [];
        $entriesById = [];
        foreach ($pending as $id => $value) {
            $entry = [
                'source_invoice_id' => $id,
                'receipt_fingerprint' => hash('sha256', $value['hash']),
                'collision' => $value['target_collision'] || $hashCounts[$value['hash']] > 1,
            ];
            $entries[] = $entry;
            $entriesById[$id] = $entry;
        }
        $this->entries = $entries;
        $this->entriesById = $entriesById;
    }

    /** @param iterable<mixed> $rows */
    public static function fromRows(
        iterable $rows,
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ): self {
        return new self($rows, $limits);
    }

    /** @return list<array{source_invoice_id:int,receipt_fingerprint:string,collision:bool}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return array{source_invoice_id:int,receipt_fingerprint:string,collision:bool}|null */
    public function entry(int $sourceId): ?array
    {
        return $this->entriesById[$sourceId] ?? null;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function collisionCount(): int
    {
        return count(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['collision'],
        ));
    }

    /** @return array{format:string,version:int,entries:list<array{source_invoice_id:int,receipt_fingerprint:string,collision:bool}>} */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'entries' => $this->entries,
        ];
    }

    public function sha256(): string
    {
        return CanonicalJson::sha256($this->toArray());
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
