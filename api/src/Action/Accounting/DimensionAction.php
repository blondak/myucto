<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Firma → Dimenze — REST API.
 *
 *   GET    /api/accounting/dimensions                          — přehled (zapnuto, skupina, typy, hodnoty)
 *   PUT    /api/accounting/dimensions/settings                 — zapnout/vypnout sekci (nastavení firmy)
 *   POST   /api/accounting/dimensions/defaults                 — založit výchozí typy
 *   POST   /api/accounting/dimensions/types                    — nový typ
 *   PATCH  /api/accounting/dimensions/types/{id}               — úprava typu
 *   DELETE /api/accounting/dimensions/types/{id}               — smazat (použitý se jen deaktivuje)
 *   POST   /api/accounting/dimensions/types/{id}/values        — nová hodnota
 *   PATCH  /api/accounting/dimensions/values/{id}              — úprava / přesun / uzavření hodnoty
 *   DELETE /api/accounting/dimensions/values/{id}              — smazat (použitá se jen uzavře)
 *   GET    /api/accounting/dimensions/responsible-candidates   — uživatelé firmy (odpovědná osoba)
 *   GET|PUT /api/accounting/dimensions/documents/{doc}/{id}    — dimenze dokladu (hlavička + položky)
 *   POST   /api/accounting/dimensions/documents/{doc}/{id}/preview — náhled dimenzí zaúčtovaných řádků
 *   GET|PUT /api/accounting/dimensions/journal/{id}            — dimenze řádků účetního zápisu
 *   GET|POST|PUT|DELETE /api/accounting/dimensions/group       — skupina firem (globální dimenze)
 *
 * Čtení = `accounting` READ, zápisy = `accounting` WRITE (RoutePermissionMap i tady),
 * zapnutí sekce a skupina firem = správa firmy.
 */
final class DimensionAction
{
    use AccountingActionSupport;

    /** Segment URL => typ dokladu v document_dimensions. */
    private const DOCUMENTS = [
        'purchase-invoices' => 'purchase_invoice',
        'invoices' => 'invoice',
        'cash-documents' => 'cash_document',
        'bank-transactions' => 'bank_transaction',
        'journal-templates' => 'journal_template',
    ];

    public function __construct(
        private readonly DimensionService $dimensions,
        private readonly UserSupplierRepository $userSuppliers,
        private readonly DimensionRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function overview(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->dimensions->overview($this->currentSupplierId($request)));
    }

    public function settings(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'settings.company.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $enabled = (bool) ($body['enabled'] ?? false);
        $this->dimensions->setEnabled($supplierId, $enabled);
        if ($enabled && !empty($body['create_defaults'])) {
            $this->dimensions->ensureDefaultTypes($supplierId);
        }
        $this->log($request, 'dimension.settings_updated', null, ['enabled' => $enabled]);
        return Json::ok($response, $this->dimensions->overview($supplierId));
    }

