<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Invariants;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\LedgerInvariantService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vrstva L3 z auditního plánu — INVARIANTY nad skutečným obsahem deníku.
 *
 * Tenhle test nic neseeduje. Bere databázi TAK, JAK JE, a ptá se, jestli platí
 * tvrzení, která platit musí nezávisle na datech. Scénářový test ověřuje cestu,
 * kterou si sám vymyslel; invariant chytí i to, co do dat dostala cesta, na kterou
 * nikdo nemyslel — backfill, import, migrace, ruční zásah do DB.
 *
 * **Pozor na vakuózní zelenou.** Izolovaná testovací DB má prázdný deník, takže by
 * všechny invarianty prošly, i kdyby kód byl rozbitý. Test to explicitně detekuje
 * a v takovém případě SKIPUJE místo aby předstíral kontrolu. Ostří má tahle vrstva
 * až nad databází s obsahem — pouštěj `php api/bin/check-invariants.php`, který
 * volá TUTÉŽ službu (`LedgerInvariantService`) a vrací nenulový exit kód.
 *
 * Vlastní logika invariantů je v `LedgerInvariantService`, ne tady: jinak by
 * existovala ve dvou kopiích (test × cron) a rozešla by se — přesně ta třída chyby,
 * kterou má tenhle audit vymýtit.
 */
#[Group('invariants')]
final class LedgerInvariantsTest extends TestCase
{
    private Connection $db;
    private LedgerInvariantService $invariants;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — invarianty vyžadují DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->invariants = $container->get(LedgerInvariantService::class);
            // Connection navazuje spojení AŽ při prvním dotazu, takže bez tohohle
            // probu by nedostupná DB neskončila skipem tady, ale PDOException
            // uprostřed testu (v CI: izolovaná `<db>_test` neexistuje).
            $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testLedgerSatisfiesAllInvariants(): void
    {
        if ($this->invariants->ledgerIsEmpty()) {
            self::markTestSkipped(
                'Deník je prázdný — invarianty nad daty by prošly vakuózně. '
                    . 'Pusť je proti databázi s obsahem: php api/bin/check-invariants.php',
            );
        }

        $failures = [];
        foreach ($this->invariants->checkAll() as $result) {
            foreach ($result['violations'] as $violation) {
                $failures[] = sprintf('%s (%s — %s): %s', $result['code'], $result['source'], $result['rule'], $violation);
            }
        }

        self::assertSame([], $failures, sprintf(
            "Porušené invarianty účetního jádra:\n  %s",
            implode("\n  ", $failures),
        ));
    }

    /**
     * Pojistka proti tiché degradaci: kdyby se všechny invarianty přeskočily (chybí
     * tabulka, neproběhla migrace), sada by zezelenala a tvrdila by, že hlídá.
     * Aspoň jeden invariant musí být SKUTEČNĚ vyhodnocený.
     */
    public function testAtLeastOneInvariantIsActuallyEvaluated(): void
    {
        $results = $this->invariants->checkAll();
        $checked = array_values(array_filter($results, static fn (array $r): bool => $r['checked']));

        self::assertNotEmpty($checked, sprintf(
            "Žádný invariant se nevyhodnotil — vrstva by tvrdila, že hlídá, a nekontrolovala nic.\nDůvody:\n  %s",
            implode("\n  ", array_map(
                static fn (array $r): string => $r['code'] . ': ' . (string) $r['skipped_reason'],
                $results,
            )),
        ));
    }

    /** Každý invariant musí být pojmenovaný a mít uvedený předpis, ze kterého plyne. */
    public function testEveryInvariantDeclaresItsLegalSource(): void
    {
        foreach ($this->invariants->checkAll() as $result) {
            self::assertNotSame('', trim($result['code']), 'Invariant bez kódu.');
            self::assertNotSame('', trim($result['rule']), $result['code'] . ': chybí formulace pravidla.');
            self::assertNotSame('', trim($result['source']), $result['code'] . ': chybí předpis, ze kterého plyne.');
        }
    }

