<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Config\RuntimePaths;

final class PayrollDocumentStorage
{
    /** @return array{storage_key:string,file_sha256:string,size_bytes:int,path:string} */
    public function store(int $supplierId, string $bytes): array
    {
        if ($supplierId <= 0 || $bytes === '') {
            throw new \InvalidArgumentException('Payroll document storage input is invalid.');
        }
        $hash = hash('sha256', $bytes);
        $dir = self::baseDir($supplierId) . '/' . substr($hash, 0, 2);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Payroll document storage is unavailable.');
        }
        $path = $dir . '/' . $hash;
        if (is_file($path)) {
            if (hash_file('sha256', $path) !== $hash) {
                throw new \RuntimeException('Stored payroll document integrity mismatch.');
            }
        } else {
            $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(12));
            try {
                if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new \RuntimeException('Payroll document could not be stored.');
                }
                if (!@rename($tmp, $path) && !is_file($path)) {
                    throw new \RuntimeException('Payroll document could not be finalized.');
                }
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        return [
            'storage_key' => $hash,
            'file_sha256' => $hash,
            'size_bytes' => strlen($bytes),
            'path' => $path,
        ];
    }

    public function readVerified(int $supplierId, string $storageKey): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $storageKey) !== 1) {
            throw new \InvalidArgumentException('Payroll document storage key is invalid.');
        }
        $path = self::baseDir($supplierId) . '/' . substr($storageKey, 0, 2) . '/' . $storageKey;
        $real = realpath($path);
        $base = realpath(self::baseDir($supplierId));
        if ($real === false || $base === false || !$this->inside($real, $base) || !is_file($real)) {
            throw new \RuntimeException('Payroll document was not found.');
        }
        $bytes = file_get_contents($real);
        if (!is_string($bytes) || hash('sha256', $bytes) !== $storageKey) {
            throw new \RuntimeException('Payroll document integrity check failed.');
        }
        return $bytes;
    }

    public static function baseDir(int $supplierId): string
    {
        return RuntimePaths::storage('payroll-documents/sup-' . $supplierId);
    }

    private function inside(string $path, string $base): bool
    {
        $path = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $path = strtolower($path);
        $base = strtolower($base);
        return str_starts_with($path . '/', $base . '/');
    }
}
