<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Cache;

use MyInvoice\Infrastructure\Config\Config;
use Throwable;

/**
 * Cache opakovaných dotazů na málo se měnící entity (licence, uživatel, dodavatel)
 * s invalidací při zápisu.
 *
 * ## Proč JEDEN klíč pro všechno a ne klíč na položku
 *
 * Tohle je ta část, kterou musí diktovat měření, ne intuice. Na loopbacku stojí:
 *
 *     Redis GET                     0,297 ms
 *     SELECT MIN(id) FROM supplier  0,113 ms
 *
 * Cachované dotazy jsou levné indexované lookupy nad UŽ OTEVŘENÝM spojením,
 * kdežto Redis potřebuje síťový round-trip. Klíč na položku by znamenal ~9
 * round-tripů (2,67 ms) místo ~0,46 ms dotazů — cache by request ZPOMALILA.
 *
 * Proto je celý obsah v jediném klíči: **jeden GET na request**. Zápis do hlídané
 * tabulky ho smaže (jeden DEL). Invalidace je tím pádem hrubší — zápis do `users`
 * zahodí i položky dodavatele —, ale to je levné: zápisy jsou proti čtením vzácné
 * a znovunaplnění stojí ty původní desetiny milisekundy.
 *
 * Hrubší invalidace má navíc bezpečnostní výhodu: nejde zapomenout na položku,
 * o které invalidační kód neví.
 *
 * ## Proč se invalidace odchytává až na úrovni PDO
 *
 * Uživatelé se zapisují na desítkách míst (CRUD, změna hesla, MFA, přiřazení role,
 * deaktivace…). Vyjmenovat je a doufat, že na žádné další nikdo nezapomene, je
 * přesně ten druh invarianty, která tiše shnije. Zápisy proto hlídá
 * {@see \MyInvoice\Infrastructure\Database\LoggingPdo} — každý INSERT/UPDATE/DELETE
 * projde přes PDO, takže detekce nejde obejít.
 *
 * Detekce je ZÁMĚRNĚ přehnaně opatrná: stačí, aby se ve write příkazu objevilo
 * jméno hlídané tabulky, a skupina se přetočí. Zbytečná invalidace stojí jeden
 * dotaz navíc; zmeškaná by znamenala, že uživatel po odebrání role o ni nepřijde.
 *
 * ## Degradace
 *
 * Bez Redisu (`redis.enabled=false` nebo nedostupný) se cache chová jako průchozí —
 * `remember()` prostě zavolá producenta. Instalace bez Redisu tedy běží přesně jako
 * dřív. Per-request memo funguje vždy, i bez Redisu.
 */
final class EntityCache
{
    public const GROUP_LICENSE = 'license';
    public const GROUP_USER = 'user';
    public const GROUP_SUPPLIER = 'supplier';
    public const GROUP_ACCOUNTING = 'accounting';

    /**
     * Tabulka → skupina. Zápis do kterékoli z nich přetočí generaci své skupiny.
     *
     * `users`/`roles`/oprávnění jsou ve stejné skupině schválně: odebrání role je
     * bezpečnostní událost a musí se projevit okamžitě, i kdyby se zapisovalo jen
     * do vazební tabulky.
     *
     * Skupina `accounting` je široká záměrně. Drží náhled doplatku daně, což je
     * projekce celoročního účetnictví — závisí prakticky na všem, co se účtuje.
     * Vyjmenovat přesně ty tabulky, které do výpočtu vstupují, by znamenalo
     * udržovat seznam synchronně s 2900řádkovou službou; přeinvalidovat je levné
     * (výpočet se prostě spočítá znovu), podinvalidovat by znamenalo ukazovat
     * uživateli špatnou částku daně.
     */
    private const TABLE_GROUPS = [
        'license'                => self::GROUP_LICENSE,
        'users'                  => self::GROUP_USER,
        'roles'                  => self::GROUP_USER,
        'role_permissions'       => self::GROUP_USER,
        'user_suppliers'         => self::GROUP_USER,
        'supplier'               => self::GROUP_SUPPLIER,
        'journal_entries'        => self::GROUP_ACCOUNTING,
        'journal_lines'          => self::GROUP_ACCOUNTING,
        'invoices'               => self::GROUP_ACCOUNTING,
        'invoice_items'          => self::GROUP_ACCOUNTING,
        'purchase_invoices'      => self::GROUP_ACCOUNTING,
        'purchase_invoice_items' => self::GROUP_ACCOUNTING,
        'bank_transactions'      => self::GROUP_ACCOUNTING,
        'cash_documents'         => self::GROUP_ACCOUNTING,
        'assets'                 => self::GROUP_ACCOUNTING,
        'accounting_periods'     => self::GROUP_ACCOUNTING,
        'closing_steps'          => self::GROUP_ACCOUNTING,
        'tax_returns'            => self::GROUP_ACCOUNTING,
        'tax_advance_schedules'  => self::GROUP_ACCOUNTING,
    ];

    /** Write příkazy, po kterých má smysl hledat jméno tabulky. */
    private const WRITE_VERBS = '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/i';

    /** Jediný Redis klíč, pod kterým žije celý obsah cache. */
    private const BLOB_KEY = 'ec:blob';

    /** @var array<string,mixed> memo v rámci requestu — platí i bez Redisu */
    private array $memo = [];

    /** @var array<string,mixed>|null obsah načtený z Redisu (jednou za request) */
    private ?array $blob = null;

