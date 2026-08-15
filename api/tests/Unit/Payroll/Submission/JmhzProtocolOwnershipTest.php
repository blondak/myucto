<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolOwnership;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use PHPUnit\Framework\TestCase;

/**
 * Do jedné datové schránky chodí protokoly ke všem podáním a soubor si
 * uživatel může splést. Uložit protokol cizí firmy pod tenhle tenant je
 * jediný výsledek, který musí být nemožný.
 */
final class JmhzProtocolOwnershipTest extends TestCase
{
    public function testProtocolOfThisEmployerPasses(): void
    {
        $this->expectNotToPerformAssertions();

        JmhzProtocolOwnership::assert('9990000001', ['9990000001']);
    }

    public function testProtocolMatchingAnyOfficeSymbolPasses(): void
    {
        $this->expectNotToPerformAssertions();

        JmhzProtocolOwnership::assert('9990000002', ['9990000001', '9990000002']);
    }

    /**
     * Registrační číslo se zapisuje jednou s vedoucí nulou a jindy bez ní.
     * Vedoucí nula identitu symbolu nemění, takže odmítnout kvůli ní by
     * znamenalo odmítnout vlastní doklad.
     */
    public function testLeadingZeroesAndSpacingDoNotDecideOwnership(): void
    {
        $this->expectNotToPerformAssertions();

        JmhzProtocolOwnership::assert('0999000001', ['999 000 001']);
    }

    public function testForeignProtocolIsRefused(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessageMatches('/nepatří/');

        JmhzProtocolOwnership::assert('9990000009', ['9990000001']);
    }

    /**
     * Nemít s čím porovnat není důvod pustit doklad dál — „zatím uložíme"
     * znamená, že ověření nikdy neproběhne.
     */
    public function testWithoutAnyKnownSymbolNothingIsAccepted(): void
    {
        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessageMatches('/nelze ověřit/');

        JmhzProtocolOwnership::assert('9990000001', ['', '   ']);
    }
}
