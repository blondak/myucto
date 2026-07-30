import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import type { AccessLevel, PermissionKey } from '@/security/permissions'

declare module 'vue-router' {
  interface RouteMeta {
    permission?: PermissionKey
    access?: AccessLevel
    superadminOnly?: boolean
    requiresSupplier?: boolean
    requiresDoubleEntry?: boolean
    requiresTaxEvidence?: boolean
    requiresCashMode?: boolean
    requiresStock?: boolean
    commercialOnly?: boolean
    requiresNoStock?: boolean
    requiresOss?: boolean
    requiresAuth?: boolean
    public?: boolean
    totpSetupOnly?: boolean
    mfaSetupOnly?: boolean
  }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('@/components/layout/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '',                       name: 'home',           component: () => import('@/pages/Dashboard.vue') },
      // Klientský portál (Epic F6) — domov role client, náhled pro všechny role.
      { path: 'portal',                 name: 'portal',         component: () => import('@/pages/portal/PortalDashboard.vue'), meta: {  } },
      // Vyžádání chybějících dokladů (Fáze F, audit 2026-07) — klientský portál.
      { path: 'portal/document-requests', name: 'portal-document-requests', component: () => import('@/pages/portal/DocumentRequests.vue'), meta: {  } },
      { path: 'clients',                name: 'clients',        component: () => import('@/pages/clients/ClientList.vue'), meta: {  } },
      { path: 'clients/new',            name: 'client-new',     component: () => import('@/pages/clients/ClientForm.vue'), meta: { requiresSupplier: true } },
      { path: 'clients/:id(\\d+)',      name: 'client-detail',  component: () => import('@/pages/clients/ClientDetail.vue'), meta: {  } },
      { path: 'clients/:id(\\d+)/edit', name: 'client-edit',    component: () => import('@/pages/clients/ClientForm.vue'), meta: { requiresSupplier: true } },
      { path: 'projects',               name: 'projects',       component: () => import('@/pages/projects/ProjectList.vue') },
      { path: 'projects/new',           name: 'project-new',    component: () => import('@/pages/projects/ProjectForm.vue'), meta: { requiresSupplier: true } },
      { path: 'projects/:id(\\d+)',     name: 'project-detail', component: () => import('@/pages/projects/ProjectDetail.vue') },
      { path: 'projects/:id(\\d+)/edit', name: 'project-edit',  component: () => import('@/pages/projects/ProjectForm.vue'), meta: { requiresSupplier: true } },
      { path: 'invoices',               name: 'invoices',       component: () => import('@/pages/invoices/InvoiceList.vue'), meta: {  } },
      // AI import vydané faktury — prodejní zrcadlo /purchase-invoices/ai-import.
      // Extrakce (ISDOC priorita, AI fallback) → draft vydané faktury k revizi v editoru.
      // Oprávnění invoices.create (write) zrcadlí BE check v AiExtractPdfIssuedAction.
      { path: 'invoices/ai-import',      name: 'invoice-ai-import', component: () => import('@/pages/invoices/SalesAiImport.vue'), meta: { requiresSupplier: true } },
      { path: 'invoices/new',           name: 'invoice-new',    component: () => import('@/pages/invoices/InvoiceEditor.vue'), meta: { requiresSupplier: true } },
      { path: 'invoices/:id(\\d+)',     name: 'invoice-detail', component: () => import('@/pages/invoices/InvoiceDetail.vue'), meta: {  } },
      { path: 'invoices/:id(\\d+)/edit', name: 'invoice-edit',  component: () => import('@/pages/invoices/InvoiceEditor.vue'), meta: { requiresSupplier: true } },
      // Export/Import vydaných (reorg UX 2026-07) — nav pod Prodej; zrcadlí
      // purchase-invoices/export|import níže, sdílená stránka DataExchange.vue.
      {
        path: 'invoices/export', name: 'invoices-export',
        component: () => import('@/pages/admin/DataExchange.vue'), props: { scope: 'issued', mode: 'export' },
      },
      {
        path: 'invoices/import', name: 'invoices-import',
        component: () => import('@/pages/admin/DataExchange.vue'), props: { scope: 'issued', mode: 'import' },
        beforeEnter: () => (useAuthStore().isSuperadmin ? true : { path: '/invoices/export' }),
      },
      // Přijaté faktury (fáze 1 integrace forku)
      { path: 'purchase-invoices',                 name: 'purchase-invoices',        component: () => import('@/pages/purchase-invoices/InvoiceList.vue'), meta: {  } },
      // Export/Import přijatých (reorg UX 2026-07) — nav pod Nákup; sdílená stránka
      // DataExchange.vue jen vybere ExportPurchase/ImportPurchase dle props.
      {
        path: 'purchase-invoices/export', name: 'purchase-invoices-export',
        component: () => import('@/pages/admin/DataExchange.vue'), props: { scope: 'purchase', mode: 'export' },
      },
      {
        path: 'purchase-invoices/import', name: 'purchase-invoices-import',
        component: () => import('@/pages/admin/DataExchange.vue'), props: { scope: 'purchase', mode: 'import' },
        // Import je jen pro superadmina (BE endpoint je adminOnly) — bez gate by nezapnutý
        // nav item stejně nešel rozkliknout, ale přímý odkaz by ukázal upload UI zbytečně;
        // zrcadlí dřívější tiché downgrade chování normalizeTab() v DataExchange.vue.
        beforeEnter: () => (useAuthStore().isSuperadmin ? true : { path: '/purchase-invoices/export' }),
      },
      { path: 'purchase-invoices/payment-orders',  name: 'purchase-invoices-payment-orders', component: () => import('@/pages/purchase-invoices/PaymentOrders.vue') },
      // AI import přijaté faktury (§12b) — extrakční flow vytažený z admin Integrations
      // (?tab=ai zůstává jen nastavení brány). Oprávnění purchase_invoices.scan zrcadlí
      // BE check v AiExtractPdfAction (účetní denní operativa, ne admin setup).
      { path: 'purchase-invoices/ai-import',       name: 'purchase-invoice-ai-import', component: () => import('@/pages/purchase-invoices/AiImport.vue'), meta: { requiresSupplier: true } },
      { path: 'purchase-invoices/new',             name: 'purchase-invoice-new',     component: () => import('@/pages/purchase-invoices/InvoiceEditor.vue'), meta: { requiresSupplier: true } },
      { path: 'purchase-invoices/:id(\\d+)',       name: 'purchase-invoice-detail',  component: () => import('@/pages/purchase-invoices/InvoiceDetail.vue'), meta: {  } },
      { path: 'purchase-invoices/:id(\\d+)/edit',  name: 'purchase-invoice-edit',    component: () => import('@/pages/purchase-invoices/InvoiceEditor.vue'), meta: { requiresSupplier: true } },
      // Dokumenty (sekce Dokumenty — plán source/11)
      { path: 'documents',              name: 'documents',        component: () => import('@/pages/documents/DocumentsBrowser.vue') },
      { path: 'documents/:id(\\d+)',    name: 'document-detail',  component: () => import('@/pages/documents/DocumentDetail.vue') },
      // Vyžádání chybějících dokladů (Fáze F, audit 2026-07) — účetní pohled.
      { path: 'document-requests',      name: 'document-requests', component: () => import('@/pages/documents/DocumentRequests.vue') },
      // Účetnictví (Epic F1 — podvojné účetnictví; jen supplier.accounting_mode === 'double_entry')
      { path: 'accounting/accounts',      name: 'accounting-accounts',      component: () => import('@/pages/accounting/ChartOfAccounts.vue'), meta: { requiresDoubleEntry: true } },
      // Předkontace, Kurzový režim, Repo sazba, Archiv účetnictví a Hromadný export
      // jsou teď záložky sjednocené stránky /utilities (Nástroje) — redirecty
      // zachovávají bookmarks i route names. Export/Import se odsud vyčlenilo (reorg UX
      // 2026-07) na samostatné routy pod Prodej/Nákup, viz níže.
      // Účetní období (Uzávěrka) — vytažené z Nástrojů do vlastní top-level položky menu.
      { path: 'accounting/periods',       name: 'accounting-periods',       component: () => import('@/pages/accounting/Periods.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/journal',       name: 'accounting-journal',       component: () => import('@/pages/accounting/Journal.vue'),         meta: { requiresDoubleEntry: true } },
      { path: 'accounting/journal/new',   name: 'accounting-journal-new',   component: () => import('@/pages/accounting/ManualEntry.vue'),     meta: { requiresDoubleEntry: true, requiresSupplier: true } },
      { path: 'accounting/payroll',       name: 'accounting-payroll',       component: () => import('@/pages/accounting/PayrollRecap.vue'),    meta: { requiresDoubleEntry: true, requiresSupplier: true } },
      { path: 'accounting/posting-rules', name: 'accounting-posting-rules', redirect: '/utilities?section=posting-rules' },
      // Účetní sestavy (Epic F2) — read-only, bez requiresWrite
      { path: 'accounting/general-ledger',   name: 'accounting-general-ledger',   component: () => import('@/pages/accounting/GeneralLedger.vue'),   meta: { requiresDoubleEntry: true } },
      { path: 'accounting/trial-balance',    name: 'accounting-trial-balance',    component: () => import('@/pages/accounting/TrialBalance.vue'),    meta: { requiresDoubleEntry: true } },
      { path: 'accounting/account-statement/:accountId(\\d+)', name: 'accounting-account-statement', component: () => import('@/pages/accounting/AccountStatement.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/balance-sheet',    name: 'accounting-balance-sheet',    component: () => import('@/pages/accounting/BalanceSheet.vue'),    meta: { requiresDoubleEntry: true } },
      { path: 'accounting/income-statement', name: 'accounting-income-statement', component: () => import('@/pages/accounting/IncomeStatement.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/income-statement-by-function', name: 'accounting-income-statement-by-function', component: () => import('@/pages/accounting/IncomeStatementByFunction.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/saldo',            name: 'accounting-saldo',            component: () => import('@/pages/accounting/Saldokonto.vue'),      meta: { requiresDoubleEntry: true } },
      // Featura E (REAL_data_followup_UX.md) — kontrola úplnosti dokladů proti bance (§24/1) + doklady po splatnosti.
      { path: 'accounting/document-completeness', name: 'accounting-document-completeness', component: () => import('@/pages/accounting/DocumentCompleteness.vue'), meta: { requiresDoubleEntry: true } },
      // Inventarizace rozvahových účtů (§29–30 ZoÚ, T2) — soupis KZ účtů tříd 0–4 k rozvahovému dni.
      { path: 'accounting/balance-inventory', name: 'accounting-balance-inventory', component: () => import('@/pages/accounting/BalanceInventory.vue'), meta: { requiresDoubleEntry: true } },
      // § 18 odst. 2 ZoÚ — přehled o peněžních tocích a o změnách vlastního kapitálu.
      { path: 'accounting/section18-statements', name: 'accounting-section18-statements', component: () => import('@/pages/accounting/Section18Statements.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/monthly-check',    name: 'accounting-monthly-check',    component: () => import('@/pages/accounting/MonthlyCheck.vue'),    meta: { requiresDoubleEntry: true } },
      // Evidenční podklad DPPO (Epic F4, R19) — odkaz z kroku uzávěrky „Daň z příjmů".
      { path: 'accounting/reports/tax-base-adjustments', name: 'accounting-tax-base-adjustments', component: () => import('@/pages/accounting/TaxBaseAdjustments.vue'), meta: { requiresDoubleEntry: true } },
      // Featura H (REAL_data_followup_UX.md) — jednotná fronta ručního doúčtování napříč zdroji.
      { path: 'accounting/manual-posting-queue', name: 'manual-posting-queue', component: () => import('@/pages/accounting/ManualPostingQueue.vue'), meta: { requiresDoubleEntry: true } },
      // Měsíční přehled klientovi (Fáze F, audit 2026-07, P3 návrh)
      { path: 'accounting/monthly-report',   name: 'accounting-monthly-report',   component: () => import('@/pages/accounting/MonthlyReport.vue'),   meta: { requiresDoubleEntry: true } },
      // Vzájemné zápočty + kurzový režim (Fáze F)
      { path: 'accounting/offsets',          name: 'accounting-offsets',          component: () => import('@/pages/accounting/Offsets.vue'),         meta: { requiresDoubleEntry: true } },
      { path: 'accounting/fx-rate-settings', name: 'accounting-fx-rate-settings', redirect: '/utilities?section=fx-rates' },
      { path: 'accounting/repo-rates',       name: 'accounting-repo-rates',       redirect: '/utilities?section=repo-rates' },
      // Majetek a odpisy (Epic F3)
      { path: 'accounting/assets',                name: 'accounting-assets',       component: () => import('@/pages/accounting/Assets.vue'),      meta: { requiresDoubleEntry: true } },
      { path: 'accounting/assets/new',            name: 'accounting-asset-new',    component: () => import('@/pages/accounting/AssetEditor.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/assets/:id(\\d+)',      name: 'accounting-asset-detail', component: () => import('@/pages/accounting/AssetDetail.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'accounting/assets/:id(\\d+)/edit', name: 'accounting-asset-edit',   component: () => import('@/pages/accounting/AssetEditor.vue'), meta: { requiresDoubleEntry: true } },
      // Drobný majetek (§DM) — evidence dle §28/5 ZoÚ. Vlastní stránka vedle DHM: jiný
      // režim (jednorázový náklad na 501 bez odpisů) i jiné oprávnění (`accounting`,
      // protože API /api/accounting/small-assets spadá pod fallback, ne pod `assets`).
      { path: 'accounting/small-assets',          name: 'accounting-small-assets', component: () => import('@/pages/accounting/SmallAssets.vue'), meta: { requiresDoubleEntry: true } },
      // Pravidla zaúčtování nákladů se přesunula pod Šablony (záložka „Pravidla nákladů").
      // Původní cesta se zachovává jako redirect kvůli starým odkazům/záložkám.
      { path: 'accounting/expense-rules', redirect: { path: '/templates', query: { section: 'expense' } } },
      // Uzávěrka období + archiv (Epic F4)
      { path: 'accounting/periods/:id(\\d+)/closing', name: 'accounting-period-closing', component: () => import('@/pages/accounting/PeriodClosing.vue'),     meta: { requiresDoubleEntry: true } },
      // Uzávěrkový balíček — ZIP se všemi sestavami uzávěrky daného účetního období.
      { path: 'accounting/periods/:id(\\d+)/closing-package', name: 'accounting-closing-package', component: () => import('@/pages/accounting/ClosingPackage.vue'), meta: { requiresDoubleEntry: true } },
      // Příloha k účetní závěrce (§ 18/1/c) — editor sekcí; ukládá se per fiskální rok,
      // takže funguje i nad uzavřeným obdobím.
      { path: 'accounting/periods/:id(\\d+)/statement-notes', name: 'accounting-statement-notes', component: () => import('@/pages/accounting/StatementNotes.vue'), meta: { requiresDoubleEntry: true } },
      // Retenční lhůty § 31/32 ZoÚ + zadržení skartace (audit UI mezer 2026-07).
      { path: 'accounting/retention', name: 'accounting-retention', component: () => import('@/pages/accounting/Retention.vue'), meta: { requiresDoubleEntry: true } },
      // Přechodový můstek § 7b ↔ § 24 ZDP (přílohy č. 2 a 3) — read-only sestava.
      // Bez mode-guardu: v menu je jen u firem na daňové evidenci (chystaný přechod),
      // ale po přechodu na podvojné musí zůstat dostupná přes URL — BE si směr ohlídá.
      { path: 'accounting/transition-report', name: 'accounting-transition-report', component: () => import('@/pages/accounting/TransitionReport.vue') },
      { path: 'accounting/archive',                   name: 'accounting-archive',        redirect: '/utilities?section=archive' },
      // Pokladna (mini-epic POKLADNA #14) — dostupná v OBOU účetních režimech: podvojné
      // účetnictví i daňová evidence (Epic DE §6, no-journal cash path). requiresCashMode
      // povolí double_entry i tax_evidence; ostatní /accounting/* zůstávají double_entry-only.
      { path: 'accounting/cash',      name: 'accounting-cash',      component: () => import('@/pages/accounting/CashRegister.vue'),       meta: { requiresCashMode: true } },
      { path: 'accounting/cash/new',  name: 'accounting-cash-new',  component: () => import('@/pages/accounting/CashDocumentEditor.vue'), meta: { requiresCashMode: true, requiresSupplier: true } },
      { path: 'accounting/cash/book', name: 'accounting-cash-book', component: () => import('@/pages/accounting/CashBook.vue'),           meta: { requiresCashMode: true } },
      // Daňová evidence (Epic DE) — jen supplier.accounting_mode === 'tax_evidence' (zrcadlo requiresDoubleEntry)
      { path: 'tax-evidence/cash-journal',         name: 'tax-evidence-cash-journal',         component: () => import('@/pages/tax-evidence/CashJournal.vue'),         meta: { requiresTaxEvidence: true } },
      { path: 'tax-evidence/receivables-payables', name: 'tax-evidence-receivables-payables', component: () => import('@/pages/tax-evidence/ReceivablesPayables.vue'), meta: { requiresTaxEvidence: true } },
      // Sklad (Epic SKLAD) — gate requiresStock (supplier.stock_enabled); role client nemá
      // clientAllowed → guard ho pošle na /portal (deny-by-default, žádný deniedRoles).
      { path: 'stock/items',            name: 'stock-items',           component: () => import('@/pages/stock/ItemList.vue'),     meta: { requiresStock: true } },
      { path: 'stock/items/new',        name: 'stock-item-new',        component: () => import('@/pages/stock/ItemEditor.vue'),   meta: { requiresStock: true, requiresSupplier: true } },
      { path: 'stock/items/:id(\\d+)',      name: 'stock-item-detail', component: () => import('@/pages/stock/ItemDetail.vue'),   meta: { requiresStock: true } },
      { path: 'stock/items/:id(\\d+)/edit', name: 'stock-item-edit',   component: () => import('@/pages/stock/ItemEditor.vue'),   meta: { requiresStock: true, requiresSupplier: true } },
      { path: 'stock/documents',            name: 'stock-documents',        component: () => import('@/pages/stock/DocumentList.vue'),   meta: { requiresStock: true } },
      { path: 'stock/documents/new',        name: 'stock-document-new',     component: () => import('@/pages/stock/DocumentEditor.vue'), meta: { requiresStock: true, requiresSupplier: true } },
      { path: 'stock/documents/:id(\\d+)',  name: 'stock-document-detail',  component: () => import('@/pages/stock/DocumentEditor.vue'), meta: { requiresStock: true } },
      { path: 'stock/warehouses',       name: 'stock-warehouses',      component: () => import('@/pages/stock/Warehouses.vue'),   meta: { requiresStock: true } },
      { path: 'stock/takes',            name: 'stock-takes',           component: () => import('@/pages/stock/TakeWizard.vue'),   meta: { requiresStock: true } },
      { path: 'stock/takes/:id(\\d+)',  name: 'stock-take-detail',     component: () => import('@/pages/stock/TakeWizard.vue'),   meta: { requiresStock: true } },
      { path: 'stock/reports',          name: 'stock-reports',         component: () => import('@/pages/stock/Reports.vue'),      meta: { requiresStock: true } },
      // E-shop — číselníky (Výrobci/Kategorie/Atributy/Tagy/Poplatky/Sklady) + import
      // jako záložky jedné stránky (?tab=…). Poslední položka sekce „Zboží".
      { path: 'eshop',               name: 'eshop',               component: () => import('@/pages/eshop/EshopPage.vue'),     meta: { requiresStock: true, requiresSupplier: true } },
      // Kniha jízd (logbook) — auta, jízdy, tankování
      { path: 'logbook',                name: 'logbook',          component: () => import('@/pages/logbook/LogbookPage.vue') },
      { path: 'stats',                  name: 'stats',           component: () => import('@/pages/Stats.vue') },
      { path: 'purchase-stats',         name: 'purchase-stats',  component: () => import('@/pages/PurchaseStats.vue') },
      // Sjednocená stránka „Bankovní účty" (Finance): výpisy + měny/účty + stavy + avíza.
      // Pravidla účtování (bank posting rules) se přesunula pod Šablony (záložka „Pravidla
      // účtování"), vedle Pravidel nákladů — jednotné místo pro všechna pravidla/šablony.
      // Starý ?tab=rules zůstává jako redirect kvůli starým odkazům/záložkám.
      {
        path: 'bank',
        name: 'bank-statements',
        component: () => import('@/pages/bank/BankPage.vue'),
        beforeEnter: to => to.query.tab === 'rules'
          ? { path: '/templates', query: { section: 'posting' } }
          : true,
      },
      { path: 'bank/:id(\\d+)',         name: 'bank-detail',     component: () => import('@/pages/bank/StatementDetail.vue') },
      // Admin (M6)
      { path: 'admin/activity-log',     name: 'activity-log',   component: () => import('@/pages/admin/ActivityLog.vue'), meta: {  } },
      { path: 'admin/sent-emails',      name: 'sent-emails',    component: () => import('@/pages/admin/SentEmails.vue'), meta: {  } },
      { path: 'admin/cron-jobs',        name: 'cron-jobs',      component: () => import('@/pages/admin/CronJobs.vue'),    meta: {  } },
      { path: 'admin/users',            name: 'admin-users',    component: () => import('@/pages/admin/Users.vue'),       meta: {  } },
      { path: 'admin/roles',            name: 'admin-roles',    component: () => import('@/pages/admin/Roles.vue'),       meta: { superadminOnly: true } },
      { path: 'admin/settings',         name: 'admin-settings', component: () => import('@/pages/admin/Settings.vue'),    meta: {  } },
      { path: 'admin/accounting-activation', name: 'accounting-activation', component: () => import('@/pages/admin/AccountingActivation.vue'), meta: {  } },
      { path: 'admin/branding',         name: 'admin-branding', component: () => import('@/pages/admin/Branding.vue'),    meta: {  } },
      // Bývalá stránka Systém → Bankovní účty je nyní součástí /bank (Finance) jako záložky.
      // Redirect zachovává bookmarks vč. původního ?tab=.
      {
        path: 'admin/bank-accounts',
        name: 'admin-bank-accounts',
        redirect: to => ({
          path: '/bank',
          query: { tab: ['accounts', 'balances', 'email'].includes(String(to.query.tab)) ? String(to.query.tab) : 'accounts' },
        }),
      },
      { path: 'admin/bank-email-notices', name: 'admin-bank-email-notices', redirect: '/bank?tab=email' },
      // Dodavatelé (multi-tenant firmy) — vlastní stránka, vytažená ze záložky v Codebooks
      // (reorg menu, audit 2026-07): Firma/Globální nastavení jsou rozdělené a Dodavatelé
      // patří jako samostatný bod pod Globální nastavení.
      { path: 'admin/suppliers',        name: 'admin-suppliers', component: () => import('@/pages/admin/SuppliersPage.vue'), meta: {  } },
      {
        path: 'admin/codebooks',
        name: 'admin-codebooks',
        component: () => import('@/pages/admin/Codebooks.vue'),
        meta: {  },
        beforeEnter: to => to.query.tab === 'tax_constants'
          ? { path: '/admin/tax-constants' }
          : true,
      },
      { path: 'admin/tax-constants',    name: 'admin-tax-constants', component: () => import('@/pages/admin/TaxConstants.vue'), meta: {  } },
      { path: 'admin/electronic-signatures', name: 'admin-electronic-signatures', component: () => import('@/pages/admin/ElectronicSignatures.vue'), meta: {  } },
      { path: 'templates',                  name: 'templates',       component: () => import('@/pages/TemplatesPage.vue') },
      // Nástroje (reorg menu, audit 2026-07) — archivy, kurzový režim, repo sazba,
      // předkontace a účetní období jako záložky jedné stránky (?section=…). Export/Import
      // se odsud vyčlenilo (reorg UX 2026-07) na /invoices/export|import a
      // /purchase-invoices/export|import — starý ?section=exchange níže jen redirectuje.
      {
        path: 'utilities',
        name: 'tools',
        component: () => import('@/pages/ToolsPage.vue'),
        beforeEnter: to => {
          if (to.query.section === 'journal-templates') {
            return { path: '/templates', query: Object.fromEntries(Object.entries(to.query).filter(([key]) => key !== 'section')) }
          }
          // Účetní období se vytáhla do vlastní routy /accounting/periods (Uzávěrka) —
          // starý ?section=periods bookmark zachováváme jako redirect.
          if (to.query.section === 'periods') {
            return { path: '/accounting/periods' }
          }
          if (to.query.section === 'exchange') {
            const tab = Array.isArray(to.query.tab) ? to.query.tab[0] : to.query.tab
            const target: Record<string, string> = {
              'export-issued': '/invoices/export', 'import-issued': '/invoices/import',
              'export-purchase': '/purchase-invoices/export', 'import-purchase': '/purchase-invoices/import',
            }
            return { path: target[String(tab)] ?? '/invoices/export' }
          }
          return true
        },
      },
      // Staré routy Export/Import (dřív sjednocené na /utilities?section=exchange, resp.
      // ?tab= 4 taby) → redirecty přímo na nové samostatné routy (zachovávají bookmarks
      // i route names).
      {
        path: 'exchange', name: 'data-exchange',
        redirect: to => {
          const tab = Array.isArray(to.query.tab) ? to.query.tab[0] : to.query.tab
          const target: Record<string, string> = {
            'export-issued': '/invoices/export', 'import-issued': '/invoices/import',
            'export-purchase': '/purchase-invoices/export', 'import-purchase': '/purchase-invoices/import',
          }
          return target[String(tab)] ?? '/invoices/export'
        },
      },
      { path: 'admin/export',           name: 'admin-export',    redirect: '/invoices/export' },
      {
        path: 'admin/import',
        name: 'admin-import',
        redirect: to => {
          const tb = Array.isArray(to.query.tab) ? to.query.tab[0] : to.query.tab
          return tb === 'purchase' ? '/purchase-invoices/import' : '/invoices/import'
        },
      },
      { path: 'admin/integrations',     name: 'admin-integrations', component: () => import('@/pages/admin/Integrations.vue'), meta: {  } },
      { path: 'crm',                    name: 'crm-dashboard',      component: () => import('@/pages/crm/CrmDashboard.vue') },
      { path: 'portfolio',              name: 'portfolio-overview', component: () => import('@/pages/portfolio/PortfolioOverview.vue') },
      { path: 'automation',             name: 'automation-cockpit', component: () => import('@/pages/automation/AutomationCockpit.vue'), meta: { requiresDoubleEntry: true } },
      { path: 'reports/dph',            name: 'reports-dph',        component: () => import('@/pages/reports/DphPriznaniReport.vue') },
      { path: 'reports/kh',             name: 'reports-kh',         component: () => import('@/pages/reports/KontrolniHlaseniReport.vue') },
      { path: 'reports/dph-book',       name: 'reports-dph-book',   component: () => import('@/pages/reports/DphBookReport.vue') },
      { path: 'reports/s74b',           name: 'reports-s74b',       component: () => import('@/pages/reports/Section74b.vue') },
      { path: 'reports/related-parties', name: 'reports-related-parties', component: () => import('@/pages/reports/RelatedParties.vue') },
      // § 76 ZDPH — koeficient krácení nároku na odpočet (zálohový + roční vypořádání).
      { path: 'reports/vat-coefficient', name: 'reports-vat-coefficient', component: () => import('@/pages/reports/VatCoefficient.vue') },
      // § 46 ZDPH — věřitelská oprava základu daně u nedobytné pohledávky + obnovy § 46e.
      { path: 'reports/s46', name: 'reports-s46', component: () => import('@/pages/reports/Section46.vue') },
      { path: 'reports/vat-corrections', name: 'reports-vat-corrections', component: () => import('@/pages/reports/VatCorrections.vue') },
      { path: 'reports/shv',            name: 'reports-shv',        component: () => import('@/pages/reports/SouhrnneHlaseniReport.vue') },
      { path: 'reports/income-tax',     name: 'reports-income-tax', component: () => import('@/pages/reports/IncomeTaxReport.vue') },
      { path: 'reports/cnb-rate-audit', name: 'reports-cnb-rate-audit', component: () => import('@/pages/reports/CnbRateAudit.vue') },
      { path: 'reports/submissions',    name: 'reports-submissions', component: () => import('@/pages/reports/TaxSubmissions.vue') },
      { path: 'reports/monthly-export', name: 'reports-monthly-export', component: () => import('@/pages/reports/MonthlyExportReport.vue') },
      { path: 'reports/oss',            name: 'reports-oss', component: () => import('@/pages/reports/OssReport.vue'), meta: { requiresOss: true } },
      { path: 'tax',                    name: 'tax-optimizer',      component: () => import('@/pages/tax/TaxOptimizer.vue') },
      { path: 'admin/email-templates',  name: 'admin-email-templates', component: () => import('@/pages/admin/EmailTemplates.vue'), meta: {  } },
      // Sekce E-maily — záložky: Odeslané / Šablony / Elektronické podpisy (vzor Codebooks)
      { path: 'admin/emails',           name: 'admin-emails',    component: () => import('@/pages/admin/Emails.vue'), meta: {  } },
      { path: 'admin/approvals',        name: 'admin-approvals', component: () => import('@/pages/admin/Approvals.vue'), meta: {  } },
      { path: 'admin/price-list',       name: 'admin-price-list', component: () => import('@/pages/admin/PriceList.vue'), meta: { requiresSupplier: true, requiresNoStock: true } },
      { path: 'admin/price-list/new',   name: 'admin-price-list-new', component: () => import('@/pages/admin/PriceListForm.vue'), meta: { requiresSupplier: true, requiresNoStock: true } },
      { path: 'admin/price-list/:id(\\d+)/edit', name: 'admin-price-list-edit', component: () => import('@/pages/admin/PriceListForm.vue'), meta: { requiresSupplier: true, requiresNoStock: true } },
      { path: 'recurring',              name: 'recurring',        component: () => import('@/pages/recurring/RecurringList.vue'), meta: {  } },
      { path: 'recurring/new',          name: 'recurring-new',    component: () => import('@/pages/recurring/RecurringForm.vue'), meta: { requiresSupplier: true } },
      { path: 'recurring/:id(\\d+)',    name: 'recurring-detail', component: () => import('@/pages/recurring/RecurringDetail.vue'), meta: {  } },
      { path: 'recurring/:id(\\d+)/edit', name: 'recurring-edit', component: () => import('@/pages/recurring/RecurringForm.vue'), meta: { requiresSupplier: true } },
      { path: 'admin/update',           name: 'admin-update',    component: () => import('@/pages/admin/Update.vue'),    meta: {  } },
      // Aktivace (E4) — licenční model, obchodní podmínky, zakoupení/aktivace. Admin only.
      { path: 'activation/license',  name: 'activation-license',  component: () => import('@/pages/activation/Licence.vue'),          meta: {  } },
      { path: 'activation/terms',    name: 'activation-terms',    component: () => import('@/pages/activation/ObchodniPodminky.vue'), meta: {  } },
      { path: 'activation/purchase', name: 'activation-purchase', component: () => import('@/pages/activation/Zakoupeni.vue'),        meta: {  } },
      // /profile/totp je zachován pro BC (staré bookmarks, force-TOTP middleware redirect),
      // ale UI ho merge-uje do /profile/password (tabs). Redirect zachovává query stringy.
      { path: 'profile/totp',           name: 'profile-totp',          redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'totp' } }) },
      { path: 'profile/password',       name: 'profile-password',      component: () => import('@/pages/PasswordChange.vue'), meta: {  } },
      { path: 'profile/shortcuts',      name: 'profile-shortcuts',     redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'shortcuts' } }) },
      { path: 'profile/api-tokens',     name: 'profile-api-tokens',    component: () => import('@/pages/ApiTokens.vue') },
      { path: 'profile/passkeys',       name: 'profile-passkeys',      redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'passkeys' } }) },
      { path: 'profile/session-lock',   name: 'profile-session-lock',  redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'session-lock' } }) },
      { path: 'profile/signing-profiles', name: 'profile-signing-profiles', redirect: '/admin/electronic-signatures' },
    ],
  },
  { path: '/login',  name: 'login',  component: () => import('@/pages/Login.vue'),          meta: { public: true } },
  { path: '/setup',  name: 'setup',  component: () => import('@/pages/Setup.vue'),          meta: { public: true } },
  { path: '/setup-mfa', name: 'setup-mfa', component: () => import('@/pages/ForcedMfaSetup.vue'), meta: { requiresAuth: true, mfaSetupOnly: true } },
  { path: '/setup-totp', name: 'setup-totp', redirect: { path: '/setup-mfa', query: { method: 'totp' } } },
  { path: '/forgot', name: 'forgot', component: () => import('@/pages/ForgotPassword.vue'), meta: { public: true } },
  { path: '/reset',  name: 'reset',  component: () => import('@/pages/ResetPassword.vue'),  meta: { public: true } },
  { path: '/approval/:token([a-f0-9]{32,128})', name: 'approval',
    component: () => import('@/pages/ApprovalPublic.vue'), meta: { public: true } },
  { path: '/work-report/:token([a-f0-9]{32,128})', name: 'work-report-tracking',
    component: () => import('@/pages/WorkReportTrackingPublic.vue'), meta: { public: true } },
  // Web faktura — veřejný náhled vystavené faktury (singular /invoice/…, interní UI je /invoices/…)
  { path: '/invoice/:token([a-f0-9]{32,128})', name: 'invoice-public',
    component: () => import('@/pages/InvoicePublic.vue'), meta: { public: true } },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/pages/NotFound.vue'),
  },
]

