<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Bootstrap;
use MyInvoice\Service\System\ManagedModeGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * H-02 / H-30 — brána nad tím, že zámek spravovaného režimu opravdu VISÍ tam,
 * kde má.
 *
 * {@see \MyInvoice\Tests\Unit\Service\System\ManagedModeGuardTest} dokazuje, že
 * třída zamyká správně. Tenhle test dokazuje něco jiného a stejně důležitého:
 * že se jí někdo ptá. Zámek, který se nikdo nezeptá, je totiž k nerozeznání od
 * zámku, který neexistuje — a odstraní se jedním smazaným řádkem, aniž by
 * cokoliv zčervenalo.
 *
 * Skrýt tlačítko nestačí; proto se kontroluje ACTION vrstva a CLI, ne UI.
 */
final class ManagedModeLockCoverageTest extends TestCase
{
    /**
     * Místo => konstanta předmětu zámku, kterou tam musí být vidět.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function lockSites(): array
    {
        return [
            'self-update HTTP' => [
                'api/src/Action/Admin/UpdateAction.php',
                'CAPABILITY_SELF_UPDATE',
            ],
            'self-update CLI' => [
                'api/bin/native-update.php',
                'CAPABILITY_SELF_UPDATE',
            ],
            'skenování adresáře s bankovními výpisy' => [
                'api/src/Action/Bank/BankStatementAction.php',
                'CAPABILITY_FILESYSTEM_SCAN',
            ],
            'skenování inboxu přijatých faktur' => [
                'api/src/Action/PurchaseInvoice/ScanInboxAction.php',
                'CAPABILITY_FILESYSTEM_SCAN',
            ],
            'vlastní SMTP transport' => [
                'api/src/Action/Settings/EmailProfilesAction.php',
                'CAPABILITY_MAIL_TRANSPORT',
            ],
            'vlastní domény firem' => [
                'api/src/Action/Settings/SupplierDomainAction.php',
                'CAPABILITY_CUSTOM_DOMAINS',
            ],
        ];
    }

    #[DataProvider('lockSites')]
    public function testLockSiteAsksTheGuard(string $relativePath, string $constant): void
    {
        $code = $this->source($relativePath);

        self::assertStringContainsString(
            'ManagedModeGuard',
            $code,
            $relativePath . ' se musí ptát ManagedModeGuard — zámek nesmí být jen v UI.',
        );
        self::assertStringContainsString(
            'ManagedModeGuard::' . $constant,
            $code,
            $relativePath . ' musí zamykat konkrétně ' . $constant . '.',
        );
        self::assertTrue(
            defined(ManagedModeGuard::class . '::' . $constant),
            'Konstanta ManagedModeGuard::' . $constant . ' neexistuje.',
        );
    }

    /**
     * CLI worker jde spustit i ručně ze shellu, takže zámek na
     * `POST /api/admin/update/trigger` sám nestačí. Odmítnutí musí navíc
     * uživateli říct PROČ — tichý exit vypadá jako rozbitý skript.
     */
    public function testNativeUpdateWorkerRefusesLoudly(): void
    {
        $code = $this->source('api/bin/native-update.php');

        self::assertStringContainsString('isLocked(ManagedModeGuard::CAPABILITY_SELF_UPDATE)', $code);
        self::assertStringContainsString('explain(ManagedModeGuard::CAPABILITY_SELF_UPDATE)', $code);
        self::assertStringContainsString('STDERR', $code);
        self::assertMatchesRegularExpression('/exit\(\s*[1-9]\d*\s*\)/', $code, 'Odmítnutí musí skončit nenulovým exit kódem.');
    }

    /**
     * Žádná Action nesmí `app.managed` číst po svém — jinak by se podmínka
     * rozsypala po obrazovkách a každá nová by na zámek musela přijít znovu.
     * (Diagnostické čtení mimo Action vrstvu, např. pro /api/health, je v pořádku.)
     */
    public function testActionsNeverReadAppManagedDirectly(): void
    {
        $offenders = [];
        foreach ($this->phpSources() as $path => $code) {
            $relative = $this->relative($path);
            if (!str_starts_with($relative, 'api/src/Action/')) {
                continue;
            }
            if (preg_match('/get\(\s*[\'"]app\.managed[\'"]/', $code) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'Action vrstva se musí ptát ManagedModeGuard, ne konfigurace.');
    }

    /**
     * Frontend se o stavu musí dozvědět z payloadu, který čte při startu.
     * Bez toho může UI akci jen skrýt — a skryté tlačítko bez vysvětlení
     * vypadá jako rozbitá funkce.
     */
    public function testStartupPayloadCarriesManagedFlag(): void
    {
        $action = $this->source('api/src/Action/Auth/SetupStatusAction.php');
        self::assertStringContainsString("'managed'", $action);
        self::assertStringContainsString('isManaged()', $action);

        $types = $this->source('web/src/api/auth.ts');
        self::assertStringContainsString('managed?: boolean', $types);

        $store = $this->source('web/src/stores/auth.ts');
        self::assertStringContainsString('isManagedInstallation', $store);
    }

    /**
     * `app.managed_provider` je výhradně diagnostický údaj do /api/health.
     * Jakmile by na něm viselo chování, přestal by být přenositelný celý SaaS:
     * aplikace nesmí vědět, KDO ji hostuje, jen že si nesmí sahat na vlastní
     * konfiguraci. Guard je jediné místo, kde se o zámcích rozhoduje, takže
     * stačí uhlídat, že jméno dodavatele nečte ani on.
     */
    public function testGuardNeverReadsProviderName(): void
    {
        $code = $this->source('api/src/Service/System/ManagedModeGuard.php');
        $withoutComments = $this->stripComments($code);

        self::assertStringNotContainsString(
            'managed_provider',
            $withoutComments,
            'Na jméně provozovatele nesmí viset žádné chování.',
        );
    }

    private function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return array<string,string> absolutní cesta => obsah */
    private function phpSources(): array
    {
        $root = $this->root() . '/api/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $out = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $out[$path] = (string) file_get_contents($path);
        }
        self::assertNotEmpty($out, 'Nenačetl se žádný zdroják — brána by tiše procházela.');

        return $out;
    }

    private function relative(string $absolute): string
    {
        return ltrim(substr($absolute, strlen($this->root())), '/');
    }

    private function root(): string
    {
        return rtrim(str_replace('\\', '/', Bootstrap::rootDir()), '/');
    }

    private function source(string $relative): string
    {
        $path = $this->root() . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
