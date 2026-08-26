<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\InvoiceNoteAlias;
use PHPUnit\Framework\TestCase;

/**
 * Issue #38 — OpenAPI slibovalo `note` → `note_below_items`, implementace klíč tiše
 * zahazovala. {@see InvoiceNoteAlias} je jediné místo, kde se ten překlad děje.
 */
final class InvoiceNoteAliasTest extends TestCase
{
    public function testNoteMapsToNoteBelowItems(): void
    {
        $out = InvoiceNoteAlias::normalize(['note' => 'TEST NOTE']);

        self::assertSame('TEST NOTE', $out['note_below_items']);
        self::assertArrayNotHasKey('note', $out, '`note` není sloupec — dál se posílat nesmí.');
    }

    public function testExplicitNoteBelowItemsWins(): void
    {
        $out = InvoiceNoteAlias::normalize([
            'note'             => 'alias',
            'note_below_items' => 'konkrétní',
        ]);

        self::assertSame('konkrétní', $out['note_below_items']);
        self::assertArrayNotHasKey('note', $out);
    }

    /** Explicitní `null` je vyprázdnění, ne „nic jsem neposlal" — alias ho nesmí přebít. */
    public function testExplicitNullNoteBelowItemsWinsOverAlias(): void
    {
        $out = InvoiceNoteAlias::normalize([
            'note'             => 'alias',
            'note_below_items' => null,
        ]);

        self::assertNull($out['note_below_items']);
        self::assertArrayNotHasKey('note', $out);
    }

    public function testNullNoteClearsNoteBelowItems(): void
    {
        $out = InvoiceNoteAlias::normalize(['note' => null]);

        self::assertArrayHasKey('note_below_items', $out);
        self::assertNull($out['note_below_items']);
    }

    public function testNoteAboveItemsIsNeverTouched(): void
    {
        $out = InvoiceNoteAlias::normalize([
            'note'             => 'dolů',
            'note_above_items' => 'nahoru',
        ]);

        self::assertSame('nahoru', $out['note_above_items']);
        self::assertSame('dolů', $out['note_below_items']);
    }

    public function testBodyWithoutNoteIsUnchanged(): void
    {
        $body = ['client_id' => 1, 'note_below_items' => 'beze změny'];

        self::assertSame($body, InvoiceNoteAlias::normalize($body));
    }

    /** Pole/objekt pod `note` je nesmysl; zahodí se stejně tiše, jako by tam nebyl. */
    public function testNonScalarNoteIsDropped(): void
    {
        $out = InvoiceNoteAlias::normalize(['note' => ['a' => 'b']]);

        self::assertArrayNotHasKey('note', $out);
        self::assertArrayNotHasKey('note_below_items', $out);
    }

    public function testScalarNoteIsCastToString(): void
    {
        $out = InvoiceNoteAlias::normalize(['note' => 12345]);

        self::assertSame('12345', $out['note_below_items']);
    }
}
