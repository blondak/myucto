<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Bank;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierBankAccountRepository;
use MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SupplierBankAccountAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly SupplierBankAccountRepository $accounts,
        private readonly Connection $db,
        private readonly BankAnalyticAssigner $analytics,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        // Účet zaevidovaný z výpisu vzniká bez analytiky. Doplní se nejpozději při prvním
        // zaúčtování (BankAnalyticResolver), ale to by na TÉHLE stránce znamenalo prázdné
        // políčko u účtu, který analytiku mít má — tak ji dohrajeme rovnou. Běží až za
        // requireDoubleEntry: v daňové evidenci žádná osnova ani deník není.
        $this->analytics->ensureAllForSupplier($supplierId);
        return Json::ok($response, ['accounts' => array_map([$this, 'publicRow'], $this->accounts->listForSupplier($supplierId))]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);
        $current = $this->accounts->find($supplierId, $id);
        if ($current === null) {
            return Json::error($response, 'not_found', 'Bankovní účet nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $patch = [];
        if (array_key_exists('kind', $body)) {
            $kind = (string) $body['kind'];
            if (!in_array($kind, ['current', 'savings', 'term_deposit'], true)) {
                return Json::error($response, 'validation_failed', 'Neplatný druh bankovního účtu.', 422);
            }
            $patch['kind'] = $kind;
        }
        if (array_key_exists('label', $body)) {
            $label = trim((string) $body['label']);
            if (mb_strlen($label) > 120) {
                return Json::error($response, 'validation_failed', 'Název účtu smí mít nejvýše 120 znaků.', 422);
            }
            $patch['label'] = $label === '' ? null : $label;
        }
        if (array_key_exists('analytic_suffix', $body)) {
            $suffix = trim((string) ($body['analytic_suffix'] ?? ''));
            if ($suffix === '') {
                // Prázdno neznamená „zrušit analytiku" — bankovní účet ji mít MUSÍ (jinak
                // se jeho pohyby vrátí na plochou syntetiku 221 a zůstatek přestane sedět
                // na výpis). Účet, který ještě žádnou nemá, tudy dostane první volnou.
                if (BankAnalyticAssigner::isValidSuffix($current['analytic_suffix'] ?? null)) {
                    return Json::error(
                        $response,
                        'validation_failed',
                        'Analytiku bankovního účtu nelze zrušit — lze ji jen změnit na jiné číslo.',
                        422,
                    );
                }
                $assigned = $this->analytics->nextFreeSuffix($supplierId);
                if ($assigned === null) {
                    return Json::error($response, 'validation_failed', 'Vyčerpány volné analytiky banky 221xxx.', 422);
                }
                $suffix = $assigned;
            }
            if (!BankAnalyticAssigner::isValidSuffix($suffix)) {
                return Json::error($response, 'validation_failed', 'Analytika musí obsahovat nejvýše 6 číslic.', 422);
            }
            $holder = $this->accounts->findBySuffix($supplierId, $suffix);
            if ($holder !== null && (int) $holder['id'] !== $id) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'Analytiku ' . BankAnalyticAssigner::codeFor($suffix) . ' už používá jiný bankovní účet.',
                    422,
                );
            }
            $patch['analytic_suffix'] = $suffix;
        }
        if (array_key_exists('is_active', $body)) {
            if (!is_bool($body['is_active'])) {
                return Json::error($response, 'validation_failed', 'is_active musí být boolean.', 422);
            }
            $patch['is_active'] = $body['is_active'];
        }
        $this->accounts->update($supplierId, $id, $patch);
        // Analytika musí v osnově existovat, jinak by na ni postDocument neuměl zaúčtovat.
        // Ruční přiřazení na už existující účet (např. termínovaný vklad na 221100) se
        // záměrně respektuje — je to mapování na hotovou analytiku, ne zakládání nové.
        if (isset($patch['analytic_suffix'])) {
            $this->analytics->ensureChartAccount(
                $supplierId,
                (string) $patch['analytic_suffix'],
                (string) ($patch['label'] ?? $current['label'] ?? ''),
            );
        }
        return Json::ok($response, $this->publicRow($this->accounts->find($supplierId, $id) ?? []));
    }

    private function publicRow(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'id', 'label', 'account_number', 'bank_code', 'iban', 'currency', 'kind',
            'analytic_suffix', 'is_active', 'source', 'currency_id',
        ]));
    }
}
