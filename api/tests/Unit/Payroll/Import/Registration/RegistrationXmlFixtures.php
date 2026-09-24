<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Registration;

/**
 * Syntetické registrace ČSSZ podle připnutých schémat REGZEC25 a PREZEC26.
 * Jména, rodná čísla, OIČ i ID PPV jsou vymyšlená; rodné číslo i OIČ projdou
 * kontrolou dělitelnosti jedenácti.
 */
final class RegistrationXmlFixtures
{
    /** @param array<string,string|null> $options */
    public static function regzecA1(array $options = []): string
    {
        $o = $options + [
            'bno' => self::birthNumber('1990-01-15', 'female', 1),
            'first' => 'Jana',
            'last' => 'Testovací',
            'tit' => 'Ing.',
            'birth_date' => '1990-01-15',
            'birth_surname' => 'Zkušební',
            'birth_place' => 'Testov',
            'sex' => 'Ž',
            'start' => '2026-07-01',
            'rel' => '1',
            'detail' => '1',
            'clas' => '43111',
            'workplace' => 'Hlavní město Praha',
            'municode' => '554782',
            'insurer' => '111',
            'street' => 'Zkušební',
            'num' => '12',
            'pnu' => '11000',
            'city' => 'Praha',
            'ikmpsv' => null,
            'oid' => null,
        ];
        $client = self::attributes(['bno' => $o['bno'], 'ikmpsv' => $o['ikmpsv']]);
        $name = self::attributes(['sur' => $o['last'], 'fir' => $o['first'], 'tit' => $o['tit']]);
        $birth = self::attributes([
            'dat' => $o['birth_date'],
            'nam' => $o['birth_surname'],
            'cit' => $o['birth_place'],
            'stat' => 'CZ',
        ]);
        $address = self::attributes([
            'str' => $o['street'],
            'num' => $o['num'],
            'pnu' => $o['pnu'],
            'cit' => $o['city'],
            'cnt' => 'CZ',
        ]);
        $job = self::attributes([
            'oid' => $o['oid'],
            'fro' => $o['start'],
            'rel' => $o['rel'],
            'relDetail' => $o['detail'],
            'sme' => 'N',
            'contractplace' => 'Praha',
            'cit' => $o['workplace'],
            'municode' => $o['municode'],
        ]);
        $insurer = $o['insurer'] === null ? '' : '<insh cnr="' . $o['insurer'] . '"/>';

        return self::regzec(<<<XML
            <employee sqnr="1" dep="111" act="1" dat="2026-07-02">
              <client{$client}>
                <name{$name}/>
                <birth{$birth}/>
                <stat mal="{$o['sex']}" cnt="CZ"/>
                <adr{$address}/>
              </client>
              <comp vs="1234567890" nam="Syntetický zaměstnavatel"/>
              <job{$job}>
                <prof clas="{$o['clas']}"/>
                <position name="Účetní"/>
              </job>
              {$insurer}
              <fact highedu="M"/>
            </employee>
            XML);
    }

    public static function regzecA2(string $birthNumber, string $oic, string $idPpv, string $endOn): string
    {
        return self::regzec(<<<XML
            <employee sqnr="1" dep="111" act="2" dat="2026-09-01">
              <client bno="{$birthNumber}" ikmpsv="{$oic}"/>
              <comp vs="1234567890" nam="Syntetický zaměstnavatel"/>
              <job oid="{$idPpv}" to="{$endOn}" rel="1" relDetail="1"/>
            </employee>
            XML);
    }

    public static function regzecA3(string $birthNumber, string $effectiveOn): string
    {
        return self::regzec(<<<XML
            <employee sqnr="1" dep="111" act="3" dat="2026-09-02" fro="{$effectiveOn}">
              <client bno="{$birthNumber}"/>
              <comp vs="1234567890" nam="Syntetický zaměstnavatel"/>
              <job rel="1"/>
              <insh cnr="207"/>
            </employee>
            XML);
    }

    public static function prezecP1(string $birthNumber, string $first, string $last, string $expectedStartOn): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026" version="1.2" partialAccept="A">
              <employees>
                <employee sqnr="1" act="9" idform="0F8A3C2E-1B2D-4C5E-8F9A-0123456789AB" dat="2026-09-10" predat="{$expectedStartOn}">
                  <client bno="{$birthNumber}">
                    <name sur="{$last}" fir="{$first}"/>
                    <birth nam="{$last}" cit="Testov"/>
                    <stat cnt="CZ"/>
                  </client>
                  <comp vs="1234567890"/>
                </employee>
              </employees>
            </PREZEC>
            XML;
    }

    /**
     * Export zaměstnanců z ePortálu ČSSZ (kořen `ExportZamestnancu` bez jmenného
     * prostoru, s BOM jako originál). Hodnota `null` element vynechá.
     *
     * @param list<array<string,string|null>> $employees
     */
    public static function csszExport(array $employees, string $generatedAt = '2026-09-20T10:15:00.123Z'): string
    {
        $rows = '';
        foreach ($employees as $employee) {
            $e = $employee + [
                'RodneCislo' => null,
                'Prijmeni' => null,
                'Jmeno' => null,
                'VariabilniSymbol' => '1234567890',
                'KodDruhuCinnosti' => '1',
                'NazevDruhuCinnosti' => 'Pracovní poměr',
                'ZMR' => 'N',
                'IdZamestnani' => null,
                'OIC' => null,
            ];
            $rows .= "    <Zamestnanec>\n";
            foreach ($e as $element => $value) {
                if ($value !== null) {
                    $rows .= "      <{$element}>" . htmlspecialchars($value, ENT_XML1) . "</{$element}>\n";
                }
            }
            $rows .= "    </Zamestnanec>\n";
        }

        return "\xEF\xBB\xBF<ExportZamestnancu>\n  <DatumGenerovani>{$generatedAt}</DatumGenerovani>\n"
            . "  <Zamestnanci>\n{$rows}  </Zamestnanci>\n</ExportZamestnancu>\n";
    }

    /** Syntetické rodné číslo (bez lomítka), které projde kontrolou modulo 11. */
    public static function birthNumber(string $birthDate, string $sex, int $sequence): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $birthDate));
        $prefix = sprintf('%02d%02d%02d', $year % 100, $month + ($sex === 'female' ? 50 : 0), $day);
        for ($suffix = $sequence * 7; ; $suffix++) {
            $nine = $prefix . sprintf('%03d', $suffix % 1000);
            for ($digit = 0; $digit <= 9; $digit++) {
                if (((int) ($nine . $digit)) % 11 === 0) {
                    return $nine . $digit;
                }
            }
        }
    }

    /** Syntetické OIČ (10 číslic, poslední = zbytek prvních devíti po dělení 11). */
    public static function oic(int $sequence): string
    {
        for ($base = 100_000_000 + $sequence * 13; ; $base++) {
            $check = $base % 11;
            if ($check <= 9) {
                return $base . $check;
            }
        }
    }

    private static function regzec(string $employee): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025" version="1.4" partialAccept="A">
              <employees>
            {$employee}
              </employees>
            </REGZEC>
            XML;
    }

    /** @param array<string,string|null> $attributes */
    private static function attributes(array $attributes): string
    {
        $result = '';
        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $result .= ' ' . $name . '="' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES) . '"';
            }
        }

        return $result;
    }
}
