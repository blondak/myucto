<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

use MyInvoice\Service\Submission\Channel\SensitiveValue;

/**
 * Registrace odesílací brány u PROVOZOVATELE aplikace.
 *
 * Není to přístup zákazníka — je to naše vlastní registrace u ISDS a jeden
 * certifikát pro celou službu. Zákazník k odesílání přes bránu nepotřebuje nic
 * (`odesilaci_brana_ISDS.pdf` v. 1.11, kap. 2.1: „Tato služba je k dispozici
 * všem držitelům datových schránek.").
 *
 * ── Proč nejsou URL složené v kódu ──────────────────────────────────────────
 * Specifikace píše adresy jako `https://www.[url-adresa-prostředí-isds]/…`
 * s hodnotami `datovka.gov.cz` (produkce) a `datovka-test.gov.cz` (veřejný
 * test), kap. 1.2. Jenže WSDL přibalené v téže příloze má pořád zapsané staré
 * `cert.mojedatovaschranka.cz`, a Provozní řád říká, že staré domény poběží
 * souběžně minimálně do 31. 12. 2027. Natvrdo zapsaná doména by tedy byla
 * jistota, kterou nemáme; hostitel je proto součást registrace.
 *
 * ── Tajemství ───────────────────────────────────────────────────────────────
 * Certifikát je {@see SensitiveValue}, tedy handle do trezoru, ne řetězec na
 * instanci. Celá třída navíc odmítá serializaci: kdyby se registrace dostala do
 * fronty úloh nebo do session, šel by z ní vytáhnout klientský certifikát, kterým
 * se aplikace prokazuje ISDS.
 */
final readonly class IsdsGatewayRegistration
{
    /** Basic auth uživatel pro `SetConcept` a spol. (kap. 3.2/3.4). */
    public const WS_USERNAME = 'ExtWS';

    public function __construct(
        public string $environment,
        public string $atsId,
        public string $label,
        public string $returnUrl,
        public ?string $errorUrl,
        public int $conceptTtlSeconds,
        public string $portalHost,
        public string $serviceHost,
        public IsdsGatewayLoginPolicy $loginPolicy,
        public SensitiveValue $certificate,
        public ?SensitiveValue $certificatePassphrase,
        public ?string $certificateFingerprint,
        public ?string $certificateValidTo,
    ) {}

    /**
     * Kam poslat uživatele, aby se přihlásil (kap. 2.6, detailní postup, bod 1).
     *
     * `appToken` je náš vlastní identifikátor, který ISDS vrátí zpět — max
     * 20 číslic. Není to API klíč a sám o sobě nic neautorizuje; slouží jen
     * k tomu, abychom po návratu poznali, o kterou relaci jde.
     */
    public function loginUrl(string $appToken): string
    {
        return 'https://www.' . $this->portalHost . '/as/login'
            . '?atsId=' . rawurlencode($this->atsId)
            . '&appToken=' . rawurlencode($appToken);
    }

    /**
     * Kam poslat uživatele, aby si koncept prohlédl a schválil (kap. 3.4 bod 2).
     *
     * `$conceptId` je dmID KONCEPTU ze `SetConceptResponse`, ne ID odeslané
     * zprávy. To dostaneme až po schválení jako `conceptDmId`.
     */
    public function conceptUrl(string $conceptId, string $appToken): string
    {
        return 'https://www.' . $this->portalHost . '/as/koncept/view'
            . '?konceptId=' . rawurlencode($conceptId)
            . '&appToken=' . rawurlencode($appToken);
    }

    /** `GetCredential.wsdl` — výměna `sessionId` za `timeLimitedId` (kap. 3.2). */
    public function credentialEndpoint(): string
    {
        return 'https://' . $this->serviceHost . '/asws/extIs2Endpoint';
    }

    /** `SetConcept.wsdl` — vložení konceptu (kap. 3.4). */
    public function conceptEndpoint(): string
    {
        return 'https://' . $this->serviceHost . '/asws/konceptEndpoint';
    }

    /** `ExtWs.wsdl` — zneplatnění `timeLimitedId` (kap. 3.5). */
    public function logoutEndpoint(): string
    {
        return 'https://' . $this->serviceHost . '/asws/extWsEndpoint';
    }

    /**
     * Do fronty úloh, session ani cache tahle hodnota nesmí — nesla by
     * klientský certifikát celé služby.
     */
    public function __serialize(): array
    {
        throw new \LogicException('Registraci odesílací brány nelze serializovat.');
    }

    /** @return array{environment:string,ats_id:string,portal_host:string,service_host:string} */
    public function toLogContext(): array
    {
        return [
            'environment' => $this->environment,
            'ats_id' => $this->atsId,
            'portal_host' => $this->portalHost,
            'service_host' => $this->serviceHost,
        ];
    }
}
