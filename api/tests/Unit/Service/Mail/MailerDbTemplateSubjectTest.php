<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EmailProfileRepository;
use MyInvoice\Repository\EmailTemplateRepository;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\SentMailAppenderInterface;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailerDbTemplateSubjectTest extends TestCase
{
    use OpensslConfigTrait;

    public function testDbTemplateSubjectIsRenderedWithTemplateVariables(): void
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn([
            'id' => 1,
            'code' => 'invoice_send',
            'locale' => 'cs',
            'subject' => 'Faktura {{ invoice.varsymbol }}',
            'body_html' => '<p>Faktura {{ invoice.varsymbol }}</p>',
            'body_text' => 'Faktura {{ invoice.varsymbol }}',
            'updated_at' => '2026-06-02 12:00:00',
        ]);

        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'from_email' => 'noreply@example.test',
                    'from_name' => 'MyInvoice',
                    'dkim' => ['enabled' => false],
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $templates,
        );

        $transport = new CapturingTransport();
        $transportProperty = new \ReflectionProperty(Mailer::class, 'transport');
        $transportProperty->setValue($mailer, $transport);

        $mailer->sendTemplate('invoice_send', 'cs', ['client@example.test'], [
            'invoice' => ['varsymbol' => '2605001'],
            'supplier' => [
                'id' => 1,
                'company_name' => 'Dodavatel s.r.o.',
                'display_name' => 'Dodavatel',
                'email_branding_enabled' => false,
            ],
        ]);

        self::assertInstanceOf(Email::class, $transport->message);
        self::assertSame('Faktura 2605001', $transport->message->getSubject());
        self::assertStringContainsString('Faktura 2605001', $transport->message->getTextBody() ?? '');
        self::assertStringContainsString('Faktura 2605001', $transport->message->getHtmlBody() ?? '');
    }

    /**
     * Předmět podle klienta (#277) přebíjí předmět e-mailové šablony z administrace.
     * Je to hotový text: `&` se nesmí HTML-escapovat a `{` v názvu se nesmí brát jako Twig.
     */
    public function testPredmetKlientaMaPrednostPredSablonouZAdministrace(): void
    {
        // Přes Twig by `{{ … }}` dosadil varsymbol a `{%` bez konce shodil vykreslení.
        $clientSubject = 'Klient & Syn {{ invoice.varsymbol }} {% x';
        $transport = $this->sendInvoiceMail(dbSubject: 'Faktura {{ invoice.varsymbol }}', vars: [
            'subject'        => $clientSubject,
            'client_subject' => $clientSubject,
        ]);

        self::assertSame($clientSubject, $transport->message->getSubject());
    }

    public function testJednorazovyPredmetMaPrednostPredPredmetemKlienta(): void
    {
        $transport = $this->sendInvoiceMail(
            dbSubject: 'Faktura {{ invoice.varsymbol }}',
            vars: ['subject' => 'Klientský', 'client_subject' => 'Klientský'],
            subjectOverride: 'Ručně {{ invoice.varsymbol }}',
        );

        self::assertSame('Ručně 2605001', $transport->message->getSubject());
    }

    /**
     * Bez šablony z administrace má předmět z vars přednost před jednorázovým
     * předmětem stejně jako před #277. API/MCP volající, kteří `subject_override`
     * posílají, nesmí dostat jiný předmět jen proto, že přibyl formát klienta.
     */
    public function testBezSablonyZAdministraceJednorazovyPredmetNemeniPredmetFaktury(): void
    {
        $default = $this->sendInvoiceMail(
            dbSubject: null,
            vars: ['subject' => 'Faktura 2605001 — Dodavatel', 'client_subject' => null],
            subjectOverride: 'Ručně {{ invoice.varsymbol }}',
        );
        $client = $this->sendInvoiceMail(
            dbSubject: null,
            vars: ['subject' => 'Klientský', 'client_subject' => 'Klientský'],
            subjectOverride: 'Ručně {{ invoice.varsymbol }}',
        );

        self::assertSame('Faktura 2605001 — Dodavatel', $default->message->getSubject());
        self::assertSame('Klientský', $client->message->getSubject());
    }

    /** E-mail bez `subject` ve vars (měsíční výkaz) dostane jednorázový předmět doslova. */
    public function testJednorazovyPredmetBezSubjectVeVarsZustavaDoslovny(): void
    {
        $transport = $this->sendInvoiceMail(dbSubject: null, vars: [], subjectOverride: 'Výkaz {% obdobi');

        self::assertSame('Výkaz {% obdobi', $transport->message->getSubject());
    }

    /** API/MCP posílá prázdný řetězec — nesmí odejít e-mail bez předmětu. */
    public function testPrazdnyJednorazovyPredmetSeIgnoruje(): void
    {
        $withClient = $this->sendInvoiceMail(dbSubject: null, vars: [
            'subject'        => 'Klient_09_2026',
            'client_subject' => 'Klient_09_2026',
        ], subjectOverride: '  ');
        $withTemplate = $this->sendInvoiceMail(dbSubject: 'Faktura {{ invoice.varsymbol }}', vars: [
            'subject'        => 'Faktura 2605001 — Dodavatel',
            'client_subject' => null,
        ], subjectOverride: '');

        self::assertSame('Klient_09_2026', $withClient->message->getSubject());
        self::assertSame('Faktura 2605001', $withTemplate->message->getSubject());
    }

    public function testBezPredmetuKlientaPlatiSablonaZAdministrace(): void
    {
        $transport = $this->sendInvoiceMail(dbSubject: 'Faktura {{ invoice.varsymbol }}', vars: [
            'subject'        => 'Faktura 2605001 — Dodavatel',
            'client_subject' => null,
        ]);

        self::assertSame('Faktura 2605001', $transport->message->getSubject());
    }

    public function testBezSablonyZAdministracePlatiPredmetKlienta(): void
    {
        $transport = $this->sendInvoiceMail(dbSubject: null, vars: [
            'subject'        => 'Klient_09_2026_Dodavatel',
            'client_subject' => 'Klient_09_2026_Dodavatel',
        ]);

        self::assertSame('Klient_09_2026_Dodavatel', $transport->message->getSubject());
    }

    /** @param array<string,mixed> $vars */
    private function sendInvoiceMail(?string $dbSubject, array $vars, ?string $subjectOverride = null): CapturingTransport
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn($dbSubject === null ? null : [
            'id' => 1,
            'code' => 'invoice_send',
            'locale' => 'cs',
            'subject' => $dbSubject,
            'body_html' => '<p>Faktura {{ invoice.varsymbol }}</p>',
            'body_text' => 'Faktura {{ invoice.varsymbol }}',
            'updated_at' => '2026-06-02 12:00:00',
        ]);
        $mailer = new Mailer(
            new Config(['smtp' => [
                'from_email' => 'noreply@example.test',
                'from_name' => 'MyInvoice',
                'dkim' => ['enabled' => false],
            ]]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $templates,
        );
        $transport = new CapturingTransport();
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $transport);

        $mailer->sendTemplate('invoice_send', 'cs', ['client@example.test'], $vars + [
            'invoice' => ['varsymbol' => '2605001'],
            'supplier' => [
                'id' => 1,
                'company_name' => 'Dodavatel s.r.o.',
                'display_name' => 'Dodavatel',
                'email_branding_enabled' => false,
            ],
        ], $subjectOverride);

        self::assertInstanceOf(Email::class, $transport->message);
        return $transport;
    }

    public function testEmailProfileWithDisabledReplyToDoesNotUseFallback(): void
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn([
            'id' => 1,
            'code' => 'invoice_send',
            'locale' => 'cs',
            'subject' => 'Faktura',
            'body_html' => '<p>Faktura</p>',
            'body_text' => 'Faktura',
            'updated_at' => '2026-06-02 12:00:00',
        ]);

        $profiles = $this->createMock(EmailProfileRepository::class);
        $profiles->expects($this->once())
            ->method('defaultProfile')
            ->with(1)
            ->willReturn([
                'id' => 10,
                'supplier_id' => 1,
                'name' => 'Profile',
                'code' => 'profile',
                'from_email' => 'profile@example.test',
                'from_name' => 'Profile sender',
                'reply_to_email' => null,
                'reply_to_name' => null,
                'reply_to_enabled' => false,
                'signing_profile_id' => null,
                'dkim_domain' => null,
                'dkim_selector' => null,
                'dkim_enabled' => false,
                'is_default' => true,
                'is_active' => true,
            ]);

        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'from_email' => 'noreply@example.test',
                    'from_name' => 'MyInvoice',
                    'reply_to_email' => 'global-reply@example.test',
                    'reply_to_name' => 'Global reply',
                    'dkim' => [
                        'enabled' => true,
                        'private_key_path' => __FILE__,
                        'domain' => 'global.example.test',
                        'selector' => 'global',
                        'passphrase' => '',
                    ],
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $templates,
            null,
            $profiles,
        );

        $transport = new CapturingTransport();
        $transportProperty = new \ReflectionProperty(Mailer::class, 'transport');
        $transportProperty->setValue($mailer, $transport);

        $mailer->sendTemplate('invoice_send', 'cs', ['client@example.test'], [
            'supplier' => [
                'id' => 1,
                'company_name' => 'Dodavatel s.r.o.',
                'display_name' => 'Dodavatel',
                'email' => 'supplier@example.test',
                'email_branding_enabled' => false,
            ],
        ]);

        self::assertInstanceOf(Email::class, $transport->message);
        self::assertSame([], $transport->message->getReplyTo());
        self::assertSame('profile@example.test', $transport->message->getFrom()[0]->getAddress());
        self::assertFalse($transport->message->getHeaders()->has('DKIM-Signature'));
    }

    public function testExplicitEmailProfileOverrideIsUsedForTestSend(): void
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn([
            'id' => 1,
            'code' => 'email_profile_test',
            'locale' => 'cs',
            'subject' => 'Test profilu',
            'body_html' => '<p>Test {{ profile.name }}</p>',
            'body_text' => 'Test {{ profile.name }}',
            'updated_at' => '2026-06-02 12:00:00',
        ]);

        $profiles = $this->createMock(EmailProfileRepository::class);
        $profiles->expects($this->never())->method('defaultProfile');

        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'from_email' => 'global@example.test',
                    'from_name' => 'Global',
                    'reply_to_email' => 'global-reply@example.test',
                    'reply_to_name' => 'Global reply',
                    'dkim' => ['enabled' => false],
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $templates,
            null,
            $profiles,
        );

        $transport = new CapturingTransport();
        $transportProperty = new \ReflectionProperty(Mailer::class, 'transport');
        $transportProperty->setValue($mailer, $transport);

        $mailer->sendTemplate(
            'email_profile_test',
            'cs',
            ['admin@example.test'],
            [
                'supplier' => [
                    'id' => 1,
                    'company_name' => 'Dodavatel s.r.o.',
                    'display_name' => 'Dodavatel',
                    'email' => 'supplier@example.test',
                    'email_branding_enabled' => false,
                ],
                'profile' => ['name' => 'Test'],
            ],
            null,
            [],
            [],
            [],
            null,
            [
                'id' => 20,
                'supplier_id' => 1,
                'name' => 'Override',
                'code' => 'override',
                'from_email' => 'override@example.test',
                'from_name' => 'Override sender',
                'reply_to_email' => null,
                'reply_to_name' => null,
                'reply_to_enabled' => false,
                'signing_profile_id' => null,
                'dkim_domain' => null,
                'dkim_selector' => null,
                'dkim_enabled' => false,
                'transport_type' => 'global',
                'is_default' => false,
                'is_active' => false,
            ],
        );

        self::assertInstanceOf(Email::class, $transport->message);
        self::assertSame('override@example.test', $transport->message->getFrom()[0]->getAddress());
        self::assertSame('Override sender', $transport->message->getFrom()[0]->getName());
        self::assertSame([], $transport->message->getReplyTo());
    }

    public function testImapArchiveRawMessageComesFromSentMessage(): void
    {
        $mailer = (new \ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();
        $email = (new Email())
            ->from('sender@example.test')
            ->to('client@example.test')
            ->subject('Archiv')
            ->text('Body');
        $sent = new SentMessage($email, Envelope::create($email));

        $method = new \ReflectionMethod(Mailer::class, 'rawMessageForImap');
        $raw = $method->invoke($mailer, $sent, $email);

        self::assertSame($sent->toString(), $raw);
        self::assertNotSame($email->toString(), $raw);
        self::assertStringContainsString('Message-ID:', $raw);
    }

    public function testImapFailureLogOnlyKeepsAcceptedSmtpSend(): void
    {
        $templates = $this->templateRepository();
        $appender = new CapturingSentMailAppender([
            'status' => 'failed',
            'folder' => 'Sent',
            'error' => 'APPEND failed',
        ]);
        $mailer = new Mailer(
            $this->mailConfig(['dkim' => ['enabled' => false]]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $templates,
            null,
            null,
            $appender,
        );
        $transport = new CapturingTransport();
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $transport);

        $result = $mailer->sendTemplateDetailed(
            'invoice_send',
            'cs',
            ['client@example.test'],
            $this->supplierVars(),
            null,
            [],
            [],
            [],
            null,
            $this->emailProfile(['imap_on_failure' => 'log_only']),
        );

        self::assertInstanceOf(Email::class, $transport->message);
        self::assertSame('failed', $result['imap_append']['status']);
        self::assertSame('APPEND failed', $result['imap_append']['error']);
        self::assertSame(1, $appender->calls);
    }

    public function testImapFailureFailSendThrowsAfterAcceptedSmtpSend(): void
    {
        $appender = new CapturingSentMailAppender([
            'status' => 'failed',
            'folder' => 'Sent',
            'error' => 'APPEND failed',
        ]);
        $mailer = new Mailer(
            $this->mailConfig(['dkim' => ['enabled' => false]]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $this->templateRepository(),
            null,
            null,
            $appender,
        );
        $transport = new CapturingTransport();
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $transport);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('E-mail byl transportem přijat');

        try {
            $mailer->sendTemplateDetailed(
                'invoice_send',
                'cs',
                ['client@example.test'],
                $this->supplierVars(),
                null,
                [],
                [],
                [],
                null,
                $this->emailProfile(['imap_on_failure' => 'fail_send']),
            );
        } finally {
            self::assertInstanceOf(Email::class, $transport->message);
            self::assertSame(1, $appender->calls);
        }
    }

    public function testSimpleSendTemplateTreatsImapFailureFailSendAsDelivered(): void
    {
        $appender = new CapturingSentMailAppender([
            'status' => 'failed',
            'folder' => 'Sent',
            'error' => 'APPEND failed',
        ]);
        $mailer = new Mailer(
            $this->mailConfig(['dkim' => ['enabled' => false]]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $this->templateRepository(),
            null,
            null,
            $appender,
        );
        $transport = new CapturingTransport();
        (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $transport);

        $smtpResponse = $mailer->sendTemplate(
            'invoice_send',
            'cs',
            ['client@example.test'],
            $this->supplierVars(),
            null,
            [],
            [],
            [],
            null,
            $this->emailProfile(['imap_on_failure' => 'fail_send']),
        );

        self::assertSame('', $smtpResponse);
        self::assertInstanceOf(Email::class, $transport->message);
        self::assertSame(1, $appender->calls);
    }

    public function testImapArchiveContainsFinalMimeWithAttachmentInlineImageDkimAndBccEnvelope(): void
    {
        $keyPath = tempnam(sys_get_temp_dir(), 'myinvoice-dkim-');
        $attachmentPath = tempnam(sys_get_temp_dir(), 'myinvoice-attachment-');
        self::assertIsString($keyPath);
        self::assertIsString($attachmentPath);

        try {
            $sslConfig = self::opensslConfigArgs();
            $privateKey = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => 2048,
            ] + $sslConfig);
            self::assertNotFalse($privateKey, 'openssl_pkey_new selhal — chybí openssl.cnf? ' . self::opensslErrors());
            $pem = '';
            self::assertTrue(openssl_pkey_export($privateKey, $pem, null, $sslConfig ?: null));
            self::assertNotFalse(file_put_contents($keyPath, $pem));
            self::assertNotFalse(file_put_contents($attachmentPath, 'attachment-body'));

            $templates = $this->templateRepository(
                '<p>Faktura <img src="{{ qr_data_uri }}"></p>',
                'Faktura',
            );
            $appender = new CapturingSentMailAppender([
                'status' => 'saved',
                'folder' => 'Sent',
                'error' => null,
            ]);
            $mailer = new Mailer(
                $this->mailConfig([
                    'dkim' => [
                        'enabled' => true,
                        'private_key_path' => $keyPath,
                        'domain' => 'global.example.test',
                        'selector' => 'global',
                        'passphrase' => '',
                    ],
                ]),
                $this->createStub(LoggerInterface::class),
                $this->createStub(Connection::class),
                $templates,
                null,
                null,
                $appender,
            );
            $transport = new CapturingTransport();
            (new \ReflectionProperty(Mailer::class, 'transport'))->setValue($mailer, $transport);

            $mailer->sendTemplateDetailed(
                'invoice_send',
                'cs',
                ['client@example.test'],
                $this->supplierVars([
                    'qr_data_uri' => 'data:image/png;base64,' . base64_encode('png-bytes'),
                ]),
                null,
                [],
                ['hidden@example.test'],
                [[
                    'path' => $attachmentPath,
                    'name' => 'invoice.txt',
                    'contentType' => 'text/plain',
                ]],
                null,
                $this->emailProfile([
                    'dkim_enabled' => true,
                    'dkim_domain' => 'example.test',
                    'dkim_selector' => 's1',
                ]),
            );

            self::assertSame(1, $appender->calls);
            self::assertIsString($appender->rawMessage);
            self::assertStringContainsString('DKIM-Signature:', $appender->rawMessage);
            self::assertStringContainsString('Content-ID:', $appender->rawMessage);
            self::assertStringContainsString('name=qr_payment', $appender->rawMessage);
            self::assertStringContainsString('src=3D"cid:', $appender->rawMessage);
            self::assertStringContainsString('invoice.txt', $appender->rawMessage);
            self::assertStringNotContainsString('Bcc:', $appender->rawMessage);
            self::assertStringNotContainsString('hidden@example.test', $appender->rawMessage);

            self::assertInstanceOf(Envelope::class, $transport->envelope);
            $recipients = array_map(
                static fn (Address $address): string => $address->getAddress(),
                $transport->envelope->getRecipients(),
            );
            self::assertContains('hidden@example.test', $recipients);
        } finally {
            @unlink($keyPath);
            @unlink($attachmentPath);
        }
    }

    public function testGlobalEmailProfileTransportUsesConfiguredSmtpTransport(): void
    {
        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'host' => 'cfg-smtp.example.test',
                    'port' => 2525,
                    'encryption' => 'none',
                    'auth_enabled' => true,
                    'auth_type' => 'LOGIN',
                    'user' => 'cfg-user@example.test',
                    'pass' => 'cfg-secret',
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'timeout' => 42,
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $this->createStub(EmailTemplateRepository::class),
        );

        $method = new \ReflectionMethod(Mailer::class, 'transport');
        $transport = $method->invoke($mailer, [
            'transport_type' => 'global',
            'smtp_host' => 'ignored-profile-smtp.example.test',
            'smtp_port' => 465,
            'smtp_auth_enabled' => true,
            'smtp_auth_type' => 'PLAIN',
            'smtp_username' => 'ignored-profile-user',
            'smtp_password' => 'ignored-profile-secret',
            'smtp_encryption' => 'ssl',
            'smtp_timeout' => 1,
        ]);

        self::assertInstanceOf(EsmtpTransport::class, $transport);
        self::assertSame('cfg-user@example.test', $transport->getUsername());
        self::assertSame('cfg-secret', $transport->getPassword());

        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame('cfg-smtp.example.test', $stream->getHost());
        self::assertSame(2525, $stream->getPort());
        self::assertFalse($stream->isTLS());
        // Globální (bezprofilová) cesta i profil s transport_type='global' jedou přes
        // legacy Transport::fromDsn(buildDsn()) = bit-za-bit shodné s masterem. Socket
        // timeout je proto default PHP (cfg.smtp.timeout ani profilový timeout=1 se u
        // globálního transportu nehonorují — má je jen profil s vlastním SMTP).
        self::assertSame((float) (int) ini_get('default_socket_timeout'), $stream->getTimeout());
    }

    public function testBuildsProfileSmtpAndSendmailTransport(): void
    {
        $mailer = new Mailer(
            new Config([
                'smtp' => [
                    'host' => 'global.example.test',
                    'port' => 587,
                    'encryption' => 'tls',
                    'auth_enabled' => false,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'timeout' => 30,
                ],
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(Connection::class),
            $this->createStub(EmailTemplateRepository::class),
        );

        $method = new \ReflectionMethod(Mailer::class, 'buildTransport');

        $smtp = $method->invoke($mailer, [
            'transport_type' => 'smtp',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_auth_enabled' => true,
            'smtp_auth_type' => 'LOGIN',
            'smtp_username' => 'user@example.test',
            'smtp_password' => 'p@ss word',
            'smtp_encryption' => 'ssl',
            'smtp_verify_peer' => false,
            'smtp_verify_peer_name' => false,
            'smtp_allow_self_signed' => true,
            'smtp_timeout' => 45,
        ]);
        self::assertInstanceOf(EsmtpTransport::class, $smtp);
        self::assertSame('user@example.test', $smtp->getUsername());
        self::assertSame('p@ss word', $smtp->getPassword());
        self::assertFalse($smtp->isTlsRequired());

        $stream = $smtp->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame('smtp.example.test', $stream->getHost());
        self::assertSame(465, $stream->getPort());
        self::assertTrue($stream->isTLS());
        self::assertSame(45.0, $stream->getTimeout());
        self::assertSame([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ], $stream->getStreamOptions());

        $authenticatorsProperty = new \ReflectionProperty(EsmtpTransport::class, 'authenticators');
        $authenticators = $authenticatorsProperty->getValue($smtp);
        self::assertCount(1, $authenticators);
        self::assertSame('LOGIN', $authenticators[0]->getAuthKeyword());

        $sendmail = $method->invoke($mailer, [
            'transport_type' => 'sendmail',
            'sendmail_command' => '/usr/sbin/sendmail -bs',
        ]);
        self::assertInstanceOf(SendmailTransport::class, $sendmail);
        $commandProperty = new \ReflectionProperty(SendmailTransport::class, 'command');
        self::assertSame('/usr/sbin/sendmail -bs', $commandProperty->getValue($sendmail));
    }

    private function templateRepository(string $html = '<p>Faktura</p>', string $text = 'Faktura'): EmailTemplateRepository
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willReturn([
            'id' => 1,
            'code' => 'invoice_send',
            'locale' => 'cs',
            'subject' => 'Faktura',
            'body_html' => $html,
            'body_text' => $text,
            'updated_at' => '2026-06-02 12:00:00',
        ]);

        return $templates;
    }

    /**
     * @param array<string,mixed> $smtpOverrides
     */
    private function mailConfig(array $smtpOverrides): Config
    {
        return new Config([
            'smtp' => array_replace([
                'from_email' => 'global@example.test',
                'from_name' => 'Global',
                'reply_to_email' => '',
                'reply_to_name' => '',
                'dkim' => ['enabled' => false],
            ], $smtpOverrides),
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function supplierVars(array $overrides = []): array
    {
        return array_replace([
            'supplier' => [
                'id' => 1,
                'company_name' => 'Dodavatel s.r.o.',
                'display_name' => 'Dodavatel',
                'email' => 'supplier@example.test',
                'email_branding_enabled' => false,
            ],
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function emailProfile(array $overrides = []): array
    {
        return array_replace([
            'id' => 20,
            'supplier_id' => 1,
            'name' => 'Profil',
            'code' => 'profil',
            'from_email' => 'profile@example.test',
            'from_name' => 'Profile sender',
            'reply_to_email' => null,
            'reply_to_name' => null,
            'reply_to_enabled' => false,
            'signing_profile_id' => null,
            'dkim_domain' => null,
            'dkim_selector' => null,
            'dkim_enabled' => false,
            'transport_type' => 'global',
            'imap_sent_enabled' => true,
            'imap_on_failure' => 'log_only',
            'is_default' => false,
            'is_active' => true,
        ], $overrides);
    }
}

final class CapturingTransport implements TransportInterface
{
    public ?RawMessage $message = null;
    public ?Envelope $envelope = null;
    public ?SentMessage $sentMessage = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->message = $message;
        $this->envelope = $envelope;
        $this->sentMessage = new SentMessage($message, $envelope ?? Envelope::create($message));

        return $this->sentMessage;
    }

    public function __toString(): string
    {
        return 'capturing://test';
    }
}

final class CapturingSentMailAppender implements SentMailAppenderInterface
{
    /** @var array{status:'skipped'|'saved'|'failed',folder:?string,error:?string} */
    private array $result;
    public int $calls = 0;
    /** @var array<string,mixed>|null */
    public ?array $profile = null;
    public ?string $rawMessage = null;

    /**
     * @param array{status:'skipped'|'saved'|'failed',folder:?string,error:?string} $result
     */
    public function __construct(array $result)
    {
        $this->result = $result;
    }

    public function appendIfEnabled(?array $profile, string $rawMessage): array
    {
        $this->calls++;
        $this->profile = $profile;
        $this->rawMessage = $rawMessage;

        return $this->result;
    }
}
