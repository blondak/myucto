<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Update\NativeUpdateService;
use MyInvoice\Service\Update\VersionService;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Provozní telemetrie instance pro licenční server (H-21).
 *
 * Hosting odložil hromadné nasazování za ostrý start, takže flotilu nikdo
 * nedrží na jedné verzi. Bez jejich rozhraní je licenční server jediný kanál,
 * kterým se dá zjistit, co na instalacích skutečně běží — a instance se na něj
 * hlásí už dnes při denní obnově licence. Telemetrie se proto **přiváží s tím,
 * co už jede**: žádný nový kanál, žádný nový cron.
 *
 * ⚠️ **NEODESÍLÁ SE NIC OSOBNÍHO ANI IDENTIFIKUJÍCÍHO.** Payload je uzavřený
 * WHITELIST {@see self::FIELDS} — čísla, stáří v sekundách, booleany a verze.
 * Nikdy sem nepatří:
 *
 *   - jména firem, uživatelů, e-maily, IČ/DIČ,
 *   - počty dokladů, obraty, jakákoli obchodní data,
 *   - hostname, doména, IP, cesty na disku, jména souborů (ani migrací),
 *   - chybové hlášky a stack trace (nesou cesty i data).
 *
 * Instanci licenční server identifikuje tím, čím se mu hlásí dnes (licenční
 * klíč + `instance_id`) — telemetrie k tomu nepřidává NIC. `build()` proto
 * skládá výsledek přes {@see self::FIELDS} a cokoli mimo whitelist zahodí;
 * „jen jedno pole navíc" tak neprojde bez úpravy whitelistu a testu
 * (`api/tests/Unit/Service/System/TelemetryPayloadBuilderTest.php`).
 *
 * Zdrojem hodnot je {@see InstanceHealthProbe} — tentýž sběr, ze kterého žije
 * `/api/health`. Druhá implementace téhož by se s ním nutně rozešla.
 *
 * ── Obsazení místa (H-10 × H-21) ──────────────────────────────────────────
 * Součástí payloadu je i poslední ZMĚŘENÉ obsazení instance a stav kvóty.
 * Důvod není diagnostika, ale doložitelnost: totéž číslo měří i hosting
 * (`GET /v1/instances/{id}` → `usage`) a dokud se obě strany nesejdou na jednom
 * místě, nemá při sporu o kvótu ani jedna čím doložit, které číslo platí.
 * Porovnává je licenční server ({@see \UsageReconciliation} na prodejním webu).
 *
 * Tři pravidla, bez kterých je to porovnání k ničemu:
 *
 *   1. ⚠️ **`null` znamená „neměřeno", ne nulu.** Nezměřená instance nesmí
 *      dorazit na server jako „0 bajtů, vše v pořádku" — pak by porovnání
 *      hlásilo obří neshodu tam, kde jen chybí měření. Žádný `(int)` cast,
 *      žádné `?? 0`.
 *   2. **`usage_truncated`** říká, že měření narazilo na strop
 *      ({@see StorageUsageMeter::MAX_ENTRIES} / `MAX_SECONDS`) a je to DOLNÍ
 *      ODHAD. Bez toho příznaku by useknuté měření vypadalo jako rozejitá
 *      definice, přestože je to jen nedoběhlý průchod.
 *   3. **Zálohy jdou zvlášť** (`usage_backup_bytes`) a do `usage_bytes`
 *      NEVSTUPUJÍ — přesně jako u hostingu. Kdyby vstupovaly, vycházel by
 *      rozdíl proti hostingu právě o velikost záloh a nikdo by nepoznal proč.
 *
 * ⚠️ Čte se HOTOVÉ číslo z databáze ({@see StorageQuotaPolicy::evaluate()} →
 * {@see StorageUsageMeter::latest()}). Telemetrie běží v cronu při obnově
 * licence a NESMÍ spustit průchod stromem — od měření je vlastní úloha.
 *
 * ⚠️ Stáří dispatcheru se čte z DATABÁZE, ne z logu: hosting náš wrapper
 * `cmd/cron-dispatch.sh` obchází a `log/cron/dispatch-*.log` na jejich instanci
 * vůbec nevzniká. O to se stará probe.
 *
 * Vypínatelnost: `license.telemetry.enabled`. Výchozí stav se liší podle toho,
 * čí je to provoz — ve spravovaném režimu (`app.managed`) zapnuto, u self-hosted
 * instalací vypnuto. Diagnostika cizí flotily je naše věc, ne věc zákazníka,
 * který si aplikaci provozuje sám.
 */
