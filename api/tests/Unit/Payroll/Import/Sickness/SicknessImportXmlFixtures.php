<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Import\Sickness;

/**
 * Syntetická podání NEMPRI a HZUPN, jaká posílá předchozí mzdový program.
 *
 * Struktura vychází ze zveřejněných XSD ČSSZ; všechny hodnoty (jména, rodná
 * čísla, variabilní symbol, IČ, čísla rozhodnutí) jsou vymyšlené.
 */
final class SicknessImportXmlFixtures
{
    public const VARIABLE_SYMBOL = '1234567890';
    public const BUSINESS_ID = '00000019';

    /**
     * NEMPRI25. Nemocenské nenese den vzniku, ošetřovné a otcovská ano.
     *
     * @param array<string,mixed> $o
     */
    public static function nempri25(string $kind, string $birthNumber, array $o = []): string
    {
        $o += [
            'first' => 'Zkušební',
            'last' => 'Pojištěnec',
            'vs' => self::VARIABLE_SYMBOL,
            'ic' => self::BUSINESS_ID,
            'employmentFrom' => '2026-01-01',
            'ossz' => 112,
            'decision' => null,
            'correction' => 'false',
            'roFrom' => '2025-09-01',
            'roTo' => '2026-08-31',
            'probable' => 30000,
            'from' => '2026-09-14',
            'to' => '2026-09-20',
            'withActivity' => true,
            'doctype' => '',
        ];
        $decision = $o['decision'] === null ? '' : "<cisloRozhodnuti>{$o['decision']}</cisloRozhodnuti>";
        $activity = $o['withActivity'] ? '<druhCinnosti>1</druhCinnosti>' : '';
        $body = match ($kind) {
            'NEM' => <<<XML
<nem><potvrzeniZamestnavatele><pracoval>false</pracoval><pocetOdpracovanychHodin>0</pocetOdpracovanychHodin><pracovniDoba>8</pracovniDoba><pobiraDuchod>false</pobiraDuchod><jeStudentem>false</jeStudentem><dobaVolnaPrvniZamestnani>false</dobaVolnaPrvniZamestnani><volnoBezNahrady>false</volnoBezNahrady><prevedenaNaJinouPraci>false</prevedenaNaJinouPraci><exekuce>false</exekuce><insolvence>false</insolvence></potvrzeniZamestnavatele></nem>
XML,
            'OSE' => <<<XML
<ose><oseVznik>true</oseVznik><oseTrvani>false</oseTrvani><oseUkonceni>true</oseUkonceni><potvrzeniZamestnavatele><pracoval>false</pracoval><pocetOdpracovanychHodin>0</pocetOdpracovanychHodin><pracovniDoba>8</pracovniDoba><jeStudentem>false</jeStudentem><prevedenaNaJinouPraci>false</prevedenaNaJinouPraci><volnoBezNahrady>false</volnoBezNahrady></potvrzeniZamestnavatele><zadostODavku><odeDne>{$o['from']}</odeDne><doDne>{$o['to']}</doDne><osetrovanaOsoba><jmeno>Zkušební</jmeno><prijmeni>Dítě</prijmeni><rodneCislo>1234567890</rodneCislo><datumNarozeni>2020-02-02</datumNarozeni></osetrovanaOsoba><onemocnela>true</onemocnela><spolecnaDomacnost>true</spolecnaDomacnost><jeOsamely>false</jeOsamely><vPeciDiteDo16Let>true</vPeciDiteDo16Let><narokNaPPMjinouOsobou>false</narokNaPPMjinouOsobou><pecovalOsobne>false</pecovalOsobne><pecovalVeDnech><obdobi><od>{$o['from']}</od><do>{$o['to']}</do></obdobi></pecovalVeDnech><kodRodVztah>PL</kodRodVztah></zadostODavku><podkladyProVyplatDavky><pracovalPoslDenPD>false</pracovalPoslDenPD><planovaneSmeny>false</planovaneSmeny></podkladyProVyplatDavky></ose>
XML,
            'OPP' => <<<XML
<opp><potvrzeniZamestnavatele><pracoval>false</pracoval><pocetOdpracovanychHodin>0</pocetOdpracovanychHodin><pracovniDoba>8</pracovniDoba></potvrzeniZamestnavatele><zadostODavku><odeDne>{$o['from']}</odeDne><dite><jmeno>Zkušební</jmeno><prijmeni>Dítě</prijmeni><rodneCislo>1234567890</rodneCislo><datumNarozeni>{$o['from']}</datumNarozeni></dite><duvodOtcovske>OTC</duvodOtcovske></zadostODavku><podkladyProVyplatDavky><planovaneSmeny>false</planovaneSmeny></podkladyProVyplatDavky></opp>
XML,
            default => throw new \InvalidArgumentException('Nepodporovaný druh dávky ve fixture.'),
        };

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>{$o['doctype']}
<NEMPRI xmlns="http://schemas.cssz.cz/nem/NEMPRI25" version="1.0"><datovaVeta poradoveCislo="1"><dokument><kodOSSZ>{$o['ossz']}</kodOSSZ><druhDavky>{$kind}</druhDavky><opravnePodani>{$o['correction']}</opravnePodani>{$decision}<zahranicni>false</zahranicni></dokument><pojistenec><jmeno>{$o['first']}</jmeno><prijmeni>{$o['last']}</prijmeni><rodneCislo>{$birthNumber}</rodneCislo></pojistenec><zamestnani><VSZamestnavatel>{$o['vs']}</VSZamestnavatel><ICZamestnavatel>{$o['ic']}</ICZamestnavatel><nazevZamestnavatel>Syntetický zaměstnavatel</nazevZamestnavatel><zamestnanOd>{$o['employmentFrom']}</zamestnanOd>{$activity}</zamestnani><rozhodneObdobi><rozhodneObdobiOd>{$o['roFrom']}</rozhodneObdobiOd><rozhodneObdobiDo>{$o['roTo']}</rozhodneObdobiDo><pravdepodobnaVysePrijmu>{$o['probable']}</pravdepodobnaVysePrijmu></rozhodneObdobi><davka>{$body}</davka></datovaVeta></NEMPRI>
XML;
    }

