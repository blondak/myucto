<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\ExchangeRateDate;
use MyInvoice\Support\ExchangeRateSources;

/**
 * Přenačtení kurzu přijaté faktury po změně rozhodného dne nebo měny.
 *
 * ── Co se opravuje ──────────────────────────────────────────────────────────────────
 * `PurchaseInvoiceRepository::updateDraft()` zapisovala kurz při KAŽDÉM PUT a editor ho
 * posílal vždycky, takže změna DUZP uložila zpátky starý kurz se starým datem. Nešlo
 * o „chybějící přenačtení", ale o aktivní přepis správné hodnoty tou starou.
 *
 * ── Kdy se přenačítá ────────────────────────────────────────────────────────────────
 * Podle VÝSLEDKU {@see ExchangeRateDate::forPurchase()} a podle `currency_id`, ne podle
 * jednotlivých polí: změna `issue_date` u dokladu, který má vyplněné DUZP, rozhodný den
 * nemění → žádné volání ČNB a žádné přeúčtování deníku.
 *
 * ── Co přepsat SMÍ ──────────────────────────────────────────────────────────────────
 * Jen kurz, který je funkcí data ('cnb', 'fixed' — viz {@see ExchangeRateSources}).
 * Kurz zadaný člověkem ('user'), přinesený importem ('import') i historický zápis
 * neznámého původu ('manual') zůstává; volající o tom dostane důvod, ať to uživateli
 * ukáže — u legacy `manual` dat je to jediná viditelná část featury.
 *
 * Služba je čistě rozhodovací: NIC nezapisuje. Volající si výsledek vloží do těla
 * requestu a uloží ho jedním atomickým UPDATEm existující cestou. Síťový dotaz na ČNB
 * tak proběhne PŘED otevřením transakce.
 *
 * `payment_exchange_rate` se nikdy nedotýká — rozdíl mezi kurzem předpisu a kurzem
 * úhrady je legitimní kurzový rozdíl (563/663), ne chyba.
 */
final class PurchaseInvoiceRateReloader
{
    public const REASON_UNCHANGED = 'unchanged';
    public const REASON_CZK_RESET = 'czk_reset';
    public const REASON_RELOADED = 'reloaded';
    public const REASON_SOURCE_LOCKED = 'source_locked';
    public const REASON_CNB_UNAVAILABLE = 'cnb_unavailable';

    public function __construct(
        private readonly Connection $db,
        private readonly ExchangeRateApplier $applier,
    ) {}

