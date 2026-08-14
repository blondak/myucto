<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\Update\VersionService;
use ZipArchive;

/**
 * Diagnostický balíček k incidentu podpory.
 *
 * Vzniká VÝHRADNĚ na vyžádání administrátora, zapisuje se na disk instalace a
 * odtud si ho zákazník stáhne. Aplikace ho nikam neodesílá — na portál podpory
 * ho zákazník přiloží sám, běžnou cestou jako každou jinou přílohu.
 *
 * Logy jsou vědomý opt-in (`include_logs`), ve výchozím stavu v balíčku NEJSOU.
 *
 * Balíček se po 24 hodinách maže: nemá smysl, aby na disku ležel soubor
 * s výřezem logů déle, než trvá jeho nahrání k incidentu.
 */
final class DiagnosticsBundleService
{
    /** Limit přílohy na portálu podpory je 25 MB — pod něj se musíme vejít. */
    public const MAX_BUNDLE_BYTES = 25 * 1024 * 1024;

    private const RETENTION_SEC = 24 * 3600;
    private const PREFIX        = 'myucto-diagnostika-';

    public function __construct(
        private readonly EnvironmentCheckService $environment,
        private readonly DiagnosticsConfigAllowlist $allowlist,
        private readonly DiagnosticsLogReader $logs,
        private readonly VersionService $version,
        private readonly LicenseService $license,
        private readonly Connection $db,
        private readonly RedisProbe $redis,
        private readonly SecretEncryption $crypto,
    ) {}

    /**
     * Náhled obsahu — položku po položce, včetně velikostí. Uživatel musí vidět,
     * co přesně odesílá, dřív než balíček vznikne.
     *
     * @return array{
     *     items:list<array{name:string,kind:string,bytes:int,sensitive:bool}>,
     *     total_bytes:int,
     *     within_limit:bool,
     *     max_bytes:int,
     *     options:array<string,mixed>,
     *     log_days:list<string>
     * }
     */
    public function preview(DiagnosticsBundleOptions $options): array
    {
        $items = [];
        foreach ($this->collect($options) as $name => $entry) {
            $items[] = [
                'name'      => $name,
                'kind'      => $entry['kind'],
                'bytes'     => strlen($entry['content']),
                'sensitive' => $entry['sensitive'],
            ];
        }
        $total = array_sum(array_column($items, 'bytes'));

        return [
            'items'        => $items,
            'total_bytes'  => $total,
            'within_limit' => $total <= self::MAX_BUNDLE_BYTES,
            'max_bytes'    => self::MAX_BUNDLE_BYTES,
            'options'      => $options->toArray(),
            'log_days'     => $options->includeLogs ? $this->logs->daysInWindow($options->days) : [],
        ];
    }

    /**
     * Sestaví ZIP a vrátí jeho popis. Nikam ho neodesílá.
     *
     * @return array{ok:bool,error:?string,filename:?string,bytes:?int,sha256:?string,items:?list<array<string,mixed>>}
     */
    public function build(DiagnosticsBundleOptions $options): array
    {
        $this->cleanup();

        $dir = RuntimePaths::storage('support');
        if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
            return $this->fail('storage_unavailable');
        }
        if (!class_exists(ZipArchive::class)) {
            return $this->fail('zip_unavailable');
        }

        $entries = $this->collect($options);

        // Nejdřív manifest — musí popisovat přesně to, co v archivu skončí.
        $manifest = $this->manifest($entries, $options);
        $entries['manifest.json'] = [
            'kind'      => 'manifest',
            'sensitive' => false,
            'content'   => self::json($manifest),
        ];

        $total = 0;
        foreach ($entries as $entry) {
            $total += strlen($entry['content']);
        }
        if ($total > self::MAX_BUNDLE_BYTES) {
            return $this->fail('too_large');
        }