    /**
     * I29 nesmí hlásit FALEŠNÝ poplach na databázi, která SYSTEM VERSIONING má.
     *
     * Testovací DB ho zapnutý má (migrace 1029), takže tohle je jediné místo, kde se
     * ověří pozitivní větev — invarianty nad prázdným deníkem se jinak skipují.
     * První verze detekce četla `information_schema`, jenže tahle verze MariaDB tam
     * období `row_start`/`row_end` NEUKAZUJE, takže by hlásila porušení i tam, kde je
     * vše v pořádku. Guard, který svítí červeně vždycky, je stejně bezcenný jako ten,
     * který svítí zeleně vždycky — jen se to pozná dřív.
     */
    public function testVersioningInvariantDoesNotFalselyAlarm(): void
    {
        $row = $this->db->pdo()->query('SHOW CREATE TABLE `journal_entries`')->fetch(\PDO::FETCH_NUM);
        if (stripos((string) ($row[1] ?? ''), 'WITH SYSTEM VERSIONING') === false) {
            self::markTestSkipped('Tahle DB versioning nemá — pozitivní větev nelze ověřit.');
        }

        $i29 = null;
        foreach ($this->invariants->checkAll() as $r) {
            if ($r['code'] === 'I29') {
                $i29 = $r;
                break;
            }
        }

        self::assertNotNull($i29, 'Invariant I29 v registru chybí.');
        self::assertTrue($i29['checked'], 'I29 se nesmí přeskočit na DB, která versioning má.');
        self::assertSame([], $i29['violations'], 'Verzovaný deník nesmí hlásit porušení.');
    }

    /**
     * I30 musí najít mzdový záznam, jehož ROZPIS se rozchází s uloženými finálními
     * částkami.
     *
     * V produkci takových měsíců bylo 27: rozpis nesl jinou zálohu na daň a jinou
     * čistou mzdu než uložené finály. Uživatel četl jedno číslo,
     * do mzdového listu a do deníku šlo druhé — a nic na to neupozornilo.
     */
    public function testPayrollSnapshotMismatchIsDetected(): void
    {
        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'payroll_monthly_records'")->fetch() === false) {
            self::markTestSkipped('Mzdový modul v DB není.');
        }
        $supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($supplierId === 0) {
            self::markTestSkipped('Chybí supplier.');
        }

        $pdo->beginTransaction();
        try {
            // Vlastní zaměstnanec — testovací DB žádného mít nemusí a přeskočený
            // test by tvrdil, že hlídá, a nekontroloval nic.
            $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name) VALUES (?, ?)')
                ->execute([$supplierId, 'I30 test']);
            $employeeId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO payroll_monthly_records
                    (supplier_id, employee_id, year, month, gross, breakdown,
                     tax_credit_taxpayer, tax_credit_children, advance_tax_final, net_final)
                 VALUES (?, ?, 2099, 12, 4500, ?, 0, 0, 0, 1561)'
            )->execute([
                $supplierId,
                $employeeId,
                json_encode(['advance_tax_withheld' => 675, 'net' => 886], JSON_UNESCAPED_UNICODE),
            ]);

            $i30 = null;
            foreach ($this->invariants->checkAll() as $r) {
                if ($r['code'] === 'I30') {
                    $i30 = $r;
                    break;
                }
            }

            self::assertNotNull($i30, 'Invariant I30 v registru chybí.');
            self::assertTrue($i30['checked'], 'I30 se nesmí přeskočit, když mzdový modul existuje.');
            self::assertNotSame([], $i30['violations'], 'Rozejitý mzdový snapshot musí být nahlášen.');
            self::assertStringContainsString('2099/12', implode("\n", $i30['violations']));
        } finally {
            $pdo->rollBack();
        }
    }
}
