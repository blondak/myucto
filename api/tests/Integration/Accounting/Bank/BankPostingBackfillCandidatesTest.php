<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Brána proti rozejití SQL předfiltru a guardu služby.
 *
 * `BankPostingBackfill::candidates()` nabízí UŽ ZAÚČTOVANOU transakci znovu jen kvůli
 * srovnání haléřového zbytku; rozhoduje ale výhradně
 * {@see \MyInvoice\Service\Accounting\Bank\BankPostingService::normalizeRoundingFullPurchase()}.
 * SQL proto musí být NADMNOŽINOU guardu — jinak se doklad nikdy nedozaučtuje a haléře
 * zůstanou viset na 321, aniž by to cokoli nahlásilo.
 *
 * Přesně tak vzniklo 28 nedoúčtovaných dokladů na reálných datech: SQL znalo jen
 * `auto_partial` + `match_type='auto'` + `confidence >= 70`, zatímco služba bere i
 * `auto_exact` a `manual` (ruční párování confidence nemá — důkazem je člověk).
 * Obě strany se rozešly tiše, protože skip 'already_posted' vypadá nevinně.
 */
#[Group('integration')]
final class BankPostingBackfillCandidatesTest extends BankPostingTestCase
{
    private function from(): string
    {
        return self::YEAR . '-01-01';
    }

    /**
     * Přijatá faktura na 1 000,00 zaplacená 999,50 → na 321 visí 0,50, doklad je
     * ale zaúčtovaný. Přesně stav, který má normalizace vyřešit.
     *
     * @return array{tx:int, purchase:int, entry:int}
     */
    private function roundingLeftover(string $tag, string $matchStatus, string $matchType, ?int $confidence): array
    {
        $vendor = $this->client('Dodavatel ' . $tag);
        $purchase = $this->purchaseInvoice('PF-' . $tag, $vendor, 1000.00);
        $this->postPredpis('purchase_invoice', $purchase, '518', '321', 1000.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -999.50, ['match_status' => $matchStatus]);
        $this->db->pdo()->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
             VALUES (?, ?, ?, 999.50, ?, ?)'
        )->execute([$this->supplierId, $tx, $purchase, $matchType, $confidence]);

        return ['tx' => $tx, 'purchase' => $purchase, 'entry' => $this->postPredpis('bank', $tx, '321', '221', 999.50)];
    }

    /**
     * Kombinace, které guard služby PŘIJÍMÁ → SQL je musí nabídnout.
     *
     * @return array<string, array{0:string, 1:string, 2:int|null}>
     */
    public static function acceptedCases(): array
    {
        return [
            // auto_exact: rozdíl do 0,05 fakturu označí jako paid, ale alokace drží
            // částku transakce → haléře zůstanou na 321. Týž jev jako auto_partial.
            'auto_exact / auto / 95'   => ['auto_exact', 'auto', 95],
            'auto_partial / auto / 70' => ['auto_partial', 'auto', 70],
            'auto_partial / auto / 95' => ['auto_partial', 'auto', 95],
            // ruční párování: confidence NULL, důkazem je člověk
            'manual / manual / NULL'   => ['manual', 'manual', null],
            'auto_partial / manual / NULL' => ['auto_partial', 'manual', null],
        ];
    }

    #[DataProvider('acceptedCases')]
    public function testAcceptedByServiceIsOfferedByCandidateQuery(
        string $matchStatus,
        string $matchType,
        ?int $confidence,
    ): void {
        $tag = strtoupper($matchStatus . '-' . $matchType . '-' . ($confidence ?? 'NULL'));
        $case = $this->roundingLeftover($tag, $matchStatus, $matchType, $confidence);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(1, $report['normalized_full'], 'SQL předfiltr musí tuhle kombinaci nabídnout');
        self::assertSame(1, $report['posted']);
        self::assertSame(1, $this->entryCountForTx($case['tx']), 'zápis se přepíše, neduplikuje');

        // Alokace srovnaná na nominál → doklad uzavřen, zbytek na 321 zmizel.
        self::assertEqualsWithDelta(1000.00, (float) $this->db->pdo()->query(
            'SELECT amount FROM payment_matches WHERE bank_transaction_id = ' . $case['tx']
        )->fetchColumn(), 0.001);
        self::assertSame('paid', $this->db->pdo()->query(
            'SELECT status FROM purchase_invoices WHERE id = ' . $case['purchase']
        )->fetchColumn());

        $lines = $this->linesByAccountCode($case['entry']);
        self::assertEqualsWithDelta(1000.00, $lines['321']['debit'], 0.001, '321 se uzavře nominálem');
        self::assertEqualsWithDelta(999.50, $lines['221']['credit'], 0.001, '221 drží skutečně zaplacené');
        self::assertEqualsWithDelta(0.50, $lines['648']['credit'], 0.001, 'rozdíl je výnos ze zaokrouhlení');
    }

    /**
     * Confidence 65 = shoda jen podle částky a data, bez VS i názvu — matcher ji
     * záměrně značí „ke kontrole". Slabý důkaz nesmí sám uzavřít doklad, takže
     * tenhle případ MÁ zůstat nedotčený; potvrdit ho musí člověk.
     */
    public function testWeakAmountDateMatchIsNotNormalized(): void
    {
        $case = $this->roundingLeftover('WEAK65', 'auto_partial', 'auto', 65);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(0, $report['normalized_full'], 'confidence 65 je pod prahem 70 — nesmí se srovnat');
        self::assertSame(0, $report['posted']);
        self::assertEqualsWithDelta(999.50, (float) $this->db->pdo()->query(
            'SELECT amount FROM payment_matches WHERE bank_transaction_id = ' . $case['tx']
        )->fetchColumn(), 0.001, 'alokace zůstane na částce transakce');

        $lines = $this->linesByAccountCode($case['entry']);
        self::assertEqualsWithDelta(999.50, $lines['321']['debit'], 0.001, 'zápis se nepřepsal');
        self::assertArrayNotHasKey('648', $lines, 'bez potvrzení se zaokrouhlení neúčtuje');
    }

    /**
     * Split platba (2+ alokace na jednu tx) je legitimní stav, kde není co srovnávat —
     * guard služby ji odmítá a SQL ji nesmí protlačit dál.
     */
    public function testSplitAllocationIsNotNormalized(): void
    {
        $vendor = $this->client('Dodavatel SPLIT');
        $first = $this->purchaseInvoice('PF-SPLIT-1', $vendor, 600.00);
        $second = $this->purchaseInvoice('PF-SPLIT-2', $vendor, 400.00);
        $this->postPredpis('purchase_invoice', $first, '518', '321', 600.00);
        $this->postPredpis('purchase_invoice', $second, '518', '321', 400.00);

        $stmt = $this->statement();
        $tx = $this->transaction($stmt, -999.50, ['match_status' => 'auto_partial']);
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
             VALUES (?, ?, ?, ?, "auto", 95)'
        );
        $ins->execute([$this->supplierId, $tx, $first, 599.50]);
        $ins->execute([$this->supplierId, $tx, $second, 400.00]);
        $this->postPredpis('bank', $tx, '321', '221', 999.50);

        $report = $this->backfill->run($this->supplierId, $this->from(), true, false);

        self::assertSame(0, $report['normalized_full'], 'víc alokací = split platba, hádat se nesmí');
    }
}
