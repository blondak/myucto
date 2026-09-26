<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\OpenItemsService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Otevřené položky a párování řádků deníku (okruhy) na libovolném účtu.
 *
 *   GET    /api/accounting/open-items/{accountId}?as_of=&only_open=&page=&per_page=
 *   GET    /api/accounting/open-items/{accountId}/suggestions?as_of=&days=
 *   POST   /api/accounting/open-items/{accountId}/suggestions/apply   {as_of, days, pairs?}
 *   POST   /api/accounting/open-items/{accountId}/pairings            {line_ids, note?}
 *   POST   /api/accounting/open-items/pairings/delete                 {pairing_ids}
 *   GET    /api/accounting/open-items/pairings/{id}
 *   POST   /api/accounting/open-items/pairings/{id}/lines             {line_ids}
 *   DELETE /api/accounting/open-items/pairings/{id}/lines/{entryId}/{lineNo}
 *
 * Práva řeší RoutePermissionMap (/api/accounting: GET = čtení, ostatní = zápis
 * účetnictví); zápisové metody si právo ověří i samy. ZÁMĚRNĚ bez guardu
 * uzavřeného období: párování je metadata, deník nemění.
 */
final class OpenItemsAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_PER_PAGE = 500;
    private const MAX_LINES = 500;

    public function __construct(
        private readonly OpenItemsService $service,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $asOf = $this->asOf($q, $response, $err);
        if ($asOf === null) return $err;

        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 100)));
        $onlyOpen = (string) ($q['only_open'] ?? '1') !== '0';

        return $this->run($response, fn () => $this->service->build(
            $supplierId, (int) ($args['accountId'] ?? 0), $asOf, $onlyOpen, $page, $perPage,
        ));
    }

    public function suggestions(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $q = $request->getQueryParams();
        $asOf = $this->asOf($q, $response, $err);
        if ($asOf === null) return $err;
        $days = $this->days($q);

        return $this->run($response, fn () => [
            'as_of' => $asOf,
            'days'  => $days,
            'items' => $this->service->suggestions($supplierId, (int) ($args['accountId'] ?? 0), $asOf, $days),
        ]);
    }

    public function applySuggestions(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $asOf = $this->asOf($body, $response, $err);
        if ($asOf === null) return $err;

        $pairs = null;
        if (array_key_exists('pairs', $body) && $body['pairs'] !== null) {
            if (!is_array($body['pairs']) || count($body['pairs']) > OpenItemsService::MAX_SUGGESTIONS) {
                return Json::error($response, 'validation_failed', 'pairs musí být seznam dvojic řádků.', 422);
            }
            $pairs = [];
            foreach ($body['pairs'] as $pair) {
                if (!is_array($pair) || count($pair) < 2) {
                    return Json::error($response, 'validation_failed', 'pairs musí být seznam dvojic řádků.', 422);
                }
                $pairs[] = array_values(array_map('intval', $pair));
            }
        }

        $accountId = (int) ($args['accountId'] ?? 0);
        $meta = $this->meta($request);
        return $this->run($response, fn () => [
            'created' => $this->service->applySuggestions($supplierId, $accountId, $asOf, $this->days($body), $pairs, $meta),
        ]);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $lineIds = $this->lineIds($body, $response, $err);
        if ($lineIds === null) return $err;
        $note = isset($body['note']) && is_string($body['note']) ? $body['note'] : null;

        $accountId = (int) ($args['accountId'] ?? 0);
        $meta = $this->meta($request);
        return $this->run($response, fn () => $this->service->create($supplierId, $accountId, $lineIds, $note, $meta), 201);
    }

    public function pairing(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        return $this->run($response, fn () => $this->service->pairing($supplierId, (int) ($args['id'] ?? 0)));
    }

    public function addLines(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $lineIds = $this->lineIds($body, $response, $err);
        if ($lineIds === null) return $err;

        $meta = $this->meta($request);
        return $this->run($response, fn () => $this->service->addLines($supplierId, (int) ($args['id'] ?? 0), $lineIds, $meta));
    }

    public function removeLine(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $meta = $this->meta($request);
        return $this->run($response, fn () => [
            'pairing' => $this->service->removeLine(
                $supplierId,
                (int) ($args['id'] ?? 0),
                (int) ($args['entryId'] ?? 0),
                (int) ($args['lineNo'] ?? 0),
                $meta,
            ),
        ]);
    }

    public function delete(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $ids = $body['pairing_ids'] ?? null;
        if (!is_array($ids) || $ids === [] || count($ids) > self::MAX_LINES) {
            return Json::error($response, 'validation_failed', 'pairing_ids musí být neprázdný seznam.', 422);
        }

        $meta = $this->meta($request);
        return $this->run($response, fn () => [
            'deleted' => $this->service->delete($supplierId, array_values(array_map('intval', $ids)), $meta),
        ]);
    }

    /**
     * @param callable():mixed $fn
     */
    private function run(Response $response, callable $fn, int $status = 200): Response
    {
        try {
            return Json::ok($response, $fn(), $status);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Otevřené položky: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Akci se nepodařilo provést.', 500);
        }
    }

    /**
     * @param array<string,mixed> $source
     */
    private function asOf(array $source, Response $response, ?Response &$err): ?string
    {
        $v = trim((string) ($source['as_of'] ?? ''));
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            $err = Json::error($response, 'validation_failed', 'as_of je povinné datum (YYYY-MM-DD).', 422);
            return null;
        }
        $err = null;
        return $v;
    }

    /**
     * @param array<string,mixed> $source
     */
    private function days(array $source): int
    {
        $days = (int) ($source['days'] ?? OpenItemsService::DEFAULT_SUGGESTION_DAYS);
        return max(0, min(366, $days));
    }

    /**
     * @param array<string,mixed> $body
     * @return list<int>|null
     */
    private function lineIds(array $body, Response $response, ?Response &$err): ?array
    {
        $ids = $body['line_ids'] ?? null;
        if (!is_array($ids) || $ids === [] || count($ids) > self::MAX_LINES) {
            $err = Json::error($response, 'validation_failed', 'line_ids musí být neprázdný seznam řádků deníku.', 422);
            return null;
        }
        $err = null;
        return array_values(array_map('intval', $ids));
    }

    /**
     * @return array{user_id:?int, ip:?string, user_agent:?string}
     */
    private function meta(Request $request): array
    {
        return [
            'user_id'    => $this->userId($request),
            'ip'         => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            'user_agent' => $request->getHeaderLine('User-Agent'),
        ];
    }
}
