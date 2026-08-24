<?php

declare(strict_types=1);

namespace MyInvoice\Service\Update;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\PhpCliLocator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Nativní auto-update z production bundle (GitHub release asset
 * `myucto-X.Y.Z.tar.gz`).
 *
 * Bundle je kompletní deployable strom — `api/vendor/`, `web/dist/`,
 * `manual/generated/` i `manual/manual.pdf` jsou představěné, takže host
 * nepotřebuje Composer, Node ani pnpm. Konfigurace a uživatelská data
 * v bundlu vůbec nejsou (CI je z tarballu vylučuje) a swap je navíc přeskočí
 * podle {@see self::PROTECTED_PATHS}.
 *
 * Pipeline (`run()`), spouštěná detached CLI workerem `api/bin/native-update.php`:
 *   1. preflight  — prostředí, práva, místo na disku, PHP CLI, phar
 *   2. download   — release asset podle tagu, nebo balíček předaný volajícím
 *                   ({@see self::useBundleOverride()}); vždy host allowlist, HTTPS only
 *   3. verify     — SHA-256 proti `.sha256` assetu / předanému otisku
 *   4. extract    — do staging dir, s validací všech cest v archivu
 *   5. backup     — soubory, které swap přepíše, se PŘESUNOU stranou (pro rollback)
 *   6. swap       — nasazení bundlu přes instalaci (bez mazání)
 *   7. migrate    — `php api/bin/migrate.php` už novým kódem
 *   8. finish     — teprve teď se přepíše `VERSION`
 *
 * ⚠️ **Swap nesmí zvýšit počet souborů na svazku.** Záloha vzniká PŘESUNEM
 * původního souboru a nová verze se PŘESOUVÁ ze stage, takže co jinde ubude,
 * jinde přibude; soubory beze změny se ze stage rovnou zahodí, takže bilance
 * dokonce klesá. Dřív se na obou koncích kopírovalo a na svazku musely naráz
 * existovat tři stromy — instalace, stage a rostoucí záloha. Sdílený hosting
 * má strop počtu souborů (inody, kvóta účtu) běžně jen kolem dvojnásobku
 * instalace, takže první spravovaná instance na to najela hned (2026-08-24:
 * 1682 zazálohovaných souborů z 12,5 tisíce, pak došly inody, a rollback
 * neobnovil ani jeden, protože i on si psal dočasnou kopii vedle cíle).
 * Hlídá to {@see \MyInvoice\Tests\Unit\Service\Update\NativeUpdateSwapTest}.
 *
 * `VERSION` se úmyslně píše jako poslední krok: dokud migrace neproběhnou,
 * instalace se hlásí starou verzí, takže přerušený update nevypadá jako
 * dokončený a UI dál nabízí aktualizaci.
 *
 * Selhání swapu spustí rollback ze zálohy. Selhání migrace rollback
 * NEspouští — schéma už může být částečně změněné a vracet kód pod novým
 * schématem by škodilo víc; výsledek se označí `failed` a řeší se ručně.
 *
 * Bezpečnostní model: důvěřujeme GitHub releasu přes TLS. SHA-256 z `.sha256`
 * assetu chrání proti poškozenému přenosu, ne proti kompromitovanému
 * účtu/repu (checksum má stejný trust root jako tarball). Update smí spustit
 * jen superadmin.
 */
final class NativeUpdateService
{
    /** Kroky pipeline v pořadí — UI je zobrazuje jako progress. */
    public const STEPS = ['preflight', 'download', 'verify', 'extract', 'backup', 'swap', 'migrate', 'finish'];

    private const RELEASE_BY_TAG_API = 'https://api.github.com/repos/radekhulan/myucto/releases/tags/v';

