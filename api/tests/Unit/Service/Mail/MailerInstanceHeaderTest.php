<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Mail\Mailer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Mime\Email;

/**
 * `X-MyUcto-Instance` — identifikace instance pro complaint smyčky (FBL).
 *
 * Celá flotila se podepisuje jednou DKIM doménou, takže bez téhle hlavičky vidí
 * antispam stížnost jako „od myucto.online" a postihne reputaci všech instancí.
 * Self-hosted instalace naopak posílá pod vlastní doménou — naše complaint smyčka
 * ji nikdy neuvidí, takže by hlavička neměla konzumenta.
 */
final class MailerInstanceHeaderTest extends TestCase
{
    public function testManagedInstallationStampsItsHostname(): void
    {
        $email = $this->stamp(['app' => ['managed' => true, 'url' => 'https://ucto.myucto.online']]);

        self::assertSame('ucto.myucto.online', $email->getHeaders()->get('X-MyUcto-Instance')?->getBodyAsString());
    }

    /** Hostname se bere z app.url, ne z From — schéma, port ani cesta do hlavičky nepatří. */
    public function testHostnameIsNormalizedFromAppUrl(): void
    {
        $email = $this->stamp(['app' => ['managed' => true, 'url' => 'https://UCTO.MyUcto.Online:8443/app']]);

        self::assertSame('ucto.myucto.online', $email->getHeaders()->get('X-MyUcto-Instance')?->getBodyAsString());
    }

    public function testSelfHostedInstallationSendsNoHeader(): void
    {
        $email = $this->stamp(['app' => ['managed' => false, 'url' => 'https://ucetnictvi.firma.cz']]);

        self::assertFalse($email->getHeaders()->has('X-MyUcto-Instance'));
    }

    /** Bez app.url není co vyplnit — vymyšlená hodnota je horší než žádná hlavička. */
    public function testMissingAppUrlSendsNoHeader(): void
    {
        $email = $this->stamp(['app' => ['managed' => true, 'url' => '']]);

        self::assertFalse($email->getHeaders()->has('X-MyUcto-Instance'));
    }

    /** @param array<string,mixed> $config */
    private function stamp(array $config): Email
    {
        $mailer = (new ReflectionClass(Mailer::class))->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(Mailer::class, 'config');
        $property->setValue($mailer, new Config($config));

        $email = (new Email())
            ->from('sender@example.test')
            ->to('recipient@example.test')
            ->subject('Test')
            ->text('Test');
        (new ReflectionMethod(Mailer::class, 'stampInstanceHeader'))->invoke($mailer, $email);

        return $email;
    }
}