const routePermissions: Record<string, [PermissionKey, AccessLevel?]> = {
  home: ['dashboard'], portal: ['profile'], 'portal-document-requests': ['profile'],
  clients: ['clients'], 'client-new': ['clients.create', 'write'], 'client-detail': ['clients'], 'client-edit': ['clients', 'write'],
  projects: ['projects'], 'project-new': ['projects.create', 'write'], 'project-detail': ['projects'], 'project-edit': ['projects', 'write'],
  invoices: ['invoices'], 'invoice-new': ['invoices.create', 'write'], 'invoice-detail': ['invoices'], 'invoice-edit': ['invoices', 'write'],
  // AI import vydané faktury jede na invoices.create (write) — stejný klíč kontroluje BE AiExtractPdfIssuedAction.
  'invoice-ai-import': ['invoices.create', 'write'],
  // Export/Import vydaných (reorg UX 2026-07) — nav pod Prodej, viz AppLayout.vue.
  'invoices-export': ['invoices'], 'invoices-import': ['invoices'],
  'purchase-invoices': ['purchase_invoices'], 'purchase-invoices-payment-orders': ['purchase_invoices.payment_orders'],
  'purchase-invoice-new': ['purchase_invoices.create', 'write'], 'purchase-invoice-detail': ['purchase_invoices'], 'purchase-invoice-edit': ['purchase_invoices', 'write'],
  // Export/Import přijatých (reorg UX 2026-07) — nav pod Nákup, viz AppLayout.vue.
  'purchase-invoices-export': ['purchase_invoices'], 'purchase-invoices-import': ['purchase_invoices'],
  // AI import jede na purchase_invoices.scan (write) — stejný klíč kontroluje BE
  // AiExtractPdfAction; readonly/client roli položka nesvítí a route ji nepustí.
  'purchase-invoice-ai-import': ['purchase_invoices.scan', 'write'],
  documents: ['documents'], 'document-detail': ['documents'], 'document-requests': ['documents.requests'],
  'accounting-accounts': ['accounting'], 'accounting-journal': ['accounting'], 'accounting-journal-new': ['accounting.journal.write', 'write'],
  // Čtení = zobrazení rozpadu mzdy; samotné zaúčtování hlídá server (accounting.journal.post).
  // Bez záznamu v téhle mapě guard route zahodí na homepage (deny-by-default, :327).
  'accounting-payroll': ['accounting'],
  'accounting-general-ledger': ['accounting'], 'accounting-trial-balance': ['accounting'], 'accounting-account-statement': ['accounting'],
  'accounting-balance-sheet': ['accounting'], 'accounting-income-statement': ['accounting'], 'accounting-income-statement-by-function': ['accounting'], 'accounting-saldo': ['accounting'],
  'accounting-document-completeness': ['accounting'],
  'accounting-balance-inventory': ['accounting'],
  'accounting-section18-statements': ['accounting'],
  'accounting-periods': ['accounting'],
  'accounting-monthly-check': ['accounting'], 'accounting-monthly-report': ['accounting'], 'accounting-offsets': ['accounting.offsets'],
  'accounting-tax-base-adjustments': ['accounting'],
  'manual-posting-queue': ['accounting'],
  'accounting-assets': ['assets'], 'accounting-asset-new': ['assets.write', 'write'], 'accounting-asset-detail': ['assets'], 'accounting-asset-edit': ['assets.write', 'write'],
  // Drobný majetek jede na `accounting`, ne na `assets` — musí sedět na RoutePermissionMap
  // na BE, kde /api/accounting/small-assets spadá pod fallback `accounting` (negativní
  // lookahead vylučuje jen `assets`, ne `small-assets`). Jiný klíč tady = menu svítí,
  // ale API vrátí 403.
  'accounting-small-assets': ['accounting'],
  'accounting-period-closing': ['accounting.periods.close'], 'accounting-closing-package': ['reports.export'], 'accounting-statement-notes': ['accounting'], 'accounting-retention': ['accounting'], 'accounting-transition-report': ['tax_evidence'], 'accounting-cash': ['cash'], 'accounting-cash-new': ['cash.document.write', 'write'], 'accounting-cash-book': ['cash'],
  'tax-evidence-cash-journal': ['tax_evidence'], 'tax-evidence-receivables-payables': ['tax_evidence'],
  'stock-items': ['stock'], 'stock-item-new': ['stock.items.write', 'write'], 'stock-item-detail': ['stock'], 'stock-item-edit': ['stock.items.write', 'write'],
  'stock-documents': ['stock'], 'stock-document-new': ['stock.documents.write', 'write'], 'stock-document-detail': ['stock'],
  'stock-warehouses': ['stock'], 'stock-takes': ['stock'], 'stock-take-detail': ['stock'], 'stock-reports': ['stock'], eshop: ['eshop'],
  logbook: ['logbook'], stats: ['dashboard'], 'purchase-stats': ['dashboard'], 'bank-statements': ['bank'], 'bank-detail': ['bank'],
  'admin-electronic-signatures': ['settings.signing', 'write'], templates: ['accounting.templates'], tools: ['utilities'], 'crm-dashboard': ['dashboard.portfolio'], 'portfolio-overview': ['dashboard.portfolio'],
  'automation-cockpit': ['accounting'],
  'admin-settings': ['settings.company.write', 'write'], 'admin-branding': ['settings.branding', 'write'], 'admin-integrations': ['settings.company.write', 'write'],
  'admin-price-list': ['settings.company.write', 'write'], 'admin-price-list-new': ['settings.company.write', 'write'], 'admin-price-list-edit': ['settings.company.write', 'write'],
  'accounting-activation': ['accounting.periods.manage', 'write'],
  'reports-dph': ['reports'], 'reports-kh': ['reports'], 'reports-dph-book': ['reports'], 'reports-s74b': ['reports'], 'reports-related-parties': ['reports'], 'reports-vat-coefficient': ['reports'], 'reports-s46': ['reports'], 'reports-vat-corrections': ['reports'], 'reports-shv': ['reports'], 'reports-oss': ['reports'],
  'reports-income-tax': ['reports'], 'reports-cnb-rate-audit': ['reports'], 'reports-submissions': ['reports'], 'reports-monthly-export': ['reports.export'], 'tax-optimizer': ['reports'], recurring: ['recurring'], 'recurring-new': ['recurring.create', 'write'],
  'recurring-detail': ['recurring'], 'recurring-edit': ['recurring', 'write'], 'profile-api-tokens': ['profile.tokens'], 'profile-shortcuts': ['profile', 'write'],
}

