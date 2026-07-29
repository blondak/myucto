<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Service\Epo\EpoSubmissionPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class EpoSubmissionPayloadBuilderTest extends TestCase
{
    public function testLeavesOrdinaryEpoXmlUnchanged(): void
    {
        $xml = '<Pisemnost><DPHDP3/></Pisemnost>';

        self::assertSame($xml, (new EpoSubmissionPayloadBuilder())->build([
            'form_code' => 'dphdp3',
            'xml_content' => $xml,
        ]));
    }

    public function testCompressesControlStatementAsZipWithExactlyOneXml(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZIP rozšíření není dostupné.');
        }
        $xml = '<Pisemnost><DPHKH1/></Pisemnost>';
        $payload = (new EpoSubmissionPayloadBuilder())->build([
            'form_code' => 'dphkh1',
            'xml_content' => $xml,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'epo-payload-test-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $payload);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            self::assertSame(1, $zip->numFiles);
            self::assertSame('DPHKH1.xml', $zip->getNameIndex(0));
            self::assertSame($xml, $zip->getFromIndex(0));
            $zip->close();
        } finally {
            @unlink($path);
        }
    }
}
