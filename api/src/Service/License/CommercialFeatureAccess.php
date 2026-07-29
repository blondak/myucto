<?php

declare(strict_types=1);

namespace MyInvoice\Service\License;

final class CommercialFeatureAccess
{
    /** @var list<string> */
    private const RESTRICTED_API_PATTERNS = [
        '#^/api/(stock|eshop)(/|$)#',
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
