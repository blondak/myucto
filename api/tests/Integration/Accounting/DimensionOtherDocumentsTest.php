<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\OtherDocumentsPostingScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Dimenze ostatních dokladů a karty majetku se promítnou do řádků deníku
 * (Účtování podle dimenzí, F4). Scénáře sdílí s regresním testem
 * {@see OtherDocumentsPostingRegressionTest}.
 */
#[Group('integration')]
final class DimensionOtherDocumentsTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private DimensionService $dimensions;
    private OtherDocumentsPostingScenarios $scenarios;
    /** @var array<string,int> */
    private array $types = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->db->pdo()->beginTransaction();
        $this->dimensions = $this->container->get(DimensionService::class);
        $this->scenarios = new OtherDocumentsPostingScenarios($this->container);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testDocumentAndCardDimensionsReachJournalLines(): void
    {
        $out = $this->runWithDimensions();

        foreach ($out['other_receivable'] as $line) {
            self::assertSame(['stredisko' => 'REGR-C1'], $line['dims'], 'Ostatní pohledávka: hlavička na všech řádcích (' . $line['account'] . ').');
        }
        foreach ($out['other_payable'] as $line) {
            self::assertSame(['projekt' => 'REGR-P1'], $line['dims']);
            self::assertSame(['stredisko' => ['REGR-C1' => 0.6, 'REGR-C2' => 0.4]], $line['splits'], 'Rozpad hlavičky 60/40 na řádku ' . $line['account'] . '.');
        }

        $offset = self::byAccount($out['offset']);
        self::assertSame(['projekt' => 'REGR-P1'], $offset['311']['dims'], 'Zápočet: pohledávka nese dimenze vydané faktury.');
        self::assertSame([], $offset['321']['dims'], 'Zápočet: závazek nese dimenze přijaté faktury (žádné).');

        foreach ($out['settlement'] as $line) {
            self::assertSame(['stredisko' => ['REGR-C1' => 0.6, 'REGR-C2' => 0.4]], $line['splits'], 'Zápočet proti účtu přebírá i rozpad faktury (' . $line['account'] . ').');
        }

        foreach (['asset_in_use', 'depreciation', 'depreciation_disposal_year', 'asset_disposal'] as $scenario) {
            self::assertNotEmpty($out[$scenario]);
            foreach ($out[$scenario] as $line) {
                self::assertSame(['projekt' => 'REGR-P1', 'stredisko' => 'REGR-C1'], $line['dims'], $scenario . ': dimenze karty na ' . $line['account'] . '.');
            }
        }

        foreach ($out['other_payable_bank'] as $line) {
            self::assertSame(['projekt' => 'REGR-P1'], $line['dims'], 'Bankovní úhrada přebírá hlavičku závazku (' . $line['account'] . ').');
        }
        foreach ($out['other_receivable_cash'] as $line) {
            self::assertSame(['stredisko' => 'REGR-C1'], $line['dims'], 'Pokladní úhrada přebírá hlavičku pohledávky.');
        }
        foreach ($out['other_schedule'] as $line) {
            self::assertSame(['stredisko' => 'REGR-C1'], $line['dims'], 'Výskyt rozvrhu nese dimenze zdroje.');
        }
        foreach ($out['asset_from_purchase'] as $line) {
            self::assertSame(['projekt' => 'REGR-P1', 'stredisko' => 'REGR-C2'], $line['dims'], 'Karta z položky: položka > hlavička faktury.');
        }
        foreach ([...$out['asset_sale_depreciation'], ...$out['asset_sale_disposal']] as $line) {
            self::assertSame(['stredisko' => 'REGR-C2'], $line['dims'], 'Prodej majetku fakturou: dimenze karty (' . $line['account'] . ').');
        }
    }

    public function testCardDimensionChangeRestampsPostedAssetEntries(): void
    {
        $this->scenarios->run();
        $this->loadTypes();
        $assetId = $this->scenarios->ids['asset'];
        $result = $this->dimensions->saveDocument($this->scenarios->supplierId, 'asset', $assetId, [], null, false,
            [0 => [$this->types['cost_center'] => [['value_id' => $this->value('C1'), 'share' => 0.6], ['value_id' => $this->value('C2'), 'share' => 0.4]]]]);
        // zařazení 2 + odpis 2 + odpis roku vyřazení 2 + vyřazení 4 řádky
        self::assertSame(10, $result['restamp']['lines']);
        self::assertFalse($result['restamp']['needs_repost']);
        foreach ($this->scenarios->sourceLines('asset_disposal', $assetId) as $line) {
            self::assertSame(['stredisko' => ['REGR-C1' => 0.6, 'REGR-C2' => 0.4]], $line['splits'], 'Vyřazení po změně karty: rozpad 60/40.');
        }
    }

    public function testRestampKeepsManualLineDimensionsOfOtherTypes(): void
    {
        $this->scenarios->run();
        $this->loadTypes();
        $supplierId = $this->scenarios->supplierId;
        $assetId = $this->scenarios->ids['asset'];
        $project = $this->types['project'];
        $cc = $this->types['cost_center'];

        // Ruční projekt na řádku odpisu 551 a na řádku zápočtu proti účtu (např. z doby před F4).
        $manual = $this->lineIds('depreciation', (int) $this->db->pdo()->query(
            "SELECT id FROM depreciation_entries WHERE asset_id = {$assetId} AND kind = 'accounting' ORDER BY fiscal_year LIMIT 1"
        )->fetchColumn(), '551');
        $settlementId = (int) $this->db->pdo()->query(
            'SELECT id FROM invoice_settlements WHERE doc_id = ' . $this->scenarios->ids['settled']
        )->fetchColumn();
        $manual = [...$manual, ...$this->lineIds('settlement', $settlementId, '355')];
        $assignments = $this->container->get(DimensionAssignmentRepository::class);
        foreach ($manual as $lineId) {
            $assignments->replaceLineDimensions($supplierId, $lineId, [$project => $this->value('P1', 'project')]);
        }

        // Uložení beze změny i se změnou jiného typu ruční projekt nesmaže.
        $this->dimensions->saveDocument($supplierId, 'asset', $assetId, [], null);
        $this->dimensions->saveDocument($supplierId, 'invoice', $this->scenarios->ids['settled'], [], null);
        foreach ($manual as $lineId) {
            self::assertSame([$project => $this->value('P1', 'project')], $this->lineDims($lineId), 'Ruční projekt zůstal (uložení bez dimenzí).');
        }
        $this->dimensions->saveDocument($supplierId, 'asset', $assetId, [$cc => $this->value('C2')], null);
        self::assertSame(self::sorted([$project => $this->value('P1', 'project'), $cc => $this->value('C2')]), $this->lineDims($manual[0]));

        // Typ, který karta přestane určovat, zmizí jen tam, kde je pořád hodnota karty.
        $this->dimensions->saveDocument($supplierId, 'asset', $assetId, [], null);
        self::assertSame([$project => $this->value('P1', 'project')], $this->lineDims($manual[0]));
    }

    public function testPurchaseInvoiceChangeRestampsCardsWithoutOwnDimensions(): void
    {
        $this->scenarios->run();
        $this->loadTypes();
        $supplierId = $this->scenarios->supplierId;
        $cc = $this->types['cost_center'];
        $purchase = $this->scenarios->ids['purchase'];

        $this->dimensions->saveDocument($supplierId, 'purchase_invoice', $purchase, [], [2 => [$cc => $this->value('C1')]]);
        foreach ($this->scenarios->sourceLines('asset', $this->scenarios->ids['asset_from_purchase']) as $line) {
            self::assertSame(['stredisko' => 'REGR-C1'], $line['dims'], 'Karta bez vlastních dimenzí se přerazítkuje z položky faktury.');
        }

        $this->dimensions->saveDocument($supplierId, 'asset', $this->scenarios->ids['asset_from_purchase'], [$cc => $this->value('C2')], null);
        $this->dimensions->saveDocument($supplierId, 'purchase_invoice', $purchase, [], null, false,
            [2 => [$cc => [['value_id' => $this->value('C1'), 'share' => 0.5], ['value_id' => $this->value('C3'), 'share' => 0.5]]]]);
        foreach ($this->scenarios->sourceLines('asset', $this->scenarios->ids['asset_from_purchase']) as $line) {
            self::assertSame(['stredisko' => 'REGR-C2'], $line['dims'], 'Karta s vlastními dimenzemi se fakturou nemění.');
        }
    }

    public function testCashPayingSeveralOtherItemsSplitsByAllocation(): void
    {
        $this->scenarios->run();
        $this->loadTypes();
        $supplierId = $this->scenarios->supplierId;
        $cc = $this->types['cost_center'];
        $other = $this->container->get(OtherItemService::class);
        $items = [];
        foreach ([[600.00, 'C1'], [400.00, 'C2']] as [$amount, $code]) {
            $item = $other->create($supplierId, [
                'side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetická pohledávka ' . $code,
                'partner_id' => $this->scenarios->ids['client'], 'issued_on' => OtherDocumentsPostingScenarios::YEAR . '-04-01',
                'due_on' => OtherDocumentsPostingScenarios::YEAR . '-04-30', 'currency' => 'CZK', 'amount' => $amount,
                'counter_account_code' => '602',
            ], null);
            $this->dimensions->saveDocument($supplierId, 'other_item', (int) $item['id'], [$cc => $this->value($code)], null);
            $other->post($supplierId, (int) $item['id'], null);
            $items[] = [(int) $item['id'], $amount];
        }
        $date = OtherDocumentsPostingScenarios::YEAR . '-04-15';
        $cashId = $this->scenarios->cashDocument(1000.00, $date);
        $this->container->get(PostingService::class)->postDocument($supplierId, 'cash', $cashId, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 1000.00],
            ['account_code' => '315', 'side' => 'credit', 'amount' => 600.00],
            ['account_code' => '315', 'side' => 'credit', 'amount' => 400.00],
        ], ['entry_date' => $date, 'posted' => true]);
        foreach ($items as [$itemId, $amount]) {
            $other->allocate($supplierId, $itemId, ['cash_document_id' => $cashId, 'amount' => $amount], null);
        }

        $lines = $this->scenarios->sourceLines('cash', $cashId);
        self::assertSame(['stredisko' => ['REGR-C1' => 0.6, 'REGR-C2' => 0.4]], $lines[0]['splits'], 'Pokladna 211: rozpad podle alokací.');
        self::assertSame(['stredisko' => 'REGR-C1'], $lines[1]['dims'], 'Řádek 315 alokace první položky.');
        self::assertSame(['stredisko' => 'REGR-C2'], $lines[2]['dims'], 'Řádek 315 alokace druhé položky.');
    }

    public function testBankUnallocationReleasesOnlyItemDimensions(): void
    {
        $out = $this->runWithDimensions();
        self::assertNotEmpty($out['other_payable_bank']);
        $supplierId = $this->scenarios->supplierId;
        $txId = $this->scenarios->ids['bank'];
        $lineIds = $this->lineIds('bank', $txId, '221');
        $assignments = $this->container->get(DimensionAssignmentRepository::class);
        $assignments->replaceLineDimensions($supplierId, $lineIds[0],
            [$this->types['project'] => $this->value('P1', 'project'), $this->types['cost_center'] => $this->value('C3')]);

        $other = $this->container->get(OtherItemService::class);
        $payable = $this->scenarios->ids['other_payable'];
        $other->unallocate($supplierId, $payable, (int) $other->allocations($supplierId, $payable)[0]['id']);
        self::assertSame([$this->types['cost_center'] => $this->value('C3')], $this->lineDims($lineIds[0]), 'Ruční středisko zůstává, převzatý projekt odejde.');
        foreach ($this->scenarios->sourceLines('bank', $txId) as $line) {
            if ($line['account'] === '325') {
                self::assertSame([], $line['dims'], 'Po odpojení úhrady dimenze položky z pohybu zmizí.');
            }
        }
    }

    public function testScheduleDraftsFollowSourceDimensions(): void
    {
        $this->scenarios->run();
        $this->loadTypes();
        $supplierId = $this->scenarios->supplierId;
        $cc = $this->types['cost_center'];
        $source = $this->scenarios->ids['other_payable'];
        $schedules = $this->container->get(OtherItemScheduleService::class);
        $schedule = $schedules->create($supplierId, $source, ['frequency' => 'monthly'], null);
        $draft = $schedules->generate($supplierId, (int) $schedule['id'], OtherDocumentsPostingScenarios::YEAR . '-03-15', null)['created_ids'][0];

        $this->dimensions->saveDocument($supplierId, 'other_item', $source, [$cc => $this->value('C3')], null);
        self::assertSame([$cc => $this->value('C3')], $this->dimensions->documentDimensions($supplierId, 'other_item', $draft)['header'],
            'Koncept výskytu převezme změnu dimenzí zdroje.');
    }

    /** @return array<string,mixed> */
    private function runWithDimensions(): array
    {
        $pdo = $this->db->pdo();
        return $this->scenarios->run(function (string $scenario, int $docId, OtherDocumentsPostingScenarios $ctx) use ($pdo): void {
            $this->loadTypes();
            $cc = $this->types['cost_center'];
            $project = $this->types['project'];
            $supplierId = $ctx->supplierId;
            $split = [0 => [$cc => [['value_id' => $this->value('C1'), 'share' => 0.6], ['value_id' => $this->value('C2'), 'share' => 0.4]]]];
            match ($scenario) {
                'other_receivable' => $this->dimensions->saveDocument($supplierId, 'other_item', $docId, [$cc => $this->value('C1')], null),
                'other_payable' => $this->dimensions->saveDocument($supplierId, 'other_item', $docId, [$project => $this->value('P1', 'project')], null, false, $split),
                'offset' => $this->dimensions->saveDocument($supplierId, 'invoice', (int) $pdo->query(
                    "SELECT doc_id FROM offset_agreement_items WHERE agreement_id = {$docId} AND doc_type = 'invoice'"
                )->fetchColumn(), [$project => $this->value('P1', 'project')], null),
                'settlement' => $this->dimensions->saveDocument($supplierId, 'invoice', $docId, [], null, false, $split),
                'asset' => $this->dimensions->saveDocument($supplierId, 'asset', $docId, [$cc => $this->value('C1'), $project => $this->value('P1', 'project')], null),
                'asset_from_purchase' => $this->dimensions->saveDocument($supplierId, 'purchase_invoice', $docId,
                    [$project => $this->value('P1', 'project'), $cc => $this->value('C1')], [2 => [$cc => $this->value('C2')]]),
                'asset_sale' => $this->dimensions->saveDocument($supplierId, 'asset', $docId, [$cc => $this->value('C2')], null),
                default => null,
            };
        });
    }

    private function loadTypes(): void
    {
        $this->types = $this->scenarios->types();
    }

    private function value(string $code, string $kind = 'cost_center'): int
    {
        return $this->scenarios->value($this->types[$kind], 'REGR-' . $code);
    }

    /** @return list<int> */
    private function lineIds(string $sourceType, int $sourceId, string $account): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ? AND je.reversed_by IS NULL AND a.account_code = ?
              ORDER BY l.id'
        );
        $stmt->execute([$this->scenarios->supplierId, $sourceType, $sourceId, $account]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<int,int> */
    private function lineDims(int $lineId): array
    {
        $dims = $this->container->get(DimensionAssignmentRepository::class)->lineDimensions($this->scenarios->supplierId, [$lineId]);
        $out = $dims[$lineId] ?? [];
        ksort($out);
        return $out;
    }

    /**
     * @param array<int,int> $dims
     * @return array<int,int>
     */
    private static function sorted(array $dims): array
    {
        ksort($dims);
        return $dims;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array<string,array<string,mixed>>
     */
    private static function byAccount(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[$line['account']] = $line;
        }
        return $out;
    }
}
