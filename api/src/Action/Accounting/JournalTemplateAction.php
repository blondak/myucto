<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Repository\JournalEntryTemplateRepository;
use MyInvoice\Service\Accounting\TemplateCsvMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Šablony ručních zápisů (Fáze F, audit 2026-07 — mzdy a leasing se každý měsíc
 * vyklikávaly ručně znovu). Čistě datové CRUD nad journal_entry_templates(_lines);
 * vytvoření zápisu ze šablony jde vždy přes POST /accounting/journal — šablona jen
 * FE (ManualEntry.vue) předvyplní řádky, PostingService zůstává jediné místo, které
 * cokoli zaúčtovává.
 *
 *   GET    /api/accounting/journal-templates                — seznam (+ lazy seed „Mzdy" a předuzávěrkových šablon, Task 34)
 *   POST   /api/accounting/journal-templates                 — uložit rozepsaný zápis jako šablonu
 *   GET    /api/accounting/journal-templates/{id}             — detail s řádky
 *   PUT    /api/accounting/journal-templates/{id}             — upravit šablonu a její řádky
 *   DELETE /api/accounting/journal-templates/{id}             — smazat
 *   POST   /api/accounting/journal-templates/{id}/import-csv  — napárovat CSV rekapitulaci na řádky šablony
 */
final class JournalTemplateAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_CSV_BYTES = 512 * 1024;
    private const MAX_LINES = 100;

    public function __construct(
        private readonly JournalEntryTemplateRepository $templates,
        private readonly TemplateCsvMatcher $matcher,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        if (!DemoReadOnlyMiddleware::enabled($request)) {
            $this->templates->ensurePayrollSeed($supplierId);
            $this->templates->ensureClosingTemplatesSeed($supplierId);
        }
        return Json::ok($response, ['items' => $this->templates->listForSupplier($supplierId)]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $template = $this->templates->find($supplierId, $id);
        if ($template === null) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        return Json::ok($response, $template);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            return Json::error($response, 'validation_failed', 'Název šablony musí mít 1–255 znaků.', 422);
        }
        $description = $this->nullableString($body['description'] ?? null);
        if ($description !== null && mb_strlen($description) > 255) {
            return Json::error($response, 'validation_failed', 'Popis smí mít nejvýše 255 znaků.', 422);
        }

        $rawLines = $body['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            return Json::error($response, 'validation_failed', 'Šablona musí mít alespoň jeden řádek.', 422);
        }
        if (count($rawLines) > self::MAX_LINES) {
            return Json::error($response, 'validation_failed', 'Šablona smí mít nejvýše ' . self::MAX_LINES . ' řádků.', 422);
        }

        $lines = [];
        foreach ($rawLines as $i => $l) {
            if (!is_array($l)) {
                return Json::error($response, 'validation_failed', "Řádek #{$i} má neplatný formát.", 422);
            }
            $accountCode = trim((string) ($l['account_code'] ?? ''));
            if ($accountCode === '' || mb_strlen($accountCode) > 20) {
                return Json::error($response, 'validation_failed', "Řádek #{$i}: chybí kód účtu.", 422);
            }
            $side = (string) ($l['side'] ?? '');
            if ($side !== 'debit' && $side !== 'credit') {
                return Json::error($response, 'validation_failed', "Řádek #{$i}: side musí být 'debit' nebo 'credit'.", 422);
            }
            $amount = null;
            if (array_key_exists('amount', $l) && $l['amount'] !== null && $l['amount'] !== '') {
                if (!is_numeric($l['amount']) || (float) $l['amount'] < 0) {
                    return Json::error($response, 'validation_failed', "Řádek #{$i}: částka musí být nezáporné číslo.", 422);
                }
                $amount = round((float) $l['amount'], 2);
            }
            $lines[] = [
                'account_code' => $accountCode,
                'side'         => $side,
                'amount'       => $amount,
                'label'        => $this->nullableString($l['label'] ?? null),
                'cost_center'  => $this->nullableString($l['cost_center'] ?? null),
            ];
        }

        $id = $this->templates->create($supplierId, $name, $description, $this->userId($request), $lines);
        return Json::ok($response, $this->templates->find($supplierId, $id), 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        if (!$this->templates->delete($supplierId, $id)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        return Json::ok($response, ['ok' => true]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $body = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            return Json::error($response, 'validation_failed', 'Název šablony musí mít 1–255 znaků.', 422);
        }
        $description = $this->nullableString($body['description'] ?? null);
        if ($description !== null && mb_strlen($description) > 255) {
            return Json::error($response, 'validation_failed', 'Popis smí mít nejvýše 255 znaků.', 422);
        }

        $rawLines = $body['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            return Json::error($response, 'validation_failed', 'Šablona musí mít alespoň jeden řádek.', 422);
        }
        if (count($rawLines) > self::MAX_LINES) {
            return Json::error($response, 'validation_failed', 'Šablona smí mít nejvýše ' . self::MAX_LINES . ' řádků.', 422);
        }

        $lines = [];
        foreach ($rawLines as $i => $l) {
            if (!is_array($l)) {
                return Json::error($response, 'validation_failed', "Řádek #{$i} má neplatný formát.", 422);
            }
            $accountCode = trim((string) ($l['account_code'] ?? ''));
            if ($accountCode === '' || mb_strlen($accountCode) > 20) {
                return Json::error($response, 'validation_failed', "Řádek #{$i}: chybí kód účtu.", 422);
            }
            $side = (string) ($l['side'] ?? '');
            if ($side !== 'debit' && $side !== 'credit') {
                return Json::error($response, 'validation_failed', "Řádek #{$i}: side musí být 'debit' nebo 'credit'.", 422);
            }
            $amount = null;
            if (array_key_exists('amount', $l) && $l['amount'] !== null && $l['amount'] !== '') {
                if (!is_numeric($l['amount']) || (float) $l['amount'] < 0) {
                    return Json::error($response, 'validation_failed', "Řádek #{$i}: částka musí být nezáporné číslo.", 422);
                }
                $amount = round((float) $l['amount'], 2);
            }
            $lines[] = [
                'account_code' => $accountCode,
                'side'         => $side,
                'amount'       => $amount,
                'label'        => $this->nullableString($l['label'] ?? null),
                'cost_center'  => $this->nullableString($l['cost_center'] ?? null),
            ];
        }

        if (!$this->templates->update($supplierId, $id, $name, $description, $lines)) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        return Json::ok($response, $this->templates->find($supplierId, $id));
    }

    /**
     * POST /journal-templates/{id}/import-csv — jen NÁHLED (žádný zápis do DB): napáruje
     * CSV na řádky šablony a vrátí je s doplněnými částkami; FE výsledkem předvyplní
     * ManualEntry, uživatel zápis uloží běžným POST /accounting/journal.
     */
    public function importCsv(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $id = (int) ($args['id'] ?? 0);

        $template = $this->templates->find($supplierId, $id);
        if ($template === null) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $file = $this->firstFile($request->getUploadedFiles());
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'bad_file', 'Nahrajte CSV soubor.', 415);
        }
        if ((int) ($file->getSize() ?? 0) > self::MAX_CSV_BYTES) {
            return Json::error($response, 'bad_file', 'Soubor je větší než 512 kB.', 415);
        }
        $filename = (string) ($file->getClientFilename() ?? '');
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            return Json::error($response, 'bad_file', 'Podporovaný formát je CSV.', 415);
        }
        $content = (string) $file->getStream()->getContents();
        if ($content === '' || str_contains($content, "\0")) {
            return Json::error($response, 'bad_file', 'Soubor nelze přečíst jako CSV.', 415);
        }

        return Json::ok($response, $this->matcher->match($template['lines'], $content));
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    /** @param array<string, UploadedFileInterface|array<int,UploadedFileInterface>> $uploads */
    private function firstFile(array $uploads): ?UploadedFileInterface
    {
        foreach ($uploads as $node) {
            if ($node instanceof UploadedFileInterface) {
                return $node;
            }
            if (is_array($node)) {
                foreach ($node as $sub) {
                    if ($sub instanceof UploadedFileInterface) {
                        return $sub;
                    }
                }
            }
        }
        return null;
    }
}
