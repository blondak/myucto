<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Backup\Company;

use MyInvoice\Service\Backup\CanonicalJson;
use MyInvoice\Service\Backup\Company as Backup;
use MyInvoice\Service\Submission\SubmissionOutboxIdentity;
use PHPUnit\Framework\TestCase;

final class CompanyBackupSubmissionIdentityTest extends TestCase
{
    public function testResealsRemappedIdsUsingExactlyTheRuntimeKeyAndBinaryCodec(): void
    {
        foreach ([null, 19] as $recipient) {
            $source = $this->row($recipient);
            $legacy = 'submission-outbox.v1|7|test|isds|HOZ|document|31|'
                . str_repeat('a', 64) . '|' . ($recipient ?? 0);
            self::assertSame($legacy, Backup\CompanyBackupSubmissionIdentity::key($source));
            self::assertSame($legacy, SubmissionOutboxIdentity::key(7, 'test', 'isds', 'HOZ',
                'document', 31, str_repeat('a', 64), $recipient));
            $source['idempotency_key_hash'] = hash('sha256', $legacy);
            $set = $this->set();
            $set->assertSourceRow($source);
            $target = $set->transform($source, static fn (array $row): array => array_replace($row,
                ['supplier_id' => 107, 'artifact_id' => 131, 'recipient_id' => $recipient === null ? null : 119]));
            $runtime = SubmissionOutboxIdentity::key(107, 'test', 'isds', 'HOZ', 'document', 131,
                str_repeat('a', 64), $recipient === null ? null : 119);
            self::assertSame(hash('sha256', $runtime), $target['idempotency_key_hash']);
            self::assertNotSame($source['idempotency_key_hash'], $target['idempotency_key_hash']);
            self::assertSame(hash('sha256', $legacy), $source['idempotency_key_hash']);
            self::assertSame(hash('sha256', $runtime, true), Backup\CompanyBackupColumnCodec::BinaryHex->decode(
                $target['idempotency_key_hash'], 'table:submission_outbox', 'idempotency_key_hash'));
        }
    }

    public function testRejectsTamperedSourceHashBeforeRemap(): void
    {
        $source = $this->row(null) + ['idempotency_key_hash' => str_repeat('f', 64)];
        $this->expectException(Backup\CompanyBackupDataSourceException::class);
        $this->set()->transform($source, static function (array $row): array {
            self::fail('Poškozený zdroj nesmí dojít k transformaci.');
        });
    }

    public function testRejectsAmbiguousSeparatorInput(): void
    {
        $row = array_replace($this->row(null), ['agenda_code' => 'HOZ|document']);
        $this->expectException(Backup\CompanyBackupDataSourceException::class);
        Backup\CompanyBackupSubmissionIdentity::key($row);
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'zero supplier' => ['supplier_id', 0];
        yield 'string artifact' => ['artifact_id', '31'];
        yield 'zero recipient' => ['recipient_id', 0];
        yield 'boolean recipient' => ['recipient_id', true];
        yield 'environment separator' => ['environment', 'test|isds'];
        yield 'unknown channel' => ['channel', 'mail'];
        yield 'unknown artifact kind' => ['artifact_kind', 'invoice'];
        yield 'uppercase hash' => ['artifact_sha256', str_repeat('A', 64)];
        yield 'short hash' => ['artifact_sha256', 'abc'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFields')]
    public function testRejectsInvalidIdentityFields(string $column, mixed $value): void
    {
        $this->expectException(Backup\CompanyBackupDataSourceException::class);
        Backup\CompanyBackupSubmissionIdentity::key(array_replace($this->row(null), [$column => $value]));
    }

    public function testFixedAlgorithmCannotBeUsedForAnotherTableOrProjection(): void
    {
        foreach (['table:synthetic', 'table:submission_outbox'] as $registryKey) {
            $metadata = $this->metadata();
            if ($registryKey === 'table:submission_outbox') {
                $metadata['projection'][0]['column'] = 'subject';
            }
            try {
                Backup\CompanyBackupDerivedHash::fromArray($metadata, $registryKey);
                self::fail('Algoritmus nesmí dovolit odlišný kontrakt.');
            } catch (Backup\CompanyBackupDataSourceException $e) {
                self::assertSame('data_derived_hash_metadata_invalid', $e->errorCode);
            }
        }
    }

    /** @return array<string,mixed> */
    private function row(?int $recipient): array
    {
        return ['supplier_id' => 7, 'environment' => 'test', 'channel' => 'isds', 'agenda_code' => 'HOZ',
            'artifact_kind' => 'document', 'artifact_id' => 31, 'artifact_sha256' => str_repeat('a', 64),
            'recipient_id' => $recipient];
    }

    /** @return array{algorithm:string,hash_column:string,nullable:bool,projection:list<array{key:string,column:string}>} */
    private function metadata(): array
    {
        return ['algorithm' => 'sha256_submission_outbox_v1', 'hash_column' => 'idempotency_key_hash',
            'nullable' => false, 'projection' => Backup\CompanyBackupSubmissionIdentity::projection()];
    }

    private function set(): Backup\CompanyBackupDerivedHashSet
    {
        // Stejná kanonizace pořadí klíčů jako při načtení manifestu z archivu.
        $metadata = json_decode(CanonicalJson::encode([$this->metadata()]), true, flags: JSON_THROW_ON_ERROR);
        return Backup\CompanyBackupDerivedHashSet::fromArray($metadata, 'table:submission_outbox',
            [...array_keys($this->row(null)), 'idempotency_key_hash']);
    }
}
