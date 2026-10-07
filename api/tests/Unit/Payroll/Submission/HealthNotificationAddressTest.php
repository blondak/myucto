<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationAddress;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * Adresa trvalého pobytu ve větě hromadného oznámení (HOZ-AD-6). Data jsou syntetická.
 */
final class HealthNotificationAddressTest extends TestCase
{
    public function testStreetLineFromThePersonCardGoesIntoOneElement(): void
    {
        $address = HealthNotificationAddress::fromStreetLine('Krátká 3', '602 00', 'Brno');

        self::assertSame('Krátká 3', $address->streetLine());
        self::assertSame('60200', $address->postalCode);
        $address->assertValid();
        $this->addToAssertionCount(1);
    }

    public function testSeparateStreetAndNumberAreStillJoined(): void
    {
        $address = new HealthNotificationAddress('Krátká', '3', '60200', 'Brno');

        self::assertSame('Krátká 3', $address->streetLine());
        $address->assertValid();
        $this->addToAssertionCount(1);
    }

    public function testSeparateStreetWithoutNumberStaysIncomplete(): void
    {
        try {
            (new HealthNotificationAddress('Krátká', '', '60200', 'Brno'))->assertValid();
            self::fail('Adresa bez čísla popisného z oddělených polí je neúplná.');
        } catch (HealthNotificationException $exception) {
            self::assertSame('zp_change_address_incomplete', $exception->errorCode);
        }
    }

    public function testStreetLineLongerThanSixtyCharactersIsRefused(): void
    {
        $address = HealthNotificationAddress::fromStreetLine(str_repeat('A', 61), '60200', 'Brno');

        try {
            $address->assertValid();
            self::fail('Ulice delší než šedesát znaků nesmí projít.');
        } catch (HealthNotificationException $exception) {
            self::assertSame('zp_change_address_too_long', $exception->errorCode);
        }
    }
}
