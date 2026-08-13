<?php

declare(strict_types=1);

namespace MyInvoice\Tooling;

use Closure;
use FilesystemIterator;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class JmhzCodebookDownloader
{
    public const NOT_FOUND = 404;

    private const OFFICIAL_HOST = 'developers.mpsv.cz';
    private const MAX_REDIRECTS = 5;
    private const MAX_SOURCE_BYTES = 32 * 1024 * 1024;
    private const ZIP_SIGNATURE = "PK\x03\x04";

    /**
     * Rozklad českých znaků na základní písmeno a kombinující znaménko. Blob storage MPSV
     * míchá klíče v NFC i NFD, takže se pro každý zdroj zkouší obě normalizace. Tabulka je
     * záměrně uzavřená a bez ext-intl, aby se chování nelišilo podle stroje.
     */
    private const DECOMPOSITIONS = [
        'á' => "a\u{0301}", 'Á' => "A\u{0301}",
        'č' => "c\u{030C}", 'Č' => "C\u{030C}",
        'ď' => "d\u{030C}", 'Ď' => "D\u{030C}",
        'é' => "e\u{0301}", 'É' => "E\u{0301}",
        'ě' => "e\u{030C}", 'Ě' => "E\u{030C}",
        'í' => "i\u{0301}", 'Í' => "I\u{0301}",
        'ň' => "n\u{030C}", 'Ň' => "N\u{030C}",
        'ó' => "o\u{0301}", 'Ó' => "O\u{0301}",
        'ř' => "r\u{030C}", 'Ř' => "R\u{030C}",
        'š' => "s\u{030C}", 'Š' => "S\u{030C}",
        'ť' => "t\u{030C}", 'Ť' => "T\u{030C}",
        'ú' => "u\u{0301}", 'Ú' => "U\u{0301}",
        'ů' => "u\u{030A}", 'Ů' => "U\u{030A}",
        'ý' => "y\u{0301}", 'Ý' => "Y\u{0301}",
        'ž' => "z\u{030C}", 'Ž' => "Z\u{030C}",
    ];

    /**
     * @var array<string,array{
     *     target:string,
     *     filename:string,
     *     version:string,
     *     url:string|null,
     *     sha256:string,
     *     byte_length:int,
     *     content_types:list<string>,
     *     signature:string
     * }>
     */
    private array $sources;

    /**
     * @var array<string,array{
     *     schema_version:string,
     *     identity_key:string,
     *     identity:string,
     *     manifest_sha256:string,
     *     counts:array<string,int>,
     *     external_reference_codebooks:list<string>,
     *     base_manifest_sha256:string|null
     * }>
     */
    private array $catalogs;

    private ?Closure $logger;

    /** @param array<mixed> $manifest */
    public function __construct(array $manifest, ?callable $logger = null)
    {
        $sources = $manifest['sources'] ?? null;
        $catalogs = $manifest['catalogs'] ?? null;
        if (!is_array($sources) || !is_array($catalogs)) {
            throw new RuntimeException('Manifest číselníků JMHZ musí mít zdroje i katalogy.');
        }
        $this->sources = $this->validateSources($sources);
        $this->catalogs = $this->validateCatalogs($catalogs);
        $this->logger = $logger === null ? null : Closure::fromCallable($logger);
    }

    /**
     * Stáhne všechny připnuté zdroje, ověří je proti manifestu a teprve pak je atomicky
     * nainstaluje. Ruční zdroje (bez připnuté URL) se jen ověřují na disku.
     */
    public function downloadAndInstall(string $resourceRoot): void
    {
        $tempRoot = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'myucto-jmhz-codebook-'
            . bin2hex(random_bytes(8));
        if (!mkdir($tempRoot, 0700, true)) {
            throw new RuntimeException("Dočasný adresář {$tempRoot} nelze vytvořit.");
        }

        try {
            $files = $this->downloadRemoteSources($tempRoot);
            $this->installFromFiles($files, $resourceRoot);
        } finally {
            $this->removeDirectory($tempRoot);
        }
    }

    /**
     * Připraví kandidáta v samostatném adresáři a nikdy nesáhne na připnutý strom.
     * Ověřuje původ, velikost, typ obsahu i podpis, ale ne připnutý hash — právě rozdíl
     * hashe je informace, kterou má kandidát přinést.
     *
     * @return array<string,array{path:string,sha256:string,byte_length:int,changed:bool}>
     */
    public function downloadCandidate(string $candidateRoot): array
    {
        if (!is_dir($candidateRoot) && !mkdir($candidateRoot, 0777, true) && !is_dir($candidateRoot)) {
            throw new RuntimeException("Adresář kandidáta {$candidateRoot} nelze vytvořit.");
        }

        $result = [];
        foreach ($this->sources as $id => $source) {
            if ($source['url'] === null) {
                continue;
            }
            $path = $candidateRoot . DIRECTORY_SEPARATOR . $source['filename'];
            $this->log("Stahuji kandidáta {$id} {$source['version']}...");
            $this->download($source['url'], $path, $source, false);
            $hash = hash_file('sha256', $path);
            $size = filesize($path);
            if (!is_string($hash) || !is_int($size)) {
                throw new RuntimeException("Kandidáta {$id} nelze ověřit.");
            }
            $result[$id] = [
                'path' => $path,
                'sha256' => $hash,
                'byte_length' => $size,
                'changed' => !hash_equals($source['sha256'], $hash) || $size !== $source['byte_length'],
            ];
        }

        return $result;
    }

    /**
     * @param array<string,string> $files
     */
    public function installFromFiles(array $files, string $resourceRoot): void
    {
        foreach ($this->sources as $id => $source) {
            if ($source['url'] === null) {
                continue;
            }
            $path = $files[$id] ?? null;
            if (!is_string($path) || !is_file($path)) {
                throw new RuntimeException("Stažený zdroj {$id} chybí.");
            }
            $this->assertPinnedBytes($id, $path, $source);
        }

        $parent = dirname($resourceRoot);
        if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new RuntimeException("Nadřazený adresář {$parent} nelze vytvořit.");
        }
        if (!is_dir($resourceRoot)) {
            throw new RuntimeException("Připnutý strom číselníků {$resourceRoot} neexistuje.");
        }

        $stage = $parent
            . DIRECTORY_SEPARATOR
            . '.'
            . basename($resourceRoot)
            . '.stage-'
            . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0777, true)) {
            throw new RuntimeException("Přípravný adresář {$stage} nelze vytvořit.");
        }

        try {
            $this->copyTree($resourceRoot, $stage);
            foreach ($this->sources as $id => $source) {
                $destination = $stage
                    . DIRECTORY_SEPARATOR
                    . $source['target']
                    . DIRECTORY_SEPARATOR
                    . $source['filename'];
                if ($source['url'] === null) {
                    $this->assertPinnedBytes($id, $destination, $source);
                    $this->log("Ruční zdroj {$id} {$source['version']} ověřen.");
                    continue;
                }
                if (!is_dir(dirname($destination))) {
                    throw new RuntimeException("Cílový adresář zdroje {$id} chybí.");
                }
                if (!copy($files[$id], $destination)) {
                    throw new RuntimeException("Zdroj {$id} nelze uložit do {$destination}.");
                }
                $this->assertPinnedBytes($id, $destination, $source);
                $this->log("Zdroj {$id} {$source['version']} připraven.");
            }

            $this->verifyCatalogs($stage);
            $this->writeContentManifest($stage);
            $this->replaceDirectory($stage, $resourceRoot);
            $this->log("Číselníky JMHZ nainstalovány v {$resourceRoot}.");
        } finally {
            if (is_dir($stage)) {
                $this->removeDirectory($stage);
            }
        }
    }

    /** Ověří připnutý strom bez jakéhokoli síťového volání. */
    public function verifyInstalled(string $resourceRoot): void
    {
        foreach ($this->sources as $id => $source) {
            $this->assertPinnedBytes(
                $id,
                $resourceRoot . DIRECTORY_SEPARATOR . $source['target'] . DIRECTORY_SEPARATOR . $source['filename'],
                $source,
            );
        }
        $this->verifyCatalogs($resourceRoot);
    }

    /** @return array<string,array{target:string,filename:string,version:string,url:string|null,sha256:string,byte_length:int,content_types:list<string>,signature:string}> */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @return array<string,string>
     */
    private function downloadRemoteSources(string $tempRoot): array
    {
        $files = [];
        foreach ($this->sources as $id => $source) {
            if ($source['url'] === null) {
                $this->log("Zdroj {$id} se obnovuje ručně, stahování se přeskakuje.");
                continue;
            }
            $path = $tempRoot . DIRECTORY_SEPARATOR . $id;
            $this->log("Stahuji {$id} {$source['version']}...");
            $this->download($source['url'], $path, $source, true);
            $files[$id] = $path;
        }

        return $files;
    }

    /**
     * @param array<mixed> $sources
     * @return array<string,array{target:string,filename:string,version:string,url:string|null,sha256:string,byte_length:int,content_types:list<string>,signature:string}>
     */
    private function validateSources(array $sources): array
    {
        if ($sources === []) {
            throw new RuntimeException('Manifest číselníků JMHZ nesmí být prázdný.');
        }

        $validated = [];
        $seen = [];
        foreach ($sources as $id => $source) {
            if (!is_string($id) || !is_array($source)) {
                throw new RuntimeException('Manifest číselníků JMHZ má neplatnou položku.');
            }
            $target = $source['target'] ?? null;
            $filename = $source['filename'] ?? null;
            $version = $source['version'] ?? null;
            $url = $source['url'] ?? null;
            $sha256 = $source['sha256'] ?? null;
            $byteLength = $source['byte_length'] ?? null;
            $contentTypes = $source['content_types'] ?? null;
            $signature = $source['signature'] ?? null;
            if (
                preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $id) !== 1
                || !is_string($target)
                || preg_match('/\A[a-z0-9][a-z0-9.-]*\z/D', $target) !== 1
                || !is_string($filename)
                || !$this->isSafeFilename($filename)
                || !is_string($version)
                || preg_match('/\A[0-9][0-9.\-]*[0-9]\z/D', $version) !== 1
                || !is_string($sha256)
                || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
                || !is_int($byteLength)
                || $byteLength < 1
                || $byteLength > self::MAX_SOURCE_BYTES
                || !is_array($contentTypes)
                || !is_string($signature)
                || !in_array($signature, ['zip', 'utf8-text'], true)
                || ($url !== null && !is_string($url))
            ) {
                throw new RuntimeException("Manifest číselníků JMHZ má neplatnou položku {$id}.");
            }
            if (isset($seen[$target . '/' . $filename])) {
                throw new RuntimeException("Manifest číselníků JMHZ duplikuje soubor {$filename}.");
            }
            $seen[$target . '/' . $filename] = true;

            $validatedTypes = [];
            foreach ($contentTypes as $contentType) {
                if (!is_string($contentType) || preg_match('#\A[a-z0-9.+-]+/[a-z0-9.+-]+\z#D', $contentType) !== 1) {
                    throw new RuntimeException("Zdroj {$id} má neplatný typ obsahu.");
                }
                $validatedTypes[] = $contentType;
            }
            if ($url !== null) {
                if ($validatedTypes === []) {
                    throw new RuntimeException("Zdroj {$id} musí mít připnutý typ obsahu.");
                }
                $this->assertOfficialUrl($url, $id);
                if (!str_ends_with($url, $filename)) {
                    throw new RuntimeException("URL zdroje {$id} neodpovídá připnutému názvu souboru.");
                }
            }

            $validated[$id] = [
                'target' => $target,
                'filename' => $filename,
                'version' => $version,
                'url' => $url,
                'sha256' => $sha256,
                'byte_length' => $byteLength,
                'content_types' => $validatedTypes,
                'signature' => $signature,
            ];
        }

        return $validated;
    }

    /**
     * @param array<mixed> $catalogs
     * @return array<string,array{schema_version:string,identity_key:string,identity:string,manifest_sha256:string,counts:array<string,int>,external_reference_codebooks:list<string>,base_manifest_sha256:string|null}>
     */
    private function validateCatalogs(array $catalogs): array
    {
        if ($catalogs === []) {
            throw new RuntimeException('Manifest číselníků JMHZ musí připnout alespoň jeden katalog.');
        }

        $validated = [];
        foreach ($catalogs as $relative => $catalog) {
            if (!is_string($relative) || !is_array($catalog)) {
                throw new RuntimeException('Manifest číselníků JMHZ má neplatný katalog.');
            }
            $schemaVersion = $catalog['schema_version'] ?? null;
            $identityKey = $catalog['identity_key'] ?? null;
            $identity = $catalog['identity'] ?? null;
            $manifestSha256 = $catalog['manifest_sha256'] ?? null;
            $counts = $catalog['counts'] ?? null;
            $externalReferences = $catalog['external_reference_codebooks'] ?? null;
            $baseManifestSha256 = $catalog['base_manifest_sha256'] ?? null;
            if (
                preg_match('#\A[a-z0-9][a-z0-9.-]*/[a-z0-9-]+\.json\z#D', $relative) !== 1
                || !is_string($schemaVersion)
                || !is_string($identityKey)
                || !is_string($identity)
                || !is_string($manifestSha256)
                || preg_match('/\A[a-f0-9]{64}\z/D', $manifestSha256) !== 1
                || !is_array($counts)
                || $counts === []
                || !is_array($externalReferences)
                || ($baseManifestSha256 !== null
                    && (!is_string($baseManifestSha256)
                        || preg_match('/\A[a-f0-9]{64}\z/D', $baseManifestSha256) !== 1))
            ) {
                throw new RuntimeException("Manifest číselníků JMHZ má neplatný katalog {$relative}.");
            }

            $validatedCounts = [];
            foreach ($counts as $key => $value) {
                if (!is_string($key) || !is_int($value) || $value < 0) {
                    throw new RuntimeException("Katalog {$relative} má neplatné očekávané počty.");
                }
                $validatedCounts[$key] = $value;
            }
            $validatedReferences = [];
            foreach ($externalReferences as $reference) {
                if (!is_string($reference) || $reference === '') {
                    throw new RuntimeException("Katalog {$relative} má neplatnou externí referenci.");
                }
                $validatedReferences[] = $reference;
            }

            $validated[$relative] = [
                'schema_version' => $schemaVersion,
                'identity_key' => $identityKey,
                'identity' => $identity,
                'manifest_sha256' => $manifestSha256,
                'counts' => $validatedCounts,
                'external_reference_codebooks' => $validatedReferences,
                'base_manifest_sha256' => is_string($baseManifestSha256) ? $baseManifestSha256 : null,
            ];
        }

        return $validated;
    }

    /**
     * @param array{target:string,filename:string,version:string,url:string|null,sha256:string,byte_length:int,content_types:list<string>,signature:string} $source
     */
    private function assertPinnedBytes(string $id, string $path, array $source): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("Zdroj {$id} chybí na cestě {$path}.");
        }
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        if ($size !== $source['byte_length']) {
            throw new RuntimeException(
                "Zdroj {$id} má " . var_export($size, true) . " bajtů; očekáváno {$source['byte_length']}.",
            );
        }
        if (!is_string($hash) || !hash_equals($source['sha256'], strtolower($hash))) {
            throw new RuntimeException("Zdroj {$id} nemá připnutý SHA-256.");
        }
    }

    /**
     * @param array{target:string,filename:string,version:string,url:string|null,sha256:string,byte_length:int,content_types:list<string>,signature:string} $source
     */
    private function download(string $url, string $target, array $source, bool $pinnedSize): void
    {
        $currentUrl = $this->selectAvailableUrl($url, fn (string $candidate): int => $this->probeStatus($candidate));
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $this->assertOfficialUrl($currentUrl, 'download');
            $input = @fopen($this->requestUrl($currentUrl), 'rb', false, $this->streamContext('GET'));
            if ($input === false) {
                throw new RuntimeException("Zdroj {$currentUrl} nelze stáhnout.");
            }

            try {
                $headers = $this->responseHeaders($input);
                $status = $this->responseStatus($headers);
                if ($status >= 300 && $status < 400) {
                    $location = $this->redirectLocation($headers);
                    if ($location === null || $redirects === self::MAX_REDIRECTS) {
                        throw new RuntimeException("Nebezpečné nebo nadměrné přesměrování u {$currentUrl}.");
                    }
                    $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                    continue;
                }
                if ($status !== 200) {
                    throw new RuntimeException("Stažení {$currentUrl} vrátilo HTTP {$status}.", $status);
                }
                $this->assertContentType($headers, $source, $currentUrl);
                $this->writeLimitedSource(
                    $input,
                    $target,
                    $currentUrl,
                    $source['signature'],
                    $pinnedSize ? $source['byte_length'] : self::MAX_SOURCE_BYTES,
                );

                return;
            } finally {
                fclose($input);
            }
        }

        throw new RuntimeException("Příliš mnoho přesměrování u {$url}.");
    }

    /**
     * Blob storage MPSV vrací 404, dokud se název souboru nepřevede na tu normalizaci
     * Unicode, ve které je klíč uložený. Zkouší se připnutá podoba a pak ta druhá; když
     * ani jedna neexistuje, běh fail-closed končí.
     *
     * @param callable(string):int $probe
     */
    private function selectAvailableUrl(string $url, callable $probe): string
    {
        $candidates = $this->downloadUrlCandidates($url);
        $statuses = [];
        foreach ($candidates as $candidate) {
            $status = $probe($candidate);
            $statuses[] = $status;
            if ($status !== 404 && $status !== 410) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            "Zdroj {$url} není dostupný v žádné normalizaci názvu (HTTP " . implode('/', $statuses) . ').',
            self::NOT_FOUND,
        );
    }

    /** @return list<string> */
    private function downloadUrlCandidates(string $url): array
    {
        $alternate = $this->alternateNormalization($url);
        if ($alternate === $url) {
            return [$url];
        }
        $this->assertOfficialUrl($alternate, 'normalization');

        return [$url, $alternate];
    }

    private function alternateNormalization(string $value): string
    {
        $this->assertNormalizable($value);

        return preg_match('/[\x{0300}-\x{036F}]/u', $value) === 1
            ? strtr($value, array_flip(self::DECOMPOSITIONS))
            : strtr($value, self::DECOMPOSITIONS);
    }

    private function assertNormalizable(string $value): void
    {
        $rest = (string) preg_replace(
            '/[\x{0300}-\x{036F}]/u',
            '',
            strtr($value, self::DECOMPOSITIONS),
        );
        if (preg_match('/[^\x20-\x7E]/', $rest) === 1) {
            throw new RuntimeException("Název {$value} obsahuje znak mimo připnutou normalizační tabulku.");
        }
    }

    private function probeStatus(string $url): int
    {
        $this->assertOfficialUrl($url, 'probe');
        $handle = @fopen($this->requestUrl($url), 'rb', false, $this->streamContext('HEAD'));
        if ($handle === false) {
            return 0;
        }

        try {
            return $this->responseStatus($this->responseHeaders($handle));
        } finally {
            fclose($handle);
        }
    }

    private function requestUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !is_string($parts['host'] ?? null) || !is_string($parts['path'] ?? null)) {
            throw new RuntimeException("URL {$url} nelze zakódovat.");
        }
        $path = implode('/', array_map('rawurlencode', explode('/', $parts['path'])));

        return 'https://' . $parts['host'] . $path;
    }

    /** @return resource */
    private function streamContext(string $method)
    {
        return stream_context_create([
            'http' => [
                'method' => $method,
                'follow_location' => 0,
                'ignore_errors' => true,
                'timeout' => 60,
                'user_agent' => 'MyUcto JMHZ codebook downloader',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);
    }

    /**
     * @param resource $stream
     * @return list<string>
     */
    private function responseHeaders($stream): array
    {
        $metadata = stream_get_meta_data($stream);
        $headers = $metadata['wrapper_data'] ?? [];
        if (is_string($headers)) {
            return [$headers];
        }
        if (!is_array($headers)) {
            return [];
        }
        $result = [];
        foreach ($headers as $header) {
            if (is_string($header)) {
                $result[] = $header;
            }
        }

        return $result;
    }

    /** @param list<string> $headers */
    private function responseStatus(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/\AHTTP\/\S+\s+([0-9]{3})(?:\s|$)/i', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }

    /** @param list<string> $headers */
    private function redirectLocation(array $headers): ?string
    {
        $locations = [];
        foreach ($headers as $header) {
            if (preg_match('/\ALocation:\s*(\S.*?)\s*\z/i', $header, $matches) === 1) {
                $locations[] = $matches[1];
            }
        }

        return count($locations) === 1 ? $locations[0] : null;
    }

    /**
     * @param list<string> $headers
     * @param array{target:string,filename:string,version:string,url:string|null,sha256:string,byte_length:int,content_types:list<string>,signature:string} $source
     */
    private function assertContentType(array $headers, array $source, string $url): void
    {
        $contentType = null;
        foreach ($headers as $header) {
            if (preg_match('#\AContent-Type:\s*([a-z0-9.+/-]+)#i', $header, $matches) === 1) {
                $contentType = strtolower($matches[1]);
            }
        }
        if ($contentType === null || !in_array($contentType, $source['content_types'], true)) {
            throw new RuntimeException(
                "Zdroj {$url} vrátil nepřípustný typ obsahu " . var_export($contentType, true) . '.',
            );
        }
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        if (str_starts_with($location, '//')) {
            throw new RuntimeException("Přesměrování bez schématu není povoleno: {$location}.");
        }
        $scheme = parse_url($location, PHP_URL_SCHEME);
        if (is_string($scheme)) {
            $this->assertOfficialUrl($location, 'redirect');

            return $location;
        }

        $host = parse_url($currentUrl, PHP_URL_HOST);
        $path = parse_url($currentUrl, PHP_URL_PATH);
        if (!is_string($host) || !is_string($path)) {
            throw new RuntimeException("Přesměrování {$location} nelze vyhodnotit.");
        }
        $redirectPath = str_starts_with($location, '/')
            ? $location
            : rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $location;
        $resolved = 'https://' . $host . rawurldecode($redirectPath);
        $this->assertOfficialUrl($resolved, 'redirect');

        return $resolved;
    }

    /** @param resource $input */
    private function writeLimitedSource(
        $input,
        string $target,
        string $url,
        string $signature,
        int $maxBytes,
    ): void {
        $output = @fopen($target, 'xb');
        if ($output === false) {
            throw new RuntimeException("Dočasný soubor {$target} nelze vytvořit.");
        }
        $written = 0;
        $complete = false;

        try {
            while (!feof($input)) {
                $chunk = fread($input, 64 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException("Stahování {$url} selhalo.");
                }
                if ($chunk === '') {
                    $metadata = stream_get_meta_data($input);
                    if ($metadata['timed_out']) {
                        throw new RuntimeException("Stahování {$url} vypršelo.");
                    }
                    continue;
                }
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new RuntimeException("Zdroj {$url} překračuje povolenou velikost {$maxBytes} B.");
                }
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException("Do {$target} nelze zapsat.");
                }
            }
            $complete = true;
        } finally {
            fclose($output);
            if (!$complete && is_file($target)) {
                unlink($target);
            }
        }

        if (!$this->hasExpectedSignature($target, $written, $signature)) {
            unlink($target);
            throw new RuntimeException("Zdroj {$url} nemá očekávaný obsah ({$signature}).");
        }
    }

    private function hasExpectedSignature(string $path, int $written, string $signature): bool
    {
        if ($written < 4) {
            return false;
        }
        if ($signature === 'zip') {
            return file_get_contents($path, false, null, 0, 4) === self::ZIP_SIGNATURE;
        }

        $head = file_get_contents($path, false, null, 0, 4096);

        return is_string($head)
            && !str_starts_with($head, "\xEF\xBB\xBF")
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $head) !== 1;
    }

    private function assertOfficialUrl(string $url, string $sourceId): void
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== self::OFFICIAL_HOST
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !is_string($path)
            || preg_match(
                '#\A/assets/documents/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/[^/]+\.(?:xlsx|csv|zip)\z#Diu',
                $path,
            ) !== 1
            || in_array('..', explode('/', rawurldecode($path)), true)
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            throw new RuntimeException("Zdroj {$sourceId} musí používat schválenou URL archivu MPSV.");
        }
    }

    private function isSafeFilename(string $filename): bool
    {
        return $filename !== ''
            && !str_contains($filename, '/')
            && !str_contains($filename, '\\')
            && !str_contains($filename, "\0")
            && $filename !== '.'
            && $filename !== '..'
            && !str_ends_with($filename, '.')
            && !str_ends_with($filename, ' ')
            && preg_match('/[\x00-\x1F\x7F]/', $filename) !== 1;
    }

    private function verifyCatalogs(string $root): void
    {
        foreach ($this->catalogs as $relative => $catalog) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $json = @file_get_contents($path);
            if ($json === false) {
                throw new RuntimeException("Katalog {$relative} nelze načíst.");
            }
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $payload = is_array($decoded) ? ($decoded['payload'] ?? null) : null;
            $manifestSha256 = is_array($decoded) ? ($decoded['manifest_sha256'] ?? null) : null;
            if (!is_array($payload) || !is_string($manifestSha256)) {
                throw new RuntimeException("Katalog {$relative} nemá očekávanou strukturu.");
            }
            /** @var array<string,mixed> $payload */
            if (!hash_equals($catalog['manifest_sha256'], $manifestSha256)) {
                throw new RuntimeException("Katalog {$relative} nemá připnutý hash manifestu.");
            }
            if (!hash_equals($manifestSha256, hash('sha256', CanonicalJson::encode($payload)))) {
                throw new RuntimeException("Katalog {$relative} neodpovídá vlastnímu hashi obsahu.");
            }
            if (($payload['schema_version'] ?? null) !== $catalog['schema_version']) {
                throw new RuntimeException("Katalog {$relative} má jinou verzi schématu.");
            }
            if (($payload[$catalog['identity_key']] ?? null) !== $catalog['identity']) {
                throw new RuntimeException("Katalog {$relative} má jinou identitu balíku.");
            }
            $this->assertCatalogCounts($relative, $payload, $catalog['counts']);
            $this->assertExternalReferences($relative, $payload, $catalog['external_reference_codebooks']);
            if ($catalog['base_manifest_sha256'] !== null) {
                $baseSpec = $payload['base_spec'] ?? null;
                if (
                    !is_array($baseSpec)
                    || !is_string($baseSpec['manifest_sha256'] ?? null)
                    || !hash_equals($catalog['base_manifest_sha256'], $baseSpec['manifest_sha256'])
                ) {
                    throw new RuntimeException("Katalog {$relative} neodkazuje na připnutý základní balík.");
                }
            }
            $this->assertCatalogSources($relative, $payload, dirname($path));
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,int> $expected
     */
    private function assertCatalogCounts(string $relative, array $payload, array $expected): void
    {
        $counts = $payload['counts'] ?? null;
        if (!is_array($counts)) {
            throw new RuntimeException("Katalog {$relative} nemá počty položek.");
        }
        foreach ($expected as $key => $value) {
            if (($counts[$key] ?? null) !== $value) {
                throw new RuntimeException(
                    "Katalog {$relative} má u {$key} hodnotu "
                        . var_export($counts[$key] ?? null, true)
                        . "; očekáváno {$value}.",
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $externalReferences
     */
    private function assertExternalReferences(string $relative, array $payload, array $externalReferences): void
    {
        if ($externalReferences === []) {
            return;
        }
        $codebooks = $payload['codebooks'] ?? null;
        if (!is_array($codebooks)) {
            throw new RuntimeException("Katalog {$relative} nemá číselníky.");
        }
        $byKey = [];
        foreach ($codebooks as $codebook) {
            if (is_array($codebook) && is_string($codebook['codebook_key'] ?? null)) {
                $byKey[$codebook['codebook_key']] = $codebook;
            }
        }
        foreach ($externalReferences as $key) {
            $codebook = $byKey[$key] ?? null;
            if (
                !is_array($codebook)
                || ($codebook['source_kind'] ?? null) !== 'external_reference'
                || ($codebook['entry_count'] ?? null) !== 0
                || ($codebook['entries'] ?? null) !== []
            ) {
                throw new RuntimeException(
                    "Číselník {$key} v katalogu {$relative} musí zůstat prázdnou externí referencí.",
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assertCatalogSources(string $relative, array $payload, string $directory): void
    {
        $sources = $payload['sources'] ?? null;
        if (!is_array($sources)) {
            return;
        }
        foreach ($sources as $source) {
            if (!is_array($source) || !is_string($source['filename'] ?? null) || !is_string($source['sha256'] ?? null)) {
                throw new RuntimeException("Katalog {$relative} má neplatný odkaz na zdroj.");
            }
            $path = $directory . DIRECTORY_SEPARATOR . $source['filename'];
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals($source['sha256'], $hash)) {
                throw new RuntimeException(
                    "Zdroj {$source['filename']} katalogu {$relative} nemá hash zapsaný v manifestu.",
                );
            }
            $byteLength = $source['byte_length'] ?? null;
            if (is_int($byteLength) && filesize($path) !== $byteLength) {
                throw new RuntimeException(
                    "Zdroj {$source['filename']} katalogu {$relative} nemá počet bajtů zapsaný v manifestu.",
                );
            }
        }
    }

    private function copyTree(string $source, string $target): void
    {
        $items = new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                throw new RuntimeException("Adresář {$source} nelze projít.");
            }
            if ($item->isLink()) {
                throw new RuntimeException("Symlink není v úložišti číselníků povolen: {$item->getPathname()}.");
            }
            $destination = $target . DIRECTORY_SEPARATOR . $item->getFilename();
            if ($item->isDir()) {
                if (!mkdir($destination, 0777, true)) {
                    throw new RuntimeException("Přípravný adresář {$destination} nelze vytvořit.");
                }
                $this->copyTree($item->getPathname(), $destination);
            } elseif ($item->getFilename() !== 'SHA256SUMS' && !copy($item->getPathname(), $destination)) {
                throw new RuntimeException("Soubor {$item->getPathname()} nelze zkopírovat.");
            }
        }
    }

    private function writeContentManifest(string $root): void
    {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false) {
            throw new RuntimeException("Přípravný adresář {$root} nelze vyhodnotit.");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        $rootPrefix = rtrim($resolvedRoot, '/\\') . DIRECTORY_SEPARATOR;
        $entries = [];
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            if ($item->isLink()) {
                throw new RuntimeException("Symlink není v úložišti číselníků povolen: {$item->getPathname()}.");
            }
            if ($item->getFilename() === 'SHA256SUMS' || strtolower($item->getExtension()) === 'md') {
                continue;
            }
            $itemPath = $item->getRealPath();
            if ($itemPath === false || !str_starts_with(strtolower($itemPath), strtolower($rootPrefix))) {
                throw new RuntimeException("Cestu {$item->getPathname()} nelze vyhodnotit.");
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($itemPath, strlen($rootPrefix)));
            $hash = hash_file('sha256', $itemPath);
            if ($relative === '' || !is_string($hash)) {
                throw new RuntimeException("Soubor {$itemPath} nelze zapsat do katalogu obsahu.");
            }
            $entries[$relative] = $hash;
        }
        if ($entries === []) {
            throw new RuntimeException('Katalog obsahu číselníků nesmí být prázdný.');
        }

        ksort($entries, SORT_STRING);
        $lines = [];
        foreach ($entries as $relative => $hash) {
            $lines[] = $hash . '  ' . $relative;
        }
        $contents = implode("\n", $lines) . "\n";
        $path = $root . DIRECTORY_SEPARATOR . 'SHA256SUMS';
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException("Katalog obsahu {$path} nelze zapsat.");
        }
    }

    private function replaceDirectory(string $stage, string $target): void
    {
        $backup = dirname($target)
            . DIRECTORY_SEPARATOR
            . '.'
            . basename($target)
            . '.backup-'
            . bin2hex(random_bytes(8));
        $hadTarget = is_dir($target);

        if ($hadTarget && !@rename($target, $backup)) {
            throw new RuntimeException("Současné číselníky nelze přesunout do {$backup}.");
        }
        if (!@rename($stage, $target)) {
            if ($hadTarget && !@rename($backup, $target)) {
                throw new RuntimeException("Instalace selhala a návrat také; záloha je {$backup}.");
            }
            throw new RuntimeException("Číselníky nelze atomicky nainstalovat do {$target}.");
        }
        if ($hadTarget) {
            $this->removeDirectory($backup);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                throw new RuntimeException("Dočasný adresář {$path} nelze projít.");
            }
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
