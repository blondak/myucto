<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Support\CompanyIdNormalizer;

final class PohodaMdbAccounting
{
    public const FILE = '89_ucetnictvi_mdb.xml';

    private const INVOICES = [
        1 => 'issued',
        4 => 'issued_advance',
        5 => 'receivable',
        6 => 'issued_proforma',
        8 => 'issued_corrective',
        11 => 'received',
        14 => 'received_advance',
        15 => 'commitment',
        18 => 'received_corrective',
    ];
    private const SOURCES = [
        2 => PohodaJournal::ISSUED,
        3 => PohodaJournal::RECEIVED,
        5 => PohodaJournal::ASSETS,
        18 => PohodaJournal::RECEIVABLE,
        19 => PohodaJournal::COMMITMENT,
        27 => PohodaJournal::CASH,
        28 => PohodaJournal::BANK,
        29 => PohodaJournal::INTERNAL,
        63 => PohodaJournal::OPENING,
    ];
    private const LINKS = [
        2 => 'issuedInvoice',
        3 => 'receivedInvoice',
        18 => 'receivable',
        19 => 'commitment',
        27 => 'voucher',
        28 => 'bank',
        29 => 'internalDocuments',
        43 => 'issuedAdvanceInvoice',
        44 => 'receivedAdvanceInvoice',
    ];
    private const TABLES = [
        'journal' => 'pUD',
        'chart' => 'pOS',
        'posting_rules' => 'pPK',
        'vat_classes' => 'sDPH',
        'addressbook' => 'AD',
        'bank_accounts' => 'sUcet',
        'cash_registers' => 'sUcet',
        'bank' => 'BV',
        'cash' => 'HO',
        'internal' => 'pINT',
    ];

    private array $indexes = [];
    private array $groups = [];

    public function __construct(public readonly PohodaMdbTables $tables)
    {
        foreach (['sKonfig', 'pUD', 'pOS', 'sDPH', 'sDPHTp', 'pPK', 'FA', 'FApol', 'AD', 'sUcet', 'sZeme', 'sCMeny', 'sFormUh', 'BV', 'BVpol', 'HO', 'HOpol', 'pINT', 'pINTpol', 'Uhrady'] as $table) {
            if (!$tables->has($table)) {
                throw new PohodaException('mdb_incomplete', 'MDB export neobsahuje všechny povinné účetní tabulky. Vytvořte nový ZIP převodníkem.');
            }
        }
        $config = iterator_to_array($tables->rows('sKonfig'), false);
        if (count($config) !== 1 || CompanyIdNormalizer::ic($config[0]['ICO'] ?? '') !== CompanyIdNormalizer::ic($tables->ico) || (int) ($config[0]['Rok'] ?? 0) !== $tables->year) {
            throw new PohodaException('mdb_source_mismatch', 'Firma nebo rok neodpovídá údajům zdrojové MDB. Vytvořte nový export.');
        }
        $this->validate();
    }

    private function validate(): void
    {
        foreach ($this->tables->rows('pUD') as $r) {
            if (!isset(self::SOURCES[(int) ($r['RelUdAg'] ?? 0)])) {
                throw new PohodaException('mdb_journal_source', 'MDB obsahuje dosud nepodporovaný zdroj účetního zápisu. Použijte standardní XML export.');
            }
        }
        foreach (['FA', 'BV', 'HO', 'pINT'] as $table) {
            foreach ($this->tables->rows($table) as $r) {
                $this->lowRate($r);
                if (in_array(strtolower($r['HistSzDPH'] ?? ''), ['true', '1', '-1'], true)) {
                    throw new PohodaException('mdb_historical_vat', 'Doklad s historickými sazbami vyžaduje standardní XML export.');
                }
                if ($table === 'FA' && (($r['Cislo'] ?? '') !== '' || ($r['Datum'] ?? '') !== '') && !isset(self::INVOICES[(int) ($r['RelTpFak'] ?? 0)])) {
                    throw new PohodaException('mdb_invoice_type', 'MDB obsahuje dosud nepodporovaný typ faktury. Použijte standardní XML export.');
                }
                if ($table === 'BV' || $table === 'HO') {
                    $this->direction($r['RelTp' . $table] ?? '');
                }
            }
        }
    }

    public function has(string $key): bool
    {
        return isset(self::TABLES[$key]) || in_array($key, array_values(self::INVOICES), true);
    }

