<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\Auth\SecretEncryption;

final class PayrollPaymentExportStorage
{
    public function __construct(
        private readonly SecretEncryption $encryption,
    ) {}

    /**
     * @return array{
     *   storage_key:string,
     *   file_sha256:string,
     *   size_bytes:int,
     *   created:bool
     * }
     */
    public function store(int $supplierId, string $bytes): array
    {
        if ($supplierId <= 0 || $bytes === '') {
            throw new \InvalidArgumentException(
                'Obsah platebního exportu není platný.',
            );
        }
        $hash = hash('sha256', $bytes);
        $directory = $this->verifiedDirectory($supplierId, $hash);
        $path = $directory . '/' . $hash;
        $created = false;
        if (is_file($path)) {
            $this->readVerified($supplierId, $hash);
        } else {
            $temporary = $directory . '/.tmp-'
                . bin2hex(random_bytes(12));
            $ciphertext = $this->encryption->encryptFor(
                $bytes,
                $this->context($supplierId, $hash),
            );
            try {
                $written = @file_put_contents(
                    $temporary,
                    $ciphertext,
                    LOCK_EX,
                );
                if ($written !== strlen($ciphertext)) {
                    throw new \RuntimeException(
                        'Platební export se nepodařilo uložit.',
                    );
                }
                @chmod($temporary, 0640);
                if (@rename($temporary, $path)) {
                    $created = true;
                } elseif (!is_file($path)) {
                    throw new \RuntimeException(
                        'Platební export se nepodařilo dokončit.',
                    );
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
            $this->readVerified($supplierId, $hash);
        }

        return [
            'storage_key' => $hash,
            'file_sha256' => $hash,
            'size_bytes' => strlen($bytes),
            'created' => $created,
        ];
    }

    public function readVerified(int $supplierId, string $storageKey): string
    {
        $path = $this->verifiedPath($supplierId, $storageKey);
        if ($path === null) {
            throw new \RuntimeException(
                'Platební export nebyl nalezen.',
            );
        }
        $ciphertext = file_get_contents($path);
        if (!is_string($ciphertext)) {
            throw new \RuntimeException(
                'Platební export se nepodařilo načíst.',
            );
        }
        if (!str_starts_with($ciphertext, 'enc:v2:')) {
            throw new \RuntimeException(
                'Archivovaný platební export není bezpečně zašifrovaný.',
            );
        }
        $bytes = $this->encryption->decryptFor(
            $ciphertext,
            $this->context($supplierId, $storageKey),
        );
        if (!hash_equals($storageKey, hash('sha256', $bytes))
        ) {
            throw new \RuntimeException(
                'Integrita platebního exportu neodpovídá archivu.',
            );
        }

        return $bytes;
    }

    public function deleteCreated(int $supplierId, string $storageKey): void
    {
        $path = $this->verifiedPath($supplierId, $storageKey, false);
        if ($path === null) {
            return;
        }
        if (!@unlink($path) && is_file($path)) {
            throw new \RuntimeException(
                'Osiřelý platební export se nepodařilo odstranit.',
            );
        }
        @rmdir(dirname($path));
    }

    public static function baseDir(int $supplierId): string
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma platebního exportu není platná.',
            );
        }

        return RuntimePaths::storage(
            'payroll-payment-exports/sup-' . $supplierId,
        );
    }

    private function verifiedPath(
        int $supplierId,
        string $storageKey,
        bool $required = true,
    ): ?string {
        if (preg_match('/^[0-9a-f]{64}$/D', $storageKey) !== 1) {
            throw new \InvalidArgumentException(
                'Klíč platebního exportu není platný.',
            );
        }
        $base = self::baseDir($supplierId);
        $path = $base . '/' . substr($storageKey, 0, 2)
            . '/' . $storageKey;
        if (!is_file($path)) {
            if ($required) {
                throw new \RuntimeException(
                    'Platební export nebyl nalezen.',
                );
            }

            return null;
        }
        $realPath = realpath($path);
        $realRoot = $this->canonicalRoot(false);
        $realBase = realpath($base);
        $realDirectory = realpath(dirname($path));
        if ($realPath === false
            || $realBase === false
            || $realDirectory === false
            || !$this->inside($realBase, $realRoot)
            || !$this->inside($realDirectory, $realBase)
            || !$this->inside($realPath, $realDirectory)
        ) {
            throw new \RuntimeException(
                'Cesta platebního exportu není bezpečná.',
            );
        }

        return $realPath;
    }

    private function verifiedDirectory(
        int $supplierId,
        string $storageKey,
    ): string {
        $realRoot = $this->canonicalRoot(true);
        $base = self::baseDir($supplierId);
        if (!is_dir($base)
            && !@mkdir($base, 0750)
            && !is_dir($base)
        ) {
            throw new \RuntimeException(
                'Úložiště platebních exportů není dostupné.',
            );
        }
        $realBase = realpath($base);
        if ($realBase === false || !$this->inside($realBase, $realRoot)) {
            throw new \RuntimeException(
                'Kořen úložiště platebních exportů není bezpečný.',
            );
        }
        $directory = $base . '/' . substr($storageKey, 0, 2);
        if (!is_dir($directory)
            && !@mkdir($directory, 0750)
            && !is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Úložiště platebních exportů není dostupné.',
            );
        }
        $realDirectory = realpath($directory);
        if ($realDirectory === false
            || !$this->inside($realDirectory, $realBase)
        ) {
            throw new \RuntimeException(
                'Cesta úložiště platebního exportu není bezpečná.',
            );
        }

        return $realDirectory;
    }

    private function context(int $supplierId, string $storageKey): string
    {
        return "payroll-payment-export-storage:{$supplierId}:{$storageKey}";
    }

    private function canonicalRoot(bool $create): string
    {
        $base = RuntimePaths::base();
        $realBase = realpath($base);
        if ($realBase === false) {
            throw new \RuntimeException(
                'Kořen runtime dat platebních exportů není bezpečný.',
            );
        }
        $storage = RuntimePaths::storage();
        if ($create
            && !is_dir($storage)
            && !@mkdir($storage, 0750)
            && !is_dir($storage)
        ) {
            throw new \RuntimeException(
                'Úložiště runtime dat platebních exportů není dostupné.',
            );
        }
        $realStorage = realpath($storage);
        if ($realStorage === false
            || !$this->inside($realStorage, $realBase)
        ) {
            throw new \RuntimeException(
                'Úložiště runtime dat platebních exportů není bezpečné.',
            );
        }
        $root = RuntimePaths::storage('payroll-payment-exports');
        if ($create
            && !is_dir($root)
            && !@mkdir($root, 0750)
            && !is_dir($root)
        ) {
            throw new \RuntimeException(
                'Kořen úložiště platebních exportů není dostupný.',
            );
        }
        $realRoot = realpath($root);
        if ($realRoot === false
            || !$this->inside($realRoot, $realStorage)
        ) {
            throw new \RuntimeException(
                'Kořen úložiště platebních exportů není bezpečný.',
            );
        }

        return $realRoot;
    }

    private function inside(string $path, string $base): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $base = strtolower(rtrim(str_replace('\\', '/', $base), '/'));

        return str_starts_with($path, $base . '/');
    }
}
