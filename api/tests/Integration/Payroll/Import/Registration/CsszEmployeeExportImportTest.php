<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Registration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\JmhzReportFixtures;
use MyInvoice\Tests\Unit\Payroll\Import\Registration\RegistrationXmlFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Export zaměstnanců z ePortálu ČSSZ v importu registrací nad izolovanou
 * syntetickou firmou (VS účtárny 1234567890, mzdy v MyÚčtu od dubna 2026).
 */
#[Group('integration')]
final class CsszEmployeeExportImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const ID_PPV = '200000000000000000101';

    private Connection $db;
    private ContainerInterface $container;
    private RegistrationImportService $imports;
    private RegistrationImportLookup $lookup;
    private int $supplierId;
    private int $userId;
    private string $birthNumber;
    private string $oic;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 6) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $this->imports = $this->container->get(RegistrationImportService::class);
        $this->lookup = $this->container->get(RegistrationImportLookup::class);
        if (!$this->db->hasTable('payroll_person_external_ids')) {
            self::markTestSkipped('Chybí tabulka payroll_person_external_ids.');
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier SET payroll_enabled = 1, accounting_mode = 'double_entry' WHERE id = ?"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetická účetní", "readonly", "cs", 1)'
        )->execute([
            'cssz-export-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, 'IMPORT', 'Syntetická účtárna', '1234567890', 1)"
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id) VALUES (?, ?)'
        )->execute([$this->supplierId, (int) $pdo->lastInsertId()]);
        $pdo->prepare(
            "INSERT INTO payroll_module_state (supplier_id, status, start_period) VALUES (?, 'active', '2026-04-01')"
        )->execute([$this->supplierId]);
        $this->birthNumber = RegistrationXmlFixtures::birthNumber('1990-01-15', 'female', 1);
        $this->oic = RegistrationXmlFixtures::oic(7);
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

    /**
     * Export nese osobu a identifikátory, hlášení nástup. Hlášení je v dávce
     * PŘED exportem, přesto se export zapíše dřív a formuláře se spárují
     * podle ID PPV samy.
     */
    public function testExportWithMonthlyReportsCreatesPersonAndFormsPairByIdPpv(): void
    {
        $files = [
            $this->file('jmhz-01.xml', $this->report(1)),
            $this->file('jmhz-02.xml', $this->report(2)),
            $this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([$this->employee()])),
        ];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        self::assertSame(['JMHZ', 'JMHZ', 'CSSZ_EXPORT'], array_column($preview['files'], 'document_type'));
        self::assertNull($preview['files'][2]['error']);
        $byType = $this->byType($preview['records']);
        $export = $byType['CSSZ_EXPORT'][0];
        self::assertSame('Export zaměstnanců ČSSZ', $export['action_label']);
        self::assertSame('create_person', $export['operation'], $this->dump($export));
        self::assertTrue($export['selectable']);
        self::assertSame('2026-01-01', $export['employment']['start_on']);
        self::assertSame('employment', $export['employment']['relation_type']);
        self::assertTrue($this->hasWarning($export, 'první den nejstaršího hlášeného měsíce'));
        self::assertCount(2, $byType['JMHZ']);
        foreach ($byType['JMHZ'] as $form) {
            self::assertSame('pair_required', $form['operation'], $this->dump($form));
            self::assertTrue($this->hasWarning($form, 'založí věta exportu zaměstnanců ČSSZ'));
        }

        $keys = array_column(array_filter($preview['records'], static fn (array $r): bool => $r['selectable']), 'key');
        self::assertCount(3, $keys);
        $applied = $this->apply($files, $keys);
        $results = array_column($applied['results'], null, 'key');
        $exportResult = $results[$export['key']];
        self::assertSame('applied', $exportResult['status'], (string) $exportResult['message']);
        self::assertNull($exportResult['message'], (string) $exportResult['message']);
        foreach (['person_created', 'activated', 'identifiers'] as $operation) {
            self::assertContains($operation, $exportResult['operations']);
        }
        $employeeId = (int) $exportResult['employee_id'];
        $employmentId = (int) $exportResult['employment_id'];
        // Leden zapíše podmínky a prohlášení; únor pak v evidenci už nic nového nemá.
        $january = $results[$byType['JMHZ'][0]['key']];
        self::assertSame('applied', $january['status'], (string) $january['message']);
        self::assertContains('terms', $january['operations']);
        foreach ($byType['JMHZ'] as $form) {
            self::assertSame($employmentId, $results[$form['key']]['employment_id']);
        }

        $employment = $this->lookup->employment($this->supplierId, $employmentId);
        self::assertSame('active', $employment['status']);
        self::assertSame('2026-01-01', $employment['start_date']);
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertTrue($identities->activePersonExternalIdMatches($this->supplierId, $employeeId, 'test', $this->oic));
        self::assertTrue($identities->activeEmploymentExternalIdMatches($this->supplierId, $employmentId, 'test', self::ID_PPV));
        self::assertSame('Jana Testovací', $this->lookup->employeeName($this->supplierId, $employeeId));

        $again = $this->byType($this->imports->preview($this->supplierId, 'test', $files)['records']);
        self::assertSame('none', $again['CSSZ_EXPORT'][0]['operation'], $this->dump($again['CSSZ_EXPORT'][0]));
        self::assertSame('matched', $again['CSSZ_EXPORT'][0]['match']['status']);
        self::assertSame($employmentId, $again['CSSZ_EXPORT'][0]['match']['employment_id']);
        self::assertSame(1, $this->tableRows('payroll_employees'));
        self::assertSame(1, $this->tableRows('payroll_employments'));
    }

    public function testInsuranceStartInsideMonthNeedsNoCheck(): void
    {
        $files = [
            $this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([$this->employee()])),
            $this->file('jmhz-03.xml', $this->report(3, '2026-03-16')),
        ];

        $export = $this->byType($this->imports->preview($this->supplierId, 'test', $files)['records'])['CSSZ_EXPORT'][0];

        self::assertSame('create_person', $export['operation'], $this->dump($export));
        self::assertSame('2026-03-16', $export['employment']['start_on']);
        self::assertTrue($this->hasWarning($export, 'začátek pojištění 2026-03-16'));
        self::assertFalse($this->hasWarning($export, 'první den nejstaršího'));
    }

    public function testExportAloneCannotCreatePerson(): void
    {
        $files = [$this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([$this->employee()]))];

        $preview = $this->imports->preview($this->supplierId, 'test', $files);
        $export = $preview['records'][0];
        self::assertSame('create_person', $export['operation']);
        self::assertFalse($export['selectable']);
        self::assertStringContainsString('měsíční hlášení', (string) $export['blocker']);
        self::assertSame(1, $preview['summary']['blocked']);

        $result = $this->apply($files, [$export['key']])['results'][0];
        self::assertSame('skipped', $result['status']);
        self::assertSame(0, $this->tableRows('payroll_employees'));
    }

    public function testExportOfAnotherEmployerIsBlocked(): void
    {
        $files = [
            $this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([
                $this->employee(['VariabilniSymbol' => '9876543210']),
            ])),
            $this->file('jmhz-01.xml', $this->report(1)),
        ];

        $export = $this->byType($this->imports->preview($this->supplierId, 'test', $files)['records'])['CSSZ_EXPORT'][0];

        self::assertFalse($export['selectable']);
        self::assertStringContainsString('jiného zaměstnavatele', (string) $export['blocker']);
    }

    /** Existující vztah bez identifikátorů: export doplní OIČ a ID PPV, druh činnosti jen ověří. */
    public function testExportAssignsIdentifiersToExistingEmploymentAndVerifiesActivity(): void
    {
        $a1 = [$this->file('a1.xml', RegistrationXmlFixtures::regzecA1(['bno' => $this->birthNumber, 'start' => '2026-01-01']))];
        $created = $this->apply($a1, [$this->imports->preview($this->supplierId, 'test', $a1)['records'][0]['key']])['results'][0];
        self::assertSame('applied', $created['status'], (string) $created['message']);
        $employeeId = (int) $created['employee_id'];
        $employmentId = (int) $created['employment_id'];

        $files = [$this->file('zamestnanci.xml', RegistrationXmlFixtures::csszExport([
            $this->employee(['KodDruhuCinnosti' => '3']),
        ]))];
        $export = $this->imports->preview($this->supplierId, 'test', $files)['records'][0];
        self::assertSame('assign_identifiers', $export['operation'], $this->dump($export));
        self::assertSame('birth_number', $export['match']['matched_by']);
        self::assertSame($employmentId, $export['match']['employment_id']);
        self::assertTrue($this->hasWarning($export, 'se liší od exportu ČSSZ (3)'));

        $result = $this->apply($files, [$export['key']])['results'][0];
        self::assertSame('applied', $result['status'], (string) $result['message']);
        self::assertSame(['identifiers'], $result['operations']);
        $identities = $this->container->get(PayrollRegistrationIdentityService::class);
        self::assertTrue($identities->activePersonExternalIdMatches($this->supplierId, $employeeId, 'test', $this->oic));
        self::assertTrue($identities->activeEmploymentExternalIdMatches($this->supplierId, $employmentId, 'test', self::ID_PPV));
        self::assertSame('1', $this->scalar(
            'SELECT activity_code FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));
        self::assertSame(1, $this->tableRows('payroll_employments'));
    }

    /**
     * @param array<string,string|null> $overrides
     * @return array<string,string|null>
     */
    private function employee(array $overrides = []): array
    {
        return $overrides + [
            'RodneCislo' => $this->birthNumber,
            'Prijmeni' => 'Testovací',
            'Jmeno' => 'Jana',
            'KodDruhuCinnosti' => '1',
            'ZMR' => 'N',
            'IdZamestnani' => self::ID_PPV,
            'OIC' => $this->oic,
        ];
    }

    private function report(int $month, ?string $insuranceFrom = null): string
    {
        return JmhzReportFixtures::report([JmhzReportFixtures::person([
            'oic' => $this->oic,
            'id_ppv' => self::ID_PPV,
            'insurance_from' => $insuranceFrom,
        ])], 2026, $month);
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,list<array<string,mixed>>>
     */
    private function byType(array $records): array
    {
        $result = [];
        foreach ($records as $record) {
            $result[$record['document_type']][] = $record;
        }

        return $result;
    }

    /** @param array<string,mixed> $plan */
    private function hasWarning(array $plan, string $needle): bool
    {
        foreach ($plan['warnings'] as $warning) {
            if (str_contains($warning, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{name:string,content_base64:string}> $files
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private function apply(array $files, array $keys): array
    {
        return $this->imports->apply(
            $this->supplierId,
            'test',
            $files,
            $keys,
            true,
            null,
            $this->userId,
            null,
            'cssz-export-test',
        );
    }

    /** @return array{name:string,content_base64:string} */
    private function file(string $name, string $content): array
    {
        return ['name' => $name, 'content_base64' => base64_encode($content)];
    }

    private function tableRows(string $table): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?", [$this->supplierId]);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    private function dump(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
