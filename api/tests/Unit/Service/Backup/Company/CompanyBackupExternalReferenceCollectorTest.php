<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupArchiveLimits;
use MyInvoice\Service\Backup\Company\CompanyBackupExternalReferenceCollector;
use MyInvoice\Service\Backup\Company\CompanyBackupPreflightException;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceOccurrence;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use PHPUnit\Framework\TestCase;

final class CompanyBackupExternalReferenceCollectorTest extends TestCase
{
    public function testDeduplicatesOnlyExternalRequirementsAndTightensFallbacks(): void
    {
        $actorWithBothFallbacks = $this->occurrence(
            CompanyBackupReferenceMapping::Actor,
            9,
            ['null', 'restore_actor'],
            'approved_by',
        );
        $actorWithRestoreFallback = $this->occurrence(
            CompanyBackupReferenceMapping::Actor,
            9,
            ['restore_actor'],
            'created_by',
        );
        $global = $this->occurrence(
            CompanyBackupReferenceMapping::GlobalNaturalKey,
            7,
            [],
            'country_id',
        );
        $credential = $this->occurrence(
            CompanyBackupReferenceMapping::CredentialDecision,
            11,
            [],
            'vault_credential_id',
        );
        $tenant = $this->occurrence(
            CompanyBackupReferenceMapping::TenantId,
            5,
            [],
            'supplier_id',
        );

        $collector = new CompanyBackupExternalReferenceCollector();
        foreach ([
            $actorWithBothFallbacks,
            $global,
            $tenant,
            $credential,
            $actorWithRestoreFallback,
        ] as $occurrence) {
            $collector->accept($occurrence);
        }
        $inventory = $collector->finish();

        self::assertCount(3, $inventory->requirements);
        self::assertSame(4, $inventory->occurrenceCount);
        self::assertSame([
            'actor' => 1,
            'credential_decision' => 1,
            'global_natural_key' => 1,
        ], $inventory->countsByMapping);
        $actor = $inventory->find(
            CompanyBackupReferenceMapping::Actor,
            'table:users',
            ['id' => 9],
        );
        self::assertNotNull($actor);
        self::assertSame(2, $actor->occurrenceCount);
        self::assertSame(['restore_actor'], $actor->fallbacks);
        self::assertSame(['approved_by', 'created_by'], array_column(
            $actor->sources,
            'column',
        ));
    }

    public function testInventoryIsCanonicalRegardlessOfTraversalOrder(): void
    {
        $occurrences = [
            $this->occurrence(
                CompanyBackupReferenceMapping::Actor,
                9,
                ['null', 'restore_actor'],
                'approved_by',
            ),
            $this->occurrence(
                CompanyBackupReferenceMapping::GlobalNaturalKey,
                7,
                [],
                'country_id',
            ),
            $this->occurrence(
                CompanyBackupReferenceMapping::Actor,
                9,
                ['restore_actor'],
                'created_by',
            ),
        ];

        $forward = new CompanyBackupExternalReferenceCollector();
        $reverse = new CompanyBackupExternalReferenceCollector();
        foreach ($occurrences as $occurrence) {
            $forward->accept($occurrence);
        }
        foreach (array_reverse($occurrences) as $occurrence) {
            $reverse->accept($occurrence);
        }

        self::assertSame($forward->finish()->toArray(), $reverse->finish()->toArray());
        self::assertSame($forward->finish()->sha256(), $reverse->finish()->sha256());
    }

    public function testUniqueRequirementLimitFailsBeforeGrowingInventory(): void
    {
        $limits = new CompanyBackupArchiveLimits(maxReferenceRequirements: 2);
        $collector = new CompanyBackupExternalReferenceCollector($limits);
        $collector->accept($this->occurrence(
            CompanyBackupReferenceMapping::Actor,
            1,
            [],
            'created_by',
        ));
        $collector->accept($this->occurrence(
            CompanyBackupReferenceMapping::Actor,
            2,
            [],
            'updated_by',
        ));

        try {
            $collector->accept($this->occurrence(
                CompanyBackupReferenceMapping::GlobalNaturalKey,
                3,
                [],
                'country_id',
            ));
            self::fail('Nadměrný mapovací inventář musí preflight zastavit.');
        } catch (CompanyBackupPreflightException $e) {
            self::assertSame(
                'reference_requirement_limit_exceeded',
                $e->errorCode,
            );
        }
    }

    /** @param list<string> $fallbacks */
    private function occurrence(
        CompanyBackupReferenceMapping $mapping,
        int $value,
        array $fallbacks,
        string $column,
    ): CompanyBackupReferenceOccurrence {
        [$target, $constraint, $nullable] = match ($mapping) {
            CompanyBackupReferenceMapping::Actor => [
                'table:users',
                CompanyBackupReferenceConstraint::Optional,
                true,
            ],
            CompanyBackupReferenceMapping::GlobalNaturalKey => [
                'table:countries',
                CompanyBackupReferenceConstraint::Required,
                false,
            ],
            CompanyBackupReferenceMapping::CredentialDecision => [
                'table:epo_signing_credentials',
                CompanyBackupReferenceConstraint::Required,
                true,
            ],
            CompanyBackupReferenceMapping::TenantId => [
                'table:supplier',
                CompanyBackupReferenceConstraint::Required,
                false,
            ],
            default => throw new \LogicException('Test nepoužívá tuto mapovací politiku.'),
        };
        $reference = CompanyBackupReferenceSet::fromArray([[
            'columns' => [$column],
            'target' => $target,
            'target_columns' => ['id'],
            'mapping' => $mapping->value,
            'constraint' => $constraint->value,
            'nullable_columns' => $nullable ? [$column] : [],
            'fallbacks' => $fallbacks,
        ]], 'table:synthetic_records')->references[0];

        return CompanyBackupReferenceOccurrence::column(
            'table:synthetic_records',
            $reference,
            [$value],
        );
    }
}
