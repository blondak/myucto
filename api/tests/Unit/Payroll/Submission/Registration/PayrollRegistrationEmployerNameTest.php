<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEmployerName;
use PHPUnit\Framework\TestCase;

/**
 * REGZEC `comp/@nam` (10120): celý název dle rejstříku a obec sídla
 * (matice REGZEC25-comp.nam-04).
 */
final class PayrollRegistrationEmployerNameTest extends TestCase
{
    public function testSeatMunicipalityIsAppendedToTheRegisteredName(): void
    {
        self::assertSame(
            'Syntetická firma s.r.o., Testov',
            PayrollRegistrationEmployerName::forSubmission('Syntetická firma s.r.o.', 'Testov'),
        );
    }

    public function testNameAlreadyEndingWithTheMunicipalityIsKept(): void
    {
        self::assertSame(
            'Syntetická firma s.r.o., Testov',
            PayrollRegistrationEmployerName::forSubmission('Syntetická firma s.r.o., Testov', 'testov'),
        );
    }

    public function testMissingMunicipalityOrOverlongResultKeepsTheName(): void
    {
        self::assertSame('Firma a.s.', PayrollRegistrationEmployerName::forSubmission(' Firma a.s. ', null));
        self::assertSame('Firma a.s.', PayrollRegistrationEmployerName::forSubmission('Firma a.s.', '  '));
        $long = str_repeat('N', 145);
        self::assertSame($long, PayrollRegistrationEmployerName::forSubmission($long, 'Testov'));
    }
}
