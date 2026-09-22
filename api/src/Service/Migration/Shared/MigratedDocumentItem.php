<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Service\Migration\OssMigrationPolicy;

/**
 * Položka převzatého dokladu (`invoice_items` / `purchase_invoice_items`).
 *
 * Vydaná položka se zakládá přes {@see issued()} a režim OSS musí uvést vždy:
 * `oss_applicable` má v databázi DEFAULT 0, takže nezapsaný sloupec by nebyl chybějící
 * údaj, ale tiché zařazení do tuzemska. Tuzemskou položku zdroj označí
 * {@see OssMigrationPolicy::DOMESTIC_COLUMNS}. Přijatá položka OSS nezná ({@see purchase()}).
 */
final class MigratedDocumentItem
{
    private const OSS_KEYS = ['oss_applicable', 'oss_consumer_country', 'oss_rate_type', 'oss_supply_type', 'oss_needs_manual_review'];

    /** @param array{oss_applicable:int,oss_consumer_country:?string,oss_rate_type:?string,oss_supply_type:?string,oss_needs_manual_review:int} $oss */
    private function __construct(
        public readonly string $description,
        public readonly float $quantity,
        public readonly string $unit,
        public readonly float $unitPrice,
        public readonly int $vatRateId,
        public readonly float $vatRateSnapshot,
        public readonly float $totalWithoutVat,
        public readonly float $totalVat,
        public readonly float $totalWithVat,
        public readonly ?string $vatClassificationCode,
        public readonly array $oss,
        public readonly bool $isFixedAsset,
        public readonly ?string $expenseKind,
    ) {}

    /**
     * @param array<string,mixed> $oss sloupce OSS položky ({@see OssMigrationPolicy::DOMESTIC_COLUMNS}
     *                                 nebo `columns` z plánu {@see OssMigrationPolicy::planItem()})
     */
    public static function issued(
        string $description,
        float $quantity,
        string $unit,
        float $unitPrice,
        int $vatRateId,
        float $vatRateSnapshot,
        float $totalWithoutVat,
        float $totalVat,
        float $totalWithVat,
        ?string $vatClassificationCode,
        array $oss,
    ): self {
        $missing = array_diff(self::OSS_KEYS, array_keys($oss));
        if ($missing !== []) {
            throw new \InvalidArgumentException('Položce vydaného dokladu chybí sloupce OSS: ' . implode(', ', $missing));
        }
        return new self(
            $description, $quantity, $unit, $unitPrice, $vatRateId, $vatRateSnapshot,
            $totalWithoutVat, $totalVat, $totalWithVat, $vatClassificationCode,
            [
                'oss_applicable' => $oss['oss_applicable'],
                'oss_consumer_country' => $oss['oss_consumer_country'],
                'oss_rate_type' => $oss['oss_rate_type'],
                'oss_supply_type' => $oss['oss_supply_type'],
                'oss_needs_manual_review' => $oss['oss_needs_manual_review'],
            ],
            false,
            null,
        );
    }

    public static function purchase(
        string $description,
        float $quantity,
        string $unit,
        float $unitPrice,
        int $vatRateId,
        float $vatRateSnapshot,
        float $totalWithoutVat,
        float $totalVat,
        float $totalWithVat,
        ?string $vatClassificationCode,
        bool $isFixedAsset = false,
        ?string $expenseKind = null,
    ): self {
        return new self(
            $description, $quantity, $unit, $unitPrice, $vatRateId, $vatRateSnapshot,
            $totalWithoutVat, $totalVat, $totalWithVat, $vatClassificationCode,
            OssMigrationPolicy::DOMESTIC_COLUMNS,
            $isFixedAsset,
            $expenseKind,
        );
    }
}
