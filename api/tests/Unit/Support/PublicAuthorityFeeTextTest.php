<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Support\PublicAuthorityFeeText;
use PHPUnit\Framework\TestCase;

/**
 * Rozpoznání poplatku orgánu veřejné moci (§ 5 odst. 4 ZDPH) — plnění mimo předmět
 * daně, které se ani u zahraničního úřadu nesamovyměřuje (issue #30).
 */
final class PublicAuthorityFeeTextTest extends TestCase
{
    public function testRecognizesPublicFees(): void
    {
        $texts = [
            'Soudní poplatek za návrh na vydání evropského platebního rozkazu',
            'SOUDNI POPLATEK',
            'Správní poplatek — výpis z rejstříku',
            'Gerichtskosten Europäisches Mahnverfahren',
            'Europäisches Mahngericht — Gebühren',
            'Court fee — European order for payment',
            'Kolková známka',
            'Poplatek katastrálnímu úřadu za vklad',
        ];
        foreach ($texts as $text) {
            $this->assertTrue(
                PublicAuthorityFeeText::indicatesPublicAuthorityFee($text),
                "má být poplatek orgánu veřejné moci: {$text}"
            );
        }
    }

    public function testDoesNotFireOnOrdinaryFees(): void
    {
        // Samotné slovo „poplatek" nestačí — bankovní, licenční a servisní poplatky
        // jsou běžná zdanitelná plnění a u zahraničního dodavatele se samovyměřují.
        $texts = [
            'Bankovní poplatek za vedení účtu',
            'Licenční poplatek za software',
            'Servisní poplatek',
            'Poplatek za dopravu',
            'Předplatné Claude Pro',
            '',
        ];
        foreach ($texts as $text) {
            $this->assertFalse(
                PublicAuthorityFeeText::indicatesPublicAuthorityFee($text),
                "nemá být poplatek orgánu veřejné moci: {$text}"
            );
        }
    }

    public function testAnyOverItems(): void
    {
        $this->assertTrue(PublicAuthorityFeeText::anyIndicatesPublicAuthorityFee(
            ['Kopírování spisu', 'Soudní poplatek 5 000 Kč']
        ));
        $this->assertFalse(PublicAuthorityFeeText::anyIndicatesPublicAuthorityFee(
            ['Kopírování spisu', 'Právní služby']
        ));
        $this->assertFalse(PublicAuthorityFeeText::anyIndicatesPublicAuthorityFee([null, 42]));
    }
}
