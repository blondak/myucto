<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Přístup ke kanálu, jak ho vidí adaptér.
 *
 * Veřejné jsou jen údaje, které veřejné jsou (ID naší schránky, režim
 * přihlášení). Cokoliv tajného je {@see SensitiveValue}, takže se to nedostane
 * do výpisu, do JSONu ani do stack trace.
 *
 * ⚠️ **Žádné `login` ani `password`, a nikdy nebudou.** Přístupové údaje ke
 * schránce nesmí opustit zařízení pod kontrolou uživatele (§ 9 odst. 2 zák.
 * 300/2008 Sb.), takže je aplikace nedrží ani na okamžik v paměti. Jediná
 * průchozí cesta je systémový certifikát.
 */
final readonly class ChannelCredentials
{
    public function __construct(
        public string $boxId,
        public string $authMode,
        public ?SensitiveValue $certificate = null,
        public ?SensitiveValue $certificatePassphrase = null,
    ) {}

    /**
     * Ani přihlašovací objekt nesmí do session, cache nebo fronty úloh —
     * odtud by šel vytáhnout certifikát. Do fronty patří `supplier_id`
     * a pověření se odemyká až těsně před voláním.
     */
    public function __serialize(): array
    {
        throw new \LogicException('Přístup ke kanálu nelze serializovat.');
    }

    /** @return array{box_id:string,auth_mode:string} Bezpečný tvar pro log. */
    public function toLogContext(): array
    {
        return ['box_id' => $this->boxId, 'auth_mode' => $this->authMode];
    }
}
