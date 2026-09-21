<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationCalculator;
use MyInvoice\Service\Accounting\Assets\DepreciationContext;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;

/**
 * Evidence majetku Money (`MajInv` karty, `MjInvPoh` pohyby): dlouhodobý majetek
 * (`TypMajetku` 1) a drobný majetek (`TypMajetku` 0).
 *
 * Pohyby karty (`MjInvPoh.Typ`): Z zařazení, V zvýšení ceny, H technické zhodnocení,
 * S snížení ceny (dotace, dobropis), U účetní odpis, D daňový odpis zaúčtovaný jako
 * účetní (karty, kde se účetní odpis rovná daňovému), X účetní zůstatková cena při roční
 * uzávěrce karty, Y vyřazení. `ZustCena` u odpisů je účetní zůstatková cena po pohybu.
 * Daňové odpisy hmotného majetku Money neukládá, počítá je z parametrů karty.
 *
 * Zařazení, odpisy i vyřazení Money zaúčtovalo interními doklady, které jsou v převzatém
 * deníku. Karta proto vznikne bez zápisu v deníku a vede se stejně jako karta převodu
 * z PREMIER ({@see \MyInvoice\Service\Migration\Premier\AssetImporter}): počáteční stavy
 * pokrývají roky před prvním převedeným rokem, každý převedený rok má účetní řádek
 * zaúčtovaný převzatým deníkem ({@see DepreciationEntryRepository::MIGRATED_JOURNAL},
 * hromadné účtování odpisů ho znovu neúčtuje) a potvrzený daňový řádek.
 */
final class AssetImporter
{
    public const STEP = 'assets';
    public const STEP_SMALL = 'small_assets';

    private const TYPE_LONG_TERM = 1;
    private const TYPE_SMALL = 0;

    /** Pohyby, které mění cenu karty (znaménko). */
    private const PRICE_MOVES = ['Z' => 1, 'V' => 1, 'H' => 1, 'S' => -1];
    private const KNOWN_MOVES = ['Z', 'V', 'H', 'S', 'U', 'D', 'X', 'Y'];

