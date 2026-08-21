<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Repository saldokonta (audit 2026-07, nález H13 — fáze D6/1). Otevřené položky
 * účtů pohledávek/závazků (311/321/314/324…) dovozené z faktur přes vazbu
 * hlavičky zápisu (journal_entries.source_type/source_id → invoices/purchase_invoices).
 *
 * FÁZE 1: partner je dovoditelný jen u AUTOMATICKY účtovaných dokladů
 * (source_type invoice/purchase_invoice); ruční a bankovní zápisy na saldokontní
 * účet partnera nenesou a spadnou do „nespárovaného zbytku" (rozdíl konfrontace).
 * Partner dimenze přímo na journal_entry_lines je fáze 2 (mimo scope).
 *
 * Návratové hodnoty jsou SIGNED (debet mínus kredit, bez abs()) — orientaci na
 * normální stranu účtu (kladné = pohledávka/závazek) dělá až SaldoService, stejně
 * jako už dělá pro `gl_balance`. Díky tomu se dobropis (opačná strana účtu) v netto
 * součtu partnera správně ODEČÍTÁ, ne přičítá (post-review fix H1 — abs() by
 * dobropis proměnil v další kladnou pohledávku místo odpočtu).
 *
 * `paid_ratio` (0..1) je dopočten PER DOKLAD K ASOF ze zdroje pravdy platby daného typu
 * dokladu (post-review fix H2 — dřívější `payment_matches` pokrývalo jen bankovní
 * úhrady, hotovostní/ruční úhrady faktur zůstávaly nezapočtené a doklad vypadal
 * jako plně otevřený i po zaplacení):
 *   - invoices: suma `invoice_payments.amount` s `paid_on <= asOf` vůči
 *     `amount_to_pay` (obojí v MĚNĚ FAKTURY — poměr je tedy stejnoměnný,
 *     bez míchání CZK zaúčtované hodnoty s cizoměnovou platbou).
 *   - purchase_invoices: nemají obdobu `invoice_payments` — `status='paid'`
 *     je plně krytý až od `paid_at`
 *     (nastaví ho i hotovostní úhrada přes CashDocumentService::applySideEffects)
 *     = plně kryto; jinak se poměr skládá ze Σ `payment_matches.amount` (banka)
 *     a Σ obou ZÁPOČTOVÝCH cest k asOf ({@see \MyInvoice\Support\Sql\PurchaseSettledExpr::offsetSettledAsOf}
 *     — `offset_agreement_items` a `invoice_settlements`). Na `status='paid'` se
 *     u zápočtu spolehnout NELZE: doúčtování zápočtu bez účetní stopy stav dokladu
 *     záměrně nepřestavuje (`InvoiceSettlementService::postRow`) a ČÁSTEČNÝ zápočet
 *     doklad na `paid` nepřeklápí vůbec — bez téhle složky by vyrovnaná faktura
 *     svítila jako celá otevřená a částečně započtená celou částkou místo zbytkem.
 *     KNOWN GAP (H3): `payment_matches.amount` je uložen
 *     v MĚNĚ TRANSAKCE (StatementMatcher::matchPurchase ukládá `$absAmount` bez
 *     převodu na měnu PF), ne nutně v měně PF — u cizoměnové PF s ČÁSTEČNOU
 *     bankovní úhradou proto může poměr vyjít nepřesně (zůstane vidět jako
 *     rozdíl v konfrontaci, ne tiše špatně). Plná/hotovostní úhrada (přes
 *     `status='paid'`) tímto zkreslením netrpí.
 *
 * DATUM VYROVNÁNÍ musí být totéž, které zná HLAVNÍ KNIHA (jinak konfrontace nesedí):
 * u bankovních úhrad se proto NEBERE `invoice_payments.paid_on` / `purchase_invoices.paid_at`
 * (den z dokladu, u legacy importů běžně den vystavení faktury), ale `entry_date`
 * ZAÚČTOVANÉHO bankovního zápisu, s fallbackem na `bank_transactions.posted_at` a teprve
 * pak na doklad. Lednový výpis k prosincové faktuře tak fakturu k 31. 12. NESKRYJE —
 * HK ji k tomu dni také má otevřenou. Nezaúčtovaný výpis fallbackem propadne na datum
 * pohybu, takže se v konfrontaci projeví jako ROZDÍL — což je žádoucí, je to nález
 * („banka není zaúčtovaná"), ne chyba sestavy.
 *
 * ZÁLOHA INKASOVANÁ PŘÍMO NA SALDOKONTNÍ ÚČET (bez 324/314) — viz
 * {@see advanceOnAccountSql}: proforma je samostatný doklad a platba míří na NI, ne na
 * finální fakturu; když ji účetní účtuje rovnou na 311, vypadá plně předplacená faktura
 * jako celá otevřená. Poddotaz proto přičte takovou zálohu do čitatele i jmenovatele
 * poměru — a protože je podmíněný tím, že úhrada zálohy dopadla NA TENHLE účet, tenantů
 * používajících 324/314 se nedotkne.
 *
 * Storno (H4): dřívější filtr `reversed_by IS NULL` odrážel AKTUÁLNÍ stav, ne stav
 * K ASOF — doklad stornovaný AŽ PO rozvahovém dni by k asOf zmizel ze seznamu,
 * přestože k tomu dni byl v hlavní knize ještě živý. Filtrujeme proto podle
 * `entry_date` protizápisu (storno platí, jen když jeho zápis má
 * `entry_date <= asOf`), shodně s tím, jak časovou platnost storna řeší hlavní
 * kniha (LedgerReportRepository počítá vše přes `entry_date`, ne přes flag).
 *
 * Storno RUČNÍM protidokladem (H4b): doklad může být účetně vyrušen i zrcadlovým
 * RUČNÍM zápisem (source_type='manual'), který VĚDOMĚ nechává původní kontaci živou
 * (oba zápisy live → HK i VH netují na 0, `reversed_by` se ZÁMĚRNĚ nenastavuje, aby
 * dotazy `reversed_by IS NULL` nevyloučily jen jednu stranu a nerozbily VH). Takový
 * protidoklad ale nenese `source_type='invoice'/'purchase_invoice'`, takže se na úrovni
 * DOKLADU s původní fakturou v saldu neztuluje a faktura by svítila jako otevřená.
 * Řešíme ČASOVĚ UVĚDOMĚLE přes `cancelled_at` jako den vyrovnání (settlement date):
 * stornovaný doklad je otevřený k asOf < cancelled_at a uzavřený k asOf >= cancelled_at.
 * Filtr je pro NORMÁLNĚ stornované doklady no-op — ty už vyloučil `reversed_by` výše
 * (běžný storno reverzuje kontaci k `entry_date` originálu, tedy DŘÍV než cancelled_at),
 * takže bije jen na ručně vyrovnané anomálie, kde je kontace živá. `cancelled_at IS NULL`
 * (H4 scénář reverzí bez timestampu) filtr nechává beze změny — vyřeší ho `reversed_by`.
 */
final class SaldoRepository
{
    /**
     * Den, ke kterému HLAVNÍ KNIHA uznává bankovní úhradu přijaté faktury (`payment_matches`
     * ⇄ `bank_transactions bt`): `entry_date` zaúčtovaného bankovního zápisu, a když zápis
     * neexistuje (nezaúčtovaný výpis), fallback na datum pohybu. Právě tenhle den, ne
     * `paid_at`/`paid_on` z dokladu, řídí, kdy z účtu 321 mizí závazek — konfrontace se
     * zůstatkem HK proto musí počítat se stejným datem. Obsahuje JEDEN placeholder (asOf
     * pro časově uvědomělé storno protizápisem).
     */
    private const MATCH_SETTLEMENT_DATE =
        "COALESCE(
            (SELECT MIN(pbe.entry_date)
               FROM journal_entries pbe
               LEFT JOIN journal_entries pbrev ON pbrev.id = pbe.reversed_by
              WHERE pbe.supplier_id = pm.supplier_id
                AND pbe.source_type = 'bank' AND pbe.source_id = pm.bank_transaction_id
                AND pbe.posted_at IS NOT NULL
                AND (pbe.reversed_by IS NULL OR pbrev.entry_date > ?)),
            DATE(bt.posted_at)
        )";

    public function __construct(private readonly Connection $db) {}

    /**
     * Syntetický (nebo listový) účet firmy dle kódu. Vrací i normal_side a typ pro
     * určení strany zůstatku. NULL = účet v osnově firmy neexistuje.
     *
     * @return array{id:int, code:string, name:string, account_type:string, normal_side:?string}|null
     */
    public function resolveAccount(int $supplierId, string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, account_code, name, account_type, normal_side
               FROM chart_of_accounts
              WHERE supplier_id = ? AND account_code = ?
              ORDER BY is_synthetic DESC, id
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'           => (int) $row['id'],
            'code'         => (string) $row['account_code'],
            'name'         => (string) $row['name'],
            'account_type' => (string) $row['account_type'],
            'normal_side'  => $row['normal_side'] === null ? null : (string) $row['normal_side'],
        ];
    }

    /**
     * Otevřené položky účtu k rozvahovému dni: faktury (vydané + přijaté) zaúčtované
     * na daný účet (vč. analytik pod syntetikou) s vazbou přes source_type/source_id.
     * `booked_signed`/`foreign_signed` = SUM(debit − credit) za doklad, SIGNED (viz
     * doc-komentář třídy). `paid_ratio` viz tamtéž. Filtrace na otevřené (netto
     * remaining ≠ 0) dělá služba.
     *
     * @return list<array{doc_type:string, doc_id:int, doc_no:string, issue_date:string,
     *                    due_date:string, status:string, partner_id:int, partner_name:string,
     *                    currency_code:string, booked_signed:float, foreign_signed:float,
     *                    paid_ratio:float}>
     */
    public function openItems(int $supplierId, int $accountId, string $asOf, ?string $accountCode = null): array
    {
        if ($accountCode === '324') {
            return $this->fetchReceivedAdvances($supplierId, $accountId, $asOf);
        }
        if ($accountCode === '314') {
            return $this->fetchPaidAdvances($supplierId, $accountId, $asOf);
        }
        return array_merge(
            $this->fetchOpenInvoices($supplierId, $accountId, $asOf),
            $this->fetchOpenPurchases($supplierId, $accountId, $asOf),
        );
    }

    /**
     * Přijaté zálohy na 324 vznikají peněžním zápisem banky/pokladny, nikoli
     * předpisem proformy. Otevřenou položkou je proto zaúčtované inkaso proformy
     * snížené o čerpání 324 z DDKP a vyúčtovacích faktur navázaných přes
     * parent_invoice_id. Odvození jen z invoice journalu by ukázalo samotné čerpání
     * jako zápornou otevřenou položku a zcela minulo původní 221/324.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchReceivedAdvances(int $supplierId, int $accountId, string $asOf): array
    {
        $sql =
            "WITH params AS (
                SELECT CAST(? AS UNSIGNED) AS supplier_id,
                       CAST(? AS DATE) AS as_of,
                       CAST(? AS UNSIGNED) AS account_id
            ), collected AS (
                SELECT ip.invoice_id, SUM(ip.amount) AS collected_czk
                  FROM invoice_payments ip
                  CROSS JOIN params x
                 WHERE ip.supplier_id = x.supplier_id AND ip.paid_on <= x.as_of
                   AND (
                       EXISTS (
                           SELECT 1
                             FROM journal_entries e
                             JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                             JOIN chart_of_accounts ca ON ca.id = l.account_id
                             LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                            WHERE ip.bank_transaction_id IS NOT NULL
                              AND e.supplier_id = ip.supplier_id
                              AND e.source_type = 'bank' AND e.source_id = ip.bank_transaction_id
                              AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                              AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                              AND l.side = 'credit'
                              AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                       )
                       OR EXISTS (
                           SELECT 1
                             FROM cash_documents cd
                             JOIN journal_entries e
                               ON e.supplier_id = cd.supplier_id AND e.source_type = 'cash' AND e.source_id = cd.id
                             JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                             JOIN chart_of_accounts ca ON ca.id = l.account_id
                             LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                            WHERE cd.supplier_id = ip.supplier_id AND cd.invoice_payment_id = ip.id
                              AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                              AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                              AND l.side = 'credit'
                              AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                       )
                   )
                 GROUP BY ip.invoice_id
            ), settled AS (
                SELECT child.parent_invoice_id AS invoice_id, SUM(l.amount) AS settled_czk
                  FROM invoices child
                  CROSS JOIN params x
                  JOIN journal_entries e
                    ON e.supplier_id = child.supplier_id AND e.source_type = 'invoice' AND e.source_id = child.id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE child.supplier_id = x.supplier_id AND child.parent_invoice_id IS NOT NULL
                   AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                   AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                   AND l.side = 'debit'
                   AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                 GROUP BY child.parent_invoice_id
            )
            SELECT p.id AS doc_id,
                   COALESCE(NULLIF(p.varsymbol, ''), CONCAT('#', p.id)) AS doc_no,
                   p.issue_date, p.due_date, p.status,
                   cl.id AS partner_id, cl.company_name AS partner_name,
                   cur.code AS currency_code,
                   c.collected_czk, COALESCE(s.settled_czk, 0) AS settled_czk
              FROM collected c
              JOIN invoices p ON p.id = c.invoice_id
              JOIN params x ON x.supplier_id = p.supplier_id
              JOIN clients cl ON cl.id = p.client_id
              JOIN currencies cur ON cur.id = p.currency_id
              LEFT JOIN settled s ON s.invoice_id = p.id
             WHERE p.invoice_type = 'proforma'
             ORDER BY cl.company_name, p.due_date, p.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $asOf, $accountId]);

        return array_map(function (array $r): array {
            $collected = round((float) $r['collected_czk'], 2);
            $settled = round((float) $r['settled_czk'], 2);
            return [
                'doc_type'       => 'invoice',
                'doc_id'         => (int) $r['doc_id'],
                'doc_no'         => (string) $r['doc_no'],
                'issue_date'     => (string) $r['issue_date'],
                'due_date'       => (string) $r['due_date'],
                'status'         => (string) $r['status'],
                'partner_id'     => (int) $r['partner_id'],
                'partner_name'   => (string) $r['partner_name'],
                'currency_code'  => (string) $r['currency_code'],
                'booked_signed'  => -$collected,
                'foreign_signed' => 0.0,
                'paid_ratio'     => $collected > 0.0 ? min(1.0, max(0.0, $settled / $collected)) : 0.0,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Poskytnuté zálohy na 314: bankovní/pokladní platba zálohové přijaté
     * faktury snížená o zúčtování 321/314 z finální přijaté faktury.
     *
     * Otevřenou položkou je i SAMOSTATNÝ DDKP (`tax_document` BEZ zálohové faktury) —
     * typicky nákup zaplacený kartou, kde prodejce vystaví jen „daňový doklad ke dni
     * přijaté úplaty" (§ 28/8) a fakturu pošle až s dodáním. `BankPostingService::
     * outgoingCounterAccount()` jeho úhradu ZÁMĚRNĚ účtuje na 314 (§ 20a/2), jenže
     * tahle metoda dřív brala jen `document_kind='advance'`, takže doklad ze saldokonta
     * vypadl CELÝ — debet z platby i vlastní kredit DPH. Hlavní kniha ho přitom měla,
     * takže konfrontace ukazovala rozdíl o zaplacenou částku sníženou o daň, aniž by
     * bylo z čeho poznat proč.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchPaidAdvances(int $supplierId, int $accountId, string $asOf): array
    {
        $sql =
            "WITH params AS (
                SELECT CAST(? AS UNSIGNED) AS supplier_id,
                       CAST(? AS DATE) AS as_of,
                       CAST(? AS UNSIGNED) AS account_id
            ), paid AS (
                SELECT advance_id, SUM(paid_czk) AS paid_czk
                  FROM (
                      SELECT pm.purchase_invoice_id AS advance_id, pm.amount AS paid_czk
                        FROM payment_matches pm
                        CROSS JOIN params x
                        JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                       WHERE pm.supplier_id = x.supplier_id AND pm.purchase_invoice_id IS NOT NULL
                         AND DATE(bt.posted_at) <= x.as_of
                         AND EXISTS (
                             SELECT 1
                               FROM journal_entries e
                               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                               JOIN chart_of_accounts ca ON ca.id = l.account_id
                               LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                              WHERE e.supplier_id = pm.supplier_id
                                AND e.source_type = 'bank' AND e.source_id = bt.id
                                AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                                AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                                AND l.side = 'debit'
                                AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                         )
                      UNION ALL
                      SELECT cd.purchase_invoice_id AS advance_id, cd.total_amount AS paid_czk
                        FROM cash_documents cd
                        CROSS JOIN params x
                       WHERE cd.supplier_id = x.supplier_id AND cd.purchase_invoice_id IS NOT NULL
                         AND cd.issue_date <= x.as_of
                         AND EXISTS (
                             SELECT 1
                               FROM journal_entries e
                               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                               JOIN chart_of_accounts ca ON ca.id = l.account_id
                               LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                              WHERE e.supplier_id = cd.supplier_id
                                AND e.source_type = 'cash' AND e.source_id = cd.id
                                AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                                AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                                AND l.side = 'debit'
                                AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                         )
                  ) movements
                 GROUP BY advance_id
            ), settled AS (
                -- Čerpání zálohy má TŘI vazební cesty a všechny musí do součtu:
                --   advance_purchase_invoice_id — vyúčtovací faktura (321 MD / 314 D),
                --   parent_purchase_invoice_id  — přijatý DDKP § 28 (343 MD / 314 D),
                --   samostatný DDKP bez rodiče  — čerpá SÁM SEBE (343 MD / 314 D).
                -- DDKP první cestu použít NEMŮŽE: nad advance_purchase_invoice_id je
                -- UNIQUE index (jedna záloha = jedna vyúčtovací faktura). Bez druhé větve
                -- proto kredit DDKP na 314 vypadl a záloha svítila jako otevřená o celou
                -- částku DPH navíc. Vydaná větev (324) tenhle problém nemá — používá
                -- obecné parent_invoice_id IS NOT NULL, které chytí DDKP i finál.
                -- Podmínka advance_purchase_invoice_id IS NULL v druhé větvi brání dvojímu
                -- započtení, kdyby jeden doklad nesl obě vazby.
                SELECT link.advance_id, SUM(l.amount) AS settled_czk
                  FROM (
                      SELECT id AS child_id, supplier_id, advance_purchase_invoice_id AS advance_id
                        FROM purchase_invoices
                       WHERE advance_purchase_invoice_id IS NOT NULL
                      UNION ALL
                      SELECT id, supplier_id, parent_purchase_invoice_id
                        FROM purchase_invoices
                       WHERE document_kind = 'tax_document'
                         AND parent_purchase_invoice_id IS NOT NULL
                         AND advance_purchase_invoice_id IS NULL
                      UNION ALL
                      -- Samostatný DDKP je sám sobě zálohou i jejím čerpáním: na 314 mu
                      -- sedí debet z úhrady a kredit vlastní daně, zbytek (základ) čeká
                      -- na konečnou fakturu. Bez téhle větve by svítil jako otevřený
                      -- o celou zaplacenou částku včetně daně, kterou už odečetl.
                      SELECT id, supplier_id, id
                        FROM purchase_invoices
                       WHERE document_kind = 'tax_document'
                         AND parent_purchase_invoice_id IS NULL
                         AND advance_purchase_invoice_id IS NULL
                  ) link
                  CROSS JOIN params x
                  JOIN journal_entries e
                    ON e.supplier_id = link.supplier_id
                   AND e.source_type = 'purchase_invoice' AND e.source_id = link.child_id
                  JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
                  LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
                 WHERE link.supplier_id = x.supplier_id
                   AND e.posted_at IS NOT NULL AND e.entry_date <= x.as_of
                   AND (e.reversed_by IS NULL OR rev.entry_date > x.as_of)
                   AND l.side = 'credit'
                   AND (ca.id = x.account_id OR ca.parent_id = x.account_id)
                 GROUP BY link.advance_id
            )
            SELECT p.id AS doc_id,
                   COALESCE(NULLIF(p.vendor_invoice_number, ''), NULLIF(p.varsymbol, ''), CONCAT('#', p.id)) AS doc_no,
                   p.issue_date, p.due_date, p.status,
                   cl.id AS partner_id, cl.company_name AS partner_name,
                   cur.code AS currency_code,
                   paid.paid_czk, COALESCE(s.settled_czk, 0) AS settled_czk
              FROM paid
              JOIN purchase_invoices p ON p.id = paid.advance_id
              JOIN params x ON x.supplier_id = p.supplier_id
              JOIN clients cl ON cl.id = p.vendor_id
              JOIN currencies cur ON cur.id = p.currency_id
              LEFT JOIN settled s ON s.advance_id = p.id
             WHERE p.document_kind = 'advance'
                OR (p.document_kind = 'tax_document' AND p.parent_purchase_invoice_id IS NULL)
             ORDER BY cl.company_name, p.due_date, p.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $asOf, $accountId]);

        return array_map(function (array $r): array {
            $paid = round((float) $r['paid_czk'], 2);
            $settled = round((float) $r['settled_czk'], 2);
            return [
                'doc_type'       => 'purchase_invoice',
                'doc_id'         => (int) $r['doc_id'],
                'doc_no'         => (string) $r['doc_no'],
                'issue_date'     => (string) $r['issue_date'],
                'due_date'       => (string) $r['due_date'],
                'status'         => (string) $r['status'],
                'partner_id'     => (int) $r['partner_id'],
                'partner_name'   => (string) $r['partner_name'],
                'currency_code'  => (string) $r['currency_code'],
                'booked_signed'  => $paid,
                'foreign_signed' => 0.0,
                'paid_ratio'     => $paid > 0.0 ? min(1.0, max(0.0, $settled / $paid)) : 0.0,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchOpenInvoices(int $supplierId, int $accountId, string $asOf): array
    {
        $sql =
            "SELECT d.id AS doc_id,
                    COALESCE(NULLIF(d.varsymbol, ''), CONCAT('#', d.id)) AS doc_no,
                    d.issue_date, d.due_date, d.status,
                    cl.id AS partner_id, cl.company_name AS partner_name,
                    cur.code AS currency_code,
                    d.amount_to_pay,
                    (SELECT COALESCE(SUM(ip.amount), 0)
                       FROM invoice_payments ip
                       LEFT JOIN bank_transactions ipbt ON ipbt.id = ip.bank_transaction_id
                      WHERE ip.supplier_id = d.supplier_id AND ip.invoice_id = d.id
                        AND COALESCE(
                                (SELECT MIN(be.entry_date)
                                   FROM journal_entries be
                                   LEFT JOIN journal_entries brev ON brev.id = be.reversed_by
                                  WHERE be.supplier_id = ip.supplier_id
                                    AND be.source_type = 'bank'
                                    AND be.source_id = ip.bank_transaction_id
                                    AND be.posted_at IS NOT NULL
                                    AND (be.reversed_by IS NULL OR brev.entry_date > ?)),
                                DATE(ipbt.posted_at),
                                ip.paid_on
                            ) <= ?) AS paid_as_of,
                    " . $this->advanceOnAccountSql('credit') . " AS advance_on_account,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS booked_signed,
                    SUM(CASE WHEN l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                             THEN (CASE WHEN l.side = 'debit' THEN l.amount_foreign ELSE -l.amount_foreign END)
                             ELSE 0 END) AS foreign_signed
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
               LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
               JOIN invoices d      ON d.id = e.source_id AND d.supplier_id = e.supplier_id
               JOIN clients cl      ON cl.id = d.client_id
               JOIN currencies cur  ON cur.id = d.currency_id
              WHERE e.supplier_id = ? AND e.source_type = 'invoice'
                AND e.posted_at IS NOT NULL
                AND e.entry_date <= ?
                AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                AND (ca.id = ? OR ca.parent_id = ?)
                AND d.status <> 'draft'
                AND (d.status <> 'cancelled' OR d.cancelled_at IS NULL OR DATE(d.cancelled_at) > ?)
              GROUP BY d.id, doc_no, d.issue_date, d.due_date, d.status,
                       cl.id, cl.company_name, cur.code, d.amount_to_pay
              ORDER BY cl.company_name, d.due_date, d.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $asOf, $asOf,                       // paid_as_of (storno guard + settlement date)
            $asOf, $asOf, $accountId, $accountId, // advance_on_account
            $supplierId, $asOf, $asOf, $accountId, $accountId, $asOf,
        ]);

        return array_map(function (array $r): array {
            // Předplacení z proformy uhrazené PŘÍMO na tenhle účet (viz advanceOnAccountSql):
            // vstupuje do čitatele i jmenovatele poměru. `amount_to_pay` finální faktury je
            // o zálohu snížené (u plně předplacené je nulové), takže bez téhle korekce vyjde
            // poměr 0 (nebo 1 z nesouvisejícího zbytku) a doklad svítí jako celý otevřený.
            $advance = round((float) $r['advance_on_account'], 2);
            return [
                'doc_type'       => 'invoice',
                'doc_id'         => (int) $r['doc_id'],
                'doc_no'         => (string) $r['doc_no'],
                'issue_date'     => (string) $r['issue_date'],
                'due_date'       => (string) $r['due_date'],
                'status'         => (string) $r['status'],
                'partner_id'     => (int) $r['partner_id'],
                'partner_name'   => (string) $r['partner_name'],
                'currency_code'  => (string) $r['currency_code'],
                'booked_signed'  => round((float) $r['booked_signed'], 2),
                'foreign_signed' => round((float) $r['foreign_signed'], 2),
                // Stejnoměnný poměr k asOf; invoice_payments pokrývá bankovní,
                // hotovostní i ruční platby jednotně.
                'paid_ratio'     => $this->paidRatio(
                    false,
                    (float) $r['paid_as_of'] + $advance,
                    (float) $r['amount_to_pay'] + $advance,
                ),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Korelovaný poddotaz: kolik z dokladu `d` je předplaceno ZÁLOHOU (proformou /
     * zálohovou PF), jejíž úhrada je zaúčtovaná PŘÍMO NA TENHLE saldokontní účet —
     * tedy bez průchodu přes 324/314.
     *
     * Proč to musí být: proforma je samostatný doklad (`invoices.invoice_type='proforma'`,
     * resp. `purchase_invoices.document_kind='advance'`) a finální faktura na ni ukazuje
     * přes `parent_invoice_id` / `advance_purchase_invoice_id`. `invoice_payments.invoice_id`
     * i `payment_matches.purchase_invoice_id` míří na ZÁLOHU, ne na finální doklad. Účetní,
     * která inkaso proformy účtuje rovnou na 311 (a ne na 324), tak dostane finální fakturu
     * se `amount_to_pay = 0`, žádnou vlastní platbou a plným předpisem na 311 → doklad
     * svítí jako celý otevřený, přestože hlavní kniha ho má vyrovnaný.
     *
     * Podmínka „úhrada zálohy je zaúčtovaná na TENHLE účet" je zároveň rozlišovací znak
     * proti tenantům, kteří 324/314 POUŽÍVAJÍ: tam inkaso kredituje 324 (ne 311) a finální
     * faktura si zálohu zúčtuje sama zápisem 324 MD / 311 D ve VLASTNÍM zápisu, takže
     * `booked_signed` už přichází netto. Pro ně vyjde tenhle poddotaz 0 a chování se nemění.
     *
     * @param 'credit'|'debit' $side strana, na kterou úhrada zálohy dopadá na daném účtu
     *                               (311 pohledávka → credit, 321 závazek → debit)
     */
    private function advanceOnAccountSql(string $side): string
    {
        $link = $side === 'credit'
            ? "pf.id = d.parent_invoice_id AND pf.invoice_type = 'proforma'"
            : "pf.id = d.advance_purchase_invoice_id AND pf.document_kind = 'advance'";
        $payments = $side === 'credit'
            ? "JOIN invoice_payments pip ON pip.supplier_id = pf.supplier_id AND pip.invoice_id = pf.id"
            : "JOIN payment_matches pip ON pip.supplier_id = pf.supplier_id AND pip.purchase_invoice_id = pf.id";
        $table = $side === 'credit' ? 'invoices' : 'purchase_invoices';
        $cashLink = $side === 'credit'
            ? 'cd.invoice_payment_id = pip.id'
            : 'cd.purchase_invoice_id = pf.id';

        return
            "(SELECT COALESCE(SUM(pip.amount), 0)
                FROM {$table} pf
                {$payments}
               WHERE {$link} AND pf.supplier_id = d.supplier_id
                 AND EXISTS (
                     SELECT 1
                       FROM journal_entries pe
                       JOIN journal_entry_lines pl ON pl.entry_id = pe.id AND pl.supplier_id = pe.supplier_id
                       JOIN chart_of_accounts pca ON pca.id = pl.account_id
                       LEFT JOIN journal_entries prev ON prev.id = pe.reversed_by
                      WHERE pe.supplier_id = pip.supplier_id
                        AND pe.posted_at IS NOT NULL AND pe.entry_date <= ?
                        AND (pe.reversed_by IS NULL OR prev.entry_date > ?)
                        AND pl.side = '{$side}'
                        AND (pca.id = ? OR pca.parent_id = ?)
                        AND (
                            (pip.bank_transaction_id IS NOT NULL
                             AND pe.source_type = 'bank' AND pe.source_id = pip.bank_transaction_id)
                            OR EXISTS (
                                SELECT 1 FROM cash_documents cd
                                 WHERE cd.supplier_id = pip.supplier_id AND {$cashLink}
                                   AND pe.source_type = 'cash' AND pe.source_id = cd.id
                            )
                        )
                 ))";
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchOpenPurchases(int $supplierId, int $accountId, string $asOf): array
    {
        $sql =
            "SELECT d.id AS doc_id,
                    COALESCE(NULLIF(d.varsymbol, ''), CONCAT('#', d.id)) AS doc_no,
                    d.issue_date, d.due_date, d.status, d.paid_at,
                    cl.id AS partner_id, cl.company_name AS partner_name,
                    cur.code AS currency_code,
                    d.amount_to_pay,
                    (SELECT COALESCE(SUM(pm.amount), 0)
                       FROM payment_matches pm
                       JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                      WHERE pm.supplier_id = d.supplier_id AND pm.purchase_invoice_id = d.id
                        AND " . self::MATCH_SETTLEMENT_DATE . " <= ?) AS paid_from_matches,
                    (SELECT COUNT(*)
                       FROM payment_matches pm
                       JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                      WHERE pm.supplier_id = d.supplier_id AND pm.purchase_invoice_id = d.id
                        AND " . self::MATCH_SETTLEMENT_DATE . " > ?) AS matches_after_as_of,
                    " . PurchaseSettledExpr::offsetSettledAsOf('d') . " AS settled_by_offsets,
                    " . $this->advanceOnAccountSql('debit') . " AS advance_on_account,
                    SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END) AS booked_signed,
                    SUM(CASE WHEN l.currency_code IS NOT NULL AND l.currency_code <> 'CZK'
                             THEN (CASE WHEN l.side = 'debit' THEN l.amount_foreign ELSE -l.amount_foreign END)
                             ELSE 0 END) AS foreign_signed
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
               LEFT JOIN journal_entries rev ON rev.id = e.reversed_by
               JOIN purchase_invoices d ON d.id = e.source_id AND d.supplier_id = e.supplier_id
               JOIN clients cl          ON cl.id = d.vendor_id
               JOIN currencies cur      ON cur.id = d.currency_id
              WHERE e.supplier_id = ? AND e.source_type = 'purchase_invoice'
                AND e.posted_at IS NOT NULL
                AND e.entry_date <= ?
                AND (e.reversed_by IS NULL OR rev.entry_date > ?)
                AND (ca.id = ? OR ca.parent_id = ?)
                AND d.status <> 'draft'
                AND (d.status <> 'cancelled' OR d.cancelled_at IS NULL OR DATE(d.cancelled_at) > ?)
              GROUP BY d.id, doc_no, d.issue_date, d.due_date, d.status, d.paid_at,
                       cl.id, cl.company_name, cur.code, d.amount_to_pay
              ORDER BY cl.company_name, d.due_date, d.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $asOf, $asOf,                         // paid_from_matches
            $asOf, $asOf,                         // matches_after_as_of
            $asOf, $asOf, $asOf, $asOf,           // settled_by_offsets (datum + storno, obě cesty)
            $asOf, $asOf, $accountId, $accountId, // advance_on_account
            $supplierId, $asOf, $asOf, $accountId, $accountId, $asOf,
        ]);

        return array_map(function (array $r) use ($asOf): array {
            // `status='paid'` je stav DOKLADU (kdy ho účetní odkliká), ne datum, ke kterému
            // úhradu zná HLAVNÍ KNIHA. Když k dokladu existuje bankovní párování, které deník
            // uznává AŽ PO rozvahovém dni (PF vystavená 31. 12., zaplacená v lednu, ale
            // `paid_at` doklad nese v prosinci), zkratka „paid ⇒ ratio 1" doklad ze salda
            // vyhodí, přestože HK ho k asOf má otevřený — a konfrontace pak nesedí o celou
            // fakturu. V takovém případě zkratku potlačíme a poměr počítáme z DATOVANÝCH
            // úhrad. Dokladům BEZ bankovního párování (hotovost, ruční „označit zaplaceno")
            // zkratka zůstává — jinak by se z nich staly trvale otevřené položky.
            $paidByStatusAsOf = (string) $r['status'] === 'paid'
                && $r['paid_at'] !== null
                && substr((string) $r['paid_at'], 0, 10) <= $asOf
                && (int) $r['matches_after_as_of'] === 0;
            $advance = round((float) $r['advance_on_account'], 2);
            return [
                'doc_type'       => 'purchase_invoice',
                'doc_id'         => (int) $r['doc_id'],
                'doc_no'         => (string) $r['doc_no'],
                'issue_date'     => (string) $r['issue_date'],
                'due_date'       => (string) $r['due_date'],
                'status'         => (string) $r['status'],
                'partner_id'     => (int) $r['partner_id'],
                'partner_name'   => (string) $r['partner_name'],
                'currency_code'  => (string) $r['currency_code'],
                'booked_signed'  => round((float) $r['booked_signed'], 2),
                'foreign_signed' => round((float) $r['foreign_signed'], 2),
                // status='paid' je autoritativní až od paid_at; jinak se poměr skládá z kanálů,
                // kterými se přijatá faktura umí vyrovnat: banka (payment_matches, KNOWN GAP H3)
                // a oba zápočty ({@see PurchaseSettledExpr::offsetSettledAsOf}). Zálohová PF
                // uhrazená přímo na 321 (bez 314) vstupuje do poměru zrcadlově k vydané větvi.
                'paid_ratio'     => $this->paidRatio(
                    $paidByStatusAsOf,
                    (float) $r['paid_from_matches'] + (float) $r['settled_by_offsets'] + $advance,
                    (float) $r['amount_to_pay'] + $advance,
                ),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Poměr uhrazeno/celkem (0..1). Autoritativní stav plné úhrady má přednost (kryje i
     * zaokrouhlovací toleranci InvoicePaymentService a hotovostní plnou úhradu PF,
     * která `paidSignal` vůbec nenaplní). `amount_to_pay` může být záporné
     * (dobropis) — poměr pak vyjde 0 (dobropis se neplatí, PAYABLE_TYPES ho
     * vylučuje z invoice_payments), doklad zůstane plně "otevřený" v původním
     * (záporném) znaménku, což je žádoucí pro netto součet v partnerově saldu.
     */
    private function paidRatio(bool $fullyPaidAsOf, float $paidSignal, float $amountToPay): float
    {
        if ($fullyPaidAsOf) {
            return 1.0;
        }
        if (abs($amountToPay) < 0.005) {
            return 0.0;
        }
        $ratio = $paidSignal / $amountToPay;
        return max(0.0, min(1.0, $ratio));
    }
}
