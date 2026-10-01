<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionSettlementPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Migrace 1955 dorovnává povinnosti, které splnilo samo doručení. SQL nemůže
 * zavolat {@see PayrollSubmissionSettlementPolicy::settlesOnDelivery()},
 * takže agendy vyjmenovává — a tenhle test hlídá, že jde o tytéž agendy.
 */
final class PayrollDeliverySettlementMigrationTest extends TestCase
{
    public function testMigrationCoversExactlyTheAgendasSettledOnDelivery(): void
    {
        $catalog = new PayrollDispatchCapabilityCatalog();
        $policy = new PayrollSubmissionSettlementPolicy($catalog);
        $expected = array_values(array_filter(
            $catalog->codes(),
            static fn (string $code): bool => $policy->settlesOnDelivery($code),
        ));
        sort($expected);
        self::assertNotSame([], $expected);

        $sql = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/db/migrations/1955_payroll_health_delivery_fulfils_obligation.sql',
        );
        self::assertSame(
            1,
            preg_match('/agenda_code\s+IN\s*\(([^)]*)\)/i', $sql, $match),
        );
        preg_match_all("/'([^']+)'/", $match[1], $codes);
        $actual = $codes[1];
        sort($actual);

        self::assertSame($expected, $actual);
    }
}
