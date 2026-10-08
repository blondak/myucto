<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerChannelCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsurerIsdsAttachmentFormat;
use PHPUnit\Framework\TestCase;

final class HealthInsurerChannelCatalogTest extends TestCase
{
    public function testCurrentAttachmentMatrixCoversAllSevenInsurers(): void
    {
        $catalog = new HealthInsurerChannelCatalog();
        $formats = [];
        foreach ($catalog->channels() as $code => $channel) {
            $formats[$code] = $channel
                ->isdsAttachmentFormatOn('2026-08-25')
                ->value;
        }

        self::assertSame([
            '111' => 'text_pdf',
            '201' => 'text_pdf',
            '205' => 'xml',
            '207' => 'xml',
            '209' => 'text_pdf',
            '211' => 'text_pdf',
            '213' => 'xml',
        ], $formats);
    }

    public function testAttachmentMatrixHasExplicitStartAndEndBoundaries(): void
    {
        $catalog = new HealthInsurerChannelCatalog();
        foreach ($catalog->channels() as $code => $channel) {
            self::assertSame(
                HealthInsurerIsdsAttachmentFormat::None,
                $channel->isdsAttachmentFormatOn('2025-12-31'),
                (string) $code,
            );
        }
        foreach ([
            '111' => 'text_pdf',
            '201' => 'text_pdf',
            '205' => 'xml',
            '207' => 'xml',
            '209' => 'text_pdf',
            '211' => 'text_pdf',
            '213' => 'xml',
        ] as $code => $expected) {
            self::assertSame(
                $expected,
                $catalog->forInsurer((string) $code)
                    ->isdsAttachmentFormatOn('2026-01-01')
                    ->value,
                (string) $code,
            );
        }
        foreach ([
            '205' => HealthInsurerIsdsAttachmentFormat::Xml,
            '207' => HealthInsurerIsdsAttachmentFormat::Xml,
            '211' => HealthInsurerIsdsAttachmentFormat::TextPdf,
            '213' => HealthInsurerIsdsAttachmentFormat::Xml,
        ] as $code => $expected) {
            self::assertSame(
                $expected,
                $catalog->forInsurer((string) $code)
                    ->isdsAttachmentFormatOn('2027-01-01'),
                (string) $code,
            );
        }
    }

    /**
     * Přechodné období pro dosavadní formáty končí 31. 12. 2026, potom zůstává
     * jen jednotná datová věta. Od ledna 2027 tak u žádné pojišťovny nesmí
     * chybět formát přílohy, jinak by nešlo vyrobit ani PPZ za 12/2026.
     */
    public function testLegacyPdfTransitionEndsWithTheSharedXmlFrom2027(): void
    {
        $catalog = new HealthInsurerChannelCatalog();
        foreach (['111', '201', '209'] as $insurerCode) {
            $channel = $catalog->forInsurer($insurerCode);
            self::assertSame(
                HealthInsurerIsdsAttachmentFormat::TextPdf,
                $channel->isdsAttachmentFormatOn('2026-12-31'),
                $insurerCode,
            );
            self::assertSame(
                HealthInsurerIsdsAttachmentFormat::Xml,
                $channel->isdsAttachmentFormatOn('2027-01-01'),
                $insurerCode,
            );
        }
        foreach ($catalog->channels() as $code => $channel) {
            foreach (['2027-01-01', '2027-01-20', '2030-06-30'] as $date) {
                self::assertNotSame(
                    HealthInsurerIsdsAttachmentFormat::None,
                    $channel->isdsAttachmentFormatOn($date),
                    "{$code} {$date}",
                );
            }
        }
    }

    public function testZpMvKeepsPdfForIsdsAfterThe2026Transition(): void
    {
        $channel = (new HealthInsurerChannelCatalog())->forInsurer('211');

        self::assertSame(
            HealthInsurerIsdsAttachmentFormat::TextPdf,
            $channel->isdsAttachmentFormatOn('2026-10-01'),
        );
        self::assertSame(
            HealthInsurerIsdsAttachmentFormat::TextPdf,
            $channel->isdsAttachmentFormatOn('2027-01-01'),
        );
    }

    public function testRbpUsesTheDocumentedSharedXml(): void
    {
        $channel = (new HealthInsurerChannelCatalog())->forInsurer('213');

        self::assertSame(
            HealthInsurerIsdsAttachmentFormat::Xml,
            $channel->isdsAttachmentFormatOn('2026-08-25'),
        );
    }

    public function testChannelCodesStayInSyncWithTheSharedInsurerCodebook(): void
    {
        self::assertSame(
            HealthInsurers::codes(),
            array_map(
                strval(...),
                array_keys((new HealthInsurerChannelCatalog())->channels()),
            ),
        );
    }
}
