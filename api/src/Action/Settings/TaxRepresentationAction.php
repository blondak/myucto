<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tax\Return\TaxRepresentationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa evidence zastoupení a podepisující osoby u správce daně
 * — `supplier_tax_representation_history` (migrace 1662, kód podepisující osoby 1964).
 *
 *   POST   /api/settings/tax-representation-history                — přidání/úprava řádku (upsert po effective_from)
 *   DELETE /api/settings/tax-representation-history/{id}           — smazání řádku
 *
 * Seznam historie vrací GET /api/settings/supplier (klíč tax_representation_history).
 *
 * Záměrně BEZ retro-guardu proti uzamčeným účetním obdobím / už podaným přiznáním
 * (na rozdíl od {@see VatStatusHistoryAction}) — zdůvodnění je v migraci 1662: zastoupení
 * nic neúčtuje, ovlivňuje jen `dan_por`/`pln_moc`/`zast_*` v dosud nearchivovaném XML.
 */
final class TaxRepresentationAction
{
    public function __construct(
        private readonly TaxRepresentationService $representation,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function save(Request $request, Response $response): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        if ($sid <= 0) return Json::error($response, 'validation_failed', 'Chybí aktivní firma.', 400);

        $body = (array) ($request->getParsedBody() ?? []);

        $effectiveFrom = trim((string) ($body['effective_from'] ?? ''));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveFrom);
        if ($date === false || $date->format('Y-m-d') !== $effectiveFrom) {
            return Json::error($response, 'validation_failed', 'Datum účinnosti zastoupení není platné.', 422);
        }

        $represented = (bool) ($body['represented'] ?? false);
        $type = null;
        $firstName = null;
        $lastName = null;
        $companyName = null;
        $ico = null;
        $evNumber = null;
        $powerOfAttorneyGrantedOn = null;
        $code = null;
        $birthDate = null;
        $signerFirstName = null;
        $signerLastName = null;
        $signerPosition = null;

