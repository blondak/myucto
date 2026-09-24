<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

/**
 * Zásobníky vymyšlených hodnot pro pseudonymy.
 *
 * Obecná česká jména, ulice a obce — žádná vazba na konkrétní osobu ani firmu.
 * Pseudonym se z nich vybírá deterministicky (HMAC klíčem běhu), takže bez
 * klíče nejde zpětně odvodit originál.
 */
final class PseudonymPools
{
    /** @var list<string> */
    public const MALE_FIRST = [
        'Adam', 'Aleš', 'Antonín', 'Bohumil', 'Bohuslav', 'Dalibor', 'Daniel', 'David',
        'Dominik', 'Filip', 'František', 'Hynek', 'Ivan', 'Jakub', 'Jaromír', 'Jiří',
        'Josef', 'Kamil', 'Karel', 'Kryštof', 'Leoš', 'Lubomír', 'Lukáš', 'Marek',
        'Martin', 'Matěj', 'Michal', 'Milan', 'Miroslav', 'Ondřej', 'Oldřich', 'Patrik',
        'Pavel', 'Radim', 'Robert', 'Roman', 'Rostislav', 'Stanislav', 'Šimon', 'Tomáš',
        'Vavřinec', 'Viktor', 'Vítězslav', 'Vladimír', 'Vojtěch', 'Zbyněk', 'Zdeněk',
    ];

    /** @var list<string> */
    public const FEMALE_FIRST = [
        'Adéla', 'Alena', 'Anežka', 'Barbora', 'Blanka', 'Dagmar', 'Dana', 'Denisa',
        'Eliška', 'Gabriela', 'Hana', 'Helena', 'Irena', 'Iveta', 'Jarmila', 'Jitka',
        'Kamila', 'Karolína', 'Kateřina', 'Klára', 'Kristýna', 'Lenka', 'Libuše', 'Lucie',
        'Ludmila', 'Magdaléna', 'Marcela', 'Markéta', 'Michaela', 'Milada', 'Monika', 'Naděžda',
        'Olga', 'Pavla', 'Radka', 'Renata', 'Romana', 'Simona', 'Soňa', 'Šárka',
        'Taťána', 'Tereza', 'Vendula', 'Věra', 'Vlasta', 'Zdenka', 'Zuzana',
    ];

    /**
     * Mužská a ženská podoba příjmení v páru — pohlaví pseudonymu sleduje originál.
     *
     * @var list<array{0:string,1:string}>
     */
    public const SURNAMES = [
        ['Bartoš', 'Bartošová'], ['Beneš', 'Benešová'], ['Blažek', 'Blažková'], ['Bureš', 'Burešová'],
        ['Cibulka', 'Cibulková'], ['Doležal', 'Doležalová'], ['Dušek', 'Dušková'], ['Fiala', 'Fialová'],
        ['Hájek', 'Hájková'], ['Havlíček', 'Havlíčková'], ['Holub', 'Holubová'], ['Hrubeš', 'Hrubešová'],
        ['Jelínek', 'Jelínková'], ['Kadlec', 'Kadlecová'], ['Kalina', 'Kalinová'], ['Klíma', 'Klímová'],
        ['Kolář', 'Kolářová'], ['Kopecký', 'Kopecká'], ['Kovařík', 'Kovaříková'], ['Kratochvíl', 'Kratochvílová'],
        ['Kubíček', 'Kubíčková'], ['Kučera', 'Kučerová'], ['Lorenc', 'Lorencová'], ['Mach', 'Machová'],
        ['Matějka', 'Matějková'], ['Moravec', 'Moravcová'], ['Musil', 'Musilová'], ['Navrátil', 'Navrátilová'],
        ['Němec', 'Němcová'], ['Paleček', 'Palečková'], ['Pešek', 'Pešková'], ['Pokorný', 'Pokorná'],
        ['Polák', 'Poláková'], ['Říha', 'Říhová'], ['Sedláček', 'Sedláčková'], ['Skála', 'Skálová'],
        ['Slanina', 'Slaninová'], ['Soukup', 'Soukupová'], ['Staněk', 'Staňková'], ['Šebesta', 'Šebestová'],
        ['Šimánek', 'Šimánková'], ['Štěpánek', 'Štěpánková'], ['Tichý', 'Tichá'], ['Toman', 'Tomanová'],
        ['Trnka', 'Trnková'], ['Urban', 'Urbanová'], ['Vacek', 'Vacková'], ['Valenta', 'Valentová'],
        ['Vaněk', 'Vaňková'], ['Vlček', 'Vlčková'], ['Vondra', 'Vondrová'], ['Zeman', 'Zemanová'],
    ];

