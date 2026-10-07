<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import;

use MyInvoice\Service\Payroll\Import\ImportFiles;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportReader;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\JmhzReportFixtures;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\TestCase;

final class ImportFilesTest extends TestCase
{
    /**
     * PRE-06: měsíční hlášení JMHZ má až 1500 formulářů, což je řádově desítky MB
     * u reálných programů; limit 5 MB ho odmítl už kolem 650 formulářů.
     */
    public function testReportWith1500FormsIsAcceptedWithXmlLimitsAndStillReadable(): void
    {
        $people = [];
        for ($i = 1; $i <= 1500; $i++) {
            $people[] = JmhzReportFixtures::person([
                'employment_id' => $i,
                'oic' => RegistrationXmlFixtures::oic($i),
                'id_ppv' => sprintf('2%020d', $i),
            ]);
        }
        $xml = JmhzReportFixtures::report($people, 2026, 8);
        self::assertGreaterThan(ImportFiles::MAX_FILE_BYTES, strlen($xml), 'Syntetický balík musí být větší než obecný limit.');
        $files = [['name' => 'jmhz-1500.xml', 'content_base64' => base64_encode($xml)]];

        $read = ImportFiles::fromRequest($files, ['xml'], ImportFiles::XML_MAX_FILE_BYTES, ImportFiles::XML_MAX_TOTAL_BYTES);

        self::assertSame(strlen($xml), strlen($read[0]['content']));
        $report = (new JmhzReportReader())->read($read[0]['content']);
        self::assertCount(1500, $report->forms);
    }

    public function testGeneralLimitStillRejectsLargeFileForOtherImports(): void
    {
        $files = [['name' => 'velky.xlsx', 'content_base64' => base64_encode(str_repeat('a', ImportFiles::MAX_FILE_BYTES + 1))]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('větší než 5 MB');
        ImportFiles::fromRequest($files, ['xlsx']);
    }

    public function testXmlLimitRejectsOversizedFileWithItsOwnNumber(): void
    {
        $files = [['name' => 'obri.xml', 'content_base64' => base64_encode(str_repeat('a', ImportFiles::XML_MAX_FILE_BYTES + 1))]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('větší než 20 MB');
        ImportFiles::fromRequest($files, ['xml'], ImportFiles::XML_MAX_FILE_BYTES, ImportFiles::XML_MAX_TOTAL_BYTES);
    }
}
