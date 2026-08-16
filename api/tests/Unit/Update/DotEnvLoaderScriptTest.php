<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;

/**
 * Regrese k issue #14 — „autoupdater nepracoval jak měl".
 *
 * Docker skripty (`cmd/docker-update.sh`, `docker-install.sh`, `docker-ghcr.sh`)
 * dřív načítaly `.env` přes `set -a; . ./.env; set +a`, tedy ho SPOUŠTĚLY jako
 * shell kód. Hodnota s mezerou bez uvozovek
 *
 *     MYINVOICE_SMTP_FROM_NAME=Jan Novak
 *
 * proto shell rozsekl na přiřazení `…=Jan` a příkaz `Novak`, který neexistuje →
 * pod `set -e` updater umřel hned na druhém řádku a aktualizace zůstala
 * nedokončená. Zároveň to byl vektor spuštění cizího kódu z `.env`.
 *
 * Test proto ověřuje obojí:
 *   a) nový parser (`cmd/lib/env-load.sh`) hodnotu s mezerou naparsuje správně,
 *   b) starý postup (`set -a; . ./.env`) na TÉŽE fixture skutečně padá
 *      (= důkaz, že test bez opravy neprojde),
 *   c) z `.env` se nespustí žádný kód,
 *   d) PowerShell varianta (`cmd/lib/env-load.ps1`) vrací totéž — parita
 *      `.sh` ↔ `.ps1` vyžadovaná AGENTS.md.
 */
final class DotEnvLoaderScriptTest extends TestCase
{
    private string $tmp;
    private string $root;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', dirname(__DIR__, 4));
        $this->tmp  = str_replace('\\', '/', sys_get_temp_dir()) . '/myucto-dotenv-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);

