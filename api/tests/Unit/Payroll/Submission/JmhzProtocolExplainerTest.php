<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolExplainer;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzProtocolParser;
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