    public function defaults(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $ids = $this->dimensions->ensureDefaultTypes($supplierId);
            $this->log($request, 'dimension.defaults_created', null, $ids);
            return $this->dimensions->overview($supplierId);
        });
    }

    public function createType(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $type = $this->dimensions->createType($supplierId, (array) ($request->getParsedBody() ?? []));
            $this->log($request, 'dimension.type_created', (int) $type['id'], ['code' => $type['code'], 'level' => $type['level']]);
            return $type;
        }, 201);
    }

    public function updateType(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $body = (array) ($request->getParsedBody() ?? []);
            $type = $this->dimensions->updateType($supplierId, $id, $body);
            $this->log($request, 'dimension.type_updated', $id, ['fields' => array_keys($body)]);
            return $type;
        });
    }

    public function deleteType(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $result = $this->dimensions->deleteType($supplierId, $id);
            $this->log($request, $result['deleted'] ? 'dimension.type_deleted' : 'dimension.type_deactivated', $id, []);
            return $result;
        });
    }

    public function createValue(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $value = $this->dimensions->createValue($supplierId, (int) ($args['id'] ?? 0), (array) ($request->getParsedBody() ?? []));
            $this->log($request, 'dimension.value_created', (int) $value['id'], ['type_id' => $value['type_id'], 'code' => $value['code']]);
            return $value;
        }, 201);
    }

    public function updateValue(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $body = (array) ($request->getParsedBody() ?? []);
            $value = $this->dimensions->updateValue($supplierId, $id, $body);
            $this->log($request, 'dimension.value_updated', $id, ['fields' => array_keys($body)]);
            return $value;
        });
    }

    public function deleteValue(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $id = (int) ($args['id'] ?? 0);
            $result = $this->dimensions->deleteValue($supplierId, $id);
            $this->log($request, $result['deleted'] ? 'dimension.value_deleted' : 'dimension.value_closed', $id, []);
            return $result;
        });
    }

    public function responsibleCandidates(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->dimensions->responsibleCandidates($this->currentSupplierId($request)));
    }

    public function getDocument(Request $request, Response $response, array $args): Response
    {
        $docType = self::DOCUMENTS[(string) ($args['doc'] ?? '')] ?? '';
        try {
            return Json::ok($response, $this->dimensions->documentDimensions(
                $this->currentSupplierId($request),
                $docType,
                (int) ($args['id'] ?? 0),
            ));
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function saveDocument(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $docType = self::DOCUMENTS[(string) ($args['doc'] ?? '')] ?? '';
            $docId = (int) ($args['id'] ?? 0);
            $body = (array) ($request->getParsedBody() ?? []);
            $result = $this->dimensions->saveDocument(
                $supplierId,
                $docType,
                $docId,
                (array) ($body['header'] ?? []),
                self::itemsFromBody($body),
            );
            $this->logDocument($request, $docId, $docType, $result, 'document');
            return $result;
        });
    }

    /**
     * POST …/documents/{doc}/{id}/preview — co by po uložení dimenzí neslo každý
     * zaúčtovaný řádek dokladu (dialog Přeúčtovat). Nic se neuloží.
     */
    public function previewDocument(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $body = (array) ($request->getParsedBody() ?? []);
            return $this->dimensions->previewDocument(
                $supplierId,
                self::DOCUMENTS[(string) ($args['doc'] ?? '')] ?? '',
                (int) ($args['id'] ?? 0),
                (array) ($body['header'] ?? []),
                self::itemsFromBody($body),
            );
        });
    }

    /**
     * Položky jen tehdy, když je tělo posílá — panel na detailu dokladu (a dialog
     * Přeúčtovat) mění jen hlavičku a dimenze položek z editoru smazat nesmí.
     *
     * @param array<string,mixed> $body
     * @return array<int|string,mixed>|null
     */
    private static function itemsFromBody(array $body): ?array
    {
        return array_key_exists('items', $body) ? (array) $body['items'] : null;
    }

    /**
     * Audit změny dimenzí dokladu — kdo a kdy změnil analytiku i zaúčtovaných řádků
     * (i v uzavřeném období, kde jinak žádná změna zápisu projít nesmí).
     *
     * @param array{header:array<int,int>, items:array<int,array<int,int>>, restamp:array{lines:int,needs_repost:bool,locked:bool}} $result
     */
    private function logDocument(Request $request, int $docId, string $docType, array $result, string $via): void
    {
        $this->log($request, 'dimension.document_updated', $docId, [
            'doc_type' => $docType,
            'via' => $via,
            'header' => $result['header'],
            'items' => $result['items'],
            'restamped_lines' => $result['restamp']['lines'],
            'locked_period' => $result['restamp']['locked'],
        ]);
    }

    public function getJournal(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, (object) $this->dimensions->entryLineDimensions(
            $this->currentSupplierId($request),
            (int) ($args['id'] ?? 0),
        ));
    }

    public function saveJournal(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $entryId = (int) ($args['id'] ?? 0);
            $body = (array) ($request->getParsedBody() ?? []);
            $changed = $this->dimensions->saveEntryLines($supplierId, $entryId, (array) ($body['lines'] ?? []));
            $this->log($request, 'dimension.journal_lines_updated', $entryId, ['lines' => (array) ($body['lines'] ?? []), 'changed' => $changed]);
            return ['changed' => $changed, 'lines' => (object) $this->dimensions->entryLineDimensions($supplierId, $entryId)];
        });
    }

    public function group(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->dimensions->groupInfo(
            $this->currentSupplierId($request),
            $this->accessibleSupplierIds($request),
        ));
    }

    /** POST — založit novou skupinu s aktuální firmou. */
    public function createGroup(Request $request, Response $response): Response
    {
        if (!$this->requireCompanyAdmin($request, $response, $err)) return $err;
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $body = (array) ($request->getParsedBody() ?? []);
            $id = $this->dimensions->createGroup($supplierId, (string) ($body['name'] ?? ''));
            $this->log($request, 'dimension.group_created', $id, ['name' => $body['name'] ?? null]);
            return $this->dimensions->groupInfo($supplierId, $this->accessibleSupplierIds($request));
        }, 201);
    }

    /** PUT — `{group_id}` připojí firmu k existující skupině, `{name}` skupinu přejmenuje. */
    public function updateGroup(Request $request, Response $response): Response
    {
        if (!$this->requireCompanyAdmin($request, $response, $err)) return $err;
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $body = (array) ($request->getParsedBody() ?? []);
            $accessible = $this->accessibleSupplierIds($request);
            if (isset($body['group_id'])) {
                $groupId = (int) $body['group_id'];
                $this->dimensions->joinGroup($supplierId, $groupId, $accessible, RequestAuthorization::isSuperadmin($request));
                $this->log($request, 'dimension.group_joined', $groupId, []);
            } elseif (isset($body['name'])) {
                $this->dimensions->renameGroup($supplierId, (string) $body['name']);
                $this->log($request, 'dimension.group_renamed', null, ['name' => $body['name']]);
            }
            return $this->dimensions->groupInfo($supplierId, $accessible);
        });
    }

    /** DELETE — firma opustí skupinu (globální dimenze přestane vidět). */
    public function leaveGroup(Request $request, Response $response): Response
    {
        if (!$this->requireCompanyAdmin($request, $response, $err)) return $err;
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $this->dimensions->leaveGroup($supplierId);
            $this->log($request, 'dimension.group_left', null, []);
            return $this->dimensions->groupInfo($supplierId, $this->accessibleSupplierIds($request));
        });
    }

    // ── interní ──────────────────────────────────────────────────────────────

    /** @param callable(int):array<mixed>|object $fn */
    private function run(Request $request, Response $response, callable $fn, int $status = 200): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        try {
            return Json::ok($response, $fn($this->currentSupplierId($request)), $status);
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return Json::error($response, 'integrity_violation', 'Hodnota je použitá nebo koliduje s jinou.', 409);
            }
            throw $e;
        }
    }

    private function requireCompanyAdmin(Request $request, Response $response, ?Response &$err): bool
    {
        if (!RequestAuthorization::isCompanyAdmin($request)
            || !RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            $err = Json::error($response, 'forbidden', 'Skupinu firem spravuje jen správce firmy.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    /**
     * Firmy, ke kterým má uživatel výslovný přístup. Superadmin vidí všechny; účet
     * bez výslovného členství tu nevidí žádnou — připojit firmu ke skupině smí jen
     * ten, kdo firmy skupiny prokazatelně spravuje.
     *
     * @return list<int>
     */
    private function accessibleSupplierIds(Request $request): array
    {
        if (RequestAuthorization::isSuperadmin($request)) {
            return $this->repo->allSupplierIds();
        }
        return $this->userSuppliers->allowedSupplierIds((int) ($this->userId($request) ?? 0));
    }

    /** @param array<string,mixed> $payload */
    private function log(Request $request, string $action, ?int $entityId, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'dimension',
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
