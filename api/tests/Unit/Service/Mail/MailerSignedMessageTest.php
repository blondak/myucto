<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Service\Mail\Mailer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;

final class MailerSignedMessageTest extends TestCase
{
    public function testFromDomainAcceptsGenericMessageReturnedBySmimeSigner(): void
    {
        $email = (new Email())
            ->from('sender@example.test')
            ->to('recipient@example.test')
            ->subject('Synthetic signed message')
            ->text('Synthetic body');
        $message = new Message($email->getPreparedHeaders(), $email->getBody());
        $mailer = (new ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Mailer::class, 'fromDomain');

        self::assertSame('example.test', $method->invoke($mailer, $message));
    }
}
