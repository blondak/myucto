<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\CsvWriter;
use MyInvoice\Service\Export\ExportFilename;
use MyInvoice\Service\Export\ExportPeriod;
use MyInvoice\Service\Export\ExportPeriodResolver;
use MyInvoice\Service\Export\PurchaseInvoiceExportService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\PurchaseInvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use ZipArchive;

/**
 * GET /api/purchase-invoices/export?month=YYYY-MM&format=pdf-zip|isdoc|pohoda|csv[&date_by=tax|issue|received]
 * GET /api/purchase-invoices/export?period=quarterly&year=YYYY&quarter=1..4&format=pdf-zip|isdoc|pohoda|csv
 *
 * Export přijatých faktur za měsíc nebo čtvrtletí jako ZIP s **vendor original PDF**.
 *
 * Priorita per faktura:
 *   1) Archivovaný originál od dodavatele (pdf_path) → použij ten
 *      (`Prijata-{vs}-{vendor}.pdf`).
 *   2) Jinak → doplň NAŠI rekonstrukci z dat faktury (PurchaseInvoicePdfRenderer),
 *      pojmenovanou `Prijata-{vs}-{vendor}-rekonstrukce.pdf`, ať účetní pozná, že
 *      nejde o originál. Faktura se přeskočí jen pokud selže i rekonstrukce.
 *
 * Počet doplněných rekonstrukcí vrací header `X-Export-Reconstructed`; reálné
 * chyby (poškozený originál i selhání rekonstrukce) jdou do `X-Export-Warnings`.
 *
 * Přístup: admin nebo accountant.
 */
final class ExportPurchaseInvoicesAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PurchaseInvoiceExportService $exporter,
        private readonly PurchaseInvoicePdfRenderer $renderer,
        private readonly ExportPeriodResolver $periodResolver,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        // readonly smí exportovat data (čtení), jen nesmí nic měnit; client exportuje
        // výhradně vlastní data (membership scope řeší SupplierScopeMiddleware — F6 §8.1)
        if (!RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        $q = $request->getQueryParams();
        try {
            $period = $this->periodResolver->resolve($q);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
        $dateBy = (string) ($q['date_by'] ?? 'tax');  // tax|issue|received
        if (!in_array($dateBy, ['tax', 'issue', 'received'], true)) {
            $dateBy = 'tax';
        }
        $format = (string) ($q['format'] ?? 'pdf-zip');
        if (!in_array($format, ['pdf-zip', 'isdoc', 'pohoda', 'csv'], true)) {
            return Json::error($response, 'unsupported_format', "Neplatný format ({$format}).", 400);
        }

        $sid = SupplierGuard::currentId($request);
        $rows = $this->findInvoices($sid, $period, $dateBy);
        if (empty($rows)) {
            return Json::error($response, 'no_invoices', "Za období {$period->label} nejsou žádné přijaté faktury.", 404);
        }

        // ISDOC bulk ZIP / Pohoda dataPack / CSV přehled → delegujeme do separate metod
        if ($format === 'isdoc') {
            return $this->exportIsdocZip($response, $request, $rows, $period, $sid);
        }
        if ($format === 'pohoda') {
            return $this->exportPohodaDataPack($response, $request, $rows, $period, $sid);
        }
        if ($format === 'csv') {
            return $this->exportCsv($response, $request, $sid, $period, $dateBy);
        }
        // (pdf-zip pokračuje níže — original code)

        $archiveRoot = $this->resolveArchiveRoot();
        $archiveRootReal = realpath($archiveRoot);

        $tmpZip = tempnam(sys_get_temp_dir(), 'pinv-zip-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return Json::error($response, 'zip_failed', 'Nelze vytvořit ZIP.', 500);
        }

        $included = 0;
        $reconstructed = 0;
        $skipped = [];
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $vs = (string) ($r['varsymbol'] ?? $r['vendor_invoice_number'] ?? ('id-' . $id));
            $vendor = (string) ($r['vendor_company_name'] ?? 'vendor');
            // Sanitize filename pro ZIP entry (zip-slip via varsymbol/vendor name).
            // Diakritiku v názvu firmy přepíšeme na ASCII (č→c, ě→e, …), ne na podtržítka.
            $entryBase = substr(ExportFilename::sanitize($vs . '-' . $vendor, 'invoice'), 0, 100);

            // 1) Archivovaný originál od dodavatele má přednost. Resolve relativní path
            //    + path-traversal guard (zip-slip). Pokud byl originál očekáván
            //    (pdf_path) ale je nedostupný, zalogujeme warning a spadneme na rekonstrukci.
            $originalAbs = null;
            if (!empty($r['pdf_path'])) {
                $abs = $archiveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $r['pdf_path']);
                $absReal = realpath($abs);
                if ($absReal === false || !is_file($absReal)) {
                    $skipped[] = "{$vs} ({$vendor}) — originál nenalezen na disku, doplněna rekonstrukce";
                } elseif ($archiveRootReal !== false
                    && !str_starts_with(
                        $isWindows ? strtolower($absReal) : $absReal,
                        ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR
                    )) {
                    $skipped[] = "{$vs} ({$vendor}) — originál mimo archive root, doplněna rekonstrukce";
                } else {
                    $originalAbs = $absReal;
                }
            }

            if ($originalAbs !== null) {
                $zip->addFile($originalAbs, 'Prijata-' . $entryBase . '.pdf');
                $included++;
                continue;
            }

            // 2) Fallback: naše rekonstrukce z dat faktury (mimic dodavatelského PDF).
            //    Odlišený název, ať účetní pozná, že nejde o originál.
            try {
                $pdfBytes = $this->renderer->render($id, $sid);
            } catch (\Throwable $e) {
                $skipped[] = "{$vs} ({$vendor}) — bez originálu a rekonstrukce selhala: " . $e->getMessage();
                continue;
            }
            $zip->addFromString('Prijata-' . $entryBase . '-rekonstrukce.pdf', $pdfBytes);
            $reconstructed++;
            $included++;
        }

        $zip->close();

        if ($included === 0) {
            @unlink($tmpZip);
            return Json::error($response, 'no_invoices_processed',
                "Za období {$period->label} se nepodařilo vyexportovat žádnou přijatou fakturu.",
                500,
                ['skipped' => $skipped],
            );
        }

        $this->logger->log('purchase_invoices.exported', $user['id'] ?? null, null, null, [
            'format' => 'pdf-zip',
            'period' => $period->label,
            'period_type' => $period->type,
            'month' => $period->month,
            'quarter' => $period->quarter,
            'date_from' => $period->dateFrom,
            'date_to_exclusive' => $period->dateToExclusive,
            'date_by' => $dateBy,
            'included' => $included, 'reconstructed' => $reconstructed, 'skipped_count' => count($skipped),
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        // Stream ZIP přímo z disku (PSR-7 withBody) — neslurpovat celý soubor do paměti
        // kvůli DoS riziku u velkých exportů. Cleanup temp file přes shutdown hook
        // (Slim zavře stream po odeslání response).
        $size = filesize($tmpZip);
        $fp = fopen($tmpZip, 'rb');
        if ($fp === false) {
            @unlink($tmpZip);
            return Json::error($response, 'zip_failed', 'Nelze otevřít ZIP ke streamu.', 500);
        }
        $stream = new Stream($fp);
        register_shutdown_function(static function () use ($tmpZip): void {
            if (is_file($tmpZip)) @unlink($tmpZip);
        });

        $r = $response
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="purchase-invoices-' . $period->label . '.zip"')
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('X-Export-Reconstructed', (string) $reconstructed);
        if (!empty($skipped)) {
            // Truncate hlavičky aby nebyla too long pro proxy
            $warnings = array_slice($skipped, 0, 10);
            $extra = count($skipped) - count($warnings);
            $msg = implode(' | ', $warnings) . ($extra > 0 ? " (+{$extra} more)" : '');
            $r = $r->withHeader('X-Export-Warnings', mb_substr($msg, 0, 1000));
        }
        return $r;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function findInvoices(int $supplierId, ExportPeriod $period, string $dateBy): array
    {
        $dateExpr = match ($dateBy) {
            'received' => 'pi.received_at',
            'issue'    => 'pi.issue_date',
            default    => 'COALESCE(pi.tax_date, pi.issue_date)',
        };

        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number,
                       pi.pdf_path, pi.pdf_original_name,
                       c.company_name AS vendor_company_name
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                 WHERE pi.supplier_id = ?
                   AND $dateExpr >= ?
                   AND $dateExpr < ?
                   AND pi.status IN ('received', 'booked', 'paid')
                 ORDER BY $dateExpr, pi.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $period->dateFrom, $period->dateToExclusive]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * CSV přehled přijatých faktur za období (UTF-8 BOM, `;`) — jeden soubor pro
     * účetní/Excel. Vlastní SELECT s plnými sloupci (findInvoices pro pdf-zip bere
     * jen id/varsymbol/vendor).
     */
    private function exportCsv(Response $response, Request $request, int $supplierId, ExportPeriod $period, string $dateBy): Response
    {
        $dateExpr = match ($dateBy) {
            'received' => 'pi.received_at',
            'issue'    => 'pi.issue_date',
            default    => 'COALESCE(pi.tax_date, pi.issue_date)',
        };
        $sql = "SELECT pi.varsymbol, pi.vendor_invoice_number, pi.document_kind,
                       c.company_name AS vendor_company_name,
                       ec.label AS expense_category_label,
                       pi.issue_date, pi.tax_date, pi.due_date,
                       cur.code AS currency,
                       pi.total_without_vat, pi.total_vat, pi.total_with_vat, pi.amount_to_pay,
                       pi.status, pi.paid_at
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
             LEFT JOIN expense_categories ec ON ec.id = pi.expense_category_id
                 WHERE pi.supplier_id = ?
                   AND $dateExpr >= ?
                   AND $dateExpr < ?
                   AND pi.status IN ('received', 'booked', 'paid')
              ORDER BY $dateExpr, pi.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $period->dateFrom, $period->dateToExclusive]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $header = [
            'Interní číslo', 'Číslo dokladu', 'Druh', 'Dodavatel', 'Kategorie',
            'Vystaveno', 'DUZP', 'Splatnost', 'Měna', 'Bez DPH', 'DPH', 'Celkem',
            'K úhradě', 'Stav', 'Zaplaceno',
        ];
        $csvRows = [];
        foreach ($rows as $r) {
            $csvRows[] = [
                CsvWriter::safe($r['varsymbol'] ?? ''),
                CsvWriter::safe($r['vendor_invoice_number'] ?? ''),
                CsvWriter::safe($r['document_kind'] ?? ''),
                CsvWriter::safe($r['vendor_company_name'] ?? ''),
                CsvWriter::safe($r['expense_category_label'] ?? ''),
                $r['issue_date'] ?? '',
                $r['tax_date'] ?? '',
                $r['due_date'] ?? '',
                $r['currency'] ?? '',
                number_format((float) ($r['total_without_vat'] ?? 0), 2, '.', ''),
                number_format((float) ($r['total_vat'] ?? 0), 2, '.', ''),
                number_format((float) ($r['total_with_vat'] ?? 0), 2, '.', ''),
                number_format((float) ($r['amount_to_pay'] ?? 0), 2, '.', ''),
                $r['status'] ?? '',
                $r['paid_at'] ?? '',
            ];
        }
        $csv = CsvWriter::build($header, $csvRows);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log('purchase_invoices.exported', $user['id'] ?? null, null, null, [
            'format' => 'csv',
            'period' => $period->label,
            'period_type' => $period->type,
            'month' => $period->month,
            'quarter' => $period->quarter,
            'date_from' => $period->dateFrom,
            'date_to_exclusive' => $period->dateToExclusive,
            'date_by' => $dateBy,
            'included' => count($csvRows),
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"prijate-{$period->label}.csv\"")
            ->withHeader('Content-Length', (string) strlen($csv))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function resolveArchiveRoot(): string
    {
        $dir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($dir !== '') return $dir;
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') return dirname($uploads) . '/purchase-invoices';
        return \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
    }

    /**
     * Bulk ISDOC export — ZIP s jedním ISDOC XML per faktura.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function exportIsdocZip(Response $response, Request $request, array $rows, ExportPeriod $period, int $supplierId): Response
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'pinv-isdoc-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return Json::error($response, 'zip_failed', 'Nelze vytvořit ZIP.', 500);
        }

        $included = 0;
        $errors = [];
        foreach ($rows as $r) {
            try {
                $xml = $this->exporter->toIsdocXml((int) $r['id'], $supplierId);
            } catch (\Throwable $e) {
                $errors[] = "{$r['varsymbol']} — " . $e->getMessage();
                continue;
            }
            $vs = (string) ($r['varsymbol'] ?? ('id-' . $r['id']));
            $vendor = (string) ($r['vendor_company_name'] ?? 'vendor');
            $base = ExportFilename::sanitize($vs . '-' . $vendor, 'invoice');
            $zip->addFromString('Prijata-' . substr($base, 0, 100) . '.isdoc', $xml);
            $included++;
        }
        $zip->close();

        if ($included === 0) {
            @unlink($tmpZip);
            return Json::error($response, 'no_invoices_processed',
                'Nepodařilo se vyexportovat žádnou fakturu.', 500, ['errors' => $errors]);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log('purchase_invoices.exported', $user['id'] ?? null, null, null, [
            'format' => 'isdoc-zip',
            'period' => $period->label,
            'period_type' => $period->type,
            'month' => $period->month,
            'quarter' => $period->quarter,
            'date_from' => $period->dateFrom,
            'date_to_exclusive' => $period->dateToExclusive,
            'included' => $included,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $size = filesize($tmpZip);
        $fp = fopen($tmpZip, 'rb');
        if ($fp === false) {
            @unlink($tmpZip);
            return Json::error($response, 'zip_failed', 'Nelze otevřít ZIP ke streamu.', 500);
        }
        $stream = new Stream($fp);
        register_shutdown_function(static function () use ($tmpZip): void {
            if (is_file($tmpZip)) @unlink($tmpZip);
        });

        return $response
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', "attachment; filename=\"prijate-isdoc-{$period->label}.zip\"")
            ->withHeader('Content-Length', (string) $size)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Export-Warnings', count($errors) > 0 ? (string) count($errors) : '0');
    }

    /**
     * Bulk Pohoda dataPack — jeden `<dat:dataPack>` s jednou `<dat:dataPackItem>` per
     * faktura. Celý balíček staví `PohodaXmlExporter::buildXml()` JEDNÍM voláním nad
     * polem faktur (žádné string-wrappování → žádný zanořený dataPack uvnitř položky).
     *
     * @param list<array<string,mixed>> $rows
     */
    private function exportPohodaDataPack(Response $response, Request $request, array $rows, ExportPeriod $period, int $supplierId): Response
    {
        $ids = array_map(static fn ($r): int => (int) $r['id'], $rows);
        $result = $this->exporter->toPohodaDataPack($ids, $supplierId);

        if ($result['included'] === 0) {
            return Json::error($response, 'no_invoices_processed',
                'Nepodařilo se vyexportovat žádnou fakturu.', 500, ['errors' => $result['errors']]);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log('purchase_invoices.exported', $user['id'] ?? null, null, null, [
            'format' => 'pohoda-datapack',
            'period' => $period->label,
            'period_type' => $period->type,
            'month' => $period->month,
            'quarter' => $period->quarter,
            'date_from' => $period->dateFrom,
            'date_to_exclusive' => $period->dateToExclusive,
            'included' => $result['included'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"prijate-pohoda-{$period->label}.xml\"")
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Export-Warnings', count($result['errors']) > 0 ? (string) count($result['errors']) : '0');
    }
}
