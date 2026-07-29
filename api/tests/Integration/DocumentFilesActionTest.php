<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Document\DocumentFilesAction;
use MyInvoice\Action\Document\DocumentsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentFileRepository;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Service\Document\DocumentStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Slim\Psr7\UploadedFile;

/**
 * Epic F7 §6 — DMS N-souborů-na-doklad routes (DocumentFilesAction) + scope filtr
 * (DocumentsAction::list). Ověřuje list/add/set-primary/delete happy path, „nelze
 * smazat poslední primary", per-file download + scope guard (user-scoped doklad
 * cizího uživatele je 404), orphan-aware mazání a Firemní/Osobní filtr.
 *
 * Volá Actions přímo (z DI) s Requestem nesoucím ATTR_USER / ATTR_CURRENT_ID. DB
 * řádky se ručně uklidí (setPrimary otevírá vlastní transakci → nelze obalit outer tx),
 * disk soubory (content-addressed unikátní sha) se cíleně unlinknou. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class DocumentFilesActionTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n";

    private Connection $db;
    private DocumentFilesAction $files;
    private DocumentsAction $docsAction;
    private DocumentRepository $docs;
    private DocumentFileRepository $fileRepo;
    private DocumentStorage $storage;

    private int $supplierId = 0;
    private int $userId = 0;
    /** @var list<int> */
    private array $createdDocs = [];
    /** @var list<string> */
    private array $diskPaths = [];
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db         = $c->get(Connection::class);
            $this->files      = $c->get(DocumentFilesAction::class);
            $this->docsAction = $c->get(DocumentsAction::class);
            $this->docs       = $c->get(DocumentRepository::class);
            $this->fileRepo   = $c->get(DocumentFileRepository::class);
            $this->storage    = $c->get(DocumentStorage::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            foreach ($this->createdDocs as $id) {
                // document_files cascade-nou (fk_df_document ON DELETE CASCADE).
                $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
            }
        }
        foreach (array_unique($this->diskPaths) as $p) {
            if (is_file($p)) @unlink($p);
        }
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) @unlink($f);
        }
        if (isset($this->db)) $this->db->close();
    }

    /** Vytvoří DMS dokument (+ primary document_files řádek + fyzický soubor). */
    private function makeDoc(string $scope = 'company', ?int $ownerUserId = null, string $salt = ''): int
    {
        $bytes = self::PDF . "\n% unique " . $salt . bin2hex(random_bytes(6));
        $stored = $this->storage->storeFromBytes($bytes, $this->supplierId, 'doklad.pdf');
        $this->diskPaths[] = $stored['abs_path'];

        $id = $this->docs->insert([
            'supplier_id'   => $this->supplierId,
            'folder_id'     => null,
            'title'         => 'F7FILESDOC',
            'description'   => null,
            'original_name' => 'doklad.pdf',
            'filename'      => $stored['filename'],
            'sha256'        => $stored['sha256'],
            'mime_type'     => $stored['mime_type'],
            'size_bytes'    => $stored['size_bytes'],
            'doc_type'      => $stored['doc_type'],
            'uploaded_by'   => $this->userId,
            'scope'         => $scope,
            'owner_user_id' => $ownerUserId,
        ]);
        $this->createdDocs[] = $id;
        $this->fileRepo->add([
            'document_id'   => $id,
            'supplier_id'   => $this->supplierId,
            'role'          => 'primary',
            'sha256'        => $stored['sha256'],
            'filename'      => $stored['filename'],
            'original_name' => 'doklad.pdf',
            'mime_type'     => $stored['mime_type'],
            'size_bytes'    => $stored['size_bytes'],
            'doc_type'      => $stored['doc_type'],
            'sort_order'    => 0,
            'uploaded_by'   => $this->userId,
        ]);
        return $id;
    }

    public function testListAddSetPrimaryDeleteHappyPath(): void
    {
        $docId = $this->makeDoc();

        // list → jen primary
        $list = $this->json($this->files, 'list', 'GET', 'accountant', $this->userId, ['id' => (string) $docId]);
        self::assertSame(200, $list['status']);
        self::assertCount(1, $list['body']['files']);
        self::assertSame('primary', $list['body']['files'][0]['role']);

        // add attachment
        $add = $this->upload($docId, 'accountant', self::PDF . 'ATT' . bin2hex(random_bytes(6)), 'priloha.pdf');
        self::assertSame(200, $add['status']);
        self::assertCount(2, $add['body']['files']);
        $this->trackDiskFromList($add['body']['files']);
        $attId = 0;
        foreach ($add['body']['files'] as $f) {
            if ($f['role'] === 'attachment') $attId = (int) $f['id'];
        }
        self::assertGreaterThan(0, $attId);

        // set-primary → attachment se stane primary, původní primary degraduje (právě jeden primary)
        $patch = $this->json($this->files, 'patch', 'PATCH', 'accountant', $this->userId,
            ['id' => (string) $docId, 'fileId' => (string) $attId], ['role' => 'primary']);
        self::assertSame(200, $patch['status']);
        $primaries = array_filter($patch['body']['files'], static fn($f) => $f['role'] === 'primary');
        self::assertCount(1, $primaries, 'právě jeden primary');
        self::assertSame($attId, (int) array_values($primaries)[0]['id']);

        // sort_order patch
        $sortPatch = $this->json($this->files, 'patch', 'PATCH', 'accountant', $this->userId,
            ['id' => (string) $docId, 'fileId' => (string) $attId], ['sort_order' => 5]);
        self::assertSame(200, $sortPatch['status']);

        // Smaž teď-attachment (původní primary, degradovaný) → řádek pryč. Bajt zůstává:
        // documents inline sha256 (SoT primary) ho stále referencuje (union ref-counting).
        $oldPrimaryId = 0;
        foreach ($patch['body']['files'] as $f) {
            if ($f['role'] === 'attachment') $oldPrimaryId = (int) $f['id'];
        }
        $del = $this->json($this->files, 'delete', 'DELETE', 'accountant', $this->userId,
            ['id' => (string) $docId, 'fileId' => (string) $oldPrimaryId]);
        self::assertSame(200, $del['status']);
        self::assertNull($this->fileRepo->find($oldPrimaryId, $docId, $this->supplierId));
    }

    public function testDeleteAttachmentOrphanCleanup(): void
    {
        $docId = $this->makeDoc();
        // Přidej attachment s UNIKÁTNÍM obsahem (sha nesdílená s primary/documents inline).
        $add = $this->upload($docId, 'accountant', self::PDF . 'ORPHAN' . bin2hex(random_bytes(8)), 'orphan.pdf');
        self::assertSame(200, $add['status']);
        $this->trackDiskFromList($add['body']['files']);
        $attId = 0; $sha = ''; $filename = '';
        foreach ($add['body']['files'] as $f) {
            if ($f['role'] === 'attachment') { $attId = (int) $f['id']; $sha = (string) $f['sha256']; $filename = (string) $f['filename']; }
        }
        $path = $this->storage->pathFor($this->supplierId, $sha, $filename);
        self::assertFileExists($path);

        // Smaž jedinou referenci na sha → union ref-count 0 → bajt odpojen.
        $del = $this->json($this->files, 'delete', 'DELETE', 'accountant', $this->userId,
            ['id' => (string) $docId, 'fileId' => (string) $attId]);
        self::assertSame(200, $del['status']);
        // Mimo právě mazaný (soft-deleted) řádek už na sha neukazuje nikdo → union 0.
        self::assertSame(0, $this->docs->countBySha($this->supplierId, $sha, [], [$attId]));
        self::assertFileDoesNotExist($path, 'orphan bajt odpojen');
    }

    /** H1 (§4.5): set-primary musí re-zrcadlit inline `documents` SoT + invalidovat náhled. */
    public function testSetPrimaryReMirrorsInlineDocumentSoT(): void
    {
        $docId = $this->makeDoc();
        // Předstírej již vygenerovaný náhled na PŮVODNÍM primary, ať test ověří invalidaci.
        $this->docs->setThumb($docId, 'thumb-old.jpg', 'generated');

        $add = $this->upload($docId, 'accountant', self::PDF . 'MIRROR' . bin2hex(random_bytes(8)), 'nova-primarni.pdf');
        self::assertSame(200, $add['status']);
        $this->trackDiskFromList($add['body']['files']);
        $att = null;
        foreach ($add['body']['files'] as $f) {
            if ($f['role'] === 'attachment') $att = $f;
        }
        self::assertNotNull($att);

        // Povýš přílohu na primary.
        $patch = $this->json($this->files, 'patch', 'PATCH', 'accountant', $this->userId,
            ['id' => (string) $docId, 'fileId' => (string) $att['id']], ['role' => 'primary']);
        self::assertSame(200, $patch['status']);

        // documents inline SoT nyní zrcadlí povýšený soubor (download/preview/export ho čtou).
        $viewer = \MyInvoice\Repository\DocumentViewerContext::fromRole('admin', null);
        $raw = $this->docs->findRaw($docId, $this->supplierId, $viewer);
        self::assertNotNull($raw);
        self::assertSame($att['sha256'], (string) $raw['sha256'], 'documents.sha256 = povýšený soubor');
        self::assertSame($att['filename'], (string) $raw['filename'], 'documents.filename = povýšený soubor');
        self::assertSame('nova-primarni.pdf', (string) $raw['original_name']);
        // Stale náhled invalidován → regeneruje se (thumb_status 'none', thumb_path NULL).
        self::assertSame('none', (string) $raw['thumb_status']);
        self::assertNull($raw['thumb_path']);
    }

    /** L2: sha sdílená dvěma přílohami — smaž obě → bajt se uvolní až na druhém delete. */
    public function testSharedShaAttachmentsFreeByteOnSecondDelete(): void
    {
        $docA = $this->makeDoc('company', null, 'shaA');
        $docB = $this->makeDoc('company', null, 'shaB');
        // Stejný obsah přílohy na obou dokladech → sdílená sha (content-addressed dedup).
        $shared = self::PDF . 'SHARED' . bin2hex(random_bytes(8));
        $addA = $this->upload($docA, 'accountant', $shared, 'shared.pdf');
        $addB = $this->upload($docB, 'accountant', $shared, 'shared.pdf');
        self::assertSame(200, $addA['status']);
        self::assertSame(200, $addB['status']);
        $this->trackDiskFromList($addA['body']['files']);
        $this->trackDiskFromList($addB['body']['files']);

        $attA = 0; $attB = 0; $sha = ''; $filename = '';
        foreach ($addA['body']['files'] as $f) {
            if ($f['role'] === 'attachment') { $attA = (int) $f['id']; $sha = (string) $f['sha256']; $filename = (string) $f['filename']; }
        }
        foreach ($addB['body']['files'] as $f) {
            if ($f['role'] === 'attachment') { $attB = (int) $f['id']; }
        }
        self::assertGreaterThan(0, $attA);
        self::assertGreaterThan(0, $attB);
        $path = $this->storage->pathFor($this->supplierId, $sha, $filename);
        self::assertFileExists($path);

        // Smaž první přílohu → bajt drží druhá AKTIVNÍ příloha (union > 0).
        $del1 = $this->json($this->files, 'delete', 'DELETE', 'accountant', $this->userId,
            ['id' => (string) $docA, 'fileId' => (string) $attA]);
        self::assertSame(200, $del1['status']);
        self::assertFileExists($path, 'bajt drží druhá aktivní příloha');

        // Smaž druhou → soft-deleted první řádek už bajt DRŽET NESMÍ (L2) → uvolněno.
        $del2 = $this->json($this->files, 'delete', 'DELETE', 'accountant', $this->userId,
            ['id' => (string) $docB, 'fileId' => (string) $attB]);
        self::assertSame(200, $del2['status']);
        self::assertSame(0, $this->docs->countBySha($this->supplierId, $sha, [], [$attB]),
            'soft-deleted document_files řádek nedrží bajt (deleted_at IS NULL větev)');
        self::assertFileDoesNotExist($path, 'orphan bajt odpojen po druhém delete');
    }

    public function testCannotDeleteLastPrimary(): void
    {
        $docId = $this->makeDoc();
        $list = $this->json($this->files, 'list', 'GET', 'accountant', $this->userId, ['id' => (string) $docId]);
        $primaryId = (int) $list['body']['files'][0]['id'];

        $del = $this->json($this->files, 'delete', 'DELETE', 'accountant', $this->userId,
            ['id' => (string) $docId, 'fileId' => (string) $primaryId]);
        self::assertSame(409, $del['status']);
        self::assertSame('cannot_delete_primary', $del['body']['error']['code']);
        self::assertNotNull($this->fileRepo->find($primaryId, $docId, $this->supplierId), 'primary nesmazán');
    }

    public function testUserScopedDocFilesInvisibleToOtherNonAdmin(): void
    {
        $otherId = $this->userId + 999999;
        $docId = $this->makeDoc('user', $this->userId, 'scoped');

        // Vlastník vidí soubory.
        $owner = $this->json($this->files, 'list', 'GET', 'accountant', $this->userId, ['id' => (string) $docId]);
        self::assertSame(200, $owner['status']);

        // Cizí non-admin → 404 (scope guard), nesmí list/add/patch/delete.
        $otherList = $this->json($this->files, 'list', 'GET', 'accountant', $otherId, ['id' => (string) $docId]);
        self::assertSame(404, $otherList['status']);
        $otherAdd = $this->upload($docId, 'accountant', self::PDF . 'x', 'x.pdf', $otherId);
        self::assertSame(404, $otherAdd['status']);

        // Admin vidí i cizí user doklad.
        $admin = $this->json($this->files, 'list', 'GET', 'admin', $otherId, ['id' => (string) $docId]);
        self::assertSame(200, $admin['status']);
    }

    public function testPerFileDownloadScopeGuarded(): void
    {
        $otherId = $this->userId + 999999;
        $docId = $this->makeDoc('user', $this->userId, 'dl');
        $add = $this->upload($docId, 'accountant', self::PDF . 'DL' . bin2hex(random_bytes(6)), 'p.pdf', $this->userId);
        self::assertSame(200, $add['status']);
        $this->trackDiskFromList($add['body']['files']);
        $attId = 0;
        foreach ($add['body']['files'] as $f) {
            if ($f['role'] === 'attachment') $attId = (int) $f['id'];
        }

        // Vlastník stáhne — attachment + nosniff.
        $ok = $this->files->download($this->req('GET', 'accountant', $this->userId), new Psr7Response(),
            ['id' => (string) $docId, 'fileId' => (string) $attId]);
        self::assertSame(200, $ok->getStatusCode());
        self::assertStringStartsWith('attachment;', $ok->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $ok->getHeaderLine('X-Content-Type-Options'));

        // Cizí non-admin → 404 (scope guard na rodičovském dokladu).
        $denied = $this->files->download($this->req('GET', 'accountant', $otherId), new Psr7Response(),
            ['id' => (string) $docId, 'fileId' => (string) $attId]);
        self::assertSame(404, $denied->getStatusCode());
    }

    public function testScopeFilterCompanyVsUserTab(): void
    {
        $companyDoc = $this->makeDoc('company', null, 'co');
        $userDoc    = $this->makeDoc('user', $this->userId, 'us');

        $idsFor = function (array $q, string $role, int $uid): array {
            $req = $this->req('GET', $role, $uid)->withQueryParams($q);
            $res = $this->docsAction->list($req, new Psr7Response());
            $body = json_decode((string) $res->getBody(), true) ?: [];
            return array_map(static fn($d) => (int) $d['id'], $body['data'] ?? []);
        };

        // Firemní tab (scope=company) → jen company doklad.
        $company = $idsFor(['scope' => 'company'], 'accountant', $this->userId);
        self::assertContains($companyDoc, $company);
        self::assertNotContains($userDoc, $company);

        // Osobní tab (scope=user) → jen user doklad (vlastníka).
        $user = $idsFor(['scope' => 'user'], 'accountant', $this->userId);
        self::assertContains($userDoc, $user);
        self::assertNotContains($companyDoc, $user);

        // Bez filtru → oba (guard viditelnost vlastníka).
        $all = $idsFor([], 'accountant', $this->userId);
        self::assertContains($companyDoc, $all);
        self::assertContains($userDoc, $all);

        // Osobní tab pro cizího non-admina → user doklad se neobjeví (guard).
        $otherUser = $idsFor(['scope' => 'user'], 'accountant', $this->userId + 999999);
        self::assertNotContains($userDoc, $otherUser);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function trackDiskFromList(array $files): void
    {
        foreach ($files as $f) {
            $this->diskPaths[] = $this->storage->pathFor(
                $this->supplierId, (string) $f['sha256'], (string) $f['filename']);
        }
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function upload(int $docId, string $role, string $content, string $filename, ?int $uid = null): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'df_');
        file_put_contents($tmp, $content);
        $this->tmpFiles[] = $tmp;
        $uf = new UploadedFile($tmp, $filename, 'application/octet-stream', strlen($content), UPLOAD_ERR_OK);
        $req = $this->req('POST', $role, $uid ?? $this->userId)->withUploadedFiles(['file' => [$uf]]);
        return $this->decode($this->files->add($req, new Psr7Response(), ['id' => (string) $docId]));
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function json(object $action, string $method, string $http, string $role, int $uid, array $args, array $body = []): array
    {
        $req = $this->req($http, $role, $uid);
        if ($body !== []) $req = $req->withParsedBody($body);
        return $this->decode($action->{$method}($req, new Psr7Response(), $args));
    }

    private function req(string $method, string $role, int $uid): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/documents')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $uid, 'role' => $role]);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function decode(\Psr\Http\Message\ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
