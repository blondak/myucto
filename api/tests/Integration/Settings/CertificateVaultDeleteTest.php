<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Settings;

use MyInvoice\Action\Settings\CertificateVaultAction;
use MyInvoice\Action\Settings\SigningProfilesAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\EpoSigningCredentialRepository;
use MyInvoice\Repository\SigningProfileRepository;
use MyInvoice\Repository\Submission\SubmissionChannelCredentialRepository;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Epo\EpoSigningCredentialService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Mazání certifikátu z trezoru (Systém → E-maily a certifikáty → Elektronické
 * podpisy) a step-up u výběru certifikátu z trezoru do podpisového profilu.
 *
 * Mazání: smí jen vlastník a jen se step-up ověřením; certifikát, který
 * podepisuje profil nebo přihlašuje datovou schránku, se nesmaže; volba
 * certifikátu pro mzdová podání se zruší s ním, ať nezůstane odkaz do prázdna.
 *
 * Výběr z trezoru i mazání přijímají stejný step-up jako sekce Certifikáty:
 * passkey proof, nebo heslo (+ TOTP, má-li ho účet zapnutý).
 */
#[Group('integration')]
final class CertificateVaultDeleteTest extends TestCase
{
    use IsolatedSupplierTrait;
    use OpensslConfigTrait;

    private const LOGIN_PASSWORD = 'Synteticke-Heslo-Do-Aplikace-9';
    private const PFX_PASSPHRASE = 'SYNTETICKE-HESLO-K-PFX-11';
    private const SESSION_TOKEN = 'synteticka-relace-cert-delete';

