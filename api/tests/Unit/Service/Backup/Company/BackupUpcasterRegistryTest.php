<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use InvalidArgumentException;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcaster;
use MyInvoice\Service\Backup\Company\Upcast\BackupUpcasterRegistry;
use PHPUnit\Framework\TestCase;

final class BackupUpcasterRegistryTest extends TestCase
{
    public function testPathIsCompleteDirectionalAndAppliedInSchemaOrder(): void
    {
        $registry = BackupUpcasterRegistry::fromUpcasters([
            new RecordingUpcaster('v2-v3', 'company.v2', 'company.v3'),
            new RecordingUpcaster('v1-v2', 'company.v1', 'company.v2'),
        ]);

        $path = $registry->path('company.v1', 'company.v3');

        self::assertNotNull($path);
        self::assertSame(['v1-v2', 'v2-v3'], $path->ids());
        self::assertTrue($path->isLossless());

        $manifest = $path->upcastManifest([
            'source' => ['schema_revision' => 'company.v1'],
            'trace' => [],
        ]);
        self::assertSame('company.v3', $manifest['source']['schema_revision']);
        self::assertSame(['v1-v2', 'v2-v3'], $manifest['trace']);

        $rows = iterator_to_array($path->upcastRows('invoices', [[
            'id' => 10,
            'trace' => [],
        ]]), false);
        self::assertSame(['v1-v2', 'v2-v3'], $rows[0]['trace']);
    }

    public function testGapAndReverseDirectionHaveNoFallback(): void
    {
        $registry = BackupUpcasterRegistry::fromUpcasters([
            new RecordingUpcaster('v1-v2', 'company.v1', 'company.v2'),
        ]);

        self::assertNull($registry->path('company.v1', 'company.v3'));
        self::assertNull($registry->path('company.v2', 'company.v1'));
    }

    public function testSameRevisionUsesIdentityPathWithoutRegisteredAdapter(): void
    {
        $path = BackupUpcasterRegistry::empty()->path('company.v1', 'company.v1');

        self::assertNotNull($path);
        self::assertSame([], $path->ids());
        self::assertSame([['id' => 1]], iterator_to_array($path->upcastRows('clients', [['id' => 1]]), false));
    }

    public function testRegistryRejectsAmbiguousSourceRevision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('více výstupních');

        BackupUpcasterRegistry::fromUpcasters([
            new RecordingUpcaster('v1-v2', 'company.v1', 'company.v2'),
            new RecordingUpcaster('v1-v3', 'company.v1', 'company.v3'),
        ]);
    }

    public function testRegistryRejectsCycle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cyklus');

        BackupUpcasterRegistry::fromUpcasters([
            new RecordingUpcaster('v1-v2', 'company.v1', 'company.v2'),
            new RecordingUpcaster('v2-v1', 'company.v2', 'company.v1'),
        ]);
    }
}

final readonly class RecordingUpcaster implements BackupUpcaster
{
    public function __construct(
        private string $upcasterId,
        private string $source,
        private string $target,
        private bool $lossless = true,
    ) {}

    public function id(): string
    {
        return $this->upcasterId;
    }

    public function sourceRevision(): string
    {
        return $this->source;
    }

    public function targetRevision(): string
    {
        return $this->target;
    }

    public function isLossless(): bool
    {
        return $this->lossless;
    }

    public function warnings(): array
    {
        return [];
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    public function upcastManifest(array $manifest): array
    {
        $manifest['trace'][] = $this->upcasterId;
        $manifest['source']['schema_revision'] = $this->target;
        return $manifest;
    }

    public function upcastRows(string $logicalObject, iterable $rows): iterable
    {
        foreach ($rows as $row) {
            $row['trace'][] = $this->upcasterId;
            yield $row;
        }
    }
}