    /** `MajInv.ZpusobOdpi` - způsob daňového odpisu. */
    private const TAX_METHODS = ['Z' => 'accelerated', 'R' => 'straight', 'N' => 'straight'];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly AssetService $assets,
        private readonly SmallAssetService $smallAssets,
        private readonly DepreciationEntryRepository $entries,
        private readonly DepreciationCalculator $calculator,
    ) {}

    public function importLongTerm(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        [$cards, $moves] = $this->read($ctx);
        $years = $this->years($ctx);
        if ($years === [] || $cards === []) {
            $p->finish(self::STEP);
            return;
        }
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_ASSET);
        $end = sprintf('%04d-12-31', max($years));
        $evidence = [];
        foreach ($cards as $no => $card) {
            if ((int) ($card['TypMajetku'] ?? -1) !== self::TYPE_LONG_TERM) {
                continue;
            }
            $this->importCard($ctx, $existing, $years, $card, $moves[$no] ?? []);
            // Evidence Money po syntetických účtech: majetek nevyřazený do konce převodu.
            $synthetic = AccountCode::synthetic((string) ($card['PrUcMaj'] ?? ''));
            $disposed = self::date($card['DatVyrazen'] ?? null);
            if (!in_array($synthetic, [null, '000'], true) && ($disposed === null || $disposed > $end)) {
                $evidence[$synthetic] = ($evidence[$synthetic] ?? 0.0) + self::priceAt($moves[$no] ?? [], $end);
            }
        }
        $this->reconcile($ctx, $end, $evidence);
        $p->finish(self::STEP);
    }

    /**
     * Karty proti účetnictví ke konci posledního převedeného roku: pořizovací ceny (vstupní
     * cena a technická zhodnocení) proti zůstatku majetkového účtu, oprávky (počáteční
     * stav a odpisy převedených let) proti zůstatku oprávkového účtu.
     *
     * @param array<string,float> $evidence ceny karet evidence Money po syntetických účtech
     */
    private function reconcile(ImportContext $ctx, string $end, array $evidence): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT a.asset_account_code, a.accumulated_account_code,
                    a.input_price + COALESCE((SELECT SUM(i.amount) FROM asset_improvements i WHERE i.asset_id = a.id AND i.completed_on <= ?), 0) AS price,
                    a.opening_acc_amount + COALESCE((SELECT SUM(d.amount) FROM depreciation_entries d
                        WHERE d.asset_id = a.id AND d.kind = 'accounting' AND d.fiscal_year <= ?), 0) AS accumulated
               FROM assets a
               JOIN money_s3_import_map m ON m.supplier_id = a.supplier_id AND m.kind = ? AND m.target_id = a.id
              WHERE a.supplier_id = ? AND a.status IN ('in_use', 'draft')"
        );
        $stmt->execute([$end, (int) substr($end, 0, 4), MoneyS3ImportRepository::KIND_ASSET, $ctx->supplierId]);
        $cards = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            // Po syntetických účtech: karta bez analytiky v osnově nese syntetiku a jiné karty
            // téhož účtu analytiku, zůstatky se tak nezapočítají dvakrát.
            $asset = substr((string) $r['asset_account_code'], 0, 3);
            $cards[$asset] = ($cards[$asset] ?? 0.0) + (float) $r['price'];
            if ($r['accumulated_account_code'] !== null) {
                $acc = substr((string) $r['accumulated_account_code'], 0, 3);
                $cards[$acc] = ($cards[$acc] ?? 0.0) - (float) $r['accumulated'];
            }
        }
        if ($cards === []) {
            return;
        }
        $balance = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               JOIN chart_of_accounts c ON c.id = l.account_id
              WHERE l.supplier_id = ? AND e.supplier_id = ? AND e.entry_date BETWEEN ? AND ?
                AND (c.account_code = ? OR c.account_code LIKE CONCAT(?, '.%'))
                AND e.source_type <> 'closing'"
        );
        ksort($cards);
        $diffs = [];
        $inMoney = [];
        foreach ($cards as $code => $amount) {
            // Rok nese počáteční stavy otevíracím zápisem, zůstatek je tedy součet jen za poslední rok.
            $balance->execute([$ctx->supplierId, $ctx->supplierId, substr($end, 0, 4) . '-01-01', $end, $code, $code]);
            $ledger = round((float) $balance->fetchColumn(), 2);
            $diff = round($amount - $ledger, 2);
            if (abs($diff) < 0.01) {
                continue;
            }
            if (isset($evidence[$code]) && abs(round($evidence[$code], 2) - round($amount, 2)) < 0.01) {
                // Karty sedí na evidenci Money, rozdíl proti účtu je už v Money (majetek mimo evidenci).
                $inMoney[] = sprintf('%s: evidence %s, účet %s', $code, self::money(round($amount, 2)), self::money($ledger));
                continue;
            }
            $diffs[] = sprintf('%s: karty %s, účet %s', $code, self::money(round($amount, 2)), self::money($ledger));
        }
        $ctx->protocol->count(self::STEP, 'accounts_checked', count($cards));
        if ($inMoney !== []) {
            $ctx->protocol->info(self::STEP, 'ledger_diff_in_money', 'Evidence majetku Money nesedí na účty už v Money (majetek účtovaný mimo evidenci), karty ji převzaly beze změny: ' . implode('; ', $inMoney) . '.', ['accounts' => $inMoney]);
        }
        if ($diffs === [] && $inMoney === []) {
            $ctx->protocol->info(self::STEP, 'ledger_match', 'Karty majetku sedí na zůstatky majetkových a oprávkových účtů ke ' . $end . '.');
        } elseif ($diffs !== []) {
            $ctx->protocol->warn(self::STEP, 'ledger_mismatch', 'Karty majetku ke ' . $end . ' nesedí na účty: ' . implode('; ', $diffs) . '. Rozdíl je majetek účtovaný mimo evidenci Money nebo karta ke kontrole.', ['accounts' => $diffs]);
        }
    }

    public function importSmall(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        [$cards, $moves] = $this->read($ctx);
        $years = $this->years($ctx);
        if ($years === [] || $cards === []) {
            $p->finish(self::STEP_SMALL);
            return;
        }
        $end = sprintf('%04d-12-31', max($years));
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_SMALL_ASSET);
        foreach ($cards as $no => $card) {
            if ((int) ($card['TypMajetku'] ?? -1) !== self::TYPE_SMALL) {
                continue;
            }
            $key = (string) $no;
            if (isset($existing[$key])) {
                $p->count(self::STEP_SMALL, 'existing');
                continue;
            }
            $list = $moves[$no] ?? [];
            $acquired = self::firstDate($list, 'Z') ?? self::date($card['DatZarazen'] ?? null) ?? self::date($card['DatVyroby'] ?? null);
            if ($acquired === null || $acquired > $end) {
                $p->count(self::STEP_SMALL, 'later_years');
                continue;
            }
            $price = self::priceAt($list, $end);
            if ($price < 0.005) {
                $price = round((float) ($card['UcOdpPorC'] ?? 0), 2);
            }
            $disposed = self::date($card['DatVyrazen'] ?? null) ?? self::firstDate($list, 'Y');
            $disposed = $disposed !== null && $disposed <= $end ? max($disposed, $acquired) : null;
            $inUse = self::date($card['DatZarazen'] ?? null);
            $vendorNo = (int) ($card['CDodavatel'] ?? 0);
            $number = trim((string) ($card['InventCisl'] ?? ''));
            $id = $this->smallAssets->create($ctx->supplierId, [
                'asset_kind' => strtoupper(trim((string) ($card['Druh'] ?? ''))) === 'N' ? 'intangible' : 'tangible',
                'name' => mb_substr(trim((string) ($card['Nazev'] ?? '')) ?: 'Drobný majetek', 0, 255),
                'inventory_number' => $number !== '' && $number !== '0' ? mb_substr($number, 0, 40) : null,
                'acquisition_date' => $acquired,
                'put_into_use_date' => $inUse !== null && $inUse >= $acquired ? $inUse : $acquired,
                'quantity' => 1,
                'unit_price' => max(0.0, $price),
                'price' => max(0.0, $price),
                'location' => self::text($card['Umisteno'] ?? null, 160),
                'vendor_client_id' => $vendorNo > 0 ? ($ctx->clientsByMoneyNo[$vendorNo] ?? null) : null,
                'vendor_name' => self::text($card['SDodavatel'] ?? null, 190),
                'status' => $disposed !== null ? 'disposed' : 'in_use',
                'disposed_at' => $disposed,
                'disposal_reason' => $disposed !== null
                    ? mb_substr('Vyřazeno v Money S3' . (($reason = trim((string) ($card['ZpVyrazeni'] ?? ''))) !== '' ? ': ' . $reason : ''), 0, 255)
                    : null,
                'notes' => 'Převzato z evidence majetku Money S3 (karta č. ' . $no . ').',
            ], $ctx->userId > 0 ? $ctx->userId : null);
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_SMALL_ASSET, $key, $id, $ctx->runId);
            $p->count(self::STEP_SMALL, $disposed !== null ? 'created_disposed' : 'created');
        }
        $p->finish(self::STEP_SMALL);
    }

    /**
     * @param array<string,int> $existing
     * @param list<int> $years převedené roky vzestupně
     * @param array<string,mixed> $card
     * @param list<array<string,mixed>> $list pohyby karty podle data
     */
    private function importCard(ImportContext $ctx, array $existing, array $years, array $card, array $list): void
    {
        $p = $ctx->protocol;
        $no = (int) $card['Cislo'];
        $first = $years[0];
        $last = $years[count($years) - 1];
        $start = sprintf('%04d-01-01', $first);
        $end = sprintf('%04d-12-31', $last);
        $name = trim((string) ($card['Nazev'] ?? '')) ?: ('Karta ' . $no);
        $disposal = self::date($card['DatVyrazen'] ?? null) ?? self::firstDate($list, 'Y');
        $inUse = self::date($card['DatZarazen'] ?? null) ?? self::firstDate($list, 'Z')
            ?? ($list !== [] ? $list[0]['Datum'] : null) ?? self::date($card['DatVyroby'] ?? null);
        // Karta bez data zařazení (typicky pozemek zadaný jen jako evidence) se vede
        // od začátku převodu, aby nechyběla v inventarizaci účtu.
        $noInUseDate = $inUse === null;
        $inUse ??= sprintf('%04d-12-31', $first - 1);
        if ($inUse > $end) {
            $p->count(self::STEP, 'later_years');
            return;
        }
        if ($disposal !== null && $disposal < $start) {
            $p->count(self::STEP, 'disposed_before');
            return;
        }
        // Karta bez majetkového účtu je v Money pomocná evidence (výpočet daňových odpisů
        // k majetku vedenému na jiné kartě, licence), majetkem v účetnictví není.
        if (in_array(AccountCode::synthetic((string) ($card['PrUcMaj'] ?? '')), [null, '000'], true)) {
            $p->count(self::STEP, 'helper_cards');
            $p->info(self::STEP, 'helper_card', "Karta {$no} ({$name}) nemá v Money majetkový účet, jde o pomocnou evidenci; nepřevádí se.", ['document_no' => (string) $no]);
            return;
        }
        $key = (string) $no;
        [$taxMethod, $taxGroup] = self::taxSetup($card, $list, $disposal);
        $plan = $this->taxPlan($taxMethod, $taxGroup, $list, $inUse, $disposal);
        if (isset($existing[$key])) {
            $this->depreciation($ctx, $existing[$key], $years, $list, $inUse, $disposal, $plan);
            $p->count(self::STEP, 'existing');
            return;
        }

        $review = [];
        if ($noInUseDate) {
            $review[] = 'Money nemá datum zařazení, doplňte ho';
        }
        foreach ($list as $m) {
            if (!in_array($m['Typ'], self::KNOWN_MOVES, true) && $m['Datum'] <= $end) {
                $review[] = 'pohyb typu „' . $m['Typ'] . '" z ' . $m['Datum'] . ' převod nezná';
            }
        }
        // Vstupní cena ke dni, od kterého se karta v MyÚčtu vede: u majetku zařazeného
        // před prvním převedeným rokem cena na jeho začátku, jinak cena ke dni zařazení.
        // Pozdější zvýšení ceny a technická zhodnocení jsou technická zhodnocení karty.
        $priceDate = $inUse < $start ? sprintf('%04d-12-31', $first - 1) : $inUse;
        $inputPrice = self::priceAt($list, $priceDate);
        if ($inputPrice < 0.01) {
            $inputPrice = round((float) ($card['UcOdpPorC'] ?? 0), 2);
        }
        $improvements = [];
        foreach ($list as $m) {
            if ($m['Datum'] > $priceDate && $m['Datum'] <= $end && isset(self::PRICE_MOVES[$m['Typ']])) {
                if (self::PRICE_MOVES[$m['Typ']] < 0) {
                    // Snížení ceny (dotace, dobropis) se zapíše jako záporné zhodnocení, aby karta
                    // seděla na účet; daňový dopad snížení vstupní ceny ale ověří účetní.
                    $review[] = 'snížení ceny ' . self::money($m['Castka']) . ' z ' . $m['Datum'] . ' (' . ($m['Popis'] ?: $m['Doklad'] ?: 'bez popisu') . ') je zapsané jako záporné technické zhodnocení, ověřte daňovou vstupní cenu';
                    $improvements[] = $m;
                } elseif ($m['Typ'] !== 'Z' || $m['Datum'] > $inUse) {
                    $improvements[] = $m;
                }
            }
        }
        if ($inputPrice < 0.01) {
            $review[] = 'karta nemá vstupní cenu';
        }

        $kind = strtoupper(trim((string) ($card['Druh'] ?? ''))) === 'N' ? 'intangible' : 'tangible';
        $assetAccount = $this->account($ctx, [(string) ($card['PrUcMaj'] ?? ''), (string) (self::lastValue($list, 'PrUcMaj') ?? '')]);
        $accumulated = $this->account($ctx, [(string) (self::lastValue($list, 'PrUcOpr') ?? ''), (string) ($card['PrUcOpr'] ?? '')]);
        $accMoves = self::accountingMoves($list, $disposal);
        // Skupina „N" je v Money neodpisovaný majetek (pozemky); odpisuje se jen karta s odpisy.
        $depreciable = $accMoves !== [];
        if ($assetAccount === null) {
            $review[] = 'majetkový účet karty ' . trim((string) ($card['PrUcMaj'] ?? '')) . ' v osnově není';
        }
        if ($depreciable && $accumulated === null) {
            $review[] = 'oprávkový účet karty v osnově není';
        }
        // Účetní odpisy před prvním převedeným rokem jsou počáteční stav karty.
        $before = array_values(array_filter($accMoves, static fn (array $m): bool => $m['Datum'] < $start));
        $openingAcc = round(array_sum(array_column($before, 'Castka')), 2);
        $inUseMonth = substr($inUse, 0, 7);
        $openingMonths = $before === [] ? 0 : max(0, self::monthsBetween($inUseMonth, substr((string) end($before)['Datum'], 0, 7)));
        // Daňové odpisy let před převodem jsou počáteční stav karty.
        $taxBefore = array_filter($plan, static fn (array $r): bool => $r['fiscal_year'] < $first);
        $taxYears = count($taxBefore);
        $taxAmount = round(array_sum(array_column($taxBefore, 'full_amount')), 2);

        $card0 = [
            'inventory_number' => $this->inventoryNumber($ctx, trim((string) ($card['InventCisl'] ?? '')) ?: trim((string) ($card['KodMaj'] ?? '')), $no),
            'name' => mb_substr($name, 0, 255),
            'kind' => $kind,
            'input_price' => $inputPrice,
            'acquisition_date' => self::firstDate($list, 'Z') ?? $inUse,
            'put_into_use_date' => $inUse,
            'status' => 'in_use',
            'tax_method' => $taxMethod,
            'tax_group' => in_array($taxMethod, ['straight', 'accelerated'], true) ? $taxGroup : null,
            'opening_tax_years' => in_array($taxMethod, ['straight', 'accelerated'], true) ? $taxYears : 0,
            'opening_tax_amount' => in_array($taxMethod, ['straight', 'accelerated'], true) ? min($taxAmount, $inputPrice) : 0.0,
            'opening_acc_months' => $depreciable ? $openingMonths : 0,
            'opening_acc_amount' => $depreciable ? min($openingAcc, $inputPrice) : 0.0,
            'acc_useful_life_months' => $depreciable ? self::usefulLife($inUseMonth, $accMoves) : null,
            'acc_method' => 'straight_line',
            'acc_residual_value' => 0.0,
            'accumulated_account_code' => $depreciable ? $accumulated : null,
        ];
        if ($card0['acquisition_date'] > $inUse) {
            $card0['acquisition_date'] = $inUse;
        }
        if ($assetAccount !== null) {
            $card0['asset_account_code'] = $assetAccount;
        }
        $acquisition = $this->acquisitionAccount($ctx, $kind);
        if ($acquisition !== null) {
            $card0['acquisition_account_code'] = $acquisition;
        }
        if ($review !== []) {
            $card0['status'] = 'draft';
            $card0['description'] = 'Převod z Money S3 (karta č. ' . $no . ') - ke kontrole: ' . implode('; ', $review) . '.';
        } else {
            $card0['description'] = 'Převod z Money S3 (karta č. ' . $no . ')' . (self::accountingOnly($card) ? ', interní karta jen pro účetní odpisy, bez daňových odpisů.' : '.');
        }
        try {
            $created = $this->assets->create($ctx->supplierId, $card0, ['user_id' => $ctx->userId > 0 ? $ctx->userId : null]);
        } catch (AssetException $e) {
            $p->warn(self::STEP, 'asset_rejected', "Karta majetku {$no} ({$name}) nepřevzata: " . $e->getMessage(), ['document_no' => (string) $no]);
            return;
        }
        $assetId = (int) $created['asset']['id'];
        $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_ASSET, $key, $assetId, $ctx->runId);
        if ($review !== []) {
            // Koncept nese zhodnocení i odpisy z Money, aby karta seděla na účty; ke kontrole
            // zůstává jen to, co převod neumí převzít.
            $p->count(self::STEP, 'drafts');
            $p->warn(self::STEP, 'asset_review', "Karta majetku {$no} ({$name}) převzata jako koncept ke kontrole: " . implode('; ', $review) . '.', ['document_no' => (string) $no]);
        } else {
            $p->count(self::STEP, 'created');
        }
        foreach ($improvements as $m) {
            $decrease = self::PRICE_MOVES[$m['Typ']] < 0;
            $this->db->pdo()->prepare(
                'INSERT INTO asset_improvements (supplier_id, asset_id, completed_on, amount, description) VALUES (?, ?, ?, ?, ?)'
            )->execute([$ctx->supplierId, $assetId, $m['Datum'], round(($decrease ? -1 : 1) * (float) $m['Castka'], 2),
                mb_substr(trim(($m['Popis'] ?: ($decrease ? 'Snížení ceny' : 'Zvýšení ceny')) . ($m['Doklad'] !== '' ? ' (' . $m['Doklad'] . ')' : '')), 0, 255)]);
            $p->count(self::STEP, 'improvements');
        }
        if ($plan !== []) {
            $p->count(self::STEP, 'tax_computed');
        }
        $this->depreciation($ctx, $assetId, $years, $list, $inUse, $disposal, $plan);
        if ($disposal !== null && $disposal <= $end) {
            $this->db->pdo()->prepare(
                "UPDATE assets SET status = 'disposed', disposal_date = ?, disposal_type = ? WHERE id = ? AND supplier_id = ?"
            )->execute([$disposal, self::disposalType((string) ($card['ZpVyrazeni'] ?? '')), $assetId, $ctx->supplierId]);
            $p->count(self::STEP, 'disposed');
        }
    }

    /**
     * Odpisy převedených let: účetní přesně podle Money (zaúčtované převzatým deníkem),
     * daňové potvrzené podle daňového plánu karty. Otevřený rok má účetní řádek za dosud
     * zaúčtované měsíce; daňový odpis roku vznikne až uzávěrkou roku (v Money po ní
     * opakovaným převodem).
     *
     * @param list<int> $years
     * @param list<array<string,mixed>> $list
     * @param array<int,array<string,mixed>> $plan daňový plán karty podle roku
     */
    private function depreciation(ImportContext $ctx, int $assetId, array $years, array $list, string $inUse, ?string $disposal, array $plan): void
    {
        $asset = $this->db->pdo()->prepare('SELECT status, tax_method FROM assets WHERE id = ? AND supplier_id = ?');
        $asset->execute([$assetId, $ctx->supplierId]);
        $row = $asset->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }
        $accMoves = self::accountingMoves($list, $disposal);
        foreach ($years as $year) {
            $from = sprintf('%04d-01-01', $year);
            $to = sprintf('%04d-12-31', $year);
            if ($inUse > $to || ($disposal !== null && $disposal < $from)) {
                continue;
            }
            $inYear = array_values(array_filter($accMoves, static fn (array $m): bool => $m['Datum'] >= $from && $m['Datum'] <= $to));
            $booked = round(array_sum(array_column($inYear, 'Castka')), 2);
            if ($booked > 0.0) {
                $months = [];
                foreach ($inYear as $m) {
                    $months[substr($m['Datum'], 0, 7)] = true;
                }
                $this->upsertMigrated($ctx, $assetId, 'accounting', $year, $booked, $booked, round((float) end($inYear)['ZustCena'], 2), count($months), false, 'posted');
            }
            $closed = $ctx->isLocked($year) || ($disposal !== null && $disposal <= $to) || $year < max($years);
            if (!$closed) {
                continue;
            }
            if ($row['tax_method'] === 'by_accounting') {
                $residual = $inYear !== [] ? round((float) end($inYear)['ZustCena'], 2) : 0.0;
                $this->upsertMigrated($ctx, $assetId, 'tax', $year, $booked, $booked, $residual, null, false, 'confirmed');
            } elseif (isset($plan[$year])) {
                $r = $plan[$year];
                $this->upsertMigrated($ctx, $assetId, 'tax', $year, (float) $r['amount'], (float) $r['full_amount'],
                    (float) $r['residual_end'], null, (bool) $r['is_half'], 'confirmed');
            }
        }
    }

    /**
     * Daňový způsob a skupina karty. Hmotný majetek podle `ZpusobOdpi` (Z zrychlený,
     * N a R rovnoměrný) a `OdpisSkupi`; nehmotný odpisuje daňově podle účetních odpisů;
     * karta bez odpisů (pozemky, skupina „N") se daňově neodpisuje.
     *
     * @param array<string,mixed> $card
     * @param list<array<string,mixed>> $list
     * @return array{0:string,1:?int}
     */
    /**
     * Karta „jen ÚČETNÍ odpis": interní záznam Money pro účetní odpisy bez vazby na kartu
     * majetku. Nese hodnotu na majetkovém účtu a účetní odpisy, daňově se neodpisuje.
     *
     * @param array<string,mixed> $card
     */
    private static function accountingOnly(array $card): bool
    {
        return preg_match('/jen\s+[uú]četn[ií]/iu', (string) ($card['Nazev'] ?? '')) === 1;
    }

    private static function taxSetup(array $card, array $list, ?string $disposal): array
    {
        if (self::accountingMoves($list, $disposal) === []) {
            return ['none', null];
        }
        if (self::accountingOnly($card)) {
            return ['none', null];
        }
        if (strtoupper(trim((string) ($card['Druh'] ?? ''))) === 'N') {
            return ['by_accounting', null];
        }
        $group = (int) trim((string) ($card['OdpisSkupi'] ?? ''));
        $method = self::TAX_METHODS[strtoupper(trim((string) ($card['ZpusobOdpi'] ?? '')))] ?? null;
        if ($method === null || $group < 1 || $group > 6) {
            return ['none', null];
        }
        return [$method, $group];
    }

    /**
     * Daňový plán karty od zařazení. Money daňové odpisy hmotného majetku neukládá, počítá
     * je z parametrů karty; stejně je spočte kalkulačka MyÚčta podle ZDP (§31, §32) ze
     * vstupní ceny ke dni zařazení a pozdějších zvýšení ceny jako technických zhodnocení.
     *
     * @param list<array<string,mixed>> $list
     * @return array<int,array<string,mixed>> rok => řádek plánu
     */
    private function taxPlan(string $method, ?int $group, array $list, string $inUse, ?string $disposal): array
    {
        if (!in_array($method, ['straight', 'accelerated'], true)) {
            return [];
        }
        $price = self::priceAt($list, $inUse);
        $improvements = [];
        foreach ($list as $m) {
            if ($m['Datum'] > $inUse && isset(self::PRICE_MOVES[$m['Typ']]) && self::PRICE_MOVES[$m['Typ']] > 0) {
                $improvements[] = ['completed_on' => $m['Datum'], 'amount' => $m['Castka']];
            }
        }
        if ($price <= 0.0) {
            return [];
        }
        $context = new DepreciationContext(
            inputPrice: $price,
            taxGroup: $group,
            firstYearIncrease: 'none',
            isFirstOwner: true,
            isM1Vehicle: false,
            m1LimitException: false,
            putIntoUseDate: $inUse,
            disposalDate: $disposal,
            accUsefulLifeMonths: null,
            accResidualValue: 0.0,
            openingTaxYears: 0,
            openingTaxAmount: 0.0,
            openingAccMonths: 0,
            openingAccAmount: 0.0,
            improvements: $improvements,
        );
        $plan = [];
        foreach ($this->calculator->plan($context, $method)['tax'] as $r) {
            $plan[(int) $r['fiscal_year']] = $r;
        }
        return $plan;
    }
    /**
     * Řádek odpisů převedeného roku. Řádek, který mezitím zaúčtovalo nebo potvrdilo MyÚčto
     * (ne převod), se nepřepisuje; převodem vzniklý se po opakovaném převodu srovná s Money.
     */
    private function upsertMigrated(ImportContext $ctx, int $assetId, string $kind, int $year, float $amount, float $full, float $residual, ?int $months, bool $half, string $status): void
    {
        $existing = $this->entries->findYear($assetId, $kind, $year);
        if ($existing !== null) {
            $own = $kind === 'accounting' ? DepreciationEntryRepository::isBookedByMigratedJournal($existing) : true;
            if (!$own || (abs((float) $existing['amount'] - $amount) < 0.005 && abs((float) $existing['residual_value_end'] - $residual) < 0.005)) {
                return;
            }
        }
        $this->entries->upsert([
            'supplier_id' => $ctx->supplierId,
            'asset_id' => $assetId,
            'kind' => $kind,
            'fiscal_year' => $year,
            'amount' => $amount,
            'full_amount' => $full,
            'residual_value_end' => max(0.0, $residual),
            'is_paused' => $full < 0.005,
            'is_half' => $half,
            'months_count' => $months,
            'detail' => $kind === 'accounting' ? json_encode(['journal' => DepreciationEntryRepository::MIGRATED_JOURNAL, 'program' => 'Money S3']) : null,
            'status' => $status,
        ]);
        $ctx->protocol->count(self::STEP, $kind === 'accounting' ? 'accounting_depreciation_booked' : 'tax_depreciation_confirmed');
    }

    /**
     * Karty a jejich pohyby podle data.
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<int,list<array<string,mixed>>>}
     */
    private function read(ImportContext $ctx): array
    {
        $cards = [];
        $table = $ctx->backup->table('MajInv');
        foreach ($table !== null && $table->hasData() ? $table->rows() : [] as $r) {
            $cards[(int) ($r['Cislo'] ?? 0)] = $r;
        }
        $moves = [];
        $table = $ctx->backup->table('MjInvPoh');
        foreach ($table !== null && $table->hasData() ? $table->rows() : [] as $r) {
            $date = self::date($r['Datum'] ?? null);
            if ($date === null) {
                continue;
            }
            $moves[(int) ($r['CisloMajet'] ?? 0)][] = [
                'Cislo' => (int) ($r['Cislo'] ?? 0),
                'Typ' => strtoupper(trim((string) ($r['Typ'] ?? ''))),
                'Datum' => $date,
                'Castka' => (float) ($r['Castka'] ?? 0),
                'ZustCena' => (float) ($r['ZustCena'] ?? 0),
                'Doklad' => trim((string) ($r['Doklad'] ?? '')),
                'Popis' => trim((string) ($r['Popis'] ?? '')),
                'PrUcMaj' => trim((string) ($r['PrUcMaj'] ?? '')),
                'PrUcOpr' => trim((string) ($r['PrUcOpr'] ?? '')),
            ];
        }
        foreach ($moves as &$list) {
            usort($list, static fn (array $a, array $b): int => [$a['Datum'], $a['Cislo']] <=> [$b['Datum'], $b['Cislo']]);
        }
        unset($list);
        return [$cards, $moves];
    }

    /** @return list<int> */
    private function years(ImportContext $ctx): array
    {
        $years = array_map('intval', array_keys($ctx->periods));
        sort($years);
        return $years;
    }

    /** První účet ze seznamu kódů Money, který je v osnově (analytika, jinak syntetika). */
    private function account(ImportContext $ctx, array $moneyCodes): ?string
    {
        foreach ($moneyCodes as $code) {
            $analytic = AccountCode::fromMoney($code);
            if ($analytic !== null && trim($code, '0') !== '' && isset($ctx->accountIds[$analytic])) {
                return $analytic;
            }
        }
        foreach ($moneyCodes as $code) {
            $synthetic = AccountCode::synthetic($code);
            if ($synthetic !== null && $synthetic !== '000' && isset($ctx->accountIds[$synthetic])) {
                return $synthetic;
            }
        }
        return null;
    }

    /** Účet pořízení (041 nehmotný, 042 hmotný): první analytika osnovy, jinak syntetika. */
    private function acquisitionAccount(ImportContext $ctx, string $kind): ?string
    {
        $prefix = $kind === 'intangible' ? '041' : '042';
        $codes = array_filter(array_keys($ctx->accountIds), static fn ($c): bool => str_starts_with((string) $c, $prefix . '.'));
        sort($codes);
        return $codes !== [] ? (string) $codes[0] : (isset($ctx->accountIds[$prefix]) ? $prefix : null);
    }

    /** Inventární číslo jedinečné ve firmě: Money dovolí shodná čísla, MyÚčto ne. */
    private function inventoryNumber(ImportContext $ctx, string $number, int $cardNo): string
    {
        $number = $number !== '' ? mb_substr($number, 0, 30) : 'MS3-' . $cardNo;
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM assets WHERE supplier_id = ? AND inventory_number = ?');
        $stmt->execute([$ctx->supplierId, $number]);
        return $stmt->fetchColumn() === false ? $number : mb_substr($number, 0, 30 - strlen('/' . $cardNo)) . '/' . $cardNo;
    }

    /**
     * Účetní odpisy karty: pohyby U, u karty bez nich (účetní odpis = daňový, typicky
     * nehmotný majetek) pohyby D. Odpis zůstatkové ceny ke dni vyřazení odpisem není.
     *
     * @param list<array<string,mixed>> $list
     * @return list<array<string,mixed>>
     */
    private static function accountingMoves(array $list, ?string $disposal): array
    {
        $u = array_values(array_filter($list, static fn (array $m): bool => $m['Typ'] === 'U'));
        return $u !== [] ? $u : array_values(array_filter($list,
            static fn (array $m): bool => $m['Typ'] === 'D' && $m['Datum'] !== $disposal));
    }




    /**
     * Doba účetního odpisování v měsících: uplynulé měsíce do posledního odpisu a zbytek
     * zůstatkové ceny posledním měsíčním odpisem.
     *
     * @param list<array<string,mixed>> $accMoves
     */
    private static function usefulLife(string $inUseMonth, array $accMoves): int
    {
        if ($accMoves === []) {
            return 1;
        }
        $lastMove = end($accMoves);
        $months = self::monthsBetween($inUseMonth, substr($lastMove['Datum'], 0, 7));
        if ($lastMove['ZustCena'] > 0.005 && $lastMove['Castka'] > 0.005) {
            $months += (int) ceil($lastMove['ZustCena'] / $lastMove['Castka']);
        }
        return max(1, $months);
    }

    /** @param list<array<string,mixed>> $list */
    private static function priceAt(array $list, string $date): float
    {
        $price = 0.0;
        foreach ($list as $m) {
            if ($m['Datum'] <= $date && isset(self::PRICE_MOVES[$m['Typ']])) {
                $price += self::PRICE_MOVES[$m['Typ']] * $m['Castka'];
            }
        }
        return round($price, 2);
    }

    /** @param list<array<string,mixed>> $list */
    private static function firstDate(array $list, string $type): ?string
    {
        foreach ($list as $m) {
            if ($m['Typ'] === $type) {
                return $m['Datum'];
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $list */
    private static function lastValue(array $list, string $field): ?string
    {
        $value = null;
        foreach ($list as $m) {
            if ($m[$field] !== '' && trim($m[$field], '0') !== '') {
                $value = $m[$field];
            }
        }
        return $value;
    }

    private static function disposalType(string $reason): string
    {
        $r = mb_strtoupper($reason);
        return match (true) {
            str_contains($r, 'PROD') => 'sold',
            str_contains($r, 'DAR') => 'donated',
            str_contains($r, 'MANK'), str_contains($r, 'ŠKOD'), str_contains($r, 'KRÁD') => 'damaged',
            default => 'liquidated',
        };
    }

    private static function monthsBetween(string $from, string $to): int
    {
        return ((int) substr($to, 0, 4) - (int) substr($from, 0, 4)) * 12 + (int) substr($to, 5, 2) - (int) substr($from, 5, 2);
    }

    private static function date(mixed $value): ?string
    {
        $v = (string) ($value ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v !== '' ? mb_substr($v, 0, $max) : null;
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' Kč';
    }
}