    /**
     * Odkud smí bundle přijít. GitHub redirectuje asset download na svůj
     * blob storage, takže hosty musíme povolit i pro cíl redirectu.
     */
    private const ALLOWED_DOWNLOAD_HOSTS = [
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    /**
     * Nikdy nepřepisovat — konfigurace, uživatelská data, git metadata.
     * Porovnává se na relativní cestu vůči rootu (přesná shoda nebo prefix
     * `cesta/`). Bundle je tyhle položky neobsahuje, tohle je druhá pojistka.
     */
    private const PROTECTED_PATHS = [
        'cfg.php',
        'cfg.local.php',
        'cfg.docker.php',
        '.env',
        'storage',
        'private',
        'log',
        'tmp',
        '.git',
        'api/vendor.prod',
    ];

    private const MAX_BUNDLE_BYTES = 600 * 1024 * 1024;
    private const MIN_FREE_BYTES   = 512 * 1024 * 1024;
    private const HTTP_TIMEOUT     = 1800;
    private const API_TIMEOUT      = 20;

    private readonly string $rootDir;
    private readonly string $stateDir;

    /** Relativní cesty už přepsané v aktuálním swapu — podklad pro rollback. */
    private array $swapped = [];

    /** Odložené `.myucto-old` soubory, které zatím nešlo smazat (zamčené). */
    private array $parked = [];

    /**
     * Balíček předaný zvenčí (provozovatelem) místo dohledávání přes GitHub API.
     * Buď obojí, nebo nic — {@see self::useBundleOverride()}.
     */
    private ?string $bundleUrl = null;
    private ?string $bundleSha256 = null;

    public function __construct(?string $rootDir = null, ?string $stateDir = null)
    {
        $this->rootDir = rtrim($rootDir ?? Bootstrap::rootDir(), '/\\');
        // Shodná logika jako VersionService::stateBaseDir() — flag/result/log
        // musí končit tam, kde je hledá VersionService.
        $this->stateDir = rtrim($stateDir ?? (Config::resolveDataDir() ?? $this->rootDir), '/\\');
    }

    // ---------- preflight -------------------------------------------------

    /**
     * Ověří, že nativní update na `$target` má smysl zkoušet. Blockery update
     * neumožní, warnings jsou informativní.
     *
     * @return array{ok:bool, supported:bool, blockers:list<string>, warnings:list<string>}
     */
    public function preflight(string $target): array
    {
        $blockers = [];
        $warnings = [];

        if (!self::isValidVersion($target)) {
            $blockers[] = 'Cílová verze „' . $target . '" není platný semver (X.Y.Z).';
        }

        if (!function_exists('gzopen')) {
            $blockers[] = 'Chybí rozšíření zlib — bez něj nelze rozbalit tar.gz bundle.';
        }
        if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $blockers[] = 'Není dostupné ani cURL, ani allow_url_fopen — bundle nelze stáhnout.';
        }
        if (PhpCliLocator::resolve() === null) {
            $blockers[] = 'Nenalezena PHP CLI binárka (PHP_BINARY=' . PHP_BINARY . ') — nelze spustit worker ani migrace.';
        }

        foreach (['', 'api', 'web', 'manual'] as $sub) {
            $dir = $sub === '' ? $this->rootDir : $this->rootDir . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            if (!$this->isDirWritable($dir)) {
                $blockers[] = 'Adresář ' . ($sub === '' ? '(root instalace)' : $sub) . ' není zapisovatelný pro uživatele webserveru.';
            }
        }

        if (!$this->canReplaceFiles()) {
            $blockers[] = 'Existující soubory nelze přepsat (rename/unlink selhal) — zkontroluj práva a zámky souborů.';
        }

        $free = @disk_free_space($this->stateDir);
        if (is_float($free) && $free < self::MIN_FREE_BYTES) {
            $blockers[] = 'Na disku je jen ' . $this->humanBytes((int) $free)
                . ' volného místa; bundle + záloha potřebují aspoň ' . $this->humanBytes(self::MIN_FREE_BYTES) . '.';
        }

        if (is_dir($this->rootDir . '/.git')) {
            $warnings[] = 'Instalace je git checkout — po aktualizaci bundlem bude pracovní kopie špinavá. '
                . 'Ve vývoji preferuj `git checkout v' . $target . '`.';
        }
        if (ini_get('opcache.enable') && !filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN)) {
            $warnings[] = 'opcache.validate_timestamps je vypnuté — po aktualizaci restartuj php-fpm / IIS application pool, '
                . 'jinak poběží stará bytecode cache.';
        }

