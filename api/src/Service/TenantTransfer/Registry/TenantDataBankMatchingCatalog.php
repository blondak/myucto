<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Trvalá historie bankovního párování a regenerovatelné návrhy shod. */
final class TenantDataBankMatchingCatalog
{
    /** @return list<TenantDataDefinition> */
    public static function definitions(): array
    {
        return [
            self::owned(
                'bank_counterparty_map',
                'preserve_counterparty_learning_history',
            ),
            self::observations(),
            self::runtime(
                'bank_match_suggestions',
                'runtime_bank_match_candidate_queue',
            ),
            self::owned(
                'bank_transfer_matches',
                'preserve_own_transfer_pairing',
            ),
        ];
    }

    private static function owned(
        string $table,
        string $transferInvariant,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwned,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'bank',
                'ownership' => [
                    'strategy' => 'supplier_id',
                    'column' => 'supplier_id',
                ],
                'secrets' => [],
                'transfer_invariant' => $transferInvariant,
            ],
        );
    }

    private static function observations(): TenantDataDefinition
    {
        return new TenantDataDefinition(
            'table:bank_counterparty_observations',
            TenantDataObjectKind::Table,
            TenantDataPolicy::TenantOwnedIndirect,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'bank',
                'ownership' => [
                    'strategy' => 'foreign_key_path',
                    'path' => [
                        [
                            'from_column' => 'map_id',
                            'to_table' => 'bank_counterparty_map',
                            'to_column' => 'id',
                        ],
                        [
                            'from_column' => 'supplier_id',
                            'to_table' => 'supplier',
                            'to_column' => 'id',
                        ],
                    ],
                ],
                'secrets' => [],
                'transfer_invariant' =>
                    'preserve_counterparty_learning_observations',
            ],
        );
    }

    private static function runtime(
        string $table,
        string $reason,
    ): TenantDataDefinition {
        return new TenantDataDefinition(
            'table:' . $table,
            TenantDataObjectKind::Table,
            TenantDataPolicy::RuntimeDerived,
            [TenantDataRegistry::TRANSFER_PROFILE],
            [
                'primary_key' => ['id'],
                'feature_group' => 'bank',
                'reason' => $reason,
                'secrets' => [],
            ],
        );
    }
}