const superadminRouteNames = new Set([
  'activity-log', 'sent-emails', 'cron-jobs', 'admin-users', 'admin-roles', 'admin-suppliers',
  'admin-codebooks', 'admin-tax-constants', 'admin-email-templates', 'admin-emails', 'admin-approvals', 'admin-update',
  'admin-price-list', 'admin-price-list-new', 'admin-price-list-edit',
  'activation-license', 'activation-terms', 'activation-purchase',
])

// Routy, které projdou deny-by-default guardem (:361) bez permission meta jinak než
// přes routePermissions — musí být zrcadleny se `selfServiceRoute` v beforeEach.
const selfServiceRouteNames = new Set(['profile-password', 'setup-totp'])
const demoCreateRouteNames = new Set(['invoice-new', 'purchase-invoice-new', 'client-new', 'accounting-journal-new'])
const demoReadOnlyRouteNames = new Set(['admin-settings', 'admin-branding', 'admin-codebooks', 'admin-tax-constants'])
const commercialOnlyRouteNames = new Set([
  'accounting-activation',
  'automation-cockpit',
  'portfolio-overview',
  'reports-s74b',
  'reports-related-parties',
  'reports-vat-corrections',
  'reports-submissions',
  'templates',
  'tools',
])

function applyAuthorizationMeta(records: RouteRecordRaw[], inheritedRequiresAuth = false): void {
  for (const record of records) {
    const name = typeof record.name === 'string' ? record.name : ''
    const requiresAuth = inheritedRequiresAuth || !!record.meta?.requiresAuth
    if (superadminRouteNames.has(name)) record.meta = { ...record.meta, superadminOnly: true }
    if (record.meta?.requiresDoubleEntry || record.meta?.requiresStock || commercialOnlyRouteNames.has(name)) {
      record.meta = { ...record.meta, commercialOnly: true }
    }
    const rule = routePermissions[name]
    if (rule) {
      const mayRenderWithoutSupplier = rule[0] === 'dashboard' || rule[0] === 'profile' || rule[0] === 'profile.tokens'
      record.meta = { ...record.meta, permission: rule[0], access: rule[1] ?? 'read', requiresSupplier: !mayRenderWithoutSupplier }
    }
    // Dev-time pojistka proti P1.5b (5b v REAL_data_followup_UX.md): route bez záznamu
    // v routePermissions projde deny-by-default guardem tiše na homepage — bez chyby,
    // bez logu. Upozorni na to hned při startu, ne až po hodině hledání v produkci.
    if (import.meta.env.DEV && name && requiresAuth && !rule
      && !superadminRouteNames.has(name) && !selfServiceRouteNames.has(name)
      && !record.meta?.public && !record.redirect) {
      console.warn(`[router] Route "${name}" nemá záznam v routePermissions ani superadminOnly/self-service výjimku — deny-by-default guard ji bude tiše přesměrovávat na homepage/portal.`)
    }
    if (record.children) applyAuthorizationMeta(record.children, requiresAuth)
  }
}
applyAuthorizationMeta(routes)

