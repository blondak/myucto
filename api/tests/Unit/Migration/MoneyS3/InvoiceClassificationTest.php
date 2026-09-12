<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\InvoiceImporter;
use MyInvoice\Service\Migration\MoneyS3\Ms3VatCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Daňová povaha faktury z Money: druh dokladu v MyÚčtu, nárok na odpočet, kód zařazení
 * do přiznání a co jde ke ruční kontrole. Členění DPH (`KodDPH`) nese řádky přiznání,
 * na které Money doklad zařadilo, a příponu (krácený odpočet, majetek, splátky…).
 */
final class InvoiceClassificationTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,bool,float,bool,string,?string,string}> */
    public static function cases(): iterable
    {
        yield 'tuzemská přijatá' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41'], false, 210.0, false, 'full', null, 'invoice'];
        yield 'krácený odpočet § 76' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41 K'], false, 210.0, false, 'reduced', null, 'invoice'];
        yield 'majetek, krácený odpočet' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41MK'], false, 210.0, false, 'reduced', null, 'invoice'];
        yield 'majetek aktivovaný později' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41P'], false, 210.0, false, 'full', null, 'invoice'];
        yield 'poměrný nárok § 75' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41MR'], false, 210.0, true, 'full', null, 'invoice'];
        yield 'tuzemská vydaná' => [['Druh' => 'N', 'KodDPH' => '19Ř01,02'], true, 210.0, false, 'full', null, 'invoice'];
        yield 'vydaná cestovní služba' => [['Druh' => 'N', 'KodDPH' => '19Ř01,02_C'], true, 210.0, true, 'full', null, 'invoice'];
        yield 'bez členění a bez daně' => [['Druh' => 'N', 'KodDPH' => ''], false, 0.0, false, 'full', null, 'invoice'];
        yield 'přijatá mimo přiznání s daní' => [['Druh' => 'N', 'KodDPH' => '19Ř00P'], false, 504.0, false, 'none', null, 'invoice'];
        yield 'vydaná mimo přiznání s daní' => [['Druh' => 'N', 'KodDPH' => '19Ř00U'], true, 210.0, true, 'full', null, 'invoice'];
        yield 'přenesená daňová povinnost na vstupu' => [['Druh' => 'N', 'KodDPH' => '19Ř10,43'], false, 0.0, true, 'full', null, 'invoice'];
        yield 'dodání zboží do EU' => [['Druh' => 'N', 'KodDPH' => '19Ř20'], true, 0.0, false, 'full', '20', 'invoice'];
        yield 'služba do EU' => [['Druh' => 'N', 'KodDPH' => '19Ř21'], true, 0.0, false, 'full', '22', 'invoice'];
        yield 'přenesení povinnosti, odpad' => [['Druh' => 'N', 'KodDPH' => '19Ř25'], true, 0.0, false, 'full', '25s5', 'invoice'];
        yield 'přenesení povinnosti, stavba' => [['Druh' => 'N', 'KodDPH' => '19Ř25_S'], true, 0.0, false, 'full', '25s', 'invoice'];
        yield 'osvobozené bez nároku' => [['Druh' => 'N', 'KodDPH' => '19Ř50'], true, 0.0, false, 'full', '3', 'invoice'];
        yield 'osvobozené mimo koeficient' => [['Druh' => 'N', 'KodDPH' => '19Ř51BN'], true, 0.0, false, 'full', '3m', 'invoice'];
        yield 'dodání do EU s daní' => [['Druh' => 'N', 'KodDPH' => '19Ř20'], true, 210.0, true, 'full', null, 'invoice'];
        yield 'neznámý tvar členění' => [['Druh' => 'N', 'KodDPH' => 'PD'], false, 210.0, true, 'full', null, 'invoice'];
        yield 'daň bez členění' => [['Druh' => 'N', 'KodDPH' => ''], false, 210.0, true, 'full', null, 'invoice'];
        yield 'přijatá zálohová' => [['Druh' => 'L', 'KodDPH' => ''], false, 0.0, false, 'full', null, 'advance'];
        yield 'starší zálohová' => [['Druh' => 'Z', 'KodDPH' => '19Ř40,41'], false, 210.0, false, 'full', null, 'advance'];
        yield 'vydaná zálohová' => [['Druh' => 'F', 'KodDPH' => ''], true, 210.0, false, 'full', null, 'proforma'];
        yield 'daňový doklad k platbě přijatý' => [['Druh' => 'D', 'KodDPH' => '19Ř40,41 K'], false, 210.0, false, 'reduced', null, 'tax_document'];
        yield 'daňový doklad k platbě vydaný' => [['Druh' => 'D', 'KodDPH' => '19Ř01,02'], true, 210.0, false, 'full', null, 'tax_document'];
        yield 'dobropis' => [['Druh' => 'N', 'Dobropis' => 1, 'KodDPH' => '19Ř40,41'], false, -105.0, false, 'full', null, 'credit_note'];
        yield 'dobropis zálohy' => [['Druh' => 'L', 'Dobropis' => 1, 'KodDPH' => ''], false, 0.0, true, 'full', null, 'advance'];
        yield 'neznámý druh' => [['Druh' => 'X', 'KodDPH' => '19Ř40,41'], false, 210.0, true, 'full', null, 'invoice'];
        yield 'storno' => [['Druh' => 'N', 'Storno' => 1, 'KodDPH' => '19Ř40,41'], false, 210.0, true, 'full', null, 'invoice'];
        yield 'neúčtovat' => [['Druh' => 'N', 'Neuctovat' => 1, 'KodDPH' => '19Ř40,41'], false, 210.0, true, 'full', null, 'invoice'];
        yield 'cizí měna' => [['Druh' => 'N', 'Mena' => 'EUR', 'Kurs' => 25.0, 'KodDPH' => '19Ř40,41'], false, 525.0, false, 'full', null, 'invoice'];
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('cases')]
    public function testClassification(array $row, bool $issued, float $vat, bool $review, string $deduction, ?string $code, string $kind): void
    {
        $class = InvoiceImporter::classify($row, $issued, $vat);

        $info = json_encode($class, JSON_UNESCAPED_UNICODE) ?: '';
        self::assertSame($review, $class['reasons'] !== [], $info);
        self::assertSame($deduction, $class['vat_deduction'], $info);
        self::assertSame($code, $class['code'], $info);
        self::assertSame($kind, $class['kind'], $info);
    }

    public function testCashCodesUseTheSameResolver(): void
    {
        self::assertSame(['in_return' => true, 'code' => null, 'deduction' => 'reduced'], Ms3VatCode::resolve('19Ř40,41 K', false));
        self::assertSame(['in_return' => false, 'code' => null, 'deduction' => 'none'], Ms3VatCode::resolve('19Ř00P', false));
        self::assertNull(Ms3VatCode::resolve('19Ř43,44', false));
        self::assertNull(Ms3VatCode::resolve('19Ř40,41', true), 'Řádek odpočtu na výstupní straně je chyba členění.');
    }

    public function testPaymentMethodFromMoneyLabel(): void
    {
        self::assertSame('bank_transfer', InvoiceImporter::paymentMethod('převodem'));
        self::assertSame('card', InvoiceImporter::paymentMethod('platební kartou'));
        self::assertSame('cash', InvoiceImporter::paymentMethod('v hotovosti'));
        self::assertSame('cash_on_delivery', InvoiceImporter::paymentMethod('dobírkou'));
        self::assertSame('direct_debit', InvoiceImporter::paymentMethod('inkasem'));
        self::assertSame('offset', InvoiceImporter::paymentMethod('zápočtem'));
        self::assertSame('bank_transfer', InvoiceImporter::paymentMethod(''));
        self::assertSame('other', InvoiceImporter::paymentMethod('šekem'));
    }
}
