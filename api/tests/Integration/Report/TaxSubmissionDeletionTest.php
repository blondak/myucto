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

/**
 * Brána mazání XML snapshotu: blokovat smí jen to, co prokazatelně odešlo.
 *
 * Vzniklo z provozního hlášení — uživatel na snapshotu spustil test EPO (`test=1`),
 * na který EPO odpovědělo „Podání nebylo přijato, protože bylo odesláno v testovacím
 * režimu.", a snapshot od té chvíle nešel smazat. Původní `hasEvidence()` blokovalo
 * mazání, jakmile u snapshotu existoval JAKÝKOLI řádek v `tax_submission_attempts`.
 */
#[Group('integration')]
final class TaxSubmissionDeletionTest extends TestCase
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

    /**
     * JÁDRO OPRAVY. Test EPO nic nepodá — EPO na `test=1` odpovídá `TEST_REZIM` — takže
     * ani otestovaný snapshot nesmí zůstat zamčený. Artefakty testu (`source_xml`,
     * `epo_error_xml`) jsou tu schválně: vznikají v `EpoDirectSubmissionService::test()`
     * a taky mazání blokovaly.
     */
    public function testSnapshotWithOnlyTestAttemptCanBeDeleted(): void
    {
        $submissionId = $this->archiveSnapshot(2020, 3);
        $attemptId = $this->insertDirectAttempt($submissionId, 'test_passed', [
            'test_passed' => 1,
            'tested_at' => '2026-08-01 09:00:00',
            'response_http_status' => 200,
        ]);
        $this->attachArtifact($submissionId, $attemptId, 'source_xml', 'test-source');
        $this->attachArtifact($submissionId, $attemptId, 'epo_error_xml', 'test-response');

        self::assertNull(
            $this->epo->deletionBlocker($submissionId, $this->supplierId),
            'Úspěšný test EPO nic nepodal — nesmí blokovat mazání.',
        );

        $enriched = $this->epo->enrich(
            [$this->submissions->find($submissionId, $this->supplierId)],
            $this->supplierId,
        )[0];
        self::assertTrue($enriched['deletable']);
        self::assertNull($enriched['delete_blocker']);

        $response = $this->deleteRequest($submissionId);
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->submissions->find($submissionId, $this->supplierId));
    }

    /** Neúspěšný test dopadá stejně — EPO ho odmítlo, nic se nepodalo. */
    public function testSnapshotWithFailedTestAttemptCanBeDeleted(): void
    {
        $submissionId = $this->archiveSnapshot(2020, 4);
        $this->insertDirectAttempt($submissionId, 'test_failed', [
            'test_passed' => 0,
            'tested_at' => '2026-08-01 09:05:00',
            'error_code' => 'epo_validation_failed',
        ]);

        self::assertNull($this->epo->deletionBlocker($submissionId, $this->supplierId));
        self::assertSame('deleted', $this->epo->deleteSubmission(
            $submissionId,
            $this->supplierId,
        )['result']);
    }

    /**
     * Potvrzené ostré podání je zákonný důkaz — blokuje natvrdo a neuvolní ho ani vědomé
     * potvrzení „nepodáno". Kdyby ho uvolnilo, byla by oprava horší než původní chyba.
     */
    public function testConfirmedSubmissionCannotBeDeletedEvenWithAcknowledgement(): void
    {
        $submissionId = $this->archiveSnapshot(2020, 9);
        $this->insertDirectAttempt($submissionId, 'confirmed', [
            'submitted_at' => '2026-08-01 10:00:00',
            'confirmed_at' => '2026-08-01 10:00:05',
            'remote_submission_ref' => 'SYNTHETIC-REF-1',
        ]);

        self::assertSame(
            TaxSubmissionEpoRepository::BLOCK_DELIVERED,
            $this->epo->deletionBlocker($submissionId, $this->supplierId),
        );

        $outcome = $this->epo->deleteSubmission(
            $submissionId,
            $this->supplierId,
            $this->userId,
            'Ověřeno v portálu EPO, podání tam není.',
        );
        self::assertSame('blocked', $outcome['result']);
        self::assertSame(TaxSubmissionEpoRepository::BLOCK_DELIVERED, $outcome['blocker']);
        self::assertSame(0, $outcome['released_attempts']);
        self::assertNotNull($this->submissions->find($submissionId, $this->supplierId));

        $response = $this->deleteRequest($submissionId, 'Ověřeno v portálu EPO, podání tam není.');
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('submission_has_evidence', $this->errorCode($response));
    }

    /**
     * Doručenka nahraná ručně dokazuje podání i bez pokusu (typicky asistovaná cesta,
     * kde uživatel podal v portálu EPO a P7S doložil zpětně).
     */
    public function testConfirmationArtifactBlocksDeletion(): void
    {
        $submissionId = $this->archiveSnapshot(2020, 10);
        $this->attachArtifact($submissionId, null, 'confirmation_p7s', 'delivery-note');

        self::assertSame(
            TaxSubmissionEpoRepository::BLOCK_DELIVERED,
            $this->epo->deletionBlocker($submissionId, $this->supplierId),
        );
    }

    /**
     * Asistované předání čekající na P7S: aplikace NEVÍ, jestli uživatel v portálu podal.
     * Smazat to jde, protože doložené podání to není — uživatel to potvrdí v dialogu.
     * Auditní stopa přitom musí rozlišit vědomé zahození od skutečného ověření v portálu:
     * tvrdit „ověřeno" u někoho, kdo jen odklikl potvrzení, by bylo nepravdivé.
     */
    public function testUnresolvedHandoffIsDeletableAndRecordsHowItWasClosed(): void
    {
        $submissionId = $this->archiveSnapshot(2020, 11);
        $attemptId = $this->epo->insertAttempt(
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', 'synthetic'),
            $this->userId,
            'production',
        );
        self::assertTrue($this->epo->markHandoffCreated($attemptId, 200, '2030-06-25 10:00:00'));

        self::assertSame(
            TaxSubmissionEpoRepository::BLOCK_UNRESOLVED,
            $this->epo->deletionBlocker($submissionId, $this->supplierId),
        );
        $enriched = $this->epo->enrich(
            [$this->submissions->find($submissionId, $this->supplierId)],
            $this->supplierId,
        )[0];
        // Uživateli se ukáže varovné znění dialogu, ale akce zůstává dostupná.
        self::assertTrue($enriched['delete_needs_acknowledgement']);

        self::assertSame(200, $this->deleteRequest($submissionId)->getStatusCode());
        self::assertNull($this->submissions->find($submissionId, $this->supplierId));
        self::assertSame('discarded_by_user', $this->closedAs());
    }

    /** Poznámka není povinná, ale když ji uživatel napíše, audit ji odliší jako ověření. */
    public function testUnresolvedHandoffWithNoteIsRecordedAsVerified(): void
    {
        $submissionId = $this->archiveSnapshot(2021, 3);
        $attemptId = $this->epo->insertAttempt(
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', 'synthetic-verified'),
            $this->userId,
            'production',
        );
        self::assertTrue($this->epo->markHandoffCreated($attemptId, 200, '2030-06-25 10:00:00'));

        $ok = $this->deleteRequest(
            $submissionId,
            'V portálu EPO jsem ověřil, že podání za období není evidované.',
        );
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('verified_not_submitted', $this->closedAs());
    }

    /** Pokus kaskáda smazala spolu se snapshotem, takže se čte z auditní stopy. */
    private function closedAs(): ?string
    {
        $payload = json_decode((string) $this->latestActivityLogPayload(), true);
        return $payload['closed_as'] ?? null;
    }

    /**
     * Smazání musí přežít v auditní stopě i s tím, co strhlo s sebou přes ON DELETE CASCADE —
     * po commitu už ty řádky nikdo nedohledá.
     */
    public function testDeletionIsRecordedInActivityLog(): void
    {
        $submissionId = $this->archiveSnapshot(2020, 12);
        $attemptId = $this->insertDirectAttempt($submissionId, 'test_failed', ['test_passed' => 0]);
        $this->attachArtifact($submissionId, $attemptId, 'source_xml', 'audit-source');

        $before = $this->activityLogCount();
        self::assertSame(200, $this->deleteRequest($submissionId)->getStatusCode());
        self::assertSame($before + 1, $this->activityLogCount());

        $payload = json_decode((string) $this->latestActivityLogPayload(), true);
        self::assertSame($submissionId, $payload['submission_id']);
        self::assertSame(1, $payload['purged']['attempts']);
        self::assertSame(1, $payload['purged']['artifacts']);

        // ON DELETE CASCADE: navázané řádky jsou pryč, dokument v DMS zůstává.
        self::assertSame(0, $this->countRows('tax_submission_attempts', $submissionId));
        self::assertSame(0, $this->countRows('tax_submission_artifacts', $submissionId));
    }

    private function archiveSnapshot(int $year, int $month): int
    {
        return $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            $year,
            $month,
            null,
            '<?xml version="1.0"?><Pisemnost nazevSW="test"/>',
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
    }

    /** @param array<string,mixed> $extra */
    private function insertDirectAttempt(int $submissionId, string $status, array $extra = []): int
    {
        $columns = array_merge([
            'supplier_id' => $this->supplierId,
            'tax_submission_id' => $submissionId,
            'channel' => 'epo_direct',
            'epo_environment' => 'production',
            'status' => $status,
            'idempotency_key' => bin2hex(random_bytes(16)),
            'request_sha256' => hash('sha256', 'synthetic-' . $status),
            'requested_by' => $this->userId,
        ], $extra);

        $stmt = $this->db->pdo()->prepare(sprintf(
            'INSERT INTO tax_submission_attempts (%s) VALUES (%s)',
            implode(',', array_keys($columns)),
            implode(',', array_fill(0, count($columns), '?')),
        ));
        $stmt->execute(array_values($columns));

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function attachArtifact(
        int $submissionId,
        ?int $attemptId,
        string $kind,
        string $seed,
    ): int {
        $sha = hash('sha256', $seed . '-' . $submissionId);
        $documentId = $this->documents->insert([
            'supplier_id' => $this->supplierId,
            'folder_id' => null,
            'title' => $seed . '.xml',
            'description' => null,
            'original_name' => $seed . '.xml',
            'filename' => substr($sha, 0, 8) . '-' . $seed . '.xml',
            'sha256' => $sha,
            'mime_type' => 'application/xml',
            'size_bytes' => 20,
            'doc_type' => 'xml',
            'uploaded_by' => $this->userId,
            'scope' => 'company',
            'owner_user_id' => null,
        ]);

        return $this->epo->addArtifact(
            $this->supplierId,
            $submissionId,
            $attemptId,
            $documentId,
            $kind,
            $sha,
            'valid',
            [],
            $this->userId,
        );
    }

    private function deleteRequest(int $submissionId, ?string $note = null): Psr7Response
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/reports/submissions/' . $submissionId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        if ($note !== null) {
            $request = $request->withParsedBody(['not_submitted_note' => $note]);
        }

        /** @var Psr7Response $response */
        $response = $this->action->delete(
            $request,
            new Psr7Response(),
            ['id' => (string) $submissionId],
        );

        return $response;
    }

    private function errorCode(Psr7Response $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true);

        return $body['error']['code'] ?? $body['code'] ?? null;
    }

    private function activityLogCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'report.submission_deleted'"
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function latestActivityLogPayload(): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE action = 'report.submission_deleted'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute();
        $payload = $stmt->fetchColumn();

        return $payload === false ? null : (string) $payload;
    }

    private function countRows(string $table, int $submissionId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM $table WHERE tax_submission_id = ? AND supplier_id = ?"
        );
        $stmt->execute([$submissionId, $this->supplierId]);

        return (int) $stmt->fetchColumn();
    }
}
