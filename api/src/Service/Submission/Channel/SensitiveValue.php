<?php

declare(strict_types=1);

namespace MyInvoice\Service\Submission\Channel;

/**
 * Obálka na tajný údaj (heslo do datové schránky, passphrase k certifikátu),
 * kterou nejde omylem vypsat.
 *
 * ── Proč handle a ne prostě private property ──────────────────────────────────
 * `private string $value` uniká hned třemi cestami: `var_dump`, `print_r`
 * i `var_export` privátní property vypisují, `json_encode` je sice nevypíše,
 * ale stačí jedno `get_object_vars()` uvnitř třídy. Uzávěra místo property
 * taky nepomůže — PHP 8.5 vypisuje `["static"]` vazby uzávěry ve `var_dump`
 * i `print_r` (ověřeno na 8.5.9), takže by se heslo objevilo doslova.
 *
 * Instance proto nedrží hodnotu, ale náhodný handle do statického trezoru.
 * Výpis objektu ukáže jen ten handle, což je náhodný hex bez významu.
 *
 * ── Proč {@see fromProducer()} a ne konstruktor s plaintextem ────────────────
 * PHP dává argumenty volání do stack trace (`getTraceAsString()` je zkrátí na
 * 15 znaků, ale vypíše). Kdyby se instance tvořila jako `new SensitiveValue($heslo)`,
 * stačila by libovolná výjimka o pár řádků níž a heslo je v logu. Producer
 * vrací hodnotu NÁVRATOVOU HODNOTOU, a ta v trace není.
 */
final class SensitiveValue implements \JsonSerializable, \Stringable
{
    public const REDACTED = '[skryto]';

    /** @var array<string,string> */
    private static array $vault = [];

    private function __construct(private readonly string $handle) {}

    /**
     * Jediná cesta k instanci. `$producer` musí vrátit plaintext — nikdy ho
     * nepředávej jako argument, viz komentář u třídy.
     *
     * @param callable():string $producer
     */
    public static function fromProducer(callable $producer): self
    {
        $handle = bin2hex(random_bytes(16));
        self::$vault[$handle] = $producer();
        return new self($handle);
    }

    public function reveal(): string
    {
        $value = self::$vault[$this->handle] ?? null;
        if ($value === null) {
            throw new \LogicException('Tajná hodnota už není k dispozici.');
        }
        return $value;
    }

    public function isEmpty(): bool
    {
        return (self::$vault[$this->handle] ?? '') === '';
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    /** Serializace by tajnou hodnotu vytáhla ven mimo trezor. */
    public function __serialize(): array
    {
        throw new \LogicException('Tajnou hodnotu nelze serializovat.');
    }

    public function __destruct()
    {
        unset(self::$vault[$this->handle]);
    }
}
