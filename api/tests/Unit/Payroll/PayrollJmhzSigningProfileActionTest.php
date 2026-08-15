<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Action\Payroll\PayrollJmhzSigningProfileAction;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Doménová pravidla volby podpisového certifikátu pro mzdová podání.
 *
 * Testuje se rozhodovací jádro akce, ne HTTP obálka: právě v něm se láme,
 * jestli podání projde u ČSSZ, nebo se vrátí jako odmítnuté několik dní po
 * odeslání. Akce se instancuje bez konstruktoru (stejný idiom jako
 * `SigningProfilesActionTest`) — služby, které konstruktor bere, do těchhle
 * rozhodnutí nevstupují a jejich dvojníci by test jen zamlžili.
 */
final class PayrollJmhzSigningProfileActionTest extends TestCase
{
    public function testEnvironmentAcceptsOnlyTestAndProduction(): void
    {
        self::assertSame('test', $this->invoke('normalizeEnvironment', 'test'));
        self::assertSame('production', $this->invoke('normalizeEnvironment', 'production'));
        self::assertSame('production', $this->invoke('normalizeEnvironment', '  PRODUCTION '));

        // Cokoliv jiného je 422 — prostředí je součástí primárního klíče a překlep
        // by znamenal volbu, kterou nikdo nikdy nenajde.
        self::assertNull($this->invoke('normalizeEnvironment', 'sandbox'));
        self::assertNull($this->invoke('normalizeEnvironment', 'prod'));
        self::assertNull($this->invoke('normalizeEnvironment', ''));
        self::assertNull($this->invoke('normalizeEnvironment', null));
        self::assertNull($this->invoke('normalizeEnvironment', 1));
    }

    public function testSerialMatchesAcrossSeparatorsCaseAndLeadingZeros(): void
    {
        $match = $this->constant('SERIAL_MATCH');

        self::assertSame($match, $this->invoke('compareSerial', '1a2b3c', '1a2b3c'));
        self::assertSame($match, $this->invoke('compareSerial', '1a2b3c', '1A:2B:3C'));
        self::assertSame($match, $this->invoke('compareSerial', '1a2b3c', '1a 2b 3c'));
        self::assertSame($match, $this->invoke('compareSerial', '1a2b3c', '0x1A2B3C'));
        // X.509 tiskne s vedoucí nulou (kladné znaménko), papír od ČSSZ bez ní.
        self::assertSame($match, $this->invoke('compareSerial', '001a2b3c', '1a2b3c'));
        self::assertSame($match, $this->invoke('compareSerial', '1a2b3c', '00001a2b3c'));
    }

    public function testSerialMatchesWhenCsszPaperworkPrintsItDecimally(): void
    {
        $match = $this->constant('SERIAL_MATCH');

        // 0x1a2b3c == 1715004; ČSSZ formulář „Oznámení o pověření" tiskne decimálně.
        self::assertSame($match, $this->invoke('compareSerial', '1a2b3c', '1715004'));
        // 0x11 == 17: hodnota čitelná oběma způsoby musí projít decimální větví.
        self::assertSame($match, $this->invoke('compareSerial', '11', '17'));
        self::assertSame($match, $this->invoke('compareSerial', '11', '11'));
    }

    public function testSerialMismatchIsRefused(): void
    {
        $mismatch = $this->constant('SERIAL_MISMATCH');

        self::assertSame($mismatch, $this->invoke('compareSerial', '1a2b3c', '1715005'));
        self::assertSame($mismatch, $this->invoke('compareSerial', '1a2b3c', 'deadbeef'));
        self::assertSame($mismatch, $this->invoke('compareSerial', '1a2b3c', '1a2b3d'));
    }

    public function testUnknownCertificateSerialIsNeverSilentlyAccepted(): void
    {
        $unknown = $this->constant('SERIAL_CERTIFICATE_UNKNOWN');

        self::assertSame($unknown, $this->invoke('compareSerial', null, '1715004'));
        self::assertSame($unknown, $this->invoke('compareSerial', '', '1715004'));
        // Nečitelný sériový údaj u certifikátu je totéž jako žádný: kontrolu
        // nelze provést, takže se nesmí tvářit, že proběhla.
        self::assertSame($unknown, $this->invoke('compareSerial', 'neznámé', '1715004'));
    }

    public function testUnreadableCsszSerialIsRejectedRatherThanGuessed(): void
    {
        $unreadable = $this->constant('SERIAL_INPUT_UNREADABLE');

        self::assertSame($unreadable, $this->invoke('compareSerial', '1a2b3c', 'viz příloha'));
        self::assertSame($unreadable, $this->invoke('compareSerial', '1a2b3c', '  '));
        self::assertSame($unreadable, $this->invoke('compareSerial', '1a2b3c', '12g4'));
    }

    public function testHexToDecimalSurvivesFullLengthSerials(): void
    {
        self::assertSame('256', $this->invoke('hexToDecimal', '100'));
        self::assertSame('1715004', $this->invoke('hexToDecimal', '1a2b3c'));
        // 20 bajtů = maximální délka sériového čísla podle RFC 5280; 2^160 - 1
        // se do PHP intu nevejde, proto řetězcová aritmetika.
        self::assertSame(
            '1461501637330902918203684832716283019655932542975',
            $this->invoke('hexToDecimal', str_repeat('f', 40)),
        );
    }

