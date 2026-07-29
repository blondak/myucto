<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Service\Report\EpoOkecCodebook;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/settings/nace-codes?q=…&limit=… — našeptávač CZ-NACE pro Nastavení.
 *
 * Vrací JEN kódy platné k dnešku z číselníku ČINNOSTI (viz EpoOkecCodebook):
 * ARES eviduje klasifikaci ještě podle NACE rev. 2, jenže číselník EPO se
 * k 1. 1. 2026 překlopil na rev. 2.1 a starým kódům nastavil konec platnosti.
 * Prefill z ARES tak často přinese kód, který portál odmítne propustnou
 * chybou 30 — našeptávač je cesta, jak si uživatel najde nástupce.
 *
 * Hledá se podle čísel v dotazu (prefix kódu, „73" i „01.48") nebo podle názvu
 * činnosti bez ohledu na diakritiku. Prázdný dotaz vrátí první stránku.
 *
 * Response: { "items": [ { "code": "731100", "display": "73.11.00",
 *                          "name": "Činnosti reklamních agentur",
 *                          "valid_from": "2026-01-01" } ] }
 *
 * Read-only referenční data — stačí přihlášení (pole samo je editovatelné
 * jen adminem, to hlídá PUT /api/settings/supplier).
 */
final class NaceCodesAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $limit = (int) ($q['limit'] ?? 20);

        return Json::ok($response, [
            'items' => EpoOkecCodebook::search((string) ($q['q'] ?? ''), $limit > 0 ? $limit : 20),
        ]);
    }
}
