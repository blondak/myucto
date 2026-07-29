<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxReturnRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Epic DP (issue #18) — perzistence přiznání k dani z příjmů (income_tax_returns,
 * migrace 1030). Ověřuje create → CAS updateInputs (úspěch i konflikt) → finalize →
 * reopen → setLastSubmission a že ruční vstupy přežijí round-trip (JSON encode/decode).
 *
 * Izolovaný supplier, transakce s rollbackem v tearDown, soft-skip bez cfg.php.
 */
#[Group('integration')]
final class TaxReturnRepositoryTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private TaxReturnRepository $repo;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(TaxReturnRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "dp-return@example.com", ?, ?)'
        );
        $stmt->execute(['DP return test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
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

    public function testCreateAndFindRoundTrip(): void
    {
        self::assertNull($this->repo->find($this->supplierId, self::YEAR, 'po'));

        $inputs = ['loss_carryforward' => 50000.0, 'notes' => 'ěščř žýá — UTF-8', 'manual_increase_items' => [['text' => 'Pokuta', 'amount' => 1234.5]]];
        $row = $this->repo->create($this->supplierId, self::YEAR, 'po', $inputs, null);

        self::assertSame('draft', $row['status']);
        self::assertSame(1, $row['row_version']);
        self::assertSame('po', $row['taxpayer_type']);
        // Celé číslo se přes JSON vrátí jako int (50000.0 → 50000) — konzumenti castují na float.
        self::assertEqualsWithDelta(50000.0, $row['inputs']['loss_carryforward'], 0.001);
        self::assertSame('ěščř žýá — UTF-8', $row['inputs']['notes']);
        self::assertSame('Pokuta', $row['inputs']['manual_increase_items'][0]['text']);
        self::assertNull($row['computed']);
    }

    public function testUpdateInputsCasSuccessAndConflict(): void
    {
        $this->repo->create($this->supplierId, self::YEAR, 'po', ['a' => 1], null);

        // Správná verze → úspěch, verze se inkrementuje.
        $updated = $this->repo->updateInputs($this->supplierId, self::YEAR, 'po', ['a' => 2], 1);
        self::assertNotNull($updated);
        self::assertSame(2, $updated['row_version']);
        self::assertSame(2, $updated['inputs']['a']);

        // Zastaralá verze (1) → konflikt (null), data se nezmění.
        $conflict = $this->repo->updateInputs($this->supplierId, self::YEAR, 'po', ['a' => 99], 1);
        self::assertNull($conflict);
        $current = $this->repo->find($this->supplierId, self::YEAR, 'po');
        self::assertSame(2, $current['inputs']['a'], 'Konfliktní zápis nesmí přepsat data.');
        self::assertSame(2, $current['row_version']);
    }

    public function testFinalizeReopenWorkflow(): void
    {
        $this->repo->create($this->supplierId, self::YEAR, 'fo', ['x' => 1], null);
        $updated = $this->repo->updateInputs($this->supplierId, self::YEAR, 'fo', ['x' => 1], 1);
        self::assertSame(2, $updated['row_version']);

        // Finalize s aktuální verzí → status final + computed snapshot.
        $computed = ['rows' => [['line' => 290, 'value' => 12345]]];
        $final = $this->repo->finalize($this->supplierId, self::YEAR, 'fo', $computed, 2);
        self::assertNotNull($final);
        self::assertSame('final', $final['status']);
        self::assertSame(12345, $final['computed']['rows'][0]['value']);

        // Editace vstupů ve stavu final je zakázaná (updateInputs nic neudělá).
        self::assertNull($this->repo->updateInputs($this->supplierId, self::YEAR, 'fo', ['x' => 9], 3));

        // Reopen → zpět na draft, computed zahozen.
        $reopened = $this->repo->reopen($this->supplierId, self::YEAR, 'fo', 3);
        self::assertNotNull($reopened);
        self::assertSame('draft', $reopened['status']);
        self::assertNull($reopened['computed']);

        // Po reopen jde zase editovat.
        $edited = $this->repo->updateInputs($this->supplierId, self::YEAR, 'fo', ['x' => 5], 4);
        self::assertNotNull($edited);
        self::assertSame(5, $edited['inputs']['x']);
    }

    /** DP v2 fáze 2: řádné a dodatečné přiznání koexistují za totéž období (UNIQUE +variant). */
    public function testVariantsCoexistForSamePeriod(): void
    {
        $radne = $this->repo->create($this->supplierId, self::YEAR, 'po', ['loss_carryforward' => 1000.0], null, 'radne');
        self::assertSame('radne', $radne['variant']);

        // Dodatečné za totéž období — NESMÍ narazit na UNIQUE, je to samostatný záznam.
        $dodatecne = $this->repo->create($this->supplierId, self::YEAR, 'po', ['d_zjist' => '2026-03-01'], null, 'dodatecne');
        self::assertSame('dodatecne', $dodatecne['variant']);
        self::assertSame(1, $dodatecne['row_version']);

        // find() rozlišuje dle varianty.
        self::assertSame('2026-03-01', $this->repo->find($this->supplierId, self::YEAR, 'po', 'dodatecne')['inputs']['d_zjist']);
        self::assertArrayNotHasKey('d_zjist', $this->repo->find($this->supplierId, self::YEAR, 'po', 'radne')['inputs']);

        // listVariants vidí obě.
        $variants = array_column($this->repo->listVariants($this->supplierId, self::YEAR, 'po'), 'variant');
        self::assertContains('radne', $variants);
        self::assertContains('dodatecne', $variants);
    }

    /** findLastKnownTax čte celkovou daň z posledního finalizovaného řádného přiznání. */
    public function testFindLastKnownTaxFromFinalizedReturn(): void
    {
        // Bez finalizovaného → null.
        self::assertNull($this->repo->findLastKnownTax($this->supplierId, self::YEAR, 'po'));

        $this->repo->create($this->supplierId, self::YEAR, 'po', [], null, 'radne');
        // Snapshot ve tvaru, jaký ukládá service: {computed:{summary:{total_tax:...}}}.
        $snapshot = ['computed' => ['summary' => ['total_tax' => 84000.0]], 'podklady' => []];
        $final = $this->repo->finalize($this->supplierId, self::YEAR, 'po', $snapshot, 1, 'radne');
        self::assertNotNull($final);

        self::assertEqualsWithDelta(84000.0, $this->repo->findLastKnownTax($this->supplierId, self::YEAR, 'po'), 0.001);
    }

    public function testSetLastSubmissionDoesNotBumpVersion(): void
    {
        $this->repo->create($this->supplierId, self::YEAR, 'po', [], null);
        $pdo = $this->db->pdo();
        // Vlož minimální tax_submissions řádek pro FK.
        $pdo->prepare(
            "INSERT INTO tax_submissions (supplier_id, form_code, period_year, xml_content, xml_size_bytes, xml_sha256, validation_status)
             VALUES (?, 'dppdp9', ?, '<x/>', 4, REPEAT('a',64), 'skipped')"
        )->execute([$this->supplierId, self::YEAR]);
        $submissionId = (int) $pdo->lastInsertId();

        $this->repo->setLastSubmission($this->supplierId, self::YEAR, 'po', $submissionId);
        $row = $this->repo->find($this->supplierId, self::YEAR, 'po');
        self::assertSame($submissionId, $row['last_submission_id']);
        self::assertSame(1, $row['row_version'], 'setLastSubmission je audit metadata, neinkrementuje verzi.');
    }

    /** E8 (§141 DŘ): za období lze podat víc dodatečných přiznání (variant_seq 1..N). */
    public function testMultipleDodatecneCoexist(): void
    {
        $this->repo->create($this->supplierId, self::YEAR, 'po', [], null, 'radne');
        $d1 = $this->repo->create($this->supplierId, self::YEAR, 'po', ['d_zjist' => '2026-02-01'], null, 'dodatecne', 1);
        $d2 = $this->repo->create($this->supplierId, self::YEAR, 'po', ['d_zjist' => '2026-05-01'], null, 'dodatecne', 2);

        self::assertSame(1, $d1['variant_seq']);
        self::assertSame(2, $d2['variant_seq']);
        self::assertSame(2, $this->repo->maxVariantSeq($this->supplierId, self::YEAR, 'po', 'dodatecne'));

        // find() rozlišuje dle pořadí.
        self::assertSame('2026-02-01', $this->repo->find($this->supplierId, self::YEAR, 'po', 'dodatecne', 1)['inputs']['d_zjist']);
        self::assertSame('2026-05-01', $this->repo->find($this->supplierId, self::YEAR, 'po', 'dodatecne', 2)['inputs']['d_zjist']);

        // listVariants nese pořadí obou dodatečných.
        $seqs = [];
        foreach ($this->repo->listVariants($this->supplierId, self::YEAR, 'po') as $v) {
            if ($v['variant'] === 'dodatecne') {
                $seqs[] = $v['variant_seq'];
            }
        }
        self::assertSame([1, 2], $seqs);
    }

    /**
     * E8: poslední známá daň = NAPOSLEDY pravomocně stanovená (§141/1 DŘ) — tedy
     * i z finalizovaného dodatečného, ne jen z řádného/opravného.
     */
    public function testFindLastKnownTaxPrefersLatestDodatecne(): void
    {
        // Řádné finalizované: daň 84 000.
        $this->repo->create($this->supplierId, self::YEAR, 'po', [], null, 'radne');
        $this->repo->finalize($this->supplierId, self::YEAR, 'po', ['computed' => ['summary' => ['total_tax' => 84000.0]]], 1, 'radne');
        self::assertEqualsWithDelta(84000.0, $this->repo->findLastKnownTax($this->supplierId, self::YEAR, 'po'), 0.001);

        // Dodatečné č. 1 finalizované: daň 90 000 → poslední známá je teď 90 000.
        $this->repo->create($this->supplierId, self::YEAR, 'po', [], null, 'dodatecne', 1);
        $this->repo->finalize($this->supplierId, self::YEAR, 'po', ['computed' => ['summary' => ['total_tax' => 90000.0]]], 1, 'dodatecne', 1);
        self::assertEqualsWithDelta(90000.0, $this->repo->findLastKnownTax($this->supplierId, self::YEAR, 'po'), 0.001);

        // Dodatečné č. 2 finalizované: daň 95 000 → poslední známá je teď 95 000.
        $this->repo->create($this->supplierId, self::YEAR, 'po', [], null, 'dodatecne', 2);
        $this->repo->finalize($this->supplierId, self::YEAR, 'po', ['computed' => ['summary' => ['total_tax' => 95000.0]]], 1, 'dodatecne', 2);
        self::assertEqualsWithDelta(95000.0, $this->repo->findLastKnownTax($this->supplierId, self::YEAR, 'po'), 0.001);
        $last = $this->repo->findLastFinalized($this->supplierId, self::YEAR, 'po');
        self::assertSame('dodatecne', $last['variant']);
        self::assertSame(2, $last['variant_seq']);
    }
}