    public function records(string $key): \Generator
    {
        if (in_array($key, array_values(self::INVOICES), true)) {
            foreach ($this->tables->rows('FA') as $r) {
                if ($this->isPlaceholder($r)) {
                    continue;
                }
                $kind = self::INVOICES[(int) ($r['RelTpFak'] ?? 0)] ?? null;
                if ($kind === null) {
                    throw new PohodaException('mdb_invoice_type', 'MDB obsahuje dosud nepodporovaný typ faktury. Použijte standardní XML export.');
                }
                if ($kind === $key) {
                    yield $this->document($r, 'invoice', 'FApol');
                }
            }
            return;
        }
        $table = self::TABLES[$key] ?? null;
        if ($table === null) {
            return;
        }
        foreach ($this->tables->rows($table) as $r) {
            if ($key === 'journal') {
                $source = self::SOURCES[(int) ($r['RelUdAg'] ?? 0)] ?? null;
                if ($source === null) {
                    throw new PohodaException('mdb_journal_source', 'MDB obsahuje dosud nepodporovaný zdroj účetního zápisu. Použijte standardní XML export.');
                }
                yield [
                    'id' => $r['ID'],
                    'source' => $source,
                    'number' => ['numberRequested' => $r['Cislo'] ?? ''],
                    'date' => $this->date($r, 'Datum'),
                    'dateTax' => $this->date($r, 'DatZdPln'),
                    'text' => $r['SText'] ?? '',
                    'homeCurrency' => ['priceSum' => $r['Kc'] ?? '0'],
                    'accounting' => ['credit' => $r['UMD'] ?? '', 'debit' => $r['UD'] ?? ''],
                ];
            } elseif ($key === 'chart') {
                yield ['@code' => $r['Ucet'] ?? '', '@name' => $r['Nazev'] ?? ''];
            } elseif ($key === 'posting_rules') {
                if (($r['RelPkAg'] ?? '') === '0') {
                    continue;
                }
                yield ['@code' => $r['IDS'] ?? '', '@accounting' => $r['SText'] ?? '', '@debit' => $r['UMD'] ?? '', '@credit' => $r['UD'] ?? ''];
            } elseif ($key === 'vat_classes') {
                $type = $this->lookup('sDPHTp', $r['RefTpDph'] ?? '');
                $section = [
                    1 => 'Nezahrnovat',
                    2 => 'A.1.',
                    3 => 'A.2.',
                    4 => 'A.3.',
                    5 => 'A.4.',
                    6 => 'A.4., A.5.',
                    7 => 'A.5.',
                    8 => 'B.1.',
                    9 => 'B.2.',
                    10 => 'B.2., B.3.',
                    11 => 'B.3.',
                ][(int) ($r['RelVlivKHDPH'] ?? 0)] ?? null;
                if ($section === null || $type === []) {
                    throw new PohodaException('mdb_vat_type', 'Členění DPH v MDB exportu nelze bezpečně určit. Použijte standardní XML export.');
                }
                yield ['classificationVATHeader' => ['id' => $r['ID'], 'code' => $r['IDS'] ?? '', 'name' => $r['SText'] ?? '', 'lineInVATReturn' => $type['Radky'] ?? '', 'sectionInVATLedgerStatement' => $section]];
            } elseif ($key === 'addressbook') {
                yield ['addressbookHeader' => ['id' => $r['ID'], 'identity' => $this->identity($r), 'email' => $r['Email'] ?? '', 'mobil' => $r['GSM'] ?? '', 'phone' => $r['Tel'] ?? '']];
            } elseif ($key === 'bank_accounts' && (int) ($r['RelJeUcet'] ?? 0) === 2) {
                yield ['bankAccountHeader' => ['id' => $r['ID'], 'ids' => $r['IDS'] ?? '', 'numberAccount' => $r['SText'] ?? '', 'codeBank' => $r['KodBanky'] ?? '', 'nameBank' => $r['Banka'] ?? '', 'IBAN' => $r['IBAN'] ?? '', 'analyticAccount' => ['ids' => $r['AUcet'] ?? '']]];
            } elseif ($key === 'cash_registers' && (int) ($r['RelJeUcet'] ?? 0) === 1) {
                yield ['cashRegisterHeader' => ['id' => $r['ID'], 'ids' => $r['IDS'] ?? '', 'name' => $r['SText'] ?? '', 'account' => ['ids' => $r['AUcet'] ?? '']]];
            } elseif ($key === 'internal') {
                yield $this->document($r, 'intDoc', 'pINTpol');
            } elseif ($key === 'cash') {
                $d = $this->document($r, 'voucher', 'HOpol');
                $d['voucherHeader']['voucherType'] = $this->direction($r['RelTpHO'] ?? '');
                $d['voucherHeader']['cashAccount'] = $this->reference('sUcet', $r['RefUcet'] ?? '');
                $d['voucherHeader']['datePayment'] = $this->date($r, 'DatPlat');
                yield $d;
            } elseif ($key === 'bank') {
                $d = $this->document($r, 'bank', 'BVpol');
                $d['bankHeader']['bankType'] = $this->direction($r['RelTpBV'] ?? '');
                $d['bankHeader']['number'] = $r['Cislo'] ?? '';
                $d['bankHeader']['symPar'] = $r['ParSym'] ?? '';
                $d['bankHeader']['account'] = $this->reference('sUcet', $r['RefUcet'] ?? '');
                $d['bankHeader']['dateStatement'] = $this->date($r, 'Datum');
                $d['bankHeader']['datePayment'] = $this->date($r, 'DatPlat');
                $parts = explode('/', $r['Vypis'] ?? '');
                $d['bankHeader']['statementNumber'] = ['statementNumber' => $parts[0], 'numberMovement' => $parts[1] ?? ''];
                yield $d;
            }
        }
    }

