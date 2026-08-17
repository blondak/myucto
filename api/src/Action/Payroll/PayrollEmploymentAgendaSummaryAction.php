<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollEmploymentAgendaSummaryRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Souhrn navazujících agend jednoho pracovního vztahu pro kartu zaměstnance.
 *
 * Agendy se filtrují PODLE OPRÁVNĚNÍ volajícího, ne až v prohlížeči: počet
 * exekučních případů je sám o sobě citlivý údaj, takže ho účetní bez práva
 * `payroll.enforcement` nesmí dostat ani jako číslo. Chybějící právo proto
 * agendu ze seznamu vypustí — nevrací se nula, která by lhala.
 */
final class PayrollEmploymentAgendaSummaryAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollEmploymentAgendaSummaryRepository $summaries,
        private readonly PayrollModuleAccess $access,
    ) {}

    /** @param array{id:string} $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll', AccessLevel::READ, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď oprávnění.');
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error ?? throw new \LogicException('Chybí chybová odpověď modulu.');
        }

        $supplierId = $this->currentSupplierId($request);
        $employment = $this->summaries->findEmployment($supplierId, (int) $args['id']);
        if ($employment === null) {
            return Json::error($response, 'not_found', 'Pracovní vztah neexistuje.', 404);
        }

        $allowed = array_values(array_filter(
            PayrollEmploymentAgendaSummaryRepository::agendaKeys(),
            fn (string $agenda): bool => RequestAuthorization::allows(
                $request,
                PayrollEmploymentAgendaSummaryRepository::permissionOf($agenda),
                AccessLevel::READ,
            ),
        ));

        return Json::ok($response, ['summary' => [
            'employment_id' => $employment['id'],
            'employee_id' => $employment['employee_id'],
            'agendas' => $this->summaries->summary(
                $supplierId,
                $employment['id'],
                $employment['employee_id'],
                $allowed,
            ),
        ]]);
    }
}