final class TelemetryPayloadBuilder
{
    /**
     * Verze tvaru payloadu. Instance se aktualizují postupně, takže na serveru
     * poběží starý a nový tvar vedle sebe — bez tohohle čísla by se nedalo
     * poznat, jestli chybějící pole znamená „starší instance" nebo „porucha".
     *
     * Zvyš při KAŽDÉ změně {@see self::FIELDS}.
     *
     * 2 = přibylo obsazení místa a stav kvóty (`usage_*`, `quota_*`).
     */
    public const PAYLOAD_VERSION = 2;

    /**
     * Uzavřený seznam klíčů, které smí odejít z instance. Pořadí je zároveň
     * pořadím v payloadu. Přidání položky je vědomé rozhodnutí o tom, co
     * o zákazníkovi víme — ne detail implementace.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'telemetry_version',
        'app_version',
        'migrations_applied',
        'migrations_pending',
        'migrations_up_to_date',
        'backup_age_sec',
        'backup_fresh',
        'dispatcher_age_sec',
        'dispatcher_fresh',
        'cron_mode',
        'maintenance',
        'managed',
        'managed_provider',
        // Obsazení místa — poslední ULOŽENÉ měření, nic se tu neměří znovu.
        // ⚠️ Všechno nullable: null = „neměřeno", nikdy ne nula.
        'usage_bytes',
        'usage_db_bytes',
        'usage_files_bytes',
        'usage_backup_bytes',
        'usage_measured_at',
        'usage_truncated',
        // Kvóta tak, jak ji vidí instance. `quota_state` odlišuje „změřeno a je
        // to v pořádku" (`ok`) od „nezměřeno" (`unknown`) a od „režim se tu
        // neuplatňuje" (`disabled`) — na dálku se to jinak nerozezná.
        'quota_limit_bytes',
        'quota_percent',
        'quota_state',
    ];

    /** Konfigurační klíč přepínače. */
    public const CONFIG_KEY = 'license.telemetry.enabled';

    /**
     * Strop délky textových polí. `cron_mode` i `managed_provider` jsou krátké
     * kódy; delší hodnota je konfigurační omyl a nemá cenu ji vozit dál.
     */
    private const TEXT_MAX = 32;

    public function __construct(
        private readonly Config $config,
        private readonly InstanceHealthProbe $probe,
        private readonly VersionService $version,
        private readonly ManagedModeGuard $managed,
        /**
         * Vyhodnocení kvóty nad POSLEDNÍM ULOŽENÝM měřením. Volitelná schválně:
         * dvojníci v testech i starší volání staví builder bez ní a payload pak
         * jen drží tvar s `null` — což je správná odpověď na „neměřeno".
         * V provozu ji vždycky dodá {@see self::forRuntime()}.
         */
        private readonly ?StorageQuotaPolicy $quota = null,
    ) {}

    /**
     * Postaví builder z toho, co má po ruce {@see \MyInvoice\Service\License\LicenseService}.
     *
     * Existuje proto, že licenční službu skládá kontejner explicitním výčtem
     * argumentů a PHP-DI navíc VOLITELNÉ parametry autowiringem přeskakuje —
     * kdyby builder přišel jako `?TelemetryPayloadBuilder $t = null`, zůstal by
     * v provozu tiše null a telemetrie by nikdy neodešla. Všechny závislosti
     * probe jsou bezstavové a levné (Config, případně jedno PDO), takže je
     * lze postavit tady bez rizika duplicitního stavu.
     */
    public static function forRuntime(Connection $db, Config $config): self
    {
        $appUrl  = new AppUrlConfiguration($config, new HostnameNormalizer(), new NullLogger());
        $version = new VersionService($db, new NativeUpdateService());

        $probe = new InstanceHealthProbe(
            $db,
            $config,
            new MaintenanceLock($config),
            $appUrl,
            new TenantDomainFeature($config),
            new EnvironmentCheckService($db, $config, new RedisProbe($config), $version, $appUrl),
        );

        $managed = new ManagedModeGuard($config);

        // ⚠️ Meter se sem předává JEN kvůli `latest()` — jednomu indexovanému
        // řádku. Průchod stromem spouští výhradně vlastní cronová úloha; obnova
        // licence si ho dovolit nesmí.
        $quota = new StorageQuotaPolicy(
            $config,
            $managed,
            new StorageUsageMeter($db, $config),
            new InstanceEntitlement($db, $config),
        );

        return new self($config, $probe, $version, $managed, $quota);
    }

