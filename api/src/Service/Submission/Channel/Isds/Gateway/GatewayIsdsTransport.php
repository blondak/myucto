<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\Isds\IsdsBoxCheck;
use MyInvoice\Service\Submission\Channel\Isds\IsdsSendReceipt;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransport;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;

/**
 * {@see IsdsTransport} pro nasazení, kde je zapnutá odesílací brána ISDS.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ NEODESÍLÁ. A není to nedodělek — je to jediná pravdivá odpověď.
 * ═══════════════════════════════════════════════════════════════════════════
 * `IsdsTransport::createMessage()` je SYNCHRONNÍ port: zavolá se a buď zpráva
 * odešla (a vrátí se {@see IsdsSendReceipt} se stavem přesně `0000` a s `dmID`),
 * nebo ne. Odesílací brána takhle nefunguje a nemůže:
 *
 *   1. Vstupem do `GetCredential` je jednorázové `sessionId`, které vzniká
 *      VÝHRADNĚ přesměrováním prohlížeče poté, co se uživatel přihlásil
 *      v perimetru ISDS. Server si ho nemá jak obstarat — a nesmí, protože
 *      přístupové údaje nesmí opustit zařízení uživatele (§ 9 odst. 2
 *      zák. č. 300/2008 Sb.). {@see ChannelContext} proto žádné heslo nenese.
 *   2. `SetConcept` vloží KONCEPT, ne zprávu. Do odeslání stojí člověk, který
 *      koncept v ISDS schválí. Kód `0000` a `conceptDmId` se dozvíme až
 *      z druhého `GetCredential` po jeho návratu (kap. 3.4 bod 4).
 *
 * Kdyby tahle třída mezi tím vším „nějak počkala", lhala by o tvaru rozhraní
 * a u daňového nebo mzdového podání je tichá lež to nejdražší, co se může stát.
 * Skutečné odeslání proto obsluhuje {@see IsdsGatewayDispatchService}
 * (dvě přesměrování, vlastní stavová relace, vlastní idempotence) a tenhle
 * adaptér na něj **odkazuje pojmenovanou překážkou**.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * K čemu tedy je
 * ═══════════════════════════════════════════════════════════════════════════
 * {@see \MyInvoice\Service\Submission\Channel\Isds\UnavailableIsdsTransport}
 * říká „napojení není nasazené, odešlete si to ručně". Na instalaci se zapnutou
 * branou je to nepravda a posílá uživatele delší cestou, než musí. Tenhle
 * adaptér říká pravdu: cesta ven existuje, vede přes bránu, a **čtení schránky
 * po ní nevede** — brána ho neumí vůbec.
 *
 * Zároveň je to fail-closed diagnostika: na odesílací cestě si registraci
 * skutečně načte ({@see IsdsGatewayRegistrationService::load()}), takže vypnutá
 * brána, chybějící certifikát nebo změněný šifrovací klíč vyhodí svou vlastní
 * pojmenovanou chybu místo obecné.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Sedm povinností z auditu — kde jsou splněné
 * ═══════════════════════════════════════════════════════════════════════════
 * Tahle třída se NEDOTÝKÁ SÍTĚ, takže je většinu z nich splňuje tím, že
 * příslušný stav vůbec nemůže nastat. Skutečné místo jejich splnění je
 * {@see SoapIsdsGatewayClient} a {@see IsdsGatewayDispatchService}:
 *
 *  1. **Přesně `0000`** — {@see IsdsGatewayCredential::isDispatched()} i
 *     {@see SoapIsdsGatewayClient::setConcept()} porovnávají rovností, ne
 *     `str_starts_with('00')`. {@see IsdsSendReceipt} to navíc vynucuje typem.
 *  2. **`\Throwable`, ne typ knihovny** — odpověď se čte přes `DOMXPath` do
 *     pole, takže neúplná odpověď nevyrobí `\Error` z neinicializované
 *     property. Kde přesto výsledek jistý není (chybějící `dmID` u stavu `OK`),
 *     hlásí se {@see \MyInvoice\Service\Submission\Channel\Isds\IsdsTransportTimeout},
 *     ne selhání. {@see \MyInvoice\Service\Submission\Channel\Isds\IsdsChannel::send()}
 *     nad tím drží ještě `catch (\Throwable)` → `uncertain`.
 *  3. **Vlastní timeouty** — `CURLOPT_CONNECTTIMEOUT` a `CURLOPT_TIMEOUT`
 *     v {@see SoapIsdsGatewayClient}; zavěšené spojení neudrží PHP worker.
 *  4. **Přihlášení bez hesla uživatele** — heslo zadává uživatel v perimetru
 *     ISDS, náš server je nikdy nevidí; vůči webovým službám se autentizujeme
 *     klientským certifikátem provozovatele (kap. 3.1 bod 4).
 *  5. **Žádný plaintext na disku** — PKCS#12 se rozbaluje do dočasných souborů
 *     s náhodným názvem a maže se ve `finally`, tedy i při výjimce. Přílohy se
 *     do TEMP nikdy neodkládají: jdou z úložiště rovnou do XML v paměti.
 *  6. **Pověření se neserializuje** — {@see IsdsGatewayCredential::__serialize()}
 *     i {@see IsdsGatewayRegistration::__serialize()} hází `LogicException`;
 *     v relaci se drží `supplier_id` a `outbox_id`, ne pověření.
 *  7. **Doručenka zůstává `unverified`** — brána ji stáhnout neumí vůbec
 *     ({@see downloadDeliveryReceipt()}), takže ji nahrává člověk a modul ji
 *     dál vede jako neověřenou, dokud si CMS podpis a časové razítko neověříme.
 *
 * Idempotence a rekonciliace: brána žádný idempotency token nemá a opakované
 * `SetConcept` na platném `timeLimitedId` by vyrobilo DRUHÝ koncept. Nese je
 * proto stavová relace, ne tenhle adaptér — UNIQUE index nad živými relacemi
 * jednoho podání, jednorázové `sessionId` a jednorázové přechody
 * `markConceptPushed()` / `markApproved()`. `dmSenderIdent` (spisová značka)
 * se do konceptu razítkuje v {@see IsdsConceptMessage}, takže po nejistém
 * konci je zpráva dohledatelná v odeslaných zprávách schránky — ovšem OKEM
 * UŽIVATELE, protože seznam odeslaných zpráv brána nečte.
 */
