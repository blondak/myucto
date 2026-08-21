<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Epo;

use MyInvoice\Repository\EpoDirectSubmissionRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Epo\EpoAssistedConfirmationService;
use MyInvoice\Service\Epo\EpoConfirmationPartsArchiver;
use MyInvoice\Service\Epo\TaxSubmissionDocumentService;
use PHPUnit\Framework\TestCase;

/**
 * Co se stane s ručně nahranou dodejkou u asistovaného podání.
 *
 * Nejcitlivější je heslo pro `epo_stav`: EPO ho vydá jednou a aplikace ho potřebuje
 * uložit, ale odpověď z uploadu jde rovnou do prohlížeče. Heslo proto nesmí opustit
 * službu ani jednou větví — ani u dodejky, která ověřením neprošla.
 */
final class EpoAssistedConfirmationServiceTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testStoresReceiptDataOnAttemptAndKeepsPasswordOut(): void
    {
        $documents = $this->createStub(TaxSubmissionDocumentService::class);
        $documents->method('artifactKind')->willReturn('confirmation_p7s');
        $documents->method('ingestArtifact')->willReturn([
            'artifact' => ['id' => 5, 'artifact_kind' => 'confirmation_p7s'],
            'confirmation' => [
                'reference' => '568467011',
                'submitted_at' => '2026-08-21 10:36:43',
                'state_password' => 'tajne123',
                'verification_status' => 'valid',
                'is_confirmation' => true,
                'receipt' => ['reference' => '568467011'],
            ],
            'hint' => null,
        ]);

        $parts = $this->createMock(EpoConfirmationPartsArchiver::class);
        $parts->expects(self::once())->method('archive')->willReturn([
            'stored' => ['confirmation_xml'],
            'failed' => [],
            'receipt' => ['reference' => '568467011'],
        ]);

        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->expects(self::once())
            ->method('encryptFor')
            ->with('tajne123', 'epo:state-password')
            ->willReturn('enc:v1:synthetic');

        $epo = $this->createMock(TaxSubmissionEpoRepository::class);
        $epo->expects(self::once())
            ->method('recordAssistedConfirmation')
            ->with(42, 7, 3, '568467011', '2026-08-21 10:36:43', 'enc:v1:synthetic')
            ->willReturn(true);

        $events = $this->createMock(EpoDirectSubmissionRepository::class);
        $events->expects(self::once())->method('addEvent');

        $service = new EpoAssistedConfirmationService(
            $epo,
            $documents,
            $parts,
            $events,
            $this->createStub(DocumentStorage::class),
            $crypto,
        );

        $result = $service->ingest(
            $this->tempFile('binarni-dodejka'),
            'dodejka.p7s',
            ['id' => 7, 'form_code' => 'dphdp3', 'xml_content' => '<Pisemnost/>', 'xml_sha256' => 'x'],
            3,
            42,
            9,
            'production',
        );

        self::assertIsArray($result['confirmation']);
        self::assertArrayNotHasKey('state_password', $result['confirmation']);
        self::assertSame('568467011', $result['confirmation']['reference']);
        self::assertSame(42, $result['confirmation']['attempt_id']);
        self::assertTrue($result['confirmation']['status_query_available']);
    }

    /** Dodejka bez ID pokusu se přiváže k poslednímu asistovanému předání. */
    public function testFallsBackToLatestAssistedAttempt(): void
    {
        $documents = $this->createStub(TaxSubmissionDocumentService::class);
        $documents->method('artifactKind')->willReturn('confirmation_p7s');
        $documents->method('ingestArtifact')->willReturn([
            'artifact' => ['id' => 5, 'artifact_kind' => 'confirmation_p7s'],
            'confirmation' => [
                'reference' => '111',
                'submitted_at' => '2026-08-21 10:36:43',
                'state_password' => null,
                'verification_status' => 'warning',
                'is_confirmation' => true,
                'receipt' => [],
            ],
            'hint' => null,
        ]);

        $epo = $this->createMock(TaxSubmissionEpoRepository::class);
        $epo->method('latestAssistedAttempt')->willReturn(['id' => 77, 'status' => 'awaiting_confirmation']);
        $epo->expects(self::once())
            ->method('recordAssistedConfirmation')
            ->with(77, 7, 3, '111', '2026-08-21 10:36:43', null)
            ->willReturn(true);

        $crypto = $this->createMock(SecretEncryption::class);
        $crypto->expects(self::never())->method('encryptFor');

        $parts = $this->createStub(EpoConfirmationPartsArchiver::class);
        $parts->method('archive')->willReturn(['stored' => [], 'failed' => [], 'receipt' => []]);

        $service = new EpoAssistedConfirmationService(
            $epo,
            $documents,
            $parts,
            $this->createStub(EpoDirectSubmissionRepository::class),
            $this->createStub(DocumentStorage::class),
            $crypto,
        );

        $result = $service->ingest(
            $this->tempFile('binarni-dodejka'),
            'dodejka.p7s',
            ['id' => 7, 'form_code' => 'dphdp3', 'xml_content' => '<Pisemnost/>', 'xml_sha256' => 'x'],
            3,
            null,
            9,
            'production',
        );

        self::assertSame(77, $result['confirmation']['attempt_id']);
        // Bez hesla dotaz na stav nabídnout nelze — portál chce podací číslo A heslo.
        self::assertFalse($result['confirmation']['status_query_available']);
    }

    public function testInvalidConfirmationIsArchivedButNothingIsTakenFromIt(): void
    {
        $documents = $this->createStub(TaxSubmissionDocumentService::class);
        $documents->method('artifactKind')->willReturn('confirmation_p7s');
        $documents->method('ingestArtifact')->willReturn([
            'artifact' => ['id' => 5, 'artifact_kind' => 'confirmation_p7s'],
            'confirmation' => [
                'reference' => '568467011',
                'submitted_at' => '2026-08-21 10:36:43',
                'state_password' => 'tajne123',
                'verification_status' => 'invalid',
                'is_confirmation' => true,
                'receipt' => [],
            ],
            'hint' => null,
        ]);

        $parts = $this->createMock(EpoConfirmationPartsArchiver::class);
        $parts->expects(self::never())->method('archive');

        $epo = $this->createMock(TaxSubmissionEpoRepository::class);
        $epo->expects(self::never())->method('recordAssistedConfirmation');

        $service = new EpoAssistedConfirmationService(
            $epo,
            $documents,
            $parts,
            $this->createStub(EpoDirectSubmissionRepository::class),
            $this->createStub(DocumentStorage::class),
            $this->createStub(SecretEncryption::class),
        );

        $result = $service->ingest(
            $this->tempFile('binarni-dodejka'),
            'dodejka.p7s',
            ['id' => 7, 'form_code' => 'dphdp3', 'xml_content' => '<Pisemnost/>', 'xml_sha256' => 'x'],
            3,
            42,
            9,
            'production',
        );

        self::assertArrayNotHasKey('state_password', $result['confirmation']);
        self::assertNull($result['confirmation']['attempt_id']);
        self::assertFalse($result['confirmation']['status_query_available']);
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epo-assisted-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }
}