        return [
            'ok'        => $blockers === [],
            'supported' => true,
            'blockers'  => $blockers,
            'warnings'  => $warnings,
        ];
    }

    // ---------- pipeline --------------------------------------------------

    /**
     * Přímý zdroj balíčku místo dohledávání assetu přes GitHub API.
     *
     * PROČ TO EXISTUJE: při zřizování posíláme hostingu kontraktní trojici
     * vydání — `app_version` + `bundle_url` + `bundle_sha256`. Nasazení na už
     * běžící instanci musí použít TENTÝŽ balíček, ne „nejnovější, co zrovna
     * leží na GitHubu": mezi zřízením a nasazením může na releasu přibýt jiný
     * asset, tag se dá přepsat a instance ve flotile by pak nesly různý kód
     * pod stejným číslem verze. S předanou trojicí je nasazení reprodukovatelné
     * a otisk je ten, na kterém jsme se dohodli.
     *
     * Pravidla:
     *  - buď OBA parametry, nebo ŽÁDNÝ — neúplná dvojice je chyba, ne částečné
     *    použití (jinak by šlo stáhnout cizí balíček a nechat si otisk dopočítat
     *    z něj samotného),
     *  - `$sha256` musí být 64 hex znaků,
     *  - `$url` prochází stejným allowlistem hostů jako všechno ostatní
     *    ({@see self::assertAllowedUrl()}) — allowlist se kvůli operátorovi
     *    nerozšiřuje,
     *  - ověření otisku zůstává povinné; při neshodě se balíček maže
     *    ({@see self::verifyChecksum()}).
     */
    public function useBundleOverride(?string $url, ?string $sha256): void
    {
        $url    = $url === null ? null : trim($url);
        $sha256 = $sha256 === null ? null : trim($sha256);

        if (($url === null || $url === '') && ($sha256 === null || $sha256 === '')) {
            $this->bundleUrl    = null;
            $this->bundleSha256 = null;

            return;
        }
        if ($url === null || $url === '' || $sha256 === null || $sha256 === '') {
            throw new RuntimeException('Zdroj balíčku se předává vždy jako dvojice bundle_url + bundle_sha256; '
                . 'jedna hodnota bez druhé se nepoužije.');
        }
        if (preg_match('/^[0-9a-f]{64}$/i', $sha256) !== 1) {
            throw new RuntimeException('bundle_sha256 musí být 64 hexadecimálních znaků, dostal jsem: ' . $sha256);
        }
        $this->assertAllowedUrl($url);

        $this->bundleUrl    = $url;
        $this->bundleSha256 = strtolower($sha256);
    }

    /**
     * Kompletní update. Volá se z CLI workeru, ne z HTTP requestu.
     *
     * `$bundleUrl` + `$bundleSha256` jsou volitelné a platí pro ně pravidla
     * z {@see self::useBundleOverride()}; bez nich se balíček dohledá v releasu
     * podle tagu jako dosud.
     *
     * @return array<string,mixed> result payload (zapsaný i do upgrade-result.json)
     */
    public function run(string $target, string $requestedBy, ?string $bundleUrl = null, ?string $bundleSha256 = null): array
    {
        $log = $this->logPath();

        if (!self::isValidVersion($target)) {
            return $this->finishFailed($target, 'Cílová verze „' . $target . '" není platný semver.', $log);
        }

        $this->appendLog($log, str_repeat('=', 60));
        $this->appendLog($log, 'MyUcto.cz nativní update → v' . $target . ' (žádal ' . $requestedBy . ')');
        $this->appendLog($log, 'root=' . $this->rootDir . ' state=' . $this->stateDir);

        $work = $this->stateDir . '/storage/updates/' . $target;

        try {
            // Argumenty přebíjejí dřív nastavený zdroj; když nepřijdou žádné,
            // platí to, co volající nastavil přes useBundleOverride().
            if ($bundleUrl !== null || $bundleSha256 !== null) {
                $this->useBundleOverride($bundleUrl, $bundleSha256);
            }
            if ($this->bundleUrl !== null) {
                $this->appendLog($log, 'Zdroj balíčku předán volajícím: ' . $this->bundleUrl);
            }

            $this->progress($target, 'preflight', 'Kontroluji prostředí…');
            $pf = $this->preflight($target);
            foreach ($pf['warnings'] as $w) {
                $this->appendLog($log, 'WARN: ' . $w);
            }
            if (!$pf['ok']) {
                throw new RuntimeException('Preflight selhal: ' . implode(' ', $pf['blockers']));
            }
            $this->ensureDir($work);

            $this->progress($target, 'download', 'Stahuji production bundle…');
            [$bundlePath, $expectedSha] = $this->downloadBundle($target, $work, $log);

            $this->progress($target, 'verify', 'Ověřuji SHA-256 kontrolní součet…');
            $this->verifyChecksum($bundlePath, $expectedSha, $log);

            $this->progress($target, 'extract', 'Rozbaluji bundle…');
            $stage = $this->extractBundle($bundlePath, $work, $target, $log);

            $this->progress($target, 'backup', 'Zakládám zálohu přepisovaných souborů…');
            $backup = $work . '/backup';
            $this->ensureDir($backup);

            // Od téhle chvíle je instalace nekonzistentní (půl staré, půl nové
            // verze; schéma ještě neposunuté) a musí requestům odpovídat 503,
            // ne fatálem z půlky autoloadu. Značka padá až po migracích.
            MaintenanceMode::begin($this->stateDir, 'MyÚčto.cz', $target);
            $this->appendLog($log, 'Režim údržby zapnut — requesty dostanou 503 do konce migrací.');

            $this->progress($target, 'swap', 'Nasazuji nové soubory…');
            $swapped = $this->swap($stage, $backup, $target, $log);

            $this->progress($target, 'migrate', 'Spouštím databázové migrace…');
            $this->runMigrations($log);

            MaintenanceMode::end($this->stateDir);
            $this->appendLog($log, 'Režim údržby vypnut.');

            $this->progress($target, 'finish', 'Dokončuji…');
            $this->writeVersionFile($target, $stage, $log);
            $this->cleanupWork($work, $log);
            $this->pruneOldBackups($target, $log);
            $this->cleanupParked($log);

            $this->appendLog($log, 'HOTOVO: nasazena verze ' . $target . ' (' . $swapped . ' souborů, záloha: ' . $backup . ')');

            return $this->finishApplied($target, $swapped, $backup, $log, $pf['warnings']);
        } catch (Throwable $e) {
            $this->appendLog($log, 'CHYBA: ' . $e->getMessage());
            $this->cleanupParked($log);
            return $this->finishFailed($target, $e->getMessage(), $log);
        } finally {
            // I aktualizace, která skončila chybou, musí instalaci vrátit do
            // provozu. Bez tohohle by ji značka držela na 503 až do vlastní
            // expirace, tedy i po rollbacku na funkční starou verzi.
            MaintenanceMode::end($this->stateDir);
        }
    }

    // ---------- download / verify -----------------------------------------

    /**
     * Najde v releasu podle tagu asset `myucto-X.Y.Z.tar.gz` + jeho `.sha256`,
     * stáhne oba.
     *
     * Když volající předal konkrétní balíček ({@see self::useBundleOverride()}),
     * GitHub API se vůbec neptáme a stáhneme přesně to, co dostal hosting při
     * zřizování. Otisk se pak neodvozuje ze staženého souboru, ale je ten
     * předaný — jinak by kontrola nic neověřovala.
     *
     * @return array{0:string, 1:string} cesta k bundlu, očekávaný sha256
     */
    private function downloadBundle(string $target, string $work, string $log): array
    {
        if ($this->bundleUrl !== null && $this->bundleSha256 !== null) {
            $bundlePath = $work . '/myucto-' . $target . '.tar.gz';
            $this->appendLog($log, 'Stahuji ' . $this->bundleUrl . ' (zdroj předán volajícím)');
            $this->downloadToFile($this->bundleUrl, $bundlePath, $target);
            $actualSize = (int) @filesize($bundlePath);
            $this->appendLog($log, 'Staženo ' . $this->humanBytes($actualSize));
            if ($actualSize > self::MAX_BUNDLE_BYTES) {
                throw new RuntimeException('Bundle je ' . $this->humanBytes($actualSize) . ', limit je '
                    . $this->humanBytes(self::MAX_BUNDLE_BYTES) . '.');
            }

            return [$bundlePath, $this->bundleSha256];
        }

        $release = $this->httpGetJson(self::RELEASE_BY_TAG_API . $target);
        $assets  = is_array($release['assets'] ?? null) ? $release['assets'] : [];

        $bundleName = 'myucto-' . $target . '.tar.gz';
        $bundleUrl  = null;
        $shaUrl     = null;
        $expectSize = null;
        foreach ($assets as $a) {
            $name = (string) ($a['name'] ?? '');
            $url  = (string) ($a['browser_download_url'] ?? '');
            if ($name === $bundleName) {
                $bundleUrl  = $url;
                $expectSize = isset($a['size']) ? (int) $a['size'] : null;
            } elseif ($name === $bundleName . '.sha256') {
                $shaUrl = $url;
            }
        }
        if ($bundleUrl === null) {
            throw new RuntimeException('Release v' . $target . ' neobsahuje asset ' . $bundleName . '.');
        }
        if ($shaUrl === null) {
            throw new RuntimeException('Release v' . $target . ' neobsahuje kontrolní součet ' . $bundleName . '.sha256.');
        }
        if ($expectSize !== null && $expectSize > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException('Bundle je ' . $this->humanBytes($expectSize) . ', limit je '
                . $this->humanBytes(self::MAX_BUNDLE_BYTES) . '.');
        }

        $bundlePath = $work . '/' . $bundleName;
        $this->appendLog($log, 'Stahuji ' . $bundleUrl);
        $this->downloadToFile($bundleUrl, $bundlePath, $target);
        $actualSize = (int) @filesize($bundlePath);
        $this->appendLog($log, 'Staženo ' . $this->humanBytes($actualSize));
        if ($expectSize !== null && $actualSize !== $expectSize) {
            throw new RuntimeException('Velikost bundlu nesouhlasí (čekáno ' . $expectSize . ' B, stáhnuto ' . $actualSize . ' B).');
        }

        $shaRaw = $this->httpGetString($shaUrl);
        if (!preg_match('/\b([0-9a-f]{64})\b/i', $shaRaw, $m)) {
            throw new RuntimeException('Asset ' . $bundleName . '.sha256 neobsahuje čitelný SHA-256.');
        }

        return [$bundlePath, strtolower($m[1])];
    }

    private function verifyChecksum(string $bundlePath, string $expectedSha, string $log): void
    {
        $actual = hash_file('sha256', $bundlePath);
        if (!is_string($actual)) {
            throw new RuntimeException('SHA-256 stáhnutého bundlu nelze spočítat.');
        }
        $actual = strtolower($actual);
        $this->appendLog($log, 'SHA-256 ' . $actual);
        if (!hash_equals($expectedSha, $actual)) {
            @unlink($bundlePath);
            throw new RuntimeException('Kontrolní součet nesouhlasí (čekáno ' . $expectedSha . ', spočítáno ' . $actual
                . '). Bundle byl smazán, aktualizace zrušena.');
        }
    }

    // ---------- extract ----------------------------------------------------

    /**
     * Rozbalí bundle do čistého staging dir přes {@see TarGzExtractor}, který
     * validuje každou cestu v archivu (prefix `myucto-X.Y.Z/`, žádné `..`,
     * absolutní cesty ani odkazy). Pak ověří, že strom vypadá jako kompletní
     * production bundle.
     *
     * @return string cesta ke staged stromu (obsah bez horního prefixu)
     */
    private function extractBundle(string $bundlePath, string $work, string $target, string $log): string
    {
        $stageRoot = $work . '/stage';
        $this->removeTree($stageRoot);
        $this->ensureDir($stageRoot);

        $prefix = 'myucto-' . $target . '/';
        $files  = (new TarGzExtractor())->extract($bundlePath, $stageRoot, $prefix);
        $this->appendLog($log, 'Rozbaleno ' . count($files) . ' souborů');

        // Ve staging rootu smí být právě jeden adresář — verzovaný prefix.
        // Cokoliv dalšího znamená, že archiv obsahoval obsah, který jsme
        // nečekali, a bundle nenasadíme.
        $topLevel = array_values(array_diff((array) @scandir($stageRoot), ['.', '..']));
        if ($topLevel !== [rtrim($prefix, '/')]) {
            throw new RuntimeException('Rozbalený bundle má nečekaný obsah v kořeni: '
                . implode(', ', array_map('strval', $topLevel)) . '.');
        }

        $stage = $stageRoot . '/' . rtrim($prefix, '/');
        if (!is_dir($stage)) {
            throw new RuntimeException('Rozbalený bundle neobsahuje očekávaný adresář ' . rtrim($prefix, '/') . '.');
        }

        $this->assertStagedTreeSane($stage, $target);
        $this->appendLog($log, 'Staging strom OK: ' . $stage);

        return $stage;
    }

    /** Rozbalený strom musí vypadat jako plnohodnotný production bundle. */
    private function assertStagedTreeSane(string $stage, string $target): void
    {
        $versionFile = $stage . '/VERSION';
        if (!is_file($versionFile)) {
            throw new RuntimeException('Bundle neobsahuje soubor VERSION.');
        }
        $staged = trim((string) @file_get_contents($versionFile));
        if ($staged !== $target) {
            throw new RuntimeException('Bundle hlásí verzi „' . $staged . '", čekána „' . $target . '".');
        }
        foreach (['api/vendor/autoload.php', 'api/public/index.php', 'api/src', 'web/dist', 'db/migrations'] as $required) {
            if (!file_exists($stage . '/' . $required)) {
                throw new RuntimeException('Bundle neobsahuje ' . $required . ' — nejde o kompletní production bundle.');
            }
        }
    }

    // ---------- backup + swap ---------------------------------------------

    /**
     * Nakopíruje staged strom přes instalaci. Soubory, které přepisuje,
     * nejdřív zazálohuje. Nic nemaže — soubory, které v novém bundlu nejsou,
     * zůstávají (bezpečnější a stejné chování jako ruční `tar -xzf`).
     *
     * `VERSION` se v tomhle kroku úmyslně přeskočí, píše ho až `finish`.
     *
     * @return int počet nasazených souborů
     */
    private function swap(string $stage, string $backup, string $target, string $log): int
    {
        $this->swapped = [];
        $files = 0;
        $skipped = 0;

        // Cesty se seberou PŘEDEM, ne za běhu iterátoru: swap staged soubory
        // PŘESOUVÁ ({@see self::moveIntoPlace()}), takže by si iterátor
        // podřezával větev pod sebou.
        $entries = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stage, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($it as $item) {
            $entries[] = [$item->getPathname(), $item->isDir()];
        }

        try {
            foreach ($entries as [$path, $isDir]) {
                $rel = $this->relativePath($stage, $path);
                if ($rel === null || $rel === 'VERSION' || $this->isProtected($rel)) {
                    continue;
                }
                $dest = $this->rootDir . '/' . $rel;

                if ($isDir) {
                    $this->ensureDir($dest);
                    continue;
                }
                if (!is_file($path)) {
                    continue;
                }

                // ⚠️ Beze změny = nesahat na to vůbec.
                //
                // Drtivá většina vydání je bajt po bajtu totožná s tím, co na
                // instalaci leží (samotný `api/vendor` je přes polovinu stromu
                // a mezi opravnými vydáními se nehne). Zálohovat a přepisovat
                // takový soubor je práce navíc, která nic nemění — a hlavně
                // drží v záloze místo i inode za soubor, který by rollback
                // stejně vrátil ve stejné podobě.
                //
                // Staged kopii proto rovnou zahodíme. Počet souborů na svazku
                // tím během swapu KLESÁ, místo aby jen stagnoval, takže se
                // aktualizace vejde i na účet, kde by dvojí strom nevyšel.
                if (is_file($dest) && $this->sameContent($path, $dest)) {
                    @unlink($path);
                    $skipped++;
                    continue;
                }

                if (is_file($dest)) {
                    $this->backupFile($rel, $dest, $backup);
                }
                // Do rollback listu se cesta zapisuje ještě PŘED přepisem:
                // když replaceFile selže napůl (cíl už smazaný, nová verze
                // nezapsaná), musí ho rollback vrátit ze zálohy taky.
                $this->swapped[] = $rel;
                $this->moveIntoPlace($path, $dest);
                $files++;

                if ($files % 500 === 0) {
                    $this->progress($target, 'swap', 'Nasazuji nové soubory… (' . $files . ')');
                }
            }
        } catch (Throwable $e) {
            $this->appendLog($log, 'Swap selhal po ' . $files . ' souborech — spouštím rollback.');
            $restored = $this->rollback($backup, $log);
            throw new RuntimeException('Nasazení souborů selhalo (' . $e->getMessage() . '). Rollback vrátil '
                . $restored . ' souborů ze zálohy ' . $backup . '.', 0, $e);
        }

        $this->appendLog($log, 'Swap OK: ' . $files . ' souborů nasazeno, '
            . $skipped . ' beze změny (přeskočeno)');

        return $files;
    }

    /**
     * Mají oba soubory totožný obsah?
     *
     * Velikost je hrubé síto, které vyřadí většinu rozdílů za jeden `stat`.
     * Teprve při shodě se počítá otisk — na obsahu, ne na čase změny: staged
     * strom se právě rozbalil z archivu, takže mtime nesedí NIKDY a jako
     * kritérium by prohlásil za změněný úplně každý soubor.
     *
     * `xxh128` je nekryptografický otisk a je to tady správně: neporovnáváme
     * se s nedůvěryhodnou stranou, jen hledáme shodu dvou lokálních souborů.
     * Pravost balíčku řeší podpisem SHA-256 {@see self::verifyChecksum()}.
     */
    private function sameContent(string $a, string $b): bool
    {
        $sizeA = @filesize($a);
        $sizeB = @filesize($b);
        if ($sizeA === false || $sizeB === false || $sizeA !== $sizeB) {
            return false;
        }

        $algo = in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'sha256';
        $hashA = @hash_file($algo, $a);
        $hashB = @hash_file($algo, $b);

        return $hashA !== false && $hashB !== false && $hashA === $hashB;
    }

    /**
     * Odloží původní soubor do zálohy — PŘESUNEM, ne kopií.
     *
     * ⚠️ Tohle je rozdíl mezi „aktualizace projde" a „aktualizace nemá kam".
     * Kopie znamená, že v jednu chvíli existují TŘI stromy naráz: instalace,
     * rozbalený stage a rostoucí záloha. Na sdíleném hostingu je strop počtu
     * souborů (inody, kvóta účtu) běžně jen kolem dvojnásobku instalace, takže
     * záloha narazí po pár tisících souborech a aktualizace spadne uprostřed —
     * přesně tak, jak to udělala na první spravované instanci (2026-08-24:
     * 1682 zazálohovaných souborů z 12,5 tisíce, pak došly inody).
     *
     * `rename()` jen přepojí jméno na existující inode: záloha nestojí ani
     * jeden navíc a instalace se o něj na okamžik zmenší. Ve dvojici
     * s {@see self::moveIntoPlace()} je bilance celého swapu NULOVÁ.
     *
     * Kopie zůstává pro případ, že přesun nejde — typicky když stage
     * a instalace leží na jiném svazku.
     */
    private function backupFile(string $rel, string $dest, string $backup): void
    {
        $target = $backup . '/' . $rel;
        $this->ensureDir(dirname($target));

        if (@rename($dest, $target)) {
            return;
        }
        if (!@copy($dest, $target)) {
            throw new RuntimeException(
                'Nelze zazálohovat ' . $rel . ' do ' . $target
                . '. Na svazku pravděpodobně došlo místo nebo povolený počet souborů (inody/kvóta účtu).'
            );
        }
    }

    /**
     * Přesune staged soubor na jeho místo v instalaci.
     *
     * Protivaha k {@see self::backupFile()}: co se přesunem uvolní ze stage, se
     * přesunem objeví v instalaci, takže počet souborů zůstává během swapu
     * plochý. Kdyby se kopírovalo, stage by až do závěrečného úklidu držel
     * druhou kopii celého stromu.
     *
     * Cíl v tuhle chvíli běžně NEEXISTUJE — backupFile ho odstěhoval. Když
     * přesto existuje (záloha musela padnout na kopii) nebo přesun nejde,
     * použije se původní opatrná cesta {@see self::replaceFile()} i s celou
     * windowsí logikou kolem zamčených souborů.
     */
    private function moveIntoPlace(string $src, string $dest): void
    {
        $this->ensureDir(dirname($dest));

        if (!file_exists($dest) && @rename($src, $dest)) {
            return;
        }
        $this->replaceFile($src, $dest);
    }

    /**
     * Přepis souboru s co nejkratším okamžikem nekonzistence: zapiš vedle,
     * pak přejmenuj. Na Windows rename přes existující soubor selže vždy,
     * proto fallbacky.
     *
     * Zamčený cíl (na Windows typicky `api/bin/native-update.php` — vlastní
     * běžící worker: PHP drží handle na spouštěný skript) nejde přepsat ani
     * copy, ani unlink+rename: `unlink()` sice uspěje, ale jméno zůstane
     * v delete-pending stavu až do konce procesu, takže se na něj nedá nic
     * zapsat — a soubor po doběhnutí zmizí. Otevřený soubor ale Windows
     * dovolí *přejmenovat*, proto cíl nejdřív uhneme stranou (`.myucto-old`)
     * a novou verzi přesuneme na uvolněné jméno. Když ani to nevyjde, vrátíme
     * původní soubor zpátky — instalace po neúspěšném přepisu nikdy nesmí
     * zůstat bez souboru.
     */
    private function replaceFile(string $src, string $dest): void
    {
        $this->ensureDir(dirname($dest));
        $tmp = $dest . '.myucto-upd';
        if (!@copy($src, $tmp)) {
            throw new RuntimeException('Nelze zapsat ' . $tmp . '.');
        }
        if (@rename($tmp, $dest)) {
            return;
        }

        $parked = null;
        if (is_file($dest)) {
            $candidate = $dest . '.myucto-old';
            @unlink($candidate);
            if (@rename($dest, $candidate)) {
                $parked = $candidate;
            }
        }
        if (@rename($tmp, $dest) || @copy($tmp, $dest)) {
            @unlink($tmp);
            if ($parked !== null) {
                $this->discardParked($parked);
            }
            return;
        }
        // Uhnout stranou nešlo — zbývá destruktivní varianta (cíl je pak už
        // stejně jen v záloze, ze které umí rollback).
        if ($parked === null && is_file($dest) && @unlink($dest)
            && (@rename($tmp, $dest) || @copy($tmp, $dest))) {
            @unlink($tmp);
            return;
        }
        if ($parked !== null) {
            @rename($parked, $dest);
        }
        @unlink($tmp);
        throw new RuntimeException('Nelze přepsat ' . $dest . ' (soubor je zamčený nebo chybí práva).');
    }

    /**
     * Zahodí odloženou původní verzi souboru. Na Windows je zámek držený jen
     * do konce procesu, takže co teď nejde smazat, zkusíme na konci pipeline
     * ještě jednou {@see self::cleanupParked()}.
     */
    private function discardParked(string $path): void
    {
        if (@unlink($path)) {
            return;
        }
        $this->parked[] = $path;
    }

    private function cleanupParked(string $log): void
    {
        $left = [];
        foreach ($this->parked as $path) {
            if (is_file($path) && !@unlink($path)) {
                $left[] = $path;
            }
        }
        $this->parked = [];
        if ($left !== []) {
            $this->appendLog($log, 'Zbyly odložené soubory (smaž ručně po restartu): ' . implode(', ', $left));
        }
    }

    /** Vrátí zazálohované soubory zpět na místo. @return int počet obnovených */
    private function rollback(string $backup, string $log): int
    {
        $restored = 0;
        foreach ($this->swapped as $rel) {
            $src = $backup . '/' . $rel;
            if (!is_file($src)) {
                continue;
            }
            try {
                // ⚠️ Taky přesunem. Rollback běží typicky PRÁVĚ TEHDY, když
                // došly inody nebo místo — a `replaceFile()` si zapisuje
                // dočasnou kopii vedle cíle, takže by v tu chvíli selhal na
                // každém souboru. Přesně to se stalo 2026-08-24: rollback
                // vrátil 0 z 1682 souborů. Přesun nic nového nealokuje.
                $this->restoreFile($src, $this->rootDir . '/' . $rel);
                $restored++;
            } catch (Throwable $e) {
                $this->appendLog($log, 'Rollback: nelze obnovit ' . $rel . ' (' . $e->getMessage() . ')');
            }
        }
        $this->appendLog($log, 'Rollback obnovil ' . $restored . ' z ' . count($this->swapped) . ' souborů.');

        return $restored;
    }

    /**
     * Vrátí soubor ze zálohy na místo. Přesunem — důvod viz {@see self::rollback()};
     * kopie zůstává jen jako nouzová varianta, když přesun nejde.
     *
     * Záloha o soubor přesunem přijde, a je to tak správně: cílem rollbacku je
     * dostat instalaci zpátky do provozu, ne udržet zálohu pro druhý pokus.
     */
    private function restoreFile(string $src, string $dest): void
    {
        $this->ensureDir(dirname($dest));

        if (@rename($src, $dest)) {
            return;
        }
        $this->replaceFile($src, $dest);
    }

    // ---------- migrace + finish -----------------------------------------

    /** Spustí `api/bin/migrate.php` samostatným CLI procesem — už novým kódem. */
    private function runMigrations(string $log): void
    {
        $php = PhpCliLocator::resolve();
        if ($php === null) {
            throw new RuntimeException('Nenalezena PHP CLI binárka pro spuštění migrací.');
        }
        $script = $this->rootDir . '/api/bin/migrate.php';
        if (!is_file($script)) {
            throw new RuntimeException('Chybí ' . $script . ' — nasazený bundle je neúplný.');
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
        $this->appendLog($log, '$ ' . $cmd);

        $out = [];
        $rc  = 0;
        @exec($cmd, $out, $rc);
        foreach ($out as $line) {
            $this->appendLog($log, '  ' . $line);
        }
        if ($rc !== 0) {
            throw new RuntimeException('Migrace selhaly (exit ' . $rc . '). Kód je už nasazený, schéma ne — '
                . 'projdi log ' . basename($log) . ' a dokonči `php api/bin/migrate.php` ručně.');
        }
        $this->appendLog($log, 'Migrace OK');
    }

    /** Poslední krok — teprve teď se instalace prohlásí za novou verzi. */
    private function writeVersionFile(string $target, string $stage, string $log): void
    {
        $src = $stage . '/VERSION';
        $this->replaceFile($src, $this->rootDir . '/VERSION');
        $this->appendLog($log, 'VERSION → ' . $target);
    }

    private function cleanupWork(string $work, string $log): void
    {
        $this->removeTree($work . '/stage');
        foreach ((array) @glob($work . '/myucto-*.tar.gz') as $f) {
            @unlink((string) $f);
        }
        $this->appendLog($log, 'Úklid: staging a tarball smazány, záloha zachována.');
    }

    /** Nechá poslední dvě zálohy, starší smaže — ať nenaroste disk. */
    private function pruneOldBackups(string $keepTarget, string $log): void
    {
        $base = $this->stateDir . '/storage/updates';
        $dirs = array_values(array_filter((array) @glob($base . '/*'), 'is_dir'));
        if (count($dirs) <= 2) {
            return;
        }
        usort($dirs, static fn ($a, $b) => (@filemtime((string) $a) ?: 0) <=> (@filemtime((string) $b) ?: 0));
        $removable = array_slice($dirs, 0, count($dirs) - 2);
        foreach ($removable as $dir) {
            if (basename((string) $dir) === $keepTarget) {
                continue;
            }
            $this->removeTree((string) $dir);
            $this->appendLog($log, 'Smazána stará záloha ' . basename((string) $dir));
        }
    }

    // ---------- progress / result ----------------------------------------

    /**
     * Zapíše aktuální krok do upgrade flagu. `heartbeat_at` drží flag živý —
     * VersionService podle něj pozná, že worker běží (a neproškrtne ho TTL).
     */
    private function progress(string $target, string $step, string $message): void
    {
        $flag = $this->stateDir . '/storage/upgrade-requested.json';
        $payload = [];
        if (is_file($flag)) {
            $decoded = json_decode((string) @file_get_contents($flag), true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $payload['mode']           = 'native';
        $payload['target_version'] = $target;
        $payload['step']           = $step;
        $payload['step_index']     = (int) array_search($step, self::STEPS, true) + 1;
        $payload['step_count']     = count(self::STEPS);
        $payload['step_message']   = $message;
        $payload['heartbeat_at']   = date(\DateTimeInterface::ATOM);

        $this->ensureDir(dirname($flag));
        @file_put_contents($flag, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @param list<string> $warnings */
    private function finishApplied(string $target, int $files, string $backup, string $log, array $warnings): array
    {
        $message = 'Nasazena verze ' . $target . ' z production bundlu (' . $files . ' souborů). '
            . 'Záloha přepsaných souborů: ' . $backup . '.';
        if ($warnings !== []) {
            $message .= ' ' . implode(' ', $warnings);
        }

        return $this->writeResult([
            'status'         => 'applied',
            'mode'           => 'native',
            'target_version' => $target,
            'applied_at'     => date(\DateTimeInterface::ATOM),
            'message'        => $message,
            'log_path'       => $log,
            'backup_path'    => $backup,
        ]);
    }

    private function finishFailed(string $target, string $error, string $log): array
    {
        return $this->writeResult([
            'status'         => 'failed',
            'mode'           => 'native',
            'target_version' => $target,
            'applied_at'     => date(\DateTimeInterface::ATOM),
            'message'        => $error . ' Podrobnosti: ' . $log,
            'log_path'       => $log,
        ]);
    }

    /** @param array<string,mixed> $result */
    private function writeResult(array $result): array
    {
        $path = $this->stateDir . '/storage/upgrade-result.json';
        $this->ensureDir(dirname($path));
        @file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        // Flag musí zmizet, jinak UI zůstane na „upgrade probíhá".
        @unlink($this->stateDir . '/storage/upgrade-requested.json');

        return $result;
    }

    // ---------- HTTP ------------------------------------------------------

    /** @return array<string,mixed> */
    private function httpGetJson(string $url): array
    {
        $raw  = $this->httpGetString($url);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('GitHub API vrátil ne-JSON odpověď (' . $url . ').');
        }

        return $data;
    }

    private function httpGetString(string $url): string
    {
        $this->assertAllowedUrl($url);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init selhal.');
            }
            curl_setopt_array($ch, $this->curlBaseOptions() + [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::API_TIMEOUT,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            // curl_close() je od PHP 8.5 deprecated (a od 8.0 no-op) — handle
            // uvolní GC sám, jak vyjde z rozsahu.
            unset($ch);
            if (!is_string($body)) {
                throw new RuntimeException('Stažení ' . $url . ' selhalo: ' . $err);
            }
            if ($code >= 400) {
                throw new RuntimeException('GitHub vrátil HTTP ' . $code . ' pro ' . $url . '.');
            }

            return $body;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "User-Agent: MyUcto.cz/native-update\r\nAccept: application/vnd.github+json\r\n",
            'timeout'       => self::API_TIMEOUT,
            'follow_location' => 1,
            'max_redirects' => 5,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!is_string($body)) {
            throw new RuntimeException('Stažení ' . $url . ' selhalo (allow_url_fopen).');
        }

        return $body;
    }

    private function downloadToFile(string $url, string $dest, string $target): void
    {
        $this->assertAllowedUrl($url);
        $this->ensureDir(dirname($dest));
        @unlink($dest);

        $fh = @fopen($dest, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Nelze zapisovat do ' . $dest . '.');
        }

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                if ($ch === false) {
                    throw new RuntimeException('curl_init selhal.');
                }
                $lastBeat = 0;
                curl_setopt_array($ch, $this->curlBaseOptions() + [
                    CURLOPT_FILE       => $fh,
                    CURLOPT_TIMEOUT    => self::HTTP_TIMEOUT,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => function ($_c, $total, $now) use ($target, &$lastBeat): int {
                        if ($now > self::MAX_BUNDLE_BYTES) {
                            return 1; // abort
                        }
                        if (time() - $lastBeat >= 5) {
                            $lastBeat = time();
                            $pct = $total > 0 ? ' (' . (int) round($now / $total * 100) . ' %)' : '';
                            $this->progress($target, 'download', 'Stahuji production bundle…' . $pct);
                        }

                        return 0;
                    },
                ]);
                $ok   = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err  = curl_error($ch);
                unset($ch); // viz httpGetString() — curl_close() je deprecated
                if ($ok === false) {
                    throw new RuntimeException('Stahování bundlu selhalo: ' . ($err !== '' ? $err : 'přerušeno'));
                }
                if ($code >= 400) {
                    throw new RuntimeException('Stahování bundlu vrátilo HTTP ' . $code . '.');
                }

                return;
            }

            $ctx = stream_context_create(['http' => [
                'method'          => 'GET',
                'header'          => "User-Agent: MyUcto.cz/native-update\r\n",
                'timeout'         => self::HTTP_TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 5,
            ]]);
            $in = @fopen($url, 'rb', false, $ctx);
            if ($in === false) {
                throw new RuntimeException('Nelze otevřít ' . $url . ' (allow_url_fopen).');
            }
            $copied = @stream_copy_to_stream($in, $fh, self::MAX_BUNDLE_BYTES);
            fclose($in);
            if ($copied === false || $copied === 0) {
                throw new RuntimeException('Stahování bundlu selhalo (0 B).');
            }
        } finally {
            fclose($fh);
        }
    }

    /** @return array<int,mixed> */
    private function curlBaseOptions(): array
    {
        $opts = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_USERAGENT      => 'MyUcto.cz/native-update',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
        ];
        // Redirect z GitHubu smí jít jen zase na HTTPS — nikdy na http/file/ftp.
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $opts[CURLOPT_PROTOCOLS_STR]       = 'https';
            $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        return $opts;
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https') {
            throw new RuntimeException('Povolené je jen HTTPS, dostal jsem: ' . $url);
        }
        $allowed = array_merge(self::ALLOWED_DOWNLOAD_HOSTS, ['api.github.com']);
        if (!in_array($host, $allowed, true)) {
            throw new RuntimeException('Nepovolený host pro stahování: ' . $host);
        }
    }

    // ---------- utils -----------------------------------------------------

    public static function isValidVersion(string $v): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $v) === 1;
    }

    /** Leží relativní cesta v chráněné množině? */
    public function isProtected(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (self::PROTECTED_PATHS as $p) {
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return string|null relativní cesta s `/`, nebo null když leží mimo base */
    private function relativePath(string $base, string $path): ?string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $base)) {
            return null;
        }
        $rel = substr($path, strlen($base));

        return $rel === '' ? null : $rel;
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nelze vytvořit adresář ' . $dir . '.');
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function isDirWritable(string $dir): bool
    {
        $probe = $dir . '/.myucto-write-probe';
        $ok = @file_put_contents($probe, 'x') !== false;
        @unlink($probe);

        return $ok;
    }

    /** Ověří, že jde přepsat existující soubor (rename/unlink nejsou blokované). */
    private function canReplaceFiles(): bool
    {
        $probe = $this->rootDir . '/.myucto-replace-probe';
        if (@file_put_contents($probe, 'old') === false) {
            return false;
        }
        try {
            $this->replaceFile($probe, $probe);

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $this->parked = [];
            @unlink($probe);
            @unlink($probe . '.myucto-upd');
            @unlink($probe . '.myucto-old');
        }
    }

    private function logPath(): string
    {
        return $this->stateDir . '/storage/upgrade-' . gmdate('Ymd\THis\Z') . '.log';
    }

    private function appendLog(string $path, string $line): void
    {
        $this->ensureDir(dirname($path));
        @file_put_contents($path, '[' . gmdate('H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'kB', 'MB', 'GB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
