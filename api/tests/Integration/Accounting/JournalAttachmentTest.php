<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\Attachment\DeleteJournalAttachmentAction;
use MyInvoice\Action\Accounting\Attachment\DownloadJournalAttachmentAction;
use MyInvoice\Action\Accounting\Attachment\ListJournalAttachmentsAction;
use MyInvoice\Action\Accounting\Attachment\PatchJournalAttachmentDescriptionAction;
use MyInvoice\Action\Accounting\Attachment\UploadJournalAttachmentAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryAttachmentRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Document\JournalAttachmentStorage;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Slim\Psr7\UploadedFile;

/**
 * F7 §33a — přílohy účetního zápisu (dedikovaná tabulka + VLASTNÍ disk namespace
 * storage/journal/sup-{id}/…). Ověřuje upload/list/download/delete happy path, dedup
 * (409), MIME/magic-byte + blocklist odmítnutí, cross-tenant/cross-entry izolaci,
 * dedup-aware orphan mazání (sdílený sha se nemaže) a RBAC.
 *
 * DB běží v transakci (rollback v tearDown); disk soubory se ručně uklidí (nejsou
 * součástí transakce). Journal namespace je nová F7 featura — sup-{id} strom je čistě testovací.
 */
#[Group('integration')]
final class JournalAttachmentTest extends TestCase
{
    private const YEAR = 2099;
    // Minimální validní PDF (finfo detekuje application/pdf z %PDF- magic).
    private const PDF = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n";

