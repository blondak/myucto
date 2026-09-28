<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Abra;

use MyInvoice\Service\Migration\Abra\AbraImportJobService;
use PHPUnit\Framework\TestCase;

final class AbraImportJobServiceTest extends TestCase
{
    public function testBlockedCatalogRunIsFailedEvenWithOnlyReviewWarnings(): void
    {
        $status = new \ReflectionMethod(AbraImportJobService::class, 'catalogRunStatus');
        self::assertSame('failed', $status->invoke(null, false, ['stock_source_changed_requires_review']));
        self::assertSame('completed_with_warnings', $status->invoke(null, true, ['catalog_price_rounded']));
        self::assertSame('completed', $status->invoke(null, true, []));
    }

    public function testProtocolExposesOnlySanitizedReconciliationSummary(): void
    {
        $normalize = new \ReflectionMethod(AbraImportJobService::class, 'normalizeReport');
        $report = $normalize->invoke(null, [
            'blocked' => true,
            'warnings' => ['trial_balance_reconciliation_failed'],
            'reconciliation' => [
                'ok' => false,
                'years' => [[
                    'year' => 2024,
                    'ok' => false,
                    'differences' => [['account' => 'synthetic-secret', 'difference' => 12.34]],
                ]],
                'warnings' => ['difference_found'],
            ],
        ]);
        $protocol = (new \ReflectionMethod(AbraImportJobService::class, 'protocol'))->invoke(
            null,
            'initial',
            'failed',
            $report,
            ['warnings' => ['source_warning']],
            2,
        );

        self::assertSame(['source_warning', 'trial_balance_reconciliation_failed'], $protocol['warnings']);
        self::assertSame([
            'ok' => false,
            'years' => [['year' => 2024, 'ok' => false]],
            'warnings' => ['difference_found'],
        ], $protocol['reconciliation']);
        self::assertStringNotContainsString('synthetic-secret', json_encode($protocol, JSON_THROW_ON_ERROR));
        self::assertTrue($protocol['blocked']);
    }
}
