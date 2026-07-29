<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

use DateTimeImmutable;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\FixedExchangeRateRepository;

/**
 * Pevný kurz per firma (§24 odst. 7 ZoÚ — Fáze F).
 *
 * Účetní jednotka si vnitřním předpisem může zvolit PEVNÝ kurz použitý po celé
 * účetní období (měsíc / rok) místo denního kurzu ČNB k DUZP. Tato služba je
 * JEDINÝ bod, kde se pevný kurz rozhoduje; volá ji {@see ExchangeRateApplier}
 * při uložení dokladu a výsledek se zapíše do `invoices.exchange_rate`. Odtud
 * ho čtou PostingService i VatLedgerService — jeden zdroj pravdy, ne dva výpočty.
 *
 * `resolve()` vrací NULL když:
 *   - firma je v režimu 'daily' (default — beze změny, volající použije ČNB), nebo
 *   - pevný kurz pro dané období/měnu není nastavený (volající bezpečně spadne
 *     zpět na denní ČNB kurz, ať se doklad neuloží bez kurzu).
 *
 * Klíčování obdobím: rok = kalendářní rok data (DUZP), měsíc = kalendářní měsíc.
 * Pro účetní jednotku s hospodářským rokem = kalendářní rok (default) je to přesné.
 */
final class FixedExchangeRateService
{
    public function __construct(
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly FixedExchangeRateRepository $rates,
    ) {}

    /**
     * Pevný kurz pro firmu/měnu/datum, nebo NULL (režim daily / kurz nenastaven).
     *
     * @return array{rate: float, rate_date: string, mode: string, source: string}|null
     */
    public function resolve(int $supplierId, string $currencyCode, DateTimeImmutable $date): ?array
    {
        $code = strtoupper(trim($currencyCode));
        if ($code === '' || $code === 'CZK') {
            return null;
        }
        $mode = $this->settings->getFxRateMode($supplierId);
        if ($mode !== 'fixed_monthly' && $mode !== 'fixed_annual') {
            return null; // daily → denní ČNB (beze změny chování)
        }

        $year  = (int) $date->format('Y');
        $month = $mode === 'fixed_monthly' ? (int) $date->format('n') : 0;

        $found = $this->rates->find($supplierId, $code, $year, $month);
        if ($found === null || $found['rate'] <= 0) {
            return null; // pevný kurz pro období není → fallback na ČNB (volající)
        }

        // rate_date = reprezentativní den období (1. den měsíce/roku) pro evidenci.
        $rateDate = $mode === 'fixed_monthly'
            ? sprintf('%04d-%02d-01', $year, $month)
            : sprintf('%04d-01-01', $year);

        return [
            'rate'      => $found['rate'],
            'rate_date' => $rateDate,
            'mode'      => $mode,
            'source'    => 'fixed',
        ];
    }

    /**
     * Aktuální kurzový režim firmy — pro {@see ExchangeRateApplier}, aby dokázal
     * odlišit „firma je v denním režimu" od „firma je v pevném režimu, ale pro
     * tohle období/měnu pevný kurz chybí" (adversariální review 2026-07, STŘEDNÍ
     * nález: druhý případ se dřív tiše přepočítal ČNB kurzem beze stopy, že šlo
     * o výjimku z vlastní účetní směrnice).
     */
    public function modeFor(int $supplierId): string
    {
        return $this->settings->getFxRateMode($supplierId);
    }
}
