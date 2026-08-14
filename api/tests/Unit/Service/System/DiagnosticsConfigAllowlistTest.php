<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\DiagnosticsConfigAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bezpečnostní hranice diagnostického balíčku.
 *
 * Výřez konfigurace je jediná část balíčku, která se dotýká `cfg.php` — tedy
 * souboru s pepperem, šifrovacím klíčem, heslem k databázi, SMTP pověřeními
 * a heslem k zálohám. Kdyby byl postavený na denylistu („vynech, co vypadá jako
 * heslo"), stačilo by přidat do konfigurace nový klíč a tajemství by odešlo ven
 * s prvním balíčkem, aniž by si toho kdokoli všiml.
 *
 * Proto se tu netestuje „jsou vybraná tajemství skrytá", ale tvrdší invariant:
 * **do výstupu se nesmí dostat žádná hodnota, která není na allowlistu.**
 */
final class DiagnosticsConfigAllowlistTest extends TestCase
{
    /** Hodnoty, které se ve výstupu nesmí objevit za žádných okolností. */
    private const SECRET_VALUES = [
        'pepper'          => 'PEPPER-SECRET-a1b2c3d4e5f6',
        'encryption'      => 'ENCKEY-SECRET-f6e5d4c3b2a1',
        'payroll_hash'    => 'PAYROLLKEY-SECRET-0f1e2d3c',
        'db_password'     => 'DBPASS-SECRET-9z8y7x6w',
        'redis_password'  => 'REDISPASS-SECRET-5v4u3t',
        'smtp_password'   => 'SMTPPASS-SECRET-2s1r0q',
        'smtp_secret'     => 'OAUTHSECRET-SECRET-p9o8i7',
        'smtp_refresh'    => 'REFRESHTOKEN-SECRET-u6y5t4',
        'captcha_secret'  => 'CAPTCHASECRET-SECRET-r3e2w1',
        'signing_pass'    => 'SIGNPASS-SECRET-q0p9o8',
        'pdf_sign_pass'   => 'PDFSIGNPASS-SECRET-i7u6y5',
        'backup_password' => 'BACKUPPASS-SECRET-t4r3e2',
        'undeclared'      => 'FUTURE-SECRET-should-never-leak',
    ];

    /**
     * Konfigurace se vším, co v reálném `cfg.php` bývá — včetně klíče, který
     * allowlist vůbec nezná (`app.future_secret`). Právě ten je jádro testu:
     * denylist by ho propustil, allowlist ne.
     *
     * @return array<string,mixed>
     */
    private static function configTree(): array
    {
        return [
            'app' => [
                'env'                   => 'production',
                'debug'                 => false,
                'timezone'              => 'Europe/Prague',
                'url'                   => 'https://ucto.example.test',
                'pepper'                => self::SECRET_VALUES['pepper'],
                'secret_encryption_key' => self::SECRET_VALUES['encryption'],
                'payroll_hash_key'      => self::SECRET_VALUES['payroll_hash'],
                'future_secret'         => self::SECRET_VALUES['undeclared'],
            ],
            'db' => [
                'host'     => '127.0.0.1',
                'port'     => 3306,
                'name'     => 'myucto_test',
                'user'     => 'myucto',
                'password' => self::SECRET_VALUES['db_password'],
                'charset'  => 'utf8mb4',
            ],
            'redis' => [
                'enabled'  => true,
                'host'     => '127.0.0.1',
                'port'     => 6379,
                'password' => self::SECRET_VALUES['redis_password'],
            ],
            'smtp' => [
                'transport' => 'smtp',
                'host'      => 'smtp.example.test',
                'port'      => 587,
                'username'  => 'ucto@example.test',
                'password'  => self::SECRET_VALUES['smtp_password'],
                'oauth'     => [
                    'client_secret' => self::SECRET_VALUES['smtp_secret'],
                    'refresh_token' => self::SECRET_VALUES['smtp_refresh'],
                ],
            ],
            'captcha' => [
                'provider'   => 'turnstile',
                'site_key'   => 'PUBLIC-SITE-KEY',
                'secret_key' => self::SECRET_VALUES['captcha_secret'],
            ],
            'signing'     => ['passphrase' => self::SECRET_VALUES['signing_pass']],
            'pdf_signing' => ['passphrase' => self::SECRET_VALUES['pdf_sign_pass']],
            'cron'        => ['backup' => ['password' => self::SECRET_VALUES['backup_password']]],
            'logging'     => ['level' => 'info', 'max_files' => 90],
        ];
    }

    private function export(): array
    {
        return (new DiagnosticsConfigAllowlist(new Config(self::configTree())))->export();
    }

    #[DataProvider('secretProvider')]
    public function testSecretValueNeverAppearsInExport(string $label, string $secret): void
    {
        $json = json_encode($this->export(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($json);
        self::assertStringNotContainsString(
            $secret,
            $json,
            'Tajemství „' . $label . '" prosáklo do diagnostického balíčku.'
        );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function secretProvider(): array
    {
        $cases = [];
        foreach (self::SECRET_VALUES as $label => $secret) {
            $cases[$label] = [$label, $secret];
        }

        return $cases;
    }

    /**
     * Tvrdý invariant: každý vypsaný klíč musí být jmenovitě na allowlistu.
     * Tenhle test padá i tehdy, když někdo do exportu přidá „jen jeden neškodný"
     * klíč mimo seznam — což je přesně ta cesta, kterou by tajemství uniklo.
     */
    public function testExportEmitsOnlyAllowlistedKeys(): void
    {
        $export = $this->export();

        foreach (array_keys($export['values']) as $key) {
            self::assertContains($key, DiagnosticsConfigAllowlist::VALUES, 'Klíč „' . $key . '" není na allowlistu hodnot.');
        }
        foreach (array_keys($export['presence']) as $key) {
            self::assertContains($key, DiagnosticsConfigAllowlist::PRESENCE, 'Klíč „' . $key . '" není na allowlistu přítomnosti.');
        }
    }

    /** Množiny se nesmí překrývat — klíč nemůže být zároveň vypsaný i maskovaný. */
    public function testValueAndPresenceListsAreDisjoint(): void
    {
        self::assertSame(
            [],
            array_intersect(DiagnosticsConfigAllowlist::VALUES, DiagnosticsConfigAllowlist::PRESENCE)
        );
    }

    public function testPresenceReportsWhetherSecretIsSetWithoutRevealingIt(): void
    {
        $presence = $this->export()['presence'];

        self::assertSame('<set>', $presence['app.pepper']);
        self::assertSame('<set>', $presence['db.password']);
        // Klíč, který v konfiguraci vůbec není, musí být rozlišitelný od prázdného.
        self::assertSame('<unset>', $presence['license.public_key']);
    }

    /** Neutrální hodnoty musí projít — jinak by diagnostika nebyla k ničemu. */
    public function testNeutralValuesArePassedThrough(): void
    {
        $values = $this->export()['values'];

        self::assertSame('production', $values['app.env']);
        self::assertSame('Europe/Prague', $values['app.timezone']);
        self::assertSame('utf8mb4', $values['db.charset']);
        self::assertSame(90, $values['logging.max_files']);
    }

    /**
     * Vnořená struktura se nesmí propustit celá — mohla by nést neprověřený
     * podstrom s tajemstvím (přesně případ `smtp.oauth`).
     */
    public function testNestedArraysAreNotEmittedVerbatim(): void
    {
        $config = new Config(['auth' => ['allowed_mfa_methods' => ['passkey', 'totp']]]);
        $values = (new DiagnosticsConfigAllowlist($config))->export()['values'];

        self::assertSame(['passkey', 'totp'], $values['auth.allowed_mfa_methods']);

        $nested = new Config(['auth' => ['allowed_mfa_methods' => ['a' => ['deep' => 'x']]]]);
        $out    = (new DiagnosticsConfigAllowlist($nested))->export()['values'];

        self::assertSame('<array:1>', $out['auth.allowed_mfa_methods']);
    }

    /** `CHANGE-ME` ze vzorové konfigurace je nález, ne tajemství. */
    public function testDefaultPlaceholderIsReportedAsFinding(): void
    {
        $config   = new Config(['app' => ['pepper' => 'CHANGE-ME']]);
        $presence = (new DiagnosticsConfigAllowlist($config))->export()['presence'];

        self::assertSame('<default:CHANGE-ME>', $presence['app.pepper']);
    }
}
