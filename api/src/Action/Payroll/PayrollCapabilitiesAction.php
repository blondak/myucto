<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\SupportMatrix;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PayrollCapabilitiesAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly SupportMatrix $matrix,
        private readonly PayrollModuleStateRepository $state,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);

        return Json::ok($response, [
            'state' => $this->state->get($supplierId),
            'support_matrix' => $this->matrix->all(),
        ]);
    }
}
