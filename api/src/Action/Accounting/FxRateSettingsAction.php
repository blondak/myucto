<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use DateTimeImmutable;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\FixedExchangeRateRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Nastavení kurzového režimu firmy (§24 odst. 7 ZoÚ — Fáze F).
 *
 *   GET    /api/accounting/fx-rate-settings                 — režim + pevné kurzy
 *   PUT    /api/accounting/fx-rate-settings                 — přepnutí režimu (účetní|admin)
 *   PUT    /api/accounting/fx-rate-settings/rates           — upsert pevného kurzu
 *   DELETE /api/accounting/fx-rate-settings/rates/{id}      — smazání pevného kurzu
 *   GET    /api/accounting/fx-rate-settings/cnb-prefill     — návrh kurzu z ČNB (1. den období)
 *
 * Přepnutí režimu platí jen do budoucna — už zaúčtované doklady si drží kurz
 * na hlavičce, mění se jen nově ukládané doklady (ExchangeRateApplier).
 */
final class FxRateSettingsAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MODES = ['daily', 'fixed_monthly', 'fixed_annual'];

    public function __construct(
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly FixedExchangeRateRepository $rates,
        private readonly CnbExchangeRateClient $cnb,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $year = (int) ($request->getQueryParams()['fiscal_year'] ?? 0);
        return Json::ok($response, [
            'mode'  => $this->settings->getFxRateMode($supplierId),
            'rates' => $this->rates->list($supplierId, $year > 0 ? $year : null),
        ]);
    }

    public function updateMode(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($body['mode'] ?? '');
        if (!in_array($mode, self::MODES, true)) {
            return Json::error($response, 'validation_failed', "mode musí být 'daily', 'fixed_monthly' nebo 'fixed_annual'.", 422);
        }

        $before = $this->settings->getFxRateMode($supplierId);
        $this->settings->setFxRateMode($supplierId, $mode);

        $this->activity->log(
            'accounting.fx_rate_mode_changed',
            $this->userId($request),
            'supplier',
            $supplierId,
            ['before' => $before, 'after' => $mode],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['mode' => $mode]);
    }

    public function upsertRate(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $parsed = $this->validateRateInput($body, $response, $err);
        if ($parsed === null) return $err;

        $this->rates->upsert(
            $supplierId,
            $parsed['currency'],
            $parsed['fiscal_year'],
            $parsed['month'],
            $parsed['rate'],
            'manual',
        );

        return Json::ok($response, ['rates' => $this->rates->list($supplierId, $parsed['fiscal_year'])]);
    }

    public function deleteRate(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $id = (int) ($args['id'] ?? 0);
        if (!$this->rates->delete($supplierId, $id)) {
            return Json::error($response, 'not_found', 'Pevný kurz nenalezen.', 404);
        }
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * Návrh pevného kurzu z ČNB — kurz k 1. dni období (měsíce/roku). Slouží jako
     * výchozí hodnota ve formuláři; uživatel ho může přepsat.
     */
    public function cnbPrefill(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $q = $request->getQueryParams();
        $currency = strtoupper(trim((string) ($q['currency'] ?? '')));
        $year  = (int) ($q['fiscal_year'] ?? 0);
        $month = (int) ($q['month'] ?? 0);
        if (!preg_match('/^[A-Z]{3}$/', $currency) || $currency === 'CZK') {
            return Json::error($response, 'validation_failed', 'currency musí být ISO kód měny (např. EUR).', 422);
        }
        if ($year < 2000 || $year > 2100) {
            return Json::error($response, 'validation_failed', 'fiscal_year je mimo rozsah.', 422);
        }
        if ($month < 0 || $month > 12) {
            return Json::error($response, 'validation_failed', 'month musí být 0 (roční) nebo 1..12.', 422);
        }

        $firstDay = $month >= 1
            ? sprintf('%04d-%02d-01', $year, $month)
            : sprintf('%04d-01-01', $year);
        $result = $this->cnb->getRate($currency, new DateTimeImmutable($firstDay));
        if ($result === null) {
            return Json::error($response, 'rate_unavailable', 'ČNB kurz pro ' . $currency . ' k ' . $firstDay . ' není dostupný.', 404);
        }

        return Json::ok($response, [
            'currency'      => $currency,
            'rate'          => (float) $result['rate'],
            'rate_date'     => (string) $result['rate_date'],
            'fallback_used' => (bool) $result['fallback_used'],
        ]);
    }

    /**
     * @param array<string,mixed> $body
     * @return array{currency:string, fiscal_year:int, month:int, rate:float}|null
     */
    private function validateRateInput(array $body, Response $response, ?Response &$err): ?array
    {
        $currency = strtoupper(trim((string) ($body['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency) || $currency === 'CZK') {
            $err = Json::error($response, 'validation_failed', 'currency musí být ISO kód měny (např. EUR).', 422);
            return null;
        }
        $year = (int) ($body['fiscal_year'] ?? 0);
        if ($year < 2000 || $year > 2100) {
            $err = Json::error($response, 'validation_failed', 'fiscal_year je mimo rozsah.', 422);
            return null;
        }
        $month = (int) ($body['month'] ?? 0);
        if ($month < 0 || $month > 12) {
            $err = Json::error($response, 'validation_failed', 'month musí být 0 (roční) nebo 1..12.', 422);
            return null;
        }
        $rate = round((float) ($body['rate'] ?? 0), 6);
        if ($rate <= 0 || $rate > 9999999.999999) {
            $err = Json::error($response, 'validation_failed', 'rate musí být kladné číslo.', 422);
            return null;
        }
        $err = null;
        return ['currency' => $currency, 'fiscal_year' => $year, 'month' => $month, 'rate' => $rate];
    }
}
