<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlParameterCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzControlParameterCatalogTest extends TestCase
{
    /**
     * Sazby podle písmene § 5a musí být právě ty, které katalog ČSSZ přiřazuje
     * kontrole 315 — jinak by hlášení spočítalo 10481 jinak než ČSSZ.
     */
    public function testParagraph5RatesAreTheParametersOfControl315(): void
    {
        $keys = JmhzControlSourceCatalog::load()->parameters()->keysForControl(315);
        $letterKeys = array_values(JmhzControlParameterCatalog::EMPLOYER_SOCIAL_RATE_BY_PARAGRAPH5_LETTER);
        sort($keys);
        sort($letterKeys);

        self::assertSame($keys, $letterKeys);
    }

    public function testEmployerSocialInsuranceIsRoundedUpToWholeCrowns(): void
    {
        $parameters = JmhzControlSourceCatalog::load()->parameters();

        self::assertSame(249, $parameters->employerSocialInsuranceCzk(1_001, 'a', '2026-06-01'));
        self::assertSame(248, $parameters->employerSocialInsuranceCzk(1_000, 'a', '2026-06-01'));
        self::assertSame(298, $parameters->employerSocialInsuranceCzk(1_000, 'b', '2026-06-01'));
        self::assertSame(278, $parameters->employerSocialInsuranceCzk(1_000, 'c', '2026-06-01'));
    }

    public function testUnknownLetterIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        JmhzControlSourceCatalog::load()->parameters()->employerSocialInsuranceCzk(1_000, 'd', '2026-06-01');
    }
}
