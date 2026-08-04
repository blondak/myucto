<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollEmploymentAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $validator,
        private readonly PayrollModuleAccess $access,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array{id:string} $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $employment = $this->employments->create(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->create($this->body($request)),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment], 201);
    }

    /** @param array{id:string} $args */
    public function addTerms(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $body = $this->body($request);
            $employment = $this->employments->addTerms(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $this->validator->terms($body),
                $this->validator->rowVersion($body),
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment]);
    }

    /** @param array{id:string,target:string} $args */
    public function transition(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->transition($this->body($request));
            $employment = $this->employments->transition(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $args['target'],
                $data['row_version'],
                $data['effective_on'],
                $data['note'],
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment]);
    }

    /** @param array{id:string,item_key:string} $args */
    public function checklist(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }
        try {
            $data = $this->validator->checklist($this->body($request));
            $employment = $this->employments->updateChecklist(
                $this->currentSupplierId($request),
                (int) $args['id'],
                $args['item_key'],
                $data['row_version'],
                $data['status'],
                $data['note'],
                $this->userId($request),
                $this->ip($request),
                $request->getHeaderLine('User-Agent'),
            );
        } catch (\Throwable $e) {
            return $this->domainError($response, $e);
        }
        return Json::ok($response, ['employment' => $employment]);
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) === 'bearer') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené relace.',
                403,
            );
        }
        $error = null;
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.employment.write',
            AccessLevel::WRITE,
            $error,
        )) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
        }
        $result = [];
        foreach ($body as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Tělo požadavku musí být objekt.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function ip(Request $request): string
    {
        $params = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return $this->ipMatcher->clientIpFromRequest($params);
    }

    private function domainError(Response $response, \Throwable $e): Response
    {
        return match (true) {
            $e instanceof PayrollEmploymentNotFoundException => Json::error(
                $response,
                'not_found',
                $e->getMessage(),
                404,
            ),
            $e instanceof PayrollEmploymentConflictException => Json::error(
                $response,
                'row_version_conflict',
                $e->getMessage(),
                409,
                ['current_row_version' => $e->currentVersion],
            ),
            $e instanceof \DomainException => Json::error(
                $response,
                'invalid_transition',
                $e->getMessage(),
                409,
            ),
            $e instanceof \InvalidArgumentException => Json::error(
                $response,
                'validation_failed',
                $e->getMessage(),
                422,
            ),
            $e instanceof \PDOException && $e->getCode() === '23000' => Json::error(
                $response,
                'employment_conflict',
                'Pracovní vztah koliduje s existujícím kódem, intervalem nebo primárním vztahem.',
                409,
            ),
            default => throw $e,
        };
    }
}
