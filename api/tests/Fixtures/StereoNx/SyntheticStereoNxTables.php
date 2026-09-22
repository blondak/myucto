<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\StereoNx;

/** Obchodní údaje níže jsou výhradně smyšlené pro test převodu. */
final class SyntheticStereoNxTables
{
    /** @return array{ico:string,dic:string,name:string,vat_payer:bool} */
    public static function identity(): array
    {
        return ['ico' => '12345678', 'dic' => 'CZ12345678', 'name' => 'Syntetická účetní jednotka s.r.o.', 'vat_payer' => true];
    }

    /** @return array<string,list<array<string,mixed>>> */
    public static function tables(): array
    {
        $day = '2025-03-15';
        $vat = static function (string $code, string $direction, int $first, ?int $deduction = null): array {
            $row = ['TypDPH' => $code, 'Plneni' => $direction, 'Kraceni' => false, 'Moss' => false];
            foreach (['Z', 'S', 'T', '0'] as $slot) {
                foreach (['Zaklad', 'Dan'] as $kind) {
                    $line = $slot === 'Z' ? $first : ($slot === 'S' || $slot === 'T' ? $first + 1 : null);
                    $row['E19Radek' . $kind . $slot] = $line === null ? 'NE' : (string) $line;
                    $row['E19Radek' . $kind . $slot . 'x'] = $slot === 'Z' && $deduction !== null ? (string) $deduction : '';
                }
            }
            return $row;
        };
        $header = static function (string $series, int $number, string $agenda, string $code,
            float $base, float $tax, float $total, string $partner, ?bool $vatFlag = true) use ($day): array {
            return ['DoklSRada' => $series, 'DoklSCislo' => $number, 'DokladS' => $series . '-' . $number,
                'Agenda' => $agenda, 'TypDokladu' => 'F', 'Stornovano' => false,
                'Mena' => 'Kč', 'Kurz' => 1.0, 'KurzMn' => 1.0, 'Zalohy' => 0.0,
                'CenySDPH' => false, 'ZpracovatDPH' => $vatFlag, 'TypDPH' => $code,
                'Text' => 'Syntetická služba', 'Firma' => $partner, 'FirmaNazev' => '',
                'KdyVyhotUD' => $day, 'KdyUskutUP' => $day, 'DatumDPH' => $day,
                'DatumSpl' => '2025-03-29', 'VarSym' => (string) (20000000 + $number),
                'ZaklDPHz' => $base, 'DPHz' => $tax, 'SazbaDPHz' => 21.0,
                'ZaklDPHs' => 0.0, 'DPHs' => 0.0, 'SazbaDPHs' => 12.0,
                'ZaklDPHt' => 0.0, 'DPHt' => 0.0, 'SazbaDPHt' => 0.0,
                'BezDane' => 0.0, 'Zaokrouhleni' => 0.0, 'Celkem' => $total,
                'EvidCislo' => $agenda === 'PF' ? 'SYN-V-' . $number : ''];
        };
        $issued = $header('VF', 1, 'VF', 'SALE', 100.0, 21.0, 121.0, 'C1');
        $purchase = $header('PF', 1, 'PF', 'BUY', 100.0, 21.0, 121.0, 'V1');
        $reverse = $header('PF', 2, 'PF', 'REVERSE', 100.0, 21.0, 100.0, 'V1', null);
        $reverse['CenySDPH'] = true;
        $invoiceLine = ['DoklSRada' => 'VF', 'DoklSCislo' => 1, 'Klic' => 1,
            'Text' => 'Syntetická služba', 'Stornovano' => false, 'Zaloha' => false,
            'ZalohaProforma' => false, 'TypSazby' => 'Z', 'Mnozstvi' => 1.0,
            'JednCena' => 100.0, 'ProcSlevy' => 0.0, 'ZakladDPH' => 100.0,
            'CelkemDPH' => 21.0, 'SazbaDPH' => 21.0];
        $cpz = static function (array $doc, string $direction) use ($day): array {
            return ['DoklSRada' => $doc['DoklSRada'], 'DoklSCislo' => $doc['DoklSCislo'],
                'SmerPlatby' => $direction, 'Mena' => 'Kč', 'Kurz' => 1.0, 'KurzMn' => 1.0,
                'Uhrazeno' => $doc['Celkem'], 'UhrazenoVse' => true,
                'Celkem' => $doc['Celkem'], 'KdyVystaveno' => $day];
        };
        $movement = static function (int $line, string $direction, float $amount,
            string $column, ?array $document = null) use ($day): array {
            return ['DoklRada' => 'B', 'DoklCislo' => 1, 'Klic' => $line,
                'DoklSRada' => $document['DoklSRada'] ?? '',
                'DoklSCislo' => $document['DoklSCislo'] ?? 0,
                'SmerPlatby' => $direction, 'Castka' => $amount, 'MenaCizi' => '',
                'KdyUcPripad' => $day, 'Sloupec' => $column,
                'Text' => 'Syntetický bankovní pohyb', 'VarSym' => (string) (20000000 + $line),
                'BaUcet' => '1000000005', 'KodBanky' => '0100'];
        };
        $bank = [
            $movement(1, 'P', 121.0, '10', $issued),
            $movement(2, 'V', 121.0, '16', $purchase),
            $movement(3, 'V', 100.0, '16', $reverse),
            $movement(4, 'V', 10.0, '16'),
            $movement(5, 'P', 50.0, '20'),
        ];
        $cash = ['DoklRada' => 'P', 'DoklCislo' => 1, 'Doklad' => 'P-1',
            'DoklSRada' => '', 'DoklSCislo' => 0, 'KdyUcPripad' => $day,
            'KdyVystaveno' => $day, 'SmerPlatby' => 'P', 'Castka' => 20.0,
            'Sloupec' => '20', 'Text' => 'Syntetický pokladní příjem', 'VarSym' => ''];
        $journal = [];
        foreach ($bank as $row) {
            $journal[] = ['Agenda' => 'B', 'DoklRada' => $row['DoklRada'],
                'DoklCislo' => $row['DoklCislo'], 'Klic' => $row['Klic'],
                'KdyUcPripad' => $day, 'Celkem' => $row['Castka'], 'Sloupec' => $row['Sloupec'],
                'SmerPlatby' => $row['SmerPlatby'], 'DPH' => 0.0];
        }
        $journal[] = ['Agenda' => 'P', 'DoklRada' => 'P', 'DoklCislo' => 1,
            'KdyUcPripad' => $day, 'Celkem' => 20.0, 'Sloupec' => '20',
            'SmerPlatby' => 'P', 'DPH' => 0.0];
        return [
            'LAdresy' => [
                ['Firma' => 'C1', 'Nazev' => 'Syntetický odběratel', 'ICO' => '11111111',
                    'DIC' => 'CZ11111111', 'Ulice' => 'Testovací 1', 'Misto' => 'Vzorov',
                    'PSC' => '10000', 'Stat' => 'CZ', 'Email' => 'customer@example.invalid',
                    'Telefon' => '', 'Odberatel' => true, 'Dodavatel' => false],
                ['Firma' => 'V1', 'Nazev' => 'Syntetický dodavatel', 'ICO' => '22222222',
                    'DIC' => 'CZ22222222', 'Ulice' => 'Testovací 2', 'Misto' => 'Vzorov',
                    'PSC' => '10000', 'Stat' => 'CZ', 'Email' => 'vendor@example.invalid',
                    'Telefon' => '', 'Odberatel' => false, 'Dodavatel' => true],
            ],
            'LFirmaUc' => [['DoklRada' => 'B', 'NazevUctu' => 'Syntetický účet',
                'BaUcet' => '1000000013', 'KodBanky' => '0800', 'IBAN' => '']],
            'Lsdph' => [$vat('SALE', 'U', 1), $vat('BUY', 'P', 40), $vat('REVERSE', 'P', 12, 43)],
            'LSloupce' => [['Sloupec' => '10', 'Typ' => 'P'], ['Sloupec' => '16', 'Typ' => 'V'],
                ['Sloupec' => '20', 'Typ' => 'OP']],
            'Svfh' => [$issued], 'Svfp' => [$invoiceLine],
            'SPFH' => [$purchase, $reverse], 'Spfp' => [],
            'Cpz' => [$cpz($issued, 'P'), $cpz($purchase, 'V'), $cpz($reverse, 'V')],
            'CPZZ' => [
                ['DoklSRada' => 'VF', 'DoklSCislo' => 1, 'SmerPlatby' => 'P'],
                ['DoklSRada' => 'PF', 'DoklSCislo' => 1, 'SmerPlatby' => 'V'],
                ['DoklSRada' => 'PF', 'DoklSCislo' => 2, 'SmerPlatby' => 'V'],
            ],
            'ZAZPVDPH' => [],
            'CBanka' => [['DoklRada' => 'B', 'DoklCislo' => 1, 'Doklad' => 'B-1',
                'KdyVystaveno' => $day]],
            'CBankap' => $bank, 'CPokl' => [$cash], 'Cdenik' => $journal,
        ];
    }
}
