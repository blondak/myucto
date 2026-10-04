<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Personnel;

use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPersonnelFileRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyDestroyedException;

/**
 * Personální spis zaměstnance: nahrané dokumenty a poznámky.
 *
 * Přijímá jen typy, které se do spisu reálně dávají (PDF, kancelářské
 * dokumenty, sken, podepsaný kontejner) — whitelist, ne blocklist jako
 * obecná sekce Dokumenty. Náhled v prohlížeči se nabízí jen u PDF a rastrových
 * obrázků, a to jen když obsah opravdu odpovídá příponě.
 */
final class PayrollPersonnelFileService
{
    public const MAX_FILE_BYTES = 25 * 1024 * 1024;

    private const NOTE_MAX_CHARS = 20000;

    /** Přípona → MIME, pod kterým se soubor vydává. */
    private const ALLOWED = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'rtf' => 'application/rtf',
        'txt' => 'text/plain',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'p7s' => 'application/pkcs7-signature',
        'p7m' => 'application/pkcs7-mime',
        'asice' => 'application/vnd.etsi.asic-e+zip',
        'zfo' => 'application/octet-stream',
        'eml' => 'message/rfc822',
        'msg' => 'application/vnd.ms-outlook',
    ];

    /** Obsah, který se nepřijme, ať přípona tvrdí cokoli. */
    private const DANGEROUS_MIME = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/x-dosexec', 'application/x-msdownload', 'application/x-executable',
        'application/x-mach-binary', 'application/x-elf', 'application/x-sh',
        'application/x-shellscript', 'application/javascript', 'text/javascript',
        'application/x-php', 'text/x-php', 'application/x-httpd-php',
        'application/vnd.microsoft.portable-executable',
    ];

    /** Co prohlížeč smí zobrazit v rámu — a jaký obsah to musí skutečně být. */
    private const PREVIEWABLE = [
        'application/pdf' => 'application/pdf',
        'image/jpeg' => 'image/jpeg',
        'image/png' => 'image/png',
        'image/webp' => 'image/webp',
    ];

    public function __construct(
        private readonly PayrollPersonnelFileRepository $repository,
        private readonly PayrollPersonnelFileStorage $storage,
        private readonly PayrollPersonnelNoteCipher $noteCipher,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * @return array{employee_id:int,documents:list<array<string,mixed>>,notes:list<array<string,mixed>>,max_file_bytes:int,allowed_extensions:list<string>}
     */
    public function overview(int $supplierId, int $employeeId): array
    {
        $this->assertEmployee($supplierId, $employeeId);

        $documents = array_map(
            fn (array $document): array => $this->presentDocument($document),
            $this->repository->listDocuments($supplierId, $employeeId),
        );

        $notes = [];
        foreach ($this->repository->listNotes($supplierId, $employeeId) as $row) {
            try {
                $body = $this->noteCipher->decrypt($supplierId, $employeeId, (string) $row['body_ciphertext']);
                $erased = false;
            } catch (PayrollDocumentKeyDestroyedException) {
                $body = null;
                $erased = true;
            }
            $notes[] = [
                'id' => (int) $row['id'],
                'body' => $body,
                'erased' => $erased,
                'pinned' => (int) $row['pinned'] === 1,
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
                'created_by_name' => $row['created_by_name'] !== null ? (string) $row['created_by_name'] : null,
                'updated_by_name' => $row['updated_by'] !== null && $row['updated_by_name'] !== null
                    ? (string) $row['updated_by_name']
                    : null,
            ];
        }

        return [
            'employee_id' => $employeeId,
            'documents' => $documents,
            'notes' => $notes,
            'max_file_bytes' => self::MAX_FILE_BYTES,
            'allowed_extensions' => array_keys(self::ALLOWED),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function upload(
        int $supplierId,
        int $employeeId,
        string $tmpPath,
        string $originalName,
        array $input,
        ?int $userId,
        ?string $ip,
        string $userAgent,
    ): array {
        $this->assertEmployee($supplierId, $employeeId);

        $originalName = self::cleanFileName($originalName);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            throw new PayrollPersonnelFileException(
                'unsupported_type',
                'Tento typ souboru do personálního spisu nahrát nelze. Povolené jsou: '
                    . implode(', ', array_keys(self::ALLOWED)) . '.',
                415,
            );
        }
        $size = (int) @filesize($tmpPath);
        if ($size <= 0) {
            throw new PayrollPersonnelFileException('empty_file', 'Soubor je prázdný.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new PayrollPersonnelFileException(
                'file_too_large',
                'Soubor je větší než ' . (int) (self::MAX_FILE_BYTES / 1024 / 1024) . ' MB.',
                413,
            );
        }
        $detected = self::detectMime($tmpPath);
        if (in_array($detected, self::DANGEROUS_MIME, true)) {
            throw new PayrollPersonnelFileException(
                'unsupported_type',
                'Obsah souboru neodpovídá povolenému typu dokumentu.',
                415,
            );
        }

        $meta = $this->validateMeta($input, pathinfo($originalName, PATHINFO_FILENAME));
        $bytes = (string) file_get_contents($tmpPath);
        $sha = hash('sha256', $bytes);
        $existing = $this->repository->findDocumentIdBySha($supplierId, $employeeId, $sha);
        if ($existing !== null) {
            throw new PayrollPersonnelFileException(
                'duplicate',
                'Stejný soubor už v personálním spisu tohoto zaměstnance je.',
                409,
            );
        }

        $stored = $this->storage->store($supplierId, $employeeId, $bytes, $userId);
        try {
            $id = $this->repository->insertDocument($supplierId, $employeeId, $meta, [
                'original_name' => $originalName,
                'mime_type' => self::ALLOWED[$ext],
                'size_bytes' => $stored['size_bytes'],
                'file_sha256' => $stored['file_sha256'],
            ], $userId);
        } catch (\Throwable $exception) {
            $this->storage->delete($supplierId, $employeeId, $stored['file_sha256']);
            throw $exception;
        }

        $this->activityLogger->log(
            'payroll.personnel.document_uploaded',
            $userId,
            'payroll_employee',
            $employeeId,
            ['document_id' => $id, 'category' => $meta['category'], 'sha256' => $stored['file_sha256']],
            $ip,
            $userAgent,
            $supplierId,
        );

        return $this->presentDocument($this->requireDocument($supplierId, $employeeId, $id));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateDocument(
        int $supplierId,
        int $employeeId,
        int $documentId,
        array $input,
        ?int $userId,
        ?string $ip,
        string $userAgent,
    ): array {
        $this->assertEmployee($supplierId, $employeeId);
        $current = $this->requireDocument($supplierId, $employeeId, $documentId);
        $meta = $this->validateMeta($input, (string) $current['title']);
        $this->repository->updateDocument($supplierId, $employeeId, $documentId, $meta, $userId);
        $this->activityLogger->log(
            'payroll.personnel.document_updated',
            $userId,
            'payroll_employee',
            $employeeId,
            ['document_id' => $documentId, 'category' => $meta['category']],
            $ip,
            $userAgent,
            $supplierId,
        );

        return $this->presentDocument($this->requireDocument($supplierId, $employeeId, $documentId));
    }

    public function deleteDocument(
        int $supplierId,
        int $employeeId,
        int $documentId,
        ?int $userId,
        ?string $ip,
        string $userAgent,
    ): void {
        $this->assertEmployee($supplierId, $employeeId);
        $document = $this->requireDocument($supplierId, $employeeId, $documentId);
        $this->repository->deleteDocument($supplierId, $employeeId, $documentId);
        // Otisk je v rámci osoby unikátní, takže na soubor už nic jiného neukazuje.
        $this->storage->delete($supplierId, $employeeId, (string) $document['file_sha256']);
        $this->activityLogger->log(
            'payroll.personnel.document_deleted',
            $userId,
            'payroll_employee',
            $employeeId,
            [
                'document_id' => $documentId,
                'category' => $document['category'],
                'sha256' => $document['file_sha256'],
            ],
            $ip,
            $userAgent,
            $supplierId,
        );
    }

    /**
     * Obsah dokumentu pro náhled nebo stažení.
     *
     * Náhled (`inline`) se povolí jen u PDF a obrázků, jejichž obsah
     * odpovídá příponě; cokoli jiného se vždy vydá jako příloha.
     *
     * @return array{bytes:string,file_name:string,content_type:string,inline:bool}
     */
    public function content(
        int $supplierId,
        int $employeeId,
        int $documentId,
        bool $inline,
        ?int $userId,
        ?string $ip,
        string $userAgent,
    ): array {
        $this->assertEmployee($supplierId, $employeeId);
        $document = $this->requireDocument($supplierId, $employeeId, $documentId);
        try {
            $bytes = $this->storage->read($supplierId, $employeeId, (string) $document['file_sha256']);
        } catch (PayrollDocumentKeyDestroyedException) {
            throw new PayrollPersonnelFileException(
                'erased',
                'Dokument je po výmazu osobních údajů nečitelný.',
                410,
            );
        } catch (\RuntimeException) {
            throw new PayrollPersonnelFileException(
                'file_missing',
                'Soubor dokumentu se nepodařilo načíst z úložiště.',
                404,
            );
        }

        $mime = (string) $document['mime_type'];
        $previewable = $inline && $this->isPreviewable($mime, $bytes);
        $this->activityLogger->log(
            $previewable ? 'payroll.personnel.document_viewed' : 'payroll.personnel.document_downloaded',
            $userId,
            'payroll_employee',
            $employeeId,
            ['document_id' => $documentId],
            $ip,
            $userAgent,
            $supplierId,
        );

        return [
            'bytes' => $bytes,
            'file_name' => (string) $document['original_name'],
            'content_type' => $previewable ? $mime : 'application/octet-stream',
            'inline' => $previewable,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createNote(
        int $supplierId,
        int $employeeId,
        array $input,
        ?int $userId,
        ?string $ip,
        string $userAgent,
    ): array {
        $this->assertEmployee($supplierId, $employeeId);
        [$body, $pinned] = $this->validateNote($input);
        $id = $this->repository->insertNote(
            $supplierId,
            $employeeId,
            $this->encryptNote($supplierId, $employeeId, $body, $userId),
            $pinned,
            $userId,
        );
        $this->activityLogger->log(
            'payroll.personnel.note_created',
            $userId,
            'payroll_employee',
            $employeeId,
            ['note_id' => $id],
            $ip,
            $userAgent,
            $supplierId,
        );

        return $this->overview($supplierId, $employeeId);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateNote(
        int $supplierId,
        int $employeeId,
        int $noteId,
        array $input,
        ?int $userId,
        ?string $ip,
        string $userAgent,
    ): array {
        $this->assertEmployee($supplierId, $employeeId);
        $this->requireNote($supplierId, $employeeId, $noteId);
        [$body, $pinned] = $this->validateNote($input);
        $this->repository->updateNote(
            $supplierId,
            $employeeId,
            $noteId,
            $this->encryptNote($supplierId, $employeeId, $body, $userId),
            $pinned,
            $userId,
        );
        $this->activityLogger->log(
            'payroll.personnel.note_updated',
            $userId,
            'payroll_employee',
            $employeeId,
            ['note_id' => $noteId],
            $ip,
            $userAgent,
            $supplierId,
        );

        return $this->overview($supplierId, $employeeId);
    }

    /** @return array<string,mixed> */
    public function deleteNote(
        int $supplierId,
        int $employeeId,
        int $noteId,
        ?int $userId,
        ?string $ip,
        string $userAgent,
    ): array {
        $this->assertEmployee($supplierId, $employeeId);
        $this->requireNote($supplierId, $employeeId, $noteId);
        $this->repository->deleteNote($supplierId, $employeeId, $noteId);
        $this->activityLogger->log(
            'payroll.personnel.note_deleted',
            $userId,
            'payroll_employee',
            $employeeId,
            ['note_id' => $noteId],
            $ip,
            $userAgent,
            $supplierId,
        );

        return $this->overview($supplierId, $employeeId);
    }

    /**
     * Výmaz osobních údajů: odstraní všechny dokumenty i poznámky osoby
     * a soubory z úložiště. Volá ho jen výkon schváleného výmazu.
     *
     * @return array{personnel_documents_purged:int}
     */
    public function purge(
        int $supplierId,
        int $employeeId,
        ?int $userId,
        ?string $ip,
        string $userAgent,
    ): array {
        $hashes = $this->repository->purge($supplierId, $employeeId);
        foreach ($hashes as $sha) {
            $this->storage->delete($supplierId, $employeeId, $sha);
        }
        if ($hashes !== []) {
            $this->activityLogger->log(
                'payroll.personnel.purged',
                $userId,
                'payroll_employee',
                $employeeId,
                ['documents' => count($hashes)],
                $ip,
                $userAgent,
                $supplierId,
            );
        }

        return ['personnel_documents_purged' => count($hashes)];
    }

    private function assertEmployee(int $supplierId, int $employeeId): void
    {
        if (!$this->repository->employeeExists($supplierId, $employeeId)) {
            throw new PayrollPersonNotFoundException();
        }
    }

    /** @return array<string,mixed> */
    private function requireDocument(int $supplierId, int $employeeId, int $documentId): array
    {
        return $this->repository->findDocument($supplierId, $employeeId, $documentId)
            ?? throw new PayrollPersonnelFileException('not_found', 'Dokument nenalezen.', 404);
    }

    private function requireNote(int $supplierId, int $employeeId, int $noteId): void
    {
        if (!$this->repository->noteExists($supplierId, $employeeId, $noteId)) {
            throw new PayrollPersonnelFileException('not_found', 'Poznámka nenalezena.', 404);
        }
    }

    private function encryptNote(int $supplierId, int $employeeId, string $body, ?int $userId): string
    {
        try {
            return $this->noteCipher->encrypt($supplierId, $employeeId, $body, $userId);
        } catch (PayrollDocumentKeyDestroyedException) {
            throw new PayrollPersonnelFileException(
                'erased',
                'Osobní údaje zaměstnance byly vymazány, do spisu už nelze zapisovat.',
                409,
            );
        }
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function presentDocument(array $document): array
    {
        $document['previewable'] = isset(self::PREVIEWABLE[(string) $document['mime_type']]);
        unset($document['file_sha256']);

        return $document;
    }

    private function isPreviewable(string $mime, string $bytes): bool
    {
        $expected = self::PREVIEWABLE[$mime] ?? null;
        if ($expected === null) {
            return false;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return $finfo->buffer($bytes) === $expected;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{category:string,title:string,document_date:?string,valid_until:?string,note:?string}
     */
    private function validateMeta(array $input, string $fallbackTitle): array
    {
        $category = $input['category'] ?? 'other';
        if (!is_string($category) || !in_array($category, PayrollPersonnelFileRepository::CATEGORIES, true)) {
            throw new PayrollPersonnelFileException('validation_failed', 'Neznámý druh dokumentu.');
        }
        $title = is_string($input['title'] ?? null) ? trim((string) $input['title']) : '';
        if ($title === '') {
            $title = trim($fallbackTitle) !== '' ? trim($fallbackTitle) : 'Dokument';
        }
        $title = mb_substr($title, 0, 255);
        $documentDate = self::optionalDate($input['document_date'] ?? null, 'Datum dokumentu');
        $validUntil = self::optionalDate($input['valid_until'] ?? null, 'Platnost do');
        if ($documentDate !== null && $validUntil !== null && $validUntil < $documentDate) {
            throw new PayrollPersonnelFileException(
                'validation_failed',
                'Platnost do nemůže být dřív než datum dokumentu.',
            );
        }
        $note = is_string($input['note'] ?? null) ? trim((string) $input['note']) : '';

        return [
            'category' => $category,
            'title' => $title,
            'document_date' => $documentDate,
            'valid_until' => $validUntil,
            'note' => $note === '' ? null : mb_substr($note, 0, 1000),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:string,1:bool}
     */
    private function validateNote(array $input): array
    {
        $body = is_string($input['body'] ?? null) ? trim((string) $input['body']) : '';
        if ($body === '') {
            throw new PayrollPersonnelFileException('validation_failed', 'Poznámka nesmí být prázdná.');
        }
        if (mb_strlen($body) > self::NOTE_MAX_CHARS) {
            throw new PayrollPersonnelFileException(
                'validation_failed',
                'Poznámka je delší než ' . self::NOTE_MAX_CHARS . ' znaků.',
            );
        }
        $pinned = $input['pinned'] ?? false;

        return [$body, $pinned === true || $pinned === 1 || $pinned === '1' || $pinned === 'true'];
    }

    private static function optionalDate(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new PayrollPersonnelFileException('validation_failed', $label . ' musí být datum RRRR-MM-DD.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new PayrollPersonnelFileException('validation_failed', $label . ' není platné datum.');
        }

        return $value;
    }

    private static function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? strtolower($mime) : 'application/octet-stream';
    }

    private static function cleanFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new PayrollPersonnelFileException('validation_failed', 'Soubor nemá platný název.');
        }

        return mb_substr($name, 0, 255);
    }
}
