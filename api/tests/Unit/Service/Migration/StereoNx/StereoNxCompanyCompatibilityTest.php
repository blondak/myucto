<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxCompanyCompatibility;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use PHPUnit\Framework\TestCase;

final class StereoNxCompanyCompatibilityTest extends TestCase
{
    public function testAccountingBackupCannotRunAsTaxEvidence(): void
    {
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('Záloha obsahuje režim „podvojné účetnictví“, ale cílová firma má nastaven režim „daňová evidence“');
        StereoNxCompanyCompatibility::assertAccountingMode(['accounting_mode' => 'double_entry'], 'tax_evidence');
    }

    public function testTaxEvidenceBackupCannotRunAsAccounting(): void
    {
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('Záloha obsahuje režim „daňová evidence“, ale cílová firma má nastaven režim „podvojné účetnictví“');
        StereoNxCompanyCompatibility::assertAccountingMode(['accounting_mode' => 'tax_evidence'], 'double_entry');
    }

    public function testMatchingAndUnknownModesDoNotInventConflict(): void
    {
        foreach (['tax_evidence', 'double_entry'] as $mode) {
            StereoNxCompanyCompatibility::assertAccountingMode(['accounting_mode' => $mode], $mode);
            StereoNxCompanyCompatibility::assertAccountingMode(['accounting_mode' => null], $mode);
            StereoNxCompanyCompatibility::assertAccountingMode([], $mode);
        }
        $this->addToAssertionCount(1);
    }
}
