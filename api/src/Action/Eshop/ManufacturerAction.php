<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ManufacturerRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Support\SafeUrl;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Výrobci — číselník (Epic ESHOP).
 *
 *   GET    /api/eshop/manufacturers        — seznam
 *   POST   /api/eshop/manufacturers        — nový
 *   GET    /api/eshop/manufacturers/{id}   — detail
 *   PUT    /api/eshop/manufacturers/{id}   — úprava
 *   DELETE /api/eshop/manufacturers/{id}   — smazání (jen když není referencovaný)
 */
final class ManufacturerAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly ManufacturerRepository $manufacturers,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $activeOnly = !empty($q['active']);
        return Json::ok($response, $this->manufacturers->listForSupplier($supplierId, $activeOnly));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $row = $this->manufacturers->find($supplierId, (int) $args['id']);
        if ($row === null) {
            return Json::error($response, 'not_found', 'Výrobce nenalezen.', 404);
        }
        return Json::ok($response, $row);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $verr] = $this->validate($response, $body);
        if ($verr !== null) {
            return $verr;
        }
        if ($this->manufacturers->findByCode($supplierId, $data['code']) !== null) {
            return Json::error($response, 'manufacturer_code_taken', 'Výrobce s tímto kódem už existuje.', 409);
        }
        $id = $this->manufacturers->insert($supplierId, $data);
        $this->log($request, 'eshop.manufacturer_created', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->manufacturers->find($supplierId, $id) ?? [], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $existing = $this->manufacturers->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Výrobce nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $verr] = $this->validate($response, $body, $existing);
        if ($verr !== null) {
            return $verr;
        }
        $byCode = $this->manufacturers->findByCode($supplierId, $data['code']);
        if ($byCode !== null && (int) $byCode['id'] !== $id) {
            return Json::error($response, 'manufacturer_code_taken', 'Výrobce s tímto kódem už existuje.', 409);
        }
        $this->manufacturers->update($supplierId, $id, $data);
        $this->log($request, 'eshop.manufacturer_updated', $id, ['code' => $data['code']]);
        return Json::ok($response, $this->manufacturers->find($supplierId, $id) ?? []);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $existing = $this->manufacturers->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Výrobce nenalezen.', 404);
        }
        if ($this->manufacturers->isReferenced($supplierId, $id)) {
            return Json::error($response, 'manufacturer_in_use', 'Výrobce nelze smazat — je přiřazen ke zboží. Archivujte jej místo mazání.', 409, ['suggestion' => 'archive']);
        }
        $this->manufacturers->delete($supplierId, $id);
        $this->log($request, 'eshop.manufacturer_deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>, 1:?Response}
     */
    private function validate(Response $response, array $body, ?array $existing = null): array
    {
        $code = trim((string) ($body['code'] ?? $existing['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? $existing['name'] ?? ''));
        if ($code === '' || mb_strlen($code) > 50) {
            return [[], Json::error($response, 'validation_failed', 'Kód výrobce je povinný (max 50 znaků).', 400)];
        }
        if ($name === '' || mb_strlen($name) > 150) {
            return [[], Json::error($response, 'validation_failed', 'Název výrobce je povinný (max 150 znaků).', 400)];
        }
        if (array_key_exists('website', $body)) {
            // SEC-10 — hodnota se renderuje do href, takže povolujeme jen absolutní
            // http(s) URL. Prázdný vstup / null maže web, nepoužitelný odmítáme.
            // Jiný typ než string (pole, objekt) nepřetypováváme, rovnou odmítáme.
            $rawWebsite = $body['website'];
            if ($rawWebsite === null || (is_string($rawWebsite) && trim($rawWebsite) === '')) {
                $website = null;
            } elseif (!is_string($rawWebsite)) {
                return [[], Json::error($response, 'validation_failed', 'Web výrobce musí být textová hodnota.', 400)];
            } else {
                $website = SafeUrl::normalizeWebUrl(trim($rawWebsite));
                if ($website === null && !$this->isUnchangedLegacyWebsite($rawWebsite, $existing)) {
                    return [[], Json::error($response, 'validation_failed', 'Web výrobce musí být platná adresa začínající http:// nebo https:// (max 255 znaků).', 400)];
                }
            }
        } else {
            // Uložená hodnota pochází z dřívějška — projede stejným filtrem, ať se
            // legacy záznam neprotáhne zpátky do odpovědi neověřený.
            $website = SafeUrl::normalizeWebUrl($existing['website'] ?? null);
        }
        $data = [
            'code'          => $code,
            'name'          => $name,
            'website'       => $website,
            'display_order' => array_key_exists('display_order', $body) ? (int) $body['display_order'] : (int) ($existing['display_order'] ?? 0),
            'export_eshop'  => array_key_exists('export_eshop', $body) ? (bool) $body['export_eshop'] : (bool) ($existing['export_eshop'] ?? true),
            'archived'      => array_key_exists('archived', $body) ? (bool) $body['archived'] : (bool) ($existing['archived'] ?? false),
        ];
        return [$data, null];
    }

    /**
     * Vrátí true, když neplatná adresa v požadavku je beze změny ta, která už je v DB.
     *
     * SEC-10 (2. kolo) — legacy záznam může mít uloženou adresu, kterou dnešní filtr
     * neprojde. Formulář posílá celý objekt zpátky, takže tvrdé 400 by u takového
     * výrobce znemožnilo změnit i jméno — pole, kterého se uživatel ani nedotkl.
     * Request proto nezablokujeme, ale hodnotu ANI NEZACHOVÁME: volající ji nechá
     * `null`, tj. nebezpečná adresa (např. `javascript:`) se z DB smaže. Grandfathering
     * by uloženou aktivní URL držel naživu, což nechceme. Odlišnou neplatnou hodnotu
     * (skutečný překlep) dál hlásíme uživateli chybou.
     *
     * @param array<string,mixed>|null $existing
     */
    private function isUnchangedLegacyWebsite(string $rawWebsite, ?array $existing): bool
    {
        $stored = $existing['website'] ?? null;
        if (!is_string($stored)) {
            return false;
        }
        return trim($stored) !== '' && trim($stored) === trim($rawWebsite);
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'manufacturer',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