    /**
     * Rozhodne, co se má s kurzem stát. Nic nezapisuje.
     *
     * @param array<string,mixed> $existing uložený doklad ({@see \MyInvoice\Repository\PurchaseInvoiceRepository::find()})
     * @param array<string,mixed> $body     tělo PUT requestu
     *
     * @return array{
     *   reason: string,
     *   apply: array<string,mixed>,
     *   blocked: bool,
     *   rate_will_change: bool,
     *   meta: array<string,mixed>|null,
     *   kept_rate: float|null,
     *   kept_rate_date: string|null,
     *   kept_source: string|null,
     * }
     */
    public function resolveForUpdate(int $supplierId, array $existing, array $body): array
    {
        // Tělo requestu má přednost jen u klíčů, které skutečně poslalo (i s hodnotou
        // null — vymazané DUZP je legitimní změna). Zbytek doplní uložený doklad.
        $next = $body + $existing;

        $oldCurrencyId = (int) ($existing['currency_id'] ?? 0);
        $newCurrencyId = array_key_exists('currency_id', $body)
            ? (int) $body['currency_id']
            : $oldCurrencyId;

        $oldDate = ExchangeRateDate::forPurchase($existing);
        $newDate = ExchangeRateDate::forPurchase($next);

        $currentRate = ($existing['exchange_rate'] ?? null) !== null ? (float) $existing['exchange_rate'] : null;
        $currentRateDate = ($existing['exchange_rate_date'] ?? null) !== null
            ? substr((string) $existing['exchange_rate_date'], 0, 10)
            : null;
        $currentSource = ExchangeRateSources::normalize($existing['exchange_rate_source'] ?? null);

        $result = [
            'reason' => self::REASON_UNCHANGED,
            'apply' => [],
            'blocked' => false,
            'rate_will_change' => false,
            'meta' => null,
            'kept_rate' => $currentRate,
            'kept_rate_date' => $currentRateDate,
            'kept_source' => $currentSource,
        ];

        $newCode = $this->currencyCode($newCurrencyId);

        // Korunový doklad kurz mít nesmí. Nemění to žádné číslo (PostingService::fxRate()
        // i VatLedgerService::normalize() u CZK počítají s 1.0 natvrdo), jen odstraňuje
        // past pro agregace bez pojistky na CZK. Repository to jistí i sama.
        if ($newCode === 'CZK') {
            if ($currentRate !== null || $currentRateDate !== null) {
                $result['reason'] = self::REASON_CZK_RESET;
                $result['apply'] = ['exchange_rate' => null, 'exchange_rate_date' => null];
                $result['rate_will_change'] = true;
            }

            return $result;
        }

        if ($newCode === null || $newDate === null) {
            return $result;
        }

        // Trigger: rozhodný DEN nebo měna. Ne jednotlivá pole — změna vystavení u dokladu
        // s vyplněným DUZP rozhodný den nemění.
        if ($newDate === $oldDate && $newCurrencyId === $oldCurrencyId && $currentRate !== null) {
            return $result;
        }

        // Volající poslal JINÝ kurz, než je uložený → vědomě ho v tomhle requestu nastavuje
        // (ruční úhoz, nebo si sám stáhl kurz z ČNB k novému datu). Automatika mu do toho
        // nesmí vstoupit; zapíše se přesně to, co poslal, i se zdrojem, který uvedl.
        if ($this->callerSetsOwnRate($body, $currentRate)) {
            return $result;
        }

        if (!ExchangeRateSources::isAutoReloadable($currentSource) && $currentRate !== null) {
            $result['reason'] = self::REASON_SOURCE_LOCKED;
            $result['blocked'] = true;
            $result['apply'] = $this->keepStored($currentRate, $currentRateDate, $currentSource);

            return $result;
        }

        $resolved = $this->applier->resolveFor($supplierId, $newCode, $newDate);
        if ($resolved === null) {
            // Výpadek ČNB nesmí shodit uložení dokladu — kurz zůstane starý a uživatel
            // dostane varování, ať si ho zkontroluje ručně.
            $result['reason'] = self::REASON_CNB_UNAVAILABLE;
            $result['blocked'] = true;
            $result['apply'] = $this->keepStored($currentRate, $currentRateDate, $currentSource);

            return $result;
        }

        $newRate = (float) $resolved['rate'];
        $newRateDate = (string) $resolved['rate_date'];
        $newSource = ExchangeRateSources::fromResolved($resolved['source'] ?? null);

        $result['reason'] = self::REASON_RELOADED;
        $result['apply'] = [
            'exchange_rate' => $newRate,
            'exchange_rate_date' => $newRateDate,
            'exchange_rate_source' => $newSource,
        ];
        $result['meta'] = $resolved;
        // Přeúčtování deníku se řídí ZMĚNOU korunové hodnoty, ne tím, že jsme se ptali ČNB.
        $result['rate_will_change'] = $currentRate === null || abs($newRate - $currentRate) > 1e-9;

        return $result;
    }

    /**
     * Nastavuje volající kurz sám v tomhle requestu? Poznáme to podle toho, že poslal
     * JINOU hodnotu, než je uložená — editor totiž kurz posílá vždycky, takže pouhá
     * přítomnost pole nic neznamená (přesně na tom původní vada stála).
     *
     * @param array<string,mixed> $body
     */
    private function callerSetsOwnRate(array $body, ?float $currentRate): bool
    {
        if (!array_key_exists('exchange_rate', $body) || !is_numeric($body['exchange_rate'])) {
            return false;
        }
        $sent = (float) $body['exchange_rate'];
        if ($sent <= 0) {
            return false;
        }

        return $currentRate === null || abs($sent - $currentRate) > 1e-9;
    }

    /**
     * Zamkne uložené hodnoty proti tomu, co poslal volající. Bez toho by klient, který
     * pošle `exchange_rate_source: 'cnb'` k cizímu kurzu, tiše degradoval ochranu:
     * kurz by tenhle request přežil, ale příští přenačtení by ho už přepsalo.
     *
     * @return array<string,mixed>
     */
    private function keepStored(?float $rate, ?string $rateDate, string $source): array
    {
        return [
            'exchange_rate' => $rate,
            'exchange_rate_date' => $rateDate,
            'exchange_rate_source' => $source,
        ];
    }

    /** ISO kód měny, nebo NULL když ID neexistuje. */
    private function currencyCode(int $currencyId): ?string
    {
        if ($currencyId <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT code FROM currencies WHERE id = ?');
        $stmt->execute([$currencyId]);
        $code = $stmt->fetchColumn();

        return $code === false ? null : strtoupper((string) $code);
    }
}
