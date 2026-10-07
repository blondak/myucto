<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Celý řetěz NEMPRI od případu dávky: evidence případu → načtení a odhalení
 * dítěte, výplatního účtu a měsíců rozhodného období → věta proti XSD.
 *
 * Jednotkové testy ověřují mapování; tenhle ověřuje, že ho někdo VOLÁ — že
 * služba dostane z databáze skutečný nástup, vyživovanou osobu i účet
 * a že případ ošetřovného, dřív zablokovaný, projde až do náhledu věty.
 * Všechna data jsou syntetická.
 */
#[Group('integration')]
final class PayrollSicknessNempriPreviewTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ContainerInterface $container;
    private int $supplierId;
    private int $userId;
    private int $officeId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $connection = $this->container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        // Starý symbol na firmě se schválně liší: podání ho číst nesmí,
        // identifikátory zaměstnavatele žijí v Mzdách (VS u účtárny vztahu,
        // kód OSSZ v nastavení zaměstnavatele).
        $pdo->prepare(
            'UPDATE supplier
                SET cssz_vsdp = "9999999999", cssz_ossz_code = 999,
                    ic = "12345678", company_name = "Testovací zaměstnavatel s.r.o."
              WHERE id = ?'
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol, is_active)
             VALUES (?, "NEM", "Syntetická účtárna", "1234567890", 1)',
        )->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, social_security_office_code)
             VALUES (?, ?, "115")',
        )->execute([$this->supplierId, $this->officeId]);
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testCareBenefitCaseBuildsCompleteSubmission(): void
    {
        [$employeeId, $employmentId] = $this->employee();
        $dependantId = $this->dependant($employeeId);
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->create(
            $this->supplierId,
            'test',
            $employmentId,
            'OSE',
            [
                'incapacity_from' => '2026-02-09',
                'incapacity_to' => '2026-02-13',
                'decision_number' => '1234567N',
                'daily_working_hours' => '8',
                'action_start' => true,
                'action_end' => true,
                'worked_last_day' => false,
                'cared_dependant_id' => $dependantId,
                'care_reason' => 'ill',
                'shared_household' => true,
                'child_under_16' => true,
                'cared_personally' => true,
                'care_days' => [['from' => '2026-02-09', 'to' => '2026-02-13']],
                'relationship_code' => 'PL',
                'planned_shifts' => true,
                'planned_shifts_worked' => false,
                'contact_worker_name' => 'Mzdová Účetní',
                'contact_worker_email' => 'mzdy@example.test',
                'decisive_months' => [
                    ['period' => '2025-10', 'income_minor' => 2_600_000, 'excluded_days' => 0],
                    ['period' => '2025-11', 'income_minor' => 3_000_000, 'excluded_days' => 2],
                    ['period' => '2025-12', 'income_minor' => 3_100_000, 'excluded_days' => 0],
                    ['period' => '2026-01', 'income_minor' => 3_000_000, 'excluded_days' => 0],
                ],
            ],
            $this->userId,
        );

        $preview = $this->service(SicknessSubmissionService::class)->preview(
            $this->supplierId,
            'test',
            (int) $case['id'],
            SicknessDocumentKind::Nempri,
        );
        $xml = (string) $preview['xml'];
        // Bod 6: symbol zaměstnavatele z účtárny vztahu, stejně jako registrace.
        self::assertStringContainsString('1234567890', $xml);
        self::assertStringNotContainsString('9999999999', $xml);

        self::assertStringContainsString('<druhDavky>OSE</druhDavky>', $xml);
        // Skutečný nástup, ne sjednaný.
        self::assertStringContainsString('<zamestnanOd>2025-10-06</zamestnanOd>', $xml);
        // Dítě z evidence vyživovaných osob i s odhaleným rodným číslem.
        self::assertStringContainsString('<rodneCislo>1501010007</rodneCislo>', $xml);
        self::assertStringContainsString('<jmeno>Dítě</jmeno>', $xml);
        // Rozhodné období nese všechny měsíce (i leden 2026, který pokrylo
        // měsíční hlášení) a oba součty — DV NEMPRI25, kontroly 7 a 8.
        self::assertStringContainsString('<rozhodneObdobiOd>2025-10-06</rozhodneObdobiOd>', $xml);
        self::assertStringContainsString('<rozhodneObdobiDo>2026-01-31</rozhodneObdobiDo>', $xml);
        self::assertSame(4, substr_count($xml, '<zapocitatelnyPrijem>'));
        self::assertStringContainsString('<zapocitatelnyPrijemCelkem>117000</zapocitatelnyPrijemCelkem>', $xml);
        self::assertStringContainsString('<vylouceneDnyCelkem>2</vylouceneDnyCelkem>', $xml);
        self::assertStringNotContainsString('pravdepodobnaVysePrijmu', $xml);
        // Výplatní účet mzdy.
        self::assertStringContainsString('<vyplatitUcetCR>true</vyplatitUcetCR>', $xml);
        self::assertStringContainsString('<ucetCislo>1000000005</ucetCislo>', $xml);
        self::assertStringContainsString('<bankaKod>0100</bankaKod>', $xml);
        self::assertStringContainsString('<kontaktniPracovnik>Mzdová Účetní</kontaktniPracovnik>', $xml);

        // Příprava zmrazí přesně tutéž větu a případ posune na „připraveno“.
        $prepared = $this->service(SicknessSubmissionService::class)->prepare(
            $this->supplierId,
            'test',
            (int) $case['id'],
            SicknessDocumentKind::Nempri,
            $this->userId,
        );
        self::assertSame('ready', $prepared['status']);
        self::assertSame(hash('sha256', $xml), $prepared['artifact_sha256']);
        $stored = $this->service(SicknessCaseService::class)
            ->requireCase($this->supplierId, 'test', (int) $case['id']);
        self::assertSame('prepared', $stored['status']);
        self::assertSame((int) $prepared['submission_id'], (int) $stored['nempri_submission_id']);
    }

    /**
     * HZUPN „nevrátil se do práce“ s důvodem a datem — celý tok od uložení
     * případu po věnu, kterou ČSSZ od jiných programů přijímá.
     */
    public function testEndOfIncapacityWithoutReturnBuildsHzupn(): void
    {
        [, $employmentId] = $this->employee();
        $cases = $this->service(SicknessCaseService::class);
        $case = $cases->create(
            $this->supplierId,
            'test',
            $employmentId,
            'NEM',
            [
                'incapacity_from' => '2026-02-09',
                'decision_number' => 'A1234567',
            ],
            $this->userId,
        );
        $case = $cases->update(
            $this->supplierId,
            'test',
            (int) $case['id'],
            (int) $case['row_version'],
            [
                'incapacity_to' => '2026-03-15',
                'issued_on' => '2026-03-16',
                'returned_to_work' => '0',
                'return_reason' => 'skončení zaměstnání',
                'returned_on' => '2026-03-16',
            ],
        );

        $preview = $this->service(SicknessSubmissionService::class)->preview(
            $this->supplierId,
            'test',
            (int) $case['id'],
            SicknessDocumentKind::Hzupn,
        );

        self::assertStringContainsString('<navratDoPrace>N</navratDoPrace>', (string) $preview['xml']);
        self::assertStringContainsString('<duvodNavratDoPrace>skončení zaměstnání</duvodNavratDoPrace>', (string) $preview['xml']);
        // DV HZUPN20: datum návratu patří jen k odpovědi „ano“; řádek případu
        // ho drží kvůli lhůtě, ve větě být nesmí.
        self::assertStringNotContainsString('datumNavratDoPrace', (string) $preview['xml']);
    }

    /**
     * Kód z cizího číselníku se odmítne už při uložení případu: „PL“ je
     * vztah u ošetřovného (CIS_RODVZTAH), u dlouhodobého ošetřovného platí
     * CIS_VZTAH. Dřív šlo uložit cokoli o 1 až 3 znacích.
     */
    public function testCaseRefusesCodeFromAnotherCodebookOnSave(): void
    {
        [, $employmentId] = $this->employee();
        $cases = $this->service(SicknessCaseService::class);
        foreach ([
            ['OSE', ['relationship_code' => 'AB'], 'nempri_relationship_code_invalid'],
            ['DLO', ['relationship_code' => 'PL'], 'nempri_relationship_code_invalid'],
            ['OPP', ['paternity_reason' => '1'], 'nempri_paternity_reason_invalid'],
            ['NEM', ['maternity_care_reason' => 'ROZ'], 'nempri_maternity_care_reason_not_in_kind'],
        ] as [$kind, $input, $code]) {
            try {
                $cases->create(
                    $this->supplierId,
                    'test',
                    $employmentId,
                    $kind,
                    ['incapacity_from' => '2026-02-09', ...$input],
                    $this->userId,
                );
                self::fail("Případ {$kind} s kódem mimo číselník se nesmí uložit.");
            } catch (SicknessException $exception) {
                self::assertSame($code, $exception->validationCode);
            }
        }

        $dlo = $cases->create(
            $this->supplierId,
            'test',
            $employmentId,
            'DLO',
            ['incapacity_from' => '2026-02-09', 'relationship_code' => '3'],
            $this->userId,
        );
        self::assertSame('3', $dlo['relationship_code']);
    }

    /**
     * Ochranná lhůta (§ 15 zák. č. 187/2006 Sb.): neschopnost do 7 dnů po
     * skončení zaměstnání se ještě předává, osmý den už ne. Dřív šlo případ
     * založit i připravit s jakýmkoli dnem vzniku; seznam případů teď říká,
     * že případ vznikl v ochranné lhůtě.
     */
    public function testProtectionPeriodAfterEmploymentEnd(): void
    {
        [, $employmentId] = $this->employee();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET end_date = "2026-03-31", status = "ended"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $employmentId]);
        $cases = $this->service(SicknessCaseService::class);

        try {
            $cases->create(
                $this->supplierId,
                'test',
                $employmentId,
                'NEM',
                ['incapacity_from' => '2026-04-08', 'decision_number' => 'A1234567'],
                $this->userId,
            );
            self::fail('Neschopnost osmý den po skončení zaměstnání nárok nezakládá.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_event_outside_protection_period', $exception->validationCode);
        }

        $case = $cases->create(
            $this->supplierId,
            'test',
            $employmentId,
            'NEM',
            ['incapacity_from' => '2026-04-07', 'decision_number' => 'A1234567'],
            $this->userId,
        );
        $listed = array_values(array_filter(
            $cases->list($this->supplierId, 'test', $employmentId),
            static fn (array $row): bool => (int) $row['id'] === (int) $case['id'],
        ));
        self::assertSame('protection_period', $listed[0]['protection_period']['status']);
        self::assertSame('2026-04-07', $listed[0]['protection_period']['protection_until']);

        try {
            $cases->update(
                $this->supplierId,
                'test',
                (int) $case['id'],
                (int) $case['row_version'],
                ['incapacity_from' => '2026-04-09'],
            );
            self::fail('Posunutí vzniku za ochrannou lhůtu nesmí projít.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_event_outside_protection_period', $exception->validationCode);
        }
    }

    public function testDecisiveMonthWithoutAnySourceStopsThePreview(): void
    {
        [, $employmentId] = $this->employee();
        $case = $this->service(SicknessCaseService::class)->create(
            $this->supplierId,
            'test',
            $employmentId,
            'NEM',
            [
                'incapacity_from' => '2026-02-09',
                'decision_number' => 'A1234567',
            ],
            $this->userId,
        );

        try {
            $this->service(SicknessSubmissionService::class)->preview(
                $this->supplierId,
                'test',
                (int) $case['id'],
                SicknessDocumentKind::Nempri,
            );
            self::fail('Měsíce 2025 bez převzaté mzdy nesmí z věty tiše vypadnout.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_decisive_month_missing', $exception->validationCode);
        }
    }

    /**
     * Q8-25: chybějící údaje se hlásily po jednom — nejdřív rozhodné období,
     * po doplnění číslo rozhodnutí. Náhled je teď vypíše najednou.
     */
    public function testPreviewListsAllMissingCaseDataAtOnce(): void
    {
        [, $employmentId] = $this->employee();
        $case = $this->service(SicknessCaseService::class)->create(
            $this->supplierId,
            'test',
            $employmentId,
            'NEM',
            ['incapacity_from' => '2026-02-09'],
            $this->userId,
        );

        try {
            $this->service(SicknessSubmissionService::class)->preview(
                $this->supplierId,
                'test',
                (int) $case['id'],
                SicknessDocumentKind::Nempri,
            );
            self::fail('Náhled bez rozhodného období a čísla rozhodnutí nesmí projít.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_decisive_month_missing', $exception->validationCode);
            self::assertStringContainsString('číslo rozhodnutí', $exception->getMessage());
            self::assertGreaterThanOrEqual(2, count(explode("\n", $exception->getMessage())));
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * NX-02: ošetřovné jen s akcí ukončení nenese rozhodné období ani
     * platební spojení (DV NEMPRI25 je bez vzniku zakazuje). Zaměstnanec
     * bez výplatního účtu proto podání nezablokuje; dřív náhled spadl
     * na `nempri_payment_connection_missing`.
     */
    public function testCareContinuationWithoutStartNeedsNoPaymentConnection(): void
    {
        [$employeeId, $employmentId] = $this->employee(withAccount: false);
        $case = $this->service(SicknessCaseService::class)->create(
            $this->supplierId,
            'test',
            $employmentId,
            'OSE',
            [
                'incapacity_from' => '2026-02-09',
                'incapacity_to' => '2026-02-13',
                'decision_number' => '1234567N',
                'daily_working_hours' => '8',
                'action_start' => false,
                'action_end' => true,
                'planned_shifts' => false,
                'worked_last_day' => false,
                'cared_dependant_id' => $this->dependant($employeeId),
                'care_reason' => 'ill',
                'care_days' => [['from' => '2026-02-09', 'to' => '2026-02-13']],
                'relationship_code' => 'PL',
            ],
            $this->userId,
        );

        $xml = (string) $this->service(SicknessSubmissionService::class)->preview(
            $this->supplierId,
            'test',
            (int) $case['id'],
            SicknessDocumentKind::Nempri,
        )['xml'];

        self::assertStringContainsString('<druhDavky>OSE</druhDavky>', $xml);
        self::assertStringNotContainsString('platebniSpojeni', $xml);
        self::assertStringNotContainsString('rozhodneObdobi', $xml);
    }

    /**
     * NX-02: mzda vyplácená přes partnera — účet ani adresu pro výplatu dávky
     * nevymýšlíme a větu bez platebního spojení neposíláme.
     */
    public function testPartnerSettlementStopsThePreviewWithClearReason(): void
    {
        [$employeeId, $employmentId] = $this->employee();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employee_profiles SET payout_method = "partner_settlement"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $employeeId]);
        $case = $this->service(SicknessCaseService::class)->create(
            $this->supplierId,
            'test',
            $employmentId,
            'NEM',
            [
                'incapacity_from' => '2026-02-09',
                'decision_number' => 'A1234567',
                'daily_working_hours' => '8',
                'decisive_months' => self::months(['2025-10', '2025-11', '2025-12', '2026-01']),
            ],
            $this->userId,
        );

        try {
            $this->service(SicknessSubmissionService::class)->preview(
                $this->supplierId,
                'test',
                (int) $case['id'],
                SicknessDocumentKind::Nempri,
            );
            self::fail('Věta bez platebního spojení se nesmí sestavit.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_payment_connection_partner_settlement', $exception->validationCode);
        }
    }

    /**
     * NRO-03 (§ 19 odst. 11): zaměstnání skončilo 27. 3., neschopnost 2. 4.
     * v ochranné lhůtě. Rozhodný den je 28. 3., takže období končí únorem
     * — ne březnem, jak by vyšlo ze dne vzniku neschopnosti.
     */
    public function testProtectionPeriodDecisivePeriodEndsBeforeTheEmploymentEndMonth(): void
    {
        [, $employmentId] = $this->employee();
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET end_date = "2026-03-27", status = "ended"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $employmentId]);
        $case = $this->service(SicknessCaseService::class)->create(
            $this->supplierId,
            'test',
            $employmentId,
            'NEM',
            [
                'incapacity_from' => '2026-04-02',
                'decision_number' => 'A1234567',
                'daily_working_hours' => '8',
                'decisive_months' => self::months(['2025-10', '2025-11', '2025-12', '2026-01', '2026-02']),
            ],
            $this->userId,
        );

        $xml = (string) $this->service(SicknessSubmissionService::class)->preview(
            $this->supplierId,
            'test',
            (int) $case['id'],
            SicknessDocumentKind::Nempri,
        )['xml'];

        self::assertStringContainsString('<rozhodneObdobiOd>2025-10-06</rozhodneObdobiOd>', $xml);
        self::assertStringContainsString('<rozhodneObdobiDo>2026-02-28</rozhodneObdobiDo>', $xml);
        self::assertSame(5, substr_count($xml, '<zapocitatelnyPrijem>'));
    }

    /**
     * @param list<string> $periods
     * @return list<array{period:string,income_minor:int,excluded_days:int}>
     */
    private static function months(array $periods): array
    {
        return array_map(
            static fn (string $period): array => ['period' => $period, 'income_minor' => 3_000_000, 'excluded_days' => 0],
            $periods,
        );
    }

    /** @return array{0:int,1:int} */
    private function employee(bool $withAccount = true): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Jan Testovací", "employee", "hpp", 0, 0, 0, NULL, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status, payout_method)
             VALUES (?, ?, "ready", "bank")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 birth_date, effective_from)
             VALUES (?, ?, "Jan Testovací", "Jan", "Testovací", "1980-01-01", "1980-01-01")'
        )->execute([$this->supplierId, $employeeId]);
        $this->sealed(
            'INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type,
                 value_ciphertext, value_hash, value_masked)
             VALUES (?, ?, "birth_number", "enc:v2:pending", ?, "")',
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $employeeId, random_bytes(32)],
            '8001010008',
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
        );
        if ($withAccount) {
            $this->sealed(
                'INSERT INTO payroll_person_accounts
                    (supplier_id, employee_id, label, bank_account_ciphertext,
                     bank_account_hash, bank_account_masked, effective_from)
                 VALUES (?, ?, "Mzda", "enc:v2:pending", ?, "", "2020-01-01")',
                'UPDATE payroll_person_accounts
                    SET bank_account_ciphertext = ?, bank_account_hash = ?,
                        bank_account_masked = ?
                  WHERE supplier_id = ? AND id = ?',
                [$this->supplierId, $employeeId, random_bytes(32)],
                '1000000005/0100',
                PayrollSensitiveField::BANK_ACCOUNT,
            );
        }
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor)
             VALUES (?, ?, ?, ?, "employment", "active", "2025-10-01", "2025-10-06", 3000000)'
        )->execute([$this->supplierId, $employeeId, $this->officeId, 'NEMPRI-' . $employeeId]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on,
                 actual_start_on, weekly_hours,
                 workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime,
                 tax_declaration_signed, is_primary, activity_code,
                 monthly_gross_minor)
             VALUES (?, ?, "2025-10-01", "2025-10-01", "2025-10-06", 40, 10000,
                     "automatic", "automatic", "advance", 0, 1, "1", 3000000)'
        )->execute([$this->supplierId, $employmentId]);

        return [$employeeId, $employmentId];
    }

    private function dependant(int $employeeId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_dependants
                (supplier_id, employee_id, relation, full_name, given_name,
                 family_name, birth_date, existence_from)
             VALUES (?, ?, "child_own", "Dítě Testovací", "Dítě", "Testovací",
                     "2015-01-01", "2015-01-01")'
        )->execute([$this->supplierId, $employeeId]);
        $dependantId = (int) $pdo->lastInsertId();
        $sealed = $this->sensitive()->seal(
            '1501010007',
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $this->supplierId,
            $dependantId,
        );
        $pdo->prepare(
            'UPDATE payroll_dependants
                SET birth_number_ciphertext = ?, birth_number_hash = ?,
                    birth_number_masked = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $this->supplierId,
            $dependantId,
        ]);

        return $dependantId;
    }

    /** @param list<mixed> $insertParams */
    private function sealed(
        string $insert,
        string $update,
        array $insertParams,
        string $plaintext,
        PayrollSensitiveField $field,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare($insert)->execute($insertParams);
        $id = (int) $pdo->lastInsertId();
        $sealed = $this->sensitive()->seal($plaintext, $field, $this->supplierId, $id);
        $pdo->prepare($update)->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $this->supplierId,
            $id,
        ]);
    }

    private function sensitive(): PayrollSensitiveData
    {
        return $this->service(PayrollSensitiveData::class);
    }
}
