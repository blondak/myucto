<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxPaymentReconciliation as Reconcile;
use PHPUnit\Framework\TestCase;

final class StereoNxPaymentReconciliationTest extends TestCase
{
    private function document(array $overrides = []): array
    {
        return $overrides + ['DoklSRada' => 'TEST', 'DoklSCislo' => 1, 'SmerPlatby' => 'P',
            'Mena' => 'Kč', 'Kurz' => 1, 'KurzMn' => 1, 'Celkem' => 121.0,
            'Uhrazeno' => 121.0, 'UhrazenoVse' => false];
    }

    private function payment(float $amount, array $overrides = []): array
    {
        return $overrides + ['DoklSRada' => 'TEST', 'DoklSCislo' => 1, 'SmerPlatby' => 'P',
            'MenaCizi' => '', 'Castka' => $amount];
    }

    public function testCombinesPartialBankAndCashPaymentsDespiteFalsePaidFlag(): void
    {
        $report = Reconcile::check([$this->document()], [$this->payment(100)], [$this->payment(21)]);
        self::assertTrue($report['ok']);
        self::assertSame(1, $report['matched_documents']);
        self::assertSame(1, $report['paid_flag_disagreements']);
        self::assertSame(2, $report['linked_movements']);
    }

    public function testMismatchIsNotSuccessful(): void
    {
        $report = Reconcile::check([$this->document()], [$this->payment(120.99)], []);
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['amount_mismatches']);
    }

    public function testOrphanIsNotSuccessful(): void
    {
        $report = Reconcile::check([], [$this->payment(121)], []);
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['orphan_links']);
    }

    public function testDirectionMismatchCannotConfirmPayment(): void
    {
        $report = Reconcile::check([$this->document()], [$this->payment(121, ['SmerPlatby' => 'V'])], []);
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['direction_mismatches']);
        self::assertSame(0, $report['matched_documents']);
        self::assertSame(0, $report['foreign_currency_unverified']);
    }

    public function testForeignCurrencyIsExplicitlyUnverified(): void
    {
        $report = Reconcile::check([$this->document(['Mena' => 'EUR', 'Kurz' => 25])], [$this->payment(121)], []);
        self::assertFalse($report['ok']);
        self::assertSame(1, $report['foreign_currency_unverified']);
        self::assertSame(0, $report['matched_documents']);
    }

    public function testUnpaidDocumentAndUnlinkedBankMovementRemainSeparate(): void
    {
        $report = Reconcile::check([$this->document(['Uhrazeno' => 0])], [$this->payment(121, ['DoklSRada' => '', 'DoklSCislo' => 0])], []);
        self::assertTrue($report['ok']);
        self::assertSame(1, $report['unlinked_movements']);
        self::assertSame(1, $report['matched_documents']);
        self::assertSame(0, $report['paid_flag_disagreements']);
    }

    public function testDuplicateIdentityFailsRatherThanOverwritingDocument(): void
    {
        $this->expectException(StereoNxException::class);
        Reconcile::check([$this->document(), $this->document()], [], []);
    }

    public function testMissingAmountDoesNotBecomeZero(): void
    {
        $this->expectException(StereoNxException::class);
        Reconcile::check([$this->document(['Uhrazeno' => null])], [], []);
    }

    public function testIncompleteLinkDoesNotBecomeUnlinkedPayment(): void
    {
        $this->expectException(StereoNxException::class);
        Reconcile::check([], [$this->payment(1, ['DoklSRada' => ''])], []);
    }
}