    private Connection $db;
    private CertificateVaultAction $action;
    private SigningProfilesAction $profilesAction;
    private EpoSigningCredentialRepository $vault;
    private EpoSigningCredentialService $credentialService;
    private SigningProfileRepository $profiles;
    private SubmissionChannelCredentialRepository $channels;
    private MfaStepUpService $mfaStepUp;
    private TotpService $totp;
    private SecretEncryption $crypto;
    private int $supplierId;
    private int $ownerId;
    private int $strangerId;
    private bool $fixturesCommitted = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_submission_signing_profiles')
            || !$this->db->hasTable('submission_channel_credentials')
        ) {
            $this->markTestSkipped('Migrace 1373/1381 neproběhly.');
        }
        if ($container->get(SecretEncryption::class)->validateKey() !== null) {
            $this->markTestSkipped('Šifrovací klíč není nastaven.');
        }
        $this->action = $container->get(CertificateVaultAction::class);
        $this->profilesAction = $container->get(SigningProfilesAction::class);
        $this->vault = $container->get(EpoSigningCredentialRepository::class);
        $this->credentialService = $container->get(EpoSigningCredentialService::class);
        $this->profiles = $container->get(SigningProfileRepository::class);
        $this->channels = $container->get(SubmissionChannelCredentialRepository::class);
        $this->mfaStepUp = $container->get(MfaStepUpService::class);
        $this->totp = $container->get(TotpService::class);
        $this->crypto = $container->get(SecretEncryption::class);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $template = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $template);
        $hash = $container->get(PasswordHasher::class)->hash(self::LOGIN_PASSWORD);
        $this->ownerId = $this->user($hash, 'owner');
        $this->strangerId = $this->user($hash, 'stranger');
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($this->fixturesCommitted) {
            $this->removeCommittedFixtures();
        }
    }

    public function testOwnerDeletesUnusedCertificateAndTheDeletionIsAudited(): void
    {
        $credentialId = $this->vaultRow($this->ownerId);

        $response = $this->delete($this->ownerId, $credentialId, ['password' => self::LOGIN_PASSWORD]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->vault->findOwned($credentialId, $this->ownerId));
        self::assertFalse($this->vault->isEnabledForSupplier($credentialId, $this->supplierId));
        $log = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM activity_log
              WHERE action = 'certificate_vault_delete' AND entity_id = ? AND user_id = ?"
        );
        $log->execute([$credentialId, $this->ownerId]);
        self::assertSame(1, (int) $log->fetchColumn());
    }

    public function testCertificateUsedBySigningProfileIsNotDeleted(): void
    {
        $credentialId = $this->vaultRow($this->ownerId);
        $profileId = $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: $this->ownerId,
            name: 'Syntetický osobní profil',
            code: 'itest_del_' . bin2hex(random_bytes(4)),
            allowedUsages: ['pdf'],
            defaultBackend: 'native',
            createdBy: $this->ownerId,
        );
        $this->profiles->upsertCredential($this->supplierId, $profileId, [
            'vault_credential_id' => $credentialId,
            'certificate_path' => null,
            'certificate_fingerprint' => str_repeat('a', 64),
            'certificate_subject' => 'CN=Synthetic Signer',
            'certificate_usage' => ['key_usage' => 'Digital Signature'],
            'passphrase_policy' => 'encrypted_store',
            'encrypted_passphrase' => null,
        ], $this->ownerId);

        $response = $this->delete($this->ownerId, $credentialId, ['password' => self::LOGIN_PASSWORD]);

        self::assertSame(409, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('credential_in_use', $error['code']);
        self::assertSame(1, $error['linked_profiles_count']);
        self::assertNotNull($this->vault->findOwned($credentialId, $this->ownerId));
    }

    /** Přístup k datové schránce nemá cizí klíč, takže by po smazání zůstal odkaz do prázdna. */
    public function testCertificateUsedByDataBoxAccessIsNotDeleted(): void
    {
        $credentialId = $this->vaultRow($this->ownerId);
        $this->channels->save($this->supplierId, 'isds', 'test', [
            'label' => 'Syntetická schránka',
            'box_id' => 'abc2def',
            'credential_id' => $credentialId,
            'certificate_ciphertext' => null,
            'certificate_passphrase_ciphertext' => null,
            'certificate_fingerprint' => null,
            'certificate_valid_to' => null,
        ], $this->ownerId);

        $response = $this->delete($this->ownerId, $credentialId, ['password' => self::LOGIN_PASSWORD]);

        self::assertSame(409, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('credential_in_use', $error['code']);
        self::assertSame(1, $error['linked_data_box_count']);
        self::assertNotNull($this->vault->findOwned($credentialId, $this->ownerId));
        $channel = $this->channels->findPublic($this->supplierId, 'isds', 'test');
        self::assertFalse($channel['credential_missing'] ?? true);
    }

    /** Volba certifikátu pro mzdová podání je jen výběr: zruší se spolu s certifikátem. */
    public function testPayrollSelectionIsCancelledTogetherWithTheCertificate(): void
    {
        $credentialId = $this->vaultRow($this->ownerId);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_submission_signing_profiles
                (supplier_id, environment, credential_id, owner_user_id, created_by)
             VALUES (?, 'production', ?, ?, ?)"
        )->execute([$this->supplierId, $credentialId, $this->ownerId, $this->ownerId]);
        $listed = $this->listedCredential($credentialId);
        self::assertSame(1, $listed['linked_payroll_selections_count']);

        $response = $this->delete($this->ownerId, $credentialId, ['password' => self::LOGIN_PASSWORD]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            [['supplier_id' => $this->supplierId, 'environment' => 'production']],
            $this->json($response)['payroll_selections_removed'],
        );
        $remaining = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_submission_signing_profiles WHERE supplier_id = ?'
        );
        $remaining->execute([$this->supplierId]);
        self::assertSame(0, (int) $remaining->fetchColumn());
        self::assertNull($this->vault->findOwned($credentialId, $this->ownerId));
    }

    public function testOtherUserCannotDeleteTheOwnersCertificate(): void
    {
        $credentialId = $this->vaultRow($this->ownerId);

        $response = $this->delete($this->strangerId, $credentialId, ['password' => self::LOGIN_PASSWORD]);

        self::assertSame(404, $response->getStatusCode());
        self::assertNotNull($this->vault->findOwned($credentialId, $this->ownerId));
    }

    public function testDeletionWithoutStepUpIsRefused(): void
    {
        $credentialId = $this->vaultRow($this->ownerId);

        $withoutProof = $this->delete($this->ownerId, $credentialId, []);
        $wrongPassword = $this->delete($this->ownerId, $credentialId, ['password' => 'spatne-heslo']);
        $viaToken = $this->delete($this->ownerId, $credentialId, ['password' => self::LOGIN_PASSWORD], 'bearer');

        self::assertSame(401, $withoutProof->getStatusCode());
        self::assertSame(401, $wrongPassword->getStatusCode());
        self::assertSame(403, $viaToken->getStatusCode());
        self::assertNotNull($this->vault->findOwned($credentialId, $this->ownerId));
    }

    /** Tlačítko Smazat nabízí i passkey: jednorázový proof nahradí heslo i TOTP. */
    public function testDeletionAcceptsPasskeyProof(): void
    {
        $credentialId = $this->vaultRow($this->ownerId);

        $response = $this->delete($this->ownerId, $credentialId, ['step_up_token' => $this->passkeyProof()]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->vault->findOwned($credentialId, $this->ownerId));
    }

    /**
     * Výběr certifikátu z trezoru do osobního podpisového profilu přijímá stejný
     * passkey proof jako sekce Certifikáty (operace `epo.certificate`).
     */
    public function testVaultLinkAcceptsPasskeyProof(): void
    {
        $credentialId = $this->importRealCertificate();
        $profileId = $this->personalProfile();

        $response = $this->link($profileId, ['credential_id' => $credentialId, 'step_up_token' => $this->passkeyProof()]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('personal_vault', $this->json($response)['certificate_source']);
        self::assertSame($credentialId, $this->profiles->credential($this->supplierId, $profileId)['vault_credential_id'] ?? null);
    }

    public function testVaultLinkAcceptsPasswordAndTotp(): void
    {
        $credentialId = $this->importRealCertificate();
        $profileId = $this->personalProfile();
        $secret = 'JBSWY3DPEHPK3PXP';
        $this->db->pdo()->prepare('UPDATE users SET totp_enabled = 1, totp_secret = ? WHERE id = ?')
            ->execute([$this->crypto->encrypt($secret), $this->ownerId]);

        $passwordOnly = $this->link($profileId, ['credential_id' => $credentialId, 'password' => self::LOGIN_PASSWORD]);
        self::assertSame(401, $passwordOnly->getStatusCode());
        self::assertSame('totp_required', $this->json($passwordOnly)['error']['code']);

        $response = $this->link($profileId, [
            'credential_id' => $credentialId,
            'password' => self::LOGIN_PASSWORD,
            'totp_code' => $this->totp->currentCode($secret),
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($credentialId, $this->profiles->credential($this->supplierId, $profileId)['vault_credential_id'] ?? null);
    }

    public function testVaultLinkWithoutProofIsRefused(): void
    {
        $credentialId = $this->importRealCertificate();
        $profileId = $this->personalProfile();

        $withoutProof = $this->link($profileId, ['credential_id' => $credentialId]);
        $forgedProof = $this->link($profileId, ['credential_id' => $credentialId, 'step_up_token' => 'neplatny-proof']);

        self::assertSame(401, $withoutProof->getStatusCode());
        self::assertSame(403, $forgedProof->getStatusCode());
        self::assertNull($this->profiles->credential($this->supplierId, $profileId));
    }

    private function delete(int $userId, int $credentialId, array $body, string $method = 'session'): ResponseInterface
    {
        return $this->action->delete(
            $this->request('DELETE', '/api/settings/certificates/' . $credentialId, $userId, $body, $method),
            new Response(),
            ['credentialId' => (string) $credentialId],
        );
    }

    private function link(int $profileId, array $body): ResponseInterface
    {
        return $this->profilesAction->linkPersonalVaultCredential(
            $this->request('PUT', '/api/settings/signing/profiles/' . $profileId . '/credentials/personal-vault', $this->ownerId, $body),
            new Response(),
            ['id' => (string) $profileId],
        );
    }

    /**
     * Jednorázový passkey proof pro operaci `epo.certificate`, jak ho vydá ověření v prohlížeči.
     *
     * Úložiště proofů si vydání i spotřebu řídí vlastní transakcí, takže tyhle
     * testy nemůžou běžet uvnitř obalové transakce: přípravná data se potvrdí
     * a tearDown je po sobě uklidí.
     */
    private function passkeyProof(): string
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->commit();
            $this->fixturesCommitted = true;
        }
        $credential = random_bytes(32);
        $this->db->pdo()->prepare(
            'UPDATE users SET webauthn_user_handle = ? WHERE id = ? AND webauthn_user_handle IS NULL'
        )->execute([random_bytes(32), $this->ownerId]);
        $this->db->pdo()->prepare(
            'INSERT INTO webauthn_credentials
             (user_id, credential_id, credential_id_hash, public_key, sign_count,
              transports_json, aaguid, label, created_at)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, UTC_TIMESTAMP(6))'
        )->execute([
            $this->ownerId, $credential, hash('sha256', $credential, true),
            random_bytes(77), '["internal"]', str_repeat("\0", 16), 'Synthetic passkey',
        ]);

        return $this->mfaStepUp->issue(
            $this->ownerId,
            self::SESSION_TOKEN,
            MfaStepUpService::OPERATION_EPO_CERTIFICATE,
            'passkey',
            (int) $this->db->pdo()->lastInsertId(),
        );
    }

    private function request(string $verb, string $path, int $userId, array $body, string $method = 'session'): Request
    {
        return (new ServerRequestFactory())
            ->createServerRequest($verb, $path, ['REMOTE_ADDR' => '192.0.2.10'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, self::SESSION_TOKEN)
            ->withParsedBody($body);
    }

    private function user(string $passwordHash, string $tag): int
    {
        $pdo = $this->db->pdo();
        $roleId = $pdo->query('SELECT role_id FROM users WHERE role_id IS NOT NULL ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, ?, ?, ?, 'cs', 1)"
        )->execute([
            // Krátký e-mail: brute-force počítadlo z něj skládá klíč s omezenou délkou.
            'cd' . $tag[0] . bin2hex(random_bytes(3)) . '@ex.invalid',
            $passwordHash,
            'Synthetic ' . $tag,
            $roleId === false ? null : (int) $roleId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)')
            ->execute([$id, $this->supplierId]);

        return $id;
    }

    private function vaultRow(int $owner): int
    {
        $id = $this->vault->create($owner, [
            'label' => 'Syntetický certifikát k smazání',
            'pfx_ciphertext' => 'enc:v1:synthetic',
            'passphrase_ciphertext' => 'enc:v1:synthetic',
            'fingerprint_sha256' => hash('sha256', 'synthetic-delete-' . random_bytes(8)),
            'subject_dn' => 'CN=Synthetic Signer',
            'issuer_dn' => 'CN=Synthetic Test CA',
            'serial_hex' => '0B',
            'valid_from' => '2020-01-01 00:00:00',
            'valid_to' => '2099-01-01 00:00:00',
            'ik_mpsv_present' => false,
        ]);
        $this->vault->setSupplierEnabled($id, $owner, $this->supplierId, true, $owner);

        return $id;
    }

    private function importRealCertificate(): int
    {
        $config = self::opensslConfigArgs();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $config);
        self::assertNotFalse($key, self::opensslErrors());
        $csr = openssl_csr_new(['commonName' => 'Synteticky firemni podpis'], $key, ['digest_alg' => 'sha256'] + $config);
        self::assertNotFalse($csr, self::opensslErrors());
        $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256'] + $config);
        self::assertNotFalse($cert, self::opensslErrors());
        $pfx = '';
        self::assertTrue(openssl_pkcs12_export($cert, $pfx, $key, self::PFX_PASSPHRASE));
        $item = $this->credentialService->import(
            $this->ownerId,
            $this->supplierId,
            'Syntetický firemní certifikát',
            $pfx,
            self::PFX_PASSPHRASE,
        );

        return (int) $item['id'];
    }

    private function personalProfile(): int
    {
        return $this->profiles->createProfile(
            supplierId: $this->supplierId,
            ownerUserId: $this->ownerId,
            name: 'Syntetický osobní profil',
            code: 'itest_personal_' . bin2hex(random_bytes(4)),
            allowedUsages: ['pdf', 'email_smime'],
            defaultBackend: 'native',
            createdBy: $this->ownerId,
        );
    }

    /** @return array<string,mixed> */
    private function listedCredential(int $credentialId): array
    {
        foreach ($this->vault->listOwnedForSupplier($this->ownerId, $this->supplierId) as $row) {
            if ((int) $row['id'] === $credentialId) {
                return $row;
            }
        }
        self::fail('Certifikát není ve výpisu trezoru.');
    }

    private function removeCommittedFixtures(): void
    {
        $pdo = $this->db->pdo();
        $users = [$this->ownerId, $this->strangerId];
        $statements = [
            ['DELETE sc FROM signing_credentials sc JOIN signing_profiles sp ON sp.id = sc.profile_id WHERE sp.supplier_id = ?', [$this->supplierId]],
            ['DELETE FROM signing_profiles WHERE supplier_id = ?', [$this->supplierId]],
            ['DELETE FROM payroll_submission_signing_profiles WHERE supplier_id = ?', [$this->supplierId]],
            ['DELETE FROM submission_channel_credentials WHERE supplier_id = ?', [$this->supplierId]],
            ['DELETE FROM epo_signing_credentials WHERE owner_user_id IN (?, ?)', $users],
            ['DELETE FROM mfa_step_up_proofs WHERE user_id IN (?, ?)', $users],
            ['DELETE FROM webauthn_credentials WHERE user_id IN (?, ?)', $users],
            ['DELETE FROM user_suppliers WHERE user_id IN (?, ?)', $users],
            ['DELETE FROM users WHERE id IN (?, ?)', $users],
            ['DELETE FROM supplier_vat_status_history WHERE supplier_id = ?', [$this->supplierId]],
            ['DELETE FROM supplier WHERE id = ?', [$this->supplierId]],
        ];
        foreach ($statements as [$sql, $parameters]) {
            try {
                $pdo->prepare($sql)->execute($parameters);
            } catch (\PDOException) {
                // Úklid je nejlepší snaha: řádek může držet auditní stopa.
            }
        }
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
