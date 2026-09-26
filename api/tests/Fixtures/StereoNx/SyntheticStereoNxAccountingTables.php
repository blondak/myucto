<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\StereoNx;

/** Syntetická podvojná firma pro integrační test převodu Stereo NX. */
final class SyntheticStereoNxAccountingTables
{
    /** @return array{ico:string,dic:string,name:string,vat_payer:bool} */
    public static function identity(): array
    {
        return [
            'ico' => '87654321',
            'dic' => 'CZ87654321',
            'name' => 'Syntetická účetní společnost s.r.o.',
            'vat_payer' => true,
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    public static function tables(float $ordinaryAmount = 1250.50, float $correctionAmount = 50.25): array
    {
        $chart = [
            self::account('221', 'Bankovní účty', 'R'),
            self::account('221kb', 'Syntetická korunová banka', 'R'),
            self::account('321', 'Dodavatelé', 'P'),
            self::account('353', 'Pohledávky za upsaný kapitál', 'A'),
            self::account('353001', 'Syntetická pohledávka', 'A'),
            self::account('411', 'Základní kapitál', 'P'),
            self::account('411010', 'Syntetický základní kapitál', 'P'),
            self::account('701', 'Počáteční účet rozvažný', 'Z', false),
        ];
        $journal = [
            self::entry(1, 1, '2026-01-01', '2025', '701', '411010', 10000.00, 'PMO', 'ps0002', 'Počáteční stav kapitálu'),
            self::entry(2, 1, '2026-01-01', '2025', '353001', '701', 10000.00, 'PMO', 'ps0001', 'Počáteční stav pohledávky'),
            self::entry(3, 1, '2026-02-03', '2025', '221kb', '321', $ordinaryAmount, 'FAD', 'fa0001', 'Úhrada syntetické faktury'),
            self::entry(4, 1, '2026-02-04', '2025', '221kb', '321', $correctionAmount, 'FAD', 'fa0002', 'Oprava syntetické úhrady'),
        ];

        return [
            'LAdresy' => [],
            'SCenik' => [],
            'SSklady' => [],
            'SParamSkl' => [],
            'Kauta' => [],
            'Kcesty' => [],
            'LFirma' => [[
                'NeniUcetniJednotkou' => false,
                'DruhPS' => 'PMO',
                'UcetPocatecniRozvazny' => '701',
            ]],
            'Lrozvrh' => $chart,
            'Cdenik' => $journal,
            'JMajetek' => [],
            'JDrobMaj' => [],
            'JDanOdpisy' => [],
            'JUcOdpisy' => [],
            'JTechZhod' => [],
            'MZAMEST' => [],
            'MMzdy' => [],
            'MPOJIST' => [],
            'MDeti' => [],
            'MOpNezdC' => [],
            'MDovol' => [],
            'MPRVYD' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function account(string $code, string $name, string $type, bool $tax = true): array
    {
        return ['Ucet' => $code, 'Nazev' => $name, 'TypUctu' => $type, 'Danovy' => $tax];
    }

    /** @return array<string,mixed> */
    private static function entry(
        int $key,
        int $order,
        string $date,
        string $sourceYear,
        string $debit,
        string $credit,
        float $amount,
        string $kind,
        string $document,
        string $text,
    ): array {
        return [
            'Agenda' => 'U',
            'DoklRada' => 'UCT',
            'DoklCislo' => (string) $key,
            'Klic' => $key,
            'Poradi' => $order,
            'KdyUcPripad' => $date,
            'Rok' => (int) $sourceYear,
            'Mesic' => (int) substr($date, 5, 2),
            'UcetMD' => $debit,
            'UcetD' => $credit,
            'Celkem' => $amount,
            'DPH' => 0.0,
            'Druh' => $kind,
            'Doklad' => $document,
            'Text' => $text,
            'Stredisko' => '',
            'Vykon' => '',
            'Zakazka' => '',
        ];
    }
}
