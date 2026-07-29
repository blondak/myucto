<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\VatRegistrationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 6 a § 4a ZDPH — vznik plátcovství z obratu.
 *
 * Matice to vedla jako ČÁSTEČNĚ: systém obrat i oba limity znal, ale nevyvodil z nich
 * DŮSLEDEK — nikde se nepočítalo, k jakému DNI se osoba stává plátcem. Plátcovství
 * přitom vzniká ZE ZÁKONA, ne přihláškou; kdo si toho nevšimne, neodvádí daň z plnění,
 * ze kterých ji odvádět měl, a doměrek jde zpětně.
 *
 * Testy proto míří na DATUM, ne na částku:
 *   > 2 000 000 Kč  → plátcem od 1. LEDNA následujícího roku (je čas se registrovat)
 *   > 2 536 500 Kč  → plátcem DNEM NÁSLEDUJÍCÍM po překročení (ode zítřka s daní)
 *
 * Rozdíl mezi těmi dvěma je celý smysl věci — zobrazit jen obrat vedle limitu tuhle
 * informaci nenese.
 */
#[Group('integration')]
final class VatRegistrationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2026;

    private Connection $db;
    private VatRegistrationService $service;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $seq = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->service = $c->get(VatRegistrationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        // Neplátce — právě u něj má kontrola smysl.
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 0 WHERE id = ?')->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals)
             VALUES (?, "CZK", "CZK", "Kč", "koruna česká", "Czech koruna", 2)'
        )->execute([$this->supplierId]);
        $this->currencyId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel", "Test 1", "Praha", "11000", ?, "o@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Pod dolním limitem plátcovství nevzniká a žádné datum se netvrdí. */
    public function testBelowLimitNoRegistration(): void
    {
        $this->invoice('2026-03-10', 500_000.0);

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertSame('below', $r['status']);
        self::assertNull($r['becomes_payer_on']);
    }

    /**
     * Nad dolním limitem: plátcem až od 1. LEDNA následujícího roku. Tohle je ta
     * „máte čas" varianta — a systém ji musí od té druhé odlišit.
     */
    public function testAboveLowLimitBecomesPayerNextJanuary(): void
    {
        $this->invoice('2026-05-10', 2_100_000.0);

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertSame('exceeded_low', $r['status']);
        self::assertSame('2027-01-01', $r['becomes_payer_on']);
        self::assertNull($r['crossed_on'], 'U dolního limitu se den překročení neuvádí — rozhoduje rok.');
    }

    /**
     * Nad horním limitem: plátcem DNEM NÁSLEDUJÍCÍM po dni překročení. Tady je datum
     * kritické — od něj se vystavuje s daní a doměrek jde zpětně právě sem.
     */
    public function testAboveHighLimitBecomesPayerNextDay(): void
    {
        $this->invoice('2026-02-10', 1_500_000.0);
        $this->invoice('2026-07-20', 1_200_000.0); // kumulativně 2 700 000 > 2 536 500

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertSame('exceeded_high', $r['status']);
        self::assertSame('2026-07-20', $r['crossed_on'], 'Den, kdy součet limit přesáhl.');
        self::assertSame('2026-07-21', $r['becomes_payer_on'], 'Plátcem NÁSLEDUJÍCÍM dnem.');
    }

    /**
     * Dobropis obrat snižuje, takže může překročení „odčinit" — pak plátcovství
     * z horního limitu nevzniká. Kdyby se dobropis do obratu nepromítl záporně,
     * systém by tvrdil vznik plátcovství, který nenastal.
     */
    public function testCreditNoteCanUndoTheCrossing(): void
    {
        $this->invoice('2026-02-10', 2_600_000.0);
        $this->invoice('2026-03-01', -500_000.0, creditNote: true);

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertSame('exceeded_low', $r['status'], '2 100 000 je nad dolním, pod horním limitem.');
        self::assertSame('2027-01-01', $r['becomes_payer_on']);
    }

    /**
     * Dobropis zadaný chybně s KLADNOU částkou nesmí obrat navýšit — právě obrat
     * rozhoduje o vzniku plátcovství, takže tahle pojistka mění výsledek.
     */
    public function testWronglySignedCreditNoteStillReducesTurnover(): void
    {
        $this->invoice('2026-02-10', 2_600_000.0);
        $this->invoice('2026-03-01', 500_000.0, creditNote: true); // kladná částka!

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertEqualsWithDelta(2_100_000.0, $r['turnover'], 0.01, 'Dobropis obrat SNÍŽIL.');
        self::assertSame('exceeded_low', $r['status']);
    }

    /** Koncepty a storna se do obratu nepočítají — obratem je uskutečněné plnění. */
    public function testDraftsAndCancelledAreExcluded(): void
    {
        $this->invoice('2026-02-10', 2_600_000.0, status: 'draft');
        $this->invoice('2026-03-10', 2_600_000.0, status: 'cancelled');

        self::assertSame('below', $this->service->evaluate($this->supplierId, self::YEAR)['status']);
    }

    /**
     * Ročníky před 2025 běží na starém mechanismu klouzavých 12 měsíců, který se
     * vědomě NEMODELUJE — dopočítávat datum podle pravidla, které tehdy neplatilo,
     * by bylo horší než přiznat, že se to neposuzuje.
     */
    public function testPre2025YearsAreNotEvaluated(): void
    {
        $this->invoice('2024-05-10', 3_000_000.0);

        $r = $this->service->evaluate($this->supplierId, 2024);

        self::assertFalse($r['applicable']);
        self::assertNull($r['becomes_payer_on']);
        // A stav NESMÍ zůstat na `below` — obrat 3 000 000 je 1,5× nad limitem
        // a slovo „pod limitem" by konzumenta, který nečte `applicable`, svedlo.
        self::assertSame('not_applicable', $r['status']);
    }

    /**
     * Firma, která plátcem UŽ JE, se plátcem znovu nestává.
     *
     * Služba `is_vat_payer` načítala a nepoužívala, takže firmě plátcovské od vzniku
     * tvrdila konkrétní datum vzniku plátcovství. Obrat a limity zůstávají — ty smysl
     * dávají pořád.
     */
    public function testExistingPayerIsNotToldToRegisterAgain(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplierId]);
        $this->invoice('2026-05-10', 3_000_000.0);

        $r = $this->service->evaluate($this->supplierId, self::YEAR);

        self::assertTrue($r['is_vat_payer']);
        self::assertSame('already_payer', $r['status']);
        self::assertNull($r['becomes_payer_on'], 'Plátci se datum vzniku plátcovství netvrdí.');
        self::assertEqualsWithDelta(3_000_000.0, $r['turnover'], 0.01, 'Obrat se vykazuje dál.');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function invoice(string $date, float $amount, bool $creditNote = false, string $status = 'issued'): int
    {
        $this->seq++;
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, invoice_type, varsymbol, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", "{}", ?, 0, ?, ?, ?)'
        )->execute([
            $this->supplierId, $this->clientId,
            $creditNote ? 'credit_note' : 'invoice',
            'F' . self::YEAR . $this->seq,
            $date, $date, $date, $this->currencyId,
            $amount, $amount, $status, $this->userId,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
