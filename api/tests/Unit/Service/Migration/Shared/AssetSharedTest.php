<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Service\Migration\Shared\MigratedDepreciation;
use MyInvoice\Service\Migration\Shared\SmallAssetCard;
use PHPUnit\Framework\TestCase;

final class AssetSharedTest extends TestCase
{
    public function testMonthsBetween(): void
    {
        self::assertSame(0, MigratedDepreciation::monthsBetween('2024-05', '2024-05'));
        self::assertSame(13, MigratedDepreciation::monthsBetween('2023-12', '2025-01'));
        self::assertSame(-2, MigratedDepreciation::monthsBetween('2024-05-10', '2024-03-31'));
    }

    public function testAccountingOpeningFromMonthPlan(): void
    {
        $plan = ['2023-03' => 100.0, '2023-04' => 100.0, '2023-05' => 100.004, '2023-06' => 100.0];
        self::assertSame([2, 300.0, 3], MigratedDepreciation::accountingOpening('2023-03-15', $plan, '2023-05'));
        self::assertSame([3, 400.0, 3], MigratedDepreciation::accountingOpening('2023-03-15', $plan, '2030-01'), 'zaúčtováno po konci plánu = celý plán');
        self::assertSame([0, 0.0, 3], MigratedDepreciation::accountingOpening('2023-03-15', $plan, null), 'nic nezaúčtováno');
        self::assertSame([0, 0.0, null], MigratedDepreciation::accountingOpening('2023-03-15', [], '2023-05'));
    }

    public function testSmallAssetPayload(): void
    {
        $card = SmallAssetCard::payload('tangible', str_repeat('n', 300), '1234567890123456789012345678901234567890XX', '2024-02-01', '2024-02-03',
            2, 50.0, 100.0, '', null, 'Vyřazeno', null, ['vendor_name' => 'Dodavatel']);
        self::assertSame(255, mb_strlen($card['name']));
        self::assertSame(40, mb_strlen($card['inventory_number']));
        self::assertNull($card['location']);
        self::assertSame(['in_use', null, null], [$card['status'], $card['disposed_at'], $card['disposal_reason']]);
        self::assertSame('Dodavatel', $card['vendor_name']);

        $disposed = SmallAssetCard::payload('intangible', 'Licence', null, '2024-02-01', null, 1, 1.0, 1.0, 'Sklad', '2024-06-30', 'Vyřazeno', 'Pozn.');
        self::assertSame(['disposed', '2024-06-30', 'Vyřazeno', 'Sklad'], [$disposed['status'], $disposed['disposed_at'], $disposed['disposal_reason'], $disposed['location']]);
    }

    public function testInventoryNumberAndDisposal(): void
    {
        self::assertNull(SmallAssetCard::inventoryNumber('0', true));
        self::assertSame('0', SmallAssetCard::inventoryNumber('0', false));
        self::assertNull(SmallAssetCard::inventoryNumber('', false));
        self::assertSame('2024-03-01', SmallAssetCard::disposedWithin('2024-01-01', '2024-03-01', '2024-12-31'), 'vyřazení před pořízením = k datu pořízení');
        self::assertNull(SmallAssetCard::disposedWithin('2025-01-02', '2024-03-01', '2024-12-31'), 'vyřazení po konci období');
        self::assertNull(SmallAssetCard::disposedWithin(null, '2024-03-01', '2024-12-31'));
    }
}
