<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Personnel;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyRing;

/**
 * Úložiště souborů personálního spisu (pracovní smlouvy, dodatky, …).
 *
 * Leží ve vlastním kořeni `storage/payroll-personnel/`, ne v sekci Dokumenty:
 * jsou to soukromá data zaměstnance a zálohují se samostatně
 * (`cron-backup-personnel`).
 *
 * Rozvržení `sup-{firma}/subj-{osoba}/{hh}/{sha256 plaintextu}`. Obsah je
 * zašifrovaný AES-256-GCM datovým klíčem OSOBY
 * ({@see PayrollDocumentKeyRing}) — tímtéž, kterým se šifrují výplatní pásky.
 * Krypto-výmaz osobních údajů tak znečitelní i personální spis, aniž by o něm
 * výmazová větev musela vědět. AAD váže ciphertext na firmu, osobu a otisk,
 * takže přejmenovaný nebo podstrčený soubor se nedešifruje.
 */
final class PayrollPersonnelFileStorage
{
    public const ROOT = 'payroll-personnel';

    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'ppf:v1:';
    private const NONCE_LEN = 12;
    private const TAG_LEN = 16;

    public function __construct(
        private readonly PayrollDocumentKeyRing $keyRing,
    ) {}

    /** @return array{file_sha256:string,size_bytes:int} */
    public function store(
        int $supplierId,
        int $employeeId,
        string $bytes,
        ?int $actorUserId,
    ): array {
        self::assertIdentity($supplierId, $employeeId);
        if ($bytes === '') {
            throw new \InvalidArgumentException('Soubor je prázdný.');
        }
        $hash = hash('sha256', $bytes);
        $dir = self::subjectDir($supplierId, $employeeId) . '/' . substr($hash, 0, 2);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Úložiště personálního spisu není dostupné.');
        }
        $path = $dir . '/' . $hash;
        if (!is_file($path)) {
            $ciphertext = $this->encrypt($supplierId, $employeeId, $hash, $bytes, $actorUserId);
            $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(12));
            try {
                if (@file_put_contents($tmp, $ciphertext, LOCK_EX) !== strlen($ciphertext)) {
                    throw new \RuntimeException('Soubor personálního spisu se nepodařilo uložit.');
                }
                @chmod($tmp, 0640);
                if (!@rename($tmp, $path) && !is_file($path)) {
                    throw new \RuntimeException('Soubor personálního spisu se nepodařilo dokončit.');
                }
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        return ['file_sha256' => $hash, 'size_bytes' => strlen($bytes)];
    }

    /**
     * @throws \MyInvoice\Service\Payroll\Document\PayrollDocumentKeyDestroyedException
     *         po výmazu osobních údajů je obsah nevratně nečitelný
     */
    public function read(int $supplierId, int $employeeId, string $sha256): string
    {
        self::assertIdentity($supplierId, $employeeId);
        $path = $this->resolve($supplierId, $employeeId, $sha256);
        if ($path === null) {
            throw new \RuntimeException('Soubor personálního spisu nebyl nalezen.');
        }
        $bytes = $this->decrypt($supplierId, $employeeId, $sha256, (string) file_get_contents($path));
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            throw new \RuntimeException('Soubor personálního spisu je poškozený.');
        }

        return $bytes;
    }

    public function delete(int $supplierId, int $employeeId, string $sha256): void
    {
        self::assertIdentity($supplierId, $employeeId);
        $path = $this->resolve($supplierId, $employeeId, $sha256);
        if ($path === null) {
            return;
        }
        if (!@unlink($path) && is_file($path)) {
            throw new \RuntimeException('Soubor personálního spisu se nepodařilo smazat.');
        }
        @rmdir(dirname($path));
    }

    public static function baseDir(int $supplierId): string
    {
        return RuntimePaths::storage(self::ROOT . '/sup-' . $supplierId);
    }

    private static function subjectDir(int $supplierId, int $employeeId): string
    {
        return self::baseDir($supplierId) . '/subj-' . $employeeId;
    }

    private static function assertIdentity(int $supplierId, int $employeeId): void
    {
        if ($supplierId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException('Identita personálního spisu není platná.');
        }
    }

    private function resolve(int $supplierId, int $employeeId, string $sha256): ?string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('Otisk souboru personálního spisu není platný.');
        }
        $base = self::subjectDir($supplierId, $employeeId);
        $path = $base . '/' . substr($sha256, 0, 2) . '/' . $sha256;
        if (!is_file($path)) {
            return null;
        }
        $real = realpath($path);
        $realBase = realpath($base);
        if ($real === false || $realBase === false) {
            return null;
        }
        $realNorm = strtolower(str_replace('\\', '/', $real));
        $baseNorm = strtolower(rtrim(str_replace('\\', '/', $realBase), '/'));
        if (!str_starts_with($realNorm, $baseNorm . '/')) {
            throw new \RuntimeException('Cesta k souboru personálního spisu není platná.');
        }

        return $real;
    }

    private function encrypt(
        int $supplierId,
        int $employeeId,
        string $hash,
        string $bytes,
        ?int $actorUserId,
    ): string {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $cipher = openssl_encrypt(
            $bytes,
            self::CIPHER,
            $this->keyRing->dataKeyForWrite($supplierId, $employeeId, $actorUserId),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::aad($supplierId, $employeeId, $hash),
        );
        if ($cipher === false) {
            throw new \RuntimeException('Soubor personálního spisu se nepodařilo zašifrovat.');
        }

        return self::PREFIX . $nonce . $cipher . $tag;
    }

    private function decrypt(int $supplierId, int $employeeId, string $hash, string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            throw new \RuntimeException('Soubor personálního spisu není zašifrovaný.');
        }
        $blob = substr($stored, strlen(self::PREFIX));
        if (strlen($blob) <= self::NONCE_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Soubor personálního spisu je poškozený.');
        }
        $plaintext = openssl_decrypt(
            substr($blob, self::NONCE_LEN, -self::TAG_LEN),
            self::CIPHER,
            $this->keyRing->dataKeyForRead($supplierId, $employeeId),
            OPENSSL_RAW_DATA,
            substr($blob, 0, self::NONCE_LEN),
            substr($blob, -self::TAG_LEN),
            self::aad($supplierId, $employeeId, $hash),
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Soubor personálního spisu je poškozený.');
        }

        return $plaintext;
    }

    private static function aad(int $supplierId, int $employeeId, string $hash): string
    {
        return 'payroll-personnel-file:' . $supplierId . ':' . $employeeId . ':' . $hash;
    }
}
