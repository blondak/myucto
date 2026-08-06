<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Příznak „místo plnění k ručnímu posouzení" (migrace 1293) musí přežít uložení faktury.
 *
 * Import ho zapisoval, ale `InvoiceRepository::replaceItems()` položky maže a zakládá
 * znovu a `ossItemParams()` ten sloupec neznal — první uložení dokladu ho tedy zahodilo.
 * U migrace 1 670 dokladů tím celá kategorie „nedokázali jsme určit místo plnění" mizela
 * po prvním doteku faktury a nezbyla po ní stopa ani v reportu (ten je dávno zavřený),
 * ani v datech.
 *
 * Druhá polovina téhož: bez ČTENÍ sloupce nemá editor co poslat zpět, takže samotný zápis
 * by problém nevyřešil — proto se tu testuje celý round-trip přes `find()`.
 */
#[Group('integration')]
final class OssManualReviewPersistenceTest extends TestCase
{
    private Connection $db;
    private InvoiceRepository $repo;

    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;

    /** @var int[] */
    private array $createdInvoiceIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->db = $container->get(Connection::class);
            $this->repo = $container->get(InvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        if (!$this->db->hasColumn('invoice_items', 'oss_needs_manual_review')) {
            $this->markTestSkipped('Chybí migrace 1293');
        }

        $pdo = $this->db->pdo();

        $supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        if ($supplierId <= 0) {
            $this->markTestSkipped('Žádný supplier');
        }

        $stmt = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? AND archived_at IS NULL LIMIT 1');
        $stmt->execute([$supplierId]);
        $this->clientId = (int) $stmt->fetchColumn();
        if ($this->clientId <= 0) {
            $this->markTestSkipped("Supplier #{$supplierId} nemá klienty");
        }

        $stmt = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$supplierId]);
        $this->currencyId = (int) $stmt->fetchColumn();
        if ($this->currencyId <= 0) {
            $this->markTestSkipped('Supplier nemá aktivní měnu');
        }

        $this->vatRateId = (int) $pdo->query(
            'SELECT id FROM vat_rates
              WHERE is_reverse_charge = 0 AND rate_percent > 0
              ORDER BY is_default DESC, rate_percent DESC LIMIT 1'
        )->fetchColumn();
        if ($this->vatRateId <= 0) {
            $this->markTestSkipped('Žádná použitelná VAT sazba');
        }

        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->userId <= 0) {
            $this->markTestSkipped('Žádný uživatel');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->createdInvoiceIds !== []) {
            $pdo = $this->db->pdo();
            foreach ($this->createdInvoiceIds as $id) {
                $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
            }
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testFlaggedOssItemKeepsTheFlagAfterSave(): void
    {
        $id = $this->createInvoiceWith([$this->ossItem(true)]);

        self::assertSame(1, $this->storedFlag($id), 'Zápis příznak zahodil');
    }

    /**
     * Vlastní scénář F4: doklad se otevře a uloží znovu (uživatel opravil splatnost),
     * položky projdou `find()` → payload → `replaceItems()`. Před opravou se příznak
     * ztratil právě tady — buď proto, že ho detail nevracel, nebo proto, že ho zápis
     * neznal.
     */
    public function testFlagSurvivesReadBackAndResave(): void
    {
        $id = $this->createInvoiceWith([$this->ossItem(true)]);

        $items = $this->repo->find($id)['items'];
        self::assertTrue($items[0]['oss_needs_manual_review'] ?? null, 'Detail dokladu příznak nevrací');

        $this->repo->replaceItems($id, $items);

        self::assertSame(1, $this->storedFlag($id), 'Druhé uložení příznak zahodilo');
    }

    /**
     * Vypnutí OSS je rozhodnutí ČLOVĚKA a dělá ho ten, kdo se rozhodl: editor posílá
     * `oss_needs_manual_review: false` zároveň s vypnutým OSS. Repozitář takový payload
     * poslechne — příznak zhasne.
     */
    public function testTurningOssOffTogetherWithTheFlagClearsIt(): void
    {
        $id = $this->createInvoiceWith([$this->ossItem(true)]);

        $item = $this->repo->find($id)['items'][0];
        $item['oss_applicable'] = false;
        $item['oss_needs_manual_review'] = false;
        $this->repo->replaceItems($id, [$item]);

        self::assertSame(0, $this->storedFlag($id));
    }

    /**
     * Vlna 3 (HIGH): řádek MIMO OSS s rozsvíceným příznakem je jediné povolené „nevím"
     * nových kanálů — cron opakovaných faktur, iDoklad, Fakturoid i AI takhle ukládají
     * položku, u které derivace místo plnění neurčila a odmítnutí nesmí zastavit běh.
     * Zápis ho dřív zahazoval, takže z nerozhodnutého řádku byl v datech řádek rozhodnutý
     * a hromadná editace, která ho má najít, o něm nevěděla.
     */
    public function testNonOssItemKeepsTheFlagWhenTheChannelSaysItDoesNotKnow(): void
    {
        $item = $this->ossItem(true);
        $item['oss_applicable'] = false;
        $item['oss_consumer_country'] = null;
        $item['oss_rate_type'] = null;
        $item['oss_supply_type'] = null;

        $id = $this->createInvoiceWith([$item]);

        self::assertSame(1, $this->storedFlag($id),
            'Řádek mimo OSS přišel s „nevím" a zápis ho zahodil — nejistota tím zmizela z dat.');
    }

    /**
     * OSS řádek se do českého přiznání ani do KH nevykazuje, takže tuzemský klasifikační
     * kód by byl mrtvá metadata — a hlavně: po zhasnutí `oss_applicable` už ho filtr
     * `VatLedgerService` nedrží a s dosazenou '1' by cizí daň dopadla na ř. 1.
     */
    public function testOssItemDoesNotGetTheDomesticClassificationCode(): void
    {
        $id = $this->createInvoiceWith([$this->ossItem(false)]);

        self::assertNull($this->storedClassificationCode($id),
            'OSS řádku se dosadil tuzemský klasifikační kód — po vypnutí OSS by šel na ř. 1.');
    }

    /** Kontrolní protějšek: mimo OSS se default dosazovat MUSÍ, jinak řádek zmizí z výkazů. */
    public function testDomesticItemStillGetsTheClassificationCode(): void
    {
        $item = $this->ossItem(false);
        $item['oss_applicable'] = false;
        $item['oss_consumer_country'] = null;

        $id = $this->createInvoiceWith([$item]);

        self::assertNotNull($this->storedClassificationCode($id));
    }

    public function testUnflaggedOssItemStaysUnflagged(): void
    {
        $id = $this->createInvoiceWith([$this->ossItem(false)]);

        self::assertSame(0, $this->storedFlag($id));
    }

    /** @return array<string,mixed> */
    private function ossItem(bool $needsReview): array
    {
        return [
            'description'            => 'TEST OSS položka (PHPUnit)',
            'quantity'               => 1,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 1000,
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
            'oss_applicable'         => true,
            'oss_consumer_country'   => 'PL',
            'oss_rate_type'          => 'standard',
            'oss_supply_type'        => 'services',
            'oss_needs_manual_review' => $needsReview,
        ];
    }

    private function storedClassificationCode(int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_classification_code FROM invoice_items
              WHERE invoice_id = ? ORDER BY order_index, id LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @param list<array<string,mixed>> $items */
    private function createInvoiceWith(array $items): int
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $id = $this->repo->createDraft([
            'invoice_type'   => 'invoice',
            'client_id'      => $this->clientId,
            'issue_date'     => $today,
            'tax_date'       => $today,
            'due_date'       => $today,
            'currency_id'    => $this->currencyId,
            'reverse_charge' => false,
            'language'       => 'cs',
        ], $this->userId);
        $this->createdInvoiceIds[] = $id;
        $this->repo->replaceItems($id, $items);

        return $id;
    }

    private function storedFlag(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oss_needs_manual_review FROM invoice_items
              WHERE invoice_id = ? ORDER BY order_index, id LIMIT 1'
        );
        $stmt->execute([$invoiceId]);

        return (int) $stmt->fetch(PDO::FETCH_COLUMN);
    }
}
