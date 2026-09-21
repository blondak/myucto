<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Relativní cesta přílohy faktury bez změny uloženého basename. */
final class CompanyBackupInvoiceAttachmentFilePath
{
    public static function sourcePath(mixed $filename, int $supplierId, int $invoiceId): string
    {
        self::assertPositiveId($supplierId);
        self::assertPositiveId($invoiceId);
        try {
            $name = CompanyBackupFileBasename::validate($filename);
        } catch (\InvalidArgumentException) {
            throw self::invalid();
        }
        $path = 'sup-' . $supplierId . '/attachments/' . $invoiceId . '/' . $name;
        CompanyBackupFileEntry::normalizeSourcePath($path);
        return $path;
    }

    public static function accepts(string $path, int $supplierId): bool
    {
        return self::parts($path, $supplierId) !== null;
    }

    public static function storedFilename(string $path, int $supplierId): string
    {
        $parts = self::parts($path, $supplierId);
        if ($parts === null) {
            throw self::invalid();
        }
        return $parts['filename'];
    }

    public static function parseInvoiceId(string $path, int $supplierId): int
    {
        $parts = self::parts($path, $supplierId);
        if ($parts === null) {
            throw self::invalid();
        }
        return $parts['invoice_id'];
    }

    public static function restoreTargetPath(
        string $sourcePath,
        int $sourceSupplierId,
        int $targetSupplierId,
        int $targetInvoiceId,
    ): string {
        $filename = self::storedFilename($sourcePath, $sourceSupplierId);
        return self::sourcePath($filename, $targetSupplierId, $targetInvoiceId);
    }

    /** @return null|array{invoice_id:int,filename:string} */
    private static function parts(string $path, int $supplierId): ?array
    {
        if ($supplierId < 1) {
            return null;
        }
        try {
            CompanyBackupFileEntry::normalizeSourcePath($path);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (preg_match('/\Asup-([1-9][0-9]*)\/attachments\/([1-9][0-9]*)\/(.+)\z/Du', $path, $matches)
            !== 1 || $matches[1] !== (string) $supplierId
        ) {
            return null;
        }
        $invoiceId = filter_var($matches[2], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($invoiceId) || (string) $invoiceId !== $matches[2]) {
            return null;
        }
        try {
            $filename = CompanyBackupFileBasename::validate($matches[3]);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return ['invoice_id' => $invoiceId, 'filename' => $filename];
    }

    private static function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw self::invalid();
        }
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('Cesta přílohy faktury není platná.');
    }
}
