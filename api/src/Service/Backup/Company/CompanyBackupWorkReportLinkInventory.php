<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;

/** Bezpečný inventář veřejných odkazů bez samotných tokenů. */
final readonly class CompanyBackupWorkReportLinkInventory
{
    public const FORMAT = 'myucto-company-work-report-link-inventory';
    public const VERSION = 1;

    /** @var list<array{source_link_id:int,token_fingerprint:string,collision:bool}> */
    private array $entries;

    /** @var array<int,array{source_link_id:int,token_fingerprint:string,collision:bool}> */
    private array $entriesById;

    /**
     * @param iterable<mixed> $rows
     */
    public function __construct(
        iterable $rows = [],
        CompanyBackupArchiveLimits $limits = new CompanyBackupArchiveLimits(),
    ) {
        $seenIds = [];
        $tokenCounts = [];
        $pending = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || array_is_list($row)
                || count($row) !== 3
                || !isset($row['id'])
                || !is_int($row['id'])
                || $row['id'] < 1
                || !array_key_exists('token', $row)
                || !isset($row['target_collision'])
                || !is_bool($row['target_collision'])
            ) {
                throw self::error('work_report_link_inventory_invalid');
            }
            if (count($pending) >= $limits->maxReferenceRequirements) {
                throw self::error('work_report_link_inventory_limit_exceeded');
            }
            $id = $row['id'];
            if (isset($seenIds[$id])) {
                throw self::error('work_report_link_source_duplicate');
            }
            $seenIds[$id] = true;
            $token = CompanyBackupWorkReportLinkPolicy::restoreToken($row['token'], false);
            $pending[$id] = [
                'token' => $token,
                'target_collision' => $row['target_collision'],
            ];
            $tokenCounts[$token] = ($tokenCounts[$token] ?? 0) + 1;
        }

        ksort($pending, SORT_NUMERIC);
        $entries = [];
        $entriesById = [];
        foreach ($pending as $id => $value) {
            $entry = [
                'source_link_id' => $id,
                'token_fingerprint' => hash('sha256', $value['token']),
                'collision' => $value['target_collision'] || $tokenCounts[$value['token']] > 1,
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

    /** @return list<array{source_link_id:int,token_fingerprint:string,collision:bool}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return array{source_link_id:int,token_fingerprint:string,collision:bool}|null */
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

    /** @return array{format:string,version:int,entries:list<array{source_link_id:int,token_fingerprint:string,collision:bool}>} */
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
            CompanyBackupWorkReportLinkPolicy::REGISTRY_KEY,
            CompanyBackupWorkReportLinkPolicy::COLUMN,
        );
    }
}
