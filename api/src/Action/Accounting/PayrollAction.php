<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Payroll\PayrollCalculator;
use MyInvoice\Service\Accounting\Payroll\PayrollPostingService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Mzdová rekapitulace (Fáze F) — spočítá rozpad hrubé mzdy a zaúčtuje ho.
 * Dosud se účtovala ručně řádek po řádku.
 *
 *   POST /api/accounting/payroll/preview  — náhled rozpadu (bez zápisu) — readonly+
 *   POST /api/accounting/payroll/post     — zaúčtovat (idempotentní na RRRRMM) — účetní|admin,
 *                                            volitelně s `employee_id` (mzdový list, §38j)
 *
 * Výpočet drží {@see PayrollCalculator}, zaúčtování {@see PayrollPostingService}
 * nad PostingService — Action jen validuje vstup a mapuje chyby.
 *
 * `$ipMatcher` je nutný — {@see AccountingActionSupport::auditMeta()}, kterou `post()`
 * volá, na něj sahá; bez závislosti v konstruktoru by zaúčtování mzdy fatálně spadlo.
 */
final class PayrollAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    /** Nad tuhle hrubou mzdu už rozpad není důvěryhodný — viz validate(). */
    private const MAX_GROSS = 10_000_000.0;

    public function __construct(
        private readonly PayrollPostingService $payroll,
        private readonly Connection $db,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** POST /api/accounting/payroll/preview */
    public function preview(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $input = $this->validate($request, $response, $err);
        if ($input === null) return $err;

        // Tenant kontrolu i existenci zaměstnance řeší parseEmployeeId stejně jako u post().
        $employeeId = $this->parseEmployeeId($request, $supplierId, $response, $err);
        if ($err !== null) return $err;

        try {
            $preview = $this->payroll->preview(
                $input['year'],
                $input['month'],
                $input['gross'],
                $input['taxpayer_type'],
                $input['taxpayer_credit'],
                $input['child_count'],
                null,
                // Se zadaným zaměstnancem musí náhled číst kartu STEJNĚ jako zaúčtování.
                // Dřív byl `employee_id` v náhledu tichý no-op a slevy kopíroval až
                // frontend — server-side náhled se tak mohl se zaúčtováním rozejít.
                $supplierId,
                $employeeId,
            );
        } catch (\OutOfRangeException | \InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $preview);
    }

    /** POST /api/accounting/payroll/post */
    public function post(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $input = $this->validate($request, $response, $err);
        if ($input === null) return $err;

        $employeeId = $this->parseEmployeeId($request, $supplierId, $response, $err);
        if ($err !== null) return $err;

        try {
            $result = $this->payroll->post(
                $supplierId,
                $input['year'],
                $input['month'],
                $input['gross'],
                $input['taxpayer_type'],
                $this->auditMeta($request),
                $employeeId,
                $input['taxpayer_credit'],
                $input['child_count'],
            );
        } catch (\OutOfRangeException | \InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->mapPostingError($response, $e);
        }

        return Json::ok($response, $result);
    }

    /**
     * Volitelná vazba na zaměstnance (mzdový list, §38j) — když je zadaná, musí patřit
     * tenantovi a být aktivní. Neúčtuje se jinak, jen se navíc uloží snapshot pro
     * {@see \MyInvoice\Service\Accounting\Payroll\PayrollSheetService}.
     */
    private function parseEmployeeId(Request $request, int $supplierId, Response $response, ?Response &$err): ?int
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $raw = $body['employee_id'] ?? null;
        if ($raw === null || $raw === '') {
            $err = null;
            return null;
        }
        $id = (int) $raw;
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_employees WHERE id = ? AND supplier_id = ? AND is_active = 1'
        );
        $stmt->execute([$id, $supplierId]);
        if ($stmt->fetchColumn() === false) {
            $err = Json::error($response, 'validation_failed', 'Zaměstnanec nenalezen nebo není aktivní.', 422);
            return null;
        }
        $err = null;
        return $id;
    }

    /** Víc dětí než tohle je zjevný překlep, ne rodina — viz validate(). */
    private const MAX_CHILDREN = 20;

    /**
     * @return array{year:int,month:int,gross:float,taxpayer_type:string,taxpayer_credit:bool,child_count:int}|null
     */
    private function validate(Request $request, Response $response, ?Response &$err): ?array
    {
        $body  = (array) ($request->getParsedBody() ?? []);
        $year  = (int) ($body['year'] ?? 0);
        $month = (int) ($body['month'] ?? 0);

        if ($month < 1 || $month > 12) {
            $err = Json::error($response, 'validation_failed', 'Měsíc musí být 1–12.', 422);
            return null;
        }
        if ($year < 2018 || $year > 2100) {
            $err = Json::error($response, 'validation_failed', 'Neplatný rok.', 422);
            return null;
        }
        if (!isset($body['gross']) || !is_numeric($body['gross'])) {
            $err = Json::error($response, 'validation_failed', 'Hrubá mzda musí být číslo.', 422);
            return null;
        }
        $gross = (float) $body['gross'];
        if ($gross < 0 || $gross > self::MAX_GROSS) {
            $err = Json::error($response, 'validation_failed', 'Hrubá mzda je mimo rozsah.', 422);
            return null;
        }

        $type = (string) ($body['taxpayer_type'] ?? PayrollCalculator::TYPE_EMPLOYEE);
        if (!in_array($type, PayrollCalculator::types(), true)) {
            $err = Json::error($response, 'validation_failed', 'Neznámý typ poplatníka.', 422);
            return null;
        }

        // Chybí-li údaj, sleva se NEUPLATNÍ (§ 38h odst. 4, § 38k odst. 4): zálohu lze
        // snížit o měsíční slevu jen u plátce, u kterého je prohlášení podepsané, a to
        // systém z ničeho neodvodí. Dřív tu byl opačný default a rekapitulace slevu
        // uplatnila i tam, kde na ni nárok nebyl — srazilo se míň a za nesraženou
        // zálohu ručí plátce (§ 38s). Přeplatek se naproti tomu vrátí v ročním zúčtování,
        // takže bezpečný směr je nesrazit méně, ale více.
        // Při zadaném `employee_id` tohle stejně přebije karta zaměstnance.
        $taxpayerCredit = isset($body['taxpayer_credit'])
            && filter_var($body['taxpayer_credit'], FILTER_VALIDATE_BOOLEAN);

        $childCount = (int) ($body['child_count'] ?? 0);
        if ($childCount < 0 || $childCount > self::MAX_CHILDREN) {
            $err = Json::error($response, 'validation_failed', 'Počet dětí je mimo rozsah.', 422);
            return null;
        }

        return [
            'year'            => $year,
            'month'           => $month,
            'gross'           => $gross,
            'taxpayer_type'   => $type,
            'taxpayer_credit' => $taxpayerCredit,
            'child_count'     => $childCount,
        ];
    }
}
