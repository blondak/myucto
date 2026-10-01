<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\PayrollSubmissionFilename;
use PHPUnit\Framework\TestCase;

/**
 * Název souboru podání říká, CO, ZA KDY, KOMU a OD KOHO — ne interní čísla
 * (`mzdove-podani-5-12.pdf`), která podatelně ani účetní nic neřeknou.
 */
final class PayrollSubmissionFilenameTest extends TestCase
{
    public function testPaymentOverviewNamesInsurerAndEmployer(): void
    {
        self::assertSame(
            'PPPZ_2026-09_VZP-111_12345678.pdf',
            PayrollSubmissionFilename::build('PPZ_2026', '2026-09-01', 'payroll_run:41:111', '12345678', 'regular', 'pdf'),
        );
    }

    public function testJmhzGoesToCssz(): void
    {
        self::assertSame(
            'JMHZ_2026-09_CSSZ_12345678.xml',
            PayrollSubmissionFilename::build('JMHZ25', '2026-09-01', 'office:3', '12345678', 'regular', 'xml'),
        );
    }

    /** Diakritika ze zkratky pojišťovny (ČPZP, ZPŠ) do názvu nesmí. */
    public function testDiacriticsAreStrippedFromInsurerAbbreviation(): void
    {
        self::assertSame(
            'HOZ_2026-09_CPZP-205_12345678.xml',
            PayrollSubmissionFilename::build('HOZ_2026', '2026-09-01', 'health_bulk_notification:2026-09:205', '123 456 78', 'regular', 'xml'),
        );
        self::assertSame(
            'PPPZ_2026-09_ZPS-209.xml',
            PayrollSubmissionFilename::build('PPZ_2026', '2026-09-01', 'payroll_run:41:209', null, 'regular', 'xml'),
        );
    }

    public function testCorrectionAndSequenceAreMarked(): void
    {
        self::assertSame(
            'JMHZ_2026-08_CSSZ_12345678_opravne_2.xml',
            PayrollSubmissionFilename::build('JMHZ25', '2026-08-01', 'office:3', '12345678', 'correction', 'xml', 2),
        );
    }

    public function testNameIsAsciiWithoutSpacesAndBounded(): void
    {
        $name = PayrollSubmissionFilename::build(
            'NĚJAKÁ dlouhá agenda bez kódu s mezerami a diakritikou',
            '2026-09-01',
            'x',
            '12345678',
            'cancellation',
            'xml',
        );

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/D', $name);
        self::assertLessThanOrEqual(PayrollSubmissionFilename::MAX_LENGTH, strlen($name));
        self::assertStringEndsWith('.xml', $name);
    }
}
