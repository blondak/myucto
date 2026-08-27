<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

/** Kanonický SHA-256 inventář všech pravidelných položek kromě sebe sama. */
final readonly class CompanyBackupChecksums
{
    /** @var array<string,string> */
    private array $hashes;

    /** @param array<string,string> $hashes */
    private function __construct(array $hashes)
    {
        ksort($hashes, SORT_STRING);
        $this->hashes = $hashes;
    }

    public static function parse(string $content): self
    {
        if ($content === '') {
            throw new CompanyBackupArchiveException('checksums_invalid');
        }
        if (!str_ends_with($content, "\n")) {
            throw new CompanyBackupArchiveException('checksums_not_canonical');
        }

        $hashes = [];
        $collisionKeys = [];
        $lines = explode("\n", substr($content, 0, -1));
        foreach ($lines as $line) {
            if (preg_match('/\A([0-9a-f]{64})  (.+)\z/D', $line, $matches) !== 1) {
                throw new CompanyBackupArchiveException('checksums_invalid');
            }
            $path = CompanyBackupArchivePath::normalize($matches[2], false);
            $collisionKey = CompanyBackupArchivePath::collisionKey($path);
            if ($collisionKey === CompanyBackupArchivePath::collisionKey('CHECKSUMS.txt')) {
                throw new CompanyBackupArchiveException('checksums_invalid', $path);
            }
            if (isset($collisionKeys[$collisionKey])) {
                throw new CompanyBackupArchiveException('checksums_duplicate', $path);
            }
            $collisionKeys[$collisionKey] = true;
            $hashes[$path] = $matches[1];
        }

        $result = new self($hashes);
        if (!hash_equals($result->canonicalText(), $content)) {
            throw new CompanyBackupArchiveException('checksums_not_canonical');
        }
        return $result;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->hashes);
    }

    public function hashFor(string $path): ?string
    {
        return $this->hashes[$path] ?? null;
    }

    public function canonicalText(): string
    {
        $lines = [];
        foreach ($this->hashes as $path => $hash) {
            $lines[] = $hash . '  ' . $path;
        }
        return implode("\n", $lines) . "\n";
    }
}
