<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Setup;

use MyInvoice\Service\Setup\TermsOrigin;
use PHPUnit\Framework\TestCase;

/**
 * H-33 — původ souhlasu s podmínkami, který jde do auditního logu k `setup.completed`.
 */
final class TermsOriginTest extends TestCase
{
    public function testMissingBlockIsNull(): void
    {
        self::assertNull(TermsOrigin::normalize(null));
        self::assertNull(TermsOrigin::normalize('2026-08-21'));
        self::assertNull(TermsOrigin::normalize([]));
        self::assertNull(TermsOrigin::normalize(['order_number' => '   ']));
    }

    public function testFullOriginIsNormalized(): void
    {
        $origin = TermsOrigin::normalize([
            'order_number' => ' 2026-000123 ',
            'accepted_at'  => '2026-08-21T18:04:11+02:00',
            'ip'           => '198.51.100.7',
        ]);

        self::assertSame([
            'order_number' => '2026-000123',
            'accepted_at'  => '2026-08-21T18:04:11+02:00',
            'ip'           => '198.51.100.7',
        ], $origin);
    }

    public function testPartialOriginKeepsOnlyWhatWasSent(): void
    {
        $origin = TermsOrigin::normalize(['order_number' => '2026-000123', 'ip' => '']);

        self::assertSame(['order_number' => '2026-000123'], $origin);
    }

    public function testUnknownKeysNeverReachTheAuditLog(): void
    {
        $origin = TermsOrigin::normalize([
            'order_number' => '2026-000123',
            'note'         => 'cokoliv, co si volající vymyslí',
        ]);

        self::assertSame(['order_number' => '2026-000123'], $origin);
    }

    public function testOverlongValuesAreTruncated(): void
    {
        $origin = TermsOrigin::normalize(['order_number' => str_repeat('9', 500)]);

        self::assertNotNull($origin);
        self::assertSame(190, mb_strlen($origin['order_number']));
    }
}
