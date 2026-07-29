<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use PHPUnit\Framework\TestCase;

final class F7NestedRbacTest extends TestCase
{
    public function testNestedDocumentAndJournalRoutesAreMapped(): void
    {
        $map = new RoutePermissionMap();
        foreach ([
            ['GET', '/api/documents/5/files', 'documents', AccessLevel::READ],
            ['POST', '/api/documents/5/files', 'documents.upload', AccessLevel::WRITE],
            ['GET', '/api/accounting/journal/5/attachments', 'accounting', AccessLevel::READ],
            ['POST', '/api/accounting/journal/5/attachments', 'accounting.journal.write', AccessLevel::WRITE],
        ] as [$method, $path, $key, $level]) {
            $rule = $map->match($method, $path);
            self::assertSame($key, $rule?->key, "$method $path");
            self::assertSame($level, $rule?->minimum, "$method $path");
        }
    }

    public function testAiCredentialsStayFixedSuperadminOnly(): void
    {
        self::assertSame(
            RoutePermissionMap::SUPERADMIN,
            (new RoutePermissionMap())->match('POST', '/api/admin/imports/ai/credentials/test')?->kind,
        );
    }
}