    /** @var list<string> */
    public const COMPANY_FIRST = [
        'Alfa', 'Azurit', 'Beryl', 'Bystřina', 'Cedr', 'Delta', 'Dubina', 'Emerit',
        'Fénix', 'Granit', 'Habr', 'Horizont', 'Jasan', 'Jantar', 'Kobalt', 'Korál',
        'Lipnice', 'Magnet', 'Meridian', 'Modřín', 'Nefrit', 'Onyx', 'Orion', 'Pegas',
        'Polaris', 'Rubín', 'Safír', 'Sirius', 'Topas', 'Vega', 'Zenit', 'Žula',
    ];

    /** @var list<string> */
    public const COMPANY_SECOND = [
        'Consult', 'Data', 'Energie', 'Group', 'Holding', 'Invest', 'Logistik', 'Montáže',
        'Obchod', 'Plus', 'Projekt', 'Servis', 'Stav', 'Systémy', 'Technik', 'Trade',
        'Služby', 'Výroba', 'Reality', 'Partner', 'Studio', 'Media', 'Agro', 'Tech',
    ];

    /** @var list<string> */
    public const STREETS = [
        'Akátová', 'Borová', 'Brusinková', 'Březová', 'Cihlářská', 'Dlouhá', 'Habrová', 'Hliníková',
        'Javorová', 'Jeřabinová', 'Kaštanová', 'Klenová', 'Krátká', 'Lesní', 'Lipová', 'Lomená',
        'Lučí', 'Malinová', 'Modřínová', 'Na Výsluní', 'Nad Potokem', 'Okružní', 'Olšová', 'Ořechová',
        'Polní', 'Pod Lesem', 'Příčná', 'Rybářská', 'Sadová', 'Slunečná', 'Smrková', 'Spojovací',
        'Šeříková', 'Topolová', 'Tovární', 'U Mlýna', 'Úzká', 'Vinná', 'Vřesová', 'Zahradní',
    ];

    /** @var list<array{0:string,1:string}> obec a PSČ, které k ní patří */
    public const CITIES = [
        ['Benešov', '256 01'], ['Beroun', '266 01'], ['Blansko', '678 01'], ['Břeclav', '690 02'],
        ['Chrudim', '537 01'], ['Domažlice', '344 01'], ['Havlíčkův Brod', '580 01'], ['Hodonín', '695 01'],
        ['Jeseník', '790 01'], ['Jičín', '506 01'], ['Kolín', '280 02'], ['Kroměříž', '767 01'],
        ['Kutná Hora', '284 01'], ['Litoměřice', '412 01'], ['Louny', '440 01'], ['Mělník', '276 01'],
        ['Náchod', '547 01'], ['Nymburk', '288 02'], ['Pelhřimov', '393 01'], ['Písek', '397 01'],
        ['Prachatice', '383 01'], ['Přerov', '750 02'], ['Rakovník', '269 01'], ['Rokycany', '337 01'],
        ['Semily', '513 01'], ['Strakonice', '386 01'], ['Svitavy', '568 02'], ['Šumperk', '787 01'],
        ['Tábor', '390 02'], ['Tachov', '347 01'], ['Třebíč', '674 01'], ['Trutnov', '541 01'],
        ['Vsetín', '755 01'], ['Vyškov', '682 01'], ['Znojmo', '669 02'], ['Žďár nad Sázavou', '591 01'],
    ];

    /**
     * Právní formy, které se v pseudonymu firmy zachovají (určují typ subjektu,
     * nikoho neidentifikují). Porovnává se bez diakritiky, bez teček a mezer.
     *
     * @var list<string>
     */
    public const LEGAL_FORMS = [
        'sro', 'spolsro', 'spolecnostsrucenimomezenym', 'as', 'akciovaspolecnost', 'vos', 'ks',
        'zs', 'zu', 'ops', 'druzstvo', 'sp', 'se', 'gmbh', 'ag', 'ltd', 'inc', 'llc', 'bv', 'sa', 'kg',
        'spzoo', 'sro.', 'statnipodnik', 'prispevkovaorganizace', 'po',
    ];

    /**
     * Kódy bank platebního styku v ČR — v textu se za účet považuje jen zápis
     * s některým z nich (jinak by se „pseudonymizovala" i čísla dokladů 2024/0100).
     *
     * @var list<string>
     */
    public const CZECH_BANK_CODES = [
        '0100', '0300', '0600', '0710', '0800', '2010', '2060', '2070', '2100', '2200', '2220',
        '2250', '2260', '2600', '2700', '3030', '3050', '3060', '3500', '4000', '4300', '5500',
        '5800', '6000', '6100', '6200', '6210', '6300', '6363', '6700', '6800', '7910', '7950',
        '7960', '7970', '7990', '8030', '8040', '8060', '8090', '8150', '8190', '8198', '8199',
        '8200', '8220', '8250', '8255', '8265', '8270', '8280', '8293', '8299', '8500',
    ];
}
