<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * §DM — rozpad nákladové strany přijaté faktury po POLOŽKÁCH podle `expense_kind`.
 *
 * Kořen problému: bez klasifikace padalo všechno na 518 „Ostatní služby", takže tablet a
 * zařízení kanceláře se vykazovaly jako služby. 501 a 518 jsou přitom RŮZNÉ řádky VZZ
 * (A.2. Spotřeba materiálu a energie × A.3. Služby).
 *
 * Nejdůležitější invariant téhle sady: přeúčtování 518 → 501 je změna ANALYTIKY, ne částky.
 * DPH (343) ani závazek (321) se tím NESMÍ hnout — jinak se accounting rozejde s podaným
 * přiznáním. Proto se autoritativní základ jen ROZDĚLUJE podle vah položek, nesčítá se znovu.
 */
#[Group('integration')]
final class ExpenseKindPostingTest extends BankPostingTestCase
{
    private int $vatRateId = 0;

    /**
     * Účet materiálu/drobného majetku NENÍ konstanta: firma může mít analytiky
     * (migrace 1127 zavedla 501.100 PHM / 501.900 ostatní materiál) a per-tenant
     * override v posting_rules pak vyhraje nad globální syntetikou 501. Test proto
     * cílový účet RESOLVUJE stejně jako produkce, místo aby předpokládal „501".
     */
    private string $materialAccount = '501';

    protected function setUp(): void
    {
        parent::setUp();
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
        $this->materialAccount = $this->resolveRuleAccount('invoice.material.received', '501');
    }

