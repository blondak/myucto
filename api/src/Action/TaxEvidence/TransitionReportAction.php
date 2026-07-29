<?php

declare(strict_types=1);

namespace MyInvoice\Action\TaxEvidence;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Service\TaxEvidence\TransitionReportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Podklady pro přechodový můstek mezi daňovou evidencí a účetnictvím podle
 * příloh č. 2 a 3 ZDP.
 * READ-ONLY.
 *
 *   GET /api/tax-evidence/transition-report?as_of=YYYY-MM-DD&direction=tax_to_accounting|accounting_to_tax
 */
final class TransitionReportAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly TransitionReportService $service,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
        private readonly AccountingModeRepository $accountingModes,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $query = $request->getQueryParams();
        $direction = trim((string) ($query['direction'] ?? 'tax_to_accounting'));
        if (!in_array($direction, ['tax_to_accounting', 'accounting_to_tax'], true)) {
            return Json::error($response, 'validation_failed', 'direction musí být tax_to_accounting nebo accounting_to_tax.', 422);
        }
        if ($direction === 'tax_to_accounting' && !$this->accountingModes->hasTaxEvidence($supplierId)) {
            return Json::error($response, 'transition_not_applicable', 'Firma nikdy nevedla daňovou evidenci.', 403);
        }
        if ($direction === 'accounting_to_tax' && !$this->accountingModes->hasDoubleEntry($supplierId)) {
            return Json::error($response, 'transition_not_applicable', 'Firma nikdy nevedla účetnictví.', 403);
        }

        $asOf = trim((string) ($query['as_of'] ?? ''));
        if ($asOf === '') {
            $asOf = (new \DateTimeImmutable())->format('Y-m-d');
        }
        if (!$this->isDate($asOf)) {
            return Json::error($response, 'validation_failed', 'as_of musí být datum (YYYY-MM-DD).', 422);
        }

        try {
            $data = $this->service->build($supplierId, $asOf, $direction);
        } catch (\Throwable $e) {
            $this->log->error('Přechodový report se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        return Json::ok($response, $data);
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
