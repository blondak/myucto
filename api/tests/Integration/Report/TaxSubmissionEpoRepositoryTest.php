<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Action\Report\TaxSubmissionAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

#[Group('integration')]
final class TaxSubmissionEpoRepositoryTest extends TestCase
{
    private Connection $db;
    private DocumentRepository $documents;
    private TaxSubmissionRepository $submissions;
    private TaxSubmissionEpoRepository $epo;
    private TaxSubmissionAction $action;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->documents = $container->get(DocumentRepository::class);
            $this->submissions = $container->get(TaxSubmissionRepository::class);
            $this->epo = $container->get(TaxSubmissionEpoRepository::class);
            $this->action = $container->get(TaxSubmissionAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testAttemptCreatesEvidenceAndIsIncludedInEnrichedSnapshot(): void
    {
        $xml = '<?xml version="1.0"?><Pisemnost nazevSW="test"/>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2020,
            5,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );

        self::assertFalse($this->epo->hasEvidence($submissionId, $this->supplierId));

        $attemptId = $this->epo->insertAttempt(
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
            'production',
        );

        self::assertTrue($this->epo->hasEvidence($submissionId, $this->supplierId));
        $rows = $this->epo->enrich(
            [$this->submissions->find($submissionId, $this->supplierId)],
            $this->supplierId,
        );
        self::assertSame($attemptId, $rows[0]['attempts'][0]['id']);
        self::assertSame('prepared', $rows[0]['attempts'][0]['status']);
        self::assertSame([], $rows[0]['artifacts']);
        self::assertTrue($this->epo->attemptBelongsToSubmission(
            $attemptId,
            $submissionId,
            $this->supplierId,
        ));
        self::assertFalse($this->epo->attemptBelongsToSubmission(
            $attemptId,
            $submissionId + 1,
            $this->supplierId,
        ));
        self::assertFalse($this->epo->attemptBelongsToSubmission(
            $attemptId,
            $submissionId,
            $this->supplierId + 1,
        ));
        self::assertNull($this->epo->latestConfirmableAttempt(
            $submissionId,
            $this->supplierId,
        ));
        self::assertTrue($this->epo->markHandoffCreated(
            $attemptId,
            200,
            '2030-06-25 10:00:00',
        ));
        self::assertSame([
            'id' => $attemptId,
            'status' => 'awaiting_confirmation',
            'epo_environment' => 'production',
        ], $this->epo->latestConfirmableAttempt($submissionId, $this->supplierId));
        self::assertTrue($this->epo->markAttemptConfirmed(
            $attemptId,
            '2020-06-25 10:00:00',
        ));
        self::assertSame('confirmed', $this->epo->attempts(
            $submissionId,
            $this->supplierId,
        )[0]['status']);
        self::assertFalse($this->epo->markAttemptConfirmed(
            $attemptId,
            '2020-06-26 10:00:00',
        ));

        $failedAttemptId = $this->epo->insertAttempt(
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
            'production',
        );
        self::assertTrue($this->epo->markAttemptFailed(
            $failedAttemptId,
            'epo_unavailable',
            'Synthetic failure',
            503,
        ));
        self::assertNull($this->epo->latestConfirmableAttempt(
            $submissionId,
            $this->supplierId,
        ));
        self::assertFalse($this->epo->markAttemptConfirmed(
            $failedAttemptId,
            '2020-06-27 10:00:00',
        ));
        self::assertSame('failed', $this->epo->attempts(
            $submissionId,
            $this->supplierId,
        )[0]['status']);

        $replacementAttemptId = $this->epo->insertAttempt(
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
            'production',
        );
        self::assertTrue($this->epo->cancelActiveAttempt(
            $replacementAttemptId,
            $submissionId,
            $this->supplierId,
        ));
        self::assertFalse($this->epo->markHandoffCreated(
            $replacementAttemptId,
            200,
            '2030-06-28 10:00:00',
        ));
        self::assertFalse($this->epo->markAttemptFailed(
            $replacementAttemptId,
            'epo_unavailable',
            'Late synthetic failure',
            503,
        ));
        self::assertSame('cancelled', $this->epo->attempts(
            $submissionId,
            $this->supplierId,
        )[0]['status']);
        self::assertNull($this->epo->latestConfirmableAttempt(
            $submissionId,
            $this->supplierId,
        ));
    }

    public function testSubmittedSnapshotCountsAsEvidenceWithoutAttempt(): void
    {
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphkh1',
            2020,
            6,
            null,
            '<Pisemnost/>',
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'submitted',
        );

