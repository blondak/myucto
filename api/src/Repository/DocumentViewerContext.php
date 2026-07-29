<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

/**
 * Kontext prohlížejícího uživatele pro per-user scope guard nad DMS dokumenty (Epic F7).
 *
 * Vnější tenant guard (supplier_id) zůstává beze změny; tohle nese vnitřní osu:
 *  - `isAdmin=true`  → vidí VŠECHNY doklady tenanta (i scope='user' cizích uživatelů),
 *  - `userId != null`→ vidí company doklady + vlastní user doklady (owner_user_id = userId),
 *  - `userId = null` → fail-closed: jen company doklady (žádné user-scoped).
 *
 * Staví se v Action vrstvě z AuthMiddleware::ATTR_USER (role + id) a protéká do
 * DocumentRepository read metod. Background joby ho rekonstruují z uloženého kontextu.
 */
final readonly class DocumentViewerContext
{
    private function __construct(
        public ?int $userId,
        public bool $isAdmin,
    ) {}

    /** Admin tenanta — vidí vše (i user-scoped cizích uživatelů). */
    public static function admin(?int $userId = null): self
    {
        return new self($userId, true);
    }

    /** Non-admin uživatel — company + vlastní user doklady. NULL userId = fail-closed (jen company). */
    public static function forUser(?int $userId): self
    {
        return new self($userId, false);
    }

    /** Non-admin bez identity — fail-closed (jen company doklady). */
    public static function companyOnly(): self
    {
        return new self(null, false);
    }

    public static function fromAuthorization(bool $isSuperadmin, ?int $userId): self
    {
        return $isSuperadmin ? self::admin($userId) : self::forUser($userId);
    }

    /** Kompatibilní konstruktor pro starší middleware-less volající. */
    public static function fromRole(string $role, ?int $userId): self
    {
        return self::fromAuthorization($role === 'admin', $userId);
    }
}
