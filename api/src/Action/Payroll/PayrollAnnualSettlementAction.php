<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementConflictException;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementAnnualClaims;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementFilingObligation;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementPriorEmployers;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementRequest;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementRequestStatus;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementUnavailableException;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Roční zúčtování záloh a daňového zvýhodnění (§ 38ch ZDP).
 *
 * Čtyři operace: přehled roku, evidence žádosti, náhled výsledku a jeho
 * provedení. Náhled je oddělený od provedení schválně — provedení je právní
 * úkon plátce daně a nesmí se stát jen tím, že se někdo podívá.
 */
final class PayrollAnnualSettlementAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly AnnualTaxSettlementService $settlements,
        private readonly PayrollAnnualSettlementRepository $repository,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $error;
        }
        $year = self::year($args);
        if ($year === null) {
            return self::invalid($response);
        }
        $supplierId = $this->currentSupplierId($request);

        return Json::ok($response, [
            'tax_year' => $year,
            'request_deadline' =>
                AnnualSettlementStatute::requestDeadline($year)->format('Y-m-d'),
            'settlement_deadline' =>
                AnnualSettlementStatute::settlementDeadline($year)->format('Y-m-d'),
            'payout_period' =>
                AnnualSettlementStatute::payoutPeriodStart($year)->format('Y-m'),
            'payout_threshold_minor' =>
                AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
            'items' => $this->repository->listForYear($supplierId, $year),
        ]);
    }

    /** @param array<string,string> $args */
    public function preview(Request $request, Response $response, array $args): Response
    {
        if (!$this->guard($request, $response, AccessLevel::READ, $error)) {
            return $error;
        }
        $year = self::year($args);
        $employeeId = (int) ($args['employeeId'] ?? 0);
        if ($year === null || $employeeId <= 0) {
            return self::invalid($response);
        }
        try {
            $preview = $this->settlements->preview(
                $this->currentSupplierId($request),
                $employeeId,
                $year,
            );
        } catch (AnnualSettlementUnavailableException $exception) {
            return Json::error(
                $response,
                'annual_settlement_unavailable',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        return Json::ok($response, [
            'tax_year' => $year,
            'employee_id' => $employeeId,
            'request' => $preview['request'],
            'result' => $preview['result']->jsonSerialize(),
            'credit_rows' => $preview['credit_rows'],
            'child_rows' => $preview['child_rows'],
            'already_settled' => $preview['already_settled'],
        ]);
    }

    /** @param array<string,string> $args */
    public function saveRequest(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }
        if (!$this->guard($request, $response, AccessLevel::WRITE, $error)) {
            return $error;
        }
        $year = self::year($args);
        $employeeId = (int) ($args['employeeId'] ?? 0);
        if ($year === null || $employeeId <= 0) {
            return self::invalid($response);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $status = AnnualSettlementRequestStatus::tryFrom(
            (string) ($body['request_status'] ?? ''),
        );
        $prior = AnnualSettlementPriorEmployers::tryFrom(
            (string) ($body['prior_employers'] ?? ''),
        );
        $filing = AnnualSettlementFilingObligation::tryFrom(
            (string) ($body['filing_obligation'] ?? ''),
        );
        $claims = AnnualSettlementAnnualClaims::tryFrom(
            (string) ($body['annual_claims'] ?? ''),
        );
        if ($status === null || $prior === null || $filing === null || $claims === null) {
            return self::invalid($response);
        }

        // Sestavením domény se vynutí tytéž podmínky, jaké hlídají CHECK
        // constrainty — validace tak žije jednou, ne zvlášť v akci a v databázi.
        try {
            $candidate = new AnnualSettlementRequest(
                $year,
                $status,
                self::date($body['requested_on'] ?? null),
                self::text($body['request_evidence_reference'] ?? null),
                $prior,
                self::date($body['prior_documents_received_on'] ?? null),
                $filing,
                self::text($body['filing_obligation_reason'] ?? null),
                $claims,
                self::text($body['annual_claims_note'] ?? null),
                self::text($body['note'] ?? null),
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        }

        $expectedRowVersion = isset($body['row_version'])
            ? (int) $body['row_version']
            : null;
        try {
            $saved = $this->repository->saveRequest(
                $this->currentSupplierId($request),
                $employeeId,
                $year,
                [
                    'request_status' => $candidate->status->value,
                    'requested_on' => $candidate->requestedOn?->format('Y-m-d'),
                    'request_evidence_reference' => $candidate->requestEvidenceReference,
                    'prior_employers' => $candidate->priorEmployers->value,
                    'prior_documents_received_on' =>
                        $candidate->priorDocumentsReceivedOn?->format('Y-m-d'),
                    'filing_obligation' => $candidate->filingObligation->value,
                    'filing_obligation_reason' => $candidate->filingObligationReason,
                    'annual_claims' => $candidate->annualClaims->value,
                    'annual_claims_note' => $candidate->annualClaimsNote,
                    'note' => $candidate->note,
                ],
                $expectedRowVersion,
                $this->userId($request),
            );
        } catch (PayrollAnnualSettlementConflictException $exception) {
            return Json::error(
                $response,
                'row_version_conflict',
                $exception->getMessage(),
                409,
            );
        }

        return Json::ok($response, ['request' => $saved]);
    }

    /** @param array<string,string> $args */
    public function settle(Request $request, Response $response, array $args): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.approve',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled(
            $request,
            $response,
            $this->moduleAccess,
            $error,
        )) {
            return $error ?? Json::error(
                $response,
                'forbidden',
                'Pro tuto akci nemáš oprávnění.',
                403,
            );
        }
        $year = self::year($args);
        $employeeId = (int) ($args['employeeId'] ?? 0);
        $userId = $this->userId($request);
        if ($year === null || $employeeId <= 0 || $userId === null) {
            return self::invalid($response);
        }
        $supplierId = $this->currentSupplierId($request);

        try {
            $settled = $this->settlements->settle(
                $supplierId,
                $employeeId,
                $year,
                $userId,
            );
        } catch (AnnualSettlementUnavailableException $exception) {
            return Json::error(
                $response,
                'annual_settlement_unavailable',
                $exception->getMessage(),
                422,
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return Json::error(
                $response,
                'validation_failed',
                $exception->getMessage(),
                422,
            );
        } catch (\Throwable) {
            return Json::error(
                $response,
                'annual_settlement_failed',
                'Roční zúčtování se nepodařilo provést. Zkontrolujte schválené '
                . 'mzdy za celý rok a zákonnou evidenci zaměstnance.',
                409,
            );
        }

        // Odmítnutí NENÍ chyba serveru: je to řádná odpověď na otázku, jestli
        // zúčtování provést lze. Vrací se 200 se seznamem překážek, aby je UI
        // uměl vypsat všechny najednou.
        if (!$settled['result']->performed) {
            return Json::ok($response, [
                'tax_year' => $year,
                'employee_id' => $employeeId,
                'performed' => false,
                'result' => $settled['result']->jsonSerialize(),
                'already_settled' => $settled['outcome'],
            ]);
        }

        if ($settled['created']) {
            $this->activity->log(
                'payroll.annual_settlement_performed',
                $userId,
                'payroll_employee',
                $employeeId,
                [
                    'tax_year' => $year,
                    'outcome' => $settled['result']->outcome?->value,
                    'settlement_difference_minor' =>
                        $settled['result']->settlementDifferenceMinorUnits,
                    'payable_minor' => $settled['result']->payableMinorUnits,
                ],
                $this->ipMatcher->clientIpFromRequest(self::serverParams($request)),
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }

        return Json::ok($response, [
            'tax_year' => $year,
            'employee_id' => $employeeId,
            'performed' => true,
            'created' => $settled['created'],
            'result' => $settled['result']->jsonSerialize(),
            'outcome' => $settled['outcome'],
            'document' => self::publicDocument($settled['document'] ?? []),
        ], $settled['created'] ? 201 : 200);
    }

    private function guard(
        Request $request,
        Response $response,
        AccessLevel $level,
        ?Response &$error,
    ): bool {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            $level,
            $error,
        ) || !$this->requirePayrollEnabled(
            $request,
            $response,
            $this->moduleAccess,
            $error,
        )) {
            $error ??= Json::error(
                $response,
                'forbidden',
                'Pro tuto akci nemáš oprávnění.',
                403,
            );

            return false;
        }

        return true;
    }

    /** @param array<string,string> $args */
    private static function year(array $args): ?int
    {
        $year = (int) ($args['year'] ?? 0);

        return $year >= 2000 && $year <= 2199 ? $year : null;
    }

    private static function invalid(Response $response): Response
    {
        return Json::error(
            $response,
            'validation_failed',
            'Požadavek na roční zúčtování není platný.',
            422,
        );
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date === false || $date->format('Y-m-d') !== trim($value)
            ? null
            : $date;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return array<string,mixed> */
    private static function serverParams(Request $request): array
    {
        $result = [];
        foreach ($request->getServerParams() as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private static function publicDocument(array $document): array
    {
        $keys = [
            'id', 'annual_revision_id', 'annual_revision_no', 'tax_year', 'purpose',
            'employee_id', 'employee_name', 'document_kind', 'document_revision_no',
            'file_sha256', 'size_bytes', 'mime_type', 'suggested_filename', 'created_at',
        ];
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $document)) {
                $result[$key] = $document[$key];
            }
        }

        return $result;
    }
}