    private function document(array $r, string $prefix, string $itemsTable): array
    {
        if (in_array(strtolower($r['HistSzDPH'] ?? ''), ['true', '1', '-1'], true)) {
            throw new PohodaException('mdb_historical_vat', 'Doklad s historickými sazbami vyžaduje standardní XML export.');
        }
        $payment = $this->lookup('sFormUh', $r['RelForUh'] ?? '');
        $h = [
            'id' => $r['ID'],
            'number' => ['numberRequested' => $r['Cislo'] ?? ''],
            'date' => $this->date($r, 'Datum'),
            'dateTax' => $this->date($r, 'DatZdPln'),
            'dateAccounting' => $this->date($r, 'DatUcP'),
            'dateDue' => $this->date($r, 'DatSplat'),
            'dateKHDPH' => $this->date($r, 'DatKHDPH'),
            'text' => $r['SText'] ?? '',
            'symVar' => $r['VarSym'] ?? '',
            'symConst' => $r['KonstSym'] ?? '',
            'symSpec' => $r['SpecSym'] ?? '',
            'originalDocument' => $r['PDoklad'] ?? '',
            'numberKHDPH' => $r['CisloKHDPH'] ?? '',
            'accounting' => $this->accounting($r['RelPk'] ?? ''),
            'classificationVAT' => $this->reference('sDPH', $r['RelTpDPH'] ?? ''),
            'partnerIdentity' => $this->identity($r),
            'paymentAccount' => ['accountNo' => $r['Ucet'] ?? '', 'bankCode' => $r['KodBanky'] ?? ''],
            'paymentType' => ['paymentType' => [1 => 'cash', 2 => 'creditcard', 3 => 'draft', 4 => 'advance', 5 => 'other', 6 => 'compensation', 7 => 'other', 8 => 'compensation', 9 => 'delivery'][(int) ($payment['RelTyp'] ?? 0)] ?? ''],
            'liquidation' => ['amountHome' => $r['KcLikv'] ?? '0', 'date' => $this->date($r, 'DatLikv')],
            'note' => $r['Pozn'] ?? '',
        ];
        $d = [$prefix . 'Header' => $h, $prefix . 'Summary' => ['homeCurrency' => $this->summary($r)]];
        if ($prefix !== 'invoice') {
            unset($d[$prefix . 'Header']['liquidation']);
        }
        $currency = $this->lookup('sCMeny', $r['RefCM'] ?? '');
        if ($currency !== []) {
            $d[$prefix . 'Summary']['foreignCurrency'] = [
                'currency' => ['ids' => $currency['Kod'] ?? ''],
                'rate' => $r['CmKurs'] ?? '',
                'amount' => $r['CmMnoz'] ?? '',
                'priceSum' => $r['CmCelkem'] ?? '0',
            ];
        }
        foreach ($this->group($itemsTable, 'RefAg')[$r['ID']] ?? [] as $it) {
            $advance = $prefix === 'invoice' && in_array((int) ($it['RelAgID'] ?? 0), [-1, 43, 44], true);
            if ($prefix === 'invoice' && (int) ($it['RelAgID'] ?? 0) === -1001) {
                continue;
            }
            $item = $this->item($it, $r);
            if ($prefix === 'bank' && ($it['ParSym'] ?? '') !== '') {
                $item['symPar'] = $it['ParSym'];
            }
            if ($advance) {
                $linked = $this->lookup('FA', $it['RefPol'] ?? '');
                $item['note'] = $linked['Cislo'] ?? ($it['SText'] ?? '');
                $item['sourceDocument'] = ['id' => $linked['ID'] ?? '', 'number' => $linked['Cislo'] ?? ''];
            }
            $tag = $advance ? 'invoiceAdvancePaymentItem' : $prefix . 'Item';
            $d[$prefix . 'Detail'][$tag][] = $item;
            if ($prefix === 'intDoc' && in_array((int) ($it['RelAgID'] ?? 0), [2, 3, 18, 19], true)) {
                $linked = $this->lookup('FA', $it['RefPol'] ?? '');
                if ($linked !== []) {
                    $d['linkedDocuments']['link'][] = [
                        'sourceAgenda' => self::LINKS[(int) $it['RelAgID']],
                        'sourceDocument' => ['id' => $linked['ID'], 'number' => $linked['Cislo'] ?? ''],
                    ];
                }
            }
        }
        if ($prefix === 'invoice') {
            $source = match ((int) $r['RelTpFak']) { 1, 8 => 2, 4, 6 => 43, 5 => 18, 11, 18 => 3, 14 => 44, 15 => 19 };
            foreach ($this->group('Uhrady', 'RelIDH')[$r['ID']] ?? [] as $u) {
                if ((int) ($u['RelAgH'] ?? 0) !== $source) {
                    continue;
                }
                $agenda = (int) ($u['RelAgU'] ?? 0);
                if ($agenda !== 0 && !isset(self::LINKS[$agenda])) {
                    throw new PohodaException('mdb_payment_source', 'MDB obsahuje nepodporovaný zdroj úhrady. Použijte standardní XML export.');
                }
                $d['liquidations']['liquidation'][] = [
                    'id' => $u['ID'],
                    'date' => $this->date($u, 'DatumU'),
                    'sourceAgenda' => self::LINKS[$agenda] ?? '',
                    'sourceDocument' => $this->paymentSource($u, $agenda),
                    'amount' => $u['KcU'] ?? '0',
                ];
            }
        }
        if (isset($d['linkedDocuments']['link'])) {
            $unique = [];
            foreach ($d['linkedDocuments']['link'] as $link) {
                $unique[$link['sourceAgenda'] . ':' . $link['sourceDocument']['id']] = $link;
            }
            $d['linkedDocuments']['link'] = array_values($unique);
        }
        return $d;
    }

