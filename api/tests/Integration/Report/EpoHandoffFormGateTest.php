<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Epo\EpoClient;
use MyInvoice\Service\Epo\EpoSubmissionException;
use MyInvoice\Service\Epo\EpoSubmissionService;
use MyInvoice\Service\Epo\TaxSubmissionDocumentService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Brána na typ formuláře před asistovaným předáním do EPO.
 *
 * Guzzle běží s prázdnou MockHandler frontou: kdyby se kód dostal až k volání
 * portálu, spadne to nahlas a bez jediného skutečného požadavku na adisspr.mfcr.cz.
 */
#[Group('integration')]
final class EpoHandoffFormGateTest extends TestCase
{
    private Connection $db;
    private TaxSubmissionRepository $submissions;
    private TaxSubmissionEpoRepository $epo;
    private EpoSubmissionService $service;
    private int $supplierId;
    private int $userId;
    /** @var list<int> */
    private array $createdSubmissionIds = [];

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
            $this->service = new EpoSubmissionService(
                $this->db,
                $this->epo,
                $container->get(TaxSubmissionDocumentService::class),
                new EpoClient(new Client([
                    'handler' => HandlerStack::create(new MockHandler([])),
                ])),
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }
    }

    /**
     * Bez obalové transakce: `createHandoff()` si otevírá vlastní a vnořená by
     * v PDO skončila chybou. Uklízí se proto adresně podle založených snapshotů.
     */
    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->createdSubmissionIds as $submissionId) {
            $pdo->prepare('DELETE FROM tax_submission_attempts WHERE tax_submission_id = ?')
                ->execute([$submissionId]);
            $pdo->prepare('DELETE FROM tax_submissions WHERE id = ?')->execute([$submissionId]);
        }
        $this->createdSubmissionIds = [];
        $this->db->close();
    }

    private function archiveSnapshot(string $formCode, ?int $month, ?int $quarter, string $xml): int
    {
        $submissionId = $this->submissions->archive(
            $this->supplierId,
            $formCode,
            2026,
            $month,
            $quarter,
            $xml,
            [],
            'passed',
            [],
            $this->userId,
            'B',
            'downloaded',
        );
        $this->createdSubmissionIds[] = $submissionId;
        return $submissionId;
    }

    public function testOssReturnIsRejectedBecauseItBelongsToTheMossOssApplication(): void
    {
        $submissionId = $this->archiveSnapshot(
            'ossei1',
            null,
            1,
            '<?xml version="1.0"?><Pisemnost><OSSEI1/></Pisemnost>',
        );

        try {
            $this->service->createHandoff($submissionId, $this->supplierId, $this->userId);
            self::fail('OSS přiznání se přes obecné EPO podat nedá, předání nesmí vzniknout.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('moss_oss_only', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
            self::assertStringContainsString('MOSS/OSS', $e->getMessage());
        }

        // Odmítnutí musí přijít dřív, než vznikne pokus nebo se cokoli odešle ven.
        self::assertSame([], $this->epo->attempts($submissionId, $this->supplierId));
    }

    public function testUnknownFormKeepsItsOwnGenericRejection(): void
    {
        $submissionId = $this->archiveSnapshot('dphsd1', 1, null, '<?xml version="1.0"?><Pisemnost/>');

        try {
            $this->service->createHandoff($submissionId, $this->supplierId, $this->userId);
            self::fail('Neznámý formulář nesmí projít.');
        } catch (EpoSubmissionException $e) {
            self::assertSame('unsupported_form', $e->errorCode);
        }
    }
}
