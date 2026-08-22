<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Setup;

use MyInvoice\Service\Setup\SetupPasswordMode;
use PHPUnit\Framework\TestCase;

/**
 * H-33 — dvě pravidla režimu „odkaz na nastavení hesla".
 */
final class SetupPasswordModeTest extends TestCase
{
    public function testClassicSetupKeepsPasswordAndAutoLogin(): void
    {
        $mode = SetupPasswordMode::fromAdminBlock(['email' => 'admin@example.test']);

        self::assertFalse($mode->usesSetupLink());
        self::assertTrue($mode->requiresPlainPassword());
        self::assertTrue($mode->issuesSession(), 'Wizard v prohlížeči se má po setupu rovnou přihlásit.');
        self::assertFalse($mode->returnsSetupToken());
    }

    public function testSetupLinkModeNeitherRequiresPasswordNorIssuesSession(): void
    {
        $mode = SetupPasswordMode::fromAdminBlock([SetupPasswordMode::REQUEST_FIELD => true]);

        self::assertTrue($mode->usesSetupLink());
        self::assertFalse($mode->requiresPlainPassword(), 'Cizí heslo nechceme držet ani minutu.');
        self::assertFalse(
            $mode->issuesSession(),
            'Setup voláme ze serveru — session by patřila nám, ne zákazníkovi.',
        );
        self::assertTrue($mode->returnsSetupToken());
    }

    /**
     * Jakákoli jiná hodnota než `true` je klasický setup. Volnější porovnání by
     * znamenalo, že překlep v požadavku tiše vypne auto-login.
     */
    public function testOnlyLiteralTrueSwitchesTheMode(): void
    {
        foreach (['true', 1, '1', 'yes', null, [], 0.0] as $value) {
            $mode = SetupPasswordMode::fromAdminBlock([SetupPasswordMode::REQUEST_FIELD => $value]);
            self::assertFalse($mode->usesSetupLink(), 'password_setup_link = ' . var_export($value, true));
            self::assertTrue($mode->requiresPlainPassword());
        }
    }
}
