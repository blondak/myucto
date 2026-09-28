<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\License;

use MyInvoice\Action\License\PayrollChangeAction;
use MyInvoice\Action\License\StorageUpgradeAction;
use MyInvoice\Action\License\TierChangeAction;
use MyInvoice\Action\License\UpgradeLicenseAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Předplatné placené fakturou nemá uloženou kartu. Licenční server změnu
 * odmítne kódem `card_payment_required` a pošle odkaz na jednorázovou platbu.
 * Hláška nesmí radit „zkuste to znovu" ani mluvit o neprošlé kartě: zákazník
 * má otevřít odkaz a zaplatit, opakování by vrátilo tutéž objednávku.
 */
final class CardPaymentRequiredMessageTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function changeMessages(): iterable
    {
        yield 'místa' => [UpgradeLicenseAction::message('card_payment_required')];
        yield 'úložiště' => [StorageUpgradeAction::message('card_payment_required')];
        yield 'tarif' => [TierChangeAction::message('card_payment_required')];
        yield 'mzdy' => [PayrollChangeAction::ERROR_MESSAGES['card_payment_required'] ?? ''];
    }

    #[DataProvider('changeMessages')]
    public function testEveryChangeExplainsOneOffCardPayment(string $message): void
    {
        self::assertSame(UpgradeLicenseAction::CARD_PAYMENT_REQUIRED_MESSAGE, $message);
        self::assertStringContainsString('jednorázově kartou', $message);
        self::assertStringNotContainsString('znovu', $message);
    }

    public function testOtherCodesKeepTheirWording(): void
    {
        self::assertStringContainsString('nepodařilo strhnout', TierChangeAction::message('charge_failed'));
        self::assertSame('Změna tarifu se nezdařila.', TierChangeAction::message('neznamy_kod'));
        self::assertStringContainsString('Zkuste to prosím znovu', UpgradeLicenseAction::message('neznamy_kod'));
    }
}
