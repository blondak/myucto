<?php

declare(strict_types=1);

use MyInvoice\Tooling\JmhzCodebookDownloader;
use MyInvoice\Tooling\JmhzCodebookManifestDiff;

require_once dirname(__DIR__) . '/api/vendor/autoload.php';
require_once __DIR__ . '/JmhzCodebookDownloader.php';
require_once __DIR__ . '/JmhzCodebookManifestDiff.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript lze spustit jen z příkazové řádky.\n");
    exit(1);
}

const JMHZ_CODEBOOK_USAGE = <<<'TEXT'
Použití: php tools/downloadJmhzCodebooks.php [volby]

  --verify                 Jen ověří připnutý strom, nic nestahuje ani nezapisuje.
  --candidate=ADRESÁŘ      Stáhne kandidáta mimo připnutý strom a nic nenainstaluje.
  --diff=SOUBOR            Kandidátský manifest.json k porovnání s připnutým.
  --pinned=SOUBOR          Připnutý manifest.json (výchozí dictionary-1.4.1.6/manifest.json).
  --report=SOUBOR          Kam zapsat strojově čitelný report změn.

Bez voleb se zdroje stáhnou, ověří proti připnutému manifestu a atomicky nainstalují.
TEXT;

/**
 * @param list<string> $arguments
 * @return array<string,string|bool>
 */
function jmhzCodebookOptions(array $arguments): array
{
    $options = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (preg_match('/\A--([a-z-]+)(?:=(.*))?\z/D', $argument, $matches) !== 1) {
            throw new RuntimeException("Neznámý argument {$argument}.");
        }
        $options[$matches[1]] = $matches[2] ?? true;
    }
    foreach (array_keys($options) as $name) {
        if (!in_array($name, ['verify', 'candidate', 'diff', 'pinned', 'report', 'help'], true)) {
            throw new RuntimeException("Neznámá volba --{$name}.");
        }
    }

    return $options;
}

/** @param array<string,string|bool> $options */
function jmhzCodebookPath(array $options, string $name): ?string
{
    $value = $options[$name] ?? null;
    if ($value === null) {
        return null;
    }
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("Volba --{$name} vyžaduje hodnotu.");
    }

    return $value;
}

try {
    $arguments = array_values(array_filter(
        $_SERVER['argv'] ?? [],
        static fn (mixed $value): bool => is_string($value),
    ));
    $options = jmhzCodebookOptions($arguments);
    if (isset($options['help'])) {
        fwrite(STDOUT, JMHZ_CODEBOOK_USAGE . PHP_EOL);
        exit(0);
    }

    $projectRoot = dirname(__DIR__);
    $resourceRoot = $projectRoot . '/api/resources/payroll/jmhz';
    $manifest = require __DIR__ . '/jmhz-codebook-sources.php';
    if (!is_array($manifest)) {
        throw new RuntimeException('Manifest zdrojů číselníků JMHZ je neplatný.');
    }
    $downloader = new JmhzCodebookDownloader(
        $manifest,
        static fn (string $message): int|false => fwrite(STDOUT, $message . PHP_EOL),
    );

    $changed = false;
    $diffPath = jmhzCodebookPath($options, 'diff');
    if ($diffPath !== null) {
        $pinnedPath = jmhzCodebookPath($options, 'pinned')
            ?? $resourceRoot . '/dictionary-1.4.1.6/manifest.json';
        $reportPath = jmhzCodebookPath($options, 'report')
            ?? $projectRoot . '/jmhz-codebook-changes.json';
        $report = JmhzCodebookManifestDiff::between(
            JmhzCodebookManifestDiff::load($pinnedPath),
            JmhzCodebookManifestDiff::load($diffPath),
        );
        JmhzCodebookManifestDiff::write($report, $reportPath);
        fwrite(STDOUT, "Report změn zapsán do {$reportPath}." . PHP_EOL);
        $changed = $report['changed'] === true;
    } elseif (isset($options['verify'])) {
        $downloader->verifyInstalled($resourceRoot);
        fwrite(STDOUT, 'Připnuté číselníky JMHZ odpovídají manifestu.' . PHP_EOL);
    } else {
        $candidateRoot = jmhzCodebookPath($options, 'candidate');
        if ($candidateRoot !== null) {
            $candidates = $downloader->downloadCandidate($candidateRoot);
            foreach ($candidates as $id => $candidate) {
                fwrite(STDOUT, sprintf(
                    '%s: %s (%d B, sha256 %s)%s',
                    $id,
                    $candidate['changed'] ? 'ZMĚNA proti připnutému zdroji' : 'beze změny',
                    $candidate['byte_length'],
                    $candidate['sha256'],
                    PHP_EOL,
                ));
                $changed = $changed || $candidate['changed'];
            }
            fwrite(STDOUT, 'Kandidát je připraven; instalaci schvaluje člověk.' . PHP_EOL);
        } else {
            $downloader->downloadAndInstall($resourceRoot);
        }
    }

    exit($changed ? 3 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Obnova číselníků JMHZ selhala: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