    /**
     * Doklad, kterým byla úhrada zaplacena. Bankovní úhradu váže POHODA oběma směry
     * (`Uhrady.RelIDU` na bankovní doklad a `BVpol.RelIDUhrady` z položky pohybu na úhradu);
     * číslo dokladu v úhradě (`CisloU`) je jen opis a číselná řada banky se po letech opakuje.
     * Rozhoduje proto vazba na id, číslo se bere z nalezeného bankovního dokladu. Export bez
     * sloupce `RelIDUhrady` (starší převodník) se čte jako dřív.
     *
     * @return array{id:string,number:string}
     */
    private function paymentSource(array $u, int $agenda): array
    {
        $id = (string) ($u['RelIDU'] ?? '');
        $number = (string) ($u['CisloU'] ?? '');
        if ($agenda !== 28) {
            return ['id' => $id, 'number' => $number];
        }
        $bank = $this->lookup('BV', $id);
        if ($bank === []) {
            foreach ($this->group('BVpol', 'RelIDUhrady')[(string) ($u['ID'] ?? '')] ?? [] as $item) {
                $bank = $this->lookup('BV', (string) ($item['RefAg'] ?? ''));
                if ($bank !== []) {
                    break;
                }
            }
        }
        return $bank === [] ? ['id' => $id, 'number' => $number] : ['id' => (string) $bank['ID'], 'number' => (string) ($bank['Cislo'] ?? $number)];
    }

    private function summary(array $r): array
    {
        $out = ['priceNone' => $r['Kc0'] ?? '0', 'round' => ['priceRound' => $r['KcZaokr'] ?? '0']];
        foreach ([1 => ['Low', $this->lowRate($r)], 2 => ['High', 21], 3 => ['3', 10]] as $slot => [$name, $rate]) {
            $out['price' . $name] = $r['Kc' . $slot] ?? '0';
            $out['price' . $name . 'VAT'] = ['#' => $r['KcDPH' . $slot] ?? '0', '@rate' => (string) $rate];
            $out['price' . $name . 'Sum'] = (string) ((float) ($r['Kc' . $slot] ?? 0) + (float) ($r['KcDPH' . $slot] ?? 0));
        }
        return $out;
    }

