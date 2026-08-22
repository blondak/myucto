<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\System;

use MyInvoice\I18n\ErrorCatalog;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\System\ManagedInstallationException;
use MyInvoice\Service\System\ManagedModeGuard;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * H-02 — co spravovaný režim zamyká.
 *
 * Testy jsou psané tak, aby padaly na obou způsobech, jak jde pravidlo pokazit:
 *
 *  1. rozšířit zámek na self-hosted instalace (tam se chování měnit NESMÍ) —
 *     {@see testSelfHostedInstallationLocksNothing},
 *  2. zamknout jen to, co je náhodou vyjmenované, a nechat protéct sousední
 *     klíč ze stejného bloku — {@see testSmtpBlockIsLockedByPrefixNotByEnumeration}.
 *
 * A na tom, na čem H-02 stojí: zámek platí i mimo UI, tedy jako HTTP odpověď
 * {@see testDenyProducesMachineReadableConflict}.
 */
final class ManagedModeGuardTest extends TestCase
{
    private function guard(bool $managed): ManagedModeGuard
    {
        return new ManagedModeGuard(new Config(['app' => ['managed' => $managed]]));
    }

    public function testSelfHostedInstallationLocksNothing(): void
    {
        $guard = $this->guard(false);

        self::assertFalse($guard->isManaged());
        foreach (array_merge($guard->lockedKeys(), $guard->lockedCapabilities()) as $subject) {
            self::assertFalse($guard->isLocked($subject), $subject);
            self::assertTrue($guard->isConfigurable($subject), $subject);
        }
        // Bez app.managed nesmí `deny()` nikdy nic vrátit — jinak by se z jedné
        // společné třídy stal zámek i pro self-hosted instalace.
        self::assertNull($guard->deny($this->response(), ManagedModeGuard::CAPABILITY_SELF_UPDATE));
    }

    public function testMissingConfigKeyBehavesLikeSelfHosted(): void
    {
        $guard = new ManagedModeGuard(new Config([]));

        self::assertFalse($guard->isManaged());
        self::assertFalse($guard->isLocked(ManagedModeGuard::CAPABILITY_SELF_UPDATE));
    }

    /**
     * Hodnota může přijít z ENV jako řetězec (`MYINVOICE_APP_MANAGED=1`).
     * `(bool) "false"` je true — proto se čte přes FILTER_VALIDATE_BOOLEAN.
     */
    public function testStringyConfigValuesAreInterpretedAsBooleans(): void
    {
        foreach ([true, 1, '1', 'true', 'on', 'yes'] as $value) {
            $guard = new ManagedModeGuard(new Config(['app' => ['managed' => $value]]));
            self::assertTrue($guard->isManaged(), 'app.managed = ' . var_export($value, true));
        }
        foreach ([false, 0, '0', 'false', 'off', 'no', '', 'zcela nesmyslná hodnota'] as $value) {
            $guard = new ManagedModeGuard(new Config(['app' => ['managed' => $value]]));
            self::assertFalse($guard->isManaged(), 'app.managed = ' . var_export($value, true));
        }
    }

    public function testManagedInstallationLocksEveryDocumentedSubject(): void
    {
        $guard = $this->guard(true);

        // Seznam je schválně napsaný ručně, ne přečtený z lockedKeys() — jinak by
        // test odsouhlasil i to, že někdo položku ze seznamu vyhodí.
        $expected = [
            'app.url',
            'app.debug',
            'demo.enabled',
            'epo_test',
            'bank_import.scan_root',
            'purchase_invoice.inbox_dir',
            'smtp',
            ManagedModeGuard::CAPABILITY_SELF_UPDATE,
            ManagedModeGuard::CAPABILITY_MAIL_TRANSPORT,
            ManagedModeGuard::CAPABILITY_FILESYSTEM_SCAN,
            ManagedModeGuard::CAPABILITY_CUSTOM_DOMAINS,
        ];

        foreach ($expected as $subject) {
            self::assertTrue($guard->isLocked($subject), $subject);
            self::assertFalse($guard->isConfigurable($subject), $subject);
        }
    }

