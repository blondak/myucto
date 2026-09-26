<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\JournalDescriptionBuilder;
use MyInvoice\Service\Accounting\JournalDescriptionRebuilder;
use MyInvoice\Service\ActivityLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Skládání popisu z DAT DOKLADU a jeho zpětné dogenerování.
 *
 * Nález z praxe: deník převzatý z jiného systému nesl v popisu jediné pole zdroje
 * („Fakturujeme Vám za …"), takže desítky vydaných faktur různým odběratelům měly
 * v deníku SHODNÝ text a zápisy se nedaly rozlišit. Tyhle testy drží, že:
 *  - popis obsahuje číslo dokladu i protistranu, takže dva doklady s týmž textem
 *    dají RŮZNÝ popis,
 *  - původní text se nezahodí — zůstane jako poslední segment,
 *  - dogenerování se nedotkne ručních zápisů ani popisu, který změnil uživatel,
 *  - opakované spuštění už nic nezmění (idempotence).
 *
 * Vše v jedné transakci, tearDown rollbackne. Data jsou syntetická.
 */
#[Group('integration')]
final class JournalDescriptionRebuildTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private JournalDescriptionBuilder $builder;
    private JournalDescriptionRebuilder $rebuilder;
    private JournalEntryRepository $journal;
    private ActivityLogger $activity;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db        = $container->get(Connection::class);
            $this->builder   = $container->get(JournalDescriptionBuilder::class);
            $this->rebuilder = $container->get(JournalDescriptionRebuilder::class);
            $this->journal   = $container->get(JournalEntryRepository::class);
            $this->activity  = $container->get(ActivityLogger::class);
            $periods         = $container->get(AccountingPeriodRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0
            || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->periodId = $periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── skládání z dokladu ──────────────────────────────────────────────────────

    /**
     * JÁDRO NÁLEZU: dvě vydané faktury se STEJNÝM textem položky, ale různým
     * odběratelem. Popis z textu položky byl u obou shodný; ze složeného popisu
     * musí být doklad i protistrana poznat.
     */
    public function testTwoInvoicesWithIdenticalItemTextGetDistinguishableDescriptions(): void
    {
        $first  = $this->sale('FV-2098-001', $this->client('Alfa Nájemce s.r.o.'), 'pronájem místa');
        $second = $this->sale('FV-2098-002', $this->client('Beta Nájemce s.r.o.'), 'pronájem místa');

        $a = $this->builder->forInvoice($this->supplierId, $first);
        $b = $this->builder->forInvoice($this->supplierId, $second);

        self::assertSame('FV FV-2098-001 — Alfa Nájemce s.r.o. — pronájem místa', $a);
        self::assertSame('FV FV-2098-002 — Beta Nájemce s.r.o. — pronájem místa', $b);
        self::assertNotSame($a, $b, 'Zápisy dvou různých faktur se musí dát odlišit.');
    }

    public function testPurchaseInvoiceNamesOwnNumberVendorNumberAndVendor(): void
    {
        $vendorId = $this->client('Gama Leasing a.s.');
        $id = $this->purchase('PF-2098-0007', 'VF-2098-88', $vendorId, 'leasing vozidla');

        self::assertSame(
            'PF PF-2098-0007 / dod. VF-2098-88 — Gama Leasing a.s. — leasing vozidla',
            $this->builder->forPurchaseInvoice($this->supplierId, $id),
        );
    }

    /** Doklad jiného tenanta ani smazaný doklad se nikdy nepopíše. */
    public function testUnknownDocumentGivesNullSoCallerKeepsItsOwnText(): void
    {
        self::assertNull($this->builder->forInvoice($this->supplierId, 987654321));
        self::assertNull($this->builder->forSource($this->supplierId, 'invoice', null));
        self::assertNull(
            $this->builder->forSource($this->supplierId, 'closing', 1),
            'Uzávěrkový zápis si popis tvoří sám — builder do něj nemluví.',
        );
    }

    // ── zpětné dogenerování ─────────────────────────────────────────────────────

    /** Zápis z převodu: špatný popis se přepíše, původní text zůstane jako detail. */
    public function testRebuildEnrichesImportedEntryAndKeepsOriginalTextAsDetail(): void
    {
        $invoiceId = $this->sale('FV-2098-010', $this->client('Delta Nájemce s.r.o.'), 'pronájem místa');
        $entryId   = $this->importedEntry('invoice', $invoiceId, 'Fakturujeme Vám za pronájem místa');

        $plan = $this->rebuilder->plan(['supplier_id' => $this->supplierId, 'entry_ids' => [$entryId]]);
        self::assertCount(1, $plan);
        self::assertSame('Fakturujeme Vám za pronájem místa', $plan[0]['before']);
        self::assertSame(
            'FV FV-2098-010 — Delta Nájemce s.r.o. — Fakturujeme Vám za pronájem místa',
            $plan[0]['after'],
        );

        self::assertSame(1, $this->rebuilder->apply($plan));
        self::assertSame(
            'FV FV-2098-010 — Delta Nájemce s.r.o. — Fakturujeme Vám za pronájem místa',
            $this->journal->find($entryId, $this->supplierId)['description'],
        );
    }

    /** Opakované spuštění už nic nezmění — jinak by běh dávky zápisy stále přepisoval. */
    public function testRebuildIsIdempotent(): void
    {
        $invoiceId = $this->sale('FV-2098-011', $this->client('Epsilon Nájemce s.r.o.'), 'pronájem místa');
        $entryId   = $this->importedEntry('invoice', $invoiceId, 'Fakturujeme Vám za pronájem místa');
        $filter    = ['supplier_id' => $this->supplierId, 'entry_ids' => [$entryId]];

        self::assertSame(1, $this->rebuilder->rebuild($filter));
        $after = $this->journal->find($entryId, $this->supplierId)['description'];

        self::assertSame([], $this->rebuilder->plan($filter), 'Druhý průchod už nemá co měnit.');
        self::assertSame(0, $this->rebuilder->rebuild($filter));
        self::assertSame($after, $this->journal->find($entryId, $this->supplierId)['description']);
    }

    /**
     * Dávka se pouští VŽDY po firmách. Dokud šel `supplier_id` vynechat, projel jeden
     * dotaz deník všech firem naráz: data sice skončila u správné firmy (plán i UPDATE
     * nesou `supplier_id` řádku), ale dotaz bez tenantové podmínky je přesně ten tvar,
     * který architektonická kontrola hlídá, a u instalace s víc firmami nebylo z běhu
     * poznat, čí deník se přepsal.
     */
    public function testSupplierIdIsMandatoryForEveryBatch(): void
    {
        foreach ([[], ['supplier_id' => null], ['supplier_id' => 0]] as $filter) {
            try {
                $this->rebuilder->plan($filter);
                self::fail('Dávka bez supplier_id musí skončit výjimkou: ' . json_encode($filter));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('supplier_id', $e->getMessage());
            }
        }

        // S firmou naopak projít musí — guard nesmí zablokovat běžné použití.
        self::assertSame([], $this->rebuilder->plan(['supplier_id' => $this->supplierId, 'entry_ids' => []]));
    }

    /** Ruční zápis je popis účetní, ne dokladu — dogenerování se ho nesmí dotknout. */
    public function testManualEntryIsNeverTouched(): void
    {
        $invoiceId = $this->sale('FV-2098-012', $this->client('Zeta Nájemce s.r.o.'), 'pronájem místa');
        // Ruční zápis, který si (nesprávně) nese id faktury jako vlastní source_id.
        $entryId   = $this->importedEntry('manual', $invoiceId, 'Přeúčtování režie na středisko');

        self::assertSame([], $this->rebuilder->plan(['supplier_id' => $this->supplierId, 'entry_ids' => [$entryId]]));
        self::assertSame(0, $this->rebuilder->rebuild(['supplier_id' => $this->supplierId]));
        self::assertSame(
            'Přeúčtování režie na středisko',
            $this->journal->find($entryId, $this->supplierId)['description'],
        );
    }

    /**
     * Popis, který uživatel sám změnil (§35 inline editace), zůstává nedotčený.
     *
     * Dnes takový popis u zápisu z dokladu nevznikne — `updateDescription()` ho pustí
     * jen na 'manual'/'closing'/'opening' (§5.4 re-post clobber gate). Guard v
     * rebuilderu je obrana do hloubky pro historická data a pro případ, že se ta
     * brána uvolní, proto se auditní stopa zakládá přímo.
     */
    public function testUserEditedDescriptionIsNeverTouched(): void
    {
        $invoiceId = $this->sale('FV-2098-013', $this->client('Eta Nájemce s.r.o.'), 'pronájem místa');
        $entryId   = $this->importedEntry('invoice', $invoiceId, 'Nájem stánku č. 7 podle dohody');

        $this->db->pdo()->prepare(
            "INSERT INTO activity_log (supplier_id, user_id, action, entity_type, entity_id, payload)
             VALUES (?, ?, 'accounting.description_edited', 'journal_entry', ?, ?)"
        )->execute([
            $this->supplierId,
            $this->userId,
            $entryId,
            (string) json_encode(['before' => 'Fakturujeme Vám za pronájem místa', 'after' => 'Nájem stánku č. 7 podle dohody']),
        ]);

        self::assertSame([], $this->rebuilder->plan(['supplier_id' => $this->supplierId, 'entry_ids' => [$entryId]]));
        self::assertSame(0, $this->rebuilder->rebuild(['supplier_id' => $this->supplierId, 'entry_ids' => [$entryId]]));
        self::assertSame(
            'Nájem stánku č. 7 podle dohody',
            $this->journal->find($entryId, $this->supplierId)['description'],
        );
    }

    /** Popis se vejde do sloupce i u dlouhého názvu firmy a dlouhého textu položky. */
    public function testRebuiltDescriptionFitsTheColumn(): void
    {
        $clientId  = $this->client(str_repeat('Velmi Dlouhý Název Společnosti ', 5) . 's.r.o.');
        $invoiceId = $this->sale('FV-2098-014', $clientId, str_repeat('velmi podrobný popis plnění ', 10));
        $entryId   = $this->importedEntry('invoice', $invoiceId, 'Fakturujeme Vám za pronájem místa');

        $this->rebuilder->rebuild(['supplier_id' => $this->supplierId, 'entry_ids' => [$entryId]]);
        $description = (string) $this->journal->find($entryId, $this->supplierId)['description'];

        self::assertLessThanOrEqual(JournalDescriptionBuilder::MAX_LENGTH, mb_strlen($description));
        self::assertStringStartsWith('FV FV-2098-014 — Velmi Dlouhý', $description);
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Testovací 1", "Praha", "11000", ?, "CZ12345678", "test@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(string $varsymbol, int $clientId, string $itemText): int
    {
        $issue = self::YEAR . '-06-15';
        $stmt  = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, 1000.00, 210.00, 1210.00, "issued", "1", ?)'
        );
        $stmt->execute([$this->supplierId, $varsymbol, $clientId, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->item('invoice_items', 'invoice_id', $id, $itemText);

        return $id;
    }

    private function purchase(string $varsymbol, string $vendorNumber, int $vendorId, string $itemText): int
    {
        $issue = self::YEAR . '-06-15';
        $stmt  = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind, issue_date,
                 tax_date, due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 1000.00, 210.00, 1210.00,
                     "received", "40", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $varsymbol, $vendorNumber,
            $issue, $issue, $issue, $issue, $this->currencyId, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->item('purchase_invoice_items', 'purchase_invoice_id', $id, $itemText);

        return $id;
    }

    private function item(string $table, string $fk, int $id, string $text): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, 'ks', 1000.00, ?, 21.00, 1000.00, 210.00, 1210.00, 0)"
        )->execute([$id, $text, $this->vatRateId]);
    }

    /** Zápis tak, jak ho do deníku zapíše převod z jiného systému (popis = pole zdroje). */
    private function importedEntry(string $sourceType, int $sourceId, string $description): int
    {
        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => self::YEAR . '-06-15',
            'document_no' => null,
            'description' => $description,
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'posted_at'   => self::YEAR . '-06-15 00:00:00',
            'posted_by'   => $this->userId,
        ], [
            ['account_id' => $this->accountId('311'), 'side' => 'debit', 'amount' => '1210.00', 'line_no' => 1],
            ['account_id' => $this->accountId('602'), 'side' => 'credit', 'amount' => '1210.00', 'line_no' => 2],
        ]);
    }

    private function accountId(string $code): int
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ? LIMIT 1');
        $stmt->execute([$this->supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $pdo->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, is_active)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([$this->supplierId, $code, 'Testovací účet ' . $code, $code[0] === '3' ? 'asset' : 'revenue']);

        return (int) $pdo->lastInsertId();
    }
}
