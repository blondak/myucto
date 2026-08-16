<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel\Isds;

/**
 * Potvrzení, že ISDS zprávu VÝSLOVNĚ přijalo.
 *
 * ── Proč to není prostý `string $dmId` ─────────────────────────────────────
 * Auditovaná knihovna při chybovém stavu ISDS **nehází výjimku** — vrátí objekt,
 * na kterém je `isOk() === false`, a kdo se nezeptá, tiše pokračuje, jako by
 * podání odešlo. U daňového přiznání je tohle nejhorší možná chyba.
 *
 * Její `isOk()` navíc bere jako úspěch každý kód začínající `00`, takže by
 * prošel i `0099`. Proto tahle třída existuje a proto má privátní konstruktor:
 * jedinou cestou k instanci je {@see accepted()}, která trvá na kódu **přesně
 * `0000`** a na neprázdném `dmID`. Absence výjimky instanci nevyrobí.
 */
final readonly class IsdsSendReceipt
{
    /** Jediný kód, který ISDS vrací při skutečném přijetí zprávy. */
    public const STATUS_ACCEPTED = '0000';

    private function __construct(
        public string $messageId,
        public string $statusCode,
    ) {}

    public static function accepted(string $messageId, string $statusCode): self
    {
        if ($statusCode !== self::STATUS_ACCEPTED) {
            throw new \InvalidArgumentException(
                'Za přijetí se smí prohlásit jen stav ' . self::STATUS_ACCEPTED . ', ne ' . $statusCode . '.',
            );
        }
        $messageId = trim($messageId);
        if ($messageId === '') {
            throw new \InvalidArgumentException('Přijatá zpráva musí mít dmID.');
        }

        return new self($messageId, $statusCode);
    }
}
