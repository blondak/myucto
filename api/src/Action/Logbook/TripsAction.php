<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\TripRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Support\Pagination;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Jízdy (kniha jízd):
 *   GET    /api/logbook/trips         — list (?car_id=&category_id=&year=&month=&date_from=&date_to=&q=&page=&per_page=)
 *   GET    /api/logbook/trips/{id}
 *   POST   /api/logbook/trips
 *   PUT    /api/logbook/trips/{id}
 *   DELETE /api/logbook/trips/{id}
 */
final class TripsAction
{
    public function __construct(
        private readonly TripRepository $repo,
        private readonly CarRepository $cars,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly TenantReferenceGuard $tenantRefs,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $filters = array_intersect_key($q, array_flip(['car_id', 'category_id', 'year', 'month', 'date_from', 'date_to', 'q']));
        $p = Pagination::fromQuery($q, 50);
        [$rows, $total] = $this->repo->listPaged($supplierId, $filters, $p['per_page'], $p['offset']);
        $carId = !empty($filters['car_id']) ? (int) $filters['car_id'] : null;
        $years = $this->repo->distinctYears($supplierId, $carId);
        return Json::ok($response, array_merge(
            Pagination::envelope($rows, $total, $p['page'], $p['per_page']),
            ['years' => $years]
        ));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $trip = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($trip === null) return Json::error($response, 'not_found', 'Jízda nenalezena.', 404);
        return Json::ok($response, $trip);
    }

    /** Našeptávač účelů cest — distinct dříve zadané účely. */
    public function purposes(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, $this->repo->distinctPurposes($supplierId));
    }

    /** Našeptávač míst (odkud / kam) — distinct dříve zadaná místa. */
    public function places(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        return Json::ok($response, $this->repo->distinctPlaces($supplierId));
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->prepare($supplierId, $body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        if ($ref = $this->tenantRefError($supplierId, $body)) {
            return Json::error($response, 'invalid_reference', $ref, 400);
        }

        $id = $this->repo->create($supplierId, $body, $this->userId($request));
        $this->log($request, 'trip.created', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Jízda nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->prepare($supplierId, $body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        if ($ref = $this->tenantRefError($supplierId, $body)) {
            return Json::error($response, 'invalid_reference', $ref, 400);
        }

        $this->repo->update($id, $supplierId, $body);
        $this->log($request, 'trip.updated', $id, $body);
        return Json::ok($response, $this->repo->find($id, $supplierId));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Jízda nenalezena.', 404);
        }
        $this->repo->delete($id, $supplierId);
        $this->log($request, 'trip.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /** Validace + dopočet distance_km (mění $body in-place). */
    private function prepare(int $supplierId, array &$body): ?string
    {
        $carId = (int) ($body['car_id'] ?? 0);
        if ($carId <= 0) return 'Auto je povinné.';
        if ($this->cars->find($carId, $supplierId) === null) return 'Auto neexistuje.';

        $date = trim((string) ($body['trip_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return 'Neplatné datum (formát YYYY-MM-DD).';

        $odoStart = $this->intOrNull($body['odometer_start'] ?? null);
        $odoEnd   = $this->intOrNull($body['odometer_end'] ?? null);
        $distance = isset($body['distance_km']) && $body['distance_km'] !== '' ? (float) $body['distance_km'] : null;
        if ($distance === null || $distance <= 0) {
            if ($odoStart !== null && $odoEnd !== null && $odoEnd >= $odoStart) {
                $distance = (float) ($odoEnd - $odoStart);
            } else {
                return 'Vyplň ujeté km nebo platný stav tachometru (od ≤ do).';
            }
        }
        if ($odoStart !== null && $odoEnd !== null && $odoEnd < $odoStart) {
            return 'Konečný stav tachometru nesmí být menší než počáteční.';
        }
        $body['distance_km'] = $distance;
        return null;
    }

    /**
     * BOLA guard (security report 2026-08, R2 #7 / sweep F3) — car_id se v prepare()
     * kontroluje odjakživa, category_id se zapisovalo nevázané a TripRepository::find()
     * ho čte zpět nescoped joinem na číselník kategorií (unikal label i příznak
     * is_private cizí firmy).
     */
    private function tenantRefError(int $supplierId, array $body): ?string
    {
        $badRefs = $this->tenantRefs->violations($supplierId, $body, ['category_id']);

        return $badRefs !== [] ? TenantReferenceGuard::message($badRefs) : null;
    }

    private function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'trip', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
