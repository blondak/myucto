<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionException;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Dávkové převzetí originálů do staging fronty — sdílené portálem i účetní frontou.
 *
 * Obě strany nahrávají tytéž bajty do téhož workflow a musí se chovat stejně:
 * stejný strop dávky, stejná částečná úspěšnost (platné soubory nezmizí kvůli
 * jednomu odmítnutému) a stejný tvar chyby na soubor. Kdyby si to každá akce
 * držela sama, rozejde se to při první úpravě jedné z nich.
 *
 * Vyžaduje na akci `$this->upload` (PurchaseInvoiceSubmissionUploadService).
 */
trait SubmitsDocumentOriginals
{
    /** Dávka běží synchronně v jednom requestu, proto strop. */
    private const MAX_ORIGINALS_PER_BATCH = 20;

    /** @return list<UploadedFileInterface> */
    private function uploadedOriginals(Request $request): array
    {
        $uploads = $request->getUploadedFiles();
        $raw = $uploads['file'] ?? $uploads['files'] ?? null;
        $list = is_array($raw) ? array_values($raw) : ($raw instanceof UploadedFileInterface ? [$raw] : []);
        return array_values(array_filter($list, static fn(mixed $v): bool => $v instanceof UploadedFileInterface));
    }

    /**
     * @param list<UploadedFileInterface> $files
     * @return array{
     *   items:list<array{submission:array<string,mixed>,duplicate:bool}>,
     *   errors:list<array{filename:string,code:string,message:string}>,
     *   first_error:?PurchaseInvoiceSubmissionException
     * }
     */
    private function submitOriginals(
        array $files,
        int $supplierId,
        ?int $userId,
        string $via,
        ?string $note = null,
        ?string $kindHint = null,
        ?int $supersedesSubmissionId = null,
    ): array {
        $items = [];
        $errors = [];
        $firstError = null;
        foreach ($files as $file) {
            try {
                $items[] = $this->upload->submit(
                    $file,
                    $supplierId,
                    $userId,
                    $via,
                    $note,
                    $kindHint,
                    null,
                    $supersedesSubmissionId,
                );
            } catch (PurchaseInvoiceSubmissionException $e) {
                $firstError ??= $e;
                $errors[] = [
                    'filename' => basename(str_replace('\\', '/', (string) $file->getClientFilename())),
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                ];
            }
        }
        return ['items' => $items, 'errors' => $errors, 'first_error' => $firstError];
    }

    /**
     * HTTP status dávky: 207 když část souborů neprošla, 200 když nic nového
     * nevzniklo (všechno byly duplicity), jinak 201.
     *
     * @param list<array{submission:array<string,mixed>,duplicate:bool}> $items
     * @param list<array{filename:string,code:string,message:string}> $errors
     */
    private function batchStatus(array $items, array $errors, int $duplicates): int
    {
        if ($errors !== []) return 207;
        return $duplicates === count($items) ? 200 : 201;
    }

    /** @param list<array{submission:array<string,mixed>,duplicate:bool}> $items */
    private function duplicateCount(array $items): int
    {
        return count(array_filter($items, static fn(array $item): bool => !empty($item['duplicate'])));
    }
}
