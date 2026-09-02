<?php

declare(strict_types=1);

namespace MyInvoice\Service\Backup\Company;

use Psr\Http\Message\StreamInterface;

/** PSR-7 stream, který nikdy nepřečte bajt mimo potvrzený download plán. */
final class CompanyBackupDownloadStream implements StreamInterface
{
    /** @var resource|null */
    private $stream;

    private int $position = 0;

    /** @param resource $stream */
    private function __construct(
        $stream,
        private readonly CompanyBackupDownloadPlan $plan,
    ) {
        $this->stream = $stream;
    }

    public function __destruct()
    {
        $this->close();
    }

    public static function open(
        string $path,
        CompanyBackupDownloadPlan $plan,
    ): self {
        if ($path === '' || is_link($path) || !is_file($path)) {
            throw new CompanyBackupJobException('artifact_unavailable');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new CompanyBackupJobException('artifact_unavailable');
        }

        $stat = @fstat($stream);
        $size = is_array($stat) ? $stat['size'] : null;
        if (!is_int($size)
            || $size !== $plan->totalBytes
            || @fseek($stream, $plan->offset, SEEK_SET) !== 0
        ) {
            @fclose($stream);
            throw new CompanyBackupJobException('artifact_unavailable');
        }

        return new self($stream, $plan);
    }

    public function __toString(): string
    {
        try {
            $this->rewind();
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    /** @return resource|null */
    public function detach()
    {
        $stream = $this->stream;
        $this->stream = null;
        return $stream;
    }

    public function getSize(): int
    {
        return $this->plan->length;
    }

    public function tell(): int
    {
        $this->requireStream();
        return $this->position;
    }

    public function eof(): bool
    {
        return !is_resource($this->stream)
            || $this->position >= $this->plan->length;
    }

    public function isSeekable(): bool
    {
        if (!is_resource($this->stream)) {
            return false;
        }
        $metadata = stream_get_meta_data($this->stream);
        return $metadata['seekable'] === true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $stream = $this->requireStream();
        $target = match ($whence) {
            SEEK_SET => $this->boundedTarget(0, $offset),
            SEEK_CUR => $this->boundedTarget($this->position, $offset),
            SEEK_END => $this->boundedTarget($this->plan->length, $offset),
            default => throw new CompanyBackupJobException('artifact_seek_invalid'),
        };
        if (!$this->isSeekable()
            || @fseek($stream, $this->plan->offset + $target, SEEK_SET) !== 0
        ) {
            throw new CompanyBackupJobException('artifact_seek_failed');
        }
        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new CompanyBackupJobException('artifact_stream_read_only');
    }

    public function isReadable(): bool
    {
        return is_resource($this->stream);
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException(
                'Počet bajtů čtených z archivu nesmí být záporný.',
            );
        }
        $stream = $this->requireStream();
        $remaining = $this->plan->length - $this->position;
        if ($length === 0 || $remaining === 0) {
            return '';
        }
        $requested = min($length, $remaining);
        if ($requested < 1) {
            return '';
        }
        $contents = @fread($stream, $requested);
        $read = is_string($contents) ? strlen($contents) : 0;
        $this->position += $read;
        if (!is_string($contents) || $read !== $requested) {
            throw new CompanyBackupJobException('artifact_read_failed');
        }

        return $contents;
    }

    public function getContents(): string
    {
        $this->requireStream();
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(min(
                1_048_576,
                $this->plan->length - $this->position,
            ));
        }
        return $contents;
    }

    /**
     * @return array<string,mixed>|mixed|null
     */
    public function getMetadata(?string $key = null)
    {
        if (!is_resource($this->stream)) {
            return null;
        }
        $metadata = stream_get_meta_data($this->stream);
        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    /** @return resource */
    private function requireStream()
    {
        if (!is_resource($this->stream)) {
            throw new CompanyBackupJobException('artifact_stream_detached');
        }
        return $this->stream;
    }

    private function boundedTarget(int $base, int $offset): int
    {
        if ($offset < -$base || $offset > $this->plan->length - $base) {
            throw new CompanyBackupJobException('artifact_seek_invalid');
        }
        return $base + $offset;
    }
}
