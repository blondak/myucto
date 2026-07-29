<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use MyInvoice\Service\Ai\AiPayloadSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * §DM — sanitizace volného textu z dokladu (popis položky, zdůvodnění od modelu).
 *
 * PIN na poučení z `a013406f`: `sanitizeMessage()` je slovníkový whitelist postavený na
 * STROJOVÝ text banky. Použitý na volný text ho rozseká na nesmysl — z „platba kartou za
 * pojištění mobilního telefonu" zbylo „kartou pojisteni". U popisu položky by to bylo ještě
 * horší: whitelist zná účetní slovník, ne značky a typy, takže by zahodil právě ta slova,
 * podle kterých se druh výdaje pozná.
 *
 * Testy jsou statické — sanitizeItemText() nesahá do DB.
 */
final class AiPayloadSanitizerItemTextTest extends TestCase
{
    /** Značka, model i barva musí přežít — bez nich nejde určit druh výdaje. */
    public function testKeepsVendorFreeTextVerbatim(): void
    {
        self::assertSame(
            'Toner HP 26X černý',
            AiPayloadSanitizer::sanitizeItemText('Toner HP 26X černý'),
        );
        self::assertSame(
            'Kávovar DeLonghi Magnifica S ECAM 22.110.B',
            AiPayloadSanitizer::sanitizeItemText('Kávovar DeLonghi Magnifica S ECAM 22.110.B'),
        );
    }

    /**
     * Regresní pin přímo na větu z `a013406f`. Kdyby někdo sanitizeItemText() přepsal na
     * whitelist, zbylo by „kartou pojisteni" — a tenhle test spadne.
     */
    public function testDoesNotApplyWordWhitelist(): void
    {
        $text = 'platba kartou za pojištění mobilního telefonu';
        self::assertSame($text, AiPayloadSanitizer::sanitizeItemText($text));
    }

    /** Diakritika ani interpunkce se nenormalizují — text jde k člověku, ne do tokenizéru. */
    public function testDoesNotNormalizeDiacriticsOrPunctuation(): void
    {
        self::assertSame(
            'Ochranné sklo 2 ks, tvrzené (9H)',
            AiPayloadSanitizer::sanitizeItemText('Ochranné sklo 2 ks, tvrzené (9H)'),
        );
    }

    public function testStripsEmailPhoneAndIban(): void
    {
        $out = AiPayloadSanitizer::sanitizeItemText('Notebook pro jan.novak@example.com, tel. +420 777 123 456');
        self::assertStringNotContainsString('jan.novak@example.com', $out);
        self::assertStringNotContainsString('777 123 456', $out);
        self::assertStringContainsString('Notebook', $out);

        $iban = AiPayloadSanitizer::sanitizeItemText('Platba na CZ6508000000192000145399 za monitor');
        self::assertStringNotContainsString('CZ6508000000192000145399', $iban);
        self::assertStringContainsString('monitor', $iban);
    }

    public function testCollapsesWhitespaceAndTrims(): void
    {
        self::assertSame(
            'Tablet Galaxy Tab S9',
            AiPayloadSanitizer::sanitizeItemText("  Tablet\n\tGalaxy   Tab S9  "),
        );
    }

    public function testAppliesLengthCap(): void
    {
        $long = str_repeat('a', 500);
        self::assertSame(200, mb_strlen(AiPayloadSanitizer::sanitizeItemText($long)));
        self::assertSame(80, mb_strlen(AiPayloadSanitizer::sanitizeItemText($long, 80)));
        // Strop se počítá ve ZNACÍCH, ne bajtech — jinak by ořez rozsekl české písmeno.
        self::assertSame(10, mb_strlen(AiPayloadSanitizer::sanitizeItemText(str_repeat('ěščř', 10), 10)));
    }

    public function testEmptyInputStaysEmpty(): void
    {
        self::assertSame('', AiPayloadSanitizer::sanitizeItemText(''));
        self::assertSame('', AiPayloadSanitizer::sanitizeItemText('   '));
    }
}
