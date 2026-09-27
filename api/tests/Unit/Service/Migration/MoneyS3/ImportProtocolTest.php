<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use PHPUnit\Framework\TestCase;

/**
 * Chyba, upozornění a rozdíl k přijetí v protokolu převodu (Money S3, POHODA, PREMIER).
 */
final class ImportProtocolTest extends TestCase
{
    public function testDifferenceWithoutAcceptanceFailsAndIsAcceptableOnly(): void
    {
        $p = new ImportProtocol('dry_run');
        $p->warn('purchase_invoices', 'number_taken', 'Číslo obsazené.');
        $p->difference('purchase_invoices', 'unknown_vat_rate', 'Sazba chybí.', ['document_no' => 'FP1']);

        self::assertSame('failed', $p->status());
        self::assertTrue($p->hasErrors());
        self::assertTrue($p->hasDifferences());
        self::assertTrue($p->acceptableOnly());
        $out = $p->toArray();
        self::assertTrue($out['acceptable_only']);
        self::assertArrayNotHasKey('accepted_differences', $out);
        $message = $out['steps'][0]['messages'][1];
        self::assertSame(['error', 'unknown_vat_rate', true], [$message['level'], $message['code'], $message['acceptable']]);
    }

    public function testHardErrorIsNeverAcceptableOnly(): void
    {
        $p = new ImportProtocol('dry_run');
        $p->difference('journal', 'entry_outside_period', 'Bez data.');
        $p->error('reconciliation', 'reconciliation_failed', 'K2 nesedí.');

        self::assertSame('failed', $p->status());
        self::assertFalse($p->acceptableOnly());
        self::assertFalse($p->toArray()['acceptable_only']);
        self::assertArrayNotHasKey('acceptable', $p->toArray()['steps'][1]['messages'][0]);
    }

    public function testInterruptedRunIsNotAcceptableOnly(): void
    {
        $p = new ImportProtocol('dry_run');
        $p->difference('journal', 'entry_outside_period', 'Bez data.');
        $p->fail('cancelled');

        self::assertFalse($p->acceptableOnly());
    }

    public function testAcceptedDifferencesCompleteWithWarnings(): void
    {
        $p = new ImportProtocol('import', true);
        $p->difference('issued_invoices', 'unknown_vat_rate', 'Sazba chybí.', ['document_no' => 'FV7', 'rate' => 17.0]);
        $p->difference('reconciliation', 'reconciliation_failed', 'K4 nesedí.', ['year' => 2025, 'criteria' => ['K4']]);

        self::assertSame('completed_with_warnings', $p->status());
        self::assertFalse($p->hasErrors());
        self::assertTrue($p->hasDifferences());
        self::assertFalse($p->acceptableOnly());
        $out = $p->toArray();
        self::assertFalse($out['acceptable_only']);
        self::assertSame([
            ['step' => 'issued_invoices', 'code' => 'unknown_vat_rate', 'text' => 'Sazba chybí.', 'context' => ['document_no' => 'FV7', 'rate' => 17.0]],
            ['step' => 'reconciliation', 'code' => 'reconciliation_failed', 'text' => 'K4 nesedí.', 'context' => ['year' => 2025, 'criteria' => ['K4']]],
        ], $out['accepted_differences']);
        self::assertSame('warning', $out['steps'][0]['messages'][0]['level']);
        self::assertTrue($out['steps'][0]['messages'][0]['acceptable']);
    }

    public function testAcceptanceDoesNotSoftenHardErrors(): void
    {
        $p = new ImportProtocol('import', true);
        $p->difference('journal', 'entry_outside_period', 'Bez data.');
        $p->error('journal', 'journal_unbalanced', 'MD ≠ D.');

        self::assertSame('failed', $p->status());
        self::assertTrue($p->blocks('journal'));
    }

    public function testDryRunNeverAcceptsDifferences(): void
    {
        $p = new ImportProtocol('dry_run', true);
        $p->difference('journal', 'entry_outside_period', 'Bez data.');

        self::assertFalse($p->acceptDifferences);
        self::assertSame('failed', $p->status());
        self::assertTrue($p->acceptableOnly());
    }

    public function testDifferenceBlocksOnlyLiveRunWithoutAcceptance(): void
    {
        $dry = new ImportProtocol('dry_run');
        $dry->difference('journal', 'entry_outside_period', 'Bez data.');
        self::assertFalse($dry->blocks('journal'), 'Zkouška nanečisto pokračuje, aby ukázala všechny rozdíly.');

        $live = new ImportProtocol('import');
        $live->difference('journal', 'entry_outside_period', 'Bez data.');
        self::assertTrue($live->blocks('journal'));

        $accepted = new ImportProtocol('import', true);
        $accepted->difference('journal', 'entry_outside_period', 'Bez data.');
        self::assertFalse($accepted->blocks('journal'));
        self::assertFalse($accepted->blocks('chart'));
    }

    public function testCleanProtocolIsNotAcceptableOnly(): void
    {
        $p = new ImportProtocol('dry_run');
        $p->warn('closing', 'closing_mismatch', 'Rok zůstává otevřený.');

        self::assertSame('completed_with_warnings', $p->status());
        self::assertFalse($p->toArray()['acceptable_only']);
        self::assertFalse($p->hasDifferences());
    }
}
