<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Změna druhu dokladu u DDKP (daňový doklad k poskytnuté záloze, § 28 ZDPH).
 *
 * Regrese: druh `tax_document` byl v {@see PurchaseInvoiceRepository::updateDraft()}
 * NEMĚNNÝ a přepisoval se TIŠE zpátky — uživatel v editoru přepnul špatně
 * naimportovaný doklad na „fakturu", uložil a dostal zpátky pořád DDKP.
 * Nově smí DDKP odejít, pokud na něm nevisí vazba; vázaný DDKP hlasitě selže.
 *
 * Kryje i druhou půlku téhož zápisu: částečný PUT bez klíče `document_kind`
 * překlápěl doklad na 'invoice' jen proto, že klíč v těle nebyl.
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 */
#[Group('integration')]
final class PurchaseTaxDocumentKindChangeTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container  = Bootstrap::buildApp()->getContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('UPDATE purchase_invoices
                              SET parent_purchase_invoice_id = NULL, advance_purchase_invoice_id = NULL
                            WHERE id = ?')->execute([$id]);
        }
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /**
     * Jádro regrese: samostatný DDKP bez jediné vazby (přesně to, co po sobě nechá
     * chybná AI klasifikace obyčejné faktury) musí jít přepnout zpátky na fakturu.
     */
    public function testStandaloneTaxDocumentCanBeSwitchedBackToInvoice(): void
    {
        $vendor = $this->vendor('DDKP dodavatel volný', 'CZ21000101');
        $id = $this->repo->createDraft($this->payload($vendor, 'DDKP-FREE', 'tax_document'), $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        self::assertTrue($this->repo->updateDraft($id, $this->payload($vendor, 'DDKP-FREE', 'invoice'), $this->supplierId));
        self::assertSame('invoice', $this->storedKind($id),
            'samostatný DDKP bez vazeb musí jít opravit na fakturu — jinak se špatná AI klasifikace nedá napravit');
    }

    /** Rychlá změna ze seznamu (#232) běží po témže SSOT. */
    public function testUpdateDocumentKindAllowsStandaloneTaxDocument(): void
    {
        $vendor = $this->vendor('DDKP dodavatel seznam', 'CZ21000102');
        $id = $this->repo->createDraft($this->payload($vendor, 'DDKP-LIST', 'tax_document'), $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        self::assertNull($this->repo->updateDocumentKind($id, $this->supplierId, 'invoice'));
        self::assertSame('invoice', $this->storedKind($id));
    }

    /** DDKP navázaný na zálohovou fakturu drží odpočet DPH ze zálohy — změna musí selhat. */
    public function testTaxDocumentLinkedToAdvanceIsBlockedLoudly(): void
    {
        $vendor  = $this->vendor('DDKP dodavatel vázaný', 'CZ21000103');
        $advance = $this->repo->createDraft($this->payload($vendor, 'DDKP-ADV', 'advance'), $this->userId, $this->supplierId);
        $ddkp    = $this->repo->createDraft($this->payload($vendor, 'DDKP-CHILD', 'tax_document'), $this->userId, $this->supplierId);
        $this->piIds[] = $advance;
        $this->piIds[] = $ddkp;
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$advance, $ddkp]);

        self::assertNotNull($this->repo->taxDocumentKindChangeBlocker($ddkp, $this->supplierId));
        self::assertNotNull($this->repo->updateDocumentKind($ddkp, $this->supplierId, 'invoice'));

        try {
            $this->repo->updateDraft($ddkp, $this->payload($vendor, 'DDKP-CHILD', 'invoice'), $this->supplierId);
            self::fail('vázaný DDKP musí selhat výjimkou, ne tiše přepsat druh zpět');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('zálohov', $e->getMessage());
        }
        self::assertSame('tax_document', $this->storedKind($ddkp));
    }

    /** Přes samostatný DDKP se dá vyúčtovat konečná faktura — pak je taky zamčený. */
    public function testTaxDocumentUsedAsAdvanceBySettlementIsBlocked(): void
    {
        $vendor = $this->vendor('DDKP dodavatel vyúčtovaný', 'CZ21000104');
        $ddkp   = $this->repo->createDraft($this->payload($vendor, 'DDKP-USED', 'tax_document'), $this->userId, $this->supplierId);
        $final  = $this->repo->createDraft($this->payload($vendor, 'DDKP-FINAL', 'invoice'), $this->userId, $this->supplierId);
        $this->piIds[] = $ddkp;
        $this->piIds[] = $final;
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ?')
            ->execute([$ddkp, $final]);

        self::assertNotNull($this->repo->taxDocumentKindChangeBlocker($ddkp, $this->supplierId));
        self::assertNotNull($this->repo->updateDocumentKind($ddkp, $this->supplierId, 'invoice'));
        self::assertSame('tax_document', $this->storedKind($ddkp));
    }

    /**
     * Částečný PUT (bez klíče `document_kind`) nesmí druh dokladu přepsat — dřív
     * ho `?? 'invoice'` překlopilo u zálohy, účtenky i dobropisu.
     */
    public function testPartialUpdateWithoutDocumentKindKeepsStoredKind(): void
    {
        $vendor = $this->vendor('Částečný PUT dodavatel', 'CZ21000105');
        $id = $this->repo->createDraft($this->payload($vendor, 'DDKP-PARTIAL', 'advance'), $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        $body = $this->payload($vendor, 'DDKP-PARTIAL', 'advance');
        unset($body['document_kind']);
        self::assertTrue($this->repo->updateDraft($id, $body, $this->supplierId));
        self::assertSame('advance', $this->storedKind($id),
            'PUT bez document_kind nesmí druh dokladu měnit');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function storedKind(int $piId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT document_kind FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$piId]);
        return (string) $stmt->fetchColumn();
    }

    private function vendor(string $name, string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    /** @return array<string,mixed> */
    private function payload(int $vendorId, string $number, string $kind): array
    {
        return [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number,
            'document_kind'         => $kind,
            'issue_date'            => '2099-06-10',
            'tax_date'              => '2099-06-10',
            'due_date'              => '2099-06-24',
            'currency_id'           => $this->currencyId,
        ];
    }
}
