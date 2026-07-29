<?php

declare(strict_types=1);

namespace MyInvoice\Service\Epo;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\TaxSubmissionEpoRepository;
use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\DocumentIngestService;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Report\TaxSubmissionFilename;

final class TaxSubmissionDocumentService
{
    private const INCOME_FORMS = ['dpfdp5', 'dpfdp7', 'dppdp9'];

    public function __construct(
        private readonly Connection $db,
        private readonly TaxSubmissionEpoRepository $epo,
        private readonly DocumentFolderRepository $folders,
        private readonly DocumentIngestService $ingest,
        private readonly DocumentStorage $storage,
        private readonly EpoConfirmationParser $confirmationParser,
    ) {}

    /**
     * @param array<string,mixed> $submission
     * @return array<string,mixed>
     */
    public function ensureSourceXml(
        array $submission,
        int $supplierId,
        ?int $attemptId,
        ?int $userId,
    ): array {
        $existing = $this->epo->sourceArtifact((int) $submission['id'], $supplierId);
        if ($existing !== null) {
            return $existing;
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            if ($this->epo->lockSubmission((int) $submission['id'], $supplierId) === null) {
                throw new EpoSubmissionException('not_found', 'Podání nebylo nalezeno.', 404);
            }
            $existing = $this->epo->sourceArtifact((int) $submission['id'], $supplierId);
            if ($existing !== null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $existing;
            }

            $xml = (string) $submission['xml_content'];
            $sha256 = hash('sha256', $xml);
            if (!hash_equals((string) $submission['xml_sha256'], $sha256)) {
                throw new EpoSubmissionException(
                    'snapshot_changed',
                    'Archivovaný XML snapshot neodpovídá uloženému otisku.',
                    409,
                );
            }
            $folderId = $this->ensureSubmissionFolder($submission, $supplierId, $userId);
            $filename = $this->sourceFilename($submission);
            $tmp = $this->storage->tmpPath($supplierId);
            if (file_put_contents($tmp, $xml) === false) {
                @unlink($tmp);
                throw new EpoSubmissionException(
                    'archive_failed',
                    'Nepodařilo se uložit zdrojové XML do Dokumentů.',
                    500,
                );
            }

            $result = $this->ingest->ingestUploadedTemp(
                $tmp,
                $supplierId,
                $folderId,
                $filename,
                $userId,
            );
            $documentId = (int) ($result['created_ids'][0] ?? 0);
            if ($documentId <= 0) {
                throw new EpoSubmissionException(
                    'archive_failed',
                    'Nepodařilo se vytvořit dokument zdrojového XML.',
                    500,
                );
            }

            $this->epo->addArtifact(
                $supplierId,
                (int) $submission['id'],
                $attemptId,
                $documentId,
                'source_xml',
                $sha256,
                'valid',
                ['snapshot_sha256_match' => true],
                $userId,
            );

            $artifact = $this->epo->artifactByKindAndSha(
                (int) $submission['id'],
                $supplierId,
                'source_xml',
                $sha256,
            ) ?? ['document_id' => $documentId, 'folder_id' => $folderId];
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $artifact;
        } catch (EpoSubmissionException $e) {
            if (isset($tmp)) {
                @unlink($tmp);
            }
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable) {
            if (isset($tmp)) {
                @unlink($tmp);
            }
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new EpoSubmissionException(
                'archive_failed',
                'Nepodařilo se uložit zdrojové XML do Dokumentů.',
                500,
            );
        }
    }