        self::assertTrue($this->epo->hasEvidence($submissionId, $this->supplierId));
        self::assertSame('has_evidence', $this->epo->deleteSubmissionIfNoEvidence(
            $submissionId,
            $this->supplierId,
        ));
        self::assertNotNull($this->submissions->find($submissionId, $this->supplierId));
    }

    public function testSandboxHandoffRemainsIdentifiableBeforeManualConfirmation(): void
    {
        $xml = '<?xml version="1.0"?><Pisemnost><DPHDP3/></Pisemnost>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2020,
            6,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $attemptId = $this->epo->insertAttempt(
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
            'test',
        );
        self::assertTrue($this->epo->markHandoffCreated(
            $attemptId,
            200,
            '2030-06-25 10:00:00',
        ));

        self::assertSame([
            'id' => $attemptId,
            'status' => 'awaiting_confirmation',
            'epo_environment' => 'test',
        ], $this->epo->latestConfirmableAttempt($submissionId, $this->supplierId));

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/reports/submissions/' . $submissionId . '/submit')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody([
                'submitted_at' => '2026-07-27 11:30:00',
                'submission_ref' => 'SYNTHETIC-TEST-REFERENCE',
            ]);
        $response = $this->action->submit(
            $request,
            new Psr7Response(),
            ['id' => (string) $submissionId],
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            'downloaded',
            $this->submissions->find($submissionId, $this->supplierId)['status'] ?? null,
        );
    }

    public function testSnapshotWithoutEvidenceCanBeDeletedAtomically(): void
    {
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2020,
            8,
            null,
            '<Pisemnost/>',
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );

        self::assertSame('deleted', $this->epo->deleteSubmissionIfNoEvidence(
            $submissionId,
            $this->supplierId,
        ));
        self::assertNull($this->submissions->find($submissionId, $this->supplierId));
        self::assertSame('not_found', $this->epo->deleteSubmissionIfNoEvidence(
            $submissionId,
            $this->supplierId,
        ));
    }

    public function testSoftDeletedArtifactCanBeReplacedByFreshDocument(): void
    {
        $xml = '<Pisemnost><DPHDP3/></Pisemnost>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2020,
            7,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $sha = hash('sha256', 'synthetic-artifact');
        $firstDocumentId = $this->insertDocument('first.xml', $sha);
        $artifactId = $this->epo->addArtifact(
            $this->supplierId,
            $submissionId,
            null,
            $firstDocumentId,
            'epo_xml',
            $sha,
            'warning',
            ['snapshot_sha256_match' => false],
            $this->userId,
        );
        self::assertGreaterThan(0, $artifactId);
        self::assertTrue($this->documents->softDelete(
            $firstDocumentId,
            $this->supplierId,
            $this->userId,
        ));
        self::assertNull($this->epo->artifactByKindAndSha(
            $submissionId,
            $this->supplierId,
            'epo_xml',
            $sha,
        ));

        $secondDocumentId = $this->insertDocument('second.xml', $sha);
        self::assertSame($artifactId, $this->epo->addArtifact(
            $this->supplierId,
            $submissionId,
            null,
            $secondDocumentId,
            'epo_xml',
            $sha,
            'valid',
            ['snapshot_sha256_match' => true],
            $this->userId,
        ));

        $artifact = $this->epo->artifactByKindAndSha(
            $submissionId,
            $this->supplierId,
            'epo_xml',
            $sha,
        );
        self::assertNotNull($artifact);
        self::assertSame($secondDocumentId, $artifact['document_id']);
        self::assertSame('valid', $artifact['verification_status']);
        self::assertNotNull($this->epo->artifact(
            (int) $artifact['id'],
            $submissionId,
            $this->supplierId,
        ));
        self::assertNull($this->epo->artifact(
            (int) $artifact['id'],
            $submissionId + 1,
            $this->supplierId,
        ));
        self::assertNull($this->epo->artifact(
            (int) $artifact['id'],
            $submissionId,
            $this->supplierId + 1,
        ));
    }

    private function insertDocument(string $name, string $sha): int
    {
        return $this->documents->insert([
            'supplier_id' => $this->supplierId,
            'folder_id' => null,
            'title' => $name,
            'description' => null,
            'original_name' => $name,
            'filename' => substr($sha, 0, 8) . '-' . $name,
            'sha256' => $sha,
            'mime_type' => 'application/xml',
            'size_bytes' => 20,
            'doc_type' => 'xml',
            'uploaded_by' => $this->userId,
            'scope' => 'company',
            'owner_user_id' => null,
        ]);
    }
}
