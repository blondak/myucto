<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Service\System\DiagnosticsBundleOptions;
use MyInvoice\Service\System\DiagnosticsLogReader;
use PHPUnit\Framework\TestCase;

/**
 * Rozsah balíčku. Logy jsou jediná položka, která může nést osobní údaje
 * třetích osob — musí být opt-in, nikdy opt-out.
 */
final class DiagnosticsBundleOptionsTest extends TestCase
{
    public function testLogsAreDisabledByDefault(): void
    {
        self::assertFalse(DiagnosticsBundleOptions::defaults()->includeLogs);
        self::assertFalse(DiagnosticsBundleOptions::fromArray([])->includeLogs);
    }

    /**
     * Chybějící ani neurčitá hodnota nesmí logy zapnout. Kdyby se `include_logs`
     * vyhodnocovalo přes `!empty()` nad chybějícím klíčem s defaultem `true`,
     * prošly by logy tiše — tenhle test to zavírá.
     */
    public function testLogsRequireExplicitTruthyFlag(): void
    {
        self::assertFalse(DiagnosticsBundleOptions::fromArray(['include_logs' => '0'])->includeLogs);
        self::assertFalse(DiagnosticsBundleOptions::fromArray(['include_logs' => 'false'])->includeLogs);
        self::assertFalse(DiagnosticsBundleOptions::fromArray(['include_logs' => ''])->includeLogs);
        self::assertFalse(DiagnosticsBundleOptions::fromArray(['include_logs' => 'nesmysl'])->includeLogs);

        self::assertTrue(DiagnosticsBundleOptions::fromArray(['include_logs' => '1'])->includeLogs);
        self::assertTrue(DiagnosticsBundleOptions::fromArray(['include_logs' => true])->includeLogs);
        self::assertTrue(DiagnosticsBundleOptions::fromArray(['include_logs' => 'true'])->includeLogs);
    }

    public function testDaysAreClampedToSupportedWindow(): void
    {
        self::assertSame(1, DiagnosticsBundleOptions::fromArray(['days' => 0])->days);
        self::assertSame(1, DiagnosticsBundleOptions::fromArray(['days' => -50])->days);
        self::assertSame(
            DiagnosticsLogReader::MAX_DAYS,
            DiagnosticsBundleOptions::fromArray(['days' => 3650])->days
        );
        self::assertSame(3, DiagnosticsBundleOptions::fromArray(['days' => 3])->days);
    }

    public function testUnknownLogLevelFallsBackToWarning(): void
    {
        self::assertSame('WARNING', DiagnosticsBundleOptions::fromArray(['log_level' => 'nope'])->logLevel);
        self::assertSame('ERROR', DiagnosticsBundleOptions::fromArray(['log_level' => 'error'])->logLevel);
    }

    public function testOmittedListsWhatUserExcluded(): void
    {
        $options = DiagnosticsBundleOptions::fromArray([
            'include_config'  => false,
            'include_license' => false,
        ]);

        self::assertContains('config', $options->omitted());
        self::assertContains('license', $options->omitted());
        // Logy jsou vypnuté defaultně, takže patří mezi vynechané taky.
        self::assertContains('logs', $options->omitted());
        self::assertNotContains('environment', $options->omitted());
    }

    public function testToArrayRoundTripsThroughFromArray(): void
    {
        $original = DiagnosticsBundleOptions::fromArray([
            'include_logs'   => true,
            'include_cron'   => false,
            'days'           => 5,
            'log_level'      => 'ERROR',
        ]);

        $restored = DiagnosticsBundleOptions::fromArray($original->toArray());

        self::assertSame($original->toArray(), $restored->toArray());
    }
}
