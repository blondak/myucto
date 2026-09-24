<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Anonymization;

use MyInvoice\Service\Anonymization\PlaceholderFiles;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\ReplacementDictionary;
use MyInvoice\Service\Anonymization\TextScrubber;
use PHPUnit\Framework\TestCase;

final class TextScrubberTest extends TestCase
{
    private Pseudonymizer $p;
    private TextScrubber $scrubber;

    protected function setUp(): void
    {
        $this->p = new Pseudonymizer('unit-test-secret-0123456789');
        $this->scrubber = new TextScrubber($this->p);
    }

    public function testKnownNamesAndIdentifiersGetTheSamePseudonymInText(): void
    {
        $ico = '1234567' . Pseudonymizer::icoCheckDigit('1234567');
        $company = $this->p->partyName('Zkušební Montáže s.r.o.');
        $pseudoIco = $this->p->ico($ico);
        $core = explode(' s.r.o.', $company)[0];

        $text = $this->scrubber->scrub("Úhrada ZKUSEBNI MONTAZE SRO dle smlouvy, IČ {$ico}; Zkušební Montáže s.r.o.");

        self::assertStringNotContainsString('Zkušební', $text);
        self::assertStringNotContainsString('ZKUSEBNI', $text);
        self::assertStringNotContainsString($ico, $text);
        self::assertStringContainsString($pseudoIco, $text);
        self::assertStringContainsString($company, $text);
        self::assertStringContainsString(mb_strtoupper(ReplacementDictionary::fold($core), 'UTF-8'), $text);
    }

    public function testReplacementRespectsWordBoundaries(): void
    {
        $surname = $this->p->lastName('Zkušebník');

        $text = $this->scrubber->scrub('Zkušebník, Zkušebníkova ulice');

        self::assertStringStartsWith($surname . ',', $text);
        self::assertStringContainsString('Zkušebníkova', $text);
    }

    public function testPatternsCatchUnknownIdentifiers(): void
    {
        $rc = null;
        for ($serial = 100; $rc === null; $serial++) {
            $nine = (int) ('855101' . $serial);
            $check = (11 - (($nine * 10) % 11)) % 11;
            $rc = $check < 10 ? '855101/' . $serial . $check : null;
        }
        $text = $this->scrubber->scrub(
            'Kontakt neznamy@firma.example, tel. +420 777 123 456, účet 1000000005/0100, '
            . "faktura 2024/0100, RČ {$rc}, IBAN CZ6508000000192000145399.",
        );

        self::assertStringNotContainsString($rc, $text);
        self::assertStringContainsString('RČ 855101/', $text, 'Datum narození v rodném čísle zůstává.');

        self::assertStringNotContainsString('neznamy@firma.example', $text);
        self::assertStringContainsString('@' . Pseudonymizer::EMAIL_DOMAIN, $text);
        self::assertStringNotContainsString('777 123 456', $text);
        self::assertStringNotContainsString('1000000005/0100', $text);
        self::assertStringContainsString('faktura 2024/0100', $text, 'Číslo dokladu se za účet vydávat nesmí.');
        self::assertStringNotContainsString('CZ6508000000192000145399', $text);
    }

    public function testInvalidBirthNumberAndAccountAreLeftAlone(): void
    {
        $text = $this->scrubber->scrub('Zakázka 123456/7890 a číslo 1234567890/0100.');

        self::assertSame('Zakázka 123456/7890 a číslo 1234567890/0100.', $text);
    }

    public function testJsonKeepsStructureAndUsesKeysForMeaning(): void
    {
        $json = '{"company_name":"Zkušební Obchod s.r.o.","ic":12345679,"email":"a@b.example","items":[],"meta":{},"amount":1234.50,"note":"bez údajů"}';

        $out = $this->scrubber->scrubJson($json);
        $decoded = json_decode($out, false, 512, JSON_THROW_ON_ERROR);

        self::assertStringEndsWith('s.r.o.', $decoded->company_name);
        self::assertNotSame('Zkušební Obchod s.r.o.', $decoded->company_name);
        self::assertIsInt($decoded->ic);
        self::assertStringContainsString('"items":[]', $out);
        self::assertStringContainsString('"meta":{}', $out);
        self::assertStringContainsString('"amount":1234.5', $out);
        self::assertSame('bez údajů', $decoded->note);
    }

    public function testUnchangedJsonIsReturnedVerbatim(): void
    {
        $json = "{\"a\": 1.10,\n \"b\": \"x\"}";

        self::assertSame($json, $this->scrubber->scrubJson($json));
    }

    public function testDictionaryIgnoresCaseDiacriticsAndSeparatorsButNotCommonWords(): void
    {
        $dictionary = new ReplacementDictionary();
        $dictionary->add('Zkušební Obchod s.r.o.', 'Alfa Servis s.r.o.');
        $dictionary->add('Nový', 'Tichý');

        self::assertSame('Platba Alfa Servis s.r.o.', $dictionary->apply('Platba Zkusebni obchod, s. r. o.'));
        self::assertSame('ALFA SERVIS S.R.O.', $dictionary->apply('ZKUŠEBNÍ OBCHOD S.R.O.'));
        self::assertSame('Tichý: nový rok', $dictionary->apply('Nový: nový rok'));
    }

    public function testDictionaryHandlesNumericPhrases(): void
    {
        $dictionary = new ReplacementDictionary();
        $dictionary->add('12345678', '87654321', 6);

        self::assertSame('IČ 87654321, x12345678', $dictionary->apply('IČ 12345678, x12345678'));
        self::assertSame('87654321', $dictionary->lookup('12345678'));
    }

    public function testPlaceholderFilesAreValid(): void
    {
        $pdf = PlaceholderFiles::content('PDF');
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        preg_match('/startxref\n(\d+)\n/', $pdf, $m);
        self::assertSame('xref', substr($pdf, (int) $m[1], 4));

        $png = PlaceholderFiles::content('jpg');
        $info = getimagesizefromstring($png);
        self::assertIsArray($info);
        self::assertSame([1, 1], [$info[0], $info[1]]);

        self::assertStringContainsString('<anonymized>', PlaceholderFiles::content('xml'));
    }

    public function testStorageMirrorReplacesContentAndHumanNamesButKeepsLayout(): void
    {
        $root = sys_get_temp_dir() . '/anon_mirror_' . bin2hex(random_bytes(6));
        $from = $root . '/storage';
        $to = $root . '/out';
        mkdir($from . '/documents/sup-3/ab', 0777, true);
        mkdir($from . '/cache', 0777, true);
        file_put_contents($from . '/documents/sup-3/ab/Smlouva Zkušební.pdf', 'SKUTECNY OBSAH');
        file_put_contents($from . '/documents/sup-3/ab/3f9a0c1d2e4b5a6c.jpg', 'SKUTECNY OBSAH');
        file_put_contents($from . '/cache/x.txt', 'cache');
        try {
            $result = PlaceholderFiles::mirror($from, $to, $this->p, static function (): void {});

            self::assertSame(2, $result['files']);
            $renamed = $to . '/' . $this->p->filePath('documents/sup-3/ab/Smlouva Zkušební.pdf');
            self::assertFileExists($renamed);
            self::assertStringStartsWith('%PDF', (string) file_get_contents($renamed));
            self::assertIsArray(getimagesize($to . '/documents/sup-3/ab/3f9a0c1d2e4b5a6c.jpg'));
            self::assertDirectoryDoesNotExist($to . '/cache');
        } finally {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }
}