export const router = createRouter({
  history: createWebHistory(),
  routes,
  // Scroll-to-top při navigaci sidebar linky; respektuj #hash a back/forward
  scrollBehavior(_to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (_to.hash) return { el: _to.hash, behavior: 'smooth' }
    return { top: 0, left: 0 }
  },
})

/**
 * Bezpečný fallback při zamítnutí. Vrací `true` (pusť dál), když už cílíme na tu
 * samou route — jinak vznikne NEKONEČNÁ smyčka.
 *
 * Reálně nastalo: ne-superadmin bez řádku v `user_suppliers` je na backendu
 * fail-closed (SupplierAccessResolver → denied), takže `/me` vrátí PRÁZDNÁ práva.
 * Tím propadne i `dashboard` na route `home`, guard přesměruje na `home`, ta se
 * zamítne znovu… a záložka zamrzne bez jediné hlášky. Radši pustit dál a nechat
 * stránku zobrazit prázdný stav / chybu z API než točit prohlížeč donekonečna.
 */
function denyFallback(toName: unknown, auth: ReturnType<typeof useAuthStore>) {
  const target = auth.isClientRole ? 'portal' : 'home'
  return toName === target ? true : { name: target }
}

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.setupStatus === null) {
    try {
      await auth.fetchSetupStatus()
    } catch {
      // ignore
    }
  }

  if (auth.needsSetup && to.name !== 'setup') {
    return { name: 'setup' }
  }
  if (!auth.needsSetup && to.name === 'setup') {
    return { name: 'login' }
  }

  const requiresAuth = to.matched.some((r) => r.meta.requiresAuth)
  if (requiresAuth && !auth.isAuthenticated) {
    // Rozhoduje stav storu, ne návratová hodnota: refresh() při síťovém výpadku
    // vrací false, ale známou identitu si záměrně drží.
    await auth.refresh()
    if (!auth.isAuthenticated) return { name: 'login' }
  }
  if (requiresAuth && auth.lockedSession) {
    useSessionSecurityStore().apply(auth.lockedSession)
    return true
  }
  if (requiresAuth) {
    const sessionSecurity = useSessionSecurityStore()
    if (sessionSecurity.state === null) {
      await sessionSecurity.refresh()
    }
  }

  // Setup session nemá přístup k business routám, dokud uživatel nedokončí MFA.
  const mustSetupMfa = auth.mustSetupMfa || auth.mustSetupTotp
  if (auth.isAuthenticated && mustSetupMfa && to.name !== 'setup-mfa' && requiresAuth) {
    return { name: 'setup-mfa' }
  }
  if (auth.isAuthenticated && !mustSetupMfa && to.name === 'setup-mfa') {
    return { name: 'home' }
  }

  if (auth.isClientRole && to.name === 'home') {
    return { name: 'portal' }
  }

  const superadminOnly = to.matched.some((r) => r.meta.superadminOnly)
  if (superadminOnly && !auth.isSuperadmin) {
    const demoReadOnlyRoute = auth.isDemo && typeof to.name === 'string' && demoReadOnlyRouteNames.has(to.name)
    if (!demoReadOnlyRoute) return denyFallback(to.name, auth)
  }

  const permissionMeta = [...to.matched].reverse().find(r => r.meta.permission)?.meta
  if (permissionMeta?.permission && !auth.can(permissionMeta.permission, permissionMeta.access ?? 'read')) {
    const demoCreateRoute = auth.isDemo && typeof to.name === 'string' && demoCreateRouteNames.has(to.name)
    const demoReadOnlyRoute = auth.isDemo && typeof to.name === 'string' && demoReadOnlyRouteNames.has(to.name)
    if (!demoCreateRoute && !demoReadOnlyRoute) return denyFallback(to.name, auth)
  }
  const selfServiceRoute = typeof to.name === 'string' && selfServiceRouteNames.has(to.name)
  if (requiresAuth && !permissionMeta?.permission && !superadminOnly && !selfServiceRoute) {
    return denyFallback(to.name, auth)
  }

  const commercialOnly = to.matched.some((r) => r.meta.commercialOnly)
  if (commercialOnly && !auth.hasCommercialFeatures) {
    return auth.isSuperadmin ? { name: 'activation-license' } : denyFallback(to.name, auth)
  }

  // Onboarding gate: pokud uživatel v úvodním nastavení přeskočil dodavatele, nemá v DB
  // žádného supplier-a. Data (klienti, faktury, currencies) jsou supplier-scoped, takže
  // zakládací formuláře by jinak spadly na matoucí „Validace selhala" (#151). Místo toho
  // ho pošleme na dashboard, kde se zobrazí výzva k vytvoření prvního dodavatele.
  // Klient bez membershipu (žádná firma) končí na /portal s empty state
  // „kontaktujte svou účetní" — NE na dashboardu s výzvou „vytvořte dodavatele".
  const requiresSupplier = to.matched.some((r) => r.meta.requiresSupplier)
  if (requiresSupplier && auth.isAuthenticated && !useSupplierStore().hasSupplier) {
    return denyFallback(to.name, auth)
  }

  const requiresOss = to.matched.some((r) => r.meta.requiresOss)
  if (requiresOss && auth.isAuthenticated && useSupplierStore().currentSupplier?.oss_enabled !== true) {
    return { name: 'home' }
  }

  // Účetnictví (Epic F1) je dostupné jen firmám v režimu podvojného účetnictví.
  // Nav sekci gatuje AppLayout; tady tvrdě blokujeme i přímý přístup přes URL.
  const requiresDoubleEntry = to.matched.some((r) => r.meta.requiresDoubleEntry)
  if (requiresDoubleEntry && useSupplierStore().currentSupplier?.accounting_mode !== 'double_entry') {
    return { name: 'home' }
  }

  // Daňová evidence (Epic DE) je dostupná jen firmám v režimu daňové evidence.
  // Nav sekci gatuje AppLayout; tady tvrdě blokujeme i přímý přístup přes URL (zrcadlo double_entry).
  const requiresTaxEvidence = to.matched.some((r) => r.meta.requiresTaxEvidence)
  if (requiresTaxEvidence && useSupplierStore().currentSupplier?.accounting_mode !== 'tax_evidence') {
    return { name: 'home' }
  }

  // Pokladna (Epic DE §6) je dostupná v OBOU účetních režimech (double_entry i tax_evidence).
  // Nav položku gatuje AppLayout; tady jen zajistíme, že firma má některý z účetních režimů.
  const requiresCashMode = to.matched.some((r) => r.meta.requiresCashMode)
  if (requiresCashMode) {
    const mode = useSupplierStore().currentSupplier?.accounting_mode
    if (mode !== 'double_entry' && mode !== 'tax_evidence') {
      return { name: 'home' }
    }
  }

  // Sklad (Epic SKLAD) je dostupný jen firmám s zapnutou skladovou evidencí.
  // Nav sekci gatuje AppLayout; tady tvrdě blokujeme i přímý přístup přes URL.
  const requiresStock = to.matched.some((r) => r.meta.requiresStock)
  if (requiresStock && !useSupplierStore().currentSupplier?.stock_enabled) {
    return { name: 'home' }
  }

  // Upstreamový ceník je fallback pro firmy bez našeho skladu/e-shopu. Jakmile je
  // stock_enabled aktivní, zdrojem položek jsou výhradně skladové karty.
  const requiresNoStock = to.matched.some((r) => r.meta.requiresNoStock)
  if (requiresNoStock && useSupplierStore().currentSupplier?.stock_enabled) {
    return { name: 'home' }
  }

  return true
})