final readonly class GatewayIsdsTransport implements IsdsTransport
{
    /**
     * Prostředí, ve kterých se brána vůbec smí použít. Shoduje se
     * s `IsdsGatewayRegistrationService::ENVIRONMENTS`.
     */
    private const ENVIRONMENTS = ['production', 'test'];

    public function __construct(private IsdsGatewayRegistrationSource $registrations) {}

    /**
     * Je brána nastavená natolik, že má smysl bindovat tenhle adaptér?
     *
     * Rozhoduje o TEXTU překážky, ne o oprávnění cokoliv odeslat — o to se
     * fail-closed stará až {@see IsdsGatewayRegistrationService::load()} při
     * skutečném odeslání. Jakákoliv nejistota (chybějící tabulka, rozbitá
     * databáze) je tady `false`: nenastavená instalace musí zůstat u
     * {@see \MyInvoice\Service\Submission\Channel\Isds\UnavailableIsdsTransport}.
     */
    public static function isConfigured(IsdsGatewayRegistrationSource $registrations): bool
    {
        foreach (self::ENVIRONMENTS as $environment) {
            try {
                if ($registrations->isDispatchReady($environment)) {
                    return true;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * Brána má tři webové služby (`GetCredential`, `SetConcept`, `ExtWs`)
     * a ani jedna se schránek neptá. Ověřit příjemce po ní tedy nejde.
     *
     * Nevědomost se nesmí vydávat ani za „schránka je v pořádku", ani za opak
     * — proto výjimka, ne {@see IsdsBoxCheck::usable()}. Tvar ID schránky se
     * i tak zkontroluje offline v {@see IsdsConceptMessage::assertValid()}
     * a případnou zrušenou schránku uvidí uživatel v ISDS ve chvíli, kdy tam
     * koncept schvaluje.
     */
    public function checkRecipientBox(ChannelContext $context, string $boxId): IsdsBoxCheck
    {
        throw new SubmissionChannelException(
            'isds_gateway_recipient_check_unsupported',
            'Odesílací brána datové schránky se neumí zeptat, jestli schránka příjemce existuje. '
            . 'Tvar identifikátoru zkontrolujeme, ale platnost schránky uvidíte až v datové schránce '
            . 'při schvalování zprávy.',
            503,
        );
    }

    /**
     * Odeslání přes bránu vede jinudy — přes {@see IsdsGatewayDispatchService}.
     *
     * Je to PROKAZATELNÉ odmítnutí, ne nejistota: nic se nikam neposlalo,
     * takže je bezpečné to zkusit znovu správnou cestou. Registrace se přesto
     * načte, aby uživatel dostal přesnou příčinu (vypnutá brána, chybějící
     * certifikát) místo obecné hlášky.
     */
    public function createMessage(
        ChannelContext $context,
        string $recipientBoxId,
        string $subject,
        string $senderIdent,
        array $files,
    ): IsdsSendReceipt {
        // Fail-closed diagnostika: hází vlastní pojmenované chyby.
        $this->registrations->load($context->environment);

        throw new SubmissionChannelException(
            'isds_gateway_dispatch_is_interactive',
            'Datovou schránkou se odesílá přes odesílací bránu, a ta potřebuje váš souhlas: '
            . 'připravíme zprávu jako koncept, vy ji v datové schránce zkontrolujete a odeslání '
            . 'potvrdíte. Automaticky, bez vašeho potvrzení, zprávu odeslat nelze. '
            . 'Spusťte odeslání tlačítkem u podání.',
            409,
        );
    }

    /**
     * Seznam odeslaných zpráv brána nečte, takže rekonciliaci po nejistém konci
     * musí udělat člověk ve své schránce podle spisové značky (`dmSenderIdent`).
     *
     * `null` by tady znamenalo „taková zpráva tam není" — což NEVÍME.
     */
    public function findSentBySenderIdent(ChannelContext $context, string $senderIdent): ?string
    {
        throw $this->readUnsupported(
            'Odesílací brána neumí číst odeslané zprávy, takže podání podle spisové značky '
            . 'nedohledáme. Najděte ji ve své datové schránce v odeslaných zprávách podle značky '
            . $senderIdent . ' a výsledek zapište u podání.',
        );
    }

    public function messageState(ChannelContext $context, string $messageId): array
    {
        throw $this->readUnsupported(
            'Odesílací brána neumí zjistit stav odeslané zprávy. Doručení uvidíte ve své datové '
            . 'schránce; doručenku k podání nahrajte ručně.',
        );
    }

    /**
     * ⚠️ Kdyby tohle vrátilo prázdné pole, tvrdilo by „ve schránce nic nového
     * není". To nevíme a vědět nemůžeme — brána do schránky nevidí.
     *
     * Vedlejší efekt téhož omezení je dobrý: brána nikdy nezpůsobí doručení
     * podle § 17 odst. 3 zák. č. 300/2008 Sb. (kap. 2.2 specifikace).
     */
    public function listReceived(ChannelContext $context): array
    {
        throw $this->readUnsupported(
            'Odesílací brána do datové schránky nevidí, takže došlé zprávy vybrat neumí. '
            . 'Je to vlastnost, ne výpadek: díky tomu vám aplikace nemůže nechtěně doručit zprávu '
            . 'a rozjet lhůty. Zprávy si vyzvedněte v datové schránce sami.',
        );
    }

    public function downloadMessage(ChannelContext $context, string $messageId): string
    {
        throw $this->readUnsupported(
            'Odesílací brána neumí zprávu stáhnout. Stáhněte si ji v datové schránce jako ZFO '
            . 'a nahrajte ji u podání.',
        );
    }

    public function downloadDeliveryReceipt(ChannelContext $context, string $messageId): ?string
    {
        // `null` je vyhrazené pro „doručenka zatím neexistuje". Tady jde
        // o něco jiného: doručenku po téhle cestě nezískáme NIKDY.
        throw $this->readUnsupported(
            'Odesílací brána neumí stáhnout doručenku. Stáhněte si ji v datové schránce '
            . 'a nahrajte ji u podání — teprve pak k němu bude důkaz o dni podání.',
        );
    }

    private function readUnsupported(string $message): SubmissionChannelException
    {
        return new SubmissionChannelException('isds_gateway_read_unsupported', $message, 503);
    }
}
