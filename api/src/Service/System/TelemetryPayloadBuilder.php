<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

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
     */
    public const PAYLOAD_VERSION = 1;

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

        return new self($config, $probe, $version, new ManagedModeGuard($config));
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
            );
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
     * @return array<string,scalar|null>
     */
    public static function fromSummary(array $summary, array $managed, ?string $appVersion): array
    {
        $migrations = is_array($summary['migrations'] ?? null) ? $summary['migrations'] : [];
        $backup     = is_array($summary['backup'] ?? null) ? $summary['backup'] : [];
        $cron       = is_array($summary['cron'] ?? null) ? $summary['cron'] : [];

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
