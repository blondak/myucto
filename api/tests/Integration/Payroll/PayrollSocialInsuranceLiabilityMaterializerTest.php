<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollPaymentLiabilityRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentQueryService;
use MyInvoice\Service\Payroll\Payment\PayrollSocialInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollSocialInsuranceLiabilityMaterializerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollSensitiveData $sensitiveData;
    private PayrollPaymentBatchBuilder $batches;
    private SecretEncryption $encryption;
    private int $supplierId;
    private int $actorId;
    private int $employeeId;
    private int $officeId;
    private int $runId;
    private int $payerCurrencyId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $sensitiveData = $container->get(PayrollSensitiveData::class);
        $batches = $container->get(PayrollPaymentBatchBuilder::class);
        $encryption = $container->get(SecretEncryption::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitiveData);
        self::assertInstanceOf(PayrollPaymentBatchBuilder::class, $batches);
        self::assertInstanceOf(SecretEncryption::class, $encryption);
        $this->db = $connection;
        $this->sensitiveData = $sensitiveData;
        $this->batches = $batches;
        $this->encryption = $encryption;
        $pdo = $connection->pdo();
        $source = $pdo->query('SELECT MIN(id) FROM supplier');
        self::assertInstanceOf(\PDOStatement::class, $source);
        $sourceSupplierId = (int) $source->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $this->actorId = $this->createActor($pdo);
        $this->payerCurrencyId = $this->createPayerCurrency($pdo);
        $pdo->prepare(
            'UPDATE supplier
                SET company_name = "Syntetická sociální firma",
                    display_name = NULL
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická sociální osoba", "employee", 1)',
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_offices
                (supplier_id, code, name, social_security_variable_symbol,
                 is_active, row_version)
             VALUES (?, "PLZEN", "Syntetická účtárna Plzeň",
                     "0012345678", 1, 1)',
        )->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id,
                 social_security_office_code)
             VALUES (?, ?, "P")',
        )->execute([$this->supplierId, $this->officeId]);
        (new PayrollInstitutionAccountRepository(
            $connection,
            $sensitiveData,
        ))->create($this->supplierId, [
            'institution_type' => 'social_security',
            'institution_code' => 'P',
            'institution_name' => 'Syntetická správa sociálního zabezpečení',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => null,
            'specific_symbol' => null,
            'constant_symbol' => '7618',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:cssz-account-notice',
            'verified_on' => '2026-06-15',
        ], $this->actorId);
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, office_id, period_start, payment_date,
                 status, current_revision_no)
             VALUES (?, ?, "2026-06-01", "2026-07-10",
                     "approved", 1)',
        )->execute([$this->supplierId, $this->officeId]);
        $this->runId = (int) $pdo->lastInsertId();
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

    public function testMaterializesOfficeScopedLiabilityAndCorrection(): void
    {
        $service = $this->service();
        $regularRevision = $this->createRevision(
            1,
            'regular',
            null,
            7_100,
            24_800,
            7_100,
        );
        $regular = $service->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        $replay = $service->materialize(
            $this->supplierId,
            $regularRevision,
            $this->actorId,
        );
        self::assertSame(1, $regular['created_count']);
        self::assertSame(0, $replay['created_count']);
        self::assertSame($regular['liability_ids'], $replay['liability_ids']);
        $row = $this->liability($regular['liability_ids'][0]);
        self::assertSame('social_insurance', $row['liability_kind']);
        self::assertSame('outgoing', $row['direction']);
        self::assertSame('2026-07-20', $row['due_on']);
        self::assertSame(31_900, $this->integer(
            $row,
            'amount_minor',
        ));
        self::assertNull($row['employee_id']);
        $source = $this->jsonObject(
            $this->string($row, 'source_snapshot_json'),
        );
        self::assertSame(
            'payroll-payment-social-insurance-source.v1',
            $source['schema_reference'],
        );
        self::assertSame('0012345678', $source['variable_symbol']);
        self::assertSame($this->officeId, $source['payroll_office_id']);
        self::assertStringNotContainsString(
            '1000000005',
            $this->string($row, 'source_snapshot_json'),
        );
        self::assertArrayNotHasKey('bank_account_ciphertext', $source);
        $listed = (new PayrollPaymentQueryService($this->db))
            ->listForPeriod($this->supplierId, '2026-06');
        self::assertSame('ready', $listed[0]['batch_eligibility'] ?? null);
        $batch = $this->batches->build(
            $this->supplierId,
            'abo',
            "currency:{$this->payerCurrencyId}",
            [[
                'liability_id' => $regular['liability_ids'][0],
                'amount_minor' => 31_900,
            ]],
            $this->actorId,
        );
        $instruction = $this->batchInstruction($batch['batch_id']);
        self::assertSame('0012345678', $instruction['variable_symbol']);
        self::assertSame('7618', $instruction['constant_symbol']);
        self::assertSame(
            'Socialni pojisteni P',
            $instruction['payment_message'],
        );

        $correctionRevision = $this->createRevision(
            2,
            'correction',
            $regularRevision,
            7_100,
            25_000,
            7_100,
        );
        $correction = $service->materialize(
            $this->supplierId,
            $correctionRevision,
            $this->actorId,
        );
        $correctionRow = $this->liability(
            $correction['liability_ids'][0],
        );
        self::assertSame('outgoing', $correctionRow['direction']);
        self::assertSame(200, $this->integer(
            $correctionRow,
            'amount_minor',
        ));
        self::assertSame(
            $regular['liability_ids'][0],
            $this->integer($correctionRow, 'previous_liability_id'),
        );

        $decreaseRevision = $this->createRevision(
            3,
            'correction',
            $correctionRevision,
            7_100,
            24_500,
            7_100,
        );
        $decrease = $service->materialize(
            $this->supplierId,
            $decreaseRevision,
            $this->actorId,
        );
        $decreaseRow = $this->liability($decrease['liability_ids'][0]);
        self::assertSame('incoming', $decreaseRow['direction']);
        self::assertSame(500, $this->integer(
            $decreaseRow,
            'amount_minor',
        ));
    }

    public function testRejectsRootPersonTotalMismatch(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            7_100,
            24_800,
            7_000,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('součtu osob');
        $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    public function testDoesNotUseAnotherTenantsEffectiveAccount(): void
    {
        $source = $this->db->pdo()->query(
            'SELECT MIN(id) FROM supplier',
        );
        self::assertInstanceOf(\PDOStatement::class, $source);
        $otherSupplierId = $this->createIsolatedSupplier(
            $this->db->pdo(),
            (int) $source->fetchColumn(),
        );
        (new PayrollInstitutionAccountRepository(
            $this->db,
            $this->sensitiveData,
        ))->create($otherSupplierId, [
            'institution_type' => 'social_security',
            'institution_code' => 'P',
            'institution_name' => 'Jiná syntetická správa',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => null,
            'specific_symbol' => null,
            'constant_symbol' => '7618',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:other-tenant-cssz',
            'verified_on' => '2026-06-15',
        ], $this->actorId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET valid_to = "2026-07-19",
                    row_version = row_version + 1
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            7_100,
            24_800,
            7_100,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nemá účinný ověřený účet');
        $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    public function testRejectsEmployerDiscountMismatch(): void
    {
        $revisionId = $this->createRevision(
            1,
            'regular',
            null,
            7_100,
            24_800,
            7_100,
            25_000,
            100,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('odvodu před slevou');
        $this->service()->materialize(
            $this->supplierId,
            $revisionId,
            $this->actorId,
        );
    }

    private function service(): PayrollSocialInsuranceLiabilityMaterializer
    {
        return new PayrollSocialInsuranceLiabilityMaterializer(
            new PayrollPaymentLiabilityRepository($this->db),
            new PayrollStatutoryResultRepository($this->db),
            new PayrollInstitutionAccountRepository(
                $this->db,
                $this->sensitiveData,
            ),
            $this->sensitiveData,
            $this->db,
        );
    }

    private function createRevision(
        int $revisionNo,
        string $revisionKind,
        ?int $previousRevisionId,
        int $employeeContribution,
        int $employerContribution,
        int $personEmployeeContribution,
        ?int $employerBeforeDiscount = null,
        int $partTimeDiscount = 0,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_runs
                SET current_revision_no = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([$revisionNo, $this->supplierId, $this->runId]);
        $input = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => '2026-06-01',
            'payment_date' => '2026-07-10',
            'office_id' => $this->officeId,
        ]);
        $result = '{"schema_version":"payroll-run-result.v2"}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, ?, ?, ?, "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $this->runId,
            $revisionNo,
            $previousRevisionId,
            $revisionKind,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash(
                'sha256',
                "synthetic-social-revision:{$this->runId}:{$revisionNo}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $personResultJson = json_encode([
            'employee_id' => $this->employeeId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, result_json,
                 result_hash, status)
             VALUES (?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            $personResultJson,
            hash('sha256', $personResultJson),
        ]);
        (new PayrollStatutoryResultRepository($this->db))->store(
            $this->supplierId,
            $revisionId,
            'social_insurance',
            'payroll-social-result.v1',
            'calculated',
            'cz-social-2026',
            str_repeat('b', 64),
            $this->jsonObject($input),
            [
                'calculation_date' => '2026-06-30',
                'status' => 'calculated',
                'participating_assessment_base_minor_units' => 100_000,
                'capped_assessment_base_minor_units' => 100_000,
                'employee_contribution_minor_units' =>
                    $employeeContribution,
                'employer_contribution_before_discount_minor_units' =>
                    $employerBeforeDiscount ?? $employerContribution,
                'part_time_discount_assessment_base_minor_units' => 0,
                'part_time_discount_minor_units' => $partTimeDiscount,
                'employer_contribution_minor_units' =>
                    $employerContribution,
                'issues' => [],
                'ruleset_id' => 'cz-social-2026',
                'ruleset_hash' => str_repeat('b', 64),
            ],
            [[
                'employee_id' => $this->employeeId,
                'input_snapshot' => [],
                'relationships' => [],
                'result_snapshot' => [
                    'person_id' => "employee:{$this->employeeId}",
                    'status' => 'calculated',
                    'employee_contribution_minor_units' =>
                        $personEmployeeContribution,
                ],
                'result_status' => 'calculated',
            ]],
            $this->actorId,
        );

        return $revisionId;
    }

    /** @return array<string,mixed> */
    private function liability(int $id): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_payment_liabilities
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $id]);

        return $this->row($statement->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private function jsonObject(string $json): array
    {
        return $this->row(json_decode(
            $json,
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Testovací řádek není pole.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Testovací řádek nemá textové klíče.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není číslo.",
            );
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new \UnexpectedValueException(
                "Testovací pole {$field} není platné číslo.",
            );
        }

        return $integer;
    }

    private function createActor(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO users
                (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Syntetický sociální uživatel",
                     "accountant", "cs", 1)',
        )->execute([
            'payroll-social-' . bin2hex(random_bytes(6))
                . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function createPayerCurrency(PDO $pdo): int
    {
        $pdo->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en,
                 decimals, is_active, is_default, account_number, bank_code)
             VALUES (?, "CZK", "Syntetický CZK účet", "Kč",
                     "Česká koruna", "Czech koruna", 2, 1, 1,
                     "1000000005", "0100")',
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function batchInstruction(int $batchId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT item_reference, instruction_ciphertext
               FROM payroll_payment_items
              WHERE supplier_id = ? AND batch_id = ?',
        );
        $statement->execute([$this->supplierId, $batchId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $normalized = $this->row($row);
        $json = $this->encryption->decryptFor(
            $this->string($normalized, 'instruction_ciphertext'),
            "payroll-payment-item:{$this->supplierId}:"
                . $this->string($normalized, 'item_reference'),
        );

        return $this->jsonObject($json);
    }
}
