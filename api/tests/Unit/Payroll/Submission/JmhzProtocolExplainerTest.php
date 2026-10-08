<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlId;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolExplainer;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolRemediationCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JmhzProtocolExplainerTest extends TestCase
{
    private const FORM_GUID = 'AAAABBBB-1111-7222-8333-CCCCDDDDEEEE';

    /**
     * Hláška z protokolu říká, co je špatně, ne kde to hledat. Doplnění
     * z katalogu je celý smysl téhle vrstvy — bez dotčených atributů zůstane
     * uživateli jen věta, se kterou nic neudělá.
     */
    public function testControlErrorIsEnrichedFromTheCatalog(): void
    {
        $explained = $this->explain(
            'JMHZ25_LT: 20315 - Pojistné na sociální zabezpečení neodpovídá'
                . ' vyměřovacímu základu zaměstnance.',
            '20315',
            withForm: true,
        );

        $form = $this->forForm($explained);
        self::assertSame(315, $form['control_id']);
        self::assertSame(self::FORM_GUID, $form['form_guid']);
        self::assertIsArray($form['control']);
        self::assertContains('10477', $form['control']['attribute_ids']);
        self::assertContains('10481', $form['control']['attribute_ids']);
        self::assertNotSame('', $form['control']['detail']);
    }

    /**
     * Prostor kódů ČSSZ je širší než náš katalog: skutečný protokol vrátil
     * 20022 dřív, než katalog 1.4.2.10 kontrolu 22 zveřejnil. Fail-closed by
     * shodilo zpracování celé odpovědi právě ve chvíli, kdy uživatel potřebuje
     * vědět, proč mu podání neprošlo.
     */
    public function testUnknownControlDoesNotBreakTheExplanation(): void
    {
        $explained = $this->explain(
            'JMHZ25_LT_G: 20999 - Kontrola, kterou katalog nezná',
            '20999',
        );

        self::assertNotSame([], $explained);
        self::assertSame(999, $explained[0]['control_id']);
        self::assertNull($explained[0]['control']);
        self::assertArrayHasKey('remediation', $explained[0]);
        self::assertNull($explained[0]['remediation']);
        self::assertSame('Kontrola, kterou katalog nezná', $explained[0]['message']);
    }

    /**
     * FAQ ČSSZ (9. 6. 2026) píše „Chyba 238", ale myslí ID kontroly: kontroly
     * 219 až 326 dělá cJMHZ, takže v protokolu chodí jako 40000 + ID. Ke každé
     * patří vysvětlení a místo v aplikaci, kde se náprava dělá.
     *
     * @return iterable<string,array{0:string,1:int,2:string,3:string}>
     */
    public static function faqControls(): iterable
    {
        yield '219 chybný GUID opravné součásti' => [
            'JMHZ25_LT: 40219 - Chybný GUID opravné součásti.',
            219, 'jmhz_protocol_correction_guid_invalid', 'correction',
        ];
        yield '238 dvojice IK MPSV a ID PPV' => [
            'JMHZ25_LT: 40238 - V rámci opravného podání nebyl nalezen GUID součásti.',
            238, 'jmhz_protocol_correction_form_unpaired', 'correction',
        ];
        yield '242 srážková daň s prohlášením' => [
            'JMHZ25_LT: 40242 - Pokud je učiněno prohlášení poplatníka k dani, pak nelze uplatnit srážkovou daň.',
            242, 'jmhz_protocol_tax_withholding_with_declaration', 'statutory_evidence',
        ];
        yield '243 nerezident s prohlášením' => [
            'JMHZ25_LT: 40243 - U daňového nerezidenta lze uplatnit pouze základní slevu na poplatníka.',
            243, 'jmhz_protocol_tax_nonresident_with_declaration', 'registration',
        ];
        yield '244 slevy bez prohlášení' => [
            'JMHZ25_LT: 40244 - Nebylo-li učiněno prohlášení poplatníka, nelze vyplnit atribut(y) slev.',
            244, 'jmhz_protocol_tax_reliefs_without_declaration', 'statutory_evidence',
        ];
        yield '245 zálohová daň při srážkové' => [
            'JMHZ25_LT: 40245 - Nebylo-li učiněno prohlášení poplatníka a příjem podléhá srážkové dani.',
            245, 'jmhz_protocol_tax_advance_with_withholding', 'statutory_evidence',
        ];
        yield '325 srážková daň při zálohové' => [
            'JMHZ25_LT: 40325 - Pro scénář, kdy je vybírána daň zálohou, nelze vyplnit srážkovou daň.',
            325, 'jmhz_protocol_tax_withholding_with_advance', 'statutory_evidence',
        ];
        yield '326 více řádných podání' => [
            'JMHZ25_LT: 40326 - V systému nesmí existovat více řádných podání za jedno rozhodné období.',
            326, 'jmhz_protocol_regular_submission_duplicate', 'correction',
        ];
    }

    #[DataProvider('faqControls')]
    public function testFaqControlCarriesRemediation(
        string $message,
        int $controlId,
        string $code,
        string $kind,
    ): void {
        $number = (string) (40_000 + $controlId);
        $form = $this->forForm($this->explain($message, $number, withForm: true));

        self::assertSame($controlId, $form['control_id']);
        self::assertSame('cjmhz', $form['origin']);
        self::assertIsArray($form['remediation']);
        self::assertSame($code, $form['remediation']['code']);
        self::assertSame($kind, $form['remediation']['kind']);
        self::assertSame(JmhzProtocolRemediationCatalog::SOURCE_FAQ, $form['remediation']['source']);
        // Hláška z protokolu zůstává, náprava ji jen doplňuje.
        self::assertSame((int) $number, $form['code']);
        self::assertStringNotContainsString((string) $number, $form['message']);
        self::assertNotSame('', $form['message']);
    }

    /**
     * FAQ u chyby 243 (bod D) vyjmenovává atributy, které u nerezidenta
     * s prohlášením nesmí mít hodnotu, ani nulu. U ostatních daňových chyb
     * platí atributy označené v protokolu, takže seznam zůstává prázdný.
     */
    public function testNonresidentRemediationListsAttributesThatMustStayEmpty(): void
    {
        $nonresident = $this->forForm($this->explain(
            'JMHZ25_LT: 40243 - U daňového nerezidenta lze uplatnit pouze základní slevu.',
            '40243',
            withForm: true,
        ));
        $empty = $nonresident['remediation']['empty_attribute_ids'];
        self::assertCount(20, $empty);
        foreach (['10300', '10431', '10440', '10453', '10304', '10306', '10307', '10309', '10310'] as $attribute) {
            self::assertContains($attribute, $empty);
        }
        self::assertNotContains('10068', $empty, 'Kód státu rezidence se naopak dohlašuje v REGZEC A3.');

        $reliefs = $this->forForm($this->explain(
            'JMHZ25_LT: 40244 - Nebylo-li učiněno prohlášení poplatníka, nelze vyplnit slevy.',
            '40244',
            withForm: true,
        ));
        self::assertSame([], $reliefs['remediation']['empty_attribute_ids']);
    }

    /**
     * Kontrolu 219 katalog 1.4.2.10 zrušil, FAQ ČSSZ ji ale dál vysvětluje
     * a starší protokol ji nese. Náprava nesmí záviset na připnutém katalogu.
     */
    public function testRemovedControl219KeepsRemediationWithoutCatalogDefinition(): void
    {
        $form = $this->forForm($this->explain(
            'JMHZ25_LT: 40219 - Chybný GUID opravné součásti.',
            '40219',
            withForm: true,
        ));

        self::assertNull($form['control']);
        self::assertSame('jmhz_protocol_correction_guid_invalid', $form['remediation']['code']);
    }

    /**
     * Kontroly 262 a 263 jsou DIS a ČSSZ je hlásí i kódem post DIS validace
     * evidence (Katalog kontrol MH 1.4.2.10, sloupec poznámek). Bez mapy by
     * parser kód 103901608 odmítl a protokol by se vůbec nezpracoval.
     *
     * @return iterable<string,array{0:int,1:int,2:string,3:string,4:string}>
     */
    public static function postDisCodes(): iterable
    {
        yield '103901608 ID PPV' => [
            103_901_608, 262, 'jmhz_protocol_employment_not_found_at_cssz',
            'jmhz.employment_external_identifier', '10228',
        ];
        yield '103901609 IK MPSV' => [
            103_901_609, 263, 'jmhz_protocol_person_not_found_at_cssz',
            'jmhz.person_external_identifier', '10051',
        ];
        yield '20262 ID PPV v rozsahu DIS' => [
            20_262, 262, 'jmhz_protocol_employment_not_found_at_cssz',
            'jmhz.employment_external_identifier', '10228',
        ];
        yield '20263 IK MPSV v rozsahu DIS' => [
            20_263, 263, 'jmhz_protocol_person_not_found_at_cssz',
            'jmhz.person_external_identifier', '10051',
        ];
    }

    #[DataProvider('postDisCodes')]
    public function testPostDisValidationCodeMapsToControlAndRemediation(
        int $number,
        int $controlId,
        string $code,
        string $field,
        string $attribute,
    ): void {
        $form = $this->forForm($this->explain(
            "JMHZ25_LT: {$number} - Pojistný vztah s uvedeným identifikátorem nebyl nalezen v systémech ČSSZ.",
            (string) $number,
            withForm: true,
        ));

        self::assertSame($number, $form['code']);
        self::assertSame('dis', $form['origin']);
        self::assertSame($controlId, $form['control_id']);
        self::assertIsArray($form['control']);
        self::assertSame([$attribute], $form['control']['attribute_ids']);
        self::assertSame($code, $form['remediation']['code']);
        self::assertSame('employment_identity', $form['remediation']['kind']);
        self::assertSame($field, $form['remediation']['field']);
        self::assertSame(
            JmhzProtocolRemediationCatalog::SOURCE_CONTROL_CATALOG,
            $form['remediation']['source'],
        );
    }

    /**
     * Registrační protokol kód post DIS validace jen přenáší a kontrolu z něj
     * neodvozuje; vysvětlení ji přesto dohledá touž mapou jako parser JMHZ.
     */
    public function testRegistrationProtocolPostDisCodeIsExplained(): void
    {
        $message = 'REGZEC25_LT: 103901608 - Pojistný vztah s uvedeným ID PPV nebyl nalezen v systémech ČSSZ.';
        $item = static fn (string $sequence, string $result, string $errMsg = '', string $errNum = ''): string => sprintf(
            '<Item sqnr="%s" identifier="" subtype="REGZEC25" period="" result="%s" errMsg="%s" errNum="%s" />',
            $sequence,
            $result,
            htmlspecialchars($errMsg, ENT_XML1 | ENT_QUOTES),
            $errNum,
        );
        $report = (new JmhzProtocolParser())->parse(
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
            . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
            . '<Class>CSSZ_REGZEC</Class><Qualifier>error</Qualifier><Function>submit</Function>'
            . '<TransactionID /><CorrelationID>CID0000000001</CorrelationID>'
            . '</MessageDetails></Header><GovTalkDetails /><Body>'
            . '<Message xmlns="http://www.cssz.cz/XMLSchema/envelope" version="1.2" eType="response">'
            . '<Header /><Body>'
            . '<ProcessingResult type="CSSZ_REGZEC" version="1,0" result="ERROR" errMsg="'
            . htmlspecialchars($message, ENT_XML1 | ENT_QUOTES)
            . '" errNumber="5" count="1" countErr="1" countWar="0">'
            . '<Error><RaisedBy /><Number>5</Number><Type>CSSZ_REGZEC</Type><Text /></Error>'
            . '<Details>' . $item('', 'OK') . $item('1', 'ERROR', $message, '608') . '</Details>'
            . '</ProcessingResult></Body></Message></Body></GovTalkMessage>',
        );

        self::assertNull($report->errors[0]->controlId);
        $explained = (new JmhzProtocolExplainer())->explain($report);
        self::assertNotSame([], $explained);
        foreach ($explained as $item) {
            self::assertSame(103_901_608, $item['code']);
            self::assertSame(262, $item['control_id']);
            self::assertSame('jmhz_protocol_employment_not_found_at_cssz', $item['remediation']['code']);
        }
    }

    /** Kódy nápravy pro klienta (union) jsou přesně ty, které katalog vydává. */
    public function testRemediationCodesMatchTheControlMap(): void
    {
        $codes = [];
        foreach (JmhzProtocolRemediationCatalog::controlIds() as $controlId) {
            $codes[] = JmhzProtocolRemediationCatalog::forControl(new JmhzControlId($controlId))['code'] ?? null;
        }
        sort($codes);
        $declared = JmhzProtocolRemediationCatalog::CODES;
        sort($declared);

        self::assertSame($declared, $codes);
        self::assertSame(
            [219, 238, 242, 243, 244, 245, 262, 263, 325, 326],
            JmhzProtocolRemediationCatalog::controlIds(),
        );
    }

    /**
     * Regrese na skutečnou odpověď z testovacího prostředí. Od katalogu
     * 1.4.2.10 je kontrola 22 připnutá, takže se hláška doplní z katalogu.
     */
    public function testDuplicateSubmissionGuidIsEnrichedFromControl22(): void
    {
        $explained = $this->explain(
            'JMHZ25_LT_G: 20022 - Podání typu R se stejným idPodani,'
                . ' variabilním symbolem, obdobím a balík pořadí již existuje',
            '20022',
        );

        self::assertSame(22, $explained[0]['control_id']);
        self::assertIsArray($explained[0]['control']);
        self::assertSame(['10001', '10007'], $explained[0]['control']['attribute_ids']);
        self::assertStringContainsString('již existuje', $explained[0]['message']);
        self::assertTrue($explained[0]['original_at_cssz']);
    }

    /**
     * Protokol, jehož jedinou chybou je „shodné podání už existuje", není
     * zamítnutí: originál je u ČSSZ a podání zůstává odeslané. Varianta
     * „idPodani je již použito s jiným variabilním symbolem" je naopak
     * skutečná chyba a zamítnutím zůstává.
     */
    public function testOnlyIdenticalSubmissionVariantMeansTheOriginalIsAtCssz(): void
    {
        $identical = (new JmhzProtocolParser())->parse(JmhzTransportSample::partialProtocol(
            'ERROR',
            [],
            'error',
            'JMHZ25_LT_G: 20022 - Podání typu S se stejným idPodani, variabilním'
                . ' symbolem a obdobím již existuje',
            '20022',
        ));
        self::assertTrue($identical->originalAlreadyAtCssz());
        self::assertSame('submitted', $identical->payrollRemoteStatus());

        $reused = (new JmhzProtocolParser())->parse(JmhzTransportSample::partialProtocol(
            'ERROR',
            [],
            'error',
            'JMHZ25_LT_G: 20022 - Toto idPodani je již použito s jiným variabilním symbolem',
            '20022',
        ));
        self::assertFalse($reused->originalAlreadyAtCssz());
        self::assertSame($reused->status->payrollRemoteStatus(), $reused->payrollRemoteStatus());
        self::assertFalse((new JmhzProtocolExplainer())->explain($reused)[0]['original_at_cssz']);
    }

    /**
     * Platformní kódy (odmítnutí na vstupu, obálka, podpis) žádnou kontrolu
     * nemají. Dopočítat ji z čísla by ukázalo na pravidlo, o které nešlo.
     */
    public function testPlatformErrorCarriesNoControl(): void
    {
        $explained = $this->explain('JMHZ25: 63 - Nesouhlasí variabilní symbol', '63');

        self::assertNull($explained[0]['control_id']);
        self::assertNull($explained[0]['control']);
        self::assertNull($explained[0]['remediation']);
        self::assertSame('platform', $explained[0]['origin']);
    }

    /** @return list<array<string,mixed>> */
    private function explain(string $message, string $number, bool $withForm = false): array
    {
        $forms = $withForm
            ? [[
                'guid' => self::FORM_GUID,
                'result' => 'ERROR',
                'errMsg' => $message,
                'errNum' => $number,
            ]]
            : [];
        $report = (new JmhzProtocolParser())->parse(JmhzTransportSample::partialProtocol(
            'ERROR',
            $forms,
            'error',
            $message,
            $number,
        ));

        return (new JmhzProtocolExplainer())->explain($report);
    }

    /**
     * @param list<array<string,mixed>> $explained
     * @return array<string,mixed>
     */
    private function forForm(array $explained): array
    {
        foreach ($explained as $item) {
            if (($item['form_guid'] ?? null) === self::FORM_GUID) {
                return $item;
            }
        }
        self::fail('Vysvětlení k součásti chybí.');
    }
}
