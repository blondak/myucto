<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\Payroll\PayrollBenefitBasketOverviewRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Payroll\Component\PayrollBenefitBasketOverviewService;
use MyInvoice\Service\Payroll\Component\PayrollBenefitBasketUsage;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přehled čerpání ročních košů osvobození za firmu.
 *
 * Oprávnění je `payroll` na úrovni READ — tedy stejné, jaké má seznam mzdových
 * vstupů. Přehled totiž nezpřístupňuje žádnou novou třídu údajů: je to součet
 * týchž benefitních vstupů, které ta obrazovka ukazuje po jednom, jen za rok
 * a za osobu. Samostatné právo by seznam po řádcích nechalo otevřený a zamkl by
 * se jen jeho součet — to není ochrana, jen zdání ochrany. Citlivé identifikátory
 * (rodné číslo, adresa, účet) v odpovědi nejsou, jméno osoby jím podle
 * § 58.17 manuálu není.
 *
 * Endpoint je jako celý mzdový modul dostupný jen z přihlášené webové relace;
 * bearer token dostane 403.
 */
final class PayrollBenefitBasketOverviewAction
{
    use PayrollActionSupport;

    /** Rozsah roku odpovídá `chk_payroll_benefit_year` v databázi. */
    private const YEAR_MIN = 2000;

    private const YEAR_MAX = 2200;

    public function __construct(
        private readonly PayrollBenefitBasketOverviewService $service,
        private readonly PayrollModuleAccess $access,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->authorize($request, $response)) !== null) {
            return $error;
        }

        $query = $request->getQueryParams();
        try {
            $year = $this->year($query['year'] ?? null);
            $basket = $this->basket($query['basket'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $search = $query['q'] ?? '';
        if (!is_string($search)) {
            $search = '';
        }

        // Strop je tvrdý, ne jen výchozí — parametrem z URL ho zvednout nejde.
        $limit = max(1, min(
            PayrollBenefitBasketOverviewRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollBenefitBasketOverviewRepository::LIST_DEFAULT_LIMIT),
        ));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $page = $this->service->overview(
            $this->currentSupplierId($request),
            $year,
            $basket,
            $search,
            $limit,
            $offset,
        );

        return Json::ok($response, [
            'items' => array_map(
                static fn (PayrollBenefitBasketUsage $usage): array => $usage->jsonSerialize(),
                $page['items'],
            ),
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
            'year' => $year,
            'years' => $page['years'],
        ]);
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
            'payroll',
            AccessLevel::READ,
            $error,
        )) {
            return $error;
        }
        if (!$this->requirePayrollEnabled($request, $response, $this->access, $error)) {
            return $error;
        }

        return null;
    }

    /**
     * Rok se nedoplňuje aktuálním, když je zadaný nesmysl — filtr, který tiše
     * ukáže jiný rok, než o jaký uživatel požádal, je horší než chyba.
     */
    private function year(mixed $value): int
    {
        if ($value === null || $value === '') {
            return (int) date('Y');
        }
        if (!is_string($value) || preg_match('/^[0-9]{4}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Parametr year musí být rok ve tvaru RRRR.');
        }
        $year = (int) $value;
        if ($year < self::YEAR_MIN || $year > self::YEAR_MAX) {
            throw new \InvalidArgumentException('Parametr year je mimo podporovaný rozsah.');
        }

        return $year;
    }

    private function basket(mixed $value): ?PayrollBenefitExemptionBasket
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Parametr basket musí být kód koše.');
        }

        return PayrollBenefitExemptionBasket::tryFrom($value)
            ?? throw new \InvalidArgumentException('Neznámý koš osvobození.');
    }
}