    /**
     * @param array<string,mixed> $submission
     * @return array{artifact:array<string,mixed>}
     */
    public function ingestArtifact(
        string $tmpPath,
        string $originalName,
        array $submission,
        int $supplierId,
        ?int $attemptId,
        ?int $userId,
    ): array {
        $kind = $this->artifactKind($originalName);
        $sha256 = hash_file('sha256', $tmpPath);
        if ($sha256 === false) {
            throw new EpoSubmissionException('hash_failed', 'Nepodařilo se ověřit soubor.', 500);
        }
        $existing = $this->epo->artifactByKindAndSha(
            (int) $submission['id'],
            $supplierId,
            $kind,
            $sha256,
        );
        if ($existing !== null) {
            @unlink($tmpPath);
            return ['artifact' => $existing];
        }

        $verification = null;
        $verificationStatus = 'not_applicable';
        if ($kind === 'confirmation_p7s') {
            $verification = $this->confirmationParser->parse(
                $tmpPath,
                (string) $submission['xml_content'],
                (string) $submission['form_code'],
            );
            $verificationStatus = $this->verificationStatus($verification);
        } elseif ($kind === 'epo_xml') {
            $verification = [
                'snapshot_sha256_match' => hash_equals((string) $submission['xml_sha256'], $sha256),
            ];
            $verificationStatus = $verification['snapshot_sha256_match'] ? 'valid' : 'warning';
        }

        $folderId = $this->ensureSubmissionFolder($submission, $supplierId, $userId);
        try {
            $result = $this->ingest->ingestUploadedTemp(
                $tmpPath,
                $supplierId,
                $folderId,
                $originalName,
                $userId,
            );
        } catch (\Throwable) {
            @unlink($tmpPath);
            throw new EpoSubmissionException(
                'artifact_store_failed',
                'Soubor se nepodařilo uložit do Dokumentů.',
                500,
            );
        }
        $documentId = (int) ($result['created_ids'][0] ?? 0);
        if ($documentId <= 0) {
            throw new EpoSubmissionException(
                'artifact_store_failed',
                'Soubor se nepodařilo uložit do Dokumentů.',
                500,
            );
        }

        $this->epo->addArtifact(
            $supplierId,
            (int) $submission['id'],
            $attemptId,
            $documentId,
            $kind,
            $sha256,
            $verificationStatus,
            $verification,
            $userId,
        );

        $artifact = $this->epo->artifactByKindAndSha(
            (int) $submission['id'],
            $supplierId,
            $kind,
            $sha256,
        ) ?? ['document_id' => $documentId, 'folder_id' => $folderId];

        return ['artifact' => $artifact];
    }

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed>|null $verification
     * @return array<string,mixed>
     */
    public function storeGeneratedArtifact(
        string $bytes,
        string $filename,
        string $kind,
        array $submission,
        int $supplierId,
        int $attemptId,
        ?int $userId,
        string $verificationStatus = 'not_applicable',
        ?array $verification = null,
    ): array {
        if ($bytes === '') {
            throw new EpoSubmissionException('empty_file', 'Generovaný soubor je prázdný.', 500);
        }
        $sha256 = hash('sha256', $bytes);
        $existing = $this->epo->artifactByKindAndSha(
            (int) $submission['id'],
            $supplierId,
            $kind,
            $sha256,
        );
        if ($existing !== null) {
            return $existing;
        }

        $tmp = $this->storage->tmpPath($supplierId);
        if (file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            throw new EpoSubmissionException(
                'artifact_store_failed',
                'Generovaný soubor se nepodařilo uložit.',
                500,
            );
        }
        $folderId = $this->ensureSubmissionFolder($submission, $supplierId, $userId);
        try {
            $result = $this->ingest->ingestUploadedTemp(
                $tmp,
                $supplierId,
                $folderId,
                $filename,
                $userId,
            );
        } catch (\Throwable) {
            @unlink($tmp);
            throw new EpoSubmissionException(
                'artifact_store_failed',
                'Generovaný soubor se nepodařilo uložit do Dokumentů.',
                500,
            );
        }
        $documentId = (int) ($result['created_ids'][0] ?? 0);
        if ($documentId <= 0) {
            throw new EpoSubmissionException(
                'artifact_store_failed',
                'Generovaný soubor se nepodařilo uložit do Dokumentů.',
                500,
            );
        }
        $this->epo->addArtifact(
            $supplierId,
            (int) $submission['id'],
            $attemptId,
            $documentId,
            $kind,
            $sha256,
            $verificationStatus,
            $verification,
            $userId,
        );
        return $this->epo->artifactByKindAndSha(
            (int) $submission['id'],
            $supplierId,
            $kind,
            $sha256,
        ) ?? ['document_id' => $documentId, 'folder_id' => $folderId];
    }

    public function validateArtifactFile(string $tmpPath, string $originalName): void
    {
        $this->artifactKind($originalName);
        $size = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;
        if ($size <= 0) {
            throw new EpoSubmissionException('empty_file', 'Soubor je prázdný.', 400);
        }
        if ($size > $this->storage->maxFileBytes()) {
            throw new EpoSubmissionException('file_too_large', 'Soubor je příliš velký.', 413);
        }

        try {
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $this->storage->classify($extension, $this->storage->detectMime($tmpPath));
        } catch (DocumentException $e) {
            throw new EpoSubmissionException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    /** @param array<string,mixed> $submission */
    public function ensureSubmissionFolder(array $submission, int $supplierId, ?int $userId): ?int
    {
        $settings = $this->epo->settings($supplierId);
        $income = in_array((string) $submission['form_code'], self::INCOME_FORMS, true);
        $root = $income
            ? $settings['income_tax_root_folder_id']
            : $settings['vat_root_folder_id'];
        if ($root !== null && $this->folders->find($root, $supplierId) === null) {
            $root = null;
        }

        $segments = [];
        if ($root === null) {
            $segments[] = $income ? 'Daň z příjmů' : 'DPH a hlášení';
        }
        $segments[] = (string) $submission['period_year'];
        if ($submission['period_month'] !== null) {
            $segments[] = sprintf('%02d', (int) $submission['period_month']);
        } elseif ($submission['period_quarter'] !== null) {
            $segments[] = 'Q' . (int) $submission['period_quarter'];
        }
        $segments[] = $this->formFolder((string) $submission['form_code']);

        return $this->ingest->ensureFolderPath($supplierId, $root, $segments, $userId);
    }

    private function artifactKind(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'p7s', 'p7m' => 'confirmation_p7s',
            'pdf' => 'receipt_pdf',
            'xml' => 'epo_xml',
            default => throw new EpoSubmissionException(
                'unsupported_artifact',
                'Lze nahrát pouze XML, PDF nebo potvrzení P7S.',
                415,
            ),
        };
    }

    /** @param array<string,mixed>|null $verification */
    private function verificationStatus(?array $verification): string
    {
        if (
            $verification === null
            || !($verification['signature_valid'] ?? false)
            || !($verification['is_confirmation'] ?? false)
            || ($verification['form_match'] ?? null) === false
            || ($verification['content_match'] ?? null) === false
        ) {
            return 'invalid';
        }
        return ($verification['chain_valid'] ?? false)
            && ($verification['epo_signer_valid'] ?? false)
            && ($verification['content_match'] ?? null) === true
                ? 'valid'
                : 'warning';
    }

    /** @param array<string,mixed> $submission */
    private function sourceFilename(array $submission): string
    {
        return TaxSubmissionFilename::forSnapshot($submission, 'source.xml');
    }

    private function formFolder(string $formCode): string
    {
        return match ($formCode) {
            'dphdp3' => 'DPH',
            'dphkh1' => 'Kontrolní hlášení',
            'dphshv' => 'Souhrnné hlášení',
            'dpfdp5', 'dpfdp7' => 'DPFO',
            'dppdp9' => 'DPPO',
            'ossei1' => 'OSS',
            default => strtoupper($formCode),
        };
    }
}
