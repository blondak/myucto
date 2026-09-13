<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Import\Attendance;

use MyInvoice\Action\Payroll\PayrollAttendanceImportAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Unit\Payroll\Import\Attendance\AttendanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Import docházky nad izolovanou firmou v transakci (tearDown ji vrací).
 *
 * Jana se páruje přes rodné číslo, Petr přes jméno, Eva v evidenci chybí.
 * Úkolová mzda se v testech použití mapuje na složku UKOL_TEST, která ve firmě
 * není ani ve výchozím katalogu — její částky musí skončit jako chyby vstupní
 * brány, ne potichu zmizet.
 */
#[Group('integration')]
final class AttendanceImportServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = AttendanceFixture::PERIOD;
    private const MISSING_COMPONENT = 'UKOL_TEST';

    private Connection $db;
    private ContainerInterface $container;
    private AttendanceImportService $service;
    private int $supplierId;
    private int $userId;
    /** @var array{employee_id:int,employment_id:int} */
    private array $jana;
    /** @var array{employee_id:int,employment_id:int} */
    private array $petr;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $db = $this->container->get(Connection::class);
        $service = $this->container->get(AttendanceImportService::class);
        if (!$db instanceof Connection || !$service instanceof AttendanceImportService) {
            throw new \RuntimeException('Služba importu docházky není dostupná.');
        }
        $this->db = $db;
        $this->service = $service;
        foreach ([
            'payroll_attendance_imports',
            'payroll_attendance_import_rows',
            'payroll_import_links',
            'payroll_import_profiles',
            'payroll_inputs',
            'payroll_person_identifiers',
        ] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);
        // Vztah bez mzdové účtárny nejde založit; doménová vrstva dosadí výchozí účtárnu zaměstnavatele.
        $pdo->prepare(
            'INSERT INTO payroll_offices (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "IMP", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$this->supplierId]);
        $officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "1234567890", "synthetic:attendance-import")',
        )->execute([$this->supplierId, $officeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$this->supplierId, $officeId]);
        $this->jana = $this->createEmployment('Jana Testovací', 'ZAM-J');
        $this->petr = $this->createEmployment('Petr Zkušební', 'ZAM-P');
        $this->insertBirthNumber($this->jana['employee_id'], AttendanceFixture::janaBirthNumber());
        $this->createComponent('ODMENA');
        $this->createComponent('PREMIE_PRIPLATKY');
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

    public function testPreviewMatchesPersonsAndChecksComponents(): void
    {
        $files = AttendanceFixture::scenario();
        $preview = $this->service->preview($this->supplierId, self::PERIOD, $files, null, null);

        self::assertSame(3, $preview['summary']['persons']);
        self::assertSame(2, $preview['summary']['matched']);
        self::assertSame(1, $preview['summary']['not_found']);
        self::assertCount(2, $preview['employment_options']);
        self::assertCount(4, $preview['sheets']);
        self::assertSame([null, null, null], array_column($preview['files'], 'error'));

        $persons = $this->byKey($preview['persons']);
        self::assertSame('matched', $persons['jana testovaci']['match']['status']);
        self::assertSame('birth_number', $persons['jana testovaci']['match']['matched_by']);
        self::assertSame($this->jana['employment_id'], $persons['jana testovaci']['match']['employment_id']);
        self::assertSame('name', $persons['petr zkusebni']['match']['matched_by']);
        self::assertSame($this->petr['employment_id'], $persons['petr zkusebni']['match']['employment_id']);
        self::assertSame('not_found', $persons['eva pokusna']['match']['status']);

        $masked = (string) $persons['jana testovaci']['birth_number_masked'];
        $digits = (string) preg_replace('/\D/', '', AttendanceFixture::janaBirthNumber());
        self::assertNotSame('', $masked);
        self::assertStringNotContainsString(substr($digits, 0, 6), $masked);
        $json = json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(AttendanceFixture::janaBirthNumber(), $json);
        self::assertStringNotContainsString($digits, $json);
        foreach ($preview['persons'] as $person) {
            foreach (array_keys($person) as $key) {
                self::assertStringStartsNotWith('_', $key);
            }
        }

        // Úkolová mzda je ve výchozím katalogu složek, který vstupní brána
        // zakládá sama — náhled proto nesmí tvrdit, že chybí.
        $checks = array_column($preview['component_checks'], 'status', 'component_code');
        self::assertSame(['ODMENA' => 'ok', 'MZDA_UKOLOVA' => 'ok', 'PREMIE_PRIPLATKY' => 'ok'], $checks);

        $missing = $this->service->preview($this->supplierId, self::PERIOD, $files, $this->rules($files), null);
        $checks = array_column($missing['component_checks'], null, 'component_code');
        self::assertSame('missing', $checks[self::MISSING_COMPONENT]['status']);
        self::assertStringContainsString('Nastavení mezd', (string) $checks[self::MISSING_COMPONENT]['message']);
    }

    public function testApplyStoresBatchWithHoursAndCreatesInputProposals(): void
    {
        $files = AttendanceFixture::scenario();
        $result = $this->service->apply($this->supplierId, self::PERIOD, $files, $this->rules($files), [], true, true, $this->userId);

        self::assertFalse($result['replayed']);
        self::assertSame(2, $result['batch']['person_count']);
        self::assertSame(8, $result['batch']['metric_count']);
        self::assertSame(3, $result['batch']['input_count']);
        self::assertSame('attendance', $result['batch']['source_system']);
        self::assertSame(self::PERIOD, $result['batch']['period']);
        self::assertSame(['podklady.xlsx', 'provoz.xlsx', 'mzdy.csv'], array_column($result['batch']['files'], 'name'));
        self::assertSame(3, $result['inputs']['created']);
        self::assertSame(0, $result['inputs']['duplicates']);
        self::assertCount(2, $result['inputs']['errors']);
        foreach ($result['inputs']['errors'] as $error) {
            self::assertStringContainsString(self::MISSING_COMPONENT, $error['error_message']);
        }
        self::assertSame(2, $result['links_saved']);
        self::assertSame(['eva pokusna'], array_column($result['skipped_persons'], 'key'));

        self::assertSame(17, $this->countRows(
            'SELECT COUNT(*) FROM payroll_attendance_import_rows WHERE supplier_id = ? AND import_id = ?',
            [$this->supplierId, $result['batch']['id']],
        ));
        self::assertSame(3, $this->countRows('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(3, $this->countRows(
            'SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ? AND status = "draft" AND source_kind = "import"',
            [$this->supplierId],
        ), 'Vstupy z docházky zůstávají návrhy ke schválení.');
        self::assertSame(150000, $this->bonus($this->jana['employment_id']));

        $detail = $this->service->batch($this->supplierId, $result['batch']['id']);
        self::assertNotNull($detail);
        $worked = array_values(array_filter(
            $detail['rows'],
            fn (array $row): bool => $row['employment_id'] === $this->jana['employment_id'] && $row['meaning'] === 'worked_hours',
        ));
        self::assertSame('168.00', $worked[0]['hours']);
        self::assertSame('provoz.xlsx!vstup!B3', $worked[0]['source']);
        self::assertSame('Jana Testovací', $worked[0]['employee_name']);
        self::assertSame('ZAM-J', $worked[0]['employment_code']);

        self::assertCount(1, $this->service->batches($this->supplierId, self::PERIOD));
        self::assertCount(0, $this->service->batches($this->supplierId, '2026-05'));
    }

    public function testRepeatedApplyReturnsTheSameBatch(): void
    {
        $files = AttendanceFixture::scenario();
        $rules = $this->rules($files);
        $first = $this->service->apply($this->supplierId, self::PERIOD, $files, $rules, [], false, true, $this->userId);
        $second = $this->service->apply($this->supplierId, self::PERIOD, $files, $rules, [], false, true, $this->userId);

        self::assertTrue($second['replayed']);
        self::assertSame($first['batch']['id'], $second['batch']['id']);
        self::assertSame(0, $second['inputs']['created']);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM payroll_attendance_imports WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(3, $this->countRows('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?', [$this->supplierId]));
    }

    /**
     * Opravený soubor (jiná odměna) je nová dávka, ale odměna se nezaloží
     * podruhé: stabilní external_id z ní udělá duplicitu a původní částka
     * zůstává, dokud ji účetní vědomě nezruší.
     */
    public function testCorrectedFileDoesNotCreateSecondBonus(): void
    {
        $original = AttendanceFixture::scenario(1500);
        $corrected = AttendanceFixture::scenario(1800);
        $first = $this->service->apply($this->supplierId, self::PERIOD, $original, $this->rules($original), [], false, true, $this->userId);
        $second = $this->service->apply($this->supplierId, self::PERIOD, $corrected, $this->rules($corrected), [], false, true, $this->userId);

        self::assertFalse($second['replayed']);
        self::assertNotSame($first['batch']['id'], $second['batch']['id']);
        self::assertSame(0, $second['inputs']['created']);
        self::assertSame(3, $second['inputs']['duplicates']);
        self::assertSame(3, $this->countRows('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(150000, $this->bonus($this->jana['employment_id']));
    }

    public function testSavedLinksMatchTheNextImport(): void
    {
        $files = AttendanceFixture::scenario();
        $this->service->apply($this->supplierId, self::PERIOD, $files, null, [], true, false, $this->userId);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?', [$this->supplierId]));

        $persons = $this->byKey($this->service->preview($this->supplierId, self::PERIOD, $files, null, null)['persons']);
        self::assertSame('linked', $persons['jana testovaci']['match']['status']);
        self::assertSame('link', $persons['petr zkusebni']['match']['matched_by']);
    }

    public function testManualLinkOverridesMatchAndMustBeValidInPeriod(): void
    {
        $files = AttendanceFixture::scenario();
        $result = $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            $files,
            null,
            [['person_key' => 'eva pokusna', 'employment_id' => $this->petr['employment_id']]],
            false,
            false,
            $this->userId,
        );
        $skipped = array_column($result['skipped_persons'], 'reason', 'key');
        self::assertArrayNotHasKey('eva pokusna', $skipped);
        self::assertStringContainsString('už je přiřazený', $skipped['petr zkusebni']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('neplatí');
        $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            $files,
            null,
            [['person_key' => 'eva pokusna', 'employment_id' => 999_999_999]],
            false,
            false,
            $this->userId,
        );
    }

    public function testPersonsCreatesEmployeeActivatesAndLinks(): void
    {
        $result = $this->service->persons($this->supplierId, self::PERIOD, [
            [
                'person_key' => 'eva pokusna',
                'full_name' => 'Eva Pokusná',
                'first_name' => 'Eva',
                'last_name' => 'Pokusná',
                'birth_number' => null,
                'relation_type' => 'employment',
                'weekly_hours' => '20',
                'planned_start_on' => '2026-06-01',
                'personal_number' => 'Z003',
                'activate' => true,
            ],
            [
                'person_key' => 'bez jmena',
                'full_name' => '',
                'relation_type' => 'employment',
                'planned_start_on' => '2026-06-01',
                'activate' => false,
            ],
        ], $this->userId, null, null);

        self::assertSame('created', $result['results'][0]['status'], (string) $result['results'][0]['message']);
        self::assertSame('failed', $result['results'][1]['status']);
        self::assertNotEmpty($result['results'][1]['message']);
        $employmentId = (int) $result['results'][0]['employment_id'];
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, actual_start_date FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        self::assertSame(['status' => 'active', 'actual_start_date' => '2026-06-01'], $stmt->fetch(PDO::FETCH_ASSOC));
        self::assertSame('Z003', $this->employmentCode($employmentId), 'Nová osoba má osobní číslo z podkladů.');
        self::assertSame(2, $this->countRows(
            'SELECT COUNT(*) FROM payroll_import_links WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, $employmentId],
        ));

        $persons = $this->byKey(
            $this->service->preview($this->supplierId, self::PERIOD, AttendanceFixture::scenario(), null, null)['persons'],
        );
        self::assertSame('linked', $persons['eva pokusna']['match']['status']);
        self::assertSame($employmentId, $persons['eva pokusna']['match']['employment_id']);
    }

    /**
     * Klient zná rodné číslo jen maskované; se soubory ho při zakládání
     * doplní server z podkladů podle klíče osoby.
     */
    public function testPersonsWithFilesTakeBirthNumberFromSourceData(): void
    {
        $result = $this->service->persons($this->supplierId, self::PERIOD, [
            [
                'person_key' => 'petr zkusebni',
                'full_name' => 'Petr Zkušební',
                'first_name' => 'Petr',
                'last_name' => 'Zkušební',
                'birth_number' => null,
                'relation_type' => 'employment',
                'weekly_hours' => '40',
                'planned_start_on' => '2026-06-01',
                'activate' => false,
            ],
        ], $this->userId, null, null, AttendanceFixture::scenario());

        self::assertSame('created', $result['results'][0]['status'], (string) $result['results'][0]['message']);
        $sensitive = $this->container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $stmt = $this->db->pdo()->prepare(
            'SELECT value_masked FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ? AND identifier_type = "birth_number"',
        );
        $stmt->execute([$this->supplierId, (int) $result['results'][0]['employee_id']]);
        self::assertSame(
            [$sensitive->mask(AttendanceFixture::petrBirthNumber(), PayrollSensitiveField::PERSONAL_IDENTIFIER)],
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testProfilesCrudAndPreviewWithProfile(): void
    {
        $rules = [['sheet' => 'výpočet', 'header' => 'Dovolená', 'meaning' => 'vacation_hours', 'unit' => 'hours', 'component_code' => null]];
        $profile = $this->service->saveProfile($this->supplierId, null, 'Docházka výroba', $rules, $this->userId);
        self::assertSame('Docházka výroba', $profile['name']);
        self::assertSame($rules, $profile['rules']);

        $renamed = $this->service->saveProfile($this->supplierId, $profile['id'], 'Docházka sklad', $rules, $this->userId);
        self::assertSame($profile['id'], $renamed['id']);
        self::assertSame(['Docházka sklad'], array_column($this->service->profiles($this->supplierId), 'name'));

        $preview = $this->service->preview($this->supplierId, self::PERIOD, AttendanceFixture::scenario(), null, $profile['id']);
        $petr = $this->byKey($preview['persons'])['petr zkusebni'];
        self::assertSame('20.00', array_column($petr['metrics'], 'hours', 'meaning')['vacation_hours']);

        try {
            $this->service->saveProfile($this->supplierId, null, 'Docházka sklad', $rules, $this->userId);
            self::fail('Dva profily se stejným názvem nesmí vzniknout.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('už existuje', $e->getMessage());
        }

        self::assertTrue($this->service->deleteProfile($this->supplierId, $profile['id']));
        self::assertFalse($this->service->deleteProfile($this->supplierId, $profile['id']));
        self::assertSame([], $this->service->profiles($this->supplierId));
    }

    public function testActionDecodesFilesAndAnswersWithoutRawBirthNumber(): void
    {
        $action = $this->container->get(PayrollAttendanceImportAction::class);
        self::assertInstanceOf(PayrollAttendanceImportAction::class, $action);
        $files = array_map(
            static fn (array $file): array => ['name' => $file['name'], 'content_base64' => base64_encode($file['content'])],
            AttendanceFixture::scenario(),
        );

        $preview = $action->preview(
            $this->request('POST', '/api/payroll/imports/attendance/preview')
                ->withParsedBody(['period' => self::PERIOD, 'files' => $files, 'rules' => null, 'profile_id' => null]),
            new Response(),
        );
        self::assertSame(200, $preview->getStatusCode(), $this->body($preview));
        self::assertStringNotContainsString(AttendanceFixture::janaBirthNumber(), $this->body($preview));
        self::assertCount(3, $this->json($preview)['persons']);

        $apply = $action->apply(
            $this->request('POST', '/api/payroll/imports/attendance/apply')->withParsedBody([
                'period' => self::PERIOD,
                'files' => $files,
                'rules' => $this->json($preview)['rules'],
                'links' => [],
                'save_links' => false,
                'create_inputs' => true,
            ]),
            new Response(),
        );
        self::assertSame(201, $apply->getStatusCode(), $this->body($apply));
        $batchId = (int) $this->json($apply)['batch']['id'];

        $detail = $action->batch($this->request('GET', "/api/payroll/imports/attendance/batches/{$batchId}"), new Response(), ['id' => (string) $batchId]);
        self::assertSame(200, $detail->getStatusCode(), $this->body($detail));
        self::assertSame($batchId, $this->json($detail)['batch']['id']);

        $missing = $action->preview(
            $this->request('POST', '/api/payroll/imports/attendance/preview')->withParsedBody(['period' => self::PERIOD, 'files' => []]),
            new Response(),
        );
        self::assertSame(422, $missing->getStatusCode());

        $token = $action->preview(
            $this->request('POST', '/api/payroll/imports/attendance/preview')
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'token')
                ->withParsedBody(['period' => self::PERIOD, 'files' => $files]),
            new Response(),
        );
        self::assertNotSame(200, $token->getStatusCode(), 'Import s osobními údaji je jen pro přihlášenou session.');
    }

    /**
     * Osobní číslo z podkladů se převezme jen tam, kde vztah nese automatický
     * kód; ručně zadané číslo zůstává a rozdíl se ohlásí.
     */
    public function testPersonalNumbersAreAdoptedOnlyOverGeneratedCodes(): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_employments SET code = "ZAM-7" WHERE supplier_id = ? AND id = ?')
            ->execute([$this->supplierId, $this->jana['employment_id']]);

        $result = $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            AttendanceFixture::scenario(),
            null,
            [],
            false,
            false,
            $this->userId,
            adoptPersonalNumbers: true,
        );

        self::assertSame(1, $result['personal_numbers_adopted']);
        $conflicts = array_column($result['personal_number_conflicts'], 'reason', 'key');
        self::assertSame(['petr zkusebni'], array_keys($conflicts));
        self::assertStringContainsString('ZAM-P', $conflicts['petr zkusebni']);
        self::assertSame('Z001', $this->employmentCode($this->jana['employment_id']));
        self::assertSame('ZAM-P', $this->employmentCode($this->petr['employment_id']));

        $persons = $this->byKey($this->service->preview($this->supplierId, self::PERIOD, AttendanceFixture::scenario(), null, null)['persons']);
        self::assertSame('Z001', $persons['jana testovaci']['match']['employment_code']);
    }

    private function employmentCode(int $employmentId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT code FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $employmentId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * Složku, kterou profil definuje a firma nemá, import založí jen na
     * potvrzení — pak částky projdou vstupní branou bez chyby.
     */
    public function testMissingComponentDefinedByProfileIsCreatedOnConfirmation(): void
    {
        $files = AttendanceFixture::scenario();
        $rules = $this->rules($files);
        $components = [['code' => self::MISSING_COMPONENT, 'name' => 'Syntetický úkol', 'kind' => 'task_wage']];

        $preview = $this->service->preview($this->supplierId, self::PERIOD, $files, $rules, null, $components);
        $check = array_column($preview['component_checks'], null, 'component_code')[self::MISSING_COMPONENT];
        self::assertSame('will_create', $check['status']);
        self::assertSame('Syntetický úkol', $check['name']);

        $result = $this->service->apply(
            $this->supplierId,
            self::PERIOD,
            $files,
            $rules,
            [],
            false,
            true,
            $this->userId,
            $components,
            true,
        );
        self::assertSame([self::MISSING_COMPONENT], $result['components_created']);
        self::assertSame([], $result['inputs']['errors']);
        self::assertSame(5, $result['inputs']['created']);
        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND frequency_kind = "one_off" AND component_kind = "task_wage"',
            [$this->supplierId, self::MISSING_COMPONENT],
        ));
    }

    public function testProfileExportImportAndCopyToAnotherSupplier(): void
    {
        $rules = [['sheet' => 'výpočet*', 'header' => '*', 'meaning' => 'component', 'unit' => 'amount', 'component_code' => '*']];
        $components = [['code' => 'PRIPLATEK_TEST', 'name' => 'Syntetický příplatek', 'kind' => 'premium']];
        $profile = $this->service->saveProfile($this->supplierId, null, 'Sdílený profil', $rules, $this->userId, $components);

        $export = $this->service->exportProfile($this->supplierId, $profile['id']);
        self::assertSame(AttendanceImportService::EXPORT_FORMAT, $export['format']);
        $imported = $this->service->importProfile($this->supplierId, $export, null, $this->userId);
        self::assertSame('Sdílený profil (2)', $imported['name']);
        self::assertSame($profile['rules'], $imported['rules']);
        self::assertSame($components, $imported['components']);
        self::assertFalse($imported['is_sample']);

        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        $copy = $this->service->copyProfile($this->supplierId, $profile['id'], $other, $this->userId);
        self::assertSame('Sdílený profil', $copy['name']);
        self::assertSame($components, $copy['components']);
        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM payroll_import_profiles WHERE supplier_id = ?',
            [$other],
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->importProfile($this->supplierId, ['format' => 'jiny', 'version' => 1], null, $this->userId);
    }

    /**
     * Pravidla z náhledu s úkolovou mzdou přemapovanou na složku, která ve
     * firmě neexistuje — test tak ověří, že chybějící složka skončí chybou.
     *
     * @param list<array{name:string,content:string,sha256:string,extension:string}> $files
     * @return list<array<string,mixed>>
     */
    private function rules(array $files): array
    {
        $rules = $this->service->preview($this->supplierId, self::PERIOD, $files, null, null)['rules'];
        foreach ($rules as &$rule) {
            if (($rule['component_code'] ?? null) === 'MZDA_UKOLOVA') {
                $rule['component_code'] = self::MISSING_COMPONENT;
            }
        }
        unset($rule);

        return $rules;
    }

    /** @return array{employee_id:int,employment_id:int} */
    private function createEmployment(string $name, string $code): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)',
        )->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor, is_legacy_projection, is_primary)
             VALUES (?, ?, ?, "employment", "active", "2026-01-01", "2026-01-01", 4200000, 0, 1)',
        )->execute([$this->supplierId, $employeeId, $code]);

        return ['employee_id' => $employeeId, 'employment_id' => (int) $pdo->lastInsertId()];
    }

    private function insertBirthNumber(int $employeeId, string $value): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type, value_ciphertext, value_hash, value_masked)
             VALUES (?, ?, "birth_number", "enc:v2:pending", ?, "")',
        )->execute([$this->supplierId, $employeeId, random_bytes(32)]);
        $id = (int) $pdo->lastInsertId();
        $sensitive = $this->container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $sealed = $sensitive->seal($value, PayrollSensitiveField::PERSONAL_IDENTIFIER, $this->supplierId, $id);
        $pdo->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([$sealed->ciphertext, $sealed->lookupHash, $sealed->masked, $this->supplierId, $id]);
    }

    private function createComponent(string $code): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code,
                 valid_from, is_active)
             VALUES (?, ?, ?, "bonus", "monetary", "one_off", "included",
                     "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "521", "331", "2026-01-01", 1)',
        )->execute([$this->supplierId, $code, "Syntetická {$code}"]);
    }

    private function bonus(int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT amount_minor FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND external_id = ?',
        );
        $stmt->execute([$this->supplierId, $employmentId, 'attendance:' . self::PERIOD . ":{$employmentId}:ODMENA"]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $rows, 'Odměna z docházky má existovat právě jednou.');

        return (int) $rows[0];
    }

    /** @param list<mixed> $params */
    private function countRows(string $sql, array $params): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<array<string,mixed>> $persons
     * @return array<string,array<string,mixed>>
     */
    private function byKey(array $persons): array
    {
        return array_column($persons, null, 'key');
    }

    private function request(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode($this->body($response), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