    /** Kopie precedence z PostingRuleRepository::resolve() — per-tenant přebíjí globální. */
    private function resolveRuleAccount(string $ruleKey, string $fallback): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT debit_account_code FROM posting_rules
              WHERE rule_key = ? AND is_active = 1 AND (supplier_id = ? OR supplier_id IS NULL)
              ORDER BY (supplier_id IS NULL) ASC, priority DESC
              LIMIT 1'
        );
        $stmt->execute([$ruleKey, $this->supplierId]);
        $code = $stmt->fetchColumn();
        return $code === false || $code === null ? $fallback : (string) $code;
    }

    /** Regrese: doklad BEZ klasifikace se musí zaúčtovat přesně jako dosud — jedna noha na 518. */
    public function testUnclassifiedInvoiceStillPostsSingleServiceLine(): void
    {
        $pf = $this->purchaseWithItems('PF-UNCL', [
            ['Hosting', 1000.00, 210.00, null],
            ['Doména', 500.00, 105.00, null],
        ]);

        $byAcc = $this->postAndGetLines($pf);

        self::assertEqualsWithDelta(1500.00, $byAcc['518']['debit'], 0.001, 'Neklasifikovaný doklad zůstává celý na 518.');
        self::assertArrayNotHasKey($this->materialAccount, $byAcc, 'Bez klasifikace se 501 nesmí objevit.');
        self::assertEqualsWithDelta(315.00, $byAcc['343.100']['debit'], 0.001);
        self::assertEqualsWithDelta(1815.00, $byAcc['321']['credit'], 0.001);
    }

    /** Jádro §DM: faktura míchající majetek i služby se rozpadne na 501 + 518. */
    public function testMixedInvoiceSplitsExpenseAcrossAccounts(): void
    {
        // Vzor z reálné Alzy: notebook + brašna + doprava + záruka na jednom dokladu.
        $pf = $this->purchaseWithItems('PF-MIX', [
            ['Notebook Dell', 45000.00, 9450.00, 'small_asset'],
            ['Brašna', 1200.00, 252.00, 'material'],
            ['Doprava', 150.00, 31.50, 'service'],
            ['Prodloužená záruka', 3000.00, 630.00, 'service'],
        ]);

        $byAcc = $this->postAndGetLines($pf);

        // small_asset i material → 501 (syntetika); service → 518.
        self::assertEqualsWithDelta(46200.00, $byAcc[$this->materialAccount]['debit'], 0.001, '501 = notebook 45 000 + brašna 1 200.');
        self::assertEqualsWithDelta(3150.00, $byAcc['518']['debit'], 0.001, '518 = doprava 150 + záruka 3 000.');

        // A tohle je ten invariant, kvůli kterému se rozděluje a nesčítá:
        self::assertEqualsWithDelta(10363.50, $byAcc['343.100']['debit'], 0.001, 'DPH se rozpadem NESMÍ hnout.');
        self::assertEqualsWithDelta(59713.50, $byAcc['321']['credit'], 0.001, 'Závazek se rozpadem NESMÍ hnout.');
        self::assertArrayNotHasKey('548', $byAcc, 'Rozpad nesmí vyrobit dorovnání na 548.');
        self::assertArrayNotHasKey('648', $byAcc, 'Rozpad nesmí vyrobit dorovnání na 648.');
    }

    /** Částečná klasifikace: neurčené položky padnou na dosavadní default (518), základ pořád sedí. */
    public function testPartiallyClassifiedInvoiceFallsBackToServicesForNullItems(): void
    {
        $pf = $this->purchaseWithItems('PF-PART', [
            ['Kávovar', 20000.00, 4200.00, 'small_asset'],
            ['Něco neurčeného', 5000.00, 1050.00, null],
        ]);

        $byAcc = $this->postAndGetLines($pf);

        self::assertEqualsWithDelta(20000.00, $byAcc[$this->materialAccount]['debit'], 0.001);
        self::assertEqualsWithDelta(5000.00, $byAcc['518']['debit'], 0.001, 'Neurčená položka zůstává na 518.');
        self::assertEqualsWithDelta(30250.00, $byAcc['321']['credit'], 0.001);
    }

    /**
     * Zaokrouhlení: Σ rozpadu musí sedět na základ na haléř i u nedělitelného poměru.
     * Kdyby zbytek propadl, appendRounding by ho poslal na 548/648 a haléře by se tvářily
     * jako provozní náklad.
     */
    public function testSplitSumsExactlyToNetWithIndivisibleRatio(): void
    {
        $pf = $this->purchaseWithItems('PF-ROUND', [
            ['Tablet', 33.33, 7.00, 'small_asset'],
            ['Hosting', 33.33, 7.00, 'service'],
            ['Kabel', 33.34, 7.00, 'material'],
        ]);

        $byAcc = $this->postAndGetLines($pf);

        $expense = ($byAcc[$this->materialAccount]['debit'] ?? 0.0) + ($byAcc['518']['debit'] ?? 0.0);
        self::assertEqualsWithDelta(100.00, $expense, 0.001, 'Σ nákladových nohou = základ na haléř.');
        self::assertArrayNotHasKey('548', $byAcc, 'Zbytek se nesmí přelít na 548.');
        self::assertArrayNotHasKey('648', $byAcc, 'Zbytek se nesmí přelít na 648.');
    }

    /** Dobropis: rozpad musí obrátit strany stejně jako jedna noha. */
    public function testCreditNoteSplitsOnOppositeSide(): void
    {
        $pf = $this->purchaseWithItems('PF-CN', [
            ['Vrácený tablet', -10000.00, -2100.00, 'small_asset'],
            ['Vrácená doprava', -100.00, -21.00, 'service'],
        ], 'credit_note');

        $byAcc = $this->postAndGetLines($pf);

        self::assertEqualsWithDelta(10000.00, $byAcc[$this->materialAccount]['credit'], 0.001, 'Dobropis: náklad na D.');
        self::assertEqualsWithDelta(100.00, $byAcc['518']['credit'], 0.001);
        self::assertEqualsWithDelta(12221.00, $byAcc['321']['debit'], 0.001, 'Dobropis: závazek na MD.');
    }

    /** Dlouhodobý majetek na řádku jde na 042 (pořízení), ne do nákladů. */
    public function testFixedAssetItemPostsToAcquisitionAccount(): void
    {
        $pf = $this->purchaseWithItems('PF-DHM', [
            ['Stroj', 120000.00, 25200.00, 'fixed_asset'],
            ['Doprava stroje', 2000.00, 420.00, 'service'],
        ]);

        $byAcc = $this->postAndGetLines($pf);

        self::assertEqualsWithDelta(120000.00, $byAcc['042']['debit'], 0.001, 'DHM patří na 042, ne do 5xx.');
        self::assertEqualsWithDelta(2000.00, $byAcc['518']['debit'], 0.001);
    }

    /**
     * REGRESE (nahlásil uživatel — „chyba zaúčtování" na dobropisu Alzy):
     * skupina, která po slevách vyjde ZÁPORNĚ, patří na OPAČNOU stranu.
     *
     * Reálný doklad PF 105: monitor 34 919,26 → 501, ale řádky „Doručení" +37,19,
     * „Sleva na dopravné" −37,19 a „Služba Sleva" −100,00 dají na 518 dohromady −100,00.
     * Slepé abs() z toho udělalo 518 MD 100,00 místo D → zápis rozvážený o 200,00
     * a assertBalanced doklad odmítl zaúčtovat.
     */
    public function testNegativeGroupGoesToOppositeSideAndEntryStaysBalanced(): void
    {
        $pf = $this->purchaseWithItems('PF-NEGGRP', [
            ['Monitor 40" Dell', 34919.26, 7333.04, 'small_asset'],
            ['Doručení na prodejnu', 37.19, 7.81, 'service'],
            ['Sleva na dopravné', -37.19, -7.81, 'service'],
            ['Služba Sleva', -100.00, 0.00, 'service'],
        ]);

        $byAcc = $this->postAndGetLines($pf);

        self::assertEqualsWithDelta(34919.26, $byAcc[$this->materialAccount]['debit'], 0.001);
        self::assertEqualsWithDelta(100.00, $byAcc['518']['credit'], 0.001,
            'Záporná skupina patří na D, ne na MD — jinak se zápis rozváží o dvojnásobek.');
        self::assertEqualsWithDelta(0.0, $byAcc['518']['debit'], 0.001, 'Na MD u 518 nesmí být nic.');
        $this->assertEntryBalanced($byAcc);
    }

    /** Dobropis téhož tvaru: znaménka se překlopí, ale vyváženost musí platit taky. */
    public function testCreditNoteWithNegativeGroupStaysBalanced(): void
    {
        $pf = $this->purchaseWithItems('PF-NEGGRP-CN', [
            ['Monitor 40" Dell', -34919.26, -7333.04, 'small_asset'],
            ['Doručení na prodejnu', -37.19, -7.81, 'service'],
            ['Sleva na dopravné', 37.19, 7.81, 'service'],
            ['Služba Sleva', 100.00, 0.00, 'service'],
        ], 'credit_note');

        $byAcc = $this->postAndGetLines($pf);

        self::assertEqualsWithDelta(34919.26, $byAcc[$this->materialAccount]['credit'], 0.001);
        self::assertEqualsWithDelta(100.00, $byAcc['518']['debit'], 0.001,
            'U dobropisu je to zrcadlově — kladná skupina jde na MD.');
        $this->assertEntryBalanced($byAcc);
    }

    /** @param array<string,array{debit:float,credit:float}> $byAcc */
    private function assertEntryBalanced(array $byAcc): void
    {
        $md = 0.0;
        $d = 0.0;
        foreach ($byAcc as $sides) {
            $md += $sides['debit'];
            $d += $sides['credit'];
        }
        self::assertEqualsWithDelta($md, $d, 0.001, 'Σ MD musí být = Σ D.');
    }

    /**
     * Účet na řádku přebíjí odvození z druhu výdaje.
     *
     * Nález z produkce: účetní má pojistné na 548 „Ostatní provozní
     * náklady", my ho měli na 518. Má pravdu — vyhláška 500/2002 řadí pojistné na F.5. Jiné
     * provozní náklady, kdežto 518 je A.3. Služby, tedy jiný řádek VZZ. Druhem výdaje je
     * pojistné pořád SLUŽBA, takže `expense_kind` to vyřešit nemůže: CO to je a KAM to jde
     * jsou dvě různé otázky.
     */
    public function testItemAccountOverrideBeatsKindMapping(): void
    {
        $pf = $this->purchaseWithItems('PF-POJ', [
            ['Pojištění odpovědnosti', 15334.92, 0.00, 'service', '548'],
            ['Hosting', 1000.00, 210.00, 'service', null],
        ]);

        $byAcc = $this->postAndGetLines($pf);

        self::assertEqualsWithDelta(15334.92, $byAcc['548']['debit'], 0.001, 'Pojistné patří na 548, ne na 518.');
        self::assertEqualsWithDelta(1000.00, $byAcc['518']['debit'], 0.001, 'Bez override zůstává služba na 518.');
        self::assertEqualsWithDelta(16544.92, $byAcc['321']['credit'], 0.001, 'Závazek se override nesmí hnout.');
    }

    /** Override sám o sobě klasifikuje — i bez `expense_kind` musí rozpad proběhnout. */
    public function testItemAccountOverrideAloneTriggersSplit(): void
    {
        $pf = $this->purchaseWithItems('PF-ONLYACC', [
            ['Pojistné', 5000.00, 0.00, null, '548'],
            ['Hosting', 1000.00, 210.00, null, null],
        ]);

        $byAcc = $this->postAndGetLines($pf);

        self::assertEqualsWithDelta(5000.00, $byAcc['548']['debit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAcc['518']['debit'], 0.001, 'Neurčená položka padá na dosavadní default.');
    }

    /** Neplatný účet na řádku musí spadnout hlasitě, ne tiše zaúčtovat jinam. */
    public function testInvalidItemAccountOverrideIsRejected(): void
    {
        $pf = $this->purchaseWithItems('PF-BADACC', [
            // 321 je saldokontní účet závazků — jako náklad nedává smysl a guard ho zakazuje.
            ['Pokus o saldokonto', 1000.00, 210.00, 'service', '321'],
        ]);

        $this->expectException(\MyInvoice\Service\Accounting\PostingException::class);
        $this->posting->buildFromPurchaseInvoice($this->supplierId, $pf);
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /**
     * @param list<array{0:string,1:float,2:float,3:?string,4?:?string}> $items
     *        [popis, základ, DPH, expense_kind, expense_account_code]
     */
    private function purchaseWithItems(string $number, array $items, string $kind = 'invoice'): int
    {
        $base = 0.0;
        $vat = 0.0;
        foreach ($items as $it) {
            $base += $it[1];
            $vat += $it[2];
        }
        $with = round($base + $vat, 2);
        $issue = self::YEAR . '-06-10';
        $vendorId = $this->client('Dodavatel ' . $number);

        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "{}", ?, "full", ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, "received", "40", ?)'
        )->execute([$this->supplierId, $vendorId, $number, $kind, $issue, $issue, $issue, $issue,
            $this->currencyId, round($base, 2), round($vat, 2), $with, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();

        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, is_fixed_asset, expense_kind, expense_account_code)
             VALUES (?, ?, 1, 'ks', ?, ?, 21.00, ?, ?, ?, ?, '40', ?, ?, ?)"
        );
        foreach (array_values($items) as $i => $item) {
            [$desc, $itemBase, $itemVat, $expenseKind] = $item;
            $stmt->execute([
                $id, $desc, $itemBase, $this->vatRateId, $itemBase, $itemVat,
                round($itemBase + $itemVat, 2), $i,
                $expenseKind === 'fixed_asset' ? 1 : 0,
                $expenseKind,
                $item[4] ?? null,
            ]);
        }
        return $id;
    }

    /** @return array<string,array{debit:float,credit:float}> */
    private function postAndGetLines(int $purchaseInvoiceId): array
    {
        $lines = $this->posting->buildFromPurchaseInvoice($this->supplierId, $purchaseInvoiceId);
        $entryId = $this->posting->postDocument(
            $this->supplierId,
            'purchase_invoice',
            $purchaseInvoiceId,
            $lines,
            ['entry_date' => self::YEAR . '-06-10'],
        );
        return $this->linesByAccountCode($entryId);
    }
}
