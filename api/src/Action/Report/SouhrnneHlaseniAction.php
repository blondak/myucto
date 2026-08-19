<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\SouhrnneHlaseniBuilder;
use MyInvoice\Service\Report\TaxSubmissionFilename;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Souhrnné hlášení DPHSHV1 endpoints:
 *   GET /api/reports/dphshv/preview?year=2026&month=5[&period=quarterly] → JSON summary
 *   GET /api/reports/dphshv?year=2026&month=5[&period=quarterly]         → XML download
 *
 * Periodicita závisí na typu plnění (§ 102 odst. 3–4 zákona 235/2004 Sb.):
 *   - dodání zboží (kód 20) → vždy měsíčně
 *   - poskytnutí služby (kód 22) → ve lhůtě přiznání k DPH (lze kvartálně)
 */
final class SouhrnneHlaseniAction
{
    public function __construct(
        private readonly SouhrnneHlaseniBuilder $builder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Report\TaxSubmissionArchiver $archiver,
        private readonly \MyInvoice\Service\Currency\MissingExchangeRateFiller $rateFiller,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $month, $period] = $this->parsePeriod($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
        return Json::ok($response, ['summary' => $result['summary'], 'warnings' => $result['warnings']]);
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $month, $period] = $this->parsePeriod($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period);
            // #238: chybí-li u cizoměnných dokladů kurz, doplň z ČNB (oficiální kurz k DUZP)
            // a přebuildi. Fill JE zápis do účetního stavu → v souladu s B8/HIGH#1 (readonly
            // download nemutuje) ho spustíme jen když má uživatel WRITE — stejná brána jako
            // posun zámku v archiveru. READ-only nebo když ČNB kurz nezná → tvrdá chyba 422.
            if (!empty($result['missing_rates'])) {
                if (RequestAuthorization::allows($request, 'reports.export', AccessLevel::WRITE)) {
                    $this->rateFiller->fill($supplierId, $result['missing_rates']);
                    $result = $this->builder->build($supplierId, $year, $month, $period);
                }
                if (!empty($result['missing_rates'])) {
                    $labels = \MyInvoice\Service\Report\VatLedgerService::missingExchangeRateLabels($result['missing_rates']);
                    return Json::error($response, 'exchange_rate_missing',
                        'Nelze vytvořit XML: ČNB nemá kurz pro doklady ' . implode(', ', $labels)
                        . '. Doplňte kurz ručně u faktury a zkuste znovu.', 422);
                }
            }
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $isQuarterly = $period === 'quarterly';
        $quarter = $isQuarterly ? (int) ceil($month / 3) : null;
        $archived = $this->archiver->archive(
            $supplierId, 'dphshv', $year, $isQuarterly ? null : $month, $quarter,
            $result['xml'], $result['summary'], $userId ?: null,
            // readonly GET/download nesmí mutovat účetní stav — dorevize B8, HIGH#1.
            // (dphshv navíc zámek neposouvá vůbec — viz TaxSubmissionArchiver::VAT_LOCK_FORMS.)
            RequestAuthorization::allows($request, 'reports.export', AccessLevel::WRITE),
            // Souhrnné hlášení má vlastní kódovou sadu — EPO u `shvies_forma`
            // povoluje jen [RN]. Výchozí 'B' archivéru je kód z přiznání k DPH
            // a u tohohle formuláře neexistuje; snapshot by tvrdil jiný druh
            // podání, než jaký nese odeslané XML.
            'R',
        );
        $periodLabel = $isQuarterly ? sprintf('%04d-Q%d', $year, $quarter) : sprintf('%04d-%02d', $year, $month);
        $this->logger->log('report.dphshv_downloaded', $userId, null, null, [
            'period' => $periodLabel,
            'rows'   => $result['summary']['rows_count'] ?? 0,
            'submission_id' => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = TaxSubmissionFilename::forSnapshot([
            'id' => $archived['submission_id'],
            'form_code' => 'dphshv',
            'period_year' => $year,
            'period_month' => $isQuarterly ? null : $month,
            'period_quarter' => $quarter,
        ], 'xml');
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array{0:int|null, 1:int|null, 2:string}
     */
    private function parsePeriod(Request $request): array
    {
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        $period = $q['period'] ?? 'monthly';
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            return [null, null, 'monthly'];
        }
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return [null, null, 'monthly'];
        }
        return [$year, $month, $period];
    }
}
