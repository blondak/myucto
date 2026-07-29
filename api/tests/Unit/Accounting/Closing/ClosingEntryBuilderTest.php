<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Closing;

use MyInvoice\Service\Accounting\Closing\ClosingEntryBuilder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use PHPUnit\Framework\TestCase;

/**
 * Unit testy ClosingEntryBuilder (Epic F4, §6.1 U1–U5) — čistá třída bez DB.
 *
 * Očekávané hodnoty jsou ZÁVAZNÉ ručně spočtené ze spec F4 (R8, ČÚS 002):
 * strany dle ZNAMÉNKA netto zůstatku per účet, výsledkové páry proti 710,
 * VH 710↔702, rozvahové páry proti 702; otevření zrcadlo proti 701 + VH na 431.
 * Builder emituje řádky po dvojicích (MD/D pár za sebou).
 */
final class ClosingEntryBuilderTest extends TestCase
{
    private ClosingEntryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ClosingEntryBuilder();
    }

    // ── U1: zisk 6 000, plná sada výsledkových i rozvahových účtů ────────────

    public function testU1ClosingLinesProfitScenario(): void
    {
        $pl = [
            self::bal(1, '602', -10000.00), // kreditní zůstatek výnosu
            self::bal(2, '518', 4000.00),   // debetní zůstatek nákladu
        ];
        $bs = [
            self::bal(3, '311', 12100.00),
            self::bal(4, '343', -2100.00),
            self::bal(5, '221', 5000.00),
            self::bal(6, '321', -4840.00),
            self::bal(7, '411', -4160.00),
        ];

        $result = $this->builder->closingLines($pl, $bs);
        $lines = $result['lines'];

        self::assertSame(self::cents(6000.00), self::cents($result['profit']), 'VH (zisk) = 10 000 − 4 000 = 6 000.');
        self::assertCount(16, $lines, '2 výsledkové páry + VH pár + 5 rozvahových párů = 16 řádků.');

        // Výsledkové páry (řazení dle account_code: 518 < 602)
        $this->assertPair($lines, '710', 4000.00, '518');   // MD 710 / D 518
        $this->assertPair($lines, '602', 10000.00, '710');  // MD 602 / D 710
        // VH: zisk MD 710 / D 702
        $this->assertPair($lines, '710', 6000.00, '702');
        // Rozvahové páry: debetní → MD 702 / D účet; kreditní → MD účet / D 702
        $this->assertPair($lines, '702', 5000.00, '221');
        $this->assertPair($lines, '702', 12100.00, '311');
        $this->assertPair($lines, '321', 4840.00, '702');
        $this->assertPair($lines, '343', 2100.00, '702');   // kreditní zůstatek → MD účet / D 702 (R8)
        $this->assertPair($lines, '411', 4160.00, '702');

        // Invariant: Σ MD na 702 == Σ D na 702 (17 100 == 17 100)
        self::assertSame(self::cents(17100.00), $this->sideSum($lines, '702', 'debit'));
        self::assertSame(self::cents(17100.00), $this->sideSum($lines, '702', 'credit'));
        $this->assertBalanced($lines);
    }

    // ── U2: ztráta −3 000 ────────────────────────────────────────────────────

    public function testU2ClosingLinesLossScenario(): void
    {
        $pl = [
            self::bal(1, '518', 8000.00),
            self::bal(2, '602', -5000.00),
        ];
        // Bilančně konzistentní rozvaha: Σ bal == profit (−3 000)
        $bs = [
            self::bal(3, '311', 5000.00),
            self::bal(4, '321', -8000.00),
        ];

        $result = $this->builder->closingLines($pl, $bs);

        self::assertSame(self::cents(-3000.00), self::cents($result['profit']), 'VH = ztráta −3 000.');
        // Ztráta: MD 702 / D 710
        $this->assertPair($result['lines'], '702', 3000.00, '710');
        $this->assertBalanced($result['lines']);
        self::assertSame(
            $this->sideSum($result['lines'], '702', 'debit'),
            $this->sideSum($result['lines'], '702', 'credit'),
            '702 končí na nule i při ztrátě.',
        );
    }

    // ── U3: přetočený zůstatek 343 (nadměrný odpočet — debetní) ──────────────

    public function testU3OverturnedVatBalanceClosesAsAsset(): void
    {
        $bs = [
            self::bal(1, '343', 1500.00),  // DEBETNÍ zůstatek DPH
            self::bal(2, '411', -1500.00),
        ];

        $result = $this->builder->closingLines([], $bs);

        // Znaménkové pravidlo: debetní zůstatek → MD 702 / D 343 (jako aktivum)
        $this->assertPair($result['lines'], '702', 1500.00, '343');
        self::assertSame(0, self::cents($result['profit']));
        $this->assertBalanced($result['lines']);
    }

    // ── U4: otevírací zápis — zrcadlo U1 ─────────────────────────────────────

    public function testU4OpeningLinesMirrorU1(): void
    {
        $bs = [
            self::bal(3, '311', 12100.00),
            self::bal(4, '343', -2100.00),
            self::bal(5, '221', 5000.00),
            self::bal(6, '321', -4840.00),
            self::bal(7, '411', -4160.00),
        ];

        $lines = $this->builder->openingLines($bs, 6000.00);

        self::assertCount(12, $lines, '5 rozvahových párů + VH pár = 12 řádků.');
        $this->assertPair($lines, '221', 5000.00, '701');
        $this->assertPair($lines, '311', 12100.00, '701');
        $this->assertPair($lines, '701', 2100.00, '343');
        $this->assertPair($lines, '701', 4840.00, '321');
        $this->assertPair($lines, '701', 4160.00, '411');
        // VH: zisk MD 701 / D 431
        $this->assertPair($lines, '701', 6000.00, '431');

        // Σ 701 MD == Σ 701 D (17 100)
        self::assertSame(self::cents(17100.00), $this->sideSum($lines, '701', 'debit'));
        self::assertSame(self::cents(17100.00), $this->sideSum($lines, '701', 'credit'));
        $this->assertBalanced($lines);
    }

    public function testU4OpeningLinesLossGoesDebit431(): void
    {
        $bs = [
            self::bal(1, '311', 5000.00),
            self::bal(2, '321', -8000.00),
        ];
        $lines = $this->builder->openingLines($bs, -3000.00);

        // Ztráta: MD 431 / D 701
        $this->assertPair($lines, '431', 3000.00, '701');
        $this->assertBalanced($lines);
    }

    // ── U5: nevyvážené vstupy → closing_unbalanced_702, nic se nezapíše ──────

    public function testU5UnbalancedInputsThrow(): void
    {
        $bs = [self::bal(1, '311', 12100.00)]; // rozvaha bez protistrany

        try {
            $this->builder->closingLines([], $bs);
            self::fail('Očekávána ClosingException closing_unbalanced_702.');
        } catch (ClosingException $e) {
            self::assertSame('closing_unbalanced_702', $e->errorCode);
            self::assertSame(500, $e->httpStatus);
        }
    }

    public function testU5UnbalancedOpeningThrows(): void
    {
        // Zrcadlová pojistka otevíracího zápisu: profit nesedí na rozvahu.
        $bs = [self::bal(1, '311', 1000.00), self::bal(2, '321', -1000.00)];

        try {
            $this->builder->openingLines($bs, 500.00);
            self::fail('Očekávána ClosingException opening_unbalanced_701.');
        } catch (ClosingException $e) {
            self::assertSame('opening_unbalanced_701', $e->errorCode);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{account_id:int, account_code:string, name:string, bal:float}
     */
    private static function bal(int $id, string $code, float $bal): array
    {
        return ['account_id' => $id, 'account_code' => $code, 'name' => 'Účet ' . $code, 'bal' => $bal];
    }

    /**
     * Builder emituje MD/D páry za sebou — ověří existenci páru (MD kód+částka,
     * D kód+stejná částka) na sudém offsetu.
     *
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function assertPair(array $lines, string $debitCode, float $amount, string $creditCode): void
    {
        for ($i = 0; $i + 1 < count($lines); $i += 2) {
            $d = $lines[$i];
            $c = $lines[$i + 1];
            if ($d['side'] === 'debit' && $c['side'] === 'credit'
                && $d['account_code'] === $debitCode && $c['account_code'] === $creditCode
                && self::cents($d['amount']) === self::cents($amount)
                && self::cents($c['amount']) === self::cents($amount)) {
                self::assertTrue(true);
                return;
            }
        }
        self::fail(sprintf('Chybí pár MD %s / D %s na %.2f.', $debitCode, $creditCode, $amount));
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function sideSum(array $lines, string $code, string $side): int
    {
        $sum = 0;
        foreach ($lines as $l) {
            if ($l['account_code'] === $code && $l['side'] === $side) {
                $sum += self::cents($l['amount']);
            }
        }
        return $sum;
    }

    /** @param list<array{side:string, amount:float}> $lines */
    private function assertBalanced(array $lines): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $l) {
            $l['side'] === 'debit' ? $debit += self::cents($l['amount']) : $credit += self::cents($l['amount']);
        }
        self::assertSame($debit, $credit, 'Σ MD == Σ D celého zápisu (v haléřích).');
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100.0);
    }
}
