<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPersonProfileConflictException;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollPersonProfileValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** @phpstan-import-type ProfileView from \MyInvoice\Repository\Payroll\PayrollPersonProfileRepository */
final class PayrollPersonProfileAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly PayrollPersonProfileValidator $validator,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{id:string} $args */
    public function get(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->requireSession($request, $response, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->errorResponse($error);
        }

        $profile = $this->profiles->get(
            $this->currentSupplierId($request),
            (int) $args['id'],
        );
        if ($profile === null) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        }

        return Json::ok($response, ['profile' => $profile]);
    }

    /** @param array{id:string} $args */
    public function put(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->requireSession($request, $response, $error)) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.person.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $this->errorResponse($error);
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $this->errorResponse($error);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return Json::error($response, 'validation_failed', 'Tělo požadavku musí být objekt.', 422);
        }
        try {
            $body = $this->stringKeyedArray($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        $version = filter_var($body['row_version'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if (!is_int($version)) {
            return Json::error(
                $response,
                'validation_failed',
                'row_version musí být nezáporné celé číslo.',
                422,
            );
        }

        $supplierId = $this->currentSupplierId($request);
        $employeeId = (int) $args['id'];
        try {
            $normalized = $this->validator->validate($body);
            $profile = $this->profiles->save(
                $supplierId,
                $employeeId,
                $normalized,
                $version,
                $this->userId($request),
                $this->ipMatcher->clientIpFromRequest(
                    $this->stringKeyedArray($request->getServerParams()),
                ),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (PayrollPersonNotFoundException) {
            return Json::error($response, 'not_found', 'Zaměstnanec nenalezen.', 404);
        } catch (PayrollPersonProfileConflictException $e) {
            return Json::error($response, 'row_version_conflict', $e->getMessage(), 409, [
                'current_row_version' => $e->currentVersion,
            ]);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, ['profile' => $profile]);
    }

    private function requireSession(
        Request $request,
        Response $response,
        ?Response &$error,
    ): bool {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            $error = Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
            return false;
        }
        $error = null;

        return true;
    }

    private function errorResponse(?Response $error): Response
    {
        return $error ?? throw new \LogicException('Chybí chybová HTTP odpověď.');
    }

    /**
     * @param array<mixed> $value
     * @return array<string,mixed>
     */
    private function stringKeyedArray(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
