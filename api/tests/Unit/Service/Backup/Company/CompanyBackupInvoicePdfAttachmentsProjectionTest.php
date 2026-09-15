<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\Company\CompanyBackupInvoiceAttachmentsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupInvoicePdfsProjection;
use MyInvoice\Service\Backup\Company\CompanyBackupReference;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceConstraint;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceMapping;
use MyInvoice\Service\Backup\Company\CompanyBackupReferenceSet;
use PHPUnit\Framework\TestCase;

final class CompanyBackupInvoicePdfAttachmentsProjectionTest extends TestCase
{
    public function testArchivedPdfRemapsInvoiceOnlyAndKeepsAuditFieldsExactly(): void
    {
        $sentTo = "[\"reader@example.test\", \"archive@example.test\"]";
        $source = [
            'id' => 13,
            'invoice_id' => 7,
            'filename' => '20260915-101112-ab12cd34-invoice.pdf',
            'size_bytes' => 1234,
            'sha256' => str_repeat('a', 64),
            'was_sent' => 1,
            'sent_to' => $sentTo,
            'reason' => 'sent',
            'archived_at' => '2026-09-15 10:11:12',
        ];
        self::assertSame(CompanyBackupInvoicePdfsProjection::dataColumns(), array_keys($source));
        $references = CompanyBackupReferenceSet::fromArray(
            CompanyBackupInvoicePdfsProjection::references(), 'table:invoice_pdfs',
        );
        self::assertSame(['invoice_id->invoices:id'], array_map(
            static fn (CompanyBackupReference $reference): string => $reference->signature(),
            $references->references,
        ));
        $visited = [];
        $mapped = $references->remap($source,
            static function (CompanyBackupReference $reference, array $key) use (&$visited): array {
                $visited[] = $reference->firstColumn();
                self::assertSame([7], $key);
                return [107];
            });
        self::assertSame(['invoice_id'], $visited);
        self::assertSame(array_replace($source, ['invoice_id' => 107]), $mapped);
        self::assertSame($sentTo, $mapped['sent_to']);
        self::assertSame(CompanyBackupReferenceMapping::TenantId, $references->references[0]->mapping);
        self::assertSame(CompanyBackupReferenceConstraint::Required, $references->references[0]->constraint);
    }

    public function testAttachmentRemapsInvoiceAndActorWithExplicitChoices(): void
    {
        $source = [
            'id' => 19,
            'invoice_id' => 7,
            'filename' => 'ab12cd34-synthetic.pdf',
            'original_name' => 'synthetic source.pdf',
            'size_bytes' => 4321,
            'sha256' => str_repeat('b', 64),
            'mime_type' => 'application/pdf',
            'uploaded_by' => 31,
            'uploaded_at' => '2026-09-15 12:13:14',
        ];
        self::assertSame(CompanyBackupInvoiceAttachmentsProjection::dataColumns(), array_keys($source));
        $references = CompanyBackupReferenceSet::fromArray(
            CompanyBackupInvoiceAttachmentsProjection::references(), 'table:invoice_attachments',
        );
        self::assertSame(['invoice_id->invoices:id', 'uploaded_by->users:id'], array_map(
            static fn (CompanyBackupReference $reference): string => $reference->signature(),
            $references->references,
        ));
        $actor = $references->references[1];
        self::assertSame(CompanyBackupReferenceMapping::Actor, $actor->mapping);
        self::assertSame(CompanyBackupReferenceConstraint::Required, $actor->constraint);
        self::assertSame(['uploaded_by'], $actor->nullableColumns);
        self::assertSame(['null', 'restore_actor'], $actor->fallbacks);

        $mapped = $references->remap($source,
            static fn (CompanyBackupReference $reference, array $key): array =>
                $reference->firstColumn() === 'invoice_id' ? [107] : [91]);
        self::assertSame(array_replace($source, [
            'invoice_id' => 107, 'uploaded_by' => 91,
        ]), $mapped);
        $nullChoice = $references->remap($source,
            static fn (CompanyBackupReference $reference, array $key): ?array =>
                $reference->firstColumn() === 'invoice_id' ? [107] : null);
        self::assertSame(array_replace($source, [
            'invoice_id' => 107, 'uploaded_by' => null,
        ]), $nullChoice);
        self::assertSame('synthetic source.pdf', $mapped['original_name']);
        self::assertSame($source['sha256'], $mapped['sha256']);
    }

    public function testNullActorNeedsNoResolutionAndMetadataIsPreserved(): void
    {
        $source = [
            'id' => 19,
            'invoice_id' => 7,
            'filename' => 'ab12cd34-synthetic.pdf',
            'original_name' => 'synthetic source.pdf',
            'size_bytes' => 4321,
            'sha256' => str_repeat('b', 64),
            'mime_type' => 'application/pdf',
            'uploaded_by' => null,
            'uploaded_at' => '2026-09-15 12:13:14',
        ];
        $references = CompanyBackupReferenceSet::fromArray(
            CompanyBackupInvoiceAttachmentsProjection::references(), 'table:invoice_attachments',
        );
        $visited = [];
        $mapped = $references->remap($source,
            static function (CompanyBackupReference $reference, array $key) use (&$visited): array {
                $visited[] = $reference->firstColumn();
                self::assertSame([7], $key);
                return [107];
            });
        self::assertSame(['invoice_id'], $visited);
        self::assertSame(array_replace($source, ['invoice_id' => 107]), $mapped);
    }
}