    private Connection $db;
    private JournalEntryRepository $journal;
    private JournalEntryAttachmentRepository $attachments;
    private JournalAttachmentStorage $storage;
    private AccountingPeriodRepository $periods;
    private UploadJournalAttachmentAction $uploadAction;
    private ListJournalAttachmentsAction $listAction;
    private DownloadJournalAttachmentAction $downloadAction;
    private DeleteJournalAttachmentAction $deleteAction;
    private PatchJournalAttachmentDescriptionAction $patchAction;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $accountId = 0;
    private int $entryA = 0;
    private int $entryB = 0;
    private bool $inTx = false;
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db             = $container->get(Connection::class);
            $this->journal        = $container->get(JournalEntryRepository::class);
            $this->attachments    = $container->get(JournalEntryAttachmentRepository::class);
            $this->storage        = $container->get(JournalAttachmentStorage::class);
            $this->periods        = $container->get(AccountingPeriodRepository::class);
            $this->uploadAction   = $container->get(UploadJournalAttachmentAction::class);
            $this->listAction     = $container->get(ListJournalAttachmentsAction::class);
            $this->downloadAction = $container->get(DownloadJournalAttachmentAction::class);
            $this->deleteAction   = $container->get(DeleteJournalAttachmentAction::class);
            $this->patchAction    = $container->get(PatchJournalAttachmentDescriptionAction::class);
            $seeder               = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId  = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->accountId = (int) $pdo->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '211' LIMIT 1"
        )->fetchColumn();
        if ($this->accountId === 0) {
            $this->markTestSkipped('Osnova nemá účet 211.');
        }
        $this->entryA = $this->makeEntry();
        $this->entryB = $this->makeEntry();
    }

    protected function tearDown(): void
    {
        // Disk soubory nejsou v transakci — ukliď celý testovací journal namespace.
        if (isset($this->storage) && $this->supplierId > 0) {
            $this->rrmdir(JournalAttachmentStorage::baseDir($this->supplierId));
        }
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) @unlink($f);
        }
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testUploadListDownloadDeleteHappyPath(): void
    {
        $up = $this->upload($this->entryA, 'accountant', self::PDF, 'doklad.pdf');
        self::assertSame(200, $up['status']);
        self::assertCount(1, $up['body']['created']);

        $list = $this->invokeJson($this->listAction, 'GET', 'accountant', ['id' => (string) $this->entryA]);
        self::assertSame(200, $list['status']);
        self::assertCount(1, $list['body']['items']);
        $att = $list['body']['items'][0];
        self::assertSame('pdf', $att['doc_type']);
        self::assertSame('doklad.pdf', $att['original_name']);
        self::assertSame('application/pdf', $att['mime_type']);
        $attId = (int) $att['id'];

        // Upload je auditovaný.
        $logged = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id = {$this->supplierId}
              AND action = 'accounting.attachment_uploaded' AND entity_id = {$this->entryA}"
        )->fetchColumn();
        self::assertSame(1, $logged);

        // Download → attachment + nosniff, tělo == uložené bajty.
        $resp = $this->downloadAction->__invoke(
            $this->request('GET', 'accountant'),
            new Psr7Response(),
            ['id' => (string) $this->entryA, 'attId' => (string) $attId],
        );
        self::assertSame(200, $resp->getStatusCode());
        self::assertStringStartsWith('attachment;', $resp->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $resp->getHeaderLine('X-Content-Type-Options'));
        $resp->getBody()->rewind();
        self::assertSame(self::PDF, (string) $resp->getBody());

        $path = $this->storage->pathFor($this->supplierId, (string) $att['sha256'], (string) $att['filename']);
        self::assertFileExists($path);

        // Delete → řádek pryč, bajt orphan → smazán.
        $del = $this->invokeJson($this->deleteAction, 'DELETE', 'accountant',
            ['id' => (string) $this->entryA, 'attId' => (string) $attId]);
        self::assertSame(200, $del['status']);
        self::assertCount(0, $this->attachments->list($this->entryA, $this->supplierId));
        self::assertFileDoesNotExist($path);
    }

    public function testDuplicateUploadReturns409(): void
    {
        $first = $this->upload($this->entryA, 'accountant', self::PDF, 'a.pdf');
        self::assertSame(200, $first['status']);
        $second = $this->upload($this->entryA, 'accountant', self::PDF, 'a-znovu.pdf');
        self::assertSame(409, $second['status']);
        self::assertSame('duplicate', $second['body']['error']['code']);
    }

    public function testExecutableExtensionRejected(): void
    {
        $res = $this->upload($this->entryA, 'accountant', self::PDF, 'malware.exe');
        self::assertSame(415, $res['status']);
        self::assertSame('executable_blocked', $res['body']['error']['code']);
        self::assertCount(0, $this->attachments->list($this->entryA, $this->supplierId));
    }

    public function testHtmlContentRejectedDespitePdfName(): void
    {
        // Magic-byte/finfo detekce z OBSAHU: HTML se neprojde ani pod .pdf názvem (nedůvěřovat client MIME).
        $html = "<!DOCTYPE html><html><head><title>x</title></head><body>hi</body></html>";
        $res = $this->upload($this->entryA, 'accountant', $html, 'faktura.pdf');
        self::assertSame(415, $res['status']);
        self::assertContains($res['body']['error']['code'], ['unsupported_type', 'executable_blocked']);
        self::assertCount(0, $this->attachments->list($this->entryA, $this->supplierId));
    }

    public function testCrossEntryAndCrossTenantIsolation(): void
    {
        $up = $this->upload($this->entryA, 'accountant', self::PDF, 'a.pdf');
        $attId = (int) $up['body']['created'][0];

        // Cross-entry: příloha zápisu A není viditelná pod zápisem B.
        self::assertNull($this->attachments->find($attId, $this->entryB, $this->supplierId));
        self::assertCount(0, $this->attachments->list($this->entryB, $this->supplierId));
        $dl = $this->downloadAction->__invoke(
            $this->request('GET', 'accountant'),
            new Psr7Response(),
            ['id' => (string) $this->entryB, 'attId' => (string) $attId],
        );
        self::assertSame(404, $dl->getStatusCode(), 'Příloha A není stažitelná přes zápis B.');

        // Cross-tenant: jiný dodavatel nevidí nic.
        self::assertNull($this->attachments->find($attId, $this->entryA, $this->supplierId + 99999));
        self::assertCount(0, $this->attachments->list($this->entryA, $this->supplierId + 99999));
    }

    public function testSharedShaNotDeletedWhileReferenced(): void
    {
        // MED-3 — content-addressed: stejný obsah u DVOU různých zápisů (dedup je per-zápis)
        // pod RŮZNÝMI původními názvy sdílí JEDEN fyzický soubor na disku (jméno = sha256).
        $a = $this->upload($this->entryA, 'accountant', self::PDF, 'a.pdf');
        $b = $this->upload($this->entryB, 'accountant', self::PDF, 'b.pdf');
        self::assertSame(200, $a['status']);
        self::assertSame(200, $b['status']);

        $attA = (int) $a['body']['created'][0];
        $rowA = $this->attachments->list($this->entryA, $this->supplierId)[0];
        $rowB = $this->attachments->list($this->entryB, $this->supplierId)[0];

        // Různé původní názvy, týž sha → týž disk name (sha) → JEDNA fyzická cesta.
        self::assertSame((string) $rowA['sha256'], (string) $rowB['sha256']);
        self::assertSame((string) $rowA['sha256'], (string) $rowA['filename'], 'Disk name je content-addressed (sha256).');
        self::assertSame('a.pdf', $rowA['original_name']);
        self::assertSame('b.pdf', $rowB['original_name']);
        $pathA = $this->storage->pathFor($this->supplierId, (string) $rowA['sha256'], (string) $rowA['filename']);
        $pathB = $this->storage->pathFor($this->supplierId, (string) $rowB['sha256'], (string) $rowB['filename']);
        self::assertSame($pathA, $pathB, 'Sdílený obsah = jeden fyzický soubor.');
        self::assertFileExists($pathB);

        // Smaž přílohu A → bajt je stále referencován přílohou B (count 1) → NESMÍ se smazat.
        $this->invokeJson($this->deleteAction, 'DELETE', 'accountant',
            ['id' => (string) $this->entryA, 'attId' => (string) $attA]);
        self::assertSame(1, $this->attachments->countBySha($this->supplierId, (string) $rowB['sha256']));
        self::assertFileExists($pathB);

        // Smaž i přílohu B → count 0 → fyzický soubor se teď odstraní.
        $attB = (int) $b['body']['created'][0];
        $this->invokeJson($this->deleteAction, 'DELETE', 'accountant',
            ['id' => (string) $this->entryB, 'attId' => (string) $attB]);
        self::assertSame(0, $this->attachments->countBySha($this->supplierId, (string) $rowB['sha256']));
        self::assertFileDoesNotExist($pathB);
    }

    public function testPatchAttachmentDescriptionAudited(): void
    {
        $up = $this->upload($this->entryA, 'accountant', self::PDF, 'a.pdf');
        $attId = (int) $up['body']['created'][0];

        $res = $this->invokeJson($this->patchAction, 'PATCH', 'accountant',
            ['id' => (string) $this->entryA, 'attId' => (string) $attId], ['description' => 'Zdrojová faktura']);
        self::assertSame(200, $res['status']);
        self::assertSame('Zdrojová faktura', $res['body']['description']);

        $logged = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log WHERE supplier_id = {$this->supplierId}
              AND action = 'accounting.attachment_description_edited' AND entity_id = {$this->entryA}"
        )->fetchColumn();
        self::assertSame(1, $logged);
    }

    public function testReadonlyCannotUpload(): void
    {
        $res = $this->upload($this->entryA, 'readonly', self::PDF, 'a.pdf');
        self::assertSame(403, $res['status']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeEntry(): int
    {
        return $this->journal->insert(
            [
                'supplier_id' => $this->supplierId,
                'period_id'   => $this->periodId,
                'entry_date'  => self::YEAR . '-06-15',
                'source_type' => 'manual',
                'source_id'   => null,
                'posted_at'   => date('Y-m-d H:i:s'),
                'posted_by'   => $this->userId,
            ],
            [
                ['account_id' => $this->accountId, 'side' => 'debit', 'amount' => 100.0],
                ['account_id' => $this->accountId, 'side' => 'credit', 'amount' => 100.0],
            ],
        );
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function upload(int $entryId, string $role, string $content, string $filename): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jea_');
        file_put_contents($tmp, $content);
        $this->tmpFiles[] = $tmp;
        $uf = new UploadedFile($tmp, $filename, 'application/octet-stream', strlen($content), UPLOAD_ERR_OK);

        $req = $this->request('POST', $role)->withUploadedFiles(['file' => $uf]);
        $resp = $this->uploadAction->__invoke($req, new Psr7Response(), ['id' => (string) $entryId]);
        return $this->decode($resp);
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function invokeJson(object $action, string $httpMethod, string $role, array $args, array $body = []): array
    {
        $req = $this->request($httpMethod, $role);
        if ($body !== []) {
            $req = $req->withParsedBody($body);
        }
        return $this->decode($action->__invoke($req, new Psr7Response(), $args));
    }

    private function request(string $httpMethod, string $role): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function decode(\Psr\Http\Message\ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $dir . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