    /**
     * Je odesílání zapnuté?
     *
     * Výchozí stav je odvozený od režimu instalace: spravovaná flotila ano,
     * self-hosted ne. Explicitní `license.telemetry.enabled` má vždy přednost —
     * včetně vypnutí ve spravovaném režimu.
     */
    public function isEnabled(): bool
    {
        $configured = $this->config->get(self::CONFIG_KEY, null);
        if ($configured === null) {
            return $this->managed->isManaged();
        }

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Payload k přiložení k obnově licence, nebo `null` když je telemetrie
     * vypnutá nebo se ji nepodařilo sestavit.
     *
     * ⚠️ Nikdy nevyhazuje. Volající obnovuje licenci — tedy to, na čem stojí
     * provoz zákazníka; diagnostika je to, co chceme my. Když se sběr nepodaří,
     * obnova musí proběhnout stejně.
     *
     * @return array<string,scalar|null>|null
     */
    public function build(): ?array
    {
        try {
            if (!$this->isEnabled()) {
                return null;
            }

            return self::fromSummary(
                $this->probe->summary(),
                $this->probe->managed(),
                $this->version->getCurrentVersion(),
                $this->usageStatus(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Stav kvóty nad posledním uloženým měřením, nebo `null`, když se ho
     * nepodařilo zjistit.
     *
     * ⚠️ `evaluate()` je ČTECÍ cesta — {@see StorageQuotaPolicy::evaluate()}
     * se ptá {@see StorageUsageMeter::latest()}. Nikdy tu nesmí být `measure()`
     * ani `measureIfStale()`: telemetrie se veze s obnovou licence a spustit
     * jí průchod souborovým stromem by z levné noční úlohy udělalo minuty I/O.
     *
     * `null` z výjimky je správně: „nevím" se pak projeví jako `null` v každém
     * `usage_*` poli, ne jako nula.
     *
     * @return array<string,mixed>|null
     */
    private function usageStatus(): ?array
    {
        if ($this->quota === null) {
            return null;
        }

        try {
            return $this->quota->evaluate()->toArray();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Čistá projekce zdravotního souhrnu do odesílaného payloadu — jediné místo,
     * které rozhoduje, CO z instance odejde.
     *
     * Je záměrně statická a bez závislostí, aby na ni šla napsat brána: test
     * krmí projekci souhrnem plným identifikujících polí (hostname, jména firem,
     * seznam nedoběhlých migrací s názvy souborů, cesty) a ověřuje, že v payloadu
     * nezůstane nic z toho. Whitelist se tak nedá obejít „jen jedním polem navíc"
     * někde v datovém zdroji.
     *
     * @param array<string,mixed> $summary {@see InstanceHealthProbe::summary()}
     * @param array<string,mixed> $managed {@see InstanceHealthProbe::managed()}
     * @param array<string,mixed>|null $usage {@see StorageQuotaStatus::toArray()};
     *        `null` = kvótu se nepodařilo vyhodnotit → všechna `usage_*` pole
     *        zůstanou `null`, tedy „neměřeno".
     * @return array<string,scalar|null>
     */
    public static function fromSummary(array $summary, array $managed, ?string $appVersion, ?array $usage = null): array
    {
        $migrations = is_array($summary['migrations'] ?? null) ? $summary['migrations'] : [];
        $backup     = is_array($summary['backup'] ?? null) ? $summary['backup'] : [];
        $cron       = is_array($summary['cron'] ?? null) ? $summary['cron'] : [];

        $usage       = $usage ?? [];
        $measurement = is_array($usage['measurement'] ?? null) ? $usage['measurement'] : [];

        // ⚠️ Jediná legální otázka na „máme čím počítat".
        //
        // Nezměřený snapshot má `usage_bytes = null`, ale `truncated = false` —
        // kdyby se `usage_truncated` odvozovalo přímo z něj, poslala by nezměřená
        // instance „změřeno celé, neuseknuto" o čísle, které neexistuje. Proto
        // se VŠECHNA měřená pole čtou až za touhle branou.
        $measured = ($measurement['measured'] ?? null) === true;

        $raw = [
            'telemetry_version'     => self::PAYLOAD_VERSION,
            'app_version'           => self::text($appVersion),
            'migrations_applied'    => self::int($migrations['applied'] ?? null),
            'migrations_pending'    => self::int($migrations['pending'] ?? null),
            'migrations_up_to_date' => self::bool($migrations['up_to_date'] ?? null),
            'backup_age_sec'        => self::int($backup['age_sec'] ?? null),
            'backup_fresh'          => self::bool($backup['fresh'] ?? null),
            'dispatcher_age_sec'    => self::int($cron['dispatcher_age_sec'] ?? null),
            'dispatcher_fresh'      => self::bool($cron['dispatcher_fresh'] ?? null),
            'cron_mode'             => self::text($cron['mode'] ?? null),
            'maintenance'           => self::bool($summary['maintenance'] ?? null) ?? false,
            'managed'               => self::bool($managed['managed'] ?? null) ?? false,
            'managed_provider'      => self::text($managed['managed_provider'] ?? null),

            // ⚠️ Živá data = soubory BEZ adresáře záloh + databáze. Zálohy mají
            // vlastní pole a do `usage_bytes` se NEPŘIČÍTAJÍ — hosting je počítá
            // stejně a rozdíl přesně o jejich velikost by nikdo nerozklíčoval.
            'usage_bytes'           => $measured ? self::int($measurement['usage_bytes'] ?? null) : null,
            'usage_db_bytes'        => $measured ? self::int($measurement['database_bytes'] ?? null) : null,
            'usage_files_bytes'     => $measured ? self::int($measurement['files_bytes'] ?? null) : null,
            'usage_backup_bytes'    => $measured ? self::int($measurement['backup_bytes'] ?? null) : null,
            'usage_measured_at'     => $measured ? self::timestamp($measurement['measured_at'] ?? null) : null,
            // Změřeno, ale useknuto = DOLNÍ ODHAD. `false` smí vzniknout jen
            // tehdy, když měření opravdu proběhlo celé.
            'usage_truncated'       => $measured ? (self::bool($measurement['truncated'] ?? null) ?? false) : null,

            // Kvóta je konfigurace, ne měření — hlásí se i tehdy, když se ještě
            // neměřilo (a naopak `quota_percent` bez měření zůstává null).
            'quota_limit_bytes'     => self::int($usage['quota_bytes'] ?? null),
            'quota_percent'         => self::float($usage['percent'] ?? null),
            'quota_state'           => self::state($usage['state'] ?? null),
        ];

        // Whitelist, ne blacklist: co není ve FIELDS, ven nejde — a co ve FIELDS
        // je a chybí v $raw, se vyplní nullem, ať má server vždy stejný tvar.
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = $raw[$field] ?? null;
        }

        return $out;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || is_float($value) ? (int) $value : null;
    }

    private static function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * Procenta jdou jako číslo, ne jako text — a bez `?? 0`. NAN/INF by se
     * v JSONu nezakódovaly a celé hlášení by zmizelo, proto padají na null.
     */
    private static function float(mixed $value): ?float
    {
        if (is_bool($value) || (!is_int($value) && !is_float($value))) {
            return null;
        }
        $value = (float) $value;
        if (is_nan($value) || is_infinite($value)) {
            return null;
        }

        return round($value, 2);
    }

    /**
     * Čas měření jako ISO-8601 v UTC.
     *
     * ⚠️ Vstup se NEKOPÍRUJE, ale PŘEPARSUJE a přeformátuje. Je to jediné pole
     * payloadu, které nese čas, a zároveň jediná cesta, kterou by se dal textem
     * protlačit obsah, o kterém nikdo nerozhodl — po přeformátování má výsledek
     * pevný tvar `2026-08-21T04:15:00Z` a nic jiného z něj nevyleze. Co se
     * nepodaří přečíst jako čas, je `null`, tedy „neměřeno".
     */
    private static function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $at = new DateTimeImmutable(trim($value));
        } catch (Throwable) {
            return null;
        }

        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Stav kvóty smí být jen některá z hodnot {@see StorageQuotaState} —
     * whitelist i uvnitř textového pole. Neznámý kód je „nevím", ne text
     * k přeposlání.
     */
    private static function state(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return StorageQuotaState::tryFrom(trim($value))?->value;
    }

    /**
     * Text prochází jen jako krátký strojový kód — bez diakritiky se neřeší,
     * ale délka ano: nechceme z instance vozit dlouhé řetězce, které by mohly
     * nést cokoli dalšího.
     */
    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || $value === 'unknown') {
            return null;
        }

        return mb_substr($value, 0, self::TEXT_MAX);
    }
}
