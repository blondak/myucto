<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\I18n;

use MyInvoice\I18n\ErrorCatalog;
use MyInvoice\I18n\Locale;
use PHPUnit\Framework\TestCase;

final class ErrorCatalogTest extends TestCase
{
    public function testCsLocaleReturnsInputUnchanged(): void
    {
        self::assertSame(
            'Vystavenou fakturu nelze editovat.',
            ErrorCatalog::lookup('Vystavenou fakturu nelze editovat.', 'cs'),
        );
    }

    public function testEnLocaleTranslatesKnownMessage(): void
    {
        self::assertSame(
            'An issued invoice cannot be edited.',
            ErrorCatalog::lookup('Vystavenou fakturu nelze editovat.', 'en'),
        );
    }

    public function testCanonicalHostnameConflictHasEnglishTranslation(): void
    {
        self::assertSame(
            'The hostname configured in app.url cannot be used as a company custom domain. Enter a different hostname.',
            ErrorCatalog::lookup(
                'Hostname nastavený v app.url nelze použít jako vlastní doménu firmy. Zadejte jiný hostname.',
                'en',
            ),
        );
    }

    public function testNewDomainBoundaryErrorsHaveEnglishTranslations(): void
    {
        $messages = [
            'Canonical hostname koliduje s vlastní doménou firmy.'
                => 'The canonical hostname conflicts with a company custom domain.',
            'Canonical přechod není pro tuto cestu povolený.'
                => 'Canonical handoff is not allowed for this path.',
            'DNS nebo HTTPS ověření domény selhalo.'
                => 'DNS or HTTPS domain verification failed.',
            'Doména musí mít čerstvě ověřené DNS a HTTPS.'
                => 'The domain must have freshly verified DNS and HTTPS.',
            'Doména se během ověření změnila; spusť kontrolu znovu.'
                => 'The domain changed during verification; run the check again.',
            'Doména se před ověřením změnila; spusť kontrolu znovu.'
                => 'The domain changed before verification; run the check again.',
        ];

        foreach ($messages as $cs => $en) {
            self::assertSame($en, ErrorCatalog::lookup($cs, 'en'), $cs);
        }
    }

    public function testEnLocaleReturnsInputForUnknownMessage(): void
    {
        self::assertSame(
            'Tohle není v katalogu',
            ErrorCatalog::lookup('Tohle není v katalogu', 'en'),
        );
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', ErrorCatalog::lookup('', 'en'));
    }

    public function testPrefixMatchForExceptionMessages(): void
    {
        // V katalogu: "Email se nepodařilo odeslat: " => "Failed to send email: "
        // Volání s konkatenovanou příčinou musí prefix přeložit.
        self::assertSame(
            'Failed to send email: connection timeout',
            ErrorCatalog::lookup('Email se nepodařilo odeslat: connection timeout', 'en'),
        );
    }

    public function testLocaleStateGlobal(): void
    {
        Locale::set('en');
        self::assertSame('en', Locale::current());
        Locale::set('cs');
        self::assertSame('cs', Locale::current());
        // Reset na default po testu, aby neovlivnil další testy
    }

    public function testLocaleSetUnknownFallsBackToCs(): void
    {
        Locale::set('xx');
        self::assertSame('cs', Locale::current());
    }
}
