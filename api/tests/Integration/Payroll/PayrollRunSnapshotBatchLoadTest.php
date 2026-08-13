<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunInputSnapshot;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Tests\Fixtures\Payroll\PayrollRunScaleFixture;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot mzdového běhu sahá do databáze MNOŽINOVĚ, ne po jedné osobě.
 *
 * Dřív připadalo na osobu ~57 round-tripů, takže běh nad 300 osobami vyrobil
 * uvnitř jedné transakce přes 17 tisíc dotazů a nedoběhl. Test hlídá obojí, co
 * u té opravy může selhat: že počet dotazů zůstane na počtu osob NEZÁVISLÝ,
 * a že se přitom nezměnil ani bajt kanonického JSONu — otisk snapshotu je
 * auditní závazek a porovnává se při přehrání běhu.
 */
#[Group('integration')]
final class PayrollRunSnapshotBatchLoadTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunSnapshotBuilder $builder;
    private PayrollEmployerPolicyRepository $policies;
    private int $sourceSupplierId;
    private int $supplierId;
    private int $actorId;
    private int $tenantOrdinal = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $builder = $container->get(PayrollRunSnapshotBuilder::class);
        $policies = $container->get(PayrollEmployerPolicyRepository::class);
        if (!$db instanceof Connection
            || !$builder instanceof PayrollRunSnapshotBuilder
            || !$policies instanceof PayrollEmployerPolicyRepository
        ) {
            $this->markTestSkipped('Služby mzdového běhu nejsou dostupné.');
        }
        $this->db = $db;
        $this->builder = $builder;
        $this->policies = $policies;
        foreach ([
            'payroll_employments',
            'payroll_statutory_accumulator_openings',
            'payroll_enforcement_claims',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $this->scalar('SELECT MIN(id) FROM supplier', []);
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $this->sourceSupplierId = $sourceSupplierId;
        $pdo->beginTransaction();
        $this->newTenant();
    }

    /**
     * Založí čerstvou izolovanou firmu (a aktéra a účinnou politiku) a přepne na ni.
     *
     * Každý počet osob potřebuje vlastní firmu: opening balance zákonných kumulací
     * je append-only, takže staré řádky nejde smazat a znovu použít.
     */
    private function newTenant(): void
    {
        $pdo = $this->db->pdo();
        ++$this->tenantOrdinal;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $this->sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $actor = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický aktér", "readonly", "cs", 1)'
        );
        $actor->execute([
            'mz30-' . bin2hex(random_bytes(6)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->actorId = (int) $pdo->lastInsertId();
        $this->policies->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'four_eyes_required' => true,
            'automatic_calculation_enabled' => true,
            'automatic_posting_enabled' => true,
            'automatic_payments_enabled' => true,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:mz30-policy',
        ], $this->actorId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /**
     * Počet dotazů nesmí růst s počtem osob.
     *
     * Před dávkováním to bylo 72 / 582 / 5 682 round-tripů pro 1 / 10 / 100 osob;
     * teď musí být všechny tři počty STEJNÉ. Rovnost je tvrdší tvrzení než horní
     * mez — chytí i to, kdyby někdo přidal jediný dotaz zpátky do smyčky.
     */
    public function testSnapshotQueryCountDoesNotGrowWithHeadcount(): void
    {
        $pdo = $this->db->pdo();
        $counts = [];
        $people = [];
        foreach ([1, 10, 100] as $headcount) {
            if ($headcount > 1) {
                $this->newTenant();
            }
            $this->seed($headcount);
            $before = PayrollRunScaleFixture::statementRoundTrips($pdo);
            $snapshot = $this->build();
            $counts[$headcount] = PayrollRunScaleFixture::statementRoundTrips($pdo) - $before;
            $people[$headcount] = count($snapshot->data['people']);
        }

        self::assertSame([1 => 1, 10 => 10, 100 => 100], $people);
        self::assertSame(
            $counts[1],
            $counts[10],
            'Snapshot deseti osob smí stát tolik dotazů co snapshot jedné.',
        );
        self::assertSame(
            $counts[1],
            $counts[100],
            'Snapshot sta osob smí stát tolik dotazů co snapshot jedné.',
        );
        // Horní mez: dávka má 500 ID, takže do 500 osob nepřibude ani jeden dotaz.
        // Číslo je vědomě těsné — má spadnout, když někdo přidá dotaz navíc.
        self::assertLessThanOrEqual(
            80,
            $counts[100],
            'Snapshot sta osob se musí vejít do 80 round-tripů.',
        );
    }

    /** Dvakrát postavený snapshot téhož vstupu musí být bajtově týž. */
    public function testSnapshotIsByteIdenticalAcrossBuilds(): void
    {
        $this->seed(24);
        $first = $this->build();
        $second = $this->build();

        self::assertSame($first->json, $second->json);
        self::assertSame($first->hash, $second->hash);
        self::assertSame($first->rulesetManifestHash, $second->rulesetManifestHash);
        self::assertSame(
            hash('sha256', $first->json),
            $first->hash,
            'Otisk snapshotu musí odpovídat jeho kanonickému JSONu.',
        );
    }

    /**
     * Dávkové načtení nesmí přiřadit řádky jiné osobě.
     *
     * Tohle je ta chyba, kterou by počet dotazů ani stabilita otisku neodhalily:
     * snapshot by byl stejně velký a stejně deterministický, jen by osoba A měla
     * srážky osoby B. Proto se každá kolekce porovnává s nezávislým dotazem
     * za jednu osobu / jeden pracovní vztah.
     */
    public function testBatchedLoadKeepsRowsWithTheirOwnPerson(): void
    {
        $this->seed(24);
        $snapshot = $this->build();
        $people = $snapshot->data['people'];
        self::assertCount(24, $people);

        $seenEmploymentIds = [];
        foreach ($people as $person) {
            $employeeId = (int) $person['employee']['id'];

            self::assertSame(
                $this->ids(
                    'SELECT id FROM payroll_employments
                      WHERE supplier_id = ? AND employee_id = ? ORDER BY id',
                    [$this->supplierId, $employeeId],
                ),
                array_map(
                    static fn (array $row): int => (int) $row['employment']['id'],
                    $person['employments'],
                ),
                "Osoba {$employeeId} má cizí pracovní vztahy.",
            );
            self::assertSame(
                $this->ids(
                    'SELECT id FROM payroll_deduction_agreements
                      WHERE supplier_id = ? AND employee_id = ? AND status = "active"
                      ORDER BY priority_no, id',
                    [$this->supplierId, $employeeId],
                ),
                array_map(
                    static fn (array $row): int => (int) $row['id'],
                    $person['deduction_agreements'],
                ),
                "Osoba {$employeeId} má cizí dohody o srážkách.",
            );
            self::assertSame(
                $this->ids(
                    'SELECT id FROM payroll_payout_rules
                      WHERE supplier_id = ? AND employee_id = ? AND is_active = 1
                      ORDER BY priority_no, id',
                    [$this->supplierId, $employeeId],
                ),
                array_map(
                    static fn (array $row): int => (int) $row['id'],
                    $person['payout_rules'],
                ),
                "Osoba {$employeeId} má cizí výplatní pravidla.",
            );
            self::assertSame(
                $this->ids(
                    'SELECT id FROM payroll_person_accounts
                      WHERE supplier_id = ? AND employee_id = ? AND is_active = 1
                      ORDER BY id',
                    [$this->supplierId, $employeeId],
                ),
                array_map(
                    static fn (array $row): int => (int) $row['id'],
                    $person['payout_accounts'],
                ),
                "Osoba {$employeeId} má cizí výplatní účty.",
            );

            $accumulators = $person['statutory_accumulators'];
            foreach (['social_insurance', 'income_tax'] as $calculationKind) {
                self::assertSame('verified', $accumulators[$calculationKind]['status']);
                self::assertSame(
                    $employeeId,
                    $accumulators[$calculationKind]['state']['employee_id'],
                    "Osoba {$employeeId} má kumulaci {$calculationKind} jiné osoby.",
                );
                self::assertSame(
                    (int) $this->scalar(
                        'SELECT id FROM payroll_statutory_accumulator_openings
                          WHERE supplier_id = ? AND employee_id = ? AND tax_year = 2026
                            AND calculation_kind = ?',
                        [$this->supplierId, $employeeId, $calculationKind],
                    ),
                    $accumulators[$calculationKind]['state']['opening_balance']['id'],
                );
            }

            self::assertSame(
                $employeeId,
                $person['statutory_evidence']['employee_id'] ?? null,
                "Osoba {$employeeId} má zákonnou evidenci jiné osoby.",
            );
            $declarationIds = $this->ids(
                'SELECT id FROM payroll_person_tax_declarations
                  WHERE supplier_id = ? AND employee_id = ? ORDER BY effective_from, id',
                [$this->supplierId, $employeeId],
            );
            self::assertSame(
                $declarationIds[0] ?? null,
                $person['statutory_evidence']['income_tax']['declaration']['id'] ?? null,
                "Osoba {$employeeId} má cizí daňové prohlášení.",
            );

            self::assertCount(
                (int) $this->scalar(
                    'SELECT COUNT(*) FROM payroll_enforcement_claims claim
                       JOIN payroll_enforcement_cases enforcement_case
                         ON enforcement_case.supplier_id = claim.supplier_id
                        AND enforcement_case.id = claim.case_id
                      WHERE claim.supplier_id = ? AND enforcement_case.employee_id = ?',
                    [$this->supplierId, $employeeId],
                ),
                $person['enforcement_evidence']['claims'],
                "Osoba {$employeeId} má cizí exekuční pohledávky.",
            );

            foreach ($person['employments'] as $employment) {
                $employmentId = (int) $employment['employment']['id'];
                self::assertArrayNotHasKey(
                    $employmentId,
                    $seenEmploymentIds,
                    'Pracovní vztah se ve snapshotu objevil dvakrát.',
                );
                $seenEmploymentIds[$employmentId] = true;
                self::assertSame($employeeId, (int) $employment['employment']['employee_id']);
                self::assertSame(
                    $this->ids(
                        'SELECT id FROM payroll_inputs
                          WHERE supplier_id = ? AND employment_id = ?
                            AND period_start = ? AND status IN ("approved", "locked")
                          ORDER BY id',
                        [
                            $this->supplierId,
                            $employmentId,
                            PayrollRunScaleFixture::PERIOD_START,
                        ],
                    ),
                    array_map(
                        static fn (array $row): int => (int) $row['id'],
                        $employment['inputs'],
                    ),
                    "Vztah {$employmentId} má cizí mzdové vstupy.",
                );
                self::assertSame(
                    $this->ids(
                        'SELECT id FROM payroll_absences
                          WHERE supplier_id = ? AND employment_id = ? AND status = "approved"
                            AND date_from <= ? AND date_to >= ?
                          ORDER BY date_from, id',
                        [
                            $this->supplierId,
                            $employmentId,
                            PayrollRunScaleFixture::PERIOD_END,
                            PayrollRunScaleFixture::PERIOD_START,
                        ],
                    ),
                    array_map(
                        static fn (array $row): int => (int) $row['id'],
                        $employment['absences'],
                    ),
                    "Vztah {$employmentId} má cizí absence.",
                );
                self::assertSame(
                    $this->scalar(
                        'SELECT id FROM payroll_time_months
                          WHERE supplier_id = ? AND employment_id = ? AND period_start = ?',
                        [
                            $this->supplierId,
                            $employmentId,
                            PayrollRunScaleFixture::PERIOD_START,
                        ],
                    ) === false ? null : (int) $this->scalar(
                        'SELECT id FROM payroll_time_months
                          WHERE supplier_id = ? AND employment_id = ? AND period_start = ?',
                        [
                            $this->supplierId,
                            $employmentId,
                            PayrollRunScaleFixture::PERIOD_START,
                        ],
                    ),
                    $employment['time_month'] === null
                        ? null
                        : (int) $employment['time_month']['id'],
                    "Vztah {$employmentId} má cizí docházkový měsíc.",
                );
                // Účinný je term od 1. 6.; ten starší skončil 31. 5. a nesmí projít.
                self::assertSame('2026-06-01', $employment['term']['effective_from']);
                self::assertSame(10000, $employment['term']['workload_basis_points']);
            }
        }
    }

    /** Naseeduje osoby do aktuální firmy; ID se posunou o pořadí firmy. */
    private function seed(int $headcount): void
    {
        (new PayrollRunScaleFixture(
            $this->db,
            $this->supplierId,
            $this->actorId,
            7_000_000_000 + ($this->tenantOrdinal * 100_000_000),
        ))->seed($headcount);
    }

    private function build(): PayrollRunInputSnapshot
    {
        return $this->builder->build(
            $this->supplierId,
            PayrollRunScaleFixture::PERIOD_START,
            PayrollRunScaleFixture::PAYMENT_DATE,
        );
    }

    /**
     * @param list<mixed> $params
     * @return list<int>
     */
    private function ids(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }
}