    private function item(array $r, array $header): array
    {
        $slot = (int) ($r['RelSzDPH'] ?? 0);
        $rate = $r['ProcentoDPH'] ?? ([0 => '0', 1 => (string) $this->lowRate($header), 2 => '21', 3 => '10'][$slot] ?? null);
        if ($rate === null) {
            throw new PohodaException('mdb_item_vat', 'Sazbu položky z MDB nelze bezpečně určit. Použijte standardní XML export.');
        }
        return [
            'id' => $r['ID'],
            'text' => $r['SText'] ?? '',
            'quantity' => $r['Mnozstvi'] ?? '1',
            'unit' => $r['MJ'] ?? '',
            'rateVAT' => ['#' => [0 => 'none', 1 => 'low', 2 => 'high', 3 => 'third'][$slot] ?? '', '@value' => $rate],
            'homeCurrency' => ['unitPrice' => $r['KcJedn'] ?? '0', 'price' => $r['Kc'] ?? '0', 'priceVAT' => $r['KcDPH'] ?? '0', 'priceSum' => (string) ((float) ($r['Kc'] ?? 0) + (float) ($r['KcDPH'] ?? 0))],
            'classificationVAT' => $this->reference('sDPH', $r['RelTpDPH'] ?? ''),
        ];
    }

    private function identity(array $r): array
    {
        $country = $this->lookup('sZeme', $r['RefZeme'] ?? '');
        return [
            'id' => $r['RefAD'] ?? '',
            'address' => ['company' => $r['Firma'] ?? '', 'name' => $r['Jmeno'] ?? '', 'division' => $r['Utvar'] ?? '', 'street' => $r['Ulice'] ?? '', 'city' => $r['Obec'] ?? '', 'zip' => $r['PSC'] ?? '', 'ico' => $r['ICO'] ?? '', 'dic' => $r['DIC'] ?? '', 'country' => ['ids' => $country['IDS'] ?? '']],
        ];
    }

    private function accounting(string $id): array
    {
        $out = $this->reference('pPK', $id);
        if ($id === '3') {
            $out['accountingType'] = 'withoutAccounting';
        }
        return $out;
    }

    private function reference(string $table, string $id): array
    {
        $r = $this->lookup($table, $id);
        return ['id' => $id, 'ids' => $r['IDS'] ?? ''];
    }

    private function lookup(string $table, string $id): array
    {
        if ($id === '' || $id === '0') {
            return [];
        }
        if (!isset($this->indexes[$table])) {
            $this->indexes[$table] = [];
            foreach ($this->tables->rows($table) as $r) {
                $this->indexes[$table][$r['ID']] = $r;
            }
        }
        return $this->indexes[$table][$id] ?? [];
    }

    private function group(string $table, string $column): array
    {
        $key = $table . ':' . $column;
        if (!isset($this->groups[$key])) {
            $this->groups[$key] = [];
            foreach ($this->tables->rows($table) as $r) {
                $this->groups[$key][$r[$column] ?? ''][] = $r;
            }
            if (str_ends_with($table, 'pol')) {
                foreach ($this->groups[$key] as &$rows) {
                    usort($rows, static fn (array $a, array $b): int => ((float) ($a['OrderFld'] ?? 0) <=> (float) ($b['OrderFld'] ?? 0)) ?: ((int) ($a['ID'] ?? 0) <=> (int) ($b['ID'] ?? 0)));
                }
                unset($rows);
            }
        }
        return $this->groups[$key];
    }

    private function date(array $r, string $key): string
    {
        return substr($r[$key] ?? '', 0, 10);
    }

    private function direction(string $type): string
    {
        return match ($type) { '1' => 'receipt', '2' => 'expense', default => throw new PohodaException('mdb_direction', 'Neznámý směr dokladu v MDB exportu.') };
    }

    private function lowRate(array $r): int
    {
        $date = ($r['DatZdPln'] ?? '') ?: ($r['Datum'] ?? '');
        if ($date !== '' && substr($date, 0, 4) < '2013') {
            throw new PohodaException('mdb_historical_vat', 'Doklad se staršími sazbami vyžaduje standardní XML export.');
        }
        return $date !== '' && substr($date, 0, 4) < '2024' ? 15 : 12;
    }

    private function isPlaceholder(array $r): bool
    {
        if (($r['Cislo'] ?? '') !== '') {
            return false;
        }
        foreach (['Kc0', 'Kc1', 'Kc2', 'Kc3', 'KcDPH1', 'KcDPH2', 'KcDPH3', 'KcZaokr'] as $key) {
            if ((float) ($r[$key] ?? 0) !== 0.0) {
                return false;
            }
        }
        return ($this->group('FApol', 'RefAg')[$r['ID']] ?? []) === [];
    }
}
