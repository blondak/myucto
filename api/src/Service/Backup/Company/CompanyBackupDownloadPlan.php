<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/**
 * Neměnný plán stažení jednoho hotového archivu.
 *
 * Podporuje právě jeden byte-range. Vícedílná odpověď by komplikovala stream a
 * neposkytuje nic navíc pro navázání jednoho velkého souboru, proto selže
 * stejnou odpovědí 416 jako ostatní neuspokojitelné rozsahy.
 */
final readonly class CompanyBackupDownloadPlan
{
    private function __construct(
        public int $statusCode,
        public int $offset,
        public int $length,
        public int $totalBytes,
        public string $etag,
    ) {}

    public static function forArchive(
        int $totalBytes,
        string $sha256,
        ?string $rangeHeader = null,
        ?string $ifRangeHeader = null,
    ): self {
        if ($totalBytes < 1 || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('Metadata archivu pro stažení nejsou platná.');
        }

        $etag = '"sha256:' . $sha256 . '"';
        if ($rangeHeader === null) {
            return self::full($totalBytes, $etag);
        }

        if ($ifRangeHeader !== null && trim($ifRangeHeader) !== $etag) {
            return self::full($totalBytes, $etag);
        }

        $rangeHeader = trim($rangeHeader);
        if (preg_match('/\Abytes=([0-9]*)-([0-9]*)\z/Di', $rangeHeader, $match) !== 1
            || ($match[1] === '' && $match[2] === '')
        ) {
            throw new CompanyBackupDownloadRangeException($totalBytes);
        }

        $startDigits = self::normalizedDecimal($match[1]);
        $endDigits = self::normalizedDecimal($match[2]);
        if ($startDigits === null) {
            return self::suffix($totalBytes, $etag, $endDigits);
        }

        if (self::greaterThanInt($startDigits, $totalBytes - 1)) {
            throw new CompanyBackupDownloadRangeException($totalBytes);
        }
        $offset = (int) $startDigits;

        if ($endDigits === null) {
            $end = $totalBytes - 1;
        } elseif (self::greaterThanInt($endDigits, $totalBytes - 1)) {
            $end = $totalBytes - 1;
        } else {
            $end = (int) $endDigits;
        }
        if ($end < $offset) {
            throw new CompanyBackupDownloadRangeException($totalBytes);
        }

        return new self(206, $offset, $end - $offset + 1, $totalBytes, $etag);
    }

    public function isPartial(): bool
    {
        return $this->statusCode === 206;
    }

    public function endInclusive(): int
    {
        return $this->offset + $this->length - 1;
    }

    public function contentRange(): ?string
    {
        if (!$this->isPartial()) {
            return null;
        }

        return 'bytes ' . $this->offset . '-' . $this->endInclusive()
            . '/' . $this->totalBytes;
    }

    private static function full(int $totalBytes, string $etag): self
    {
        return new self(200, 0, $totalBytes, $totalBytes, $etag);
    }

    private static function suffix(
        int $totalBytes,
        string $etag,
        ?string $suffixDigits,
    ): self {
        if ($suffixDigits === null || $suffixDigits === '0') {
            throw new CompanyBackupDownloadRangeException($totalBytes);
        }

        $offset = self::greaterThanOrEqualToInt($suffixDigits, $totalBytes)
            ? 0
            : $totalBytes - (int) $suffixDigits;

        return new self(206, $offset, $totalBytes - $offset, $totalBytes, $etag);
    }

    private static function normalizedDecimal(string $digits): ?string
    {
        if ($digits === '') {
            return null;
        }

        $normalized = ltrim($digits, '0');
        return $normalized === '' ? '0' : $normalized;
    }

    private static function greaterThanInt(string $decimal, int $value): bool
    {
        $boundary = (string) $value;
        return strlen($decimal) > strlen($boundary)
            || (strlen($decimal) === strlen($boundary)
                && strcmp($decimal, $boundary) > 0);
    }

    private static function greaterThanOrEqualToInt(string $decimal, int $value): bool
    {
        $boundary = (string) $value;
        return strlen($decimal) > strlen($boundary)
            || (strlen($decimal) === strlen($boundary)
                && strcmp($decimal, $boundary) >= 0);
    }
}
