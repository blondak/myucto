<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use Throwable;

/**
 * ROZSAH ZAPLACENÉ SLUŽBY — kolik místa a jaký tarif má instalace zaplacený.
 *
 * ── Proč to není konfigurace ──────────────────────────────────────────────
 * Do `cfg.local.php` zapisuje `instance.quota_gb` a `instance.plan` zřizování,
 * a to jednou — při založení instance. Zákazník si ale úložiště dokupuje a
 * tarif mění průběžně, a v tu chvíli nemá kdo do konfigurace sáhnout: hosting
 * o naší objednávce neví (dostane jen kvótu, kterou má nastavit na disku) a my
 * na běžící instanci nemáme kam zaklepat.
 *
 * Rozsah zaplacené služby proto vzniká tam, kde se platí — na licenčním serveru
 * — a na instanci se veze s denní obnovou licence. Poslední doručená podoba je
 * v `license.instance_info` (migrace 1524), aby přežila výpadek serveru.
 *
 * ── Pořadí zdrojů ─────────────────────────────────────────────────────────
 * 1. licenční server (poslední doručená hodnota)  ← platí, jakmile existuje
 * 2. konfigurace                                  ← jen než doběhne první obnova
 *
 * Obrácené pořadí by znamenalo, že instance po dokoupení místa napořád ukazuje
 * starou kvótu a vyzývá zákazníka, ať si koupí něco, co už koupené má.
 * {@see quotaSource()} říká, který zdroj zrovna platí — aby to šlo vidět, ne
 * dedukovat z chování.
 *
 * ── ⚠️ null není nula ─────────────────────────────────────────────────────
 * `null` znamená „nevíme, kolik má zákazník zaplaceno" — self-hosted instalace
 * i spravovaná před první obnovou. Nesmí se z něj stát nula: nulová kvóta =
 * 100 % obsazeno = režim jen pro čtení na instalaci, která nic neprovedla.
 * Volající proto musí `null` ošetřit sám; tahle třída ho nikdy nenahrazuje.
 */
final class InstanceEntitlement
{
    public const SOURCE_LICENSE = 'license';
    public const SOURCE_CONFIG  = 'config';
    public const SOURCE_NONE    = 'none';

    private const GIB = 1024 * 1024 * 1024;

    /**
     * Cache na request. `false` = ještě nenačteno, `null` = načteno a není nic.
     * Rozlišit to je nutné, jinak by se prázdný výsledek načítal pořád dokola.
     *
     * @var array<string,mixed>|null|false
     */
    private array|null|false $delivered = false;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /** Zaplacený objem úložiště v bajtech; null = neznámý. */
    public function quotaBytes(): ?int
    {
        $gb = $this->quotaGb();

        return $gb === null ? null : (int) round($gb * self::GIB);
    }

    /** Zaplacený objem úložiště v GiB; null = neznámý. */
    public function quotaGb(): ?float
    {
        $fromLicense = $this->positiveFloat($this->delivered()['quota_gb'] ?? null);
        if ($fromLicense !== null) {
            return $fromLicense;
        }

        return $this->positiveFloat($this->config->get('instance.quota_gb', ''));
    }

    /** Odkud {@see quotaGb()} pochází. */
    public function quotaSource(): string
    {
        if ($this->positiveFloat($this->delivered()['quota_gb'] ?? null) !== null) {
            return self::SOURCE_LICENSE;
        }
        if ($this->positiveFloat($this->config->get('instance.quota_gb', '')) !== null) {
            return self::SOURCE_CONFIG;
        }

        return self::SOURCE_NONE;
    }

    /** Kód tarifu hostingu; null = neznámý. Jen popisný údaj, nic na něm nevisí. */
    public function plan(): ?string
    {
        return $this->nonEmpty($this->delivered()['plan'] ?? null)
            ?? $this->nonEmpty($this->config->get('instance.plan', ''));
    }

    /** Od kdy je instalace spravovaná (YYYY-MM-DD); null = neznámo. */
    public function managedSince(): ?string
    {
        return $this->nonEmpty($this->delivered()['managed_since'] ?? null)
            ?? $this->nonEmpty($this->config->get('instance.managed_since', ''));
    }

    /**
     * Kdy licenční server rozsah naposled potvrdil (ATOM), nebo null.
     *
     * Není to totéž co „kdy se to změnilo": server posílá rozsah při každé
     * obnově. Slouží k tomu, aby obrazovka mohla říct, jak čerstvý údaj ukazuje.
     */
    public function deliveredAt(): ?string
    {
        return $this->nonEmpty($this->delivered()['delivered_at'] ?? null);
    }

    /**
     * Celý poslední doručený rozsah, jak přišel ze serveru. Pole, která server
     * přidá později, se tím dostanou ven bez zásahu do kódu.
     *
     * @return array<string,mixed>
     */
    public function deliveredRaw(): array
    {
        return $this->delivered() ?? [];
    }

    /** Zahodí cache — po zápisu nové hodnoty v rámci téhož requestu. */
    public function forget(): void
    {
        $this->delivered = false;
    }

    /**
     * Poslední doručený rozsah z licenčního serveru.
     *
     * ⚠️ Nesmí vyhodit: čte se z něj i při vykreslování běžné stránky a chybějící
     * tabulka (instalace před migrací) ani rozbitý JSON nesmí shodit aplikaci —
     * neznámý rozsah je stav, se kterým volající umí pracovat.
     *
     * @return array<string,mixed>|null
     */
    private function delivered(): ?array
    {
        if ($this->delivered !== false) {
            return $this->delivered;
        }
        $this->delivered = null;

        try {
            if (!$this->db->hasTable('license') || !$this->db->hasColumn('license', 'instance_info')) {
                return null;
            }
            $stmt = $this->db->pdo()->query('SELECT instance_info FROM license WHERE id = 1');
            $raw = $stmt === false ? null : $stmt->fetchColumn();
        } catch (Throwable) {
            return null;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        $this->delivered = is_array($decoded) ? $decoded : null;

        return $this->delivered;
    }

    /** Kladné číslo, nebo null. Nula i záporné číslo jsou „nevíme", ne kvóta. */
    private function positiveFloat(mixed $raw): ?float
    {
        if (!is_int($raw) && !is_float($raw) && (!is_string($raw) || trim($raw) === '')) {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_FLOAT);

        return ($value === false || $value <= 0.0) ? null : $value;
    }

    private function nonEmpty(mixed $raw): ?string
    {
        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }
}