    public function testSmtpBlockIsLockedByPrefixNotByEnumeration(): void
    {
        $guard = $this->guard(true);

        // Nově přidaný klíč do bloku (obálková adresa, další ověřování) musí být
        // zamčený automaticky — na výčet by se zapomnělo.
        foreach (['smtp.host', 'smtp.from_email', 'smtp.envelope_from', 'smtp.dkim.private_key_path'] as $key) {
            self::assertFalse($guard->isConfigurable($key), $key);
        }

        // Prefix ale nesmí chytat nic, co jen začíná stejnými písmeny.
        self::assertTrue($guard->isConfigurable('smtponly.host'));
        self::assertTrue($guard->isConfigurable('app.timezone'));
        self::assertTrue($guard->isConfigurable(''));
    }

    public function testAssertConfigurableThrowsWithSubjectAndHumanReason(): void
    {
        $guard = $this->guard(true);

        $guard->assertConfigurable('app.timezone');

        try {
            $guard->assertConfigurable('smtp.host');
            self::fail('Zamčený klíč musí vyhodit ManagedInstallationException.');
        } catch (ManagedInstallationException $e) {
            self::assertSame('smtp.host', $e->subject);
            self::assertNotSame('', $e->getMessage());
            self::assertStringNotContainsStringIgnoringCase('servermaster', $e->getMessage());
        }
    }

    public function testDenyProducesMachineReadableConflict(): void
    {
        $guard = $this->guard(true);

        $response = $guard->deny($this->response(), ManagedModeGuard::CAPABILITY_FILESYSTEM_SCAN);
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('managed_installation', $body['error']['code']);
        self::assertSame(ManagedModeGuard::CAPABILITY_FILESYSTEM_SCAN, $body['error']['locked']);
        self::assertNotSame('', $body['error']['message']);
    }

    public function testEffectiveFlagReadsLockedSwitchesAsOff(): void
    {
        $selfHosted = $this->guard(false);
        $managed    = $this->guard(true);

        // Zamčený přepínač se čte jako vypnutý bez ohledu na cfg…
        self::assertFalse($managed->effectiveFlag(ManagedModeGuard::KEY_EPO_TEST, true));
        self::assertFalse($managed->effectiveFlag(ManagedModeGuard::KEY_DEMO_ENABLED, true));
        self::assertFalse($managed->effectiveFlag(ManagedModeGuard::KEY_APP_DEBUG, true));

        // …a nezamčený přesně podle cfg.
        self::assertTrue($selfHosted->effectiveFlag(ManagedModeGuard::KEY_EPO_TEST, true));
        self::assertFalse($selfHosted->effectiveFlag(ManagedModeGuard::KEY_EPO_TEST, false));
        self::assertTrue($managed->effectiveFlag('app.timezone_autodetect', true));
    }

    /**
     * Aplikace nesmí vědět, kdo ji hostuje. `app.managed_provider` je výhradně
     * diagnostický údaj do /api/health — kdyby na něm viselo chování, jedna
     * instance na jiném hostingu by se chovala jinak.
     */
    public function testProviderNameNeverChangesBehaviourNorLeaksIntoMessages(): void
    {
        $guard = new ManagedModeGuard(new Config([
            'app' => ['managed' => true, 'managed_provider' => 'Tajný Provozovatel s.r.o.'],
        ]));
        $without = $this->guard(true);

        self::assertSame($without->lockedKeys(), $guard->lockedKeys());
        self::assertSame($without->lockedCapabilities(), $guard->lockedCapabilities());

        foreach (array_merge($guard->lockedKeys(), $guard->lockedCapabilities()) as $subject) {
            self::assertSame($without->explain($subject), $guard->explain($subject), $subject);
            self::assertStringNotContainsString('Provozovatel s.r.o.', $guard->explain($subject), $subject);
        }
    }

    /** Každá hláška, kterou uživatel může dostat, musí být přeložitelná. */
    public function testEveryExplanationHasEnglishTranslation(): void
    {
        $guard = $this->guard(true);

        $subjects = array_merge($guard->lockedKeys(), $guard->lockedCapabilities(), ['neznámý předmět']);
        foreach ($subjects as $subject) {
            $cs = $guard->explain($subject);
            self::assertNotSame(
                $cs,
                ErrorCatalog::lookup($cs, 'en'),
                'Chybí EN překlad hlášky pro ' . $subject,
            );
        }
    }

    private function response(): \Psr\Http\Message\ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }
}
