<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Mail\DemoModeMailBlockedException;
use MyInvoice\Service\Mail\Mailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MailerDemoModeTest extends TestCase
{
    public function testDemoModeFailsBeforeTemplateOrTransportAccess(): void
    {
        $config = new Config(['demo' => ['enabled' => true]]);
        $templates = $this->createMock(EmailTemplateRepository::class);
        $templates->expects(self::never())->method('find');
        $mailer = new Mailer($config, new NullLogger(), new Connection($config), $templates);

        $this->expectException(DemoModeMailBlockedException::class);
        $this->expectExceptionMessage('Demo režim neodesílá e-maily.');

        $mailer->sendTemplate('invoice_send', 'cs', ['demo@example.test'], []);
    }
}
