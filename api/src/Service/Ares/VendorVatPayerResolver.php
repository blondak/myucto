<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ares;

use MyInvoice\Repository\ClientRepository;

/**
 * Zjistí, zda je dodavatel plátce DPH, z autoritativních registrů a uloží příznak
 * na klienta (`clients.is_vat_payer`). Sdílí ho AI import, online refresh endpoint
 * i backfill skript — jediné místo s pravidlem.
 *
 * Zdroj podle typu dodavatele (precedence):
 *  1. CZ (IČO 8 číslic) → ARES `is_vat_payer` (stavZdrojeDph === 'AKTIVNI').
 *     - Pozitivní výsledek je vždy konečný.
 *     - Negativní výsledek (vč. ZANIKLY) je konečný JEN pokud DIČ na dokladu
 *       odpovídá vlastní registraci subjektu (číselná část DIČ = IČO). Člen
 *       skupinové registrace DPH (§5a ZDPH) má vlastní registraci zaniklou
 *       vstupem do skupiny — ARES dle IČO proto vrátí neplátce/ZANIKLY, i když
 *       fakturuje s DPH jako člen skupiny. DIČ skupiny má jiný tvar (typicky
 *       `CZ699xxxxxx`), tedy číselná část ≠ IČO → v tom případě ARES negativum
 *       NENÍ konečné a jde se doověřit krokem 2.
 *  2. VIES `valid` podle DIČ (zahraniční EU dodavatel, NEBO doověření skupinové
 *     registrace z kroku 1 — `ViesClient::lookup()` skupinové DIČ interně routuje
 *     na CRPDPH, protože VIES DPH skupiny vůbec neeviduje). Nález v CRPDPH = plátce.
 *  3. Nezjištěno (registry nedostupné / 404 / mimo EU) → null (necháme dosavadní
 *     příznak beze změny), NEBO — u skupinové DIČ z kroku 1, kterou se nepodařilo
 *     doověřit — ponecháme ARES negativum (konzervativní fallback, nic nezhoršuje
 *     oproti dřívějšímu chování).
 *
 * Cache: ARES (ares_cache 24 h), VIES (vies_cache) i CRPDPH (crpdph_cache) řeší TTL
 * na úrovni klientů → volání při každém vytvoření faktury je fakticky „1× denně"
 * bez zátěže registrů.
 */
final class VendorVatPayerResolver
{
    public function __construct(
        private readonly AresClient $ares,
        private readonly ViesClient $vies,
        private readonly ClientRepository $clients,
    ) {}

    /**
     * Zjistí plátcovství a (pokud je výsledek jednoznačný) uloží ho na klienta.
     *
     * @return array{is_vat_payer:?bool, source:'ares'|'vies'|'unknown'}
     */
    public function resolveAndPersist(int $clientId, ?string $ic, ?string $dic): array
    {
        $res = $this->resolve($ic, $dic);
        if ($res['is_vat_payer'] !== null) {
            $this->clients->setVatPayer($clientId, $res['is_vat_payer']);
        }
        return $res;
    }

    /**
     * Pure lookup bez zápisu — vrací is_vat_payer (true/false) nebo null (nezjištěno).
     *
     * @return array{is_vat_payer:?bool, source:'ares'|'vies'|'unknown'}
     */
    public function resolve(?string $ic, ?string $dic): array
    {
        $icDigits = preg_replace('/\D/', '', (string) $ic) ?? '';
        $dicTrim = trim((string) $dic);
        $dicDigits = preg_replace('/\D/', '', $dicTrim) ?? '';

        // 1. CZ subjekt dle IČO → ARES (autoritativní stav VLASTNÍ registrace).
        $aresResult = null;
        if (strlen($icDigits) === 8) {
            $resp = $this->ares->lookup($icDigits);
            if ($resp !== null && ($resp['found'] ?? false) && isset($resp['data'])) {
                $aresResult = ['is_vat_payer' => (bool) ($resp['data']['is_vat_payer'] ?? false), 'source' => 'ares'];

                // Pozitiv je vždy konečný. Negativ je konečný jen když DIČ na dokladu
                // odpovídá vlastní registraci (číselná část = IČO) — jinak jde o DIČ
                // skupinové registrace (BUG: člen skupiny má vlastní registraci
                // zaniklou), doověříme krokem 2.
                $isDicMismatch = $dicDigits !== '' && $dicDigits !== $icDigits;
                if ($aresResult['is_vat_payer'] || !$isDicMismatch) {
                    return $aresResult;
                }
            }
        }

        // 2. Zahraniční EU dle DIČ → VIES (valid = registrovaný plátce), nebo doověření
        //    skupinové DPH registrace z kroku 1 — ViesClient skupinové DIČ (CZ699xxxxxx)
        //    interně routuje na CRPDPH. Jen když ARES nerozhodl nebo rozhodl negativně
        //    u nesouhlasícího DIČ výše. source='error' = registr nedostupný → nevíme.
        if ($dicTrim !== '') {
            try {
                $v = $this->vies->lookup($dicTrim);
                if (($v['source'] ?? '') !== 'error') {
                    return ['is_vat_payer' => !empty($v['valid']), 'source' => 'vies'];
                }
            } catch (\Throwable) {
                // VIES/CRPDPH timeout / chyba — necháme nezjištěno (níže: fallback na ARES).
            }
        }

        // ARES rozhodl negativně u DIČ skupinové registrace, ale doověření selhalo nebo
        // nebylo k dispozici (chybí DIČ) → radši konzervativně ARES negativum než
        // "nezjištěno" (nezhoršuje dnešní stav).
        if ($aresResult !== null) {
            return $aresResult;
        }

        return ['is_vat_payer' => null, 'source' => 'unknown'];
    }
}