    public function testExpiredCertificateIsFlaggedNotHidden(): void
    {
        $now = (int) strtotime('2026-08-15 12:00:00');
        $certificate = $this->invoke(
            'presentCertificate',
            [
                'id' => '7',
                'label' => 'ČSSZ 2025',
                'subject_dn' => 'CN=Firma s.r.o.',
                'issuer_dn' => 'CN=I.CA',
                'serial_hex' => '1A2B3C',
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2026-01-01 00:00:00',
                'enabled_for_supplier' => 1,
                'ik_mpsv_present' => 1,
            ],
            $now,
        );

        self::assertIsArray($certificate);
        self::assertSame(7, $certificate['id']);
        self::assertSame('CN=Firma s.r.o.', $certificate['subject']);
        self::assertSame('CN=I.CA', $certificate['issuer']);
        self::assertSame('1a2b3c', $certificate['serial_hex']);
        self::assertSame('1715004', $certificate['serial_decimal']);
        self::assertTrue($certificate['expired']);
        self::assertFalse($certificate['not_yet_valid']);
        self::assertFalse($certificate['usable_now']);
        self::assertTrue($certificate['enabled_for_supplier']);
    }

    public function testNotYetValidCertificateIsFlaggedSeparately(): void
    {
        $now = (int) strtotime('2026-08-15 12:00:00');
        $certificate = $this->invoke(
            'presentCertificate',
            [
                'id' => 9,
                'label' => 'ČSSZ 2027',
                'valid_from' => '2027-01-01 00:00:00',
                'valid_to' => '2028-01-01 00:00:00',
                'serial_hex' => null,
                'enabled_for_supplier' => 0,
            ],
            $now,
        );

        self::assertIsArray($certificate);
        self::assertTrue($certificate['not_yet_valid']);
        self::assertFalse($certificate['expired']);
        self::assertFalse($certificate['usable_now']);
        self::assertNull($certificate['serial_hex']);
        self::assertNull($certificate['serial_decimal']);
    }

    public function testExpiredChoiceProducesLoudWarnings(): void
    {
        $now = (int) strtotime('2026-08-15 12:00:00');
        $certificate = $this->invoke(
            'presentCertificate',
            [
                'id' => 7,
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2026-01-01 00:00:00',
                'serial_hex' => '1a2b3c',
                'enabled_for_supplier' => 0,
            ],
            $now,
        );
        self::assertIsArray($certificate);

        $warnings = $this->invoke(
            'profileWarnings',
            [
                'environment' => 'production',
                'credential_id' => 7,
                'cssz_registered_serial' => null,
                'row_version' => 3,
            ],
            $certificate,
        );

        self::assertIsArray($warnings);
        $codes = array_column($warnings, 'code');
        self::assertContains('certificate_expired', $codes);
        self::assertContains('certificate_not_enabled_for_supplier', $codes);
        self::assertContains('cssz_serial_missing', $codes);
    }

    public function testChoiceOwnedByAnotherUserIsReportedAsInaccessible(): void
    {
        $warnings = $this->invoke(
            'profileWarnings',
            [
                'environment' => 'test',
                'credential_id' => 7,
                'cssz_registered_serial' => '1a2b3c',
                'row_version' => 1,
            ],
            null,
        );

        self::assertIsArray($warnings);
        self::assertSame(['certificate_not_accessible'], array_column($warnings, 'code'));
    }

    public function testRowVersionIsAcceptedOnlyAsPositiveInteger(): void
    {
        self::assertSame(3, $this->invoke('positiveInt', 3));
        self::assertSame(3, $this->invoke('positiveInt', '3'));
        self::assertNull($this->invoke('positiveInt', 0));
        self::assertNull($this->invoke('positiveInt', -1));
        self::assertNull($this->invoke('positiveInt', '3.0'));
        self::assertNull($this->invoke('positiveInt', 'abc'));
        self::assertNull($this->invoke('positiveInt', null));

        // Optimistický zámek se posílá jen při změně existující volby: chybějící
        // klíč znamená první uložení, prázdný řetězec je totéž (formulář bez hodnoty).
        self::assertFalse($this->invoke('sendsRowVersion', []));
        self::assertFalse($this->invoke('sendsRowVersion', ['row_version' => null]));
        self::assertFalse($this->invoke('sendsRowVersion', ['row_version' => '']));
        self::assertTrue($this->invoke('sendsRowVersion', ['row_version' => 1]));
        self::assertTrue($this->invoke('sendsRowVersion', ['row_version' => 'x']));
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $action = (new ReflectionClass(PayrollJmhzSigningProfileAction::class))
            ->newInstanceWithoutConstructor();

        return (new ReflectionMethod(PayrollJmhzSigningProfileAction::class, $method))
            ->invokeArgs($action, $arguments);
    }

    private function constant(string $name): string
    {
        $value = (new ReflectionClass(PayrollJmhzSigningProfileAction::class))->getConstant($name);
        self::assertIsString($value, "Konstanta {$name} musí existovat.");

        return $value;
    }
}
