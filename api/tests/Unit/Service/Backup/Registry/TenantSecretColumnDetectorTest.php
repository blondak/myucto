<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Registry;

use MyInvoice\Service\Backup\Registry\TenantSecretColumnDetector;
use PHPUnit\Framework\TestCase;

final class TenantSecretColumnDetectorTest extends TestCase
{
    public function testSecretLikeColumnsRequireExplicitPolicy(): void
    {
        foreach ([
            'api_key_enc',
            'ai_pseudo_salt',
            'approval_token',
            'certificate_private_key',
            'smtp_password_enc',
        ] as $column) {
            self::assertTrue(TenantSecretColumnDetector::matches($column), $column);
        }
    }

    public function testOrdinaryBusinessColumnsStayOutsideHeuristic(): void
    {
        self::assertFalse(TenantSecretColumnDetector::matches('tax_rate'));
        self::assertFalse(TenantSecretColumnDetector::matches('supplier_id'));
        self::assertFalse(TenantSecretColumnDetector::matches('client_id'));
    }
}
