<?php

declare(strict_types=1);

namespace MyInvoice\Security;

final class RoutePermissionMap
{
    public const PUBLIC = 'public';
    public const SELF_SERVICE = 'self_service';
    public const SUPERADMIN = 'superadmin';
    public const PERMISSION = 'permission';

    /** @var list<string> */
    private const PUBLIC_PATHS = [
        '/api/health', '/api/version', '/api/openapi.yaml', '/api/docs', '/api/reference', '/api/scalar',
        '/api/auth/setup-status', '/api/auth/setup', '/api/auth/setup-ares-lookup',
        '/api/auth/setup-crpdph-lookup', '/api/auth/login',
        '/api/auth/webauthn/login/options', '/api/auth/webauthn/login/verify',
        '/api/auth/forgot', '/api/auth/reset', '/api/csrf-token',
    ];

    /** @var list<string> */
    private const SELF_PATHS = [
        '/api/auth/logout', '/api/auth/me', '/api/auth/api-me', '/api/auth/change-password',
        '/api/auth/totp/status', '/api/auth/totp/setup', '/api/auth/totp/enable',
        '/api/auth/webauthn/credentials',
        '/api/auth/webauthn/register/options', '/api/auth/webauthn/register/verify',
        '/api/auth/webauthn/step-up/options', '/api/auth/webauthn/step-up/verify',
        '/api/auth/mfa/step-up/totp', '/api/auth/mfa/step-up/recovery',
        // Vlastní záložní kódy spravuje jen jejich majitel — generování si navíc
        // vynucuje čerstvý step-up skutečným faktorem (MfaRecoveryCodeAction).
        '/api/auth/mfa/recovery-codes',
        '/api/auth/session/status', '/api/auth/session/activity', '/api/auth/session/lock',
        '/api/auth/session/lock-preference',
        '/api/auth/session/unlock/options', '/api/auth/session/unlock/verify',
    ];

