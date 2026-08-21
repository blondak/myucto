<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/accounting/journal/for-document/{source}/{id} — zaúčtování faktury
 * (vydané i přijaté) i s řádky.
 *
 * Podklad pro sbalenou sekci „Zaúčtování" na detailu faktury. Detail dosud uměl
 * jen odskok do deníku, takže účetní musel kvůli jednomu pohledu na kontaci
 * opustit doklad. Pokladní doklad sekci nemá — tam kontace vede jen jeden zápis
 * a řádek pokladny na něj rovnou odkazuje.
 *
 * Vrací VŠECHNY zápisy dokladu (původní i storno), řazené od nejstaršího —
 * doklad může mít protizápis a účetní musí vidět obojí.
 *
 * V daňové evidenci deník neexistuje; místo chyby se vrací prázdný seznam, aby
 * volající sekci prostě nezobrazil (načítá se na pozadí, chyba by tam byla šum).
 *
 * Právo řeší RoutePermissionMap: GET /api/accounting/journal(/|$) → 'accounting' READ.
 * Tenant se bere ze session a filtruje se jím dotaz — cizí doklad nevrátí nic.
 */
final class JournalForDocumentAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    /** URL segment → journal_entries.source_type */
    private const SOURCES = [
        'invoices'           => 'invoice',
        'purchase-invoices'  => 'purchase_invoice',
    ];

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $sourceType = self::SOURCES[(string) ($args['source'] ?? '')] ?? null;
        $docId = (int) ($args['id'] ?? 0);
        if ($sourceType === null || $docId <= 0) {
            return Json::error($response, 'validation_failed', 'Neznámý typ dokladu.', 422);
        }

        if (!$this->accountingModeIs($this->db, $supplierId, 'double_entry')) {
            return Json::ok($response, ['items' => []]);
        }

        $entries = $this->journal->listBySourceWithLines($supplierId, $sourceType, $docId);
        if ($entries !== []) {
            $accMap = $this->accounts->idToAccountMap($supplierId);
            foreach ($entries as $i => $entry) {
                $entries[$i]['lines'] = array_map(static function (array $line) use ($accMap): array {
                    $acc = $accMap[(int) $line['account_id']] ?? null;
                    $line['account_code'] = $acc['code'] ?? null;
                    $line['account_name'] = $acc['name'] ?? null;
                    return $line;
                }, $entry['lines']);
            }
        }

        return Json::ok($response, ['items' => $entries]);
    }
}
