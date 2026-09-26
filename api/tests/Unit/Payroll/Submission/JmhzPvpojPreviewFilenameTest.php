<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use PHPUnit\Framework\TestCase;

/**
 * Název staženého náhledu PVPOJ nese číslo revize v běhu, které účetní vidí
 * v aplikaci — ne interní ID řádku revize.
 */
final class JmhzPvpojPreviewFilenameTest extends TestCase
{
    public function testFilenameUsesRevisionNumberNotRowId(): void
    {
        $preview = new JmhzPvpojPreview(
            supplierId: 10,
            runId: 55,
            revisionId: 77,
            revisionNo: 2,
            period: '2026-06',
            office: ['office_id' => 3, 'code' => 'HQ', 'name' => 'Účtárna', 'variable_symbol' => '1234567890'],
            allocation: [],
            source: [],
            pvpoj: [],
            reconciliation: [],
        );

        self::assertSame('jmhz-pvpoj-preview-2026-06-revize-2-uctarna-3.json', $preview->filename());
    }
}
