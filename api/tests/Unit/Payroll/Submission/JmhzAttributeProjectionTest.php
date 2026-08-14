<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeProjection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use PHPUnit\Framework\TestCase;

final class JmhzAttributeProjectionTest extends TestCase
{
    public function testEachPartKeepsItsOwnAttributes(): void
    {
        $projection = JmhzAttributeProjection::fromXml(JmhzXmlSample::minimal());

        self::assertSame(7, $projection->submission()->integer('10010'));
        self::assertSame(2026, $projection->submission()->integer('10011'));
        self::assertSame('1234567890', $projection->submission()->value('10221'));
        self::assertSame(150, $projection->summary()->integer('10034'));
        self::assertSame(1000, $projection->pvpoj()->integer('10023'));
        self::assertCount(1, $projection->forms());
        self::assertSame(1000, $projection->forms()[0]->integer('10286'));
    }

    /**
     * `hlavicka` je pod kořenem dokumentu i uvnitř součásti. Kdyby se části
     * rozlišovaly jen podle názvu elementu, spadl by příznak primárního PPV
     * do metadatové hlavičky a kontroly nad součástí by ho neviděly.
     */
    public function testFormHeaderDoesNotLeakIntoSubmissionHeader(): void
    {
        $projection = JmhzAttributeProjection::fromXml(JmhzXmlSample::minimal());

        self::assertFalse($projection->submission()->has('10495'));
        self::assertTrue($projection->forms()[0]->boolean('10495'));
    }

    /**
     * Slovník uvádí cestu k počtu formulářů bez prefixu hlavičky, přestože
     * element uvnitř hlavičky leží. Promítnutí proto bere nejdelší příponu
     * cesty, která je ve slovníku celou cestou.
     */
    public function testDictionaryPathWithoutHeaderPrefixStillResolves(): void
    {
        $projection = JmhzAttributeProjection::fromXml(JmhzXmlSample::minimal());

        self::assertSame(3, $projection->submission()->integer('10015'));
        self::assertSame(3, $projection->submission()->integer('10488'));
    }

    /**
     * Dva stejnojmenné listy s různým rodičem jsou různé atributy. Zdravotní
     * pojištění za zaměstnavatele a za zaměstnance se nesmí slít.
     */
    public function testSameLeafNameUnderDifferentParentsStaysDistinct(): void
    {
        $form = JmhzAttributeProjection::fromXml(JmhzXmlSample::minimal())->forms()[0];

        self::assertSame(90, $form->integer('10482'));
        self::assertSame(45, $form->integer('10371'));
    }

    public function testRepeatedEldpSectionsAreGroupedByTheirOwnBlock(): void
    {
        $form = JmhzAttributeProjection::fromXml(JmhzXmlSample::twoEldpSections())->forms()[0];

        self::assertSame(
            [
                ['10241' => '2026-07-01', '10242' => '2026-07-15', '10356' => '15'],
                ['10241' => '2026-07-16', '10242' => '2026-07-31', '10356' => '16'],
            ],
            $form->groupedBy(['10241', '10242', '10356']),
        );
    }

    public function testUnknownElementFailsClosed(): void
    {
        $xml = str_replace(
            '<mesic>7</mesic>',
            '<mesicek>7</mesicek>',
            JmhzXmlSample::minimal(),
        );

        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/nemá ve slovníku JMHZ/');
        JmhzAttributeProjection::fromXml($xml);
    }

    public function testTwoFormsProduceTwoScopes(): void
    {
        $projection = JmhzAttributeProjection::fromXml(JmhzXmlSample::twoForms());

        self::assertCount(2, $projection->forms());
        self::assertSame('1000000001', $projection->forms()[0]->value('10051'));
        self::assertSame('1000000012', $projection->forms()[1]->value('10051'));
    }
}