        // Loadery i skripty kopírujeme do pracovního adresáře a všechno pouštíme
        // relativně — Windows cesty (`C:\…`) přes escapeshellarg do bashe
        // neprojdou a test by měřil quoting, ne parser.
        foreach (['env-load.sh', 'env-load.ps1'] as $file) {
            copy($this->root . '/cmd/lib/' . $file, $this->tmp . '/' . $file);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    // ---------- fixture ---------------------------------------------------

    /**
     * `.env` se vším, co uživatelé reálně píšou: hodnota s mezerou bez uvozovek
     * (issue #14), CRLF, BOM, komentáře, obojí uvozovky, `export`, řádek bez `=`.
     */
    private function writeFixture(): string
    {
        $path = $this->tmp . '/fixture.env';
        $lines = [
            "\u{FEFF}# MyUcto.cz - Docker compose env",
            'APP_PORT=8080',
            'MYINVOICE_SMTP_FROM_NAME=jmeno mezera jmeno',
            'QUOTED_DOUBLE="bar baz"',
            "QUOTED_SINGLE='sin gle'",
            'INLINE_COMMENT=bar   # tohle je komentar',
            'HASH_IN_QUOTES="a#b"',
            'ESCAPED_QUOTE="a\"b"',
            'NO_EXPANSION=$HOME',
            '  SPACED_KEY   =   value with spaces   ',
            'export EXPORTED=val ue',
            'DB_PORT2=3308',
            'this line has no equals sign',
            'TRAILING=last',
        ];

        // CRLF na první polovině — konce řádků nesmí hrát roli.
        $text = '';
        foreach ($lines as $i => $line) {
            $text .= $line . ($i < 6 ? "\r\n" : "\n");
        }
        file_put_contents($path, $text);

        return $path;
    }

    /** @return array{0:string,1:string,2:int} [stdout, stderr, exitCode] */
    private function runProcess(string $command): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, $this->tmp);
        self::assertIsResource($proc, 'proc_open selhal pro: ' . $command);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        return [$stdout, $stderr, $rc];
    }

    private function bashOrSkip(): void
    {
        [, , $rc] = $this->runProcess('bash -c "exit 0"');
        if ($rc !== 0) {
            self::markTestSkipped('bash není v PATH (na Linuxu i v git-bash na Windows být má).');
        }
    }

    /** Uloží bash snippet vedle fixtures a spustí ho — nulové quoting riziko. */
    private function runBashSnippet(string $name, string $script): array
    {
        file_put_contents($this->tmp . '/' . $name, "#!/usr/bin/env bash\n" . $script . "\n");

        return $this->runProcess('bash ' . $name);
    }

    /** @return array<string,string> */
    private function parsePrintOutput(string $stdout): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', trim($stdout)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[$k] = $v;
        }

        return $out;
    }

    // ---------- a) nový parser -------------------------------------------

    public function testShellLoaderParsesValuesWithSpacesAndQuotes(): void
    {
        $this->bashOrSkip();
        $this->writeFixture();

        [$stdout, $stderr, $rc] = $this->runProcess('bash env-load.sh --print fixture.env');

        self::assertSame(0, $rc, 'env-load.sh musí skončit nulou. stderr: ' . $stderr);
        $vars = $this->parsePrintOutput($stdout);

        // Jádro issue #14 — hodnota s mezerou BEZ uvozovek.
        self::assertSame('jmeno mezera jmeno', $vars['MYINVOICE_SMTP_FROM_NAME'] ?? null);

        self::assertSame('8080', $vars['APP_PORT'] ?? null);
        self::assertSame('bar baz', $vars['QUOTED_DOUBLE'] ?? null);
        self::assertSame('sin gle', $vars['QUOTED_SINGLE'] ?? null);
        self::assertSame('bar', $vars['INLINE_COMMENT'] ?? null);
        self::assertSame('a#b', $vars['HASH_IN_QUOTES'] ?? null);
        self::assertSame('a"b', $vars['ESCAPED_QUOTE'] ?? null);
        self::assertSame('$HOME', $vars['NO_EXPANSION'] ?? null, 'hodnota je data, ne kód — žádná expanze');
        self::assertSame('value with spaces', $vars['SPACED_KEY'] ?? null);
        self::assertSame('val ue', $vars['EXPORTED'] ?? null);
        self::assertSame('3308', $vars['DB_PORT2'] ?? null, 'klíč s číslicí (starý PS regex ho neznal)');
        self::assertSame('last', $vars['TRAILING'] ?? null);
        self::assertArrayNotHasKey('this line has no equals sign', $vars);
    }

    /**
     * Loader se v update skriptech sourcuje a exportuje proměnné do prostředí —
     * ověř, že se hodnota s mezerou dostane do `docker compose` v celku.
     */
    public function testShellLoaderExportsValueIntoEnvironment(): void
    {
        $this->bashOrSkip();
        $this->writeFixture();

        [$stdout, $stderr, $rc] = $this->runBashSnippet('load.sh', implode("\n", [
            'set -euo pipefail',
            '. ./env-load.sh',
            'dotenv_load ./fixture.env',
            'printf "[%s]" "$MYINVOICE_SMTP_FROM_NAME"',
        ]));

        self::assertSame(0, $rc, 'stderr: ' . $stderr);
        self::assertSame('[jmeno mezera jmeno]', trim($stdout));
    }

    // ---------- b) důkaz, že starý postup padal ---------------------------

    /**
     * Přesně to, co dřív stálo v `cmd/docker-update.sh:31`. Kdyby tenhle test
     * prošel, znamenalo by to, že fixture bug nereprodukuje a testy výše
     * nic nehlídají.
     */
    public function testLegacySourcingOfDotEnvActuallyBreaks(): void
    {
        $this->bashOrSkip();
        $this->writeFixture();

        [$stdout, $stderr, $rc] = $this->runBashSnippet('legacy.sh', implode("\n", [
            'set -euo pipefail',
            'set -a; . ./fixture.env; set +a',
            'echo LOADED',
        ]));

        self::assertNotSame(0, $rc, 'starý `. ./.env` musí na hodnotě s mezerou padnout — jinak test nic nedokazuje');
        self::assertStringNotContainsString('LOADED', $stdout, 'skript nesmí dojít za načtení .env');
        self::assertMatchesRegularExpression('/command not found|not found/i', $stderr);
    }

    // ---------- c) žádné spuštění kódu z .env -----------------------------

    public function testShellLoaderNeverExecutesContentOfDotEnv(): void
    {
        $this->bashOrSkip();

        $sentinel = $this->tmp . '/pwned.txt';
        file_put_contents($this->tmp . '/evil.env', implode("\n", [
            'APP_PORT=8080',
            'EVIL=$(touch pwned.txt)',
            'EVIL2=`touch pwned.txt`',
            'EVIL3=x; touch pwned.txt',
            'PATH=/pwned',
            '',
        ]));

        [$stdout, $stderr, $rc] = $this->runBashSnippet('evil.sh', implode("\n", [
            'set -euo pipefail',
            '. ./env-load.sh',
            'dotenv_load ./evil.env',
            'if [ "$PATH" = "/pwned" ]; then PATH_OK=NO; else PATH_OK=YES; fi',
            'printf "APP_PORT=%s\nPATH_OK=%s\n" "$APP_PORT" "$PATH_OK"',
        ]));

        self::assertSame(0, $rc, 'stderr: ' . $stderr);
        self::assertFileDoesNotExist($sentinel, '.env se NESMÍ spustit jako kód');
        self::assertStringContainsString('APP_PORT=8080', $stdout);
        self::assertStringContainsString('PATH_OK=YES', $stdout, 'PATH z .env se nesmí přepsat');
    }

    // ---------- d) parita .sh ↔ .ps1 --------------------------------------

    public function testPowerShellLoaderMatchesShellLoader(): void
    {
        $this->bashOrSkip();

        [, , $rc] = $this->runProcess('pwsh -NoProfile -Command "exit 0"');
        if ($rc !== 0) {
            self::markTestSkipped('pwsh není v PATH — PowerShell parita se testuje jen tam, kde PS 7 je.');
        }

        $this->writeFixture();

        [$shOut, , $shRc]      = $this->runProcess('bash env-load.sh --print fixture.env');
        [$psOut, $psErr, $psRc] = $this->runProcess('pwsh -NoProfile -File env-load.ps1 -Print fixture.env');

        self::assertSame(0, $shRc);
        self::assertSame(0, $psRc, 'stderr: ' . $psErr);
        self::assertSame(
            $this->parsePrintOutput($shOut),
            $this->parsePrintOutput($psOut),
            'cmd/lib/env-load.ps1 musí vracet totéž co cmd/lib/env-load.sh (AGENTS.md — multiplatformnost)'
        );
    }

    // ---------- e) skripty už .env nesourcují -----------------------------

    /**
     * Guard proti návratu vzoru. Hledá se `. ./.env` / `source .env` v update
     * a install skriptech — kdyby se to někdo pokusil „zjednodušit" zpět,
     * spadne to tady, ne až na produkci uživatele.
     */
    public function testDockerScriptsDoNotSourceDotEnv(): void
    {
        $scripts = [
            'cmd/docker-update.sh',
            'cmd/docker-install.sh',
            'cmd/docker-ghcr.sh',
        ];

        foreach ($scripts as $rel) {
            $file = $this->root . '/' . $rel;
            self::assertFileExists($file);
            $code = (string) file_get_contents($file);

            self::assertDoesNotMatchRegularExpression(
                '/^\s*(set -a;\s*)?(\.|source)\s+\.?\.?\/?\.env/m',
                $code,
                $rel . ' nesmí `.env` sourcovat — použij `dotenv_load` z cmd/lib/env-load.sh (issue #14)'
            );
            self::assertStringContainsString('dotenv_load', $code, $rel . ' má načítat .env přes dotenv_load');
        }
    }
}
