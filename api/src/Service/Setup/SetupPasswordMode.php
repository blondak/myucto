<?php

declare(strict_types=1);

namespace MyInvoice\Service\Setup;

/**
 * H-33 — režim, ve kterém setup zachází s heslem prvního admina.
 *
 * Dvě pravidla, na kterých to stojí, jsou tady schválně jako volatelný SSOT,
 * ne jako `if` schovaný uvnitř akce:
 *
 *  1. `admin.password_setup_link = true` → heslo v otevřené podobě není potřeba
 *     (vygeneruje se náhodné, které nikdo nikdy nepoužije) a volající místo něj
 *     dostane jednorázový token na nastavení hesla.
 *  2. ⚠️ V tomhle režimu se admin NESMÍ automaticky přihlásit. Setup voláme my
 *     ze serveru, takže by session patřila nám, ne zákazníkovi.
 */
final class SetupPasswordMode
{
    /** Pole v bloku `admin`, kterým se režim zapíná. */
    public const REQUEST_FIELD = 'password_setup_link';

    private function __construct(private readonly bool $setupLink) {}

    /**
     * @param array<string,mixed> $admin
     */
    public static function fromAdminBlock(array $admin): self
    {
        // Striktně `=== true`: jakákoli jiná hodnota (řetězec, 1, null) znamená
        // klasický setup s heslem, ne tiché vypnutí auto-loginu.
        return new self(($admin[self::REQUEST_FIELD] ?? null) === true);
    }

    public function usesSetupLink(): bool
    {
        return $this->setupLink;
    }

    /** Heslo v požadavku je povinné jen tehdy, když si ho zákazník nenastaví sám. */
    public function requiresPlainPassword(): bool
    {
        return !$this->setupLink;
    }

    /** Auto-login (session + cookie) jen mimo režim odkazu — viz pravidlo 2 výše. */
    public function issuesSession(): bool
    {
        return !$this->setupLink;
    }

    public function returnsSetupToken(): bool
    {
        return $this->setupLink;
    }
}
