<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds\Gateway;

use MyInvoice\Service\Submission\Channel\SensitiveValue;

/**
 * Odpověď `authConfirmationResponse` z `GetCredential.wsdl` (kap. 3.2).
 *
 * Nese dvě různé věci podle toho, ve které fázi se volá:
 *   - PO PŘIHLÁŠENÍ  → jen `timeLimitedId` (a `appToken`, pokud jsme ho poslali),
 *   - PO SCHVÁLENÍ   → navíc `conceptDmId`, `conceptStatusCode`
 *                      a `conceptStatusMessage` (kap. 3.4 bod 4).
 *
 * ── Proč je `timeLimitedId` tajemství ───────────────────────────────────────
 * Používá se jako HESLO v Basic autentizaci (`ExtWS` / `timeLimitedId`, kap. 3.4).
 * Kdo ho má, může jménem naší brány vložit uživateli koncept do schránky.
 * Není to sice přístupový údaj uživatele ve smyslu § 9 odst. 2 zák. 300/2008 Sb.
 * — přístupové údaje uživatel zadává v perimetru ISDS a náš server je nikdy
 * neuvidí — ale je to plnohodnotné pověření a zachází se s ním jako s heslem:
 * {@see SensitiveValue}, nikdy do logu, nikdy do databáze.
 */
final readonly class IsdsGatewayCredential
{
    /** Uživatel koncept zamítl (kap. 3.4 bod 4). Nic neodešlo. */
    public const STATUS_REJECTED_BY_USER = '2305';

    /** Jediný kód, který u ISDS znamená úspěch. `00xx` obecně NE. */
    public const STATUS_OK = '0000';

    public function __construct(
        public SensitiveValue $timeLimitedId,
        public ?string $appToken,
        public ?string $conceptDmId,
        public ?string $conceptStatusCode,
        public ?string $conceptStatusMessage,
    ) {}

    /**
     * Odešel koncept opravdu?
     *
     * Kontroluje se přesná rovnost s `0000`, ne `str_starts_with('00')` —
     * to je nález S-2 z auditu knihovny a u daňového nebo mzdového podání je
     * tichý neúspěch to nejdražší, co se může stát.
     */
    public function isDispatched(): bool
    {
        return $this->conceptStatusCode === self::STATUS_OK
            && $this->conceptDmId !== null
            && $this->conceptDmId !== '';
    }

    public function isRejectedByUser(): bool
    {
        return $this->conceptStatusCode === self::STATUS_REJECTED_BY_USER;
    }

    /** Nese odpověď vůbec informaci o osudu konceptu? */
    public function hasConceptOutcome(): bool
    {
        return $this->conceptStatusCode !== null && $this->conceptStatusCode !== '';
    }

    public function __serialize(): array
    {
        throw new \LogicException('Pověření odesílací brány nelze serializovat.');
    }

    /** @return array<string,mixed> Bezpečný tvar pro log — bez `timeLimitedId`. */
    public function toLogContext(): array
    {
        return [
            'concept_dm_id' => $this->conceptDmId,
            'concept_status_code' => $this->conceptStatusCode,
        ];
    }
}
