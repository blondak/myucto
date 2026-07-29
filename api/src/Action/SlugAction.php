<?php

declare(strict_types=1);

namespace MyInvoice\Action;

use MyInvoice\Http\Json;
use MyInvoice\Support\Slugifier;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sdílený slug helper pro číselníky (e-shop, admin/codebooks, …).
 *
 *   GET /api/slug?text=Oblečení  → { "slug": "obleceni" }
 *
 * Vystavuje {@see Slugifier} (mód `code`: bez diakritiky, malá písmena, pomlčka
 * mezi slovy). Frontend jím předvyplňuje pole „Kód" z „Název"; server je jediný
 * zdroj pravdy, takže preview odpovídá tomu, co by se uložilo. Bez tenant/role
 * kontextu — čistá textová transformace za běžnou autentizací API.
 */
final class SlugAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        $text = (string) ($request->getQueryParams()['text'] ?? '');
        return Json::ok($response, ['slug' => Slugifier::slug($text, '-', 'lower', 50)]);
    }
}
