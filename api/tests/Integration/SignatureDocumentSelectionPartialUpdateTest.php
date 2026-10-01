<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Settings\SignatureDocumentSelectionAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\SigningProfileRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class SignatureDocumentSelectionPartialUpdateTest extends TestCase
{
    private PDO $pdo;
    private SigningProfileRepository $profiles;
    private SignatureDocumentSelectionAction $action;
    private int $supplierId;
    private int $invoiceId;
    private int $userId;
    /** @var list<int> */
    private array $createdProfiles = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if ($container === null) {
                $this->markTestSkipped('Container not available');
            }
            $this->pdo = $container->get(Connection::class)->pdo();
            $this->profiles = $container->get(SigningProfileRepository::class);
            $this->action = $container->get(SignatureDocumentSelectionAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI unavailable: ' . $e->getMessage());
        }

        $row = $this->pdo->query('SELECT id, supplier_id FROM invoices ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $uid = $this->pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        if ($row === false || !$uid) {
            $this->markTestSkipped('No invoice or user');
        }
        $this->invoiceId = (int) $row['id'];
        $this->supplierId = (int) $row['supplier_id'];
        $this->userId = (int) $uid;
        $this->profiles->deleteDocumentOverride($this->supplierId, 'pdf', 'work_report', $this->invoiceId);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->profiles->deleteDocumentOverride($this->supplierId, 'pdf', 'work_report', $this->invoiceId);
        if ($this->createdProfiles !== []) {
            $in = implode(',', array_fill(0, count($this->createdProfiles), '?'));
            $this->pdo->prepare("DELETE FROM signing_profiles WHERE id IN ($in)")->execute($this->createdProfiles);
        }
    }

    private function put(array $body): Response
    {
        $request = (new ServerRequestFactory())->createServerRequest('PUT', '/documents/work_report/' . $this->invoiceId . '/signature-selection')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withParsedBody($body);

        return $this->action->put($request, new Response(), ['entity_type' => 'work_report', 'id' => (string) $this->invoiceId]);
    }

    public function testOmittedKeysKeepStoredOverrideAndExplicitValuesChangeIt(): void
    {
        $adminProfileId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: null,
            name: 'Integration partial admin profile',
            code: 'itest_partial_' . bin2hex(random_bytes(4)),
            allowedUsages: ['pdf'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->createdProfiles[] = $adminProfileId;

        $res = $this->put(['selection_source' => 'admin_profile_settings', 'admin_profile_id' => $adminProfileId]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());

        // Prázdné tělo: nic se nemění (dřív se override smazal / vynuloval).
        $res = $this->put([]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $override = $this->profiles->documentOverride($this->supplierId, 'pdf', 'work_report', $this->invoiceId);
        self::assertNotNull($override);
        self::assertSame('admin_profile_settings', $override['selection_source']);
        self::assertSame($adminProfileId, $override['admin_profile_id']);

        // Vynechaný admin_profile_id při potvrzení stejného zdroje ponechá profil.
        $res = $this->put(['selection_source' => 'admin_profile_settings']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $override = $this->profiles->documentOverride($this->supplierId, 'pdf', 'work_report', $this->invoiceId);
        self::assertSame($adminProfileId, $override['admin_profile_id']);

        // Vynechaný selection_source při změně jen admin_profile_id ponechá zdroj.
        $res = $this->put(['admin_profile_id' => null]);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $override = $this->profiles->documentOverride($this->supplierId, 'pdf', 'work_report', $this->invoiceId);
        self::assertSame('admin_profile_settings', $override['selection_source']);
        self::assertNull($override['admin_profile_id']);

        // Explicitní inherit maže override.
        $res = $this->put(['selection_source' => 'inherit']);
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertNull($this->profiles->documentOverride($this->supplierId, 'pdf', 'work_report', $this->invoiceId));
    }

    public function testOmittedSelectionSourceWithoutStoredOverrideIsRejected(): void
    {
        $res = $this->put([]);
        self::assertSame(400, $res->getStatusCode(), (string) $res->getBody());
        self::assertNull($this->profiles->documentOverride($this->supplierId, 'pdf', 'work_report', $this->invoiceId));
    }
}