    /** Objevily se v tomhle requestu nové položky? */
    private bool $blobDirty = false;

    private bool $flushRegistered = false;

    private readonly bool $enabled;
    private readonly int $ttl;

    public function __construct(
        private readonly RedisFactory $redis,
        private readonly Config $config,
    ) {
        // Pod PHPUnitem vypnuto: testy mění data přímo v DB i mimo aplikaci
        // (fixtures, TRUNCATE) a sdílená cache by mezi nimi přenášela stav.
        $this->enabled = !defined('PHPUNIT_COMPOSER_INSTALL')
            && (bool) $config->get('cache.entities_enabled', true);
        $this->ttl = max(1, (int) $config->get('cache.entities_ttl', 300));
    }

    /**
     * Průchozí instance — `remember()` vždy zavolá producenta.
     *
     * Pro testovací dvojníky a pro služby stavěné ručně mimo kontejner: nemusí
     * řešit Redis a chovají se jako před zavedením cache.
     */
    public static function disabled(): self
    {
        return new self(new RedisFactory(new Config([])), new Config(['cache' => ['entities_enabled' => false]]));
    }

    /**
     * Vrátí hodnotu z cache, nebo ji spočítá producentem a uloží.
     *
     * @template T
     * @param callable():T $producer
     * @return T
     */
    public function remember(string $group, string $key, callable $producer): mixed
    {
        // Vypnutá cache je ÚPLNĚ průchozí — ani memo v rámci requestu. Memo se totiž
        // čistí jen přes invalidateGroup(), a tu volá WriteWatcher, na který vypnutá
        // instance (např. z disabled()) napojená není. Držet za takové situace memo
        // by znamenalo, že volající po vlastním zápisu dostane starou hodnotu.
        if (!$this->enabled) {
            return $producer();
        }

        $memoKey = $group . '|' . $key;
        if (array_key_exists($memoKey, $this->memo)) {
            return $this->memo[$memoKey];
        }

        $blob = $this->blob();
        if (array_key_exists($memoKey, $blob)) {
            return $this->memo[$memoKey] = $blob[$memoKey];
        }

        $value = $producer();
        $this->memo[$memoKey] = $value;

        // Zápis do Redisu se odkládá na konec requestu (flush()), ať i request,
        // který objeví šest nových položek, zaplatí jediný SET.
        $this->blob[$memoKey] = $value;
        $this->blobDirty = true;

        return $value;
    }

    /**
     * Uloží obsah do Redisu, pokud v tomhle requestu přibyly položky.
     * Volá se ze shutdown handleru — viz {@see registerFlush()}.
     */
    public function flush(): void
    {
        if (!$this->blobDirty || !$this->enabled || $this->blob === null) {
            return;
        }
        $this->blobDirty = false;

        $payload = json_encode($this->blob, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        $ttl = $this->ttl;
        $this->redis->run(static fn ($c) => $c->setex(self::BLOB_KEY, $ttl, $payload));
    }

    /**
     * Obsah cache — z Redisu právě JEDNÍM GETem za request.
     *
     * @return array<string,mixed>
     */
    private function blob(): array
    {
        if ($this->blob !== null) {
            return $this->blob;
        }
        $this->blob = [];

        if (!$this->enabled) {
            return $this->blob;
        }
        $this->registerFlush();

        $raw = $this->redis->run(static fn ($c) => $c->get(self::BLOB_KEY));
        if (!is_string($raw) || $raw === '') {
            return $this->blob;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->blob;
        }

        return $this->blob = $decoded;
    }

    private function registerFlush(): void
    {
        if ($this->flushRegistered) {
            return;
        }
        $this->flushRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush();
        });
    }

    /**
     * Přetočí generaci skupiny — všechny její dosavadní klíče se stanou nedosažitelnými.
     */
    public function invalidateGroup(string $group): void
    {
        // Memo requestu padá jen pro dotčenou skupinu: zápis do DB právě proběhl
        // a zbytek requestu nesmí pracovat se starou hodnotou.
        foreach (array_keys($this->memo) as $memoKey) {
            if (str_starts_with($memoKey, $group . '|')) {
                unset($this->memo[$memoKey]);
            }
        }

        if (!$this->enabled) {
            return;
        }

        // V Redisu se maže CELÝ blob, ne jen skupina — je to jeden DEL místo
        // čtení, filtrování a zpětného zápisu. Zápisy jsou proti čtením vzácné
        // a znovunaplnění stojí desetiny milisekundy.
        $this->blob = [];
        $this->blobDirty = false;
        $this->redis->run(static fn ($c) => $c->del([self::BLOB_KEY]));
    }

    /**
     * Skupiny dotčené SQL příkazem. Prázdné pole = není co invalidovat.
     *
     * @return list<string>
     */
    public static function groupsForSql(string $sql): array
    {
        if (preg_match(self::WRITE_VERBS, $sql) !== 1) {
            return [];
        }

        $groups = [];
        foreach (self::TABLE_GROUPS as $table => $group) {
            // Hranice slova, ať `users` netrefí `user_suppliers` a naopak — obojí
            // je sice ve stejné skupině, ale u `supplier` vs. `supplier_bank_accounts`
            // by volnější shoda invalidovala zbytečně často.
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $sql) === 1) {
                $groups[$group] = true;
            }
        }

        return array_keys($groups);
    }


}
