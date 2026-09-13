<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Service\Mail\RecipientResolver;
use PHPUnit\Framework\TestCase;

final class RecipientResolverOverridesTest extends TestCase
{
    private const RESOLVED = [
        'to'       => ['fakturace@example.test'],
        'cc'       => ['dodavatel@example.test'],
        'bcc'      => [],
        'resolved' => [['email' => 'fakturace@example.test', 'recipient' => 'to', 'source' => 'main_email', 'usage' => null, 'label' => null]],
    ];

    public function testWithoutOverridesKeepsResolved(): void
    {
        self::assertSame(self::RESOLVED, RecipientResolver::applyOverrides(self::RESOLVED, null, [], []));
    }

    public function testExtraCcIsAddedToResolvedWithoutTo(): void
    {
        $r = RecipientResolver::applyOverrides(self::RESOLVED, null, [' stavbyvedouci@example.test ', 'dodavatel@example.test'], ['archiv@example.test']);

        self::assertSame(['fakturace@example.test'], $r['to']);
        self::assertSame(['dodavatel@example.test', 'stavbyvedouci@example.test'], $r['cc']);
        self::assertSame(['archiv@example.test'], $r['bcc']);
        self::assertSame(self::RESOLVED['resolved'], $r['resolved']);
    }

    public function testExplicitToIsAuthoritative(): void
    {
        $r = RecipientResolver::applyOverrides(self::RESOLVED, ['fakturace@example.test', '', 'nakupci@example.test'], [], []);

        self::assertSame(['fakturace@example.test', 'nakupci@example.test'], $r['to']);
        self::assertSame([], $r['cc'], 'Uživatel kopii dodavateli v modalu smazal — nesmí se vrátit.');
        self::assertSame([], $r['bcc']);
        self::assertSame([], $r['resolved']);
    }
}
