<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

/**
 * Rozsah diagnostického balíčku zvolený uživatelem.
 *
 * Logy jsou jediná část balíčku, která může nést osobní údaje třetích osob,
 * takže tady zůstávají fail-closed: požadavek, který `include_logs` neposlal,
 * je nikdy nezapne. Že je stránka Diagnostiky předškrtává, na tom nic nemění —
 * uživatel je tam vidí zaškrtnuté, může je odškrtnout a jejich obsah navíc
 * potvrzuje zvlášť.
 */
final class DiagnosticsBundleOptions
{
    private function __construct(
        public readonly bool $includeVersion,
        public readonly bool $includeEnvironment,
        public readonly bool $includeLicense,
        public readonly bool $includeMigrations,
        public readonly bool $includeCron,
        public readonly bool $includeConfig,
        public readonly bool $includeLogs,
        public readonly int $days,
        public readonly string $logLevel,
    ) {}

    public static function defaults(): self
    {
        return new self(
            includeVersion: true,
            includeEnvironment: true,
            includeLicense: true,
            includeMigrations: true,
            includeCron: true,
            includeConfig: true,
            includeLogs: false,
            days: DiagnosticsLogReader::DEFAULT_DAYS,
            logLevel: DiagnosticsLogReader::DEFAULT_LEVEL,
        );
    }

    /**
     * Vstup z HTTP. Neznámé klíče se ignorují a `include_logs` musí být
     * explicitně pravdivé — chybějící hodnota nikdy logy nezapne.
     *
     * @param array<string,mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $flag = static fn (string $key, bool $default): bool => array_key_exists($key, $input)
            ? filter_var($input[$key], FILTER_VALIDATE_BOOL)
            : $default;

        $level = strtoupper(trim((string) ($input['log_level'] ?? DiagnosticsLogReader::DEFAULT_LEVEL)));
        if (!in_array($level, DiagnosticsLogReader::levels(), true)) {
            $level = DiagnosticsLogReader::DEFAULT_LEVEL;
        }

        $days = (int) ($input['days'] ?? DiagnosticsLogReader::DEFAULT_DAYS);
        $days = max(1, min(DiagnosticsLogReader::MAX_DAYS, $days));

        return new self(
            includeVersion: $flag('include_version', true),
            includeEnvironment: $flag('include_environment', true),
            includeLicense: $flag('include_license', true),
            includeMigrations: $flag('include_migrations', true),
            includeCron: $flag('include_cron', true),
            includeConfig: $flag('include_config', true),
            includeLogs: $flag('include_logs', false),
            days: $days,
            logLevel: $level,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'include_version'     => $this->includeVersion,
            'include_environment' => $this->includeEnvironment,
            'include_license'     => $this->includeLicense,
            'include_migrations'  => $this->includeMigrations,
            'include_cron'        => $this->includeCron,
            'include_config'      => $this->includeConfig,
            'include_logs'        => $this->includeLogs,
            'days'                => $this->days,
            'log_level'           => $this->logLevel,
        ];
    }

    /**
     * Co uživatel vědomě vynechal. Do manifestu patří stejně jako to, co v něm
     * je — řešitel musí poznat rozdíl mezi „nic tam není" a „nebylo vyžádáno".
     *
     * @return list<string>
     */
    public function omitted(): array
    {
        $map = [
            'version'     => $this->includeVersion,
            'environment' => $this->includeEnvironment,
            'license'     => $this->includeLicense,
            'migrations'  => $this->includeMigrations,
            'cron'        => $this->includeCron,
            'config'      => $this->includeConfig,
            'logs'        => $this->includeLogs,
        ];

        return array_values(array_keys(array_filter($map, static fn (bool $on): bool => !$on)));
    }
}
