<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\OtherDocumentsPostingScenarios;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Dimenze ostatních dokladů a karty majetku se promítnou do řádků deníku
 * (Účtování podle dimenzí, F4): ostatní pohledávka a závazek (hlavička i rozpad),
 * vzájemný zápočet po stranách, zápočet proti účtu z faktury, karta majetku do
 * zařazení, odpisů a vyřazení. Scénáře sdílí s regresním testem
 * {@see OtherDocumentsPostingRegressionTest}.
 */
#[Group('integration')]
final class DimensionOtherDocumentsTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->db->pdo()->beginTransaction();
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
        $dimensions = $this->container->get(DimensionService::class);
        $pdo = $this->db->pdo();
        $out = (new OtherDocumentsPostingScenarios($this->container))->run(
            function (string $scenario, int $docId, OtherDocumentsPostingScenarios $ctx) use ($dimensions, $pdo): void {
                $types = $ctx->types();
                $cc = $types['cost_center'];
                $c1 = $ctx->value($cc, 'REGR-C1');
                $c2 = $ctx->value($cc, 'REGR-C2');
                $p1 = $ctx->value($types['project'], 'REGR-P1');
                match ($scenario) {
                    'other_receivable' => $dimensions->saveDocument($ctx->supplierId, 'other_item', $docId, [$cc => $c1], null),
                    'other_payable' => $dimensions->saveDocument($ctx->supplierId, 'other_item', $docId, [$types['project'] => $p1], null, false,
                        [0 => [$cc => [['value_id' => $c1, 'share' => 0.6], ['value_id' => $c2, 'share' => 0.4]]]]),
                    'offset' => $dimensions->saveDocument($ctx->supplierId, 'invoice', (int) $pdo->query(
                        "SELECT doc_id FROM offset_agreement_items WHERE agreement_id = {$docId} AND doc_type = 'invoice'"
                    )->fetchColumn(), [$types['project'] => $p1], null),
                    'settlement' => $dimensions->saveDocument($ctx->supplierId, 'invoice', $docId, [$cc => $c2], null),
                    'asset' => $dimensions->saveDocument($ctx->supplierId, 'asset', $docId, [$cc => $c1, $types['project'] => $p1], null),
                    default => null,
                };
            },
        );

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
            self::assertSame(['stredisko' => 'REGR-C2'], $line['dims'], 'Zápočet proti účtu přebírá dimenze faktury (' . $line['account'] . ').');
        }

        foreach (['asset_in_use', 'depreciation', 'depreciation_disposal_year', 'asset_disposal'] as $scenario) {
            self::assertNotEmpty($out[$scenario]);
            foreach ($out[$scenario] as $line) {
                self::assertSame(['projekt' => 'REGR-P1', 'stredisko' => 'REGR-C1'], $line['dims'], $scenario . ': dimenze karty na ' . $line['account'] . '.');
            }
        }
        self::assertSame('551', $out['depreciation'][0]['account']);
    }

    public function testCardDimensionChangeRestampsPostedAssetEntries(): void
    {
        $scenarios = new OtherDocumentsPostingScenarios($this->container);
        $assetId = 0;
        $scenarios->run(function (string $scenario, int $docId) use (&$assetId): void {
            if ($scenario === 'asset') {
                $assetId = $docId;
            }
        });
        $dimensions = $this->container->get(DimensionService::class);
        $types = $scenarios->types();
        $c1 = $scenarios->value($types['cost_center'], 'REGR-C1');
        $c2 = $scenarios->value($types['cost_center'], 'REGR-C2');
        $result = $dimensions->saveDocument($scenarios->supplierId, 'asset', $assetId, [], null, false,
            [0 => [$types['cost_center'] => [['value_id' => $c1, 'share' => 0.6], ['value_id' => $c2, 'share' => 0.4]]]]);
        // zařazení 2 + odpis 2 + odpis roku vyřazení 2 + vyřazení 4 řádky
        self::assertSame(10, $result['restamp']['lines']);
        self::assertFalse($result['restamp']['needs_repost']);

        $stmt = $this->db->pdo()->prepare(
            "SELECT je.id FROM journal_entries je
               JOIN depreciation_entries de ON de.id = je.source_id AND je.source_type = 'depreciation'
              WHERE de.asset_id = ? AND je.reversed_by IS NULL ORDER BY je.id LIMIT 1"
        );
        $stmt->execute([$assetId]);
        foreach ($scenarios->entryLines((int) $stmt->fetchColumn()) as $line) {
            self::assertSame(['stredisko' => ['REGR-C1' => 0.6, 'REGR-C2' => 0.4]], $line['splits'], 'Odpis po změně karty: rozpad 60/40.');
        }
    }

    public function testScheduleOccurrenceCopiesSourceDimensions(): void
    {
        $scenarios = new OtherDocumentsPostingScenarios($this->container);
        $receivable = 0;
        $scenarios->run(function (string $scenario, int $docId) use (&$receivable): void {
            if ($scenario === 'other_receivable') {
                $receivable = $docId;
            }
        });
        $types = $scenarios->types();
        $c2 = $scenarios->value($types['cost_center'], 'REGR-C2');
        $dimensions = $this->container->get(DimensionService::class);
        $dimensions->saveDocument($scenarios->supplierId, 'other_item', $receivable, [$types['cost_center'] => $c2], null);

        $schedules = $this->container->get(OtherItemScheduleService::class);
        $schedule = $schedules->create($scenarios->supplierId, $receivable, ['frequency' => 'monthly'], null);
        $generated = $schedules->generate($scenarios->supplierId, (int) $schedule['id'], OtherDocumentsPostingScenarios::YEAR . '-03-15', null);
        self::assertCount(1, $generated['created_ids']);
        $next = $generated['created_ids'][0];
        self::assertSame([$types['cost_center'] => $c2], $dimensions->documentDimensions($scenarios->supplierId, 'other_item', $next)['header']);

        $posted = $this->container->get(OtherItemService::class)->post($scenarios->supplierId, $next, null);
        foreach ($scenarios->entryLines((int) $posted['journal_entry_id']) as $line) {
            self::assertSame(['stredisko' => 'REGR-C2'], $line['dims']);
        }
    }

    public function testBankPaymentTakesOtherItemDimensionsOnAllocation(): void
    {
        $scenarios = new OtherDocumentsPostingScenarios($this->container);
        $payable = 0;
        $scenarios->run(function (string $scenario, int $docId) use (&$payable): void {
            if ($scenario === 'other_payable') {
                $payable = $docId;
            }
        });
        $types = $scenarios->types();
        $c1 = $scenarios->value($types['cost_center'], 'REGR-C1');
        $this->container->get(DimensionService::class)
            ->saveDocument($scenarios->supplierId, 'other_item', $payable, [$types['cost_center'] => $c1], null);

        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date, currency)
             VALUES (?, "synteticky-vypis-f4", ?, "1000000005/0100", ?, "CZK")'
        )->execute([$scenarios->supplierId, hash('sha256', uniqid('', true)), OtherDocumentsPostingScenarios::YEAR . '-02-20']);
        $pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, match_status, counterparty_name, description)
             VALUES (?, ?, -1200, "CZK", "unmatched", "Syntetický pronajímatel", "Nájem")'
        )->execute([(int) $pdo->lastInsertId(), OtherDocumentsPostingScenarios::YEAR . '-02-20']);
        $txId = (int) $pdo->lastInsertId();
        $entryId = $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($scenarios->supplierId, 'bank', $txId, [
            ['account_code' => '325', 'side' => 'debit', 'amount' => 1200],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1200],
        ], ['entry_date' => OtherDocumentsPostingScenarios::YEAR . '-02-20', 'posted' => true]);
        foreach ($scenarios->entryLines($entryId) as $line) {
            self::assertSame([], $line['dims'], 'Před spárováním pohyb dimenze nemá.');
        }

        $service = $this->container->get(OtherItemService::class);
        $service->allocate($scenarios->supplierId, $payable, ['bank_transaction_id' => $txId, 'amount' => 1200], null);
        foreach ($scenarios->entryLines($entryId) as $line) {
            self::assertSame(['stredisko' => 'REGR-C1'], $line['dims'], 'Spárovaná úhrada přebírá dimenze položky (' . $line['account'] . ').');
        }

        $allocationId = (int) $service->allocations($scenarios->supplierId, $payable)[0]['id'];
        $service->unallocate($scenarios->supplierId, $payable, $allocationId);
        foreach ($scenarios->entryLines($entryId) as $line) {
            self::assertSame([], $line['dims'], 'Po odpojení úhrady dimenze z pohybu zmizí.');
        }
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
