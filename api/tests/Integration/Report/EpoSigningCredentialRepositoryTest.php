<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Repository\SigningProfileRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class EpoSigningCredentialRepositoryTest extends TestCase
{
    private Connection $db;
    private EpoSigningCredentialRepository $credentials;
    private SigningProfileRepository $profiles;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->credentials = $container->get(EpoSigningCredentialRepository::class);
            $this->profiles = $container->get(SigningProfileRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($this->supplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí základní data.');
        }
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testCredentialIsOwnerScopedAndExplicitlyMappedToSupplier(): void
    {
        $fingerprint = hash('sha256', 'synthetic-epo-certificate-' . random_bytes(8));
        $credentialId = $this->credentials->create($this->userId, [
            'label' => 'Syntetický EPO certifikát',
            'pfx_ciphertext' => 'enc:v1:synthetic',
            'passphrase_ciphertext' => 'enc:v1:synthetic',
            'fingerprint_sha256' => $fingerprint,
            'subject_dn' => 'CN=Synthetic EPO Signer',
            'issuer_dn' => 'CN=Synthetic Test CA',
            'serial_hex' => '01',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'ik_mpsv_present' => true,
        ]);

        self::assertNotNull($this->credentials->findOwned($credentialId, $this->userId));
        self::assertNull($this->credentials->findOwned($credentialId, $this->userId + 999999));
        self::assertNull($this->credentials->findUsable(
            $credentialId,
            $this->userId,
            $this->supplierId,
        ));

        self::assertTrue($this->credentials->setSupplierEnabled(
            $credentialId,
            $this->userId,
            $this->supplierId,
            true,
            $this->userId,
        ));
        self::assertNotNull($this->credentials->findUsable(
            $credentialId,
            $this->userId,
            $this->supplierId,
        ));
        $listed = $this->credentials->listOwnedForSupplier($this->userId, $this->supplierId);
        self::assertTrue($listed[0]['enabled_for_supplier']);
        self::assertArrayNotHasKey('pfx_ciphertext', $listed[0]);
        self::assertArrayNotHasKey('passphrase_ciphertext', $listed[0]);

        self::assertTrue($this->credentials->deleteOwned($credentialId, $this->userId));
        self::assertNull($this->credentials->findOwned($credentialId, $this->userId));
    }

    public function testCredentialCannotBeDeletedWhileSharedWithSigningProfile(): void
    {
        $fingerprint = hash('sha256', 'synthetic-shared-certificate-' . random_bytes(8));
        $credentialId = $this->credentials->create($this->userId, [
            'label' => 'Sdílený syntetický certifikát',
            'pfx_ciphertext' => 'enc:v1:synthetic',
            'passphrase_ciphertext' => 'enc:v1:synthetic',
            'fingerprint_sha256' => $fingerprint,
            'subject_dn' => 'CN=Synthetic Shared Signer',
            'issuer_dn' => 'CN=Synthetic Test CA',
            'serial_hex' => '02',
            'valid_from' => '2026-01-01 00:00:00',
            'valid_to' => '2027-01-01 00:00:00',
            'ik_mpsv_present' => true,
        ]);
        self::assertTrue($this->credentials->setSupplierEnabled(
            $credentialId,
            $this->userId,
            $this->supplierId,
            true,
            $this->userId,
        ));

        $profileId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: $this->userId,
            name: 'Shared certificate integration profile',
            code: 'itest_shared_' . bin2hex(random_bytes(4)),
            allowedUsages: ['pdf', 'email_smime'],
            defaultBackend: 'native',
            createdBy: $this->userId,
        );
        $this->profiles->upsertCredential($this->supplierId, $profileId, [
            'vault_credential_id' => $credentialId,
            'certificate_path' => null,
            'certificate_fingerprint' => $fingerprint,
            'certificate_subject' => 'CN=Synthetic Shared Signer',
            'certificate_usage' => ['key_usage' => 'Digital Signature'],
            'passphrase_policy' => 'encrypted_store',
            'encrypted_passphrase' => null,
        ], $this->userId);

        $profileCredential = $this->profiles->credential($this->supplierId, $profileId);
        self::assertNotNull($profileCredential);
        self::assertSame($credentialId, $profileCredential['vault_credential_id']);
        self::assertNull($profileCredential['certificate_path']);
        self::assertSame(1, $this->credentials->linkedProfileCount($credentialId, $this->userId));
        self::assertSame(1, $this->credentials->linkedSupplierProfileCount(
            $credentialId,
            $this->userId,
            $this->supplierId,
        ));
        self::assertFalse($this->credentials->deleteOwned($credentialId, $this->userId));

        $listed = $this->credentials->listOwnedForSupplier($this->userId, $this->supplierId);
        $shared = array_values(array_filter(
            $listed,
            static fn (array $row): bool => (int) $row['id'] === $credentialId,
        ));
        self::assertSame(1, $shared[0]['linked_profiles_count'] ?? null);
        self::assertSame(1, $shared[0]['linked_supplier_profiles_count'] ?? null);

        self::assertTrue($this->profiles->softDeleteProfile($this->supplierId, $profileId));
        $credentialCount = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM signing_credentials WHERE profile_id = ?'
        );
        $credentialCount->execute([$profileId]);
        self::assertSame(0, (int) $credentialCount->fetchColumn());
        self::assertSame(0, $this->credentials->linkedProfileCount($credentialId, $this->userId));
        self::assertTrue($this->credentials->deleteOwned($credentialId, $this->userId));
    }
}
