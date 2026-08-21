<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\TenantTransfer;

use MyInvoice\Service\TenantTransfer\Fingerprint\TenantSchemaTableInventory;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataDefinition;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataObjectKind;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataPolicy;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataRegistry;
use MyInvoice\Service\TenantTransfer\Registry\TenantDataSoftReferenceCoverageValidator;
use PHPUnit\Framework\TestCase;

final class TenantDataSoftReferenceCoverageValidatorTest extends TestCase
{
    public function testClosedPolymorphicTargetMapPasses(): void
    {
        $source = self::source(['client' => 'clients']);
        $client = self::target('clients');

        self::assertSame(
            [],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                $source,
                self::inventory(),
                ['clients' => self::targetInventory('clients')],
                ['clients' => $client],
            ),
        );
    }

    public function testUnregisteredTargetAndMissingColumnsFailClosed(): void
    {
        $source = self::source(['client' => 'clients']);
        $details = $source->details;
        $references = $details['soft_references'];
        self::assertIsArray($references);
        self::assertIsArray($references['entity'] ?? null);
        $references['entity']['id_column'] = 'ghost_id';
        $details['soft_references'] = $references;
        $source = new TenantDataDefinition(
            $source->key,
            $source->kind,
            $source->policy,
            $source->profiles,
            $details,
        );

        self::assertSame(
            [
                'soft_reference_column_missing:document_links.ghost_id',
                'soft_reference_target_unregistered:'
                    . 'document_links.entity.client->clients',
            ],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                $source,
                self::inventory(),
                [],
                [],
            ),
        );
    }

    public function testEnumAndTargetMapMustStayInExactParity(): void
    {
        $source = self::source(['client' => 'clients']);
        $client = self::target('clients');

        self::assertSame(
            ['soft_reference_target_map_mismatch:document_links.entity'],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                $source,
                self::inventory(['client', 'invoice']),
                ['clients' => self::targetInventory('clients')],
                ['clients' => $client],
            ),
        );
    }

    public function testUnknownDiscriminatorValuesMustBeBlocked(): void
    {
        $source = self::source(['client' => 'clients']);
        $details = $source->details;
        $references = $details['soft_references'];
        self::assertIsArray($references);
        self::assertIsArray($references['entity'] ?? null);
        unset($references['entity']['unknown_value']);
        $details['soft_references'] = $references;

        self::assertSame(
            [
                'soft_reference_unknown_value_not_blocked:'
                    . 'document_links.entity',
            ],
            (new TenantDataSoftReferenceCoverageValidator())->issues(
                new TenantDataDefinition(
                    $source->key,
                    $source->kind,
                    $source->policy,
                    $source->profiles,
                    $details,
                ),
                self::inventory(),
                ['clients' => self::targetInventory('clients')],
                ['clients' => self::target('clients')],
            ),
        );
    }

    /** @param array<string,string> $targets */
    private static function source(array $targets): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:document_links',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['document_id', 'entity_type', 'entity_id'],
                'soft_references' => [
                    'entity' => [
                        'strategy' => 'polymorphic_tenant_entity',
                        'type_column' => 'entity_type',
                        'id_column' => 'entity_id',
                        'unknown_value' => 'block',
                        'targets' => $targets,
                    ],
                ],
            ],
        );
    }

    private static function target(string $table): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
            ],
        );
    }

    /** @param list<string> $entityTypes */
    private static function inventory(
        array $entityTypes = ['client'],
    ): TenantSchemaTableInventory
    {
        return new TenantSchemaTableInventory(
            'document_links',
            'BASE TABLE',
            ['document_id', 'entity_type', 'entity_id'],
            ['document_id', 'entity_type', 'entity_id'],
            [],
            [['document_id', 'entity_type', 'entity_id']],
            [],
            ['entity_type' => $entityTypes],
        );
    }

    private static function targetInventory(
        string $table,
    ): TenantSchemaTableInventory {
        return new TenantSchemaTableInventory(
            $table,
            'BASE TABLE',
            ['id', 'supplier_id'],
            ['id'],
            [],
            [['id']],
        );
    }
}
