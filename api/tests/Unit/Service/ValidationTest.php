<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service;

use MyInvoice\Service\Validation;
use PHPUnit\Framework\TestCase;

/**
 * Validation::client (hard errors) + Validation::clientWarnings (non-blocking —
 * audit 2026-07, nález "IČO bez kontroly mod 11, DIČ se nevaliduje vůbec").
 *
 * IČO fixtures jsou čistě syntetická čísla odvozená přímo z mod-11 algoritmu
 * (ne reálná IČO existujících subjektů — repo je veřejné):
 *   - "12345679" — běžný případ (prefix 1234567, kontrolní číslice 9)
 *   - "00000001" — hraniční případ zbytek=0 → kontrolní číslice 1
 *   - "00000060" — hraniční případ zbytek=1 → kontrolní číslice 0
 */
final class ValidationTest extends TestCase
{
    private function validBase(): array
    {
        return [
            'company_name' => 'Testovací s.r.o.',
            'street' => 'Testovací 1',
            'city' => 'Praha',
            'zip' => '11000',
            'main_email' => 'test@example.com',
        ];
    }

    public function testValidClientPasses(): void
    {
        self::assertSame([], Validation::client($this->validBase()));
    }

    public function testIcWrongDigitCountRejectedHard(): void
    {
        $data = $this->validBase();
        $data['ic'] = '123';
        $err = Validation::client($data);
        self::assertArrayHasKey('ic', $err);
    }

    public function testIcValidChecksumNoWarning(): void
    {
        $warn = Validation::clientWarnings(['ic' => '12345679']);
        self::assertSame([], $warn);
    }

    public function testIcInvalidChecksumWarns(): void
    {
        // Poslední číslice pozměněna z platné 9 na 8 — porušuje mod 11.
        $warn = Validation::clientWarnings(['ic' => '12345678']);
        self::assertContains('ic_checksum_invalid', $warn);
    }

    public function testIcChecksumRemainderZeroEdgeCase(): void
    {
        self::assertSame([], Validation::clientWarnings(['ic' => '00000001']));
    }

    public function testIcChecksumRemainderOneEdgeCase(): void
    {
        self::assertSame([], Validation::clientWarnings(['ic' => '00000060']));
    }

    public function testIcWrongFormatDoesNotDoubleWarn(): void
    {
        // client() už IČO se špatným počtem číslic tvrdě odmítne — clientWarnings()
        // (jen doplňkové mod-11 varování) tu stejnou chybu nesmí hlásit znovu.
        $warn = Validation::clientWarnings(['ic' => '123']);
        self::assertNotContains('ic_checksum_invalid', $warn);
    }

    public function testEmptyIcNoWarning(): void
    {
        // Zahraniční subjekt bez IČO musí projít beze zbytku (nikdy neblokovat).
        self::assertSame([], Validation::clientWarnings(['ic' => '']));
        self::assertSame([], Validation::clientWarnings([]));
    }

    public function testDicDomesticValidFormatNoWarning(): void
    {
        self::assertSame([], Validation::clientWarnings(['dic' => 'CZ12345679']));
    }

    public function testDicDomesticInvalidFormatWarns(): void
    {
        $warn = Validation::clientWarnings(['dic' => 'CZ123']);
        self::assertContains('dic_format_invalid', $warn);
    }

    public function testDicForeignVatIdLooseFormatNoWarning(): void
    {
        // AT U12345678 — alfanumerické EU VAT ID (přesný formát ověří VIES lookup).
        self::assertSame([], Validation::clientWarnings(['dic' => 'ATU12345678']));
    }

    public function testDicGarbageFormatWarns(): void
    {
        $warn = Validation::clientWarnings(['dic' => '!!!']);
        self::assertContains('dic_format_invalid', $warn);
    }

    public function testDicIcMismatchWarns(): void
    {
        $warn = Validation::clientWarnings(['ic' => '12345679', 'dic' => 'CZ00000001']);
        self::assertContains('dic_ic_mismatch', $warn);
    }

    public function testDicIcMatchNoMismatchWarning(): void
    {
        $warn = Validation::clientWarnings(['ic' => '12345679', 'dic' => 'CZ12345679']);
        self::assertNotContains('dic_ic_mismatch', $warn);
    }

    public function testDicNineDigitsSkipsIcMismatchCheck(): void
    {
        // DIČ fyzické osoby odvozené z rodného čísla (9-10 číslic) se s 8místným
        // IČO záměrně neporovnává.
        $warn = Validation::clientWarnings(['ic' => '12345679', 'dic' => 'CZ123456789']);
        self::assertNotContains('dic_ic_mismatch', $warn);
    }
}
