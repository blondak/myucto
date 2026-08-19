<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportTimeout;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * ⚠️ JEDINÉ MÍSTO ODESÍLACÍ BRÁNY, KTERÉ SE DOTÝKÁ SÍTĚ ⚠️
 *
 * Tři webové služby a nic víc — přesně tolik jich odesílací brána má
 * (`odesilaci_brana_ISDS.pdf` v. 1.11, kap. 3.2, 3.4, 3.5; čtvrtá, `GetPDZInfo`,
 * se týká komerčních poštovních datových zpráv a podání se netýká vůbec).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Co brána NEUMÍ, a proč to není nedodělek
 * ═══════════════════════════════════════════════════════════════════════════
 * **Číst schránku.** Ani seznam došlých zpráv, ani stažení zprávy, ani
 * doručenku. V celé specifikaci není jediná zmínka o download nebo o seznamu —
 * brána je jednosměrná z principu, protože její smysl je předat koncept do
 * perimetru ISDS, ne dát aplikaci přístup ke schránce.
 *
 * Praktický důsledek: **doručenka se přes bránu nedá stáhnout.** Zůstává ruční
 * cesta (uživatel nahraje ZFO) nebo pozdější druhý kanál. Párování odpovědi
 * ČSSZ podle věci už hotové je
 * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsResponseMatcher}),
 * ale zprávu, ve které tu věc má hledat, musí do aplikace dostat člověk.
 *
 * Vedlejší efekt téhož omezení je dobrý: brána nikdy nezpůsobí doručení podle
 * § 17 odst. 3 zák. 300/2008 Sb. Specifikace to říká výslovně (kap. 2.2):
 * „Využití odesílací brány není chápáno jako přihlášení do datové schránky
 * ve smyslu zákona č. 300/2008 Sb. […] Autentizace uživatele tak nezpůsobuje
 * doručení dodaných datových zpráv."
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Povinnosti implementace
 * ═══════════════════════════════════════════════════════════════════════════
 * 1. **Klientský certifikát ke KAŽDÉMU volání.** Kap. 3.1 bod 4. Bez něj vrátí
 *    služba 401, i když je `timeLimitedId` platné.
 * 2. **Timeouty vždy.** Zavěšené spojení drží PHP worker na IIS FastCGI.
 * 3. **Nejistota není chyba.** Když se `SetConcept` přeruší, koncept už mohl
 *    v ISDS vzniknout. Takový stav se hlásí jako {@see IsdsTransportTimeout},
 *    nikdy jako selhání — jinak by uživatel vložil koncept podruhé a mohl by
 *    schválit oba.
 * 4. **`timeLimitedId` se nikam nezapisuje.** Ani do logu, ani do databáze,
 *    ani do chybové hlášky. Je to heslo Basic autentizace.
 * 5. **Žádné automatické opakování.** Brána idempotency token nemá; opakované
 *    `SetConcept` na spotřebované `timeLimitedId` vrátí 401, ale na platné by
 *    vyrobilo druhý koncept.
 */
interface IsdsGatewayClient
{
    /**
     * `GetCredential.wsdl` → `authConfirmationRequest` (kap. 3.2).
     *
     * Vymění jednorázové `sessionId` z přesměrování za `timeLimitedId`.
     * Po schválení konceptu vrací totéž volání navíc `conceptDmId`,
     * `conceptStatusCode` a `conceptStatusMessage` (kap. 3.4 bod 4).
     *
     * ⚠️ „Získání informací (timeLimitedId) z ISDS za pomocí tohoto daného
     * sessionId je možné pouze jednou." (kap. 2.6 bod 5) — druhé volání
     * s týmž `sessionId` skončí `SESSION_NOT_FOUND`.
     *
     * @throws SubmissionChannelException při prokazatelném odmítnutí
     *         (`SESSION_NOT_FOUND`, HTTP 401, vadná odpověď)
     * @throws IsdsTransportTimeout když se nedovoláme
     */
    public function exchangeSession(IsdsGatewayRegistration $registration, string $sessionId): IsdsGatewayCredential;

    /**
     * `SetConcept.wsdl` → `SetConcept` (kap. 3.4).
     *
     * Vloží koncept do perimetru ISDS a vrátí jeho dmID. **Není to odeslání** —
     * zprávu odešle až uživatel tím, že koncept v ISDS schválí.
     *
     * „Na jedno timeLimitedId lze vložit pouze jeden koncept a tím je
     * timeLimitedId spotřebováno." (kap. 3.4)
     *
     * @return string dmID konceptu
     * @throws SubmissionChannelException při prokazatelném odmítnutí — tedy když
     *         je jisté, že koncept NEVZNIKL a je bezpečné začít znovu
     * @throws IsdsTransportTimeout kdykoli není výsledek jistý
     */
    public function setConcept(
        IsdsGatewayRegistration $registration,
        IsdsGatewayCredential $credential,
        IsdsConceptMessage $message,
    ): string;

    /**
     * `ExtWs.wsdl` → `extWsLogoutRequest` (kap. 3.5).
     *
     * Zneplatní `timeLimitedId`. Volá se po dokončení i po zamítnutí — ISDS
     * povoluje nejvýš tři rozpracované koncepty na uživatele a bránu (kap. 3.1
     * bod 6), takže neuklizené tokeny by uživateli po pár pokusech zablokovaly
     * další podání.
     *
     * Selhání se NEPROPAGUJE: úklid nesmí přebít výsledek podání. Implementace
     * ho jen zaloguje. Služba sama vrací `OK` i pro neexistující token
     * („Z bezpečnostních důvodů je vráceno i v případě, že timeLimitedId
     * neexistuje" — kap. 3.5.2), takže volání navíc nic nerozbije.
     */
    public function logout(IsdsGatewayRegistration $registration, IsdsGatewayCredential $credential): void;
}
