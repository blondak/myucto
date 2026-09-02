<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Backup\Company\CompanyBackupEmbeddedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupEncodedReference;
use MyInvoice\Service\Backup\Company\CompanyBackupProtectedSecretProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlProtectedSecretSource;
use MyInvoice\Service\Backup\Company\CompanyBackupSqlRowSource;
use MyInvoice\Service\Backup\Company\CompanyBackupTableProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupTableSchemaReader;
use MyInvoice\Service\Backup\Registry\TenantDataRegistryFactory;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKind;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\IncomeTaxComponent;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\TaxChildClaim;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationEvidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidenceEvidence;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetLifecycle;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\RulesetApproval;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDOStatement;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * MZ-04-W05 — evidence vyživovaných osob a jejich napojení na existující
 * daňové zvýhodnění (payroll_person_tax_child_claims → snímek revize → výpočet).
 */
#[Group('integration')]
final class PayrollDependantApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CHILD_A = '010101/0008';
    private const CHILD_B = '150202/0003';

    private Connection $db;
    private PayrollDependantAction $action;
    private PayrollPersonStatutoryEvidenceRepository $statutory;
    private PayrollSensitiveData $sensitiveData;
    private SecretEncryption $encryption;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $secondEmployeeId;
    private int $otherEmployeeId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            self::assertInstanceOf(ContainerInterface::class, $container);
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollDependantAction::class);
            $this->statutory = $container->get(
                PayrollPersonStatutoryEvidenceRepository::class,
            );
            $this->sensitiveData = $container->get(PayrollSensitiveData::class);
            $encryption = $container->get(SecretEncryption::class);
            self::assertInstanceOf(SecretEncryption::class, $encryption);
            $this->encryption = $encryption;
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasTable('payroll_dependants')) {
            $this->markTestSkipped('Migrace 1312 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplier = $pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1',
        );
        $sourceUser = $pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1',
        );
        self::assertInstanceOf(PDOStatement::class, $sourceSupplier);
        self::assertInstanceOf(PDOStatement::class, $sourceUser);
        $sourceSupplierId = (int) ($sourceSupplier->fetchColumn() ?: 0);
        $this->userId = (int) ($sourceUser->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            "UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)"
        )->execute([$this->supplierId, $this->otherSupplierId]);

        $this->employeeId = $this->createEmployee($this->supplierId, 'Syntetický Poplatník');
        $this->secondEmployeeId = $this->createEmployee($this->supplierId, 'Syntetický Druhý Rodič');
        $this->otherEmployeeId = $this->createEmployee($this->otherSupplierId, 'Cizí Poplatník');

        $this->signDeclaration($this->supplierId, $this->employeeId);
        $this->signDeclaration($this->supplierId, $this->secondEmployeeId);
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

    public function testCreatesChildAndReturnsBirthNumberOnlyMasked(): void
    {
        $response = $this->post($this->supplierId, $this->employeeId, $this->child());
        self::assertSame(200, $response->getStatusCode());

        $body = $this->json($response);
        self::assertCount(1, $body['dependants']);
        $dependant = $body['dependants'][0];
        self::assertSame('Syntetické Dítě A', $dependant['full_name']);
        self::assertTrue($dependant['has_birth_number']);
        self::assertIsString($dependant['birth_number_masked']);
        self::assertStringContainsString('•', $dependant['birth_number_masked']);
        self::assertStringNotContainsString(self::CHILD_A, $dependant['birth_number_masked']);
        self::assertStringNotContainsString('0101010008', json_encode($body, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::CHILD_A, json_encode($body, JSON_THROW_ON_ERROR));

        $stored = $this->db->pdo()->prepare(
            'SELECT birth_number_ciphertext FROM payroll_dependants WHERE id = ?'
        );
        $stored->execute([$dependant['id']]);
        $ciphertext = (string) $stored->fetchColumn();
        self::assertStringStartsWith('enc:v2:', $ciphertext);
        self::assertStringNotContainsString('010101', $ciphertext);

        $list = $this->json($this->get($this->supplierId, $this->employeeId));
        self::assertStringNotContainsString(
            self::CHILD_A,
            json_encode($list, JSON_THROW_ON_ERROR),
        );
    }

    public function testCompanyBackupStreamsAndResealsOptionalBirthNumber(): void
    {
        $protectedId = $this->createChild();
        $emptyId = $this->createChild([
            'full_name' => 'Syntetické Dítě Bez Identifikátoru',
            'birth_date' => '2018-03-03',
            'birth_number' => null,
            'existence_from' => '2018-03-03',
        ]);
        $foreign = $this->post(
            $this->otherSupplierId,
            $this->otherEmployeeId,
            $this->child(),
        );
        self::assertSame(200, $foreign->getStatusCode(), (string) $foreign->getBody());
        $foreignId = (int) $this->json($foreign)['dependants'][0]['id'];

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition('table:payroll_dependants');
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = array_values(iterator_to_array(
            (new CompanyBackupSqlRowSource(batchSize: 1))->rows(
                $this->db->pdo(),
                $this->supplierId,
                $definition,
            ),
        ));
        self::assertCount(2, $rows);
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int) $row['id']] = $row;
            self::assertArrayNotHasKey('birth_number_ciphertext', $row);
            self::assertArrayNotHasKey('birth_number_hash', $row);
            self::assertArrayNotHasKey('birth_number_masked', $row);
        }
        self::assertArrayHasKey($protectedId, $rowsById);
        self::assertArrayHasKey($emptyId, $rowsById);
        self::assertArrayNotHasKey($foreignId, $rowsById);

        $secretProjection = CompanyBackupProtectedSecretProjection::fromDefinition(
            $definition,
        );
        $values = array_values(iterator_to_array(
            (new CompanyBackupSqlProtectedSecretSource($this->encryption))->values(
                $this->db->pdo(),
                $this->supplierId,
                $secretProjection,
            ),
        ));
        self::assertCount(1, $values);
        self::assertSame(self::CHILD_A, $values[0]->plaintext());
        self::assertSame(['id' => $protectedId], $values[0]->primaryKey);

        $storedSource = $this->db->pdo()->prepare(
            'SELECT birth_number_ciphertext, birth_number_hash
               FROM payroll_dependants
              WHERE supplier_id = ? AND id = ?'
        );
        $storedSource->execute([$this->supplierId, $protectedId]);
        $sourceStorage = $storedSource->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($sourceStorage);

        $targetSupplierId = $this->otherSupplierId + 100_000;
        $protectedTarget = $rowsById[$protectedId];
        $protectedTarget['supplier_id'] = $targetSupplierId;
        $protectedTarget['id'] = $protectedId + 100_000;
        $targetStorage = $projection->protectedSecretMaterializations
            ->materializeForColumn(
                'birth_number_ciphertext',
                $values[0],
                $rowsById[$protectedId],
                $protectedTarget,
                $this->sensitiveData,
            );

        self::assertIsString($targetStorage['birth_number_ciphertext']);
        self::assertIsString($targetStorage['birth_number_hash']);
        self::assertNotSame(
            $sourceStorage['birth_number_ciphertext'],
            $targetStorage['birth_number_ciphertext'],
        );
        self::assertNotSame(
            $sourceStorage['birth_number_hash'],
            $targetStorage['birth_number_hash'],
        );
        self::assertSame(
            self::CHILD_A,
            $this->sensitiveData->reveal(
                $targetStorage['birth_number_ciphertext'],
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $targetSupplierId,
                (int) $protectedTarget['id'],
            ),
        );

        $emptyTarget = $rowsById[$emptyId];
        $emptyTarget['supplier_id'] = $targetSupplierId;
        $emptyTarget['id'] = $emptyId + 100_000;
        self::assertSame(
            [
                'birth_number_ciphertext' => null,
                'birth_number_hash' => null,
                'birth_number_masked' => null,
            ],
            $projection->protectedSecretMaterializations->materializeForColumn(
                'birth_number_ciphertext',
                null,
                $rowsById[$emptyId],
                $emptyTarget,
                $this->sensitiveData,
            ),
        );
    }

    public function testCompanyBackupStreamsAndRemapsChildClaimGraph(): void
    {
        $dependantId = $this->createChild();
        $created = $this->json($this->postClaim(
            $dependantId,
            $this->claim(['effective_from' => '2026-01-01']),
        ));
        $original = $created['dependants'][0]['claims'][0];
        $this->approveRun('2026-03-01');
        $superseded = $this->json($this->putClaim(
            $dependantId,
            (int) $original['id'],
            $this->claim([
                'child_order' => 2,
                'effective_from' => '2026-01-01',
                'row_version' => $original['row_version'],
            ]),
        ));
        $claims = $superseded['dependants'][0]['claims'];
        self::assertCount(2, $claims);

        $legacyInsert = $this->db->pdo()->prepare(
            "INSERT INTO payroll_person_tax_child_claims
                (supplier_id, employee_id, dependant_id, child_reference,
                 child_order, ztp_p, evidence_status,
                 shared_household_confirmed, other_claimant_excluded,
                 effective_from, effective_to, evidence_reference)
             VALUES (?, ?, NULL, ?, 3, 0, 'unverified', 0, 0,
                     '2026-01-01', NULL, NULL)"
        );
        $legacyInsert->execute([
            $this->supplierId,
            $this->employeeId,
            'legacy:synthetic-child',
        ]);
        $legacyId = (int) $this->db->pdo()->lastInsertId();

        $foreign = $this->post(
            $this->otherSupplierId,
            $this->otherEmployeeId,
            $this->child(),
        );
        self::assertSame(200, $foreign->getStatusCode(), (string) $foreign->getBody());
        $foreignDependantId = (int) $this->json($foreign)['dependants'][0]['id'];
        $foreignInsert = $this->db->pdo()->prepare(
            "INSERT INTO payroll_person_tax_child_claims
                (supplier_id, employee_id, dependant_id, child_reference,
                 child_order, ztp_p, evidence_status,
                 shared_household_confirmed, other_claimant_excluded,
                 effective_from, effective_to, evidence_reference)
             VALUES (?, ?, ?, ?, 1, 0, 'unverified', 0, 0,
                     '2026-01-01', NULL, NULL)"
        );
        $foreignInsert->execute([
            $this->otherSupplierId,
            $this->otherEmployeeId,
            $foreignDependantId,
            'dependant-' . $foreignDependantId,
        ]);
        $foreignClaimId = (int) $this->db->pdo()->lastInsertId();

        $registry = TenantDataRegistryFactory::draftV1();
        $definition = $registry->definition(
            'table:payroll_person_tax_child_claims',
        );
        self::assertNotNull($definition);
        $projection = CompanyBackupTableProjection::fromDefinition($definition);
        $schemaReader = new CompanyBackupTableSchemaReader();
        $schema = $schemaReader->read($this->db->pdo(), $projection);
        $projection->assertRuntimeSchema(
            $schema->columns,
            $schema->generatedColumns,
            $schema->primaryKey,
            $schema->binaryColumns,
        );
        $projection->references->assertRegistryTargets($registry);
        $projection->encodedReferences->assertRegistryTargets($registry);
        $projection->references->assertRuntimeSchema(
            $schemaReader->readReferences($this->db->pdo(), $projection),
        );

        $rows = array_values(iterator_to_array(
            (new CompanyBackupSqlRowSource(batchSize: 1))->rows(
                $this->db->pdo(),
                $this->supplierId,
                $definition,
            ),
        ));
        self::assertCount(3, $rows);
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int) $row['id']] = $row;
        }
        self::assertArrayHasKey((int) $original['id'], $rowsById);
        self::assertArrayHasKey($legacyId, $rowsById);
        self::assertArrayNotHasKey($foreignClaimId, $rowsById);

        $modernRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['dependant_id'] === $dependantId,
        ));
        self::assertCount(2, $modernRows);
        foreach ($modernRows as $row) {
            self::assertSame('dependant-' . $dependantId, $row['child_reference']);
            $restored = $projection->remapPayloadReferences(
                $row,
                static function (
                    CompanyBackupEncodedReference|CompanyBackupEmbeddedReference $reference,
                    int|string $value,
                ) use ($dependantId): int {
                    self::assertInstanceOf(
                        CompanyBackupEncodedReference::class,
                        $reference,
                    );
                    self::assertSame('table:payroll_dependants', $reference->target);
                    self::assertSame($dependantId, $value);
                    return $dependantId + 100_000;
                },
            );
            self::assertSame($dependantId + 100_000, $restored['dependant_id']);
            self::assertSame(
                'dependant-' . ($dependantId + 100_000),
                $restored['child_reference'],
            );
        }

        $legacy = $rowsById[$legacyId];
        self::assertNull($legacy['dependant_id']);
        self::assertSame('legacy:synthetic-child', $legacy['child_reference']);
        self::assertSame(
            $legacy,
            $projection->remapPayloadReferences(
                $legacy,
                static fn (): never => throw new \LogicException(
                    'Legacy reference bez dependant_id nesmí volat mapper.',
                ),
            ),
        );

        $originalRow = $rowsById[(int) $original['id']];
        self::assertNotNull($originalRow['superseded_by_id']);
        self::assertArrayHasKey((int) $originalRow['superseded_by_id'], $rowsById);
    }

    public function testOverlappingClaimForTheSameChildIsRejected(): void
    {
        $dependantId = $this->createChild();
        self::assertSame(200, $this->postClaim(
            $dependantId,
            $this->claim(['effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']),
        )->getStatusCode());

        $response = $this->postClaim(
            $dependantId,
            $this->claim(['effective_from' => '2026-06-01']),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'překrývá',
            (string) $this->json($response)['error']['message'],
        );
    }

    public function testTwoChildrenCannotShareTheSameOrderInTheSameMonth(): void
    {
        $first = $this->createChild();
        $second = $this->createChild([
            'full_name' => 'Syntetické Dítě B',
            'birth_date' => '2015-02-02',
            'birth_number' => self::CHILD_B,
            'existence_from' => '2015-02-02',
        ]);

        self::assertSame(200, $this->postClaim(
            $first,
            $this->claim(['child_order' => 1, 'effective_from' => '2026-01-01']),
        )->getStatusCode());

        $response = $this->postClaim(
            $second,
            $this->claim(['child_order' => 1, 'effective_from' => '2026-05-01']),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'Pořadí dítěte 1',
            (string) $this->json($response)['error']['message'],
        );
    }

    public function testClaimOutsideDependantExistenceIsRejected(): void
    {
        $dependantId = $this->createChild([
            'existence_from' => '2026-05-01',
            'existence_to' => '2026-09-30',
        ]);

        $early = $this->postClaim(
            $dependantId,
            $this->claim(['effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']),
        );
        self::assertSame(422, $early->getStatusCode());
        self::assertStringContainsString(
            'dříve',
            (string) $this->json($early)['error']['message'],
        );

        $late = $this->postClaim(
            $dependantId,
            $this->claim(['effective_from' => '2026-06-01', 'effective_to' => null]),
        );
        self::assertSame(422, $late->getStatusCode());
        self::assertStringContainsString(
            'déle',
            (string) $this->json($late)['error']['message'],
        );
    }

    public function testVerifiedClaimWithoutSignedDeclarationDoesNotArise(): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_person_tax_declarations
              WHERE supplier_id = ? AND employee_id = ?'
        )->execute([$this->supplierId, $this->employeeId]);

        $dependantId = $this->createChild();
        $response = $this->postClaim($dependantId, $this->claim());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'prohlášení poplatníka',
            (string) $this->json($response)['error']['message'],
        );
        self::assertSame(0, $this->claimCount());
    }

    public function testClaimWithoutEvidenceReferenceIsStored(): void
    {
        $dependantId = $this->createChild();

        $response = $this->postClaim($dependantId, $this->claim([
            'evidence_status' => 'verified',
            'evidence_reference' => null,
        ]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull(
            $this->json($response)['dependants'][0]['claims'][0]['evidence_reference'],
        );
        self::assertSame(1, $this->claimCount());
    }

    public function testUnverifiedClaimIsStoredButNeverReachesTheCalculation(): void
    {
        $dependantId = $this->createChild();
        $response = $this->postClaim($dependantId, $this->claim([
            'evidence_status' => 'unverified',
            'evidence_reference' => null,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $claim = $this->json($response)['dependants'][0]['claims'][0];
        self::assertContains('evidence_unverified', $claim['blockers']);

        $snapshot = $this->statutory->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-06-30',
        );
        self::assertIsArray($snapshot);
        $rows = $snapshot['income_tax']['child_claims'];
        self::assertCount(1, $rows);
        self::assertSame('unverified', $rows[0]['evidence_status']);
    }

    public function testZtpPChildDoublesTheCreditInTheCalculation(): void
    {
        $dependantId = $this->createChild(['ztp_p' => true]);
        self::assertSame(200, $this->postClaim(
            $dependantId,
            $this->claim(['ztp_p' => true, 'effective_from' => '2026-01-01']),
        )->getStatusCode());

        $snapshot = $this->statutory->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-06-30',
        );
        self::assertIsArray($snapshot);
        $effective = $snapshot['income_tax']['effective']['child_claims'] ?? null;
        $rows = is_array($effective) ? $effective : $snapshot['income_tax']['child_claims'];
        self::assertCount(1, $rows);
        self::assertTrue((bool) $rows[0]['ztp_p']);
        self::assertSame('dependant-' . $dependantId, $rows[0]['child_reference']);

        $result = $this->calculator()->calculate(new MonthlyEmploymentIncomeTaxInput(
            calculationDate: '2026-06-30',
            employeeReference: 'synthetic-employee',
            relationships: [new EmploymentRelationshipTaxInput(
                'employment',
                'synthetic-payer',
                EmploymentRelationshipKind::Employment,
                [new IncomeTaxComponent('synthetic-income', 4_000_000)],
            )],
            declarations: [new TaxDeclarationEvidence(
                TaxDeclarationStatus::Signed,
                '2026-01-01',
                null,
                'document:tax-declaration',
            )],
            residence: new TaxResidenceEvidence(
                TaxResidence::CzechResident,
                '2026-01-01',
                null,
                'document:tax-residence',
            ),
            childClaims: [new TaxChildClaim(
                (string) $rows[0]['child_reference'],
                (int) $rows[0]['child_order'],
                (bool) $rows[0]['ztp_p'],
                (string) $rows[0]['effective_from'],
                $rows[0]['effective_to'] === null ? null : (string) $rows[0]['effective_to'],
                TaxEvidenceStatus::from((string) $rows[0]['evidence_status']),
                (bool) $rows[0]['shared_household_confirmed'],
                (bool) $rows[0]['other_claimant_excluded'],
                (string) $rows[0]['evidence_reference'],
            )],
        ));

        self::assertSame(2 * 126_700, $result->claimedChildCreditMinorUnits);
    }

    public function testSpouseCannotCarryMonthlyChildCredit(): void
    {
        $spouseId = $this->createChild([
            'relation' => 'spouse',
            'full_name' => 'Syntetický Manžel',
            'birth_date' => '2001-01-01',
            'birth_number' => self::CHILD_A,
            'existence_from' => '2020-01-01',
        ]);

        $response = $this->postClaim($spouseId, $this->claim());

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'roční zúčtování',
            (string) $this->json($response)['error']['message'],
        );
    }

    public function testDoubleCreditRequiresZtpPOnThePerson(): void
    {
        $dependantId = $this->createChild();

        $response = $this->postClaim($dependantId, $this->claim(['ztp_p' => true]));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'ZTP/P',
            (string) $this->json($response)['error']['message'],
        );
    }

    public function testSameChildCannotBeClaimedByTwoTaxpayersOfOneEmployer(): void
    {
        $first = $this->createChild();
        self::assertSame(200, $this->postClaim($first, $this->claim())->getStatusCode());

        $second = $this->json($this->post(
            $this->supplierId,
            $this->secondEmployeeId,
            $this->child(),
        ))['dependants'][0]['id'];

        $response = $this->action->createClaim(
            $this->request(
                'POST',
                $this->supplierId,
                "/api/payroll/people/{$this->secondEmployeeId}/dependants/{$second}/claims",
                $this->claim(),
            ),
            new Response(),
            [
                'id' => (string) $this->secondEmployeeId,
                'dependantId' => (string) $second,
            ],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'jiný poplatník',
            (string) $this->json($response)['error']['message'],
        );
    }

    public function testChangeAfterApprovedRevisionCreatesNewVersionAndKeepsHistory(): void
    {
        $dependantId = $this->createChild();
        $created = $this->json($this->postClaim(
            $dependantId,
            $this->claim(['child_order' => 1, 'effective_from' => '2026-01-01']),
        ));
        $claim = $created['dependants'][0]['claims'][0];
        $claimId = (int) $claim['id'];

        $this->approveRun('2026-03-01');

        $response = $this->putClaim(
            $dependantId,
            $claimId,
            $this->claim([
                'child_order' => 2,
                'effective_from' => '2026-01-01',
                'row_version' => $claim['row_version'],
            ]),
        );
        self::assertSame(200, $response->getStatusCode());

        $claims = $this->json($response)['dependants'][0]['claims'];
        self::assertCount(2, $claims);
        $byId = [];
        foreach ($claims as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $historical = $byId[$claimId];
        self::assertSame('2026-03-31', $historical['effective_to']);
        self::assertSame(1, $historical['child_order'], 'Historie se nesmí přepsat.');
        self::assertNotNull($historical['superseded_by_id']);

        $replacement = $byId[(int) $historical['superseded_by_id']];
        self::assertSame('2026-04-01', $replacement['effective_from']);
        self::assertSame(2, $replacement['child_order']);

        $frozenMonth = $this->statutory->snapshot(
            $this->supplierId,
            $this->employeeId,
            '2026-02-28',
        );
        self::assertIsArray($frozenMonth);
        $frozenRows = array_values(array_filter(
            $frozenMonth['income_tax']['child_claims'],
            static fn (array $row): bool => $row['effective_from'] === '2026-01-01',
        ));
        self::assertSame(1, (int) $frozenRows[0]['child_order']);
    }

    public function testStaleRowVersionOnClaimReturnsConflict(): void
    {
        $dependantId = $this->createChild();
        $created = $this->json($this->postClaim($dependantId, $this->claim()));
        $claimId = (int) $created['dependants'][0]['claims'][0]['id'];

        $response = $this->putClaim(
            $dependantId,
            $claimId,
            $this->claim(['row_version' => 99, 'effective_to' => '2026-08-31']),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('row_version_conflict', $this->json($response)['error']['code']);
    }

    public function testStaleRowVersionOnDependantReturnsConflict(): void
    {
        $dependantId = $this->createChild();

        $response = $this->action->update(
            $this->request(
                'PUT',
                $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/dependants/{$dependantId}",
                $this->child(['row_version' => 42]),
            ),
            new Response(),
            [
                'id' => (string) $this->employeeId,
                'dependantId' => (string) $dependantId,
            ],
        );

        self::assertSame(409, $response->getStatusCode());
    }

    public function testTenantIsolation(): void
    {
        $dependantId = $this->createChild();

        $foreignRead = $this->get($this->otherSupplierId, $this->employeeId);
        self::assertSame(404, $foreignRead->getStatusCode());

        $foreignWrite = $this->action->createClaim(
            $this->request(
                'POST',
                $this->otherSupplierId,
                "/api/payroll/people/{$this->employeeId}/dependants/{$dependantId}/claims",
                $this->claim(),
            ),
            new Response(),
            [
                'id' => (string) $this->employeeId,
                'dependantId' => (string) $dependantId,
            ],
        );
        self::assertSame(404, $foreignWrite->getStatusCode());

        $otherTenantList = $this->json($this->get(
            $this->otherSupplierId,
            $this->otherEmployeeId,
        ));
        self::assertSame([], $otherTenantList['dependants']);
    }

    public function testBearerTokenCannotReachTheDependantEvidence(): void
    {
        $response = $this->action->list(
            $this->request(
                'GET',
                $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/dependants",
                null,
                'accountant',
                'bearer',
            ),
            new Response(),
            ['id' => (string) $this->employeeId],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->json($response)['error']['code']);
    }

    public function testEndingAClaimIsAllowedOutsideFrozenPeriod(): void
    {
        $dependantId = $this->createChild();
        $created = $this->json($this->postClaim($dependantId, $this->claim()));
        $claim = $created['dependants'][0]['claims'][0];

        $response = $this->putClaim(
            $dependantId,
            (int) $claim['id'],
            $this->claim([
                'row_version' => $claim['row_version'],
                'effective_to' => '2026-09-30',
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        $claims = $this->json($response)['dependants'][0]['claims'];
        self::assertCount(1, $claims);
        self::assertSame('2026-09-30', $claims[0]['effective_to']);
        self::assertNull($claims[0]['superseded_by_id']);
    }

    // --- pomocníci ---------------------------------------------------------

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function child(array $overrides = []): array
    {
        return $overrides + [
            'relation' => 'child_own',
            'full_name' => 'Syntetické Dítě A',
            'birth_date' => '2001-01-01',
            'birth_number' => self::CHILD_A,
            'ztp_p' => false,
            'student' => true,
            'existence_from' => '2001-01-01',
            'existence_to' => null,
            'note' => null,
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function claim(array $overrides = []): array
    {
        return $overrides + [
            'child_order' => 1,
            'claim_reason' => 'own_household',
            'evidence_status' => 'verified',
            'evidence_reference' => 'document:child-claim',
            'shared_household_confirmed' => true,
            'other_claimant_excluded' => true,
            'ztp_p' => false,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function createChild(array $overrides = []): int
    {
        $response = $this->post($this->supplierId, $this->employeeId, $this->child($overrides));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $dependants = $body['dependants'];

        return (int) $dependants[count($dependants) - 1]['id'];
    }

    private function claimCount(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_person_tax_child_claims
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $statement->execute([$this->supplierId, $this->employeeId]);

        return (int) $statement->fetchColumn();
    }

    private function get(int $supplierId, int $employeeId): ResponseInterface
    {
        return $this->action->list(
            $this->request(
                'GET',
                $supplierId,
                "/api/payroll/people/{$employeeId}/dependants",
            ),
            new Response(),
            ['id' => (string) $employeeId],
        );
    }

    /** @param array<string,mixed> $payload */
    private function post(
        int $supplierId,
        int $employeeId,
        array $payload,
    ): ResponseInterface
    {
        return $this->action->create(
            $this->request(
                'POST',
                $supplierId,
                "/api/payroll/people/{$employeeId}/dependants",
                $payload,
            ),
            new Response(),
            ['id' => (string) $employeeId],
        );
    }

    /** @param array<string,mixed> $payload */
    private function postClaim(
        int $dependantId,
        array $payload,
    ): ResponseInterface
    {
        return $this->action->createClaim(
            $this->request(
                'POST',
                $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/dependants/{$dependantId}/claims",
                $payload,
            ),
            new Response(),
            [
                'id' => (string) $this->employeeId,
                'dependantId' => (string) $dependantId,
            ],
        );
    }

    /** @param array<string,mixed> $payload */
    private function putClaim(
        int $dependantId,
        int $claimId,
        array $payload,
    ): ResponseInterface
    {
        return $this->action->saveClaim(
            $this->request(
                'PUT',
                $this->supplierId,
                "/api/payroll/people/{$this->employeeId}/dependants/{$dependantId}"
                . "/claims/{$claimId}",
                $payload,
            ),
            new Response(),
            [
                'id' => (string) $this->employeeId,
                'dependantId' => (string) $dependantId,
                'claimId' => (string) $claimId,
            ],
        );
    }

    /** @param array<string,mixed>|null $body */
    private function request(
        string $method,
        int $supplierId,
        string $path,
        ?array $body = null,
        string $role = 'accountant',
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);

        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createEmployee(int $supplierId, string $fullName): int
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 1, 1, 0, ?, 0, 1)'
        );
        $statement->execute([$supplierId, $fullName, 'employee', 'hpp', 40_000]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function signDeclaration(int $supplierId, int $employeeId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, effective_to,
                 evidence_reference)
             VALUES (?, ?, 'signed', '2020-01-01', NULL, 'document:tax-declaration')"
        )->execute([$supplierId, $employeeId]);
    }

    private function approveRun(string $periodStart): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status)
             VALUES (?, ?, ?, 'approved')"
        )->execute([
            $this->supplierId,
            $periodStart,
            (new \DateTimeImmutable($periodStart))->modify('+40 days')->format('Y-m-d'),
        ]);
        $runId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'test-1', ?, '{}', ?, ?)"
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            random_bytes(32),
        ]);
    }

    /** Dodaná sada je účinná rovnou — není co aktivovat ani co schvalovat. */
    private function calculator(): MonthlyEmploymentIncomeTaxCalculator
    {
        return new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([
                CzechPayrollRulesets2026::provider()
                    ->forDate(PayrollRulesetDomain::IncomeTax, '2026-06-30'),
            ]),
        );
    }
}
