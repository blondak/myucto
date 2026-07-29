<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;

/**
 * Bcrypt cost 12 + pepper z cfg.app.pepper.
 *
 * Pepper se přidává jako suffix k heslu před hashováním. Pokud je pepper prázdný,
 * hashujeme jen heslo (devel režim) — v produkci je třeba mít pepper nastavený.
 *
 * Jedinou výjimkou z costu 12 je běh PHPUnit nad databází `*_test` — viz TEST_COST.
 */
final class PasswordHasher
{
    private const COST = 12;

    /**
     * Cost používaný VÝHRADNĚ v testovacím běhu. Bcrypt je záměrně pomalý — cost 12
     * stojí na téhle mašině ~165 ms na hash a testy hesla hashují v setUp (zakládání
     * uživatelů, step-up tokeny, členství), což dělalo ~6 s z běhu sady.
     *
     * Není to konfigurační hodnota a ZÁMĚRNĚ jí být nemá: config se dá omylem nasadit.
     * Gate je stejná pojistka, jakou používá izolace testovací databáze — jméno DB musí
     * končit na `_test` A ZÁROVEŇ musí běžet PHPUnit. Produkce nemá jak se do téhle
     * větve dostat, takže produkční cost zůstává 12 za všech okolností.
     */
    private const TEST_COST = 4;

    private const MIN_LENGTH = 12;
    private const MAX_LENGTH = 128;

    public function __construct(private readonly Config $config) {}

    public function hash(string $plain): string
    {
        $this->validate($plain);
        return password_hash($this->withPepper($plain), PASSWORD_BCRYPT, ['cost' => $this->cost()]);
    }

    public function verify(string $plain, string $hash): bool
    {
        if ($plain === '' || $hash === '') {
            return false;
        }
        return password_verify($this->withPepper($plain), $hash);
    }

    public function needsRehash(string $hash): bool
    {
        if (!password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => $this->cost()])) {
            return false;
        }
        // V testovací DB leží hashe s produkčním costem 12 (seedy, klon ostré DB).
        // Kdyby je snížený testovací cost prohlásil za zastaralé, LoginAction by je
        // při každém přihlášení přepisoval — chování, které v produkci nenastane.
        // Proto v testech uznáváme oba costy; nebcryptový/poškozený hash propadne dál.
        if (self::testCostApplies($this->config)) {
            return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::COST]);
        }

        return true;
    }

    /**
     * Vždy spustit (i pro neexistující email) → konstantní timing proti user enumeration.
     * Cost musí odpovídat tomu, jakým se hashuje, jinak by dummy větev měla jinou
     * dobu běhu než reálná a rozdíl by prozradil existenci účtu.
     */
    public function dummyVerify(): void
    {
        password_verify('dummy', sprintf('$2y$%02d$', $this->cost()) . str_repeat('a', 53));
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function validate(string $plain): void
    {
        $len = strlen($plain);
        if ($len < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Heslo musí mít alespoň %d znaků.', self::MIN_LENGTH));
        }
        if ($len > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('Heslo nesmí být delší než %d znaků.', self::MAX_LENGTH));
        }
    }

    private function withPepper(string $plain): string
    {
        $pepper = (string) $this->config->get('app.pepper', '');
        return $pepper === '' ? $plain : ($plain . $pepper);
    }

    private function cost(): int
    {
        return self::testCostApplies($this->config) ? self::TEST_COST : self::COST;
    }

    /**
     * Snížený cost platí jen tam, kde nemůže uškodit: pod PHPUnit a proti databázi,
     * jejíž jméno končí na `_test`. Obě podmínky musí platit současně — je to táž
     * pojistka, jakou má tests/bootstrap.php proti běhu testů nad ostrými daty.
     */
    private static function testCostApplies(Config $config): bool
    {
        return defined('PHPUNIT_COMPOSER_INSTALL')
            && str_ends_with((string) $config->get('db.name', ''), '_test');
    }
}
