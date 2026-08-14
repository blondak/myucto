<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

/**
 * Hranice mezi bezplatným základem a komerčními moduly MyÚčta.
 *
 * Zdarma zůstává to, co uživatel dostal už v MyInvoice (MIT): fakturace,
 * klienti, dokumenty, banka a pokladna, přiznání k DPH i kontrolní hlášení
 * a celé nastavení firmy. Za licencí jsou čtyři nadstavbové moduly —
 * ÚČETNICTVÍ, MZDY, SKLAD (a e-shop) a OSS — plus věci, které se o ně opírají
 * (zaúčtování dokladů, automatizace, přehled firem, korekce odpočtu).
 *
 * Prvních 60 dní běží instalace v trialu, kde je dostupné všechno; po jeho
 * vypršení (a stejně tak u degradované licence) se tyhle cesty uzavřou.
 */
final class CommercialFeatureAccess
{
    /** @var list<string> */
    private const RESTRICTED_API_PATTERNS = [
        '#^/api/(stock|eshop)(/|$)#',
        // Mzdy jsou komerční modul celé, včetně capabilities a nastavení —
        // frontend si podle 403 schová sekci stejně jako u skladu.
        '#^/api/payroll(/|$)#',
        // OSS (One Stop Shop) — evidence, přiznání i hromadné zařazení faktur.
        // Číselník sazeb členských států zůstává volný: je to jen data.
        '#^/api/reports/oss(/|$)#',
        '#^/api/invoices/bulk-oss(/|$)#',
        // Daňová evidence je druhá tvář téhož modulu „Vést účetnictví" —
        // peněžní deník a přehled pohledávek/závazků stojí a padají s ním.
        '#^/api/tax-evidence(/|$)#',
        '#^/api/invoices/[0-9]+/stock-documents(/|$)#',
        '#^/api/invoices/[0-9]+/book$#',
        '#^/api/purchase-invoices/[0-9]+/stock-receipts?(/|$)#',
        '#^/api/purchase-invoices/[0-9]+/ai-suggest$#',
        '#^/api/bank-transactions/[0-9]+/(post|unpost|ai-suggest)$#',
        '#^/api/bank-ai-suggestion-availability$#',
        '#^/api/accounting(?:$|/(?!cash-(?:documents|registers)(?:/|$)|bank-accounts(?:/|$)))#',
        '#^/api/(automation|portfolio)(/|$)#',
        '#^/api/ai/suggestions(/|$)#',
        '#^/api/purchase-ai-suggestion-availability$#',
        '#^/api/settings/accounting-activation(/|$)#',
        '#^/api/reports/(s74b|s43|s46|s79|related-parties|closing-package|submissions)(/|$)#',
    ];

    public function __construct(private readonly LicenseService $license) {}

    public function isAvailable(): bool
    {
        try {
            return $this->license->current()->hasCommercialFeatures();
        } catch (\Throwable) {
            return true;
        }
    }

    public static function restrictsApiPath(string $path): bool
    {
        foreach (self::RESTRICTED_API_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }
}
