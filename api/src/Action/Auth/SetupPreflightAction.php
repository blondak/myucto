<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Service\System\EnvironmentCheckService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/auth/setup-preflight — audit prostředí PŘED prvním setupem.
 *
 * Setup wizard ho volá jako první krok: dokud instalace neběží, nemá se kde
 * dozvědět, že v prostředí chybí rozšíření PHP nebo je stará MariaDB, a člověk
 * by na to narazil až uprostřed práce s ostrými daty.
 *
 * Endpoint je záměrně veřejný — na čerstvé instalaci ještě žádný účet není.
 * Proto vrací jen verdikt kontrol z {@see EnvironmentCheckService::PREFLIGHT_CHECKS},
 * ne naměřená fakta o stroji, a jakmile setup proběhne, končí na 409: od té chvíle
 * je totéž (a víc) v Systém → Diagnostika, kam se dostane jen admin.
 */
final class SetupPreflightAction
{
    public function __construct(
        private readonly FirstRunLockMiddleware $lockProbe,
        private readonly EnvironmentCheckService $environment,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->lockProbe->needsSetup()) {
            return Json::error(
                $response,
                'setup_already_done',
                'Setup už proběhl. Kontrola prostředí je v sekci Systém → Diagnostika.',
                409,
            );
        }

        return Json::ok($response, $this->environment->preflight());
    }
}
