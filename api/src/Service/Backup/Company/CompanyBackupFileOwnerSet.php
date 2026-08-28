<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use MyInvoice\Service\Backup\Registry\TenantDataDefinition;
use MyInvoice\Service\Backup\Registry\TenantDataObjectKind;
use MyInvoice\Service\Backup\Registry\TenantDataPolicy;
use MyInvoice\Service\Backup\Registry\TenantDataRegistry;

/** Úplný allowlist databázových vlastníků jedné souborové oblasti. */
final readonly class CompanyBackupFileOwnerSet
{
    private const MAX_OWNERS = 256;

    /** @var list<CompanyBackupFileOwnerDefinition> */
    public array $owners;

    /** @var array<string,CompanyBackupFileOwnerDefinition> */
    private array $bySignature;

    /**
     * @param list<CompanyBackupFileOwnerDefinition> $owners
     * @param array<string,CompanyBackupFileOwnerDefinition> $bySignature
     */
    private function __construct(array $owners, array $bySignature)
    {
        $this->owners = $owners;
        $this->bySignature = $bySignature;
    }

    public static function fromDefinition(
        TenantDataDefinition $area,
        TenantDataRegistry $registry,
    ): self {
        $metadata = $area->details['file_owners'] ?? null;
        if (!is_array($metadata)
            || !array_is_list($metadata)
            || $metadata === []
            || count($metadata) > self::MAX_OWNERS
        ) {
            throw self::invalid($area->key);
        }

        $owners = [];
        $bySignature = [];
        $previous = null;
        foreach ($metadata as $value) {
            $owner = CompanyBackupFileOwnerDefinition::fromArray($value, $area->key);
            self::assertTarget($owner, $area, $registry);
            $signature = $owner->signature();
            if (isset($bySignature[$signature])
                || ($previous !== null && strcmp($previous, $signature) >= 0)
            ) {
                throw self::invalid($area->key);
            }
            $previous = $signature;
            $owners[] = $owner;
            $bySignature[$signature] = $owner;
        }
        return new self($owners, $bySignature);
    }

    /** @param list<string> $path */
    public function owner(
        string $registryKey,
        string $column,
        array $path,
    ): ?CompanyBackupFileOwnerDefinition {
        return $this->bySignature[
            CompanyBackupFileOwnerDefinition::signatureFor(
                $registryKey,
                $column,
                $path,
            )
        ] ?? null;
    }

    private static function assertTarget(
        CompanyBackupFileOwnerDefinition $owner,
        TenantDataDefinition $area,
        TenantDataRegistry $registry,
    ): void {
        $target = $registry->definition($owner->registryKey);
        $primaryKey = $target?->details['primary_key'] ?? null;
        if ($target === null
            || !in_array(
                $target->kind,
                [TenantDataObjectKind::Table, TenantDataObjectKind::LogicalObject],
                true,
            )
            || !$target->hasProfile(TenantDataRegistry::COMPANY_BACKUP_PROFILE)
            || !$target->policy->hasMachineDataPayload()
            || $target->policy === TenantDataPolicy::Unsupported
            || !is_array($primaryKey)
            || !array_is_list($primaryKey)
            || $primaryKey === []
        ) {
            throw self::invalid($area->key);
        }
        foreach ($primaryKey as $column) {
            if (!is_string($column)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1
            ) {
                throw self::invalid($area->key);
            }
        }
    }

    private static function invalid(string $registryKey): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            'Souborová oblast ' . $registryKey . ' nemá úplný allowlist vlastníků.',
        );
    }
}