    /**
     * NEMPRI 2020 ve tvaru, který posílá Money S3 (nemoc přes eNeschopenku).
     *
     * @param array<string,mixed> $o
     */
    public static function nempri20(string $birthNumber, array $o = []): string
    {
        $o += [
            'vs' => self::VARIABLE_SYMBOL,
            'decision' => '103600000000000001',
            'roTo' => '2026-07-31',
        ];

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<NEMPRI xmlns="http://schemas.cssz.cz/nem/NEMPRI20" version="2020.0" partialAccept="A"><datovaVeta poradoveCislo="1"><dokument><cisloPotvrzeni>{$o['decision']}</cisloPotvrzeni><kodOSSZ>112</kodOSSZ><nazevOSSZ>Syntetická OSSZ</nazevOSSZ><druhDavky>NEM</druhDavky><opravnePodani>N</opravnePodani></dokument><pojistenec><jmeno>Zkušební</jmeno><prijmeni>Pojištěnec</prijmeni><rodneCislo>{$birthNumber}</rodneCislo></pojistenec><zamestnani><VSZamestnavatel>{$o['vs']}</VSZamestnavatel><ICZamestnavatel>00000019</ICZamestnavatel><nazevZamestnavatel>Syntetický zaměstnavatel</nazevZamestnavatel><zamestnanOd>2026-01-01</zamestnanOd><druhCinnosti>1</druhCinnosti></zamestnani><rozhodneObdobi><rozhodneObdobiOd>2026-01-01</rozhodneObdobiOd><rozhodneObdobiDo>{$o['roTo']}</rozhodneObdobiDo></rozhodneObdobi><prilohaStrana2><pracoval>N</pracoval><pobiraDuchod>N</pobiraDuchod><jeStudentem>N</jeStudentem><spadaDoPrazdnin>N</spadaDoPrazdnin><dobaVolnaPrvniZamestnani>N</dobaVolnaPrvniZamestnani><volnoBezNahrady>N</volnoBezNahrady><nastupujePPM>N</nastupujePPM><prevedenaNaJinouPraci>N</prevedenaNaJinouPraci><exekuce>N</exekuce><insolvence>N</insolvence><kontaktniTelefon>111222333</kontaktniTelefon><kontaktniEmail>ucetni@example.invalid</kontaktniEmail></prilohaStrana2></datovaVeta></NEMPRI>
XML;
    }

    /**
     * HZUPN20.
     *
     * @param array<string,mixed> $o
     */
    public static function hzupn20(string $birthNumber, array $o = []): string
    {
        $o += [
            'vs' => self::VARIABLE_SYMBOL,
            'decision' => 'A1234567',
            'issued' => '2026-10-16',
            'returned' => 'A',
            'returnedOn' => '2026-10-15',
            'hours' => '4',
            'shift' => '8',
            'personReport' => 'N',
            'employerReport' => 'A',
            'withBirthNumber' => true,
        ];
        $number = $o['withBirthNumber'] ? "<rodCislo>{$birthNumber}</rodCislo>" : '';
        $returnedOn = $o['returnedOn'] === null ? '' : "<datumNavratDoPrace>{$o['returnedOn']}</datumNavratDoPrace>";

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<PodaniHZUPN xmlns="http://schemas.cssz.cz/nem/HZUPN20" version="1.1"><FormularHZUPN poradoveCislo="1"><dokument><hlasZamest>{$o['employerReport']}</hlasZamest><hlasOsoby>{$o['personReport']}</hlasOsoby><cisloPotvrzeni>{$o['decision']}</cisloPotvrzeni><kodOSSZ>112</kodOSSZ><datumVystaveni>{$o['issued']}</datumVystaveni><opravnePodani>N</opravnePodani></dokument><pojistenec><jmeno>Zkušební</jmeno><prijmeni>Pojištěnec</prijmeni>{$number}<datumNar>1990-01-01</datumNar></pojistenec><zamestnani><nazevZamestnavatel>Syntetický zaměstnavatel</nazevZamestnavatel><ICZamestnavatel>00000019</ICZamestnavatel><variabilniSymbol>{$o['vs']}</variabilniSymbol></zamestnani><potvrzeniZamestnavatele><navratDoPrace>{$o['returned']}</navratDoPrace>{$returnedOn}<pocetOdpracHodinPoslDenPD>{$o['hours']}</pocetOdpracHodinPoslDenPD><pracovniDobaPoslDenPD>{$o['shift']}</pracovniDobaPoslDenPD></potvrzeniZamestnavatele></FormularHZUPN></PodaniHZUPN>
XML;
    }
}
