<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Co se z ručně nahrané dodejky zapíše k ASISTOVANÉMU předání.
 *
 * Podací číslo a heslo pro `epo_stav` vydá EPO jen jednou, v dodejce. Dokud se
 * nepřebíraly, zůstalo asistované podání bez „Obnovit stav" i bez cesty k opisu
 * na portálu — přestože aplikace ten soubor měla archivovaný.
 */
#[Group('integration')]
final class EpoAssistedConfirmationRecordTest extends TestCase
{
    private Connection $db;
    private TaxSubmissionRepository $submissions;
    private TaxSubmissionEpoRepository $epo;
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
            $this->submissions = $container->get(TaxSubmissionRepository::class);
            $this->epo = $container->get(TaxSubmissionEpoRepository::class);
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

    public function testReceiptDataUnlocksStatusQueryForAssistedAttempt(): void
    {
        $xml = '<?xml version="1.0"?><Pisemnost nazevSW="test"/>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2021,
            3,
            null,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );

        self::assertNull($this->epo->latestAssistedAttempt($submissionId, $this->supplierId));

        $attemptId = $this->epo->insertAttempt(
            $this->supplierId,
            $submissionId,
            bin2hex(random_bytes(16)),
            hash('sha256', $xml),
            $this->userId,
            'production',
        );
        self::assertTrue($this->epo->markHandoffCreated($attemptId, 200, '2030-06-25 10:00:00'));

        $latest = $this->epo->latestAssistedAttempt($submissionId, $this->supplierId);
        self::assertNotNull($latest);
        self::assertSame($attemptId, $latest['id']);

        // Před nahráním dodejky není o co dotaz na stav opřít.
        $before = $this->epo->attempts($submissionId, $this->supplierId)[0];
        self::assertFalse($before['status_query_available']);

        self::assertTrue($this->epo->recordAssistedConfirmation(
            $attemptId,
            $submissionId,
            $this->supplierId,
            '568467011',
            '2026-08-21 10:36:43',
            'ciphertext-1',
        ));

        $after = $this->epo->attempts($submissionId, $this->supplierId)[0];
        self::assertSame('568467011', $after['remote_submission_ref']);
        self::assertTrue($after['status_query_available']);
        self::assertTrue($after['refresh_available']);
        // Nahrání dodejky je důkaz, ne právní úkon — stav pokusu zůstává na účetní.
        self::assertSame('awaiting_confirmation', $after['status']);

        // Opakované nahrání téhož souboru už heslo nenese; přepsat ho NULLem by
        // znamenalo přijít o jedinou kopii, kterou EPO vydává.
        self::assertTrue($this->epo->recordAssistedConfirmation(
            $attemptId,
            $submissionId,
            $this->supplierId,
            '568467011',
            '2026-08-21 10:36:43',
            null,
        ));
        self::assertTrue(
            $this->epo->attempts($submissionId, $this->supplierId)[0]['status_query_available'],
        );

        // A pořád platí hranice tenanta i podání.
        self::assertFalse($this->epo->recordAssistedConfirmation(
            $attemptId,
            $submissionId,
            $this->supplierId + 1,
            '111',
            '2026-08-21 10:36:43',
            null,
        ));
        self::assertFalse($this->epo->recordAssistedConfirmation(
            $attemptId,
            $submissionId + 1,
            $this->supplierId,
            '111',
            '2026-08-21 10:36:43',
            null,
        ));
        self::assertSame(
            '568467011',
            $this->epo->attempts($submissionId, $this->supplierId)[0]['remote_submission_ref'],
        );
    }

    /** Přímý pokus asistovanou cestou dohledat nejde — dodejka by k němu neseděla. */
    public function testDirectAttemptIsNotOfferedAsAssisted(): void
    {
        $xml = '<?xml version="1.0"?><Pisemnost nazevSW="test-direct"/>';
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            'dphdp3',
            2021,
            4,
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
            'production',
        );
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts SET channel = 'epo_direct' WHERE id = ?"
        )->execute([$attemptId]);

        self::assertNull($this->epo->latestAssistedAttempt($submissionId, $this->supplierId));
        self::assertFalse($this->epo->recordAssistedConfirmation(
            $attemptId,
            $submissionId,
            $this->supplierId,
            '568467011',
            '2026-08-21 10:36:43',
            'ciphertext-1',
        ));
    }
}
