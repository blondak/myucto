<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionGuidFactory;
use PHPUnit\Framework\TestCase;

final class JmhzSubmissionGuidFactoryTest extends TestCase
{
    public function testGeneratedGuidIsAcceptedByTheEnvelope(): void
    {
        $factory = new JmhzSubmissionGuidFactory();

        $envelope = JmhzSubmissionEnvelope::create(
            $factory->next(),
            [101 => $factory->next()],
            '2026-08-05T09:30:00Z',
            'MyÚčto.cz',
            '5.6.0',
        );

        self::assertMatchesRegularExpression(
            '/^[0-9A-F]{8}-[0-9A-F]{4}-7[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/D',
            $envelope->submissionGuid,
        );
    }

    public function testGuidsAreUniqueAndTimeOrdered(): void
    {
        $factory = new JmhzSubmissionGuidFactory();
        $guids = [];
        for ($index = 0; $index < 200; $index++) {
            $guids[] = $factory->next();
        }

        self::assertCount(200, array_unique($guids));
        // Prvních dvanáct hexadecimálních číslic je čas v milisekundách, takže
        // dávka vygenerovaná v jednom běhu musí být neklesající.
        $prefixes = array_map(
            static fn (string $guid): string => substr($guid, 0, 8) . substr($guid, 9, 4),
            $guids,
        );
        $sorted = $prefixes;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $prefixes);
    }
}
