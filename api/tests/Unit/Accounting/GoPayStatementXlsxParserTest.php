<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\GoPay\GoPayException;
use MyInvoice\Service\Accounting\GoPay\GoPayStatementXlsxParser;
use MyInvoice\Tests\Support\GoPayStatementFixture;
use PHPUnit\Framework\TestCase;

final class GoPayStatementXlsxParserTest extends TestCase
{
    public function testParsesStatementPaymentsAndRefunds(): void
    {
        $content = GoPayStatementFixture::xlsx('1. 1. 2097', '31. 1. 2097', 0.0, [
            ['15. 1. 2097', 'GOPAY-platba', '400000******0001', 1000.0, 'TEST000001'],
            ['20. 1. 2097', 'GOPAY-storno-platby', 'GOPAY', -100.0, 'TEST000001'],
            ['20. 1. 2097', 'GOPAY-storno-popl', 'GOPAY', -5.0, 'TEST000001'],
        ]);
        self::assertTrue(GoPayStatementXlsxParser::isSpreadsheet($content));

        $result = (new GoPayStatementXlsxParser())->parse($content);

        self::assertSame('xlsx', $result['file_format']);
        self::assertSame('VYPIS-1000000001-20970101-20970131', $result['clearing_id']);
        self::assertSame('CZK', $result['currency']);
        self::assertSame(['2097-01-01', '2097-01-31'], [$result['cleared_from'], $result['cleared_to']]);
        self::assertSame(['1000.00', '100.00', '5.00', '0.00'],
            [$result['amount_gross'], $result['amount_storno'], $result['amount_storno_fee'], $result['amount_sent']]);
        self::assertSame('', $result['variable_symbol']);
        self::assertSame(['credit', 'storno', 'storno_fee'], array_column($result['movements'], 'movement_type'));
        self::assertSame('TEST000001', $result['movements'][0]['order_id']);
        self::assertNull($result['movements'][0]['payment_session_id']);
    }

    /** Výplata ve výpisu nese ID clearingu a musí mít stejný identifikátor jako v XML. */
    public function testPayoutOfPreviousClearingSharesIdentifierWithXml(): void
    {
        $content = GoPayStatementFixture::xlsx('1. 2. 2097', '28. 2. 2097', 895.0, [
            ['1. 2. 2097', 'GOPAY-vyuctovani', '1000000005/0100', -875.0, '20970001'],
            ['1. 2. 2097', 'GOPAY-popl', 'GOPAY', -10.0, '20970001'],
            ['1. 2. 2097', 'GOPAY-popl', 'GOPAY', -10.0, '20970001'],
        ]);

        $result = (new GoPayStatementXlsxParser())->parse($content);

        self::assertSame('20970001', $result['variable_symbol']);
        self::assertSame('2097-02-01', $result['performed_on']);
        self::assertSame(['875.00', '20.00'], [$result['amount_sent'], $result['amount_fee']]);
        $ids = array_column($result['movements'], 'amount', 'external_id');
        self::assertSame(['clearing:20970001:payout' => '-875.00', 'clearing:20970001:clearing_fee' => '-20.00'], $ids);
    }

    public function testRejectsStatementWhoseMovementsDoNotMatchSummary(): void
    {
        $content = GoPayStatementFixture::xlsx('1. 1. 2097', '31. 1. 2097', 50.0, [
            ['15. 1. 2097', 'GOPAY-platba', '400000******0001', 1000.0, 'TEST000001'],
        ]);
        $broken = $this->sharedStrings($content);

        $this->expectException(GoPayException::class);
        $this->expectExceptionMessage('Součet pohybů neodpovídá');
        (new GoPayStatementXlsxParser())->parse($broken);
    }

    public function testRejectsUnknownMovementType(): void
    {
        $content = GoPayStatementFixture::xlsx('1. 1. 2097', '31. 1. 2097', 0.0, [
            ['15. 1. 2097', 'GOPAY-neznamy', 'GOPAY', -1.0, 'X'],
        ]);

        $this->expectException(GoPayException::class);
        $this->expectExceptionMessage('nepodporovaný typ pohybu');
        (new GoPayStatementXlsxParser())->parse($content);
    }

    public function testRejectsMultiplePayoutsInOneStatement(): void
    {
        $content = GoPayStatementFixture::xlsx('1. 2. 2097', '28. 3. 2097', 200.0, [
            ['1. 2. 2097', 'GOPAY-vyuctovani', '1000000005/0100', -100.0, '20970001'],
            ['1. 3. 2097', 'GOPAY-vyuctovani', '1000000005/0100', -100.0, '20970002'],
        ]);

        try {
            (new GoPayStatementXlsxParser())->parse($content);
            self::fail('Výpis s více výplatami nejde přiřadit k jednomu vyúčtování.');
        } catch (GoPayException $e) {
            self::assertSame('multiple_payouts', $e->errorCode);
        }
    }

    public function testXmlIsNotDetectedAsSpreadsheet(): void
    {
        self::assertFalse(GoPayStatementXlsxParser::isSpreadsheet('<?xml version="1.0"?><clearing/>'));
    }

    /** Přepíše souhrn v XLSX (sdílené řetězce) a vrátí nový obsah. */
    private function sharedStrings(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gopay_unit_');
        file_put_contents($tmp, $content);
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->addFromString('xl/sharedStrings.xml', str_replace('1 050,00 CZK', '1 049,00 CZK', $xml));
        $zip->close();
        $out = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $out;
    }
}
