<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\CzkRecap;

/**
 * Po každém Create/Update faktury: pokud měna ≠ CZK, zjistí kurz CNB pro issue_date
 * (s fallbackem den-zpět + last-known) a uloží do `invoices.exchange_rate`.
 *
 * Vrací metadata pro UI (warning toast po uložení) — null pokud se nic neaplikuje
 * (CZK faktura) nebo pokud DB neobsahuje žádný kurz pro danou měnu (ani last-known).
 */
final class ExchangeRateApplier
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly CnbExchangeRateClient $cnb,
        private readonly FixedExchangeRateService $fixed,
    ) {}

    /**
     * @return array{
     *   currency: string,
     *   rate: float,
     *   rate_date: string,
     *   fallback_used: bool,
     *   source: 'cache'|'fresh'|'last_known'|'fixed',
     *   fixed_missing: bool
     * }|null
     */
    public function applyToInvoice(int $invoiceId): ?array
    {
        // Kurz se váže k DUZP (§ 4 odst. 5 / § 8 ZDPH — den vzniku daňové povinnosti),
        // ne k datu vystavení. Fallback na issue_date, když DUZP chybí.
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.supplier_id, COALESCE(i.tax_date, i.issue_date) AS rate_date, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ?'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $code = strtoupper((string) $row['currency']);
        if ($code === 'CZK') {
            // Reset — kdyby uživatel změnil EUR fakturu na CZK
            $this->invoices->setExchangeRate($invoiceId, null, null);
            return null;
        }

        $result = $this->resolveFor(
            (int) $row['supplier_id'],
            $code,
            (string) $row['rate_date'],
        );
        if ($result === null) {
            $this->invoices->setExchangeRate($invoiceId, null, null);
            return null;
        }

        $this->invoices->setExchangeRate($invoiceId, $result['rate'], $result['rate_date']);
        return $result;
    }

    /**
     * Vyřeší kurz bez zápisu do faktury. Síťový/cache lookup tak může caller provést
     * ještě před otevřením zápisové transakce a uvnitř ní už jen uložit výsledek.
     *
     * @return array{
     *   currency: string,
     *   rate: float,
     *   rate_date: string,
     *   fallback_used: bool,
     *   source: 'cache'|'fresh'|'last_known'|'fixed',
     *   fixed_missing: bool
     * }|null
     */
    public function resolveFor(int $supplierId, string $currencyCode, string $rateDate): ?array
    {
        $code = strtoupper(trim($currencyCode));
        if ($code === '' || $code === 'CZK') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($rateDate);
        } catch (\Exception) {
            return null;
        }

        // Fáze F (§24/7 ZoÚ): firma v režimu pevného kurzu použije uložený pevný
        // kurz období MÍSTO denního ČNB kurzu. Zapisuje se do stejného sloupce
        // (invoices.exchange_rate) → PostingService i VatLedgerService čtou pevný
        // kurz automaticky (jeden zdroj pravdy). Nenastavený pevný kurz vrátí NULL
        // → spadneme na ČNB, ať se doklad neuloží bez kurzu.
        $fixed = $this->fixed->resolve($supplierId, $code, $date);
        if ($fixed !== null) {
            return [
                'currency'      => $code,
                'rate'          => $fixed['rate'],
                'rate_date'     => $fixed['rate_date'],
                'fallback_used' => false,
                'source'        => 'fixed',
                'fixed_missing' => false,
            ];
        }

        // Adversariální review 2026-07 (STŘEDNÍ nález): firma v pevném režimu, ale
        // bez nastaveného kurzu pro tohle období/měnu — tiché spadnutí na ČNB by
        // porušilo vlastní kurzový režim beze stopy pro účetní. `fixed_missing`
        // nechává uložení projít (doklad musí mít kurz), jen FE upozorní.
        $mode = $this->fixed->modeFor($supplierId);
        $fixedMissing = ($mode === 'fixed_monthly' || $mode === 'fixed_annual');

        $result = $this->cnb->getRate($code, $date);
        if ($result === null) {
            return null;
        }

        return [
            'currency'      => $code,
            'rate'          => (float) $result['rate'],
            'rate_date'     => (string) $result['rate_date'],
            'fallback_used' => (bool) $result['fallback_used'],
            'source'        => (string) $result['source'],
            'fixed_missing' => $fixedMissing,
        ];
    }

    /**
     * Idempotentní backfill — pokud faktura v cizí měně nemá zafixovaný kurz
     * (legacy data, nebo dřívější fetch z ČNB selhal), zkusí cache → CNB → last
     * known. Volá se z GetInvoiceAction / PdfAction při každém otevření faktury,
     * aby starší doklady automaticky dostaly přepočet jakmile bude kurz dostupný.
     *
     * Pokud kurz už uložen je, nic nedělá. Pokud měna je CZK, nic nedělá.
     *
     * @return array{
     *   currency: string,
     *   rate: float,
     *   rate_date: string,
     *   fallback_used: bool,
     *   source: 'cache'|'fresh'|'last_known'|'fixed',
     *   fixed_missing: bool
     * }|null
     */
    public function ensureRate(int $invoiceId, bool $persist = true): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.supplier_id, i.exchange_rate,
                    COALESCE(i.tax_date, i.issue_date) AS rate_date,
                    cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ?'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if (strtoupper((string) $row['currency']) === 'CZK') return null;
        if ($row['exchange_rate'] !== null) return null;

        if ($persist) {
            return $this->applyToInvoice($invoiceId);
        }

        return $this->resolveFor(
            (int) $row['supplier_id'],
            (string) $row['currency'],
            (string) $row['rate_date'],
        );
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array{
     *   currency: string,
     *   rate: float,
     *   rate_date: string,
     *   fallback_used: bool,
     *   source: 'cache'|'fresh'|'last_known'|'fixed',
     *   fixed_missing: bool
     * }|null $resolved
     * @return array<string,mixed>
     */
    public function applyResolvedToInvoiceData(array $invoice, ?array $resolved): array
    {
        if ($resolved === null) return $invoice;

        $invoice['exchange_rate'] = (float) $resolved['rate'];
        $invoice['exchange_rate_date'] = (string) $resolved['rate_date'];
        if (isset($invoice['vat_breakdown']) && is_array($invoice['vat_breakdown'])) {
            $invoice['czk_recap'] = CzkRecap::build(
                $invoice['vat_breakdown'],
                (float) $resolved['rate'],
                (string) $resolved['rate_date'],
                (bool) $resolved['fallback_used'],
            );
        }
        return $invoice;
    }
}