        if ($represented) {
            $type = trim((string) ($body['type'] ?? ''));
            if (!in_array($type, ['F', 'P'], true)) {
                return Json::error($response, 'validation_failed', 'Typ zástupce musí být F (fyzická osoba) nebo P (právnická osoba).', 422);
            }
            $code = trim((string) ($body['code'] ?? ''));
            if ($code === '') {
                $code = $type === 'P' ? '4c' : '4b';
            }
            if (!in_array($code, TaxRepresentationService::codesForType($type), true)) {
                return Json::error($response, 'validation_failed', $type === 'F'
                    ? 'Kód podepisující osoby ' . $code . ' nepatří k zástupci fyzické osobě.'
                    : 'Kód podepisující osoby ' . $code . ' nepatří k zástupci právnické osobě.', 422);
            }
            $evNumber = trim((string) ($body['ev_number'] ?? ''));
            if (mb_strlen($evNumber) > 36) {
                return Json::error($response, 'validation_failed', 'Evidenční číslo má max. 36 znaků.', 422);
            }
            if ($code === '4b' && $evNumber === '') {
                return Json::error($response, 'validation_failed', 'Evidenční číslo daňového poradce nebo advokáta je povinné.', 422);
            }

            if ($type === 'F') {
                $firstName = trim((string) ($body['first_name'] ?? ''));
                $lastName = trim((string) ($body['last_name'] ?? ''));
                if ($firstName === '' || $lastName === '') {
                    return Json::error($response, 'validation_failed', 'Jméno a příjmení zástupce jsou povinné.', 422);
                }
                if (mb_strlen($firstName) > 20 || mb_strlen($lastName) > 36) {
                    return Json::error($response, 'validation_failed', 'Jméno má max. 20 a příjmení max. 36 znaků (limit EPO).', 422);
                }
                $birthRaw = trim((string) ($body['birth_date'] ?? ''));
                if ($birthRaw !== '') {
                    $birth = \DateTimeImmutable::createFromFormat('!Y-m-d', $birthRaw);
                    if ($birth === false || $birth->format('Y-m-d') !== $birthRaw || (int) $birth->format('Y') < 1900) {
                        return Json::error($response, 'validation_failed', 'Datum narození zástupce není platné.', 422);
                    }
                    $birthDate = $birthRaw;
                }
                if ($evNumber === '' && $birthDate === null) {
                    return Json::error($response, 'validation_failed', 'U zástupce fyzické osoby vyplňte evidenční číslo nebo datum narození (vyžaduje EPO).', 422);
                }
            } else {
                $companyName = trim((string) ($body['company_name'] ?? ''));
                if ($companyName === '') {
                    return Json::error($response, 'validation_failed', 'Název zastupující právnické osoby je povinný.', 422);
                }
                if (mb_strlen($companyName) > 255) {
                    return Json::error($response, 'validation_failed', 'Název zastupující právnické osoby má max. 255 znaků.', 422);
                }
                $ico = preg_replace('/\D/', '', trim((string) ($body['ico'] ?? ''))) ?? '';
                if ($ico === '' || strlen($ico) > 10) {
                    return Json::error($response, 'validation_failed', 'IČO zastupující právnické osoby je povinné (vyžaduje EPO).', 422);
                }
                $signerFirstName = trim((string) ($body['signer_first_name'] ?? ''));
                $signerLastName = trim((string) ($body['signer_last_name'] ?? ''));
                $signerPosition = trim((string) ($body['signer_position'] ?? ''));
                if ($signerFirstName === '' || $signerLastName === '') {
                    return Json::error($response, 'validation_failed', 'Jméno a příjmení osoby, která za zastupující právnickou osobu podepisuje, jsou povinné.', 422);
                }
                if (mb_strlen($signerFirstName) > 20 || mb_strlen($signerLastName) > 36 || mb_strlen($signerPosition) > 40) {
                    return Json::error($response, 'validation_failed', 'Podepisující osoba: jméno max. 20, příjmení max. 36 a postavení max. 40 znaků (limit EPO).', 422);
                }
                $signerPosition = $signerPosition !== '' ? $signerPosition : null;
            }
            $evNumber = $evNumber !== '' ? $evNumber : null;

            $poaRaw = trim((string) ($body['power_of_attorney_granted_on'] ?? ''));
            if ($poaRaw !== '') {
                $poaDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $poaRaw);
                if ($poaDate === false || $poaDate->format('Y-m-d') !== $poaRaw) {
                    return Json::error($response, 'validation_failed', 'Datum udělení plné moci není platné.', 422);
                }
                $powerOfAttorneyGrantedOn = $poaRaw;
            }
        }

        $note = trim((string) ($body['note'] ?? ''));
        if (mb_strlen($note) > 255) {
            return Json::error($response, 'validation_failed', 'Poznámka má max. 255 znaků.', 422);
        }

        $this->representation->upsert(
            $sid,
            $effectiveFrom,
            $represented,
            $type,
            $firstName,
            $lastName,
            $companyName,
            $ico,
            $evNumber,
            $powerOfAttorneyGrantedOn,
            $note !== '' ? $note : null,
            $this->userId($request),
            $code,
            $birthDate,
            $signerFirstName,
            $signerLastName,
            $signerPosition,
        );

        $this->log($request, 'supplier.tax_representation_changed', $sid, [
            'effective_from' => $effectiveFrom,
            'represented' => $represented,
            'type' => $type,
            'code' => $code,
            'ev_number' => $evNumber,
        ]);

        return Json::ok($response, ['tax_representation_history' => $this->representation->history($sid)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, $err)) return $err;
        $sid = $this->supplierId($request);
        if ($sid <= 0) return Json::error($response, 'validation_failed', 'Chybí aktivní firma.', 400);

        $id = (int) ($args['id'] ?? 0);
        $deleted = $this->representation->delete($sid, $id);
        if (!$deleted) {
            return Json::error($response, 'not_found', 'Řádek historie zastoupení nenalezen.', 404);
        }

        $this->log($request, 'supplier.tax_representation_deleted', $sid, ['id' => $id]);

        return Json::ok($response, ['tax_representation_history' => $this->representation->history($sid)]);
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ((int) ($user['id'] ?? 0)) ?: null;
    }

    private function guard(Request $request, Response $response, ?Response &$err): bool
    {
        if (!RequestAuthorization::allows($request, 'settings.company.write', AccessLevel::WRITE)) {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);
            return false;
        }
        $err = null;
        return true;
    }

    private function log(Request $request, string $action, int $supplierId, array $payload): void
    {
        $this->logger->log(
            $action,
            (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0),
            'supplier',
            $supplierId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}