    /**
     * Specific rules precede module fallbacks.
     * @var list<array{0:string,1:string,2:string,3:AccessLevel}>
     */
    private const RULES = [
        ['GET', '#^/api/auth/tokens(/|$)#', 'profile.tokens', AccessLevel::READ],
        ['*', '#^/api/auth/tokens(/|$)#', 'profile.tokens', AccessLevel::WRITE],
        // Log volání API vlastními tokeny — čtení sdílí oprávnění se správou tokenů.
        ['GET', '#^/api/auth/api-log$#', 'profile.tokens', AccessLevel::READ],

        ['GET', '#^/api/invoices/[0-9]+/stock-documents(/|$)#', 'stock', AccessLevel::READ],
        ['*', '#^/api/invoices/[0-9]+/stock-documents(/|$)#', 'stock', AccessLevel::WRITE],
        ['*', '#^/api/invoices/[0-9]+/book$#', 'accounting.journal.post', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/[0-9]+/issue(-final)?$#', 'invoices.issue', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/[0-9]+/(send|send-test)$#', 'invoices.send', AccessLevel::WRITE],
        ['POST', '#^/api/invoices(/[0-9]+)?/(reminder|reminder-test)$#', 'invoices.reminder', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/[0-9]+/(mark-paid|unmark-paid|payments)(/|$)#', 'invoices.mark_paid', AccessLevel::WRITE],
        ['DELETE', '#^/api/invoices/[0-9]+/payments(/|$)#', 'invoices.mark_paid', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/[0-9]+/cancel$#', 'invoices.cancel', AccessLevel::WRITE],
        // Obnova snapshotů stran u vystaveného dokladu — sdílí oprávnění s editací
        // faktury; navíc je v akci tvrdý admin-only check (superadmin).
        ['POST', '#^/api/invoices/[0-9]+/rebuild-snapshots$#', 'invoices.create', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/[0-9]+/clone$#', 'invoices.clone', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/bulk-reminder$#', 'invoices.reminder', AccessLevel::WRITE],
        ['POST', '#^/api/invoices/bulk-reissue$#', 'invoices.clone', AccessLevel::WRITE],
        ['DELETE', '#^/api/invoices/[0-9]+$#', 'invoices.delete', AccessLevel::WRITE],
        ['*', '#^/api/invoices/[0-9]+/(request-approval|request-approval-test|approval-status)$#', 'invoices.approval', AccessLevel::WRITE],
        ['POST', '#^/api/invoices$#', 'invoices.create', AccessLevel::WRITE],
        ['GET', '#^/api/invoices(/|$)#', 'invoices', AccessLevel::READ],
        ['*', '#^/api/invoices(/|$)#', 'invoices', AccessLevel::WRITE],

        // Čtení fallbackového ceníku používá editor faktur. Správa je níže v match()
        // vyhrazena superadminovi a API samo navíc odmítne firmy s aktivním skladem.
        ['GET', '#^/api/price-list-items(/|$)#', 'invoices', AccessLevel::READ],

        ['GET', '#^/api/purchase-invoices/payment-orders(/|$)#', 'purchase_invoices.payment_orders', AccessLevel::READ],
        ['*', '#^/api/purchase-invoices/payment-orders(/|$)#', 'purchase_invoices.payment_orders', AccessLevel::WRITE],
        ['POST', '#^/api/purchase-invoices/scan-inbox$#', 'purchase_invoices.scan', AccessLevel::WRITE],
        ['GET', '#^/api/purchase-invoices/[0-9]+/documents(/|$)#', 'documents', AccessLevel::READ],
        ['*', '#^/api/purchase-invoices/[0-9]+/documents(/|$)#', 'documents', AccessLevel::WRITE],
        ['GET', '#^/api/purchase-invoices/[0-9]+/stock-receipts?(/|$)#', 'stock', AccessLevel::READ],
        ['*', '#^/api/purchase-invoices/[0-9]+/stock-receipts?(/|$)#', 'stock', AccessLevel::WRITE],
        ['POST', '#^/api/purchase-invoices/[0-9]+/transition$#', 'purchase_invoices.transition', AccessLevel::WRITE],
        ['DELETE', '#^/api/purchase-invoices/[0-9]+/(link-advance|advance-suggestion|pdf)$#', 'purchase_invoices', AccessLevel::WRITE],
        ['DELETE', '#^/api/purchase-invoices/[0-9]+(/|$)#', 'purchase_invoices.delete', AccessLevel::WRITE],
        ['POST', '#^/api/purchase-invoices$#', 'purchase_invoices.create', AccessLevel::WRITE],
        ['GET', '#^/api/purchase-invoices(/|$)#', 'purchase_invoices', AccessLevel::READ],
        ['*', '#^/api/purchase-invoices(/|$)#', 'purchase_invoices', AccessLevel::WRITE],

        ['POST', '#^/api/recurring$#', 'recurring.create', AccessLevel::WRITE],
        ['POST', '#^/api/recurring/[0-9]+/(run|run-now|generate)$#', 'recurring.run', AccessLevel::WRITE],
        ['POST', '#^/api/recurring/[0-9]+/(pause|resume)$#', 'recurring.pause', AccessLevel::WRITE],
        ['DELETE', '#^/api/recurring/[0-9]+$#', 'recurring.delete', AccessLevel::WRITE],
        ['GET', '#^/api/recurring(/|$)#', 'recurring', AccessLevel::READ],
        ['*', '#^/api/recurring(/|$)#', 'recurring', AccessLevel::WRITE],

        ['POST', '#^/api/clients$#', 'clients.create', AccessLevel::WRITE],
        ['GET', '#^/api/clients/[0-9]+/projects$#', 'projects', AccessLevel::READ],
        ['DELETE', '#^/api/clients/[0-9]+$#', 'clients.archive', AccessLevel::WRITE],
        ['*', '#^/api/clients/[0-9]+/(archive|unarchive|restore)$#', 'clients.archive', AccessLevel::WRITE],
        ['GET', '#^/api/clients/[0-9]+/work-report-link(/|$)#', 'clients.public_links', AccessLevel::READ],
        ['*', '#^/api/clients/[0-9]+/work-report-link(/|$)#', 'clients.public_links', AccessLevel::WRITE],
        ['GET', '#^/api/clients(/|$)#', 'clients', AccessLevel::READ],
        ['*', '#^/api/clients(/|$)#', 'clients', AccessLevel::WRITE],
        ['POST', '#^/api/projects$#', 'projects.create', AccessLevel::WRITE],
        ['DELETE', '#^/api/projects/[0-9]+$#', 'projects.archive', AccessLevel::WRITE],
        ['*', '#^/api/projects/[0-9]+/(archive|restore)$#', 'projects.archive', AccessLevel::WRITE],
        ['GET', '#^/api/projects(/|$)#', 'projects', AccessLevel::READ],
        ['*', '#^/api/projects(/|$)#', 'projects', AccessLevel::WRITE],

        ['*', '#^/api/bank-transactions/[0-9]+/match(/|$)#', 'bank.match', AccessLevel::WRITE],
        ['*', '#^/api/bank-match-suggestions/[0-9]+/(accept|reject)$#', 'bank.match', AccessLevel::WRITE],
        ['POST', '#^/api/bank-transactions/[0-9]+/post$#', 'bank.post', AccessLevel::WRITE],
        ['POST', '#^/api/bank-transactions/[0-9]+/ai-suggest$#', 'bank.post', AccessLevel::WRITE],
        ['GET', '#^/api/bank-ai-suggestion-availability$#', 'bank.post', AccessLevel::WRITE],
        ['GET', '#^/api/purchase-ai-suggestion-availability$#', 'accounting', AccessLevel::WRITE],
        ['POST', '#^/api/bank-transactions/[0-9]+/unpost$#', 'bank.unpost', AccessLevel::WRITE],

        ['GET', '#^/api/payroll/people$#', 'payroll', AccessLevel::READ],
        ['GET', '#^/api/payroll/people/[0-9]+$#', 'payroll', AccessLevel::READ],
        ['GET', '#^/api/payroll/capabilities$#', 'payroll', AccessLevel::READ],
        ['GET', '#^/api/payroll/settings/activation$#', 'payroll.settings', AccessLevel::READ],
        ['*', '#^/api/payroll/settings/activation$#', 'payroll.settings', AccessLevel::WRITE],
        ['GET', '#^/api/payroll(/|$)#', 'payroll', AccessLevel::READ],
        ['*', '#^/api/payroll(/|$)#', 'payroll', AccessLevel::WRITE],

        ['GET', '#^/api/accounting/bank-posting-(rules|suggestions|unposted)(/|$)#', 'bank.rules', AccessLevel::READ],
        ['*', '#^/api/accounting/bank-posting-(rules|suggestions|unposted)(/|$)#', 'bank.rules', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/(bank-rule-templates|auto-posting-policy)(/|$)#', 'bank.rules', AccessLevel::READ],
        ['*', '#^/api/accounting/(bank-rule-templates|auto-posting-policy)(/|$)#', 'bank.rules', AccessLevel::WRITE],
        ['POST', '#^/api/automation/wizard/apply$#', 'bank.rules', AccessLevel::WRITE],
        ['GET', '#^/api/automation(/|$)#', 'accounting', AccessLevel::READ],
        ['POST', '#^/api/ai/suggestions/[0-9]+/(accept|reject)$#', 'accounting.journal.post', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/bank-accounts(/|$)#', 'accounting', AccessLevel::READ],
        ['*', '#^/api/accounting/bank-accounts(/|$)#', 'accounting', AccessLevel::WRITE],
        ['POST', '#^/api/bank-statements/(upload|upload-pdf|scan)$#', 'bank.import', AccessLevel::WRITE],
        ['GET', '#^/api/(bank-statements|bank-transactions)(/|$)#', 'bank', AccessLevel::READ],
        ['*', '#^/api/(bank-statements|bank-transactions)(/|$)#', 'bank', AccessLevel::WRITE],

        ['GET', '#^/api/document-requests(/|$)#', 'documents.requests', AccessLevel::READ],
        ['*', '#^/api/document-requests(/|$)#', 'documents.requests', AccessLevel::WRITE],
        ['POST', '#^/api/(documents|document-folders)(/|$)#', 'documents.upload', AccessLevel::WRITE],
        ['*', '#^/api/documents/[0-9]+/(move|links)(/|$)#', 'documents.move', AccessLevel::WRITE],
        ['DELETE', '#^/api/(documents|document-folders)(/|$)#', 'documents.delete', AccessLevel::WRITE],
        ['POST', '#^/api/documents/[0-9]+/restore$#', 'documents.restore', AccessLevel::WRITE],
        ['GET', '#^/api/(documents|document-folders)(/|$)#', 'documents', AccessLevel::READ],
        ['*', '#^/api/(documents|document-folders)(/|$)#', 'documents', AccessLevel::WRITE],

        ['GET', '#^/api/accounting/periods(/|$)#', 'accounting', AccessLevel::READ],
        ['*', '#^/api/accounting/periods/[0-9]+/(closing|close|open-next|revert)(/|$)#', 'accounting.periods.close', AccessLevel::WRITE],
        ['*', '#^/api/accounting/periods(/|$)#', 'accounting.periods.manage', AccessLevel::WRITE],
        ['*', '#^/api/accounting/journal/(post|transfer)|^/api/accounting/journal/post-(invoice|purchase)/#', 'accounting.journal.post', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/journal-templates(/|$)#', 'accounting.templates', AccessLevel::READ],
        ['*', '#^/api/accounting/journal-templates(/|$)#', 'accounting.templates', AccessLevel::WRITE],
        // Mzdová rekapitulace: náhled je POST (nese vstupy v těle), ale nic nemění →
        // READ. Na této úrovni závisí demo brána; při budoucím zápisu změnit na WRITE.
        // Zaúčtování sdílí právo s ostatním účtováním deníku.
        ['*', '#^/api/accounting/payroll/preview$#', 'accounting', AccessLevel::READ],
        ['*', '#^/api/accounting/payroll/post$#', 'accounting.journal.post', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/offsets(/|$)#', 'accounting.offsets', AccessLevel::READ],
        ['*', '#^/api/accounting/offsets(/|$)#', 'accounting.offsets', AccessLevel::WRITE],
        // Zápočet faktury proti účtu — stejné právo jako vzájemné zápočty.
        ['GET', '#^/api/accounting/settlements(/|$)#', 'accounting.offsets', AccessLevel::READ],
        ['*', '#^/api/accounting/settlements(/|$)#', 'accounting.offsets', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/journal(/|$)#', 'accounting', AccessLevel::READ],
        ['*', '#^/api/accounting/journal(/|$)#', 'accounting.journal.write', AccessLevel::WRITE],
        ['GET', '#^/api/accounting(?:$|/(?!cash-|assets|bank-posting-))#', 'accounting', AccessLevel::READ],
        ['*', '#^/api/accounting(?:$|/(?!cash-|assets|bank-posting-))#', 'accounting', AccessLevel::WRITE],

        ['*', '#^/api/tax-evidence/classification(/|$)#', 'tax_evidence.classification.write', AccessLevel::WRITE],
        ['GET', '#^/api/tax-evidence/.*/export$#', 'tax_evidence.export', AccessLevel::READ],
        ['GET', '#^/api/tax-evidence(/|$)#', 'tax_evidence', AccessLevel::READ],
        ['*', '#^/api/tax-evidence(/|$)#', 'tax_evidence', AccessLevel::WRITE],
        ['*', '#^/api/(reports|tax-return)/.*/finalize$#', 'reports.finalize', AccessLevel::WRITE],
        ['*', '#^/api/(reports|tax-return)/.*/reopen$#', 'reports.reopen', AccessLevel::WRITE],
        // Featura A — rekonciliace proti podanému přiznání je POST (upload), ale read-only
        // (nic neukládá/neúčtuje) → jen READ, ne module-fallback WRITE níže. Na této
        // úrovni závisí demo brána; začne-li endpoint data ukládat, musí být WRITE.
        ['POST', '#^/api/tax-return/.*/reconcile$#', 'reports', AccessLevel::READ],
        ['GET', '#^/api/reports/submissions/settings$#', 'reports.submit', AccessLevel::WRITE],
        ['GET', '#^/api/reports/submissions/[0-9]+/artifacts/[0-9]+/download$#', 'reports.export', AccessLevel::READ],
        ['GET', '#^/api/reports/submissions(/|$)#', 'reports', AccessLevel::READ],
        ['*', '#^/api/reports/submissions(/|$)#', 'reports.submit', AccessLevel::WRITE],
        ['GET', '#^/api/reports/monthly-export(/|$)#', 'reports.export', AccessLevel::READ],
        ['*', '#^/api/reports/monthly-export(/|$)#', 'reports.export', AccessLevel::WRITE],
        ['GET', '#^/api/reports/closing-package(/|$)#', 'reports.export', AccessLevel::READ],
        ['*', '#^/api/reports/closing-package(/|$)#', 'reports.export', AccessLevel::WRITE],
        ['GET', '#^/api/(reports|tax-return)(/|$).*(xml|export|pdf|download)#', 'reports.export', AccessLevel::READ],
        ['GET', '#^/api/(reports|tax-return|tax)(/|$)#', 'reports', AccessLevel::READ],
        ['*', '#^/api/(reports|tax-return|tax)(/|$)#', 'reports', AccessLevel::WRITE],

        ['GET', '#^/api/accounting/cash-(documents|registers)(/|$)#', 'cash', AccessLevel::READ],
        ['*', '#^/api/accounting/cash-documents(/|$)#', 'cash.document.write', AccessLevel::WRITE],
        ['*', '#^/api/accounting/cash-registers/[0-9]+/(close|lock)$#', 'cash.close', AccessLevel::WRITE],
        ['*', '#^/api/accounting/cash-(documents|registers)(/|$)#', 'cash', AccessLevel::WRITE],
        ['GET', '#^/api/accounting/assets(/|$)#', 'assets', AccessLevel::READ],
        ['*', '#^/api/accounting/assets/[0-9]+/(depreciation|improvements)(/|$)#', 'assets.depreciation', AccessLevel::WRITE],
        ['*', '#^/api/accounting/assets/[0-9]+/(dispose|sale)$#', 'assets.dispose', AccessLevel::WRITE],
        ['*', '#^/api/accounting/assets(/|$)#', 'assets.write', AccessLevel::WRITE],

        ['GET', '#^/api/stock(/|$)#', 'stock', AccessLevel::READ],
        ['*', '#^/api/stock/items(/|$)#', 'stock.items.write', AccessLevel::WRITE],
        ['*', '#^/api/stock/documents(/|$)#', 'stock.documents.write', AccessLevel::WRITE],
        ['*', '#^/api/stock/.*/close$#', 'stock.close', AccessLevel::WRITE],
        ['*', '#^/api/stock/takes(/|$)#', 'stock.take', AccessLevel::WRITE],
        ['*', '#^/api/stock(/|$)#', 'stock', AccessLevel::WRITE],
        ['GET', '#^/api/eshop(/|$)#', 'eshop', AccessLevel::READ],
        ['*', '#^/api/eshop(/|$)#', 'eshop.write', AccessLevel::WRITE],
        ['POST', '#^/api/logbook/.*/import#', 'logbook.import', AccessLevel::WRITE],
        ['DELETE', '#^/api/logbook(/|$)#', 'logbook.delete', AccessLevel::WRITE],
        ['GET', '#^/api/logbook(/|$)#', 'logbook', AccessLevel::READ],
        ['*', '#^/api/logbook(/|$)#', 'logbook.write', AccessLevel::WRITE],

        ['GET', '#^/api/settings/currencies(/|$)#', 'settings.bank_accounts', AccessLevel::READ],
        ['*', '#^/api/settings/(bank-accounts|currencies)(/|$)#', 'settings.bank_accounts', AccessLevel::WRITE],
        ['GET', '#^/api/settings/email-branding/preview$#', 'settings.branding', AccessLevel::READ],
        ['*', '#^/api/settings/(email-branding|supplier/logo)(/|$)#', 'settings.branding', AccessLevel::WRITE],
        ['GET', '#^/api/settings/ai-assist$#', 'settings.ai_provider', AccessLevel::READ],
        ['*', '#^/api/settings/.*/ai|^/api/settings/ai#', 'settings.ai_provider', AccessLevel::WRITE],
        ['GET', '#^/api/settings/(signing|pdf-signing)(/|$)#', 'settings.signing', AccessLevel::READ],
        ['*', '#^/api/settings/(signing|pdf-signing)(/|$)#', 'settings.signing', AccessLevel::WRITE],
        ['GET', '#^/api/settings/accounting-activation/status$#', 'settings.company', AccessLevel::READ],
        ['*', '#^/api/settings/accounting-activation(/|$)#', 'accounting.periods.manage', AccessLevel::WRITE],
        ['GET', '#^/api/settings(/|$)#', 'settings.company', AccessLevel::READ],
        ['*', '#^/api/settings(/|$)#', 'settings.company.write', AccessLevel::WRITE],

        ['GET', '#^/api/dashboard(/|$)#', 'dashboard', AccessLevel::READ],
        ['GET', '#^/api/(portfolio|crm)(/|$)#', 'dashboard.portfolio', AccessLevel::READ],
        ['*', '#^/api/(portfolio|crm)(/|$)#', 'dashboard.portfolio', AccessLevel::WRITE],
        ['GET', '#^/api/(codebooks|expense-categories|revenue-categories|vat-classifications)(/|$)#', 'settings.company', AccessLevel::READ],
        ['*', '#^/api/(codebooks|expense-categories|revenue-categories|vat-classifications)(/|$)#', 'settings.company.write', AccessLevel::WRITE],
        ['GET', '#^/api/(suppliers|search|slug)(/|$)#', 'profile', AccessLevel::READ],
        ['GET', '#^/api/branding-profiles$#', 'profile', AccessLevel::READ],
        ['*', '#^/api/user/(filters|preferences)(/|$)#', 'profile', AccessLevel::WRITE],
        ['GET', '#^/api/portal(/|$)#', 'profile', AccessLevel::READ],
        ['POST', '#^/api/portal/document-requests/[0-9]+/upload$#', 'purchase_invoices.create', AccessLevel::WRITE],
        ['GET', '#^/api/(work-reports)(/|$)#', 'projects', AccessLevel::READ],
        ['*', '#^/api/(work-reports)(/|$)#', 'projects', AccessLevel::WRITE],
    ];

    public function match(string $method, string $path): ?RoutePermission
    {
        $method = strtoupper($method);
        if (in_array($path, self::PUBLIC_PATHS, true) || str_starts_with($path, '/api/public/')) {
            return new RoutePermission(self::PUBLIC);
        }
        if (in_array($path, self::SELF_PATHS, true)) {
            return new RoutePermission(self::SELF_SERVICE);
        }
        // Správa vlastních přístupových klíčů (přejmenování, smazání) je self-service —
        // vazba na přihlášeného uživatele se kontroluje až v akci podle ID klíče.
        if (preg_match('#^/api/auth/webauthn/credentials/[0-9]+$#', $path) === 1) {
            return new RoutePermission(self::SELF_SERVICE);
        }
        if ($path === '/api/auth/setup-sample') {
            return new RoutePermission(self::SUPERADMIN);
        }
        if (str_starts_with($path, '/api/admin/') || str_starts_with($path, '/api/maintenance/')) {
            return new RoutePermission(self::SUPERADMIN);
        }
        // Licencování a aktivace (E4) — admin only.
        if (str_starts_with($path, '/api/license/')) {
            return new RoutePermission(self::SUPERADMIN);
        }
        if ($method !== 'GET' && preg_match('#^/api/price-list-items(/|$)#', $path) === 1) {
            return new RoutePermission(self::SUPERADMIN);
        }
        if ($method !== 'GET' && preg_match('#^/api/suppliers(/[0-9]+)?$#', $path) === 1) {
            return new RoutePermission(self::SUPERADMIN);
        }
        foreach (self::RULES as [$ruleMethod, $pattern, $key, $level]) {
            if (($ruleMethod === '*' || $ruleMethod === $method) && preg_match($pattern, $path) === 1) {
                return new RoutePermission(self::PERMISSION, $key, $level);
            }
        }
        return null;
    }
}

final class RoutePermission
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $key = null,
        public readonly AccessLevel $minimum = AccessLevel::NONE,
    ) {}
}