        $filename = self::PREFIX . $this->instanceSlug() . '-' . date('Ymd-His') . '.zip';
        $path     = $dir . DIRECTORY_SEPARATOR . $filename;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->fail('zip_open_failed');
        }
        foreach ($entries as $name => $entry) {
            $zip->addFromString($name, $entry['content']);
        }
        if (!$zip->close()) {
            return $this->fail('zip_write_failed');
        }
        @chmod($path, 0o640);

        $bytes = (int) @filesize($path);
        if ($bytes > self::MAX_BUNDLE_BYTES) {
            @unlink($path);
            return $this->fail('too_large');
        }

        return [
            'ok'       => true,
            'error'    => null,
            'filename' => $filename,
            'bytes'    => $bytes,
            'sha256'   => (string) hash_file('sha256', $path),
            'items'    => array_values(array_map(
                static fn (string $name, array $e): array => [
                    'name'      => $name,
                    'kind'      => $e['kind'],
                    'bytes'     => strlen($e['content']),
                    'sensitive' => $e['sensitive'],
                ],
                array_keys($entries),
                $entries
            )),
        ];
    }

    /** Absolutní cesta k vygenerovanému balíčku, nebo null když neexistuje. */
    public function resolvePath(string $filename): ?string
    {
        // Jméno pochází od klienta — nikdy z něj neskládáme cestu bez ověření tvaru.
        if (preg_match('/^' . preg_quote(self::PREFIX, '/') . '[A-Za-z0-9._-]{1,80}\.zip$/', $filename) !== 1) {
            return null;
        }
        $path = RuntimePaths::storage('support') . DIRECTORY_SEPARATOR . $filename;

        return is_file($path) ? $path : null;
    }

    /** Smaže balíčky starší 24 hodin. */
    public function cleanup(): int
    {
        $removed  = 0;
        $deadline = time() - self::RETENTION_SEC;
        foreach (glob(RuntimePaths::storage('support') . DIRECTORY_SEPARATOR . self::PREFIX . '*.zip') ?: [] as $file) {
            if ((int) @filemtime($file) < $deadline && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    // ── Obsah ────────────────────────────────────────────────────────────────

    /**
     * @return array<string,array{kind:string,sensitive:bool,content:string}>
     */
    private function collect(DiagnosticsBundleOptions $options): array
    {
        $report  = $this->environment->report();
        $entries = [];

        if ($options->includeVersion) {
            $entries['version.json'] = [
                'kind'      => 'version',
                'sensitive' => false,
                'content'   => self::json($this->safe(fn () => $this->version->getStatus())),
            ];
        }

        if ($options->includeEnvironment) {
            $entries['environment.json'] = [
                'kind'      => 'environment',
                'sensitive' => false,
                'content'   => self::json($report),
            ];
            $entries['health.json'] = [
                'kind'      => 'health',
                'sensitive' => false,
                'content'   => self::json($this->health()),
            ];
        }

        if ($options->includeLicense) {
            $entries['license.json'] = [
                'kind'      => 'license',
                'sensitive' => false,
                'content'   => self::json($this->safe(fn () => $this->licenseSnapshot())),
            ];
        }

        if ($options->includeMigrations) {
            $entries['migrations.txt'] = [
                'kind'      => 'migrations',
                'sensitive' => false,
                'content'   => $this->migrationsText($report),
            ];
        }

        if ($options->includeCron) {
            $entries['cron.json'] = [
                'kind'      => 'cron',
                'sensitive' => false,
                'content'   => self::json($report['facts']['runtime']['cron'] ?? []),
            ];
        }

        if ($options->includeConfig) {
            $entries['config-sanitized.json'] = [
                'kind'      => 'config',
                'sensitive' => false,
                'content'   => self::json($this->safe(fn () => $this->allowlist->export())),
            ];
        }

        if ($options->includeLogs) {
            foreach ($this->logs->daysInWindow($options->days) as $day) {
                $data = $this->logs->readDay($day, $options->logLevel);
                if ($data['lines'] === []) {
                    continue;
                }
                $entries['log/app-' . $day . '.log'] = [
                    'kind'      => 'log',
                    'sensitive' => true,
                    'content'   => implode("\n", $data['lines']) . "\n",
                ];
            }
        }

        // README se skládá až nakonec — popisuje jmenovitě to, co v balíčku
        // opravdu je, takže musí znát hotový seznam položek. Do archivu jde
        // jako první.
        return ['README.txt' => [
            'kind'      => 'readme',
            'sensitive' => false,
            'content'   => $this->readme($options, $report, array_keys($entries)),
        ]] + $entries;
    }

    /** @return array<string,mixed> */
    private function health(): array
    {
        $warnings = [];
        $keyWarning = $this->safe(fn () => $this->crypto->validateKey());
        if (is_string($keyWarning) && $keyWarning !== '') {
            $warnings[] = ['code' => 'secret_encryption_key', 'message' => $keyWarning];
        }

        return [
            'db'       => $this->safe(fn () => $this->db->ping(), false),
            'redis'    => $this->safe(fn () => $this->redis->isAvailable(), false),
            'warnings' => $warnings,
            'time'     => date(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string,mixed> */
    private function licenseSnapshot(): array
    {
        $state = $this->license->current();

        return $state->toArray($this->license->buyUrl());
    }

    /** @param array<string,mixed> $report */
    private function migrationsText(array $report): string
    {
        $mig = $report['facts']['runtime']['migrations'] ?? [];
        $out = [
            'Stav migrací (ekvivalent `php api/bin/migrate.php --status`)',
            'Aplikováno: ' . (int) ($mig['applied'] ?? 0) . ' z ' . (int) ($mig['total'] ?? 0),
            'Čeká: ' . (int) ($mig['pending_count'] ?? 0),
            '',
        ];
        foreach ((array) ($mig['pending'] ?? []) as $name) {
            $out[] = '[ ] ' . $name;
        }
        if (($mig['pending_count'] ?? 0) === 0) {
            $out[] = 'Žádná migrace nečeká na spuštění.';
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * @param array<string,array{kind:string,sensitive:bool,content:string}> $entries
     * @return array<string,mixed>
     */
    private function manifest(array $entries, DiagnosticsBundleOptions $options): array
    {
        $items = [];
        foreach ($entries as $name => $entry) {
            $items[] = [
                'name'      => $name,
                'kind'      => $entry['kind'],
                'bytes'     => strlen($entry['content']),
                'sha256'    => hash('sha256', $entry['content']),
                'sensitive' => $entry['sensitive'],
            ];
        }

        return [
            'schema'       => 1,
            'generated_at' => date(\DateTimeInterface::ATOM),
            'app_version'  => $this->safe(fn () => $this->version->getCurrentVersion(), 'unknown'),
            'instance_id'  => $this->instanceSlug(),
            'options'      => $options->toArray(),
            'items'        => $items,
            'omitted'      => $options->omitted(),
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @param list<string> $names jména položek, které v balíčku skutečně budou
     */
    private function readme(DiagnosticsBundleOptions $options, array $report, array $names): string
    {
        $summary = $report['summary'] ?? [];
        $lines   = [
            'Diagnostický balíček MyÚčto.cz',
            '==============================',
            '',
            'Vytvořeno: ' . date('j. n. Y H:i:s'),
            'Verze aplikace: ' . $this->safe(fn () => $this->version->getCurrentVersion(), 'unknown'),
            'Instalace: ' . $this->instanceSlug(),
            'Výsledek kontroly prostředí: ' . strtoupper((string) ($summary['status'] ?? '?'))
                . ' (' . (int) ($summary['fail'] ?? 0) . " problémů, " . (int) ($summary['warn'] ?? 0) . ' varování)',
            '',
            'K ČEMU TO JE',
            '------------',
            'Balíček slouží jako podklad k incidentu placené podpory. Vygenerovala',
            'ho vaše instalace na vaši žádost. Aplikace ho nikam neodeslala —',
            'přiložíte ho k incidentu sami na myucto.cz/support.',
            '',
            'CO JE UVNITŘ',
            '------------',
        ];

        $descriptions = [
            'README.txt'            => 'tento popis',
            'manifest.json'         => 'seznam položek s kontrolním součtem SHA-256',
            'version.json'          => 'verze aplikace a dostupnost novější',
            'environment.json'      => 'audit prostředí (PHP, databáze, úložiště, časy) a jeho vyhodnocení',
            'health.json'           => 'dostupnost databáze a Redisu, provozní varování',
            'license.json'          => 'stav licence (klíč je maskovaný)',
            'migrations.txt'        => 'seznam databázových migrací a těch, které čekají',
            'cron.json'             => 'kdy naposledy proběhly plánované úlohy',
            'config-sanitized.json' => 'výřez konfigurace pořízený allowlistem; hesla, klíče a tokeny se nepřenášejí',
        ];

        $lines[] = sprintf('  %-24s %s', 'README.txt', $descriptions['README.txt']);
        $lines[] = sprintf('  %-24s %s', 'manifest.json', $descriptions['manifest.json']);
        foreach ($names as $name) {
            if (str_starts_with($name, 'log/')) {
                continue;
            }
            $lines[] = sprintf('  %-24s %s', $name, $descriptions[$name] ?? '');
        }

        if ($options->includeLogs) {
            $lines[] = '';
            $lines[] = '  log/app-YYYY-MM-DD.log   výřez aplikačního logu';
            $lines[] = '';
            $lines[] = 'UPOZORNĚNÍ K LOGŮM';
            $lines[] = '------------------';
            $lines[] = 'Logy jste do balíčku přidali vědomě. Obsahují provozní záznamy vaší';
            $lines[] = 'instalace za posledních ' . $options->days . ' dnů (úroveň ' . $options->logLevel . ' a výš)';
            $lines[] = 'a mohou obsahovat osobní údaje třetích osob — například e-mailové adresy';
            $lines[] = 'příjemců dokladů, jména a adresy klientů nebo hodnoty z chybových hlášek';
            $lines[] = 'databáze. Odstraněny byly navázané parametry databázových dotazů,';
            $lines[] = 'stack trace a záznamy o komunikaci se SMTP serverem.';
            $lines[] = 'Před předáním si obsah prosím projděte.';
        } else {
            $lines[] = '';
            $lines[] = 'Logy aplikace v tomto balíčku NEJSOU.';
        }

        $lines[] = '';
        $lines[] = 'Balíček se z instalace automaticky smaže po 24 hodinách.';

        return implode("\n", $lines) . "\n";
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    private function instanceSlug(): string
    {
        $id = $this->safe(fn () => $this->license->current()->instanceId, '');

        return preg_replace('/[^A-Za-z0-9-]/', '', (string) $id) ?: 'unknown';
    }

    /**
     * @return array{ok:false,error:string,filename:null,bytes:null,sha256:null,items:null}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'filename' => null, 'bytes' => null, 'sha256' => null, 'items' => null];
    }

    /**
     * @template T
     * @param callable():T $fn
     * @param T $fallback
     * @return T
     */
    private function safe(callable $fn, mixed $fallback = null): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private static function json(mixed $data): string
    {
        return (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }
}
